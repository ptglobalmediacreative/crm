<?php
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
// HANDLE FILTER SALES & BULAN
// ============================================
$filterSalesId = isset($_GET['sales_id']) ? (int)$_GET['sales_id'] : 0;
$isSalesRole = ($role === 'sales');

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

$sqlFilterSA = "";
if ($filterSalesId > 0) {
    $sqlFilterSA = " AND sa.sales_id = $filterSalesId";
}

$sqlFilterAcc = "";
if ($filterSalesId > 0) {
    $sqlFilterAcc = " AND sales_id = $filterSalesId";
}

// ============================================
// DATA STATISTIK
// ============================================
if ($isSalesRole) {
    $sqlTotalLeads = "SELECT COUNT(*) FROM accounts WHERE sales_id = $userId";
} else {
    $sqlTotalLeads = "SELECT COUNT(*) FROM accounts WHERE 1=1" . $sqlFilterAcc;
}
$totalLeads = $db->query($sqlTotalLeads)->fetchColumn();

$pipelineCounts = [
    'New Lead' => 0,
    'Middle Prospek' => 0,
    'Hot Prospek' => 0,
    'Deal' => 0,
    'Lost Deal' => 0
];

// New Lead
$sqlNewLead = "SELECT COUNT(*) FROM accounts WHERE 1=1" . $sqlFilterAcc . " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
$pipelineCounts['New Lead'] = (int)$db->query($sqlNewLead)->fetchColumn();

// Middle Prospek
$sqlMid = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           JOIN activity_details ad ON ad.sales_activity_id = sa.id
           WHERE ad.jenis_tugas = 'Prospecting'
           AND sa.account_id NOT IN (
               SELECT DISTINCT sa2.account_id FROM sales_activities sa2
               JOIN activity_details ad2 ON ad2.sales_activity_id = sa2.id
               WHERE ad2.jenis_tugas IN ('Negosiasi', 'Kontrak') AND sa2.account_id IS NOT NULL
           )" . $sqlFilterSA;
$pipelineCounts['Middle Prospek'] = (int)$db->query($sqlMid)->fetchColumn();

// Hot Prospek
$sqlHot = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           JOIN activity_details ad ON ad.sales_activity_id = sa.id
           WHERE ad.jenis_tugas = 'Negosiasi'
           AND sa.account_id NOT IN (
               SELECT DISTINCT sa2.account_id FROM sales_activities sa2
               JOIN activity_details ad2 ON ad2.sales_activity_id = sa2.id
               WHERE ad2.jenis_tugas = 'Kontrak' AND sa2.account_id IS NOT NULL
           )
           AND sa.account_id NOT IN (
               SELECT DISTINCT sa3.account_id FROM sales_activities sa3
               JOIN activity_details ad3 ON ad3.sales_activity_id = sa3.id
               WHERE ad3.jenis_tugas = 'Negosiasi' AND ad3.status = 'completed' AND ad3.customer_deal = 'No' AND sa3.account_id IS NOT NULL
           )
           AND NOT (ad.status = 'completed' AND ad.customer_deal = 'No')" . $sqlFilterSA;
$pipelineCounts['Hot Prospek'] = (int)$db->query($sqlHot)->fetchColumn();

// Lost Deal
$sqlLost = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            JOIN activity_details ad ON ad.sales_activity_id = sa.id
            WHERE ad.jenis_tugas = 'Negosiasi'
            AND ad.status = 'completed' 
            AND ad.customer_deal = 'No'" . $sqlFilterSA;
$pipelineCounts['Lost Deal'] = (int)$db->query($sqlLost)->fetchColumn();

// Deal
$sqlDeal = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            JOIN activity_details ad ON ad.sales_activity_id = sa.id
            WHERE ad.jenis_tugas = 'Kontrak'" . $sqlFilterSA;
$pipelineCounts['Deal'] = (int)$db->query($sqlDeal)->fetchColumn();

// Revenue Forecast
$totalRevenue = 0;

$filteredSalesName = ($filterSalesId > 0) ? ($db->query("SELECT full_name FROM users WHERE id = $filterSalesId")->fetchColumn() ?: 'Sales') : 'Semua Sales';

// ============================================
// CHART TREN
// ============================================
$chartLabels = [];
$chartDatasets = [];

$colorPalette = [
    '#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6', '#1abc9c', '#e67e22', '#34495e'
];

function hexToRgba($hex, $alpha) {
    list($r, $g, $b) = sscanf($hex, "#%02x%02x%02x");
    return "rgba($r, $g, $b, $alpha)";
}

if ($filterSalesId > 0) {
    $chartQuery = "SELECT DATE(sa.created_at) as date, COUNT(*) as total 
                   FROM sales_activities sa
                   WHERE DATE_FORMAT(sa.created_at, '%Y-%m') = ? AND sa.sales_id = ? 
                   GROUP BY DATE(sa.created_at) ORDER BY date ASC";
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
    $year = substr($filterMonth, 0, 4);
    $month = substr($filterMonth, 5, 2);
    $totalDays = cal_days_in_month(CAL_GREGORIAN, (int)$month, (int)$year);
    
    for ($day = 1; $day <= $totalDays; $day++) {
        $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $chartLabels[] = date('d M', strtotime($dateKey));
    }

    $colorIndex = 0;
    foreach ($allSalesList as $sales) {
        $sId = $sales['id'];
        $sName = $sales['full_name'];

        $chartQuery = "SELECT DATE(sa.created_at) as date, COUNT(*) as total 
                       FROM sales_activities sa
                       WHERE DATE_FORMAT(sa.created_at, '%Y-%m') = ? AND sa.sales_id = ? 
                       GROUP BY DATE(sa.created_at) ORDER BY date ASC";
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

// ============================================
// AKTIVITAS TERBARU
// ============================================
$activityLimit = 5;
$sqlActivities = "SELECT sa.*, a.nama_pt, u.full_name as sales_name,
                  (SELECT ad.subject FROM activity_details ad WHERE ad.sales_activity_id = sa.id ORDER BY ad.id DESC LIMIT 1) as subject,
                  (SELECT ad.jenis_tugas FROM activity_details ad WHERE ad.sales_activity_id = sa.id ORDER BY ad.id DESC LIMIT 1) as jenis_tugas
                  FROM sales_activities sa 
                  LEFT JOIN accounts a ON sa.account_id = a.id 
                  LEFT JOIN users u ON sa.sales_id = u.id
                  WHERE 1=1" . $sqlFilterSA . "
                  ORDER BY sa.created_at DESC 
                  LIMIT $activityLimit";
$recentActivities = $db->query($sqlActivities)->fetchAll(PDO::FETCH_ASSOC);

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
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
<style>
:root{--bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);--text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--cyan:#22d3ee;--green:#34d399;--red:#fb7185;--amber:#fbbf24;--purple:#a78bfa}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);color:var(--text);min-height:100vh;overflow-x:hidden}.app{min-height:100vh}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:50}.brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.brand img{width:38px;height:38px;object-fit:contain}.brand strong{font-size:17px;letter-spacing:-.4px}.brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}.top-left-actions{display:flex;align-items:center;gap:10px}.top-actions{display:none}.search{display:none;width:190px;height:38px;border:1px solid var(--line);border-radius:20px;background:#0a1020;color:#dce5f5;display:flex;align-items:center;padding:0 13px;gap:9px}.search input{background:none;border:0;outline:0;color:#fff;width:100%;font-size:11px}.search input::placeholder{color:#65738e}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5)}
.shell{display:flex}.rail{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:40;overflow-y:auto}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.rail a{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600}.rail a i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.rail a:hover,.rail a.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.rail a.active i{color:#60a5fa}.rail .spacer{flex:1;min-height:20px}.rail-user{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.rail-user .mini-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.rail-user strong{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rail-user span{display:block;font-size:8px;color:#66758f;margin-top:2px}.content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px}.hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.hero h1{font-size:26px;letter-spacing:-1px;font-weight:800;margin:0}.hero p{font-size:12px;color:var(--muted);margin-top:7px}.filters{display:flex;gap:8px;align-items:center}.filter{height:38px;border:1px solid var(--line);background:rgba(10,17,33,.85);color:#cdd7e7;border-radius:11px;padding:0 12px;font-size:11px;outline:none}.filter option{background:#0b1222}.btn-add{height:38px;border:0;border-radius:11px;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:11px;font-weight:700;padding:0 15px;box-shadow:0 0 24px rgba(59,130,246,.25)}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:14px}.kpi{position:relative;overflow:hidden;min-height:112px;background:linear-gradient(145deg,rgba(15,27,50,.96),rgba(8,16,31,.96));border:1px solid var(--line);border-radius:16px;padding:17px;box-shadow:0 12px 35px rgba(0,0,0,.18)}.kpi:after{content:"";position:absolute;width:85px;height:85px;border-radius:50%;right:-35px;bottom:-45px;background:rgba(59,130,246,.15);filter:blur(5px)}.kpi-top{display:flex;justify-content:space-between;align-items:center}.kpi-icon{width:31px;height:31px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12px}.kpi-icon.blue{background:rgba(59,130,246,.15);color:#60a5fa}.kpi-icon.cyan{background:rgba(34,211,238,.12);color:#67e8f9}.kpi-icon.amber{background:rgba(251,191,36,.12);color:#fcd34d}.kpi-icon.red{background:rgba(251,113,133,.12);color:#fb7185}.kpi-icon.green{background:rgba(52,211,153,.12);color:#6ee7b7}.kpi-icon.purple{background:rgba(167,139,250,.12);color:#c4b5fd}.kpi .number{font-size:24px;font-weight:800;letter-spacing:-1px;margin-top:10px}.kpi .label{font-size:10px;color:#7f8ca5;margin-top:2px}.trend{font-size:9px;color:#56d6b0}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(290px,.8fr);gap:14px;margin-bottom:14px}.panel{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden}.panel-head{height:58px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(148,163,184,.10)}.panel-title{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:700}.panel-title i{color:#60a5fa}.panel-sub{font-size:9px;color:#687791}.panel-body{padding:17px}.pipeline{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.stage{min-height:125px;border:1px solid rgba(148,163,184,.12);background:rgba(5,12,25,.54);border-radius:13px;padding:12px;position:relative}.stage:before{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:var(--c);box-shadow:0 0 14px var(--c)}.stage .stage-name{font-size:9px;color:#8592a9;text-transform:uppercase;letter-spacing:.5px}.stage .stage-num{font-size:22px;font-weight:800;margin-top:13px}.stage .stage-meta{font-size:9px;color:#63718a;margin-top:5px}.stage-bar{height:4px;background:#101c31;border-radius:5px;overflow:hidden;margin-top:14px}.stage-bar span{display:block;height:100%;background:var(--c);width:var(--w);box-shadow:0 0 10px var(--c)}
.hot-list{display:flex;flex-direction:column}.hot-item{display:grid;grid-template-columns:32px 1fr auto;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid rgba(148,163,184,.08)}.hot-item:last-child{border-bottom:0}.machine{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#102b55,#0b1930);display:flex;align-items:center;justify-content:center;color:#60a5fa}.hot-name{font-size:11px;font-weight:700}.hot-desc{font-size:9px;color:#687791;margin-top:3px}.hot-value{text-align:right;font-size:10px;font-weight:700}.score{font-size:8px;color:#60a5fa;margin-top:3px}.scorebar{width:64px;height:3px;border-radius:5px;background:#152238;margin-top:4px;overflow:hidden}.scorebar span{display:block;height:100%;background:linear-gradient(90deg,#6366f1,#22d3ee);width:var(--score)}
.lower{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}.chart-wrap{height:260px}.activity-list{padding:4px 17px 10px}.activity{display:grid;grid-template-columns:30px 1fr auto;gap:10px;padding:12px 0;border-bottom:1px solid rgba(148,163,184,.08)}.activity:last-child{border-bottom:0}.act-icon{width:30px;height:30px;border-radius:9px;background:rgba(59,130,246,.12);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-size:11px}.act-title{font-size:10px;font-weight:700}.act-desc{font-size:9px;color:#74829b;margin-top:3px}.act-time{font-size:8px;color:#56657e;white-space:nowrap}.empty{padding:30px;text-align:center;color:#66758f;font-size:11px}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding:17px}.quick{border:1px solid rgba(148,163,184,.10);background:rgba(6,13,27,.55);border-radius:12px;padding:13px;text-decoration:none;color:#dce5f5;transition:.2s}.quick:hover{border-color:rgba(59,130,246,.45);transform:translateY(-2px)}.quick i{color:#60a5fa;font-size:13px}.quick strong{display:block;font-size:10px;margin-top:9px}.quick span{font-size:8px;color:#687791}.footer{text-align:center;color:#44536c;font-size:9px;margin-top:22px}
@media(max-width:1200px){.kpis{grid-template-columns:repeat(3,1fr)}.brand{min-width:190px}.search{width:150px}}@media(max-width:1050px){.brand{flex:1}.dashboard-grid,.lower{grid-template-columns:1fr}.pipeline{grid-template-columns:repeat(3,1fr)}}@media(max-width:650px){.top-left-actions{gap:8px}.topbar{padding:0 14px}.content{padding:20px 14px 40px}.rail{display:none}.content{margin-left:0;width:100%}.kpis{grid-template-columns:repeat(2,1fr)}.hero-row{align-items:flex-start;flex-direction:column}.filters{width:100%;flex-wrap:wrap}.filter{flex:1;min-width:130px}.pipeline{grid-template-columns:1fr 1fr}.brand{min-width:0}.brand div{display:none}.top-actions .search{display:none}}
</style>
</head>
<body>
<div class="app">
<header class="topbar">
<a class="brand" href="dashboard.php"><img src="images/logo.webp" alt="GET"><div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div></a>
<div class="top-left-actions">
<button class="icon-btn" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button>
<div class="avatar" title="<?= htmlspecialchars($fullName) ?>"><?= strtoupper(substr($fullName,0,1)) ?></div>
</div>
</header>
<div class="shell>
<aside class="rail">
<div class="rail-label">Main Menu</div>
<a class="active" href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
<?php if(in_array('sales_activity',$menuNames)): ?><a href="salesactivity.php"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a><?php endif; ?>
<?php if(in_array('account_management',$menuNames)): ?><a href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a><?php endif; ?>
<?php if(in_array('transaction_request',$menuNames)): ?><a href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a><?php endif; ?>
<?php if(in_array('produk',$menuNames)): ?><a href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a><?php endif; ?>
<?php if(in_array('delivery_order',$menuNames)): ?><a href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a><?php endif; ?>
<div class="rail-label">Administration</div>
<?php if(in_array('data_user',$menuNames)): ?><a href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a><?php endif; ?>
<?php if(in_array('data_sales',$menuNames) && file_exists('data_sales.php')): ?><a href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a><?php endif; ?>
<div class="spacer"></div>
<div class="rail-user"><div class="mini-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div><div><strong><?= htmlspecialchars($fullName) ?></strong><span><?= htmlspecialchars(getRoleLabel($role)) ?></span></div></div>
<a href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
</aside>
<main class="content">
<section class="hero-row"><div class="hero"><div class="eyebrow">PT Ganda Elang Tangguh · Customer Relationship Management</div><h1>Welcome, <?= htmlspecialchars($fullName) ?> 👋</h1><p>Monitor your sales pipeline, customer activities and dealership performance.</p></div><div class="filters"><?php if(!$isSalesRole): ?><select class="filter" id="filterSales" onchange="applyFilter()"><option value="0">All Sales</option><?php foreach($allSalesList as $s): ?><option value="<?= $s['id'] ?>" <?= ($filterSalesId==$s['id'])?'selected':'' ?>><?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?><input class="filter" type="month" id="filterMonth" value="<?= htmlspecialchars($filterMonth) ?>" onchange="applyFilter()"><button class="btn-add" onclick="location.href='account_management.php'"><i class="fas fa-plus me-1"></i> Add Lead</button></div></section>
<section class="kpis">
<div class="kpi"><div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-users"></i></div><span class="trend">CRM</span></div><div class="number"><?= number_format($totalLeads) ?></div><div class="label">Total Accounts / Leads</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon cyan"><i class="fas fa-user-plus"></i></div><span class="trend">30 days</span></div><div class="number"><?= number_format($pipelineCounts['New Lead']) ?></div><div class="label">New Leads</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon amber"><i class="fas fa-crosshairs"></i></div><span class="trend">Prospecting</span></div><div class="number"><?= number_format($pipelineCounts['Middle Prospek']) ?></div><div class="label">Middle Prospects</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon red"><i class="fas fa-fire"></i></div><span class="trend">Hot</span></div><div class="number"><?= number_format($pipelineCounts['Hot Prospek']) ?></div><div class="label">Hot Prospects</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon green"><i class="fas fa-handshake"></i></div><span class="trend">Won</span></div><div class="number"><?= number_format($pipelineCounts['Deal']) ?></div><div class="label">Deals / Contracts</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon purple"><i class="fas fa-ban"></i></div><span class="trend">Closed</span></div><div class="number"><?= number_format($pipelineCounts['Lost Deal']) ?></div><div class="label">Lost Deals</div></div>
</section>
<section class="dashboard-grid">
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-filter"></i> Sales Pipeline</div><div class="panel-sub">Current prospect movement by stage</div></div><span class="panel-sub"><?= htmlspecialchars($filteredSalesName) ?></span></div><div class="panel-body"><div class="pipeline">
<?php $pipe=[['New Lead','blue',$pipelineCounts['New Lead'],34],['Middle Prospek','amber',$pipelineCounts['Middle Prospek'],50],['Hot Prospek','red',$pipelineCounts['Hot Prospek'],66],['Deal','green',$pipelineCounts['Deal'],82],['Lost Deal','purple',$pipelineCounts['Lost Deal'],28]]; foreach($pipe as $p): $c=['blue'=>'#60a5fa','amber'=>'#fbbf24','red'=>'#fb7185','green'=>'#34d399','purple'=>'#a78bfa'][$p[1]]; ?><div class="stage" style="--c:<?= $c ?>;--w:<?= $p[3] ?>%"><div class="stage-name"><?= htmlspecialchars($p[0]) ?></div><div class="stage-num"><?= number_format($p[2]) ?></div><div class="stage-meta">Accounts in stage</div><div class="stage-bar"><span></span></div></div><?php endforeach; ?></div></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-fire"></i> Hot Opportunities</div><div class="panel-sub">Highest priority prospects</div></div><span class="panel-sub">View all →</span></div><div class="panel-body hot-list">
<?php $hotDemo=[]; foreach($recentActivities as $a){ if(count($hotDemo)>=5) break; $hotDemo[]=$a; } if($hotDemo): foreach($hotDemo as $i=>$a): ?><div class="hot-item"><div class="machine"><i class="fas fa-tractor"></i></div><div><div class="hot-name"><?= htmlspecialchars($a['nama_pt']??'-') ?></div><div class="hot-desc"><?= htmlspecialchars($a['jenis_tugas']??'Sales Activity') ?> · <?= htmlspecialchars($a['subject']??'-') ?></div></div><div><div class="hot-value">#<?= $i+1 ?></div><div class="score">Priority</div><div class="scorebar" style="--score:<?= max(35,90-($i*10)) ?>%"><span></span></div></div></div><?php endforeach; else: ?><div class="empty">Belum ada opportunity terbaru.</div><?php endif; ?></div></div>
</section>
<section class="lower">
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-chart-area"></i> Activity Performance</div><div class="panel-sub">Daily sales activity for <?= date('F Y',strtotime($filterMonth.'-01')) ?></div></div></div><div class="panel-body"><div class="chart-wrap"><canvas id="trendChart"></canvas></div></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-bolt"></i> Recent Activity</div><div class="panel-sub">Latest CRM actions</div></div><a href="salesactivity.php" style="font-size:9px;color:#60a5fa;text-decoration:none">View all →</a></div><div class="activity-list"><?php if($recentActivities): foreach($recentActivities as $act): ?><div class="activity"><div class="act-icon"><i class="fas fa-file-lines"></i></div><div><div class="act-title"><?= htmlspecialchars($act['subject']??'-') ?></div><div class="act-desc"><?= htmlspecialchars($act['nama_pt']??'-') ?> · <?= htmlspecialchars($act['jenis_tugas']??'-') ?></div></div><div class="act-time"><?= date('d M H:i',strtotime($act['created_at'])) ?></div></div><?php endforeach; else: ?><div class="empty">Belum ada aktivitas.</div><?php endif; ?></div></div>
</section>
<section class="panel" style="margin-top:14px"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-bolt"></i> Quick Access</div><div class="panel-sub">Frequently used CRM modules</div></div></div><div class="quick-grid">
<?php if(in_array('sales_activity',$menuNames)): ?><a class="quick" href="salesactivity.php"><i class="fas fa-chart-line"></i><strong>Sales Activity</strong><span>Manage leads & prospect actions</span></a><?php endif; ?>
<?php if(in_array('account_management',$menuNames)): ?><a class="quick" href="account_management.php"><i class="fas fa-building"></i><strong>Accounts</strong><span>Customer & company database</span></a><?php endif; ?>
<?php if(in_array('transaction_request',$menuNames)): ?><a class="quick" href="transactionrequest.php"><i class="fas fa-file-signature"></i><strong>Transaction Request</strong><span>Track approval & transactions</span></a><?php endif; ?>
<?php if(in_array('delivery_order',$menuNames)): ?><a class="quick" href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><strong>Delivery</strong><span>Monitor delivery instructions</span></a><?php endif; ?>
</div></section>
<div class="footer">© <?= date('Y') ?> PT Ganda Elang Tangguh · Heavy Equipment Dealer CRM</div>
</main></div></div>
<script>
const ctx=document.getElementById('trendChart').getContext('2d');
const gradient=ctx.createLinearGradient(0,0,0,260);gradient.addColorStop(0,'rgba(59,130,246,.30)');gradient.addColorStop(1,'rgba(59,130,246,0)');
new Chart(ctx,{type:'line',data:{labels:<?= json_encode($chartLabels) ?>,datasets:<?= json_encode($chartDatasets) ?>},options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{labels:{color:'#8290aa',usePointStyle:true,font:{family:'Inter',size:9}}},tooltip:{backgroundColor:'#0b1222',borderColor:'rgba(96,165,250,.3)',borderWidth:1,titleColor:'#fff',bodyColor:'#cbd5e1'}},scales:{y:{beginAtZero:true,grid:{color:'rgba(148,163,184,.08)'},ticks:{color:'#60708b',font:{size:9}}},x:{grid:{display:false},ticks:{color:'#60708b',font:{size:9},maxTicksLimit:10}}}}});
function applyFilter(){const s=document.getElementById('filterSales')?document.getElementById('filterSales').value:0;const m=document.getElementById('filterMonth').value;location.href='?sales_id='+encodeURIComponent(s)+'&month='+encodeURIComponent(m)}
</script>
</body></html>