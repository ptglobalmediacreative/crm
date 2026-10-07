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
// PRODUCT PIPELINE DATA
// ============================================================
// Prospect = activity_details (Prospecting) -> activity_detail_units
// Hot/Deal = activity_details (tr_number) -> tr_detail_units
// Deal     = detail_transaction_requests.customer_deal = yes
// Hot      = TR sudah ada tetapi customer_deal bukan yes
//
// Satu Activity Number + satu Tipe Unit hanya dihitung sekali.
// Jika sudah masuk TR, tahap Prospect untuk tipe unit tersebut digantikan
// oleh tahap TR (Hot/Deal).

$pipeline = [];
$pipelineErrors = [];

function pipelineQty($value) {
    return max(0, (int)$value);
}

function pipelineAdd(&$map, $activityId, $activityNumber, $productId, $productName, $qty, $stage) {
    $activityId = (int)$activityId;
    $productId = (int)$productId;
    $qty = pipelineQty($qty);
    $productName = trim((string)$productName);

    if ($activityId <= 0 || $productId <= 0 || $productName === '' || $qty <= 0) {
        return;
    }

    if (!isset($map[$activityId])) {
        $map[$activityId] = [
            'activity_id' => $activityId,
            'activity_number' => (string)$activityNumber,
            'units' => []
        ];
    }

    if (!isset($map[$activityId]['units'][$productId])) {
        $map[$activityId]['units'][$productId] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'prospect_qty' => 0,
            'hot_qty' => 0,
            'deal_qty' => 0
        ];
    }

    $field = $stage . '_qty';
    $map[$activityId]['units'][$productId][$field] = max(
        (int)$map[$activityId]['units'][$productId][$field],
        $qty
    );
}

// ------------------------------------------------------------
// 1. PROSPECT
// ------------------------------------------------------------
try {
    $sqlProspect = "
        SELECT
            sa.id AS activity_id,
            sa.leads_number AS activity_number,
            adu.product_id,
            adu.quantity,
            p.nama_produk
        FROM activity_details ad
        INNER JOIN sales_activities sa
            ON sa.id = ad.sales_activity_id
        INNER JOIN activity_detail_units adu
            ON adu.activity_detail_id = ad.id
        INNER JOIN products p
            ON p.id = adu.product_id
        WHERE ad.jenis_tugas = 'Prospecting'
          AND adu.product_id IS NOT NULL
          AND adu.quantity > 0
          AND p.nama_produk IS NOT NULL
          AND TRIM(p.nama_produk) <> ''
        ORDER BY sa.id ASC, ad.id ASC, adu.id ASC
    ";

    $stmt = $db->query($sqlProspect);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        pipelineAdd(
            $pipeline,
            $row['activity_id'],
            $row['activity_number'],
            $row['product_id'],
            $row['nama_produk'],
            $row['quantity'],
            'prospect'
        );
    }
} catch (PDOException $e) {
    $pipelineErrors[] = 'Prospect: ' . $e->getMessage();
}

// ------------------------------------------------------------
// 2. HOT PROSPECT / DEAL DARI TR
// ------------------------------------------------------------
try {
    $sqlTR = "
        SELECT
            sa.id AS activity_id,
            sa.leads_number AS activity_number,
            ad.tr_number,
            tdu.unit_id AS product_id,
            tdu.qty,
            p.nama_produk,
            COALESCE(
                (
                    SELECT dtr.customer_deal
                    FROM detail_transaction_requests dtr
                    WHERE dtr.trf_number = ad.tr_number
                    ORDER BY dtr.id DESC
                    LIMIT 1
                ),
                ''
            ) AS customer_deal
        FROM activity_details ad
        INNER JOIN sales_activities sa
            ON sa.id = ad.sales_activity_id
        INNER JOIN tr_detail_units tdu
            ON tdu.trf_number = ad.tr_number
        INNER JOIN products p
            ON p.id = tdu.unit_id
        WHERE ad.tr_number IS NOT NULL
          AND TRIM(ad.tr_number) <> ''
          AND tdu.unit_id IS NOT NULL
          AND tdu.qty > 0
          AND p.nama_produk IS NOT NULL
          AND TRIM(p.nama_produk) <> ''
        ORDER BY sa.id ASC, ad.id ASC, tdu.id ASC
    ";

    $stmt = $db->query($sqlTR);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $deal = strtolower(trim((string)$row['customer_deal']));
        $stage = ($deal === 'yes') ? 'deal' : 'hot';

        pipelineAdd(
            $pipeline,
            $row['activity_id'],
            $row['activity_number'],
            $row['product_id'],
            $row['nama_produk'],
            $row['qty'],
            $stage
        );
    }
} catch (PDOException $e) {
    $pipelineErrors[] = 'Transaction Request: ' . $e->getMessage();
}

// ------------------------------------------------------------
// 3. AGREGASI PER TIPE UNIT
// ------------------------------------------------------------
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

        if ((int)$unit['deal_qty'] > 0) {
            $pipelineByProduct[$productId]['deal'] += (int)$unit['deal_qty'];
        } elseif ((int)$unit['hot_qty'] > 0) {
            $pipelineByProduct[$productId]['hot_prospect'] += (int)$unit['hot_qty'];
        } elseif ((int)$unit['prospect_qty'] > 0) {
            $pipelineByProduct[$productId]['prospect'] += (int)$unit['prospect_qty'];
        }
    }
}

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
