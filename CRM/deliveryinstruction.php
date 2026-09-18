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
    <link rel="stylesheet" href="css/navigation.css">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="css/deliveryinstruction.css">
    <link rel="stylesheet" href="css/notifications.css">

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