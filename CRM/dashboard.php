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

/* Total Transaction Request
 *
 * Sumber TR yang benar di CRM adalah activity_details.tr_number.
 * Halaman Transaction Request juga menggunakan sumber ini dan menghitung
 * nomor TR secara DISTINCT, karena satu TR dapat memiliki beberapa activity detail.
 */
$sqlTotalTR = "SELECT COUNT(DISTINCT ad.tr_number)
               FROM activity_details ad
               LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
               WHERE ad.tr_number IS NOT NULL
                 AND TRIM(ad.tr_number) <> ''";
if ($filterSalesId > 0) {
    $sqlTotalTR .= " AND sa.sales_id = $filterSalesId";
}
$totalTransactionRequests = (int)$db->query($sqlTotalTR)->fetchColumn();

/* Total Delivery Order / Delivery Instruction */
$sqlTotalDO = "SELECT COUNT(DISTINCT ad.di_number)
              FROM activity_details ad
              LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
              WHERE ad.di_number IS NOT NULL
                AND TRIM(ad.di_number) <> ''";
if ($filterSalesId > 0) {
    $sqlTotalDO .= " AND sa.sales_id = $filterSalesId";
}
$totalDeliveryOrders = (int)$db->query($sqlTotalDO)->fetchColumn();

$pipelineCounts = [
    'New Lead' => 0,
    'Middle Prospek' => 0,
    'Hot Prospek' => 0,
    'Deal' => 0,
    'Lost Deal' => 0
];

// Pipeline stage counts
// Klasifikasi mengikuti Sales Activity:
// Perkenalan/Visit = Suspect, Prospecting = Prospect,
// Negosiasi/Kontrak = Hot Prospect,
// Delivery Order Yes = Deal, Delivery Order No = Lost Deal.
$pipelineCounts = [
    'Suspect' => 0,
    'Prospect' => 0,
    'Hot Prospect' => 0,
    'Deal' => 0,
    'Lost Deal' => 0
];

$sqlPipeline = "
    SELECT latest.account_id, latest.jenis_tugas, latest.customer_deal
    FROM (
        SELECT sa.account_id, ad.jenis_tugas, ad.customer_deal, ad.id,
               ROW_NUMBER() OVER (
                   PARTITION BY sa.account_id
                   ORDER BY ad.id DESC
               ) AS rn
        FROM sales_activities sa
        INNER JOIN activity_details ad ON ad.sales_activity_id = sa.id
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IS NOT NULL
          AND TRIM(ad.jenis_tugas) <> ''
          " . ($filterSalesId > 0 ? " AND sa.sales_id = $filterSalesId" : "") . "
    ) latest
    WHERE latest.rn = 1
";
try {
    $pipelineRows = $db->query($sqlPipeline)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sqlPipeline = "
        SELECT sa.account_id, ad.jenis_tugas, ad.customer_deal
        FROM sales_activities sa
        INNER JOIN activity_details ad ON ad.sales_activity_id = sa.id
        INNER JOIN (
            SELECT sa2.account_id, MAX(ad2.id) AS max_detail_id
            FROM sales_activities sa2
            INNER JOIN activity_details ad2 ON ad2.sales_activity_id = sa2.id
            WHERE sa2.account_id IS NOT NULL
            GROUP BY sa2.account_id
        ) x ON x.account_id = sa.account_id AND x.max_detail_id = ad.id
        WHERE sa.account_id IS NOT NULL
        " . ($filterSalesId > 0 ? " AND sa.sales_id = $filterSalesId" : "") . "
    ";
    $pipelineRows = $db->query($sqlPipeline)->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($pipelineRows as $row) {
    $jenisTugas = $row['jenis_tugas'];
    $customerDeal = $row['customer_deal'];

    if ($jenisTugas === 'Delivery Order') {
        if ($customerDeal === 'Yes') {
            $pipelineCounts['Deal']++;
        } elseif ($customerDeal === 'No') {
            $pipelineCounts['Lost Deal']++;
        }
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

// ============================================
// HOT OPPORTUNITIES
// Hanya tampilkan account yang ACTIVITY TERBARUNYA
// masuk kategori Hot Prospect (Negosiasi / Kontrak).
// Jadi aktivitas Prospecting, Suspect, Deal, Lost Deal, dll
// tidak ikut masuk ke panel Hot Opportunities.
// ============================================
$sqlHotOpportunities = "
    SELECT latest.account_id, latest.nama_pt, latest.sales_name,
           latest.jenis_tugas, latest.subject, latest.created_at
    FROM (
        SELECT sa.account_id, a.nama_pt, u.full_name AS sales_name,
               ad.jenis_tugas, ad.subject, ad.created_at, ad.id,
               ROW_NUMBER() OVER (
                   PARTITION BY sa.account_id
                   ORDER BY ad.id DESC
               ) AS rn
        FROM sales_activities sa
        INNER JOIN activity_details ad ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IS NOT NULL
          AND TRIM(ad.jenis_tugas) <> ''
          " . ($filterSalesId > 0 ? " AND sa.sales_id = $filterSalesId" : "") . "
    ) latest
    WHERE latest.rn = 1
      AND latest.jenis_tugas IN ('Negosiasi', 'Kontrak')
    ORDER BY latest.created_at DESC
    LIMIT 5
";
try {
    $hotOpportunities = $db->query($sqlHotOpportunities)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback untuk MySQL versi lama yang belum mendukung ROW_NUMBER().
    $sqlHotOpportunities = "
        SELECT sa.account_id, a.nama_pt, u.full_name AS sales_name,
               ad.jenis_tugas, ad.subject, ad.created_at
        FROM sales_activities sa
        INNER JOIN activity_details ad ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        WHERE sa.account_id IS NOT NULL
          AND ad.jenis_tugas IN ('Negosiasi', 'Kontrak')
          " . ($filterSalesId > 0 ? " AND sa.sales_id = $filterSalesId" : "") . "
          AND ad.id = (
              SELECT MAX(ad2.id)
              FROM sales_activities sa2
              INNER JOIN activity_details ad2 ON ad2.sales_activity_id = sa2.id
              WHERE sa2.account_id = sa.account_id
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
<link rel="stylesheet" href="css/navigation.css">
<link rel="stylesheet" href="css/dashboard.css">
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

            <div class="filters"><?php if(!$isSalesRole): ?><select class="filter" id="filterSales" onchange="applyFilter()"><option value="0">All Sales</option><?php foreach($allSalesList as $s): ?><option value="<?= $s['id'] ?>" <?= ($filterSalesId==$s['id'])?'selected':'' ?>><?= htmlspecialchars($s['full_name']) ?></option><?php endforeach; ?></select><?php endif; ?><input class="filter" type="month" id="filterMonth" value="<?= htmlspecialchars($filterMonth) ?>" onchange="applyFilter()"><button class="btn-add" onclick="location.href='account_management.php'"><i class="fas fa-plus me-1"></i> Add Lead</button></div>
        </section>

        <section class="kpis">
<div class="kpi"><div class="kpi-top"><div class="kpi-icon blue"><i class="fas fa-users"></i></div><span class="trend">CRM</span></div><div class="number"><?= number_format($totalLeads) ?></div><div class="label">Total Account / Leads</div></div>
<div class="kpi"><div class="kpi-top"><div class="kpi-icon cyan"><i class="fas fa-user-plus"></i></div><span class="trend">30 days</span></div><div class="number"><?= number_format($db->query("SELECT COUNT(*) FROM accounts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" . ($filterSalesId > 0 ? " AND sales_id = $filterSalesId" : ""))->fetchColumn()) ?></div><div class="label">New Leads</div></div>
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
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-fire"></i> Hot Opportunities</div><div class="panel-sub">Highest priority prospects</div></div><span class="panel-sub">View all →</span></div><div class="panel-body hot-list">
<?php if($hotOpportunities): foreach($hotOpportunities as $i=>$a): ?><div class="hot-item"><div class="machine"><i class="fas fa-tractor"></i></div><div><div class="hot-name"><?= htmlspecialchars($a['nama_pt']??'-') ?></div><div class="hot-desc"><?= htmlspecialchars($a['jenis_tugas']??'Hot Prospect') ?> · <?= htmlspecialchars($a['subject']??'-') ?></div></div><div><div class="hot-value">#<?= $i+1 ?></div><div class="score">Hot Prospect</div><div class="scorebar" style="--score:<?= max(55,95-($i*8)) ?>%"><span></span></div></div></div><?php endforeach; else: ?><div class="empty">Belum ada Hot Prospect.</div><?php endif; ?></div></div>
</section>
<section class="lower">
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-chart-area"></i> Activity Performance</div><div class="panel-sub">Daily sales activity for <?= date('F Y',strtotime($filterMonth.'-01')) ?></div></div></div><div class="panel-body"><div class="chart-wrap"><canvas id="trendChart"></canvas></div></div></div>
<div class="panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-bolt"></i> Recent Activity</div><div class="panel-sub">Latest CRM actions</div></div><a class="panel-link" href="salesactivity.php">View all →</a></div><div class="activity-list"><?php if($recentActivities): foreach($recentActivities as $act): ?><div class="activity"><div class="act-icon"><i class="fas fa-file-lines"></i></div><div><div class="act-title"><?= htmlspecialchars($act['subject']??'-') ?></div><div class="act-desc"><?= htmlspecialchars($act['nama_pt']??'-') ?> · <?= htmlspecialchars($act['jenis_tugas']??'-') ?></div></div><div class="act-time"><?= date('d M H:i',strtotime($act['created_at'])) ?></div></div><?php endforeach; else: ?><div class="empty">Belum ada aktivitas.</div><?php endif; ?></div></div>
</section>
<section class="panel quick-access-panel"><div class="panel-head"><div><div class="panel-title"><i class="fas fa-bolt"></i> Quick Access</div><div class="panel-sub">Frequently used CRM modules</div></div></div><div class="quick-grid">
<?php if(in_array('sales_activity',$menuNames)): ?><a class="quick" href="salesactivity.php"><i class="fas fa-chart-line"></i><strong>Sales Activity</strong><span>Manage leads & prospect actions</span></a><?php endif; ?>
<?php if(in_array('account_management',$menuNames)): ?><a class="quick" href="account_management.php"><i class="fas fa-building"></i><strong>Accounts</strong><span>Customer & company database</span></a><?php endif; ?>
<?php if(in_array('transaction_request',$menuNames)): ?><a class="quick" href="transactionrequest.php"><i class="fas fa-file-signature"></i><strong>Transaction Request</strong><span>Track approval & transactions</span></a><?php endif; ?>
<?php if(in_array('delivery_order',$menuNames)): ?><a class="quick" href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><strong>Delivery</strong><span>Monitor delivery instructions</span></a><?php endif; ?>
</div></section>
<div class="footer">© <?= date('Y') ?> PT Ganda Elang Tangguh · Heavy Equipment Dealer CRM</div>
    </main>
</div>

<script>
const ctx=document.getElementById('trendChart').getContext('2d');
const gradient=ctx.createLinearGradient(0,0,0,260);gradient.addColorStop(0,'rgba(59,130,246,.30)');gradient.addColorStop(1,'rgba(59,130,246,0)');
new Chart(ctx,{type:'line',data:{labels:<?= json_encode($chartLabels) ?>,datasets:<?= json_encode($chartDatasets) ?>},options:{responsive:true,maintainAspectRatio:false,interaction:{intersect:false,mode:'index'},plugins:{legend:{labels:{color:'#8290aa',usePointStyle:true,font:{family:'Inter',size:9}}},tooltip:{backgroundColor:'#0b1222',borderColor:'rgba(96,165,250,.3)',borderWidth:1,titleColor:'#fff',bodyColor:'#cbd5e1'}},scales:{y:{beginAtZero:true,grid:{color:'rgba(148,163,184,.08)'},ticks:{color:'#60708b',font:{size:9}}},x:{grid:{display:false},ticks:{color:'#60708b',font:{size:9},maxTicksLimit:10}}}}});
function applyFilter(){const s=document.getElementById('filterSales')?document.getElementById('filterSales').value:0;const m=document.getElementById('filterMonth').value;location.href='?sales_id='+encodeURIComponent(s)+'&month='+encodeURIComponent(m)}
</script>
</body></html>