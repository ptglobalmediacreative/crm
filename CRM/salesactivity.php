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
// BUAT TABEL SALES_ACTIVITIES (JIKA BELUM ADA)
// ============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS sales_activities (
        id INT PRIMARY KEY AUTO_INCREMENT,
        leads_number VARCHAR(50) NOT NULL UNIQUE,
        account_id INT NOT NULL,
        sales_id INT NULL,
        jenis_tugas VARCHAR(100) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        status ENUM('pending', 'in_progress', 'completed', 'overdue') DEFAULT 'pending',
        customer_deal ENUM('', 'Yes', 'No') DEFAULT '',
        due_date DATETIME NULL,
        description TEXT NULL,
        trf_number VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
        FOREIGN KEY (sales_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_account_id (account_id),
        INDEX idx_sales_id (sales_id),
        INDEX idx_status (status),
        INDEX idx_due_date (due_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(PDOException $e) {
    // Abaikan jika tabel sudah ada
}

// ============================================
// TAMBAH KOLOM JIKA BELUM ADA
// ============================================
try {
    $db->query("SELECT subject FROM sales_activities LIMIT 1");
} catch(PDOException $e) {
    $db->exec("ALTER TABLE sales_activities ADD COLUMN subject VARCHAR(255) NOT NULL AFTER jenis_tugas");
}

try {
    $db->query("SELECT description FROM sales_activities LIMIT 1");
} catch(PDOException $e) {
    $db->exec("ALTER TABLE sales_activities ADD COLUMN description TEXT NULL AFTER due_date");
}

try {
    $db->query("SELECT customer_deal FROM sales_activities LIMIT 1");
} catch(PDOException $e) {
    $db->exec("ALTER TABLE sales_activities ADD COLUMN customer_deal ENUM('', 'Yes', 'No') DEFAULT '' AFTER status");
}

// ============================================
// FUNGSI GENERATE LEADS NUMBER
// ============================================
function generateLeadsNumber($db) {
    $tahun = date('Y');
    $bulan = date('n'); // 1-12
    
    // Konversi ke Romawi
    $romawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    $bulanRomawi = $romawi[$bulan];
    
    // Prefix
    $prefix = "0001/GET-ACT/JKT/{$bulanRomawi}/{$tahun}";
    
    // Cari nomor terakhir dengan bulan dan tahun yang sama
    $pattern = "%/GET-ACT/JKT/{$bulanRomawi}/{$tahun}%";
    $stmt = $db->prepare("SELECT leads_number FROM sales_activities WHERE leads_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        // Extract nomor dari format: 0001/GET-ACT/JKT/III/2025
        $parts = explode('/', $lastNumber);
        $lastSequence = (int)$parts[0];
        $nextSequence = $lastSequence + 1;
        $sequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    } else {
        $sequence = '0001';
    }
    
    return "{$sequence}/GET-ACT/JKT/{$bulanRomawi}/{$tahun}";
}

// ============================================
// FUNGSI KONVERSI BULAN KE ROMAWI
// ============================================
function getBulanRomawi($month) {
    $romawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romawi[(int)$month];
}

// ============================================
// FILTER & PAGINATION
// ============================================
$userRole = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';

// Filter Status
$filterStatus = isset($_GET['status']) ? bersihkan($_GET['status']) : '';
$filterJenisTugas = isset($_GET['jenis_tugas']) ? bersihkan($_GET['jenis_tugas']) : '';

// Build query
$where = "WHERE 1=1";
$params = [];

// Filter berdasarkan role (sales hanya lihat miliknya)
if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}

if (!empty($search)) {
    $where .= " AND (sa.leads_number LIKE ? OR sa.subject LIKE ? OR a.nama_pt LIKE ? OR a.nama_pic LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%"]);
}

if (!empty($filterStatus)) {
    $where .= " AND sa.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterJenisTugas)) {
    $where .= " AND sa.jenis_tugas = ?";
    $params[] = $filterJenisTugas;
}

// ============================================
// STATISTIK UNTUK DONUT CHART
// ============================================
$statWhere = "WHERE 1=1";
$statParams = [];

if ($userRole === 'sales') {
    $statWhere .= " AND sa.sales_id = ?";
    $statParams[] = $userId;
}

// Status Aktivitas
$statusCounts = [
    'total' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'overdue' => 0
];

$sqlTotalAktivitas = "SELECT COUNT(*) FROM sales_activities sa $statWhere";
$stmt = $db->prepare($sqlTotalAktivitas);
$stmt->execute($statParams);
$statusCounts['total'] = (int)$stmt->fetchColumn();

$sqlInProgress = "SELECT COUNT(*) FROM sales_activities sa $statWhere AND sa.status = 'in_progress'";
$stmt = $db->prepare($sqlInProgress);
$stmt->execute($statParams);
$statusCounts['in_progress'] = (int)$stmt->fetchColumn();

$sqlCompleted = "SELECT COUNT(*) FROM sales_activities sa $statWhere AND sa.status = 'completed'";
$stmt = $db->prepare($sqlCompleted);
$stmt->execute($statParams);
$statusCounts['completed'] = (int)$stmt->fetchColumn();

$sqlOverdue = "SELECT COUNT(*) FROM sales_activities sa $statWhere AND sa.status = 'overdue'";
$stmt = $db->prepare($sqlOverdue);
$stmt->execute($statParams);
$statusCounts['overdue'] = (int)$stmt->fetchColumn();

// Status Prospek
$prospekCounts = [
    'Middle Prospek' => 0,
    'Hot Prospek' => 0,
    'Lost Prospek' => 0,
    'Deal' => 0
];

// Middle Prospek (Prospecting, belum Negosiasi/Kontrak)
$sqlMiddle = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
              WHERE sa.jenis_tugas = 'Prospecting'
              AND sa.account_id NOT IN (
                  SELECT DISTINCT account_id FROM sales_activities 
                  WHERE jenis_tugas IN ('Negosiasi', 'Kontrak') AND account_id IS NOT NULL
              )";
if ($userRole === 'sales') {
    $sqlMiddle .= " AND sa.sales_id = ?";
}
$stmt = $db->prepare($sqlMiddle);
if ($userRole === 'sales') {
    $stmt->execute([$userId]);
} else {
    $stmt->execute();
}
$prospekCounts['Middle Prospek'] = (int)$stmt->fetchColumn();

// Hot Prospek (Negosiasi)
$sqlHot = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
           WHERE sa.jenis_tugas = 'Negosiasi'
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas = 'Kontrak' AND account_id IS NOT NULL
           )
           AND sa.account_id NOT IN (
               SELECT DISTINCT account_id FROM sales_activities 
               WHERE jenis_tugas = 'Negosiasi' AND status = 'completed' AND customer_deal = 'No' AND account_id IS NOT NULL
           )
           AND NOT (sa.status = 'completed' AND sa.customer_deal = 'No')";
if ($userRole === 'sales') {
    $sqlHot .= " AND sa.sales_id = ?";
}
$stmt = $db->prepare($sqlHot);
if ($userRole === 'sales') {
    $stmt->execute([$userId]);
} else {
    $stmt->execute();
}
$prospekCounts['Hot Prospek'] = (int)$stmt->fetchColumn();

// Lost Prospek
$sqlLost = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Negosiasi'
            AND sa.status = 'completed' 
            AND sa.customer_deal = 'No'";
if ($userRole === 'sales') {
    $sqlLost .= " AND sa.sales_id = ?";
}
$stmt = $db->prepare($sqlLost);
if ($userRole === 'sales') {
    $stmt->execute([$userId]);
} else {
    $stmt->execute();
}
$prospekCounts['Lost Prospek'] = (int)$stmt->fetchColumn();

// Deal
$sqlDeal = "SELECT COUNT(DISTINCT sa.account_id) FROM sales_activities sa 
            WHERE sa.jenis_tugas = 'Kontrak'";
if ($userRole === 'sales') {
    $sqlDeal .= " AND sa.sales_id = ?";
}
$stmt = $db->prepare($sqlDeal);
if ($userRole === 'sales') {
    $stmt->execute([$userId]);
} else {
    $stmt->execute();
}
$prospekCounts['Deal'] = (int)$stmt->fetchColumn();

// ============================================
// GET TOTAL DATA & LIST ACTIVITIES
// ============================================
$countSql = "SELECT COUNT(*) FROM sales_activities sa LEFT JOIN accounts a ON sa.account_id = a.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT sa.*, a.nama_pt, a.badan_usaha, a.bidang_usaha, a.nama_pic, a.no_hp_pic, u.full_name as sales_name
        FROM sales_activities sa 
        LEFT JOIN accounts a ON sa.account_id = a.id 
        LEFT JOIN users u ON sa.sales_id = u.id
        $where 
        ORDER BY sa.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// ============================================
// AMBIL DATA ACCOUNTS UNTUK DROPDOWN
// ============================================
$sqlAccounts = "SELECT id, nama_pt, badan_usaha, bidang_usaha, nama_pic, no_hp_pic, npwp, alamat, email_pic 
                FROM accounts 
                ORDER BY nama_pt ASC";
$accountsList = $db->query($sqlAccounts)->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// AMBIL DATA SALES UNTUK DROPDOWN
// ============================================
$salesUsers = $db->query("SELECT id, full_name FROM users WHERE role IN ('sales', 'sales_manager') ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROSES TAMBAH SALES ACTIVITY
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        if (!canAdd('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menambah aktivitas!', 'danger');
            redirect('salesactivity.php');
        }
        
        $account_id = (int)$_POST['account_id'];
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $subject = bersihkan($_POST['subject']);
        $status = bersihkan($_POST['status']);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
        $description = !empty($_POST['description']) ? bersihkan($_POST['description']) : NULL;
        $customer_deal = isset($_POST['customer_deal']) ? bersihkan($_POST['customer_deal']) : '';
        
        // Sales ID
        if ($userRole === 'sales') {
            $sales_id = $userId;
        } else {
            $sales_id = !empty($_POST['sales_id']) ? (int)$_POST['sales_id'] : NULL;
        }
        
        // Generate Leads Number
        $leads_number = generateLeadsNumber($db);
        
        $errors = [];
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($status)) $errors[] = 'Status wajib dipilih!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO sales_activities (leads_number, account_id, sales_id, jenis_tugas, subject, status, customer_deal, due_date, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$leads_number, $account_id, $sales_id, $jenis_tugas, $subject, $status, $customer_deal, $due_date, $description]);
            
            setFlash('Sales Activity berhasil ditambahkan! Leads Number: ' . $leads_number, 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        if (!canEdit('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk mengedit aktivitas!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        $account_id = (int)$_POST['account_id'];
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $subject = bersihkan($_POST['subject']);
        $status = bersihkan($_POST['status']);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
        $description = !empty($_POST['description']) ? bersihkan($_POST['description']) : NULL;
        $customer_deal = isset($_POST['customer_deal']) ? bersihkan($_POST['customer_deal']) : '';
        
        if ($userRole === 'sales') {
            $sales_id = $userId;
        } else {
            $sales_id = !empty($_POST['sales_id']) ? (int)$_POST['sales_id'] : NULL;
        }
        
        $errors = [];
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($status)) $errors[] = 'Status wajib dipilih!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE sales_activities SET account_id = ?, sales_id = ?, jenis_tugas = ?, subject = ?, status = ?, customer_deal = ?, due_date = ?, description = ? WHERE id = ?");
            $stmt->execute([$account_id, $sales_id, $jenis_tugas, $subject, $status, $customer_deal, $due_date, $description, $id]);
            
            setFlash('Sales Activity berhasil diupdate!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menghapus aktivitas!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Sales Activity berhasil dihapus!', 'success');
        redirect('salesactivity.php');
    }
}

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
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
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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

        /* ---- CHART GRID ---- */
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 991px) { .chart-grid { grid-template-columns: 1fr; } }
        
        .chart-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .chart-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .chart-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; }
        .chart-card h6 i { color: #ffd700; margin-right: 8px; }
        .chart-wrapper { height: 300px; width: 100%; position: relative; }

        /* ---- FILTER BAR ---- */
        .filter-bar {
            display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
            background: #fff; border-radius: 12px; padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea;
            margin-bottom: 24px;
        }
        .filter-bar .form-select, .filter-bar .form-control {
            border-radius: 8px; border: 2px solid #e8edf2; font-size: 13px; padding: 8px 12px;
        }
        .filter-bar .btn-filter {
            background: #0e1a2b; color: #fff; border: none; border-radius: 8px;
            padding: 8px 20px; font-weight: 600; font-size: 13px;
        }
        .filter-bar .btn-filter:hover { background: #1a2d4a; }

        /* ---- TABLE ---- */
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
        
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-status.in_progress { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-status.completed { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-status.overdue { background: rgba(231, 76, 60, 0.12); color: #c0392b; }
        .badge-status.pending { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        
        .badge-tugas {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-tugas.Prospecting { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-tugas.Negosiasi { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-tugas.Kontrak { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        
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
        
        .form-control[readonly] {
            background: #f8f9fa;
            cursor: not-allowed;
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

        .detail-item { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f2f5; }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .detail-label { font-weight: 600; color: #555; width: 160px; flex-shrink: 0; font-size: 13px; }
        .detail-item .detail-value { color: #0e1a2b; font-size: 13px; word-break: break-word; }

        .leads-number-display {
            background: rgba(255, 215, 0, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            color: #d4a017;
            text-align: center;
            font-size: 16px;
            letter-spacing: 0.5px;
        }

        .customer-deal-field { display: none; }
        .customer-deal-field.show { display: block; }

        .mobile-toggle { display: none; }

        .footer-text { text-align: center; padding: 16px 0 8px; color: #999; font-size: 11px; }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }

        /* Select2 Custom */
        .select2-container--default .select2-selection--single {
            border-radius: 8px;
            padding: 6px 14px;
            border: 2px solid #e8edf2;
            font-size: 13px;
            min-height: 42px;
        }
        .select2-container--default .select2-selection--single:focus {
            border-color: #ffd700;
        }

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
            .chart-wrapper { height: 220px; }
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
            <a href="salesactivity.php" class="nav-item active"><i class="fas fa-chart-bar"></i> Sales Activity</a>
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
                    <h4><span><i class="fas fa-chart-bar" style="color:#ffd700;"></i></span> Sales Activity</h4>
                </div>
            </div>
            <?php if (canAdd('sales_activity')): ?>
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalActivity">
                    <i class="fas fa-plus"></i> Tambah Aktivitas
                </button>
            <?php endif; ?>
        </div>

        <!-- CHART DONUT -->
        <div class="chart-grid">
            <!-- Donut Status Aktivitas -->
            <div class="chart-card">
                <h6><i class="fas fa-tasks"></i> Status Aktivitas</h6>
                <div class="chart-wrapper">
                    <canvas id="donutStatusAktivitas"></canvas>
                </div>
            </div>
            
            <!-- Donut Status Prospek -->
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie"></i> Status Prospek</h6>
                <div class="chart-wrapper">
                    <canvas id="donutStatusProspek"></canvas>
                </div>
            </div>
        </div>

        <!-- FILTER BAR -->
        <div class="filter-bar">
            <form method="GET" class="d-flex gap-2 flex-wrap align-items-center w-100">
                <i class="fas fa-filter" style="color:#d4a017;"></i>
                <input type="text" name="search" class="form-control" placeholder="Cari Leads Number, Subject, Nama PT..." value="<?= htmlspecialchars($search) ?>" style="width: 250px;">
                
                <select name="status" class="form-select" style="width: 150px;">
                    <option value="">Semua Status</option>
                    <option value="in_progress" <?= $filterStatus == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $filterStatus == 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="overdue" <?= $filterStatus == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="pending" <?= $filterStatus == 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
                
                <select name="jenis_tugas" class="form-select" style="width: 150px;">
                    <option value="">Semua Jenis</option>
                    <option value="Prospecting" <?= $filterJenisTugas == 'Prospecting' ? 'selected' : '' ?>>Prospecting</option>
                    <option value="Negosiasi" <?= $filterJenisTugas == 'Negosiasi' ? 'selected' : '' ?>>Negosiasi</option>
                    <option value="Kontrak" <?= $filterJenisTugas == 'Kontrak' ? 'selected' : '' ?>>Kontrak</option>
                </select>
                
                <button type="submit" class="btn btn-filter"><i class="fas fa-search"></i> Cari</button>
                
                <?php if (!empty($search) || !empty($filterStatus) || !empty($filterJenisTugas)): ?>
                    <a href="salesactivity.php" class="btn btn-secondary-custom" style="padding: 8px 16px;"><i class="fas fa-times"></i> Reset</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Sales Activity</h6>
            </div>
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Leads Number</th>
                                <th>Nama PT</th>
                                <th>Jenis Tugas</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Due Date</th>
                                <th>Sales</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($activities) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($activities as $act): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($act['leads_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($act['nama_pt']) ?></td>
                                        <td>
                                            <span class="badge-tugas <?= htmlspecialchars($act['jenis_tugas']) ?>">
                                                <?= htmlspecialchars($act['jenis_tugas']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($act['subject']) ?></td>
                                        <td>
                                            <span class="badge-status <?= htmlspecialchars($act['status']) ?>">
                                                <?php 
                                                    $statusLabels = [
                                                        'pending' => 'Pending',
                                                        'in_progress' => 'In Progress',
                                                        'completed' => 'Completed',
                                                        'overdue' => 'Overdue'
                                                    ];
                                                    echo $statusLabels[$act['status']] ?? $act['status'];
                                                ?>
                                            </span>
                                        </td>
                                        <td><?= $act['due_date'] ? date('d-m-Y', strtotime($act['due_date'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($act['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailActivity(<?= htmlspecialchars(json_encode($act)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (canEdit('sales_activity')): ?>
                                                    <button class="btn-action edit" onclick="editActivity(<?= htmlspecialchars(json_encode($act)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if (canDelete('sales_activity')): ?>
                                                    <button class="btn-action delete" onclick="deleteActivity(<?= $act['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data aktivitas
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
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&jenis_tugas=<?= urlencode($filterJenisTugas) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&jenis_tugas=<?= urlencode($filterJenisTugas) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($filterStatus) ?>&jenis_tugas=<?= urlencode($filterJenisTugas) ?>">Next</a></li>
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

    <!-- MODAL TAMBAH / EDIT ACTIVITY -->
    <div class="modal fade" id="modalActivity" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formActivity">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <!-- Leads Number (Auto Generate untuk Tambah) -->
                        <div class="mb-3" id="leadsNumberContainer">
                            <label class="form-label">Leads Number</label>
                            <div class="leads-number-display" id="leadsNumberDisplay">
                                <?php 
                                    $tahun = date('Y');
                                    $bulanRomawi = getBulanRomawi(date('n'));
                                    echo "0001/GET-ACT/JKT/{$bulanRomawi}/{$tahun}";
                                ?>
                            </div>
                            <small class="text-muted">Generate otomatis saat disimpan</small>
                        </div>
                        
                        <!-- Account Management -->
                        <div class="mb-3">
                            <label class="form-label">Account Management <span class="text-danger">*</span></label>
                            <select name="account_id" id="account_id" class="form-select select2-account" required style="width: 100%;">
                                <option value="">-- Pilih Account (Ketik untuk mencari) --</option>
                                <?php foreach ($accountsList as $acc): ?>
                                    <option value="<?= $acc['id'] ?>" 
                                        data-badan_usaha="<?= htmlspecialchars($acc['badan_usaha'] ?? 'PT') ?>"
                                        data-bidang_usaha="<?= htmlspecialchars($acc['bidang_usaha'] ?? '-') ?>"
                                        data-nama_pic="<?= htmlspecialchars($acc['nama_pic'] ?? '-') ?>"
                                        data-no_hp_pic="<?= htmlspecialchars($acc['no_hp_pic'] ?? '-') ?>">
                                        <?= htmlspecialchars($acc['nama_pt']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Informasi Account (Readonly) -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Badan Usaha</label>
                                <input type="text" id="badan_usaha" class="form-control" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Business Segment</label>
                                <input type="text" id="bidang_usaha" class="form-control" readonly>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama PIC</label>
                                <input type="text" id="nama_pic" class="form-control" readonly>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Mobile Phone</label>
                                <input type="text" id="no_hp_pic" class="form-control" readonly>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <!-- Jenis Tugas -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jenis Tugas <span class="text-danger">*</span></label>
                                <select name="jenis_tugas" id="jenis_tugas" class="form-select" required>
                                    <option value="">Pilih Jenis Tugas</option>
                                    <option value="Prospecting">Prospecting</option>
                                    <option value="Negosiasi">Negosiasi</option>
                                    <option value="Kontrak">Kontrak</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="status" id="status" class="form-select" required>
                                    <option value="">Pilih Status</option>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                    <option value="overdue">Overdue</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="subject" class="form-control" placeholder="Masukkan subject aktivitas" required>
                        </div>
                        
                        <!-- Customer Deal (muncul jika jenis_tugas = Negosiasi) -->
                        <div class="row customer-deal-field" id="customerDealField">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Customer Deal?</label>
                                <select name="customer_deal" id="customer_deal" class="form-select">
                                    <option value="">-- Pilih --</option>
                                    <option value="Yes">Yes</option>
                                    <option value="No">No</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Due Date <span class="optional">(Optional)</span></label>
                                <input type="date" name="due_date" id="due_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Sales</label>
                                <?php if ($userRole === 'sales'): ?>
                                    <input type="hidden" name="sales_id" value="<?= $userId ?>">
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($fullName) ?> (Sales)" readonly>
                                <?php else: ?>
                                    <select name="sales_id" id="sales_id" class="form-select">
                                        <option value="">-- Pilih Sales --</option>
                                        <?php foreach ($salesUsers as $s): ?>
                                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['full_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description <span class="optional">(Optional)</span></label>
                            <textarea name="description" id="description" class="form-control" rows="3" placeholder="Masukkan deskripsi aktivitas"></textarea>
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

    <!-- MODAL DETAIL -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-chart-bar" style="color:#ffd700;"></i> Detail Aktivitas</h5>
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
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus aktivitas ini?</p>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // ============================================
        // SELECT2 UNTUK SEARCH ACCOUNT
        // ============================================
        $(document).ready(function() {
            $('.select2-account').select2({
                placeholder: '-- Pilih Account (Ketik untuk mencari) --',
                allowClear: true,
                dropdownParent: $('#modalActivity')
            });
            
            // Event ketika account dipilih
            $('.select2-account').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                if (selectedOption.val()) {
                    $('#badan_usaha').val(selectedOption.data('badan_usaha'));
                    $('#bidang_usaha').val(selectedOption.data('bidang_usaha'));
                    $('#nama_pic').val(selectedOption.data('nama_pic'));
                    $('#no_hp_pic').val(selectedOption.data('no_hp_pic'));
                } else {
                    $('#badan_usaha').val('');
                    $('#bidang_usaha').val('');
                    $('#nama_pic').val('');
                    $('#no_hp_pic').val('');
                }
            });
            
            // Event ketika jenis_tugas berubah
            $('#jenis_tugas').on('change', function() {
                if ($(this).val() === 'Negosiasi') {
                    $('#customerDealField').addClass('show');
                } else {
                    $('#customerDealField').removeClass('show');
                    $('#customer_deal').val('');
                }
            });
        });

        // ============================================
        // CHART DONUT - STATUS AKTIVITAS
        // ============================================
        const ctxStatusAktivitas = document.getElementById('donutStatusAktivitas').getContext('2d');
        new Chart(ctxStatusAktivitas, {
            type: 'doughnut',
            data: {
                labels: ['Total Aktivitas', 'In Progress', 'Completed', 'Overdue'],
                datasets: [{
                    data: [
                        <?= $statusCounts['total'] ?>,
                        <?= $statusCounts['in_progress'] ?>,
                        <?= $statusCounts['completed'] ?>,
                        <?= $statusCounts['overdue'] ?>
                    ],
                    backgroundColor: [
                        '#d4a017', // Gold
                        '#2980b9', // Blue
                        '#27ae60', // Green
                        '#e74c3c'  // Red
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: { family: 'Inter', size: 12 }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                var label = context.label || '';
                                var value = context.raw || 0;
                                return label + ': ' + value;
                            }
                        }
                    }
                }
            }
        });

        // ============================================
        // CHART DONUT - STATUS PROSPEK
        // ============================================
        const ctxStatusProspek = document.getElementById('donutStatusProspek').getContext('2d');
        new Chart(ctxStatusProspek, {
            type: 'doughnut',
            data: {
                labels: ['Middle Prospek', 'Hot Prospek', 'Lost Prospek', 'Deal'],
                datasets: [{
                    data: [
                        <?= $prospekCounts['Middle Prospek'] ?>,
                        <?= $prospekCounts['Hot Prospek'] ?>,
                        <?= $prospekCounts['Lost Prospek'] ?>,
                        <?= $prospekCounts['Deal'] ?>
                    ],
                    backgroundColor: [
                        '#f39c12', // Orange
                        '#e74c3c', // Red
                        '#95a5a6', // Gray
                        '#2ecc71'  // Green
                    ],
                    borderWidth: 0,
                    hoverOffset: 10
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: { family: 'Inter', size: 12 }
                        }
                    }
                }
            }
        });

        // ============================================
        // DETAIL ACTIVITY
        // ============================================
        function detailActivity(data) {
            var statusLabels = {pending: 'Pending', in_progress: 'In Progress', completed: 'Completed', overdue: 'Overdue'};
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Leads Number</div>
                    <div class="detail-value"><strong>${data.leads_number}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Nama PT</div>
                    <div class="detail-value">${data.nama_pt || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Badan Usaha</div>
                    <div class="detail-value">${data.badan_usaha || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Business Segment</div>
                    <div class="detail-value">${data.bidang_usaha || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Nama PIC</div>
                    <div class="detail-value">${data.nama_pic || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Contact Mobile</div>
                    <div class="detail-value">${data.no_hp_pic || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Jenis Tugas</div>
                    <div class="detail-value">${data.jenis_tugas}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Subject</div>
                    <div class="detail-value">${data.subject}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value"><span class="badge-status ${data.status}">${statusLabels[data.status] || data.status}</span></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Due Date</div>
                    <div class="detail-value">${data.due_date ? new Date(data.due_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Sales</div>
                    <div class="detail-value">${data.sales_name || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Description</div>
                    <div class="detail-value">${data.description || '-'}</div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }

        // ============================================
        // EDIT ACTIVITY
        // ============================================
        function editActivity(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Aktivitas';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            
            // Set Leads Number (readonly saat edit)
            document.getElementById('leadsNumberDisplay').textContent = data.leads_number;
            
            // Set Account
            $('#account_id').val(data.account_id).trigger('change');
            
            // Set field lainnya
            document.getElementById('jenis_tugas').value = data.jenis_tugas;
            document.getElementById('status').value = data.status;
            document.getElementById('subject').value = data.subject;
            document.getElementById('due_date').value = data.due_date ? data.due_date.split(' ')[0] : '';
            document.getElementById('description').value = data.description || '';
            document.getElementById('customer_deal').value = data.customer_deal || '';
            
            // Tampilkan customer_deal jika jenis_tugas = Negosiasi
            if (data.jenis_tugas === 'Negosiasi') {
                document.getElementById('customerDealField').classList.add('show');
            } else {
                document.getElementById('customerDealField').classList.remove('show');
            }
            
            // Set sales_id
            if (document.getElementById('sales_id')) {
                document.getElementById('sales_id').value = data.sales_id || '';
            }
            
            var modal = new bootstrap.Modal(document.getElementById('modalActivity'));
            modal.show();
        }

        // ============================================
        // RESET FORM SAAT MODAL DITUTUP
        // ============================================
        document.getElementById('modalActivity').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formActivity').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Aktivitas';
            document.getElementById('leadsNumberDisplay').textContent = '0001/GET-ACT/JKT/<?= getBulanRomawi(date("n")) ?>/<?= date("Y") ?>';
            $('#account_id').val('').trigger('change');
            $('#badan_usaha').val('');
            $('#bidang_usaha').val('');
            $('#nama_pic').val('');
            $('#no_hp_pic').val('');
            document.getElementById('customerDealField').classList.remove('show');
        });

        // ============================================
        // DELETE ACTIVITY
        // ============================================
        function deleteActivity(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>