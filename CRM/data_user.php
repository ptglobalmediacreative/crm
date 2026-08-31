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
requirePermission('data_user', 'view');

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
        'sales' => 'Sales',
        'service_support' => 'Service Support',
        'part_support' => 'Part Support'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';

// Build query
$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// Get total data
$countSql = "SELECT COUNT(*) FROM users $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT id, username, email, full_name, phone, role, is_active, created_at FROM users $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// Proses tambah user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        // Cek permission tambah
        if (!canAdd('data_user')) {
            setFlash('Anda tidak memiliki akses untuk menambah user!', 'danger');
            redirect('data_user.php');
        }
        
        $username = bersihkan($_POST['username']);
        $email = bersihkan($_POST['email']);
        $full_name = bersihkan($_POST['full_name']);
        $phone = bersihkan($_POST['phone']);
        $password = $_POST['password'];
        $role_name = bersihkan($_POST['role_name']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validasi
        $errors = [];
        if (empty($username)) $errors[] = 'Username wajib diisi!';
        if (empty($email)) $errors[] = 'Email wajib diisi!';
        if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi!';
        if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter!';
        if (empty($role_name)) $errors[] = 'Divisi wajib dipilih!';
        
        // Cek username/email sudah ada
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Username atau email sudah terdaftar!';
        }
        
        if (empty($errors)) {
            $hash = hashPassword($password);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $hash, $full_name, $phone, $role_name, $is_active]);
            
            setFlash('User berhasil ditambahkan!', 'success');
            redirect('data_user.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        // Cek permission edit
        if (!canEdit('data_user')) {
            setFlash('Anda tidak memiliki akses untuk mengedit user!', 'danger');
            redirect('data_user.php');
        }
        
        $id = (int)$_POST['id'];
        $username = bersihkan($_POST['username']);
        $email = bersihkan($_POST['email']);
        $full_name = bersihkan($_POST['full_name']);
        $phone = bersihkan($_POST['phone']);
        $role_name = bersihkan($_POST['role_name']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'];
        
        $errors = [];
        if (empty($username)) $errors[] = 'Username wajib diisi!';
        if (empty($email)) $errors[] = 'Email wajib diisi!';
        if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi!';
        if (empty($role_name)) $errors[] = 'Divisi wajib dipilih!';
        
        // Cek username/email sudah ada (kecuali dirinya sendiri)
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->execute([$username, $email, $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Username atau email sudah digunakan oleh user lain!';
        }
        
        if (empty($errors)) {
            if (!empty($password)) {
                $hash = hashPassword($password);
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, full_name = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$username, $email, $hash, $full_name, $phone, $role_name, $is_active, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$username, $email, $full_name, $phone, $role_name, $is_active, $id]);
            }
            
            setFlash('User berhasil diupdate!', 'success');
            redirect('data_user.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        // Cek permission delete
        if (!canDelete('data_user')) {
            setFlash('Anda tidak memiliki akses untuk menghapus user!', 'danger');
            redirect('data_user.php');
        }
        
        $id = (int)$_POST['id'];
        // Cek jangan hapus user utama
        if ($id == 1) {
            setFlash('Tidak dapat menghapus user utama!', 'danger');
            redirect('data_user.php');
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('User berhasil dihapus!', 'success');
        redirect('data_user.php');
    }
}

// Ambil data untuk edit
$editData = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Data User - PT Ganda Elang Tangguh</title>
    
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
        /* ---- SIDEBAR MODERN (Deep Navy Blue) ---- */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: #0e1a2b;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            padding: 30px 20px;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s ease;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 215, 0, 0.3); border-radius: 10px; }

        .sidebar .brand { 
            display: flex; align-items: center; gap: 12px; margin-bottom: 40px; text-decoration: none; 
            padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar .brand .logo-wrapper { width: 42px; height: 42px; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text h5 { font-weight: 800; margin: 0; color: #fff; letter-spacing: 0.5px; font-size: 16px; }
        .sidebar .brand .brand-text h5 span { color: #ffd700; }
        .sidebar .brand .brand-text small { font-size: 10px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }

        .sidebar .nav-item { 
            display: flex; align-items: center; padding: 12px 16px; 
            color: rgba(255,255,255,0.6); text-decoration: none; 
            border-radius: 10px; margin-bottom: 5px; transition: all 0.2s ease; font-weight: 500; 
            font-size: 14px; position: relative;
        }
        .sidebar .nav-item i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .sidebar .nav-item.active { 
            background: rgba(255, 215, 0, 0.1); 
            color: #ffd700; 
            box-shadow: inset 3px 0 0 #ffd700;
        }
        
        .sidebar .user-profile { 
            margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); 
            display: flex; align-items: center; gap: 12px; 
        }
        .sidebar .user-profile .avatar { 
            width: 42px; height: 42px; border-radius: 50%; 
            background: linear-gradient(135deg, #1a1a2e, #16213e); 
            color: #ffd700; display: flex; align-items: center; justify-content: center; 
            font-weight: 700; font-size: 16px; border: 2px solid rgba(255,215,0,0.2);
        }
        .sidebar .user-profile .user-info .name { font-size: 14px; font-weight: 600; color: #fff; }
        .sidebar .user-profile .user-info .role { font-size: 12px; color: rgba(255,255,255,0.4); }

        .sidebar .logout-btn {
            display: block; text-align: center; margin-top: 15px; 
            padding: 10px; border-radius: 10px; color: #e74c3c; text-decoration: none; 
            font-weight: 600; font-size: 14px; background: rgba(231, 76, 60, 0.1); 
            transition: all 0.2s;
        }
        .sidebar .logout-btn:hover { background: rgba(231, 76, 60, 0.2); }

        /* ---- MAIN CONTENT ---- */
        .main-content { margin-left: 260px; padding: 30px; width: 100%; }

        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 30px; flex-wrap: wrap; gap: 15px; 
        }
        .page-header h4 { 
            font-weight: 800; color: #0e1a2b; font-size: 24px; margin:0; 
            letter-spacing: -0.5px;
        }
        .page-header h4 span { color: #ffd700; }

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .stat-card { 
            background: #fff; border-radius: 16px; padding: 20px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .stat-card .stat-icon { 
            width: 44px; height: 44px; border-radius: 12px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 18px; margin-bottom: 10px; 
        }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.12); color: #d4a017; }
        .stat-card .stat-icon.blue { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .stat-card .stat-number { font-size: 24px; font-weight: 800; color: #0e1a2b; margin-bottom: 2px; }
        .stat-card .stat-label { font-size: 13px; color: #888; }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            transition: all 0.3s ease;
        }
        .card-custom:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        
        .card-custom .card-header-custom {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-custom .card-header-custom h6 {
            font-weight: 600;
            color: #0e1a2b;
            margin: 0;
            font-size: 16px;
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
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #7f8c8d;
            border-bottom: 1px solid #f0f2f5;
            padding: 12px 16px;
            background: #fafafa;
            white-space: nowrap;
        }
        
        .table-custom td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .table-custom tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tr:hover {
            background: #f8f9fa;
        }
        
        .badge-role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-role.it_support { background: rgba(155, 89, 182, 0.15); color: #8e44ad; }
        .badge-role.admin { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-role.finance { background: rgba(0, 206, 209, 0.15); color: #0d7a7a; }
        .badge-role.direktur_sales { background: rgba(26, 188, 156, 0.12); color: #16a085; }
        .badge-role.direktur_utama { background: rgba(241, 196, 15, 0.15); color: #b7950b; }
        .badge-role.direktur_operasional { background: rgba(155, 89, 182, 0.15); color: #8e44ad; }
        .badge-role.business { background: rgba(52, 73, 94, 0.12); color: #2c3e50; }
        .badge-role.sales_manager { background: rgba(52, 73, 94, 0.12); color: #2c3e50; }
        .badge-role.sales { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-role.service_support { background: rgba(26, 188, 156, 0.12); color: #16a085; }
        .badge-role.part_support { background: rgba(230, 126, 34, 0.12); color: #e67e22; }
        
        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-status.active { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-status.inactive { background: rgba(214, 48, 49, 0.12); color: #d63031; }
        
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
        .btn-action:hover { transform: scale(1.1); }
        .btn-action.edit { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        .btn-action.permission { background: rgba(155, 89, 182, 0.1); color: #8e44ad; }
        .btn-action.permission:hover { background: rgba(155, 89, 182, 0.2); }

        .modal-content { border: none; border-radius: 12px; }
        .modal-header { border-bottom: 1px solid #f0f2f5; padding: 18px 24px; }
        .modal-header .modal-title { font-weight: 700; font-size: 18px; color: #0e1a2b; }
        .modal-header .modal-title i { color: #ffd700; margin-right: 8px; }
        .modal-body { padding: 20px 24px; }
        .modal-footer { border-top: 1px solid #f0f2f5; padding: 14px 24px; }

        .form-label { font-weight: 600; font-size: 13px; color: #333; }
        .form-label .optional { font-weight: 400; color: #999; font-size: 11px; }
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
            background: #0e1a2b;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-primary-custom:hover {
            background: #1a2d4a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(14, 26, 43, 0.3);
            color: #fff;
        }
        .btn-primary-custom i { margin-right: 6px; }

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
        .btn-secondary-custom:hover { background: #e8edf2; color: #333; }

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }

        .mobile-toggle { display: none; }

        .breadcrumb { background: transparent; padding: 0; margin: 0; font-size: 13px; }
        .breadcrumb-item a { color: #2980b9; text-decoration: none; }
        .breadcrumb-item a:hover { color: #ffd700; }
        .breadcrumb-item.active { color: #0e1a2b; font-weight: 600; }

        .footer-text { text-align: center; padding: 16px 0 8px; color: #999; font-size: 11px; }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { 
                display: flex !important; background: #0e1a2b; border: none; 
                width: 40px; height: 40px; border-radius: 8px; 
                color: #ffd700; font-size: 20px; align-items: center; justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stat-card .stat-number { font-size: 17px; }
            .stat-card { padding: 12px 14px; }
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR MODERN -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
            <div class="brand-text">
                <h5>CUSTOMER <span>RELATIONSHIP</span></h5>
                <small>PT Ganda Elang Tangguh</small>
            </div>
        </a>

        <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
        
        <?php if (in_array('sales_activity', $menuNames)): ?>
            <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        
        <?php if (in_array('account_management', $menuNames)): ?>
            <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="deliveryinstruction.php" class="nav-item"><i class="fas fa-tractor"></i> Delivery</a>
        <?php endif; ?>
        
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-item active"><i class="fas fa-users"></i> User</a>
        <?php endif; ?>

        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div style="display:flex; gap:15px; align-items:center;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4><span><i class="fas fa-users" style="color:#ffd700;"></i></span> Data User</h4>
                </div>
            </div>
            <?php if (canAdd('data_user')): ?>
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalUser">
                    <i class="fas fa-plus"></i> Tambah User
                </button>
            <?php endif; ?>
        </div>

        <!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= number_format($totalData) ?></div>
                <div class="stat-label">Total User</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar User</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 220px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="data_user.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Username</th>
                                <th>Nama Lengkap</th>
                                <th>Email</th>
                                <th>No HP</th>
                                <th>Divisi</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= htmlspecialchars($user['phone'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-role <?= htmlspecialchars($user['role']) ?>">
                                                <?= getRoleLabel($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if (canEdit('data_user')): ?>
                                                    <button class="btn-action edit" onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (canDelete('data_user') && $user['id'] != 1): ?>
                                                    <button class="btn-action delete" onclick="deleteUser(<?= $user['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data user
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
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next</a></li>
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

    <!-- ============================================
    MODAL TAMBAH / EDIT USER
    ============================================ -->
    <div class="modal fade" id="modalUser" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formUser">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" id="username" class="form-control" placeholder="Masukkan username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" class="form-control" placeholder="user@email.com" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Masukkan nama lengkap" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nomor HP <span class="optional">(Optional)</span></label>
                                <input type="text" name="phone" id="phone" class="form-control" placeholder="08123456789">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Minimal 6 karakter" minlength="6">
                                <small class="text-muted" id="passwordHint">Minimal 6 karakter</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Divisi <span class="text-danger">*</span></label>
                                <select name="role_name" id="role_name" class="form-select" required>
                                    <option value="">Pilih Divisi</option>
                                    <option value="it_support">IT Support</option>
                                    <option value="admin">Admin</option>
                                    <option value="finance">Finance</option>
                                    <option value="business">Business</option>
                                    <option value="direktur_utama">Direktur Utama</option>
                                    <option value="direktur_sales">Direktur Sales</option>
                                    <option value="direktur_operasional">Direktur Operasional</option>
                                    <option value="sales_manager">Sales Manager</option>
                                    <option value="sales">Sales</option>
                                    <option value="service_support">Service Support</option>
                                    <option value="part_support">Part Support</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                                <label class="form-check-label" for="is_active">Aktif</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan</button>
                    </div>
                </form>
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
                    <p>Apakah Anda yakin ingin menghapus user ini?</p>
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

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Edit User
        function editUser(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('username').value = data.username;
            document.getElementById('email').value = data.email;
            document.getElementById('full_name').value = data.full_name;
            document.getElementById('phone').value = data.phone || '';
            document.getElementById('role_name').value = data.role;
            document.getElementById('is_active').checked = data.is_active == 1;
            
            document.getElementById('password').required = false;
            document.getElementById('password').placeholder = 'Kosongkan jika tidak diubah';
            document.getElementById('passwordHint').textContent = 'Kosongkan jika tidak ingin mengubah password';
            
            var modal = new bootstrap.Modal(document.getElementById('modalUser'));
            modal.show();
        }
        
        document.getElementById('modalUser').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formUser').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah User';
            document.getElementById('password').required = true;
            document.getElementById('password').placeholder = 'Minimal 6 karakter';
            document.getElementById('passwordHint').textContent = 'Minimal 6 karakter';
        });
        
        function deleteUser(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>