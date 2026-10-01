<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once 'config.php';

// Set timezone ke WIB
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();
$menuNames = array_column($userMenus, 'module_name');

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// ============================================
// FUNGSI UNTUK MENGUBAH ROLE MENJADI LABEL DIVISI
// ============================================
function getRoleLabel($role) {
    $roleLabels = [
        'it_support' => 'IT Support',
        'admin' => 'Admin',
        'finance' => 'Finance',
        'direktur_utama' => 'Direktur Utama',
        'direktur_operasional' => 'Direktur Operasional',
        'direktur_sales' => 'Direktur Sales',
        'business' => 'Business',
        'sales_manager' => 'Sales Manager',
        'sales' => 'Sales'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// HANDLE FILTER SALES & BULAN + HAK AKSES DASHBOARD
// ============================================

// Role yang boleh melihat seluruh data / All Sales
$fullReportRoles = [
    'direktur_utama',
    'direktur_operasional',
    'direktur_sales',
    'sales_manager',
    'it_support'
];

$canViewAllReport = in_array($role, $fullReportRoles, true);

// Filter Sales hanya boleh digunakan oleh user yang punya akses full report.
// User lain selalu dikunci ke data miliknya sendiri.
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;

if ($canViewAllReport) {
    // Full report: 0 = All Sales
    if ($filterSalesId < 0) {
        $filterSalesId = 0;
    }
} else {
    // User biasa: tidak boleh melihat data user lain.
    $filterSalesId = (int)$userId;
}

// Default dashboard = All Month.
// Jika month kosong / tidak dikirim, berarti semua bulan.
$filterMonth = isset($_GET['month']) ? trim((string)$_GET['month']) : '';
if ($filterMonth !== '' && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
    $filterMonth = '';
}

// Daftar Sales hanya untuk user dengan akses full report.
$allSalesList = [];
if ($canViewAllReport) {
    $stmt = $db->query("
        SELECT id, full_name
        FROM users
        WHERE role IN ('sales', 'sales_manager')
        ORDER BY full_name ASC
    ");
    $allSalesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter activity berdasarkan Sales.
$sqlFilterSA = "";
if ($filterSalesId > 0) {
    $sqlFilterSA = " AND sa.sales_id = " . (int)$filterSalesId;
}

// Filter account berdasarkan Sales.
$sqlFilterAcc = "";
if ($filterSalesId > 0) {
    $sqlFilterAcc = " AND a.sales_id = " . (int)$filterSalesId;
}

// Filter periode berdasarkan tanggal input.
// All Month = tidak menambahkan kondisi tanggal.
$sqlFilterSAMonth = "";
$sqlFilterAccMonth = "";

if ($filterMonth !== '') {
    $monthStart = $filterMonth . '-01';
    $monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));

    $sqlFilterSAMonth = " AND sa.created_at >= " . $db->quote($monthStart) .
                        " AND sa.created_at < " . $db->quote($monthEnd);

    $sqlFilterAccMonth = " AND a.created_at >= " . $db->quote($monthStart) .
                         " AND a.created_at < " . $db->quote($monthEnd);
}

// Nama filter yang sedang aktif.
if ($filterSalesId > 0) {
    $stmtSalesName = $db->query(
        "SELECT full_name FROM users WHERE id = " . (int)$filterSalesId . " LIMIT 1"
    );
    $filteredSalesName = $stmtSalesName->fetchColumn() ?: 'User';
} else {
    $filteredSalesName = 'Semua Sales';
}

$isAllMonth = ($filterMonth === '');

// ============================================
// DATA STATISTIK
// Semua KPI mengikuti filter Sales + filter Bulan.
// ============================================

// Total Account / Leads
$sqlTotalLeads = "
    SELECT COUNT(*)
    FROM accounts a
    WHERE 1=1
    {$sqlFilterAcc}
    {$sqlFilterAccMonth}
";
$totalLeads = (int)$db->query($sqlTotalLeads)->fetchColumn();

// New Leads = Account yang dibuat pada periode filter.
// All Month = seluruh Account yang dapat dilihat user.
$sqlNewLeads = "
    SELECT COUNT(*)
    FROM accounts a
    WHERE 1=1
    {$sqlFilterAcc}
    {$sqlFilterAccMonth}
";
$newLeads = (int)$db->query($sqlNewLeads)->fetchColumn();

/*
 * Total Transaction Request.
 * Sumber TR = activity_details.tr_number.
 * Periode mengikuti tanggal sales activity (sa.created_at).
 */
$sqlTotalTR = "
    SELECT COUNT(DISTINCT ad.tr_number)
    FROM activity_details ad
    INNER JOIN sales_activities sa ON ad.sales_activity_id = sa.id
    WHERE ad.tr_number IS NOT NULL
      AND TRIM(ad.tr_number) <> ''
      {$sqlFilterSA}
      {$sqlFilterSAMonth}
";
$totalTransactionRequests = (int)$db->query($sqlTotalTR)->fetchColumn();

/*
 * Total Delivery Order / Delivery Instruction.
 * Periode mengikuti tanggal sales activity (sa.created_at).
 */
$sqlTotalDO = "
    SELECT COUNT(DISTINCT ad.di_number)
    FROM activity_details ad
    INNER JOIN sales_activities sa ON ad.sales_activity_id = sa.id
    WHERE ad.di_number IS NOT NULL
      AND TRIM(ad.di_number) <> ''
      {$sqlFilterSA}
      {$sqlFilterSAMonth}
";
$totalDeliveryOrders = (int)$db->query($sqlTotalDO)->fetchColumn();

// ============================================
// SALES PIPELINE
// Ambil aktivitas TERAKHIR dalam periode yang dipilih.
// Jika All Month, ambil aktivitas terakhir sepanjang data.
// ============================================
$pipelineCounts = [
    'Suspect' => 0,
    'Prospect' => 0,
    'Hot Prospect' => 0,
    'Deal' => 0,
    'Lost Deal' => 0
];

$sqlPipeline = "
    SELECT latest.account_id,
           latest.jenis_tugas,
           latest.tr_number,
           latest.customer_deal,
           latest.customer_deal_keterangan
    FROM (
        SELECT sa.account_id,
               ad.jenis_tugas,
               ad.tr_number,
               dtr.customer_deal,
               dtr.customer_deal_keterangan,
               ad.id,
               ROW_NUMBER() OVER (
                   PARTITION BY sa.account_id
                   ORDER BY ad.id DESC
               ) AS rn
        FROM sales_activities sa
        INNER JOIN activity_details ad
            ON ad.sales_activity_id = sa.id
        LEFT JOIN detail_transaction_requests dtr
            ON dtr.trf_number = ad.tr_number
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IS NOT NULL
          AND TRIM(ad.jenis_tugas) <> ''
          {$sqlFilterSA}
          {$sqlFilterSAMonth}
    ) latest
    WHERE latest.rn = 1
";

try {
    $pipelineRows = $db->query($sqlPipeline)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback MySQL versi lama tanpa ROW_NUMBER().
    $pipelineWhere = "WHERE sa2.account_id IS NOT NULL
                       AND ad2.jenis_tugas IS NOT NULL
                       AND TRIM(ad2.jenis_tugas) <> ''";

    if ($filterSalesId > 0) {
        $pipelineWhere .= " AND sa2.sales_id = " . (int)$filterSalesId;
    }

    if ($filterMonth !== '') {
        $pipelineWhere .= " AND sa2.created_at >= " . $db->quote($monthStart) .
                          " AND sa2.created_at < " . $db->quote($monthEnd);
    }

    $sqlPipeline = "
        SELECT sa.account_id,
               ad.jenis_tugas,
               ad.tr_number,
               dtr.customer_deal,
               dtr.customer_deal_keterangan
        FROM sales_activities sa
        INNER JOIN activity_details ad
            ON ad.sales_activity_id = sa.id
        LEFT JOIN detail_transaction_requests dtr
            ON dtr.trf_number = ad.tr_number
        INNER JOIN (
            SELECT sa2.account_id, MAX(ad2.id) AS max_detail_id
            FROM sales_activities sa2
            INNER JOIN activity_details ad2
                ON ad2.sales_activity_id = sa2.id
            {$pipelineWhere}
            GROUP BY sa2.account_id
        ) x
            ON x.account_id = sa.account_id
           AND x.max_detail_id = ad.id
        WHERE 1=1
          " . ($filterSalesId > 0 ? " AND sa.sales_id = " . (int)$filterSalesId : "") . "
    ";

    $pipelineRows = $db->query($sqlPipeline)->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($pipelineRows as $row) {
    $jenisTugas = trim((string)($row['jenis_tugas'] ?? ''));
    $customerDeal = strtolower(trim((string)($row['customer_deal'] ?? '')));

    if ($customerDeal === 'yes') {
        $pipelineCounts['Deal']++;
    } elseif ($customerDeal === 'no') {
        $pipelineCounts['Lost Deal']++;
    } elseif ($jenisTugas === 'Negosiasi' || $jenisTugas === 'Kontrak') {
        $pipelineCounts['Hot Prospect']++;
    } elseif ($jenisTugas === 'Prospecting') {
        $pipelineCounts['Prospect']++;
    } elseif ($jenisTugas === 'Perkenalan' || $jenisTugas === 'Visit/Meeting') {
        $pipelineCounts['Suspect']++;
    } elseif ($jenisTugas === 'After Sales') {
        $pipelineCounts['Deal']++;
    }
}

// Revenue Forecast
$totalRevenue = 0;

// ============================================
// CHART ACTIVITY PERFORMANCE
// Filter Sales + Bulan selalu diterapkan.
// All Month ditampilkan per bulan.
// Bulan tertentu ditampilkan per hari.
// ============================================
$chartLabels = [];
$chartDatasets = [];

$colorPalette = [
    '#e74c3c', '#3498db', '#2ecc71', '#f39c12',
    '#9b59b6', '#1abc9c', '#e67e22', '#34495e'
];

function hexToRgba($hex, $alpha) {
    list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
    return "rgba($r,$g,$b,$alpha)";
}

if ($filterMonth !== '') {
    // Bulan tertentu -> per hari.
    $year = (int)substr($filterMonth, 0, 4);
    $month = (int)substr($filterMonth, 5, 2);
    $totalDays = cal_days_in_month(CAL_GREGORIAN, $month, $year);

    $chartStart = $filterMonth . '-01';
    $chartEnd = date('Y-m-d', strtotime($chartStart . ' +1 month'));

    if ($filterSalesId > 0) {
        $chartQuery = "
            SELECT DATE(sa.created_at) AS date, COUNT(*) AS total
            FROM sales_activities sa
            WHERE sa.created_at >= ?
              AND sa.created_at < ?
              AND sa.sales_id = ?
            GROUP BY DATE(sa.created_at)
            ORDER BY date ASC
        ";

        $stmt = $db->prepare($chartQuery);
        $stmt->execute([$chartStart, $chartEnd, $filterSalesId]);
        $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dataMap = [];
        foreach ($chartData as $row) {
            $dataMap[$row['date']] = (int)$row['total'];
        }

        $values = [];
        for ($day = 1; $day <= $totalDays; $day++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $chartLabels[] = date('d M', strtotime($dateKey));
            $values[] = $dataMap[$dateKey] ?? 0;
        }

        $chartDatasets[] = [
            'label' => htmlspecialchars($filteredSalesName),
            'data' => $values,
            'backgroundColor' => 'rgba(41, 128, 185, 0.6)',
            'borderColor' => '#2980b9',
            'borderWidth' => 3,
            'fill' => true,
            'tension' => 0.4,
            'pointRadius' => 5,
            'pointBackgroundColor' => '#fff',
            'pointBorderColor' => '#2980b9',
            'pointBorderWidth' => 2,
            'pointHoverRadius' => 7
        ];
    } else {
        // Full report tanpa filter sales -> semua sales.
        for ($day = 1; $day <= $totalDays; $day++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $chartLabels[] = date('d M', strtotime($dateKey));
        }

        $colorIndex = 0;
        foreach ($allSalesList as $sales) {
            $sId = (int)$sales['id'];
            $sName = $sales['full_name'];

            $chartQuery = "
                SELECT DATE(sa.created_at) AS date, COUNT(*) AS total
                FROM sales_activities sa
                WHERE sa.created_at >= ?
                  AND sa.created_at < ?
                  AND sa.sales_id = ?
                GROUP BY DATE(sa.created_at)
                ORDER BY date ASC
            ";

            $stmt = $db->prepare($chartQuery);
            $stmt->execute([$chartStart, $chartEnd, $sId]);
            $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $dataMap = [];
            foreach ($chartData as $row) {
                $dataMap[$row['date']] = (int)$row['total'];
            }

            $values = [];
            for ($day = 1; $day <= $totalDays; $day++) {
                $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $values[] = $dataMap[$dateKey] ?? 0;
            }

            $color = $colorPalette[$colorIndex % count($colorPalette)];

            $chartDatasets[] = [
                'label' => htmlspecialchars($sName),
                'data' => $values,
                'backgroundColor' => hexToRgba($color, 0.2),
                'borderColor' => $color,
                'borderWidth' => 2,
                'fill' => false,
                'tension' => 0.4,
                'pointRadius' => 4,
                'pointBackgroundColor' => '#fff',
                'pointBorderColor' => $color,
                'pointBorderWidth' => 2,
                'pointHoverRadius' => 6
            ];

            $colorIndex++;
        }
    }
} else {
    // All Month -> per bulan.
    $chartEnd = date('Y-m-01', strtotime('+1 month'));
    $chartStart = date('Y-m-01', strtotime('-11 months'));

    $monthRows = [];

    $sqlChartAll = "
        SELECT DATE_FORMAT(sa.created_at, '%Y-%m') AS period,
               sa.sales_id,
               COUNT(*) AS total
        FROM sales_activities sa
        WHERE sa.created_at >= ?
          AND sa.created_at < ?
    ";

    $chartParams = [$chartStart, $chartEnd];

    if ($filterSalesId > 0) {
        $sqlChartAll .= " AND sa.sales_id = ?";
        $chartParams[] = $filterSalesId;
    }

    $sqlChartAll .= "
        GROUP BY DATE_FORMAT(sa.created_at, '%Y-%m'), sa.sales_id
        ORDER BY period ASC
    ";

    $stmt = $db->prepare($sqlChartAll);
    $stmt->execute($chartParams);
    $monthRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Buat 12 bulan terakhir agar chart tetap rapi.
    $periods = [];
    $cursor = new DateTime($chartStart);
    $endCursor = new DateTime($chartEnd);

    while ($cursor < $endCursor) {
        $periodKey = $cursor->format('Y-m');
        $periods[] = $periodKey;
        $chartLabels[] = $cursor->format('M Y');
        $cursor->modify('+1 month');
    }

    if ($filterSalesId > 0) {
        $map = [];
        foreach ($monthRows as $row) {
            $map[$row['period']] = (int)$row['total'];
        }

        $values = [];
        foreach ($periods as $period) {
            $values[] = $map[$period] ?? 0;
        }

        $chartDatasets[] = [
            'label' => htmlspecialchars($filteredSalesName),
            'data' => $values,
            'backgroundColor' => 'rgba(41, 128, 185, 0.6)',
            'borderColor' => '#2980b9',
            'borderWidth' => 3,
            'fill' => true,
            'tension' => 0.4,
            'pointRadius' => 5,
            'pointBackgroundColor' => '#fff',
            'pointBorderColor' => '#2980b9',
            'pointBorderWidth' => 2,
            'pointHoverRadius' => 7
        ];
    } else {
        $maps = [];

        foreach ($allSalesList as $sales) {
            $maps[(int)$sales['id']] = array_fill_keys($periods, 0);
        }

        foreach ($monthRows as $row) {
            $sid = (int)$row['sales_id'];
            if (isset($maps[$sid])) {
                $maps[$sid][$row['period']] = (int)$row['total'];
            }
        }

        $colorIndex = 0;
        foreach ($allSalesList as $sales) {
            $sid = (int)$sales['id'];
            $color = $colorPalette[$colorIndex % count($colorPalette)];

            $chartDatasets[] = [
                'label' => htmlspecialchars($sales['full_name']),
                'data' => array_values($maps[$sid] ?? array_fill_keys($periods, 0)),
                'backgroundColor' => hexToRgba($color, 0.2),
                'borderColor' => $color,
                'borderWidth' => 2,
                'fill' => false,
                'tension' => 0.4,
                'pointRadius' => 4,
                'pointBackgroundColor' => '#fff',
                'pointBorderColor' => $color,
                'pointBorderWidth' => 2,
                'pointHoverRadius' => 6
            ];

            $colorIndex++;
        }
    }
}

// ============================================
// AKTIVITAS TERBARU
// Mengikuti filter Sales + Bulan.
// ============================================
$activityLimit = 5;

$sqlActivities = "
    SELECT sa.*,
           a.nama_pt,
           u.full_name AS sales_name,
           (
               SELECT ad.subject
               FROM activity_details ad
               WHERE ad.sales_activity_id = sa.id
               ORDER BY ad.id DESC
               LIMIT 1
           ) AS subject,
           (
               SELECT ad.jenis_tugas
               FROM activity_details ad
               WHERE ad.sales_activity_id = sa.id
               ORDER BY ad.id DESC
               LIMIT 1
           ) AS jenis_tugas
    FROM sales_activities sa
    LEFT JOIN accounts a ON sa.account_id = a.id
    LEFT JOIN users u ON sa.sales_id = u.id
    WHERE 1=1
      {$sqlFilterSA}
      {$sqlFilterSAMonth}
    ORDER BY sa.created_at DESC
    LIMIT {$activityLimit}
";

$recentActivities = $db->query($sqlActivities)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// HOT OPPORTUNITIES
// Mengambil aktivitas terakhir untuk setiap Account
// DALAM periode filter yang dipilih.
// ============================================
$sqlHotOpportunities = "
    SELECT latest.account_id,
           latest.nama_pt,
           latest.sales_name,
           latest.jenis_tugas,
           latest.subject,
           latest.created_at
    FROM (
        SELECT sa.account_id,
               a.nama_pt,
               u.full_name AS sales_name,
               ad.jenis_tugas,
               ad.subject,
               ad.created_at,
               ad.id,
               ROW_NUMBER() OVER (
                   PARTITION BY sa.account_id
                   ORDER BY ad.id DESC
               ) AS rn
        FROM sales_activities sa
        INNER JOIN activity_details ad
            ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a
            ON sa.account_id = a.id
        LEFT JOIN users u
            ON sa.sales_id = u.id
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IS NOT NULL
          AND TRIM(ad.jenis_tugas) <> ''
          {$sqlFilterSA}
          {$sqlFilterSAMonth}
    ) latest
    WHERE latest.rn = 1
      AND latest.jenis_tugas IN ('Negosiasi', 'Kontrak')
    ORDER BY latest.created_at DESC
    LIMIT 5
";

try {
    $hotOpportunities = $db->query($sqlHotOpportunities)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback MySQL lama tanpa ROW_NUMBER().
    $hotWhere = "
        sa2.account_id IS NOT NULL
        AND ad2.jenis_tugas IS NOT NULL
        AND TRIM(ad2.jenis_tugas) <> ''
        AND ad2.jenis_tugas IN ('Negosiasi', 'Kontrak')
    ";

    if ($filterSalesId > 0) {
        $hotWhere .= " AND sa2.sales_id = " . (int)$filterSalesId;
    }

    if ($filterMonth !== '') {
        $hotWhere .= " AND sa2.created_at >= " . $db->quote($monthStart) .
                     " AND sa2.created_at < " . $db->quote($monthEnd);
    }

    $sqlHotOpportunities = "
        SELECT sa.account_id,
               a.nama_pt,
               u.full_name AS sales_name,
               ad.jenis_tugas,
               ad.subject,
               ad.created_at
        FROM sales_activities sa
        INNER JOIN activity_details ad
            ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a
            ON sa.account_id = a.id
        LEFT JOIN users u
            ON sa.sales_id = u.id
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IN ('Negosiasi', 'Kontrak')
          " . ($filterSalesId > 0 ? " AND sa.sales_id = " . (int)$filterSalesId : "") . "
          " . ($filterMonth !== ''
                ? " AND sa.created_at >= " . $db->quote($monthStart) .
                  " AND sa.created_at < " . $db->quote($monthEnd)
                : "") . "
          AND ad.id = (
              SELECT MAX(ad2.id)
              FROM sales_activities sa2
              INNER JOIN activity_details ad2
                  ON ad2.sales_activity_id = sa2.id
              WHERE sa2.account_id = sa.account_id
                " . ($filterSalesId > 0 ? " AND sa2.sales_id = " . (int)$filterSalesId : "") . "
                " . ($filterMonth !== ''
                    ? " AND sa2.created_at >= " . $db->quote($monthStart) .
                      " AND sa2.created_at < " . $db->quote($monthEnd)
                    : "") . "
          )
        ORDER BY ad.created_at DESC
        LIMIT 5
    ";

    $hotOpportunities = $db->query($sqlHotOpportunities)->fetchAll(PDO::FETCH_ASSOC);
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CRM Panel - PT Ganda Elang Tangguh</title>
<link rel="icon" type="image/webp" href="images/favicon.webp">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/footer.css">
<link rel="stylesheet" href="css/navigation.css">
<style>
.month-filter-wrap{
    display:flex;
    align-items:center;
    gap:8px;
}
.month-filter-label{
    font-size:12px;
    font-weight:600;
    color:#8290aa;
    white-space:nowrap;
}
.month-filter{
    min-width:155px;
    cursor:pointer;
}
.btn-filter-reset{
    height:38px;
    padding:0 12px;
    border:1px solid rgba(148,163,184,.18);
    border-radius:8px;
    background:rgba(15,23,42,.72);
    color:#8290aa;
    font-size:12px;
    font-weight:600;
    cursor:pointer;
    transition:.18s ease;
}
.btn-filter-reset:hover,.btn-filter-reset.active{
    border-color:rgba(96,165,250,.45);
    background:rgba(59,130,246,.10);
    color:#dbeafe;
}
@media(max-width:768px){
    .month-filter-wrap{flex-wrap:wrap;}
    .month-filter{min-width:145px;}
}
</style>
</head>
<body>
<div class="app">
    <?php require_once 'navigation.php'; ?>

    <main class="content">
<section class="hero-row">
            <div class="hero">
                <div class="eyebrow">PT Ganda Elang Tangguh · Customer Relationship Management</div>
                <h1>Welcome, <?= htmlspecialchars($fullName) ?> 👋</h1>
                <p>Monitor your sales pipeline, customer activities and dealership performance.</p>
            </div>

            <div class="filters">
<?php if($canViewAllReport): ?>
<select class="filter" id="filterSales" onchange="applyFilter()">
    <option value="0" <?= $filterSalesId === 0 ? 'selected' : '' ?>>All Sales</option>
    <?php foreach($allSalesList as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= ($filterSalesId == $s['id']) ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['full_name']) ?>
        </option>
    <?php endforeach; ?>
</select>
<?php endif; ?>

<div class="month-filter-wrap">
    <label class="month-filter-label" for="filterMonth">Periode</label>
    <input
        class="filter month-filter"
        type="month"
        id="filterMonth"
        value="<?= htmlspecialchars($filterMonth) ?>"
        onchange="applyFilter()"
        aria-label="Filter tahun dan bulan"
    >
    <button
        type="button"
        class="btn-filter-reset <?= $isAllMonth ? 'active' : '' ?>"
        onclick="resetMonthFilter()"
    >
        All Month
    </button>
</div>

<button class="btn-add" onclick="location.href='account_management.php'">
    <i class="fas fa-plus me-1"></i> Add Lead
</button>
</div>
        </section>

        <section class="kpis">
<div class="kpi"><div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-users"></i></div><span class="trend">CRM</span></div><div class="number"><?= number_format($totalLeads) ?></div><div class="label">Total Account / Leads</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon cyan"><i class="fas fa-user-plus"></i></div><span class="trend">Filter</span></div><div class="number"><?= number_format($newLeads) ?></div><div class="label">New Leads</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon purple"><i class="fas fa-file-signature"></i></div><span class="trend">TR</span></div><div class="number"><?= number_format($totalTransactionRequests) ?></div><div class="label">Total Transaction Request</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon amber"><i class="fas fa-truck-moving"></i></div><span class="trend">Delivery</span></div><div class="number"><?= number_format($totalDeliveryOrders) ?></div><div class="label">Total Delivery Order</div></div>
</section>
<section class="dashboard-grid">
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-filter"></i> Sales Pipeline</div><div class="panel-sub">Current prospect movement by stage</div></div><span class="panel-sub"><?= htmlspecialchars($filteredSalesName) ?></span></div><div class="panel-body"><div class="pipeline">
<?php $pipe = [
    ['Suspect', 'blue', $pipelineCounts['Suspect'], 32],
    ['Prospect', 'cyan', $pipelineCounts['Prospect'], 52],
    ['Hot Prospect', 'amber', $pipelineCounts['Hot Prospect'], 68],
    ['Deal', 'green', $pipelineCounts['Deal'], 84],
    ['Lost Deal', 'red', $pipelineCounts['Lost Deal'], 30],
];

$pipelineColors = [
    'blue' => '#60a5fa',
    'cyan' => '#22d3ee',
    'amber' => '#fbbf24',
    'red' => '#fb7185',
    'green' => '#34d399',
    'purple' => '#a78bfa',
];
?>
<?php foreach ($pipe as $p):
    $c = $pipelineColors[$p[1]] ?? '#60a5fa';
?><div class="stage" style="--c:<?= $c ?>;--w:<?= $p[3] ?>%"><div class="stage-name"><?= htmlspecialchars($p[0]) ?></div><div class="stage-num"><?= number_format($p[2]) ?></div><div class="stage-meta">Accounts in stage</div><div class="stage-bar"><span></span></div></div><?php endforeach; ?>
                </div>
            </div>
        </div>
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-fire"></i> Hot Opportunities</div><div class="panel-sub">Highest priority prospects</div></div><a class="panel-link" href="salesactivity.php<?= $filterMonth !== '' ? '?month=' . urlencode($filterMonth) : '?' ?>jenis_prospek=Hot+Prospect&status=&sales_id=<?= (int)$filterSalesId ?>&search=">View all →</a></div><div class="panel-body hot-list">
<?php if($hotOpportunities): foreach($hotOpportunities as $i=>$a): ?><div class="hot-item"><div class="machine"><i class="fas fa-tractor"></i></div><div><div class="hot-name"><?= htmlspecialchars($a['nama_pt']??'-') ?></div><div class="hot-desc"><?= htmlspecialchars($a['jenis_tugas']??'Hot Prospect') ?> · <?= htmlspecialchars($a['subject']??'-') ?></div></div><div><div class="hot-value">#<?= $i+1 ?></div><div class="score">Hot Prospect</div><div class="scorebar" style="--score:<?= max(55,95-($i*8)) ?>%"><span></span></div></div></div><?php endforeach; else: ?><div class="empty">Belum ada Hot Prospect.</div><?php endif; ?></div></div>
</section>
<section class="lower">
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-chart-area"></i> Activity Performance</div><div class="panel-sub"><?= $isAllMonth ? 'All Month' : 'Daily sales activity for ' . date('F Y', strtotime($filterMonth . '-01')) ?></div></div></div><div class="panel-body"><div class="chart-wrap"><canvas id="trendChart"></canvas></div></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-bolt"></i> Recent Activity</div><div class="panel-sub">Latest CRM actions</div></div><a class="panel-link" href="salesactivity.php<?= $filterMonth !== '' ? '?month=' . urlencode($filterMonth) . ($filterSalesId > 0 ? '&sales_id=' . (int)$filterSalesId : '') : ($filterSalesId > 0 ? '?sales_id=' . (int)$filterSalesId : '') ?>">View all →</a></div><div class="activity-list"><?php if($recentActivities): foreach($recentActivities as $act): ?><div class="activity"><div class="act-icon"><i class="fas fa-file-lines"></i></div><div><div class="act-title"><?= htmlspecialchars($act['subject']??'-') ?></div><div class="act-desc"><?= htmlspecialchars($act['nama_pt']??'-') ?> · <?= htmlspecialchars($act['jenis_tugas']??'-') ?></div></div><div class="act-time"><?= date('d M H:i',strtotime($act['created_at'])) ?></div></div><?php endforeach; else: ?><div class="empty">Belum ada aktivitas.</div><?php endif; ?></div></div>
</section>

    <?php require_once 'footer.php'; ?>
</main>
</div>

<script>
const ctx=document.getElementById('trendChart').getContext('2d');
const gradient=ctx.createLinearGradient(0,0,0,260);gradient.addColorStop(0,'rgba(59,130,246,.30)');gradient.addColorStop(1,'rgba(59,130,246,0)');
new Chart(ctx,{type:'line',data:{labels:<?= json_encode($chartLabels) ?>,datasets:<?= json_encode($chartDatasets) ?>},options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{labels:{color:'#8290aa',usePointStyle:true,font:{family:'Inter',size:9}}},tooltip:{backgroundColor:'#0b1222',borderColor:'rgba(96,165,250,.3)',borderWidth:1,titleColor:'#fff',bodyColor:'#cbd5e1'}},scales:{y:{beginAtZero:true,grid:{color:'rgba(148,163,184,.08)'},ticks:{color:'#60708b',font:{size:9}}},x:{grid:{display:false},ticks:{color:'#60708b',font:{size:9},maxTicksLimit:10}}}}});
function applyFilter(){
    const salesEl = document.getElementById('filterSales');
    const monthEl = document.getElementById('filterMonth');
    const s = salesEl ? salesEl.value : <?= (int)$filterSalesId ?>;
    const m = monthEl ? monthEl.value : '';
    const params = new URLSearchParams();
    params.set('sales_id', s);
    // Jika memilih tahun + bulan, kirim periode tersebut.
    // Jika kosong, dashboard kembali ke All Month.
    if (m !== '') {
        params.set('month', m);
    }
    location.href = '?' + params.toString();
}

function resetMonthFilter(){
    const monthEl = document.getElementById('filterMonth');
    if (monthEl) monthEl.value = '';
    applyFilter();
}
</script>
</body></html>