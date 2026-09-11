<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

// ============================================
// SET ZONA WAKTU WIB (GMT+7)
// ============================================
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// CEK AKSES HALAMAN
// ============================================
requirePermission('transaction_request', 'view');

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();
$menuNames = array_column($userMenus, 'module_name');

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
// CEK USER UNTUK AKSES
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

$fullAccessRoles = ['it_support', 'admin', 'finance', 'business', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$hasFullAccess = in_array($userRole, $fullAccessRoles);
$isDirektur = in_array($userRole, ['direktur_utama', 'direktur_sales', 'direktur_operasional']);

// ============================================
// FILTER & PAGINATION
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// ============================================
// AMBIL DATA TR NUMBER DARI ACTIVITY_DETAILS
// ============================================
$where = "WHERE ad.tr_number IS NOT NULL AND ad.tr_number != ''";
$params = [];

if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where .= " AND (
            NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci)
            OR EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending')
        )";
    } elseif ($status_filter === 'approved') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved')
                    AND NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status IN ('pending', 'rejected'))";
    } elseif ($status_filter === 'rejected') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected')";
    }
}

if (!empty($search)) {
    $where .= " AND (ad.tr_number LIKE ? OR a.nama_pt LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$countSql = "SELECT COUNT(DISTINCT ad.tr_number) 
             FROM activity_details ad
             LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
             LEFT JOIN accounts a ON sa.account_id = a.id
             $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT ad.tr_number, 
               ad.due_date,
               MIN(ad.created_at) as request_date,
               a.nama_pt, 
               a.badan_usaha,
               u.full_name as sales_name,
               sa.sales_id,
               sa.id as sales_activity_id,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected'
                   ) THEN 'rejected'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending'
                   ) THEN 'pending'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved'
                   ) THEN 'approved'
                   ELSE 'pending'
               END as status
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        $where
        GROUP BY ad.tr_number, sa.sales_id, sa.id
        ORDER BY request_date DESC
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$statWhere = "WHERE ad.tr_number IS NOT NULL AND ad.tr_number != ''";
$statParams = [];

if ($userRole === 'sales') {
    $statWhere .= " AND sa.sales_id = ?";
    $statParams[] = $userId;
}

$sqlPending = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
               LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
               $statWhere 
               AND (
                   NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci)
                   OR EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending')
               )";
$stmt = $db->prepare($sqlPending);
$stmt->execute($statParams);
$totalPending = $stmt->fetchColumn();

$sqlApproved = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved')
                AND NOT EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status IN ('pending', 'rejected'))";
$stmt = $db->prepare($sqlApproved);
$stmt->execute($statParams);
$totalApproved = $stmt->fetchColumn();

$sqlRejected = "SELECT COUNT(DISTINCT ad.tr_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_transaction_requests dtr WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected')";
$stmt = $db->prepare($sqlRejected);
$stmt->execute($statParams);
$totalRejected = $stmt->fetchColumn();

$totalRequests = $totalPending + $totalApproved + $totalRejected;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaction Request - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
:root{--bg:#060b16;--panel:#0b1120;--panel2:#0a1020;--line:rgba(148,163,184,.14);--text:#e8eef7;--muted:#748198;--blue:#2563eb;--blue2:#60a5fa;--gold:#f5c84b}
*{margin:0;padding:0;box-sizing:border-box}
html,body{min-height:100%;background:var(--bg)}
body{font-family:'Inter','Segoe UI',sans-serif;color:var(--text);padding-bottom:40px;background:radial-gradient(circle at 75% -10%,rgba(37,99,235,.08),transparent 32%),#060b16}
.topbar{height:72px;position:fixed;top:0;left:0;right:0;z-index:1100;background:rgba(6,11,22,.94);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);display:flex;align-items:center;padding:0 28px}
.top-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff}.top-brand img{width:38px;height:38px;object-fit:contain}.top-brand strong{display:block;font-size:13px;font-weight:800;letter-spacing:.2px}.top-brand small{display:block;color:#68758a;font-size:9px;margin-top:2px;letter-spacing:.4px}
.top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.top-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5)}
.top-mobile-toggle{display:none}
.sidebar{width:245px;height:calc(100vh - 72px);position:fixed;top:72px;left:0;z-index:1000;background:linear-gradient(180deg,#08101e,#070d19);border-right:1px solid var(--line);padding:22px 16px;overflow-y:auto}
.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:rgba(148,163,184,.16);border-radius:10px}
.sidebar .brand{display:none}.menu-section{font-size:9px;font-weight:800;letter-spacing:1.2px;color:#4f5d73;padding:10px 12px 8px}.nav-item{display:flex;align-items:center;gap:12px;padding:10px 12px;margin:3px 0;color:#8290a5;text-decoration:none;border-radius:9px;font-size:12px;font-weight:600;transition:.2s}.nav-item i{width:18px;text-align:center;font-size:13px}.nav-item:hover{background:rgba(255,255,255,.035);color:#e8eef7}.nav-item.active{background:rgba(37,99,235,.16);color:#8dbaff;box-shadow:inset 3px 0 0 #3b82f6}.sidebar-bottom{margin-top:24px;padding-top:16px;border-top:1px solid var(--line)}.user-profile{display:flex;align-items:center;gap:10px;padding:4px 5px}.sidebar .avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;font-weight:800}.user-info{min-width:0}.user-info .name{font-size:11px;font-weight:700;color:#dce4ef;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-info .role{font-size:9px;color:#66748a;margin-top:2px}.logout-btn{display:block;margin-top:12px;padding:9px 10px;border-radius:8px;color:#ef8b8b;text-decoration:none;background:rgba(239,68,68,.06);font-size:11px;font-weight:700;text-align:left}.logout-btn i{margin-right:8px}.logout-btn:hover{background:rgba(239,68,68,.12)}
.main-content{margin-left:245px;padding:104px 30px 30px;max-width:1600px}
.page-header{margin-bottom:24px}.eyebrow{font-size:9px;letter-spacing:1.4px;color:#5f7392;font-weight:800;margin-bottom:7px}.page-header h1{font-size:25px;line-height:1.2;font-weight:800;letter-spacing:-.5px;color:#eef3f9}.page-header h1 i{font-size:19px;color:#60a5fa;margin-right:8px}.page-header p{font-size:12px;color:#68758a;margin-top:7px}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{background:linear-gradient(145deg,#0d1525,#0a101d);border:1px solid var(--line);border-radius:13px;padding:17px;box-shadow:0 10px 30px rgba(0,0,0,.14);transition:.25s}.stat-card:hover{transform:translateY(-2px);border-color:rgba(96,165,250,.25)}.stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:12px}.stat-icon.gold{background:rgba(245,200,75,.1);color:#f5c84b}.stat-icon.blue{background:rgba(59,130,246,.11);color:#60a5fa}.stat-icon.red{background:rgba(239,68,68,.1);color:#f87171}.stat-number{font-size:22px;font-weight:800;color:#f0f4fa}.stat-label{font-size:10px;color:#68758a;margin-top:3px}
.card-custom{background:#0b1120;border:1px solid var(--line);border-radius:14px;overflow:hidden;box-shadow:0 12px 35px rgba(0,0,0,.16)}.card-header-custom{padding:17px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.card-header-custom h6{margin:0;color:#dce4ef;font-size:13px;font-weight:700}.card-header-custom h6 i{color:#60a5fa;margin-right:7px}.card-header-custom form{display:flex;align-items:center}.card-header-custom input{background:#0a1020!important;border:1px solid var(--line)!important;color:#dce4ef!important;border-radius:8px!important}.card-header-custom input::placeholder{color:#59677d}.btn-primary-custom{background:linear-gradient(135deg,#2563eb,#1d4ed8);border:0;border-radius:8px;padding:8px 14px;color:#fff;font-size:11px;font-weight:700}.btn-secondary-custom{background:#0a1020;border:1px solid var(--line);border-radius:8px;padding:8px 14px;color:#9aa8ba;font-size:11px;font-weight:700}
.border-bottom{border-color:var(--line)!important}.filter-buttons{display:flex;gap:7px;flex-wrap:wrap}.btn-filter{padding:7px 12px;border:1px solid var(--line);background:#0a1020;border-radius:8px;color:#748198;text-decoration:none;font-size:10px;font-weight:600}.btn-filter:hover{color:#dce4ef;border-color:rgba(96,165,250,.3)}.btn-filter.active{background:rgba(37,99,235,.15);border-color:rgba(96,165,250,.3);color:#9fc5ff}.btn-filter .count{background:rgba(255,255,255,.05);padding:2px 6px;border-radius:10px;margin-left:4px}
.card-body-custom{padding:0}.table-responsive{background:#0b1120}.table-custom{margin:0!important;width:100%;font-size:11px;--bs-table-bg:#0b1120;--bs-table-color:#cbd5e1;--bs-table-border-color:rgba(255,255,255,.055)}.table-custom th{background:#0a1020!important;color:#65738a!important;border-bottom:1px solid var(--line)!important;padding:11px 14px!important;font-size:9px;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap}.table-custom td{background:#0b1120!important;color:#aeb9ca!important;border-bottom:1px solid rgba(255,255,255,.045)!important;padding:12px 14px!important;vertical-align:middle}.table-custom tbody tr:hover td{background:rgba(255,255,255,.025)!important;color:#e8eef7}.tr-number-link{color:#8dbaff;text-decoration:none;font-weight:700;font-size:11px}.tr-number-link:hover{color:#fff}.badge-status-tr{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:7px;font-size:9px;font-weight:700}.badge-status-tr.pending{background:rgba(245,200,75,.09);color:#f5c84b;border:1px solid rgba(245,200,75,.14)}.badge-status-tr.approved{background:rgba(59,130,246,.1);color:#7db2ff;border:1px solid rgba(96,165,250,.14)}.badge-status-tr.rejected{background:rgba(239,68,68,.09);color:#f08080;border:1px solid rgba(239,68,68,.14)}.btn-pdf{background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.2);border-radius:7px;padding:5px 9px;color:#75df9a;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700}.btn-pdf-disabled{background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.12);border-radius:7px;padding:5px 9px;color:#59677d;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;cursor:not-allowed}.card-footer{background:#0b1120!important;border-top:1px solid var(--line)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1020;border:1px solid var(--line);color:#8190a5;border-radius:7px!important;font-size:10px}.pagination .page-item.active .page-link{background:#2563eb;border-color:#2563eb;color:#fff}.pagination .page-link:hover{background:#111a2d;color:#fff}.alert{border-radius:9px;border:1px solid var(--line);font-size:11px}.footer-text{text-align:center;padding:18px 0 5px;color:#4f5d73;font-size:9px}.footer-text a{color:#6f8098;text-decoration:none}
@media(max-width:991px){.topbar{padding:0 16px}.top-mobile-toggle{display:flex;width:36px;height:36px;margin-right:10px;border:1px solid var(--line);background:#0a1020;color:#9fc5ff;border-radius:8px;align-items:center;justify-content:center}.sidebar{transform:translateX(-100%);transition:.25s}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0;padding:94px 18px 25px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.top-brand div{display:none}.main-content{padding-left:12px;padding-right:12px}.stat-grid{grid-template-columns:1fr 1fr;gap:9px}.stat-card{padding:13px}.page-header h1{font-size:21px}.card-header-custom{align-items:stretch}.card-header-custom form{width:100%}.card-header-custom input{flex:1;width:auto!important}.table-custom{min-width:760px}.filter-buttons{overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.btn-filter{white-space:nowrap}}
</style>
</head>
<body>
<header class="topbar">
    <button class="top-mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
    <a class="top-brand" href="dashboard.php">
        <img src="images/logo.webp" alt="GET">
        <div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div>
    </a>
    <div class="top-actions">
        <button class="icon-btn" type="button" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button>
        <div class="top-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div>
    </div>
</header>
<!-- SIDEBAR MODERN -->
<nav class="sidebar" id="sidebar">
    <a href="dashboard.php" class="brand">
        <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
        <div class="brand-text">
            <h5>CUSTOMER <span>RELATIONSHIP</span></h5>
            <small>PT Ganda Elang Tangguh</small>
        </div>
    </a>

    <div class="menu-section">MAIN MENU</div>
    <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if (in_array('sales_activity', $menuNames)): ?>
        <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-bar"></i><span>Sales Activity</span></a>
    <?php endif; ?>
    <?php if (in_array('account_management', $menuNames)): ?>
        <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i><span>Account Management</span></a>
    <?php endif; ?>
    <?php if (in_array('transaction_request', $menuNames)): ?>
        <a href="transactionrequest.php" class="nav-item active"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a>
    <?php endif; ?>
    <?php if (in_array('produk', $menuNames)): ?>
        <a href="produk.php" class="nav-item"><i class="fas fa-box"></i><span>Produk</span></a>
    <?php endif; ?>
    <?php if (in_array('delivery_order', $menuNames)): ?>
        <a href="deliveryinstruction.php" class="nav-item"><i class="fas fa-truck"></i><span>Delivery Order</span></a>
    <?php endif; ?>

    <div class="menu-section">ADMINISTRATION</div>
    <?php if (in_array('data_user', $menuNames)): ?>
        <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i><span>Data User</span></a>
    <?php endif; ?>
    <?php if (in_array('data_sales', $menuNames)): ?>
        <a href="data_sales.php" class="nav-item"><i class="fas fa-user-tie"></i><span>Data Sales</span></a>
    <?php endif; ?>

    <div class="sidebar-bottom">
        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>
<div class="main-content">
<!-- PAGE HEADER -->
<div class="page-header">
    <div>
        <div class="eyebrow">TRANSACTION MANAGEMENT</div>
        <h1><i class="fas fa-file-signature"></i> Transaction Request</h1>
        <p>Kelola dan pantau seluruh pengajuan transaction request.</p>
    </div>
</div>
<!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-file-signature"></i></div>
                <div class="stat-number"><?= number_format($totalRequests) ?></div>
                <div class="stat-label">Total Request</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?= number_format($totalPending) ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?= number_format($totalApproved) ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-times-circle"></i></div>
                <div class="stat-number"><?= number_format($totalRejected) ?></div>
                <div class="stat-label">Rejected</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Transaction Request</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="transactionrequest.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Filter Status -->
            <div class="px-3 pt-3 pb-2 border-bottom">
                <div class="filter-buttons">
                    <a href="?status=all&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'all' ? 'active' : '' ?>">
                        Semua <span class="count"><?= $totalRequests ?></span>
                    </a>
                    <a href="?status=pending&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'pending' ? 'active' : '' ?>">
                        <i class="fas fa-clock fa-fw" style="color:#f39c12;"></i> Pending <span class="count"><?= $totalPending ?></span>
                    </a>
                    <a href="?status=approved&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'approved' ? 'active' : '' ?>">
                        <i class="fas fa-check-circle fa-fw" style="color:#2980b9;"></i> Approved <span class="count"><?= $totalApproved ?></span>
                    </a>
                    <a href="?status=rejected&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'rejected' ? 'active' : '' ?>">
                        <i class="fas fa-times-circle fa-fw" style="color:#e74c3c;"></i> Rejected <span class="count"><?= $totalRejected ?></span>
                    </a>
                </div>
            </div>
            
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>TR Number</th>
                                <th>Account</th>
                                <th>Request Date</th>
                                <th>Due Date</th>
                                <th>Sales</th>
                                <th>Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($requests) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php 
                                    $statusLabel = ucfirst($request['status']);
                                    $statusClass = $request['status'];
                                    $isApproved = ($request['status'] == 'approved');
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <a href="detailtr.php?tr_number=<?= urlencode($request['tr_number']) ?>" class="tr-number-link">
                                                <?= htmlspecialchars($request['tr_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($request['nama_pt'] ?? '-') ?></td>
                                        <td><?= date('d/m/Y', strtotime($request['request_date'])) ?></td>
                                        <td><?= date('d/m/Y', strtotime($request['due_date'])) ?></td>
                                        <td><?= htmlspecialchars($request['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-status-tr <?= $statusClass ?>">
                                                <?php if ($request['status'] == 'pending'): ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php elseif ($request['status'] == 'approved'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php elseif ($request['status'] == 'rejected'): ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php endif; ?>
                                                <?= $statusLabel ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($userRole !== 'sales'): ?>
                                                <?php if ($isApproved): ?>
                                                    <a href="export_detail_pdf.php?tr_number=<?= urlencode($request['tr_number']) ?>" 
                                                       class="btn-pdf" 
                                                       target="_blank"
                                                       title="Download PDF Detail TR">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </a>
                                                <?php else: ?>
                                                    <span class="btn-pdf-disabled" title="PDF hanya tersedia untuk TR yang sudah Approved">
                                                        <i class="fas fa-file-pdf"></i> PDF
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data transaction request
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-transparent border-top p-3">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

    
</div>
    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>