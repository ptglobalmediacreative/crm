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
// HANDLE FILTER SALES & BULAN
// ============================================
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;
$isSalesRole = ($role === 'sales');

// Filter Bulan (Default ke bulan sekarang)
if (isset($_GET['month']) && !empty($_GET['month'])) {
    $filterMonth = $_GET['month'];
} else {
    $filterMonth = date('Y-m');
}

if ($isSalesRole) {
    $filterSalesId = $userId;
}

$allSalesList = [];
if (!$isSalesRole) {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC");
    $allSalesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter SQL untuk Sales_Activities (Pipeline, Chart, Recent Activities)
// --- PERBAIKAN PENTING DI SINI ---
$sqlFilterSA = "";
if ($filterSalesId > 0) {
    $sqlFilterSA = " AND sa.sales_id = $filterSalesId";
}

// Filter SQL untuk Accounts (Total Leads, New Leads bulan ini)
$sqlFilterAcc = "";
if ($filterSalesId > 0) {
    $sqlFilterAcc = " AND sales_id = $filterSalesId";
}

// Filter SQL untuk Detail TR (Revenue Forecast)
$sqlFilterTR = "";
if ($filterSalesId > 0) {
    $sqlFilterTR = " AND sa.sales_id = $filterSalesId";
}

// ============================================
// DATA STATISTIK & PIPELINE
// ============================================
// 1. Total Leads (Dari tabel accounts)
if ($isSalesRole) {
    $sqlTotalLeads = "SELECT COUNT(*) FROM accounts WHERE sales_id = $userId";
} else {
    $sqlTotalLeads = "SELECT COUNT(*) FROM accounts WHERE 1=1" . $sqlFilterAcc;
}
$totalLeads = $db->query($sqlTotalLeads)->fetchColumn();

// 2. Pipeline Data (Dari sales_activities & accounts)
$sqlTotal = "SELECT COUNT(*) FROM sales_activities sa WHERE 1=1" . $sqlFilterSA;
$totalActivities = $db->query($sqlTotal)->fetchColumn();

$pipelineCounts = [
    'New Lead'     => 0,
    'Middle Prospek' => 0,
    'Hot Prospek'    => 0,
    'Deal'           => 0,
    'Lost Deal'      => 0
];

// --- NEW LEAD (Dari Account Management - 30 Hari Terakhir) ---
$sqlNewLead = "SELECT COUNT(*) FROM accounts WHERE 1=1" . $sqlFilterAcc . " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$pipelineCounts['New Lead'] = (int)$db->query($sqlNewLead)->fetchColumn();

// --- MIDDLE PROSPEK ---
$sqlMid = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           WHERE sa.jenis_tugas = 'Prospecting'
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas IN ('Negosiasi', 'Kontrak') AND account_id IS NOT NULL
           )" . $sqlFilterSA;
$pipelineCounts['Middle Prospek'] = (int)$db->query($sqlMid)->fetchColumn();

// --- HOT PROSPEK ---
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
           AND NOT (sa.status = 'completed' AND sa.customer_deal = 'No')" . $sqlFilterSA;
$pipelineCounts['Hot Prospek'] = (int)$db->query($sqlHot)->fetchColumn();

// --- LOST DEAL ---
$sqlLost = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Negosiasi'
            AND sa.status = 'completed' 
            AND sa.customer_deal = 'No'" . $sqlFilterSA;
$pipelineCounts['Lost Deal'] = (int)$db->query($sqlLost)->fetchColumn();

// --- DEAL (Kontrak) ---
$sqlDeal = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Kontrak'" . $sqlFilterSA;
$pipelineCounts['Deal'] = (int)$db->query($sqlDeal)->fetchColumn();

// 3. Revenue Forecast (Dari Tabel detail_transaction_requests)
if ($isSalesRole) {
    $sqlRevenue = "SELECT SUM(dtr.grand_total) 
                   FROM detail_transaction_requests dtr
                   LEFT JOIN sales_activities sa ON dtr.trf_number = sa.trf_number
                   WHERE sa.sales_id = $userId";
} else {
    $sqlRevenue = "SELECT SUM(dtr.grand_total) 
                   FROM detail_transaction_requests dtr
                   LEFT JOIN sales_activities sa ON dtr.trf_number = sa.trf_number
                   WHERE 1=1" . $sqlFilterTR;
}
$totalRevenue = (float)$db->query($sqlRevenue)->fetchColumn();
if (!$totalRevenue) $totalRevenue = 0;

$filteredSalesName = ($filterSalesId > 0) ? ($db->query("SELECT full_name FROM users WHERE id = $filterSalesId")->fetchColumn() ?: 'Sales') : 'Semua Sales';

// ============================================
// DATA CHART TREN (PERBANDINGAN MULTI SALES ATAU TUNGGAL)
// ============================================
$chartLabels = [];
$chartDatasets = []; // Kumpulan data untuk Chart.js (Bisa 1 atau banyak garis)

// Tentukan warna untuk setiap Sales (bisa ditambah sesuai jumlah sales)
$colorPalette = [
    '#e74c3c', // Merah
    '#3498db', // Biru
    '#2ecc71', // Hijau
    '#f39c12', // Oranye
    '#9b59b6', // Ungu
    '#1abc9c', // Tosca
    '#e67e22', // Oranye Tua
    '#34495e'  // Abu-abu Gelap
];

// 1. Jika filter memilih Sales Tertentu (atau Role Sales) -> Tampilkan 1 Garis
if ($filterSalesId > 0) {
    // Ambil data aktivitas dari database untuk sales tersebut
    $chartQuery = "SELECT DATE(created_at) as date, COUNT(*) as total FROM sales_activities 
                   WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND sales_id = ? 
                   GROUP BY DATE(created_at) ORDER BY date ASC"; 

    $stmt = $db->prepare($chartQuery);
    $stmt->execute([$filterMonth, $filterSalesId]);
    $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dataMap = [];
    foreach ($chartData as $row) {
        $dataMap[$row['date']] = (int)$row['total'];
    }

    $year = substr($filterMonth, 0, 4);
    $month = substr($filterMonth, 5, 2);
    $totalDays = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    
    $values = [];
    for ($day = 1; $day <= $totalDays; $day++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $chartLabels[] = date('d M', strtotime($dateKey));
        $values[] = isset($dataMap[$dateKey]) ? $dataMap[$dateKey] : 0;
    }

    // Buat dataset untuk 1 garis
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
    // 2. Jika Filter "Semua Sales" -> Tampilkan Garis Perbandingan (Multi Line)
    $year = substr($filterMonth, 0, 4);
    $month = substr($filterMonth, 5, 2);
    $totalDays = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    
    // Buat label tanggal dulu (dari 1 sampai akhir bulan)
    for ($day = 1; $day <= $totalDays; $day++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $chartLabels[] = date('d M', strtotime($dateKey));
    }

    // Loop setiap Sales untuk mengambil datanya
    $colorIndex = 0;
    foreach ($allSalesList as $sales) {
        $sId = $sales['id'];
        $sName = $sales['full_name'];

        $chartQuery = "SELECT DATE(created_at) as date, COUNT(*) as total FROM sales_activities 
                       WHERE DATE_FORMAT(created_at, '%Y-%m') = ? AND sales_id = ? 
                       GROUP BY DATE(created_at) ORDER BY date ASC"; 
        $stmt = $db->prepare($chartQuery);
        $stmt->execute([$filterMonth, $sId]);
        $chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dataMap = [];
        foreach ($chartData as $row) {
            $dataMap[$row['date']] = (int)$row['total'];
        }

        $values = [];
        for ($day = 1; $day <= $totalDays; $day++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $values[] = isset($dataMap[$dateKey]) ? $dataMap[$dateKey] : 0;
        }

        // Ambil warna dari palette, looping jika sales lebih banyak dari warna
        $color = $colorPalette[$colorIndex % count($colorPalette)];

        $chartDatasets[] = [
            'label' => htmlspecialchars($sName),
            'data' => $values,
            'backgroundColor' => hexToRgba($color, 0.2),
            'borderColor' => $color,
            'borderWidth' => 2,
            'fill' => false, // Untuk multi-line, biasanya fill dimatikan agar tidak saling timpa
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

// Helper function untuk mengubah Hex ke RGBA (untuk background gradient)
function hexToRgba($hex, $alpha) {
    list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
    return "rgba($r, $g, $b, $alpha)";
}

// ============================================
// DATA AKTIVITAS TERBARU (TIMELINE)
// ============================================
$activityLimit = 5;
// PERBAIKAN: Pastikan $sqlFilterSA digunakan di sini
$sqlActivities = "SELECT sa.*, a.nama_pt, u.full_name as sales_name
                  FROM sales_activities sa 
                  LEFT JOIN accounts a ON sa.account_id = a.id 
                  LEFT JOIN users u ON sa.sales_id = u.id
                  WHERE 1=1" . $sqlFilterSA . "
                  ORDER BY sa.created_at DESC 
                  LIMIT $activityLimit";
$recentActivities = $db->query($sqlActivities)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DATA LAPORAN PER BULAN (PERFORMA SALES - SESUAI BULAN FILTER)
// ============================================
function getSalesMonthlyReport($db, $salesId, $month) {
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%M %Y') as month_label, 
               DATE_FORMAT(created_at, '%Y-%m') as month_sort, 
               COUNT(*) as total_activity,
               SUM(CASE WHEN status = 'Deal' THEN 1 ELSE 0 END) as total_deal,
               SUM(CASE WHEN status = 'Lost Prospek' THEN 1 ELSE 0 END) as total_lost
        FROM sales_activities 
        WHERE sales_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?
        GROUP BY month_sort 
        ORDER BY month_sort DESC 
    ");
    $stmt->execute([$salesId, $month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filteredReportData = [];
if ($filterSalesId > 0) {
    $filteredReportData = getSalesMonthlyReport($db, $filterSalesId, $filterMonth);
} else {
    $stmt = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC");
    $allUsersForReport = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allUsersForReport as $u) {
        $filteredReportData[] = [
            'name' => $u['full_name'],
            'data' => getSalesMonthlyReport($db, $u['id'], $filterMonth)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PT Ganda Elang Tangguh</title>
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; display: flex; min-height: 100vh; }
        
        /* ---- SIDEBAR MODERN (Deep Navy Blue) ---- */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: #0e1a2b; /* Deep Navy Blue Modern */
            position: fixed;
            top: 0; left: 0; bottom: 0;
            padding: 30px 20px;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 215, 0, 0.3); border-radius: 10px; }

        .sidebar .brand { 
            display: flex; align-items: center; gap: 12px; margin-bottom: 40px; text-decoration: none; 
            padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar .brand .logo-wrapper { width: 42px; height: 42px; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text h5 { font-weight: 800; margin: 0; color: #fff; letter-spacing: 0.5px; font-size: 16px; }
        .sidebar .brand .brand-text h5 span { color: #ffd700; }
        .sidebar .brand .brand-text small { font-size: 10px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar .nav-item { 
            display: flex; align-items: center; padding: 12px 16px; 
            color: rgba(255,255,255,0.6); text-decoration: none; 
            border-radius: 10px; margin-bottom: 5px; transition: all 0.2s ease; font-weight: 500; 
            font-size: 14px; position: relative;
        }
        .sidebar .nav-item i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .sidebar .nav-item.active { 
            background: rgba(255, 215, 0, 0.1); 
            color: #ffd700; 
            box-shadow: inset 3px 0 0 #ffd700;
        }
        
        .sidebar .user-profile { 
            margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); 
            display: flex; align-items: center; gap: 12px; 
        }
        .sidebar .user-profile .avatar { 
            width: 42px; height: 42px; border-radius: 50%; 
            background: linear-gradient(135deg, #1a1a2e, #16213e); 
            color: #ffd700; display: flex; align-items: center; justify-content: center; 
            font-weight: 700; font-size: 16px; border: 2px solid rgba(255,215,0,0.2);
        }
        .sidebar .user-profile .user-info .name { font-size: 14px; font-weight: 600; color: #fff; }
        .sidebar .user-profile .user-info .role { font-size: 12px; color: rgba(255,255,255,0.4); }

        .sidebar .logout-btn {
            display: block; text-align: center; margin-top: 15px; 
            padding: 10px; border-radius: 10px; color: #e74c3c; text-decoration: none; 
            font-weight: 600; font-size: 14px; background: rgba(231, 76, 60, 0.1); 
            transition: all 0.2s;
        }
        .sidebar .logout-btn:hover { background: rgba(231, 76, 60, 0.2); }

        /* ---- MAIN CONTENT ---- */
        .main-content { margin-left: 260px; padding: 30px; width: 100%; }
        
        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 30px; flex-wrap: wrap; gap: 15px; 
        }
        .page-header h4 { 
            font-weight: 800; color: #0e1a2b; font-size: 24px; margin:0; 
            letter-spacing: -0.5px;
        }
        .page-header h4 span { color: #ffd700; }
        .page-header .filter-area { display: flex; gap: 10px; align-items: center; }
        .page-header .filter-area select, .page-header .filter-area input { border-radius: 8px; border: 1px solid #e0e4ea; font-size: 13px; }

        /* ---- STAT CARDS ---- */
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { 
            background: #fff; border-radius: 16px; padding: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .stat-card .stat-icon { 
            width: 44px; height: 44px; border-radius: 12px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 18px; margin-bottom: 10px; 
        }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.12); color: #d4a017; }
        .stat-card .stat-icon.blue { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .stat-card .stat-icon.purple { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .stat-card .stat-icon.red { background: rgba(231, 76, 60, 0.12); color: #e74c3c; }
        .stat-card .stat-number { font-size: 24px; font-weight: 800; color: #0e1a2b; margin-bottom: 2px; }
        .stat-card .stat-label { font-size: 13px; color: #888; }

        /* ---- ROW PIPELINE & CHART ---- */
        .grid-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 991px) { .grid-2-col { grid-template-columns: 1fr; } }
        
        .pipeline-card, .chart-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .pipeline-card:hover, .chart-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        
        .pipeline-card h6, .chart-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; }
        .pipeline-bars { display: flex; height: 6px; border-radius: 4px; overflow: hidden; margin-bottom: 12px; }
        .pipeline-bars .bar { height: 100%; transition: width 0.5s; }
        .pipeline-bars .bar.new { background: #3498db; }
        .pipeline-bars .bar.middle { background: #f39c12; }
        .pipeline-bars .bar.hot { background: #e74c3c; }
        .pipeline-bars .bar.deal { background: #2ecc71; }
        .pipeline-bars .bar.lost { background: #95a5a6; }

        .pipeline-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center; }
        .pipeline-stats .p-item .p-label { font-size: 11px; color: #888; display: block; }
        .pipeline-stats .p-item .p-value { font-size: 16px; font-weight: 700; color: #0e1a2b; }
        .pipeline-stats .p-item .p-value.new { color: #3498db; }
        .pipeline-stats .p-item .p-value.middle { color: #f39c12; }
        .pipeline-stats .p-item .p-value.hot { color: #e74c3c; }
        .pipeline-stats .p-item .p-value.deal { color: #2ecc71; }
        .pipeline-stats .p-item .p-value.lost { color: #95a5a6; }

        .chart-wrapper { height: 220px; width: 100%; }

        /* ---- RECENT ACTIVITIES ---- */
        .activity-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            height: 100%; transition: all 0.3s ease;
        }
        .activity-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .activity-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; }
        .activity-item { 
            display: flex; gap: 14px; padding: 12px 0; 
            border-bottom: 1px solid #f0f2f5; align-items: flex-start; 
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-item .act-icon { 
            width: 36px; height: 36px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 14px; flex-shrink: 0; 
        }
        .activity-item .act-icon.gold { background: rgba(255, 215, 0, 0.1); color: #d4a017; }
        .activity-item .act-icon.blue { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .activity-item .act-icon.green { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .activity-item .act-icon.red { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .activity-item .act-info { flex: 1; }
        .activity-item .act-info .act-title { font-weight: 600; font-size: 14px; color: #0e1a2b; }
        .activity-item .act-info .act-desc { font-size: 13px; color: #7f8c8d; margin-top: 2px; }
        .activity-item .act-info .act-time { font-size: 11px; color: #bdc3c7; margin-top: 4px; display: block; }

        /* ---- MOBILE ---- */
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .mobile-toggle { 
                display: flex !important; background: #0e1a2b; border: none; 
                width: 40px; height: 40px; border-radius: 8px; 
                color: #ffd700; font-size: 20px; align-items: center; justify-content: center;
            }
        }
        .mobile-toggle { display: none; }
    </style>
</head>
<body>

    <!-- SIDEBAR MODERN -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
            <div class="brand-text">
                <h5>GANDA <span>ELANG</span></h5>
                <small>PT Ganda Elang Tangguh</small>
            </div>
        </a>

        <a href="dashboard.php" class="nav-item active"><i class="fas fa-th-large"></i> Dashboard</a>
        
        <?php if (in_array('sales_activity', $menuNames)): ?>
            <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        <?php if (in_array('account_management', $menuNames)): ?>
            <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        <?php if (in_array('detail_transaction_request', $menuNames)): ?>
            <a href="detailtr.php" class="nav-item"><i class="fas fa-file-alt"></i> Detail TR</a>
        <?php endif; ?>
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="#" class="nav-item"><i class="fas fa-tractor"></i> Delivery</a>
        <?php endif; ?>
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i> User</a>
        <?php endif; ?>

        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div style="display:flex; gap:15px; align-items:center;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <h4>Sales <span>Dashboard</span></h4>
            </div>
            <div class="filter-area">
                <span style="font-weight:600; color:#555; font-size:14px;">Filter:</span>
                <?php if (!$isSalesRole): ?>
                <select class="form-select form-select-sm" id="filterSales" onchange="applyFilter()" style="background:#f8f9fa;">
                    <option value="0">Semua Sales</option>
                    <?php foreach ($allSalesList as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($filterSalesId == $s['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="month" id="filterMonth" class="form-control form-control-sm" style="width:160px; background:#f8f9fa;" value="<?= $filterMonth ?>" onchange="applyFilter()">
            </div>
        </div>

        <!-- STAT CARDS (REAL DATA) -->
        <div class="stat-grid">
            <!-- 1. TOTAL LEADS -->
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= number_format($totalLeads) ?></div>
                <div class="stat-label">Total Leads</div>
            </div>
            
            <!-- 2. OPEN DEALS -->
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-briefcase"></i></div>
                <div class="stat-number"><?= number_format($pipelineCounts['Deal']) ?></div>
                <div class="stat-label">Open Deals</div>
            </div>

            <!-- 3. REVENUE FORECAST -->
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-number">Rp <?= number_format($totalRevenue, 0, ',', '.') ?></div>
                <div class="stat-label">Revenue Forecast</div>
            </div>
            
            <!-- 4. FILTERED SALES NAME -->
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-number" style="font-size:18px;"><?= htmlspecialchars($filteredSalesName) ?></div>
                <div class="stat-label">Sedang Ditinjau</div>
            </div>
        </div>

        <!-- GRID: PIPELINE (REAL DATA) & CHART -->
        <div class="grid-2-col">
            <!-- Pipeline -->
            <div class="pipeline-card">
                <h6><i class="fas fa-filter" style="color:#ffd700;"></i> Sales Pipeline</h6>
                
                <?php 
                // Hitung total pipeline untuk persentase
                $totalPipeline = array_sum($pipelineCounts);
                $pctNew = $totalPipeline > 0 ? ($pipelineCounts['New Lead'] / $totalPipeline * 100) : 0;
                $pctMid = $totalPipeline > 0 ? ($pipelineCounts['Middle Prospek'] / $totalPipeline * 100) : 0;
                $pctHot = $totalPipeline > 0 ? ($pipelineCounts['Hot Prospek'] / $totalPipeline * 100) : 0;
                $pctDeal = $totalPipeline > 0 ? ($pipelineCounts['Deal'] / $totalPipeline * 100) : 0;
                $pctLost = $totalPipeline > 0 ? ($pipelineCounts['Lost Deal'] / $totalPipeline * 100) : 0;
                ?>
                
                <div class="pipeline-bars">
                    <div class="bar new" style="width: <?= $pctNew ?>%;"></div>
                    <div class="bar middle" style="width: <?= $pctMid ?>%;"></div>
                    <div class="bar hot" style="width: <?= $pctHot ?>%;"></div>
                    <div class="bar deal" style="width: <?= $pctDeal ?>%;"></div>
                    <div class="bar lost" style="width: <?= $pctLost ?>%;"></div>
                </div>
                <div class="pipeline-stats">
                    <div class="p-item"><span class="p-label">New Lead</span><span class="p-value new"><?= $pipelineCounts['New Lead'] ?></span></div>
                    <div class="p-item"><span class="p-label">Middle</span><span class="p-value middle"><?= $pipelineCounts['Middle Prospek'] ?></span></div>
                    <div class="p-item"><span class="p-label">Hot</span><span class="p-value hot"><?= $pipelineCounts['Hot Prospek'] ?></span></div>
                    <div class="p-item"><span class="p-label">Deal</span><span class="p-value deal"><?= $pipelineCounts['Deal'] ?></span></div>
                    <div class="p-item"><span class="p-label">Lost</span><span class="p-value lost"><?= $pipelineCounts['Lost Deal'] ?></span></div>
                </div>
            </div>

            <!-- Chart Tren (Multi Sales Comparison) -->
            <div class="chart-card">
                <h6><i class="fas fa-chart-area" style="color:#2980b9;"></i> Tren Aktivitas</h6>
                <div class="chart-wrapper"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <!-- GRID: AKTIVITAS TERBARU & LAPORAN SALES -->
        <div class="grid-2-col">
            <!-- Recent Activities (REAL DATA) -->
            <div class="activity-card">
                <div style="display:flex; justify-content:space-between;">
                    <h6><i class="fas fa-clock" style="color:#d4a017;"></i> Aktivitas Terbaru</h6>
                    <a href="salesactivity.php" style="font-size:12px; color:#2980b9; text-decoration:none;">Lihat Semua</a>
                </div>
                
                <?php if (!empty($recentActivities)): ?>
                    <?php foreach ($recentActivities as $act): 
                        // Ambil inisial nama Sales (maksimal 2 huruf)
                        $salesInitial = '';
                        if (!empty($act['sales_name'])) {
                            $names = explode(' ', $act['sales_name']);
                            if (count($names) >= 2) {
                                $salesInitial = strtoupper(substr($names[0], 0, 1) . substr($names[1], 0, 1));
                            } else {
                                $salesInitial = strtoupper(substr($act['sales_name'], 0, 2));
                            }
                        }
                    ?>
                    <div class="activity-item">
                        <div class="act-icon gold"><i class="fas fa-file-alt"></i></div>
                        <div class="act-info">
                            <div class="act-title">
                                <?php if (!empty($salesInitial)): ?>
                                    <span class="badge bg-primary me-2" style="font-size:11px;"><?= $salesInitial ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($act['subject']) ?>
                            </div>
                            <div class="act-desc"><?= htmlspecialchars($act['nama_pt'] ?? '-') ?> - <?= htmlspecialchars($act['jenis_tugas']) ?></div>
                            <span class="act-time"><?= date('d M H:i', strtotime($act['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">Belum ada aktivitas.</div>
                <?php endif; ?>
            </div>

            <!-- Monthly Sales Report (Ringkasan Sales REAL - Filtered by Month) -->
            <div class="activity-card">
                <div style="display:flex; justify-content:space-between;">
                    <h6><i class="fas fa-chart-simple" style="color:#27ae60;"></i> Performa Sales (<?= date('F Y', strtotime($filterMonth . '-01')) ?>)</h6>
                </div>
                <div style="overflow-y:auto; max-height:300px;">
                    <table class="table table-sm table-hover" style="font-size:14px; margin:0;">
                        <thead><tr><th>Sales</th><th class="text-center">Deal</th><th class="text-center">Lost</th></tr></thead>
                        <tbody>
                            <?php 
                            $reportData = $filteredReportData ?? [];
                            if ($filterSalesId > 0) {
                                // Jika filter satu sales
                                $totalDeal = 0; $totalLost = 0;
                                foreach($filteredReportData as $m) { $totalDeal += $m['total_deal']; $totalLost += $m['total_lost']; }
                                echo "<tr><td><strong>" . htmlspecialchars($filteredSalesName) . "</strong></td>
                                      <td class='text-center text-deal'>$totalDeal</td>
                                      <td class='text-center text-lost'>$totalLost</td></tr>";
                            } else {
                                // Jika semua sales
                                foreach($filteredReportData as $sales):
                                    $gtD = 0; $gtL = 0;
                                    foreach($sales['data'] as $m) { $gtD += $m['total_deal']; $gtL += $m['total_lost']; }
                            ?>
                            <tr>
                                <td><i class="fas fa-user-circle me-2 text-secondary"></i> <?= htmlspecialchars($sales['name']) ?></td>
                                <td class="text-center text-deal"><strong><?= $gtD ?></strong></td>
                                <td class="text-center text-lost"><?= $gtL ?></td>
                            </tr>
                            <?php endforeach; } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4 text-muted" style="font-size:12px;">&copy; <?= date('Y') ?> PT Ganda Elang Tangguh - CRM</div>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // CHART TREN (SINGLE atau MULTI SALES)
        // ============================================
        const ctx = document.getElementById('trendChart').getContext('2d');
        
        let trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: <?= json_encode($chartDatasets) ?>
            },
            options: {
                responsive: true, 
                maintainAspectRatio: false,
                plugins: { 
                    legend: { 
                        display: <?= ($filterSalesId > 0) ? 'false' : 'true' ?>, // Tampilkan legenda hanya jika multi sales
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { family: 'Inter', size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#2980b9',
                        titleFont: { weight: 'bold', size: 14 },
                        bodyColor: '#1a1a2e',
                        bodyFont: { size: 13 },
                        borderColor: '#2980b9',
                        borderWidth: 2,
                        cornerRadius: 10,
                        padding: 12,
                        displayColors: true
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: 'rgba(0,0,0,0.04)' }, 
                        ticks: { stepSize: 1 } 
                    },
                    x: { grid: { display: false } }
                }
            }
        });

        // ============================================
        // FUNGSI APPLY FILTER (Reload untuk Table & Pipeline)
        // ============================================
        function applyFilter() {
            const salesId = document.getElementById('filterSales') ? document.getElementById('filterSales').value : 0;
            const month = document.getElementById('filterMonth').value;
            
            // Refresh halaman dengan filter bulan dan sales terbaru agar semua elemen tabel berubah
            window.location.href = '?sales_id=' + salesId + '&month=' + month;
        }
    </script>
</body>
</html>