<?php
require_once 'config.php';

// Set timezone ke WIB
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
// FUNGSI GENERATE NOMOR
// ============================================
function getBulanRomawi($month) {
    $romawi = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
    return $romawi[(int)$month];
}

function generateTRNumber($db) {
    $tahun = date('Y');
    $bulanRomawi = getBulanRomawi(date('n'));
    $prefix = "/GET-TR/JKT/{$bulanRomawi}/{$tahun}";

    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(tr_number, '/', 1) AS UNSIGNED))
        FROM activity_details
        WHERE tr_number LIKE ?
    ");
    $stmt->execute(['%' . $prefix]);
    $maxSequence = (int)$stmt->fetchColumn();

    return str_pad((string)($maxSequence + 1), 4, '0', STR_PAD_LEFT)
        . $prefix;
}

function generateDINumber($db) {
    $tahun = date('Y');
    $bulanRomawi = getBulanRomawi(date('n'));
    $prefix = "/GET-DI/JKT/{$bulanRomawi}/{$tahun}";

    $stmt = $db->prepare("
        SELECT MAX(CAST(SUBSTRING_INDEX(di_number, '/', 1) AS UNSIGNED))
        FROM activity_details
        WHERE di_number LIKE ?
    ");
    $stmt->execute(['%' . $prefix]);
    $maxSequence = (int)$stmt->fetchColumn();

    return str_pad((string)($maxSequence + 1), 4, '0', STR_PAD_LEFT)
        . $prefix;
}

// ============================================
// AMBIL DATA SALES ACTIVITY
// ============================================
$leadsId = isset($_GET['leads_id']) ? (int)$_GET['leads_id'] : 0;

if (!$leadsId) {
    setFlash('Leads ID tidak ditemukan!', 'danger');
    redirect('salesactivity.php');
}

$stmt = $db->prepare("SELECT sa.*, a.nama_pt, a.badan_usaha, a.bidang_usaha, a.nama_pic, a.no_hp_pic, a.email_pic, u.full_name as sales_name
                      FROM sales_activities sa 
                      LEFT JOIN accounts a ON sa.account_id = a.id 
                      LEFT JOIN users u ON sa.sales_id = u.id
                      WHERE sa.id = ?");
$stmt->execute([$leadsId]);
$activity = $stmt->fetch();

if (!$activity) {
    setFlash('Data aktivitas tidak ditemukan!', 'danger');
    redirect('salesactivity.php');
}

// ============================================
// AMBIL DATA USER
// ============================================
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// ============================================
// UPDATE STATUS OVERDUE OTOMATIS (WIB)
// ============================================
$stmt = $db->prepare("UPDATE activity_details SET status = 'overdue' 
                      WHERE sales_activity_id = ? 
                      AND status = 'in_progress' 
                      AND due_date IS NOT NULL 
                      AND due_date < DATE_ADD(NOW(), INTERVAL 7 HOUR)");
$stmt->execute([$leadsId]);

// ============================================
// HELPER: HAPUS DATA TR YANG SUDAH TIDAK TERPAKAI
// ============================================
function deleteUnusedTransactionRequestData($db, $trNumber) {
    if (empty($trNumber)) return;

    $check = $db->prepare("SELECT COUNT(*) FROM activity_details WHERE tr_number = ?");
    $check->execute([$trNumber]);

    if ((int)$check->fetchColumn() > 0) return;

    $tableColumns = [
        'detail_transaction_requests' => 'trf_number',
        'transaction_requests' => 'trf_number',
        'tr_additional_costs' => 'trf_number',
        'tr_additional_cost_items' => 'trf_number',
        'tr_approval_history' => 'trf_number',
        'tr_cost_calculations' => 'trf_number',
        'tr_detail_units' => 'trf_number',
        'tr_mediators' => 'trf_number',
        'tr_product_supports' => 'trf_number',
        'tr_term_of_payments' => 'trf_number'
    ];

    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
        $stmt->execute([$trNumber]);
    }
}

// ============================================
// HELPER: HAPUS DATA DI YANG SUDAH TIDAK TERPAKAI
// ============================================
function deleteUnusedDeliveryInstructionData($db, $diNumber) {
    if (empty($diNumber)) return;

    $check = $db->prepare("SELECT COUNT(*) FROM activity_details WHERE di_number = ?");
    $check->execute([$diNumber]);

    if ((int)$check->fetchColumn() > 0) return;

    $tableColumns = [
        'di_approval_history' => 'di_number',
        'di_units' => 'di_number',
        'di_accessories' => 'di_number',
        'di_logistics' => 'di_number',
        'di_product_supports' => 'di_number',
        'detail_delivery_instructions' => 'di_number'
    ];

    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare("DELETE FROM `{$table}` WHERE `{$column}` = ?");
        $stmt->execute([$diNumber]);
    }
}

// ============================================
// HELPER: RENUMBER TR SELURUH DATABASE
// ============================================
function renumberAllTransactionRequestsFromActivities($db, $period = null) {
    // Hanya renumber periode yang memang berubah. Jangan menyentuh TR bulan lain.
    if ($period !== null) {
        $period = trim((string)$period);
        if (!preg_match('/^(?:I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)\/\d{4}$/', $period)) {
            throw new RuntimeException('Periode TR tidak valid untuk renumber: ' . $period);
        }
    }

    $sql = "
        SELECT
            tr_number AS old_tr,
            MIN(created_at) AS first_created_at
        FROM activity_details
        WHERE tr_number IS NOT NULL
          AND TRIM(tr_number) <> ''
    ";
    $params = [];

    if ($period !== null) {
        $sql .= " AND tr_number LIKE ? ";
        $params[] = '%/GET-TR/JKT/' . $period;
    }

    $sql .= " GROUP BY tr_number ORDER BY first_created_at ASC, old_tr ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) return 0;

    if ($period === null) {
        foreach ($rows as &$row) {
            if (preg_match('#^\d{4}/GET-TR/JKT/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/\d{4}$#', trim($row['old_tr']), $m)) {
                $row['period'] = $m[1];
            } else {
                throw new RuntimeException('Periode TR tidak dapat ditentukan untuk: ' . $row['old_tr']);
            }
        }
        unset($row);
        usort($rows, static function ($a, $b) {
            return [$a['period'], $a['first_created_at'], $a['old_tr']] <=>
                   [$b['period'], $b['first_created_at'], $b['old_tr']];
        });
    } else {
        foreach ($rows as &$row) $row['period'] = $period;
        unset($row);
    }

    $mapping = [];
    $sequence = 0;
    foreach ($rows as $row) {
        $sequence++;
        $mapping[$row['old_tr']] =
            str_pad((string)$sequence, 4, '0', STR_PAD_LEFT) .
            '/GET-TR/JKT/' . $row['period'];
    }

    $tableColumns = [
        'activity_details' => 'tr_number',
        'detail_transaction_requests' => 'trf_number',
        'transaction_requests' => 'trf_number',
        'tr_additional_costs' => 'trf_number',
        'tr_additional_cost_items' => 'trf_number',
        'tr_approval_history' => 'trf_number',
        'tr_cost_calculations' => 'trf_number',
        'tr_detail_units' => 'trf_number',
        'tr_mediators' => 'trf_number',
        'tr_product_supports' => 'trf_number',
        'tr_term_of_payments' => 'trf_number'
    ];

    // Temporary key dijamin <= 50 karakter.
    $token = '__TRTMP_' . bin2hex(random_bytes(8));
    $temporaryMap = [];

    // Tahap 1: semua old number -> temporary number.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $token . '_' . substr(hash('sha256', $oldTr), 0, 24);
        if (strlen($temporaryTr) > 50) {
            throw new RuntimeException('Temporary TR number melebihi 50 karakter.');
        }
        $temporaryMap[$oldTr] = $temporaryTr;

        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $stmt->execute([$temporaryTr, $oldTr]);
        }
    }

    // Tahap 2: temporary number -> nomor final.
    foreach ($mapping as $oldTr => $newTr) {
        $temporaryTr = $temporaryMap[$oldTr];
        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $stmt->execute([$newTr, $temporaryTr]);
        }
    }

    // Safety check: tidak boleh ada temporary TR yang tertinggal.
    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE ?");
        $stmt->execute([$token . '%']);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Temporary TR masih tersisa di tabel ' . $table . '. Transaction dibatalkan.');
        }
    }

    return count($mapping);
}

// ============================================
// HELPER: RENUMBER DI SELURUH DATABASE
// ============================================
function renumberAllDeliveryInstructions($db, $period = null) {
    if ($period !== null) {
        $period = trim((string)$period);
        if (!preg_match('/^(?:I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)\/\d{4}$/', $period)) {
            throw new RuntimeException('Periode DI tidak valid untuk renumber: ' . $period);
        }
    }

    $sql = "
        SELECT
            di_number AS old_di,
            MIN(created_at) AS first_created_at
        FROM activity_details
        WHERE di_number IS NOT NULL
          AND TRIM(di_number) <> ''
    ";
    $params = [];
    if ($period !== null) {
        $sql .= " AND di_number LIKE ? ";
        $params[] = '%/GET-DI/JKT/' . $period;
    }
    $sql .= " GROUP BY di_number ORDER BY first_created_at ASC, old_di ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;

    if ($period === null) {
        foreach ($rows as &$row) {
            if (preg_match('#^\d{4}/GET-DI/JKT/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/\d{4}$#', trim($row['old_di']), $m)) {
                $row['period'] = $m[1];
            } else {
                throw new RuntimeException('Periode DI tidak dapat ditentukan untuk: ' . $row['old_di']);
            }
        }
        unset($row);
        usort($rows, static function ($a, $b) {
            return [$a['period'], $a['first_created_at'], $a['old_di']] <=>
                   [$b['period'], $b['first_created_at'], $b['old_di']];
        });
    } else {
        foreach ($rows as &$row) $row['period'] = $period;
        unset($row);
    }

    $mapping = [];
    $sequence = 0;
    foreach ($rows as $row) {
        $sequence++;
        $mapping[$row['old_di']] =
            str_pad((string)$sequence, 4, '0', STR_PAD_LEFT) .
            '/GET-DI/JKT/' . $row['period'];
    }

    $tableColumns = [
        'activity_details' => 'di_number',
        'detail_delivery_instructions' => 'di_number',
        'di_approval_history' => 'di_number',
        'di_units' => 'di_number',
        'di_accessories' => 'di_number',
        'di_logistics' => 'di_number',
        'di_product_supports' => 'di_number'
    ];

    $token = '__DITMP_' . bin2hex(random_bytes(8));
    $temporaryMap = [];

    foreach ($mapping as $oldDi => $newDi) {
        $temporaryDi = $token . '_' . substr(hash('sha256', $oldDi), 0, 24);
        if (strlen($temporaryDi) > 50) {
            throw new RuntimeException('Temporary DI number melebihi 50 karakter.');
        }
        $temporaryMap[$oldDi] = $temporaryDi;

        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $stmt->execute([$temporaryDi, $oldDi]);
        }
    }

    foreach ($mapping as $oldDi => $newDi) {
        $temporaryDi = $temporaryMap[$oldDi];
        foreach ($tableColumns as $table => $column) {
            $stmt = $db->prepare("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?");
            $stmt->execute([$newDi, $temporaryDi]);
        }
    }

    // Safety check: tidak boleh ada temporary DI yang tertinggal.
    foreach ($tableColumns as $table => $column) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` LIKE ?");
        $stmt->execute([$token . '%']);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException('Temporary DI masih tersisa di tabel ' . $table . '. Transaction dibatalkan.');
        }
    }

    return count($mapping);
}

// ============================================
// PROSES TAMBAH DETAIL AKTIVITAS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        // Sales hanya bisa menambah aktivitas untuk leads miliknya
        if ($role === 'sales') {
            if ($activity['sales_id'] != $userId) {
                setFlash('Anda tidak memiliki akses untuk leads ini!', 'danger');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            }
        } elseif (!canAdd('sales_activity')) {
            setFlash('Anda tidak memiliki akses!', 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
        
        $subject = bersihkan($_POST['subject']);
        $jenis_tugas = bersihkan($_POST['jenis_tugas']);
        $deskripsi = trim($_POST['deskripsi']);
        $due_date = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;
        
        $errors = [];
        if (empty($subject)) $errors[] = 'Subject wajib diisi!';
        if (empty($jenis_tugas)) $errors[] = 'Jenis Tugas wajib dipilih!';
        if (empty($due_date)) $errors[] = 'Due Date wajib diisi!';
        if (strlen($deskripsi) < 50) $errors[] = 'Deskripsi minimal 50 karakter!';
        
        // Generate TR Number jika jenis_tugas = Negosiasi
        $tr_number = NULL;
        if ($jenis_tugas === 'Negosiasi') {
            $tr_number = generateTRNumber($db);
        }
        
        // Untuk Kontrak, Delivery Order, dan After Sales ambil TR Number dari Negosiasi sebelumnya
        if ($jenis_tugas === 'Kontrak' || $jenis_tugas === 'Delivery Order' || $jenis_tugas === 'After Sales') {
            $stmt = $db->prepare("SELECT tr_number FROM activity_details 
                                  WHERE sales_activity_id = ? AND jenis_tugas = 'Negosiasi' 
                                  ORDER BY id DESC LIMIT 1");
            $stmt->execute([$leadsId]);
            $tr_negosiasi = $stmt->fetchColumn();
            if ($tr_negosiasi) {
                $tr_number = $tr_negosiasi;
            }
        }
        
        if (empty($errors)) {
            $db->beginTransaction();
            
            try {
                $stmt = $db->prepare("INSERT INTO activity_details (sales_activity_id, subject, jenis_tugas, deskripsi, due_date, tr_number, status) VALUES (?, ?, ?, ?, ?, ?, 'in_progress')");
                $stmt->execute([$leadsId, $subject, $jenis_tugas, $deskripsi, $due_date, $tr_number]);
                
                // AUTO CREATE DETAIL TRANSACTION REQUEST
                if (!empty($tr_number)) {
                    $checkTR = $db->prepare("SELECT id FROM detail_transaction_requests WHERE trf_number = ?");
                    $checkTR->execute([$tr_number]);
                    $existingTR = $checkTR->fetch();
                    
                    if (!$existingTR) {
                        $insertTR = $db->prepare("INSERT INTO detail_transaction_requests (trf_number, status, created_at, updated_at) VALUES (?, 'pending', NOW(), NOW())");
                        $insertTR->execute([$tr_number]);
                    }
                }
                
                $db->commit();
                
                setFlash('Aktivitas berhasil ditambahkan!', 'success');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('Gagal menyimpan data: ' . $e->getMessage(), 'danger');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            }
        } else {
            setFlash(implode('<br>', $errors), 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
    }
    
    if ($action === 'complete') {
        $detail_id = (int)$_POST['detail_id'];
        
        // Sales hanya bisa complete miliknya sendiri
        if ($role === 'sales') {
            $checkOwner = $db->prepare("SELECT sa.sales_id FROM activity_details ad 
                                        JOIN sales_activities sa ON ad.sales_activity_id = sa.id 
                                        WHERE ad.id = ?");
            $checkOwner->execute([$detail_id]);
            $ownerData = $checkOwner->fetch();
            
            if (!$ownerData || $ownerData['sales_id'] != $userId) {
                setFlash('Anda tidak memiliki akses!', 'danger');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            }
        } elseif (!canEdit('sales_activity')) {
            setFlash('Anda tidak memiliki akses!', 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
        
        $result = trim($_POST['result']);
        $customer_deal = isset($_POST['customer_deal']) ? bersihkan($_POST['customer_deal']) : '';
        $di_number = NULL;
        $tr_number = NULL;
        
        $errors = [];
        if (strlen($result) < 50) $errors[] = 'Result minimal 50 karakter!';
        
        // Ambil data detail untuk cek jenis_tugas
        $stmt = $db->prepare("SELECT * FROM activity_details WHERE id = ?");
        $stmt->execute([$detail_id]);
        $detail = $stmt->fetch();
        
        if (!$detail) {
            $errors[] = 'Data detail tidak ditemukan!';
        }
        
        // HANYA Delivery Order yang wajib isi customer_deal
        if ($detail && $detail['jenis_tugas'] === 'Delivery Order') {
            if (empty($customer_deal)) $errors[] = 'Customer Deal wajib dipilih!';
            
            if ($customer_deal === 'Yes') {
                $di_number = generateDINumber($db);
            }
        }
        
        // Untuk Kontrak dan After Sales ambil TR & DI Number dari Delivery Order sebelumnya
        if ($detail && ($detail['jenis_tugas'] === 'Kontrak' || $detail['jenis_tugas'] === 'After Sales')) {
            $stmt = $db->prepare("SELECT tr_number, di_number FROM activity_details 
                                  WHERE sales_activity_id = ? AND jenis_tugas = 'Delivery Order' 
                                  ORDER BY id DESC LIMIT 1");
            $stmt->execute([$detail['sales_activity_id']]);
            $doData = $stmt->fetch();
            
            if ($doData) {
                $tr_number = $doData['tr_number'];
                $di_number = $doData['di_number'];
            } else {
                // Fallback: cari dari Negosiasi
                $stmt = $db->prepare("SELECT tr_number, di_number FROM activity_details 
                                      WHERE sales_activity_id = ? AND jenis_tugas = 'Negosiasi' 
                                      ORDER BY id DESC LIMIT 1");
                $stmt->execute([$detail['sales_activity_id']]);
                $negosiasiData = $stmt->fetch();
                
                if ($negosiasiData) {
                    $tr_number = $negosiasiData['tr_number'];
                    $di_number = $negosiasiData['di_number'];
                }
            }
        }
        
        // Upload file (multiple)
        $attachment_files = [];
        if (!empty($_FILES['attachment_file']['name']) && is_array($_FILES['attachment_file']['name'])) {
            $target_dir = "uploads/attachments/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
            $max_file_size = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['attachment_file']['name'] as $key => $filename) {
                if ($_FILES['attachment_file']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    $file_size = $_FILES['attachment_file']['size'][$key];
                    
                    if (!in_array($file_extension, $allowed_extensions)) {
                        $errors[] = 'Format file ' . $filename . ' tidak didukung!';
                    } elseif ($file_size > $max_file_size) {
                        $errors[] = 'File ' . $filename . ' melebihi ukuran maksimal 5MB!';
                    } else {
                        $new_filename = $target_dir . time() . '_' . uniqid() . '_' . $key . '.' . $file_extension;
                        if (move_uploaded_file($_FILES['attachment_file']['tmp_name'][$key], $new_filename)) {
                            $attachment_files[] = $new_filename;
                        } else {
                            $errors[] = 'Gagal mengupload file ' . $filename . '!';
                        }
                    }
                }
            }
        }
        
        if (empty($attachment_files)) {
            $errors[] = 'Attachment File wajib diupload minimal 1 file!';
        }
        
        $attachment_file = !empty($attachment_files) ? implode(',', $attachment_files) : NULL;
        
        if (empty($errors)) {
            $db->beginTransaction();
            
            try {
                // Update activity_details
                $stmt = $db->prepare("UPDATE activity_details SET result = ?, attachment_file = ?, customer_deal = ?, di_number = ?, tr_number = COALESCE(?, tr_number), status = 'completed', completed_at = NOW() WHERE id = ?");
                $stmt->execute([$result, $attachment_file, $customer_deal, $di_number, $tr_number, $detail_id]);
                
                // AUTO CREATE DETAIL DELIVERY INSTRUCTION
                if (!empty($di_number)) {
                    $salesActivityId = $detail['sales_activity_id'];
                    
                    $checkDI = $db->prepare("SELECT id FROM detail_delivery_instructions WHERE di_number = ?");
                    $checkDI->execute([$di_number]);
                    $existingDI = $checkDI->fetch();
                    
                    if (!$existingDI) {
                        $insertDI = $db->prepare("INSERT INTO detail_delivery_instructions (di_number, sales_activity_id, activity_detail_id, no_so, status, current_approval_order, created_at, updated_at) VALUES (?, ?, ?, NULL, 'pending', 1, NOW(), NOW())");
                        $insertDI->execute([$di_number, $salesActivityId, $detail_id]);
                    } else {
                        $updateDI = $db->prepare("UPDATE detail_delivery_instructions SET activity_detail_id = ?, sales_activity_id = ?, updated_at = NOW() WHERE di_number = ?");
                        $updateDI->execute([$detail_id, $salesActivityId, $di_number]);
                    }
                }
                
                // AUTO CREATE DETAIL TRANSACTION REQUEST
                if (!empty($tr_number)) {
                    $salesActivityId = $detail['sales_activity_id'];
                    
                    $checkTR = $db->prepare("SELECT id FROM detail_transaction_requests WHERE trf_number = ?");
                    $checkTR->execute([$tr_number]);
                    $existingTR = $checkTR->fetch();
                    
                    if (!$existingTR) {
                        $insertTR = $db->prepare("INSERT INTO detail_transaction_requests (trf_number, status, created_at, updated_at) VALUES (?, 'pending', NOW(), NOW())");
                        $insertTR->execute([$tr_number]);
                    }
                }
                
                $db->commit();
                
                setFlash('Aktivitas berhasil diselesaikan!', 'success');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('Gagal menyimpan data: ' . $e->getMessage(), 'danger');
                redirect('detailaktivitas.php?leads_id=' . $leadsId);
            }
        } else {
            setFlash(implode('<br>', $errors), 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
    }
    
    if ($action === 'delete') {
        if ($role === 'sales' || !canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses!', 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }

        $detail_id = (int)($_POST['detail_id'] ?? 0);

        if ($detail_id <= 0) {
            setFlash('Detail aktivitas tidak valid!', 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }

        $db->beginTransaction();

        try {
            // Lock dan pastikan detail benar-benar milik leads ini.
            $stmt = $db->prepare("
                SELECT ad.*
                FROM activity_details ad
                WHERE ad.id = ?
                  AND ad.sales_activity_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$detail_id, $leadsId]);
            $detailToDelete = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$detailToDelete) {
                throw new RuntimeException('Detail aktivitas tidak ditemukan.');
            }

            $trNumber = $detailToDelete['tr_number'] ?? null;
            $diNumber = $detailToDelete['di_number'] ?? null;

            $attachmentFiles = [];
            if (!empty($detailToDelete['attachment_file'])) {
                $attachmentFiles = array_filter(
                    array_map('trim', explode(',', $detailToDelete['attachment_file']))
                );
            }

            // Hapus detail aktivitas.
            $stmt = $db->prepare("
                DELETE FROM activity_details
                WHERE id = ?
                  AND sales_activity_id = ?
            ");
            $stmt->execute([$detail_id, $leadsId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Detail aktivitas gagal dihapus.');
            }

            // Hapus DI/TR hanya bila sudah tidak direferensikan detail lain.
            deleteUnusedDeliveryInstructionData($db, $diNumber);
            deleteUnusedTransactionRequestData($db, $trNumber);

            // Rapikan hanya periode yang terdampak, dalam transaction yang sama.
            $trPeriod = null;
            if (!empty($trNumber) && preg_match('#^\d{4}/GET-TR/JKT/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/\d{4}$#', trim($trNumber), $m)) {
                $trPeriod = $m[1] . '/' . substr(trim($trNumber), -4);
            }

            $diPeriod = null;
            if (!empty($diNumber) && preg_match('#^\d{4}/GET-DI/JKT/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/\d{4}$#', trim($diNumber), $m)) {
                $diPeriod = $m[1] . '/' . substr(trim($diNumber), -4);
            }

            if ($trPeriod !== null) {
                renumberAllTransactionRequestsFromActivities($db, $trPeriod);
            }
            if ($diPeriod !== null) {
                renumberAllDeliveryInstructions($db, $diPeriod);
            }

            $db->commit();

            // File fisik dihapus setelah commit.
            foreach ($attachmentFiles as $attachmentPath) {
                $safePath = str_replace(['..', '\\'], '', $attachmentPath);
                if (
                    strpos($safePath, 'uploads/attachments/') === 0 &&
                    is_file($safePath)
                ) {
                    @unlink($safePath);
                }
            }

            setFlash(
                'Aktivitas berhasil dihapus dan nomor TR/DI telah dirapikan!',
                'success'
            );
            redirect('detailaktivitas.php?leads_id=' . $leadsId);

        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            setFlash('Gagal menghapus data: ' . $e->getMessage(), 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
    }
}

// ============================================
// AMBIL DATA DETAIL AKTIVITAS
// ============================================
$details = $db->prepare("SELECT * FROM activity_details WHERE sales_activity_id = ? ORDER BY created_at DESC");
$details->execute([$leadsId]);
$detailsList = $details->fetchAll();

$deliveryOrderCompleted = [];
foreach ($detailsList as $d) {
    if ($d['jenis_tugas'] === 'Delivery Order' && $d['status'] === 'completed') {
        $deliveryOrderCompleted[] = $d;
    }
}

$negosiasiCompleted = [];
foreach ($detailsList as $d) {
    if ($d['jenis_tugas'] === 'Negosiasi' && $d['status'] === 'completed') {
        $negosiasiCompleted[] = $d;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detail Aktivitas - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
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

        .info-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            margin-bottom: 24px;
        }
        .info-card .info-item {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        .info-card .info-item:last-child { border-bottom: none; }
        .info-card .info-label { font-weight: 600; color: #555; width: 180px; flex-shrink: 0; font-size: 13px; }
        .info-card .info-value { color: #0e1a2b; font-size: 13px; }

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
        .card-custom .card-body-custom { padding: 0; overflow-x: auto; }
        
        .table-custom { margin-bottom: 0; font-size: 13px; }
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
        .table-custom tr:last-child td { border-bottom: none; }
        .table-custom tr:hover { background: #f8f9fa; }

        .badge-tugas {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-tugas.Perkenalan { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-tugas.Visit\/Meeting { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .badge-tugas.Prospecting { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-tugas.Negosiasi { background: rgba(231, 76, 60, 0.12); color: #c0392b; }
        .badge-tugas.Kontrak { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-tugas.Delivery.Order { background: rgba(52, 152, 219, 0.15); color: #2471a3; }
        .badge-tugas.After.Sales { background: rgba(26, 188, 156, 0.12); color: #16a085; }

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
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        .btn-action.complete { background: rgba(46, 204, 113, 0.15); color: #27ae60; }
        .btn-action.complete:hover { background: rgba(46, 204, 113, 0.25); }

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
        .form-control[readonly] { background: #f8f9fa; cursor: not-allowed; }

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
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 40px;
            background: #0a1020;
            border: 1px solid rgba(148,163,184,.20);
            border-radius: 10px;
            padding: 9px 16px;
            font-weight: 700;
            font-size: 13px;
            line-height: 1;
            transition: all 0.25s ease;
            color: #aeb9ca;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-secondary-custom i {
            font-size: 12px;
            margin: 0;
        }
        .btn-secondary-custom:hover {
            background: #111a2d;
            border-color: rgba(96,165,250,.45);
            color: #fff;
            transform: translateY(-1px);
        }

        .page-header > .d-flex {
            align-items: center;
            flex-wrap: wrap;
        }

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }

        .tr-number-display, .di-number-display {
            background: rgba(255, 215, 0, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            color: #d4a017;
            text-align: center;
            font-size: 14px;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }

        .info-negosiasi-container {
            background: #f8f9fa;
            border: 2px solid #ffd700;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .info-negosiasi-container h6 {
            color: #d4a017;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .info-negosiasi-container h6 i {
            margin-right: 8px;
        }

        .customer-deal-field, .di-number-field { display: none; }
        .customer-deal-field.show, .di-number-field.show { display: block; }

        .mobile-toggle { display: none; }
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
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
            .info-card .info-item { flex-direction: column; }
            .info-card .info-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .info-card .info-value { font-size: 12px; }
        }
    /* =========================================================
           DASHBOARD PREMIUM THEME — VISUAL ONLY
           Backend, queries, POST actions and JavaScript unchanged.
           ========================================================= */
        :root{--bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);--text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--green:#34d399;--red:#fb7185;--amber:#fbbf24}
        html,body{min-height:100%;background:#060b18}
        body{font-family:Inter,Arial,sans-serif!important;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c)!important;color:var(--text)!important;padding-bottom:0!important;overflow-x:hidden}
        .topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:1050}
        .topbar .brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px;margin:0;padding:0;border:0}
        .topbar .brand img{width:38px;height:38px;object-fit:contain}
        .topbar .brand strong{font-size:17px;letter-spacing:-.4px;color:#fff}
        .topbar .brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}
        .top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}
        .icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}
        .notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}
        .top-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5);color:#fff}
        .shell{display:flex;min-height:calc(100vh - 72px)}
        .rail{width:245px!important;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92)!important;border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px!important;gap:6px;z-index:1040;overflow-y:auto;transform:none!important}
        .rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}
        .rail a{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600}
        .rail a i{width:20px;text-align:center;font-size:14px;color:#6e7d97;margin:0}
        .rail a:hover,.rail a.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}
        .rail a.active i{color:#60a5fa}
        .rail .spacer{flex:1;min-height:20px}
        .rail-user{margin:8px 4px 4px!important;padding:12px!important;border:1px solid rgba(148,163,184,.10)!important;background:rgba(10,18,34,.7)!important;border-radius:13px!important;display:flex;align-items:center;gap:10px}
        .rail-user .mini-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}
        .rail-user strong{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .rail-user span{display:block;font-size:8px;color:#66758f;margin-top:2px}
        .logout-link{margin:0!important;background:transparent!important;color:#8794aa!important;text-align:left!important}
        .logout-link:hover{color:#fb7185!important;background:rgba(251,113,133,.08)!important}
        .logout-link i{color:#6e7d97!important}
        .content{margin-left:245px!important;width:calc(100% - 245px)!important;padding:26px 28px 50px!important;min-width:0}
        .page-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;flex-wrap:wrap;gap:20px}
        .page-header h4{font-size:26px!important;letter-spacing:-1px!important;font-weight:800!important;color:#f7f9ff!important;margin:0!important}
        .page-header h4 span,.page-header h4 span i{color:#60a5fa!important}
        .page-header > div:last-child{display:flex;gap:8px}
        .info-card,.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96))!important;border:1px solid var(--line)!important;border-radius:17px!important;box-shadow:0 18px 45px rgba(0,0,0,.18)!important;color:#dce5f5}
        .info-card{padding:17px 18px!important;margin-bottom:14px!important}
        .info-card .info-item{padding:11px 0;border-bottom:1px solid rgba(148,163,184,.08)!important}
        .info-card .info-label{color:#687791!important;font-size:10px!important;width:180px}
        .info-card .info-value{color:#dce5f5!important;font-size:11px!important}
        .card-custom .card-header-custom{height:58px;padding:0 18px;border-bottom:1px solid rgba(148,163,184,.10)!important}
        .card-custom .card-header-custom h6{color:#f7f9ff!important;font-size:13px!important;font-weight:700}
        .card-custom .card-header-custom h6 i{color:#60a5fa!important}
        .card-custom .card-body-custom{background:transparent!important}
        .table-custom{font-size:11px!important;color:#cdd7e7!important}
        .table-custom th{color:#71809b!important;background:rgba(6,13,27,.65)!important;border-bottom:1px solid rgba(148,163,184,.10)!important;font-size:9px!important;padding:12px 14px!important}
        .table-custom td{color:#cdd7e7!important;border-bottom:1px solid rgba(148,163,184,.08)!important;padding:12px 14px!important;background:transparent!important}
        .table-custom tr:hover td{background:rgba(59,130,246,.035)!important}
        .text-muted{color:#66758f!important}
        .btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1)!important;border:0!important;border-radius:11px!important;color:#fff!important;padding:10px 15px!important;font-size:11px!important;font-weight:700!important;box-shadow:0 0 24px rgba(59,130,246,.18)}
        .btn-primary-custom:hover{transform:translateY(-1px);box-shadow:0 8px 25px rgba(59,130,246,.22)}
        .btn-secondary-custom{height:38px;background:rgba(10,17,33,.85)!important;border:1px solid var(--line)!important;border-radius:11px!important;color:#aeb9ca!important;padding:0 14px!important;font-size:11px!important}
        .btn-secondary-custom:hover{background:#0d1730!important;color:#fff!important}
        .badge-tugas,.badge-status{border:1px solid rgba(148,163,184,.12);box-shadow:none}
        .btn-action{border:1px solid rgba(148,163,184,.10)!important;background:rgba(10,18,34,.75)!important}
        .btn-action.detail{color:#60a5fa!important}.btn-action.delete{color:#fb7185!important}.btn-action.complete{color:#34d399!important}
        .modal-content{background:#0b1222!important;color:#dce5f5!important;border:1px solid var(--line)!important;border-radius:16px!important;box-shadow:0 25px 70px rgba(0,0,0,.45)}
        .modal-header,.modal-footer{border-color:rgba(148,163,184,.10)!important}
        .modal-header .modal-title{color:#f7f9ff!important}
        .modal-header .modal-title i{color:#60a5fa!important}
        .form-label{color:#9aa8bf!important}
        .form-control,.form-select{background:#070e1c!important;color:#dce5f5!important;border:1px solid rgba(148,163,184,.16)!important;border-radius:10px!important}
        .form-control:focus,.form-select:focus{border-color:#3b82f6!important;box-shadow:0 0 0 3px rgba(59,130,246,.12)!important}
        .form-control[readonly]{background:#0d1730!important;color:#8e9bb5!important}
        .tr-number-display,.di-number-display{background:rgba(59,130,246,.10)!important;color:#60a5fa!important;border:1px solid rgba(59,130,246,.20)}
        .info-negosiasi-container{background:rgba(10,18,34,.75)!important;border:1px solid rgba(96,165,250,.25)!important}
        .info-negosiasi-container h6{color:#60a5fa!important}
        .alert{background:#0d1730;border:1px solid var(--line);color:#dce5f5}
        .footer-text{color:#44536c!important}.footer-text a{color:#60a5fa!important}
        .top-mobile-toggle{display:none}
        @media(max-width:991px){
            .top-mobile-toggle{display:flex!important;background:transparent!important;border:1px solid var(--line)!important;width:38px;height:38px;border-radius:10px;color:#60a5fa!important;align-items:center;justify-content:center}
            .rail{transform:translateX(-100%)!important;display:flex!important;transition:transform .25s ease!important}
            .rail.open{transform:translateX(0)!important}
            .content{margin-left:0!important;width:100%!important;padding:20px!important}
            .page-header{align-items:flex-start}
        }
        @media(max-width:650px){
            .topbar{height:64px;padding:0 14px;gap:10px}
            .topbar .brand{min-width:0}.topbar .brand div{display:none}
            .top-avatar{width:36px;height:36px}.icon-btn{width:36px;height:36px}
            .rail{top:64px}
            .content{padding:20px 14px 40px!important}
            .info-card .info-item{flex-direction:column}.info-card .info-label{width:100%;margin-bottom:3px}
            .page-header{flex-direction:column;align-items:stretch;gap:12px}
            .page-header>div:last-child{width:100%;flex-wrap:wrap}
        }
>
    </style>
</head>
<body>    <header class="topbar">
        <button class="mobile-toggle top-mobile-toggle" type="button" onclick="document.getElementById('sidebar').classList.toggle('open')" aria-label="Menu">
            <i class="fas fa-bars"></i>
        </button>
        <a class="brand" href="dashboard.php">
            <img src="images/logo.webp" alt="GET">
            <div>
                <strong>PT Ganda Elang Tangguh</strong>
                <small>Customer Relationship Management</small>
            </div>
        </a>
        <div class="top-actions">
            <button class="icon-btn" type="button" aria-label="Notifications">
                <i class="far fa-bell"></i><span class="notif">!</span>
            </button>
            <div class="top-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div>
        </div>
    </header>

    <div class="shell">
        <aside class="rail" id="sidebar">
            <div class="rail-label">Main Menu</div>
            <a href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
            <?php if (in_array('sales_activity', $menuNames)): ?>
                <a class="active" href="salesactivity.php"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a>
            <?php endif; ?>
            <?php if (in_array('account_management', $menuNames)): ?>
                <a href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a>
            <?php endif; ?>
            <?php if (in_array('transaction_request', $menuNames)): ?>
                <a href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a>
            <?php endif; ?>
            <?php if (in_array('produk', $menuNames)): ?>
                <a href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a>
            <?php endif; ?>
            <?php if (in_array('delivery_order', $menuNames)): ?>
                <a href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a>
            <?php endif; ?>

            <div class="rail-label">Administration</div>
            <?php if (in_array('data_user', $menuNames)): ?>
                <a href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a>
            <?php endif; ?>
            <?php if (in_array('data_sales', $menuNames) && file_exists('data_sales.php')): ?>
                <a href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a>
            <?php endif; ?>

            <div class="spacer"></div>
            <div class="rail-user">
                <div class="mini-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div>
                <div>
                    <strong><?= htmlspecialchars($fullName) ?></strong>
                    <span><?= htmlspecialchars(getRoleLabel($role)) ?></span>
                </div>
            </div>
            <a class="logout-link" href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
        </aside>
    <!-- MAIN CONTENT -->
    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div style="display:flex; gap:15px; align-items:center;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <h4><span><i class="fas fa-chart-bar" style="color:#ffd700;"></i></span> Detail Aktivitas</h4>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="salesactivity.php" class="btn btn-secondary-custom">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
                <?php if (canAdd('sales_activity')): ?>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalAddDetail">
                        <i class="fas fa-plus"></i> Tambah Aktivitas
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- INFO LEADS -->
        <div class="info-card">
            <div class="info-item">
                <div class="info-label">Leads Number</div>
                <div class="info-value"><strong><?= htmlspecialchars($activity['leads_number']) ?></strong></div>
            </div>
            <div class="info-item">
                <div class="info-label">Nama PT</div>
                <div class="info-value"><?= htmlspecialchars($activity['nama_pt']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Badan Usaha</div>
                <div class="info-value"><?= htmlspecialchars($activity['badan_usaha'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Business Segment</div>
                <div class="info-value"><?= htmlspecialchars($activity['bidang_usaha'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Nama PIC</div>
                <div class="info-value"><?= htmlspecialchars($activity['nama_pic'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Contact Mobile</div>
                <div class="info-value"><?= htmlspecialchars($activity['no_hp_pic'] ?? '-') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Sales</div>
                <div class="info-value"><?= htmlspecialchars($activity['sales_name'] ?? '-') ?></div>
            </div>
        </div>

        <!-- TABLE DETAIL -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Aktivitas</h6>
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
                                <th>TR Number</th>
                                <th>DI Number</th>
                                <th>Customer Deal</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Sales</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($detailsList) > 0): ?>
                                <?php $no = 1; ?>
                                <?php foreach ($detailsList as $detail): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($detail['subject']) ?></strong></td>
                                        <td><?= htmlspecialchars($activity['nama_pt']) ?></td>
                                        <td>
                                            <span class="badge-tugas <?= str_replace('/', '\/', str_replace(' ', '.', $detail['jenis_tugas'])) ?>">
                                                <?= htmlspecialchars($detail['jenis_tugas']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($detail['tr_number'])): ?>
                                                <a href="detailtr.php?tr_number=<?= urlencode($detail['tr_number']) ?>" 
                                                   style="color: #2980b9; text-decoration: none; font-weight: 600;"
                                                   target="_blank">
                                                    <?= htmlspecialchars($detail['tr_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($detail['di_number'])): ?>
                                                <a href="detaildi.php?di_number=<?= urlencode($detail['di_number']) ?>" 
                                                   style="color: #27ae60; text-decoration: none; font-weight: 600;"
                                                   target="_blank">
                                                    <?= htmlspecialchars($detail['di_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($detail['customer_deal'])): ?>
                                                <?php if ($detail['customer_deal'] === 'Yes'): ?>
                                                    <span class="badge-status completed">YES</span>
                                                <?php elseif ($detail['customer_deal'] === 'No'): ?>
                                                    <span class="badge-status overdue">NO</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $detail['due_date'] ? date('d-m-Y', strtotime($detail['due_date'])) : '-' ?></td>
                                        <td>
                                            <span class="badge-status <?= $detail['status'] ?>">
                                                <?php 
                                                    if ($detail['status'] === 'completed') {
                                                        echo 'Completed';
                                                    } elseif ($detail['status'] === 'overdue') {
                                                        echo 'Overdue';
                                                    } else {
                                                        echo 'In Progress';
                                                    }
                                                ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($activity['sales_name'] ?? '-') ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action detail" onclick="viewDetail(<?= htmlspecialchars(json_encode($detail)) ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($detail['status'] === 'in_progress' || $detail['status'] === 'overdue'): ?>
                                                    <?php if ($role === 'sales'): ?>
                                                        <?php if ($activity['sales_id'] == $userId): ?>
                                                            <button class="btn-action complete" onclick="completeDetail(<?= htmlspecialchars(json_encode($detail)) ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <?php if (canEdit('sales_activity')): ?>
                                                            <button class="btn-action complete" onclick="completeDetail(<?= htmlspecialchars(json_encode($detail)) ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if (canDelete('sales_activity')): ?>
                                                            <button class="btn-action delete" onclick="deleteDetail(<?= $detail['id'] ?>)">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada aktivitas
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

    </div>

    <!-- MODAL TAMBAH DETAIL -->
    <div class="modal fade" id="modalAddDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Tambah Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="detailaktivitas.php?leads_id=<?= $leadsId ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        
                        <div class="mb-3">
                            <label class="form-label">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" class="form-control" placeholder="Masukkan subject" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Jenis Tugas <span class="text-danger">*</span></label>
                            <select name="jenis_tugas" id="jenis_tugas_add" class="form-select" required>
                                <option value="">Pilih Jenis Tugas</option>
                                <option value="Perkenalan">Perkenalan</option>
                                <option value="Visit/Meeting">Visit/Meeting</option>
                                <option value="Prospecting">Prospecting</option>
                                <option value="Negosiasi">Negosiasi</option>
                                <option value="Kontrak">Kontrak</option>
                                <option value="Delivery Order">Delivery Order</option>
                                <option value="After Sales">After Sales</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi <span class="text-danger">*</span> <small class="text-muted">(Minimal 50 karakter)</small></label>
                            <textarea name="deskripsi" id="deskripsi_add" class="form-control" rows="5" placeholder="Masukkan deskripsi minimal 50 karakter..." minlength="50" required></textarea>
                            <small class="text-muted" id="wordCountAdd">0 karakter</small>
                        </div>
                        
                        <div class="mb-3" id="trNumberFieldAdd" style="display: none;">
                            <label class="form-label">Transaction Request Form</label>
                            <div class="tr-number-display">
                                <?= htmlspecialchars(generateTRNumber($db)) ?>
                            </div>
                        </div>
                        
                        <div id="negosiasiInfoAdd" style="display: none;"></div>
                        
                        <div class="mb-3">
                            <label class="form-label">Due Date <span class="text-danger">*</span></label>
                            <input type="date" name="due_date" class="form-control" required>
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

    <!-- MODAL COMPLETE -->
    <div class="modal fade" id="modalComplete" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-check-circle" style="color:#27ae60;"></i> Complete Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" action="detailaktivitas.php?leads_id=<?= $leadsId ?>" id="formComplete">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="detail_id" id="completeDetailId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Result <span class="text-danger">*</span> <small class="text-muted">(Minimal 50 karakter)</small></label>
                            <textarea name="result" id="result_complete" class="form-control" rows="5" placeholder="Masukkan result minimal 50 karakter..." minlength="50" required></textarea>
                            <small class="text-muted" id="wordCountComplete">0 karakter</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment File <span class="text-danger">*</span> <small class="text-muted">(Bisa pilih banyak file)</small></label>
                            <input type="file" name="attachment_file[]" id="attachment_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" multiple required>
                            <small class="text-muted">Tahan tombol Ctrl untuk memilih banyak file (JPG, PNG, PDF, DOC, XLS) - Maksimal 5MB per file</small>
                        </div>
                        
                        <div id="customerDealFieldComplete" style="display: none;">
                            <div class="mb-3">
                                <label class="form-label">Customer Deal <span class="text-danger">*</span></label>
                                <select name="customer_deal" id="customer_deal_complete" class="form-select">
                                    <option value="">-- Pilih --</option>
                                    <option value="Yes">YES</option>
                                    <option value="No">NO</option>
                                </select>
                            </div>
                            
                            <div class="mb-3" id="diNumberFieldComplete" style="display: none;">
                                <label class="form-label">Delivery Instruction Number</label>
                                <div class="di-number-display">
                                    <?= htmlspecialchars(generateDINumber($db)) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check"></i> Complete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL DETAIL VIEW -->
    <div class="modal fade" id="modalViewDetail" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye" style="color:#ffd700;"></i> Detail Aktivitas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewDetailBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DELETE -->
    <div class="modal fade" id="modalDeleteDetail" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus aktivitas ini?</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="detailaktivitas.php?leads_id=<?= $leadsId ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="detail_id" id="deleteDetailId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </main>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var deliveryOrderCompletedList = <?= json_encode(array_values($deliveryOrderCompleted)) ?>;
        var negosiasiCompletedList = <?= json_encode(array_values($negosiasiCompleted)) ?>;
        
        document.getElementById('deskripsi_add').addEventListener('input', function() {
            var chars = this.value.length;
            document.getElementById('wordCountAdd').textContent = chars + ' karakter';
            if (chars < 50) {
                document.getElementById('wordCountAdd').style.color = '#e74c3c';
            } else {
                document.getElementById('wordCountAdd').style.color = '#27ae60';
            }
        });
        
        document.getElementById('result_complete').addEventListener('input', function() {
            var chars = this.value.length;
            document.getElementById('wordCountComplete').textContent = chars + ' karakter';
            if (chars < 50) {
                document.getElementById('wordCountComplete').style.color = '#e74c3c';
            } else {
                document.getElementById('wordCountComplete').style.color = '#27ae60';
            }
        });
        
        document.getElementById('jenis_tugas_add').addEventListener('change', function() {
            var trNumberField = document.getElementById('trNumberFieldAdd');
            var negosiasiInfoAdd = document.getElementById('negosiasiInfoAdd');
            
            if (this.value === 'Negosiasi') {
                trNumberField.style.display = 'block';
                negosiasiInfoAdd.style.display = 'none';
                negosiasiInfoAdd.innerHTML = '';
            } else if (this.value === 'Kontrak' || this.value === 'Delivery Order' || this.value === 'After Sales') {
                trNumberField.style.display = 'none';
                negosiasiInfoAdd.style.display = 'block';
                
                var infoHtml = '';
                if (negosiasiCompletedList.length > 0) {
                    var lastNegosiasi = negosiasiCompletedList[negosiasiCompletedList.length - 1];
                    
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-link"></i>Data dari Negosiasi Sebelumnya</h6>';
                    
                    if (lastNegosiasi.tr_number) {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> <a href="detailtr.php?tr_number=' + encodeURIComponent(lastNegosiasi.tr_number) + '" style="color: #2980b9;" target="_blank">' + lastNegosiasi.tr_number + '</a></div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> -</div>';
                    }
                    
                    infoHtml += '</div>';
                } else {
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-info-circle"></i>Data dari Negosiasi Sebelumnya</h6>';
                    infoHtml += '<div class="text-muted">Tidak ada data Negosiasi yang completed.</div>';
                    infoHtml += '</div>';
                }
                
                negosiasiInfoAdd.innerHTML = infoHtml;
            } else {
                trNumberField.style.display = 'none';
                negosiasiInfoAdd.style.display = 'none';
                negosiasiInfoAdd.innerHTML = '';
            }
        });
        
        document.getElementById('customer_deal_complete').addEventListener('change', function() {
            if (this.value === 'Yes') {
                document.getElementById('diNumberFieldComplete').style.display = 'block';
            } else {
                document.getElementById('diNumberFieldComplete').style.display = 'none';
            }
        });
        
        function viewDetail(data) {
            var html = `
                <div class="info-card" style="margin-bottom: 0;">
                    <div class="info-item">
                        <div class="info-label">Subject</div>
                        <div class="info-value"><strong>${data.subject}</strong></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Jenis Tugas</div>
                        <div class="info-value">${data.jenis_tugas}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Deskripsi</div>
                        <div class="info-value">${data.deskripsi}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Due Date</div>
                        <div class="info-value">${data.due_date ? new Date(data.due_date).toLocaleDateString('id-ID') : '-'}</div>
                    </div>
                    ${data.tr_number ? `
                    <div class="info-item">
                        <div class="info-label">TR Number</div>
                        <div class="info-value"><a href="detailtr.php?tr_number=${encodeURIComponent(data.tr_number)}" style="color: #2980b9; font-weight: 600;" target="_blank">${data.tr_number}</a></div>
                    </div>` : ''}
                    ${data.di_number ? `
                    <div class="info-item">
                        <div class="info-label">DI Number</div>
                        <div class="info-value"><a href="detaildi.php?di_number=${encodeURIComponent(data.di_number)}" style="color: #27ae60; font-weight: 600;" target="_blank">${data.di_number}</a></div>
                    </div>` : ''}
                    ${data.customer_deal ? `
                    <div class="info-item">
                        <div class="info-label">Customer Deal</div>
                        <div class="info-value">${data.customer_deal}</div>
                    </div>` : ''}
                    ${data.result ? `
                    <div class="info-item">
                        <div class="info-label">Result</div>
                        <div class="info-value">${data.result}</div>
                    </div>` : ''}
                    ${data.attachment_file ? `
                    <div class="info-item">
                        <div class="info-label">Attachment</div>
                        <div class="info-value">
                            ${data.attachment_file.split(',').map(function(file, index) {
                                return '<a href="' + file.trim() + '" target="_blank" class="me-2"><i class="fas fa-file me-1"></i>File ' + (index + 1) + '</a>';
                            }).join('')}
                        </div>
                    </div>` : ''}
                    <div class="info-item">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            ${data.status === 'completed' ? 'Completed' : data.status === 'overdue' ? 'Overdue' : 'In Progress'}
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('viewDetailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalViewDetail'));
            modal.show();
        }
        
        function completeDetail(data) {
            document.getElementById('completeDetailId').value = data.id;
            
            document.getElementById('customerDealFieldComplete').style.display = 'none';
            document.getElementById('customer_deal_complete').required = false;
            document.getElementById('diNumberFieldComplete').style.display = 'none';
            document.getElementById('customer_deal_complete').value = '';
            
            var existingContainer = document.getElementById('negosiasiInfoContainer');
            if (existingContainer) {
                existingContainer.remove();
            }
            
            // HANYA Delivery Order yang menampilkan Customer Deal
            if (data.jenis_tugas === 'Delivery Order') {
                document.getElementById('customerDealFieldComplete').style.display = 'block';
                document.getElementById('customer_deal_complete').required = true;
            }
            
            if (data.jenis_tugas === 'Kontrak' || data.jenis_tugas === 'After Sales') {
                var infoHtml = '';
                
                if (deliveryOrderCompletedList.length > 0) {
                    var lastDO = deliveryOrderCompletedList[deliveryOrderCompletedList.length - 1];
                    
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-link"></i>Data dari Delivery Order Sebelumnya</h6>';
                    
                    if (lastDO.tr_number) {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> <a href="detailtr.php?tr_number=' + encodeURIComponent(lastDO.tr_number) + '" style="color: #2980b9;" target="_blank">' + lastDO.tr_number + '</a></div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> -</div>';
                    }
                    
                    if (lastDO.di_number) {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> <a href="detaildi.php?di_number=' + encodeURIComponent(lastDO.di_number) + '" style="color: #27ae60;" target="_blank">' + lastDO.di_number + '</a></div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> -</div>';
                    }
                    
                    if (lastDO.customer_deal) {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> ' + lastDO.customer_deal + '</div>';
                    } else {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> -</div>';
                    }
                    
                    infoHtml += '</div>';
                } else {
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-info-circle"></i>Data dari Delivery Order Sebelumnya</h6>';
                    infoHtml += '<div class="text-muted">Tidak ada data Delivery Order yang completed.</div>';
                    infoHtml += '</div>';
                }
                
                var modalBody = document.querySelector('#modalComplete .modal-body');
                var infoContainer = document.createElement('div');
                infoContainer.id = 'negosiasiInfoContainer';
                infoContainer.innerHTML = infoHtml;
                
                var attachmentField = document.getElementById('attachment_file').closest('.mb-3');
                attachmentField.after(infoContainer);
            }
            
            var modal = new bootstrap.Modal(document.getElementById('modalComplete'));
            modal.show();
        }
        
        document.getElementById('modalComplete').addEventListener('hidden.bs.modal', function() {
            var infoContainer = document.getElementById('negosiasiInfoContainer');
            if (infoContainer) {
                infoContainer.remove();
            }
        });
        
        function deleteDetail(id) {
            document.getElementById('deleteDetailId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDeleteDetail'));
            modal.show();
        }
    </script>
</body>
</html>