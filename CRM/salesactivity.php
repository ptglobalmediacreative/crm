<?php
require_once 'config.php';

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// CEK AKSES HALAMAN
// ============================================
requirePermission('sales_activity', 'view');

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

// Role yang bisa full access (edit, delete)
$fullAccessRoles = ['it_support', 'admin', 'finance', 'business', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$hasFullAccess = in_array($userRole, $fullAccessRoles);

// ============================================
// GENERATE LEADS NUMBER
// ============================================
function generateLeadsNumber($db, $date) {
    $month = date('m', strtotime($date));
    $year = date('Y', strtotime($date));
    
    $stmt = $db->prepare("SELECT leads_number FROM sales_activities 
                          WHERE leads_number LIKE ? 
                          ORDER BY leads_number DESC LIMIT 1");
    $pattern = "LEAD/GET/" . $month . "/" . $year . "/%";
    $stmt->execute([$pattern]);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $lastNumber = (int)substr($last, -4);
        $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '0001';
    }
    
    return "LEAD/GET/" . $month . "/" . $year . "/" . $newNumber;
}

// ============================================
// TAMBAHKAN KOLOM status KE TABEL (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS status VARCHAR(20) NULL DEFAULT 'in_progress'");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL");
    $db->exec("ALTER TABLE sales_activities ADD INDEX idx_status (status)");
} catch(PDOException $e) {
    // Kolom sudah ada atau error lainnya
}

// ============================================
// AMBIL DATA ACCOUNT UNTUK DROPDOWN (sesuai sales)
// ============================================
if ($userRole === 'sales') {
    $stmt = $db->prepare("SELECT id, nama_pt, badan_usaha FROM accounts WHERE sales_id = ? ORDER BY nama_pt");
    $stmt->execute([$userId]);
} else {
    $stmt = $db->prepare("SELECT id, nama_pt, badan_usaha FROM accounts ORDER BY nama_pt");
    $stmt->execute();
}
$accounts = $stmt->fetchAll();

// ============================================
// PROSES TAMBAH / EDIT / COMPLETE / DELETE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        if (!canAdd('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menambah sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $subject = bersihkan($_POST['subject']);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : NULL;
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $deskripsi = bersihkan($_POST['deskripsi']);
        $start_date = $_POST['start_date'];
        
        // Ambil data account untuk auto-fill
        $contact_name = '';
        $contact_mobile = '';
        $business_segment = '';
        $badan_usaha = '';
        if ($account_id) {
            $stmt = $db->prepare("SELECT nama_pic, no_hp_pic, bidang_usaha, badan_usaha FROM accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $account = $stmt->fetch();
            if ($account) {
                $contact_name = $account['nama_pic'];
                $contact_mobile = $account['no_hp_pic'];
                $business_segment = $account['bidang_usaha'];
                $badan_usaha = $account['badan_usaha'];
            }
        }
        
        // Validasi
        $errors = [];
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($start_date)) $errors[] = 'Start Date wajib diisi!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO sales_activities 
                                  (subject, account_id, contact_name, contact_mobile, business_segment, 
                                   badan_usaha, jenis_tugas, deskripsi, start_date, status, sales_id) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'in_progress', ?)");
            $stmt->execute([
                $subject, $account_id, $contact_name, $contact_mobile, $business_segment,
                $badan_usaha, $jenis_tugas, $deskripsi, $start_date, $userId
            ]);
            
            setFlash('Sales Activity berhasil ditambahkan!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'complete') {
        if (!canEdit('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menyelesaikan sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        $result = bersihkan($_POST['result']);
        $customer_prospek = bersihkan($_POST['customer_prospek']);
        
        // Generate leads number jika customer prospek = Yes
        $leads_number = NULL;
        if ($customer_prospek === 'Yes') {
            $stmt = $db->prepare("SELECT start_date FROM sales_activities WHERE id = ?");
            $stmt->execute([$id]);
            $start_date = $stmt->fetchColumn();
            if ($start_date) {
                $leads_number = generateLeadsNumber($db, $start_date);
            }
        }
        
        // Upload file
        $attachment_file = '';
        if (!empty($_FILES['attachment_file']['name'])) {
            $target_dir = "uploads/sales_activity/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['attachment_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $attachment_file = $target_dir . time() . '_' . uniqid() . '.' . $file_extension;
                move_uploaded_file($_FILES['attachment_file']['tmp_name'], $attachment_file);
            } else {
                setFlash('Format file tidak didukung! (JPG, PNG, GIF, WEBP, PDF)', 'danger');
                redirect('salesactivity.php');
            }
        }
        
        // Keep existing file jika tidak upload baru
        if (empty($attachment_file)) {
            $stmt = $db->prepare("SELECT attachment_file FROM sales_activities WHERE id = ?");
            $stmt->execute([$id]);
            $attachment_file = $stmt->fetchColumn();
        }
        
        $errors = [];
        if (empty($result)) $errors[] = 'Result wajib diisi!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE sales_activities SET 
                                  result = ?, customer_prospek = ?, leads_number = ?, 
                                  attachment_file = ?, status = 'completed', completed_at = NOW() 
                                  WHERE id = ?");
            $stmt->execute([$result, $customer_prospek, $leads_number, $attachment_file, $id]);
            
            setFlash('Sales Activity berhasil diselesaikan!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        if (!canEdit('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk mengedit sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        $subject = bersihkan($_POST['subject']);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : NULL;
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $deskripsi = bersihkan($_POST['deskripsi']);
        $start_date = $_POST['start_date'];
        
        // Ambil data account untuk auto-fill
        $contact_name = '';
        $contact_mobile = '';
        $business_segment = '';
        $badan_usaha = '';
        if ($account_id) {
            $stmt = $db->prepare("SELECT nama_pic, no_hp_pic, bidang_usaha, badan_usaha FROM accounts WHERE id = ?");
            $stmt->execute([$account_id]);
            $account = $stmt->fetch();
            if ($account) {
                $contact_name = $account['nama_pic'];
                $contact_mobile = $account['no_hp_pic'];
                $business_segment = $account['bidang_usaha'];
                $badan_usaha = $account['badan_usaha'];
            }
        }
        
        $errors = [];
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($start_date)) $errors[] = 'Start Date wajib diisi!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE sales_activities SET 
                                  subject = ?, account_id = ?, contact_name = ?, contact_mobile = ?, 
                                  business_segment = ?, badan_usaha = ?, jenis_tugas = ?, deskripsi = ?, start_date = ? 
                                  WHERE id = ? AND status = 'in_progress'");
            $stmt->execute([
                $subject, $account_id, $contact_name, $contact_mobile, $business_segment,
                $badan_usaha, $jenis_tugas, $deskripsi, $start_date, $id
            ]);
            
            setFlash('Sales Activity berhasil diupdate!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menghapus sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        
        $stmt = $db->prepare("SELECT attachment_file FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetchColumn();
        if ($file && file_exists($file)) {
            unlink($file);
        }
        
        $stmt = $db->prepare("DELETE FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Sales Activity berhasil dihapus!', 'success');
        redirect('salesactivity.php');
    }
}

// ============================================
// AMBIL DATA SALES ACTIVITY
// ============================================
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

$where = "WHERE 1=1";
$params = [];

if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}

if ($status_filter !== 'all') {
    $where .= " AND sa.status = ?";
    $params[] = $status_filter;
}

if (!empty($search)) {
    $where .= " AND (sa.subject LIKE ? OR sa.contact_name LIKE ? OR sa.contact_mobile LIKE ? OR a.nama_pt LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

// Count total
$countSql = "SELECT COUNT(*) FROM sales_activities sa LEFT JOIN accounts a ON sa.account_id = a.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT sa.*, a.nama_pt, a.badan_usaha as account_badan_usaha, u.full_name as sales_name 
        FROM sales_activities sa 
        LEFT JOIN accounts a ON sa.account_id = a.id 
        LEFT JOIN users u ON sa.sales_id = u.id 
        $where 
        ORDER BY sa.start_date DESC, sa.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Statistik
$totalInProgress = $db->query("SELECT COUNT(*) FROM sales_activities WHERE status = 'in_progress'")->fetchColumn();
$totalCompleted = $db->query("SELECT COUNT(*) FROM sales_activities WHERE status = 'completed'")->fetchColumn();
$totalActivities = $totalInProgress + $totalCompleted;

// Ambil data untuk edit
$editData = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM sales_activities WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
}

// ============================================
// API ENDPOINT untuk get account data (AJAX)
// ============================================
if (isset($_GET['get_account'])) {
    $account_id = (int)$_GET['get_account'];
    $stmt = $db->prepare("SELECT nama_pic, no_hp_pic, bidang_usaha, badan_usaha FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($data ?: []);
    exit;
}

// ============================================
// AMBIL DATA UNTUK MODAL COMPLETE
// ============================================
$completeData = null;
if (isset($_GET['complete'])) {
    $id = (int)$_GET['complete'];
    $stmt = $db->prepare("SELECT * FROM sales_activities WHERE id = ?");
    $stmt->execute([$id]);
    $completeData = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sales Activity - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
        
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .section-title h5 {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 15px;
            margin: 0;
        }
        
        .section-title h5 i {
            color: #ffd700;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
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
        
        .badge-tugas {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-tugas.Perkenalan { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-tugas.Visit_Meeting { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-tugas.Prospecting { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .badge-tugas.Negosiasi { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-tugas.Kontrak { background: rgba(26, 188, 156, 0.12); color: #16a085; }
        .badge-tugas.Collect_Payment { background: rgba(231, 76, 60, 0.12); color: #c0392b; }
        .badge-tugas.Aftersales { background: rgba(22, 160, 133, 0.12); color: #1abc9c; }
        
        .badge-prospek {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-prospek.Yes { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-prospek.No { background: rgba(149, 165, 166, 0.12); color: #7f8c8d; }
        
        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-status.in_progress { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-status.completed { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        
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
        
        .btn-action.edit {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
        }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        
        .btn-action.delete {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        
        .btn-action.complete {
            background: rgba(241, 196, 15, 0.12);
            color: #d4a017;
        }
        .btn-action.complete:hover { background: rgba(241, 196, 15, 0.2); }
        
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
        
        .form-label .optional {
            font-weight: 400;
            color: #999;
            font-size: 11px;
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
        
        .form-control-file {
            padding: 8px 0;
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
        
        .btn-complete-custom {
            background: #f39c12;
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
        
        .btn-complete-custom:hover {
            background: #e67e22;
            color: #fff;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
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
        
        .bottom-nav .nav-item:hover .nav-icon {
            color: #1a1a2e;
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
            .section-title h5 { font-size: 14px; }
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
        
        .auto-fill-field {
            background: #f8f9fa !important;
            cursor: default;
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
    </style>
</head>
<body>

    <!-- ============================================
    DESKTOP NAVBAR
    ============================================ -->
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
                <a href="salesactivity.php" class="nav-link active">
                    <i class="fas fa-chart-bar"></i> Sales Activity
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
                <span class="badge-notif">3</span>
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

    <!-- ============================================
    MOBILE HEADER
    ============================================ -->
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
                <span class="badge-notif">3</span>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
        </div>
    </header>

    <!-- ============================================
    MAIN CONTENT
    ============================================ -->
    <main style="padding: 16px 20px 0; max-width: 1400px; margin: 0 auto;">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Sales Activity</div>
                <h3>Kelola Aktivitas Sales</h3>
            </div>
            <i class="fas fa-chart-bar welcome-icon"></i>
        </div>

        <!-- STATISTIK -->
        <div class="row g-3 mb-4">
            <div class="col-xl-4 col-lg-4 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalActivities) ?></div>
                        <div class="stat-label">Total Aktivitas</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-tasks"></i></div>
                </div>
            </div>
            <div class="col-xl-4 col-lg-4 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center" style="border-left: 3px solid #2980b9;">
                    <div>
                        <div class="stat-number" style="color: #2980b9;"><?= number_format($totalInProgress) ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-spinner"></i></div>
                </div>
            </div>
            <div class="col-xl-4 col-lg-4 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center" style="border-left: 3px solid #27ae60;">
                    <div>
                        <div class="stat-number" style="color: #27ae60;"><?= number_format($totalCompleted) ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i>Daftar Sales Activity</h6>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 180px;">
                        <button type="submit" class="btn btn-sm btn-primary-custom"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="salesactivity.php?status=<?= $status_filter ?>" class="btn btn-sm btn-secondary-custom"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                    <?php if (canAdd('sales_activity')): ?>
                        <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalSalesActivity">
                            <i class="fas fa-plus"></i> Tambah
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Filter Status -->
            <div class="px-3 pt-3 pb-2 border-bottom">
                <div class="filter-buttons">
                    <a href="?status=all&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'all' ? 'active' : '' ?>">
                        Semua <span class="count"><?= $totalActivities ?></span>
                    </a>
                    <a href="?status=in_progress&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'in_progress' ? 'active' : '' ?>">
                        <i class="fas fa-spinner fa-fw"></i> In Progress <span class="count"><?= $totalInProgress ?></span>
                    </a>
                    <a href="?status=completed&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'completed' ? 'active' : '' ?>">
                        <i class="fas fa-check-circle fa-fw"></i> Completed <span class="count"><?= $totalCompleted ?></span>
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
                                <th>Subject</th>
                                <th>Account</th>
                                <th>Badan Usaha</th>
                                <th>Contact</th>
                                <th>Jenis Tugas</th>
                                <th>Start Date</th>
                                <th>Status</th>
                                <th>Sales</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($activities) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($activities as $activity): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($activity['subject']) ?></strong></td>
                                        <td><?= htmlspecialchars($activity['nama_pt'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-badan-usaha">
                                                <?= htmlspecialchars($activity['account_badan_usaha'] ?? $activity['badan_usaha'] ?? 'PT') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($activity['contact_name'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-tugas <?= str_replace(' ', '_', str_replace('/', '_', $activity['jenis_tugas'])) ?>">
                                                <?= htmlspecialchars($activity['jenis_tugas']) ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($activity['start_date'])) ?></td>
                                        <td>
                                            <span class="badge-status <?= $activity['status'] ?>">
                                                <?= $activity['status'] == 'in_progress' ? 'In Progress' : 'Completed' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($activity['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailActivity(<?= htmlspecialchars(json_encode($activity)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($activity['status'] == 'in_progress'): ?>
                                                    <?php if (canEdit('sales_activity') && ($hasFullAccess || $activity['sales_id'] == $userId)): ?>
                                                        <button class="btn-action edit" onclick="editActivity(<?= htmlspecialchars(json_encode($activity)) ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (canEdit('sales_activity') && ($hasFullAccess || $activity['sales_id'] == $userId)): ?>
                                                        <button class="btn-action complete" onclick="completeActivity(<?= $activity['id'] ?>)">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (canDelete('sales_activity') && ($hasFullAccess || $activity['sales_id'] == $userId)): ?>
                                                    <button class="btn-action delete" onclick="deleteActivity(<?= $activity['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data sales activity
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

    <!-- ============================================
    MODAL TAMBAH / EDIT SALES ACTIVITY (IN PROGRESS)
    ============================================ -->
    <div class="modal fade" id="modalSalesActivity" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Sales Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formSalesActivity">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="subject" class="form-control" placeholder="Masukkan subject" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Account Management <span class="text-danger">*</span></label>
                            <select name="account_id" id="account_id" class="form-select" required>
                                <option value="">-- Pilih Account --</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= $account['id'] ?>">
                                        <?= htmlspecialchars($account['nama_pt']) ?> (<?= htmlspecialchars($account['badan_usaha'] ?? 'PT') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Badan Usaha</label>
                                <input type="text" name="badan_usaha_field" id="badan_usaha_field" class="form-control auto-fill-field" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Business Segment</label>
                                <input type="text" name="business_segment" id="business_segment" class="form-control auto-fill-field" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Contact Mobile Phone</label>
                                <input type="text" name="contact_mobile" id="contact_mobile" class="form-control auto-fill-field" readonly>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jenis Tugas <span class="text-danger">*</span></label>
                                <select name="jenis_tugas" id="jenis_tugas" class="form-select" required>
                                    <option value="">-- Pilih Jenis Tugas --</option>
                                    <option value="Perkenalan">Perkenalan</option>
                                    <option value="Visit/Meeting">Visit/Meeting</option>
                                    <option value="Prospecting">Prospecting</option>
                                    <option value="Negosiasi">Negosiasi</option>
                                    <option value="Kontrak">Kontrak</option>
                                    <option value="Collect Payment">Collect Payment</option>
                                    <option value="Aftersales">Aftersales</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="start_date" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" id="deskripsi" class="form-control" rows="3" placeholder="Masukkan deskripsi"></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Info:</strong> Setelah menyimpan, Anda dapat melanjutkan ke tahap <strong>Complete</strong> untuk mengisi Result, Customer Prospek, dan Attachment.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan (In Progress)</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================
    MODAL COMPLETE
    ============================================ -->
    <div class="modal fade" id="modalComplete" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-check-circle"></i> Complete Sales Activity
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formComplete">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" id="completeId" value="">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Subject</label>
                                <input type="text" id="completeSubject" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Account</label>
                                <input type="text" id="completeAccount" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jenis Tugas</label>
                                <input type="text" id="completeJenisTugas" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="text" id="completeStartDate" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea id="completeDeskripsi" class="form-control" rows="2" readonly style="background: #f8f9fa;"></textarea>
                        </div>
                        
                        <hr>
                        
                        <div class="mb-3">
                            <label class="form-label">Result <span class="text-danger">*</span></label>
                            <textarea name="result" id="result" class="form-control" rows="3" placeholder="Masukkan hasil dari aktivitas" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Prospek</label>
                                <select name="customer_prospek" id="customer_prospek" class="form-select">
                                    <option value="No">No</option>
                                    <option value="Yes">Yes</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Leads Number</label>
                                <input type="text" name="leads_number" id="leads_number" class="form-control" readonly>
                                <small class="text-muted">Akan digenerate otomatis jika Customer Prospek = Yes</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment File <span class="optional">(Optional)</span></label>
                            <input type="file" name="attachment_file" id="attachment_file" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                            <small class="text-muted">Format: JPG, PNG, GIF, WEBP, PDF (Max 5MB)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-complete-custom">
                            <i class="fas fa-check"></i> Complete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================
    MODAL DETAIL
    ============================================ -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar" style="color:#ffd700;"></i> Detail Sales Activity</h5>
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

    <!-- ============================================
    MODAL DELETE
    ============================================ -->
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus data ini?</p>
                    <p class="text-muted small">Data yang dihapus tidak dapat dikembalikan!</p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    BOTTOM NAVIGATION - MOBILE
    ============================================ -->
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
            <a href="salesactivity.php" class="nav-item active">
                <i class="fas fa-chart-bar nav-icon"></i>
                <span class="nav-label">Sales</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-item">
                <i class="fas fa-box nav-icon"></i>
                <span class="nav-label">Produk</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('delivery_order')): ?>
            <a href="#" class="nav-item">
                <i class="fas fa-tractor nav-icon"></i>
                <span class="nav-label">DO</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('data_user')): ?>
            <a href="data_user.php" class="nav-item">
                <i class="fas fa-users nav-icon"></i>
                <span class="nav-label">User</span>
            </a>
        <?php endif; ?>
        
        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i>
            <span class="nav-label" style="color:#d63031;">Logout</span>
        </a>
    </nav>

    <!-- ============================================
    SCRIPTS
    ============================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // AUTO FILL ACCOUNT DATA
        // ============================================
        document.getElementById('account_id').addEventListener('change', function() {
            var accountId = this.value;
            if (accountId) {
                fetch('salesactivity.php?get_account=' + accountId)
                    .then(response => response.json())
                    .then(data => {
                        document.getElementById('badan_usaha_field').value = data.badan_usaha || '';
                        document.getElementById('business_segment').value = data.bidang_usaha || '';
                        document.getElementById('contact_mobile').value = data.no_hp_pic || '';
                    })
                    .catch(error => console.error('Error:', error));
            } else {
                document.getElementById('badan_usaha_field').value = '';
                document.getElementById('business_segment').value = '';
                document.getElementById('contact_mobile').value = '';
            }
        });

        // ============================================
        // SET DEFAULT DATE TO TODAY
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var dateInput = document.getElementById('start_date');
            if (dateInput && !dateInput.value) {
                var today = new Date();
                var year = today.getFullYear();
                var month = String(today.getMonth() + 1).padStart(2, '0');
                var day = String(today.getDate()).padStart(2, '0');
                dateInput.value = year + '-' + month + '-' + day;
            }
        });

        // ============================================
        // COMPLETE ACTIVITY
        // ============================================
        function completeActivity(id) {
            // Fetch data for complete modal
            fetch('salesactivity.php?complete=' + id)
                .then(response => response.text())
                .then(html => {
                    // Parse data from the complete modal
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var dataElement = doc.querySelector('#completeData');
                    if (dataElement) {
                        var data = JSON.parse(dataElement.textContent);
                        document.getElementById('completeId').value = data.id;
                        document.getElementById('completeSubject').value = data.subject;
                        document.getElementById('completeAccount').value = data.nama_pt || '-';
                        document.getElementById('completeJenisTugas').value = data.jenis_tugas;
                        document.getElementById('completeStartDate').value = data.start_date ? new Date(data.start_date).toLocaleDateString('id-ID') : '-';
                        document.getElementById('completeDeskripsi').value = data.deskripsi || '-';
                    }
                })
                .catch(error => console.error('Error:', error));
            
            // Alternatively, use the data from the table row
            // For simplicity, we'll use the data from the table
            var modal = new bootstrap.Modal(document.getElementById('modalComplete'));
            modal.show();
        }

        // ============================================
        // SHOW LEADS NUMBER WHEN PROSPEK = YES (in complete modal)
        // ============================================
        document.getElementById('customer_prospek').addEventListener('change', function() {
            var leadsInput = document.getElementById('leads_number');
            if (this.value === 'Yes') {
                leadsInput.value = 'Akan digenerate otomatis';
                leadsInput.style.color = '#27ae60';
                leadsInput.style.fontWeight = '600';
            } else {
                leadsInput.value = '';
                leadsInput.style.color = '';
                leadsInput.style.fontWeight = '';
            }
        });

        // ============================================
        // DETAIL ACTIVITY
        // ============================================
        function detailActivity(data) {
            var statusLabel = data.status == 'in_progress' ? 'In Progress' : 'Completed';
            var statusBadge = data.status == 'in_progress' ? 'in_progress' : 'completed';
            
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="badge-status ${statusBadge}">${statusLabel}</span>
                        ${data.status == 'completed' ? `<small class="text-muted ms-2">Selesai pada: ${new Date(data.completed_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>` : ''}
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
                    <div class="detail-value">${data.account_badan_usaha || data.badan_usaha || 'PT'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Business Segment</div>
                    <div class="detail-value">${data.business_segment || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Contact Mobile</div>
                    <div class="detail-value">${data.contact_mobile || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Jenis Tugas</div>
                    <div class="detail-value">
                        <span class="badge-tugas ${data.jenis_tugas.replace(/ /g, '_').replace(/\//g, '_')}">${data.jenis_tugas}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Deskripsi</div>
                    <div class="detail-value">${data.deskripsi || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Start Date</div>
                    <div class="detail-value">${new Date(data.start_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' })}</div>
                </div>
                ${data.status == 'completed' ? `
                <div class="detail-item">
                    <div class="detail-label">Result</div>
                    <div class="detail-value">${data.result || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Customer Prospek</div>
                    <div class="detail-value">
                        <span class="badge-prospek ${data.customer_prospek}">${data.customer_prospek}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Leads Number</div>
                    <div class="detail-value">${data.leads_number ? `<code>${data.leads_number}</code>` : '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Attachment</div>
                    <div class="detail-value">
                        ${data.attachment_file ? `<a href="${data.attachment_file}" target="_blank"><i class="fas fa-file-image"></i> Lihat File</a>` : '-'}
                    </div>
                </div>
                ` : ''}
                <div class="detail-item">
                    <div class="detail-label">Sales</div>
                    <div class="detail-value">${data.sales_name || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Dibuat Pada</div>
                    <div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }

        // ============================================
        // EDIT ACTIVITY (only for in_progress)
        // ============================================
        function editActivity(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Sales Activity';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('subject').value = data.subject;
            document.getElementById('account_id').value = data.account_id || '';
            document.getElementById('badan_usaha_field').value = data.account_badan_usaha || data.badan_usaha || '';
            document.getElementById('business_segment').value = data.business_segment || '';
            document.getElementById('contact_mobile').value = data.contact_mobile || '';
            document.getElementById('jenis_tugas').value = data.jenis_tugas;
            document.getElementById('deskripsi').value = data.deskripsi || '';
            document.getElementById('start_date').value = data.start_date;
            
            var modal = new bootstrap.Modal(document.getElementById('modalSalesActivity'));
            modal.show();
        }

        // ============================================
        // RESET FORM
        // ============================================
        document.getElementById('modalSalesActivity').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formSalesActivity').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Sales Activity';
            document.getElementById('badan_usaha_field').value = '';
            document.getElementById('business_segment').value = '';
            document.getElementById('contact_mobile').value = '';
            
            var today = new Date();
            var year = today.getFullYear();
            var month = String(today.getMonth() + 1).padStart(2, '0');
            var day = String(today.getDate()).padStart(2, '0');
            document.getElementById('start_date').value = year + '-' + month + '-' + day;
        });

        // ============================================
        // DELETE ACTIVITY
        // ============================================
        function deleteActivity(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }

        // ============================================
        // COMPLETE MODAL - LOAD DATA
        // ============================================
        // Override completeActivity with better implementation
        function completeActivity(id) {
            // Find the data from the table row
            var rows = document.querySelectorAll('table tbody tr');
            var data = null;
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];
                var deleteBtn = row.querySelector('.btn-action.delete');
                if (deleteBtn) {
                    var onclickAttr = deleteBtn.getAttribute('onclick');
                    if (onclickAttr && onclickAttr.includes(id)) {
                        // Found the row, get the data from the detail button
                        var detailBtn = row.querySelector('.btn-action.detail');
                        if (detailBtn) {
                            var detailOnclick = detailBtn.getAttribute('onclick');
                            if (detailOnclick) {
                                var match = detailOnclick.match(/detailActivity\((.*)\)/);
                                if (match) {
                                    data = JSON.parse(match[1]);
                                }
                            }
                        }
                        break;
                    }
                }
            }
            
            if (data) {
                document.getElementById('completeId').value = data.id;
                document.getElementById('completeSubject').value = data.subject;
                document.getElementById('completeAccount').value = data.nama_pt || '-';
                document.getElementById('completeJenisTugas').value = data.jenis_tugas;
                document.getElementById('completeStartDate').value = data.start_date ? new Date(data.start_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
                document.getElementById('completeDeskripsi').value = data.deskripsi || '-';
                document.getElementById('customer_prospek').value = 'No';
                document.getElementById('leads_number').value = '';
                document.getElementById('result').value = '';
                document.getElementById('attachment_file').value = '';
                
                var modal = new bootstrap.Modal(document.getElementById('modalComplete'));
                modal.show();
            } else {
                alert('Data tidak ditemukan!');
            }
        }
    </script>
</body>
</html>