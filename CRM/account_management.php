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
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();
$menuNames = array_column($userMenus, 'module_name');

// ============================================
// CEK ROLE DIREKTUR (untuk akses penuh)
// ============================================
$userRole = $_SESSION['role'] ?? 'user';
$direkturRoles = ['direktur_utama', 'direktur_sales', 'direktur_operasional'];
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
    echo '<th>Jabatan PIC</th>';
    echo '<th>No Handphone PIC</th>';
    echo '<th>Email PIC</th>';
    echo '<th>Lead Source</th>';
    echo '<th>Nama Referensi</th>';
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
        echo '<td>' . htmlspecialchars($account['jabatan_pic'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars($account['no_hp_pic']) . '</td>';
        echo '<td>' . htmlspecialchars($account['email_pic']) . '</td>';
        echo '<td>' . htmlspecialchars($account['lead_source']) . '</td>';
        echo '<td>' . htmlspecialchars($account['nama_referensi'] ?? '-') . '</td>';
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
// TAMBAHKAN KOLOM KE TABEL accounts (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS sales_id INT NULL");
    $db->exec("ALTER TABLE accounts ADD INDEX idx_sales_id (sales_id)");
} catch(PDOException $e) {}

try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS area VARCHAR(100) NULL");
} catch(PDOException $e) {}

try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS badan_usaha VARCHAR(50) NULL DEFAULT 'PT'");
} catch(PDOException $e) {}

try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS nama_referensi VARCHAR(100) NULL");
} catch(PDOException $e) {}

// ============================================
// TAMBAHKAN KOLOM jabatan_pic KE TABEL accounts (jika belum ada)
// ============================================
try {
    $db->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS jabatan_pic VARCHAR(100) NULL");
} catch(PDOException $e) {}

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
    $where .= " AND (a.nama_pt LIKE ? OR a.alamat LIKE ? OR a.nama_pic LIKE ? OR a.email_pic LIKE ? OR u.full_name LIKE ? OR a.nama_referensi LIKE ? OR a.jabatan_pic LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
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

// ============================================
// FUNGSI CEK APAKAH SALES BISA EDIT NPWP
// ============================================
function canSalesEditNPWP($db, $account_id, $userId) {
    // Cek apakah account milik sales ini
    $stmt = $db->prepare("SELECT sales_id, npwp FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
    
    if (!$account) return false;
    
    // Jika account bukan milik sales ini
    if ($account['sales_id'] != $userId) return false;
    
    // Jika NPWP sudah terisi (tidak NULL dan tidak kosong), sales tidak bisa edit
    if (!empty($account['npwp']) && trim($account['npwp']) !== '') {
        return false;
    }
    
    // Sales hanya bisa edit NPWP jika NPWP masih kosong
    return true;
}

// Proses tambah account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        // Sales dan Direktur bisa tambah
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
        $jabatan_pic = bersihkan($_POST['jabatan_pic']);
        $no_hp_pic = bersihkan($_POST['no_hp_pic']);
        $email_pic = bersihkan($_POST['email_pic']);
        $lead_source = bersihkan($_POST['lead_source']);
        $bidang_usaha = bersihkan($_POST['bidang_usaha']);
        $nama_referensi = !empty($_POST['nama_referensi']) ? bersihkan($_POST['nama_referensi']) : NULL;
        
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
        if (empty($jabatan_pic)) $errors[] = 'Jabatan PIC wajib diisi!';
        if (empty($no_hp_pic)) $errors[] = 'No Handphone PIC wajib diisi!';
        if (empty($email_pic)) $errors[] = 'Email PIC wajib diisi!';
        if (empty($lead_source)) $errors[] = 'Lead Source wajib dipilih!';
        if (empty($bidang_usaha)) $errors[] = 'Bidang Usaha wajib dipilih!';
        
        // Validasi jika lead source = Referensi, nama_referensi wajib diisi
        if ($lead_source === 'Referensi' && empty($nama_referensi)) {
            $errors[] = 'Nama Referensi wajib diisi jika Lead Source = Referensi!';
        }
        
        // ============================================
        // VALIDASI: Jika NPWP diisi, file NPWP WAJIB diupload
        // ============================================
        if (!empty($npwp) && empty($_FILES['npwp_file']['name'])) {
            $errors[] = 'Jika mengisi NPWP, Anda wajib upload file NPWP!';
        }
        
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
            $stmt = $db->prepare("INSERT INTO accounts (badan_usaha, nama_pt, alamat, area, npwp, npwp_file, nama_pic, jabatan_pic, no_hp_pic, email_pic, lead_source, bidang_usaha, sales_id, nama_referensi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $npwp_file, $nama_pic, $jabatan_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id, $nama_referensi]);
            setFlash('Data account berhasil ditambahkan!', 'success');
            redirect('account_management.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $isSales = ($userRole === 'sales');
        
        // Cek apakah sales bisa edit NPWP saja
        if ($isSales) {
            // Sales hanya bisa edit jika NPWP masih kosong
            if (!canSalesEditNPWP($db, $id, $userId)) {
                setFlash('Anda tidak memiliki akses untuk mengedit account ini! (NPWP sudah terisi atau bukan milik Anda)', 'danger');
                redirect('account_management.php');
            }
            
            // Sales hanya bisa update NPWP saja
            $npwp = bersihkan($_POST['npwp']);
            
            // ============================================
            // VALIDASI: Jika NPWP diisi, file NPWP WAJIB diupload
            // ============================================
            if (!empty($npwp) && empty($_FILES['npwp_file']['name'])) {
                setFlash('Jika mengisi NPWP, Anda wajib upload file NPWP!', 'danger');
                redirect('account_management.php');
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
                    setFlash('Format file NPWP tidak didukung! (JPG, PNG, PDF)', 'danger');
                    redirect('account_management.php');
                }
            }
            
            // Update hanya NPWP dan file NPWP
            if (!empty($npwp_file)) {
                $stmt = $db->prepare("UPDATE accounts SET npwp = ?, npwp_file = ? WHERE id = ? AND sales_id = ?");
                $stmt->execute([$npwp, $npwp_file, $id, $userId]);
            } else {
                $stmt = $db->prepare("UPDATE accounts SET npwp = ? WHERE id = ? AND sales_id = ?");
                $stmt->execute([$npwp, $id, $userId]);
            }
            
            setFlash('NPWP berhasil diupdate!', 'success');
            redirect('account_management.php');
            
        } else {
            // Direktur / Admin / IT Support bisa edit semua
            if (!$isDirektur && !canEdit('account_management')) {
                setFlash('Anda tidak memiliki akses untuk mengedit account!', 'danger');
                redirect('account_management.php');
            }
            
            $badan_usaha = bersihkan($_POST['badan_usaha']);
            $nama_pt = bersihkan($_POST['nama_pt']);
            $alamat = bersihkan($_POST['alamat']);
            $area = bersihkan($_POST['area']);
            $npwp = bersihkan($_POST['npwp']);
            $nama_pic = bersihkan($_POST['nama_pic']);
            $jabatan_pic = bersihkan($_POST['jabatan_pic']);
            $no_hp_pic = bersihkan($_POST['no_hp_pic']);
            $email_pic = bersihkan($_POST['email_pic']);
            $lead_source = bersihkan($_POST['lead_source']);
            $bidang_usaha = bersihkan($_POST['bidang_usaha']);
            $sales_id = !empty($_POST['sales_id']) ? (int)$_POST['sales_id'] : NULL;
            $nama_referensi = !empty($_POST['nama_referensi']) ? bersihkan($_POST['nama_referensi']) : NULL;
            
            $errors = [];
            if (empty($badan_usaha)) $errors[] = 'Badan Usaha wajib dipilih!';
            if (empty($nama_pt)) $errors[] = 'Nama PT/Perusahaan wajib diisi!';
            if (empty($alamat)) $errors[] = 'Alamat wajib diisi!';
            if (empty($area)) $errors[] = 'Area wajib diisi!';
            if (empty($nama_pic)) $errors[] = 'Nama PIC wajib diisi!';
            if (empty($jabatan_pic)) $errors[] = 'Jabatan PIC wajib diisi!';
            if (empty($no_hp_pic)) $errors[] = 'No Handphone PIC wajib diisi!';
            if (empty($email_pic)) $errors[] = 'Email PIC wajib diisi!';
            if (empty($lead_source)) $errors[] = 'Lead Source wajib dipilih!';
            if (empty($bidang_usaha)) $errors[] = 'Bidang Usaha wajib dipilih!';
            
            // Validasi jika lead source = Referensi, nama_referensi wajib diisi
            if ($lead_source === 'Referensi' && empty($nama_referensi)) {
                $errors[] = 'Nama Referensi wajib diisi jika Lead Source = Referensi!';
            }
            
            // ============================================
            // VALIDASI: Jika NPWP diisi, file NPWP WAJIB diupload
            // ============================================
            if (!empty($npwp) && empty($_FILES['npwp_file']['name']) && empty($_POST['existing_npwp_file'])) {
                $errors[] = 'Jika mengisi NPWP, Anda wajib upload file NPWP!';
            }
            
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
                    $stmt = $db->prepare("UPDATE accounts SET badan_usaha = ?, nama_pt = ?, alamat = ?, area = ?, npwp = ?, npwp_file = ?, nama_pic = ?, jabatan_pic = ?, no_hp_pic = ?, email_pic = ?, lead_source = ?, bidang_usaha = ?, sales_id = ?, nama_referensi = ? WHERE id = ?");
                    $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $npwp_file, $nama_pic, $jabatan_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id, $nama_referensi, $id]);
                } else {
                    $stmt = $db->prepare("UPDATE accounts SET badan_usaha = ?, nama_pt = ?, alamat = ?, area = ?, npwp = ?, nama_pic = ?, jabatan_pic = ?, no_hp_pic = ?, email_pic = ?, lead_source = ?, bidang_usaha = ?, sales_id = ?, nama_referensi = ? WHERE id = ?");
                    $stmt->execute([$badan_usaha, $nama_pt, $alamat, $area, $npwp, $nama_pic, $jabatan_pic, $no_hp_pic, $email_pic, $lead_source, $bidang_usaha, $sales_id, $nama_referensi, $id]);
                }
                setFlash('Data account berhasil diupdate!', 'success');
                redirect('account_management.php');
            } else {
                setFlash(implode('<br>', $errors), 'danger');
            }
        }
    }
    
    if ($action === 'delete') {
        // Sales TIDAK BISA menghapus
        if ($userRole === 'sales') {
            setFlash('Anda tidak memiliki akses untuk menghapus account!', 'danger');
            redirect('account_management.php');
        }
        
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

// ============================================
// FUNGSI CEK APAKAH SALES BISA EDIT ACCOUNT
// ============================================
function canSalesEdit($db, $account_id, $userId) {
    $stmt = $db->prepare("SELECT sales_id, npwp FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch();
    
    if (!$account) return false;
    if ($account['sales_id'] != $userId) return false;
    if (!empty($account['npwp']) && trim($account['npwp']) !== '') return false;
    
    return true;
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
            background: #0e1a2b; /* Deep Navy Blue Modern */
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
        .stat-card .stat-icon.purple { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
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
        
        .btn-action.edit-npwp {
            background: rgba(241, 196, 15, 0.12);
            color: #d4a017;
        }
        .btn-action.edit-npwp:hover { background: rgba(241, 196, 15, 0.2); }
        
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
            color: #0e1a2b;
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
            color: #0e1a2b;
            font-size: 13px;
            word-break: break-word;
        }
        
        .referensi-field {
            display: none;
        }
        
        .referensi-field.show {
            display: block;
        }

        /* ---- MOBILE TOGGLE ---- */
        .mobile-toggle { 
            display: none; 
        }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; }
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

        .breadcrumb {
            background: transparent;
            padding: 0;
            margin: 0;
            font-size: 13px;
        }
        .breadcrumb-item a {
            color: #2980b9;
            text-decoration: none;
        }
        .breadcrumb-item a:hover {
            color: #ffd700;
        }
        .breadcrumb-item.active {
            color: #0e1a2b;
            font-weight: 600;
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
            <a href="account_management.php" class="nav-item active"><i class="fas fa-building"></i> Account</a>
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
                    <h4><span><i class="fas fa-building" style="color:#ffd700;"></i></span> Account Management</h4>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="account_management.php?export=excel" class="btn btn-success-custom">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <?php if ($userRole === 'sales' || $isDirektur || canAdd('account_management')): ?>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAccount">
                        <i class="fas fa-plus"></i> Tambah
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-building"></i></div>
                <div class="stat-number"><?= number_format($totalAccounts) ?></div>
                <div class="stat-label">Total Account</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-phone"></i></div>
                <div class="stat-number"><?= number_format($leadCall) ?></div>
                <div class="stat-label">Lead Call</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-people-arrows"></i></div>
                <div class="stat-number"><?= number_format($leadCanvasing) ?></div>
                <div class="stat-label">Lead Canvasing</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><i class="fas fa-globe"></i></div>
                <div class="stat-number"><?= number_format($leadWebsite) ?></div>
                <div class="stat-label">Lead Website</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Account</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 220px;">
                    <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="account_management.php" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
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
                                <th>Badan Usaha</th>
                                <th>Nama PT/Perusahaan</th>
                                <th>PIC</th>
                                <th>Jabatan PIC</th>
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
                                    <?php 
                                    $isSalesOwner = ($userRole === 'sales' && $account['sales_id'] == $userId);
                                    $canEditNPWP = $isSalesOwner && (empty($account['npwp']) || trim($account['npwp']) === '');
                                    ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <span class="badge-badan-usaha">
                                                <?= htmlspecialchars($account['badan_usaha'] ?? 'PT') ?>
                                            </span>
                                        </td>
                                        <td><strong><?= htmlspecialchars($account['nama_pt']) ?></strong></td>
                                        <td><?= htmlspecialchars($account['nama_pic']) ?></td>
                                        <td><?= htmlspecialchars($account['jabatan_pic'] ?? '-') ?></td>
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
                                            <?php if (!empty($account['npwp'])): ?>
                                                <span class="text-success"><i class="fas fa-check-circle"></i> Terisi</span>
                                                <?php if (!empty($account['npwp_file'])): ?>
                                                    <a href="<?= htmlspecialchars($account['npwp_file']) ?>" target="_blank" class="btn-action detail" style="width:20px;height:20px;font-size:10px;">
                                                        <i class="fas fa-file"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-warning">Kosong</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailAccount(<?= htmlspecialchars(json_encode($account)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($userRole === 'sales'): ?>
                                                    <?php if ($canEditNPWP): ?>
                                                        <button class="btn-action edit-npwp" onclick="editNPWP(<?= htmlspecialchars(json_encode($account)) ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
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
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4 text-muted">
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

    </div>

    <!-- MODALS -->
    <!-- Modal Tambah / Edit -->
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
                                    <option value="Oil and Gas">Oil and Gas</option>
                                    <option value="Industrial">Industrial</option>
                                    <option value="Rent Company">Rent Company</option>
                                    <option value="Trading">Trading</option>
                                    <option value="Logistic">Logistic</option>
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
                                <label class="form-label">Upload NPWP <span class="text-danger" id="npwpFileRequired" style="display:none;">*</span></label>
                                <input type="file" name="npwp_file" id="npwp_file" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.pdf">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama PIC <span class="text-danger">*</span></label>
                                <input type="text" name="nama_pic" id="nama_pic" class="form-control" placeholder="Masukkan nama PIC" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Jabatan PIC <span class="text-danger">*</span></label>
                                <input type="text" name="jabatan_pic" id="jabatan_pic" class="form-control" placeholder="Contoh: Manager, Direktur, Supervisor" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">No Handphone PIC <span class="text-danger">*</span></label>
                                <input type="text" name="no_hp_pic" id="no_hp_pic" class="form-control" placeholder="Contoh: 08123456789" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email PIC <span class="text-danger">*</span></label>
                                <input type="email" name="email_pic" id="email_pic" class="form-control" placeholder="pic@email.com" required>
                            </div>
                        </div>
                        
                        <div class="row">
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
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Input Sales</label>
                                <?php if ($userRole === 'sales'): ?>
                                    <input type="hidden" name="sales_id" value="<?= $userId ?>">
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($fullName) ?> (Sales)" disabled>
                                    <small class="text-muted">Sales otomatis sesuai akun Anda</small>
                                <?php else: ?>
                                    <select name="sales_id" id="sales_id" class="form-select">
                                        <option value="">-- Pilih Sales --</option>
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
                        
                        <!-- Field Nama Referensi (muncul jika Lead Source = Referensi) -->
                        <div class="row referensi-field" id="referensiField">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nama Referensi <span class="text-danger" id="referensiRequired">*</span></label>
                                <input type="text" name="nama_referensi" id="nama_referensi" class="form-control" placeholder="Masukkan nama orang yang mereferensikan">
                                <small class="text-muted">Masukkan nama lengkap orang yang memberikan referensi</small>
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

    <!-- Modal Edit NPWP (Khusus Sales) -->
    <div class="modal fade" id="modalEditNPWP" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f39c12, #e67e22);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-edit"></i> Edit NPWP
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="editNPWPId" value="">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Info:</strong> Anda hanya bisa mengisi NPWP jika belum terisi.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Nama PT/Perusahaan</label>
                            <input type="text" id="editNPWPNama" class="form-control" readonly style="background: #f8f9fa;">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">NPWP <span class="text-danger">*</span></label>
                            <input type="text" name="npwp" id="editNPWPInput" class="form-control" placeholder="Contoh: 12.345.678.9-012.000" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Upload File NPWP <span class="text-danger" id="editNPWPFileRequired" style="display:none;">*</span></label>
                            <input type="file" name="npwp_file" id="editNPWPFile" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.pdf">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Update NPWP
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Detail -->
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

    <!-- Modal Delete -->
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

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // SHOW/HIDE REFERENSI FIELD
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var leadSource = document.getElementById('lead_source');
            var referensiField = document.getElementById('referensiField');
            var referensiRequired = document.getElementById('referensiRequired');
            var namaReferensi = document.getElementById('nama_referensi');
            
            function toggleReferensiField() {
                if (leadSource.value === 'Referensi') {
                    referensiField.classList.add('show');
                    referensiRequired.style.display = 'inline';
                    namaReferensi.required = true;
                } else {
                    referensiField.classList.remove('show');
                    referensiRequired.style.display = 'none';
                    namaReferensi.required = false;
                    namaReferensi.value = '';
                }
            }
            
            toggleReferensiField();
            leadSource.addEventListener('change', toggleReferensiField);
        });

        // ============================================
        // SHOW/HIDE NPWP FILE REQUIRED
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var npwpInput = document.getElementById('npwp');
            var npwpFileRequired = document.getElementById('npwpFileRequired');
            var npwpFile = document.getElementById('npwp_file');
            
            function toggleNPWPFileRequired() {
                if (npwpInput.value.trim() !== '') {
                    npwpFileRequired.style.display = 'inline';
                    npwpFile.required = true;
                } else {
                    npwpFileRequired.style.display = 'none';
                    npwpFile.required = false;
                }
            }
            
            toggleNPWPFileRequired();
            npwpInput.addEventListener('input', toggleNPWPFileRequired);
        });

        // ============================================
        // DETAIL ACCOUNT
        // ============================================
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
                    <div class="detail-label">Jabatan PIC</div>
                    <div class="detail-value">${data.jabatan_pic || '-'}</div>
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
                    <div class="detail-label">Nama Referensi</div>
                    <div class="detail-value">${data.nama_referensi || '-'}</div>
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
            document.getElementById('jabatan_pic').value = data.jabatan_pic || '';
            document.getElementById('no_hp_pic').value = data.no_hp_pic;
            document.getElementById('email_pic').value = data.email_pic;
            document.getElementById('lead_source').value = data.lead_source;
            document.getElementById('bidang_usaha').value = data.bidang_usaha;
            document.getElementById('sales_id').value = data.sales_id || '';
            document.getElementById('nama_referensi').value = data.nama_referensi || '';
            
            var existingInput = document.createElement('input');
            existingInput.type = 'hidden';
            existingInput.name = 'existing_npwp_file';
            existingInput.value = data.npwp_file || '';
            document.getElementById('formAccount').appendChild(existingInput);
            
            document.getElementById('npwp_file').required = false;
            
            var event = new Event('change');
            document.getElementById('lead_source').dispatchEvent(event);
            
            var npwpEvent = new Event('input');
            document.getElementById('npwp').dispatchEvent(npwpEvent);
            
            var modal = new bootstrap.Modal(document.getElementById('modalAccount'));
            modal.show();
        }
        
        function editNPWP(data) {
            document.getElementById('editNPWPId').value = data.id;
            document.getElementById('editNPWPNama').value = data.nama_pt;
            document.getElementById('editNPWPInput').value = data.npwp || '';
            document.getElementById('editNPWPFile').value = '';
            
            var npwpEvent = new Event('input');
            document.getElementById('editNPWPInput').dispatchEvent(npwpEvent);
            
            var modal = new bootstrap.Modal(document.getElementById('modalEditNPWP'));
            modal.show();
        }
        
        document.getElementById('modalAccount').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formAccount').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Account';
            document.getElementById('npwp_file').required = false;
            document.getElementById('nama_referensi').value = '';
            document.getElementById('nama_referensi').required = false;
            document.getElementById('referensiField').classList.remove('show');
            
            var existingInput = document.querySelector('input[name="existing_npwp_file"]');
            if (existingInput) {
                existingInput.remove();
            }
        });
        
        function deleteAccount(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>