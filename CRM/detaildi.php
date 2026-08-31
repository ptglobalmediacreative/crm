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
$validTabs = ['data_penjualan', 'data_customer', 'data_unit', 'aksesoris', 'logistik', 'product_support'];
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
    1 => ['role' => 'admin', 'label' => 'Admin Sales'],
    2 => ['role' => 'business', 'label' => 'Business'],
    3 => ['role' => 'service_support', 'label' => 'Service Support'],
    4 => ['role' => 'part_support', 'label' => 'Part Support'],
    5 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    6 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// TENTUKAN CURRENT APPROVER DAN NEXT APPROVER
// ============================================
$currentApprovalOrder = 1;
$currentApproverLabel = '';
$nextApproverLabel = '';

if ($detailDI) {
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
    
    if ($isRejected || $detailDI['status'] == 'rejected') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } elseif ($detailDI['status'] == 'approved') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } else {
        $currentApprovalOrder = $lastApprovedOrder + 1;
        if ($currentApprovalOrder <= 6) {
            $currentApproverLabel = $approvalLevels[$currentApprovalOrder]['label'];
            $nextOrder = $currentApprovalOrder + 1;
            $nextApproverLabel = $nextOrder <= 6 ? $approvalLevels[$nextOrder]['label'] : 'No More Approval';
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
    
    $editActions = ['save_data_penjualan', 'save_units', 'save_accessories', 'save_logistics', 'save_product_support'];
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
    // ============================================
    if ($action === 'approve' || $action === 'reject') {
        try {
            $db->beginTransaction();
            $approvalStatus = $action === 'approve' ? 'approved' : 'rejected';
            $currentOrder = (int)($_POST['approval_order'] ?? 0);
            
            $canApprove = false;
            if ($currentOrder > 0 && $currentOrder <= 6) {
                $requiredRole = $approvalLevels[$currentOrder]['role'];
                if ($userRole == $requiredRole) {
                    $canApprove = true;
                }
            }
            
            if ($canApprove) {
                $checkApproval = $db->prepare("SELECT id FROM di_approval_history WHERE di_number = ? AND approval_order = ?");
                $checkApproval->execute([$di_number, $currentOrder]);
                $existingApproval = $checkApproval->fetch();
                
                if ($existingApproval) {
                    $updateApproval = $db->prepare("UPDATE di_approval_history SET status = ?, catatan = '', approved_by = ?, approved_at = NOW() WHERE id = ?");
                    $updateApproval->execute([$approvalStatus, $userId, $existingApproval['id']]);
                } else {
                    $insertApproval = $db->prepare("INSERT INTO di_approval_history (di_number, approval_order, approval_role, approval_label, status, catatan, approved_by, approved_at, created_at) VALUES (?, ?, ?, ?, ?, '', ?, NOW(), NOW())");
                    $insertApproval->execute([$di_number, $currentOrder, $approvalLevels[$currentOrder]['role'], $approvalLevels[$currentOrder]['label'], $approvalStatus, $userId]);
                }
                
                $newStatus = 'pending';
                if ($approvalStatus == 'rejected') {
                    $newStatus = 'rejected';
                } elseif ($currentOrder >= 6) {
                    $newStatus = 'approved';
                }
                
                $updateDetail = $db->prepare("UPDATE detail_delivery_instructions SET status = ?, current_approval_order = ?, updated_at = NOW() WHERE di_number = ?");
                $updateDetail->execute([$newStatus, $currentOrder + 1, $di_number]);
                
                $db->commit();
                setFlash($approvalStatus == 'approved' ? 'DI berhasil di-approve!' : 'DI berhasil di-reject!', 'success');
            } else {
                setFlash('Anda tidak memiliki hak untuk melakukan approval ini!', 'danger');
            }
        } catch (Exception $e) {
            $db->rollBack();
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
            
            $deleteSql = "DELETE FROM di_accessories WHERE di_number = ?";
            $deleteStmt = $db->prepare($deleteSql);
            $deleteStmt->execute([$di_number]);
            
            $nos = $_POST['no'] ?? [];
            $uraians = $_POST['uraian'] ?? [];
            $satuans = $_POST['satuan'] ?? [];
            $jumlahs = $_POST['jumlah'] ?? [];
            $keterangans = $_POST['keterangan'] ?? [];
            
            foreach ($nos as $index => $no) {
                if (!empty($no) || !empty($uraians[$index])) {
                    $insertSql = "INSERT INTO di_accessories (di_number, no, uraian, satuan, jumlah, keterangan, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
                    $insertStmt = $db->prepare($insertSql);
                    $insertStmt->execute([
                        $di_number,
                        $no,
                        $uraians[$index] ?? '',
                        $satuans[$index] ?? '',
                        (int)($jumlahs[$index] ?? 0),
                        $keterangans[$index] ?? ''
                    ]);
                }
            }
            
            resetDIApprovalHistory($db, $di_number);
            $db->commit();
            setFlash('Data Aksesoris berhasil disimpan!', 'success');
        } catch (Exception $e) {
            $db->rollBack();
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
        .form-control {
            font-size: 13px;
            border-radius: 8px;
            border: 1px solid #e0e4ea;
            padding: 8px 12px;
        }
        .form-control:focus {
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

        .badge-status-di {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-status-di.pending { background: rgba(241, 196, 15, 0.15); color: #d4a017; }
        .badge-status-di.approved { background: rgba(52, 152, 219, 0.15); color: #2980b9; }
        .badge-status-di.rejected { background: rgba(231, 76, 60, 0.15); color: #c0392b; }

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

        .data-row {
            background: #fff;
            border: 1px solid #e0e4ea;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .data-row .data-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f2f5;
        }
        .data-row .data-header strong { color: #0e1a2b; font-size: 14px; }
        .data-row .data-header strong i { color: #ffd700; }

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
            <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        
        <?php if (in_array('produk', $menuNames)): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        
        <?php if (in_array('delivery_order', $menuNames)): ?>
            <a href="deliveryinstruction.php" class="nav-item active"><i class="fas fa-tractor"></i> Delivery</a>
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
                    <h4><span><i class="fas fa-tractor" style="color:#ffd700;"></i></span> Detail DI - <?= htmlspecialchars($di_number) ?></h4>
                </div>
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
                            <div class="col-md-3 mb-3">
                                <label class="form-label">No. DI</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($di_number) ?>" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Tanggal</label>
                                <input type="text" class="form-control" value="<?= date('d/m/Y', strtotime($request['request_date'])) ?>" readonly>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">No. SO *</label>
                                <input type="text" name="no_so" class="form-control" value="<?= htmlspecialchars($request['no_so']) ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
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
                        <div class="col-md-3">
                            <div class="info-label">No. DI</div>
                            <div class="info-value"><strong><?= htmlspecialchars($di_number) ?></strong></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Tanggal</div>
                            <div class="info-value"><?= date('d/m/Y', strtotime($request['request_date'])) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">No. SO</div>
                            <div class="info-value"><?= htmlspecialchars($request['no_so'] ?: '-') ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="info-label">Sales</div>
                            <div class="info-value"><?= htmlspecialchars($request['sales_name'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <!-- ============================================ -->
                <!-- APPROVAL SECTION (Di dalam Data Penjualan) -->
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
                
                <!-- APPROVAL HISTORY -->
                <h6 class="mt-3 mb-3"><i class="fas fa-history"></i> Riwayat Approval</h6>
                
                <?php if (count($approvalHistory) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered" style="font-size: 13px;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th>Level</th>
                                    <th>Approver</th>
                                    <th>Status</th>
                                    <th>Tanggal Approve</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($approvalHistory as $history): ?>
                                    <?php 
                                    $approverName = '';
                                    if (!empty($history['approved_by'])) {
                                        $stmtUser = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                                        $stmtUser->execute([$history['approved_by']]);
                                        $approverName = $stmtUser->fetchColumn();
                                    }
                                    ?>
                                    <tr>
                                        <td><?= $history['approval_order'] ?></td>
                                        <td>
                                            <?= htmlspecialchars($history['approval_label']) ?>
                                            <?php if ($history['status'] != 'pending' && !empty($approverName)): ?>
                                                <br><small class="text-muted">by: <?= htmlspecialchars($approverName) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($history['status'] == 'approved'): ?>
                                                <span class="badge-status-di approved"><i class="fas fa-check-circle"></i> Approved</span>
                                            <?php elseif ($history['status'] == 'rejected'): ?>
                                                <span class="badge-status-di rejected"><i class="fas fa-times-circle"></i> Rejected</span>
                                            <?php else: ?>
                                                <span class="badge-status-di pending"><i class="fas fa-clock"></i> Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($history['approved_at']): ?>
                                                <?= date('d/m/Y H:i', strtotime($history['approved_at'])) ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="fas fa-inbox me-2"></i> Belum ada riwayat approval
                    </div>
                <?php endif; ?>
                
                <!-- APPROVAL ACTION -->
                <?php if ($currentApprovalOrder > 0 && $currentApprovalOrder <= 6 && $request['status'] == 'pending'): ?>
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
                            <div class="card mb-3" style="border: 1px solid #e0e4ea; border-radius: 10px;">
                                <div class="card-header" style="background: #f8f9fa; border-bottom: 1px solid #e0e4ea; border-radius: 10px 10px 0 0; padding: 10px 15px;">
                                    <strong style="color: #0e1a2b;">
                                        <i class="fas fa-box" style="color: #ffd700;"></i> 
                                        Unit <?= $index + 1 ?>
                                    </strong>
                                </div>
                                <div class="card-body" style="padding: 15px;">
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

    </div>

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
            rowDiv.innerHTML = `
                <div class="data-header">
                    <strong><i class="fas fa-tools"></i> Aksesoris ${accessoryRowCount}</strong>
                    <button type="button" class="btn btn-danger-custom btn-sm" onclick="removeRow('accessoryRow_${accessoryRowCount}')">
                        <i class="fas fa-trash"></i> Hapus
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-2 mb-2">
                        <label class="form-label">No</label>
                        <input type="text" name="no[]" class="form-control" value="${data ? data.no : ''}">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Uraian</label>
                        <input type="text" name="uraian[]" class="form-control" value="${data ? data.uraian : ''}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Satuan</label>
                        <input type="text" name="satuan[]" class="form-control" value="${data ? data.satuan : ''}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Jumlah</label>
                        <input type="number" name="jumlah[]" class="form-control" min="0" value="${data ? data.jumlah : 0}">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Keterangan</label>
                        <input type="text" name="keterangan[]" class="form-control" value="${data ? data.keterangan : ''}">
                    </div>
                </div>
            `;
            container.appendChild(rowDiv);
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
            <?php if (count($diUnits) > 0): ?>
                <?php foreach ($diUnits as $unit): ?>
                    addUnitRow({
                        lokasi_unit: '<?= addslashes($unit['lokasi_unit']) ?>',
                        cabang: '<?= addslashes($unit['cabang']) ?>',
                        kode_unit: '<?= addslashes($unit['kode_unit']) ?>',
                        brand: '<?= addslashes($unit['brand']) ?>',
                        tipe: '<?= addslashes($unit['tipe']) ?>',
                        serial_number: '<?= addslashes($unit['serial_number']) ?>',
                        engine_number: '<?= addslashes($unit['engine_number']) ?>',
                        keterangan: '<?= addslashes($unit['keterangan']) ?>'
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addUnitRow();
            <?php endif; ?>
            
            <?php if (count($diAccessories) > 0): ?>
                <?php foreach ($diAccessories as $acc): ?>
                    addAccessoryRow({
                        no: '<?= addslashes($acc['no']) ?>',
                        uraian: '<?= addslashes($acc['uraian']) ?>',
                        satuan: '<?= addslashes($acc['satuan']) ?>',
                        jumlah: '<?= $acc['jumlah'] ?>',
                        keterangan: '<?= addslashes($acc['keterangan']) ?>'
                    });
                <?php endforeach; ?>
            <?php else: ?>
                addAccessoryRow();
            <?php endif; ?>
        });
    </script>
</body>
</html>