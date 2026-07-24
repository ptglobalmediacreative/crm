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
requirePermission('account_management', 'view');

// ============================================
// CEK ROLE DIREKTUR (untuk akses penuh)
// ============================================
$userRole = $_SESSION['role'] ?? 'user';
$direkturRoles = ['direktur_utama', 'direktur_sales'];
$isDirektur = in_array($userRole, $direkturRoles);

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
// FUNGSI UNTUK MEMBERSIHKAN NAMA PT (CEK DUPLIKAT)
// ============================================
function bersihkanNamaPT($nama) {
    // Hilangkan titik, koma, strip, dan karakter khusus lainnya
    $nama = preg_replace('/[^\w\s]/', '', $nama);
    // Hilangkan spasi berlebih
    $nama = preg_replace('/\s+/', ' ', $nama);
    // Ubah ke huruf kecil semua
    $nama = strtolower(trim($nama));
    return $nama;
}

// ============================================
// CEK APAKAH USER ADALAH DIREKTUR OPERASIONAL
// ============================================
$isDirekturOperasional = ($userRole === 'direktur_operasional');

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Data_Account_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $sql = "SELECT a.*, u.full_name as sales_name FROM accounts a LEFT JOIN users u ON a.sales_id = u.id ORDER BY a.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $allAccounts = $stmt->fetchAll();
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<h2>Data Account - PT Ganda Elang Tangguh</h2>';
    echo '<p>Tanggal Export: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr style="background-color: #1a1a2e; color: #ffffff;">';
    echo '<th>No</th>';
    echo '<th>Badan Usaha</th>';
    echo '<th>Nama PT/Perusahaan</th>';
    echo '<th>Bidang Usaha</th>';
    echo '<th>Alamat</th>';
    echo '<th>Area</th>';
    echo '<th>NPWP</th>';
    echo '<th>Nama PIC</th>';
    echo '<th>No Handphone PIC</th>';
    echo '<th>Email PIC</th>';
    echo '<th>Lead Source</th>';
    echo '<th>Sales</th>';
    echo '<th>Tanggal Dibuat</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    foreach ($allAccounts as $account) {
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($account['badan_usaha'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($account['nama_pt']) . '</td>';
        echo '<td>' . htmlspecialchars($account['bidang_usaha']) . '</td>';
        echo '<td>' . htmlspecialchars($account['alamat']) . '</td>';
        echo '<td>' . htmlspecialchars($account['area'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($account['npwp'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($account['nama_pic']) . '</td>';
        echo '<td>' . htmlspecialchars($account['no_hp_pic']) . '</td>';
        echo '<td>' . htmlspecialchars($account['email_pic']) . '</td>';
        echo '<td>' . htmlspecialchars($account['lead_source']) . '</td>';
        echo '<td>' . htmlspecialchars($account['sales_name'] ?? '-') . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($account['created_at'])) . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<p style="margin-top: 20px; font-size: 12px; color: #999;">* Data di export pada ' . date('d-m-Y H:i:s') . '</p>';
    echo '</body>';
    echo '</html>';
    exit;
}

// ============================================
// TAMBAHKAN KOLOM sales_id KE TABEL accounts (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS sales_id INT NULL");
    $db->exec("ALTER TABLE accounts ADD INDEX idx_sales_id (sales_id)");
} catch(PDOException $e) {
    // Kolom sudah ada atau error lainnya
}

// ============================================
// TAMBAHKAN KOLOM area KE TABEL accounts (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS area VARCHAR(100) NULL");
} catch(PDOException $e) {
    // Kolom sudah ada atau error lainnya
}

// ============================================
// TAMBAHKAN KOLOM badan_usaha KE TABEL accounts (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS badan_usaha VARCHAR(50) NULL DEFAULT 'PT'");
} catch(PDOException $e) {
    // Kolom sudah ada atau error lainnya
}

// ============================================
// FILTER BERDASARKAN SALES (Hanya sales yang bersangkutan bisa melihat datanya)
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';

// Build query with filter
$where = "WHERE 1=1";
$params = [];

// Filter berdasarkan role
if ($userRole !== 'it_support' && $userRole !== 'admin' && !in_array($userRole, ['direktur_utama', 'direktur_sales', 'direktur_operasional'])) {
    $where .= " AND a.sales_id = ?";
    $params[] = $userId;
}

if (!empty($search)) {
    $where .= " AND (a.nama_pt LIKE ? OR a.alamat LIKE ? OR a.nama_pic LIKE ? OR a.email_pic LIKE ? OR u.full_name LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
}

// Get total data
$countSql = "SELECT COUNT(*) FROM accounts a LEFT JOIN users u ON a.sales_id = u.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT a.*, u.full_name as sales_name FROM accounts a LEFT JOIN users u ON a.sales_id = u.id $where ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$accounts = $stmt->fetchAll();

// ============================================
// STATISTIK - HANYA DATA YANG BISA DILIHAT USER
// ============================================
$statWhere = "WHERE 1=1";
$statParams = [];

if ($userRole !== 'it_support' && $userRole !== 'admin' && !in_array($userRole, ['direktur_utama', 'direktur_sales', 'direktur_operasional'])) {
    $statWhere .= " AND sales_id = ?";
    $statParams[] = $userId;
}

// Total Account
$totalAccounts = $db->prepare("SELECT COUNT(*) FROM accounts $statWhere");
$totalAccounts->execute($statParams);
$totalAccounts = $totalAccounts->fetchColumn();

// Lead Call
$leadCall = $db->prepare("SELECT COUNT(*) FROM accounts $statWhere AND lead_source = 'Call'");
$leadCall->execute($statParams);
$leadCall = $leadCall->fetchColumn();

// Lead Canvasing
$leadCanvasing = $db->prepare("SELECT COUNT(*) FROM accounts $statWhere AND lead_source = 'Canvasing'");
$leadCanvasing->execute($statParams);
$leadCanvasing = $leadCanvasing->fetchColumn();

// Lead Website
$leadWebsite = $db->prepare("SELECT COUNT(*) FROM accounts $statWhere AND lead_source = 'Website'");
$leadWebsite->execute($statParams);
$leadWebsite = $leadWebsite->fetchColumn();

// ============================================
// AMBIL DATA SALES DAN DIREKTUR UNTUK DROPDOWN
// ============================================
$salesUsers = $db->query("
    SELECT id, username, full_name, email, role 
    FROM users 
    WHERE role = 'sales' OR role LIKE 'direktur%' 
    ORDER BY role, full_name
")->fetchAll();

$hasSales = count($salesUsers) > 0;

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

// Proses tambah account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        if ($userRole !== 'sales' && !$isDirektur && !canAdd('account_management')) {
            setFlash('Anda tidak memiliki akses untuk menambah account!', 'danger');
            redirect('account_management.php');
        }
        
        $badan_usaha = bersihkan($_POST['badan_usaha']);
        $nama_pt = bersihkan($_POST['nama_pt']);
        $alamat = bersihkan($_POST['alamat']);
        $area = bersihkan($_POST['area']);
        $npwp = bersihkan($_POST['npwp']);
        $nama_pic = bersihkan($_POST['nama_pic']);
        $no_hp_pic = bersihkan($_POST['no_hp_pic']);
        $email_pic = bersihkan($_POST['email_pic']);
        $lead_source = bersihkan($_POST['lead_source']);
        $bidang_usaha = bersihkan($_POST['bidang_usaha']);
        
        if ($userRole === 'sales') {
            $sales_id = $userId;
        } else {
            $sales_id = !empty($_POST['sales_id']) ? (int)$_POST['sales_id'] : NULL;
        }
        
        $npwp_file = '';
        
        // Validasi
        $errors = [];
        if (empty($badan_usaha)) $errors[] = 'Badan Usaha wajib dipilih!';
        if (empty($nama_pt)) $errors[] = 'Nama PT/Perusahaan wajib diisi!';
        if (empty($alamat)) $errors[] = 'Alamat wajib diisi!';
        if (empty($area)) $errors[] = 'Area wajib diisi!';
        if (empty($nama_pic)) $errors[] = 'Nama PIC wajib diisi!';
        if (empty($no_hp_pic)) $errors[] = 'No Handphone PIC wajib diisi!';
        if (empty($email_pic)) $errors[] = 'Email PIC wajib diisi!';
        if (empty($lead_source)) $errors[] = 'Lead Source wajib dipilih!';
        if (empty($bidang_usaha)) $errors[] = 'Bidang Usaha wajib dipilih!';
        
        // ============================================
        // CEK DUPLIKAT NAMA PT
        // ============================================
        $nama_pt_clean = bersihkanNamaPT($nama_pt);
        
        // Ambil semua nama PT dari database
        $stmt = $db->prepare("SELECT id, nama_pt FROM accounts");
        $stmt->execute();
        $existingAccounts = $stmt->fetchAll();
        
        $isDuplicate = false;
        foreach ($existingAccounts as $existing) {
            $existing_clean = bersihkanNamaPT($existing['nama_pt']);
            if ($nama_pt_clean === $existing_clean) {
                $isDuplicate = true;
                break;
            }
        }
        
        if ($isDuplicate) {
            $errors[] = 'Nama PT/Perusahaan "' . $nama_pt . '" sudah terdaftar! Silakan gunakan nama yang berbeda.';
        }
        
        // Upload file NPWP
        if (!empty($_FILES['npwp_file']['name'])) {
            $target_dir = "uploads/npwp/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['npwp_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $npwp_file = $target_dir . time() . '_' . uniqid() . '.' . $file_extension;
                move_uploaded_file($_FILES['npwp_file']['tmp_name'], $npwp_file);
            } else {
                $errors[] = 'Format file NPWP tidak didukung! (JPG, PNG, PDF)';
            }
        }
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO accounts (badan_usaha, nama_pt, alamat, area, npwp, npwp_file, nama_pic, no_hp_pic, email_pic, lead_source, bidang_usaha, sales_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $npwp_file, $nama_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id]);
            setFlash('Data account berhasil ditambahkan!', 'success');
            redirect('account_management.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        if (!$isDirektur && !canEdit('account_management')) {
            setFlash('Anda tidak memiliki akses untuk mengedit account!', 'danger');
            redirect('account_management.php');
        }
        
        $id = (int)$_POST['id'];
        $badan_usaha = bersihkan($_POST['badan_usaha']);
        $nama_pt = bersihkan($_POST['nama_pt']);
        $alamat = bersihkan($_POST['alamat']);
        $area = bersihkan($_POST['area']);
        $npwp = bersihkan($_POST['npwp']);
        $nama_pic = bersihkan($_POST['nama_pic']);
        $no_hp_pic = bersihkan($_POST['no_hp_pic']);
        $email_pic = bersihkan($_POST['email_pic']);
        $lead_source = bersihkan($_POST['lead_source']);
        $bidang_usaha = bersihkan($_POST['bidang_usaha']);
        $sales_id = !empty($_POST['sales_id']) ? (int)$_POST['sales_id'] : NULL;
        
        $errors = [];
        if (empty($badan_usaha)) $errors[] = 'Badan Usaha wajib dipilih!';
        if (empty($nama_pt)) $errors[] = 'Nama PT/Perusahaan wajib diisi!';
        if (empty($alamat)) $errors[] = 'Alamat wajib diisi!';
        if (empty($area)) $errors[] = 'Area wajib diisi!';
        if (empty($nama_pic)) $errors[] = 'Nama PIC wajib diisi!';
        if (empty($no_hp_pic)) $errors[] = 'No Handphone PIC wajib diisi!';
        if (empty($email_pic)) $errors[] = 'Email PIC wajib diisi!';
        if (empty($lead_source)) $errors[] = 'Lead Source wajib dipilih!';
        if (empty($bidang_usaha)) $errors[] = 'Bidang Usaha wajib dipilih!';
        
        // ============================================
        // CEK DUPLIKAT NAMA PT (Kecuali dirinya sendiri)
        // ============================================
        $nama_pt_clean = bersihkanNamaPT($nama_pt);
        
        // Ambil semua nama PT dari database (kecuali dirinya sendiri)
        $stmt = $db->prepare("SELECT id, nama_pt FROM accounts WHERE id != ?");
        $stmt->execute([$id]);
        $existingAccounts = $stmt->fetchAll();
        
        $isDuplicate = false;
        foreach ($existingAccounts as $existing) {
            $existing_clean = bersihkanNamaPT($existing['nama_pt']);
            if ($nama_pt_clean === $existing_clean) {
                $isDuplicate = true;
                break;
            }
        }
        
        if ($isDuplicate) {
            $errors[] = 'Nama PT/Perusahaan "' . $nama_pt . '" sudah terdaftar! Silakan gunakan nama yang berbeda.';
        }
        
        // Upload file NPWP
        $npwp_file = '';
        if (!empty($_FILES['npwp_file']['name'])) {
            $target_dir = "uploads/npwp/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = strtolower(pathinfo($_FILES['npwp_file']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                $npwp_file = $target_dir . time() . '_' . uniqid() . '.' . $file_extension;
                move_uploaded_file($_FILES['npwp_file']['tmp_name'], $npwp_file);
            } else {
                $errors[] = 'Format file NPWP tidak didukung! (JPG, PNG, PDF)';
            }
        }
        
        if (empty($errors)) {
            if (!empty($npwp_file)) {
                $stmt = $db->prepare("UPDATE accounts SET badan_usaha = ?, nama_pt = ?, alamat = ?, area = ?, npwp = ?, npwp_file = ?, nama_pic = ?, no_hp_pic = ?, email_pic = ?, lead_source = ?, bidang_usaha = ?, sales_id = ? WHERE id = ?");
                $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $npwp_file, $nama_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id, $id]);
            } else {
                $stmt = $db->prepare("UPDATE accounts SET badan_usaha = ?, nama_pt = ?, alamat = ?, area = ?, npwp = ?, nama_pic = ?, no_hp_pic = ?, email_pic = ?, lead_source = ?, bidang_usaha = ?, sales_id = ? WHERE id = ?");
                $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $nama_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id, $id]);
            }
            setFlash('Data account berhasil diupdate!', 'success');
            redirect('account_management.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!$isDirektur && !canDelete('account_management')) {
            setFlash('Anda tidak memiliki akses untuk menghapus account!', 'danger');
            redirect('account_management.php');
        }
        
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM accounts WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Data account berhasil dihapus!', 'success');
        redirect('account_management.php');
    }
}

// Ambil data untuk detail
$detailData = null;
if (isset($_GET['detail'])) {
    $id = (int)$_GET['detail'];
    $stmt = $db->prepare("SELECT a.*, u.full_name as sales_name FROM accounts a LEFT JOIN users u ON a.sales_id = u.id WHERE a.id = ?");
    $stmt->execute([$id]);
    $detailData = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Account Management - PT Ganda Elang Tangguh</title>
    
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
        
        /* ============================================
           TOP HEADER - MOBILE
           ============================================ */
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
        
        /* ============================================
           WELCOME BANNER
           ============================================ */
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
        
        /* ============================================
           SECTION TITLE
           ============================================ */
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
        
        /* ============================================
           STAT CARD
           ============================================ */
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
        
        /* ============================================
           TABLE
           ============================================ */
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
        
        .badge-lead {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-lead.call { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-lead.chat { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-lead.meeting { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .badge-lead.canvasing { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-lead.referensi { background: rgba(26, 188, 156, 0.12); color: #16a085; }
        .badge-lead.website { background: rgba(231, 76, 60, 0.12); color: #c0392b; }
        
        .badge-sales {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(241, 196, 15, 0.12);
            color: #d4a017;
        }
        
        .badge-badan-usaha {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(26, 188, 156, 0.12);
            color: #16a085;
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
        
        /* ============================================
           MODAL
           ============================================ */
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
            width: 140px;
            flex-shrink: 0;
            font-size: 13px;
        }
        
        .detail-item .detail-value {
            color: #1a1a2e;
            font-size: 13px;
            word-break: break-word;
        }
        
        /* ============================================
           BOTTOM NAVIGATION - MOBILE
           ============================================ */
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
        
        /* ============================================
           DESKTOP NAVBAR
           ============================================ */
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
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        
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
                <a href="account_management.php" class="nav-link active">
                    <i class="fas fa-building"></i> Account
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('sales_activity')): ?>
                <a href="salesactivity.php" class="nav-link">
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
                <div class="greeting">Account Management</div>
                <h3>Kelola Data Perusahaan</h3>
            </div>
            <i class="fas fa-building welcome-icon"></i>
        </div>

        <!-- STATISTIK - HANYA DATA YANG BISA DILIHAT -->
        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($totalAccounts) ?></div>
                        <div class="stat-label">Total Account</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-building"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($leadCall) ?></div>
                        <div class="stat-label">Lead Call</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-phone"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($leadCanvasing) ?></div>
                        <div class="stat-label">Lead Canvasing</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-people-arrows"></i></div>
                </div>
            </div>
            <div class="col-xl-3 col-lg-6 col-md-6">
                <div class="stat-card d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-number"><?= number_format($leadWebsite) ?></div>
                        <div class="stat-label">Lead Website</div>
                    </div>
                    <div class="stat-icon"><i class="fas fa-globe"></i></div>
                </div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i>Daftar Account</h6>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 200px;">
                        <button type="submit" class="btn btn-sm btn-primary-custom"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="account_management.php" class="btn btn-sm btn-secondary-custom"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
                    <a href="account_management.php?export=excel" class="btn btn-sm btn-success-custom">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </a>
                    <?php if ($userRole === 'sales' || $isDirektur || canAdd('account_management')): ?>
                        <button class="btn btn-sm btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAccount">
                            <i class="fas fa-plus"></i> Tambah
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
                                <th>Badan Usaha</th>
                                <th>Nama PT/Perusahaan</th>
                                <th>PIC</th>
                                <th>No HP</th>
                                <th>Email</th>
                                <th>Lead Source</th>
                                <th>Sales</th>
                                <th>Area</th>
                                <th>NPWP</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($accounts) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($accounts as $account): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <span class="badge-badan-usaha">
                                                <?= htmlspecialchars($account['badan_usaha'] ?? 'PT') ?>
                                            </span>
                                        </td>
                                        <td><strong><?= htmlspecialchars($account['nama_pt']) ?></strong></td>
                                        <td><?= htmlspecialchars($account['nama_pic']) ?></td>
                                        <td><?= htmlspecialchars($account['no_hp_pic']) ?></td>
                                        <td><?= htmlspecialchars($account['email_pic']) ?></td>
                                        <td>
                                            <span class="badge-lead <?= strtolower($account['lead_source']) ?>">
                                                <?= htmlspecialchars($account['lead_source']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($account['sales_name'])): ?>
                                                <span class="badge-sales">
                                                    <i class="fas fa-user-tie"></i> <?= htmlspecialchars($account['sales_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($account['area'] ?? '-') ?></td>
                                        <td>
                                            <?php if (!empty($account['npwp_file'])): ?>
                                                <a href="<?= htmlspecialchars($account['npwp_file']) ?>" target="_blank" class="btn-action detail">
                                                    <i class="fas fa-file"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailAccount(<?= htmlspecialchars(json_encode($account)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($isDirektur || canEdit('account_management')): ?>
                                                    <button class="btn-action edit" onclick="editAccount(<?= htmlspecialchars(json_encode($account)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if ($isDirektur || canDelete('account_management')): ?>
                                                    <button class="btn-action delete" onclick="deleteAccount(<?= $account['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data account
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
    MODAL TAMBAH / EDIT
    ============================================ -->
    <div class="modal fade" id="modalAccount" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formAccount">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Badan Usaha <span class="text-danger">*</span></label>
                                <select name="badan_usaha" id="badan_usaha" class="form-select" required>
                                    <option value="">Pilih Badan Usaha</option>
                                    <option value="PT">PT</option>
                                    <option value="CV">CV</option>
                                    <option value="Perorangan">Perorangan</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama PT/Perusahaan <span class="text-danger">*</span></label>
                                <input type="text" name="nama_pt" id="nama_pt" class="form-control" placeholder="Masukkan nama PT/perusahaan" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bidang Usaha <span class="text-danger">*</span></label>
                                <select name="bidang_usaha" id="bidang_usaha" class="form-select" required>
                                    <option value="">Pilih Bidang Usaha</option>
                                    <option value="Mining">Mining</option>
                                    <option value="Construction">Construction</option>
                                    <option value="Agriculture">Agriculture</option>
                                    <option value="Forestry">Forestry</option>
                                    <option value="Oil & Gas">Oil & Gas</option>
                                    <option value="Industrial">Industrial</option>
                                    <option value="Property">Property</option>
                                    <option value="Trading">Trading</option>
                                    <option value="Services">Services</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Area <span class="text-danger">*</span></label>
                                <input type="text" name="area" id="area" class="form-control" placeholder="Contoh: Jakarta, Surabaya, Kalimantan" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Alamat <span class="text-danger">*</span></label>
                            <textarea name="alamat" id="alamat" class="form-control" rows="2" placeholder="Masukkan alamat lengkap" required></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">NPWP <span class="optional">(Optional)</span></label>
                                <input type="text" name="npwp" id="npwp" class="form-control" placeholder="Contoh: 12.345.678.9-012.000">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Upload NPWP <span class="optional">(Optional)</span></label>
                                <input type="file" name="npwp_file" id="npwp_file" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.pdf">
                                <small class="text-muted">Format: JPG, PNG, PDF | Maks: 2MB</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama PIC <span class="text-danger">*</span></label>
                                <input type="text" name="nama_pic" id="nama_pic" class="form-control" placeholder="Masukkan nama PIC" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">No Handphone PIC <span class="text-danger">*</span></label>
                                <input type="text" name="no_hp_pic" id="no_hp_pic" class="form-control" placeholder="Contoh: 08123456789" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email PIC <span class="text-danger">*</span></label>
                                <input type="email" name="email_pic" id="email_pic" class="form-control" placeholder="pic@email.com" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lead Source <span class="text-danger">*</span></label>
                                <select name="lead_source" id="lead_source" class="form-select" required>
                                    <option value="">Pilih Lead Source</option>
                                    <option value="Call">Call</option>
                                    <option value="Chat">Chat</option>
                                    <option value="Meeting">Meeting</option>
                                    <option value="Canvasing">Canvasing</option>
                                    <option value="Referensi">Referensi</option>
                                    <option value="Website">Website</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Input Sales</label>
                                <?php if ($userRole === 'sales'): ?>
                                    <input type="hidden" name="sales_id" value="<?= $userId ?>">
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($fullName) ?> (Sales)" disabled>
                                    <small class="text-muted">Sales otomatis sesuai akun Anda</small>
                                <?php else: ?>
                                    <select name="sales_id" id="sales_id" class="form-select">
                                        <option value="">-- Pilih Sales / Direktur --</option>
                                        <?php 
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
                                        
                                        foreach ($salesUsers as $u): 
                                            $roleLabel = $roleLabels[$u['role']] ?? ucfirst(str_replace('_', ' ', $u['role']));
                                        ?>
                                            <option value="<?= $u['id'] ?>">
                                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($roleLabel) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$hasSales): ?>
                                        <small class="text-warning">
                                            <i class="fas fa-exclamation-triangle"></i> Belum ada data Sales atau Direktur. 
                                            <a href="data_user.php" target="_blank">Tambah di Data User</a>
                                        </small>
                                    <?php endif; ?>
                                <?php endif; ?>
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
    MODAL DETAIL
    ============================================ -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-building" style="color:#ffd700;"></i> Detail Account</h5>
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
            <a href="account_management.php" class="nav-item active">
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
        
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-item">
                <i class="fas fa-box nav-icon"></i>
                <span class="nav-label">Produk</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('delivery_order')): ?>
            <a href="#" class="nav-item">
                <i class="fas fa-tractor nav-icon"></i>
                <span class="nav-label">Delivery Order</span>
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
        function detailAccount(data) {
            var salesName = data.sales_name || '-';
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Badan Usaha</div>
                    <div class="detail-value">
                        <span class="badge-badan-usaha">${data.badan_usaha || 'PT'}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Nama PT/Perusahaan</div>
                    <div class="detail-value"><strong>${data.nama_pt}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Bidang Usaha</div>
                    <div class="detail-value">${data.bidang_usaha}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Alamat</div>
                    <div class="detail-value">${data.alamat}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Area</div>
                    <div class="detail-value">${data.area || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">NPWP</div>
                    <div class="detail-value">${data.npwp || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">File NPWP</div>
                    <div class="detail-value">
                        ${data.npwp_file ? `<a href="${data.npwp_file}" target="_blank"><i class="fas fa-file"></i> Lihat File</a>` : '-'}
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Nama PIC</div>
                    <div class="detail-value">${data.nama_pic}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">No Handphone PIC</div>
                    <div class="detail-value">${data.no_hp_pic}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Email PIC</div>
                    <div class="detail-value">${data.email_pic}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Lead Source</div>
                    <div class="detail-value">
                        <span class="badge-lead ${data.lead_source.toLowerCase()}">${data.lead_source}</span>
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Sales</div>
                    <div class="detail-value">${salesName}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tanggal Dibuat</div>
                    <div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Terakhir Update</div>
                    <div class="detail-value">${new Date(data.updated_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }
        
        function editAccount(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Account';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('badan_usaha').value = data.badan_usaha || 'PT';
            document.getElementById('nama_pt').value = data.nama_pt;
            document.getElementById('alamat').value = data.alamat;
            document.getElementById('area').value = data.area || '';
            document.getElementById('npwp').value = data.npwp || '';
            document.getElementById('nama_pic').value = data.nama_pic;
            document.getElementById('no_hp_pic').value = data.no_hp_pic;
            document.getElementById('email_pic').value = data.email_pic;
            document.getElementById('lead_source').value = data.lead_source;
            document.getElementById('bidang_usaha').value = data.bidang_usaha;
            document.getElementById('sales_id').value = data.sales_id || '';
            
            document.getElementById('npwp_file').required = false;
            
            var modal = new bootstrap.Modal(document.getElementById('modalAccount'));
            modal.show();
        }
        
        document.getElementById('modalAccount').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formAccount').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Account';
            document.getElementById('npwp_file').required = false;
        });
        
        function deleteAccount(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>