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
    $deleteApproval = $db->prepare("DELETE FROM tr_approval_history WHERE trf_number = ?");
    $deleteApproval->execute([$tr_number]);

    $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET status = 'pending', updated_at = NOW() WHERE trf_number = ?");
    $updateDetail->execute([$tr_number]);

    return true;
}

// Validasi minimum data yang wajib ada sebelum TR dapat di-approve.
function validateTRApprovalData($detailTR, $detailUnits, $termPayments, $additionalCostItems) {
    $missing = [];

    if (!$detailTR || trim((string)($detailTR['deskripsi'] ?? '')) === '') {
        $missing[] = 'Deskripsi (Summary)';
    }

    if (empty($detailUnits)) {
        $missing[] = 'Detail Unit';
    } else {
        foreach ($detailUnits as $unit) {
            if ((int)($unit['unit_id'] ?? 0) <= 0) {
                $missing[] = 'Detail Unit - Produk';
                break;
            }
            if ((int)($unit['qty'] ?? 0) <= 0) {
                $missing[] = 'Detail Unit - Quantity';
                break;
            }
            if ((float)($unit['price'] ?? 0) <= 0) {
                $missing[] = 'Detail Unit - Harga';
                break;
            }
        }
    }

    if (empty($termPayments)) {
        $missing[] = 'Term of Payment';
    } else {
        $hasPositivePayment = false;
        foreach ($termPayments as $payment) {
            if ((float)($payment['amount'] ?? 0) > 0) {
                $hasPositivePayment = true;
                break;
            }
        }
        if (!$hasPositivePayment) {
            $missing[] = 'Term of Payment - Nominal';
        }
    }

    if (empty($additionalCostItems)) {
        $missing[] = 'Additional Cost';
    }

    return $missing;
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
$validTabs = ['summary', 'detail_unit', 'term_of_payment', 'additional_cost', 'mediator', 'product_support', 'cost_calculation'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'summary';
}

if (empty($tr_number)) {
    setFlash('TR Number tidak ditemukan!', 'danger');
    redirect('transactionrequest.php');
}

// ============================================
// AMBIL DATA TRANSACTION REQUEST
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
// CEK HAK EDIT PER DIVISI / SECTION
// ============================================
$canEditSalesSection = (
    $userRole === 'sales' &&
    isset($request['sales_user_id']) &&
    (int)$request['sales_user_id'] === (int)$userId
);

$canEditBusinessSection = ($userRole === 'business');

$businessOnlyTabs = ['additional_cost', 'product_support', 'cost_calculation'];
$canViewBusinessTabs = ($userRole !== 'sales');

if (!$canViewBusinessTabs && in_array($activeTab, $businessOnlyTabs, true)) {
    setFlash('Anda tidak memiliki akses ke menu Business!', 'danger');
    redirect('detailtr.php?tr_number=' . urlencode($tr_number) . '&tab=summary');
}

$reviewOnlyRoles = ['sales_manager', 'direktur_sales', 'direktur_operasional', 'direktur_utama', 'finance', 'it_support', 'admin'];
$isReviewOnly = in_array($userRole, $reviewOnlyRoles, true);

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
    $canEditSalesSection = false;
    $canEditBusinessSection = false;
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
    3 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    4 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];
$totalApprovalLevels = count($approvalLevels);

// ============================================
// TENTUKAN CURRENT APPROVER DAN NEXT APPROVER
// ============================================
$currentApprovalOrder = 1;
$currentApproverLabel = '';
$nextApproverLabel = '';

if ($detailTR) {
    $approvedByOrder = [];
    $rejectedByCurrentFlow = false;

    foreach ($approvalHistory as $approval) {
        $order = (int)($approval['approval_order'] ?? 0);
        if (!isset($approvalLevels[$order])) {
            continue;
        }

        if (($approval['approval_role'] ?? '') !== $approvalLevels[$order]['role']) {
            continue;
        }

        if ($approval['status'] === 'approved') {
            $approvedByOrder[$order] = true;
        } elseif ($approval['status'] === 'rejected') {
            $rejectedByCurrentFlow = true;
        }
    }

    $lastApprovedOrder = 0;
    for ($order = 1; $order <= $totalApprovalLevels; $order++) {
        if (!empty($approvedByOrder[$order])) {
            $lastApprovedOrder = $order;
        } else {
            break;
        }
    }
    
    $isRejected = $rejectedByCurrentFlow;
    
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
        if ($currentApprovalOrder <= $totalApprovalLevels) {
            $currentApproverLabel = $approvalLevels[$currentApprovalOrder]['label'];
            $nextOrder = $currentApprovalOrder + 1;
            $nextApproverLabel = $nextOrder <= $totalApprovalLevels ? $approvalLevels[$nextOrder]['label'] : 'No More Approval';
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
// AMBIL DATA PRODUCT SUPPORTS
// ============================================
$trSupports = [];
try {
    $sqlSup = "SELECT * FROM tr_product_supports WHERE trf_number = ? ORDER BY id ASC";
    $stmtSup = $db->prepare($sqlSup);
    $stmtSup->execute([$tr_number]);
    $trSupports = $stmtSup->fetchAll();
} catch (Exception $e) {
    $trSupports = [];
}

// Ambil Data Cost Calculation
$costCalculation = null;
try {
    $sqlCC = "SELECT * FROM tr_cost_calculations WHERE trf_number = ? ORDER BY id DESC LIMIT 1";
    $stmtCC = $db->prepare($sqlCC);
    $stmtCC->execute([$tr_number]);
    $costCalculation = $stmtCC->fetch();
} catch (Exception $e) {
    $costCalculation = null;
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $salesEditActions = ['save_summary', 'save_unit', 'delete_unit', 'save_top', 'save_mediator'];
    $businessEditActions = ['save_cost', 'save_product_support', 'save_cost_calculation'];

    if (in_array($action, $salesEditActions, true) && !$canEditSalesSection) {
        if ($hasBeenApproved) {
            setFlash('TR ini sudah masuk proses approval, data tidak bisa diedit lagi!', 'danger');
        } else {
            setFlash('Hanya Sales pemilik TR yang dapat menambah atau mengedit bagian ini!', 'danger');
        }
        redirect('detailtr.php?tr_number=' . urlencode($tr_number) . '&tab=summary');
    }

    if (in_array($action, $businessEditActions, true) && !$canEditBusinessSection) {
        if ($hasBeenApproved) {
            setFlash('TR ini sudah masuk proses approval, data tidak bisa diedit lagi!', 'danger');
        } else {
            setFlash('Hanya Divisi Business yang dapat menambah atau mengedit bagian ini!', 'danger');
        }
        redirect('detailtr.php?tr_number=' . urlencode($tr_number) . '&tab=summary');
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
            $postedOrder = (int)($_POST['approval_order'] ?? 0);

            // Ambil status + approval history TERBARU di dalam transaction.
            // Jangan mempercayai approval_order dari browser sebagai sumber kebenaran.
            $lockDetail = $db->prepare("SELECT * FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $lockDetail->execute([$tr_number]);
            $lockedDetailTR = $lockDetail->fetch();

            if (!$lockedDetailTR) {
                throw new Exception('Detail Transaction Request tidak ditemukan.');
            }

            if (in_array($lockedDetailTR['status'], ['approved', 'rejected'], true)) {
                throw new Exception('TR sudah berstatus ' . ucfirst($lockedDetailTR['status']) . ' dan tidak dapat diproses kembali.');
            }

            $lockedHistoryStmt = $db->prepare("SELECT approval_order, approval_role, status FROM tr_approval_history WHERE trf_number = ? ORDER BY approval_order ASC FOR UPDATE");
            $lockedHistoryStmt->execute([$tr_number]);
            $lockedHistory = $lockedHistoryStmt->fetchAll();

            $approvedByOrder = [];
            foreach ($lockedHistory as $history) {
                if ((int)$history['approval_order'] > 0 && $history['status'] === 'approved') {
                    $approvedByOrder[(int)$history['approval_order']] = true;
                }
            }

            $serverCurrentOrder = 1;
            for ($order = 1; $order <= $totalApprovalLevels; $order++) {
                if (!empty($approvedByOrder[$order])) {
                    $serverCurrentOrder = $order + 1;
                } else {
                    break;
                }
            }

            $canApprove = (
                $postedOrder > 0 &&
                $postedOrder === $serverCurrentOrder &&
                $serverCurrentOrder <= $totalApprovalLevels &&
                isset($approvalLevels[$serverCurrentOrder]) &&
                hash_equals((string)$approvalLevels[$serverCurrentOrder]['role'], (string)$userRole)
            );

            $approvalMissing = validateTRApprovalData($lockedDetailTR, $detailUnits, $termPayments, $additionalCostItems);
            $checkDataComplete = empty($approvalMissing);

            if ($canApprove && $checkDataComplete) {
                $checkApproval = $db->prepare("SELECT id FROM tr_approval_history WHERE trf_number = ? AND approval_order = ?");
                $checkApproval->execute([$tr_number, $serverCurrentOrder]);
                $existingApproval = $checkApproval->fetch();
                
                if ($existingApproval) {
                    $updateApproval = $db->prepare("UPDATE tr_approval_history SET approval_role = ?, status = ?, catatan = '', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $updateApproval->execute([$approvalLevels[$serverCurrentOrder]['role'], $approvalStatus, $userId, $existingApproval['id']]);
                } else {
                    $insertApproval = $db->prepare("INSERT INTO tr_approval_history (trf_number, approval_order, approval_role, status, catatan, approved_by, created_at) VALUES (?, ?, ?, ?, '', ?, NOW())");
                    $insertApproval->execute([$tr_number, $serverCurrentOrder, $approvalLevels[$serverCurrentOrder]['role'], $approvalStatus, $userId]);
                }
                
                $newStatus = 'pending';
                if ($approvalStatus == 'rejected') {
                    $newStatus = 'rejected';
                } elseif ($serverCurrentOrder >= $totalApprovalLevels) {
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
                    setFlash('Data belum lengkap: ' . implode(', ', $approvalMissing), 'danger');
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
            $free_part_service = $_POST['free_part_service'] ?? '';
            $machine_location = $_POST['machine_location'] ?? '';
            $delivery_terms = $_POST['delivery_terms'] ?? '';
            $delivery_schedule = $_POST['delivery_schedule'] ?? '';
            $transaction_type = $_POST['transaction_type'] ?? '';
            $transaction_type_other = $_POST['transaction_type_other'] ?? '';
            
            if (empty($unit_id) || (int)$unit_id <= 0) {
                throw new Exception('Produk/unit wajib dipilih.');
            }
            if ($qty <= 0) {
                throw new Exception('Quantity harus lebih besar dari 0.');
            }
            if ($price <= 0) {
                throw new Exception('Harga unit harus lebih besar dari 0.');
            }

            $ppn = $price * 0.11;
            $grand_total = ($price + $ppn) * $qty;
            
            if ($transaction_type === 'Other' && !empty($transaction_type_other)) {
                $transaction_type = 'Other: ' . $transaction_type_other;
            }
            
            $unitId = $_POST['unit_id_hidden'] ?? 0;
            
            if ($unitId > 0) {
                $updateSql = "UPDATE tr_detail_units SET unit_id = ?, qty = ?, price = ?, ppn = ?, grand_total = ?, specification = ?, additional_attachment = ?, waranty = ?, free_part_service = ?, machine_location = ?, delivery_terms = ?, delivery_schedule = ?, transaction_type = ?, updated_at = NOW() WHERE id = ? AND trf_number = ?";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([$unit_id, $qty, $price, $ppn, $grand_total, $specification, $additional_attachment, $waranty, $free_part_service, $machine_location, $delivery_terms, $delivery_schedule, $transaction_type, $unitId, $tr_number]);
            } else {
                $insertSql = "INSERT INTO tr_detail_units (trf_number, unit_id, qty, price, ppn, grand_total, specification, additional_attachment, waranty, free_part_service, machine_location, delivery_terms, delivery_schedule, transaction_type, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$tr_number, $unit_id, $qty, $price, $ppn, $grand_total, $specification, $additional_attachment, $waranty, $free_part_service, $machine_location, $delivery_terms, $delivery_schedule, $transaction_type]);
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
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=cost_calculation");
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
    
    // SAVE PRODUCT SUPPORT
    if ($action === 'save_product_support') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM tr_product_supports WHERE trf_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$tr_number]);
            
            $support_names = $_POST['support_name'] ?? [];
            $support_keterangans = $_POST['support_keterangan'] ?? [];
            
            foreach ($support_names as $index => $name) {
                if (!empty($name)) {
                    $keterangan = $support_keterangans[$index] ?? '';
                    
                    $insertSql = "INSERT INTO tr_product_supports (trf_number, support_name, keterangan, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([$tr_number, $name, $keterangan]);
                }
            }
            
            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Data Product Support berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data product support: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=product_support");
    }
    
    // SAVE COST CALCULATION
    if ($action === 'save_cost_calculation') {
        try {
            $db->beginTransaction();
            $dealer_price = (float)($_POST['dealer_price'] ?? 0);
            $persentase = (float)($_POST['persentase'] ?? 0);

            if ($dealer_price <= 0) {
                throw new Exception('Dealer Price harus lebih besar dari 0.');
            }
            if ($persentase < 0 || $persentase > 100) {
                throw new Exception('Persentase harus berada di antara 0 sampai 100.');
            }
            if (empty($detailUnits)) {
                throw new Exception('Detail Unit wajib diisi sebelum Cost Calculation.');
            }

            $support_price = $dealer_price - ($dealer_price * ($persentase / 100));

            $additional_cost = 0;
            foreach ($additionalCostItems as $item) {
                $additional_cost += (float)$item['amount'];
            }

            $total_mediator_fee = 0;
            foreach ($mediators as $med) {
                $total_mediator_fee += (float)$med['amount'];
            }

            $selling_price = 0;
            foreach ($detailUnits as $unit) {
                $selling_price += (float)$unit['price'];
            }

            $total_cogs = $support_price + $additional_cost + $total_mediator_fee;
            $dealer_profit_request = $selling_price - $total_cogs;
            $dealer_profit_net = $selling_price > 0 ? ($dealer_profit_request / $selling_price) * 100 : 0;

            $deleteSql = "DELETE FROM tr_cost_calculations WHERE trf_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$tr_number]);

            $insertSql = "INSERT INTO tr_cost_calculations (trf_number, dealer_price, persentase, support_price, additional_cost, total_cogs, selling_price, dealer_profit_request, dealer_profit_net, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([$tr_number, $dealer_price, $persentase, $support_price, $additional_cost, $total_cogs, $selling_price, $dealer_profit_request, $dealer_profit_net]);

            resetApprovalHistory($db, $tr_number);
            $db->commit();
            setFlash('Cost Calculation berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan Cost Calculation: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=cost_calculation");
    }
}

// ============================================
// HITUNG TOTAL
// ============================================
$totalUnitGrandTotal = 0;
foreach ($detailUnits as $unit) {
    $totalUnitGrandTotal += (float)$unit['grand_total'];
}

$totalUnitPrice = 0;
foreach ($detailUnits as $unit) {
    $totalUnitPrice += (float)$unit['price'];
}

$totalTOP = 0;
foreach ($termPayments as $top) {
    $totalTOP += (float)$top['amount'];
}

$totalAdditionalCost = 0;
foreach ($additionalCostItems as $item) {
    $totalAdditionalCost += (float)$item['amount'];
}

$totalMediatorFee = 0;
foreach ($mediators as $med) {
    $totalMediatorFee += (float)$med['amount'];
}

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
:root{
 --bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);
 --text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--cyan:#22d3ee;
 --green:#34d399;--red:#fb7185;--amber:#fbbf24;--purple:#a78bfa;
}
*{box-sizing:border-box;margin:0;padding:0}
html{background:#060b18}
body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);color:var(--text);min-height:100vh;overflow-x:hidden;padding-bottom:0}
a{color:inherit}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:1100}
.top-brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.top-brand img{width:38px;height:38px;object-fit:contain}.top-brand strong{font-size:17px;letter-spacing:-.4px;font-weight:800;display:block;line-height:1.15}.top-brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px;line-height:1}
.top-actions{display:flex;align-items:center;gap:10px;margin-left:auto}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.top-avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5);color:#fff}.top-mobile-toggle{display:none}
.sidebar{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:1000;overflow-y:auto}.sidebar::-webkit-scrollbar{width:4px}.sidebar::-webkit-scrollbar-thumb{background:rgba(96,165,250,.25);border-radius:10px}.sidebar .brand{display:none!important}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.sidebar .nav-item{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600;margin:0}.sidebar .nav-item i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.sidebar .nav-item:hover,.sidebar .nav-item.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.sidebar .nav-item.active i{color:#60a5fa}.sidebar-spacer{flex:1;min-height:20px}.sidebar .user-profile{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.sidebar .avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.user-info{min-width:0}.user-info .name{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-info .role{display:block;font-size:8px;color:#66758f;margin-top:2px}.sidebar .logout-btn{width:100%;height:43px;border-radius:11px;color:#8794aa;background:transparent;border:0;display:flex;align-items:center;gap:10px;text-align:left;text-decoration:none;transition:.2s;padding:0 13px;margin:0;font-size:11px;font-weight:600}.sidebar .logout-btn i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.sidebar .logout-btn:hover{color:#fb7185;background:rgba(251,113,133,.08)}.sidebar .logout-btn:hover i{color:#fb7185}
.main-content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px;min-height:calc(100vh - 72px);max-width:none}.page-header{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px;flex-wrap:wrap}.page-header>div:first-child{display:flex;gap:12px;align-items:center}.page-header h4{display:flex;align-items:center;gap:10px;font-size:25px;line-height:1.1;font-weight:800;letter-spacing:-.4px;color:#f7f9ff;margin:0}.page-header h4 span{width:38px;height:38px;border-radius:11px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.15);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}.page-header h4 span i{font-size:15px;color:#60a5fa;margin:0}.page-header p{font-size:12px;color:var(--muted);margin-top:7px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.mobile-toggle{display:none}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{min-height:128px;background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);padding:18px 19px;transition:.25s;color:#eaf0f8}.stat-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25);transform:translateY(-1px)}.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:0}.stat-icon.gold{background:rgba(212,160,23,.12);color:#e0b53d}.stat-icon.blue{background:rgba(59,130,246,.12);color:#60a5fa}.stat-icon.green{background:rgba(52,211,153,.12);color:#34d399}.stat-icon.red{background:rgba(251,113,133,.10);color:#fb7185}.stat-number{color:#f7f9ff;font-size:24px;font-weight:800;line-height:1;margin-top:15px;margin-bottom:6px}.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;color:#70809b}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.card-header-custom{min-height:62px;padding:13px 17px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:12px}.card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-header-custom form{display:flex;align-items:center;gap:7px}.card-header-custom input{height:36px;width:230px;background:#0a1427!important;border:1px solid rgba(148,163,184,.16)!important;color:#dbe5f5!important;border-radius:9px!important;font-size:11px}.card-header-custom input::placeholder{color:#52627d}.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}
.border-bottom{border-color:rgba(148,163,184,.10)!important}.filter-buttons{display:flex;gap:8px;flex-wrap:wrap}.btn-filter{padding:7px 12px;border:1px solid rgba(148,163,184,.12);background:rgba(10,17,33,.85);border-radius:9px;color:#8492aa;text-decoration:none;font-size:10px;font-weight:600;transition:.2s}.btn-filter:hover{color:#fff;border-color:rgba(96,165,250,.25);background:#10203a}.btn-filter.active{background:rgba(37,99,235,.15);border-color:rgba(96,165,250,.3);color:#9fc5ff}.btn-filter .count{background:rgba(255,255,255,.05);padding:2px 6px;border-radius:10px;margin-left:4px}.card-body-custom{padding:0}.table-responsive{background:transparent;overflow-x:auto}.table-custom{margin-bottom:0!important;width:100%;min-width:1120px;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1;--bs-table-border-color:transparent}.table-custom th{height:43px;font-weight:700;font-size:8.5px;text-transform:uppercase;letter-spacing:.6px;color:#66758f!important;border-bottom:1px solid rgba(148,163,184,.10)!important;padding:11px 13px!important;background:rgba(5,12,25,.48)!important;white-space:nowrap}.table-custom td{height:54px;padding:10px 13px!important;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07)!important;color:#cbd5e1!important;background:transparent!important}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important;color:#e8eef7!important}.table-custom tr:last-child td{border-bottom:none!important}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}.table-custom a:hover{color:#93c5fd!important}.table-custom th:first-child,.table-custom td:first-child{width:48px;text-align:center}.table-custom th:last-child,.table-custom td:last-child{width:92px;text-align:center}.text-muted{color:#64748b!important}
.badge-status-tr{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-status-tr.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}.badge-status-tr.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-status-tr.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.btn-pdf{background:rgba(52,211,153,.10);border:1px solid rgba(52,211,153,.15);border-radius:8px;padding:6px 10px;color:#6ee7b7;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;transition:.2s}.btn-pdf:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}.btn-pdf-disabled{background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.12);border-radius:8px;padding:6px 10px;color:#59677d;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;cursor:not-allowed}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.footer-text{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}
html,body{scrollbar-color:rgba(96,165,250,.32) #060b18;scrollbar-width:thin}html::-webkit-scrollbar,body::-webkit-scrollbar{width:7px;height:7px}html::-webkit-scrollbar-track,body::-webkit-scrollbar-track{background:#060b18}html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.30);border-radius:999px;border:1px solid rgba(6,11,24,.9)}html::-webkit-scrollbar-thumb:hover,body::-webkit-scrollbar-thumb:hover{background:rgba(96,165,250,.48)}
@media(max-width:991px){.topbar{padding:0 16px}.top-mobile-toggle{display:flex;width:36px;height:36px;margin-right:10px;border:1px solid var(--line);background:#0a1427;color:#60a5fa;border-radius:9px;align-items:center;justify-content:center}.sidebar{transform:translateX(-100%);transition:.25s}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0;width:100%;padding:92px 18px 24px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-header{align-items:flex-start}}
@media(max-width:800px){.topbar{padding:0 16px}.top-brand{min-width:0}.top-brand>div{display:none}.sidebar{top:72px}.main-content{padding:20px 14px 40px}.page-header{align-items:flex-start;flex-direction:column}.mobile-toggle{display:none}.table-custom{min-width:1120px}}
@media(max-width:520px){.topbar{height:64px;padding:0 12px}.top-brand img{width:32px;height:32px}.main-content{padding:17px 10px 34px}.sidebar{top:64px}.page-header h4{font-size:20px}.page-header h4 span{width:34px;height:34px;border-radius:9px}.stat-grid{gap:9px}.stat-card{min-height:110px;padding:13px;border-radius:14px}.stat-number{font-size:20px}.card-header-custom{align-items:flex-start;padding:13px}.card-header-custom form{width:100%}.card-header-custom input{flex:1;width:auto}.filter-buttons{overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.btn-filter{white-space:nowrap}}
</style>
<style>
/* DETAIL TR — VISUAL PARITY WITH ACCOUNT MANAGEMENT */
.info-label{font-size:9px;text-transform:uppercase;letter-spacing:.8px;color:#66758f;font-weight:700;margin:0 0 5px}
.info-value{font-size:12px;color:#e3eaf5;font-weight:600;margin-bottom:18px;line-height:1.55;word-break:break-word}
.form-label{font-size:10px;text-transform:uppercase;letter-spacing:.55px;color:#71809a;font-weight:700;margin-bottom:6px}
.form-control,.form-select,.form-control:disabled,.form-control[readonly]{font-size:11px;border-radius:9px;border:1px solid rgba(148,163,184,.15);padding:9px 11px;background:#0a1427;color:#dbe5f5;box-shadow:none}
.form-control::placeholder{color:#52627d}
.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.45);box-shadow:0 0 0 3px rgba(59,130,246,.10);background:#0b172c;color:#fff}
.form-select option{background:#0b1222;color:#dbe5f5}
.btn-success-custom{background:linear-gradient(135deg,#059669,#10b981);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;color:#fff;transition:.2s}
.btn-success-custom:hover{background:linear-gradient(135deg,#10b981,#34d399);transform:translateY(-1px);box-shadow:0 8px 22px rgba(16,185,129,.18);color:#fff}
.btn-danger-custom{background:linear-gradient(135deg,#e11d48,#fb7185);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;color:#fff;transition:.2s}
.btn-danger-custom:hover{background:linear-gradient(135deg,#f43f5e,#fb7185);transform:translateY(-1px);box-shadow:0 8px 22px rgba(251,113,133,.18);color:#fff}
.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}
.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}
.btn-sm{padding:6px 10px;font-size:9px;border-radius:8px}
.badge-status-tr{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}
.badge-status-tr.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}
.badge-status-tr.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}
.badge-status-tr.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}
.total-box{background:linear-gradient(145deg,rgba(12,23,43,.98),rgba(7,14,28,.98));color:#fff;padding:11px 14px;border:1px solid rgba(148,163,184,.12);border-radius:11px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:7px;box-shadow:0 10px 25px rgba(0,0,0,.12)}
.total-box .total-label{font-size:9px;text-transform:uppercase;letter-spacing:.7px;color:#70809b;font-weight:700}.total-box .total-value{font-size:16px;font-weight:800;color:#e0b53d}
.tab-nav{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 45px rgba(0,0,0,.18);margin-bottom:18px;padding:4px;overflow-x:auto;white-space:nowrap}
.tab-nav .nav-tabs{border-bottom:none;padding:0;gap:3px;display:flex;min-width:max-content}.tab-nav .nav-tabs .nav-item{margin:0}
.tab-nav .nav-tabs .nav-link{border:none;border-radius:10px;padding:10px 13px;font-weight:700;font-size:10px;color:#71809a;transition:.2s;display:flex;align-items:center;gap:7px;white-space:nowrap}
.tab-nav .nav-tabs .nav-link i{font-size:11px}.tab-nav .nav-tabs .nav-link:hover{background:rgba(59,130,246,.07);color:#dbe7f7}.tab-nav .nav-tabs .nav-link.active{background:linear-gradient(135deg,rgba(37,99,235,.22),rgba(59,130,246,.10));color:#9fc5ff;box-shadow:inset 0 0 0 1px rgba(96,165,250,.12)}
.support-row{background:rgba(8,16,31,.72);border:1px solid rgba(148,163,184,.11);border-radius:11px;padding:13px;margin-bottom:12px;color:#cbd5e1}
.cost-item-header,.mediator-header,.d-flex.justify-content-between{border-bottom:1px solid rgba(148,163,184,.09)!important;color:#dbe5f5;padding-bottom:9px;margin-bottom:13px}.cost-item-header strong,.mediator-header strong,.d-flex.justify-content-between strong{color:#e8eef8;font-size:11px}.cost-item-header strong i,.mediator-header strong i,.d-flex.justify-content-between strong i{color:#60a5fa}
#editSummaryForm,#approvalForm,#costFormContainer,#mediatorFormContainer,#editSupport,#editCostCalc{background:#0a1427!important;border:1px solid rgba(148,163,184,.10);border-radius:12px!important;padding:16px!important}
#viewSummary,#viewUnit,#viewTOP,#viewMediator,#viewCost,#viewSupport,#viewCostCalc{color:#dbe5f5}
#viewUnit>div,#viewTOP>div,#viewMediator>div,#viewCost>div,#viewSupport>div,#viewCostCalc>div{color:#cbd5e1}
[style*="background: #f8f9fa"],[style*="background:#f8f9fa"]{background:#0a1427!important}
[style*="border: 1px solid #e0e4ea"],[style*="border:1px solid #e0e4ea"]{border-color:rgba(148,163,184,.12)!important}
[style*="border-bottom: 1px solid #f0f2f5"],[style*="border-bottom:1px solid #f0f2f5"]{border-color:rgba(148,163,184,.09)!important}
[style*="color: #0e1a2b"],[style*="color:#0e1a2b"]{color:#e8eef8!important}
[style*="color: #555"],[style*="color:#555"]{color:#9aa8bd!important}
[style*="color: #ffd700"],[style*="color:#ffd700"]{color:#e0b53d!important}
[style*="color: #27ae60"],[style*="color:#27ae60"]{color:#34d399!important}
[style*="color: #2980b9"],[style*="color:#2980b9"]{color:#60a5fa!important}
.page-header h4 span i[style]{color:#60a5fa!important}
@media(max-width:991px){.main-content{padding-top:24px}.sidebar{top:72px}.page-header{margin-bottom:16px}.tab-nav{border-radius:13px}.tab-nav .nav-tabs .nav-link{padding:9px 11px}}
@media(max-width:520px){.main-content{padding:17px 10px 34px}.tab-nav{margin-bottom:14px}.tab-nav .nav-tabs .nav-link{font-size:9px;padding:8px 10px}.info-value{font-size:11px}}
</style>

<style>
/* FINAL DETAIL TR POLISH — KEEP SYSTEM/LOGIC UNCHANGED */
.main-content{padding-top:28px}
.page-header{margin-bottom:18px}
.page-header h4{font-size:24px}
.page-header h4 span{box-shadow:0 8px 24px rgba(59,130,246,.08)}
.card-body-custom{padding:20px}
.card-custom .card-body{padding:20px!important;background:transparent!important}
.card-custom hr{border:0;border-top:1px solid rgba(148,163,184,.10);opacity:1;margin:6px 0 20px}
.card-custom .row{--bs-gutter-x:24px;--bs-gutter-y:0}
.info-label{margin-top:1px}
.info-value{margin-bottom:16px;padding-bottom:1px}
.info-value:last-child{margin-bottom:0}
.tab-nav{margin-bottom:16px}
.tab-nav .nav-tabs{align-items:stretch}
.tab-nav .nav-tabs .nav-link{min-height:40px;justify-content:center}
.tab-nav .nav-tabs .nav-link i{width:14px;text-align:center}
.form-control,.form-select{min-height:38px}
textarea.form-control{min-height:96px;resize:vertical}
.form-control:disabled,.form-control[readonly]{opacity:.78}
.btn{line-height:1.35}
.btn-primary-custom,.btn-secondary-custom,.btn-success-custom,.btn-danger-custom{display:inline-flex;align-items:center;justify-content:center;gap:7px}
.total-box{margin-top:8px}
.support-row{box-shadow:0 8px 20px rgba(0,0,0,.08)}
.cost-item-header,.mediator-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
#editSummaryForm,#approvalForm,#costFormContainer,#mediatorFormContainer,#editSupport,#editCostCalc{margin:0 0 20px!important}
#editSummaryForm .row,#approvalForm .row,#costFormContainer .row,#mediatorFormContainer .row,#editSupport .row,#editCostCalc .row{--bs-gutter-y:14px}
.table-custom{border-collapse:separate;border-spacing:0}
.table-custom td,.table-custom th{white-space:normal}
.badge-status-tr{min-height:24px}
.alert{margin-bottom:18px}
@media(max-width:991px){
 .main-content{padding:22px 18px 40px}
 .card-body-custom,.card-custom .card-body{padding:17px!important}
 .card-custom .row{--bs-gutter-x:18px}
}
@media(max-width:767px){
 .page-header{gap:12px}
 .page-header h4{font-size:20px}
 .page-header>div:last-child{width:100%}
 .page-header>div:last-child .btn{width:100%}
 .tab-nav{padding:3px}
 .tab-nav .nav-tabs .nav-link{min-height:38px;padding:9px 11px}
 .info-value{margin-bottom:14px}
}
@media(max-width:520px){
 .main-content{padding:16px 10px 32px}
 .card-body-custom,.card-custom .card-body{padding:14px!important}
 .card-header-custom{min-height:58px}
 .card-header-custom h6{font-size:11px}
 .tab-nav .nav-tabs .nav-link{font-size:9px;gap:5px;padding:8px 10px}
}
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
            <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i><span>Account Management</span></a>
        <?php endif; ?>
        <?php if (in_array('transaction_request', $menuNames)): ?>
            <a href="transactionrequest.php" class="nav-item active"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a>
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
        <div class="user-profile"><div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div><div class="user-info"><div class="name"><strong><?= htmlspecialchars($fullName) ?></strong></div><div class="role"><?= getRoleLabel($role) ?></div></div></div>
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
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'summary' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=summary">
                        <i class="fas fa-info-circle"></i> Summary
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'detail_unit' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=detail_unit">
                        <i class="fas fa-boxes"></i> Detail Unit
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'term_of_payment' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=term_of_payment">
                        <i class="fas fa-money-bill-wave"></i> Term Of Payment
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'mediator' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=mediator">
                        <i class="fas fa-user-tie"></i> Data Mediator
                    </a>
                </li>
                <?php if ($canViewBusinessTabs): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'additional_cost' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=additional_cost">
                        <i class="fas fa-coins"></i> Additional Cost
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'product_support' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=product_support">
                        <i class="fas fa-headset"></i> Product Support
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $activeTab == 'cost_calculation' ? 'active' : '' ?>" href="detailtr.php?tr_number=<?= urlencode($tr_number) ?>&tab=cost_calculation">
                        <i class="fas fa-calculator"></i> Cost Calculation
                    </a>
                </li>
                <?php endif; ?>
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
                    <?php if ($canEditSalesSection): ?>
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
                    
                </div>
                
                <?php if ($currentApprovalOrder > 0 && $currentApprovalOrder <= $totalApprovalLevels && $request['status'] == 'pending'): ?>
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
                <?php if ($canEditSalesSection): ?>
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
                                <label class="form-label">Free Part/Service</label>
                                <input type="text" name="free_part_service" class="form-control" placeholder="Contoh: Free Filter 500 Jam, Free Service 1x">
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

                                    <div class="info-label">Free Part/Service</div>
                                    <div class="info-value"><?= htmlspecialchars($unit['free_part_service']) ?: '-' ?></div>

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
                <?php if ($canEditSalesSection): ?>
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
        <!-- TAB CONTENT: DATA MEDIATOR (MULTIPLE) -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'mediator'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-user-tie"></i> Data Mediator Fee</h6>
                <?php if ($canEditSalesSection): ?>
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

        <!-- ============================================ -->
        <!-- TAB CONTENT: ADDITIONAL COST (MULTIPLE ITEMS) -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'additional_cost' && $canViewBusinessTabs): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-coins"></i> Additional Cost / Machines</h6>
                <?php if ($canEditBusinessSection): ?>
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
        <!-- TAB CONTENT: PRODUCT SUPPORT -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'product_support' && $canViewBusinessTabs): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-headset"></i> Product Support</h6>
                <?php if ($canEditBusinessSection): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editSupport', 'viewSupport')">
                    <i class="fas fa-edit"></i> <?= count($trSupports) > 0 ? 'Edit Support' : 'Tambah Support' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editSupport" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_product_support">
                        
                        <div id="supportRows">
                            <!-- Support rows akan ditambahkan di sini oleh JavaScript -->
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addSupportRow()">
                                <i class="fas fa-plus"></i> Tambah Support
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Semua Support
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editSupport', 'viewSupport')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewSupport">
                    <?php if (count($trSupports) > 0): ?>
                        <?php foreach ($trSupports as $index => $support): ?>
                            <div class="card mb-3" style="border: 1px solid #e0e4ea; border-radius: 10px;">
                                <div class="card-header" style="background: #f8f9fa; border-bottom: 1px solid #e0e4ea; border-radius: 10px 10px 0 0; padding: 10px 15px;">
                                    <strong style="color: #0e1a2b;">
                                        <i class="fas fa-headset" style="color: #ffd700;"></i> 
                                        Support <?= $index + 1 ?>
                                    </strong>
                                </div>
                                <div class="card-body" style="padding: 15px;">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-label">Nama Support</div>
                                            <div class="info-value"><?= htmlspecialchars($support['support_name']) ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Keterangan</div>
                                            <div class="info-value"><?= htmlspecialchars($support['keterangan'] ?: '-') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-headset me-2"></i> Belum ada data product support
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: COST CALCULATION -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'cost_calculation' && $canViewBusinessTabs): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-calculator"></i> Cost Calculation</h6>
                <?php if ($canEditBusinessSection): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editCostCalc', 'viewCostCalc')"><i class="fas fa-edit"></i> <?= $costCalculation ? 'Edit Calculation' : 'Tambah Calculation' ?></button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editCostCalc" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_cost_calculation">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Dealer Price (Rp) *</label>
                                <input type="number" name="dealer_price" id="dealer_price" class="form-control" min="0" step="0.01" value="<?= $costCalculation['dealer_price'] ?? 0 ?>" required onchange="calculateCostCalc()" onkeyup="calculateCostCalc()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Persentase Diskon (%) *</label>
                                <input type="number" name="persentase" id="persentase" class="form-control" min="0" max="100" step="0.01" value="<?= $costCalculation['persentase'] ?? 0 ?>" required onchange="calculateCostCalc()" onkeyup="calculateCostCalc()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Support Price</label>
                                <input type="text" id="support_price_display" class="form-control" readonly>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Additional Cost</label>
                                <input type="text" class="form-control" value="Rp <?= number_format($totalAdditionalCost, 0, ',', '.') ?>" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Mediator Fee</label>
                                <input type="text" class="form-control" value="Rp <?= number_format($totalMediatorFee, 0, ',', '.') ?>" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Total COGS</label>
                                <input type="text" id="total_cogs_display" class="form-control" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Selling Price (Non PPN)</label>
                                <input type="text" class="form-control" value="Rp <?= number_format($totalUnitPrice, 0, ',', '.') ?>" readonly>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dealer Profit Request</label>
                                <input type="text" id="dealer_profit_display" class="form-control" readonly style="font-weight: bold; color: #27ae60;">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Dealer Profit Net (%)</label>
                                <input type="text" id="dealer_profit_net_display" class="form-control" readonly style="font-weight: bold; color: #2980b9;">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan Cost Calculation</button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editCostCalc', 'viewCostCalc')"><i class="fas fa-times"></i> Batal</button>
                    </form>
                </div>
                <div id="viewCostCalc">
                    <?php if ($costCalculation): ?>
                        <?php $dealer_profit_net = $costCalculation['selling_price'] > 0 ? ($costCalculation['dealer_profit_request'] / $costCalculation['selling_price']) * 100 : 0; ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Dealer Price</div>
                                <div class="info-value">Rp <?= number_format($costCalculation['dealer_price'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Persentase</div>
                                <div class="info-value"><?= $costCalculation['persentase'] ?>%</div>
                                
                                <div class="info-label">Support Price</div>
                                <div class="info-value">Rp <?= number_format($costCalculation['support_price'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Additional Cost</div>
                                <div class="info-value">Rp <?= number_format($costCalculation['additional_cost'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Mediator Fee</div>
                                <div class="info-value">Rp <?= number_format($totalMediatorFee, 0, ',', '.') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Total COGS</div>
                                <div class="info-value">Rp <?= number_format($costCalculation['total_cogs'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Selling Price (Non PPN)</div>
                                <div class="info-value">Rp <?= number_format($costCalculation['selling_price'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Dealer Profit Request</div>
                                <div class="info-value" style="color: #27ae60; font-weight: 700;">Rp <?= number_format($costCalculation['dealer_profit_request'], 0, ',', '.') ?></div>
                                
                                <div class="info-label">Dealer Profit Net</div>
                                <div class="info-value" style="color: #2980b9; font-weight: 700;"><?= number_format($dealer_profit_net, 2, ',', '.') ?>%</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted"><i class="fas fa-calculator me-2"></i> Belum ada data Cost Calculation</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
    <!-- END MAIN CONTENT -->

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
        // FUNGSI UMUM TOGGLE SECTION
        // ============================================
        function toggleSection(editId, viewId) {
            const editEl = document.getElementById(editId);
            const viewEl = document.getElementById(viewId);
            if (editEl.style.display === 'none') {
                editEl.style.display = 'block';
                viewEl.style.display = 'none';
                if (editId === 'editSupport') { loadSupportData(); }
                if (editId === 'editCostCalc') { calculateCostCalc(); }
            } else {
                editEl.style.display = 'none';
                viewEl.style.display = 'block';
            }
        }

        function calculateCostCalc() {
            const dealerPrice = parseFloat(document.getElementById('dealer_price').value) || 0;
            const persentase = parseFloat(document.getElementById('persentase').value) || 0;
            const additionalCost = <?= (float)$totalAdditionalCost ?>;
            const mediatorFee = <?= (float)$totalMediatorFee ?>;
            const sellingPrice = <?= (float)$totalUnitPrice ?>;

            const supportPrice = dealerPrice - (dealerPrice * (persentase / 100));
            const totalCogs = supportPrice + additionalCost + mediatorFee;
            const dealerProfitRequest = sellingPrice - totalCogs;
            const dealerProfitNet = sellingPrice > 0 ? (dealerProfitRequest / sellingPrice) * 100 : 0;

            document.getElementById('support_price_display').value = 'Rp ' + supportPrice.toLocaleString('id-ID');
            document.getElementById('total_cogs_display').value = 'Rp ' + totalCogs.toLocaleString('id-ID');
            document.getElementById('dealer_profit_display').value = 'Rp ' + dealerProfitRequest.toLocaleString('id-ID');
            document.getElementById('dealer_profit_net_display').value = dealerProfitNet.toFixed(2) + '%';
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
                const freePartServiceInput = document.querySelector('input[name="free_part_service"]');
                const locationInput = document.querySelector('input[name="machine_location"]');
                const deliveryTermsInput = document.querySelector('input[name="delivery_terms"]');
                const deliveryScheduleInput = document.querySelector('input[name="delivery_schedule"]');
                const transTypeInput = document.querySelector('select[name="transaction_type"]');
                
                specInput.value = '<?= addslashes($firstUnit['specification']) ?>';
                attachmentInput.value = '<?= addslashes($firstUnit['additional_attachment']) ?>';
                warantyInput.value = '<?= addslashes($firstUnit['waranty']) ?>';
                freePartServiceInput.value = '<?= addslashes($firstUnit['free_part_service']) ?>';
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

        // ============================================
        // FUNGSI UNTUK PRODUCT SUPPORT
        // ============================================
        let supportRowCount = 0;
        
        function addSupportRow(data = null) {
            supportRowCount++;
            const container = document.getElementById('supportRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'support-row';
            rowDiv.id = 'supportRow_' + supportRowCount;
            
            rowDiv.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-3 pb-2" style="border-bottom: 1px solid #f0f2f5;">
                    <strong><i class="fas fa-headset" style="color: #ffd700;"></i> Support ${supportRowCount}</strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeSupportRow(${supportRowCount})">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nama Support *</label>
                        <input type="text" name="support_name[]" class="form-control" placeholder="Contoh: Free Filter Engine, Jarak Service, dll" value="${data ? data.support_name : ''}" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="support_keterangan[]" class="form-control" placeholder="Keterangan (opsional)" value="${data ? data.keterangan : ''}">
                    </div>
                </div>
            `;
            
            container.appendChild(rowDiv);
        }
        
        function removeSupportRow(rowId) {
            const row = document.getElementById('supportRow_' + rowId);
            if (row) {
                row.remove();
                const rows = document.querySelectorAll('.support-row');
                rows.forEach((row, index) => {
                    const title = row.querySelector('strong');
                    if (title) {
                        title.innerHTML = `<i class="fas fa-headset" style="color: #ffd700;"></i> Support ${index + 1}`;
                    }
                });
            }
        }
        
        function loadSupportData() {
            const container = document.getElementById('supportRows');
            container.innerHTML = '';
            supportRowCount = 0;
            
            <?php if (count($trSupports) > 0): ?>
                <?php foreach ($trSupports as $support): ?>
                    addSupportRow({
                        support_name: '<?= addslashes($support['support_name']) ?>',
                        keterangan: '<?= addslashes($support['keterangan'] ?? '') ?>'
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addSupportRow();
            <?php endif; ?>
        }
    </script>
</body>
</html>