<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
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
requirePermission('delivery_order', 'view');

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
        'sales' => 'Sales',
        'service_support' => 'Service Support',
        'part_support' => 'Part Support'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// FUNGSI UNTUK RESET APPROVAL HISTORY
// ============================================
function resetDIApprovalHistory($db, $di_number) {
    try {
        $deleteApproval = $db->prepare("DELETE FROM di_approval_history WHERE di_number = ?");
        $deleteApproval->execute([$di_number]);
        
        $updateDetail = $db->prepare("UPDATE detail_delivery_instructions SET status = 'pending', current_approval_order = 1, updated_at = NOW() WHERE di_number = ?");
        $updateDetail->execute([$di_number]);
        
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
// AMBIL DI NUMBER DARI URL
// ============================================
$di_number = isset($_GET['di_number']) ? bersihkan($_GET['di_number']) : '';
$activeTab = isset($_GET['tab']) ? bersihkan($_GET['tab']) : 'data_penjualan';

// Validasi tab
$validTabs = ['data_penjualan', 'data_customer', 'data_unit', 'aksesoris', 'logistik', 'detail_part', 'komparasi_logistik', 'product_support'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'data_penjualan';
}

if (empty($di_number)) {
    setFlash('DI Number tidak ditemukan!', 'danger');
    redirect('deliveryinstruction.php');
}

// ============================================
// AUTO CREATE DETAIL DI JIKA BELUM ADA
// ============================================
try {
    $checkExisting = $db->prepare("SELECT id FROM detail_delivery_instructions WHERE di_number = ?");
    $checkExisting->execute([$di_number]);
    $existingRecord = $checkExisting->fetch();
    
    if (!$existingRecord) {
        $getActivityData = $db->prepare("SELECT id, sales_activity_id FROM activity_details WHERE di_number = ? ORDER BY id DESC LIMIT 1");
        $getActivityData->execute([$di_number]);
        $activityData = $getActivityData->fetch();
        
        if ($activityData) {
            $insertDI = $db->prepare("INSERT INTO detail_delivery_instructions (di_number, sales_activity_id, activity_detail_id, no_so, status, current_approval_order, created_at, updated_at) VALUES (?, ?, ?, NULL, 'pending', 1, NOW(), NOW())");
            $insertDI->execute([$di_number, $activityData['sales_activity_id'], $activityData['id']]);
        }
    }
} catch (Exception $e) {
    // Jika tabel belum ada, abaikan
}

// ============================================
// AMBIL DATA DELIVERY INSTRUCTION
// ============================================
$sql = "SELECT ad.di_number,
               ad.tr_number,
               sa.leads_number AS activity_number,
               ad.due_date,
               ad.created_at as request_date,
               ad.id as activity_detail_id,
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
        WHERE ad.di_number = ?
        ORDER BY ad.id DESC
        LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute([$di_number]);
$request = $stmt->fetch();

if (!$request) {
    setFlash('Data delivery instruction tidak ditemukan!', 'danger');
    redirect('deliveryinstruction.php');
}

// ============================================
// AMBIL DATA DETAIL DI
// ============================================
$detailDI = null;
try {
    $sqlDetail = "SELECT * FROM detail_delivery_instructions WHERE di_number = ? ORDER BY id DESC LIMIT 1";
    $stmtDetail = $db->prepare($sqlDetail);
    $stmtDetail->execute([$di_number]);
    $detailDI = $stmtDetail->fetch();
} catch (Exception $e) {
    $detailDI = null;
}

$statusDI = $detailDI['status'] ?? 'pending';
$request['status'] = $statusDI;
$request['no_so'] = $detailDI['no_so'] ?? '';

// ============================================
// CEK HAK EDIT - HANYA ADMIN YANG BISA EDIT
// ============================================
$canEdit = false;
if ($userRole === 'admin') {
    $canEdit = true;
}

// ============================================
// CEK APAKAH DI SUDAH PERNAH DI-APPROVE
// ============================================
$hasBeenApproved = false;
try {
    $checkApproved = $db->prepare("SELECT COUNT(*) as total FROM di_approval_history WHERE di_number = ? AND status = 'approved'");
    $checkApproved->execute([$di_number]);
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
// AMBIL DATA APPROVAL HISTORY
// ============================================
$approvalHistory = [];
try {
    $sqlApproval = "SELECT * FROM di_approval_history WHERE di_number = ? ORDER BY approval_order ASC";
    $stmtApproval = $db->prepare($sqlApproval);
    $stmtApproval->execute([$di_number]);
    $approvalHistory = $stmtApproval->fetchAll();
} catch (Exception $e) {
    $approvalHistory = [];
}

// ============================================
// DAFTAR APPROVAL LEVELS
// ============================================
$approvalLevels = [
    1 => ['role' => 'business', 'label' => 'Business'],
    2 => ['role' => 'part_support', 'label' => 'Part Support'],
    3 => ['role' => 'service_support', 'label' => 'Service Support'],
    4 => ['role' => 'finance', 'label' => 'Finance'],
    5 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    6 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    7 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    8 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// TENTUKAN CURRENT APPROVER DAN NEXT APPROVER
// Approval baru aktif setelah minimal 1 menu DI diisi Admin.
// ============================================
$currentApprovalOrder = 0;
$currentApproverLabel = 'Belum ada data untuk approval';
$nextApproverLabel = '-';

// Nilai ini akan dihitung ulang setelah seluruh data menu DI diambil.
$hasInputData = false;

// ============================================
// AMBIL DATA UNITS
// ============================================
$diUnits = [];
try {
    $sqlUnit = "SELECT * FROM di_units WHERE di_number = ? ORDER BY id ASC";
    $stmtUnit = $db->prepare($sqlUnit);
    $stmtUnit->execute([$di_number]);
    $diUnits = $stmtUnit->fetchAll();
} catch (Exception $e) {
    $diUnits = [];
}

// ============================================
// AMBIL DATA ACCESSORIES
// ============================================
$diAccessories = [];
try {
    $sqlAcc = "SELECT * FROM di_accessories WHERE di_number = ? ORDER BY id ASC";
    $stmtAcc = $db->prepare($sqlAcc);
    $stmtAcc->execute([$di_number]);
    $diAccessories = $stmtAcc->fetchAll();
} catch (Exception $e) {
    $diAccessories = [];
}

// ============================================
// AMBIL DATA LOGISTICS
// ============================================
$diLogistics = null;
try {
    $sqlLog = "SELECT * FROM di_logistics WHERE di_number = ? ORDER BY id DESC LIMIT 1";
    $stmtLog = $db->prepare($sqlLog);
    $stmtLog->execute([$di_number]);
    $diLogistics = $stmtLog->fetch();
} catch (Exception $e) {
    $diLogistics = null;
}

// ============================================
// AMBIL DATA PRODUCT SUPPORTS
// ============================================
$diSupports = [];
try {
    $sqlSup = "SELECT * FROM di_product_supports WHERE di_number = ? ORDER BY id ASC";
    $stmtSup = $db->prepare($sqlSup);
    $stmtSup->execute([$di_number]);
    $diSupports = $stmtSup->fetchAll();
} catch (Exception $e) {
    $diSupports = [];
}

// ============================================
// AMBIL DATA DETAIL PART
// ============================================
$diParts = [];
try {
    $sqlPart = "SELECT * FROM di_parts WHERE di_number = ? ORDER BY id ASC";
    $stmtPart = $db->prepare($sqlPart);
    $stmtPart->execute([$di_number]);
    $diParts = $stmtPart->fetchAll();
} catch (Exception $e) {
    $diParts = [];
}

// ============================================
// AMBIL DATA KOMPARASI LOGISTIK
// ============================================
$diLogisticsComparisons = [];
try {
    $sqlLogComp = "SELECT * FROM di_logistics_comparisons WHERE di_number = ? ORDER BY id ASC";
    $stmtLogComp = $db->prepare($sqlLogComp);
    $stmtLogComp->execute([$di_number]);
    $diLogisticsComparisons = $stmtLogComp->fetchAll();
} catch (Exception $e) {
    $diLogisticsComparisons = [];
}

$selectedLogisticsVendor = null;
foreach ($diLogisticsComparisons as $comparison) {
    if ((int)($comparison['is_selected'] ?? 0) === 1) {
        $selectedLogisticsVendor = $comparison;
        break;
    }
}

$jumlahUnitDI = count($diUnits);

// ============================================
// CEK APAKAH MINIMAL 1 MENU SUDAH DIINPUT ADMIN
// detail_delivery_instructions yang dibuat otomatis tidak dihitung sebagai input.
// ============================================
$hasInputData = (
    !empty(trim((string)($request['no_so'] ?? ''))) ||
    count($diUnits) > 0 ||
    count($diAccessories) > 0 ||
    $diLogistics !== null ||
    count($diSupports) > 0 ||
    count($diParts) > 0 ||
    count($diLogisticsComparisons) > 0
);

// ============================================
// TENTUKAN CURRENT APPROVER DAN NEXT APPROVER
// Urutan: Business -> Part Support -> Service Support -> Finance
// -> Sales Manager -> Direktur Sales -> Direktur Operasional -> Direktur Utama
// ============================================
$currentApprovalOrder = 0;
$currentApproverLabel = $hasInputData ? 'Business' : 'Belum ada data untuk approval';
$nextApproverLabel = $hasInputData ? 'Part Support' : '-';

if ($hasInputData && $detailDI) {
    $lastApprovedOrder = 0;
    $isRejected = false;

    foreach ($approvalHistory as $approval) {
        if ($approval['status'] === 'approved') {
            $lastApprovedOrder = max($lastApprovedOrder, (int)$approval['approval_order']);
        }
        if ($approval['status'] === 'rejected') {
            $isRejected = true;
        }
    }

    if ($isRejected || $detailDI['status'] === 'rejected') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'Rejected - Menunggu perbaikan Admin';
        $nextApproverLabel = '-';
    } elseif ($detailDI['status'] === 'approved') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'Selesai';
        $nextApproverLabel = '-';
    } else {
        $currentApprovalOrder = $lastApprovedOrder + 1;
        if ($currentApprovalOrder >= 1 && $currentApprovalOrder <= 8) {
            $currentApproverLabel = $approvalLevels[$currentApprovalOrder]['label'];
            $nextOrder = $currentApprovalOrder + 1;
            $nextApproverLabel = $nextOrder <= 7 ? $approvalLevels[$nextOrder]['label'] : '-';
        } else {
            $currentApprovalOrder = 0;
            $currentApproverLabel = 'Selesai';
            $nextApproverLabel = '-';
        }
    }
}

// Group supports by type
$supportsGrouped = [
    'free_filter_engine' => [],
    'jarak_service' => [],
    'catatan' => [],
    'free_service' => [],
    'warranty' => []
];
foreach ($diSupports as $support) {
    if (isset($supportsGrouped[$support['support_type']])) {
        $supportsGrouped[$support['support_type']][] = $support;
    }
}

// ============================================
// HANDLE FORM SUBMISSION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    $editActions = ['save_data_penjualan', 'save_units', 'save_accessories', 'save_logistics', 'save_product_support', 'save_parts', 'save_logistics_comparison', 'select_logistics_vendor'];
    if (in_array($action, $editActions) && !$canEdit) {
        if ($hasBeenApproved) {
            setFlash('DI ini sudah di-approve, data tidak bisa diedit lagi!', 'danger');
        } else {
            setFlash('Anda tidak memiliki hak untuk mengedit data ini! Hanya Admin yang bisa.', 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // ============================================
    // SAVE DATA PENJUALAN
    // ============================================
    if ($action === 'save_data_penjualan') {
        try {
            $db->beginTransaction();
            $no_so = bersihkan($_POST['no_so'] ?? '');
            
            if ($detailDI) {
                $updateSql = "UPDATE detail_delivery_instructions SET no_so = ?, updated_at = NOW() WHERE id = ?";
                $updateStmt = $db->prepare($updateSql);
                $updateStmt->execute([$no_so, $detailDI['id']]);
            } else {
                $insertSql = "INSERT INTO detail_delivery_instructions (di_number, sales_activity_id, activity_detail_id, no_so, status, current_approval_order, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', 1, NOW(), NOW())";
                $insertStmt = $db->prepare($insertSql);
                $insertStmt->execute([$di_number, $request['sales_activity_id'], $request['activity_detail_id'], $no_so]);
            }
            
            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Penjualan berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=data_penjualan");
    }
    
    // ============================================
    // APPROVE / REJECT
    // Hanya role pada level yang sedang aktif yang boleh melakukan aksi.
    // ============================================
    if ($action === 'approve' || $action === 'reject') {
        try {
            $db->beginTransaction();
            $approvalStatus = $action === 'approve' ? 'approved' : 'rejected';
            $currentOrder = (int)($_POST['approval_order'] ?? 0);

            // Pastikan minimal ada satu menu yang benar-benar sudah diinput.
            $inputChecks = [
                "SELECT COUNT(*) FROM di_units WHERE di_number = ?",
                "SELECT COUNT(*) FROM di_accessories WHERE di_number = ?",
                "SELECT COUNT(*) FROM di_product_supports WHERE di_number = ?",
                "SELECT COUNT(*) FROM di_parts WHERE di_number = ?",
                "SELECT COUNT(*) FROM di_logistics_comparisons WHERE di_number = ?",
                "SELECT COUNT(*) FROM di_logistics WHERE di_number = ?"
            ];
            $hasApprovalInput = !empty(trim((string)($request['no_so'] ?? '')));
            foreach ($inputChecks as $checkSql) {
                $checkStmt = $db->prepare($checkSql);
                $checkStmt->execute([$di_number]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    $hasApprovalInput = true;
                    break;
                }
            }

            if (!$hasApprovalInput) {
                throw new Exception('Belum ada data DI yang diinput. Minimal isi 1 menu terlebih dahulu.');
            }

            // Ambil status/order aktual dari database agar approval tidak bisa
            // dipalsukan hanya dengan mengubah hidden input.
            $detailCheck = $db->prepare("SELECT status, current_approval_order FROM detail_delivery_instructions WHERE di_number = ? ORDER BY id DESC LIMIT 1");
            $detailCheck->execute([$di_number]);
            $currentDetail = $detailCheck->fetch();

            if (!$currentDetail || $currentDetail['status'] !== 'pending') {
                throw new Exception('DI belum berada pada status pending untuk approval.');
            }

            $dbCurrentOrder = (int)($currentDetail['current_approval_order'] ?? 0);
            if ($currentOrder < 1 || $currentOrder > 8 || $currentOrder !== $dbCurrentOrder) {
                throw new Exception('Urutan approval tidak valid atau approval ini sudah diproses.');
            }

            $requiredRole = $approvalLevels[$currentOrder]['role'];
            if ($userRole !== $requiredRole) {
                throw new Exception('Anda tidak memiliki hak untuk melakukan approval pada level ini.');
            }

            $checkApproval = $db->prepare("SELECT id FROM di_approval_history WHERE di_number = ? AND approval_order = ? LIMIT 1");
            $checkApproval->execute([$di_number, $currentOrder]);
            $existingApproval = $checkApproval->fetch();

            if ($existingApproval) {
                $updateApproval = $db->prepare("UPDATE di_approval_history SET status = ?, catatan = '', approved_by = ?, approved_at = NOW() WHERE id = ?");
                $updateApproval->execute([$approvalStatus, $userId, $existingApproval['id']]);
            } else {
                $insertApproval = $db->prepare("INSERT INTO di_approval_history (di_number, approval_order, approval_role, approval_label, status, catatan, approved_by, approved_at, created_at) VALUES (?, ?, ?, ?, ?, '', ?, NOW(), NOW())");
                $insertApproval->execute([
                    $di_number,
                    $currentOrder,
                    $approvalLevels[$currentOrder]['role'],
                    $approvalLevels[$currentOrder]['label'],
                    $approvalStatus,
                    $userId
                ]);
            }

            if ($approvalStatus === 'rejected') {
                $newStatus = 'rejected';
                $newOrder = $currentOrder;
            } elseif ($currentOrder === 8) {
                $newStatus = 'approved';
                $newOrder = 8;
            } else {
                $newStatus = 'pending';
                $newOrder = $currentOrder + 1;
            }

            $updateDetail = $db->prepare("UPDATE detail_delivery_instructions SET status = ?, current_approval_order = ?, updated_at = NOW() WHERE di_number = ?");
            $updateDetail->execute([$newStatus, $newOrder, $di_number]);

            $db->commit();
            setFlash($approvalStatus === 'approved' ? 'DI berhasil di-approve!' : 'DI berhasil di-reject!', $approvalStatus === 'approved' ? 'success' : 'warning');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('Gagal melakukan approval: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=data_penjualan");
    }
    
    // ============================================
    // SAVE UNITS
    // ============================================
    if ($action === 'save_units') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM di_units WHERE di_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$di_number]);
            
            $lokasi_units = $_POST['lokasi_unit'] ?? [];
            $cabangs = $_POST['cabang'] ?? [];
            $kode_units = $_POST['kode_unit'] ?? [];
            $brands = $_POST['brand'] ?? [];
            $tipes = $_POST['tipe'] ?? [];
            $serial_numbers = $_POST['serial_number'] ?? [];
            $engine_numbers = $_POST['engine_number'] ?? [];
            $keterangans = $_POST['keterangan'] ?? [];
            
            foreach ($lokasi_units as $index => $lokasi) {
                if (!empty($lokasi) || !empty($kode_units[$index])) {
                    $insertSql = "INSERT INTO di_units (di_number, lokasi_unit, cabang, kode_unit, brand, tipe, serial_number, engine_number, keterangan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([
                        $di_number,
                        $lokasi,
                        $cabangs[$index] ?? '',
                        $kode_units[$index] ?? '',
                        $brands[$index] ?? '',
                        $tipes[$index] ?? '',
                        $serial_numbers[$index] ?? '',
                        $engine_numbers[$index] ?? '',
                        $keterangans[$index] ?? ''
                    ]);
                }
            }
            
            if (!$detailDI) {
                $insertDetail = $db->prepare("INSERT INTO detail_delivery_instructions (di_number, sales_activity_id, activity_detail_id, status, current_approval_order, created_at, updated_at) VALUES (?, ?, ?, 'pending', 1, NOW(), NOW())");
                $insertDetail->execute([$di_number, $request['sales_activity_id'], $request['activity_detail_id']]);
            } else {
                $updateDetail = $db->prepare("UPDATE detail_delivery_instructions SET status = 'pending', current_approval_order = 1, updated_at = NOW() WHERE di_number = ?");
                $updateDetail->execute([$di_number]);
            }
            
            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Unit berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data unit: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=data_unit");
    }
    
    // ============================================
    // SAVE ACCESSORIES
    // ============================================
    if ($action === 'save_accessories') {
        try {
            $db->beginTransaction();

            $ids = $_POST['id'] ?? [];
            $nos = $_POST['no'] ?? [];
            $uraians = $_POST['uraian'] ?? [];
            $satuans = $_POST['satuan'] ?? [];
            $jumlahs = $_POST['jumlah'] ?? [];
            $keterangans = $_POST['keterangan'] ?? [];
            $submittedIds = [];

            $updateStmt = $db->prepare("UPDATE di_accessories
                SET no = ?, uraian = ?, satuan = ?, jumlah = ?, keterangan = ?, updated_at = NOW()
                WHERE id = ? AND di_number = ?");
            $insertStmt = $db->prepare("INSERT INTO di_accessories
                (di_number, no, uraian, satuan, jumlah, keterangan, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");

            foreach ($nos as $index => $no) {
                $id = (int)($ids[$index] ?? 0);
                $uraian = trim($uraians[$index] ?? '');
                $satuan = trim($satuans[$index] ?? '');
                $jumlah = max(0, (int)($jumlahs[$index] ?? 0));
                $keterangan = trim($keterangans[$index] ?? '');

                if ($no !== '' || $uraian !== '') {
                    if ($id > 0) {
                        $updateStmt->execute([$no, $uraian, $satuan, $jumlah, $keterangan, $id, $di_number]);
                        $submittedIds[] = $id;
                    } else {
                        $insertStmt->execute([$di_number, $no, $uraian, $satuan, $jumlah, $keterangan]);
                        $submittedIds[] = (int)$db->lastInsertId();
                    }
                }
            }

            if (count($submittedIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
                $params = array_merge([$di_number], $submittedIds);
                $deleteStmt = $db->prepare("DELETE FROM di_accessories WHERE di_number = ? AND id NOT IN ($placeholders)");
                $deleteStmt->execute($params);
            } else {
                $deleteStmt = $db->prepare("DELETE FROM di_accessories WHERE di_number = ?");
                $deleteStmt->execute([$di_number]);
            }

            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Aksesoris berhasil disimpan!', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('Gagal menyimpan data aksesoris: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=aksesoris");
    }


    // ============================================
    // SAVE LOGISTICS
    // ============================================
    if ($action === 'save_logistics') {
        try {
            $db->beginTransaction();
            
            $lokasi_pengambilan = bersihkan($_POST['lokasi_pengambilan'] ?? '');
            $lokasi_pengiriman = bersihkan($_POST['lokasi_pengiriman'] ?? '');
            $transportir = bersihkan($_POST['transportir'] ?? '');
            $waktu_pengiriman = !empty($_POST['waktu_pengiriman']) ? $_POST['waktu_pengiriman'] : null;
            $eta = !empty($_POST['eta']) ? $_POST['eta'] : null;
            
            $deleteSql = "DELETE FROM di_logistics WHERE di_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$di_number]);
            
            $insertSql = "INSERT INTO di_logistics (di_number, lokasi_pengambilan, lokasi_pengiriman, transportir, waktu_pengiriman, eta, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([$di_number, $lokasi_pengambilan, $lokasi_pengiriman, $transportir, $waktu_pengiriman, $eta]);
            
            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Logistik berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data logistik: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=logistik");
    }
    
    // ============================================
    // SAVE PRODUCT SUPPORT
    // ============================================
    if ($action === 'save_product_support') {
        try {
            $db->beginTransaction();
            
            $deleteSql = "DELETE FROM di_product_supports WHERE di_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$di_number]);
            
            $supportTypes = [
                'free_filter_engine' => $_POST['free_filter_engine'] ?? [],
                'jarak_service' => $_POST['jarak_service'] ?? [],
                'catatan' => $_POST['catatan'] ?? [],
                'free_service' => $_POST['free_service'] ?? [],
                'warranty' => $_POST['warranty'] ?? []
            ];
            
            foreach ($supportTypes as $type => $values) {
                foreach ($values as $value) {
                    if (!empty($value)) {
                        $insertSql = "INSERT INTO di_product_supports (di_number, support_type, value, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())";
                        $insertStmt = $db->prepare($insertSql);
                        $insertStmt->execute([$di_number, $type, $value]);
                    }
                }
            }
            
            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Product Support berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('Gagal menyimpan data product support: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=product_support");
    }

    // ============================================
    // SAVE DETAIL PART
    // ============================================
    if ($action === 'save_parts') {
        try {
            $db->beginTransaction();

            $ids = $_POST['id'] ?? [];
            $partNumbers = $_POST['part_number'] ?? [];
            $descriptions = $_POST['description'] ?? [];
            $prices = $_POST['price'] ?? [];
            $qtys = $_POST['qty'] ?? [];
            $submittedIds = [];

            // unit tetap disimpan kosong di database untuk kompatibilitas
            // dengan struktur tabel lama; field ini tidak lagi ditampilkan.
            $updateStmt = $db->prepare("UPDATE di_parts
                SET part_number = ?, description = ?, price = ?, qty = ?, total_amount = ?, updated_at = NOW()
                WHERE id = ? AND di_number = ?");
            $insertStmt = $db->prepare("INSERT INTO di_parts
                (di_number, part_number, description, price, qty, unit, total_amount, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, '', ?, NOW(), NOW())");

            foreach ($partNumbers as $index => $partNumber) {
                $id = (int)($ids[$index] ?? 0);
                $partNumber = trim($partNumber);
                $description = trim($descriptions[$index] ?? '');
                $price = max(0, (float)($prices[$index] ?? 0));
                $qty = max(0, (float)($qtys[$index] ?? 0));
                $totalAmount = $price * $qty * $jumlahUnitDI;

                if ($partNumber !== '' || $description !== '') {
                    if ($id > 0) {
                        $updateStmt->execute([$partNumber, $description, $price, $qty, $totalAmount, $id, $di_number]);
                        $submittedIds[] = $id;
                    } else {
                        $insertStmt->execute([$di_number, $partNumber, $description, $price, $qty, $totalAmount]);
                        $submittedIds[] = (int)$db->lastInsertId();
                    }
                }
            }

            if (count($submittedIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
                $params = array_merge([$di_number], $submittedIds);
                $deleteStmt = $db->prepare("DELETE FROM di_parts WHERE di_number = ? AND id NOT IN ($placeholders)");
                $deleteStmt->execute($params);
            } else {
                $deleteStmt = $db->prepare("DELETE FROM di_parts WHERE di_number = ?");
                $deleteStmt->execute([$di_number]);
            }

            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Detail Part berhasil disimpan!', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('Gagal menyimpan detail part: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=detail_part");
    }


    // ============================================
    // SAVE KOMPARASI LOGISTIK
    // ============================================
    if ($action === 'save_logistics_comparison') {
        try {
            $db->beginTransaction();

            $ids = $_POST['id'] ?? [];
            $vendors = $_POST['vendor_name'] ?? [];
            $paymentMethods = $_POST['payment_method'] ?? [];
            $etas = $_POST['eta_kirim'] ?? [];
            $prices = $_POST['harga'] ?? [];
            $notes = $_POST['keterangan_komparasi'] ?? [];
            $submittedIds = [];

            $updateStmt = $db->prepare("UPDATE di_logistics_comparisons
                SET vendor_name = ?, payment_method = ?, eta_kirim = ?, harga = ?, keterangan = ?, updated_at = NOW()
                WHERE id = ? AND di_number = ?");
            $insertStmt = $db->prepare("INSERT INTO di_logistics_comparisons
                (di_number, vendor_name, payment_method, eta_kirim, harga, keterangan, is_selected, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), NOW())");

            foreach ($vendors as $index => $vendor) {
                $id = (int)($ids[$index] ?? 0);
                $vendor = trim($vendor);
                $paymentMethod = trim($paymentMethods[$index] ?? '');
                $eta = !empty($etas[$index]) ? $etas[$index] : null;
                $harga = max(0, (float)($prices[$index] ?? 0));
                $keterangan = trim($notes[$index] ?? '');

                if ($vendor !== '' || $paymentMethod !== '' || $eta !== null || $harga > 0 || $keterangan !== '') {
                    if ($id > 0) {
                        $updateStmt->execute([$vendor, $paymentMethod, $eta, $harga, $keterangan, $id, $di_number]);
                        $submittedIds[] = $id;
                    } else {
                        $insertStmt->execute([$di_number, $vendor, $paymentMethod, $eta, $harga, $keterangan]);
                        $submittedIds[] = (int)$db->lastInsertId();
                    }
                }
            }

            if (count($submittedIds) > 0) {
                $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
                $params = array_merge([$di_number], $submittedIds);
                $deleteStmt = $db->prepare("DELETE FROM di_logistics_comparisons WHERE di_number = ? AND id NOT IN ($placeholders) AND is_selected = 0");
                $deleteStmt->execute($params);
            } else {
                $deleteStmt = $db->prepare("DELETE FROM di_logistics_comparisons WHERE di_number = ?");
                $deleteStmt->execute([$di_number]);
            }

            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Komparasi Logistik berhasil disimpan!', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('Gagal menyimpan komparasi logistik: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=komparasi_logistik");
    }

    // ============================================
    // PILIH VENDOR - HANYA BOLEH SEKALI
    // Setelah vendor dipilih, pilihan dikunci dan tidak
    // dapat diganti lagi. Tidak ada tombol simpan terpisah.
    // ============================================
    if ($action === 'select_logistics_vendor') {
        try {
            $db->beginTransaction();

            // Cek apakah DI ini sudah mempunyai vendor terpilih.
            // Jika sudah, jangan izinkan perubahan pilihan.
            $checkSelected = $db->prepare("SELECT id FROM di_logistics_comparisons WHERE di_number = ? AND is_selected = 1 LIMIT 1");
            $checkSelected->execute([$di_number]);
            if ($checkSelected->fetch()) {
                throw new Exception('Vendor terpilih sudah dikunci dan tidak dapat diubah lagi.');
            }

            $selectedVendorId = (int)($_POST['selected_vendor_id'] ?? 0);
            if ($selectedVendorId <= 0) {
                throw new Exception('Silakan pilih vendor terlebih dahulu.');
            }

            $checkVendor = $db->prepare("SELECT id FROM di_logistics_comparisons WHERE id = ? AND di_number = ?");
            $checkVendor->execute([$selectedVendorId, $di_number]);
            if (!$checkVendor->fetch()) {
                throw new Exception('Vendor tidak ditemukan untuk DI ini.');
            }

            $setSelected = $db->prepare("UPDATE di_logistics_comparisons SET is_selected = 1, updated_at = NOW() WHERE id = ? AND di_number = ? AND is_selected = 0");
            $setSelected->execute([$selectedVendorId, $di_number]);

            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Vendor berhasil dipilih dan dikunci.', 'success');
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            setFlash('Gagal memilih vendor: ' . $e->getMessage(), 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number) . "&tab=komparasi_logistik");
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detail DI - <?= htmlspecialchars($di_number) ?> - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/detaildi.css">

    <style>
        .part-summary{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px}
        .part-summary>div{background:#f8f9fa;border:1px solid #e9ecef;border-radius:10px;padding:12px 16px;min-width:190px}
        .part-summary span{display:block;font-size:12px;color:#6c757d;margin-bottom:4px}
        .part-summary strong{font-size:14px;color:#212529}
        .detail-part-table th,.logistics-comparison-table th{white-space:nowrap;background:#f8f9fa}
        .table-total-row{background:#0a1427 !important;color:#e8eef8}
        .comparison-note{background:#eef6ff;border:1px solid #cfe2ff;color:#24558a;padding:10px 13px;border-radius:8px;margin-bottom:15px;font-size:13px}
        .vendor-data-row{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px;margin-bottom:12px}
        .vendor-data-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px}
        .vendor-label{font-weight:700;font-size:15px}
        .selected-vendor-row{background:#f0fdf4}
        .selected-check{display:inline-flex;width:28px;height:28px;border-radius:50%;align-items:center;justify-content:center;background:#198754;color:#fff}
        .not-selected{color:#adb5bd}
        .selected-vendor-box{margin-top:18px;padding:18px;border-radius:12px;background:#f8f9fa;border:1px solid #e9ecef}
        .selected-vendor-title{font-size:13px;font-weight:700;color:#198754;margin-bottom:8px}
        .selected-vendor-name{font-size:18px;font-weight:800;margin-bottom:8px}
        .selected-vendor-detail{font-size:13px;color:#495057}
        .selected-vendor-note{margin-top:10px;padding-top:10px;border-top:1px solid #dee2e6;font-size:13px;color:#495057}
        .part-total-display{font-weight:700;background:#f8f9fa}
        .vendor-check-wrap{display:flex;align-items:center;justify-content:center;height:100%}
        .vendor-check{width:20px;height:20px;cursor:pointer;accent-color:#c9a24d}
        .vendor-select-column{width:82px;text-align:center!important}
        .vendor-select-cell{text-align:center!important;vertical-align:middle!important;width:82px}
        .vendor-inline-select-form{margin:0;display:flex;align-items:center;justify-content:center}
        .vendor-checkbox-label{display:inline-flex;align-items:center;justify-content:center;cursor:pointer;margin:0}
        .vendor-checkbox-label input{position:absolute;opacity:0;pointer-events:none}
        .vendor-checkmark{
            width:20px;height:20px;border:1.5px solid #aab4c3;border-radius:6px;
            background:#fff;display:inline-flex;align-items:center;justify-content:center;
            transition:all .18s ease;box-shadow:0 1px 3px rgba(0,0,0,.08)
        }
        .vendor-checkbox-label:hover .vendor-checkmark{border-color:#c9a24d;box-shadow:0 0 0 3px rgba(201,162,77,.12)}
        .vendor-checkbox-label input:checked + .vendor-checkmark{
            background:#c9a24d;border-color:#c9a24d;box-shadow:0 3px 10px rgba(201,162,77,.22)
        }
        .vendor-checkbox-label input:checked + .vendor-checkmark::after{
            content:"\f00c";font-family:"Font Awesome 6 Free";font-weight:900;color:#fff;font-size:11px
        }
        .vendor-checkbox-label input:disabled + .vendor-checkmark{opacity:.9;cursor:not-allowed}
        .selected-vendor-row .vendor-checkmark{background:#c9a24d;border-color:#c9a24d}
        .approval-flow{border-top:1px solid #e9ecef;padding-top:18px}
        .approval-flow-text{display:flex;flex-direction:column;line-height:1.15;min-width:0}
        .approval-flow-text strong{font-size:11px;white-space:normal}
        .approval-flow-item.current{background:#fff8e1;border-color:#ffe08a}
        .approval-flow-item.current .approval-flow-icon{background:#f0ad00;color:#fff}
        .approval-flow-item.rejected .approval-flow-icon{background:#dc3545;color:#fff}
        @media(max-width:1100px){.approval-flow-list{grid-template-columns:repeat(4,minmax(120px,1fr))}}
        @media(max-width:700px){.approval-flow-list{grid-template-columns:repeat(2,minmax(130px,1fr))}}
        .detail-link {
            color: #2563eb;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            transition: color .2s ease, text-decoration .2s ease;
        }
        .detail-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }
        .detail-link-disabled {
            color: inherit;
        }
    </style>
</head>
<body class="page-detaildi">

    <?php require_once 'navigation.php'; ?>

    <!-- MAIN CONTENT -->
    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h4><span><i class="fas fa-tractor"></i></span> Detail DI - <?= htmlspecialchars($di_number) ?></h4>
            </div>
            <div>
                <a href="deliveryinstruction.php" class="btn btn-secondary-custom">
                    <i class="fas fa-arrow-left"></i> Kembali
                </a>
            </div>
        </div>

        <?= showFlash() ?>

        <!-- TAB NAVIGATION -->
        <div class="tab-nav">
            <ul class="nav nav-tabs" id="diTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'data_penjualan' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=data_penjualan">
                        <i class="fas fa-file-invoice"></i> Data Penjualan
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'data_customer' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=data_customer">
                        <i class="fas fa-building"></i> Data Customer
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'data_unit' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=data_unit">
                        <i class="fas fa-boxes"></i> Data Unit
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'aksesoris' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=aksesoris">
                        <i class="fas fa-tools"></i> Aksesoris
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'logistik' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=logistik">
                        <i class="fas fa-truck"></i> Logistik
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'detail_part' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=detail_part">
                        <i class="fas fa-cogs"></i> Detail Part
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'komparasi_logistik' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=komparasi_logistik">
                        <i class="fas fa-scale-balanced"></i> Komparasi Logistik
                    </a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $activeTab == 'product_support' ? 'active' : '' ?>" href="detaildi.php?di_number=<?= urlencode($di_number) ?>&tab=product_support">
                        <i class="fas fa-headset"></i> Product Support
                    </a>
                </li>
            </ul>
        </div>

        <!-- ============================================ -->
        <!-- TAB CONTENT: DATA PENJUALAN -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'data_penjualan'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-file-invoice"></i> Data Penjualan</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editDataPenjualan', 'viewDataPenjualan')">
                    <i class="fas fa-edit"></i> Edit
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editDataPenjualan" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_data_penjualan">
                        
                        <div class="row">
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Activity Number</label>
                                <?php if (!empty($request['activity_number']) && !empty($request['sales_activity_id'])): ?>
                                    <a href="detailaktivitas.php?leads_id=<?= urlencode($request['sales_activity_id']) ?>" class="detail-link" title="Buka Detail Activity">
                                        <?= htmlspecialchars($request['activity_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="detail-link-disabled">-</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">No. DI</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($di_number) ?>" readonly>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">No. TR</label>
                                <?php if (!empty($request['tr_number'])): ?>
                                    <a href="detailtr.php?tr_number=<?= urlencode($request['tr_number']) ?>" class="detail-link" title="Buka Detail TR">
                                        <?= htmlspecialchars($request['tr_number']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="detail-link-disabled">-</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="text" class="form-control" value="<?= date('d/m/Y', strtotime($request['request_date'])) ?>" readonly>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">No. SO *</label>
                                <input type="text" name="no_so" class="form-control" value="<?= htmlspecialchars($request['no_so']) ?>" required>
                            </div>
                            <div class="col-md-2 mb-3">
                                <label class="form-label">Sales</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($request['sales_name'] ?? '-') ?>" readonly>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editDataPenjualan', 'viewDataPenjualan')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewDataPenjualan">
                    <div class="row">
                        <div class="col-md-2">
                            <div class="info-label">Activity Number</div>
                            <div class="info-value">
                                    <?php if (!empty($request['activity_number']) && !empty($request['sales_activity_id'])): ?>
                                        <a href="detailaktivitas.php?leads_id=<?= urlencode($request['sales_activity_id']) ?>" class="detail-link" title="Buka Detail Activity">
                                            <strong><?= htmlspecialchars($request['activity_number']) ?></strong>
                                        </a>
                                    <?php else: ?>
                                        <span class="detail-link-disabled">-</span>
                                    <?php endif; ?>
                                </div>
                        </div>
                        <div class="col-md-2">
                            <div class="info-label">No. DI</div>
                            <div class="info-value"><strong><?= htmlspecialchars($di_number) ?></strong></div>
                        </div>
                        <div class="col-md-2">
                            <div class="info-label">No. TR</div>
                            <div class="info-value">
                                    <?php if (!empty($request['tr_number'])): ?>
                                        <a href="detailtr.php?tr_number=<?= urlencode($request['tr_number']) ?>" class="detail-link" title="Buka Detail TR">
                                            <strong><?= htmlspecialchars($request['tr_number']) ?></strong>
                                        </a>
                                    <?php else: ?>
                                        <span class="detail-link-disabled">-</span>
                                    <?php endif; ?>
                                </div>
                        </div>
                        <div class="col-md-2">
                            <div class="info-label">Tanggal</div>
                            <div class="info-value"><?= date('d/m/Y', strtotime($request['request_date'])) ?></div>
                        </div>
                        <div class="col-md-2">
                            <div class="info-label">No. SO</div>
                            <div class="info-value"><?= htmlspecialchars($request['no_so'] ?: '-') ?></div>
                        </div>
                        <div class="col-md-2">
                            <div class="info-label">Sales</div>
                            <div class="info-value"><?= htmlspecialchars($request['sales_name'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <!-- ============================================ -->
                <!-- APPROVAL INFO (Di dalam Data Penjualan) -->
                <!-- ============================================ -->
                <div class="row mt-3">
                    <div class="col-md-3">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="badge-status-di <?= $request['status'] ?>">
                                <?php if ($request['status'] == 'pending'): ?>
                                    <i class="fas fa-clock"></i> Pending
                                <?php elseif ($request['status'] == 'approved'): ?>
                                    <i class="fas fa-check-circle"></i> Approved
                                <?php elseif ($request['status'] == 'rejected'): ?>
                                    <i class="fas fa-times-circle"></i> Rejected
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="info-label">Current Approver</div>
                        <div class="info-value"><?= htmlspecialchars($currentApproverLabel) ?></div>
                    </div>
                    <div class="col-md-5">
                        <div class="info-label">Next Approver</div>
                        <div class="info-value"><?= htmlspecialchars($nextApproverLabel) ?></div>
                    </div>
                </div>
                
                <?php if (!$hasInputData): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-lock"></i>
                        Approval belum tersedia. <strong>Admin harus menginput minimal 1 menu</strong> terlebih dahulu, misalnya Data Unit.
                    </div>
                <?php endif; ?>

                <!-- APPROVAL ACTION -->
                <?php if ($hasInputData && $currentApprovalOrder > 0 && $currentApprovalOrder <= 8 && $request['status'] == 'pending'): ?>
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
                    
                    <?php if ($canApprove): ?>
                    <div class="mt-3 p-3" style="background: #f8f9fa; border-radius: 10px;">
                        <h6 class="mb-3"><i class="fas fa-check-double"></i> Approval Action</h6>
                        <p>Anda memiliki hak untuk melakukan approval sebagai <strong><?= htmlspecialchars($currentApproverLabel) ?></strong></p>
                        <form method="POST" id="approvalForm">
                            <input type="hidden" name="action" id="approvalAction" value="approve">
                            <input type="hidden" name="approval_order" value="<?= $currentApprovalOrder ?>">
                            <button type="button" class="btn btn-success-custom" onclick="submitApproval('approve')">
                                <i class="fas fa-check-circle"></i> Approve
                            </button>
                            <button type="button" class="btn btn-danger-custom" onclick="submitApproval('reject')">
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
        <!-- TAB CONTENT: DATA CUSTOMER -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'data_customer'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-building"></i> Data Customer</h6>
            </div>
            <div class="card-body-custom">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-label">Nama PT</div>
                        <div class="info-value"><?= htmlspecialchars($request['nama_pt'] ?? '-') ?></div>
                        
                        <div class="info-label">Alamat</div>
                        <div class="info-value"><?= htmlspecialchars($request['alamat'] ?? '-') ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-label">Nama PIC</div>
                        <div class="info-value"><?= htmlspecialchars($request['nama_pic'] ?? '-') ?></div>
                        
                        <div class="info-label">No Telepon PIC</div>
                        <div class="info-value"><?= htmlspecialchars($request['no_hp_pic'] ?? '-') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: DATA UNIT -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'data_unit'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-boxes"></i> Data Unit</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editUnits', 'viewUnits')">
                    <i class="fas fa-edit"></i> <?= count($diUnits) > 0 ? 'Edit Unit' : 'Tambah Unit' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editUnits" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_units">
                        
                        <div id="unitRows">
                            <!-- Unit rows akan ditambahkan di sini oleh JavaScript -->
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addUnitRow()">
                                <i class="fas fa-plus"></i> Tambah Unit
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Semua Unit
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editUnits', 'viewUnits')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewUnits">
                    <?php if (count($diUnits) > 0): ?>
                        <?php foreach ($diUnits as $index => $unit): ?>
                            <div class="unit-result-card">
                                <div class="unit-result-header">
                                    <strong>
                                        <i class="fas fa-box"></i> 
                                        Unit <?= $index + 1 ?>
                                    </strong>
                                </div>
                                <div class="unit-result-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="info-label">Lokasi Unit</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['lokasi_unit'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Cabang</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['cabang'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Kode Unit</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['kode_unit'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Brand</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['brand'] ?: '-') ?></div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="info-label">Tipe</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['tipe'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Serial Number</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['serial_number'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Engine Number</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['engine_number'] ?: '-') ?></div>
                                            
                                            <div class="info-label">Keterangan</div>
                                            <div class="info-value"><?= htmlspecialchars($unit['keterangan'] ?: '-') ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-box-open me-2"></i> Belum ada data unit
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: AKSESORIS -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'aksesoris'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-tools"></i> Aksesoris</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editAccessories', 'viewAccessories')">
                    <i class="fas fa-edit"></i> <?= count($diAccessories) > 0 ? 'Edit Aksesoris' : 'Tambah Aksesoris' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editAccessories" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_accessories">
                        
                        <div id="accessoryRows">
                            <!-- Accessory rows akan ditambahkan di sini oleh JavaScript -->
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addAccessoryRow()">
                                <i class="fas fa-plus"></i> Tambah Aksesoris
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Semua Aksesoris
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editAccessories', 'viewAccessories')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewAccessories">
                    <?php if (count($diAccessories) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered" style="font-size: 13px;">
                                <thead>
                                    <tr style="background: #f8f9fa;">
                                        <th style="width: 50px;">No</th>
                                        <th>Uraian</th>
                                        <th style="width: 100px;">Satuan</th>
                                        <th style="width: 80px;">Jumlah</th>
                                        <th>Keterangan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($diAccessories as $acc): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($acc['no'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($acc['uraian'] ?: '-') ?></td>
                                            <td><?= htmlspecialchars($acc['satuan'] ?: '-') ?></td>
                                            <td><?= (int)$acc['jumlah'] ?></td>
                                            <td><?= htmlspecialchars($acc['keterangan'] ?: '-') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-tools me-2"></i> Belum ada data aksesoris
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: LOGISTIK -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'logistik'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-truck"></i> Logistik</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editLogistics', 'viewLogistics')">
                    <i class="fas fa-edit"></i> <?= $diLogistics ? 'Edit Logistik' : 'Tambah Logistik' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editLogistics" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_logistics">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lokasi Pengambilan</label>
                                <input type="text" name="lokasi_pengambilan" class="form-control" value="<?= htmlspecialchars($diLogistics['lokasi_pengambilan'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Lokasi Pengiriman</label>
                                <input type="text" name="lokasi_pengiriman" class="form-control" value="<?= htmlspecialchars($diLogistics['lokasi_pengiriman'] ?? '') ?>">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Transportir</label>
                                <input type="text" name="transportir" class="form-control" value="<?= htmlspecialchars($diLogistics['transportir'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Waktu Pengiriman</label>
                                <input type="date" name="waktu_pengiriman" class="form-control" value="<?= $diLogistics['waktu_pengiriman'] ? date('Y-m-d', strtotime($diLogistics['waktu_pengiriman'])) : '' ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">ETA</label>
                                <input type="date" name="eta" class="form-control" value="<?= $diLogistics['eta'] ? date('Y-m-d', strtotime($diLogistics['eta'])) : '' ?>">
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Logistik
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editLogistics', 'viewLogistics')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewLogistics">
                    <?php if ($diLogistics): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Lokasi Pengambilan</div>
                                <div class="info-value"><?= htmlspecialchars($diLogistics['lokasi_pengambilan'] ?: '-') ?></div>
                                
                                <div class="info-label">Lokasi Pengiriman</div>
                                <div class="info-value"><?= htmlspecialchars($diLogistics['lokasi_pengiriman'] ?: '-') ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Transportir</div>
                                <div class="info-value"><?= htmlspecialchars($diLogistics['transportir'] ?: '-') ?></div>
                                
                                <div class="info-label">Waktu Pengiriman</div>
                                <div class="info-value"><?= $diLogistics['waktu_pengiriman'] ? date('d/m/Y', strtotime($diLogistics['waktu_pengiriman'])) : '-' ?></div>
                                
                                <div class="info-label">ETA</div>
                                <div class="info-value"><?= $diLogistics['eta'] ? date('d/m/Y', strtotime($diLogistics['eta'])) : '-' ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-truck me-2"></i> Belum ada data logistik
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: DETAIL PART -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'detail_part'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-cogs"></i> Detail Part</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editParts', 'viewParts')">
                    <i class="fas fa-edit"></i> <?= count($diParts) > 0 ? 'Edit Detail Part' : 'Tambah Detail Part' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">

                <div id="editParts" style="display:none; margin-bottom:20px; background:#f8f9fa; padding:20px; border-radius:10px;">
                    <form method="POST" id="partsForm">
                        <input type="hidden" name="action" value="save_parts">
                        <div id="partRows"></div>
                        <div class="mt-3">
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addPartRow()">
                                <i class="fas fa-plus"></i> Tambah Part
                            </button>
                        </div>
                        <hr>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan Detail Part</button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editParts', 'viewParts')"><i class="fas fa-times"></i> Batal</button>
                    </form>
                </div>

                <div id="viewParts">
                    <?php if (count($diParts) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered detail-part-table">
                            <thead><tr>
                                <th style="width:50px">No</th><th>Part Number</th><th>Description</th><th>Price</th><th>Qty</th><th>Jumlah Unit</th><th>Total Amount</th>
                            </tr></thead>
                            <tbody>
                            <?php $grandTotalParts = 0; foreach ($diParts as $idx => $part): $grandTotalParts += (float)$part['total_amount']; ?>
                                <tr>
                                    <td><?= $idx + 1 ?></td>
                                    <td><?= htmlspecialchars($part['part_number']) ?></td>
                                    <td><?= htmlspecialchars($part['description']) ?></td>
                                    <td>Rp <?= number_format((float)$part['price'], 0, ',', '.') ?></td>
                                    <td><?= rtrim(rtrim(number_format((float)$part['qty'], 2, ',', '.'), '0'), ',') ?></td>
                                    <td><?= (int)$jumlahUnitDI ?></td>
                                    <td><strong>Rp <?= number_format((float)$part['total_amount'], 0, ',', '.') ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-total-row"><td colspan="6" class="text-end"><strong>Grand Total</strong></td><td><strong>Rp <?= number_format($grandTotalParts, 0, ',', '.') ?></strong></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted"><i class="fas fa-cogs me-2"></i> Belum ada data detail part</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: KOMPARASI LOGISTIK -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'komparasi_logistik'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-scale-balanced"></i> Komparasi Harga Logistik Unit</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editLogisticsComparison', 'viewLogisticsComparison')">
                    <i class="fas fa-edit"></i> <?= count($diLogisticsComparisons) > 0 ? 'Edit Komparasi' : 'Tambah Vendor' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editLogisticsComparison" style="display:none; margin-bottom:20px; background:#f8f9fa; padding:20px; border-radius:10px;">
                    <form method="POST" id="logisticsComparisonForm">
                        <input type="hidden" name="action" value="save_logistics_comparison">
                        <div class="comparison-note"><i class="fas fa-info-circle"></i> Input dan simpan data semua vendor terlebih dahulu. Pemilihan vendor dilakukan setelah data tersimpan.</div>
                        <div id="vendorRows"></div>
                        <button type="button" class="btn btn-secondary-custom btn-sm mt-3" onclick="addVendorRow()"><i class="fas fa-plus"></i> Tambah Vendor</button>
                        <hr>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan Komparasi</button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editLogisticsComparison', 'viewLogisticsComparison')"><i class="fas fa-times"></i> Batal</button>
                    </form>
                </div>

                <div id="viewLogisticsComparison">
                    <?php if (count($diLogisticsComparisons) > 0): ?>
                    <div class="table-responsive">
                        <div class="comparison-note">
                            <i class="fas fa-circle-check"></i>
                            <?php if ($selectedLogisticsVendor): ?>
                                Vendor terpilih sudah disimpan dan dikunci. Pilihan vendor tidak dapat diubah lagi.
                            <?php else: ?>
                                Centang satu vendor pada kolom <strong>Pilih</strong>. Setelah dicentang, pilihan akan langsung tersimpan dan dikunci.
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered logistics-comparison-table">
                                <thead>
                                    <tr>
                                        <th style="width:90px">Vendor</th>
                                        <th>Nama Vendor</th>
                                        <th>Metode Pembayaran</th>
                                        <th>ETA Kirim</th>
                                        <th>Harga</th>
                                        <th>Keterangan</th>
                                        <th class="vendor-select-column">Pilih</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($diLogisticsComparisons as $idx => $vendor): ?>
                                    <?php
                                        $isSelected = (int)$vendor['is_selected'] === 1;
                                        $vendorLocked = $selectedLogisticsVendor !== null;
                                    ?>
                                    <tr class="<?= $isSelected ? 'selected-vendor-row' : '' ?>">
                                        <td><strong>Vendor <?= chr(65 + $idx) ?></strong></td>
                                        <td><?= htmlspecialchars($vendor['vendor_name']) ?></td>
                                        <td><?= htmlspecialchars($vendor['payment_method'] ?: '-') ?></td>
                                        <td><?= $vendor['eta_kirim'] ? date('d/m/Y', strtotime($vendor['eta_kirim'])) : '-' ?></td>
                                        <td>Rp <?= number_format((float)$vendor['harga'], 0, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($vendor['keterangan'] ?: '-') ?></td>
                                        <td class="vendor-select-cell">
                                            <form method="POST" class="vendor-inline-select-form">
                                                <input type="hidden" name="action" value="select_logistics_vendor">
                                                <input type="hidden" name="selected_vendor_id" value="<?= (int)$vendor['id'] ?>">
                                                <label class="vendor-checkbox-label" title="<?= $isSelected ? 'Vendor terpilih' : ($vendorLocked ? 'Pilihan vendor sudah dikunci' : 'Pilih vendor ini') ?>">
                                                    <input
                                                        type="checkbox"
                                                        class="vendor-check"
                                                        <?= $isSelected ? 'checked' : '' ?>
                                                        <?= $vendorLocked ? 'disabled' : 'onchange="this.form.submit();"' ?>
                                                    >
                                                    <span class="vendor-checkmark"></span>
                                                </label>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="selected-vendor-box">
                        <div class="selected-vendor-title"><i class="fas fa-circle-check"></i> Vendor Terpilih</div>
                        <?php if ($selectedLogisticsVendor): ?>
                            <?php $selectedIndex = 0; foreach ($diLogisticsComparisons as $i => $v) { if ((int)$v['is_selected'] === 1) { $selectedIndex = $i; break; } } ?>
                            <div class="selected-vendor-name">Vendor <?= chr(65 + $selectedIndex) ?> — <?= htmlspecialchars($selectedLogisticsVendor['vendor_name']) ?></div>
                            <div class="selected-vendor-detail">
                                Metode Pembayaran: <strong><?= htmlspecialchars($selectedLogisticsVendor['payment_method'] ?: '-') ?></strong> &nbsp; | &nbsp;
                                ETA Kirim: <strong><?= $selectedLogisticsVendor['eta_kirim'] ? date('d/m/Y', strtotime($selectedLogisticsVendor['eta_kirim'])) : '-' ?></strong> &nbsp; | &nbsp;
                                Harga: <strong>Rp <?= number_format((float)$selectedLogisticsVendor['harga'], 0, ',', '.') ?></strong>
                            </div>
                            <?php if (!empty($selectedLogisticsVendor['keterangan'])): ?><div class="selected-vendor-note"><?= htmlspecialchars($selectedLogisticsVendor['keterangan']) ?></div><?php endif; ?>
                        <?php else: ?>
                            <div class="text-muted">Belum ada vendor yang dipilih.</div>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted"><i class="fas fa-truck-fast me-2"></i> Belum ada komparasi harga logistik</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TAB CONTENT: PRODUCT SUPPORT -->
        <!-- ============================================ -->
        <?php if ($activeTab == 'product_support'): ?>
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-headset"></i> Product Support</h6>
                <?php if ($canEdit): ?>
                <button class="btn btn-primary-custom btn-sm" onclick="toggleSection('editSupport', 'viewSupport')">
                    <i class="fas fa-edit"></i> <?= count($diSupports) > 0 ? 'Edit Support' : 'Tambah Support' ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body-custom">
                <div id="editSupport" style="display: none; margin-bottom: 20px; background: #f8f9fa; padding: 20px; border-radius: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_product_support">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Free Filter Engine</label>
                            <div id="ffeContainer">
                                <?php foreach ($supportsGrouped['free_filter_engine'] as $item): ?>
                                    <input type="text" name="free_filter_engine[]" class="form-control mb-2" value="<?= htmlspecialchars($item['value']) ?>" placeholder="Free Filter Engine">
                                <?php endforeach; ?>
                                <?php if (count($supportsGrouped['free_filter_engine']) == 0): ?>
                                    <input type="text" name="free_filter_engine[]" class="form-control mb-2" placeholder="Free Filter Engine">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addInputRow('ffeContainer', 'free_filter_engine[]')">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Jarak Service</label>
                            <div id="jsContainer">
                                <?php foreach ($supportsGrouped['jarak_service'] as $item): ?>
                                    <input type="text" name="jarak_service[]" class="form-control mb-2" value="<?= htmlspecialchars($item['value']) ?>" placeholder="Jarak Service">
                                <?php endforeach; ?>
                                <?php if (count($supportsGrouped['jarak_service']) == 0): ?>
                                    <input type="text" name="jarak_service[]" class="form-control mb-2" placeholder="Jarak Service">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addInputRow('jsContainer', 'jarak_service[]')">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Catatan</label>
                            <div id="catatanContainer">
                                <?php foreach ($supportsGrouped['catatan'] as $item): ?>
                                    <input type="text" name="catatan[]" class="form-control mb-2" value="<?= htmlspecialchars($item['value']) ?>" placeholder="Catatan">
                                <?php endforeach; ?>
                                <?php if (count($supportsGrouped['catatan']) == 0): ?>
                                    <input type="text" name="catatan[]" class="form-control mb-2" placeholder="Catatan">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addInputRow('catatanContainer', 'catatan[]')">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Free Service</label>
                            <div id="fsContainer">
                                <?php foreach ($supportsGrouped['free_service'] as $item): ?>
                                    <input type="text" name="free_service[]" class="form-control mb-2" value="<?= htmlspecialchars($item['value']) ?>" placeholder="Free Service">
                                <?php endforeach; ?>
                                <?php if (count($supportsGrouped['free_service']) == 0): ?>
                                    <input type="text" name="free_service[]" class="form-control mb-2" placeholder="Free Service">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addInputRow('fsContainer', 'free_service[]')">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Warranty</label>
                            <div id="warrantyContainer">
                                <?php foreach ($supportsGrouped['warranty'] as $item): ?>
                                    <input type="text" name="warranty[]" class="form-control mb-2" value="<?= htmlspecialchars($item['value']) ?>" placeholder="Warranty">
                                <?php endforeach; ?>
                                <?php if (count($supportsGrouped['warranty']) == 0): ?>
                                    <input type="text" name="warranty[]" class="form-control mb-2" placeholder="Warranty">
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn btn-secondary-custom btn-sm" onclick="addInputRow('warrantyContainer', 'warranty[]')">
                                <i class="fas fa-plus"></i> Tambah
                            </button>
                        </div>
                        
                        <hr>
                        
                        <button type="submit" class="btn btn-primary-custom">
                            <i class="fas fa-save"></i> Simpan Product Support
                        </button>
                        <button type="button" class="btn btn-secondary-custom" onclick="toggleSection('editSupport', 'viewSupport')">
                            <i class="fas fa-times"></i> Batal
                        </button>
                    </form>
                </div>
                
                <div id="viewSupport">
                    <?php if (count($diSupports) > 0): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <?php if (count($supportsGrouped['free_filter_engine']) > 0): ?>
                                    <div class="info-label">Free Filter Engine</div>
                                    <?php foreach ($supportsGrouped['free_filter_engine'] as $item): ?>
                                        <div class="info-value"><?= htmlspecialchars($item['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (count($supportsGrouped['jarak_service']) > 0): ?>
                                    <div class="info-label">Jarak Service</div>
                                    <?php foreach ($supportsGrouped['jarak_service'] as $item): ?>
                                        <div class="info-value"><?= htmlspecialchars($item['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (count($supportsGrouped['catatan']) > 0): ?>
                                    <div class="info-label">Catatan</div>
                                    <?php foreach ($supportsGrouped['catatan'] as $item): ?>
                                        <div class="info-value"><?= htmlspecialchars($item['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <?php if (count($supportsGrouped['free_service']) > 0): ?>
                                    <div class="info-label">Free Service</div>
                                    <?php foreach ($supportsGrouped['free_service'] as $item): ?>
                                        <div class="info-value"><?= htmlspecialchars($item['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <?php if (count($supportsGrouped['warranty']) > 0): ?>
                                    <div class="info-label">Warranty</div>
                                    <?php foreach ($supportsGrouped['warranty'] as $item): ?>
                                        <div class="info-value"><?= htmlspecialchars($item['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="fas fa-headset me-2"></i> Belum ada data product support
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSection(editId, viewId) {
            const editEl = document.getElementById(editId);
            const viewEl = document.getElementById(viewId);
            if (editEl.style.display === 'none') {
                editEl.style.display = 'block';
                viewEl.style.display = 'none';
            } else {
                editEl.style.display = 'none';
                viewEl.style.display = 'block';
            }
        }
        
        function submitApproval(action) {
            if (action === 'reject') {
                if (!confirm('Yakin ingin me-reject DI ini?')) return;
            }
            if (action === 'approve') {
                if (!confirm('Yakin ingin meng-approve DI ini?')) return;
            }
            document.getElementById('approvalAction').value = action;
            document.getElementById('approvalForm').submit();
        }
        
        let unitRowCount = 0;
        function addUnitRow(data = null) {
            unitRowCount++;
            const container = document.getElementById('unitRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'data-row';
            rowDiv.id = 'unitRow_' + unitRowCount;
            rowDiv.innerHTML = `
                <div class="data-header">
                    <strong><i class="fas fa-box"></i> Unit ${unitRowCount}</strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow('unitRow_${unitRowCount}')">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Lokasi Unit</label>
                        <input type="text" name="lokasi_unit[]" class="form-control" value="${data ? data.lokasi_unit : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Cabang</label>
                        <input type="text" name="cabang[]" class="form-control" value="${data ? data.cabang : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Kode Unit</label>
                        <input type="text" name="kode_unit[]" class="form-control" value="${data ? data.kode_unit : ''}">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand[]" class="form-control" value="${data ? data.brand : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Tipe</label>
                        <input type="text" name="tipe[]" class="form-control" value="${data ? data.tipe : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Serial Number</label>
                        <input type="text" name="serial_number[]" class="form-control" value="${data ? data.serial_number : ''}">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Engine Number</label>
                        <input type="text" name="engine_number[]" class="form-control" value="${data ? data.engine_number : ''}">
                    </div>
                    <div class="col-md-8 mb-2">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan[]" class="form-control" value="${data ? data.keterangan : ''}">
                    </div>
                </div>
            `;
            container.appendChild(rowDiv);
        }
        
        let accessoryRowCount = 0;
        function addAccessoryRow(data = null) {
            accessoryRowCount++;
            const container = document.getElementById('accessoryRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'data-row';
            rowDiv.id = 'accessoryRow_' + accessoryRowCount;

            const satuanValue = data ? String(data.satuan || '') : '';
            const satuanOptions = ['PCS', 'SET', 'UNIT', 'BOX', 'PAIR', 'LOT', 'LITER', 'METER'];
            const customOption = satuanValue && !satuanOptions.includes(satuanValue.toUpperCase())
                ? `<option value="${escapeHtml(satuanValue)}" selected>${escapeHtml(satuanValue)}</option>` : '';

            rowDiv.innerHTML = `
                <input type="hidden" name="id[]" value="${data && data.id ? data.id : 0}">
                <div class="data-header">
                    <strong><i class="fas fa-tools"></i> Aksesoris ${accessoryRowCount}</strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow('accessoryRow_${accessoryRowCount}')">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-2 mb-2">
                        <label class="form-label">No</label>
                        <input type="text" name="no[]" class="form-control" value="${data ? escapeHtml(data.no) : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Uraian</label>
                        <input type="text" name="uraian[]" class="form-control" value="${data ? escapeHtml(data.uraian) : ''}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Satuan</label>
                        <select name="satuan[]" class="form-select">
                            <option value="">Pilih Satuan</option>
                            ${customOption}
                            ${satuanOptions.map(opt => `<option value="${opt}" ${satuanValue.toUpperCase() === opt ? 'selected' : ''}>${opt}</option>`).join('')}
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Jumlah</label>
                        <input type="number" name="jumlah[]" class="form-control" min="0" step="1" value="${data ? data.jumlah : 0}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan[]" class="form-control" value="${data ? escapeHtml(data.keterangan) : ''}">
                    </div>
                </div>
            `;
            container.appendChild(rowDiv);
        }

        let partRowCount = 0;
        const jumlahUnitDI = <?= (int)$jumlahUnitDI ?>;

        function formatRupiahNumber(value) {
            const number = Number(value) || 0;
            return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(number);
        }

        function updatePartTotal(row) {
            const price = parseFloat(row.querySelector('[name="price[]"]')?.value || 0);
            const qty = parseFloat(row.querySelector('[name="qty[]"]')?.value || 0);
            const total = price * qty * jumlahUnitDI;
            const totalInput = row.querySelector('[name="total_amount_display[]"]');
            if (totalInput) totalInput.value = formatRupiahNumber(total);
        }

        function addPartRow(data = null) {
            partRowCount++;
            const container = document.getElementById('partRows');
            const rowDiv = document.createElement('div');
            rowDiv.className = 'data-row part-data-row';
            rowDiv.id = 'partRow_' + partRowCount;
            rowDiv.innerHTML = `
                <input type="hidden" name="id[]" value="${data && data.id ? data.id : 0}">
                <div class="data-header">
                    <strong><i class="fas fa-cogs"></i> Part ${partRowCount}</strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removePartRow('partRow_${partRowCount}')"><i class="fas fa-trash"></i> Hapus</button>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="form-label">Part Number</label><input type="text" name="part_number[]" class="form-control" value="${data ? escapeHtml(data.part_number) : ''}" required></div>
                    <div class="col-md-5 mb-2"><label class="form-label">Description</label><input type="text" name="description[]" class="form-control" value="${data ? escapeHtml(data.description) : ''}"></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Price</label><input type="number" name="price[]" class="form-control" min="0" step="0.01" value="${data ? data.price : 0}" oninput="updatePartTotal(this.closest('.part-data-row'))"></div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2"><label class="form-label">Qty</label><input type="number" name="qty[]" class="form-control" min="0" step="0.01" value="${data ? data.qty : 1}" oninput="updatePartTotal(this.closest('.part-data-row'))"></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Jumlah Unit</label><input type="text" class="form-control" value="${jumlahUnitDI}" readonly></div>
                    <div class="col-md-4 mb-2"><label class="form-label">Total Amount</label><input type="text" name="total_amount_display[]" class="form-control part-total-display" value="Rp 0" readonly></div>
                </div>`;
            container.appendChild(rowDiv);
            updatePartTotal(rowDiv);
        }

        function escapeHtml(value) {
            return String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        }

        function removePartRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) row.remove();
            renumberRows('part-data-row', 'Part');
        }

        function addVendorRow(data = null) {
            const container = document.getElementById('vendorRows');
            const index = container.querySelectorAll('.vendor-data-row').length;
            const letter = String.fromCharCode(65 + index);
            const rowDiv = document.createElement('div');
            rowDiv.className = 'vendor-data-row';
            rowDiv.innerHTML = `
                <input type="hidden" name="id[]" value="${data && data.id ? data.id : 0}">
                <div class="vendor-data-header">
                    <div class="vendor-label"><i class="fas fa-truck"></i> Vendor ${letter}</div>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeVendorRow(this)"><i class="fas fa-trash"></i> Hapus</button>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2"><label class="form-label">Nama Vendor</label><input type="text" name="vendor_name[]" class="form-control" value="${data ? escapeHtml(data.vendor_name) : ''}" required></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Metode Pembayaran</label><input type="text" name="payment_method[]" class="form-control" value="${data ? escapeHtml(data.payment_method) : ''}" placeholder="Cash / Transfer / Tempo"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">ETA Kirim</label><input type="date" name="eta_kirim[]" class="form-control" value="${data ? data.eta_kirim : ''}"></div>
                    <div class="col-md-3 mb-2"><label class="form-label">Harga</label><input type="number" name="harga[]" class="form-control" min="0" step="0.01" value="${data ? data.harga : 0}"></div>
                </div>
                <div class="row"><div class="col-md-12 mb-1"><label class="form-label">Keterangan</label><textarea name="keterangan_komparasi[]" class="form-control" rows="2" placeholder="Keterangan vendor...">${data ? escapeHtml(data.keterangan) : ''}</textarea></div></div>`;
            container.appendChild(rowDiv);
        }


        function removeVendorRow(button) {
            const row = button.closest('.vendor-data-row');
            if (row) row.remove();
            renumberVendors();
        }

        function renumberVendors() {
            document.querySelectorAll('#vendorRows .vendor-data-row').forEach((row, index) => {
                const label = row.querySelector('.vendor-label');
                if (label) label.innerHTML = `<i class="fas fa-truck"></i> Vendor ${String.fromCharCode(65 + index)}`;
                const checkbox = row.querySelector('.vendor-check');
                if (checkbox) checkbox.value = index;
            });
        }

        function renumberRows(selector, prefix) {
            document.querySelectorAll('.' + selector).forEach((row, index) => {
                const title = row.querySelector('.data-header strong');
                if (title) title.innerHTML = `<i class="fas fa-cogs"></i> ${prefix} ${index + 1}`;
            });
        }

        function addInputRow(containerId, inputName) {
            const container = document.getElementById(containerId);
            const newInput = document.createElement('input');
            newInput.type = 'text';
            newInput.name = inputName;
            newInput.className = 'form-control mb-2';
            newInput.placeholder = inputName.replace('[]', '');
            container.appendChild(newInput);
        }
        
        function removeRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) {
                row.remove();
                const rows = document.querySelectorAll('.data-row');
                rows.forEach((row, index) => {
                    const title = row.querySelector('strong');
                    if (title) {
                        const icon = title.querySelector('i');
                        const type = icon ? icon.className : '';
                        title.innerHTML = `<i class="${type}"></i> ${index + 1}`;
                    }
                });
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // =====================================================
            // LOAD DATA LAMA KE FORM EDIT
            // Hanya jalankan initializer jika container form memang
            // ada di tab yang sedang aktif. Ini mengikuti pola Data Unit.
            // =====================================================

            const unitRows = document.getElementById('unitRows');
            if (unitRows) {
                <?php if (count($diUnits) > 0): ?>
                    <?php foreach ($diUnits as $unit): ?>
                        addUnitRow({
                            lokasi_unit: <?= json_encode($unit['lokasi_unit'] ?? '') ?>,
                            cabang: <?= json_encode($unit['cabang'] ?? '') ?>,
                            kode_unit: <?= json_encode($unit['kode_unit'] ?? '') ?>,
                            brand: <?= json_encode($unit['brand'] ?? '') ?>,
                            tipe: <?= json_encode($unit['tipe'] ?? '') ?>,
                            serial_number: <?= json_encode($unit['serial_number'] ?? '') ?>,
                            engine_number: <?= json_encode($unit['engine_number'] ?? '') ?>,
                            keterangan: <?= json_encode($unit['keterangan'] ?? '') ?>
                        });
                    <?php endforeach; ?>
                <?php else: ?>
                    addUnitRow();
                <?php endif; ?>
            }

            const accessoryRows = document.getElementById('accessoryRows');
            if (accessoryRows) {
                <?php if (count($diAccessories) > 0): ?>
                    <?php foreach ($diAccessories as $acc): ?>
                        addAccessoryRow({
                            id: <?= (int)$acc['id'] ?>,
                            no: <?= json_encode($acc['no'] ?? '') ?>,
                            uraian: <?= json_encode($acc['uraian'] ?? '') ?>,
                            satuan: <?= json_encode($acc['satuan'] ?? '') ?>,
                            jumlah: <?= json_encode($acc['jumlah'] ?? '') ?>,
                            keterangan: <?= json_encode($acc['keterangan'] ?? '') ?>
                        });
                    <?php endforeach; ?>
                <?php else: ?>
                    addAccessoryRow();
                <?php endif; ?>
            }

            const partRows = document.getElementById('partRows');
            if (partRows) {
                <?php if (count($diParts) > 0): ?>
                    <?php foreach ($diParts as $part): ?>
                        addPartRow({
                            id: <?= (int)$part['id'] ?>,
                            part_number: <?= json_encode($part['part_number'] ?? '') ?>,
                            description: <?= json_encode($part['description'] ?? '') ?>,
                            price: <?= json_encode($part['price'] ?? 0) ?>,
                            qty: <?= json_encode($part['qty'] ?? 0) ?>
                        });
                    <?php endforeach; ?>
                <?php else: ?>
                    addPartRow();
                <?php endif; ?>
            }

            const vendorRows = document.getElementById('vendorRows');
            if (vendorRows) {
                <?php if (count($diLogisticsComparisons) > 0): ?>
                    <?php foreach ($diLogisticsComparisons as $vendor): ?>
                        addVendorRow({
                            id: <?= (int)$vendor['id'] ?>,
                            vendor_name: <?= json_encode($vendor['vendor_name'] ?? '') ?>,
                            payment_method: <?= json_encode($vendor['payment_method'] ?? '') ?>,
                            eta_kirim: <?= json_encode($vendor['eta_kirim'] ?? '') ?>,
                            harga: <?= json_encode($vendor['harga'] ?? 0) ?>,
                            keterangan: <?= json_encode($vendor['keterangan'] ?? '') ?>
                        });
                    <?php endforeach; ?>
                <?php else: ?>
                    addVendorRow();
                <?php endif; ?>
            }
        });
    </script>
</body>
</html>