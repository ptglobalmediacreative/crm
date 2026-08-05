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
// DATA GLOBAL - GRAFIK DASHBOARD
// ============================================

// 1. STATISTIK TOTAL
if ($role === 'sales') {
    $totalActivities = $db->query("SELECT COUNT(*) FROM sales_activities WHERE sales_id = $userId")->fetchColumn();
} else {
    $totalActivities = $db->query("SELECT COUNT(*) FROM sales_activities")->fetchColumn();
}

// 2. DATA CHART HARIAN (7 HARI TERAKHIR)
$chartQueryDaily = "SELECT DATE(created_at) as date, COUNT(*) as total FROM sales_activities";
if ($role === 'sales') $chartQueryDaily .= " WHERE sales_id = $userId";
$chartQueryDaily .= " GROUP BY DATE(created_at) ORDER BY date ASC LIMIT 7";

$chartDailyData = $db->query($chartQueryDaily)->fetchAll(PDO::FETCH_ASSOC);

$labelsDaily = [];
$valuesDaily = [];
$tempDaily = [];
foreach ($chartDailyData as $row) $tempDaily[$row['date']] = $row['total'];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labelsDaily[] = date('d M', strtotime("-$i days"));
    $valuesDaily[] = isset($tempDaily[$date]) ? $tempDaily[$date] : 0;
}

// 3. DATA CHART BULANAN (12 BULAN TERAKHIR)
$chartQueryMonthly = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as total FROM sales_activities";
if ($role === 'sales') $chartQueryMonthly .= " WHERE sales_id = $userId";
$chartQueryMonthly .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC LIMIT 12";

$chartMonthlyData = $db->query($chartQueryMonthly)->fetchAll(PDO::FETCH_ASSOC);

$labelsMonthly = [];
$valuesMonthly = [];
$tempMonthly = [];
foreach ($chartMonthlyData as $row) $tempMonthly[$row['month']] = $row['total'];
for ($i = 11; $i >= 0; $i--) {
    $date = date('Y-m', strtotime("-$i months"));
    $labelsMonthly[] = date('M Y', strtotime("-$i months"));
    $valuesMonthly[] = isset($tempMonthly[$date]) ? $tempMonthly[$date] : 0;
}

// 4. DATA PIPELINE PROSPEK (Donut Chart)
$statusQuery = "SELECT status, COUNT(*) as total FROM sales_activities";
if ($role === 'sales') $statusQuery .= " WHERE sales_id = $userId";
$statusQuery .= " GROUP BY status";

$statusData = $db->query($statusQuery)->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [
    'Middle Prospek' => 0,
    'Hot Prospek'    => 0,
    'Deal'           => 0,
    'Lost Prospek'   => 0
];

foreach ($statusData as $row) {
    $statusName = trim($row['status']);
    if (isset($statusCounts[$statusName])) {
        $statusCounts[$statusName] = (int)$row['total'];
    }
}
$pipelineLabels = array_keys($statusCounts);
$pipelineValues = array_values($statusCounts);

// ============================================
// DATA LAPORAN PER SALES & PER BULAN
// ============================================

$salesReports = [];

if ($role === 'sales') {
    // Jika user Sales login, tampilkan laporan dirinya sendiri
    $salesReports[] = [
        'id' => $userId,
        'name' => $fullName,
        'data' => getSalesMonthlyReport($db, $userId)
    ];
} else {
    // Jika Admin login, tampilkan laporan semua Sales & Manager
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC");
    $allSales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allSales as $sale) {
        $salesReports[] = [
            'id' => $sale['id'],
            'name' => $sale['full_name'],
            'data' => getSalesMonthlyReport($db, $sale['id'])
        ];
    }
}

// FUNGSI BANTUAN UNTUK MENGAMBIL REPORT PER SALES
function getSalesMonthlyReport($db, $salesId) {
    // Ambil per bulan: Total Aktivitas
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
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; display: flex; }

        /* SIDEBAR STYLING */
        .sidebar {
            width: 260px; height: 100vh; background: #1a1a2e; position: fixed;
            top: 0; left: 0; z-index: 1000; padding: 20px; overflow-y: auto;
            transition: all 0.3s ease;
        }
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

        /* MAIN CONTENT */
        .main-content { margin-left: 260px; padding: 30px 40px; width: 100%; min-height: 100vh; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h4 { font-weight: 700; color: #1a1a2e; margin: 0; font-size: 22px; }
        .page-header h4 span { color: #ffd700; }

        /* STAT CARDS */
        .stat-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); transition: transform 0.3s ease; height: 100%; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 16px; }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.12); color: #d4a017; }
        .stat-card .stat-icon.blue { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .stat-card .stat-icon.purple { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .stat-card .stat-number { font-size: 28px; font-weight: 800; color: #1a1a2e; }
        .stat-card .stat-label { font-size: 13px; color: #888; font-weight: 500; }

        /* CHART CONTAINER */
        .chart-container { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); margin-top: 20px; height: 100%; }

        /* SALES REPORT TABLE & CARDS */
        .report-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); margin-bottom: 20px; }
        .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f2f5; padding-bottom: 15px; margin-bottom: 15px; flex-wrap: wrap; gap: 10px;}
        .report-header h5 { font-weight: 700; margin: 0; color: #1a1a2e; }
        .report-header .badge-sales { background: rgba(255, 215, 0, 0.1); color: #d4a017; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; }

        .table-report { font-size: 14px; width: 100%; }
        .table-report th { font-weight: 600; color: #888; padding: 12px 8px; border-bottom: 1px solid #eee; }
        .table-report td { padding: 12px 8px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
        .table-report tr:last-child td { border-bottom: none; }
        .text-deal { color: #27ae60; font-weight: 600; }
        .text-lost { color: #e74c3c; font-weight: 600; }

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

        <?php if (in_array('account_management', $menuNames)): ?>
            <a href="account_management.php" class="nav-link"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        <?php if (in_array('sales_activity', $menuNames)): ?>
            <a href="salesactivity.php" class="nav-link"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-link"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        <?php if (in_array('detail_transaction_request', $menuNames)): ?>
            <a href="detailtr.php" class="nav-link"><i class="fas fa-file-alt"></i> Detail TR</a>
        <?php endif; ?>
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-link"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="#" class="nav-link"><i class="fas fa-tractor"></i> Delivery</a>
        <?php endif; ?>
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-link"><i class="fas fa-users"></i> User</a>
        <?php endif; ?>

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

        <!-- STATISTICS CARDS -->
        <div class="row g-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card"><div class="stat-icon gold"><i class="fas fa-chart-line"></i></div><div class="stat-number"><?= number_format($totalActivities) ?></div><div class="stat-label">Total Aktivitas Global</div></div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-calendar-week"></i></div><div class="stat-number"><?= date('M Y') ?></div><div class="stat-label">Bulan Berjalan</div></div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card"><div class="stat-icon green"><i class="fas fa-users"></i></div><div class="stat-number"><?= getRoleLabel($role) ?></div><div class="stat-label">Role User</div></div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-clock"></i></div><div class="stat-number"><?= date('H:i') ?></div><div class="stat-label">Jam Sekarang</div></div>
            </div>
        </div>

        <!-- CHART 1 & 2: HARIAN & BULANAN -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="chart-container">
                    <h5 style="font-weight:600; color:#1a1a2e; margin-bottom:20px;"><i class="fas fa-chart-bar" style="color:#ffd700; margin-right:8px;"></i> Tren 7 Hari Terakhir</h5>
                    <div style="height: 260px; width: 100%;"><canvas id="dailyChart"></canvas></div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="chart-container">
                    <h5 style="font-weight:600; color:#1a1a2e; margin-bottom:20px;"><i class="fas fa-calendar-alt" style="color:#ffd700; margin-right:8px;"></i> Tren 12 Bulan Terakhir</h5>
                    <div style="height: 260px; width: 100%;"><canvas id="monthlyChart"></canvas></div>
                </div>
            </div>
        </div>

        <!-- CHART 3 & 4: PIPELINE & KONTRIBUSI SALES -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="chart-container">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
                        <h5 style="font-weight:600; color:#1a1a2e; margin:0;"><i class="fas fa-filter" style="color:#ffd700; margin-right:8px;"></i> Pipeline Prospek</h5>
                        <span style="background:rgba(255,215,0,0.1); padding:4px 12px; border-radius:20px; font-size:12px; color:#d4a017;"><i class="fas fa-chart-pie"></i> Distribusi Status</span>
                    </div>
                    <div style="height: 280px; width: 100%;"><canvas id="pipelineChart"></canvas></div>
                </div>
            </div>
            <?php if ($role !== 'sales' && count($salesReports) > 1): ?>
            <div class="col-lg-6">
                <div class="chart-container">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
                        <h5 style="font-weight:600; color:#1a1a2e; margin:0;"><i class="fas fa-user-tie" style="color:#ffd700; margin-right:8px;"></i> Kontribusi Per Sales</h5>
                        <span style="background:rgba(255,215,0,0.1); padding:4px 12px; border-radius:20px; font-size:12px; color:#d4a017;"><i class="fas fa-database"></i> Data All Sales</span>
                    </div>
                    <?php 
                    $topSalesLabels = [];
                    $topSalesData = [];
                    foreach($salesReports as $report) {
                        $total = 0;
                        foreach($report['data'] as $month) { $total += $month['total_activity']; }
                        $topSalesLabels[] = $report['name'];
                        $topSalesData[] = $total;
                    }
                    ?>
                    <div style="height: 280px; width: 100%;"><canvas id="userChart"></canvas></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ============================================
        SECTION LAPORAN DETAIL PER SALES PER BULAN
        ============================================ -->
        <div class="mt-5 mb-4">
            <h4 style="font-weight:700; color:#1a1a2e; margin-bottom:20px;"><i class="fas fa-clipboard-list" style="color:#ffd700; margin-right:8px;"></i> Laporan Performa Sales (Per Bulan)</h4>
            
            <?php if (!empty($salesReports)): ?>
                <div class="row">
                    <?php foreach ($salesReports as $report): ?>
                    <div class="col-xl-6 col-lg-12 mb-4">
                        <div class="report-card">
                            <div class="report-header">
                                <h5><i class="fas fa-user-circle" style="color:#ffd700; margin-right:8px;"></i> <?= htmlspecialchars($report['name']) ?></h5>
                                <span class="badge-sales"><i class="fas fa-chart-simple me-1"></i> Sales Report</span>
                            </div>
                            
                            <?php if (!empty($report['data'])): ?>
                            <div class="table-responsive">
                                <table class="table table-report">
                                    <thead>
                                        <tr>
                                            <th>Bulan</th>
                                            <th class="text-center">Total Aktivitas</th>
                                            <th class="text-center text-deal">Deal</th>
                                            <th class="text-center text-lost">Lost</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report['data'] as $monthData): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($monthData['month_label']) ?></strong></td>
                                            <td class="text-center"><span class="badge bg-dark bg-opacity-10 text-dark"><?= $monthData['total_activity'] ?></span></td>
                                            <td class="text-center text-deal"><i class="fas fa-check-circle me-1"></i> <?= $monthData['total_deal'] ?></td>
                                            <td class="text-center text-lost"><i class="fas fa-times-circle me-1"></i> <?= $monthData['total_lost'] ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php 
                                        // Tambahkan baris total keseluruhan
                                        $grandTotal = 0; $grandDeal = 0; $grandLost = 0;
                                        foreach($report['data'] as $m) { 
                                            $grandTotal += $m['total_activity']; 
                                            $grandDeal += $m['total_deal']; 
                                            $grandLost += $m['total_lost']; 
                                        }
                                        ?>
                                        <tr style="border-top: 2px solid #1a1a2e;">
                                            <td><strong>TOTAL</strong></td>
                                            <td class="text-center"><strong><?= $grandTotal ?></strong></td>
                                            <td class="text-center text-deal"><strong><?= $grandDeal ?></strong></td>
                                            <td class="text-center text-lost"><strong><?= $grandLost ?></strong></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-3 mb-0"><i class="fas fa-inbox me-2"></i> Belum ada aktivitas untuk sales ini.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-light text-center border">Belum ada data sales untuk ditampilkan.</div>
            <?php endif; ?>
        </div>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // CHART 1: HARIAN
        const ctxDaily = document.getElementById('dailyChart').getContext('2d');
        const gradientDaily = ctxDaily.createLinearGradient(0, 0, 0, 300);
        gradientDaily.addColorStop(0, 'rgba(255, 215, 0, 0.6)'); gradientDaily.addColorStop(1, 'rgba(255, 215, 0, 0.0)');
        new Chart(ctxDaily, {
            type: 'bar', data: { labels: <?= json_encode($labelsDaily) ?>, datasets: [{ label: 'Aktivitas Harian', data: <?= json_encode($valuesDaily) ?>, backgroundColor: gradientDaily, borderColor: '#d4a017', borderWidth: 2, borderRadius: 6, barPercentage: 0.6, }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
        });

        // CHART 2: BULANAN
        const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
        const gradientMonthly = ctxMonthly.createLinearGradient(0, 0, 0, 300);
        gradientMonthly.addColorStop(0, 'rgba(52, 152, 219, 0.8)'); gradientMonthly.addColorStop(1, 'rgba(52, 152, 219, 0.0)');
        new Chart(ctxMonthly, {
            type: 'line', data: { labels: <?= json_encode($labelsMonthly) ?>, datasets: [{ label: 'Aktivitas Bulanan', data: <?= json_encode($valuesMonthly) ?>, backgroundColor: gradientMonthly, borderColor: '#2980b9', borderWidth: 3, pointBackgroundColor: '#2980b9', pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 5, fill: true, tension: 0.4, }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } }, x: { grid: { display: false } } } }
        });

        // CHART 3: PIPELINE PROSPEK
        const ctxPipeline = document.getElementById('pipelineChart').getContext('2d');
        new Chart(ctxPipeline, {
            type: 'doughnut', data: { labels: <?= json_encode($pipelineLabels) ?>, datasets: [{ data: <?= json_encode($pipelineValues) ?>, backgroundColor: ['#f39c12','#e74c3c','#2ecc71','#95a5a6'], borderColor: '#fff', borderWidth: 3, hoverOffset: 10 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, font: { family: 'Inter', size: 12, weight: '500' } } } } }
        });

        // CHART 4: PER USER SALES (Hanya Admin)
        <?php if ($role !== 'sales' && count($topSalesLabels) > 0): ?>
        const ctxUser = document.getElementById('userChart').getContext('2d');
        const gradientUser = ctxUser.createLinearGradient(0, 0, 0, 300);
        gradientUser.addColorStop(0, 'rgba(46, 204, 113, 0.7)'); gradientUser.addColorStop(1, 'rgba(46, 204, 113, 0.0)');
        new Chart(ctxUser, {
            type: 'bar', data: { labels: <?= json_encode($topSalesLabels) ?>, datasets: [{ label: 'Total Aktivitas', data: <?= json_encode($topSalesData) ?>, backgroundColor: gradientUser, borderColor: '#27ae60', borderWidth: 2, borderRadius: 6, barPercentage: 0.6, }] },
            options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { grid: { display: false } }, x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } } } }
        });
        <?php endif; ?>
    </script>
</body>
</html>