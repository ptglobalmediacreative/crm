<?php
require_once 'config.php';

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
// HANDLE FILTER (AJAX & GET)
// ============================================
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;
$isSalesRole = ($role === 'sales');

if ($isSalesRole) {
    $filterSalesId = $userId;
}

$allSalesList = [];
if (!$isSalesRole) {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC");
    $allSalesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sqlFilter = "";
if ($filterSalesId > 0) {
    $sqlFilter = " AND sa.sales_id = $filterSalesId";
}

// ============================================
// DATA STATISTIK & PIPELINE
// ============================================
$sqlTotal = "SELECT COUNT(*) FROM sales_activities sa WHERE 1=1" . $sqlFilter;
$totalActivities = $db->query($sqlTotal)->fetchColumn();

$pipelineCounts = [
    'Middle Prospek' => 0,
    'Hot Prospek'    => 0,
    'Deal'           => 0,
    'Lost Prospek'   => 0
];

// Middle Prospek
$sqlMid = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           WHERE sa.jenis_tugas = 'Prospecting'
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas IN ('Negosiasi', 'Kontrak') AND account_id IS NOT NULL
           )" . $sqlFilter;
$pipelineCounts['Middle Prospek'] = (int)$db->query($sqlMid)->fetchColumn();

// Hot Prospek
$sqlHot = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           WHERE sa.jenis_tugas = 'Negosiasi'
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas = 'Kontrak' AND account_id IS NOT NULL
           )
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas = 'Negosiasi' AND status = 'completed' AND customer_deal = 'No' AND account_id IS NOT NULL
           )
           AND NOT (sa.status = 'completed' AND sa.customer_deal = 'No')" . $sqlFilter;
$pipelineCounts['Hot Prospek'] = (int)$db->query($sqlHot)->fetchColumn();

// Lost Prospek
$sqlLost = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Negosiasi'
            AND sa.status = 'completed' 
            AND sa.customer_deal = 'No'" . $sqlFilter;
$pipelineCounts['Lost Prospek'] = (int)$db->query($sqlLost)->fetchColumn();

// Deal (Kontrak)
$sqlDeal = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Kontrak'" . $sqlFilter;
$pipelineCounts['Deal'] = (int)$db->query($sqlDeal)->fetchColumn();

$pipelineLabels = array_keys($pipelineCounts);
$pipelineValues = array_values($pipelineCounts);
$filteredSalesName = ($filterSalesId > 0) ? ($db->query("SELECT full_name FROM users WHERE id = $filterSalesId")->fetchColumn() ?: 'Sales') : 'Semua Sales';

// ============================================
// DATA LAPORAN PER BULAN
// ============================================
function getSalesMonthlyReport($db, $salesId) {
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%M %Y') as month_label, 
               DATE_FORMAT(created_at, '%Y-%m') as month_sort, 
               COUNT(*) as total_activity,
               SUM(CASE WHEN status = 'Deal' THEN 1 ELSE 0 END) as total_deal,
               SUM(CASE WHEN status = 'Lost Prospek' THEN 1 ELSE 0 END) as total_lost
        FROM sales_activities 
        WHERE sales_id = ? 
        GROUP BY month_sort 
        ORDER BY month_sort DESC 
        LIMIT 6
    ");
    $stmt->execute([$salesId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filteredReportData = [];
if ($filterSalesId > 0) {
    $filteredReportData = getSalesMonthlyReport($db, $filterSalesId);
} else {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC");
    $allUsersForReport = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allUsersForReport as $u) {
        $filteredReportData[] = [
            'name' => $u['full_name'],
            'data' => getSalesMonthlyReport($db, $u['id'])
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; display: flex; }
        
        .sidebar { width: 260px; height: 100vh; background: #1a1a2e; position: fixed; top: 0; left: 0; z-index: 1000; padding: 20px; overflow-y: auto; transition: all 0.3s ease; }
        .sidebar .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); color: #fff; text-decoration: none; }
        .sidebar .brand .logo-wrapper { width: 40px; height: 40px; flex-shrink: 0; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text .brand-name { font-size: 15px; font-weight: 700; line-height: 1.2; }
        .sidebar .brand .brand-text .brand-name span { color: #ffd700; }
        .sidebar .brand .brand-text .brand-sub { font-size: 8px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar .menu-label { font-size: 11px; color: rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 1px; margin: 20px 0 10px 12px; font-weight: 600; }
        .sidebar .nav-link { display: flex; align-items: center; padding: 12px 16px; color: rgba(255,255,255,0.6); text-decoration: none; border-radius: 10px; margin-bottom: 4px; transition: all 0.3s ease; font-size: 14px; font-weight: 500; }
        .sidebar .nav-link i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255, 215, 0, 0.08); color: #fff; }
        .sidebar .nav-link.active { color: #ffd700; background: rgba(255, 215, 0, 0.1); box-shadow: inset 3px 0 0 #ffd700; }
        .sidebar .user-profile { margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 12px; }
        .sidebar .user-profile .avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255, 215, 0, 0.2); color: #ffd700; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .sidebar .user-profile .user-info .name { font-size: 14px; color: #fff; font-weight: 600; }
        .sidebar .user-profile .user-info .role { font-size: 11px; color: rgba(255,255,255,0.4); }
        .sidebar .logout-btn { display: flex; align-items: center; padding: 12px 16px; color: #ff6b6b; text-decoration: none; border-radius: 10px; margin-top: 10px; transition: all 0.3s ease; font-size: 14px; font-weight: 500; background: rgba(214, 48, 49, 0.1); }
        .sidebar .logout-btn:hover { background: rgba(214, 48, 49, 0.2); }

        .main-content { margin-left: 260px; padding: 30px 40px; width: 100%; min-height: 100vh; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h4 { font-weight: 700; color: #1a1a2e; margin: 0; font-size: 22px; }
        .page-header h4 span { color: #ffd700; }

        /* STAT CARDS - DIPERBESAR DAN DIPERJELAS */
        .stat-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); transition: transform 0.3s ease; height: 100%; text-align: center; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .stat-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin: 0 auto 10px auto; }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.15); color: #d4a017; }
        .stat-card .stat-icon.orange { background: rgba(243, 156, 18, 0.15); color: #f39c12; }
        .stat-card .stat-icon.red { background: rgba(231, 76, 60, 0.15); color: #e74c3c; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.15); color: #27ae60; }
        .stat-card .stat-icon.gray { background: rgba(149, 165, 166, 0.15); color: #7f8c8d; }
        
        .stat-card .stat-number { font-size: 30px; font-weight: 800; color: #1a1a2e; }
        .stat-card .stat-label { font-size: 13px; color: #888; font-weight: 500; margin-top: 2px; }

        .chart-container { background: #fff; border-radius: 16px; padding: 24px 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); margin-top: 20px; height: 100%; }
        
        .filter-control { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; margin-bottom: 15px; }
        .filter-control .form-select { width: auto; min-width: 200px; border-radius: 8px; }
        .filter-control .btn-group .btn { border-radius: 20px; padding: 4px 16px; font-size: 12px; border: 1px solid #dee2e6; }
        .filter-control .btn-group .btn.active { background: #1a1a2e; color: #fff; border-color: #1a1a2e; }

        /* REPORT CARD & TABLE */
        .report-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); margin-bottom: 20px; height: 100%; }
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f2f5; padding-bottom: 15px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;}
        .report-header h5 { font-weight: 700; margin: 0; color: #1a1a2e; }
        .table-report { font-size: 14px; width: 100%; }
        .table-report th { font-weight: 600; color: #888; padding: 12px 8px; border-bottom: 1px solid #eee; }
        .table-report td { padding: 12px 8px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
        .table-report tr:last-child td { border-bottom: none; }
        .text-deal { color: #27ae60; font-weight: 600; }
        .text-lost { color: #e74c3c; font-weight: 600; }

        /* PIPELINE ANGKA DI TENGAH DONUT */
        .pipeline-center-text {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            pointer-events: none;
        }
        .pipeline-center-text .big-number { font-size: 32px; font-weight: 800; color: #1a1a2e; display: block; }
        .pipeline-center-text .small-label { font-size: 11px; color: #888; font-weight: 500; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); width: 280px; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { display: flex !important; align-items: center; justify-content: center; width: 40px; height: 40px; background: #1a1a2e; color: #fff; border-radius: 8px; border: none; cursor: pointer; font-size: 18px; }
        }
        .mobile-toggle { display: none; }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="PT Ganda Elang Tangguh"></div>
            <div class="brand-text"><div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div><div class="brand-sub">CRM System</div></div>
        </a>
        <div class="menu-label">Menu Utama</div>
        <a href="dashboard.php" class="nav-link active"><i class="fas fa-th-large"></i> Dashboard</a>
        <?php if (in_array('account_management', $menuNames)): ?><a href="account_management.php" class="nav-link"><i class="fas fa-building"></i> Account</a><?php endif; ?>
        <?php if (in_array('sales_activity', $menuNames)): ?><a href="salesactivity.php" class="nav-link"><i class="fas fa-chart-bar"></i> Sales Activity</a><?php endif; ?>
        <?php if (in_array('transaction_request', $menuNames)): ?><a href="transactionrequest.php" class="nav-link"><i class="fas fa-file-signature"></i> TR Request</a><?php endif; ?>
        <?php if (in_array('detail_transaction_request', $menuNames)): ?><a href="detailtr.php" class="nav-link"><i class="fas fa-file-alt"></i> Detail TR</a><?php endif; ?>
        <?php if (in_array('produk', $menuNames)): ?><a href="produk.php" class="nav-link"><i class="fas fa-box"></i> Produk</a><?php endif; ?>
        <?php if (in_array('delivery_order', $menuNames)): ?><a href="#" class="nav-link"><i class="fas fa-tractor"></i> Delivery</a><?php endif; ?>
        <?php if (in_array('data_user', $menuNames)): ?><a href="data_user.php" class="nav-link"><i class="fas fa-users"></i> User</a><?php endif; ?>

        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info"><div class="name"><?= htmlspecialchars($fullName) ?></div><div class="role"><?= getRoleLabel($role) ?></div></div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt" style="margin-right:12px;"></i> Logout</a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="page-header">
            <div>
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
                <h4>Sales <span>Dashboard</span></h4>
            </div>
            <div style="background:#fff; padding: 8px 16px; border-radius: 8px; box-shadow:0 2px 5px rgba(0,0,0,0.03); font-size:14px; font-weight:500;"><?= date('d F Y') ?></div>
        </div>

        <!-- STATISTICS CARDS (Semua Angka Muncul di Sini) -->
        <div class="row g-4">
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon gold"><i class="fas fa-chart-line"></i></div><div class="stat-number"><?= number_format($totalActivities) ?></div><div class="stat-label">Total Aktivitas</div></div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-user-tie"></i></div><div class="stat-number"><?= number_format($pipelineCounts['Middle Prospek']) ?></div><div class="stat-label">Middle Prospek</div></div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon red"><i class="fas fa-fire"></i></div><div class="stat-number"><?= number_format($pipelineCounts['Hot Prospek']) ?></div><div class="stat-label">Hot Prospek</div></div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon green"><i class="fas fa-handshake"></i></div><div class="stat-number"><?= number_format($pipelineCounts['Deal']) ?></div><div class="stat-label">Deal (Kontrak)</div></div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon gray"><i class="fas fa-times-circle"></i></div><div class="stat-number"><?= number_format($pipelineCounts['Lost Prospek']) ?></div><div class="stat-label">Lost Prospek</div></div>
            </div>
            <div class="col-xl-2 col-lg-4 col-md-6">
                <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-user"></i></div><div class="stat-number" style="font-size: 20px;"><?= htmlspecialchars($filteredSalesName) ?></div><div class="stat-label">Data Sedang Ditinjau</div></div>
            </div>
        </div>

        <!-- FILTER & CHART SECTION -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <div class="filter-control">
                        <div class="d-flex align-items-center gap-3 flex-wrap w-100">
                            <?php if (!$isSalesRole): ?>
                            <div class="d-flex align-items-center gap-2">
                                <label class="fw-bold text-secondary small">Sales:</label>
                                <select class="form-select form-select-sm" id="filterSales" onchange="applyFilter()">
                                    <option value="0">-- Semua Sales --</option>
                                    <?php foreach ($allSalesList as $s): ?>
                                    <option value="<?= $s['id'] ?>" <?= ($filterSalesId == $s['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['full_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex align-items-center gap-2 ms-auto">
                                <label class="fw-bold text-secondary small">Periode:</label>
                                <div class="btn-group btn-group-sm" id="timeFilterGroup">
                                    <button class="btn btn-outline-secondary active" data-period="daily">Harian</button>
                                    <button class="btn btn-outline-secondary" data-period="weekly">Mingguan</button>
                                    <button class="btn btn-outline-secondary" data-period="monthly">Bulanan</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="height: 300px; width: 100%;">
                        <canvas id="trendChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- CHART KEDUA: PIPELINE & TABLE REPORT -->
        <div class="row mt-4">
            <div class="col-lg-5">
                <div class="chart-container" style="position: relative; height: 100%;">
                    <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-filter" style="color:#ffd700; margin-right:8px;"></i> Pipeline Prospek (Visual)</h6>
                    
                    <!-- Teks Angka di Tengah Donut -->
                    <div class="pipeline-center-text">
                        <span class="big-number"><?= array_sum($pipelineValues) ?></span>
                        <span class="small-label">Total Prospek</span>
                    </div>
                    
                    <div style="height: 250px; width: 100%;">
                        <canvas id="pipelineChart"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-7">
                <div class="report-card">
                    <div class="report-header">
                        <h5><i class="fas fa-clipboard-list" style="color:#ffd700; margin-right:8px;"></i> Laporan Performa Bulanan</h5>
                        <span class="badge-sales"><i class="fas fa-database me-1"></i> <?= htmlspecialchars($filteredSalesName) ?></span>
                    </div>
                    
                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                        <table class="table table-report table-sm">
                            <thead>
                                <tr>
                                    <th>Bulan</th>
                                    <th class="text-center">Total Aktivitas</th>
                                    <th class="text-center text-deal">Deal</th>
                                    <th class="text-center text-lost">Lost</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($filterSalesId > 0): ?>
                                    <!-- Mode: Detail Satu Sales -->
                                    <?php if (!empty($filteredReportData)): 
                                        $gt = 0; $gd = 0; $gl = 0;
                                        foreach($filteredReportData as $m) { $gt += $m['total_activity']; $gd += $m['total_deal']; $gl += $m['total_lost']; }
                                    ?>
                                        <?php foreach ($filteredReportData as $monthData): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($monthData['month_label']) ?></strong></td>
                                            <td class="text-center"><span class="badge bg-dark bg-opacity-10 text-dark"><?= $monthData['total_activity'] ?></span></td>
                                            <td class="text-center text-deal"><i class="fas fa-check-circle me-1"></i> <?= $monthData['total_deal'] ?></td>
                                            <td class="text-center text-lost"><i class="fas fa-times-circle me-1"></i> <?= $monthData['total_lost'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <tr style="border-top: 2px solid #1a1a2e;">
                                            <td><strong>TOTAL KESELURUHAN</strong></td>
                                            <td class="text-center"><strong><?= $gt ?></strong></td>
                                            <td class="text-center text-deal"><strong><?= $gd ?></strong></td>
                                            <td class="text-center text-lost"><strong><?= $gl ?></strong></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data aktivitas sales ini.</td></tr>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Mode: Semua Sales -->
                                    <?php if (!empty($filteredReportData)): ?>
                                        <?php foreach ($filteredReportData as $salesReport): ?>
                                            <tr style="background:#f8f9fa; border-top:1px solid #dee2e6;">
                                                <td colspan="4"><strong><i class="fas fa-user me-2"></i> <?= htmlspecialchars($salesReport['name']) ?></strong></td>
                                            </tr>
                                            <?php 
                                            $gt = 0; $gd = 0; $gl = 0;
                                            foreach($salesReport['data'] as $m) { $gt += $m['total_activity']; $gd += $m['total_deal']; $gl += $m['total_lost']; }
                                            ?>
                                            <?php foreach ($salesReport['data'] as $monthData): ?>
                                            <tr>
                                                <td style="padding-left: 30px;"><?= htmlspecialchars($monthData['month_label']) ?></td>
                                                <td class="text-center"><span class="badge bg-dark bg-opacity-10 text-dark"><?= $monthData['total_activity'] ?></span></td>
                                                <td class="text-center text-deal"><i class="fas fa-check-circle me-1"></i> <?= $monthData['total_deal'] ?></td>
                                                <td class="text-center text-lost"><i class="fas fa-times-circle me-1"></i> <?= $monthData['total_lost'] ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td style="padding-left: 30px;"><strong>Total <?= htmlspecialchars($salesReport['name']) ?></strong></td>
                                                <td class="text-center"><strong><?= $gt ?></strong></td>
                                                <td class="text-center text-deal"><strong><?= $gd ?></strong></td>
                                                <td class="text-center text-lost"><strong><?= $gl ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data sales.</td></tr>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // CHART UTAMA (TREN)
        // ============================================
        const ctxTrend = document.getElementById('trendChart').getContext('2d');
        let trendChart = new Chart(ctxTrend, {
            type: 'line',
            data: { labels: [], datasets: [{ label: 'Aktivitas', data: [], borderColor: '#ffd700', backgroundColor: 'rgba(255,215,0,0.1)', borderWidth: 3, fill: true, tension: 0.4, pointRadius: 5 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
        });

        // ============================================
        // CHART PIPELINE (DONUT) + ANGKA DI TENGAH
        // ============================================
        const ctxPipeline = document.getElementById('pipelineChart').getContext('2d');
        new Chart(ctxPipeline, {
            type: 'doughnut',
            data: { 
                labels: <?= json_encode($pipelineLabels) ?>, 
                datasets: [{ 
                    data: <?= json_encode($pipelineValues) ?>, 
                    backgroundColor: ['#f39c12','#e74c3c','#2ecc71','#95a5a6'], 
                    borderColor: '#fff', borderWidth: 3, hoverOffset: 10 
                }] 
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '72%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: { family: 'Inter', size: 12, weight: '500' } } }
                }
            }
        });

        // ============================================
        // LOGIKA FILTER
        // ============================================
        function applyFilter() {
            const salesId = document.getElementById('filterSales') ? document.getElementById('filterSales').value : 0;
            window.location.href = '?sales_id=' + salesId;
        }

        document.querySelectorAll('#timeFilterGroup .btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('#timeFilterGroup .btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const salesId = document.getElementById('filterSales') ? document.getElementById('filterSales').value : 0;
                const period = this.dataset.period;

                fetch(`api/get_trend_data.php?sales_id=${salesId}&period=${period}`)
                    .then(response => response.json())
                    .then(data => {
                        trendChart.data.labels = data.labels;
                        trendChart.data.datasets[0].data = data.values;
                        trendChart.update();
                    })
                    .catch(error => console.error('Error:', error));
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            const defaultBtn = document.querySelector('#timeFilterGroup .btn.active');
            if (defaultBtn) defaultBtn.click();
        });
    </script>
</body>
</html>