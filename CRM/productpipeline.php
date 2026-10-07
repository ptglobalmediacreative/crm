<?php
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================================
// PASTIKAN MODULE PRODUCT PIPELINE TERSEDIA
// ============================================================
try {
    $stmtModule = $db->prepare("SELECT id FROM modules WHERE module_name = ? LIMIT 1");
    $stmtModule->execute(['product_pipeline']);
    $moduleId = $stmtModule->fetchColumn();

    if (!$moduleId) {
        $stmtInsert = $db->prepare("
            INSERT INTO modules
                (module_name, module_label, module_icon, module_order, is_active, is_main_menu)
            VALUES (?, ?, ?, ?, 1, 1)
        ");
        $stmtInsert->execute([
            'product_pipeline',
            'Product Pipeline',
            'fa-chart-column',
            7
        ]);
        $moduleId = (int)$db->lastInsertId();
    }

    // Sinkronkan permission untuk role yang sudah mempunyai user.
    $roles = $db->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> ''")
                ->fetchAll(PDO::FETCH_COLUMN);
    $stmtPermission = $db->prepare("
        INSERT IGNORE INTO permissions
            (module_id, role_name, can_view, can_add, can_edit, can_delete)
        VALUES (?, ?, 0, 0, 0, 0)
    ");
    foreach ($roles as $role) {
        $stmtPermission->execute([(int)$moduleId, $role]);
    }
} catch (PDOException $e) {
    // Permission tetap ditangani oleh requirePermission().
}

requirePermission('product_pipeline', 'view');

$userMenus = getUserMenus();

// ============================================================
// HELPER
// ============================================================
function pipelineQty($value) {
    return max(0, (int)$value);
}

function pipelineAdd(&$map, $activityId, $activityNumber, $productId, $productName, $qty, $stage) {
    $activityId = (int)$activityId;
    $productId = (int)$productId;
    $qty = pipelineQty($qty);
    if ($activityId <= 0 || $productId <= 0 || $productName === '' || $qty <= 0) {
        return;
    }

    if (!isset($map[$activityId])) {
        $map[$activityId] = [
            'activity_id' => $activityId,
            'activity_number' => $activityNumber,
            'units' => []
        ];
    }

    $key = $productId;
    if (!isset($map[$activityId]['units'][$key])) {
        $map[$activityId]['units'][$key] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'prospect_qty' => 0,
            'hot_qty' => 0,
            'deal_qty' => 0
        ];
    }

    // Satu Activity Number + satu Tipe Unit hanya boleh masuk ke SATU tahap.
    // Nilai qty memakai nilai terbesar agar histori/revisi tidak terhitung double.
    $field = $stage . '_qty';
    $map[$activityId]['units'][$key][$field] = max(
        (int)$map[$activityId]['units'][$key][$field],
        $qty
    );
}

// ============================================================
// 1. PROSPECT: TIPE UNIT DARI DETAIL AKTIVITAS PROSPECTING
// ============================================================
$pipeline = [];

try {
    $sqlProspect = "
        SELECT
            sa.id AS activity_id,
            sa.leads_number AS activity_number,
            ad.id AS activity_detail_id,
            adu.product_id,
            p.nama_produk,
            adu.quantity
        FROM sales_activities sa
        INNER JOIN activity_details ad
            ON ad.sales_activity_id = sa.id
           AND ad.jenis_tugas = 'Prospecting'
        INNER JOIN activity_detail_units adu
            ON adu.activity_detail_id = ad.id
        INNER JOIN products p
            ON p.id = adu.product_id
        WHERE p.nama_produk IS NOT NULL
          AND TRIM(p.nama_produk) <> ''
        ORDER BY sa.id ASC, p.nama_produk ASC
    ";
    $stmt = $db->query($sqlProspect);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        pipelineAdd(
            $pipeline,
            $row['activity_id'],
            $row['activity_number'],
            $row['product_id'],
            trim((string)$row['nama_produk']),
            $row['quantity'],
            'prospect'
        );
    }
} catch (PDOException $e) {
    // Jika tabel belum tersedia, halaman tetap dapat dibuka.
}

// ============================================================
// 2. HOT PROSPECT / DEAL: TIPE UNIT DARI DETAIL TR
//    Relasi WAJIB berdasarkan Activity Number -> activity_details.sales_activity_id.
//
//    Customer Deal:
//      yes = Deal
//      selain yes / belum diisi = Hot Prospect
//
//    Jika tipe unit sama dengan tipe unit Prospect pada Activity Number
//    yang sama, Prospect dipindahkan ke tahap TR dan tidak dihitung double.
// ============================================================
try {
    $sqlTR = "
        SELECT
            sa.id AS activity_id,
            sa.leads_number AS activity_number,
            ad.tr_number,
            tdu.id AS tr_unit_id,
            tdu.unit_id AS product_id,
            tdu.qty,
            p.nama_produk,
            COALESCE(dtr.customer_deal, '') AS customer_deal
        FROM activity_details ad
        INNER JOIN sales_activities sa
            ON sa.id = ad.sales_activity_id
        INNER JOIN tr_detail_units tdu
            ON tdu.trf_number = ad.tr_number
        INNER JOIN products p
            ON p.id = tdu.unit_id
        LEFT JOIN detail_transaction_requests dtr
            ON dtr.id = (
                SELECT MAX(d2.id)
                FROM detail_transaction_requests d2
                WHERE d2.trf_number = ad.tr_number
            )
        WHERE ad.tr_number IS NOT NULL
          AND TRIM(ad.tr_number) <> ''
          AND p.nama_produk IS NOT NULL
          AND TRIM(p.nama_produk) <> ''
        ORDER BY sa.id ASC, tdu.id ASC
    ";

    $stmt = $db->query($sqlTR);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerDeal = strtolower(trim((string)$row['customer_deal']));
        $stage = ($customerDeal === 'yes') ? 'deal' : 'hot';

        pipelineAdd(
            $pipeline,
            $row['activity_id'],
            $row['activity_number'],
            $row['product_id'],
            trim((string)$row['nama_produk']),
            $row['qty'],
            $stage
        );
    }
} catch (PDOException $e) {
    // Jika tabel TR belum tersedia, halaman tetap dapat dibuka.
}

// ============================================================
// 3. AGREGASI PER TIPE UNIT
//    Satu Activity Number + Tipe Unit hanya dihitung sekali.
// ============================================================
$pipelineByProduct = [];
foreach ($pipeline as $activityData) {
    foreach ($activityData['units'] as $unit) {
        $productId = (int)$unit['product_id'];
        if (!isset($pipelineByProduct[$productId])) {
            $pipelineByProduct[$productId] = [
                'product_id' => $productId,
                'product_name' => $unit['product_name'],
                'prospect' => 0,
                'hot_prospect' => 0,
                'deal' => 0
            ];
        }

        // Tahap tertinggi menjadi sumber qty final untuk Activity Number tersebut.
        if ((int)$unit['deal_qty'] > 0) {
            $pipelineByProduct[$productId]['deal'] += (int)$unit['deal_qty'];
        } elseif ((int)$unit['hot_qty'] > 0) {
            $pipelineByProduct[$productId]['hot_prospect'] += (int)$unit['hot_qty'];
        } elseif ((int)$unit['prospect_qty'] > 0) {
            $pipelineByProduct[$productId]['prospect'] += (int)$unit['prospect_qty'];
        }
    }
}

// Urutkan berdasarkan nama tipe unit.
usort($pipelineByProduct, static function ($a, $b) {
    return strcasecmp($a['product_name'], $b['product_name']);
});

$totalProspect = 0;
$totalHot = 0;
$totalDeal = 0;
foreach ($pipelineByProduct as $row) {
    $totalProspect += (int)$row['prospect'];
    $totalHot += (int)$row['hot_prospect'];
    $totalDeal += (int)$row['deal'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Pipeline - PT Ganda Elang Tangguh</title>

    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
</head>
<body>

<?php require_once 'navigation.php'; ?>
<link rel="stylesheet" href="css/productpipeline.css">

<main class="pp-page page-productpipeline">
    <div class="page-header productpipeline-header">
        <div>
            <div class="page-eyebrow"><i class="fas fa-chart-column"></i> Sales Intelligence</div>
            <h1>Product Pipeline</h1>
            <p>Rekap perkembangan tipe unit dari Prospect → Hot Prospect → Deal berdasarkan Activity Number.</p>
        </div>
    </div>

    <?= showFlash() ?>

    <div class="pipeline-summary">
        <div class="pipeline-summary-card prospect-card">
            <span class="summary-icon"><i class="fas fa-bullseye"></i></span>
            <div><span>Prospek</span><strong><?= number_format($totalProspect, 0, ',', '.') ?></strong></div>
        </div>
        <div class="pipeline-summary-card hot-card">
            <span class="summary-icon"><i class="fas fa-fire"></i></span>
            <div><span>Hot Prospek</span><strong><?= number_format($totalHot, 0, ',', '.') ?></strong></div>
        </div>
        <div class="pipeline-summary-card deal-card">
            <span class="summary-icon"><i class="fas fa-handshake"></i></span>
            <div><span>Deal</span><strong><?= number_format($totalDeal, 0, ',', '.') ?></strong></div>
        </div>
    </div>

    <section class="card-custom pipeline-card">
        <div class="card-header-custom pipeline-card-header">
            <div>
                <h6><i class="fas fa-boxes-stacked"></i> Product Pipeline</h6>
                <span>Qty dihitung satu kali berdasarkan Activity Number + Tipe Unit.</span>
            </div>
            <span class="pipeline-count"><?= count($pipelineByProduct) ?> Tipe Unit</span>
        </div>

        <div class="table-responsive">
            <table class="table pipeline-table align-middle mb-0">
                <thead>
                    <tr>
                        <th class="number-col">No.</th>
                        <th>Tipe Unit</th>
                        <th class="stage-col prospect-col"><i class="fas fa-bullseye"></i> Prospek</th>
                        <th class="stage-col hot-col"><i class="fas fa-fire"></i> Hot Prospek</th>
                        <th class="stage-col deal-col"><i class="fas fa-handshake"></i> Deal</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($pipelineByProduct)): ?>
                    <?php foreach ($pipelineByProduct as $index => $row): ?>
                        <tr>
                            <td class="number-cell"><?= $index + 1 ?></td>
                            <td>
                                <div class="unit-name">
                                    <span class="unit-icon"><i class="fas fa-box"></i></span>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong>
                                </div>
                            </td>
                            <td class="qty-cell"><span class="qty-badge prospect"><?= number_format((int)$row['prospect'], 0, ',', '.') ?></span></td>
                            <td class="qty-cell"><span class="qty-badge hot"><?= number_format((int)$row['hot_prospect'], 0, ',', '.') ?></span></td>
                            <td class="qty-cell"><span class="qty-badge deal"><?= number_format((int)$row['deal'], 0, ',', '.') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="empty-state">
                            <i class="fas fa-box-open"></i>
                            <strong>Belum ada data Product Pipeline</strong>
                            <span>Data akan muncul setelah Tipe Unit Prospecting atau Detail Unit TR tersedia.</span>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php require_once 'footer.php'; ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
