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
// AMBIL DATA TRANSACTION REQUESTS
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

$where = "WHERE 1=1";
$params = [];

// Filter berdasarkan role
if ($userRole === 'sales') {
    $where .= " AND tr.sales_id = ?";
    $params[] = $userId;
}

if ($status_filter !== 'all') {
    $where .= " AND tr.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where .= " AND (tr.trf_number LIKE ? OR tr.subject LIKE ? OR a.nama_pt LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

// Count total
$countSql = "SELECT COUNT(*) FROM transaction_requests tr 
              LEFT JOIN accounts a ON tr.account_id = a.id 
              $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT tr.*, a.nama_pt, a.badan_usaha, u.full_name as sales_name,
        (SELECT full_name FROM users WHERE id = tr.approved_by) as approved_by_name,
        (SELECT full_name FROM users WHERE id = tr.rejected_by) as rejected_by_name
        FROM transaction_requests tr 
        LEFT JOIN accounts a ON tr.account_id = a.id 
        LEFT JOIN users u ON tr.sales_id = u.id 
        $where 
        ORDER BY tr.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$statWhere = "WHERE 1=1";
$statParams = [];

if ($userRole === 'sales') {
    $statWhere .= " AND sales_id = ?";
    $statParams[] = $userId;
}

$totalPending = $db->prepare("SELECT COUNT(*) FROM transaction_requests $statWhere AND status = 'pending'");
$totalPending->execute($statParams);
$totalPending = $totalPending->fetchColumn();

$totalApproved = $db->prepare("SELECT COUNT(*) FROM transaction_requests $statWhere AND status = 'approved'");
$totalApproved->execute($statParams);
$totalApproved = $totalApproved->fetchColumn();

$totalRejected = $db->prepare("SELECT COUNT(*) FROM transaction_requests $statWhere AND status = 'rejected'");
$totalRejected->execute($statParams);
$totalRejected = $totalRejected->fetchColumn();

$totalCompleted = $db->prepare("SELECT COUNT(*) FROM transaction_requests $statWhere AND status = 'completed'");
$totalCompleted->execute($statParams);
$totalCompleted = $totalCompleted->fetchColumn();

$totalRequests = $totalPending + $totalApproved + $totalRejected + $totalCompleted;

// ============================================
// PROSES UPDATE STATUS (APPROVE / REJECT / COMPLETE)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];
    
    if ($action === 'approve') {
        // Hanya direktur yang bisa approve
        if (!$isDirektur && !$hasFullAccess) {
            setFlash('Anda tidak memiliki akses untuk approve!', 'danger');
            redirect('transactionrequest.php');
        }
        
        $stmt = $db->prepare("UPDATE transaction_requests SET 
                              status = 'approved', 
                              approved_by = ?, 
                              approved_at = NOW() 
                              WHERE id = ? AND status = 'pending'");
        $stmt->execute([$userId, $id]);
        
        if ($stmt->rowCount() > 0) {
            // Update juga status di sales_activities
            $stmt2 = $db->prepare("UPDATE sales_activities SET status = 'approved' WHERE trf_number = (SELECT trf_number FROM transaction_requests WHERE id = ?)");
            $stmt2->execute([$id]);
            
            setFlash('Transaction Request berhasil di-approve!', 'success');
        } else {
            setFlash('Gagal approve atau status sudah berubah!', 'warning');
        }
        redirect('transactionrequest.php');
    }
    
    if ($action === 'reject') {
        // Hanya direktur yang bisa reject
        if (!$isDirektur && !$hasFullAccess) {
            setFlash('Anda tidak memiliki akses untuk reject!', 'danger');
            redirect('transactionrequest.php');
        }
        
        $reason = bersihkan($_POST['reason'] ?? '');
        
        $stmt = $db->prepare("UPDATE transaction_requests SET 
                              status = 'rejected', 
                              rejected_by = ?, 
                              rejected_at = NOW(),
                              rejected_reason = ? 
                              WHERE id = ? AND status = 'pending'");
        $stmt->execute([$userId, $reason, $id]);
        
        if ($stmt->rowCount() > 0) {
            // Update juga status di sales_activities
            $stmt2 = $db->prepare("UPDATE sales_activities SET status = 'rejected' WHERE trf_number = (SELECT trf_number FROM transaction_requests WHERE id = ?)");
            $stmt2->execute([$id]);
            
            setFlash('Transaction Request berhasil di-reject!', 'success');
        } else {
            setFlash('Gagal reject atau status sudah berubah!', 'warning');
        }
        redirect('transactionrequest.php');
    }
    
    if ($action === 'complete') {
        // Sales yang membuat atau admin yang bisa complete
        $canComplete = false;
        if ($hasFullAccess) {
            $canComplete = true;
        } elseif ($userRole === 'sales') {
            $stmt = $db->prepare("SELECT sales_id FROM transaction_requests WHERE id = ?");
            $stmt->execute([$id]);
            $ownerId = $stmt->fetchColumn();
            if ($ownerId == $userId) {
                $canComplete = true;
            }
        }
        
        if (!$canComplete) {
            setFlash('Anda tidak memiliki akses untuk complete!', 'danger');
            redirect('transactionrequest.php');
        }
        
        $stmt = $db->prepare("UPDATE transaction_requests SET 
                              status = 'completed' 
                              WHERE id = ? AND status = 'approved'");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            // Update juga status di sales_activities
            $stmt2 = $db->prepare("UPDATE sales_activities SET status = 'completed' WHERE trf_number = (SELECT trf_number FROM transaction_requests WHERE id = ?)");
            $stmt2->execute([$id]);
            
            setFlash('Transaction Request berhasil di-complete!', 'success');
        } else {
            setFlash('Gagal complete atau status harus Approved!', 'warning');
        }
        redirect('transactionrequest.php');
    }
}

// ============================================
// AMBIL DATA UNTUK DETAIL
// ============================================
$detailData = null;
if (isset($_GET['detail'])) {
    $id = (int)$_GET['detail'];
    $stmt = $db->prepare("SELECT tr.*, a.nama_pt, a.badan_usaha, u.full_name as sales_name,
                          (SELECT full_name FROM users WHERE id = tr.approved_by) as approved_by_name,
                          (SELECT full_name FROM users WHERE id = tr.rejected_by) as rejected_by_name
                          FROM transaction_requests tr 
                          LEFT JOIN accounts a ON tr.account_id = a.id 
                          LEFT JOIN users u ON tr.sales_id = u.id 
                          WHERE tr.id = ?");
    $stmt->execute([$id]);
    $detailData = $stmt->fetch();
}
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
        .top-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-header .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .top-header .header-left .logo-wrapper {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .top-header .header-left .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .top-header .header-left .brand-text .brand-name {
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        
        .top-header .header-left .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .top-header .header-left .brand-text .brand-sub {
            font-size: 8px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        
        .top-header .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .top-header .header-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 16px;
            cursor: pointer;
        }
        
        .top-header .header-right .notif-icon .badge-notif {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .top-header .header-right .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 13px;
            text-decoration: none;
            border: 2px solid rgba(255, 215, 0, 0.2);
            transition: border-color 0.3s ease;
        }
        
        .top-header .header-right .user-avatar:hover {
            border-color: #ffd700;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 12px;
            padding: 16px 24px;
            color: #fff;
            margin-bottom: 16px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner .welcome-text .greeting {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            font-weight: 400;
        }
        
        .welcome-banner .welcome-text h3 {
            font-weight: 700;
            font-size: 18px;
            margin: 2px 0 0;
        }
        
        .welcome-banner .welcome-text h3 span {
            color: #ffd700;
        }
        
        .welcome-banner .welcome-icon {
            font-size: 32px;
            color: rgba(255, 215, 0, 0.05);
            position: absolute;
            right: 15px;
            bottom: 10px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .stat-card .stat-label {
            font-size: 12px;
            color: #888;
            font-weight: 500;
        }
        
        .stat-card .stat-icon {
            font-size: 28px;
            opacity: 0.15;
        }
        
        .card-custom {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .card-custom .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-custom .card-header-custom h6 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 14px;
        }
        
        .card-custom .card-header-custom h6 i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .card-custom .card-body-custom {
            padding: 0;
            overflow-x: auto;
        }
        
        .table-custom {
            margin-bottom: 0;
            font-size: 13px;
        }
        
        .table-custom th {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #999;
            border-bottom: 1px solid #f0f2f5;
            padding: 10px 15px;
            background: #fafafa;
            white-space: nowrap;
        }
        
        .table-custom td {
            padding: 10px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .table-custom tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tr:hover {
            background: #f8f9fa;
        }
        
        .badge-status-tr {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-status-tr.pending { background: rgba(241, 196, 15, 0.15); color: #d4a017; }
        .badge-status-tr.approved { background: rgba(52, 152, 219, 0.15); color: #2980b9; }
        .badge-status-tr.rejected { background: rgba(231, 76, 60, 0.15); color: #c0392b; }
        .badge-status-tr.completed { background: rgba(46, 204, 113, 0.15); color: #27ae60; }
        
        .badge-trf {
            background: rgba(52, 152, 219, 0.12);
            color: #2980b9;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .btn-action {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.3s ease;
            font-size: 13px;
            cursor: pointer;
        }
        
        .btn-action:hover {
            transform: scale(1.1);
        }
        
        .btn-action.detail {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        .btn-action.detail:hover { background: rgba(46, 204, 113, 0.2); }
        
        .btn-action.approve {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
        }
        .btn-action.approve:hover { background: rgba(52, 152, 219, 0.2); }
        
        .btn-action.reject {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }
        .btn-action.reject:hover { background: rgba(231, 76, 60, 0.2); }
        
        .btn-action.complete-tr {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        .btn-action.complete-tr:hover { background: rgba(46, 204, 113, 0.2); }
        
        .modal-content {
            border: none;
            border-radius: 12px;
        }
        
        .modal-header {
            border-bottom: 1px solid #f0f2f5;
            padding: 18px 24px;
        }
        
        .modal-header .modal-title {
            font-weight: 700;
            font-size: 18px;
            color: #1a1a2e;
        }
        
        .modal-header .modal-title i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .modal-body {
            padding: 20px 24px;
        }
        
        .modal-footer {
            border-top: 1px solid #f0f2f5;
            padding: 14px 24px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 2px solid #e8edf2;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(26, 26, 46, 0.3);
            color: #fff;
        }
        
        .btn-primary-custom i {
            margin-right: 6px;
        }
        
        .btn-secondary-custom {
            background: #f0f2f5;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #555;
        }
        
        .btn-secondary-custom:hover {
            background: #e8edf2;
            color: #333;
        }
        
        .btn-approve-custom {
            background: #2980b9;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-approve-custom:hover {
            background: #1a6d9e;
            color: #fff;
        }
        
        .btn-reject-custom {
            background: #e74c3c;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-reject-custom:hover {
            background: #c0392b;
            color: #fff;
        }
        
        .btn-success-custom {
            background: #27ae60;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-success-custom:hover {
            background: #219a52;
            color: #fff;
        }
        
        .detail-item {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-item .detail-label {
            font-weight: 600;
            color: #555;
            width: 160px;
            flex-shrink: 0;
            font-size: 13px;
        }
        
        .detail-item .detail-value {
            color: #1a1a2e;
            font-size: 13px;
            word-break: break-word;
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 0 env(safe-area-inset-bottom);
            z-index: 999;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.06);
        }
        
        .bottom-nav .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            padding: 3px 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            min-width: 45px;
        }
        
        .bottom-nav .nav-item .nav-icon {
            font-size: 17px;
            color: #999;
            transition: all 0.3s ease;
        }
        
        .bottom-nav .nav-item .nav-label {
            font-size: 8px;
            color: #999;
            font-weight: 500;
            margin-top: 2px;
            transition: all 0.3s ease;
        }
        
        .bottom-nav .nav-item.active .nav-icon {
            color: #ffd700;
        }
        
        .bottom-nav .nav-item.active .nav-label {
            color: #1a1a2e;
            font-weight: 600;
        }
        
        .bottom-nav .nav-item.active::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 18px;
            height: 2px;
            background: #ffd700;
            border-radius: 0 0 2px 2px;
        }
        
        .bottom-nav .nav-item .badge-nav {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #d63031;
            color: #fff;
            font-size: 7px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 15px;
            text-align: center;
        }
        
        .desktop-nav-wrapper {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .desktop-nav-wrapper .brand-section {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
        }
        
        .desktop-nav-wrapper .brand-section .logo-wrapper {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .desktop-nav-wrapper .brand-section .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-sub {
            font-size: 8px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .desktop-nav-wrapper .desktop-menu {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link.active {
            color: #ffd700;
            background: rgba(255, 215, 0, 0.08);
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link i {
            font-size: 14px;
        }
        
        .desktop-nav-wrapper .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 17px;
            cursor: pointer;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon .badge-notif {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .desktop-nav-wrapper .nav-right .user-info {
            text-align: right;
            color: #fff;
        }
        
        .desktop-nav-wrapper .nav-right .user-info .name {
            font-weight: 600;
            font-size: 13px;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .nav-right .user-info .role {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            border: 2px solid rgba(255, 215, 0, 0.2);
            transition: border-color 0.3s ease;
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar:hover {
            border-color: #ffd700;
        }
        
        .desktop-nav-wrapper .nav-right .logout-btn {
            color: rgba(255, 255, 255, 0.5);
            padding: 5px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .desktop-nav-wrapper .nav-right .logout-btn:hover {
            color: #ff6b6b;
            background: rgba(214, 48, 49, 0.1);
            border-color: rgba(214, 48, 49, 0.3);
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .filter-buttons .btn-filter {
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            border: 2px solid #e8edf2;
            background: transparent;
            color: #666;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .filter-buttons .btn-filter:hover {
            border-color: #ffd700;
            color: #1a1a2e;
        }
        
        .filter-buttons .btn-filter.active {
            background: #1a1a2e;
            border-color: #1a1a2e;
            color: #fff;
        }
        
        .filter-buttons .btn-filter .count {
            background: rgba(0,0,0,0.1);
            padding: 0 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 4px;
        }
        
        .filter-buttons .btn-filter.active .count {
            background: rgba(255,255,255,0.2);
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
        }
        
        @media (min-width: 769px) {
            .bottom-nav { display: none !important; }
            body { padding-bottom: 0; }
            .top-header { display: none !important; }
        }
        
        @media (max-width: 768px) {
            .desktop-nav-wrapper { display: none !important; }
            body { padding-bottom: 65px; }
            .stat-card .stat-number { font-size: 20px; }
            .welcome-banner { padding: 14px 18px; }
            .welcome-banner .welcome-text h3 { font-size: 16px; }
            .welcome-banner .welcome-icon { display: none; }
            .table-custom { font-size: 12px; }
            .table-custom th, .table-custom td { padding: 8px 10px; }
            .card-custom .card-header-custom { padding: 12px 16px; }
            .detail-item .detail-label { width: 100px; font-size: 12px; }
            .detail-item .detail-value { font-size: 12px; }
            .filter-buttons { flex-wrap: wrap; }
        }
        
        @media (max-width: 480px) {
            .stat-card .stat-number { font-size: 17px; }
            .stat-card { padding: 12px 14px; }
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
            .detail-item { flex-direction: column; padding: 8px 0; }
            .detail-item .detail-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .detail-item .detail-value { font-size: 12px; }
        }
        
        .footer-text {
            text-align: center;
            padding: 16px 0 8px;
            color: #999;
            font-size: 11px;
        }
        
        .footer-text a {
            color: #16213e;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            color: #ffd700;
        }
    </style>
</head>
<body>

    <!-- DESKTOP NAVBAR -->
    <div class="desktop-nav-wrapper">
        <div class="brand-section">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Customer Relationship Management System</div>
            </div>
        </div>
        
        <div class="desktop-menu">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            
            <?php if (canAccessMenu('account_management')): ?>
                <a href="account_management.php" class="nav-link">
                    <i class="fas fa-building"></i> Account
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('sales_activity')): ?>
                <a href="salesactivity.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i> Sales Activity
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('transaction_request')): ?>
                <a href="transactionrequest.php" class="nav-link active">
                    <i class="fas fa-file-signature"></i> TR Request
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('produk')): ?>
                <a href="produk.php" class="nav-link">
                    <i class="fas fa-box"></i> Produk
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('delivery_order')): ?>
                <a href="#" class="nav-link">
                    <i class="fas fa-tractor"></i> Delivery
                </a>
            <?php endif; ?>
        </div>
        
        <div class="nav-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif"><?= $totalPending ?></span>
            </div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- MOBILE HEADER -->
    <header class="top-header">
        <div class="header-left">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Customer Relationship Management</div>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif"><?= $totalPending ?></span>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main style="padding: 16px 20px 0; max-width: 1400px; margin: 0 auto;">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Transaction Request</div>
                <h3>Kelola Transaction Request Form</h3>
            </div>
            <i class="fas fa-file-signature welcome-icon"></i>
        </div>

        <!-- STATISTIK -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalRequests) ?></div>
                        <div class="stat-label">Total Request</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-file-signature"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalPending) ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-clock" style="color:#f39c12;"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalApproved) ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle" style="color:#2980b9;"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalRejected) ?></div>
                        <div class="stat-label">Rejected</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-times-circle" style="color:#e74c3c;"></i></div>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i>Daftar Transaction Request</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                        <button type="submit" class="btn btn-sm btn-primary-custom"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="transactionrequest.php" class="btn btn-sm btn-secondary-custom"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                </div>
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
                    <a href="?status=completed&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'completed' ? 'active' : '' ?>">
                        <i class="fas fa-check-double fa-fw" style="color:#27ae60;"></i> Completed <span class="count"><?= $totalCompleted ?></span>
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
                                <th>TRF Number</th>
                                <th>Subject</th>
                                <th>Account</th>
                                <th>Request Date</th>
                                <th>Due Date</th>
                                <th>Sales</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($requests) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($requests as $request): ?>
                                    <?php 
                                    $statusLabel = ucfirst($request['status']);
                                    $statusClass = $request['status'];
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <span class="badge-trf">
                                                <i class="fas fa-file-signature"></i> <?= htmlspecialchars($request['trf_number']) ?>
                                            </span>
                                        </td>
                                        <td><strong><?= htmlspecialchars($request['subject']) ?></strong></td>
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
                                                <?php elseif ($request['status'] == 'completed'): ?>
                                                    <i class="fas fa-check-double"></i>
                                                <?php endif; ?>
                                                <?= $statusLabel ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailRequest(<?= htmlspecialchars(json_encode($request)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($request['status'] == 'pending'): ?>
                                                    <?php if ($isDirektur || $hasFullAccess): ?>
                                                        <button class="btn-action approve" onclick="approveRequest(<?= $request['id'] ?>, '<?= htmlspecialchars($request['trf_number']) ?>')">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button class="btn-action reject" onclick="rejectRequest(<?= $request['id'] ?>, '<?= htmlspecialchars($request['trf_number']) ?>')">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if ($request['status'] == 'approved'): ?>
                                                    <?php 
                                                    $canComplete = false;
                                                    if ($hasFullAccess) {
                                                        $canComplete = true;
                                                    } elseif ($userRole === 'sales' && $request['sales_id'] == $userId) {
                                                        $canComplete = true;
                                                    }
                                                    ?>
                                                    <?php if ($canComplete): ?>
                                                        <button class="btn-action complete-tr" onclick="completeRequest(<?= $request['id'] ?>, '<?= htmlspecialchars($request['trf_number']) ?>')">
                                                            <i class="fas fa-check-double"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
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

    </main>

    <!-- MODALS -->
    <!-- Modal Detail -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-signature" style="color:#ffd700;"></i> Detail Transaction Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody">
                    <!-- Detail akan diisi oleh JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Approve -->
    <div class="modal fade" id="modalApprove" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #2980b9, #1a6d9e);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-check-circle"></i> Approve Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menyetujui request ini?</p>
                    <p class="text-muted small">TRF Number: <strong id="approveTrfNumber"></strong></p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="id" id="approveId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-approve-custom"><i class="fas fa-check"></i> Approve</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Reject -->
    <div class="modal fade" id="modalReject" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #e74c3c, #c0392b);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-times-circle"></i> Reject Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="id" id="rejectId" value="">
                        
                        <p>Apakah Anda yakin ingin menolak request ini?</p>
                        <p class="text-muted small">TRF Number: <strong id="rejectTrfNumber"></strong></p>
                        
                        <div class="mb-3">
                            <label class="form-label">Alasan Penolakan</label>
                            <textarea name="reason" class="form-control" rows="3" placeholder="Masukkan alasan penolakan..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-reject-custom"><i class="fas fa-times"></i> Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Complete -->
    <div class="modal fade" id="modalComplete" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #27ae60, #219a52);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-check-double"></i> Complete Request
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menyelesaikan request ini?</p>
                    <p class="text-muted small">TRF Number: <strong id="completeTrfNumber"></strong></p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" id="completeId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success-custom"><i class="fas fa-check-double"></i> Complete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Navigation -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-th-large nav-icon"></i>
            <span class="nav-label">Home</span>
        </a>
        
        <?php if (canAccessMenu('account_management')): ?>
            <a href="account_management.php" class="nav-item">
                <i class="fas fa-building nav-icon"></i>
                <span class="nav-label">Account</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('sales_activity')): ?>
            <a href="salesactivity.php" class="nav-item">
                <i class="fas fa-chart-bar nav-icon"></i>
                <span class="nav-label">Sales Activity</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('transaction_request')): ?>
            <a href="transactionrequest.php" class="nav-item active">
                <i class="fas fa-file-signature nav-icon"></i>
                <span class="nav-label">TR Request</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-item">
                <i class="fas fa-box nav-icon"></i>
                <span class="nav-label">Produk</span>
            </a>
        <?php endif; ?>
        
        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i>
            <span class="nav-label" style="color:#d63031;">Logout</span>
        </a>
    </nav>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function detailRequest(data) {
            var statusLabel = data.status.charAt(0).toUpperCase() + data.status.slice(1);
            var statusBadge = data.status;
            var statusIcon = data.status == 'pending' ? 'fa-clock' : 
                           (data.status == 'approved' ? 'fa-check-circle' : 
                           (data.status == 'rejected' ? 'fa-times-circle' : 'fa-check-double'));
            
            var html = `
                <div class="detail-item">
                    <div class="detail-label">TRF Number</div>
                    <div class="detail-value">
                        <span class="badge-trf"><i class="fas fa-file-signature"></i> ${data.trf_number}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="badge-status-tr ${statusBadge}">
                            <i class="fas ${statusIcon}"></i> ${statusLabel}
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value"><strong>${data.subject}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Account</div>
                    <div class="detail-value">${data.nama_pt || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Badan Usaha</div>
                    <div class="detail-value">${data.badan_usaha || 'PT'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Jenis Tugas</div>
                    <div class="detail-value">${data.jenis_tugas || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Request Date</div>
                    <div class="detail-value">${new Date(data.request_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Due Date</div>
                    <div class="detail-value">${new Date(data.due_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Description</div>
                    <div class="detail-value">${data.description || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Result</div>
                    <div class="detail-value">${data.result || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Customer Deal</div>
                    <div class="detail-value">
                        <span class="badge-deal-status ${data.customer_deal}">${data.customer_deal}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Leads Number</div>
                    <div class="detail-value">${data.leads_number ? '<code>' + data.leads_number + '</code>' : '-'}</div>
                </div>
                ${data.attachment_file ? `
                <div class="detail-item">
                    <div class="detail-label">Attachment</div>
                    <div class="detail-value">
                        <a href="${data.attachment_file}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-file"></i> Lihat File
                        </a>
                    </div>
                </div>
                ` : ''}
                <div class="detail-item">
                    <div class="detail-label">Sales</div>
                    <div class="detail-value">${data.sales_name || '-'}</div>
                </div>
                ${data.approved_by_name ? `
                <div class="detail-item">
                    <div class="detail-label">Approved By</div>
                    <div class="detail-value">${data.approved_by_name} pada ${data.approved_at ? new Date(data.approved_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-'}</div>
                </div>
                ` : ''}
                ${data.rejected_by_name ? `
                <div class="detail-item">
                    <div class="detail-label">Rejected By</div>
                    <div class="detail-value">${data.rejected_by_name} pada ${data.rejected_at ? new Date(data.rejected_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Alasan Reject</div>
                    <div class="detail-value">${data.rejected_reason || '-'}</div>
                </div>
                ` : ''}
                <div class="detail-item">
                    <div class="detail-label">Dibuat Pada</div>
                    <div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }
        
        function approveRequest(id, trfNumber) {
            document.getElementById('approveId').value = id;
            document.getElementById('approveTrfNumber').textContent = trfNumber;
            var modal = new bootstrap.Modal(document.getElementById('modalApprove'));
            modal.show();
        }
        
        function rejectRequest(id, trfNumber) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectTrfNumber').textContent = trfNumber;
            var modal = new bootstrap.Modal(document.getElementById('modalReject'));
            modal.show();
        }
        
        function completeRequest(id, trfNumber) {
            document.getElementById('completeId').value = id;
            document.getElementById('completeTrfNumber').textContent = trfNumber;
            var modal = new bootstrap.Modal(document.getElementById('modalComplete'));
            modal.show();
        }
    </script>
</body>
</html>