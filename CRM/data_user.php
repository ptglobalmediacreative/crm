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
            // Update user
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
        /* ===== SEMUA STYLE SAMA SEPERTI SEBELUMNYA ===== */
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
        
        .badge-role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .badge-role.it_support { background: rgba(155, 89, 182, 0.15); color: #8e44ad; }
        .badge-role.admin { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-role.direktur_sales { background: rgba(26, 188, 156, 0.12); color: #16a085; }
        .badge-role.direktur_utama { background: rgba(241, 196, 15, 0.15); color: #b7950b; }
        .badge-role.direktur_operasional { background: rgba(155, 89, 182, 0.15); color: #8e44ad; }
        .badge-role.business { background: rgba(52, 73, 94, 0.12); color: #2c3e50; }
        .badge-role.sales_manager { background: rgba(52, 73, 94, 0.12); color: #2c3e50; }
        .badge-role.sales { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        
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
        
        .btn-action:hover {
            transform: scale(1.1);
        }
        
        .btn-action.edit { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        
        .btn-action.permission { background: rgba(155, 89, 182, 0.1); color: #8e44ad; }
        .btn-action.permission:hover { background: rgba(155, 89, 182, 0.2); }
        
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
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
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
            .table-custom { font-size: 12px; }
            .table-custom th, .table-custom td { padding: 8px 10px; }
        }
        
        @media (max-width: 480px) {
            .stat-card .stat-number { font-size: 17px; }
            .stat-card { padding: 12px 14px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
            .modal-body { padding: 14px 16px; }
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
            <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="account_management.php" class="nav-link"><i class="fas fa-building"></i> Account</a>
            <a href="#" class="nav-link"><i class="fas fa-chart-bar"></i> Sales</a>
            <a href="#" class="nav-link"><i class="fas fa-box"></i> Produk</a>
            <a href="#" class="nav-link"><i class="fas fa-truck"></i> Delivery</a>
            <a href="data_user.php" class="nav-link active"><i class="fas fa-users"></i> Data User</a>
        </div>
        
        <div class="nav-right">
            <div class="notif-icon"><i class="fas fa-bell"></i><span class="badge-notif">3</span></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= ucfirst($role) ?></div>
            </div>
            <a href="logout.php" class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- MOBILE HEADER -->
    <header class="top-header">
        <div class="header-left">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="PT Ganda Elang Tangguh"></div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Customer Relationship Management</div>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-icon"><i class="fas fa-bell"></i><span class="badge-notif">3</span></div>
            <a href="logout.php" class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></a>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main style="padding: 16px 20px 0; max-width: 1400px; margin: 0 auto;">

        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Data User</div>
                <h3>Kelola User & Permission</h3>
            </div>
            <i class="fas fa-users welcome-icon"></i>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i>Daftar User</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                        <button type="submit" class="btn btn-sm btn-primary-custom"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="data_user.php" class="btn btn-sm btn-secondary-custom"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                    
                    <?php if (canAdd('data_user')): ?>
                        <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalUser">
                            <i class="fas fa-plus"></i> Tambah User
                        </button>
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
                                                <?php 
                                                    $roleLabels = [
                                                        'it_support' => 'IT Support',
                                                        'admin' => 'Admin',
                                                        'direktur_utama' => 'Dir. Utama',
                                                        'direktur_operasional' => 'Dir. Operasional',
                                                        'direktur_sales' => 'Dir. Sales',
                                                        'business' => 'Business',
                                                        'sales_manager' => 'Sales Manager',
                                                        'sales' => 'Sales'
                                                    ];
                                                    echo $roleLabels[$user['role']] ?? ucfirst($user['role']);
                                                ?>
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
                                                
                                                <?php if (canManageUser()): ?>
                                                    <button class="btn-action permission" onclick="showPermission(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                        <i class="fas fa-lock"></i>
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

        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

    </main>

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
                                    <option value="business">Business</option>
                                    <option value="direktur_utama">Direktur Utama</option>
                                    <option value="direktur_sales">Direktur Sales</option>
                                    <option value="direktur_operasional">Direktur Operasional</option>
                                    <option value="sales_manager">Sales Manager</option>
                                    <option value="sales">Sales</option>
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
    MODAL PERMISSION - HANYA MENU UTAMA
    ============================================ -->
    <div class="modal fade" id="modalPermission" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-lock" style="color:#ffd700;"></i> Atur Akses Menu Utama</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="permissionBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Memuat data...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary-custom" onclick="savePermission()"><i class="fas fa-save"></i> Simpan</button>
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

    <!-- BOTTOM NAVIGATION -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i><span class="nav-label">Home</span></a>
        <a href="account_management.php" class="nav-item"><i class="fas fa-building nav-icon"></i><span class="nav-label">Account</span></a>
        <a href="#" class="nav-item"><i class="fas fa-chart-bar nav-icon"></i><span class="nav-label">Sales</span></a>
        <a href="#" class="nav-item"><i class="fas fa-box nav-icon"></i><span class="nav-label">Produk</span></a>
        <a href="#" class="nav-item"><i class="fas fa-truck nav-icon"></i><span class="nav-label">DO</span><span class="badge-nav">12</span></a>
        <a href="data_user.php" class="nav-item active"><i class="fas fa-users nav-icon"></i><span class="nav-label">User</span></a>
        <a href="logout.php" class="nav-item"><i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i><span class="nav-label" style="color:#d63031;">Logout</span></a>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUserId = null;
        let currentUserRole = null;
        
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
        
        function showPermission(data) {
            currentUserId = data.id;
            currentUserRole = data.role;
            
            var modal = new bootstrap.Modal(document.getElementById('modalPermission'));
            modal.show();
            
            document.getElementById('permissionBody').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Memuat data...</p>
                </div>
            `;
            
            fetch('api/get_permission.php?user_id=' + data.id)
                .then(response => response.json())
                .then(data => {
                    var html = '';
                    if (data.modules && data.modules.length > 0) {
                        html = '<p class="text-muted mb-3">Atur akses menu utama untuk divisi <strong>' + data.role + '</strong></p>';
                        html += '<p class="text-warning small"><i class="fas fa-info-circle"></i> Centang menu yang ingin ditampilkan di dashboard</p>';
                        html += '<div class="table-responsive">';
                        html += '<table class="table table-bordered table-sm">';
                        html += '<thead><tr><th>Menu Utama</th><th>Tampil di Dashboard</th></tr></thead>';
                        html += '<tbody>';
                        data.modules.forEach(function(module) {
                            var checked = module.can_view == 1 ? 'checked' : '';
                            html += '<tr>';
                            html += '<td><strong>' + module.module_label + '</strong></td>';
                            html += '<td>';
                            html += '<input type="checkbox" class="perm-check form-check-input" data-module="' + module.module_name + '" ' + checked + '>';
                            html += '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                    } else {
                        html = '<div class="text-center py-4"><i class="fas fa-inbox fa-3x text-muted mb-3"></i><p>Belum ada data menu utama</p></div>';
                    }
                    document.getElementById('permissionBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('permissionBody').innerHTML = '<div class="text-center py-4 text-danger">Gagal memuat data!</div>';
                });
        }
        
        function savePermission() {
            var permissions = [];
            document.querySelectorAll('.perm-check').forEach(function(checkbox) {
                var module = checkbox.dataset.module;
                var checked = checkbox.checked ? 1 : 0;
                permissions.push({module: module, value: checked});
            });
            
            fetch('api/save_permission.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    role_name: currentUserRole,
                    permissions: permissions
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Permission berhasil disimpan!');
                    var modal = bootstrap.Modal.getInstance(document.getElementById('modalPermission'));
                    modal.hide();
                    location.reload();
                } else {
                    alert('Gagal menyimpan permission: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Terjadi kesalahan!');
            });
        }
    </script>
</body>
</html>