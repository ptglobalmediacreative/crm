<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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
requirePermission('delivery_order', 'view');

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

// Validasi status filter
$allowedStatus = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $allowedStatus)) {
    $status_filter = 'all';
}

// ============================================
// AMBIL DATA DI NUMBER DARI ACTIVITY_DETAILS
// ============================================
$where = "WHERE ad.di_number IS NOT NULL AND ad.di_number != ''";
$params = [];

if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where .= " AND (
            NOT EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci)
            OR EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'pending')
        )";
    } elseif ($status_filter === 'approved') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'approved')
                    AND NOT EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status IN ('pending', 'rejected'))";
    } elseif ($status_filter === 'rejected') {
        $where .= " AND EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'rejected')";
    }
}

if (!empty($search)) {
    $where .= " AND (ad.di_number LIKE ? OR a.nama_pt LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$countSql = "SELECT COUNT(DISTINCT ad.di_number) 
             FROM activity_details ad
             LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
             LEFT JOIN accounts a ON sa.account_id = a.id
             $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT ad.di_number, 
               ad.due_date,
               MIN(ad.created_at) as request_date,
               a.nama_pt, 
               a.badan_usaha,
               a.alamat,
               u.full_name as sales_name,
               sa.sales_id,
               sa.id as sales_activity_id,
               ad.id as activity_detail_id,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM detail_delivery_instructions ddi 
                       WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'rejected'
                   ) THEN 'rejected'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_delivery_instructions ddi 
                       WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'pending'
                   ) THEN 'pending'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_delivery_instructions ddi 
                       WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'approved'
                   ) THEN 'approved'
                   ELSE 'pending'
               END as status
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        $where
        GROUP BY ad.di_number, sa.sales_id, sa.id
        ORDER BY request_date DESC
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$statWhere = "WHERE ad.di_number IS NOT NULL AND ad.di_number != ''";
$statParams = [];

if ($userRole === 'sales') {
    $statWhere .= " AND sa.sales_id = ?";
    $statParams[] = $userId;
}

$sqlPending = "SELECT COUNT(DISTINCT ad.di_number) FROM activity_details ad
               LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
               $statWhere 
               AND (
                   NOT EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci)
                   OR EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'pending')
               )";
$stmt = $db->prepare($sqlPending);
$stmt->execute($statParams);
$totalPending = $stmt->fetchColumn();

$sqlApproved = "SELECT COUNT(DISTINCT ad.di_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'approved')
                AND NOT EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status IN ('pending', 'rejected'))";
$stmt = $db->prepare($sqlApproved);
$stmt->execute($statParams);
$totalApproved = $stmt->fetchColumn();

$sqlRejected = "SELECT COUNT(DISTINCT ad.di_number) FROM activity_details ad
                LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                $statWhere 
                AND EXISTS (SELECT 1 FROM detail_delivery_instructions ddi WHERE ddi.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci AND ddi.status = 'rejected')";
$stmt = $db->prepare($sqlRejected);
$stmt->execute($statParams);
$totalRejected = $stmt->fetchColumn();

$totalDeliveries = $totalPending + $totalApproved + $totalRejected;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Delivery Instruction - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
:root{--bg:#060b18;--panel:#0a1220;--line:rgba(148,163,184,.13);--text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--green:#34d399;--yellow:#f6cf63;--red:#fb7185}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;background:var(--bg);color:var(--text)}
body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);overflow-x:hidden}
.content{margin-left:245px;width:calc(100% - 245px);padding:28px 30px 52px;min-height:calc(100vh - 72px)}
.page-header{min-height:48px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:18px}
.page-header h4{display:flex;align-items:center;gap:10px;margin:0;font-size:25px;font-weight:800;line-height:1.1;letter-spacing:-.6px;color:#eef4ff}
.page-header h4 span{width:38px;height:38px;border-radius:11px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.15);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.page-header h4 span i{font-size:15px;color:var(--blue2)!important}
.card-custom{background:#0a1220;border:1px solid var(--line);border-radius:15px;overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.12);color:#eaf0f8}
.card-custom:hover{border-color:rgba(96,165,250,.30);box-shadow:0 18px 38px rgba(0,0,0,.18)}
.card-header-custom{min-height:62px;padding:13px 17px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:rgba(13,23,41,.55)}
.card-header-custom h6{margin:0;color:#eaf1fc;font-size:12px;font-weight:750;letter-spacing:.1px}.card-header-custom h6 i{color:var(--blue2);margin-right:7px}
.card-header-custom form{margin-left:auto;display:flex;align-items:center;gap:7px}.card-header-custom form input{width:230px!important;height:36px}
.card-header-custom form .btn{height:36px;display:inline-flex;align-items:center;justify-content:center;padding:0 13px!important}
.card-body-custom{padding:0;background:rgba(3,8,18,.12);overflow-x:auto}.table-responsive{overflow-x:auto}
.table-custom{width:100%;min-width:900px;margin:0!important;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1}
.table-custom th{height:43px;padding:11px 16px!important;background:rgba(5,12,25,.48)!important;color:#66758f!important;border-bottom:1px solid rgba(148,163,184,.10)!important;border-top:0!important;font-size:9px!important;font-weight:800!important;letter-spacing:1px;text-transform:uppercase;white-space:nowrap}
.table-custom td{height:56px;padding:12px 16px!important;border-bottom:1px solid rgba(148,163,184,.07)!important;color:#aeb9cb!important;vertical-align:middle;background:transparent!important}
.table-custom tbody tr{transition:.16s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important;color:#d7e1ef!important}.table-custom tbody tr:last-child td{border-bottom:0!important}
.table-custom th:first-child,.table-custom td:first-child{width:58px;text-align:center;color:#7f8da5}.table-custom th:nth-child(2){width:15%}.table-custom th:nth-child(3){width:23%}.table-custom th:nth-child(4){width:13%}.table-custom th:nth-child(5){width:13%}.table-custom th:nth-child(6){width:15%}.table-custom th:nth-child(7){width:11%}.table-custom th:last-child,.table-custom td:last-child{width:112px;text-align:center}
.table-custom td strong{color:#e5edf8;font-weight:700;display:block;line-height:1.4}
.di-number-link{color:#7eb6ff;text-decoration:none;font-weight:750;letter-spacing:.2px}.di-number-link:hover{color:#a8d0ff}
.badge-status-di{display:inline-flex;align-items:center;gap:5px;min-height:22px;padding:3px 9px;border-radius:999px;font-size:9px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-status-di.pending{background:rgba(251,191,36,.10);color:#f6cf63;border-color:rgba(251,191,36,.14)}.badge-status-di.approved{background:rgba(52,211,153,.09);color:#69e5b0;border-color:rgba(52,211,153,.13)}.badge-status-di.rejected{background:rgba(251,113,133,.09);color:#ff8da0;border-color:rgba(251,113,133,.13)}
.btn-pdf,.btn-pdf-disabled{min-width:58px;height:30px;border-radius:8px;padding:0 10px;font-size:10px;display:inline-flex;align-items:center;justify-content:center;gap:5px;font-weight:700;text-decoration:none;transition:.18s}
.btn-pdf{background:rgba(52,211,153,.09);color:#69e5b0;border:1px solid rgba(52,211,153,.13)}.btn-pdf:hover{background:rgba(52,211,153,.16);color:#a7f3d0;transform:translateY(-1px)}
.btn-pdf-disabled{background:rgba(148,163,184,.07);color:#59677c;border:1px solid rgba(148,163,184,.10);cursor:not-allowed}
.btn-primary-custom{height:38px;background:linear-gradient(135deg,#3b82f6,#6366f1)!important;color:#fff!important;border:0!important;border-radius:11px!important;font-size:11px!important;font-weight:700!important;padding:0 15px!important;display:inline-flex;align-items:center;justify-content:center;gap:6px;transition:.18s!important}.btn-primary-custom:hover{background:#1d4ed8!important;transform:translateY(-1px);box-shadow:0 8px 20px rgba(37,99,235,.20)}
.btn-secondary-custom{height:36px;background:#111d31!important;color:#8f9db4!important;border:1px solid rgba(148,163,184,.13)!important;border-radius:9px!important;font-size:11px!important;font-weight:600!important;padding:0 13px!important;display:inline-flex;align-items:center;justify-content:center}.btn-secondary-custom:hover{background:#172238!important;color:#d6deea!important}
.filter-wrap{padding:11px 17px;border-bottom:1px solid rgba(148,163,184,.08);background:rgba(8,15,28,.45)}.filter-buttons{display:flex;gap:7px;flex-wrap:wrap}.filter-buttons .btn-filter{min-height:29px;padding:5px 11px;border-radius:999px;font-size:9px;font-weight:700;border:1px solid rgba(148,163,184,.13);background:#0c1728;color:#75839a;text-decoration:none;transition:.18s}.filter-buttons .btn-filter:hover{border-color:rgba(96,165,250,.28);color:#dce7f7}.filter-buttons .btn-filter.active{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 5px 15px rgba(37,99,235,.16)}.filter-buttons .btn-filter .count{margin-left:4px;color:inherit;opacity:.8}
.pagination{margin:0!important;gap:4px}.pagination .page-link{background:#0d1727;color:#8998ae;border:1px solid rgba(148,163,184,.10);font-size:10px;padding:6px 9px;border-radius:8px!important}.pagination .page-link:hover{background:#14213a;color:#fff}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff}.card-footer{background:rgba(10,18,32,.75)!important;border-color:rgba(148,163,184,.08)!important}
.form-control,.form-select{background:#0a1220!important;color:#dbe5f2!important;border:1px solid rgba(148,163,184,.15)!important;border-radius:8px!important;font-size:11px!important;padding:9px 11px!important}.form-control::placeholder{color:#53627a!important}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55)!important;box-shadow:0 0 0 3px rgba(59,130,246,.10)!important;background:#0b172d!important;color:#fff!important}
.alert{border-radius:9px!important;border:1px solid rgba(148,163,184,.10)!important;font-size:11px!important;margin:12px 14px!important}.footer-text{text-align:center;padding:16px 0 8px;color:#4f5e74;font-size:9px}.footer-text a{color:#71819a;text-decoration:none}.footer-text a:hover{color:#60a5fa}
html,body{scrollbar-color:rgba(96,165,250,.32) #060b18;scrollbar-width:thin}html::-webkit-scrollbar,body::-webkit-scrollbar{width:7px;height:7px}html::-webkit-scrollbar-track,body::-webkit-scrollbar-track{background:#060b18}html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.30);border-radius:999px}.table-responsive::-webkit-scrollbar{height:7px}.table-responsive::-webkit-scrollbar-track{background:#060b18}.table-responsive::-webkit-scrollbar-thumb{background:rgba(96,165,250,.28);border-radius:999px}
@media(max-width:1050px){.content{padding:24px 20px 45px}.page-header{align-items:flex-start}}
@media(max-width:800px){.content{margin-left:0;width:100%;padding:20px 14px 40px}.page-header{flex-direction:column;align-items:flex-start;gap:14px}.card-header-custom{align-items:stretch}.card-header-custom form{width:100%;margin:0}.card-header-custom form input{width:100%!important;flex:1}.table-custom{min-width:860px}}
@media(max-width:480px){.content{padding:18px 10px 35px}.page-header h4{font-size:20px}.page-header h4 span{width:34px;height:34px;border-radius:9px}.table-custom{min-width:820px}}
    </style>
</head>
<body>

    <?php require_once 'navigation.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h4><span><i class="fas fa-truck-moving"></i></span> Delivery Instruction</h4>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Delivery Instruction</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm search-input" placeholder="Cari DI number atau account..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="deliveryinstruction.php" class="btn btn-secondary-custom"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Filter Status -->
            <div class="filter-wrap">
                <div class="filter-buttons">
                    <a href="?status=all&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'all' ? 'active' : '' ?>">
                        Semua <span class="count"><?= $totalDeliveries ?></span>
                    </a>
                    <a href="?status=pending&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'pending' ? 'active' : '' ?>">
                        <i class="fas fa-clock fa-fw"></i> Pending <span class="count"><?= $totalPending ?></span>
                    </a>
                    <a href="?status=approved&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'approved' ? 'active' : '' ?>">
                        <i class="fas fa-check-circle fa-fw"></i> Approved <span class="count"><?= $totalApproved ?></span>
                    </a>
                    <a href="?status=rejected&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'rejected' ? 'active' : '' ?>">
                        <i class="fas fa-times-circle fa-fw"></i> Rejected <span class="count"><?= $totalRejected ?></span>
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
                                <th>DI Number</th>
                                <th>Account</th>
                                <th>Request Date</th>
                                <th>Due Date</th>
                                <th>Sales</th>
                                <th>Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($deliveries) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($deliveries as $delivery): ?>
                                    <?php 
                                    $statusLabel = ucfirst($delivery['status']);
                                    $statusClass = $delivery['status'];
                                    $isApproved = ($delivery['status'] == 'approved');
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <a href="detaildi.php?di_number=<?= urlencode($delivery['di_number']) ?>" class="di-number-link">
                                                <?= htmlspecialchars($delivery['di_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($delivery['nama_pt'] ?? '-') ?></td>
                                        <td><?= date('d/m/Y', strtotime($delivery['request_date'])) ?></td>
                                        <td><?= date('d/m/Y', strtotime($delivery['due_date'])) ?></td>
                                        <td><?= htmlspecialchars($delivery['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-status-di <?= $statusClass ?>">
                                                <?php if ($delivery['status'] == 'pending'): ?>
                                                    <i class="fas fa-clock"></i>
                                                <?php elseif ($delivery['status'] == 'approved'): ?>
                                                    <i class="fas fa-check-circle"></i>
                                                <?php elseif ($delivery['status'] == 'rejected'): ?>
                                                    <i class="fas fa-times-circle"></i>
                                                <?php endif; ?>
                                                <?= $statusLabel ?>
                                            </span>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($isApproved): ?>
                                                <a href="export_detail_pdf_di.php?di_number=<?= urlencode($delivery['di_number']) ?>" 
                                                   class="btn-pdf" 
                                                   target="_blank"
                                                   title="Download PDF Detail DI">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </a>
                                            <?php else: ?>
                                                <span class="btn-pdf-disabled" title="PDF hanya tersedia untuk DI yang sudah Approved">
                                                    <i class="fas fa-file-pdf"></i> PDF
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data delivery instruction
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

    </main>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>