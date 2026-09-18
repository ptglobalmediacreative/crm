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
// APPROVAL FLOW - SAMA DENGAN detailtr.php
// ============================================
$approvalLevels = [
    1 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    2 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    3 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    4 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];
$totalApprovalLevels = count($approvalLevels);

function getCurrentApproverForTR(PDO $db, string $trNumber, array $approvalLevels, int $totalApprovalLevels): string
{
    try {
        // Ambil detail TR TERBARU, persis berdasarkan TR Number.
        $stmt = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$trNumber]);
        $detailTR = $stmt->fetch(PDO::FETCH_ASSOC);

        // Jika detail TR belum ada, flow di detailtr.php memulai dari Sales Manager.
        if (!$detailTR) {
            return $approvalLevels[1]['label'];
        }

        // Ambil approval history untuk TR Number yang sama.
        $stmt = $db->prepare("SELECT approval_order, approval_role, status FROM tr_approval_history WHERE trf_number = ? ORDER BY approval_order ASC");
        $stmt->execute([$trNumber]);
        $approvalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $approvedByOrder = [];
        $rejectedByCurrentFlow = false;

        foreach ($approvalHistory as $approval) {
            $order = (int)($approval['approval_order'] ?? 0);
            if (!isset($approvalLevels[$order])) {
                continue;
            }
            if (($approval['approval_role'] ?? '') !== $approvalLevels[$order]['role']) {
                continue;
            }

            if (($approval['status'] ?? '') === 'approved') {
                $approvedByOrder[$order] = true;
            } elseif (($approval['status'] ?? '') === 'rejected') {
                $rejectedByCurrentFlow = true;
            }
        }

        if ($rejectedByCurrentFlow || ($detailTR['status'] ?? '') === 'rejected') {
            return 'No More Approval';
        }

        if (($detailTR['status'] ?? '') === 'approved') {
            return 'No More Approval';
        }

        $lastApprovedOrder = 0;
        for ($order = 1; $order <= $totalApprovalLevels; $order++) {
            if (!empty($approvedByOrder[$order])) {
                $lastApprovedOrder = $order;
            } else {
                break;
            }
        }

        $currentApprovalOrder = $lastApprovedOrder + 1;
        return $currentApprovalOrder <= $totalApprovalLevels
            ? $approvalLevels[$currentApprovalOrder]['label']
            : 'No More Approval';
    } catch (Exception $e) {
        return '-';
    }
}

// ============================================
// FILTER & PAGINATION
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$next_approver_filter = isset($_GET['next_approver']) ? $_GET['next_approver'] : 'all';

$allowedNextApprovers = [
    'Sales Manager',
    'Direktur Sales',
    'Direktur Operasional',
    'Direktur Utama',
    'No More Approval'
];

if ($next_approver_filter !== 'all' && !in_array($next_approver_filter, $allowedNextApprovers, true)) {
    $next_approver_filter = 'all';
}

// ============================================
// AMBIL DATA TR NUMBER DARI ACTIVITY_DETAILS
// ============================================
$where = "WHERE ad.tr_number IS NOT NULL AND ad.tr_number != ''";
$params = [];
$nextApproverSql = "(CASE
    WHEN NOT EXISTS (
        SELECT 1 FROM detail_transaction_requests d0
        WHERE d0.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
    ) THEN 'Sales Manager'
    WHEN EXISTS (
        SELECT 1 FROM detail_transaction_requests d0
        WHERE d0.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND d0.status = 'rejected'
    ) THEN 'No More Approval'
    WHEN EXISTS (
        SELECT 1 FROM detail_transaction_requests d0
        WHERE d0.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND d0.status = 'approved'
    ) AND NOT EXISTS (
        SELECT 1 FROM detail_transaction_requests d0
        WHERE d0.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND d0.status IN ('pending', 'rejected')
    ) THEN 'No More Approval'
    WHEN EXISTS (
        SELECT 1 FROM tr_approval_history ah1
        WHERE ah1.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah1.approval_order = 1
          AND ah1.approval_role = 'sales_manager'
          AND ah1.status = 'approved'
    ) AND NOT EXISTS (
        SELECT 1 FROM tr_approval_history ah2
        WHERE ah2.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah2.approval_order = 2
          AND ah2.approval_role = 'direktur_sales'
          AND ah2.status = 'approved'
    ) THEN 'Direktur Sales'
    WHEN EXISTS (
        SELECT 1 FROM tr_approval_history ah1
        WHERE ah1.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah1.approval_order = 1
          AND ah1.approval_role = 'sales_manager'
          AND ah1.status = 'approved'
    ) AND EXISTS (
        SELECT 1 FROM tr_approval_history ah2
        WHERE ah2.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah2.approval_order = 2
          AND ah2.approval_role = 'direktur_sales'
          AND ah2.status = 'approved'
    ) AND NOT EXISTS (
        SELECT 1 FROM tr_approval_history ah3
        WHERE ah3.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah3.approval_order = 3
          AND ah3.approval_role = 'direktur_operasional'
          AND ah3.status = 'approved'
    ) THEN 'Direktur Operasional'
    WHEN EXISTS (
        SELECT 1 FROM tr_approval_history ah1
        WHERE ah1.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah1.approval_order = 1
          AND ah1.approval_role = 'sales_manager'
          AND ah1.status = 'approved'
    ) AND EXISTS (
        SELECT 1 FROM tr_approval_history ah2
        WHERE ah2.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah2.approval_order = 2
          AND ah2.approval_role = 'direktur_sales'
          AND ah2.status = 'approved'
    ) AND EXISTS (
        SELECT 1 FROM tr_approval_history ah3
        WHERE ah3.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah3.approval_order = 3
          AND ah3.approval_role = 'direktur_operasional'
          AND ah3.status = 'approved'
    ) AND NOT EXISTS (
        SELECT 1 FROM tr_approval_history ah4
        WHERE ah4.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
          AND ah4.approval_order = 4
          AND ah4.approval_role = 'direktur_utama'
          AND ah4.status = 'approved'
    ) THEN 'Direktur Utama'
    ELSE 'Sales Manager'
END)";

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

if ($next_approver_filter !== 'all') {
    $where .= " AND $nextApproverSql = ?";
    $params[] = $next_approver_filter;
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
               END as status,
               $nextApproverSql as next_approver
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
    <link rel="stylesheet" href="css/navigation.css">
    <link rel="stylesheet" href="css/transactionrequest.css">
    <link rel="stylesheet" href="css/notifications.css">
</head>
<body>

    <?php require_once 'navigation.php'; ?>

    <main class="content">

<!-- PAGE HEADER -->
<div class="page-header">
    <h4><span><i class="fas fa-file-signature"></i></span> Transaction Request</h4>
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
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="transactionrequest.php" class="btn btn-secondary-custom"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Filter Status -->
            <div class="filter-bar">
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

                    <form method="GET" class="next-approver-filter-form">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <div class="next-approver-filter-wrap">
                            <i class="fas fa-user-check"></i>
                            <select name="next_approver" class="next-approver-select" onchange="this.form.submit()">
                                <option value="all" <?= $next_approver_filter === 'all' ? 'selected' : '' ?>>Semua Next Approver</option>
                                <?php foreach ($allowedNextApprovers as $approver): ?>
                                    <option value="<?= htmlspecialchars($approver) ?>" <?= $next_approver_filter === $approver ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($approver) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if ($next_approver_filter !== 'all'): ?>
                        <a href="?status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>" class="btn-filter" title="Reset filter Next Approver">
                            <i class="fas fa-times"></i> Reset Approver
                        </a>
                    <?php endif; ?>
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
                                <th>Sales</th>
                                <th>Next Approver</th>
                                <th>Status</th>
                                <th>Action</th>
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
                                        <td>
    <div class="account-name">
        <strong><?= htmlspecialchars($request['nama_pt'] ?? '-') ?></strong>
        <?php if (!empty($request['badan_usaha'])): ?>
            <span>, <?= htmlspecialchars($request['badan_usaha']) ?></span>
        <?php endif; ?>
    </div>
</td>
                                        <td><?= date('d/m/Y', strtotime($request['request_date'])) ?></td>
                                        <td><?= htmlspecialchars($request['sales_name'] ?? '-') ?></td>
                                        <td><span class="current-approver"><?= htmlspecialchars($request['next_approver'] ?? '-') ?></span></td>
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
                                        <td>
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
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>">Next</a></li>
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