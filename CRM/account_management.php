<?php
// Debug mode
error_reporting(E_ALL);
ini_set('display_errors', 1);
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
:root{
 --bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);
 --text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--cyan:#22d3ee;
 --green:#34d399;--red:#fb7185;--amber:#fbbf24;--purple:#a78bfa;
}
*{box-sizing:border-box;margin:0;padding:0}
html{background:#060b18}
body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);color:var(--text);min-height:100vh;overflow-x:hidden;padding-bottom:0}
a{color:inherit}
.app{min-height:100vh}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:50}
.brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.brand img{width:38px;height:38px;object-fit:contain}.brand strong{font-size:17px;letter-spacing:-.4px}.brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}
.top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5)}
.shell{display:flex;min-height:calc(100vh - 72px)}
.rail{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:40;overflow-y:auto}.rail::-webkit-scrollbar{width:4px}.rail::-webkit-scrollbar-thumb{background:rgba(96,165,250,.25);border-radius:10px}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.rail a{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600}.rail a i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.rail a:hover,.rail a.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.rail a.active i{color:#60a5fa}.rail .spacer{flex:1;min-height:20px}.rail-user{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.rail-user .mini-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.rail-user strong{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rail-user span{display:block;font-size:8px;color:#66758f;margin-top:2px}.rail .logout-link{color:#8794aa}.rail .logout-link:hover{color:#fb7185;background:rgba(251,113,133,.08);box-shadow:none}
.content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px}
.hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.hero h1{font-size:26px;letter-spacing:-1px;font-weight:800;margin:0}.hero p{font-size:12px;color:var(--muted);margin-top:7px}.filters{display:flex;gap:8px;align-items:center}.filter{height:38px;border:1px solid var(--line);background:rgba(10,17,33,.85);color:#cdd7e7;border-radius:11px;padding:0 12px;font-size:11px;outline:none}.filter option{background:#0b1222}.btn-add{height:38px;border:0;border-radius:11px;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:11px;font-weight:700;padding:0 15px;box-shadow:0 0 24px rgba(59,130,246,.25);display:inline-flex;align-items:center;justify-content:center;text-decoration:none}.btn-add:hover{color:#fff;transform:translateY(-1px);box-shadow:0 0 28px rgba(59,130,246,.35)}
.header-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn-export{height:38px;border:1px solid rgba(52,211,153,.25);border-radius:11px;background:rgba(52,211,153,.08);color:#6ee7b7;font-size:11px;font-weight:700;padding:0 14px;display:inline-flex;align-items:center;text-decoration:none}.btn-export:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.chart-card{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.chart-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.chart-card h6{height:58px;margin:0;padding:0 18px;display:flex;align-items:center;font-size:13px;font-weight:700;color:#f7f9ff;border-bottom:1px solid rgba(148,163,184,.10)}.chart-card h6 i{color:#60a5fa!important;margin-right:9px}.chart-wrapper{height:270px;width:100%;position:relative;padding:10px 16px 14px}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.card-custom .card-header-custom{padding:15px 18px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}.card-custom .card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:13px}.card-custom .card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-custom .card-body-custom{padding:0;overflow-x:auto}
.table-responsive{border-radius:0}.table-custom{margin-bottom:0;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1}.table-custom th{font-weight:700;font-size:9px;text-transform:uppercase;letter-spacing:.45px;color:#66758f;border-bottom:1px solid rgba(148,163,184,.10);padding:13px 14px;background:rgba(5,12,25,.48);white-space:nowrap}.table-custom td{padding:13px 14px;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07);color:#cbd5e1;background:transparent}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)}.table-custom tr:last-child td{border-bottom:none}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}.table-custom a:hover{color:#93c5fd!important}.text-muted{color:#64748b!important}
.badge-prospek,.badge-status-prospek{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-prospek.suspect{background:rgba(96,165,250,.10);color:#93c5fd;border-color:rgba(96,165,250,.15)}.badge-prospek.prospect{background:rgba(167,139,250,.10);color:#c4b5fd;border-color:rgba(167,139,250,.15)}.badge-prospek.hot-prospect{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}.badge-prospek.deal-prospek{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-prospek.lost-deal{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.badge-status-prospek.in-progress{background:rgba(34,211,238,.10);color:#67e8f9;border-color:rgba(34,211,238,.15)}.badge-status-prospek.completed{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-status-prospek.overdue{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.last-activity{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;background:rgba(96,165,250,.08);color:#93c5fd;border:1px solid rgba(96,165,250,.14);font-size:8px;font-weight:700;white-space:nowrap}
.btn-action{width:29px;height:29px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;transition:.2s;font-size:10px;cursor:pointer}.btn-action:hover{transform:translateY(-1px) scale(1.04)}.btn-action.detail{background:rgba(96,165,250,.10);color:#60a5fa;border-color:rgba(96,165,250,.12)}.btn-action.detail:hover{background:rgba(96,165,250,.18)}.btn-action.delete{background:rgba(251,113,133,.09);color:#fb7185;border-color:rgba(251,113,133,.12)}.btn-action.delete:hover{background:rgba(251,113,133,.16)}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}
.form-label{font-weight:600;font-size:11px;color:#aebbd0}.form-control,.form-select{border-radius:9px;padding:9px 11px;border:1px solid rgba(148,163,184,.16);background:#0a1427;color:#dbe5f5;font-size:11px;transition:.2s}.form-control::placeholder{color:#52627d}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55);box-shadow:0 0 0 3px rgba(59,130,246,.10);background:#0b172d;color:#fff}.form-control[readonly]{background:#0a1325;color:#7f8da5;cursor:not-allowed}.form-select option{background:#0b1222;color:#dbe5f5}.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-primary-custom i{margin-right:6px}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}.btn-danger{background:#dc3545!important;border:0;border-radius:9px;font-size:11px;font-weight:700}.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.detail-item{display:flex;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.08)}.detail-item:last-child{border-bottom:none}.detail-item .detail-label{font-weight:600;color:#6f7f98;width:160px;flex-shrink:0;font-size:10px}.detail-item .detail-value{color:#dbe5f5;font-size:10px;word-break:break-word}.leads-number-display{background:rgba(59,130,246,.08);border:1px solid rgba(96,165,250,.14);padding:10px 13px;border-radius:9px;font-weight:700;color:#60a5fa;text-align:center;font-size:14px;letter-spacing:.5px}
.modal-content{background:linear-gradient(145deg,#0c172b,#07101f);border:1px solid var(--line);border-radius:15px;color:#dbe5f5;box-shadow:0 24px 70px rgba(0,0,0,.45)}.modal-header{border-bottom:1px solid rgba(148,163,184,.10);padding:16px 20px}.modal-header .modal-title{font-weight:700;font-size:14px;color:#f7f9ff}.modal-header .modal-title i{color:#60a5fa!important;margin-right:8px}.modal-footer{border-top:1px solid rgba(148,163,184,.10);padding:12px 20px}.modal-body{padding:18px 20px}.btn-close{filter:invert(1) grayscale(1);opacity:.55}.btn-close:hover{opacity:1}.select2-container--default .select2-selection--single{height:40px;border-radius:9px;border:1px solid rgba(148,163,184,.16);background:#0a1427;color:#dbe5f5;padding:6px 10px;font-size:11px}.select2-container--default .select2-selection--single .select2-selection__rendered{color:#dbe5f5;line-height:26px}.select2-container--default .select2-selection--single .select2-selection__arrow{height:38px}.select2-container--default .select2-selection--single:focus{border-color:rgba(96,165,250,.55)}.select2-dropdown{background:#0b1426;border:1px solid rgba(148,163,184,.18);color:#dbe5f5}.select2-container--default .select2-search--dropdown .select2-search__field{background:#07101f;border:1px solid rgba(148,163,184,.16);color:#fff}.select2-container--default .select2-results__option{font-size:11px}.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#2563eb}.select2-container--default .select2-results__option[aria-selected=true]{background:#10203a;color:#fff}
.footer-text,.footer{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}.mobile-toggle{display:none}
@media(max-width:1050px){.chart-grid{grid-template-columns:1fr}.brand{min-width:190px}.content{padding:22px 20px 45px}}
@media(max-width:800px){.rail.open{display:flex;position:fixed;top:64px;bottom:0;left:0;width:245px}.topbar{padding:0 16px}.brand{min-width:0}.brand div{display:none}.mobile-toggle{display:inline-flex;width:36px;height:36px;border-radius:9px;border:1px solid var(--line);background:#0a1427;color:#60a5fa;align-items:center;justify-content:center;margin-right:8px}.content{margin-left:0;width:100%;padding:20px 14px 40px}.rail{display:none}.hero-row{align-items:flex-start;flex-direction:column}.filters{width:100%;flex-wrap:wrap}.filter{flex:1;min-width:130px}.header-actions{width:100%}.header-actions a,.header-actions button{flex:1}.card-custom .card-header-custom{align-items:flex-start}.card-custom .card-header-custom form{width:100%}.table-custom{font-size:9px}.table-custom th,.table-custom td{padding:10px 9px}}
@media(max-width:480px){.topbar{height:64px}.shell{min-height:calc(100vh - 64px)}.content{padding:18px 10px 35px}.hero h1{font-size:22px}.hero p{font-size:10px}.chart-wrapper{height:235px;padding:8px}.chart-card h6{height:52px;padding:0 14px}.card-custom .card-header-custom{padding:13px}.form-control,.form-select{font-size:10px}.detail-item{flex-direction:column}.detail-item .detail-label{width:100%;margin-bottom:3px}.topbar .mobile-toggle{display:inline-flex}}

/* =========================================================
   ACCOUNT MANAGEMENT — VISUAL PARITY WITH SALES ACTIVITY
   Presentation only. Existing PHP/DB/JS logic is untouched.
   ========================================================= */
.top-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}
.top-brand img{width:38px;height:38px;object-fit:contain}
.top-brand strong{font-size:17px;letter-spacing:-.4px}
.top-brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}
.top-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5);color:#fff}
.sidebar{width:245px;position:fixed;top:72px;bottom:0;left:0;height:auto;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:40;overflow-y:auto;box-shadow:none}
.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:rgba(96,165,250,.25);border-radius:10px}
.sidebar .brand{display:none!important}
.sidebar .rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}
.sidebar .nav-item{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex!important;align-items:center!important;gap:12px!important;text-decoration:none;transition:.2s;padding:0 13px!important;font-size:11px;font-weight:600;margin:0}
.sidebar .nav-item i{width:20px;text-align:center;font-size:14px;color:#6e7d97;margin:0}
.sidebar .nav-item:hover,.sidebar .nav-item.active{color:#fff!important;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06))!important;box-shadow:inset 2px 0 0 #60a5fa!important}
.sidebar .nav-item.active i{color:#60a5fa!important}
.sidebar-spacer{flex:1;min-height:20px}
.sidebar .user-profile{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}
.sidebar .user-profile .avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800;border:0}
.sidebar .user-profile .user-info .name{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sidebar .user-profile .user-info .role{display:block;font-size:8px;color:#66758f;margin-top:2px}
.sidebar .logout-btn{display:flex;align-items:center;gap:10px;text-align:left;color:#8794aa;background:transparent;border:0;margin-top:0;padding:0 13px;height:43px;font-size:11px;text-decoration:none}
.sidebar .logout-btn:hover{color:#fb7185;background:rgba(251,113,133,.08);box-shadow:none}
.main-content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px;min-height:calc(100vh - 72px)}
.page-header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px;flex-wrap:wrap}
.page-header h4{font-size:26px;letter-spacing:-1px;font-weight:800;margin:0;color:#f7f9ff!important}
.page-header h4 span,.page-header h4 span i{color:#60a5fa!important}
.page-header>div:last-child{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:14px}
.stat-card{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);padding:18px;transition:.25s;color:#eaf0f8}
.stat-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25);transform:translateY(-1px)}
.stat-card .stat-number{color:#f7f9ff;font-size:23px;font-weight:800}.stat-card .stat-label{color:#8290a5;font-size:11px}.stat-card .stat-icon{margin-bottom:10px}
.stat-card .stat-icon.gold{background:rgba(212,160,23,.12);color:#e0b53d}.stat-card .stat-icon.blue{background:rgba(59,130,246,.12);color:#60a5fa}.stat-card .stat-icon.green{background:rgba(34,197,94,.12);color:#4ade80}.stat-card .stat-icon.purple{background:rgba(168,85,247,.12);color:#c084fc}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s;color:#eaf0f8}
.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}
.card-custom .card-header-custom{padding:15px 18px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.card-custom .card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:13px}.card-custom .card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-custom .card-body-custom{padding:0;overflow-x:auto}
.table-responsive{border-radius:0}.table-custom{margin-bottom:0;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1}.table-custom th{font-weight:700;font-size:9px;text-transform:uppercase;letter-spacing:.45px;color:#66758f;border-bottom:1px solid rgba(148,163,184,.10);padding:13px 14px;background:rgba(5,12,25,.48);white-space:nowrap}.table-custom td{padding:13px 14px;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07);color:#cbd5e1;background:transparent}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)}.table-custom tr:last-child td{border-bottom:none}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}
.badge-prospek,.badge-status-prospek,.badge-badan-usaha,.badge-sales,.badge-lead{border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid rgba(255,255,255,.06)}
.badge-badan-usaha{display:inline-flex;align-items:center;padding:5px 9px;background:rgba(34,211,238,.08);color:#67e8f9;border-color:rgba(34,211,238,.14)}
.badge-sales{display:inline-flex;align-items:center;gap:6px;max-width:175px;padding:5px 10px;background:rgba(59,130,246,.10);color:#9fc5ff;border-color:rgba(96,165,250,.18);font-size:8px;overflow:hidden;text-overflow:ellipsis}
.badge-sales i{font-size:9px;color:#60a5fa}.badge-lead{display:inline-flex;align-items:center;padding:5px 9px}.badge-lead.call{background:rgba(59,130,246,.10);color:#93c5fd;border-color:rgba(96,165,250,.15)}.badge-lead.chat{background:rgba(34,211,153,.10);color:#6ee7b7}.badge-lead.meeting{background:rgba(167,139,250,.10);color:#c4b5fd}.badge-lead.canvasing{background:rgba(251,191,36,.10);color:#fcd34d}.badge-lead.referensi{background:rgba(34,211,238,.10);color:#67e8f9}.badge-lead.website{background:rgba(251,113,133,.10);color:#fb7185}
.btn-action{width:29px;height:29px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;transition:.2s;font-size:10px;cursor:pointer}.btn-action:hover{transform:translateY(-1px) scale(1.04)}.btn-action.detail{background:rgba(96,165,250,.10);color:#60a5fa;border-color:rgba(96,165,250,.12)}.btn-action.edit{background:rgba(167,139,250,.10);color:#c4b5fd;border-color:rgba(167,139,250,.12)}.btn-action.edit-npwp{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.14)}.btn-action.delete{background:rgba(251,113,133,.09);color:#fb7185;border-color:rgba(251,113,133,.12)}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}
.form-label{font-weight:600;font-size:11px;color:#aebbd0}.form-control,.form-select{border-radius:9px;padding:9px 11px;border:1px solid rgba(148,163,184,.16);background:#0a1427;color:#dbe5f5;font-size:11px;transition:.2s}.form-control::placeholder{color:#52627d}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55);box-shadow:0 0 0 3px rgba(59,130,246,.10);background:#0b172d;color:#fff}.form-control[readonly]{background:#0a1325;color:#7f8da5}.form-select option{background:#0b1222;color:#dbe5f5}.btn-primary-custom{height:38px;background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:11px;padding:0 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff;display:inline-flex;align-items:center;justify-content:center}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-primary-custom i{margin-right:6px}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}.btn-success-custom{height:38px;border:1px solid rgba(52,211,153,.25);border-radius:11px;background:rgba(52,211,153,.08);color:#6ee7b7;font-size:11px;font-weight:700;padding:0 14px;display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none}.btn-success-custom i{margin:0}.btn-success-custom:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}.btn-danger{background:#dc3545!important;border:0;border-radius:9px;font-size:11px;font-weight:700}
.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.detail-item{display:flex;padding:10px 0;border-bottom:1px solid rgba(148,163,184,.08)}.detail-item:last-child{border-bottom:none}.detail-item .detail-label{font-weight:600;color:#6f7f98;width:160px;flex-shrink:0;font-size:10px}.detail-item .detail-value{color:#dbe5f5;font-size:10px;word-break:break-word}.modal-content{background:linear-gradient(145deg,#0c172b,#07101f);border:1px solid var(--line);border-radius:15px;color:#dbe5f5;box-shadow:0 24px 70px rgba(0,0,0,.45)}.modal-header{border-bottom:1px solid rgba(148,163,184,.10);padding:16px 20px}.modal-header .modal-title{font-weight:700;font-size:14px;color:#f7f9ff}.modal-header .modal-title i{color:#60a5fa!important;margin-right:8px}.modal-footer{border-top:1px solid rgba(148,163,184,.10);padding:12px 20px}.modal-body{padding:18px 20px}.btn-close{filter:invert(1) grayscale(1);opacity:.55}.text-muted{color:#64748b!important}.text-success{color:#4ade80!important}.text-warning{color:#fbbf24!important}.footer-text{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}
.top-mobile-toggle{display:none;background:#0a1427;border:1px solid var(--line);color:#60a5fa;width:38px;height:38px;border-radius:9px;margin-right:8px;align-items:center;justify-content:center}
.mobile-toggle{display:none!important}
@media(max-width:1050px){.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.main-content{padding:22px 20px 45px}.page-header{align-items:flex-start}.page-header>div:last-child{justify-content:flex-start}}
@media(max-width:991px){.top-mobile-toggle{display:flex!important}.sidebar{transform:translateX(-100%)!important}.sidebar.open{transform:translateX(0)!important}.main-content{margin-left:0!important;width:100%!important;padding:92px 18px 24px!important}.page-header>div:last-child{width:100%!important}.sidebar{width:245px!important}}
@media(max-width:800px){.topbar{padding:0 16px}.top-brand{min-width:0}.top-brand>div{display:none}.top-mobile-toggle{display:flex!important}.sidebar{display:flex!important;top:64px!important;transform:translateX(-100%)!important}.sidebar.open{transform:translateX(0)!important}.main-content{padding:20px 14px 40px!important}.page-header{align-items:flex-start;flex-direction:column}.page-header>div:last-child{width:100%!important}.page-header>div:last-child a,.page-header>div:last-child button{flex:1}.table-custom{font-size:9px}.table-custom th,.table-custom td{padding:10px 9px}}
@media(max-width:480px){.topbar{height:64px!important}.top-brand strong{font-size:11px!important}.top-brand small{font-size:8px!important}.top-brand img{width:32px!important;height:32px!important}.main-content{padding:18px 10px 35px!important}.page-header h4{font-size:22px!important}.stat-grid{grid-template-columns:repeat(2,1fr)!important;gap:10px!important}.stat-card{padding:14px!important}.card-custom .card-header-custom{align-items:flex-start!important}.card-custom .card-header-custom form{width:100%!important}.card-custom .card-header-custom form input{width:100%!important}.table-custom{min-width:1050px!important}.detail-item{flex-direction:column}.detail-item .detail-label{width:100%;margin-bottom:3px}.top-mobile-toggle{display:flex!important}}


/* =========================================================
   FINAL POLISH — ACCOUNT MANAGEMENT
   Visual refinement only. No PHP / DB / JS logic changed.
   ========================================================= */
.topbar{padding:0 28px!important;height:72px!important}
.top-brand{min-width:245px!important}
.top-brand>div{line-height:1.05}
.top-brand strong{display:block;line-height:1.15}
.top-actions{gap:12px!important}

.main-content{padding:28px 30px 52px!important}
.page-header{min-height:48px;margin-bottom:20px!important;align-items:center!important}
.page-header>div:first-child{gap:12px!important}
.page-header h4{display:flex;align-items:center;gap:10px;font-size:25px!important;line-height:1.1}
.page-header h4 span{width:38px;height:38px;border-radius:11px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.15);display:inline-flex;align-items:center;justify-content:center}
.page-header h4 span i{font-size:15px!important}
.page-header>div:last-child{gap:8px!important}

.stat-grid{gap:14px!important;margin-bottom:18px!important}
.stat-card{position:relative;min-height:128px;padding:18px 19px!important;display:flex;flex-direction:column;justify-content:flex-end;overflow:hidden}
.stat-card:before{content:"";position:absolute;right:-30px;top:-38px;width:105px;height:105px;border-radius:50%;background:rgba(96,165,250,.035);pointer-events:none}
.stat-card .stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;margin:0 0 auto!important;font-size:14px;border:1px solid rgba(255,255,255,.04)}
.stat-card .stat-number{font-size:24px!important;line-height:1;margin-top:15px;margin-bottom:6px}
.stat-card .stat-label{font-size:10px!important;text-transform:uppercase;letter-spacing:.7px;font-weight:700;color:#70809b!important}

.card-custom{border-radius:16px!important}
.card-custom .card-header-custom{min-height:62px;padding:13px 17px!important}
.card-custom .card-header-custom h6{font-size:12px!important;letter-spacing:.1px}
.card-custom .card-header-custom form{display:flex;align-items:center;gap:7px!important}
.card-custom .card-header-custom form input{height:36px!important;width:230px!important}
.card-custom .card-header-custom form .btn{height:36px!important;display:inline-flex;align-items:center;justify-content:center}
.card-custom .card-body-custom{background:rgba(3,8,18,.12)}

.table-custom{font-size:10px!important;min-width:1120px}
.table-custom thead th{height:43px;padding:11px 13px!important;font-size:8.5px!important;letter-spacing:.6px!important}
.table-custom tbody td{height:54px;padding:10px 13px!important;font-size:10px!important}
.table-custom tbody tr{background:transparent!important}
.table-custom tbody tr:hover td{background:rgba(59,130,246,.045)!important}
.table-custom th:first-child,.table-custom td:first-child{width:48px;text-align:center}
.table-custom th:last-child,.table-custom td:last-child{width:92px;text-align:center}
.table-custom td:nth-child(3){font-weight:600;color:#e5ebf5}
.table-custom td:nth-child(6),.table-custom td:nth-child(7){color:#aebbd0}

.badge-badan-usaha,.badge-sales,.badge-lead{height:25px!important;align-items:center!important}
.badge-badan-usaha{padding:0 9px!important}
.badge-sales{padding:0 9px!important;max-width:155px!important}
.badge-lead{padding:0 9px!important}
.btn-action{width:30px!important;height:30px!important;border-radius:8px!important}

.text-success,.text-warning{font-size:9px;font-weight:700;white-space:nowrap}

.pagination{margin-bottom:0!important}
.card-footer{padding:12px 16px!important}

/* Modal consistency */
.modal-dialog{max-width:760px}
.modal-content{border-radius:16px!important}
.modal-header{min-height:56px;padding:14px 18px!important}
.modal-body{padding:20px!important}
.modal-footer{padding:12px 18px!important}
.modal-title{display:flex;align-items:center}

/* Desktop balance */
@media(min-width:1200px){
 .stat-card{min-height:132px}
 .table-custom th:nth-child(2){width:115px}
 .table-custom th:nth-child(3){min-width:190px}
 .table-custom th:nth-child(4){min-width:125px}
 .table-custom th:nth-child(5){min-width:110px}
 .table-custom th:nth-child(6){min-width:120px}
 .table-custom th:nth-child(7){min-width:175px}
 .table-custom th:nth-child(8){min-width:120px}
 .table-custom th:nth-child(9){min-width:135px}
 .table-custom th:nth-child(10){min-width:85px}
 .table-custom th:nth-child(11){min-width:95px}
}

@media(max-width:1050px){
 .main-content{padding:24px 20px 45px!important}
 .page-header{align-items:flex-start!important}
 .stat-grid{gap:12px!important}
}
@media(max-width:800px){
 .main-content{padding:20px 14px 40px!important}
 .page-header{gap:14px!important}
 .page-header h4{font-size:22px!important}
 .page-header>div:last-child{gap:7px!important}
 .stat-card{min-height:116px;padding:15px!important}
 .stat-card .stat-number{font-size:21px!important}
 .card-custom .card-header-custom{padding:12px 13px!important}
 .card-custom .card-header-custom form{width:100%!important}
 .card-custom .card-header-custom form input{width:100%!important}
 .table-custom{min-width:1120px!important}
}
@media(max-width:480px){
 .main-content{padding:17px 10px 34px!important}
 .page-header h4{font-size:20px!important}
 .page-header h4 span{width:34px;height:34px;border-radius:9px}
 .stat-grid{gap:9px!important}
 .stat-card{min-height:110px;padding:13px!important;border-radius:14px!important}
 .stat-card .stat-icon{width:34px;height:34px;border-radius:9px}
 .stat-card .stat-number{font-size:19px!important;margin-top:11px}
 .stat-card .stat-label{font-size:8px!important}
 .topbar{padding:0 12px!important}
}


/* FINAL SCROLLBAR — SAME DARK CRM BACKGROUND */
html, body {
    scrollbar-color: rgba(96,165,250,.32) #060b18;
    scrollbar-width: thin;
}
html::-webkit-scrollbar, body::-webkit-scrollbar { width: 7px; height: 7px; }
html::-webkit-scrollbar-track, body::-webkit-scrollbar-track { background: #060b18; }
html::-webkit-scrollbar-thumb, body::-webkit-scrollbar-thumb {
    background: rgba(96,165,250,.30);
    border-radius: 999px;
    border: 1px solid rgba(6,11,24,.9);
}
html::-webkit-scrollbar-thumb:hover, body::-webkit-scrollbar-thumb:hover { background: rgba(96,165,250,.48); }
.card-custom .card-body-custom::-webkit-scrollbar, .table-responsive::-webkit-scrollbar { height: 7px; }
.card-custom .card-body-custom::-webkit-scrollbar-track, .table-responsive::-webkit-scrollbar-track { background: #060b18; }
.card-custom .card-body-custom::-webkit-scrollbar-thumb, .table-responsive::-webkit-scrollbar-thumb {
    background: rgba(96,165,250,.28);
    border-radius: 999px;
}
.card-custom .card-body-custom::-webkit-scrollbar-thumb:hover, .table-responsive::-webkit-scrollbar-thumb:hover { background: rgba(96,165,250,.46); }
.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-track { background: #060b18; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(96,165,250,.24); border-radius: 999px; }

/* =========================================================
   FINAL CRM STYLE — EXACT VISUAL CONSISTENCY WITH SALES ACTIVITY
   Presentation only. PHP / DB / JS logic untouched.
   ========================================================= */

/* Global canvas */
html,body{background:#060b18!important;color:#f7f9ff!important}
body{font-family:Inter,Arial,sans-serif!important;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c)!important;overflow-x:hidden!important}

/* Topbar */
.topbar{height:72px!important;padding:0 26px!important;gap:24px!important;border-bottom:1px solid var(--line)!important;background:rgba(5,9,20,.88)!important;backdrop-filter:blur(18px)!important;position:sticky!important;top:0!important;z-index:1100!important}
.top-brand{display:flex!important;align-items:center!important;gap:11px!important;min-width:220px!important;color:#fff!important;text-decoration:none!important}
.top-brand img{width:38px!important;height:38px!important;object-fit:contain!important}
.top-brand strong{font-size:17px!important;font-weight:800!important;letter-spacing:-.4px!important;line-height:1.2!important}
.top-brand small{display:block!important;color:#65738e!important;font-size:9px!important;text-transform:uppercase!important;letter-spacing:1.2px!important;margin-top:2px!important;line-height:1.2!important}
.top-actions{display:flex!important;align-items:center!important;gap:10px!important;margin-left:auto!important}
.icon-btn{width:38px!important;height:38px!important;border:1px solid var(--line)!important;background:#0a1020!important;color:#aeb9ca!important;border-radius:50%!important}
.top-avatar{width:38px!important;height:38px!important;border-radius:50%!important;background:linear-gradient(135deg,#1e3a8a,#2563eb)!important;color:#fff!important;border:1px solid rgba(96,165,250,.5)!important;display:flex!important;align-items:center!important;justify-content:center!important;font-weight:800!important;font-size:12px!important}
.notif{right:-2px!important;top:-3px!important;background:#ef4444!important;color:#fff!important;border-radius:10px!important;font-size:8px!important;padding:3px 5px!important;font-weight:700!important}

/* Sidebar */
.sidebar{width:245px!important;top:72px!important;bottom:0!important;left:0!important;background:rgba(5,10,21,.92)!important;border-right:1px solid var(--line)!important;padding:22px 14px!important;gap:6px!important;z-index:40!important;box-shadow:none!important}
.sidebar .rail-label{font-size:9px!important;color:#52627d!important;text-transform:uppercase!important;letter-spacing:1.5px!important;font-weight:800!important;padding:8px 12px 7px!important}
.sidebar .nav-item{width:100%!important;height:43px!important;border-radius:11px!important;color:#8794aa!important;display:flex!important;align-items:center!important;gap:12px!important;padding:0 13px!important;font-size:11px!important;font-weight:600!important;transition:.2s!important}
.sidebar .nav-item i{width:20px!important;text-align:center!important;font-size:14px!important;color:#6e7d97!important}
.sidebar .nav-item:hover,.sidebar .nav-item.active{color:#fff!important;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06))!important;box-shadow:inset 2px 0 0 #60a5fa!important}
.sidebar .nav-item.active i{color:#60a5fa!important}
.sidebar .user-profile{margin:8px 4px 4px!important;padding:12px!important;border:1px solid rgba(148,163,184,.10)!important;background:rgba(10,18,34,.7)!important;border-radius:13px!important;gap:10px!important}
.sidebar .user-profile .avatar{width:32px!important;height:32px!important;border-radius:50%!important;background:linear-gradient(135deg,#1e3a8a,#2563eb)!important;color:#fff!important;font-size:11px!important;border:0!important}
.sidebar .user-profile .user-info .name{font-size:10px!important;color:#e8eef9!important}
.sidebar .user-profile .user-info .role{font-size:8px!important;color:#66758f!important}
.sidebar .logout-btn{width:100%!important;height:43px!important;border-radius:11px!important;color:#8794aa!important;background:transparent!important;display:flex!important;align-items:center!important;gap:12px!important;text-align:left!important;text-decoration:none!important;transition:.2s!important;padding:0 13px!important;margin-top:0!important;font-size:11px!important;font-weight:600!important;border:0!important;box-shadow:none!important}
.sidebar .logout-btn i{width:20px!important;text-align:center!important;font-size:14px!important;color:#6e7d97!important}
.sidebar .logout-btn:hover{color:#fb7185!important;background:rgba(251,113,133,.08)!important;box-shadow:none!important}
.sidebar .logout-btn:hover i{color:#fb7185!important}

/* Main / page header */
.main-content{margin-left:245px!important;width:calc(100% - 245px)!important;padding:26px 28px 50px!important;min-height:calc(100vh - 72px)!important}
.page-header{display:flex!important;justify-content:space-between!important;align-items:center!important;gap:20px!important;min-height:48px!important;margin-bottom:20px!important;flex-wrap:wrap!important}
.page-header>div:first-child{display:flex!important;align-items:center!important;gap:12px!important}
.page-header h4{display:flex!important;align-items:center!important;gap:10px!important;font-size:25px!important;line-height:1.1!important;letter-spacing:-1px!important;font-weight:800!important;margin:0!important;color:#f7f9ff!important}
.page-header h4 span{width:38px!important;height:38px!important;border-radius:11px!important;background:rgba(96,165,250,.10)!important;border:1px solid rgba(96,165,250,.15)!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;flex-shrink:0!important}
.page-header h4 span i{font-size:15px!important;color:#60a5fa!important}
.page-header>div:last-child{display:flex!important;gap:8px!important;align-items:center!important;flex-wrap:wrap!important}

/* Stat cards */
.stat-grid{gap:14px!important;margin-bottom:14px!important}
.stat-card{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96))!important;border:1px solid var(--line)!important;border-radius:17px!important;box-shadow:0 18px 45px rgba(0,0,0,.18)!important;padding:18px!important;transition:.25s!important;color:#eaf0f8!important}
.stat-card:hover{border-color:rgba(96,165,250,.35)!important;box-shadow:0 20px 48px rgba(0,0,0,.25)!important;transform:translateY(-1px)!important}
.stat-card .stat-number{color:#f7f9ff!important;font-size:23px!important;font-weight:800!important}
.stat-card .stat-label{color:#8290a5!important;font-size:11px!important}
.stat-card .stat-icon{margin-bottom:10px!important}
.stat-card .stat-icon.gold{background:rgba(212,160,23,.12)!important;color:#e0b53d!important}.stat-card .stat-icon.blue{background:rgba(59,130,246,.12)!important;color:#60a5fa!important}.stat-card .stat-icon.green{background:rgba(34,197,94,.12)!important;color:#4ade80!important}.stat-card .stat-icon.purple{background:rgba(168,85,247,.12)!important;color:#c084fc!important}

/* Content card / table */
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96))!important;border:1px solid var(--line)!important;border-radius:17px!important;box-shadow:0 18px 45px rgba(0,0,0,.18)!important;overflow:hidden!important;color:#eaf0f8!important}
.card-custom:hover{border-color:rgba(96,165,250,.35)!important;box-shadow:0 20px 48px rgba(0,0,0,.25)!important}
.card-custom .card-header-custom{padding:15px 18px!important;border-bottom:1px solid rgba(148,163,184,.10)!important;display:flex!important;justify-content:space-between!important;align-items:center!important;flex-wrap:wrap!important;gap:12px!important}
.card-custom .card-header-custom h6{font-weight:700!important;color:#f7f9ff!important;font-size:13px!important}
.card-custom .card-header-custom h6 i{color:#60a5fa!important;margin-right:8px!important}
.card-custom .card-body-custom{padding:0!important;overflow-x:auto!important}
.table-custom{font-size:10px!important;color:#cbd5e1!important;--bs-table-bg:transparent!important;--bs-table-color:#cbd5e1!important;margin-bottom:0!important}
.table-custom th{font-weight:700!important;font-size:9px!important;text-transform:uppercase!important;letter-spacing:.45px!important;color:#66758f!important;border-bottom:1px solid rgba(148,163,184,.10)!important;padding:13px 14px!important;background:rgba(5,12,25,.48)!important;white-space:nowrap!important}
.table-custom td{padding:13px 14px!important;vertical-align:middle!important;border-bottom:1px solid rgba(148,163,184,.07)!important;color:#cbd5e1!important;background:transparent!important}
.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important}
.table-custom tr:last-child td{border-bottom:none!important}
.table-custom a{color:#60a5fa!important;text-decoration:none!important;font-weight:700!important}

/* Buttons / badges / pagination */
.btn-primary-custom{height:38px!important;background:linear-gradient(135deg,#3b82f6,#6366f1)!important;border:0!important;border-radius:11px!important;padding:0 15px!important;font-weight:700!important;font-size:11px!important;color:#fff!important;display:inline-flex!important;align-items:center!important;justify-content:center!important}
.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3)!important;transform:translateY(-1px)!important;box-shadow:0 8px 22px rgba(59,130,246,.2)!important;color:#fff!important}
.btn-success-custom{height:38px!important;border:1px solid rgba(52,211,153,.25)!important;border-radius:11px!important;background:rgba(52,211,153,.08)!important;color:#6ee7b7!important;font-size:11px!important;font-weight:700!important;padding:0 14px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important}
.btn-secondary-custom{background:#111d31!important;border:1px solid rgba(148,163,184,.13)!important;border-radius:9px!important;color:#8f9db4!important}
.pagination{gap:4px!important}.pagination .page-link{background:#0a1427!important;border:1px solid rgba(148,163,184,.12)!important;color:#8492aa!important;border-radius:8px!important;font-size:9px!important;padding:6px 9px!important}.pagination .page-link:hover{background:#10203a!important;color:#fff!important;border-color:rgba(96,165,250,.25)!important}.pagination .page-item.active .page-link{background:#2563eb!important;border-color:#3b82f6!important;color:#fff!important;box-shadow:0 0 15px rgba(59,130,246,.22)!important}

/* Forms / modal */
.form-label{font-weight:600!important;font-size:11px!important;color:#aebbd0!important}.form-control,.form-select{border-radius:9px!important;padding:9px 11px!important;border:1px solid rgba(148,163,184,.16)!important;background:#0a1427!important;color:#dbe5f5!important;font-size:11px!important}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55)!important;box-shadow:0 0 0 3px rgba(59,130,246,.10)!important;background:#0b172d!important;color:#fff!important}.form-select option{background:#0b1222!important;color:#dbe5f5!important}
.modal-content{background:linear-gradient(145deg,#0c172b,#07101f)!important;border:1px solid var(--line)!important;border-radius:15px!important;color:#dbe5f5!important;box-shadow:0 24px 70px rgba(0,0,0,.45)!important}.modal-header{border-bottom:1px solid rgba(148,163,184,.10)!important;padding:16px 20px!important}.modal-header .modal-title{font-weight:700!important;font-size:14px!important;color:#f7f9ff!important}.modal-header .modal-title i{color:#60a5fa!important;margin-right:8px!important}.modal-footer{border-top:1px solid rgba(148,163,184,.10)!important;padding:12px 20px!important}.modal-body{padding:18px 20px!important}.btn-close{filter:invert(1) grayscale(1)!important;opacity:.55!important}

/* Footer + scrollbar */
.footer-text{color:#44536c!important;font-size:9px!important;margin-top:20px!important;text-align:center!important}.footer-text a{color:#6b7a94!important}.footer-text a:hover{color:#60a5fa!important}
html,body{scrollbar-color:rgba(96,165,250,.32) #060b18!important;scrollbar-width:thin!important}html::-webkit-scrollbar,body::-webkit-scrollbar{width:7px;height:7px}html::-webkit-scrollbar-track,body::-webkit-scrollbar-track{background:#060b18}html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.30);border-radius:999px;border:1px solid rgba(6,11,24,.9)}html::-webkit-scrollbar-thumb:hover,body::-webkit-scrollbar-thumb:hover{background:rgba(96,165,250,.48)}

/* Responsive — same breakpoints as Sales Activity */
@media(max-width:1050px){.main-content{padding:22px 20px 45px!important}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.page-header{align-items:flex-start!important}}
@media(max-width:991px){.top-mobile-toggle{display:flex!important}.sidebar{transform:translateX(-100%)!important}.sidebar.open{transform:translateX(0)!important}.main-content{margin-left:0!important;width:100%!important;padding:92px 18px 24px!important}}
@media(max-width:800px){.topbar{padding:0 16px!important}.top-brand{min-width:0!important}.top-brand>div{display:none!important}.sidebar{display:flex!important;top:64px!important}.main-content{padding:20px 14px 40px!important}.page-header{align-items:flex-start!important;flex-direction:column!important;gap:14px!important}.page-header>div:last-child{width:100%!important}.page-header>div:last-child a,.page-header>div:last-child button{flex:1!important}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.table-custom{font-size:9px!important}.table-custom th,.table-custom td{padding:10px 9px!important}}
@media(max-width:480px){.topbar{height:64px!important;padding:0 12px!important}.top-brand img{width:32px!important;height:32px!important}.top-brand strong{font-size:11px!important}.top-brand small{font-size:8px!important}.main-content{padding:18px 10px 35px!important}.page-header h4{font-size:20px!important}.page-header h4 span{width:34px!important;height:34px!important;border-radius:9px!important}.stat-grid{gap:9px!important}.stat-card{min-height:110px!important;padding:13px!important;border-radius:14px!important}.stat-card .stat-icon{width:34px!important;height:34px!important;border-radius:9px!important}.stat-card .stat-number{font-size:19px!important;margin-top:11px!important}.stat-card .stat-label{font-size:8px!important}.card-custom .card-header-custom{padding:13px!important}.card-custom .card-header-custom form{width:100%!important}.table-custom{min-width:1120px!important}.detail-item{flex-direction:column!important}.detail-item .detail-label{width:100%!important;margin-bottom:3px!important}.top-mobile-toggle{display:flex!important}}

</style>
</head>
<body>

    <!-- TOPBAR -->
    <header class="topbar">
        <button class="top-mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu"><i class="fas fa-bars"></i></button>
        <a class="top-brand" href="dashboard.php"><img src="images/logo.webp" alt="GET"><div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div></a>
        <div class="top-actions"><button class="icon-btn" type="button" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button><div class="top-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div></div>
    </header>

    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <div class="rail-label">Main Menu</div>
        <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
        <?php if (in_array('sales_activity', $menuNames)): ?>
            <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a>
        <?php endif; ?>
        <?php if (in_array('account_management', $menuNames)): ?>
            <a href="account_management.php" class="nav-item active"><i class="fas fa-building"></i><span>Account Management</span></a>
        <?php endif; ?>
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a>
        <?php endif; ?>
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i><span>Produk</span></a>
        <?php endif; ?>
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="deliveryinstruction.php" class="nav-item"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a>
        <?php endif; ?>
        <div class="rail-label">Administration</div>
        <?php if (in_array('data_user', $menuNames)): ?>
            <a href="data_user.php" class="nav-item"><i class="fas fa-users"></i><span>Data User</span></a>
        <?php endif; ?>
        <?php if (in_array('data_sales', $menuNames) && file_exists('data_sales.php')): ?>
            <a href="data_sales.php" class="nav-item"><i class="fas fa-user-tie"></i><span>Data Sales</span></a>
        <?php endif; ?>
        <div class="sidebar-spacer"></div>
        <div class="user-profile"><div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div><div class="user-info"><div class="name"><strong><?= htmlspecialchars($fullName) ?><strong></div><div class="role"><?= getRoleLabel($role) ?></div></div></div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-power-off"></i><span>Logout</span></a>
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
                        <i class="fas fa-plus"></i> Tambah Account
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
                                    <option value="UD">UD</option>
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
                <div class="modal-header">
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