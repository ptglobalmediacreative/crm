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
requirePermission('transaction_request', 'view');

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
// FUNGSI UNTUK RESET APPROVAL HISTORY
// ============================================
function resetApprovalHistory($db, $tr_number) {
    try {
        $deleteApproval = $db->prepare("DELETE FROM tr_approval_history WHERE trf_number = ?");
        $deleteApproval->execute([$tr_number]);
        
        $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = 'pending', updated_at = NOW() WHERE trf_number = ?");
        $updateDetail->execute([$tr_number]);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// CEK USER UNTUK AKSES
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

// ============================================
// AMBIL TR NUMBER DARI URL
// ============================================
$tr_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';
$activeTab = isset($_GET['tab']) ? bersihkan($_GET['tab']) : 'summary';

// Validasi tab
$validTabs = ['summary', 'detail_unit', 'term_of_payment', 'additional_cost', 'mediator'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'summary';
}

if (empty($tr_number)) {
    setFlash('TR Number tidak ditemukan!', 'danger');
    redirect('transactionrequest.php');
}

// ============================================
// AMBIL DATA TRANSACTION REQUEST (QUERY SEDERHANA)
// ============================================
$sql = "SELECT ad.tr_number, 
               ad.due_date,
               ad.created_at as request_date,
               ad.id as latest_activity_id,
               a.id as account_id,
               a.nama_pt, 
               a.badan_usaha,
               a.alamat,
               a.npwp,
               a.nama_pic,
               a.jabatan_pic,
               a.no_hp_pic,
               a.email_pic,
               u.full_name as sales_name,
               u.id as sales_user_id,
               sa.sales_id,
               sa.id as sales_activity_id
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        WHERE ad.tr_number = ?
        ORDER BY ad.id DESC
        LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute([$tr_number]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('Data transaction request tidak ditemukan!', 'danger');
    redirect('transactionrequest.php');
}

// ============================================
// DAPATKAN STATUS DARI DETAIL TRANSACTION REQUEST
// ============================================
$statusTR = 'pending';
try {
    $checkStatus = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
    $checkStatus->execute([$tr_number]);
    $statusData = $checkStatus->fetch();
    if ($statusData && !empty($statusData['status'])) {
        $statusTR = $statusData['status'];
    }
} catch (Exception $e) {
    $statusTR = 'pending';
}

$request['status'] = $statusTR;

// ============================================
// CEK HAK EDIT
// ============================================
$canEdit = false;
if ($userRole === 'sales' && isset($request['sales_user_id']) && $request['sales_user_id'] == $userId) {
    $canEdit = true;
}

$reviewOnlyRoles = ['sales_manager', 'direktur_sales', 'business', 'direktur_operasional', 'direktur_utama', 'finance', 'it_support', 'admin'];
$isReviewOnly = in_array($userRole, $reviewOnlyRoles);

// ============================================
// CEK APAKAH TR SUDAH PERNAH DI-APPROVE
// ============================================
$hasBeenApproved = false;
try {
    $checkApproved = $db->prepare("SELECT COUNT(*) as total FROM tr_approval_history WHERE trf_number = ? AND status = 'approved'");
    $checkApproved->execute([$tr_number]);
    $approvedCount = $checkApproved->fetch()['total'];
    if ($approvedCount > 0) {
        $hasBeenApproved = true;
    }
} catch (Exception $e) {
    $hasBeenApproved = false;
}

if ($hasBeenApproved) {
    $canEdit = false;
}

// ============================================
// AMBIL DATA DETAIL TRANSACTION REQUEST
// ============================================
$detailTR = null;
try {
    $sqlDetail = "SELECT * FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1";
    $stmtDetail = $db->prepare($sqlDetail);
    $stmtDetail->execute([$tr_number]);
    $detailTR = $stmtDetail->fetch();
} catch (Exception $e) {
    $detailTR = null;
}

// ============================================
// AMBIL DATA APPROVAL HISTORY
// ============================================
$approvalHistory = [];
try {
    $sqlApproval = "SELECT * FROM tr_approval_history WHERE trf_number = ? ORDER BY approval_order ASC";
    $stmtApproval = $db->prepare($sqlApproval);
    $stmtApproval->execute([$tr_number]);
    $approvalHistory = $stmtApproval->fetchAll();
} catch (Exception $e) {
    $approvalHistory = [];
}

// ============================================
// DAFTAR APPROVAL LEVELS
// ============================================
$approvalLevels = [
    1 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    2 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    3 => ['role' => 'business', 'label' => 'Divisi Business'],
    4 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    5 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// TENTUKAN CURRENT APPROVER DAN NEXT APPROVER
// ============================================
$currentApprovalOrder = 1;
$currentApproverLabel = '';
$nextApproverLabel = '';

if ($detailTR) {
    $lastApprovedOrder = 0;
    foreach ($approvalHistory as $approval) {
        if ($approval['status'] == 'approved') {
            $lastApprovedOrder = max($lastApprovedOrder, $approval['approval_order']);
        }
    }
    
    $isRejected = false;
    foreach ($approvalHistory as $approval) {
        if ($approval['status'] == 'rejected') {
            $isRejected = true;
            break;
        }
    }
    
    if ($isRejected || $detailTR['status'] == 'rejected') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } elseif ($detailTR['status'] == 'approved') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } else {
        $currentApprovalOrder = $lastApprovedOrder + 1;
        if ($currentApprovalOrder <= 5) {
            $currentApproverLabel = $approvalLevels[$currentApprovalOrder]['label'];
            $nextOrder = $currentApprovalOrder + 1;
            $nextApproverLabel = $nextOrder <= 5 ? $approvalLevels[$nextOrder]['label'] : 'No More Approval';
        } else {
            $currentApproverLabel = 'No More Approval';
            $nextApproverLabel = 'No More Approval';
        }
    }
} else {
    $currentApproverLabel = $approvalLevels[1]['label'];
    $nextApproverLabel = $approvalLevels[2]['label'];
}

// ============================================
// AMBIL DATA PRODUK UNTUK DROPDOWN UNIT
// ============================================
$produkList = [];
try {
    $sqlProduk = "SELECT id, nama_produk FROM products ORDER BY nama_produk ASC";
    $stmtProduk = $db->prepare($sqlProduk);
    $stmtProduk->execute();
    $produkList = $stmtProduk->fetchAll();
} catch (Exception $e) {
    $produkList = [];
}

// ============================================
// AMBIL DATA DETAIL UNIT
// ============================================
$detailUnits = [];
try {
    $sqlUnit = "SELECT * FROM tr_detail_units WHERE trf_number = ? ORDER BY id ASC";
    $stmtUnit = $db->prepare($sqlUnit);
    $stmtUnit->execute([$tr_number]);
    $detailUnits = $stmtUnit->fetchAll();
} catch (Exception $e) {
    $detailUnits = [];
}

// ============================================
// AMBIL DATA TERM OF PAYMENT
// ============================================
$termPayments = [];
try {
    $sqlTOP = "SELECT * FROM tr_term_of_payments WHERE trf_number = ? ORDER BY id ASC";
    $stmtTOP = $db->prepare($sqlTOP);
    $stmtTOP->execute([$tr_number]);
    $termPayments = $stmtTOP->fetchAll();
} catch (Exception $e) {
    $termPayments = [];
}

// ============================================
// AMBIL DATA ADDITIONAL COST ITEMS (MULTIPLE)
// ============================================
$additionalCostItems = [];
try {
    $sqlCostItems = "SELECT * FROM tr_additional_cost_items WHERE trf_number = ? ORDER BY id ASC";
    $stmtCostItems = $db->prepare($sqlCostItems);
    $stmtCostItems->execute([$tr_number]);
    $additionalCostItems = $stmtCostItems->fetchAll();
} catch (Exception $e) {
    $additionalCostItems = [];
}

// ============================================
// AMBIL DATA MEDIATOR (MULTIPLE)
// ============================================
$mediators = [];
try {
    $sqlMediator = "SELECT * FROM tr_mediators WHERE trf_number = ? ORDER BY id ASC";
    $stmtMediator = $db->prepare($sqlMediator);
    $stmtMediator->execute([$tr_number]);
    $mediators = $stmtMediator->fetchAll();
} catch (Exception $e) {
    $mediators = [];
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $editActions = ['save_summary', 'save_unit', 'delete_unit', 'save_top', 'save_cost', 'save_mediator'];
    if (in_array($action, $editActions) && !$canEdit) {
        if ($hasBeenApproved) {
            setFlash('TR ini sudah di-approve, data tidak bisa diedit lagi!', 'danger');
        } else {
            setFlash('Anda tidak memiliki hak untuk mengedit data ini!', 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=summary");
    }
    
    // SAVE SUMMARY
    if ($action === 'save_summary') {
        try {
            $db->beginTransaction();
            $deskripsi = $_POST['deskripsi'] ?? '';
            
            if ($detailTR) {
                $updateSql = "UPDATE detail_transaction_requests SET deskripsi = ?, updated_at = NOW() WHERE id = ?";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([$deskripsi, $detailTR['id']]);
            } else {
                $insertSql = "INSERT INTO detail_transaction_requests (trf_number, deskripsi, status, created_at, updated_at) VALUES (?, ?, 'pending', NOW(), NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$tr_number, $deskripsi]);
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Summary berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan summary: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=summary");
    }
    
    // APPROVE / REJECT
    if ($action === 'approve' || $action === 'reject') {
        try {
            $db->beginTransaction();
            $approvalStatus = $action === 'approve' ? 'approved' : 'rejected';
            $currentOrder = (int)($_POST['approval_order'] ?? 0);
            
            $canApprove = false;
            if ($currentOrder > 0 && $currentOrder <= 5) {
                $requiredRole = $approvalLevels[$currentOrder]['role'];
                if ($userRole == $requiredRole) {
                    $canApprove = true;
                }
            }
            
            $checkDataComplete = true;
            if (empty($detailTR['deskripsi'])) $checkDataComplete = false;
            if (count($detailUnits) == 0) $checkDataComplete = false;
            if (count($termPayments) == 0) $checkDataComplete = false;
            if (count($additionalCostItems) == 0) $checkDataComplete = false;
            
            if ($canApprove && $checkDataComplete) {
                $checkApproval = $db->prepare("SELECT id FROM tr_approval_history WHERE trf_number = ? AND approval_order = ?");
                $checkApproval->execute([$tr_number, $currentOrder]);
                $existingApproval = $checkApproval->fetch();
                
                if ($existingApproval) {
                    $updateApproval = $db->prepare("UPDATE tr_approval_history SET status = ?, catatan = '', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $updateApproval->execute([$approvalStatus, $userId, $existingApproval['id']]);
                } else {
                    $insertApproval = $db->prepare("INSERT INTO tr_approval_history (trf_number, approval_order, approval_role, status, catatan, approved_by, created_at) VALUES (?, ?, ?, ?, '', ?, NOW())");
                    $insertApproval->execute([$tr_number, $currentOrder, $approvalLevels[$currentOrder]['role'], $approvalStatus, $userId]);
                }
                
                $newStatus = 'pending';
                if ($approvalStatus == 'rejected') {
                    $newStatus = 'rejected';
                } elseif ($currentOrder >= 5) {
                    $newStatus = 'approved';
                }
                
                $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = ?, updated_at = NOW() WHERE trf_number = ?");
                $updateDetail->execute([$newStatus, $tr_number]);
                
                $db->commit();
                setFlash($approvalStatus == 'approved' ? 'TR berhasil di-approve!' : 'TR berhasil di-reject!', 'success');
            } else {
                if (!$canApprove) {
                    setFlash('Anda tidak memiliki hak untuk melakukan approval ini!', 'danger');
                } elseif (!$checkDataComplete) {
                    setFlash('Data belum lengkap!', 'danger');
                }
            }
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal melakukan approval: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=summary");
    }
    
    // SAVE DETAIL UNIT
    if ($action === 'save_unit') {
        try {
            $db->beginTransaction();
            
            $unit_id = $_POST['unit_id'] ?? '';
            $qty = (int)($_POST['qty'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            $specification = $_POST['specification'] ?? '';
            $additional_attachment = $_POST['additional_attachment'] ?? '';
            $waranty = $_POST['waranty'] ?? '';
            $machine_location = $_POST['machine_location'] ?? '';
            $delivery_terms = $_POST['delivery_terms'] ?? '';
            $delivery_schedule = $_POST['delivery_schedule'] ?? '';
            $transaction_type = $_POST['transaction_type'] ?? '';
            $transaction_type_other = $_POST['transaction_type_other'] ?? '';
            
            $ppn = $price * 0.11;
            $grand_total = ($price + $ppn) * $qty;
            
            if ($transaction_type === 'Other' && !empty($transaction_type_other)) {
                $transaction_type = 'Other: ' . $transaction_type_other;
            }
            
            $unitId = $_POST['unit_id_hidden'] ?? 0;
            
            if ($unitId > 0) {
                $updateSql = "UPDATE tr_detail_units SET unit_id = ?, qty = ?, price = ?, ppn = ?, grand_total = ?, specification = ?, additional_attachment = ?, waranty = ?, machine_location = ?, delivery_terms = ?, delivery_schedule = ?, transaction_type = ?, updated_at = NOW() WHERE id = ? AND trf_number = ?";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([$unit_id, $qty, $price, $ppn, $grand_total, $specification, $additional_attachment, $waranty, $machine_location, $delivery_terms, $delivery_schedule, $transaction_type, $unitId, $tr_number]);
            } else {
                $insertSql = "INSERT INTO tr_detail_units (trf_number, unit_id, qty, price, ppn, grand_total, specification, additional_attachment, waranty, machine_location, delivery_terms, delivery_schedule, transaction_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$tr_number, $unit_id, $qty, $price, $ppn, $grand_total, $specification, $additional_attachment, $waranty, $machine_location, $delivery_terms, $delivery_schedule, $transaction_type]);
            }
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = 'pending', updated_at = NOW() WHERE trf_number = ?");
            $updateDetail->execute([$tr_number]);
            
            if ($updateDetail->rowCount() == 0) {
                $insertDetail = $db->prepare("INSERT INTO detail_transaction_requests (trf_number, status, created_at, updated_at) VALUES (?, 'pending', NOW(), NOW())");
                $insertDetail->execute([$tr_number]);
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Detail unit berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan detail unit: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=detail_unit");
    }
    
    // DELETE DETAIL UNIT
    if ($action === 'delete_unit') {
        $unitId = (int)($_POST['unit_id'] ?? 0);
        if ($unitId > 0) {
            try {
                $db->beginTransaction();
                $deleteSql = "DELETE FROM tr_detail_units WHERE id = ? AND trf_number = ?";
                $deleteStmt = $db->prepare($deleteSql);
                $deleteStmt->execute([$unitId, $tr_number]);
                resetApprovalHistory($db, $tr_number);
                $db->commit();
                setFlash('Detail unit berhasil dihapus!', 'success');
            } catch (Exception $e) {
                $db->rollBack();
                setFlash('Gagal menghapus detail unit!', 'danger');
            }
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=detail_unit");
    }
    
    // SAVE TERM OF PAYMENT
    if ($action === 'save_top') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM tr_term_of_payments WHERE trf_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$tr_number]);
            
            $booking_fee = (float)($_POST['booking_fee'] ?? 0);
            $booking_fee_keterangan = $_POST['booking_fee_keterangan'] ?? '';
            if ($booking_fee > 0) {
                $insertSql = "INSERT INTO tr_term_of_payments (trf_number, payment_type, payment_label, amount, keterangan, created_at) VALUES (?, 'booking_fee', 'Booking Fee', ?, ?, NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$tr_number, $booking_fee, $booking_fee_keterangan]);
            }
            
            $dp_labels = $_POST['dp_label'] ?? [];
            $dp_amounts = $_POST['dp_amount'] ?? [];
            $dp_keterangans = $_POST['dp_keterangan'] ?? [];
            foreach ($dp_labels as $index => $label) {
                if (!empty($label) && isset($dp_amounts[$index]) && $dp_amounts[$index] > 0) {
                    $keterangan = $dp_keterangans[$index] ?? '';
                    $insertSql = "INSERT INTO tr_term_of_payments (trf_number, payment_type, payment_label, amount, keterangan, created_at) VALUES (?, 'down_payment', ?, ?, ?, NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([$tr_number, $label, $dp_amounts[$index], $keterangan]);
                }
            }
            
            $angsuran_labels = $_POST['angsuran_label'] ?? [];
            $angsuran_amounts = $_POST['angsuran_amount'] ?? [];
            $angsuran_keterangans = $_POST['angsuran_keterangan'] ?? [];
            foreach ($angsuran_labels as $index => $label) {
                if (!empty($label) && isset($angsuran_amounts[$index]) && $angsuran_amounts[$index] > 0) {
                    $keterangan = $angsuran_keterangans[$index] ?? '';
                    $insertSql = "INSERT INTO tr_term_of_payments (trf_number, payment_type, payment_label, amount, keterangan, created_at) VALUES (?, 'angsuran', ?, ?, ?, NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([$tr_number, $label, $angsuran_amounts[$index], $keterangan]);
                }
            }
            
            $nominal_po = (float)($_POST['nominal_po_leasing'] ?? 0);
            $nominal_po_keterangan = $_POST['nominal_po_leasing_keterangan'] ?? '';
            if ($nominal_po > 0) {
                $insertSql = "INSERT INTO tr_term_of_payments (trf_number, payment_type, payment_label, amount, keterangan, created_at) VALUES (?, 'nominal_po', 'Nominal PO Leasing', ?, ?, NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$tr_number, $nominal_po, $nominal_po_keterangan]);
            }
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = 'pending', updated_at = NOW() WHERE trf_number = ?");
            $updateDetail->execute([$tr_number]);
            
            if ($updateDetail->rowCount() == 0) {
                $insertDetail = $db->prepare("INSERT INTO detail_transaction_requests (trf_number, status, created_at, updated_at) VALUES (?, 'pending', NOW(), NOW())");
                $insertDetail->execute([$tr_number]);
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Term of Payment berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan Term of Payment: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=term_of_payment");
    }
    
    // SAVE ADDITIONAL COST ITEMS (MULTIPLE)
    if ($action === 'save_cost') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM tr_additional_cost_items WHERE trf_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$tr_number]);
            
            $item_names = $_POST['item_name'] ?? [];
            $item_amounts = $_POST['item_amount'] ?? [];
            $item_keterangans = $_POST['item_keterangan'] ?? [];
            
            foreach ($item_names as $index => $name) {
                if (!empty($name)) {
                    $amount = (float)($item_amounts[$index] ?? 0);
                    $keterangan = $item_keterangans[$index] ?? '';
                    
                    $insertSql = "INSERT INTO tr_additional_cost_items (trf_number, item_name, amount, keterangan, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([$tr_number, $name, $amount, $keterangan]);
                }
            }
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = 'pending', updated_at = NOW() WHERE trf_number = ?");
            $updateDetail->execute([$tr_number]);
            
            if ($updateDetail->rowCount() == 0) {
                $insertDetail = $db->prepare("INSERT INTO detail_transaction_requests (trf_number, status, created_at, updated_at) VALUES (?, 'pending', NOW(), NOW())");
                $insertDetail->execute([$tr_number]);
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Additional Cost berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan Additional Cost: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=additional_cost");
    }
    
    // SAVE MEDIATOR (MULTIPLE)
    if ($action === 'save_mediator') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM tr_mediators WHERE trf_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$tr_number]);
            
            $mediator_names = $_POST['mediator_name'] ?? [];
            $mediator_id_cards = $_POST['mediator_id_card'] ?? [];
            $mediator_npwps = $_POST['mediator_npwp'] ?? [];
            $mediator_bank_names = $_POST['mediator_bank_name'] ?? [];
            $mediator_bank_accounts = $_POST['mediator_bank_account'] ?? [];
            $mediator_amounts = $_POST['mediator_amount'] ?? [];
            
            foreach ($mediator_names as $index => $name) {
                if (!empty($name)) {
                    $id_card = $mediator_id_cards[$index] ?? '';
                    $npwp = $mediator_npwps[$index] ?? '';
                    $bank_name = $mediator_bank_names[$index] ?? '';
                    $bank_account = $mediator_bank_accounts[$index] ?? '';
                    $amount = (float)($mediator_amounts[$index] ?? 0);
                    
                    $insertSql = "INSERT INTO tr_mediators (trf_number, name, id_card_no, npwp_no, bank_name, bank_account, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([$tr_number, $name, $id_card, $npwp, $bank_name, $bank_account, $amount]);
                }
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Data Mediator berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data Mediator: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=mediator");
    }
}

// ============================================
// HITUNG TOTAL
// ============================================
$totalUnitGrandTotal = 0;
foreach ($detailUnits as $unit) {
    $totalUnitGrandTotal += (float)$unit['grand_total'];
}

$totalTOP = 0;
foreach ($termPayments as $top) {
    $totalTOP += (float)$top['amount'];
}

// Total Additional Cost HANYA dari Additional Cost Items
$totalAdditionalCost = 0;
foreach ($additionalCostItems as $item) {
    $totalAdditionalCost += (float)$item['amount'];
}

// Total Mediator Fee dihitung terpisah (hanya untuk tampilan di tab Mediator)
$totalMediatorFee = 0;
foreach ($mediators as $med) {
    $totalMediatorFee += (float)$med['amount'];
}

// Total Masukan = Total Unit - Total Additional Cost
$totalMasukan = $totalUnitGrandTotal - $totalAdditionalCost;

// ============================================
// CEK KELENGKAPAN DATA
// ============================================
$isDataComplete = true;
$missingSections = [];

if (empty($detailTR['deskripsi'])) {
    $isDataComplete = false;
    $missingSections[] = 'Deskripsi (Summary)';
}

if (count($detailUnits) == 0) {
    $isDataComplete = false;
    $missingSections[] = 'Detail Unit';
}

if (count($termPayments) == 0) {
    $isDataComplete = false;
    $missingSections[] = 'Term of Payment';
}

if (count($additionalCostItems) == 0) {
    $isDataComplete = false;
    $missingSections[] = 'Additional Cost (minimal 1 item)';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detail TR - <?= htmlspecialchars($tr_number) ?> - PT Ganda Elang Tangguh</title>
    
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

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            margin-bottom: 25px;
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
            font-weight: 700;
            color: #0e1a2b;
            margin: 0;
            font-size: 16px;
        }
        .card-custom .card-header-custom h6 i {
            color: #ffd700;
            margin-right: 8px;
        }
        .card-custom .card-body-custom { padding: 24px; }

        .info-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #999;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #0e1a2b;
            margin-bottom: 15px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #555;
            margin-bottom: 4px;
        }
        .form-control, .form-select {
            font-size: 13px;
            border-radius: 8px;
            border: 1px solid #e0e4ea;
            padding: 8px 12px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.15);
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

        .btn-success-custom {
            background: #27ae60;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-success-custom:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(39, 174, 96, 0.3);
            color: #fff;
        }
        .btn-success-custom i { margin-right: 6px; }
        .btn-success-custom:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-danger-custom {
            background: #e74c3c;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-danger-custom:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(231, 76, 60, 0.3);
            color: #fff;
        }
        .btn-danger-custom i { margin-right: 6px; }
        .btn-danger-custom:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-warning-custom {
            background: #ffd700;
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #0e1a2b;
        }
        .btn-warning-custom:hover {
            background: #e6c200;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
            color: #0e1a2b;
        }
        .btn-warning-custom i { margin-right: 6px; }

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

        .badge-status-tr {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-status-tr.pending { background: rgba(241, 196, 15, 0.15); color: #d4a017; }
        .badge-status-tr.approved { background: rgba(52, 152, 219, 0.15); color: #2980b9; }
        .badge-status-tr.rejected { background: rgba(231, 76, 60, 0.15); color: #c0392b; }

        .total-box {
            background: #0e1a2b;
            color: #fff;
            padding: 10px 15px;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 5px;
        }
        .total-box .total-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.6);
            font-weight: 600;
        }
        .total-box .total-value {
            font-size: 16px;
            font-weight: 700;
            color: #ffd700;
        }

        .mobile-toggle { display: none; }

        .tab-nav {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            margin-bottom: 25px;
            padding: 0;
            overflow-x: auto;
            white-space: nowrap;
        }
        .tab-nav .nav-tabs {
            border-bottom: none;
            padding: 5px;
            gap: 5px;
            display: flex;
        }
        .tab-nav .nav-tabs .nav-item { margin: 0; }
        .tab-nav .nav-tabs .nav-link {
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 13px;
            color: #666;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }
        .tab-nav .nav-tabs .nav-link i { font-size: 14px; }
        .tab-nav .nav-tabs .nav-link:hover { background: #f8f9fa; color: #0e1a2b; }
        .tab-nav .nav-tabs .nav-link.active { background: #0e1a2b; color: #ffd700; }

        .cost-item-row {
            background: #fff;
            border: 1px solid #e0e4ea;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .cost-item-row .cost-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f2f5;
        }
        .cost-item-row .cost-item-header strong { color: #0e1a2b; font-size: 14px; }
        .cost-item-row .cost-item-header strong i { color: #ffd700; }

        .mediator-row {
            background: #fff;
            border: 1px solid #e0e4ea;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .mediator-row .mediator-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f2f5;
        }
        .mediator-row .mediator-header strong { color: #0e1a2b; font-size: 14px; }
        .mediator-row .mediator-header strong i { color: #ffd700; }

        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { 
                display: flex !important; background: #0e1a2b; border: none; 
                width: 40px; height: 40px; border-radius: 8px; 
                color: #ffd700; font-size: 20px; align-items: center; justify-content: center;
            }
            .tab-nav .nav-tabs .nav-link { padding: 10px 15px; font-size: 12px; }
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
            <a href="transactionrequest.php" class="nav-item active"><i class="fas fa-file-signature"></i> TR Request</a>
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
                    <h4><span><i class="fas fa-file-signature" style="color:#ffd700;"></i></span> Detail TR - <?= htmlspecialchars($tr_number) ?></h4>
                </div>
            </div>
            <div>
                <a href="transactionrequest.php" class="btn btn-secondary-custom">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
        </div>

        <?= showFlash() ?>

        <!-- TAB NAVIGATION -->
        <div class="tab-nav">
            <ul class="nav nav-tabs" id="trTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'summary' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=summary">
                        <i class="fas fa-info-circle"></i> Summary
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'detail_unit' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=detail_unit">
                        <i class="fas fa-boxes"></i> Detail Unit
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'term_of_payment' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=term_of_payment">
                        <i class="fas fa-money-bill-wave"></i> Term Of Payment
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'additional_cost' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=additional_cost">
                        <i class="fas fa-coins"></i> Additional Cost
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'mediator' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=mediator">
                        <i class="fas fa-user-tie"></i> Data Mediator
                    </a>
                </li>
            </ul>
        </div>

        <!-- ============================================ -->
        <!-- TAB CONTENT: SUMMARY -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'summary'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-info-circle"></i> Summary</h6>
                <div>
                    <span class="badge-status-tr <?= $request['status'] ?> me-2">
                        <?php if ($request['status'] == 'pending'): ?>
                            <i class="fas fa-clock"></i> Pending
                        <?php elseif ($request['status'] == 'approved'): ?>
                            <i class="fas fa-check-circle"></i> Approved
                        <?php elseif ($request['status'] == 'rejected'): ?>
                            <i class="fas fa-times-circle"></i> Rejected
                        <?php endif; ?>
                    </span>
                    <?php if ($canEdit): ?>
                    <button class="btn btn-primary-custom btn-sm" onclick="showEditSummary()">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="editSummaryForm" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_summary">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Salesman</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($request['sales_name'] ?? '-') ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <input type="text" class="form-control" value="<?= ucfirst($request['status']) ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi</label>
                            <textarea name="deskripsi" class="form-control" rows="4"><?= htmlspecialchars($detailTR['deskripsi'] ?? '') ?></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="hideEditSummary()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewSummary">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-label">Nama PT</div>
                            <div class="info-value"><?= htmlspecialchars($request['nama_pt'] ?? '-') ?></div>
                            
                            <div class="info-label">No NPWP</div>
                            <div class="info-value"><?= htmlspecialchars($request['npwp'] ?? '-') ?></div>
                            
                            <div class="info-label">Alamat</div>
                            <div class="info-value"><?= htmlspecialchars($request['alamat'] ?? '-') ?></div>
                            
                            <div class="info-label">Nama PIC</div>
                            <div class="info-value"><?= htmlspecialchars($request['nama_pic'] ?? '-') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Jabatan PIC</div>
                            <div class="info-value"><?= htmlspecialchars($request['jabatan_pic'] ?? '-') ?></div>
                            
                            <div class="info-label">No Telepon PIC</div>
                            <div class="info-value"><?= htmlspecialchars($request['no_hp_pic'] ?? '-') ?></div>
                            
                            <div class="info-label">Email PIC</div>
                            <div class="info-value"><?= htmlspecialchars($request['email_pic'] ?? '-') ?></div>
                            
                            <div class="info-label">Badan Usaha</div>
                            <div class="info-value"><?= htmlspecialchars($request['badan_usaha'] ?? '-') ?></div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-label">Salesman</div>
                            <div class="info-value"><?= htmlspecialchars($request['sales_name'] ?? '-') ?></div>
                            
                            <div class="info-label">Deskripsi</div>
                            <div class="info-value"><?= nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-label">Status</div>
                            <div class="info-value">
                                <span class="badge-status-tr <?= $request['status'] ?>">
                                    <?= ucfirst($request['status']) ?>
                                </span>
                            </div>
                            
                            <div class="info-label">Current Approver</div>
                            <div class="info-value"><?= htmlspecialchars($currentApproverLabel) ?></div>
                            
                            <div class="info-label">Next Approver</div>
                            <div class="info-value"><?= htmlspecialchars($nextApproverLabel) ?></div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="info-label">Grand Total Unit (Include PPN)</div>
                            <div class="info-value">Rp <?= number_format($totalUnitGrandTotal, 0, ',', '.') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-label">Total Additional Cost</div>
                            <div class="info-value">Rp <?= number_format($totalAdditionalCost, 0, ',', '.') ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="info-label">Total Masukan</div>
                            <div class="info-value"><strong>Rp <?= number_format($totalMasukan, 0, ',', '.') ?></strong></div>
                        </div>
                    </div>
                </div>
                
                <?php if ($currentApprovalOrder > 0 && $currentApprovalOrder <= 5 && $request['status'] == 'pending'): ?>
                    <?php 
                    $canApprove = false;
                    $requiredRole = $approvalLevels[$currentApprovalOrder]['role'];
                    if ($userRole == $requiredRole) {
                        $canApprove = true;
                    }
                    ?>
                    
                    <?php if (!$canApprove): ?>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> 
                        Anda tidak memiliki hak untuk melakukan approval pada level ini. 
                        Menunggu approval dari: <strong><?= htmlspecialchars($currentApproverLabel) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($canApprove && !$isDataComplete): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Data belum lengkap!</strong> Section yang belum diisi:
                        <ul class="mb-0 mt-2">
                            <?php foreach ($missingSections as $section): ?>
                                <li><?= htmlspecialchars($section) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($canApprove): ?>
                    <div class="mt-4 p-3" style="background: #f8f9fa; border-radius: 10px;">
                        <h6 class="mb-3"><i class="fas fa-check-double"></i> Approval Action</h6>
                        <form method="POST" id="approvalForm">
                            <input type="hidden" name="action" id="approvalAction" value="approve">
                            <input type="hidden" name="approval_order" value="<?= $currentApprovalOrder ?>">
                            <button type="button" class="btn btn-success-custom" onclick="submitApproval('approve')" <?= !$isDataComplete ? 'disabled' : '' ?>>
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                            <button type="button" class="btn btn-danger-custom" onclick="submitApproval('reject')" <?= !$isDataComplete ? 'disabled' : '' ?>>
                                <i class="fas fa-times-circle"></i> Reject
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: DETAIL UNIT -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'detail_unit'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-boxes"></i> Detail Unit</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="showAddUnitForm()">
                    <i class="fas fa-edit"></i> <?= count($detailUnits) > 0 ? 'Edit Unit' : 'Tambah Unit' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="addUnitForm" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST" id="unitForm">
                        <input type="hidden" name="action" value="save_unit">
                        <input type="hidden" name="unit_id_hidden" id="unit_id_hidden" value="0">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit *</label>
                                <select name="unit_id" id="unit_id" class="form-select" required>
                                    <option value="">-- Pilih Unit --</option>
                                    <?php foreach ($produkList as $produk): ?>
                                        <option value="<?= $produk['id'] ?>">
                                            <?= htmlspecialchars($produk['nama_produk']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">QTY *</label>
                                <input type="number" name="qty" id="qty" class="form-control" min="1" required onchange="calculateTotal()" onkeyup="calculateTotal()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Price (Non PPN) *</label>
                                <input type="number" name="price" id="price" class="form-control" min="0" step="0.01" required onchange="calculateTotal()" onkeyup="calculateTotal()">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">PPN (11%)</label>
                                <input type="text" id="ppn_display" class="form-control" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Grand Total Include PPN</label>
                                <input type="text" id="grand_total_display" class="form-control" readonly>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Specification *</label>
                                <input type="text" name="specification" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Additional Attachment / Safety Devices</label>
                                <input type="text" name="additional_attachment" class="form-control">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Waranty</label>
                                <input type="text" name="waranty" class="form-control">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Machine Location Works *</label>
                                <input type="text" name="machine_location" class="form-control" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Delivery Terms *</label>
                                <input type="text" name="delivery_terms" class="form-control" placeholder="Contoh: Loco Jakarta / Franco Kalimantan" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Delivery Schedule Plan *</label>
                                <input type="date" name="delivery_schedule" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Transaction Type *</label>
                                <select name="transaction_type" id="transaction_type" class="form-select" required onchange="toggleOtherTransaction()">
                                    <option value="">-- Pilih --</option>
                                    <option value="Cash On Delivery">Cash On Delivery</option>
                                    <option value="Leasing">Leasing</option>
                                    <option value="Direct Credit">Direct Credit</option>
                                    <option value="Other">Other</option>
                                </select>
                                <input type="text" name="transaction_type_other" id="transaction_type_other" class="form-control mt-2" placeholder="Sebutkan..." style="display: none;">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Unit
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="hideAddUnitForm()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                        <button type="button" class="btn btn-danger-custom" id="deleteUnitBtn" style="display: none;" onclick="deleteUnit()">
                            <i class="fas fa-trash"></i> Hapus Unit
                        </button>
                        <?php if (count($detailUnits) > 0): ?>
                        <button type="button" class="btn btn-success-custom" onclick="showNewUnitForm()">
                            <i class="fas fa-plus"></i> Tambah Unit Baru
                        </button>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div id="viewUnit">
                    <?php if (count($detailUnits) > 0): ?>
                        <?php foreach ($detailUnits as $index => $unit): ?>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="info-label">Unit</div>
                                    <div class="info-value">
                                        <?php 
                                        $namaUnit = '-';
                                        foreach ($produkList as $produk) {
                                            if ($produk['id'] == $unit['unit_id']) {
                                                $namaUnit = $produk['nama_produk'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($namaUnit);
                                        ?>
                                    </div>
                                    
                                    <div class="info-label">QTY</div>
                                    <div class="info-value"><?= $unit['qty'] ?></div>
                                    
                                    <div class="info-label">Price (Non PPN)</div>
                                    <div class="info-value">Rp <?= number_format($unit['price'], 0, ',', '.') ?></div>
                                    
                                    <div class="info-label">PPN (11%)</div>
                                    <div class="info-value">Rp <?= number_format($unit['ppn'], 0, ',', '.') ?></div>
                                    
                                    <div class="info-label">Grand Total Include PPN</div>
                                    <div class="info-value"><strong>Rp <?= number_format($unit['grand_total'], 0, ',', '.') ?></strong></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-label">Specification</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['specification']) ?></div>
                                    
                                    <div class="info-label">Additional Attachment / Safety Devices</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['additional_attachment']) ?: '-' ?></div>
                                    
                                    <div class="info-label">Waranty</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['waranty']) ?: '-' ?></div>
                                    
                                    <div class="info-label">Machine Location Works</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['machine_location']) ?></div>
                                    
                                    <div class="info-label">Delivery Terms</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['delivery_terms']) ?></div>
                                    
                                    <div class="info-label">Delivery Schedule Plan</div>
                                    <div class="info-value"><?= date('d/m/Y', strtotime($unit['delivery_schedule'])) ?></div>
                                    
                                    <div class="info-label">Transaction Type</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['transaction_type']) ?></div>
                                </div>
                            </div>
                            <?php if ($index < count($detailUnits) - 1): ?>
                                <hr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="total-box">
                                    <span class="total-label">Total Grand Total Unit</span>
                                    <span class="total-value">Rp <?= number_format($totalUnitGrandTotal, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-box-open me-2"></i> Belum ada detail unit
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: TERM OF PAYMENT -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'term_of_payment'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-money-bill-wave"></i> Term Of Payment</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="showTOPSection()">
                    <i class="fas fa-edit"></i> Edit TOP
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="topForm" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_top">
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Booking Fee</label>
                                <input type="number" name="booking_fee" id="booking_fee" class="form-control" min="0" step="0.01" value="<?= $termPayments ? array_sum(array_column(array_filter($termPayments, function($t) { return $t['payment_type'] == 'booking_fee'; }), 'amount')) : 0 ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Keterangan Booking Fee</label>
                                <input type="text" name="booking_fee_keterangan" class="form-control" value="<?= $termPayments ? (array_values(array_filter($termPayments, function($t) { return $t['payment_type'] == 'booking_fee'; }))[0]['keterangan'] ?? '') : '' ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Nominal PO Leasing</label>
                                <input type="number" name="nominal_po_leasing" id="nominal_po_leasing" class="form-control" min="0" step="0.01" value="<?= $termPayments ? array_sum(array_column(array_filter($termPayments, function($t) { return $t['payment_type'] == 'nominal_po'; }), 'amount')) : 0 ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Ket. PO Leasing</label>
                                <input type="text" name="nominal_po_leasing_keterangan" class="form-control" value="<?= $termPayments ? (array_values(array_filter($termPayments, function($t) { return $t['payment_type'] == 'nominal_po'; }))[0]['keterangan'] ?? '') : '' ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Down Payment</label>
                            <div id="dpContainer">
                                <?php 
                                $dpPayments = array_filter($termPayments, function($t) { return $t['payment_type'] == 'down_payment'; });
                                if (count($dpPayments) > 0): 
                                    foreach ($dpPayments as $dp): ?>
                                        <div class="row mb-2 dp-row">
                                            <div class="col-md-4">
                                                <input type="text" name="dp_label[]" class="form-control" placeholder="Label (contoh: DP 1)" value="<?= htmlspecialchars($dp['payment_label']) ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" name="dp_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01" value="<?= $dp['amount'] ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <input type="text" name="dp_keterangan[]" class="form-control" placeholder="Keterangan" value="<?= htmlspecialchars($dp['keterangan'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-1">
                                                <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </div>
                                    <?php endforeach; 
                                else: ?>
                                    <div class="row mb-2 dp-row">
                                        <div class="col-md-4">
                                            <input type="text" name="dp_label[]" class="form-control" placeholder="Label (contoh: DP 1)">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="dp_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" name="dp_keterangan[]" class="form-control" placeholder="Keterangan">
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm mt-2" onclick="addDPRow()">
                                <i class="fas fa-plus"></i> Tambah DP
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Angsuran</label>
                            <div id="angsuranContainer">
                                <?php 
                                $angsuranPayments = array_filter($termPayments, function($t) { return $t['payment_type'] == 'angsuran'; });
                                if (count($angsuranPayments) > 0): 
                                    foreach ($angsuranPayments as $angsuran): ?>
                                        <div class="row mb-2 angsuran-row">
                                            <div class="col-md-4">
                                                <input type="text" name="angsuran_label[]" class="form-control" placeholder="Label (contoh: Angsuran 1)" value="<?= htmlspecialchars($angsuran['payment_label']) ?>">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" name="angsuran_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01" value="<?= $angsuran['amount'] ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <input type="text" name="angsuran_keterangan[]" class="form-control" placeholder="Keterangan" value="<?= htmlspecialchars($angsuran['keterangan'] ?? '') ?>">
                                            </div>
                                            <div class="col-md-1">
                                                <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </div>
                                    <?php endforeach; 
                                else: ?>
                                    <div class="row mb-2 angsuran-row">
                                        <div class="col-md-4">
                                            <input type="text" name="angsuran_label[]" class="form-control" placeholder="Label (contoh: Angsuran 1)">
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="angsuran_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01">
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" name="angsuran_keterangan[]" class="form-control" placeholder="Keterangan">
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm mt-2" onclick="addAngsuranRow()">
                                <i class="fas fa-plus"></i> Tambah Angsuran
                            </button>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan TOP
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="hideTOPSection()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewTOP">
                    <?php if (count($termPayments) > 0): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?php 
                                $bookingFee = array_filter($termPayments, function($t) { return $t['payment_type'] == 'booking_fee'; });
                                if (count($bookingFee) > 0):
                                    $bf = array_values($bookingFee)[0];
                                ?>
                                <div class="info-label">Booking Fee</div>
                                <div class="info-value">Rp <?= number_format($bf['amount'], 0, ',', '.') ?></div>
                                <div class="info-label">Keterangan Booking Fee</div>
                                <div class="info-value"><?= htmlspecialchars($bf['keterangan'] ?? '-') ?></div>
                                <?php endif; ?>
                                
                                <?php 
                                $nominalPO = array_filter($termPayments, function($t) { return $t['payment_type'] == 'nominal_po'; });
                                if (count($nominalPO) > 0):
                                    $npo = array_values($nominalPO)[0];
                                ?>
                                <div class="info-label">Nominal PO Leasing</div>
                                <div class="info-value">Rp <?= number_format($npo['amount'], 0, ',', '.') ?></div>
                                <div class="info-label">Keterangan PO Leasing</div>
                                <div class="info-value"><?= htmlspecialchars($npo['keterangan'] ?? '-') ?></div>
                                <?php endif; ?>
                                
                                <?php 
                                $dpPayments = array_filter($termPayments, function($t) { return $t['payment_type'] == 'down_payment'; });
                                foreach ($dpPayments as $dp):
                                ?>
                                <div class="info-label"><?= htmlspecialchars($dp['payment_label']) ?></div>
                                <div class="info-value">Rp <?= number_format($dp['amount'], 0, ',', '.') ?></div>
                                <div class="info-label">Keterangan <?= htmlspecialchars($dp['payment_label']) ?></div>
                                <div class="info-value"><?= htmlspecialchars($dp['keterangan'] ?? '-') ?></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="col-md-6">
                                <?php 
                                $angsuranPayments = array_filter($termPayments, function($t) { return $t['payment_type'] == 'angsuran'; });
                                foreach ($angsuranPayments as $angsuran):
                                ?>
                                <div class="info-label"><?= htmlspecialchars($angsuran['payment_label']) ?></div>
                                <div class="info-value">Rp <?= number_format($angsuran['amount'], 0, ',', '.') ?></div>
                                <div class="info-label">Keterangan <?= htmlspecialchars($angsuran['payment_label']) ?></div>
                                <div class="info-value"><?= htmlspecialchars($angsuran['keterangan'] ?? '-') ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="total-box">
                                    <span class="total-label">Grand Total TOP</span>
                                    <span class="total-value">Rp <?= number_format($totalTOP, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-money-bill me-2"></i> Belum ada data Term of Payment
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: ADDITIONAL COST (MULTIPLE ITEMS) -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'additional_cost'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-coins"></i> Additional Cost / Machines</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleCostForm()">
                    <i class="fas fa-edit"></i> <?= count($additionalCostItems) > 0 ? 'Edit Cost' : 'Tambah Cost' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="costFormContainer" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST" id="costForm">
                        <input type="hidden" name="action" value="save_cost">
                        
                        <div id="costItemRows">
                            <!-- Cost item rows akan ditambahkan di sini oleh JavaScript -->
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addCostItemRow()">
                                <i class="fas fa-plus"></i> Tambah Item
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Semua Cost
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleCostForm()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewCost">
                    <?php if (count($additionalCostItems) > 0): ?>
                        <?php foreach ($additionalCostItems as $index => $item): ?>
                            <div class="card mb-3" style="border: 1px solid #e0e4ea; border-radius: 10px;">
                                <div class="card-header" style="background: #f8f9fa; border-bottom: 1px solid #e0e4ea; border-radius: 10px 10px 0 0; padding: 10px 15px;">
                                    <strong style="color: #0e1a2b;">
                                        <i class="fas fa-coins" style="color: #ffd700;"></i> 
                                        <?= htmlspecialchars($item['item_name']) ?>
                                    </strong>
                                </div>
                                <div class="card-body" style="padding: 15px;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-label">Nama Item</div>
                                            <div class="info-value"><?= htmlspecialchars($item['item_name']) ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Nominal</div>
                                            <div class="info-value">
                                                <strong style="color: #27ae60;">
                                                    Rp <?= number_format($item['amount'], 0, ',', '.') ?>
                                                </strong>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($item['keterangan'])): ?>
                                    <div class="info-label">Keterangan</div>
                                    <div class="info-value" style="margin-bottom:0;"><?= htmlspecialchars($item['keterangan']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="total-box">
                                    <span class="total-label">Total Additional Cost</span>
                                    <span class="total-value">Rp <?= number_format($totalAdditionalCost, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-coins me-2"></i> Belum ada data Additional Cost
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: DATA MEDIATOR (MULTIPLE) -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'mediator'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-user-tie"></i> Data Mediator Fee</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleMediatorForm()">
                    <i class="fas fa-edit"></i> <?= count($mediators) > 0 ? 'Edit Mediator' : 'Tambah Mediator' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="mediatorFormContainer" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST" id="mediatorForm">
                        <input type="hidden" name="action" value="save_mediator">
                        
                        <div id="mediatorRows">
                            <!-- Mediator rows akan ditambahkan di sini oleh JavaScript -->
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addMediatorRow()">
                                <i class="fas fa-plus"></i> Tambah Mediator
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Semua Mediator
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleMediatorForm()">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewMediator">
                    <?php if (count($mediators) > 0): ?>
                        <?php $totalMediatorAmount = 0; ?>
                        <?php foreach ($mediators as $index => $med): ?>
                            <?php $totalMediatorAmount += $med['amount']; ?>
                            <div class="card mb-3" style="border: 1px solid #e0e4ea; border-radius: 10px;">
                                <div class="card-header" style="background: #f8f9fa; border-bottom: 1px solid #e0e4ea; border-radius: 10px 10px 0 0; padding: 10px 15px;">
                                    <strong style="color: #0e1a2b;">
                                        <i class="fas fa-user-tie" style="color: #ffd700;"></i> 
                                        Mediator <?= $index + 1 ?>
                                    </strong>
                                </div>
                                <div class="card-body" style="padding: 15px;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-label">Name</div>
                                            <div class="info-value"><?= htmlspecialchars($med['name']) ?></div>
                                            
                                            <div class="info-label">ID Card No</div>
                                            <div class="info-value"><?= htmlspecialchars($med['id_card_no']) ?: '-' ?></div>
                                            
                                            <div class="info-label">NPWP No</div>
                                            <div class="info-value"><?= htmlspecialchars($med['npwp_no']) ?: '-' ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Bank Name</div>
                                            <div class="info-value"><?= htmlspecialchars($med['bank_name']) ?: '-' ?></div>
                                            
                                            <div class="info-label">Bank Account</div>
                                            <div class="info-value"><?= htmlspecialchars($med['bank_account']) ?: '-' ?></div>
                                            
                                            <div class="info-label">Amount</div>
                                            <div class="info-value">
                                                <strong style="color: #27ae60;">
                                                    Rp <?= number_format($med['amount'], 0, ',', '.') ?>
                                                </strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="total-box">
                                    <span class="total-label">Total Mediator Fee</span>
                                    <span class="total-value">Rp <?= number_format($totalMediatorFee, 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-user-tie me-2"></i> Belum ada data Mediator
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // FUNGSI UNTUK SUMMARY
        // ============================================
        function showEditSummary() {
            document.getElementById('editSummaryForm').style.display = 'block';
            document.getElementById('viewSummary').style.display = 'none';
        }
        
        function hideEditSummary() {
            document.getElementById('editSummaryForm').style.display = 'none';
            document.getElementById('viewSummary').style.display = 'block';
        }
        
        function submitApproval(action) {
            if (action === 'reject') {
                if (!confirm('Yakin ingin me-reject TR ini?')) {
                    return;
                }
            }
            if (action === 'approve') {
                if (!confirm('Yakin ingin meng-approve TR ini?')) {
                    return;
                }
            }
            document.getElementById('approvalAction').value = action;
            document.getElementById('approvalForm').submit();
        }
        
        // ============================================
        // FUNGSI UNTUK DETAIL UNIT
        // ============================================
        function showAddUnitForm() {
            document.getElementById('addUnitForm').style.display = 'block';
            document.getElementById('unitForm').reset();
            document.getElementById('unit_id_hidden').value = '0';
            document.getElementById('deleteUnitBtn').style.display = 'none';
            
            <?php if (count($detailUnits) > 0): ?>
                <?php $firstUnit = $detailUnits[0]; ?>
                document.getElementById('unit_id_hidden').value = '<?= $firstUnit['id'] ?>';
                document.getElementById('unit_id').value = '<?= $firstUnit['unit_id'] ?>';
                document.getElementById('qty').value = '<?= $firstUnit['qty'] ?>';
                document.getElementById('price').value = '<?= $firstUnit['price'] ?>';
                
                const specInput = document.querySelector('input[name="specification"]');
                const attachmentInput = document.querySelector('input[name="additional_attachment"]');
                const warantyInput = document.querySelector('input[name="waranty"]');
                const locationInput = document.querySelector('input[name="machine_location"]');
                const deliveryTermsInput = document.querySelector('input[name="delivery_terms"]');
                const deliveryScheduleInput = document.querySelector('input[name="delivery_schedule"]');
                const transTypeInput = document.querySelector('select[name="transaction_type"]');
                
                specInput.value = '<?= addslashes($firstUnit['specification']) ?>';
                attachmentInput.value = '<?= addslashes($firstUnit['additional_attachment']) ?>';
                warantyInput.value = '<?= addslashes($firstUnit['waranty']) ?>';
                locationInput.value = '<?= addslashes($firstUnit['machine_location']) ?>';
                deliveryTermsInput.value = '<?= addslashes($firstUnit['delivery_terms']) ?>';
                deliveryScheduleInput.value = '<?= $firstUnit['delivery_schedule'] ?>';
                transTypeInput.value = '<?= addslashes($firstUnit['transaction_type']) ?>';
                
                calculateTotal();
                toggleOtherTransaction();
                document.getElementById('deleteUnitBtn').style.display = 'inline-block';
            <?php else: ?>
                calculateTotal();
                toggleOtherTransaction();
            <?php endif; ?>
        }
        
        function showNewUnitForm() {
            document.getElementById('addUnitForm').style.display = 'block';
            document.getElementById('unitForm').reset();
            document.getElementById('unit_id_hidden').value = '0';
            document.getElementById('deleteUnitBtn').style.display = 'none';
            calculateTotal();
            toggleOtherTransaction();
        }
        
        function hideAddUnitForm() {
            document.getElementById('addUnitForm').style.display = 'none';
        }
        
        function calculateTotal() {
            const price = parseFloat(document.getElementById('price').value) || 0;
            const qty = parseInt(document.getElementById('qty').value) || 0;
            const ppn = price * 0.11;
            const grandTotal = (price + ppn) * qty;
            
            document.getElementById('ppn_display').value = 'Rp ' + ppn.toLocaleString('id-ID');
            document.getElementById('grand_total_display').value = 'Rp ' + grandTotal.toLocaleString('id-ID');
        }
        
        function toggleOtherTransaction() {
            const type = document.getElementById('transaction_type').value;
            const otherInput = document.getElementById('transaction_type_other');
            if (type === 'Other') {
                otherInput.style.display = 'block';
            } else {
                otherInput.style.display = 'none';
            }
        }
        
        function deleteUnit() {
            const unitId = document.getElementById('unit_id_hidden').value;
            if (unitId > 0) {
                if (confirm('Yakin ingin menghapus unit ini?')) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete_unit">
                        <input type="hidden" name="unit_id" value="${unitId}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }
        
        // ============================================
        // FUNGSI UNTUK TERM OF PAYMENT
        // ============================================
        function showTOPSection() {
            document.getElementById('topForm').style.display = 'block';
        }
        
        function hideTOPSection() {
            document.getElementById('topForm').style.display = 'none';
        }
        
        function addDPRow() {
            const container = document.getElementById('dpContainer');
            const newRow = document.createElement('div');
            newRow.className = 'row mb-2 dp-row';
            newRow.innerHTML = `
                <div class="col-md-4">
                    <input type="text" name="dp_label[]" class="form-control" placeholder="Label (contoh: DP 1)">
                </div>
                <div class="col-md-3">
                    <input type="number" name="dp_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01">
                </div>
                <div class="col-md-4">
                    <input type="text" name="dp_keterangan[]" class="form-control" placeholder="Keterangan">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function addAngsuranRow() {
            const container = document.getElementById('angsuranContainer');
            const newRow = document.createElement('div');
            newRow.className = 'row mb-2 angsuran-row';
            newRow.innerHTML = `
                <div class="col-md-4">
                    <input type="text" name="angsuran_label[]" class="form-control" placeholder="Label (contoh: Angsuran 1)">
                </div>
                <div class="col-md-3">
                    <input type="number" name="angsuran_amount[]" class="form-control" placeholder="Nominal" min="0" step="0.01">
                </div>
                <div class="col-md-4">
                    <input type="text" name="angsuran_keterangan[]" class="form-control" placeholder="Keterangan">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                </div>
            `;
            container.appendChild(newRow);
        }
        
        function removeRow(button) {
            button.closest('.row').remove();
        }
        
        // ============================================
        // FUNGSI UNTUK ADDITIONAL COST ITEMS (MULTIPLE)
        // ============================================
        let costItemRowCount = 0;
        
        function toggleCostForm() {
            const formContainer = document.getElementById('costFormContainer');
            if (formContainer.style.display === 'none') {
                formContainer.style.display = 'block';
                loadCostItemData();
            } else {
                formContainer.style.display = 'none';
            }
        }
        
        function addCostItemRow(data = null) {
            costItemRowCount++;
            const container = document.getElementById('costItemRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'cost-item-row';
            rowDiv.id = 'costItemRow_' + costItemRowCount;
            
            rowDiv.innerHTML = `
                <div class="cost-item-header">
                    <strong>
                        <i class="fas fa-coins"></i> 
                        Item ${costItemRowCount}
                    </strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeCostItemRow(${costItemRowCount})">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="form-label">Nama Item *</label>
                        <input type="text" name="item_name[]" class="form-control" placeholder="Contoh: Insurance, Delivery Cost, dll" value="${data ? data.item_name : ''}" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nominal (Rp) *</label>
                        <input type="number" name="item_amount[]" class="form-control" min="0" step="0.01" placeholder="0" value="${data ? data.amount : 0}" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="item_keterangan[]" class="form-control" placeholder="Keterangan (opsional)" value="${data ? data.keterangan : ''}">
                    </div>
                </div>
            `;
            
            container.appendChild(rowDiv);
        }
        
        function removeCostItemRow(rowId) {
            const row = document.getElementById('costItemRow_' + rowId);
            if (row) {
                row.remove();
                const rows = document.querySelectorAll('.cost-item-row');
                rows.forEach((row, index) => {
                    const title = row.querySelector('strong');
                    if (title) {
                        title.innerHTML = `<i class="fas fa-coins"></i> Item ${index + 1}`;
                    }
                });
            }
        }
        
        function loadCostItemData() {
            const container = document.getElementById('costItemRows');
            container.innerHTML = '';
            costItemRowCount = 0;
            
            <?php if (count($additionalCostItems) > 0): ?>
                <?php foreach ($additionalCostItems as $item): ?>
                    addCostItemRow({
                        item_name: '<?= addslashes($item['item_name']) ?>',
                        amount: '<?= $item['amount'] ?>',
                        keterangan: '<?= addslashes($item['keterangan'] ?? '') ?>'
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addCostItemRow();
            <?php endif; ?>
        }
        
        // ============================================
        // FUNGSI UNTUK MULTIPLE MEDIATOR
        // ============================================
        let mediatorRowCount = 0;
        
        function toggleMediatorForm() {
            const formContainer = document.getElementById('mediatorFormContainer');
            if (formContainer.style.display === 'none') {
                formContainer.style.display = 'block';
                loadMediatorData();
            } else {
                formContainer.style.display = 'none';
            }
        }
        
        function addMediatorRow(data = null) {
            mediatorRowCount++;
            const container = document.getElementById('mediatorRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'mediator-row';
            rowDiv.id = 'mediatorRow_' + mediatorRowCount;
            
            rowDiv.innerHTML = `
                <div class="mediator-header">
                    <strong>
                        <i class="fas fa-user-tie"></i> 
                        Mediator ${mediatorRowCount}
                    </strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeMediatorRow(${mediatorRowCount})">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="mediator_name[]" class="form-control" value="${data ? data.name : ''}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">ID Card No</label>
                        <input type="text" name="mediator_id_card[]" class="form-control" value="${data ? data.id_card_no : ''}">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">NPWP No</label>
                        <input type="text" name="mediator_npwp[]" class="form-control" value="${data ? data.npwp_no : ''}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Name</label>
                        <input type="text" name="mediator_bank_name[]" class="form-control" value="${data ? data.bank_name : ''}">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Bank Account</label>
                        <input type="text" name="mediator_bank_account[]" class="form-control" value="${data ? data.bank_account : ''}">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Amount</label>
                        <input type="number" name="mediator_amount[]" class="form-control" min="0" step="0.01" value="${data ? data.amount : 0}">
                    </div>
                </div>
            `;
            
            container.appendChild(rowDiv);
        }
        
        function removeMediatorRow(rowId) {
            const row = document.getElementById('mediatorRow_' + rowId);
            if (row) {
                row.remove();
                const rows = document.querySelectorAll('.mediator-row');
                rows.forEach((row, index) => {
                    const title = row.querySelector('strong');
                    if (title) {
                        title.innerHTML = `<i class="fas fa-user-tie"></i> Mediator ${index + 1}`;
                    }
                });
            }
        }
        
        function loadMediatorData() {
            const container = document.getElementById('mediatorRows');
            container.innerHTML = '';
            mediatorRowCount = 0;
            
            <?php if (count($mediators) > 0): ?>
                <?php foreach ($mediators as $med): ?>
                    addMediatorRow({
                        name: '<?= addslashes($med['name']) ?>',
                        id_card_no: '<?= addslashes($med['id_card_no']) ?>',
                        npwp_no: '<?= addslashes($med['npwp_no']) ?>',
                        bank_name: '<?= addslashes($med['bank_name']) ?>',
                        bank_account: '<?= addslashes($med['bank_account']) ?>',
                        amount: '<?= $med['amount'] ?>'
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addMediatorRow();
            <?php endif; ?>
        }
    </script>
</body>
</html>