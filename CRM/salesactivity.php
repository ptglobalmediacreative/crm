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

// Tambahkan CSS untuk link TR Number
function addTRLinkStyle() {
    echo '<style>
        .trf-link {
            color: #2980b9;
            text-decoration: none;
            display: inline-block;
        }
        .trf-link:hover {
            color: #1a6d9e;
            text-decoration: underline;
        }
        .trf-link .badge-trf {
            transition: all 0.3s ease;
        }
        .trf-link:hover .badge-trf {
            background: rgba(52, 152, 219, 0.25);
        }
    </style>';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sales Activity - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }

        /* ============================================
           SIDEBAR STYLING - SAMA SEPERTI DASHBOARD
           ============================================ */
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

        /* ============================================
           MAIN CONTENT
           ============================================ */
        .main-content { margin-left: 260px; padding: 30px; width: 100%; min-height: 100vh; }
        
        .page-header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 30px; flex-wrap: wrap; gap: 15px; 
        }
        .page-header h4 { 
            font-weight: 800; color: #0e1a2b; font-size: 24px; margin:0; 
            letter-spacing: -0.5px;
        }
        .page-header h4 span { color: #ffd700; }
        .page-header .filter-area { display: flex; gap: 10px; align-items: center; }
        .page-header .filter-area select, .page-header .filter-area input { border-radius: 8px; border: 1px solid #e0e4ea; font-size: 13px; }

        /* ============================================
           STAT CARDS
           ============================================ */
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
        .stat-card .stat-icon.red { background: rgba(231, 76, 60, 0.12); color: #e74c3c; }
        .stat-card .stat-number { font-size: 24px; font-weight: 800; color: #0e1a2b; margin-bottom: 2px; }
        .stat-card .stat-label { font-size: 13px; color: #888; }

        /* ============================================
           ROW PIPELINE & CHART
           ============================================ */
        .grid-2-col { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px; }
        @media (max-width: 991px) { .grid-2-col { grid-template-columns: 1fr; } }
        
        .pipeline-card, .chart-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            transition: all 0.3s ease;
        }
        .pipeline-card:hover, .chart-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        
        .pipeline-card h6, .chart-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; }
        .pipeline-bars { display: flex; height: 6px; border-radius: 4px; overflow: hidden; margin-bottom: 12px; }
        .pipeline-bars .bar { height: 100%; transition: width 0.5s; }
        .pipeline-bars .bar.new { background: #3498db; }
        .pipeline-bars .bar.middle { background: #f39c12; }
        .pipeline-bars .bar.hot { background: #e74c3c; }
        .pipeline-bars .bar.deal { background: #2ecc71; }
        .pipeline-bars .bar.lost { background: #95a5a6; }

        .pipeline-stats { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; text-align: center; }
        .pipeline-stats .p-item .p-label { font-size: 11px; color: #888; display: block; }
        .pipeline-stats .p-item .p-value { font-size: 16px; font-weight: 700; color: #0e1a2b; }
        .pipeline-stats .p-item .p-value.new { color: #3498db; }
        .pipeline-stats .p-item .p-value.middle { color: #f39c12; }
        .pipeline-stats .p-item .p-value.hot { color: #e74c3c; }
        .pipeline-stats .p-item .p-value.deal { color: #2ecc71; }
        .pipeline-stats .p-item .p-value.lost { color: #95a5a6; }

        .chart-wrapper { height: 220px; width: 100%; }

        /* ============================================
           RECENT ACTIVITIES
           ============================================ */
        .activity-card { 
            background: #fff; border-radius: 16px; padding: 24px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; 
            height: 100%; transition: all 0.3s ease;
        }
        .activity-card:hover { box-shadow: 0 8px 25px rgba(14,26,43,0.08); border-color: #ffd700; }
        .activity-card h6 { font-weight: 600; margin-bottom: 20px; color: #0e1a2b; }
        .activity-item { 
            display: flex; gap: 14px; padding: 12px 0; 
            border-bottom: 1px solid #f0f2f5; align-items: flex-start; 
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-item .act-icon { 
            width: 36px; height: 36px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 14px; flex-shrink: 0; 
        }
        .activity-item .act-icon.gold { background: rgba(255, 215, 0, 0.1); color: #d4a017; }
        .activity-item .act-icon.blue { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .activity-item .act-icon.green { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .activity-item .act-icon.red { background: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        .activity-item .act-info { flex: 1; }
        .activity-item .act-info .act-title { font-weight: 600; font-size: 14px; color: #0e1a2b; }
        .activity-item .act-info .act-desc { font-size: 13px; color: #7f8c8d; margin-top: 2px; }
        .activity-item .act-info .act-time { font-size: 11px; color: #bdc3c7; margin-top: 4px; display: block; }

        /* ============================================
           MOBILE
           ============================================ */
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
        .mobile-toggle { display: none; }
    </style>
</head>
<body>

    <!-- SIDEBAR SAMA SEPERTI DASHBOARD -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
            <div class="brand-text">
                <h5>PT GANDA <span>ELANG</span></h5>
                <small>TANGGUH</small>
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
        <?php if (in_array('detail_transaction_request', $menuNames)): ?>
            <a href="detailtr.php" class="nav-item"><i class="fas fa-file-alt"></i> Detail TR</a>
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
                <h4>Sales <span>Activity</span></h4>
            </div>
            <div class="filter-area">
                <span style="font-weight:600; color:#555; font-size:14px;">Filter:</span>
                <?php if (!$isSalesRole): ?>
                <select class="form-select form-select-sm" id="filterSales" onchange="applyFilter()" style="background:#f8f9fa;">
                    <option value="0">Semua Sales</option>
                    <?php foreach ($allSalesList as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($filterSalesId == $s['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['full_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="month" id="filterMonth" class="form-control form-control-sm" style="width:160px; background:#f8f9fa;" value="<?= $filterMonth ?>" onchange="applyFilter()">
            </div>
        </div>

        <!-- STAT CARDS (REAL DATA) -->
        <div class="stat-grid">
            <!-- 1. TOTAL LEADS -->
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= number_format($totalLeads) ?></div>
                <div class="stat-label">Total Leads</div>
            </div>
            
            <!-- 2. OPEN DEALS -->
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-briefcase"></i></div>
                <div class="stat-number"><?= number_format($pipelineCounts['Deal']) ?></div>
                <div class="stat-label">Open Deals</div>
            </div>

            <!-- 3. REVENUE FORECAST -->
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-money-bill-wave"></i></div>
                <div class="stat-number">Rp <?= number_format($totalRevenue, 0, ',', '.') ?></div>
                <div class="stat-label">Revenue Forecast</div>
            </div>
            
            <!-- 4. FILTERED SALES NAME -->
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-number" style="font-size:18px;"><?= htmlspecialchars($filteredSalesName) ?></div>
                <div class="stat-label">Sedang Ditinjau</div>
            </div>
        </div>

        <!-- GRID: PIPELINE (REAL DATA) & CHART -->
        <div class="grid-2-col">
            <!-- Pipeline -->
            <div class="pipeline-card">
                <h6><i class="fas fa-filter" style="color:#ffd700;"></i> Sales Pipeline</h6>
                
                <?php 
                // Hitung total pipeline untuk persentase
                $totalPipeline = array_sum($pipelineCounts);
                $pctNew = $totalPipeline > 0 ? ($pipelineCounts['New Lead'] / $totalPipeline * 100) : 0;
                $pctMid = $totalPipeline > 0 ? ($pipelineCounts['Middle Prospek'] / $totalPipeline * 100) : 0;
                $pctHot = $totalPipeline > 0 ? ($pipelineCounts['Hot Prospek'] / $totalPipeline * 100) : 0;
                $pctDeal = $totalPipeline > 0 ? ($pipelineCounts['Deal'] / $totalPipeline * 100) : 0;
                $pctLost = $totalPipeline > 0 ? ($pipelineCounts['Lost Deal'] / $totalPipeline * 100) : 0;
                ?>
                
                <div class="pipeline-bars">
                    <div class="bar new" style="width: <?= $pctNew ?>%;"></div>
                    <div class="bar middle" style="width: <?= $pctMid ?>%;"></div>
                    <div class="bar hot" style="width: <?= $pctHot ?>%;"></div>
                    <div class="bar deal" style="width: <?= $pctDeal ?>%;"></div>
                    <div class="bar lost" style="width: <?= $pctLost ?>%;"></div>
                </div>
                <div class="pipeline-stats">
                    <div class="p-item"><span class="p-label">New Lead</span><span class="p-value new"><?= $pipelineCounts['New Lead'] ?></span></div>
                    <div class="p-item"><span class="p-label">Middle</span><span class="p-value middle"><?= $pipelineCounts['Middle Prospek'] ?></span></div>
                    <div class="p-item"><span class="p-label">Hot</span><span class="p-value hot"><?= $pipelineCounts['Hot Prospek'] ?></span></div>
                    <div class="p-item"><span class="p-label">Deal</span><span class="p-value deal"><?= $pipelineCounts['Deal'] ?></span></div>
                    <div class="p-item"><span class="p-label">Lost</span><span class="p-value lost"><?= $pipelineCounts['Lost Deal'] ?></span></div>
                </div>
            </div>

            <!-- Chart Tren (Multi Sales Comparison) -->
            <div class="chart-card">
                <h6><i class="fas fa-chart-area" style="color:#2980b9;"></i> Tren Aktivitas</h6>
                <div class="chart-wrapper"><canvas id="trendChart"></canvas></div>
            </div>
        </div>

        <!-- GRID: AKTIVITAS TERBARU & LAPORAN SALES -->
        <div class="grid-2-col">
            <!-- Recent Activities (REAL DATA) -->
            <div class="activity-card">
                <div style="display:flex; justify-content:space-between;">
                    <h6><i class="fas fa-clock" style="color:#d4a017;"></i> Aktivitas Terbaru</h6>
                    <a href="salesactivity.php" style="font-size:12px; color:#2980b9; text-decoration:none;">Lihat Semua</a>
                </div>
                
                <?php if (!empty($recentActivities)): ?>
                    <?php foreach ($recentActivities as $act): 
                        // Ambil inisial nama Sales (maksimal 2 huruf)
                        $salesInitial = '';
                        if (!empty($act['sales_name'])) {
                            $names = explode(' ', $act['sales_name']);
                            if (count($names) >= 2) {
                                $salesInitial = strtoupper(substr($names[0], 0, 1) . substr($names[1], 0, 1));
                            } else {
                                $salesInitial = strtoupper(substr($act['sales_name'], 0, 2));
                            }
                        }
                    ?>
                    <div class="activity-item">
                        <div class="act-icon gold"><i class="fas fa-file-alt"></i></div>
                        <div class="act-info">
                            <div class="act-title">
                                <?php if (!empty($salesInitial)): ?>
                                    <span class="badge bg-primary me-2" style="font-size:11px;"><?= $salesInitial ?></span>
                                <?php endif; ?>
                                <?= htmlspecialchars($act['subject']) ?>
                            </div>
                            <div class="act-desc"><?= htmlspecialchars($act['nama_pt'] ?? '-') ?> - <?= htmlspecialchars($act['jenis_tugas']) ?></div>
                            <span class="act-time"><?= date('d M H:i', strtotime($act['created_at'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">Belum ada aktivitas.</div>
                <?php endif; ?>
            </div>

            <!-- Monthly Sales Report (Ringkasan Sales REAL - Filtered by Month) -->
            <div class="activity-card">
                <div style="display:flex; justify-content:space-between;">
                    <h6><i class="fas fa-chart-simple" style="color:#27ae60;"></i> Performa Sales (<?= date('F Y', strtotime($filterMonth . '-01')) ?>)</h6>
                </div>
                <div style="overflow-y:auto; max-height:300px;">
                    <table class="table table-sm table-hover" style="font-size:14px; margin:0;">
                        <thead><tr><th>Sales</th><th class="text-center">Deal</th><th class="text-center">Lost</th></tr></thead>
                        <tbody>
                            <?php 
                            $reportData = $filteredReportData ?? [];
                            if ($filterSalesId > 0) {
                                // Jika filter satu sales
                                $totalDeal = 0; $totalLost = 0;
                                foreach($filteredReportData as $m) { $totalDeal += $m['total_deal']; $totalLost += $m['total_lost']; }
                                echo "<tr><td><strong>" . htmlspecialchars($filteredSalesName) . "</strong></td>
                                      <td class='text-center text-deal'>$totalDeal</td>
                                      <td class='text-center text-lost'>$totalLost</td></tr>";
                            } else {
                                // Jika semua sales
                                foreach($filteredReportData as $sales):
                                    $gtD = 0; $gtL = 0;
                                    foreach($sales['data'] as $m) { $gtD += $m['total_deal']; $gtL += $m['total_lost']; }
                            ?>
                            <tr>
                                <td><i class="fas fa-user-circle me-2 text-secondary"></i> <?= htmlspecialchars($sales['name']) ?></td>
                                <td class="text-center text-deal"><strong><?= $gtD ?></strong></td>
                                <td class="text-center text-lost"><?= $gtL ?></td>
                            </tr>
                            <?php endforeach; } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div class="text-center mt-4 text-muted" style="font-size:12px;">&copy; <?= date('Y') ?> PT Ganda Elang Tangguh - CRM</div>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // CHART TREN (SINGLE atau MULTI SALES)
        // ============================================
        const ctx = document.getElementById('trendChart').getContext('2d');
        
        let trendChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: <?= json_encode($chartDatasets) ?>
            },
            options: {
                responsive: true, 
                maintainAspectRatio: false,
                plugins: { 
                    legend: { 
                        display: <?= ($filterSalesId > 0) ? 'false' : 'true' ?>, // Tampilkan legenda hanya jika multi sales
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            font: { family: 'Inter', size: 12 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#2980b9',
                        titleFont: { weight: 'bold', size: 14 },
                        bodyColor: '#1a1a2e',
                        bodyFont: { size: 13 },
                        borderColor: '#2980b9',
                        borderWidth: 2,
                        cornerRadius: 10,
                        padding: 12,
                        displayColors: true
                    }
                },
                scales: {
                    y: { 
                        beginAtZero: true, 
                        grid: { color: 'rgba(0,0,0,0.04)' }, 
                        ticks: { stepSize: 1 } 
                    },
                    x: { grid: { display: false } }
                }
            }
        });

        // ============================================
        // FUNGSI APPLY FILTER (Reload untuk Table & Pipeline)
        // ============================================
        function applyFilter() {
            const salesId = document.getElementById('filterSales') ? document.getElementById('filterSales').value : 0;
            const month = document.getElementById('filterMonth').value;
            
            // Refresh halaman dengan filter bulan dan sales terbaru agar semua elemen tabel berubah
            window.location.href = '?sales_id=' + salesId + '&month=' + month;
        }
    </script>
</body>
</html>