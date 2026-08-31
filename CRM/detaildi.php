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
        'admin_sales' => 'Admin Sales',
        'logistik' => 'Logistik',
        'service_support' => 'Service Support',
        'part_support' => 'Part Support',
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

if (empty($di_number)) {
    setFlash('DI Number tidak ditemukan!', 'danger');
    redirect('deliveryinstruction.php');
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
// CEK HAK EDIT - HANYA ADMIN SALES YANG BISA EDIT
// ============================================
$canEdit = false;
$adminSalesRoles = ['admin', 'admin_sales']; // Role yang bisa edit DI

if (in_array($userRole, $adminSalesRoles)) {
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
    1 => ['role' => 'admin_sales', 'label' => 'Admin Sales'],
    2 => ['role' => 'logistik', 'label' => 'Logistik'],
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
            setFlash('Anda tidak memiliki hak untuk mengedit data ini! Hanya Admin Sales yang bisa.', 'danger');
        }
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // SAVE DATA PENJUALAN
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // APPROVE / REJECT
    if ($action === 'approve' || $action === 'reject') {
        try {
            $db->beginTransaction();
            $approvalStatus = $action === 'approve' ? 'approved' : 'rejected';
            $currentOrder = (int)($_POST['approval_order'] ?? 0);
            
            $canApprove = false;
            if ($currentOrder > 0 && $currentOrder <= 6) {
                $requiredRole = $approvalLevels[$currentOrder]['role'];
                if ($userRole == $requiredRole || ($userRole == 'admin' && in_array($requiredRole, ['admin_sales', 'logistik', 'service_support', 'part_support']))) {
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
                    $insertApproval = $db->prepare("INSERT INTO di_approval_history (di_number, approval_order, approval_role, approval_label, status, catatan, approved_by, created_at) VALUES (?, ?, ?, ?, ?, '', ?, NOW())");
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // SAVE UNITS
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // SAVE ACCESSORIES
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // SAVE LOGISTICS
    if ($action === 'save_logistics') {
        try {
            $db->beginTransaction();
            
            $lokasi_pengambilan = bersihkan($_POST['lokasi_pengambilan'] ?? '');
            $lokasi_pengiriman = bersihkan($_POST['lokasi_pengiriman'] ?? '');
            $transportir = bersihkan($_POST['transportir'] ?? '');
            $waktu_pengiriman = $_POST['waktu_pengiriman'] ?? null;
            $eta = $_POST['eta'] ?? null;
            
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
    }
    
    // SAVE PRODUCT SUPPORT
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
        redirect("detaildi.php?di_number=" . urlencode($di_number));
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

        .alert-info-custom {
            background: #e8f4fd;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 12px 16px;
            color: #004085;
            font-size: 13px;
        }

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

        <!-- STATUS BAR -->
        <div class="card-custom">
            <div class="card-body-custom" style="padding: 15px 24px;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge-status-di <?= $request['status'] ?> me-2">
                            <?php if ($request['status'] == 'pending'): ?>
                                <i class="fas fa-clock"></i> Pending
                            <?php elseif ($request['status'] == 'approved'): ?>
                                <i class="fas fa-check-circle"></i> Approved
                            <?php elseif ($request['status'] == 'rejected'): ?>
                                <i class="fas fa-times-circle"></i> Rejected
                            <?php endif; ?>
                        </span>
                        <strong style="color:#0e1a2b;">Current Approver:</strong> 
                        <span style="color:#d4a017;"><?= htmlspecialchars($currentApproverLabel) ?></span>
                    </div>
                    <div>
                        <strong style="color:#0e1a2b;">Next Approver:</strong> 
                        <span style="color:#2980b9;"><?= htmlspecialchars($nextApproverLabel) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- NOTIFIKASI HAK AKSES -->
        <?php if (!$canEdit && !$hasBeenApproved): ?>
        <div class="alert-info-custom mb-3">
            <i class="fas fa-info-circle"></i> 
            Hanya <strong>Admin Sales</strong> yang dapat mengedit data DI ini.
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- SECTION: DATA PENJUALAN -->
        <!-- ============================================ -->
        <?php include 'sections/di_data_penjualan.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: DATA CUSTOMER -->
        <!-- ============================================ -->
        <?php include 'sections/di_data_customer.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: DATA UNIT -->
        <!-- ============================================ -->
        <?php include 'sections/di_data_unit.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: AKSESORIS -->
        <!-- ============================================ -->
        <?php include 'sections/di_aksesoris.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: LOGISTIK -->
        <!-- ============================================ -->
        <?php include 'sections/di_logistik.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: PRODUCT SUPPORT -->
        <!-- ============================================ -->
        <?php include 'sections/di_product_support.php'; ?>

        <!-- ============================================ -->
        <!-- SECTION: APPROVAL -->
        <!-- ============================================ -->
        <?php include 'sections/di_approval.php'; ?>

    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // FUNGSI TOGGLE SECTION
        // ============================================
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
                if (!confirm('Yakin ingin me-reject DI ini?')) {
                    return;
                }
            }
            if (action === 'approve') {
                if (!confirm('Yakin ingin meng-approve DI ini?')) {
                    return;
                }
            }
            document.getElementById('approvalAction').value = action;
            document.getElementById('approvalForm').submit();
        }
        
        // ============================================
        // FUNGSI UNTUK UNIT
        // ============================================
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
        
        // ============================================
        // FUNGSI UNTUK AKSESORIS
        // ============================================
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
        
        // ============================================
        // FUNGSI UNTUK PRODUCT SUPPORT
        // ============================================
        function addInputRow(containerId, inputName) {
            const container = document.getElementById(containerId);
            const newInput = document.createElement('input');
            newInput.type = 'text';
            newInput.name = inputName;
            newInput.className = 'form-control mb-2';
            newInput.placeholder = inputName.replace('[]', '');
            container.appendChild(newInput);
        }
        
        // ============================================
        // FUNGSI UMUM
        // ============================================
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
        
        // ============================================
        // LOAD DATA SAAT HALAMAN DIMUAT
        // ============================================
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