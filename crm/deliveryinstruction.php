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
if (!function_exists('getRoleLabel')) {
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
            'sales' => 'Sales',
            'service_support' => 'Service Support',
            'part_support' => 'Part Support'
        ];
        return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }
}

// ============================================
// FUNGSI: HITUNG CURRENT APPROVER LABEL
// ------------------------------------------------------------
// Logika ini SAMA PERSIS dengan detaildi.php.
// Sumber data yang dipakai adalah RIWAYAT APPROVAL
// (di_approval_history), bukan kolom current_approval_order,
// supaya nilai yang ditampilkan konsisten dengan halaman detail.
// ============================================
function getDICurrentApproverLabel(PDO $db, string $diNumber): string
{
    $levelLabels = [
        1 => 'Business',
        2 => 'Part Support',
        3 => 'Service Support',
        4 => 'Finance',
        5 => 'Sales Manager',
        6 => 'Direktur Sales',
        7 => 'Direktur Operasional',
        8 => 'Direktur Utama',
    ];

    // 1. Fetch detail DI (baris terbaru).
    $detail = null;
    try {
        $stmt = $db->prepare("
            SELECT status, no_so
            FROM detail_delivery_instructions
            WHERE di_number = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([$diNumber]);
        $detail = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        $detail = null;
    }

    // 2. Fetch riwayat approval.
    $approvalHistory = [];
    try {
        $stmt = $db->prepare("
            SELECT approval_order, status
            FROM di_approval_history
            WHERE di_number = ?
            ORDER BY approval_order ASC
        ");
        $stmt->execute([$diNumber]);
        $approvalHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $approvalHistory = [];
    }

    // 3. Cek hasInputData (minimal 1 menu terisi).
    $hasInputData = false;
    if ($detail && trim((string)($detail['no_so'] ?? '')) !== '') {
        $hasInputData = true;
    }

    if (!$hasInputData) {
        $inputChecks = [
            "SELECT COUNT(*) FROM di_units WHERE di_number = ?",
            "SELECT COUNT(*) FROM di_accessories WHERE di_number = ?",
            "SELECT COUNT(*) FROM di_logistics WHERE di_number = ?",
            "SELECT COUNT(*) FROM di_product_supports WHERE di_number = ?",
            "SELECT COUNT(*) FROM di_parts WHERE di_number = ?",
            "SELECT COUNT(*) FROM di_logistics_comparisons WHERE di_number = ?",
        ];
        foreach ($inputChecks as $checkSql) {
            try {
                $c = $db->prepare($checkSql);
                $c->execute([$diNumber]);
                if ((int)$c->fetchColumn() > 0) {
                    $hasInputData = true;
                    break;
                }
            } catch (Exception $e) {
                // Lanjut cek berikutnya.
            }
        }
    }

    if (!$hasInputData) {
        return 'Belum ada data untuk approval';
    }

    // 4. Tentukan last approved order dari riwayat approval.
    $lastApprovedOrder = 0;
    $isRejected = false;
    foreach ($approvalHistory as $approval) {
        $status = strtolower(trim((string)($approval['status'] ?? '')));
        $order  = (int)($approval['approval_order'] ?? 0);

        if ($status === 'approved') {
            if ($order > $lastApprovedOrder) {
                $lastApprovedOrder = $order;
            }
        } elseif ($status === 'rejected') {
            $isRejected = true;
        }
    }

    $statusDI = strtolower(trim((string)($detail['status'] ?? 'pending')));

    // 5. Rejected → tolak priority.
    if ($isRejected || $statusDI === 'rejected') {
        return 'Rejected - Menunggu perbaikan Admin';
    }

    // 6. Approved → selesai.
    if ($statusDI === 'approved') {
        return 'Selesai';
    }

    // 7. Pending → current = last approved + 1.
    $currentOrder = $lastApprovedOrder + 1;
    if ($currentOrder >= 1 && $currentOrder <= 8) {
        return $levelLabels[$currentOrder];
    }

    return 'Selesai';
}

// ============================================
// CEK USER UNTUK AKSES
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

$fullAccessRoles = ['it_support', 'admin', 'finance', 'business', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$pdfAccessRoles = ['admin', 'business', 'sales_manager', 'direktur_sales', 'direktur_operasional', 'direktur_utama'];
$canAccessPdf = in_array($userRole, $pdfAccessRoles, true);
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
$next_approver_filter = isset($_GET['next_approver']) ? $_GET['next_approver'] : 'all';
$filter_period = isset($_GET['period']) ? trim($_GET['period']) : '';

$allowedNextApprovers = [
    'Business',
    'Part Support',
    'Service Support',
    'Finance',
    'Sales Manager',
    'Direktur Sales',
    'Direktur Operasional',
    'Direktur Utama',
    'No More Approval'
];

if ($next_approver_filter !== 'all' && !in_array($next_approver_filter, $allowedNextApprovers, true)) {
    $next_approver_filter = 'all';
}

$filterYear = 0;
$filterMonth = 0;
if ($filter_period !== '' && preg_match('/^\d{4}-\d{2}$/', $filter_period)) {
    [$filterYear, $filterMonth] = array_map('intval', explode('-', $filter_period));
    if ($filterYear < 2000 || $filterYear > 2100 || $filterMonth < 1 || $filterMonth > 12) {
        $filter_period = '';
        $filterYear = 0;
        $filterMonth = 0;
    }
} else {
    $filter_period = '';
}

$allowedStatus = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $allowedStatus)) {
    $status_filter = 'all';
}

// ============================================
// AMBIL DATA DI NUMBER DARI ACTIVITY_DETAILS
// ============================================
$where = "WHERE ad.di_number IS NOT NULL AND ad.di_number != ''";
$params = [];

$nextApproverSql = "(CASE
    WHEN NOT EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
    ) THEN 'Business'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.status = 'rejected'
    ) THEN 'No More Approval'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.status = 'approved'
    ) THEN 'No More Approval'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 1
    ) THEN 'Business'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 2
    ) THEN 'Part Support'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 3
    ) THEN 'Service Support'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 4
    ) THEN 'Finance'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 5
    ) THEN 'Sales Manager'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 6
    ) THEN 'Direktur Sales'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 7
    ) THEN 'Direktur Operasional'
    WHEN EXISTS (
        SELECT 1 FROM detail_delivery_instructions ddi0
        WHERE ddi0.di_number COLLATE utf8mb4_unicode_ci = ad.di_number COLLATE utf8mb4_unicode_ci
          AND ddi0.current_approval_order = 8
    ) THEN 'Direktur Utama'
    ELSE 'No More Approval'
END)";

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

if ($filter_period !== '') {
    $where .= " AND YEAR(ad.created_at) = ? AND MONTH(ad.created_at) = ?";
    $params[] = $filterYear;
    $params[] = $filterMonth;
}

if ($next_approver_filter !== 'all') {
    $where .= " AND (($nextApproverSql) COLLATE utf8mb4_unicode_ci) = (? COLLATE utf8mb4_unicode_ci)";
    $params[] = $next_approver_filter;
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
               $nextApproverSql as next_approver,
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

if ($filter_period !== '') {
    $statWhere .= " AND YEAR(ad.created_at) = ? AND MONTH(ad.created_at) = ?";
    $statParams[] = $filterYear;
    $statParams[] = $filterMonth;
}

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

// NOTE berdasarkan approval terakhir / status DI.
function getDINote(PDO $db, string $diNumber, string $status, string $nextApprover): string
{
    try {
        $stmt = $db->prepare("SELECT catatan FROM di_approval_history WHERE di_number = ? AND catatan IS NOT NULL AND TRIM(catatan) != '' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$diNumber]);
        $note = trim((string)$stmt->fetchColumn());
        if ($note !== '') {
            return $note;
        }
    } catch (Exception $e) {
        // fallback di bawah
    }

    if ($status === 'rejected') {
        return 'DI ditolak dan menunggu perbaikan Admin';
    }
    if ($status === 'approved') {
        return 'DI telah selesai melalui seluruh proses approval';
    }
    return $nextApprover !== '-' ? 'Menunggu approval ' . $nextApprover : 'Menunggu proses approval';
}

// ============================================
// HITUNG CURRENT APPROVER UNTUK SETIAP BARIS
// ------------------------------------------------------------
// Gunakan fungsi getDICurrentApproverLabel() di atas yang
// membaca riwayat approval, BUKAN kolom current_approval_order.
// Ini membuat nilai di halaman ini konsisten dengan
// "Current Approver" pada detaildi.php.
// ============================================
foreach ($deliveries as &$delivery) {
    $delivery['next_approver'] = getDICurrentApproverLabel($db, (string)($delivery['di_number'] ?? ''));
    $delivery['note'] = getDINote(
        $db,
        (string)($delivery['di_number'] ?? ''),
        (string)($delivery['status'] ?? 'pending'),
        (string)($delivery['next_approver'] ?? '-')
    );
}
unset($delivery);
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
    
    <link rel="stylesheet" href="css/deliveryinstruction.css">
    <link rel="stylesheet" href="css/footer.css">

</head>
<body class="page-deliveryinstruction">

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
            
            <!-- FILTER STATUS / CURRENT APPROVER / PERIODE -->
            <div class="filter-bar">
                <div class="filter-controls">

                    <!-- STATUS DROPDOWN -->
                    <form method="GET" class="status-filter-form">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="next_approver" value="<?= htmlspecialchars($next_approver_filter) ?>">
                        <input type="hidden" name="period" value="<?= htmlspecialchars($filter_period) ?>">
                        <div class="status-filter-wrap">
                            <i class="fas fa-filter"></i>
                            <select name="status" class="status-filter-select" onchange="this.form.submit()">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Semua (<?= $totalDeliveries ?>)</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending (<?= $totalPending ?>)</option>
                                <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Approved (<?= $totalApproved ?>)</option>
                                <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Rejected (<?= $totalRejected ?>)</option>
                            </select>
                        </div>
                    </form>

                    <!-- CURRENT APPROVER DROPDOWN -->
                    <form method="GET" class="next-approver-filter-form">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <input type="hidden" name="period" value="<?= htmlspecialchars($filter_period) ?>">
                        <div class="next-approver-filter-wrap">
                            <i class="fas fa-user-check"></i>
                            <select name="next_approver" class="next-approver-select" onchange="this.form.submit()">
                                <option value="all" <?= $next_approver_filter === 'all' ? 'selected' : '' ?>>Semua Current Approver</option>
                                <?php foreach ($allowedNextApprovers as $approver): ?>
                                    <option value="<?= htmlspecialchars($approver) ?>" <?= $next_approver_filter === $approver ? 'selected' : '' ?>><?= htmlspecialchars($approver) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <!-- FILTER PERIODE -->
                    <form method="GET" class="period-filter-form">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                        <input type="hidden" name="next_approver" value="<?= htmlspecialchars($next_approver_filter) ?>">
                        <div class="period-filter-wrap">
                            <i class="fas fa-calendar-alt"></i>
                            <span class="period-filter-placeholder" aria-hidden="true">
                                <?= !empty($filter_period) ? htmlspecialchars(date('F Y', strtotime($filter_period . '-01'))) : 'All Periode' ?>
                            </span>
                            <input type="month" name="period" class="period-filter-input" value="<?= htmlspecialchars($filter_period) ?>" onchange="this.form.submit()" aria-label="Filter periode">
                        </div>
                    </form>

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
                                <th>Sales</th>
                                <th>Current Approver</th>
                                <th>Status</th>
                                <th>Note</th>
                                <?php if ($canAccessPdf): ?>
                                <th style="text-align:center;">Action</th>
                                <?php endif; ?>
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
                                        <td><?= htmlspecialchars($delivery['sales_name'] ?? '-') ?></td>
                                        <td><span class="current-approver"><?= htmlspecialchars($delivery['next_approver'] ?? '-') ?></span></td>
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
                                        <td style="min-width: 280px; max-width: 420px;">
                                            <div class="current-approver tr-note <?= $delivery['status'] === 'rejected' ? 'tr-note-rejected' : ($delivery['status'] === 'approved' ? 'tr-note-approved' : 'tr-note-pending') ?>">
                                                <i class="fas <?= $delivery['status'] === 'rejected' ? 'fa-comment-slash' : ($delivery['status'] === 'approved' ? 'fa-circle-check' : 'fa-note-sticky') ?>"></i>
                                                <span><?= htmlspecialchars($delivery['note'] ?? '-') ?></span>
                                            </div>
                                        </td>
                                        <?php if ($canAccessPdf): ?>
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
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $canAccessPdf ? 9 : 8 ?>" class="text-center py-4 text-muted">
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
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>&period=<?= urlencode($filter_period) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>&period=<?= urlencode($filter_period) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&next_approver=<?= urlencode($next_approver_filter) ?>&period=<?= urlencode($filter_period) ?>">Next</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
        <?php require_once 'footer.php'; ?>

    </main>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.period-filter-wrap').forEach(function (wrap) {
            const input = wrap.querySelector('.period-filter-input');
            const placeholder = wrap.querySelector('.period-filter-placeholder');
            if (!input || !placeholder) return;
            function syncPeriodLabel() {
                if (input.value) {
                    const parts = input.value.split('-');
                    const months = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
                    const m = parseInt(parts[1], 10);
                    placeholder.textContent = (months[m - 1] || '') + ' ' + parts[0];
                } else {
                    placeholder.textContent = 'All Periode';
                }
            }
            syncPeriodLabel();
            input.addEventListener('change', syncPeriodLabel);
        });
    });
    </script>
</body>
</html>