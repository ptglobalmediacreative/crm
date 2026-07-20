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
// CEK APAKAH TABEL PRODUCTS ADA
// ============================================
try {
    $db->query("SELECT 1 FROM products LIMIT 1");
} catch(PDOException $e) {
    // Buat tabel jika belum ada
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nama_produk VARCHAR(200) NOT NULL,
        jumlah_stok INT DEFAULT 0,
        harga_tebus_dealer DECIMAL(15,2) DEFAULT 0,
        harga_jual_sales DECIMAL(15,2) DEFAULT 0,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// ============================================
// CEK APAKAH KOLOM updated_by ADA
// ============================================
try {
    $db->query("SELECT updated_by FROM products LIMIT 1");
} catch(PDOException $e) {
    // Tambahkan kolom jika belum ada
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
// CEK USER YANG BISA AKSES PENUH (INPUT, EDIT, DELETE, DETAIL)
// ============================================
$fullAccessRoles = ['finance', 'business', 'it_support', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$userRole = $_SESSION['role'] ?? 'user';
$hasFullAccess = in_array($userRole, $fullAccessRoles);

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Set header untuk download file Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Data_Produk_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Ambil semua data tanpa pagination
    $sql = "SELECT p.*, u.full_name as updated_by_name 
            FROM products p 
            LEFT JOIN users u ON p.updated_by = u.id 
            ORDER BY p.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $allProducts = $stmt->fetchAll();
    
    // Buat tabel HTML untuk Excel
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
    echo '<th>Jumlah Stok</th>';
    echo '<th>Harga Tebus Dealer</th>';
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
        echo '<td>' . number_format($product['jumlah_stok']) . '</td>';
        echo '<td>Rp ' . number_format($product['harga_tebus_dealer'], 0, ',', '.') . '</td>';
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

// Get data dengan join user untuk updated_by
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
$totalStok = $db->query("SELECT SUM(jumlah_stok) FROM products")->fetchColumn() ?? 0;

// Produk dengan stok <= 10 (hampir habis)
$lowStock = $db->query("SELECT COUNT(*) FROM products WHERE jumlah_stok <= 10 AND jumlah_stok > 0")->fetchColumn() ?? 0;

// Produk dengan stok 0
$emptyStock = $db->query("SELECT COUNT(*) FROM products WHERE jumlah_stok <= 0")->fetchColumn() ?? 0;

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// Proses tambah produk (hanya untuk yang punya akses penuh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // Cek apakah user punya akses penuh
    if (!$hasFullAccess) {
        setFlash('Anda tidak memiliki akses untuk melakukan tindakan ini!', 'danger');
        redirect('produk.php');
    }
    
    if ($action === 'add') {
        // Cek permission tambah
        if (!canAdd('produk')) {
            setFlash('Anda tidak memiliki akses untuk menambah produk!', 'danger');
            redirect('produk.php');
        }
        
        $nama_produk = bersihkan($_POST['nama_produk']);
        $jumlah_stok = (int)$_POST['jumlah_stok'];
        $harga_tebus_dealer = str_replace(['.', ','], '', $_POST['harga_tebus_dealer']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        // Validasi
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($jumlah_stok < 0) $errors[] = 'Jumlah stok tidak boleh negatif!';
        if ($harga_tebus_dealer < 0) $errors[] = 'Harga tebus dealer tidak boleh negatif!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO products (nama_produk, jumlah_stok, harga_tebus_dealer, harga_jual_sales, updated_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nama_produk, $jumlah_stok, $harga_tebus_dealer, $harga_jual_sales, $userId]);
            
            setFlash('Produk berhasil ditambahkan!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        // Cek permission edit
        if (!canEdit('produk')) {
            setFlash('Anda tidak memiliki akses untuk mengedit produk!', 'danger');
            redirect('produk.php');
        }
        
        $id = (int)$_POST['id'];
        $nama_produk = bersihkan($_POST['nama_produk']);
        $jumlah_stok = (int)$_POST['jumlah_stok'];
        $harga_tebus_dealer = str_replace(['.', ','], '', $_POST['harga_tebus_dealer']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($jumlah_stok < 0) $errors[] = 'Jumlah stok tidak boleh negatif!';
        if ($harga_tebus_dealer < 0) $errors[] = 'Harga tebus dealer tidak boleh negatif!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE products SET nama_produk = ?, jumlah_stok = ?, harga_tebus_dealer = ?, harga_jual_sales = ?, updated_by = ? WHERE id = ?");
            $stmt->execute([$nama_produk, $jumlah_stok, $harga_tebus_dealer, $harga_jual_sales, $userId, $id]);
            
            setFlash('Produk berhasil diupdate!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        // Cek permission delete
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

// Ambil data untuk edit (hanya untuk yang punya akses penuh)
$editData = null;
if (isset($_GET['edit']) && $hasFullAccess) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
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
        
        .section-title .see-all {
            font-size: 12px;
            color: #888;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            cursor: pointer;
        }
        
        .section-title .see-all:hover {
            color: #ffd700;
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
        
        .btn-action.detail:hover {
            background: rgba(46, 204, 113, 0.2);
        }
        
        .btn-action.edit {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
        }
        
        .btn-action.edit:hover {
            background: rgba(52, 152, 219, 0.2);
        }
        
        .btn-action.delete {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }
        
        .btn-action.delete:hover {
            background: rgba(231, 76, 60, 0.2);
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
        
        /* Format Rupiah Input */
        .currency-input {
            position: relative;
        }
        
        .currency-input .currency-prefix {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-weight: 600;
            font-size: 13px;
        }
        
        .currency-input .form-control {
            padding-left: 40px;
        }
        
        /* Detail Item */
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
        
        .detail-item .detail-value .badge {
            font-size: 12px;
            padding: 4px 10px;
        }
        
        /* Badge akses penuh */
        .badge-full-access {
            background: rgba(46, 204, 113, 0.12);
            color: #27ae60;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-view-only {
            background: rgba(52, 152, 219, 0.12);
            color: #2980b9;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
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
                <a href="#" class="nav-link">
                    <i class="fas fa-chart-bar"></i> Sales Activity
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('produk')): ?>
                <a href="produk.php" class="nav-link active">
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
                <div class="greeting">Produk</div>
                <h3>Kelola Data Produk</h3>
            </div>
            <i class="fas fa-box welcome-icon"></i>
        </div>

        <!-- STATISTIK -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-lg-3 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalProducts) ?></div>
                        <div class="stat-label">Total Produk</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-box"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalStok) ?></div>
                        <div class="stat-label">Total Stok</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-cubes"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($lowStock) ?></div>
                        <div class="stat-label">Stok Hampir Habis</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle" style="color:#f39c12;"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-3 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($emptyStock) ?></div>
                        <div class="stat-label">Stok Kosong</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-times-circle" style="color:#d63031;"></i></div>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i>Daftar Produk</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                        <button type="submit" class="btn btn-sm btn-primary-custom"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="produk.php" class="btn btn-sm btn-secondary-custom"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                    <a href="produk.php?export=excel" class="btn btn-sm btn-success-custom">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                    <!-- Tombol Tambah - HANYA UNTUK USER DENGAN AKSES PENUH -->
                    <?php if ($hasFullAccess && canAdd('produk')): ?>
                        <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalProduk">
                            <i class="fas fa-plus"></i> Tambah Produk
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
                                <th>Nama Produk</th>
                                <th>Jumlah Stok</th>
                                <!-- Harga Tebus Dealer - HANYA UNTUK AKSES PENUH -->
                                <?php if ($hasFullAccess): ?>
                                    <th>Harga Tebus Dealer</th>
                                <?php endif; ?>
                                <th>Harga Jual Sales</th>
                                <!-- Kolom Aksi - HANYA UNTUK AKSES PENUH -->
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
                                        <td>
                                            <span class="badge <?= $product['jumlah_stok'] <= 0 ? 'bg-danger' : ($product['jumlah_stok'] <= 10 ? 'bg-warning' : 'bg-success') ?>">
                                                <?= number_format($product['jumlah_stok']) ?>
                                            </span>
                                        </td>
                                        <!-- Harga Tebus Dealer - HANYA UNTUK AKSES PENUH -->
                                        <?php if ($hasFullAccess): ?>
                                            <td>Rp <?= number_format($product['harga_tebus_dealer'], 0, ',', '.') ?></td>
                                        <?php endif; ?>
                                        <td>Rp <?= number_format($product['harga_jual_sales'], 0, ',', '.') ?></td>
                                        <!-- Kolom Aksi - HANYA UNTUK AKSES PENUH -->
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
                                    <td colspan="<?= $hasFullAccess ? 6 : 4 ?>" class="text-center py-4 text-muted">
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

    </main>

    <!-- ============================================
    MODAL TAMBAH / EDIT PRODUK - HANYA UNTUK AKSES PENUH
    ============================================ -->
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
                            <label class="form-label">Jumlah Stok <span class="text-danger">*</span></label>
                            <input type="number" name="jumlah_stok" id="jumlah_stok" class="form-control" placeholder="0" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Harga Tebus Dealer <span class="text-danger">*</span></label>
                            <div class="currency-input">
                                <span class="currency-prefix">Rp</span>
                                <input type="text" name="harga_tebus_dealer" id="harga_tebus_dealer" class="form-control currency-mask" placeholder="0" required>
                            </div>
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

    <!-- ============================================
    MODAL DETAIL - HANYA UNTUK AKSES PENUH
    ============================================ -->
    <?php if ($hasFullAccess): ?>
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-box" style="color:#ffd700;"></i> Detail Produk</h5>
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
    <?php endif; ?>

    <!-- ============================================
    MODAL DELETE - HANYA UNTUK AKSES PENUH
    ============================================ -->
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

    <!-- ============================================
    BOTTOM NAVIGATION - MOBILE
    ============================================ -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-th-large nav-icon"></i>
            <span class="nav-label">Home</span>
        </a>
        
        <!-- Account Management -->
        <?php if (canAccessMenu('account_management')): ?>
            <a href="account_management.php" class="nav-item active">
                <i class="fas fa-building nav-icon"></i>
                <span class="nav-label">Account</span>
            </a>
        <?php endif; ?>
        
        <!-- Sales Activity -->
        <?php if (canAccessMenu('sales_activity')): ?>
            <a href="#" class="nav-item">
                <i class="fas fa-chart-bar nav-icon"></i>
                <span class="nav-label">Sales</span>
            </a>
        <?php endif; ?>
        
        <!-- Produk -->
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-item">
                <i class="fas fa-box nav-icon"></i>
                <span class="nav-label">Produk</span>
            </a>
        <?php endif; ?>
        
        <!-- Delivery Order -->
        <?php if (canAccessMenu('delivery_order')): ?>
            <a href="#" class="nav-item">
                <i class="fas fa-tractor nav-icon"></i>
                <span class="nav-label">DO</span>
            </a>
        <?php endif; ?>
        
        <!-- Data User -->
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
        
        // Detail Produk dengan history
        function detailProduk(data) {
            var updatedByName = data.updated_by_name || '-';
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Nama Produk</div>
                    <div class="detail-value"><strong>${data.nama_produk}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Jumlah Stok</div>
                    <div class="detail-value">
                        <span class="badge ${data.jumlah_stok <= 0 ? 'bg-danger' : (data.jumlah_stok <= 10 ? 'bg-warning' : 'bg-success')}">
                            ${new Intl.NumberFormat('id-ID').format(data.jumlah_stok)}
                        </span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Harga Tebus Dealer</div>
                    <div class="detail-value">Rp ${new Intl.NumberFormat('id-ID').format(data.harga_tebus_dealer)}</div>
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
            document.getElementById('jumlah_stok').value = data.jumlah_stok;
            document.getElementById('harga_tebus_dealer').value = new Intl.NumberFormat('id-ID').format(data.harga_tebus_dealer);
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