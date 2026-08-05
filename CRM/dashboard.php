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
// LOGIKA AMBIL DATA DARI DATABASE
// ============================================

// 1. STATISTIK TOTAL
if ($role === 'sales') {
    $totalActivities = $db->query("SELECT COUNT(*) FROM sales_activities WHERE sales_id = $userId")->fetchColumn();
} else {
    $totalActivities = $db->query("SELECT COUNT(*) FROM sales_activities")->fetchColumn();
}

// 2. DATA CHART HARIAN (7 HARI TERAKHIR)
// LOGIKA SQL: GROUP BY DATE(created_at)
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
// LOGIKA SQL: GROUP BY MONTH(created_at)
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

// 4. DATA CHART PER USER SALES (Hanya untuk Admin/Non-Sales)
$salesUsersData = [];
$salesUsersLabels = [];
if ($role !== 'sales') {
    // Ambil nama user dan total aktivitasnya
    $stmt = $db->query("
        SELECT u.full_name, COUNT(sa.id) as total_activity 
        FROM users u 
        LEFT JOIN sales_activities sa ON u.id = sa.sales_id 
        WHERE u.role = 'sales' OR u.role = 'sales_manager'
        GROUP BY u.id 
        ORDER BY total_activity DESC 
        LIMIT 10
    ");
    $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($salesData as $row) {
        $salesUsersLabels[] = $row['full_name'];
        $salesUsersData[] = $row['total_activity'];
    }
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
        body { font-family: 'Inter', sans-serif; background: #f5f6fa; display: flex; }

        /* ============================================
           SIDEBAR STYLING
           ============================================ */
        .sidebar {
            width: 260px; height: 100vh; background: #1a1a2e; position: fixed;
            top: 0; left: 0; z-index: 1000; padding: 20px; overflow-y: auto;
            transition: all 0.3s ease;
        }

        .sidebar .brand {
            display: flex; align-items: center; gap: 12px; margin-bottom: 30px;
            padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);
            color: #fff; text-decoration: none;
        }
        .sidebar .brand .logo-wrapper { width: 40px; height: 40px; flex-shrink: 0; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text .brand-name { font-size: 15px; font-weight: 700; line-height: 1.2; }
        .sidebar .brand .brand-text .brand-name span { color: #ffd700; }
        .sidebar .brand .brand-text .brand-sub { font-size: 8px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar .menu-label { font-size: 11px; color: rgba(255,255,255,0.3); text-transform: uppercase; letter-spacing: 1px; margin: 20px 0 10px 12px; font-weight: 600; }

        .sidebar .nav-link {
            display: flex; align-items: center; padding: 12px 16px;
            color: rgba(255,255,255,0.6); text-decoration: none; border-radius: 10px;
            margin-bottom: 4px; transition: all 0.3s ease; font-size: 14px; font-weight: 500;
        }
        .sidebar .nav-link i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255, 215, 0, 0.08); color: #fff; }
        .sidebar .nav-link.active { color: #ffd700; background: rgba(255, 215, 0, 0.1); box-shadow: inset 3px 0 0 #ffd700; }

        .sidebar .user-profile { margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 12px; }
        .sidebar .user-profile .avatar { width: 40px; height: 40px; border-radius: 50%; background: rgba(255, 215, 0, 0.2); color: #ffd700; display: flex; align-items: center; justify-content: center; font-weight: 700; }
        .sidebar .user-profile .user-info .name { font-size: 14px; color: #fff; font-weight: 600; }
        .sidebar .user-profile .user-info .role { font-size: 11px; color: rgba(255,255,255,0.4); }

        .sidebar .logout-btn { display: flex; align-items: center; padding: 12px 16px; color: #ff6b6b; text-decoration: none; border-radius: 10px; margin-top: 10px; transition: all 0.3s ease; font-size: 14px; font-weight: 500; background: rgba(214, 48, 49, 0.1); }
        .sidebar .logout-btn:hover { background: rgba(214, 48, 49, 0.2); }

        /* ============================================
           MAIN CONTENT STYLING
           ============================================ */
        .main-content { margin-left: 260px; padding: 30px 40px; width: 100%; min-height: 100vh; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .page-header h4 { font-weight: 700; color: #1a1a2e; margin: 0; font-size: 22px; }
        .page-header h4 span { color: #ffd700; }

        .stat-card { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); transition: transform 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-card .stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 16px; }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.12); color: #d4a017; }
        .stat-card .stat-icon.blue { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .stat-card .stat-icon.purple { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .stat-card .stat-number { font-size: 28px; font-weight: 800; color: #1a1a2e; }
        .stat-card .stat-label { font-size: 13px; color: #888; font-weight: 500; }

        .chart-container { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 10px rgba(0,0,0,0.03); border: 1px solid rgba(0,0,0,0.02); margin-top: 20px; }

        /* ============================================
           MOBILE & RESPONSIVE
           ============================================ */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); width: 280px; }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { display: flex !important; align-items: center; justify-content: center; width: 40px; height: 40px; background: #1a1a2e; color: #fff; border-radius: 8px; border: none; cursor: pointer; font-size: 18px; }
            .page-header { flex-wrap: wrap; gap: 15px; }
        }
        .mobile-toggle { display: none; }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="PT Ganda Elang Tangguh"></div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">CRM System</div>
            </div>
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
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt" style="margin-right:12px;"></i> Logout</a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="page-header">
            <div>
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <h4>Sales <span>Dashboard</span></h4>
            </div>
            <div style="display:flex; gap:10px; align-items:center;">
                <div style="background:#fff; padding: 8px 16px; border-radius: 8px; box-shadow:0 2px 5px rgba(0,0,0,0.03); font-size:14px; font-weight:500;">
                    <?= date('d F Y') ?>
                </div>
            </div>
        </div>

        <!-- STATISTICS CARDS -->
        <div class="row g-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon gold"><i class="fas fa-chart-line"></i></div>
                    <div class="stat-number"><?= number_format($totalActivities) ?></div>
                    <div class="stat-label">Total Aktivitas</div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-calendar-week"></i></div>
                    <div class="stat-number"><?= date('M Y') ?></div>
                    <div class="stat-label">Bulan Berjalan</div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?= getRoleLabel($role) ?></div>
                    <div class="stat-label">Role User</div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?= date('H:i') ?></div>
                    <div class="stat-label">Jam Sekarang</div>
                </div>
            </div>
        </div>

        <!-- CHART 1: HARIAN -->
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="chart-container">
                    <h5 style="font-weight:600; color:#1a1a2e; margin-bottom:20px;">
                        <i class="fas fa-chart-bar" style="color:#ffd700; margin-right:8px;"></i> 
                        Tren 7 Hari Terakhir
                    </h5>
                    <div style="height: 260px; width: 100%;">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- CHART 2: BULANAN -->
            <div class="col-lg-6">
                <div class="chart-container">
                    <h5 style="font-weight:600; color:#1a1a2e; margin-bottom:20px;">
                        <i class="fas fa-calendar-alt" style="color:#ffd700; margin-right:8px;"></i> 
                        Tren 12 Bulan Terakhir
                    </h5>
                    <div style="height: 260px; width: 100%;">
                        <canvas id="monthlyChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- CHART 3: PER SALES (Hanya untuk Admin/Manager) -->
        <?php if ($role !== 'sales' && count($salesUsersLabels) > 0): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="chart-container">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
                        <h5 style="font-weight:600; color:#1a1a2e; margin:0;">
                            <i class="fas fa-user-tie" style="color:#ffd700; margin-right:8px;"></i> 
                            Kontribusi Aktivitas Per Sales
                        </h5>
                        <span style="background:rgba(255,215,0,0.1); padding:4px 12px; border-radius:20px; font-size:12px; color:#d4a017;">
                            <i class="fas fa-database"></i> Data All Sales
                        </span>
                    </div>
                    <div style="height: 300px; width: 100%;">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // CHART 1: HARIAN
        // ============================================
        const ctxDaily = document.getElementById('dailyChart').getContext('2d');
        const gradientDaily = ctxDaily.createLinearGradient(0, 0, 0, 300);
        gradientDaily.addColorStop(0, 'rgba(255, 215, 0, 0.6)');
        gradientDaily.addColorStop(1, 'rgba(255, 215, 0, 0.0)');

        new Chart(ctxDaily, {
            type: 'bar',
            data: {
                labels: <?= json_encode($labelsDaily) ?>,
                datasets: [{
                    label: 'Aktivitas Harian',
                    data: <?= json_encode($valuesDaily) ?>,
                    backgroundColor: gradientDaily,
                    borderColor: '#d4a017',
                    borderWidth: 2,
                    borderRadius: 6,
                    barPercentage: 0.6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });

        // ============================================
        // CHART 2: BULANAN
        // ============================================
        const ctxMonthly = document.getElementById('monthlyChart').getContext('2d');
        const gradientMonthly = ctxMonthly.createLinearGradient(0, 0, 0, 300);
        gradientMonthly.addColorStop(0, 'rgba(52, 152, 219, 0.8)');
        gradientMonthly.addColorStop(1, 'rgba(52, 152, 219, 0.0)');

        new Chart(ctxMonthly, {
            type: 'line',
            data: {
                labels: <?= json_encode($labelsMonthly) ?>,
                datasets: [{
                    label: 'Aktivitas Bulanan',
                    data: <?= json_encode($valuesMonthly) ?>,
                    backgroundColor: gradientMonthly,
                    borderColor: '#2980b9',
                    borderWidth: 3,
                    pointBackgroundColor: '#2980b9',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    fill: true,
                    tension: 0.4,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });

        // ============================================
        // CHART 3: PER USER SALES
        // ============================================
        <?php if ($role !== 'sales' && count($salesUsersLabels) > 0): ?>
        const ctxUser = document.getElementById('userChart').getContext('2d');
        const gradientUser = ctxUser.createLinearGradient(0, 0, 0, 300);
        gradientUser.addColorStop(0, 'rgba(46, 204, 113, 0.7)');
        gradientUser.addColorStop(1, 'rgba(46, 204, 113, 0.0)');

        new Chart(ctxUser, {
            type: 'bar',
            data: {
                labels: <?= json_encode($salesUsersLabels) ?>,
                datasets: [{
                    label: 'Total Aktivitas',
                    data: <?= json_encode($salesUsersData) ?>,
                    backgroundColor: gradientUser,
                    borderColor: '#27ae60',
                    borderWidth: 2,
                    borderRadius: 6,
                    barPercentage: 0.6,
                }]
            },
            options: {
                indexAxis: 'y', // MEMBUAT CHART MENDATAR (Lebih mudah dibaca untuk list nama)
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { grid: { display: false } },
                    x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>