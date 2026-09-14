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
    <link rel="stylesheet" href="css/navigation.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
    * { box-sizing: border-box; }

    :root {
        --page-bg: #060b18;
        --panel: #0b1222;
        --panel-2: #0d1730;
        --line: rgba(148, 163, 184, 0.16);
        --text: #f7f9ff;
        --muted: #8e9bb5;
        --blue: #3b82f6;
        --blue-light: #60a5fa;
        --green: #34d399;
        --red: #fb7185;
        --amber: #fbbf24;
    }

    html,
    body {
        min-height: 100%;
        background: var(--page-bg);
    }

    body {
        margin: 0;
        font-family: 'Inter', Arial, sans-serif;
        background:
            radial-gradient(circle at 70% -10%, rgba(37, 99, 235, 0.20), transparent 30%),
            linear-gradient(145deg, #050914, #08111f 55%, #07162c);
        color: var(--text);
        overflow-x: hidden;
    }

    .content {
        margin-left: 245px;
        width: calc(100% - 245px);
        min-width: 0;
        padding: 26px 28px 50px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 20px;
        margin-bottom: 22px;
        flex-wrap: wrap;
    }

    .page-title {
        display: flex;
        align-items: center;
        gap: 14px;
    }

    .page-title h4 {
        margin: 0;
        color: var(--text);
        font-size: 26px;
        font-weight: 800;
        letter-spacing: -1px;
    }

    .page-title h4 span,
    .page-title h4 span i {
        color: var(--blue-light);
    }

    .page-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .info-card,
    .card-custom {
        background: linear-gradient(145deg, rgba(12, 23, 43, 0.94), rgba(7, 14, 28, 0.96));
        border: 1px solid var(--line);
        border-radius: 17px;
        box-shadow: 0 18px 45px rgba(0, 0, 0, 0.18);
        color: #dce5f5;
    }

    .info-card {
        padding: 17px 18px;
        margin-bottom: 14px;
    }

    .info-item {
        display: flex;
        align-items: flex-start;
        gap: 18px;
        padding: 11px 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.08);
    }

    .info-item:last-child {
        border-bottom: 0;
    }

    .info-label {
        width: 180px;
        flex-shrink: 0;
        color: #687791;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .info-value {
        color: #dce5f5;
        font-size: 11px;
        line-height: 1.6;
        word-break: break-word;
    }

    .info-value strong {
        color: #fff;
    }

    .card-custom {
        overflow: hidden;
    }

    .card-header-custom {
        min-height: 58px;
        padding: 0 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.10);
    }

    .card-header-custom h6 {
        margin: 0;
        color: var(--text);
        font-size: 13px;
        font-weight: 700;
    }

    .card-header-custom h6 i {
        margin-right: 8px;
        color: var(--blue-light);
    }

    .card-body-custom {
        padding: 0;
        background: transparent;
        overflow-x: auto;
    }

    .table-custom {
        margin-bottom: 0;
        font-size: 11px;
        color: #cdd7e7;
        white-space: nowrap;
    }

    .table-custom th {
        padding: 12px 14px;
        color: #71809b;
        background: rgba(6, 13, 27, 0.65);
        border-bottom: 1px solid rgba(148, 163, 184, 0.10);
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .table-custom td {
        padding: 12px 14px;
        color: #cdd7e7;
        background: transparent;
        border-bottom: 1px solid rgba(148, 163, 184, 0.08);
        vertical-align: middle;
    }

    .table-custom tbody tr:last-child td {
        border-bottom: 0;
    }

    .table-custom tbody tr:hover td {
        background: rgba(59, 130, 246, 0.035);
    }

    .table-custom a {
        text-decoration: none;
        font-weight: 600;
    }

    .table-custom a.tr-link {
        color: var(--blue-light);
    }

    .table-custom a.di-link {
        color: var(--green);
    }

    .badge-tugas,
    .badge-status {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 24px;
        padding: 4px 10px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.12);
        font-size: 10px;
        font-weight: 700;
        line-height: 1.2;
        white-space: nowrap;
    }

    .badge-tugas.Perkenalan { background: rgba(52, 152, 219, 0.12); color: #60a5fa; }
    .badge-tugas.Visit\/Meeting { background: rgba(155, 89, 182, 0.12); color: #c084fc; }
    .badge-tugas.Prospecting { background: rgba(241, 196, 15, 0.12); color: #fbbf24; }
    .badge-tugas.Negosiasi { background: rgba(231, 76, 60, 0.12); color: #fb7185; }
    .badge-tugas.Kontrak { background: rgba(46, 204, 113, 0.12); color: #34d399; }
    .badge-tugas.Delivery\.Order { background: rgba(52, 152, 219, 0.12); color: #60a5fa; }
    .badge-tugas.After\.Sales { background: rgba(26, 188, 156, 0.12); color: #2dd4bf; }

    .badge-status.in_progress { background: rgba(59, 130, 246, 0.12); color: var(--blue-light); }
    .badge-status.completed { background: rgba(52, 211, 153, 0.12); color: var(--green); }
    .badge-status.overdue { background: rgba(251, 113, 133, 0.12); color: var(--red); }

    .btn-action {
        width: 30px;
        height: 30px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(148, 163, 184, 0.10);
        border-radius: 8px;
        background: rgba(10, 18, 34, 0.75);
        font-size: 12px;
        cursor: pointer;
        transition: transform 0.2s ease, background 0.2s ease;
    }

    .btn-action:hover {
        transform: translateY(-1px);
    }

    .btn-action.detail { color: var(--blue-light); }
    .btn-action.detail:hover { background: rgba(59, 130, 246, 0.12); }
    .btn-action.complete { color: var(--green); }
    .btn-action.complete:hover { background: rgba(52, 211, 153, 0.12); }
    .btn-action.delete { color: var(--red); }
    .btn-action.delete:hover { background: rgba(251, 113, 133, 0.12); }

    .btn-primary-custom,
    .btn-secondary-custom {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        min-height: 38px;
        padding: 0 14px;
        border-radius: 11px;
        font-size: 11px;
        font-weight: 700;
        line-height: 1;
        text-decoration: none;
        transition: all 0.25s ease;
        white-space: nowrap;
    }

    .btn-primary-custom {
        color: #fff;
        background: linear-gradient(135deg, var(--blue), #6366f1);
        border: 0;
        box-shadow: 0 0 24px rgba(59, 130, 246, 0.18);
    }

    .btn-primary-custom:hover {
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 8px 25px rgba(59, 130, 246, 0.22);
    }

    .btn-secondary-custom {
        color: #aeb9ca;
        background: rgba(10, 17, 33, 0.85);
        border: 1px solid var(--line);
    }

    .btn-secondary-custom:hover {
        color: #fff;
        background: #0d1730;
        border-color: rgba(96, 165, 250, 0.45);
        transform: translateY(-1px);
    }

    .alert {
        margin: 0;
        padding: 12px 16px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #0d1730;
        color: #dce5f5;
        font-size: 13px;
    }

    .modal-content {
        color: #dce5f5;
        background: #0b1222;
        border: 1px solid var(--line);
        border-radius: 16px;
        box-shadow: 0 25px 70px rgba(0, 0, 0, 0.45);
    }

    .modal-header,
    .modal-footer {
        border-color: rgba(148, 163, 184, 0.10);
    }

    .modal-header {
        padding: 18px 24px;
    }

    .modal-header .modal-title {
        color: var(--text);
        font-size: 17px;
        font-weight: 700;
    }

    .modal-header .modal-title i {
        margin-right: 8px;
        color: var(--blue-light);
    }

    .modal-body {
        padding: 20px 24px;
    }

    .modal-footer {
        padding: 14px 24px;
    }

    .form-label {
        color: #9aa8bf;
        font-size: 12px;
        font-weight: 600;
    }

    .form-control,
    .form-select {
        min-height: 40px;
        color: #dce5f5;
        background: #070e1c;
        border: 1px solid rgba(148, 163, 184, 0.16);
        border-radius: 10px;
        font-size: 12px;
    }

    .form-control:focus,
    .form-select:focus {
        color: #dce5f5;
        background: #070e1c;
        border-color: var(--blue);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
    }

    .form-control[readonly] {
        color: var(--muted);
        background: #0d1730;
    }

    .form-control::placeholder {
        color: #52627d;
    }

    .tr-number-display,
    .di-number-display {
        margin-bottom: 15px;
        padding: 10px 15px;
        color: var(--blue-light);
        background: rgba(59, 130, 246, 0.10);
        border: 1px solid rgba(59, 130, 246, 0.20);
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.5px;
        text-align: center;
    }

    .info-negosiasi-container {
        margin-bottom: 15px;
        padding: 15px;
        color: #dce5f5;
        background: rgba(10, 18, 34, 0.75);
        border: 1px solid rgba(96, 165, 250, 0.25);
        border-radius: 10px;
    }

    .info-negosiasi-container h6 {
        margin-bottom: 10px;
        color: var(--blue-light);
        font-size: 13px;
        font-weight: 700;
    }

    .info-negosiasi-container h6 i {
        margin-right: 8px;
    }

    .footer-text {
        padding: 16px 0 8px;
        color: #44536c;
        font-size: 11px;
        text-align: center;
    }

    .footer-text a {
        color: var(--blue-light);
        text-decoration: none;
        font-weight: 500;
    }

    .mobile-toggle {
        display: none;
    }

    @media (max-width: 800px) {
        .content {
            margin-left: 0;
            width: 100%;
            padding: 20px 14px 40px;
        }

        .page-header {
            align-items: flex-start;
        }
    }

    @media (max-width: 650px) {
        .page-header {
            flex-direction: column;
            align-items: stretch;
            gap: 12px;
        }

        .page-actions {
            width: 100%;
        }

        .page-actions .btn {
            flex: 1 1 auto;
        }

        .info-item {
            flex-direction: column;
            gap: 3px;
        }

        .info-label {
            width: 100%;
            font-size: 9px;
        }
    }

    @media (max-width: 480px) {
        .content {
            padding: 18px 10px 35px;
        }

        .page-title h4 {
            font-size: 22px;
        }

        .info-card {
            padding: 14px;
        }

        .table-custom {
            font-size: 10px;
        }

        .table-custom th,
        .table-custom td {
            padding: 8px 9px;
        }

        .btn-action {
            width: 27px;
            height: 27px;
            font-size: 11px;
        }

        .modal-body {
            padding: 14px 16px;
        }

        .modal-header {
            padding: 14px 16px;
        }
    }
</style>
</head>
<body>

    <?php require_once 'navigation.php'; ?>

    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div class="page-title">
                <h4><span><i class="fas fa-chart-bar"></i></span> Detail Aktivitas</h4>
            </div>
            <div class="page-actions">
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