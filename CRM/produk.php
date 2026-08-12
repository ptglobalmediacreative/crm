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
requirePermission('produk', 'view');

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();
$menuNames = array_column($userMenus, 'module_name');

// ============================================
// BUAT TABEL PRODUCTS (HANYA NAMA PRODUK DAN HARGA JUAL SALES)
// ============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nama_produk VARCHAR(200) NOT NULL,
        harga_jual_sales DECIMAL(15,2) DEFAULT 0,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch(PDOException $e) {
    // Abaikan error jika tabel sudah ada
}

// ============================================
// CEK APAKAH KOLOM updated_by ADA
// ============================================
try {
    $db->query("SELECT updated_by FROM products LIMIT 1");
} catch(PDOException $e) {
    $db->exec("ALTER TABLE products ADD COLUMN updated_by INT NULL");
}

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
// CEK USER YANG BISA AKSES PENUH
// ============================================
$fullAccessRoles = ['finance', 'business', 'it_support', 'admin', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$userRole = $_SESSION['role'] ?? 'user';
$hasFullAccess = in_array($userRole, $fullAccessRoles);

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Data_Produk_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $sql = "SELECT p.*, u.full_name as updated_by_name 
            FROM products p 
            LEFT JOIN users u ON p.updated_by = u.id 
            ORDER BY p.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $allProducts = $stmt->fetchAll();
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<h2>Data Produk - PT Ganda Elang Tangguh</h2>';
    echo '<p>Tanggal Export: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr style="background-color: #1a1a2e; color: #ffffff;">';
    echo '<th>No</th>';
    echo '<th>Nama Produk</th>';
    echo '<th>Harga Jual Sales</th>';
    echo '<th>Tanggal Dibuat</th>';
    echo '<th>Terakhir Update</th>';
    echo '<th>Diupdate Oleh</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    foreach ($allProducts as $product) {
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($product['nama_produk']) . '</td>';
        echo '<td>Rp ' . number_format($product['harga_jual_sales'], 0, ',', '.') . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($product['created_at'])) . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($product['updated_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($product['updated_by_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<p style="margin-top: 20px; font-size: 12px; color: #999;">* Data di export pada ' . date('d-m-Y H:i:s') . '</p>';
    echo '</body>';
    echo '</html>';
    exit;
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
    $where .= " AND nama_produk LIKE ?";
    $params = ["%$search%"];
}

// Get total data
$countSql = "SELECT COUNT(*) FROM products $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT p.*, u.full_name as updated_by_name 
        FROM products p 
        LEFT JOIN users u ON p.updated_by = u.id 
        $where 
        ORDER BY p.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// Proses tambah/edit/hapus produk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if (!$hasFullAccess) {
        setFlash('Anda tidak memiliki akses untuk melakukan tindakan ini!', 'danger');
        redirect('produk.php');
    }
    
    if ($action === 'add') {
        if (!canAdd('produk')) {
            setFlash('Anda tidak memiliki akses untuk menambah produk!', 'danger');
            redirect('produk.php');
        }
        
        $nama_produk = bersihkan($_POST['nama_produk']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO products (nama_produk, harga_jual_sales, updated_by) VALUES (?, ?, ?)");
            $stmt->execute([$nama_produk, $harga_jual_sales, $userId]);
            
            setFlash('Produk berhasil ditambahkan!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        if (!canEdit('produk')) {
            setFlash('Anda tidak memiliki akses untuk mengedit produk!', 'danger');
            redirect('produk.php');
        }
        
        $id = (int)$_POST['id'];
        $nama_produk = bersihkan($_POST['nama_produk']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE products SET nama_produk = ?, harga_jual_sales = ?, updated_by = ? WHERE id = ?");
            $stmt->execute([$nama_produk, $harga_jual_sales, $userId, $id]);
            
            setFlash('Produk berhasil diupdate!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!canDelete('produk')) {
            setFlash('Anda tidak memiliki akses untuk menghapus produk!', 'danger');
            redirect('produk.php');
        }
        
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Produk berhasil dihapus!', 'success');
        redirect('produk.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Produk - PT Ganda Elang Tangguh</title>
    
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
        .btn-action.detail { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .btn-action.detail:hover { background: rgba(46, 204, 113, 0.2); }
        .btn-action.edit { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }

        .modal-content { border: none; border-radius: 12px; }
        .modal-header { border-bottom: 1px solid #f0f2f5; padding: 18px 24px; }
        .modal-header .modal-title { font-weight: 700; font-size: 18px; color: #0e1a2b; }
        .modal-header .modal-title i { color: #ffd700; margin-right: 8px; }
        .modal-body { padding: 20px 24px; }
        .modal-footer { border-top: 1px solid #f0f2f5; padding: 14px 24px; }

        .form-label { font-weight: 600; font-size: 13px; color: #333; }
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
        .btn-success-custom:hover { background: #219a52; color: #fff; }

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }

        .detail-item { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f2f5; }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .detail-label { font-weight: 600; color: #555; width: 160px; flex-shrink: 0; font-size: 13px; }
        .detail-item .detail-value { color: #0e1a2b; font-size: 13px; word-break: break-word; }

        .currency-input { position: relative; }
        .currency-input .currency-prefix { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #999; font-weight: 600; font-size: 13px; }
        .currency-input .form-control { padding-left: 40px; }

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
            .detail-item { flex-direction: column; padding: 8px 0; }
            .detail-item .detail-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .detail-item .detail-value { font-size: 12px; }
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
            <a href="produk.php" class="nav-item active"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="#" class="nav-item"><i class="fas fa-tractor"></i> Delivery</a>
        <?php endif; ?>
        
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i> User</a>
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
                    <h4><span><i class="fas fa-box" style="color:#ffd700;"></i></span> Produk</h4>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="produk.php?export=excel" class="btn btn-success-custom">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <?php if ($hasFullAccess && canAdd('produk')): ?>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalProduk">
                        <i class="fas fa-plus"></i> Tambah Produk
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-box"></i></div>
                <div class="stat-number"><?= number_format($totalProducts) ?></div>
                <div class="stat-label">Total Produk</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Produk</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>" style="width: 220px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="produk.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
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
                                <th>Nama Produk</th>
                                <th>Harga Jual Sales</th>
                                <?php if ($hasFullAccess): ?>
                                    <th>Aksi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($product['nama_produk']) ?></strong></td>
                                        <td>Rp <?= number_format($product['harga_jual_sales'], 0, ',', '.') ?></td>
                                        <?php if ($hasFullAccess): ?>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button class="btn-action detail" onclick="detailProduk(<?= htmlspecialchars(json_encode($product)) ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (canEdit('produk')): ?>
                                                        <button class="btn-action edit" onclick="editProduk(<?= htmlspecialchars(json_encode($product)) ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (canDelete('produk')): ?>
                                                        <button class="btn-action delete" onclick="deleteProduk(<?= $product['id'] ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $hasFullAccess ? 4 : 3 ?>" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data produk
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

    <!-- MODAL TAMBAH / EDIT PRODUK -->
    <?php if ($hasFullAccess): ?>
    <div class="modal fade" id="modalProduk" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formProduk">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                            <input type="text" name="nama_produk" id="nama_produk" class="form-control" placeholder="Masukkan nama produk" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Harga Jual Sales <span class="text-danger">*</span></label>
                            <div class="currency-input">
                                <span class="currency-prefix">Rp</span>
                                <input type="text" name="harga_jual_sales" id="harga_jual_sales" class="form-control currency-mask" placeholder="0" required>
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
    <?php endif; ?>

    <!-- MODAL DETAIL -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-box" style="color:#ffd700;"></i> Detail Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DELETE -->
    <?php if ($hasFullAccess): ?>
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus produk ini?</p>
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
    <?php endif; ?>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Format Rupiah untuk input
        document.querySelectorAll('.currency-mask').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value) {
                    this.value = new Intl.NumberFormat('id-ID').format(value);
                } else {
                    this.value = '';
                }
            });
        });
        
        // Detail Produk
        function detailProduk(data) {
            var updatedByName = data.updated_by_name || '-';
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Nama Produk</div>
                    <div class="detail-value"><strong>${data.nama_produk}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Harga Jual Sales</div>
                    <div class="detail-value">Rp ${new Intl.NumberFormat('id-ID').format(data.harga_jual_sales)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tanggal Dibuat</div>
                    <div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Terakhir Update</div>
                    <div class="detail-value">${new Date(data.updated_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Diupdate Oleh</div>
                    <div class="detail-value">
                        <i class="fas fa-user-edit" style="color:#2980b9;"></i>
                        ${updatedByName}
                    </div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }
        
        // Edit Produk
        function editProduk(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Produk';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('nama_produk').value = data.nama_produk;
            document.getElementById('harga_jual_sales').value = new Intl.NumberFormat('id-ID').format(data.harga_jual_sales);
            
            var modal = new bootstrap.Modal(document.getElementById('modalProduk'));
            modal.show();
        }
        
        // Reset form when modal closed
        document.getElementById('modalProduk').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formProduk').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Produk';
            document.querySelectorAll('.currency-mask').forEach(function(input) {
                input.value = '';
            });
        });
        
        // Delete Produk
        function deleteProduk(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>