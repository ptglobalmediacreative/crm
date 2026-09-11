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
:root{--bg:#060b18;--panel:#0b1222;--panel2:#0d1730;--line:rgba(148,163,184,.16);--text:#f7f9ff;--muted:#8e9bb5;--blue:#3b82f6;--blue2:#60a5fa;--cyan:#22d3ee;--green:#34d399;--red:#fb7185;--amber:#fbbf24;--purple:#a78bfa}
*{box-sizing:border-box;margin:0;padding:0}body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 70% -10%,rgba(37,99,235,.20),transparent 30%),linear-gradient(145deg,#050914,#08111f 55%,#07162c);color:var(--text);min-height:100vh;overflow-x:hidden}.app{min-height:100vh}
.topbar{height:72px;border-bottom:1px solid var(--line);background:rgba(5,9,20,.88);backdrop-filter:blur(18px);display:flex;align-items:center;padding:0 26px;gap:24px;position:sticky;top:0;z-index:50}.brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:#fff;min-width:220px}.brand img{width:38px;height:38px;object-fit:contain}.brand strong{font-size:17px;letter-spacing:-.4px}.brand small{display:block;color:#65738e;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;margin-top:2px}.topnav{display:flex;align-items:center;gap:5px;flex:1}.topnav a{color:#9ca8bc;text-decoration:none;font-size:12px;font-weight:500;padding:10px 14px;border-radius:11px;transition:.2s}.topnav a:hover,.topnav a.active{color:#fff;background:rgba(59,130,246,.18)}.topnav a.active{box-shadow:inset 0 -2px 0 var(--blue2)}
.top-actions{display:flex;align-items:center;gap:10px}.search{width:190px;height:38px;border:1px solid var(--line);border-radius:20px;background:#0a1020;color:#dce5f5;display:flex;align-items:center;padding:0 13px;gap:9px}.search input{background:none;border:0;outline:0;color:#fff;width:100%;font-size:11px}.search input::placeholder{color:#65738e}.icon-btn{width:38px;height:38px;border:1px solid var(--line);background:#0a1020;color:#aeb9ca;border-radius:50%;display:flex;align-items:center;justify-content:center;position:relative}.notif{position:absolute;right:-2px;top:-3px;background:#ef4444;color:#fff;border-radius:10px;font-size:8px;padding:3px 5px;font-weight:700}.avatar{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;border:1px solid rgba(96,165,250,.5)}
.shell{display:flex}.rail{width:245px;position:fixed;top:72px;bottom:0;left:0;background:rgba(5,10,21,.92);border-right:1px solid var(--line);display:flex;flex-direction:column;padding:22px 14px;gap:6px;z-index:40;overflow-y:auto}.rail-label{font-size:9px;color:#52627d;text-transform:uppercase;letter-spacing:1.5px;font-weight:800;padding:8px 12px 7px}.rail a{width:100%;height:43px;border-radius:11px;color:#8794aa;display:flex;align-items:center;gap:12px;text-decoration:none;transition:.2s;padding:0 13px;font-size:11px;font-weight:600}.rail a i{width:20px;text-align:center;font-size:14px;color:#6e7d97}.rail a:hover,.rail a.active{color:#fff;background:linear-gradient(90deg,rgba(59,130,246,.20),rgba(37,99,235,.06));box-shadow:inset 2px 0 0 #60a5fa}.rail a.active i{color:#60a5fa}.rail .spacer{flex:1;min-height:20px}.rail-user{margin:8px 4px 4px;padding:12px;border:1px solid rgba(148,163,184,.10);background:rgba(10,18,34,.7);border-radius:13px;display:flex;align-items:center;gap:10px}.rail-user .mini-avatar{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#1e3a8a,#2563eb);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:800}.rail-user strong{display:block;font-size:10px;color:#e8eef9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.rail-user span{display:block;font-size:8px;color:#66758f;margin-top:2px}.content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px}.hero-row{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}.hero h1{font-size:26px;letter-spacing:-1px;font-weight:800;margin:0}.hero p{font-size:12px;color:var(--muted);margin-top:7px}.filters{display:flex;gap:8px;align-items:center}.filter{height:38px;border:1px solid var(--line);background:rgba(10,17,33,.85);color:#cdd7e7;border-radius:11px;padding:0 12px;font-size:11px;outline:none}.filter option{background:#0b1222}.btn-add{height:38px;border:0;border-radius:11px;background:linear-gradient(135deg,#3b82f6,#6366f1);color:#fff;font-size:11px;font-weight:700;padding:0 15px;box-shadow:0 0 24px rgba(59,130,246,.25)}
.kpis{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:14px}.kpi{position:relative;overflow:hidden;min-height:112px;background:linear-gradient(145deg,rgba(15,27,50,.96),rgba(8,16,31,.96));border:1px solid var(--line);border-radius:16px;padding:17px;box-shadow:0 12px 35px rgba(0,0,0,.18)}.kpi:after{content:"";position:absolute;width:85px;height:85px;border-radius:50%;right:-35px;bottom:-45px;background:rgba(59,130,246,.15);filter:blur(5px)}.kpi-top{display:flex;justify-content:space-between;align-items:center}.kpi-icon{width:31px;height:31px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:12px}.kpi-icon.blue{background:rgba(59,130,246,.15);color:#60a5fa}.kpi-icon.cyan{background:rgba(34,211,238,.12);color:#67e8f9}.kpi-icon.amber{background:rgba(251,191,36,.12);color:#fcd34d}.kpi-icon.red{background:rgba(251,113,133,.12);color:#fb7185}.kpi-icon.green{background:rgba(52,211,153,.12);color:#6ee7b7}.kpi-icon.purple{background:rgba(167,139,250,.12);color:#c4b5fd}.kpi .number{font-size:24px;font-weight:800;letter-spacing:-1px;margin-top:10px}.kpi .label{font-size:10px;color:#7f8ca5;margin-top:2px}.trend{font-size:9px;color:#56d6b0}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(290px,.8fr);gap:14px;margin-bottom:14px}.panel{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden}.panel-head{height:58px;padding:0 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(148,163,184,.10)}.panel-title{display:flex;align-items:center;gap:9px;font-size:13px;font-weight:700}.panel-title i{color:#60a5fa}.panel-sub{font-size:9px;color:#687791}.panel-body{padding:17px}.pipeline{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.stage{min-height:125px;border:1px solid rgba(148,163,184,.12);background:rgba(5,12,25,.54);border-radius:13px;padding:12px;position:relative}.stage:before{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:var(--c);box-shadow:0 0 14px var(--c)}.stage .stage-name{font-size:9px;color:#8592a9;text-transform:uppercase;letter-spacing:.5px}.stage .stage-num{font-size:22px;font-weight:800;margin-top:13px}.stage .stage-meta{font-size:9px;color:#63718a;margin-top:5px}.stage-bar{height:4px;background:#101c31;border-radius:5px;overflow:hidden;margin-top:14px}.stage-bar span{display:block;height:100%;background:var(--c);width:var(--w);box-shadow:0 0 10px var(--c)}
.hot-list{display:flex;flex-direction:column}.hot-item{display:grid;grid-template-columns:32px 1fr auto;gap:10px;align-items:center;padding:11px 0;border-bottom:1px solid rgba(148,163,184,.08)}.hot-item:last-child{border-bottom:0}.machine{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#102b55,#0b1930);display:flex;align-items:center;justify-content:center;color:#60a5fa}.hot-name{font-size:11px;font-weight:700}.hot-desc{font-size:9px;color:#687791;margin-top:3px}.hot-value{text-align:right;font-size:10px;font-weight:700}.score{font-size:8px;color:#60a5fa;margin-top:3px}.scorebar{width:64px;height:3px;border-radius:5px;background:#152238;margin-top:4px;overflow:hidden}.scorebar span{display:block;height:100%;background:linear-gradient(90deg,#6366f1,#22d3ee);width:var(--score)}
.lower{display:grid;grid-template-columns:1.2fr .8fr;gap:14px}.chart-wrap{height:260px}.activity-list{padding:4px 17px 10px}.activity{display:grid;grid-template-columns:30px 1fr auto;gap:10px;padding:12px 0;border-bottom:1px solid rgba(148,163,184,.08)}.activity:last-child{border-bottom:0}.act-icon{width:30px;height:30px;border-radius:9px;background:rgba(59,130,246,.12);color:#60a5fa;display:flex;align-items:center;justify-content:center;font-size:11px}.act-title{font-size:10px;font-weight:700}.act-desc{font-size:9px;color:#74829b;margin-top:3px}.act-time{font-size:8px;color:#56657e;white-space:nowrap}.empty{padding:30px;text-align:center;color:#66758f;font-size:11px}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding:17px}.quick{border:1px solid rgba(148,163,184,.10);background:rgba(6,13,27,.55);border-radius:12px;padding:13px;text-decoration:none;color:#dce5f5;transition:.2s}.quick:hover{border-color:rgba(59,130,246,.45);transform:translateY(-2px)}.quick i{color:#60a5fa;font-size:13px}.quick strong{display:block;font-size:10px;margin-top:9px}.quick span{font-size:8px;color:#687791}.footer{text-align:center;color:#44536c;font-size:9px;margin-top:22px}
@media(max-width:1200px){.kpis{grid-template-columns:repeat(3,1fr)}.topnav a{padding:9px 8px}.brand{min-width:190px}.search{width:150px}}@media(max-width:1050px){.topnav{display:none}.brand{flex:1}.dashboard-grid,.lower{grid-template-columns:1fr}.pipeline{grid-template-columns:repeat(3,1fr)}}@media(max-width:650px){.topbar{padding:0 14px}.content{padding:20px 14px 40px}.rail{display:none}.content{margin-left:0;width:100%}.kpis{grid-template-columns:repeat(2,1fr)}.hero-row{align-items:flex-start;flex-direction:column}.filters{width:100%;flex-wrap:wrap}.filter{flex:1;min-width:130px}.pipeline{grid-template-columns:1fr 1fr}.brand{min-width:0}.brand div{display:none}.top-actions .search{display:none}}
</style>
<style>
/* DETAIL TR COMPONENTS — same dashboard premium language */
.detail-shell{max-width:100%;}
.page-header{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:18px;}
.page-header h4{font-size:21px!important;font-weight:800!important;color:#eef3fa!important;letter-spacing:-.4px;margin:0!important;}
.page-header h4 span,.page-header h4 i{color:#60a5fa!important;}
.card-custom,.tab-nav{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96))!important;border:1px solid var(--line)!important;border-radius:14px!important;box-shadow:0 12px 35px rgba(0,0,0,.18)!important;}
.card-custom:hover,.tab-nav:hover{border-color:rgba(96,165,250,.22)!important;box-shadow:0 16px 40px rgba(0,0,0,.22)!important;}
.card-custom .card-header-custom{padding:16px 18px!important;border-bottom:1px solid rgba(148,163,184,.10)!important;}
.card-custom .card-header-custom h6{color:#dce5f1!important;font-size:13px!important;font-weight:700!important;}
.card-custom .card-header-custom h6 i{color:#60a5fa!important;margin-right:7px!important;}
.card-custom .card-body-custom{padding:20px!important;color:#cbd5e1!important;}
.info-label{color:#687791!important;font-size:9px!important;letter-spacing:.8px!important;}
.info-value{color:#e2e8f0!important;font-size:12px!important;font-weight:600!important;}
.form-label{color:#8d9bb0!important;font-size:10px!important;}
.form-control,.form-select{background:#080e1b!important;color:#dbe5f2!important;border:1px solid rgba(148,163,184,.16)!important;border-radius:9px!important;font-size:12px!important;}
.form-control:focus,.form-select:focus{background:#0a1120!important;color:#fff!important;border-color:rgba(96,165,250,.6)!important;box-shadow:0 0 0 3px rgba(59,130,246,.1)!important;}
.form-select option{background:#0b1120;color:#e5edf7;}
.tab-nav{padding:5px!important;margin-bottom:14px!important;overflow-x:auto!important;white-space:nowrap!important;}
.tab-nav .nav-tabs{padding:3px!important;gap:3px!important;border:0!important;}
.tab-nav .nav-tabs .nav-link{color:#7f8ba0!important;border:0!important;border-radius:9px!important;padding:10px 14px!important;font-size:10px!important;font-weight:600!important;}
.tab-nav .nav-tabs .nav-link:hover{background:rgba(255,255,255,.035)!important;color:#dbe7f5!important;}
.tab-nav .nav-tabs .nav-link.active{background:rgba(59,130,246,.14)!important;color:#70adff!important;}
.support-row{background:rgba(6,13,27,.55)!important;border:1px solid rgba(148,163,184,.12)!important;color:#cbd5e1!important;border-radius:10px!important;}
.total-box{background:#080e1b!important;border:1px solid var(--line)!important;color:#fff!important;}
.total-box .total-label{color:#718097!important;}
.total-box .total-value{color:#60a5fa!important;}
.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#2563eb)!important;border:1px solid rgba(96,165,250,.25)!important;color:#fff!important;border-radius:10px!important;}
.btn-primary-custom:hover{background:linear-gradient(135deg,#60a5fa,#3b82f6)!important;color:#fff!important;}
.btn-success-custom{background:#15803d!important;border-radius:10px!important;}
.btn-danger-custom{background:#b91c1c!important;border-radius:10px!important;}
.btn-secondary-custom{background:#0a1020!important;border:1px solid var(--line)!important;color:#aeb9ca!important;border-radius:10px!important;}
.btn-secondary-custom:hover{background:#111a2d!important;border-color:rgba(96,165,250,.45)!important;color:#fff!important;}
.badge-status-tr.pending{background:rgba(234,179,8,.12)!important;color:#facc15!important;}
.badge-status-tr.approved{background:rgba(59,130,246,.12)!important;color:#60a5fa!important;}
.badge-status-tr.rejected{background:rgba(239,68,68,.12)!important;color:#f87171!important;}
.alert{background:#0b1120!important;border:1px solid var(--line)!important;color:#cbd5e1!important;border-radius:10px!important;}
.table,.table *{border-color:var(--line)!important;}
.table{--bs-table-bg:transparent!important;--bs-table-color:#cbd5e1!important;}
.table thead th{background:#0a1020!important;color:#718097!important;}
.table tbody td{background:#0b1120!important;color:#cbd5e1!important;}
.table tbody tr:hover td{background:rgba(255,255,255,.025)!important;}
.text-muted{color:#68758a!important;}
#editSummaryForm,#addUnitForm{background:#080e1b!important;border:1px solid var(--line)!important;}
.cost-item-header,.mediator-header,.d-flex.justify-content-between{border-bottom-color:var(--line)!important;}
.cost-item-header strong,.mediator-header strong,.d-flex.justify-content-between strong{color:#dce5f1!important;}
hr{border-color:var(--line)!important;opacity:1!important;}
.footer-text{color:#536176!important;}
@media(max-width:1050px){.page-header{align-items:flex-start;flex-direction:column;}.page-header>div:last-child{width:100%;}.page-header>div:last-child .btn-secondary-custom{width:auto;}}
@media(max-width:650px){.page-header h4{font-size:17px!important;}.card-custom .card-body-custom{padding:15px!important;}.tab-nav .nav-tabs .nav-link{padding:9px 10px!important;font-size:9px!important;}}
</style>
</head>
<body>
<div class="app">
<header class="topbar">
    <a class="brand" href="dashboard.php"><img src="images/logo.webp" alt="GET"><div><strong>Ganda Elang CRM</strong><small>Heavy Equipment Dealer</small></div></a>
    <nav class="topnav">
        <a href="dashboard.php">Dashboard</a>
        <?php if(in_array('sales_activity',$menuNames)): ?><a href="salesactivity.php">Sales Activity</a><?php endif; ?>
        <?php if(in_array('account_management',$menuNames)): ?><a href="account_management.php">Account</a><?php endif; ?>
        <?php if(in_array('transaction_request',$menuNames)): ?><a class="active" href="transactionrequest.php">TR Request</a><?php endif; ?>
        <?php if(in_array('produk',$menuNames)): ?><a href="produk.php">Produk</a><?php endif; ?>
        <?php if(in_array('delivery_order',$menuNames)): ?><a href="deliveryinstruction.php">Delivery</a><?php endif; ?>
        <?php if(in_array('data_user',$menuNames)): ?><a href="data_user.php">User</a><?php endif; ?>
        <?php if(in_array('data_sales',$menuNames) && file_exists('data_sales.php')): ?><a href="data_sales.php">Data Sales</a><?php endif; ?>
    </nav>
    <div class="top-actions"><button class="icon-btn" type="button" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button><div class="avatar"><?= strtoupper(substr($fullName,0,1)) ?></div></div>
</header>
<div class="shell">
<aside class="rail">
    <div class="rail-label">Main Menu</div>
    <a href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if(in_array('sales_activity',$menuNames)): ?><a href="salesactivity.php"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a><?php endif; ?>
    <?php if(in_array('account_management',$menuNames)): ?><a href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a><?php endif; ?>
    <?php if(in_array('transaction_request',$menuNames)): ?><a class="active" href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a><?php endif; ?>
    <?php if(in_array('produk',$menuNames)): ?><a href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a><?php endif; ?>
    <?php if(in_array('delivery_order',$menuNames)): ?><a href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a><?php endif; ?>
    <div class="rail-label">Administration</div>
    <?php if(in_array('data_user',$menuNames)): ?><a href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a><?php endif; ?>
    <?php if(in_array('data_sales',$menuNames) && file_exists('data_sales.php')): ?><a href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a><?php endif; ?>
    <div class="spacer"></div>
    <div class="rail-user"><div class="mini-avatar"><?= strtoupper(substr($fullName,0,1)) ?></div><div><strong><?= htmlspecialchars($fullName) ?></strong><span><?= htmlspecialchars(getRoleLabel($role)) ?></span></div></div>
    <a href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
</aside>
<main class="content">
<section class="hero-row"><div class="hero"><div class="eyebrow">PT Ganda Elang Tangguh · CRM Command Center</div><h1>Detail Transaction Request</h1><p>Review customer transaction details, approval flow and commercial information.</p></div><div class="filters"><a href="transactionrequest.php" class="btn-add" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px;"><i class="fas fa-arrow-left"></i> Kembali</a></div></section>


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
</main>
</div>
</div>
</body>
</html>