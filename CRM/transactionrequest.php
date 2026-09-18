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

    <style>
        :root{
            --bg:#060b18;
            --panel:#0b1222;
            --panel2:#0d1730;
            --line:rgba(148,163,184,.16);
            --text:#f7f9ff;
            --muted:#8e9bb5;
            --blue:#3b82f6;
            --blue2:#60a5fa;
            --green:#34d399;
            --red:#fb7185;
            --amber:#fbbf24;
        }

        *{box-sizing:border-box;margin:0;padding:0}

        html{
            background:var(--bg);
            scrollbar-color:rgba(96,165,250,.32) var(--bg);
            scrollbar-width:thin;
        }

        body{
            font-family:Inter,Arial,sans-serif;
            background:
                radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),
                linear-gradient(145deg,#050914,#08111f 55%,#07162c);
            color:var(--text);
            min-height:100vh;
            overflow-x:hidden;
        }

        a{color:inherit}

        .content{
            margin-left:245px;
            width:calc(100% - 245px);
            padding:26px 28px 50px;
            min-height:calc(100vh - 72px);
        }

        .page-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:20px;
            margin-bottom:20px;
            flex-wrap:wrap;
        }

        .page-header h4{
            display:flex;
            align-items:center;
            gap:10px;
            font-size:25px;
            line-height:1.1;
            font-weight:800;
            letter-spacing:-.4px;
            color:var(--text);
            margin:0;
        }

        .page-header h4 span{
            width:38px;
            height:38px;
            border-radius:11px;
            background:rgba(96,165,250,.10);
            border:1px solid rgba(96,165,250,.15);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            flex-shrink:0;
        }

        .page-header h4 span i{
            font-size:15px;
            color:var(--blue2);
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:14px;
            margin-bottom:18px;
        }

        .stat-card{
            min-height:128px;
            background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));
            border:1px solid var(--line);
            border-radius:17px;
            box-shadow:0 18px 45px rgba(0,0,0,.18);
            padding:18px 19px;
            color:#eaf0f8;
            transition:.25s;
        }

        .stat-card:hover{
            border-color:rgba(96,165,250,.35);
            box-shadow:0 20px 48px rgba(0,0,0,.25);
            transform:translateY(-1px);
        }

        .stat-icon{
            width:38px;
            height:38px;
            border-radius:11px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:14px;
        }

        .stat-icon.gold{background:rgba(212,160,23,.12);color:#e0b53d}
        .stat-icon.blue{background:rgba(59,130,246,.12);color:var(--blue2)}
        .stat-icon.red{background:rgba(251,113,133,.10);color:var(--red)}

        .stat-number{
            color:var(--text);
            font-size:24px;
            font-weight:800;
            line-height:1;
            margin-top:15px;
            margin-bottom:6px;
        }

        .stat-label{
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:.7px;
            font-weight:700;
            color:#70809b;
        }

        .card-custom{
            background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));
            border:1px solid var(--line);
            border-radius:16px;
            box-shadow:0 18px 45px rgba(0,0,0,.18);
            overflow:hidden;
            transition:.25s;
        }

        .card-custom:hover{
            border-color:rgba(96,165,250,.35);
            box-shadow:0 20px 48px rgba(0,0,0,.25);
        }

        .card-header-custom{
            min-height:62px;
            padding:13px 17px;
            border-bottom:1px solid rgba(148,163,184,.10);
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .card-header-custom h6{
            font-weight:700;
            color:var(--text);
            margin:0;
            font-size:12px;
        }

        .card-header-custom h6 i{
            color:var(--blue2);
            margin-right:8px;
        }

        .card-header-custom form{
            display:flex;
            align-items:center;
            gap:7px;
        }

        .card-header-custom input{
            height:36px;
            width:230px;
            background:#0a1427!important;
            border:1px solid rgba(148,163,184,.16)!important;
            color:#dbe5f5!important;
            border-radius:9px!important;
            font-size:11px;
        }

        .card-header-custom input::placeholder{color:#52627d}

        .btn-primary-custom{
            background:linear-gradient(135deg,#3b82f6,#6366f1);
            border:0;
            border-radius:9px;
            padding:9px 15px;
            font-weight:700;
            font-size:11px;
            color:#fff;
            transition:.2s;
        }

        .btn-primary-custom:hover{
            background:linear-gradient(135deg,#4f8df7,#6d70f3);
            transform:translateY(-1px);
            box-shadow:0 8px 22px rgba(59,130,246,.2);
            color:#fff;
        }

        .btn-secondary-custom{
            background:#111d31;
            border:1px solid rgba(148,163,184,.13);
            border-radius:9px;
            padding:9px 15px;
            font-weight:600;
            font-size:11px;
            color:#8f9db4;
            transition:.2s;
        }

        .btn-secondary-custom:hover{
            background:#17253d;
            color:#fff;
            border-color:rgba(148,163,184,.22);
        }

        .filter-bar{
            padding:13px 17px 11px;
            border-bottom:1px solid rgba(148,163,184,.10);
        }

        .filter-buttons{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }

        .btn-filter{
            padding:7px 12px;
            border:1px solid rgba(148,163,184,.12);
            background:rgba(10,17,33,.85);
            border-radius:9px;
            color:#8492aa;
            text-decoration:none;
            font-size:10px;
            font-weight:600;
            transition:.2s;
        }

        .btn-filter:hover{
            color:#fff;
            border-color:rgba(96,165,250,.25);
            background:#10203a;
        }

        .btn-filter.active{
            background:rgba(37,99,235,.15);
            border-color:rgba(96,165,250,.3);
            color:#9fc5ff;
        }

        .btn-filter .count{
            background:rgba(255,255,255,.05);
            padding:2px 6px;
            border-radius:10px;
            margin-left:4px;
        }

        .next-approver-filter-form{
            display:flex;
            align-items:center;
            margin-left:auto;
        }

        .next-approver-filter-wrap{
            height:34px;
            display:flex;
            align-items:center;
            gap:7px;
            padding:0 10px;
            background:rgba(10,17,33,.92);
            border:1px solid rgba(148,163,184,.14);
            border-radius:9px;
            transition:.2s;
        }

        .next-approver-filter-wrap:focus-within{
            border-color:rgba(96,165,250,.35);
            background:#10203a;
            box-shadow:0 0 0 3px rgba(59,130,246,.06);
        }

        .next-approver-filter-wrap > i{
            color:var(--blue2);
            font-size:10px;
        }

        .next-approver-select{
            min-width:175px;
            max-width:210px;
            border:0;
            outline:0;
            background:transparent;
            color:#cbd5e1;
            font-family:Inter,Arial,sans-serif;
            font-size:10px;
            font-weight:600;
            cursor:pointer;
        }

        .next-approver-select option{
            background:#0b1222;
            color:#e8eef7;
        }

        .table-responsive{
            background:transparent;
            overflow-x:auto;
        }

        .table-custom{
            margin-bottom:0!important;
            width:100%;
            min-width:1080px;
            font-size:10px;
            color:#cbd5e1;
            --bs-table-bg:transparent;
            --bs-table-color:#cbd5e1;
            --bs-table-border-color:transparent;
        }

        .table-custom th{
            height:43px;
            padding:11px 13px!important;
            background:rgba(5,12,25,.48)!important;
            border-bottom:1px solid rgba(148,163,184,.10)!important;
            color:#66758f!important;
            font-size:8.5px;
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.6px;
            white-space:nowrap;
        }

        .table-custom td{
            height:54px;
            padding:10px 13px!important;
            vertical-align:middle;
            border-bottom:1px solid rgba(148,163,184,.07)!important;
            color:#cbd5e1!important;
            background:transparent!important;
        }

        .table-custom tbody tr{transition:.15s}
        .table-custom tbody tr:hover td{
            background:rgba(59,130,246,.035)!important;
            color:#e8eef7!important;
        }

        .table-custom tr:last-child td{border-bottom:none!important}

        .table-custom a{
            color:var(--blue2)!important;
            text-decoration:none;
            font-weight:700;
        }

        .table-custom a:hover{color:#93c5fd!important}

        .table-custom th:first-child,
        .table-custom td:first-child{
            width:48px;
            text-align:center;
        }

        .table-custom th:nth-child(2){min-width:120px}
        .table-custom th:nth-child(3){min-width:190px}
        .table-custom th:nth-child(4){min-width:105px}
        .table-custom th:nth-child(5){min-width:150px}
        .table-custom th:nth-child(6){min-width:135px}
        .table-custom th:nth-child(7){min-width:105px}
        .table-custom th:nth-child(8),
        .table-custom td:last-child{
            width:92px;
            text-align:center;
        }

        .account-name{
            display:flex;
            align-items:center;
            gap:2px;
            min-width:0;
            white-space:nowrap;
        }

        .account-name strong{
            font-size:10px;
            font-weight:700;
            color:#e8eef7;
        }

        .account-name span{
            font-size:10px;
            font-weight:600;
            color:#8e9bb5;
        }

        .current-approver{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:28px;
            padding:5px 9px;
            border-radius:8px;
            background:rgba(96,165,250,.08);
            border:1px solid rgba(96,165,250,.14);
            color:#cbd5e1;
            font-size:9px;
            font-weight:700;
            white-space:nowrap;
        }

        .badge-status-tr{
            display:inline-flex;
            align-items:center;
            gap:5px;
            padding:5px 9px;
            border-radius:999px;
            font-size:8px;
            font-weight:700;
            white-space:nowrap;
            border:1px solid transparent;
        }

        .badge-status-tr.pending{
            background:rgba(251,191,36,.10);
            color:#fcd34d;
            border-color:rgba(251,191,36,.15);
        }

        .badge-status-tr.approved{
            background:rgba(52,211,153,.10);
            color:#6ee7b7;
            border-color:rgba(52,211,153,.15);
        }

        .badge-status-tr.rejected{
            background:rgba(251,113,133,.10);
            color:var(--red);
            border-color:rgba(251,113,133,.15);
        }

        .btn-pdf,
        .btn-pdf-disabled{
            display:inline-flex;
            align-items:center;
            gap:5px;
            border-radius:8px;
            padding:6px 10px;
            font-size:9px;
            font-weight:700;
        }

        .btn-pdf{
            background:rgba(52,211,153,.10);
            border:1px solid rgba(52,211,153,.15);
            color:#6ee7b7;
            text-decoration:none;
            transition:.2s;
        }

        .btn-pdf:hover{
            color:#a7f3d0;
            background:rgba(52,211,153,.14);
            transform:translateY(-1px);
        }

        .btn-pdf-disabled{
            background:rgba(100,116,139,.08);
            border:1px solid rgba(100,116,139,.12);
            color:#59677d;
            cursor:not-allowed;
        }

        .card-footer{
            background:rgba(5,12,25,.35)!important;
            border-top:1px solid rgba(148,163,184,.08)!important;
        }

        .pagination{gap:4px}

        .pagination .page-link{
            background:#0a1427;
            border:1px solid rgba(148,163,184,.12);
            color:#8492aa;
            border-radius:8px!important;
            font-size:9px;
            padding:6px 9px;
        }

        .pagination .page-link:hover{
            background:#10203a;
            color:#fff;
            border-color:rgba(96,165,250,.25);
        }

        .pagination .page-item.active .page-link{
            background:#2563eb;
            border-color:#3b82f6;
            color:#fff;
            box-shadow:0 0 15px rgba(59,130,246,.22);
        }

        .text-muted{color:#64748b!important}

        .footer-text{
            text-align:center;
            color:#44536c;
            font-size:9px;
            margin-top:20px;
        }

        .footer-text a{
            color:#6b7a94;
            text-decoration:none;
        }

        .footer-text a:hover{color:var(--blue2)}

        html::-webkit-scrollbar,
        body::-webkit-scrollbar{
            width:7px;
            height:7px;
        }

        html::-webkit-scrollbar-track,
        body::-webkit-scrollbar-track{background:var(--bg)}

        html::-webkit-scrollbar-thumb,
        body::-webkit-scrollbar-thumb{
            background:rgba(96,165,250,.30);
            border-radius:999px;
            border:1px solid rgba(6,11,24,.9);
        }

        html::-webkit-scrollbar-thumb:hover,
        body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.48)}

        @media(max-width:991px){
            .content{
                margin-left:0;
                width:100%;
                padding:92px 18px 24px;
            }

            .stat-grid{
                grid-template-columns:repeat(2,minmax(0,1fr));
            }

            .page-header{align-items:flex-start}
        }

        @media(max-width:800px){
            .content{padding:20px 14px 40px}

            .page-header{
                align-items:flex-start;
                flex-direction:column;
            }

            .table-custom{min-width:1080px}
        }

        @media(max-width:520px){
            .content{padding:17px 10px 34px}

            .page-header h4{font-size:20px}

            .page-header h4 span{
                width:34px;
                height:34px;
                border-radius:9px;
            }

            .stat-grid{gap:9px}

            .stat-card{
                min-height:110px;
                padding:13px;
                border-radius:14px;
            }

            .stat-number{font-size:20px}

            .card-header-custom{
                align-items:flex-start;
                padding:13px;
            }

            .card-header-custom form{width:100%}

            .card-header-custom input{
                flex:1;
                width:auto;
            }

            .filter-buttons{
                overflow-x:auto;
                flex-wrap:nowrap;
                padding-bottom:2px;
            }

            .next-approver-filter-form{
                width:100%;
                margin-left:0;
            }

            .next-approver-filter-wrap{
                width:100%;
            }

            .next-approver-select{
                min-width:0;
                width:100%;
                max-width:none;
            }

            .btn-filter{white-space:nowrap}
        }
    </style>
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