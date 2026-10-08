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

// ============================================================
// USER DATA + ROLE SEBELUM INCLUDE navigation.php
// ============================================================
$userMenus = getUserMenus();

$profileUserId = (int)($_SESSION['user_id'] ?? 0);
$userData = [];

if ($profileUserId > 0 && isset($db) && $db instanceof PDO) {
    try {
        $stmtUser = $db->prepare("
            SELECT id, username, full_name, email, phone, role, profile_photo
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmtUser->execute([$profileUserId]);
        $userData = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $userData = [];
    }
}

$role     = (string)($userData['role'] ?? $_SESSION['role'] ?? '');
$userRole = $role;

// ============================================================
// CRM_LIGHT_NAV
// ============================================================
if (!defined('CRM_LIGHT_NAV')) {
    define('CRM_LIGHT_NAV', true);
}

// ============================================================
// FILTER SALES + PERIODE
// ------------------------------------------------------------
// Role dengan akses full report dapat memilih sales apa saja
// dan melihat SEMUA data:
//   - Direktur Utama
//   - Direktur Sales
//   - Direktur Operasional
//   - Sales Manager
//   - IT Support
//   - Business
//
// Role lain (sales, finance, part_support, service_support)
// otomatis dikunci ke data miliknya sendiri.
// ============================================================
$fullReportRoles = [
    'direktur_utama',
    'direktur_operasional',
    'direktur_sales',
    'sales_manager',
    'it_support',
    'business',
];

$canViewAllReport = in_array($role, $fullReportRoles, true);

// Filter Sales
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;

if ($canViewAllReport) {
    if ($filterSalesId < 0) {
        $filterSalesId = 0;
    }
} else {
    $filterSalesId = $profileUserId;
}

// Filter Month (YYYY-MM)
$filterMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
if ($filterMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = '';
}

// Daftar Sales (hanya untuk role full report)
$allSalesList = [];
if ($canViewAllReport) {
    try {
        $stmtSales = $db->query("
            SELECT id, full_name
            FROM users
            WHERE role IN ('sales', 'sales_manager')
            ORDER BY full_name ASC
        ");
        $allSalesList = $stmtSales->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $allSalesList = [];
    }
}

// Nama sales yang sedang difilter
$filteredSalesName = 'Semua Sales';
if ($filterSalesId > 0) {
    try {
        $stmtName = $db->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
        $stmtName->execute([$filterSalesId]);
        $filteredSalesName = $stmtName->fetchColumn() ?: 'User';
    } catch (Throwable $e) {
        $filteredSalesName = 'User';
    }
}

// ============================================================
// BUILD FILTER SQL FRAGMENTS
// ============================================================
$prospectFilterSql = '';
$prospectFilterParams = [];

$trFilterSql = '';
$trFilterParams = [];

if ($filterSalesId > 0) {
    $prospectFilterSql .= " AND sa.sales_id = ?";
    $prospectFilterParams[] = $filterSalesId;

    $trFilterSql .= " AND sa.sales_id = ?";
    $trFilterParams[] = $filterSalesId;
}

if ($filterMonth !== '') {
    $monthStart = $filterMonth . '-01';
    $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    $prospectFilterSql .= " AND sa.created_at >= ? AND sa.created_at < ?";
    $prospectFilterParams[] = $monthStart;
    $prospectFilterParams[] = $monthEnd;

    $trFilterSql .= " AND sa.created_at >= ? AND sa.created_at < ?";
    $trFilterParams[] = $monthStart;
    $trFilterParams[] = $monthEnd;
}

// ============================================================
// HELPER
// ============================================================
function pipelineQty($value) {
    return max(0, (int)$value);
}

function pipelineAdd(&$map, $activityId, $activityNumber, $productId, $productName, $qty, $stage) {
    $activityId = (int)$activityId;
    $productId  = (int)$productId;
    $qty        = pipelineQty($qty);

    if ($activityId <= 0 || $productId <= 0 || $productName === '' || $qty <= 0) {
        return;
    }

    if (!isset($map[$activityId])) {
        $map[$activityId] = [
            'activity_id'     => $activityId,
            'activity_number' => $activityNumber,
            'units'           => []
        ];
    }

    $key = $productId;
    if (!isset($map[$activityId]['units'][$key])) {
        $map[$activityId]['units'][$key] = [
            'product_id'   => $productId,
            'product_name' => $productName,
            'prospect_qty' => 0,
            'hot_qty'      => 0,
            'lost_qty'     => 0,
            'deal_qty'     => 0
        ];
    }

    $field = $stage . '_qty';
    if (!isset($map[$activityId]['units'][$key][$field])) {
        $map[$activityId]['units'][$key][$field] = 0;
    }

    $map[$activityId]['units'][$key][$field] = max(
        (int)$map[$activityId]['units'][$key][$field],
        $qty
    );
}

function pipelineResolveStage($customerDealRaw) {
    $value = strtolower(trim((string)$customerDealRaw));
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    if ($value === 'yes' || $value === 'deal') {
        return 'deal';
    }

    if (
        $value === 'lost deal' ||
        $value === 'lostdeal' ||
        $value === 'lost'
    ) {
        return 'lost';
    }

    return 'hot';
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
          {$prospectFilterSql}
        ORDER BY sa.id ASC, p.nama_produk ASC
    ";

    $stmt = $db->prepare($sqlProspect);
    $stmt->execute($prospectFilterParams);

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
// 2. HOT PROSPECT / LOST DEAL / DEAL: TIPE UNIT DARI DETAIL TR
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
          {$trFilterSql}
        ORDER BY sa.id ASC, tdu.id ASC
    ";

    $stmt = $db->prepare($sqlTR);
    $stmt->execute($trFilterParams);

    $activitiesWithLost = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stage = pipelineResolveStage($row['customer_deal']);

        pipelineAdd(
            $pipeline,
            $row['activity_id'],
            $row['activity_number'],
            $row['product_id'],
            trim((string)$row['nama_produk']),
            $row['qty'],
            $stage
        );

        if ($stage === 'lost') {
            $activitiesWithLost[(int)$row['activity_id']] = true;
        }
    }

    if (!empty($activitiesWithLost)) {
        foreach ($pipeline as $activityId => $activityData) {
            if (!isset($activitiesWithLost[$activityId])) {
                continue;
            }

            foreach ($activityData['units'] as $productId => $unit) {
                $hotQty  = (int)$unit['hot_qty'];
                $lostQty = (int)$unit['lost_qty'];

                if ($hotQty > 0) {
                    $pipeline[$activityId]['units'][$productId]['lost_qty'] = max($lostQty, $hotQty);
                    $pipeline[$activityId]['units'][$productId]['hot_qty']  = 0;
                }
            }
        }
    }
} catch (PDOException $e) {
    // Jika tabel TR belum tersedia, halaman tetap dapat dibuka.
}

// ============================================================
// 3. AGREGASI PER TIPE UNIT
// ============================================================
$pipelineByProduct = [];
foreach ($pipeline as $activityData) {
    foreach ($activityData['units'] as $unit) {
        $productId = (int)$unit['product_id'];
        if (!isset($pipelineByProduct[$productId])) {
            $pipelineByProduct[$productId] = [
                'product_id'   => $productId,
                'product_name' => $unit['product_name'],
                'prospect'     => 0,
                'hot_prospect' => 0,
                'lost_deal'    => 0,
                'deal'         => 0
            ];
        }

        $dealQty     = (int)($unit['deal_qty']     ?? 0);
        $lostQty     = (int)($unit['lost_qty']     ?? 0);
        $hotQty      = (int)($unit['hot_qty']      ?? 0);
        $prospectQty = (int)($unit['prospect_qty'] ?? 0);

        if ($dealQty > 0) {
            $pipelineByProduct[$productId]['deal'] += $dealQty;
        } elseif ($lostQty > 0) {
            $pipelineByProduct[$productId]['lost_deal'] += $lostQty;
        } elseif ($hotQty > 0) {
            $pipelineByProduct[$productId]['hot_prospect'] += $hotQty;
        } elseif ($prospectQty > 0) {
            $pipelineByProduct[$productId]['prospect'] += $prospectQty;
        }
    }
}

usort($pipelineByProduct, static function ($a, $b) {
    return strcasecmp($a['product_name'], $b['product_name']);
});

$totalProspect = 0;
$totalHot      = 0;
$totalLost     = 0;
$totalDeal     = 0;
foreach ($pipelineByProduct as $row) {
    $totalProspect += (int)$row['prospect'];
    $totalHot      += (int)$row['hot_prospect'];
    $totalLost     += (int)$row['lost_deal'];
    $totalDeal     += (int)$row['deal'];
}

// ============================================================
// EXPORT EXCEL — ikut menerapkan filter aktif
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $exportFilename = 'Product_Pipeline_' . date('Ymd_His') . '.xls';

    if (ob_get_level() > 0) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    echo "\xEF\xBB\xBF";

    $exportUserName = trim((string)($userData['full_name'] ?? $_SESSION['full_name'] ?? 'User'));

    $exportFilterLabel = [];
    if ($filterSalesId > 0) {
        $exportFilterLabel[] = 'Sales: ' . $filteredSalesName;
    }
    if ($filterMonth !== '') {
        $exportFilterLabel[] = 'Periode: ' . date('F Y', strtotime($filterMonth . '-01'));
    }
    $exportFilterText = $exportFilterLabel
        ? implode(' | ', $exportFilterLabel)
        : 'Semua Sales | All Periode';
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]>
<xml>
<x:ExcelWorkbook>
<x:ExcelWorksheets>
<x:ExcelWorksheet>
<x:Name>Product Pipeline</x:Name>
<x:WorksheetOptions>
<x:DisplayGridlines/>
</x:WorksheetOptions>
</x:ExcelWorksheet>
</x:ExcelWorksheets>
</x:ExcelWorkbook>
</xml>
<![endif]-->
<style>
    table { border-collapse: collapse; }
    td, th {
        border: 1px solid #94a3b8;
        padding: 6px 10px;
        font-family: Arial, sans-serif;
        font-size: 11px;
        vertical-align: middle;
    }
    .title { font-size: 16px; font-weight: bold; color: #1e3a8a; text-align: left; border: 0; }
    .subtitle { font-size: 11px; color: #475569; text-align: left; border: 0; padding-top: 0; }
    .filter-info { font-size: 11px; color: #1e3a8a; font-weight: bold; text-align: left; border: 0; }
    .spacer { border: 0; }
    thead th {
        background: #1e3a8a;
        color: #ffffff;
        font-weight: bold;
        text-align: center;
        font-size: 11px;
    }
    .text-left   { text-align: left; }
    .text-center { text-align: center; }
    .total-row td {
        background: #f1f5f9;
        font-weight: bold;
        color: #0f172a;
    }
    .num-prospect { color: #2563eb; font-weight: bold; }
    .num-hot      { color: #d97706; font-weight: bold; }
    .num-lost     { color: #dc2626; font-weight: bold; }
    .num-deal     { color: #16a34a; font-weight: bold; }
</style>
</head>
<body>
<table>
    <tr><td colspan="6" class="title">PRODUCT PIPELINE</td></tr>
    <tr><td colspan="6" class="subtitle">PT Ganda Elang Tangguh &mdash; Customer Relationship Management</td></tr>
    <tr><td colspan="6" class="subtitle">
        Diekspor oleh: <?= htmlspecialchars($exportUserName) ?>
        &nbsp;|&nbsp; Tanggal: <?= date('d F Y H:i') ?> WIB
        &nbsp;|&nbsp; Total Tipe Unit: <?= count($pipelineByProduct) ?>
    </td></tr>
    <tr><td colspan="6" class="filter-info">Filter: <?= htmlspecialchars($exportFilterText) ?></td></tr>
    <tr><td colspan="6" class="spacer">&nbsp;</td></tr>

    <thead>
        <tr>
            <th style="width:40px;">No.</th>
            <th style="width:280px;">Tipe Unit</th>
            <th style="width:90px;">Prospek</th>
            <th style="width:110px;">Hot Prospek</th>
            <th style="width:100px;">Lost Deal</th>
            <th style="width:80px;">Deal</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!empty($pipelineByProduct)): ?>
        <?php foreach ($pipelineByProduct as $i => $r): ?>
            <tr>
                <td class="text-center"><?= $i + 1 ?></td>
                <td class="text-left"><?= htmlspecialchars($r['product_name']) ?></td>
                <td class="text-center num-prospect"><?= (int)$r['prospect'] ?></td>
                <td class="text-center num-hot"><?= (int)$r['hot_prospect'] ?></td>
                <td class="text-center num-lost"><?= (int)$r['lost_deal'] ?></td>
                <td class="text-center num-deal"><?= (int)$r['deal'] ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total-row">
            <td colspan="2" class="text-left">TOTAL</td>
            <td class="text-center"><?= $totalProspect ?></td>
            <td class="text-center"><?= $totalHot ?></td>
            <td class="text-center"><?= $totalLost ?></td>
            <td class="text-center"><?= $totalDeal ?></td>
        </tr>
    <?php else: ?>
        <tr>
            <td colspan="6" class="text-center">Belum ada data Product Pipeline</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</body>
</html>
    <?php
    exit;
}

// ============================================================
// SIAPKAN URL EXPORT (meneruskan filter yang aktif)
// ============================================================
$exportQueryParams = ['export' => 'excel'];
if ($filterSalesId > 0) {
    $exportQueryParams['sales_id'] = $filterSalesId;
}
if ($filterMonth !== '') {
    $exportQueryParams['month'] = $filterMonth;
}
$exportUrl = '?' . http_build_query($exportQueryParams);

// ============================================================
// LABEL PERIODE AKTIF
// ============================================================
$periodLabel = 'All Periode';
if ($filterMonth !== '') {
    $bulanIndo = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    $year  = (int)substr($filterMonth, 0, 4);
    $month = (int)substr($filterMonth, 5, 2);
    $periodLabel = $bulanIndo[$month] . ' ' . $year;
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
    <link rel="stylesheet" href="css/productpipeline.css">
</head>
<body>

<?php require_once 'navigation.php'; ?>

<main class="pp-page page-productpipeline">
    <div class="page-header productpipeline-header">
        <div class="pipeline-title">
            <h1>Product Pipeline</h1>
            <p>Rekap perkembangan tipe unit dari Prospect → Hot Prospect → Lost Deal / Deal berdasarkan Activity Number.</p>
        </div>

        <div class="pipeline-actions">
            <?php if ($canViewAllReport): ?>
                <select class="pp-filter-select" id="filterSales" onchange="applyPipelineFilter()">
                    <option value="0">All Sales</option>
                    <?php foreach ($allSalesList as $s): ?>
                        <option value="<?= (int)$s['id'] ?>" <?= ($filterSalesId == (int)$s['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <div class="pp-filter-period"
                 id="periodFilter"
                 role="button"
                 tabindex="0"
                 aria-label="Pilih periode"
                 onclick="openPeriodPicker(event)"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();openPeriodPicker(event);}">
                <span id="periodFilterLabel"><?= htmlspecialchars($periodLabel) ?></span>
                <i class="fas fa-calendar-alt"></i>
                <input type="month"
                       id="filterMonth"
                       value="<?= htmlspecialchars($filterMonth) ?>"
                       onchange="applyPipelineFilter()"
                       tabindex="-1"
                       aria-hidden="true">
            </div>

            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-export">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
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
        <div class="pipeline-summary-card lost-card">
            <span class="summary-icon"><i class="fas fa-circle-xmark"></i></span>
            <div><span>Lost Deal</span><strong><?= number_format($totalLost, 0, ',', '.') ?></strong></div>
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
                        <th class="stage-col lost-col"><i class="fas fa-circle-xmark"></i> Lost Deal</th>
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
                            <td class="qty-cell"><span class="qty-badge lost"><?= number_format((int)$row['lost_deal'], 0, ',', '.') ?></span></td>
                            <td class="qty-cell"><span class="qty-badge deal"><?= number_format((int)$row['deal'], 0, ',', '.') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="empty-state">
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
<script>
function applyPipelineFilter() {
    const salesEl = document.getElementById('filterSales');
    const monthEl = document.getElementById('filterMonth');

    const params = new URLSearchParams();

    if (salesEl && salesEl.value && salesEl.value !== '0') {
        params.set('sales_id', salesEl.value);
    }

    if (monthEl && monthEl.value) {
        params.set('month', monthEl.value);
    }

    const qs = params.toString();
    location.href = qs ? ('?' + qs) : 'productpipeline.php';
}

function openPeriodPicker(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const monthEl = document.getElementById('filterMonth');
    if (!monthEl) return;

    if (typeof monthEl.showPicker === 'function') {
        try {
            monthEl.showPicker();
            return;
        } catch (e) {}
    }

    monthEl.focus();
    monthEl.click();
}

document.addEventListener('DOMContentLoaded', function () {
    const monthEl = document.getElementById('filterMonth');
    const label = document.getElementById('periodFilterLabel');

    if (monthEl && label) {
        monthEl.addEventListener('change', function () {
            if (!this.value) {
                label.textContent = 'All Periode';
                return;
            }

            const [year, month] = this.value.split('-');
            const names = [
                'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
            ];

            label.textContent = names[parseInt(month, 10) - 1] + ' ' + year;
        });
    }
});
</script>
</body>
</html>