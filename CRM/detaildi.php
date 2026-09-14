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
    <link rel="stylesheet" href="css/navigation.css">
    
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

.content{margin-left:245px;width:calc(100% - 245px);padding:26px 28px 50px;min-height:calc(100vh - 72px);max-width:none}.page-header{display:flex;justify-content:space-between;align-items:center;gap:20px;margin-bottom:20px;flex-wrap:wrap}.page-header>div:first-child{display:flex;gap:12px;align-items:center}.page-header h4{display:flex;align-items:center;gap:10px;font-size:25px;line-height:1.1;font-weight:800;letter-spacing:-.4px;color:#f7f9ff;margin:0}.page-header h4 span{width:38px;height:38px;border-radius:11px;background:rgba(96,165,250,.10);border:1px solid rgba(96,165,250,.15);display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}.page-header h4 span i{font-size:15px;color:#60a5fa;margin:0}.page-header p{font-size:12px;color:var(--muted);margin-top:7px}.eyebrow{font-size:10px;color:#6f80a0;text-transform:uppercase;letter-spacing:1.6px;font-weight:700;margin-bottom:7px}
.stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}.stat-card{min-height:128px;background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:17px;box-shadow:0 18px 45px rgba(0,0,0,.18);padding:18px 19px;transition:.25s;color:#eaf0f8}.stat-card:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25);transform:translateY(-1px)}.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:0}.stat-icon.gold{background:rgba(212,160,23,.12);color:#e0b53d}.stat-icon.blue{background:rgba(59,130,246,.12);color:#60a5fa}.stat-icon.green{background:rgba(52,211,153,.12);color:#34d399}.stat-icon.red{background:rgba(251,113,133,.10);color:#fb7185}.stat-number{color:#f7f9ff;font-size:24px;font-weight:800;line-height:1;margin-top:15px;margin-bottom:6px}.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:.7px;font-weight:700;color:#70809b}
.card-custom{background:linear-gradient(145deg,rgba(12,23,43,.94),rgba(7,14,28,.96));border:1px solid var(--line);border-radius:16px;box-shadow:0 18px 45px rgba(0,0,0,.18);overflow:hidden;transition:.25s}.card-custom:hover{border-color:rgba(96,165,250,.35);box-shadow:0 20px 48px rgba(0,0,0,.25)}.card-header-custom{min-height:62px;padding:13px 17px;border-bottom:1px solid rgba(148,163,184,.10);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.card-header-custom h6{font-weight:700;color:#f7f9ff;margin:0;font-size:12px}.card-header-custom h6 i{color:#60a5fa;margin-right:8px}.card-header-custom form{display:flex;align-items:center;gap:7px}.card-header-custom input{height:36px;width:230px;background:#0a1427!important;border:1px solid rgba(148,163,184,.16)!important;color:#dbe5f5!important;border-radius:9px!important;font-size:11px}.card-header-custom input::placeholder{color:#52627d}.btn-primary-custom{background:linear-gradient(135deg,#3b82f6,#6366f1);border:0;border-radius:9px;padding:9px 15px;font-weight:700;font-size:11px;transition:.2s;color:#fff}.btn-primary-custom:hover{background:linear-gradient(135deg,#4f8df7,#6d70f3);transform:translateY(-1px);box-shadow:0 8px 22px rgba(59,130,246,.2);color:#fff}.btn-secondary-custom{background:#111d31;border:1px solid rgba(148,163,184,.13);border-radius:9px;padding:9px 15px;font-weight:600;font-size:11px;color:#8f9db4;transition:.2s}.btn-secondary-custom:hover{background:#17253d;color:#fff;border-color:rgba(148,163,184,.22)}
.border-bottom{border-color:rgba(148,163,184,.10)!important}.filter-buttons{display:flex;gap:8px;flex-wrap:wrap}.btn-filter{padding:7px 12px;border:1px solid rgba(148,163,184,.12);background:rgba(10,17,33,.85);border-radius:9px;color:#8492aa;text-decoration:none;font-size:10px;font-weight:600;transition:.2s}.btn-filter:hover{color:#fff;border-color:rgba(96,165,250,.25);background:#10203a}.btn-filter.active{background:rgba(37,99,235,.15);border-color:rgba(96,165,250,.3);color:#9fc5ff}.btn-filter .count{background:rgba(255,255,255,.05);padding:2px 6px;border-radius:10px;margin-left:4px}.card-body-custom{padding:0}.table-responsive{background:transparent;overflow-x:auto}.table-custom{margin-bottom:0!important;width:100%;min-width:1120px;font-size:10px;color:#cbd5e1;--bs-table-bg:transparent;--bs-table-color:#cbd5e1;--bs-table-border-color:transparent}.table-custom th{height:43px;font-weight:700;font-size:8.5px;text-transform:uppercase;letter-spacing:.6px;color:#66758f!important;border-bottom:1px solid rgba(148,163,184,.10)!important;padding:11px 13px!important;background:rgba(5,12,25,.48)!important;white-space:nowrap}.table-custom td{height:54px;padding:10px 13px!important;vertical-align:middle;border-bottom:1px solid rgba(148,163,184,.07)!important;color:#cbd5e1!important;background:transparent!important}.table-custom tbody tr{transition:.15s}.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important;color:#e8eef7!important}.table-custom tr:last-child td{border-bottom:none!important}.table-custom a{color:#60a5fa!important;text-decoration:none;font-weight:700}.table-custom a:hover{color:#93c5fd!important}.table-custom th:first-child,.table-custom td:first-child{width:48px;text-align:center}.table-custom th:last-child,.table-custom td:last-child{width:92px;text-align:center}.text-muted{color:#64748b!important}
.badge-status-di{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}.badge-status-di.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}.badge-status-di.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}.badge-status-di.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}.btn-pdf{background:rgba(52,211,153,.10);border:1px solid rgba(52,211,153,.15);border-radius:8px;padding:6px 10px;color:#6ee7b7;text-decoration:none;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;transition:.2s}.btn-pdf:hover{color:#a7f3d0;background:rgba(52,211,153,.14);transform:translateY(-1px)}.btn-pdf-disabled{background:rgba(100,116,139,.08);border:1px solid rgba(100,116,139,.12);border-radius:8px;padding:6px 10px;color:#59677d;display:inline-flex;align-items:center;gap:5px;font-size:9px;font-weight:700;cursor:not-allowed}
.card-footer{background:rgba(5,12,25,.35)!important;border-top:1px solid rgba(148,163,184,.08)!important}.pagination{gap:4px}.pagination .page-link{background:#0a1427;border:1px solid rgba(148,163,184,.12);color:#8492aa;border-radius:8px!important;font-size:9px;padding:6px 9px}.pagination .page-link:hover{background:#10203a;color:#fff;border-color:rgba(96,165,250,.25)}.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff;box-shadow:0 0 15px rgba(59,130,246,.22)}.alert{border-radius:10px;border:1px solid rgba(96,165,250,.14);padding:10px 13px;font-size:11px;background:#0c1830;color:#cbd5e1}.footer-text{text-align:center;color:#44536c;font-size:9px;margin-top:20px}.footer-text a{color:#6b7a94;text-decoration:none}.footer-text a:hover{color:#60a5fa}
html,body{scrollbar-color:rgba(96,165,250,.32) #060b18;scrollbar-width:thin}html::-webkit-scrollbar,body::-webkit-scrollbar{width:7px;height:7px}html::-webkit-scrollbar-track,body::-webkit-scrollbar-track{background:#060b18}html::-webkit-scrollbar-thumb,body::-webkit-scrollbar-thumb{background:rgba(96,165,250,.30);border-radius:999px;border:1px solid rgba(6,11,24,.9)}html::-webkit-scrollbar-thumb:hover,body::-webkit-scrollbar-thumb:hover{background:rgba(96,165,250,.48)}
@media(max-width:991px){.content{margin-left:0;width:100%;padding:24px 18px 40px}.stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.page-header{align-items:flex-start}}
@media(max-width:800px){.content{padding:20px 14px 40px}.page-header{align-items:flex-start;flex-direction:column}.table-custom{min-width:1120px}}
@media(max-width:520px){.content{padding:17px 10px 34px}.page-header h4{font-size:20px}.page-header h4 span{width:34px;height:34px;border-radius:9px}.stat-grid{gap:9px}.stat-card{min-height:110px;padding:13px;border-radius:14px}.stat-number{font-size:20px}.card-header-custom{align-items:flex-start;padding:13px}.card-header-custom form{width:100%}.card-header-custom input{flex:1;width:auto}.filter-buttons{overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.btn-filter{white-space:nowrap}}

/* DETAIL DI — VISUAL PARITY WITH ACCOUNT MANAGEMENT */
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
.badge-status-di{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:8px;font-weight:700;white-space:nowrap;border:1px solid transparent}
.badge-status-di.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}
.badge-status-di.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}
.badge-status-di.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}
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

@media(max-width:991px){.content{padding-top:24px}.page-header{margin-bottom:16px}.tab-nav{border-radius:13px}.tab-nav .nav-tabs .nav-link{padding:9px 11px}}
@media(max-width:520px){.content{padding:17px 10px 34px}.tab-nav{margin-bottom:14px}.tab-nav .nav-tabs .nav-link{font-size:9px;padding:8px 10px}.info-value{font-size:11px}}

/* FINAL DETAIL DI POLISH — KEEP SYSTEM/LOGIC UNCHANGED */
.content{padding-top:28px}
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
.badge-status-di{min-height:24px}
.alert{margin-bottom:18px}
.result-item-card{margin:0 0 12px;background:linear-gradient(145deg,rgba(10,20,39,.92),rgba(7,14,28,.96));border:1px solid rgba(148,163,184,.11);border-radius:12px;overflow:hidden;box-shadow:0 10px 24px rgba(0,0,0,.10);transition:.2s}.result-item-card:hover{border-color:rgba(96,165,250,.22);box-shadow:0 14px 30px rgba(0,0,0,.16)}.result-item-header{display:flex;align-items:center;justify-content:space-between;min-height:44px;padding:10px 14px;background:rgba(5,12,25,.42);border-bottom:1px solid rgba(148,163,184,.09)}.result-item-header strong{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:750;color:#e8eef8}.result-item-header strong i{color:#60a5fa;font-size:12px}.result-item-body{padding:14px}.result-item-body .row{--bs-gutter-x:18px}.result-item-body .info-label{margin-bottom:5px}.result-item-body .info-value{margin-bottom:13px;color:#dbe5f5}.cost-result-card .result-item-header strong i{color:#e0b53d}.support-result-card .result-item-header strong i{color:#60a5fa}.result-item-card:last-child{margin-bottom:0}
.rejection-notice{margin:0 0 20px;padding:16px;border:1px solid rgba(251,113,133,.22);border-radius:13px;background:linear-gradient(145deg,rgba(74,18,35,.32),rgba(10,20,39,.72));box-shadow:0 10px 26px rgba(0,0,0,.10)}
.rejection-notice-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding-bottom:11px;margin-bottom:13px;border-bottom:1px solid rgba(251,113,133,.13);font-size:11px;font-weight:800;color:#fecdd3}.rejection-notice-head div{display:flex;align-items:center;gap:8px}.rejection-notice-head i{color:#fb7185}.rejection-notice-head span{font-size:9px;font-weight:600;color:#8e9bb5}.rejection-notice-body{display:grid;grid-template-columns:minmax(0,1fr) 210px;gap:16px}.rejection-item{min-width:0}.rejection-label{display:block;margin-bottom:6px;font-size:8px;text-transform:uppercase;letter-spacing:.8px;color:#8e9bb5;font-weight:800}.rejection-comment{font-size:11px;line-height:1.65;color:#f1f5f9;white-space:normal;word-break:break-word}.rejection-item strong{font-size:11px;color:#e8eef7}.rejection-hint{margin-top:13px;padding-top:11px;border-top:1px solid rgba(148,163,184,.08);font-size:9px;line-height:1.55;color:#8e9bb5}.rejection-hint i{color:#fbbf24;margin-right:5px}.reject-modal{display:none;position:fixed;inset:0;z-index:2000;align-items:center;justify-content:center;padding:20px}.reject-modal.show{display:flex}.reject-modal-backdrop{position:absolute;inset:0;background:rgba(1,5,13,.78);backdrop-filter:blur(7px)}.reject-modal-dialog{position:relative;width:min(520px,100%);background:linear-gradient(145deg,#0c172b,#07101f);border:1px solid rgba(251,113,133,.22);border-radius:16px;box-shadow:0 30px 80px rgba(0,0,0,.48);overflow:hidden}.reject-modal-header{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:17px 18px;border-bottom:1px solid rgba(148,163,184,.10)}.reject-modal-header>div:first-child{display:flex;align-items:center;gap:11px}.reject-modal-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:rgba(251,113,133,.10);border:1px solid rgba(251,113,133,.16);color:#fb7185}.reject-modal-header h5{font-size:13px;font-weight:800;color:#f8fafc;margin:0}.reject-modal-header p{font-size:9px;color:#7f8da5;margin:3px 0 0;line-height:1.4}.reject-modal-close{width:32px;height:32px;border:1px solid rgba(148,163,184,.12);border-radius:8px;background:#0a1427;color:#8492aa;display:flex;align-items:center;justify-content:center}.reject-modal-body{padding:18px}.reject-modal-body>label{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.55px;color:#aab7ca;font-weight:800;margin-bottom:7px}.reject-modal-body>label span{color:#fb7185}.reject-modal-body textarea{min-height:120px;background:#071223!important}.reject-modal-note{margin-top:9px;font-size:9px;color:#71809a;line-height:1.5}.reject-modal-note i{color:#60a5fa;margin-right:5px}.reject-modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:14px 18px;border-top:1px solid rgba(148,163,184,.10);background:rgba(5,12,25,.30)}
@media(max-width:767px){.rejection-notice-body{grid-template-columns:1fr}.reject-modal{padding:12px}.reject-modal-dialog{border-radius:13px}}
@media(max-width:991px){
 .content{padding:22px 18px 40px}
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
 .content{padding:16px 10px 32px}
 .card-body-custom,.card-custom .card-body{padding:14px!important}
 .card-header-custom{min-height:58px}
 .card-header-custom h6{font-size:11px}
 .tab-nav .nav-tabs .nav-link{font-size:9px;gap:5px;padding:8px 10px}
}

/* SEARCHABLE UNIT COMBOBOX */
.unit-combobox{position:relative;width:100%;z-index:30}
.unit-combo-control{height:40px;display:flex;align-items:center;gap:9px;padding:0 12px;background:#081426;border:1px solid rgba(148,163,184,.16);border-radius:9px;cursor:text;transition:.2s}
.unit-combobox.is-open .unit-combo-control,.unit-combo-control:focus-within{border-color:rgba(96,165,250,.48);box-shadow:0 0 0 3px rgba(59,130,246,.10);background:#0b172c}
.unit-combo-control>i:first-child{color:#60a5fa;font-size:11px;flex:0 0 auto}
.unit-combo-chevron{color:#60708b;font-size:10px;transition:.2s}
.unit-combobox.is-open .unit-combo-chevron{transform:rotate(180deg);color:#60a5fa}
.unit-combo-dropdown{position:absolute;left:0;right:0;top:calc(100% + 6px);padding:6px;background:#0b1425;border:1px solid rgba(148,163,184,.16);border-radius:10px;box-shadow:0 18px 38px rgba(0,0,0,.35);opacity:0;visibility:hidden;transform:translateY(-4px);transition:.16s;max-height:260px;overflow-y:auto}
.unit-combobox.is-open .unit-combo-dropdown{opacity:1;visibility:visible;transform:translateY(0)}
.unit-combo-option{width:100%;min-height:36px;display:flex;align-items:center;text-align:left;padding:8px 10px;border:0;border-radius:7px;background:transparent;color:#cbd6e8;font:600 11px Inter,Arial,sans-serif;cursor:pointer}
.unit-combo-option:hover{background:rgba(96,165,250,.10);color:#fff}
.unit-combo-option span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.unit-combo-empty{display:none;padding:10px;color:#6f7d95;font-size:10px;text-align:center}
.unit-native-select{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important}
.unit-search-box{display:none!important}

/* DETAIL UNIT + TOP — EXPLICIT DARK FORM POLISH */
#addUnitForm,#topForm{
    background:linear-gradient(145deg,rgba(10,20,39,.96),rgba(7,14,28,.98))!important;
    border:1px solid rgba(148,163,184,.12)!important;
    border-radius:13px!important;
    padding:18px!important;
    box-shadow:0 14px 32px rgba(0,0,0,.14);
}
#addUnitForm .form-label,#topForm .form-label{color:#8e9bb5!important}
#addUnitForm .form-control,#addUnitForm .form-select,#topForm .form-control,#topForm .form-select{
    background:#081426!important;
    border:1px solid rgba(148,163,184,.16)!important;
    color:#dbe5f5!important;
}
#addUnitForm .form-control:focus,#addUnitForm .form-select:focus,#topForm .form-control:focus,#topForm .form-select:focus{
    background:#0b172c!important;color:#fff!important;border-color:rgba(96,165,250,.45)!important;
    box-shadow:0 0 0 3px rgba(59,130,246,.10)!important;
}
#addUnitForm .form-select option,#topForm .form-select option{background:#0b1222!important;color:#dbe5f5!important}
.unit-select-wrap{display:flex;flex-direction:column;gap:7px}
.unit-search-box{height:38px;display:flex;align-items:center;gap:9px;padding:0 11px;background:#071223;border:1px solid rgba(148,163,184,.15);border-radius:9px;transition:.2s}
.unit-search-box:focus-within{border-color:rgba(96,165,250,.45);box-shadow:0 0 0 3px rgba(59,130,246,.10)}
.unit-search-box i{font-size:11px;color:#60a5fa}
.unit-search-input{width:100%;height:100%;border:0;outline:0;background:transparent;color:#dbe5f5;font:500 11px Inter,Arial,sans-serif}
.unit-search-input::placeholder{color:#52627d}
#unit_id{min-height:38px}
.dp-row,.angsuran-row{padding:10px 0;margin:0 0 9px!important;background:rgba(7,18,34,.58);border:1px solid rgba(148,163,184,.08);border-radius:10px}
.dp-row>div,.angsuran-row>div{padding-left:7px;padding-right:7px}
#dpContainer,#angsuranContainer{padding:4px 0}
#topForm .form-label.fw-bold{color:#dbe5f5!important;font-size:11px;text-transform:none;letter-spacing:0}
@media(max-width:767px){.dp-row,.angsuran-row{padding:10px 8px}.dp-row>div,.angsuran-row>div{padding-left:5px;padding-right:5px}}
    </style>
<style id="detaildi-compat">
.badge-status-di.pending{background:rgba(251,191,36,.10);color:#fcd34d;border-color:rgba(251,191,36,.15)}
.badge-status-di.approved{background:rgba(52,211,153,.10);color:#6ee7b7;border-color:rgba(52,211,153,.15)}
.badge-status-di.rejected{background:rgba(251,113,133,.10);color:#fb7185;border-color:rgba(251,113,133,.15)}
.data-row{background:linear-gradient(145deg,rgba(10,20,39,.96),rgba(7,14,28,.98));border:1px solid rgba(148,163,184,.12);border-radius:12px;padding:15px;margin-bottom:14px;box-shadow:0 10px 25px rgba(0,0,0,.10)}
.data-row .data-header{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid rgba(148,163,184,.09)}
.data-row .data-header strong{color:#e8eef7;font-size:11px;font-weight:700}.data-row .data-header strong i{color:#60a5fa;margin-right:6px}
.unit-result-card{margin:0 0 14px;background:linear-gradient(145deg,rgba(10,20,39,.96),rgba(7,14,28,.98));border:1px solid rgba(148,163,184,.12);border-radius:12px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,.10);transition:.2s}.unit-result-card:hover{border-color:rgba(96,165,250,.24);box-shadow:0 14px 30px rgba(0,0,0,.16)}.unit-result-header{display:flex;align-items:center;min-height:46px;padding:10px 15px;background:rgba(5,12,25,.42);border-bottom:1px solid rgba(148,163,184,.09)}.unit-result-header strong{display:flex;align-items:center;gap:8px;color:#e8eef7;font-size:11px;font-weight:750}.unit-result-header strong i{color:#60a5fa;font-size:12px}.unit-result-body{padding:15px;background:transparent}.unit-result-body .info-value{color:#dbe5f5}.unit-result-body .info-label{color:#71809a}
</style>
</head>
<body>

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