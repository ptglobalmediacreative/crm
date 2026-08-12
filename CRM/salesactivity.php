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
// FUNGSI ROMAN MONTH (BULAN ROMAWI)
// ============================================
function getRomanMonth($month) {
    $romanMonths = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];
    return $romanMonths[(int)$month] ?? '';
}

// ============================================
// GENERATE TRANSACTION REQUEST FORM NUMBER
// ============================================
function generateTRFNumber($db) {
    $month = date('m');
    $year = date('Y');
    $romanMonth = getRomanMonth($month);
    
    $stmt = $db->prepare("SELECT trf_number FROM sales_activities 
                          WHERE trf_number LIKE ? 
                          ORDER BY trf_number DESC LIMIT 1");
    $pattern = "%/GET-TR/JKT/" . $romanMonth . "/" . $year;
    $stmt->execute([$pattern]);
    $last = $stmt->fetchColumn();
    
    if ($last) {
        $parts = explode('/', $last);
        $lastNumber = (int)$parts[0];
        $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $newNumber = '0001';
    }
    
    return $newNumber . "/GET-TR/JKT/" . $romanMonth . "/" . $year;
}

// ============================================
// FUNGSI KOMPRESI GAMBAR
// ============================================
function compressImage($source_path, $destination_path, $quality = 80) {
    if (!file_exists($source_path)) {
        return false;
    }
    
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return false;
    }
    
    $mime_type = $image_info['mime'];
    $max_width = 1920;
    $max_height = 1920;
    
    switch ($mime_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source_path);
            break;
        case 'image/webp':
            $image = imagecreatefromwebp($source_path);
            break;
        default:
            return copy($source_path, $destination_path);
    }
    
    if (!$image) {
        return false;
    }
    
    $orig_width = imagesx($image);
    $orig_height = imagesy($image);
    
    if ($orig_width > $max_width || $orig_height > $max_height) {
        $ratio = min($max_width / $orig_width, $max_height / $orig_height);
        $new_width = round($orig_width * $ratio);
        $new_height = round($orig_height * $ratio);
        
        $resized_image = imagecreatetruecolor($new_width, $new_height);
        
        if ($mime_type == 'image/png') {
            imagealphablending($resized_image, false);
            imagesavealpha($resized_image, true);
            $transparent = imagecolorallocatealpha($resized_image, 255, 255, 255, 127);
            imagefilledrectangle($resized_image, 0, 0, $new_width, $new_height, $transparent);
        } elseif ($mime_type == 'image/gif') {
            $transparent = imagecolorallocatealpha($resized_image, 0, 0, 0, 127);
            imagecolortransparent($resized_image, $transparent);
        }
        
        imagecopyresampled($resized_image, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
        imagedestroy($image);
        $image = $resized_image;
    }
    
    $result = false;
    switch ($mime_type) {
        case 'image/jpeg':
        case 'image/jpg':
            $result = imagejpeg($image, $destination_path, $quality);
            break;
        case 'image/png':
            $png_quality = round(($quality / 100) * 9);
            $result = imagepng($image, $destination_path, $png_quality);
            break;
        case 'image/gif':
            $result = imagegif($image, $destination_path);
            break;
        case 'image/webp':
            $result = imagewebp($image, $destination_path, $quality);
            break;
        default:
            $result = copy($source_path, $destination_path);
    }
    
    imagedestroy($image);
    return $result;
}

// ============================================
// FUNGSI UPLOAD FILE DENGAN KOMPRESI
// ============================================
function uploadFileWithCompression($file, $target_dir, $allowed_extensions = [], $max_file_size = 5242880, $compress_quality = 80) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Error upload file'];
    }
    
    if ($file['size'] > $max_file_size) {
        return ['success' => false, 'message' => 'Ukuran file melebihi ' . ($max_file_size / 1024 / 1024) . 'MB'];
    }
    
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return ['success' => false, 'message' => 'Format file tidak didukung'];
    }
    
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $new_filename = time() . '_' . uniqid() . '.' . $file_extension;
    $file_path = $target_dir . $new_filename;
    
    $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($file_extension, $image_types)) {
        $compress_result = compressImage($file['tmp_name'], $file_path, $compress_quality);
        if (!$compress_result) {
            copy($file['tmp_name'], $file_path);
        }
    } else {
        copy($file['tmp_name'], $file_path);
    }
    
    return [
        'success' => true,
        'file_path' => $file_path,
        'filename' => $new_filename,
        'original_name' => $file['name'],
        'size' => filesize($file_path)
    ];
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
$direkturRoles = ['direktur_utama', 'direktur_operasional', 'direktur_sales', 'admin', 'it_support', 'finance', 'business'];

// ============================================
// FUNGSI CEK DEADLINE
// ============================================
function getDeadlineStatus($due_date, $status = 'in_progress') {
    if ($status == 'completed') {
        if (empty($due_date)) {
            return ['status' => 'none', 'label' => '-', 'class' => 'text-muted', 'icon' => '', 'badge_class' => 'secondary'];
        }
        return [
            'status' => 'completed',
            'label' => date('d/m/Y', strtotime($due_date)),
            'class' => 'text-muted',
            'icon' => '',
            'badge_class' => 'secondary'
        ];
    }
    
    if (empty($due_date)) return ['status' => 'none', 'label' => '-', 'class' => 'text-muted', 'icon' => '', 'badge_class' => 'secondary'];
    
    $today = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $today->setTime(0, 0, 0);
    
    $due = new DateTime($due_date);
    $due->setTime(0, 0, 0);
    
    $diff = $today->diff($due);
    $days = (int)$diff->format('%r%a');
    
    if ($days < 0) {
        return [
            'status' => 'overdue',
            'label' => 'LEWAT JATUH TEMPO!',
            'class' => 'text-danger fw-bold deadline-overdue',
            'icon' => 'fa-exclamation-triangle',
            'badge_class' => 'danger',
            'days' => abs($days)
        ];
    } elseif ($days <= 3) {
        return [
            'status' => 'approaching',
            'label' => $days . ' hari lagi',
            'class' => 'text-warning fw-bold',
            'icon' => 'fa-clock',
            'badge_class' => 'warning',
            'days' => $days
        ];
    } else {
        return [
            'status' => 'safe',
            'label' => date('d/m/Y', strtotime($due_date)),
            'class' => 'text-muted',
            'icon' => '',
            'badge_class' => 'success',
            'days' => $days
        ];
    }
}

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
// FUNGSI UNTUK MENDAPATKAN SALES ID DARI ACCOUNT
// ============================================
function getSalesIdFromAccount($db, $account_id) {
    $stmt = $db->prepare("SELECT sales_id FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $sales_id = $stmt->fetchColumn();
    return $sales_id ? (int)$sales_id : null;
}

// ============================================
// TAMBAHKAN KOLOM KE TABEL
// ============================================
try {
    $stmt = $db->query("SHOW COLUMNS FROM sales_activities LIKE 'status'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE sales_activities ADD COLUMN status VARCHAR(20) NULL DEFAULT 'in_progress'");
    }
    
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS due_date DATE NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS result TEXT NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS trf_number VARCHAR(50) NULL");
    
    try {
        $db->exec("ALTER TABLE sales_activities CHANGE COLUMN customer_prospek customer_deal VARCHAR(10) NULL");
    } catch(PDOException $e) {}
    
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS customer_deal VARCHAR(10) NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS leads_number VARCHAR(50) NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS attachment_file TEXT NULL");
    $db->exec("ALTER TABLE sales_activities ADD COLUMN IF NOT EXISTS badan_usaha VARCHAR(50) NULL");
    
    $db->exec("ALTER TABLE sales_activities ADD INDEX IF NOT EXISTS idx_status (status)");
    $db->exec("ALTER TABLE sales_activities ADD INDEX IF NOT EXISTS idx_due_date (due_date)");
} catch(PDOException $e) {}

// ============================================
// UPDATE STATUS OVERDUE OTOMATIS
// ============================================
try {
    $db->exec("UPDATE sales_activities 
               SET status = 'overdue' 
               WHERE status = 'in_progress' 
               AND due_date < CURDATE()");
} catch(PDOException $e) {}

// ============================================
// AMBIL DATA ACCOUNT UNTUK DROPDOWN
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
// API ENDPOINT untuk generate TRF Number (AJAX)
// ============================================
if (isset($_GET['generate_trf'])) {
    $trf_number = generateTRFNumber($db);
    header('Content-Type: application/json');
    echo json_encode(['trf_number' => $trf_number]);
    exit;
}

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
        $due_date = $_POST['due_date'];
        $result = !empty($_POST['result']) ? bersihkan($_POST['result']) : '';
        $customer_deal = !empty($_POST['customer_deal']) ? bersihkan($_POST['customer_deal']) : 'No';
        $trf_number = !empty($_POST['trf_number']) ? bersihkan($_POST['trf_number']) : '';
        
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
        
        $attachment_file = '';
        if (!empty($_FILES['attachment_file']['name'])) {
            $target_dir = "uploads/salesactivity/";
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            $max_file_size = 5 * 1024 * 1024;
            $compress_quality = 80;
            
            $upload_result = uploadFileWithCompression(
                $_FILES['attachment_file'],
                $target_dir,
                $allowed_extensions,
                $max_file_size,
                $compress_quality
            );
            
            if ($upload_result['success']) {
                $attachment_file = $upload_result['file_path'];
            } else {
                setFlash($upload_result['message'], 'danger');
                redirect('salesactivity.php');
            }
        }
        
        $errors = [];
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($account_id)) $errors[] = 'Account wajib dipilih!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($due_date)) $errors[] = 'Due Date wajib diisi!';
        if (empty($deskripsi)) $errors[] = 'Deskripsi wajib diisi!';
        if (strlen($deskripsi) < 80) $errors[] = 'Deskripsi minimal 80 karakter!';
        
        if (!empty($result)) {
            if (strlen($result) < 80) {
                $errors[] = 'Result minimal 80 karakter!';
            }
            if (empty($attachment_file)) {
                $errors[] = 'Jika mengisi Result, Attachment file wajib diupload!';
            }
        }
        
        if (empty($errors)) {
            $status = empty($result) ? 'in_progress' : 'completed';
            
            $leads_number = NULL;
            if ($status === 'completed' && $customer_deal === 'Yes' && $jenis_tugas === 'Negosiasi') {
                $leads_number = generateLeadsNumber($db, $due_date);
            }
            
            // Generate TRF Number jika jenis tugas = Negosiasi
            if ($jenis_tugas === 'Negosiasi' && empty($trf_number)) {
                $trf_number = generateTRFNumber($db);
            }
            
            $targetSalesId = $userId;
            if (in_array($userRole, $direkturRoles) && $account_id) {
                $salesIdFromAccount = getSalesIdFromAccount($db, $account_id);
                if ($salesIdFromAccount) {
                    $targetSalesId = $salesIdFromAccount;
                }
            }
            
            $stmt = $db->prepare("INSERT INTO sales_activities 
                                  (subject, account_id, contact_name, contact_mobile, business_segment, 
                                   badan_usaha, jenis_tugas, deskripsi, due_date, status, sales_id,
                                   result, customer_deal, leads_number, attachment_file, completed_at, trf_number) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $subject, $account_id, $contact_name, $contact_mobile, $business_segment,
                $badan_usaha, $jenis_tugas, $deskripsi, $due_date, $status, $targetSalesId,
                $result, $customer_deal, $leads_number, $attachment_file,
                $status === 'completed' ? date('Y-m-d H:i:s') : NULL,
                $trf_number
            ]);
            
            $salesActivityId = $db->lastInsertId();
            
            // ============================================
            // INSERT KE TRANSACTION REQUESTS
            // ============================================
            if (!empty($trf_number) && $jenis_tugas === 'Negosiasi') {
                try {
                    $stmt_tr = $db->prepare("INSERT INTO transaction_requests 
                                              (trf_number, sales_activity_id, account_id, sales_id, 
                                               subject, jenis_tugas, description, request_date, due_date, 
                                               customer_deal, leads_number, attachment_file, result, status) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                    $stmt_tr->execute([
                        $trf_number,
                        $salesActivityId,
                        $account_id,
                        $targetSalesId,
                        $subject,
                        $jenis_tugas,
                        $deskripsi,
                        date('Y-m-d'),
                        $due_date,
                        $customer_deal,
                        $leads_number,
                        $attachment_file,
                        $result
                    ]);
                } catch(PDOException $e) {
                    error_log("Error inserting transaction_request: " . $e->getMessage());
                }
            }
            
            if ($status === 'completed') {
                setFlash('Sales Activity berhasil ditambahkan dan diselesaikan!', 'success');
            } else {
                setFlash('Sales Activity berhasil ditambahkan! (In Progress)', 'success');
            }
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'complete') {
        $id = (int)$_POST['id'];
        
        $canComplete = false;
        if ($hasFullAccess) {
            $canComplete = true;
        } elseif ($userRole === 'sales') {
            $stmt = $db->prepare("SELECT sales_id FROM sales_activities WHERE id = ?");
            $stmt->execute([$id]);
            $ownerId = $stmt->fetchColumn();
            if ($ownerId == $userId) {
                $canComplete = true;
            }
        } elseif (canEdit('sales_activity')) {
            $canComplete = true;
        }
        
        if (!$canComplete) {
            setFlash('Anda tidak memiliki akses untuk menyelesaikan sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $result = bersihkan($_POST['result']);
        $customer_deal = bersihkan($_POST['customer_deal']);
        $jenis_tugas = bersihkan($_POST['jenis_tugas_hidden'] ?? '');
        $trf_number = bersihkan($_POST['trf_number'] ?? '');
        
        // AMBIL TRF NUMBER YANG SUDAH ADA DARI DATABASE
        $stmt = $db->prepare("SELECT trf_number FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        $existing_trf = $stmt->fetchColumn();
        
        // Gunakan TRF number yang sudah ada, jangan generate ulang
        if (!empty($existing_trf)) {
            $trf_number = $existing_trf;
        } elseif (empty($trf_number) && $jenis_tugas === 'Negosiasi') {
            // Jika belum ada TRF number dan jenis tugas = Negosiasi, generate
            $trf_number = generateTRFNumber($db);
        }
        
        $leads_number = NULL;
        if ($customer_deal === 'Yes' && $jenis_tugas === 'Negosiasi') {
            $stmt = $db->prepare("SELECT due_date FROM sales_activities WHERE id = ?");
            $stmt->execute([$id]);
            $due_date = $stmt->fetchColumn();
            if ($due_date) {
                $leads_number = generateLeadsNumber($db, $due_date);
            }
        }
        
        $attachment_files = [];
        $attachment_file_names = [];
        
        $target_dir = "uploads/salesactivity/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar'];
        $max_file_size = 5 * 1024 * 1024;
        $compress_quality = 80;
        
        if (!empty($_FILES['attachment_files']['name'][0])) {
            foreach ($_FILES['attachment_files']['name'] as $key => $name) {
                if (empty($name)) continue;
                
                $file = [
                    'name' => $_FILES['attachment_files']['name'][$key],
                    'type' => $_FILES['attachment_files']['type'][$key],
                    'tmp_name' => $_FILES['attachment_files']['tmp_name'][$key],
                    'error' => $_FILES['attachment_files']['error'][$key],
                    'size' => $_FILES['attachment_files']['size'][$key]
                ];
                
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    setFlash('Error upload file: ' . htmlspecialchars($name), 'danger');
                    redirect('salesactivity.php');
                }
                
                $upload_result = uploadFileWithCompression(
                    $file,
                    $target_dir,
                    $allowed_extensions,
                    $max_file_size,
                    $compress_quality
                );
                
                if ($upload_result['success']) {
                    $attachment_files[] = $upload_result['file_path'];
                    $attachment_file_names[] = $upload_result['original_name'];
                } else {
                    setFlash($upload_result['message'] . ' - ' . htmlspecialchars($name), 'danger');
                    redirect('salesactivity.php');
                }
            }
        }
        
        $errors = [];
        if (empty($result)) $errors[] = 'Result wajib diisi!';
        if (strlen($result) < 80) $errors[] = 'Result minimal 80 karakter!';
        if (empty($attachment_files)) $errors[] = 'Minimal 1 file attachment wajib diupload!';
        
        if (empty($errors)) {
            $attachment_json = json_encode([
                'files' => $attachment_files,
                'names' => $attachment_file_names
            ]);
            
            // UPDATE TANPA MENGUBAH TRF NUMBER (kecuali jika belum ada)
            if (!empty($trf_number)) {
                $stmt = $db->prepare("UPDATE sales_activities SET 
                                      result = ?, customer_deal = ?, leads_number = ?, 
                                      attachment_file = ?, status = 'completed', completed_at = NOW(), trf_number = ? 
                                      WHERE id = ? AND (status = 'in_progress' OR status = 'overdue')");
                $stmt->execute([$result, $customer_deal, $leads_number, $attachment_json, $trf_number, $id]);
            } else {
                $stmt = $db->prepare("UPDATE sales_activities SET 
                                      result = ?, customer_deal = ?, leads_number = ?, 
                                      attachment_file = ?, status = 'completed', completed_at = NOW() 
                                      WHERE id = ? AND (status = 'in_progress' OR status = 'overdue')");
                $stmt->execute([$result, $customer_deal, $leads_number, $attachment_json, $id]);
            }
            
            // ============================================
            // UPDATE TRANSACTION REQUESTS
            // ============================================
            if (!empty($trf_number)) {
                try {
                    $stmt_tr = $db->prepare("UPDATE transaction_requests SET 
                                              status = 'completed',
                                              customer_deal = ?,
                                              leads_number = ?,
                                              result = ?,
                                              attachment_file = ?
                                              WHERE trf_number = ?");
                    $stmt_tr->execute([$customer_deal, $leads_number, $result, $attachment_json, $trf_number]);
                } catch(PDOException $e) {
                    error_log("Error updating transaction_request: " . $e->getMessage());
                }
            }
            
            setFlash('Sales Activity berhasil diselesaikan!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        
        $canEdit = false;
        if ($hasFullAccess) {
            $canEdit = true;
        } elseif ($userRole === 'sales') {
            $stmt = $db->prepare("SELECT sales_id FROM sales_activities WHERE id = ?");
            $stmt->execute([$id]);
            $ownerId = $stmt->fetchColumn();
            if ($ownerId == $userId) {
                $canEdit = true;
            }
        } elseif (canEdit('sales_activity')) {
            $canEdit = true;
        }
        
        if (!$canEdit) {
            setFlash('Anda tidak memiliki akses untuk mengedit sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $subject = bersihkan($_POST['subject']);
        $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : NULL;
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $deskripsi = bersihkan($_POST['deskripsi']);
        $due_date = $_POST['due_date'];
        
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
        if (empty($due_date)) $errors[] = 'Due Date wajib diisi!';
        if (empty($deskripsi)) $errors[] = 'Deskripsi wajib diisi!';
        if (strlen($deskripsi) < 80) $errors[] = 'Deskripsi minimal 80 karakter!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE sales_activities SET 
                                  subject = ?, account_id = ?, contact_name = ?, contact_mobile = ?, 
                                  business_segment = ?, badan_usaha = ?, jenis_tugas = ?, deskripsi = ?, due_date = ? 
                                  WHERE id = ? AND (status = 'in_progress' OR status = 'overdue')");
            $stmt->execute([
                $subject, $account_id, $contact_name, $contact_mobile, $business_segment,
                $badan_usaha, $jenis_tugas, $deskripsi, $due_date, $id
            ]);
            
            setFlash('Sales Activity berhasil diupdate!', 'success');
            redirect('salesactivity.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!$hasFullAccess || !canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses untuk menghapus sales activity!', 'danger');
            redirect('salesactivity.php');
        }
        
        $id = (int)$_POST['id'];
        
        // Ambil trf_number sebelum delete
        $stmt = $db->prepare("SELECT trf_number FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        $trf_number = $stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT attachment_file FROM sales_activities WHERE id = ?");
        $stmt->execute([$id]);
        $attachment_data = $stmt->fetchColumn();
        
        if ($attachment_data) {
            $files = json_decode($attachment_data, true);
            if ($files && isset($files['files']) && is_array($files['files'])) {
                foreach ($files['files'] as $file) {
                    if ($file && file_exists($file)) {
                        unlink($file);
                    }
                }
            } else if ($attachment_data && file_exists($attachment_data)) {
                unlink($attachment_data);
            }
        }
        
        // ============================================
        // HAPUS TRANSACTION REQUESTS
        // ============================================
        if (!empty($trf_number)) {
            try {
                $stmt_tr = $db->prepare("DELETE FROM transaction_requests WHERE trf_number = ?");
                $stmt_tr->execute([$trf_number]);
            } catch(PDOException $e) {
                error_log("Error deleting transaction_request: " . $e->getMessage());
            }
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
    if ($status_filter === 'overdue') {
        $where .= " AND sa.status = 'overdue'";
    } elseif ($status_filter === 'in_progress') {
        $where .= " AND (sa.status = 'in_progress' OR sa.status = 'overdue')";
    } elseif ($status_filter === 'completed') {
        $where .= " AND sa.status = 'completed'";
    } elseif ($status_filter === 'middle_prospek') {
        $where .= " AND sa.jenis_tugas = 'Prospecting' 
                    AND NOT EXISTS (
                        SELECT 1 FROM sales_activities sa2 
                        WHERE sa2.account_id = sa.account_id 
                        AND sa2.jenis_tugas IN ('Negosiasi', 'Kontrak')
                        AND sa2.id != sa.id
                    )";
    } elseif ($status_filter === 'hot_prospek') {
        $where .= " AND sa.jenis_tugas = 'Negosiasi' 
                    AND NOT EXISTS (
                        SELECT 1 FROM sales_activities sa2 
                        WHERE sa2.account_id = sa.account_id 
                        AND sa2.jenis_tugas = 'Kontrak'
                        AND sa2.id != sa.id
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM sales_activities sa3 
                        WHERE sa3.account_id = sa.account_id 
                        AND sa3.jenis_tugas = 'Negosiasi'
                        AND sa3.status = 'completed'
                        AND sa3.customer_deal = 'No'
                        AND sa3.id != sa.id
                    )
                    AND NOT (sa.status = 'completed' AND sa.customer_deal = 'No')";
    } elseif ($status_filter === 'lost_prospek') {
        $where .= " AND sa.jenis_tugas = 'Negosiasi' 
                    AND sa.status = 'completed' 
                    AND sa.customer_deal = 'No'";
    } elseif ($status_filter === 'deal') {
        $where .= " AND sa.jenis_tugas = 'Kontrak'";
    } else {
        $where .= " AND sa.status = ?";
        $params[] = $status_filter;
    }
}

if (!empty($search)) {
    $where .= " AND (sa.subject LIKE ? OR sa.contact_name LIKE ? OR sa.contact_mobile LIKE ? OR a.nama_pt LIKE ? OR sa.trf_number LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
}

$countSql = "SELECT COUNT(*) FROM sales_activities sa LEFT JOIN accounts a ON sa.account_id = a.id $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

$sql = "SELECT sa.*, a.nama_pt, a.badan_usaha as account_badan_usaha, u.full_name as sales_name,
        (SELECT COUNT(*) FROM sales_activities sa2 WHERE sa2.account_id = sa.account_id AND sa2.jenis_tugas IN ('Negosiasi', 'Kontrak') AND sa2.id != sa.id) as has_negosiasi_kontrak,
        (SELECT COUNT(*) FROM sales_activities sa3 WHERE sa3.account_id = sa.account_id AND sa3.jenis_tugas = 'Kontrak' AND sa3.id != sa.id) as has_kontrak,
        (SELECT COUNT(*) FROM sales_activities sa4 WHERE sa4.account_id = sa.account_id AND sa4.jenis_tugas = 'Negosiasi' AND sa4.status = 'completed' AND sa4.customer_deal = 'No' AND sa4.id != sa.id) as has_lost_prospek
        FROM sales_activities sa 
        LEFT JOIN accounts a ON sa.account_id = a.id 
        LEFT JOIN users u ON sa.sales_id = u.id 
        $where 
        ORDER BY sa.due_date ASC, sa.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$totalInProgress = 0;
$totalCompleted = 0;
$totalOverdue = 0;
$approachingCount = 0;

if ($userRole === 'sales') {
    $totalInProgress = $db->query("SELECT COUNT(*) FROM sales_activities WHERE sales_id = $userId AND (status = 'in_progress' OR status = 'overdue')")->fetchColumn();
    $totalCompleted = $db->query("SELECT COUNT(*) FROM sales_activities WHERE sales_id = $userId AND status = 'completed'")->fetchColumn();
    $totalOverdue = $db->query("SELECT COUNT(*) FROM sales_activities WHERE sales_id = $userId AND status = 'overdue'")->fetchColumn();
    $approachingCount = $db->query("SELECT COUNT(*) FROM sales_activities 
                                    WHERE sales_id = $userId 
                                    AND status = 'in_progress' 
                                    AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE sales_id = ? 
                          AND jenis_tugas = 'Prospecting'
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE sales_id = ? 
                              AND jenis_tugas IN ('Negosiasi', 'Kontrak')
                              AND account_id IS NOT NULL
                          )");
    $stmt->execute([$userId, $userId]);
    $totalMiddleProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE sales_id = ? 
                          AND jenis_tugas = 'Negosiasi'
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE sales_id = ? 
                              AND jenis_tugas = 'Kontrak'
                              AND account_id IS NOT NULL
                          )
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE sales_id = ? 
                              AND jenis_tugas = 'Negosiasi'
                              AND status = 'completed'
                              AND customer_deal = 'No'
                              AND account_id IS NOT NULL
                          )");
    $stmt->execute([$userId, $userId, $userId]);
    $totalHotProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE sales_id = ? 
                          AND jenis_tugas = 'Negosiasi'
                          AND status = 'completed'
                          AND customer_deal = 'No'");
    $stmt->execute([$userId]);
    $totalLostProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE sales_id = ? 
                          AND jenis_tugas = 'Kontrak'");
    $stmt->execute([$userId]);
    $totalDeal = $stmt->fetchColumn();
    
} else {
    $totalInProgress = $db->query("SELECT COUNT(*) FROM sales_activities WHERE (status = 'in_progress' OR status = 'overdue')")->fetchColumn();
    $totalCompleted = $db->query("SELECT COUNT(*) FROM sales_activities WHERE status = 'completed'")->fetchColumn();
    $totalOverdue = $db->query("SELECT COUNT(*) FROM sales_activities WHERE status = 'overdue'")->fetchColumn();
    $approachingCount = $db->query("SELECT COUNT(*) FROM sales_activities 
                                    WHERE status = 'in_progress' 
                                    AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE jenis_tugas = 'Prospecting'
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE jenis_tugas IN ('Negosiasi', 'Kontrak')
                              AND account_id IS NOT NULL
                          )");
    $stmt->execute();
    $totalMiddleProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE jenis_tugas = 'Negosiasi'
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE jenis_tugas = 'Kontrak'
                              AND account_id IS NOT NULL
                          )
                          AND account_id NOT IN (
                              SELECT DISTINCT account_id FROM sales_activities 
                              WHERE jenis_tugas = 'Negosiasi'
                              AND status = 'completed'
                              AND customer_deal = 'No'
                              AND account_id IS NOT NULL
                          )");
    $stmt->execute();
    $totalHotProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE jenis_tugas = 'Negosiasi'
                          AND status = 'completed'
                          AND customer_deal = 'No'");
    $stmt->execute();
    $totalLostProspek = $stmt->fetchColumn();
    
    $stmt = $db->prepare("SELECT COUNT(DISTINCT account_id) FROM sales_activities 
                          WHERE jenis_tugas = 'Kontrak'");
    $stmt->execute();
    $totalDeal = $stmt->fetchColumn();
}

$totalActivities = $totalInProgress + $totalCompleted;
$overdueCount = $totalOverdue;

$editData = null;
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM sales_activities WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch();
}

if (isset($_GET['get_account'])) {
    $account_id = (int)$_GET['get_account'];
    $stmt = $db->prepare("SELECT nama_pic, no_hp_pic, bidang_usaha, badan_usaha FROM accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode($data ?: []);
    exit;
}

$completeData = null;
if (isset($_GET['complete'])) {
    $id = (int)$_GET['complete'];
    $stmt = $db->prepare("SELECT sa.*, a.nama_pt 
                          FROM sales_activities sa 
                          LEFT JOIN accounts a ON sa.account_id = a.id 
                          WHERE sa.id = ?");
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
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
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

        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 25px; }
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
        .stat-card .stat-icon.blue { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .stat-card .stat-icon.green { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .stat-card .stat-icon.red { background: rgba(231, 76, 60, 0.12); color: #e74c3c; }
        .stat-card .stat-icon.gold { background: rgba(255, 215, 0, 0.12); color: #d4a017; }
        .stat-card .stat-icon.purple { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .stat-card .stat-number { font-size: 24px; font-weight: 800; color: #0e1a2b; margin-bottom: 2px; }
        .stat-card .stat-label { font-size: 13px; color: #888; }

        .grid-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 991px) { .grid-2-col { grid-template-columns: 1fr; } }
        
        .chart-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .chart-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .chart-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; font-size: 16px; }
        .chart-card h6 i { margin-right: 8px; }
        .chart-wrapper { height: 220px; width: 100%; max-width: 320px; margin: 0 auto; }

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

        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-status.in_progress { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-status.completed { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-status.overdue { background: rgba(231, 76, 60, 0.15); color: #c0392b; }

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
        .btn-action:hover { transform: scale(1.1); }
        .btn-action.detail { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .btn-action.detail:hover { background: rgba(46, 204, 113, 0.2); }
        .btn-action.edit { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        .btn-action.complete { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .btn-action.complete:hover { background: rgba(241, 196, 15, 0.2); }

        .badge-badan-usaha {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(26, 188, 156, 0.12);
            color: #16a085;
        }

        .badge-middle-prospek {
            background: rgba(243, 156, 18, 0.15);
            color: #f39c12;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-hot-prospek {
            background: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-deal {
            background: rgba(142, 68, 173, 0.15);
            color: #8e44ad;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-lost {
            background: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

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
        .btn-complete-custom:hover { background: #e67e22; color: #fff; }

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }
        .alert.deadline-alert { border-left: 4px solid #dc3545; background: #fff5f5; }
        .alert.deadline-warning { border-left: 4px solid #ffc107; background: #fffbf0; }

        .detail-item { display: flex; padding: 10px 0; border-bottom: 1px solid #f0f2f5; }
        .detail-item:last-child { border-bottom: none; }
        .detail-item .detail-label { font-weight: 600; color: #555; width: 160px; flex-shrink: 0; font-size: 13px; }
        .detail-item .detail-value { color: #0e1a2b; font-size: 13px; word-break: break-word; }

        .filter-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
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
        .filter-buttons .btn-filter:hover { border-color: #ffd700; color: #0e1a2b; }
        .filter-buttons .btn-filter.active { background: #0e1a2b; border-color: #0e1a2b; color: #fff; }
        .filter-buttons .btn-filter .count {
            background: rgba(0,0,0,0.1);
            padding: 0 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 4px;
        }
        .filter-buttons .btn-filter.active .count { background: rgba(255,255,255,0.2); }

        .deadline-overdue { animation: blink 1s infinite; }
        @keyframes blink { 0% { opacity: 1; } 50% { opacity: 0.3; } 100% { opacity: 1; } }
        .badge-overdue { background: #dc3545 !important; animation: blink 1s infinite; }
        .badge-approaching { background: #ffc107 !important; color: #212529 !important; }
        .badge-safe { background: #198754 !important; }

        .table-overdue { background-color: #fff5f5 !important; }
        .table-overdue:hover { background-color: #ffe8e8 !important; }

        .char-counter { font-size: 12px; padding: 4px 0; }
        .char-counter .count { font-weight: 600; }
        .char-counter .count.valid { color: #27ae60; }
        .char-counter .count.invalid { color: #e74c3c; }

        .deal-fields { display: none; }
        .deal-fields.show { display: block; }
        .trf-field { display: none; }
        .trf-field.show { display: block; }

        .auto-fill-field { background: #f8f9fa !important; cursor: default; }

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
            .grid-2-col { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 480px) {
            .stat-grid { grid-template-columns: 1fr; }
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
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalSalesActivity">
                    <i class="fas fa-plus"></i> Tambah
                </button>
            <?php endif; ?>
        </div>

        <!-- DEADLINE NOTIFICATION -->
        <?php if ($overdueCount > 0): ?>
            <div class="alert alert-danger deadline-alert mb-3" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <strong>Perhatian!</strong> Ada <strong><?= $overdueCount ?></strong> aktivitas yang <strong>MELEWATI JATUH TEMPO</strong>! Segera selesaikan!
            </div>
        <?php endif; ?>
        
        <?php if ($approachingCount > 0): ?>
            <div class="alert alert-warning deadline-warning mb-3" role="alert">
                <i class="fas fa-clock me-2"></i>
                Ada <strong><?= $approachingCount ?></strong> aktivitas yang <strong>mendekati jatuh tempo</strong> (&le; 3 hari)! Segera selesaikan!
            </div>
        <?php endif; ?>

        <!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-spinner"></i></div>
                <div class="stat-number"><?= number_format($totalInProgress) ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?= number_format($totalCompleted) ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?= number_format($overdueCount) ?></div>
                <div class="stat-label">Overdue</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-tasks"></i></div>
                <div class="stat-number"><?= number_format($totalActivities) ?></div>
                <div class="stat-label">Total Aktivitas</div>
            </div>
        </div>

        <!-- CHARTS -->
        <div class="grid-2-col">
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie" style="color:#2980b9;"></i> Status Aktivitas</h6>
                <div class="chart-wrapper"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="chart-card">
                <h6><i class="fas fa-chart-pie" style="color:#f39c12;"></i> Status Prospek</h6>
                <div class="chart-wrapper"><canvas id="prospekChart"></canvas></div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Sales Activity</h6>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <form method="GET" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari..." value="<?= htmlspecialchars($search) ?>" style="width: 180px;">
                        <button type="submit" class="btn btn-primary-custom" style="padding: 6px 16px;"><i class="fas fa-search"></i></button>
                        <?php if (!empty($search)): ?>
                            <a href="salesactivity.php?status=<?= $status_filter ?>" class="btn btn-secondary-custom" style="padding: 6px 16px;"><i class="fas fa-times"></i></a>
                        <?php endif; ?>
                    </form>
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
                    <a href="?status=overdue&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'overdue' ? 'active' : '' ?>">
                        <i class="fas fa-exclamation-triangle fa-fw" style="color:#e74c3c;"></i> Overdue <span class="count"><?= $overdueCount ?></span>
                    </a>
                    <a href="?status=middle_prospek&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'middle_prospek' ? 'active' : '' ?>">
                        <i class="fas fa-user-tie fa-fw" style="color:#f39c12;"></i> Middle Prospek <span class="count"><?= $totalMiddleProspek ?></span>
                    </a>
                    <a href="?status=hot_prospek&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'hot_prospek' ? 'active' : '' ?>">
                        <i class="fas fa-fire fa-fw" style="color:#ff6b6b;"></i> Hot Prospek <span class="count"><?= $totalHotProspek ?></span>
                    </a>
                    <a href="?status=lost_prospek&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'lost_prospek' ? 'active' : '' ?>">
                        <i class="fas fa-times-circle fa-fw" style="color:#e74c3c;"></i> Lost Prospek <span class="count"><?= $totalLostProspek ?></span>
                    </a>
                    <a href="?status=deal&search=<?= urlencode($search) ?>" class="btn-filter <?= $status_filter == 'deal' ? 'active' : '' ?>">
                        <i class="fas fa-handshake fa-fw" style="color:#8e44ad;"></i> Deal <span class="count"><?= $totalDeal ?></span>
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
                                <th>Jenis Tugas</th>
                                <th>Due Date</th>
                                <th>Status Deadline</th>
                                <th>Sales</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($activities) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($activities as $activity): ?>
                                    <?php 
                                    $deadline = getDeadlineStatus($activity['due_date'], $activity['status']);
                                    $isOverdue = $activity['status'] == 'overdue' || ($deadline['status'] == 'overdue' && $activity['status'] == 'in_progress');
                                    $isApproaching = $deadline['status'] == 'approaching' && $activity['status'] == 'in_progress';
                                    $rowClass = $isOverdue ? 'table-overdue' : '';
                                    
                                    $isMiddleProspek = ($activity['jenis_tugas'] == 'Prospecting' && $activity['has_negosiasi_kontrak'] == 0);
                                    $isHotProspek = ($activity['jenis_tugas'] == 'Negosiasi' && $activity['has_kontrak'] == 0 && $activity['has_lost_prospek'] == 0 && !($activity['status'] == 'completed' && $activity['customer_deal'] == 'No'));
                                    $isLostProspek = ($activity['jenis_tugas'] == 'Negosiasi' && $activity['status'] == 'completed' && $activity['customer_deal'] == 'No');
                                    $isDeal = ($activity['jenis_tugas'] == 'Kontrak');
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td><?= $no++ ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($activity['subject']) ?></strong>
                                            <?php if ($isMiddleProspek): ?>
                                                <br><span class="badge-middle-prospek"><i class="fas fa-user-tie"></i> Middle Prospek</span>
                                            <?php endif; ?>
                                            <?php if ($isHotProspek): ?>
                                                <br><span class="badge-hot-prospek"><i class="fas fa-fire"></i> Hot Prospek</span>
                                            <?php endif; ?>
                                            <?php if ($isLostProspek): ?>
                                                <br><span class="badge-lost"><i class="fas fa-times-circle"></i> Lost Prospek</span>
                                            <?php endif; ?>
                                            <?php if ($isDeal): ?>
                                                <br><span class="badge-deal"><i class="fas fa-handshake"></i> Deal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($activity['nama_pt'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-tugas <?= str_replace(' ', '_', str_replace('/', '_', $activity['jenis_tugas'])) ?>">
                                                <?= htmlspecialchars($activity['jenis_tugas']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($activity['due_date']): ?>
                                                <span class="<?= $deadline['class'] ?>">
                                                    <?= date('d/m/Y', strtotime($activity['due_date'])) ?>
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($activity['status'] == 'in_progress' && $activity['due_date']): ?>
                                                <?php if ($deadline['status'] == 'overdue'): ?>
                                                    <span class="badge badge-overdue">
                                                        <i class="fas fa-exclamation-triangle"></i> LEWAT!
                                                    </span>
                                                <?php elseif ($deadline['status'] == 'approaching'): ?>
                                                    <span class="badge badge-approaching">
                                                        <i class="fas fa-clock"></i> <?= $deadline['label'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge badge-safe">
                                                        <i class="fas fa-check-circle"></i> On Track
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($activity['status'] == 'overdue'): ?>
                                                <span class="badge badge-overdue">
                                                    <i class="fas fa-exclamation-triangle"></i> OVERDUE!
                                                </span>
                                            <?php elseif ($activity['status'] == 'completed'): ?>
                                                <span class="badge bg-secondary">
                                                    <i class="fas fa-check"></i> Selesai
                                                </span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($activity['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="detailActivity(<?= htmlspecialchars(json_encode($activity)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <?php if ($activity['status'] == 'in_progress' || $activity['status'] == 'overdue'): ?>
                                                    <?php 
                                                    $canEdit = false;
                                                    if ($hasFullAccess) {
                                                        $canEdit = true;
                                                    } elseif ($userRole === 'sales' && $activity['sales_id'] == $userId) {
                                                        $canEdit = true;
                                                    } elseif (canEdit('sales_activity')) {
                                                        $canEdit = true;
                                                    }
                                                    ?>
                                                    <?php if ($canEdit): ?>
                                                        <button class="btn-action edit" onclick="editActivity(<?= htmlspecialchars(json_encode($activity)) ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn-action complete" onclick="completeActivity(<?= $activity['id'] ?>, <?= htmlspecialchars(json_encode($activity)) ?>)">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if ($hasFullAccess && canDelete('sales_activity')): ?>
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
                                    <td colspan="8" class="text-center py-4 text-muted">
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

    </div>

    <!-- MODALS -->
    <!-- Modal Tambah / Edit Sales Activity -->
    <div class="modal fade" id="modalSalesActivity" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Sales Activity</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formSalesActivity" onsubmit="return validateFormAdd()">
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
                                <label class="form-label">Due Date <span class="text-danger">*</span></label>
                                <input type="date" name="due_date" id="due_date" class="form-control" required>
                                <small class="text-muted">Tanggal jatuh tempo penyelesaian aktivitas</small>
                            </div>
                        </div>
                        
                        <!-- Transaction Request Form Number -->
                        <div class="trf-field" id="trfField">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Transaction Request Form</label>
                                    <input type="text" name="trf_number" id="trf_number_add" class="form-control" readonly>
                                    <small class="text-muted">Akan digenerate otomatis untuk jenis tugas Negosiasi</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi <span class="text-danger">*</span></label>
                            <textarea name="deskripsi" id="deskripsi" class="form-control" rows="4" placeholder="Masukkan deskripsi (minimal 80 karakter)" required oninput="updateCharCount('deskripsi', 'deskripsiCounter')"></textarea>
                            <div class="char-counter">
                                <span class="count" id="deskripsiCounter">0</span> / 80 karakter (minimal)
                            </div>
                        </div>
                        
                        <hr>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Opsional:</strong> Jika Anda langsung mengisi <strong>Result</strong>, aktivitas akan otomatis menjadi <strong>Completed</strong>. Jika tidak diisi, status akan <strong>In Progress</strong>.
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Result</label>
                            <textarea name="result" id="result_add" class="form-control" rows="3" placeholder="Masukkan hasil aktivitas (kosongkan jika masih in progress)" oninput="updateCharCount('result_add', 'resultCounterAdd')"></textarea>
                            <div class="char-counter">
                                <span class="count" id="resultCounterAdd">0</span> / 80 karakter (minimal jika diisi)
                            </div>
                        </div>
                        
                        <!-- Customer Deal & Leads Number -->
                        <div class="deal-fields" id="dealFields">
                            <hr>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer Deal</label>
                                    <select name="customer_deal" id="customer_deal_add" class="form-select">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Leads Number</label>
                                    <input type="text" name="leads_number" id="leads_number_add" class="form-control" readonly>
                                    <small class="text-muted">Akan digenerate otomatis saat Complete</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment File <span id="attachment_required" style="display:none;color:red;">*</span></label>
                            <input type="file" name="attachment_file" id="attachment_file_add" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                            <small class="text-muted">Upload file jika mengisi Result</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom" id="btnSubmit">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Complete -->
    <div class="modal fade" id="modalComplete" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #27ae60, #2ecc71);">
                    <h5 class="modal-title" style="color: #fff;">
                        <i class="fas fa-check-circle"></i> Complete Sales Activity
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="formComplete" onsubmit="return validateFormComplete()">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" id="completeId" value="">
                        <input type="hidden" name="jenis_tugas_hidden" id="jenis_tugas_hidden" value="">
                        
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
                                <label class="form-label">Due Date</label>
                                <input type="text" id="completeDueDate" class="form-control" readonly style="background: #f8f9fa;">
                            </div>
                        </div>
                        
                        <!-- TRF Number di Complete Modal -->
                        <div class="trf-field" id="trfFieldComplete">
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Transaction Request Form</label>
                                    <input type="text" name="trf_number_display" id="trf_number_complete" class="form-control" readonly>
                                    <input type="hidden" name="trf_number" id="trf_number_complete_hidden" value="">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea id="completeDeskripsi" class="form-control" rows="2" readonly style="background: #f8f9fa;"></textarea>
                        </div>
                        
                        <hr>
                        
                        <div class="mb-3">
                            <label class="form-label">Result <span class="text-danger">*</span></label>
                            <textarea name="result" id="result" class="form-control" rows="4" placeholder="Masukkan hasil dari aktivitas (minimal 80 karakter)" required oninput="updateCharCount('result', 'resultCounter')"></textarea>
                            <div class="char-counter">
                                <span class="count" id="resultCounter">0</span> / 80 karakter (minimal)
                            </div>
                        </div>
                        
                        <!-- Customer Deal & Leads Number -->
                        <div class="deal-fields" id="dealFieldsComplete">
                            <hr>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Customer Deal</label>
                                    <select name="customer_deal" id="customer_deal" class="form-select">
                                        <option value="No">No</option>
                                        <option value="Yes">Yes</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Leads Number</label>
                                    <input type="text" name="leads_number" id="leads_number" class="form-control" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment Files <span class="text-danger">*</span></label>
                            <input type="file" name="attachment_files[]" id="attachment_files" class="form-control form-control-file" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" multiple required>
                            <div id="fileList" class="mt-2"><span class="text-muted">Belum ada file dipilih</span></div>
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

    <!-- Modal Detail -->
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

    <!-- Modal Delete -->
    <div class="modal fade" id="modalDelete" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
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
        // GET DATE WIB (GMT+7)
        // ============================================
        function getDateWIB(offsetDays) {
            var now = new Date();
            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            if (offsetDays) {
                today.setDate(today.getDate() + offsetDays);
            }
            var year = today.getFullYear();
            var month = String(today.getMonth() + 1).padStart(2, '0');
            var day = String(today.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        }

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
        // SET DEFAULT DATE TO TODAY + 7 DAYS (WIB)
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                var dateInput = document.getElementById('due_date');
                if (dateInput && !dateInput.value) {
                    dateInput.value = getDateWIB(7);
                }
            }, 100);
        });

        // ============================================
        // TOGGLE TRF & DEAL FIELDS
        // ============================================
        function toggleTRFField() {
            var jenisTugas = document.getElementById('jenis_tugas');
            var trfField = document.getElementById('trfField');
            var trfInput = document.getElementById('trf_number_add');
            
            if (jenisTugas && trfField) {
                if (jenisTugas.value === 'Negosiasi') {
                    trfField.classList.add('show');
                    if (trfInput && trfInput.value === '') {
                        fetch('salesactivity.php?generate_trf=1')
                            .then(response => response.json())
                            .then(data => {
                                if (data.trf_number) {
                                    trfInput.value = data.trf_number;
                                }
                            })
                            .catch(error => {
                                var now = new Date();
                                var month = now.getMonth() + 1;
                                var year = now.getFullYear();
                                var romanMonths = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
                                var romanMonth = romanMonths[month];
                                trfInput.value = '0001/GET-TR/JKT/' + romanMonth + '/' + year;
                            });
                    }
                } else {
                    trfField.classList.remove('show');
                    if (trfInput) trfInput.value = '';
                }
            }
        }

        function toggleDealFields() {
            var jenisTugas = document.getElementById('jenis_tugas');
            var dealFields = document.getElementById('dealFields');
            if (jenisTugas && dealFields) {
                if (jenisTugas.value === 'Negosiasi') {
                    dealFields.classList.add('show');
                } else {
                    dealFields.classList.remove('show');
                    document.getElementById('customer_deal_add').value = 'No';
                    document.getElementById('leads_number_add').value = '';
                }
            }
        }

        function toggleTRFFieldComplete() {
            var jenisTugas = document.getElementById('completeJenisTugas');
            var trfFieldComplete = document.getElementById('trfFieldComplete');
            if (jenisTugas && trfFieldComplete) {
                if (jenisTugas.value === 'Negosiasi') {
                    trfFieldComplete.classList.add('show');
                } else {
                    trfFieldComplete.classList.remove('show');
                }
            }
        }

        function toggleDealFieldsComplete() {
            var jenisTugas = document.getElementById('completeJenisTugas');
            var dealFieldsComplete = document.getElementById('dealFieldsComplete');
            if (jenisTugas && dealFieldsComplete) {
                if (jenisTugas.value === 'Negosiasi') {
                    dealFieldsComplete.classList.add('show');
                } else {
                    dealFieldsComplete.classList.remove('show');
                    document.getElementById('customer_deal').value = 'No';
                    document.getElementById('leads_number').value = '';
                }
            }
        }

        // ============================================
        // CHARACTER COUNTER
        // ============================================
        function updateCharCount(textareaId, counterId) {
            var textarea = document.getElementById(textareaId);
            var counter = document.getElementById(counterId);
            if (!textarea || !counter) return;
            var length = textarea.value.length;
            counter.textContent = length;
            counter.className = 'count ' + (length >= 80 ? 'valid' : 'invalid');
        }

        // ============================================
        // PREVIEW MULTIPLE FILES
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var attachmentInput = document.getElementById('attachment_files');
            if (attachmentInput) {
                attachmentInput.addEventListener('change', function() {
                    var fileList = document.getElementById('fileList');
                    if (!fileList) return;
                    fileList.innerHTML = '';
                    if (this.files.length === 0) {
                        fileList.innerHTML = '<span class="text-muted">Belum ada file dipilih</span>';
                        return;
                    }
                    var html = '<div class="alert alert-info"><i class="fas fa-file"></i> <strong>' + this.files.length + ' file</strong> dipilih:<br>';
                    for (var i = 0; i < this.files.length; i++) {
                        var file = this.files[i];
                        var size = (file.size / 1024).toFixed(1);
                        size = size > 1024 ? (size / 1024).toFixed(1) + ' MB' : size + ' KB';
                        html += '<span class="badge bg-secondary me-1 mb-1"><i class="fas fa-file"></i> ' + file.name + ' (' + size + ')</span> ';
                    }
                    html += '</div>';
                    fileList.innerHTML = html;
                });
            }
            
            var jenisTugas = document.getElementById('jenis_tugas');
            if (jenisTugas) {
                jenisTugas.addEventListener('change', function() {
                    toggleTRFField();
                    toggleDealFields();
                });
                setTimeout(function() {
                    toggleTRFField();
                    toggleDealFields();
                }, 100);
            }
        });

        // ============================================
        // VALIDASI FORM
        // ============================================
        function validateFormAdd() {
            var deskripsi = document.getElementById('deskripsi');
            var resultAdd = document.getElementById('result_add');
            var attachmentAdd = document.getElementById('attachment_file_add');
            var errors = [];
            
            if (deskripsi && deskripsi.value.trim().length < 80) {
                errors.push('Deskripsi minimal 80 karakter!');
                deskripsi.style.borderColor = '#e74c3c';
            } else if (deskripsi) {
                deskripsi.style.borderColor = '';
            }
            
            if (resultAdd && resultAdd.value.trim().length > 0 && resultAdd.value.trim().length < 80) {
                errors.push('Result minimal 80 karakter jika diisi!');
                resultAdd.style.borderColor = '#e74c3c';
            } else if (resultAdd) {
                resultAdd.style.borderColor = '';
            }
            
            if (resultAdd && resultAdd.value.trim().length > 0 && (!attachmentAdd || !attachmentAdd.files || attachmentAdd.files.length === 0)) {
                errors.push('Jika mengisi Result, Attachment file wajib diupload!');
                if (attachmentAdd) attachmentAdd.style.borderColor = '#e74c3c';
            } else if (attachmentAdd) {
                attachmentAdd.style.borderColor = '';
            }
            
            if (errors.length > 0) {
                alert('⚠️ ' + errors.join('\n'));
                return false;
            }
            return true;
        }

        function validateFormComplete() {
            var result = document.getElementById('result');
            var attachment = document.getElementById('attachment_files');
            var errors = [];
            
            if (result && result.value.trim().length < 80) {
                errors.push('Result minimal 80 karakter!');
                result.style.borderColor = '#e74c3c';
            } else if (result) {
                result.style.borderColor = '';
            }
            
            if (attachment && (!attachment.files || attachment.files.length === 0)) {
                errors.push('Minimal 1 file attachment wajib diupload!');
                attachment.style.borderColor = '#e74c3c';
            } else if (attachment) {
                attachment.style.borderColor = '';
            }
            
            if (errors.length > 0) {
                alert('⚠️ ' + errors.join('\n'));
                return false;
            }
            return true;
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
            document.getElementById('due_date').value = getDateWIB(7);
            document.getElementById('result_add').value = '';
            document.getElementById('attachment_file_add').value = '';
            document.getElementById('leads_number_add').value = '';
            document.getElementById('trf_number_add').value = '';
            document.getElementById('attachment_required').style.display = 'none';
            document.getElementById('attachment_file_add').required = false;
            document.getElementById('customer_deal_add').value = 'No';
            document.getElementById('dealFields').classList.remove('show');
            document.getElementById('trfField').classList.remove('show');
            var note = document.getElementById('resultNotification');
            if (note) note.remove();
            
            var deskripsiCounter = document.getElementById('deskripsiCounter');
            if (deskripsiCounter) {
                deskripsiCounter.textContent = '0';
                deskripsiCounter.className = 'count invalid';
            }
            var resultCounterAdd = document.getElementById('resultCounterAdd');
            if (resultCounterAdd) {
                resultCounterAdd.textContent = '0';
                resultCounterAdd.className = 'count invalid';
            }
        });

        // ============================================
        // COMPLETE ACTIVITY
        // ============================================
        function completeActivity(id, data) {
            if (data) {
                document.getElementById('completeId').value = data.id;
                document.getElementById('completeSubject').value = data.subject;
                document.getElementById('completeAccount').value = data.nama_pt || '-';
                document.getElementById('completeJenisTugas').value = data.jenis_tugas;
                document.getElementById('jenis_tugas_hidden').value = data.jenis_tugas;
                document.getElementById('completeDueDate').value = data.due_date ? new Date(data.due_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-';
                document.getElementById('completeDeskripsi').value = data.deskripsi || '-';
                document.getElementById('customer_deal').value = 'No';
                document.getElementById('leads_number').value = '';
                
                var trfNumber = data.trf_number || '';
                document.getElementById('trf_number_complete').value = trfNumber;
                document.getElementById('trf_number_complete_hidden').value = trfNumber;
                
                if (data.jenis_tugas === 'Negosiasi' && !trfNumber) {
                    fetch('salesactivity.php?generate_trf=1')
                        .then(response => response.json())
                        .then(response => {
                            if (response.trf_number) {
                                document.getElementById('trf_number_complete').value = response.trf_number;
                                document.getElementById('trf_number_complete_hidden').value = response.trf_number;
                            }
                        })
                        .catch(error => console.error('Error generating TRF:', error));
                }
                
                document.getElementById('result').value = '';
                document.getElementById('attachment_files').value = '';
                
                setTimeout(function() {
                    toggleTRFFieldComplete();
                    toggleDealFieldsComplete();
                }, 100);
                
                var fileList = document.getElementById('fileList');
                if (fileList) fileList.innerHTML = '<span class="text-muted">Belum ada file dipilih</span>';
                
                var resultCounter = document.getElementById('resultCounter');
                if (resultCounter) {
                    resultCounter.textContent = '0';
                    resultCounter.className = 'count invalid';
                }
                
                var modal = new bootstrap.Modal(document.getElementById('modalComplete'));
                modal.show();
            } else {
                alert('Data tidak ditemukan!');
            }
        }

        // ============================================
        // SHOW LEADS NUMBER WHEN DEAL = YES
        // ============================================
        document.getElementById('customer_deal').addEventListener('change', function() {
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

        document.getElementById('customer_deal_add').addEventListener('change', function() {
            var leadsInput = document.getElementById('leads_number_add');
            if (this.value === 'Yes') {
                leadsInput.value = 'Akan digenerate otomatis saat Complete';
                leadsInput.style.color = '#27ae60';
                leadsInput.style.fontWeight = '600';
            } else {
                leadsInput.value = '';
                leadsInput.style.color = '';
                leadsInput.style.fontWeight = '';
            }
        });

        // ============================================
        // RESULT VALIDATION
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var resultInput = document.getElementById('result_add');
            var attachmentInput = document.getElementById('attachment_file_add');
            var attachmentRequired = document.getElementById('attachment_required');
            
            if (resultInput) {
                resultInput.addEventListener('input', function() {
                    if (this.value.trim() !== '') {
                        attachmentRequired.style.display = 'inline';
                        attachmentInput.required = true;
                        if (!document.getElementById('resultNotification')) {
                            var note = document.createElement('div');
                            note.id = 'resultNotification';
                            note.className = 'alert alert-warning mt-2';
                            note.innerHTML = '<i class="fas fa-info-circle"></i> Karena Anda mengisi Result, file attachment wajib diupload.';
                            resultInput.parentNode.appendChild(note);
                        }
                    } else {
                        attachmentRequired.style.display = 'none';
                        attachmentInput.required = false;
                        var note = document.getElementById('resultNotification');
                        if (note) note.remove();
                    }
                });
            }
            
            var deskripsi = document.getElementById('deskripsi');
            if (deskripsi) updateCharCount('deskripsi', 'deskripsiCounter');
            var resultAdd = document.getElementById('result_add');
            if (resultAdd) updateCharCount('result_add', 'resultCounterAdd');
            var resultComplete = document.getElementById('result');
            if (resultComplete) updateCharCount('result', 'resultCounter');
        });

        // ============================================
        // DETAIL ACTIVITY
        // ============================================
        function detailActivity(data) {
            var statusLabel = data.status == 'in_progress' ? 'In Progress' : (data.status == 'overdue' ? 'Overdue' : 'Completed');
            var statusBadge = data.status == 'in_progress' ? 'in_progress' : (data.status == 'overdue' ? 'overdue' : 'completed');
            
            var isMiddleProspek = data.jenis_tugas == 'Prospecting' && data.has_negosiasi_kontrak == 0;
            var isHotProspek = data.jenis_tugas == 'Negosiasi' && data.has_kontrak == 0 && data.has_lost_prospek == 0 && !(data.status == 'completed' && data.customer_deal == 'No');
            var isLostProspek = data.jenis_tugas == 'Negosiasi' && data.status == 'completed' && data.customer_deal == 'No';
            var isDeal = data.jenis_tugas == 'Kontrak';
            
            var pipelineBadge = '';
            if (isMiddleProspek) {
                pipelineBadge = '<span class="badge-middle-prospek ms-2"><i class="fas fa-user-tie"></i> Middle Prospek</span>';
            } else if (isHotProspek) {
                pipelineBadge = '<span class="badge-hot-prospek ms-2"><i class="fas fa-fire"></i> Hot Prospek</span>';
            } else if (isLostProspek) {
                pipelineBadge = '<span class="badge-lost ms-2"><i class="fas fa-times-circle"></i> Lost Prospek</span>';
            } else if (isDeal) {
                pipelineBadge = '<span class="badge-deal ms-2"><i class="fas fa-handshake"></i> Deal</span>';
            }
            
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <span class="badge-status ${statusBadge}">${statusLabel}</span>
                        ${data.status == 'completed' && data.completed_at ? `<small class="text-muted ms-2">Selesai pada: ${new Date(data.completed_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</small>` : ''}
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
                        <span class="badge-tugas ${data.jenis_tugas ? data.jenis_tugas.replace(/ /g, '_').replace(/\//g, '_') : ''}">${data.jenis_tugas || '-'}</span>
                        ${pipelineBadge}
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">TR Number</div>
                    <div class="detail-value">
                        ${data.trf_number ? `<a href="detailtr.php?trf=${encodeURIComponent(data.trf_number)}" target="_blank" class="trf-link"><span class="badge-trf"><i class="fas fa-file-signature"></i> ${data.trf_number}</span></a>` : '-'}
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Due Date</div>
                    <div class="detail-value">
                        ${data.due_date ? new Date(data.due_date).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '-'}
                    </div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Deskripsi</div>
                    <div class="detail-value">${data.deskripsi || '-'}</div>
                </div>
                ${data.status == 'completed' ? `
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
                    <div class="detail-value">${data.leads_number ? `<code>${data.leads_number}</code>` : '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Attachment</div>
                    <div class="detail-value">
                        ${data.attachment_file ? (function() {
                            try {
                                var files = JSON.parse(data.attachment_file);
                                if (files.files && files.files.length > 0) {
                                    var html = '<div class="d-flex flex-wrap gap-2">';
                                    for (var i = 0; i < files.files.length; i++) {
                                        var filePath = files.files[i];
                                        var fileName = files.names && files.names[i] ? files.names[i] : filePath.split('/').pop();
                                        html += '<a href="' + filePath + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file"></i> ' + fileName + '</a>';
                                    }
                                    html += '</div>';
                                    return html;
                                } else {
                                    return '<a href="' + data.attachment_file + '" target="_blank"><i class="fas fa-file-image"></i> Lihat File</a>';
                                }
                            } catch(e) {
                                return '<a href="' + data.attachment_file + '" target="_blank"><i class="fas fa-file-image"></i> Lihat File</a>';
                            }
                        })() : '-'}
                    </div>
                </div>
                ` : ''}
                <div class="detail-item">
                    <div class="detail-label">Sales</div>
                    <div class="detail-value">${data.sales_name || '-'}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Dibuat Pada</div>
                    <div class="detail-value">${data.created_at ? new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-'}</div>
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
            document.getElementById('due_date').value = data.due_date || '';
            
            setTimeout(function() {
                toggleTRFField();
                toggleDealFields();
            }, 100);
            
            setTimeout(function() {
                updateCharCount('deskripsi', 'deskripsiCounter');
            }, 300);
            
            var modal = new bootstrap.Modal(document.getElementById('modalSalesActivity'));
            modal.show();
        }

        // ============================================
        // DELETE ACTIVITY
        // ============================================
        function deleteActivity(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }

        // ============================================
        // INIT CHARTS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            var ctx1 = document.getElementById('statusChart').getContext('2d');
            var inProgress = <?= $totalInProgress ?>;
            var completed = <?= $totalCompleted ?>;
            var overdue = <?= $overdueCount ?>;
            
            new Chart(ctx1, {
                type: 'doughnut',
                data: {
                    labels: ['In Progress', 'Completed', 'Overdue'],
                    datasets: [{
                        data: [inProgress, completed, overdue],
                        backgroundColor: ['#2980b9', '#27ae60', '#e74c3c'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: { size: 11, weight: '600' }
                            }
                        }
                    }
                }
            });

            var ctx2 = document.getElementById('prospekChart').getContext('2d');
            var middleProspek = <?= $totalMiddleProspek ?>;
            var hotProspek = <?= $totalHotProspek ?>;
            var lostProspek = <?= $totalLostProspek ?>;
            var deal = <?= $totalDeal ?>;
            
            new Chart(ctx2, {
                type: 'doughnut',
                data: {
                    labels: ['Middle Prospek', 'Hot Prospek', 'Lost Prospek', 'Deal'],
                    datasets: [{
                        data: [middleProspek, hotProspek, lostProspek, deal],
                        backgroundColor: ['#f39c12', '#ff6b6b', '#e74c3c', '#8e44ad'],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                font: { size: 11, weight: '600' }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>