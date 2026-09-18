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
function resetApprovalHistory($db, $tr_number, $force = false) {
    // Saat TR masih rejected, perubahan data revisi tidak langsung mengubah status.
    // Status baru kembali pending hanya ketika user menekan "Ajukan TR Kembali".
    if (!$force) {
        $checkStatus = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
        $checkStatus->execute([$tr_number]);
        $currentStatus = $checkStatus->fetchColumn();
        if ($currentStatus === 'rejected') {
            return true;
        }
    }

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
// ATUR HAK EDIT BERDASARKAN STATUS TR TERKINI
// ============================================
// Pending  = boleh diedit oleh Sales pemilik TR
// Rejected = boleh diedit oleh Sales pemilik TR
// Approved = dikunci (final)
//
// Jangan menggunakan riwayat approval sebagai lock edit karena sebuah TR
// dapat pernah melewati approval lalu kembali menjadi pending/rejected.
$statusTRNormalized = strtolower(trim((string)$statusTR));
$isFinalApproved = ($statusTRNormalized === 'approved');

if ($isFinalApproved) {
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
// AMBIL INFORMASI REJECTION TERAKHIR
// ============================================
$rejectionInfo = null;
try {
    $sqlRejection = "SELECT h.catatan, h.approved_at, h.approved_by, u.full_name AS rejected_by_name
                     FROM tr_approval_history h
                     LEFT JOIN users u ON h.approved_by = u.id
                     WHERE h.trf_number = ?
                       AND h.status = 'rejected'
                     ORDER BY h.id DESC
                     LIMIT 1";
    $stmtRejection = $db->prepare($sqlRejection);
    $stmtRejection->execute([$tr_number]);
    $rejectionInfo = $stmtRejection->fetch() ?: null;
} catch (Exception $e) {
    $rejectionInfo = null;
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

    // ============================================
    // SAVE CUSTOMER DEAL (SETELAH FINAL APPROVAL)
    // SUMBER CUSTOMER DEAL: detail_transaction_requests
    // Aktivitas terkait diverifikasi melalui TR Number + sales_activity_id.
    // ============================================
    if ($action === 'save_customer_deal') {
        try {
            if ($userRole !== 'sales' || !isset($request['sales_user_id']) || (int)$request['sales_user_id'] !== (int)$userId) {
                throw new Exception('Hanya Sales pemilik TR yang dapat mengisi Customer Deal.');
            }

            $deal = strtolower(trim((string)($_POST['customer_deal'] ?? '')));
            $dealKeterangan = trim((string)($_POST['customer_deal_keterangan'] ?? ''));

            if (!in_array($deal, ['yes', 'no'], true)) {
                throw new Exception('Pilihan Customer Deal tidak valid.');
            }

            if ($deal === 'no' && $dealKeterangan === '') {
                throw new Exception('Keterangan wajib diisi jika Customer Deal = No.');
            }

            if (empty($request['sales_activity_id'])) {
                throw new Exception('Activity Number untuk TR ini tidak ditemukan. Customer Deal tidak dapat disimpan.');
            }

            $db->beginTransaction();

            // Kunci record Detail TR TERBARU agar tidak terjadi update ke record historis.
            $lockDetail = $db->prepare("
                SELECT id, status, trf_number, customer_deal, customer_deal_keterangan
                FROM detail_transaction_requests
                WHERE trf_number = ?
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $lockDetail->execute([$tr_number]);
            $lockedDetail = $lockDetail->fetch(PDO::FETCH_ASSOC);

            if (!$lockedDetail) {
                throw new Exception('Detail Transaction Request tidak ditemukan.');
            }

            if (strtolower(trim((string)($lockedDetail['status'] ?? ''))) !== 'approved') {
                throw new Exception('Customer Deal hanya dapat diisi setelah seluruh approval selesai.');
            }

            // Pastikan TR Number memang terhubung dengan Activity Number yang sedang dibuka.
            $checkActivity = $db->prepare("
                SELECT id
                FROM activity_details
                WHERE tr_number = ?
                  AND sales_activity_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $checkActivity->execute([$tr_number, (int)$request['sales_activity_id']]);

            if (!$checkActivity->fetchColumn()) {
                throw new Exception('TR Number dan Activity Number tidak cocok. Customer Deal tidak dapat disimpan.');
            }

            // Update HANYA Detail TR terbaru, bukan seluruh histori dengan TR Number yang sama.
            $saveDeal = $db->prepare("
                UPDATE detail_transaction_requests
                SET customer_deal = ?,
                    customer_deal_keterangan = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $saveDeal->execute([
                $deal,
                $deal === 'no' ? $dealKeterangan : null,
                (int)$lockedDetail['id']
            ]);

            $db->commit();
            setFlash('Status Customer Deal berhasil disimpan!', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('Gagal menyimpan Customer Deal: ' . $e->getMessage(), 'danger');
        }
        redirect("detailtr.php?tr_number=" . urlencode($tr_number) . "&tab=summary");
    }
    
    $salesEditActions = ['save_summary', 'save_unit', 'delete_unit', 'save_top', 'save_mediator'];
    $businessEditActions = ['save_cost', 'save_product_support', 'save_cost_calculation'];

    if (in_array($action, $salesEditActions, true) && !$canEditSalesSection) {
        if ($isFinalApproved) {
            setFlash('TR sudah Approved final sehingga data tidak dapat diedit lagi.', 'danger');
        } else {
            setFlash('Hanya Sales pemilik TR yang dapat menambah atau mengedit bagian ini.', 'danger');
        }
        redirect('detailtr.php?tr_number=' . urlencode($tr_number) . '&tab=summary');
    }

    if (in_array($action, $businessEditActions, true) && !$canEditBusinessSection) {
        if ($isFinalApproved) {
            setFlash('TR sudah Approved final sehingga data tidak dapat diedit lagi.', 'danger');
        } else {
            setFlash('Hanya Divisi Business yang dapat menambah atau mengedit bagian ini.', 'danger');
        }
        redirect('detailtr.php?tr_number=' . urlencode($tr_number) . '&tab=summary');
    }
    
    // AJUKAN KEMBALI TR SETELAH REJECT
    if ($action === 'resubmit_rejected') {
        try {
            if (!$canEditSalesSection) {
                throw new Exception('Hanya Sales pemilik TR yang dapat mengajukan kembali TR ini.');
            }

            $db->beginTransaction();

            $lockResubmit = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $lockResubmit->execute([$tr_number]);
            $lockedResubmit = $lockResubmit->fetch();

            if (!$lockedResubmit) {
                throw new Exception('Detail Transaction Request tidak ditemukan.');
            }
            if (($lockedResubmit['status'] ?? '') !== 'rejected') {
                throw new Exception('TR hanya dapat diajukan kembali jika berstatus rejected.');
            }

            // Hapus approval lama agar alur kembali ke Sales Manager.
            resetApprovalHistory($db, $tr_number, true);

            $db->commit();
            setFlash('TR berhasil diajukan kembali dan kembali ke approval awal (Sales Manager).', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('Gagal mengajukan kembali TR: ' . $e->getMessage(), 'danger');
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
            $postedOrder = (int)($_POST['approval_order'] ?? 0);
            $approvalComment = trim((string)($_POST['approval_comment'] ?? ''));

            if ($approvalStatus === 'rejected' && $approvalComment === '') {
                throw new Exception('Alasan reject wajib diisi.');
            }

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
                    $updateApproval = $db->prepare("UPDATE tr_approval_history SET approval_role = ?, status = ?, catatan = ?, approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $updateApproval->execute([$approvalLevels[$serverCurrentOrder]['role'], $approvalStatus, $approvalComment, $userId, $existingApproval['id']]);
                } else {
                    $insertApproval = $db->prepare("INSERT INTO tr_approval_history (trf_number, approval_order, approval_role, status, catatan, approved_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $insertApproval->execute([$tr_number, $serverCurrentOrder, $approvalLevels[$serverCurrentOrder]['role'], $approvalStatus, $approvalComment, $userId]);
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
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET updated_at = NOW() WHERE trf_number = ?");
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
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET updated_at = NOW() WHERE trf_number = ?");
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
            
            $updateDetail = $db->prepare("UPDATE detail_transaction_requests SET updated_at = NOW() WHERE trf_number = ?");
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
    <link rel="stylesheet" href="css/navigation.css">
    <link rel="stylesheet" href="css/notification.css">
    <link rel="stylesheet" href="css/detailtr.css">
    
    



</head>
<body>    
    <?php require_once 'navigation.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h4><span><i class="fas fa-file-signature"></i></span> Detail TR - <?= htmlspecialchars($tr_number) ?></h4>
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
                    <?php if ($request['status'] === 'rejected' && $canEditSalesSection): ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirmResubmit()">
                        <input type="hidden" name="action" value="resubmit_rejected">
                        <button type="submit" class="btn btn-primary-custom btn-sm">
                            <i class="fas fa-paper-plane"></i> Ajukan TR Kembali
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canEditSalesSection): ?>
                    <button type="button" class="btn btn-primary-custom btn-sm" onclick="showEditSummary(event); return false;">
                        <i class="fas fa-edit"></i> Edit
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body-custom">
                <div id="editSummaryForm" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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
                
                <?php if ($request['status'] === 'rejected' && $rejectionInfo): ?>
                <div class="rejection-notice">
                    <div class="rejection-notice-head">
                        <div><i class="fas fa-circle-exclamation"></i> TR Ditolak</div>
                        <span><?= !empty($rejectionInfo['approved_at']) ? date('d/m/Y H:i', strtotime($rejectionInfo['approved_at'])) : '-' ?></span>
                    </div>
                    <div class="rejection-notice-body">
                        <div class="rejection-item">
                            <span class="rejection-label">Alasan / Komentar Reject</span>
                            <div class="rejection-comment"><?= nl2br(htmlspecialchars($rejectionInfo['catatan'] ?? '-')) ?></div>
                        </div>
                        <div class="rejection-item">
                            <span class="rejection-label">Rejected By</span>
                            <strong><?= htmlspecialchars($rejectionInfo['rejected_by_name'] ?? '-') ?></strong>
                        </div>
                    </div>
                    <div class="rejection-hint"><i class="fas fa-lightbulb"></i> Silakan revisi data TR terlebih dahulu, lalu klik <strong>Ajukan TR Kembali</strong> untuk memulai approval dari Sales Manager.</div>
                </div>
                <?php endif; ?>

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

                <?php if ($request['status'] === 'approved'): ?>
                <?php
                    $customerDeal = strtolower(trim((string)($detailTR['customer_deal'] ?? '')));
                    $customerDealKeterangan = (string)($detailTR['customer_deal_keterangan'] ?? '');
                    $canSetCustomerDeal = (
                        $userRole === 'sales' &&
                        isset($request['sales_user_id']) &&
                        (int)$request['sales_user_id'] === (int)$userId
                    );
                ?>
                <div class="customer-deal-card">
                    <div class="customer-deal-head">
                        <div>
                            <div class="customer-deal-title"><i class="fas fa-handshake"></i> Customer Deal</div>
                            <div class="customer-deal-subtitle">Diisi setelah seluruh approval TR selesai.</div>
                        </div>
                        <?php if ($customerDeal === 'yes'): ?>
                            <span class="customer-deal-status yes"><i class="fas fa-check-circle"></i> Deal: Yes</span>
                        <?php elseif ($customerDeal === 'no'): ?>
                            <span class="customer-deal-status no"><i class="fas fa-times-circle"></i> Deal: No</span>
                        <?php else: ?>
                            <span class="customer-deal-status" style="background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.18);"><i class="fas fa-clock"></i> Belum diisi</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($customerDeal === 'no' && $customerDealKeterangan !== ''): ?>
                    <div class="mb-3">
                        <div class="info-label">Keterangan Customer Tidak Deal</div>
                        <div class="customer-deal-readonly"><?= nl2br(htmlspecialchars($customerDealKeterangan)) ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if ($canSetCustomerDeal): ?>
                    <div class="customer-deal-actions">
                        <form method="POST" onsubmit="return confirmCustomerDeal('yes')">
                            <input type="hidden" name="action" value="save_customer_deal">
                            <input type="hidden" name="customer_deal" value="yes">
                            <button type="submit" class="customer-deal-btn yes"><i class="fas fa-check"></i> Customer Deal: Yes</button>
                        </form>
                        <button type="button" class="customer-deal-btn no" onclick="toggleCustomerDealNo()"><i class="fas fa-times"></i> Customer Deal: No</button>
                    </div>

                    <div id="customerDealNoForm" class="customer-deal-form" style="display:none;">
                        <form method="POST" onsubmit="return validateCustomerDealNo()">
                            <input type="hidden" name="action" value="save_customer_deal">
                            <input type="hidden" name="customer_deal" value="no">
                            <label class="form-label" for="customerDealKeterangan">Keterangan Kenapa Customer Tidak Deal <span style="color:#fb7185;">*</span></label>
                            <textarea id="customerDealKeterangan" name="customer_deal_keterangan" class="form-control" maxlength="2000" placeholder="Contoh: Customer menunda pembelian, harga belum sesuai, memilih kompetitor, budget belum tersedia, dll."><?= htmlspecialchars($customerDealKeterangan) ?></textarea>
                            <div class="mt-3">
                                <button type="submit" class="btn btn-danger-custom"><i class="fas fa-save"></i> Simpan Customer Deal: No</button>
                                <button type="button" class="btn btn-secondary-custom" onclick="toggleCustomerDealNo()"><i class="fas fa-times"></i> Batal</button>
                            </div>
                        </form>
                    </div>
                    <?php elseif ($userRole !== 'sales'): ?>
                    <div class="customer-deal-subtitle"><i class="fas fa-info-circle"></i> Customer Deal hanya dapat diisi oleh Sales pemilik TR.</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
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
                    <div class="mt-4 p-3" style="border-radius: 10px;">
                        <h6 class="mb-3"><i class="fas fa-check-double"></i> Approval Action</h6>
                        <form method="POST" id="approvalForm">
                            <input type="hidden" name="action" id="approvalAction" value="approve">
                            <input type="hidden" name="approval_order" value="<?= $currentApprovalOrder ?>">
                            <input type="hidden" name="approval_comment" id="approvalComment" value="">
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

        <!-- MODAL ALASAN REJECT -->
        <div id="rejectModal" class="reject-modal" aria-hidden="true">
            <div class="reject-modal-backdrop" onclick="closeRejectModal()"></div>
            <div class="reject-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
                <div class="reject-modal-header">
                    <div>
                        <span class="reject-modal-icon"><i class="fas fa-times-circle"></i></span>
                        <div>
                            <h5 id="rejectModalTitle">Reject Transaction Request</h5>
                            <p>Berikan alasan agar Sales dapat melakukan revisi dengan jelas.</p>
                        </div>
                    </div>
                    <button type="button" class="reject-modal-close" onclick="closeRejectModal()" aria-label="Tutup"><i class="fas fa-times"></i></button>
                </div>
                <div class="reject-modal-body">
                    <label for="rejectReason">Komentar / Alasan Reject <span>*</span></label>
                    <textarea id="rejectReason" class="form-control" rows="5" maxlength="2000" placeholder="Contoh: Harga unit perlu direvisi dan Term of Payment belum sesuai."></textarea>
                    <div class="reject-modal-note"><i class="fas fa-info-circle"></i> Alasan ini akan tampil di Summary TR beserta nama user yang melakukan reject.</div>
                </div>
                <div class="reject-modal-footer">
                    <button type="button" class="btn btn-secondary-custom" onclick="closeRejectModal()">Batal</button>
                    <button type="button" class="btn btn-danger-custom" onclick="confirmReject()"><i class="fas fa-times-circle"></i> Reject TR</button>
                </div>
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
                <button type="button" class="btn btn-primary-custom btn-sm" onclick="showAddUnitForm(event); return false;">
                    <i class="fas fa-edit"></i> <?= count($detailUnits) > 0 ? 'Edit Unit' : 'Tambah Unit' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="addUnitForm" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
                    <form method="POST" id="unitForm">
                        <input type="hidden" name="action" value="save_unit">
                        <input type="hidden" name="unit_id_hidden" id="unit_id_hidden" value="0">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Unit *</label>
                                <div class="unit-select-wrap">
                                    <div class="unit-combobox" id="unitCombobox">
                                        <div class="unit-combo-control" id="unitComboControl">
                                            <i class="fas fa-search"></i>
                                            <input type="text" id="unitSearch" class="unit-search-input" placeholder="Cari / pilih unit..." autocomplete="off">
                                            <i class="fas fa-chevron-down unit-combo-chevron"></i>
                                        </div>
                                        <div class="unit-combo-dropdown" id="unitComboDropdown">
                                            <div class="unit-combo-empty" id="unitComboEmpty">Ketik untuk mencari unit</div>
                                            <div class="unit-combo-options" id="unitComboOptions">
                                                <?php foreach ($produkList as $produk): ?>
                                                    <button type="button" class="unit-combo-option" data-value="<?= $produk['id'] ?>" data-label="<?= htmlspecialchars($produk['nama_produk'], ENT_QUOTES) ?>">
                                                        <span><?= htmlspecialchars($produk['nama_produk']) ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <select name="unit_id" id="unit_id" class="form-select unit-native-select" required aria-hidden="true" tabindex="-1">
                                        <option value="">-- Pilih Unit --</option>
                                        <?php foreach ($produkList as $produk): ?>
                                            <option value="<?= $produk['id'] ?>">
                                                <?= htmlspecialchars($produk['nama_produk']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
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
                <button type="button" class="btn btn-primary-custom btn-sm" onclick="showTOPSection(event); return false;">
                    <i class="fas fa-edit"></i> Edit TOP
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="topForm" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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
                <button type="button" class="btn btn-primary-custom btn-sm" onclick="toggleMediatorForm(event); return false;">
                    <i class="fas fa-edit"></i> <?= count($mediators) > 0 ? 'Edit Mediator' : 'Tambah Mediator' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="mediatorFormContainer" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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
                <div id="costFormContainer" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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
                            <div class="result-item-card cost-result-card">
                                <div class="result-item-header">
                                    <strong>
                                        <i class="fas fa-coins"></i>
                                        <?= htmlspecialchars($item['item_name']) ?>
                                    </strong>
                                </div>
                                <div class="result-item-body">
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
                <div id="editSupport" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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
                            <div class="result-item-card support-result-card">
                                <div class="result-item-header">
                                    <strong>
                                        <i class="fas fa-headset"></i>
                                        Support <?= $index + 1 ?>
                                    </strong>
                                </div>
                                <div class="result-item-body">
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
                <div id="editCostCalc" style="display: none; margin-bottom: 20px; padding: 20px; border-radius: 12px;">
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

    </main>
    <!-- END MAIN CONTENT -->

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // FUNGSI UNTUK SUMMARY
        // ============================================
        function showEditSummary(event) {
            if (event) event.preventDefault();
            const editForm = document.getElementById('editSummaryForm');
            const viewSummary = document.getElementById('viewSummary');
            if (!editForm) {
                console.error('Element #editSummaryForm tidak ditemukan.');
                return false;
            }
            editForm.style.display = 'block';
            if (viewSummary) viewSummary.style.display = 'none';
            return false;
        }
        
        function hideEditSummary() {
            document.getElementById('editSummaryForm').style.display = 'none';
            document.getElementById('viewSummary').style.display = 'block';
        }
        
        function submitApproval(action) {
            if (action === 'reject') {
                openRejectModal();
                return;
            }
            if (action === 'approve') {
                if (!confirm('Yakin ingin meng-approve TR ini?')) {
                    return;
                }
            }
            document.getElementById('approvalComment').value = '';
            document.getElementById('approvalAction').value = action;
            document.getElementById('approvalForm').submit();
        }

        function openRejectModal() {
            const modal = document.getElementById('rejectModal');
            const reason = document.getElementById('rejectReason');
            if (!modal || !reason) return;
            modal.classList.add('show');
            modal.setAttribute('aria-hidden', 'false');
            reason.value = '';
            document.body.style.overflow = 'hidden';
            setTimeout(() => reason.focus(), 50);
        }

        function closeRejectModal() {
            const modal = document.getElementById('rejectModal');
            if (!modal) return;
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        function confirmReject() {
            const reason = document.getElementById('rejectReason');
            const comment = reason ? reason.value.trim() : '';
            if (!comment) {
                if (reason) reason.focus();
                alert('Komentar / alasan reject wajib diisi.');
                return;
            }
            if (!confirm('Yakin ingin me-reject TR ini dengan alasan tersebut?')) {
                return;
            }
            document.getElementById('approvalComment').value = comment;
            document.getElementById('approvalAction').value = 'reject';
            closeRejectModal();
            document.getElementById('approvalForm').submit();
        }

        function confirmResubmit() {
            return confirm('Ajukan TR ini kembali? Status akan menjadi Pending dan approval dimulai lagi dari Sales Manager.');
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') closeRejectModal();
        });

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
        // SEARCHABLE UNIT COMBOBOX
        // ============================================
        function filterUnitOptions() {
            const search = document.getElementById('unitSearch');
            const select = document.getElementById('unit_id');
            const optionsWrap = document.getElementById('unitComboOptions');
            const empty = document.getElementById('unitComboEmpty');
            if (!search || !select || !optionsWrap) return;

            const keyword = search.value.trim().toLowerCase();
            let visible = 0;
            optionsWrap.querySelectorAll('.unit-combo-option').forEach(option => {
                const label = (option.dataset.label || option.textContent || '').toLowerCase();
                const show = keyword === '' || label.includes(keyword);
                option.style.display = show ? 'flex' : 'none';
                if (show) visible++;
            });

            if (empty) {
                empty.textContent = visible ? 'Pilih unit dari daftar' : 'Unit tidak ditemukan';
                empty.style.display = visible ? 'none' : 'block';
            }
        }

        function openUnitDropdown() {
            const combo = document.getElementById('unitCombobox');
            if (!combo) return;
            combo.classList.add('is-open');
            filterUnitOptions();
        }

        function closeUnitDropdown() {
            const combo = document.getElementById('unitCombobox');
            if (combo) combo.classList.remove('is-open');
        }

        function selectUnitOption(value, label) {
            const select = document.getElementById('unit_id');
            const search = document.getElementById('unitSearch');
            if (!select || !search) return;
            select.value = String(value);
            search.value = label || '';
            filterUnitOptions();
            closeUnitDropdown();
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function resetUnitSearch() {
            const search = document.getElementById('unitSearch');
            const select = document.getElementById('unit_id');
            if (search) search.value = '';
            if (select) select.value = '';
            filterUnitOptions();
            closeUnitDropdown();
        }

        function syncUnitSearchFromSelect() {
            const search = document.getElementById('unitSearch');
            const select = document.getElementById('unit_id');
            if (!search || !select) return;
            const selected = select.options[select.selectedIndex];
            search.value = selected && selected.value ? selected.text.trim() : '';
        }

        // ============================================
        // FUNGSI UNTUK DETAIL UNIT
        // ============================================
        function showAddUnitForm(event) {
            if (event) event.preventDefault();
            const formContainer = document.getElementById('addUnitForm');
            if (!formContainer) {
                console.error('Element #addUnitForm tidak ditemukan.');
                return false;
            }
            formContainer.style.display = 'block';
            const unitForm = document.getElementById('unitForm');
            if (unitForm) unitForm.reset();
            resetUnitSearch();
            document.getElementById('unit_id_hidden').value = '0';
            document.getElementById('deleteUnitBtn').style.display = 'none';
            
            <?php if (count($detailUnits) > 0): ?>
                <?php $firstUnit = $detailUnits[0]; ?>
                document.getElementById('unit_id_hidden').value = <?= json_encode((string)($firstUnit['id'] ?? '')) ?>;
                document.getElementById('unit_id').value = <?= json_encode((string)($firstUnit['unit_id'] ?? '')) ?>;
                syncUnitSearchFromSelect();
                document.getElementById('qty').value = <?= json_encode((string)($firstUnit['qty'] ?? '')) ?>;
                document.getElementById('price').value = <?= json_encode((string)($firstUnit['price'] ?? '')) ?>;
                
                const specInput = document.querySelector('input[name="specification"]');
                const attachmentInput = document.querySelector('input[name="additional_attachment"]');
                const warantyInput = document.querySelector('input[name="waranty"]');
                const freePartServiceInput = document.querySelector('input[name="free_part_service"]');
                const locationInput = document.querySelector('input[name="machine_location"]');
                const deliveryTermsInput = document.querySelector('input[name="delivery_terms"]');
                const deliveryScheduleInput = document.querySelector('input[name="delivery_schedule"]');
                const transTypeInput = document.querySelector('select[name="transaction_type"]');
                
                specInput.value = <?= json_encode((string)($firstUnit['specification'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                attachmentInput.value = <?= json_encode((string)($firstUnit['additional_attachment'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                warantyInput.value = <?= json_encode((string)($firstUnit['waranty'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                freePartServiceInput.value = <?= json_encode((string)($firstUnit['free_part_service'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                locationInput.value = <?= json_encode((string)($firstUnit['machine_location'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                deliveryTermsInput.value = <?= json_encode((string)($firstUnit['delivery_terms'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                deliveryScheduleInput.value = <?= json_encode((string)($firstUnit['delivery_schedule'] ?? '')) ?>;
                transTypeInput.value = <?= json_encode((string)($firstUnit['transaction_type'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
                
                calculateTotal();
                toggleOtherTransaction();
                document.getElementById('deleteUnitBtn').style.display = 'inline-block';
            <?php else: ?>
                calculateTotal();
                toggleOtherTransaction();
            <?php endif; ?>
        }
        
        function showNewUnitForm() {
            if (!addUnitForm) return;
            addUnitForm.style.display = 'block';
            document.getElementById('unitForm').reset();
            resetUnitSearch();
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
        function showTOPSection(event) {
            if (event) event.preventDefault();
            const topForm = document.getElementById('topForm');
            if (!topForm) {
                console.error('Element #topForm tidak ditemukan.');
                return false;
            }
            topForm.style.display = 'block';
            return false;
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
                        <input type="text" name="item_name[]" class="form-control" placeholder="Contoh: Insurance, Delivery Cost, dll" required>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Nominal (Rp) *</label>
                        <input type="number" name="item_amount[]" class="form-control" min="0" step="0.01" placeholder="0" value="0" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="item_keterangan[]" class="form-control" placeholder="Keterangan (opsional)">
                    </div>
                </div>
            `;
            
            container.appendChild(rowDiv);

            if (data) {
                const nameInput = rowDiv.querySelector('input[name="item_name[]"]');
                const amountInput = rowDiv.querySelector('input[name="item_amount[]"]');
                const keteranganInput = rowDiv.querySelector('input[name="item_keterangan[]"]');
                if (nameInput) nameInput.value = data.item_name ?? '';
                if (amountInput) amountInput.value = data.amount ?? 0;
                if (keteranganInput) keteranganInput.value = data.keterangan ?? '';
            }
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
                        item_name: <?= json_encode((string)($item['item_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        amount: <?= json_encode((string)($item['amount'] ?? 0), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        keterangan: <?= json_encode((string)($item['keterangan'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
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
        
        function toggleMediatorForm(event) {
            if (event) event.preventDefault();
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
                        name: <?= json_encode((string)($med['name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        id_card_no: <?= json_encode((string)($med['id_card_no'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        npwp_no: <?= json_encode((string)($med['npwp_no'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        bank_name: <?= json_encode((string)($med['bank_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        bank_account: <?= json_encode((string)($med['bank_account'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        amount: <?= json_encode((string)($med['amount'] ?? '0')) ?>
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
                        <input type="text" name="support_name[]" class="form-control" placeholder="Contoh: Free Filter Engine, Jarak Service, dll" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="support_keterangan[]" class="form-control" placeholder="Keterangan (opsional)">
                    </div>
                </div>
            `;
            
            container.appendChild(rowDiv);

            if (data) {
                const nameInput = rowDiv.querySelector('input[name="support_name[]"]');
                const keteranganInput = rowDiv.querySelector('input[name="support_keterangan[]"]');
                if (nameInput) nameInput.value = data.support_name ?? '';
                if (keteranganInput) keteranganInput.value = data.keterangan ?? '';
            }
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
                        support_name: <?= json_encode((string)($support['support_name'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
                        keterangan: <?= json_encode((string)($support['keterangan'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addSupportRow();
            <?php endif; ?>
        }
        // ============================================
        // CUSTOMER DEAL
        // ============================================
        function toggleCustomerDealNo() {
            const form = document.getElementById('customerDealNoForm');
            const reason = document.getElementById('customerDealKeterangan');
            if (!form) return;
            const isHidden = form.style.display === 'none' || form.style.display === '';
            form.style.display = isHidden ? 'block' : 'none';
            if (isHidden && reason) setTimeout(() => reason.focus(), 50);
        }

        function validateCustomerDealNo() {
            const reason = document.getElementById('customerDealKeterangan');
            const value = reason ? reason.value.trim() : '';
            if (!value) {
                alert('Keterangan wajib diisi jika Customer Deal = No.');
                if (reason) reason.focus();
                return false;
            }
            return confirm('Simpan Customer Deal = No dengan keterangan tersebut?');
        }

        function confirmCustomerDeal(choice) {
            if (choice === 'yes') {
                return confirm('Yakin customer dinyatakan DEAL (Yes)?');
            }
            return true;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const unitSearch = document.getElementById('unitSearch');
            const unitControl = document.getElementById('unitComboControl');
            const unitSelect = document.getElementById('unit_id');
            if (unitSearch) {
                unitSearch.addEventListener('focus', openUnitDropdown);
                unitSearch.addEventListener('input', function(){ openUnitDropdown(); filterUnitOptions(); });
                unitSearch.addEventListener('keydown', function(event){
                    if (event.key === 'Escape') closeUnitDropdown();
                });
            }
            if (unitControl) unitControl.addEventListener('click', function(){
                openUnitDropdown();
                if (unitSearch) unitSearch.focus();
            });
            document.querySelectorAll('.unit-combo-option').forEach(function(option){
                option.addEventListener('click', function(){
                    selectUnitOption(this.dataset.value, this.dataset.label);
                });
            });
            if (unitSelect) unitSelect.addEventListener('change', syncUnitSearchFromSelect);
            document.addEventListener('click', function(event){
                const combo = document.getElementById('unitCombobox');
                if (combo && !combo.contains(event.target)) closeUnitDropdown();
            });
        });
    </script>
</body>
</html>