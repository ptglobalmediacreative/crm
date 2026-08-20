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
// CEK USER UNTUK AKSES
// ============================================
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['role'] ?? 'user';
$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';

$fullAccessRoles = ['it_support', 'admin', 'finance', 'business', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$hasFullAccess = in_array($userRole, $fullAccessRoles);
$isDirektur = in_array($userRole, ['direktur_utama', 'direktur_sales', 'direktur_operasional']);

// ============================================
// AMBIL DATA PRODUK UNTUK DROPDOWN UNIT
// ============================================
try {
    $stmt = $db->query("SELECT id, nama_produk FROM products ORDER BY nama_produk");
    $produkList = $stmt->fetchAll();
} catch(PDOException $e) {
    $produkList = [];
}

// ============================================
// FUNGSI UNTUK MENDAPATKAN NAMA APPROVER
// ============================================
function getApproverName($db, $role) {
    try {
        $stmt = $db->prepare("SELECT full_name FROM users WHERE role = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$role]);
        $result = $stmt->fetch();
        return $result ? $result['full_name'] : '-';
    } catch(PDOException $e) {
        return '-';
    }
}

// ============================================
// FUNGSI CEK APAKAH USER BISA APPROVE
// ============================================
function canUserApprove($userRole, $approvalLevel, $status) {
    if ($status === 'rejected' || $status === 'completed') return false;
    
    $nextLevel = $approvalLevel + 1;
    
    $role_mapping = [
        1 => 'direktur_sales',
        2 => 'business',
        3 => 'direktur_operasional',
        4 => 'direktur_utama'
    ];
    
    $required_role = $role_mapping[$nextLevel] ?? null;
    
    if (!$required_role) return false;
    
    return ($userRole === $required_role);
}

// ============================================
// FUNGSI CEK APAKAH USER BISA REJECT
// ============================================
function canUserReject($userRole, $approvalLevel, $status) {
    if ($status === 'rejected' || $status === 'completed') return false;
    
    $nextLevel = $approvalLevel + 1;
    
    $role_mapping = [
        1 => 'direktur_sales',
        2 => 'business',
        3 => 'direktur_operasional',
        4 => 'direktur_utama'
    ];
    
    $required_role = $role_mapping[$nextLevel] ?? null;
    
    if (!$required_role) return false;
    
    return ($userRole === $required_role);
}

// ============================================
// PROSES SIMPAN / UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save') {
        $trf_number = isset($_POST['trf_number']) ? bersihkan($_POST['trf_number']) : '';
        $transaction_request_id = isset($_POST['transaction_request_id']) ? (int)$_POST['transaction_request_id'] : 0;
        $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
        
        $nama_pt = isset($_POST['nama_pt']) ? bersihkan($_POST['nama_pt']) : '';
        $npwp = isset($_POST['npwp']) ? bersihkan($_POST['npwp']) : '';
        $alamat = isset($_POST['alamat']) ? bersihkan($_POST['alamat']) : '';
        $nama_pic = isset($_POST['nama_pic']) ? bersihkan($_POST['nama_pic']) : '';
        $jabatan_pic = isset($_POST['jabatan_pic']) ? bersihkan($_POST['jabatan_pic']) : '';
        $no_hp_pic = isset($_POST['no_hp_pic']) ? bersihkan($_POST['no_hp_pic']) : '';
        $email_pic = isset($_POST['email_pic']) ? bersihkan($_POST['email_pic']) : '';
        
        // Units
        $units = [];
        if (isset($_POST['units_data']) && !empty($_POST['units_data'])) {
            $units = json_decode($_POST['units_data'], true);
            if (!is_array($units)) $units = [];
        }
        
        // Term Of Payment
        $top = [];
        if (isset($_POST['top_data']) && !empty($_POST['top_data'])) {
            $top = json_decode($_POST['top_data'], true);
            if (!is_array($top)) {
                $top = ['booking_fee'=>0,'booking_fee_remark'=>'','nominal_po_leasing'=>0,'nominal_po_leasing_remark'=>'','down_payments'=>[],'installments'=>[],'grand_total_top'=>0];
            }
        }
        
        // Additional Cost
        $additional_cost = [];
        if (isset($_POST['additional_data']) && !empty($_POST['additional_data'])) {
            $additional_cost = json_decode($_POST['additional_data'], true);
            if (!is_array($additional_cost)) {
                $additional_cost = ['insurance_ops'=>0,'insurance_ops_remark'=>'','insurance_cargo'=>0,'insurance_cargo_remark'=>'','delivery_cost'=>0,'delivery_cost_remark'=>'','free_part'=>0,'free_part_remark'=>'','free_service'=>0,'free_service_remark'=>'','mediator_fee'=>0,'mediator_fee_remark'=>'','others'=>0,'others_remark'=>'','total_additional'=>0];
            }
        }
        
        // Mediator Fee
        $mediator_fee = [];
        if (isset($_POST['mediator_data']) && !empty($_POST['mediator_data'])) {
            $mediator_fee = json_decode($_POST['mediator_data'], true);
            if (!is_array($mediator_fee)) {
                $mediator_fee = ['name'=>'','id_card_no'=>'','npwp_no'=>'','bank_name'=>'','bank_account'=>'','amount'=>0];
            }
        }
        
        $grand_total = 0;
        foreach ($units as $unit) {
            $grand_total += $unit['grand_total'] ?? 0;
        }
        
        $status = isset($_POST['status']) ? $_POST['status'] : 'draft';
        $approval_level = isset($_POST['approval_level']) ? (int)$_POST['approval_level'] : 0;
        
        $stmt = $db->prepare("SELECT id FROM detail_transaction_requests WHERE trf_number = ?");
        $stmt->execute([$trf_number]);
        $existing = $stmt->fetch();
        
        try {
            if ($existing) {
                $stmt = $db->prepare("UPDATE detail_transaction_requests SET 
                                      transaction_request_id = ?, account_id = ?,
                                      nama_pt = ?, npwp = ?, alamat = ?, nama_pic = ?, jabatan_pic = ?, no_hp_pic = ?, email_pic = ?,
                                      units = ?, term_of_payment = ?, additional_cost = ?, mediator_fee = ?, grand_total = ?, status = ?, approval_level = ?
                                      WHERE trf_number = ?");
                $stmt->execute([$transaction_request_id, $account_id, $nama_pt, $npwp, $alamat, $nama_pic, $jabatan_pic, $no_hp_pic, $email_pic, json_encode($units), json_encode($top), json_encode($additional_cost), json_encode($mediator_fee), $grand_total, $status, $approval_level, $trf_number]);
                setFlash('Data Detail TR berhasil diupdate!', 'success');
            } else {
                $stmt = $db->prepare("INSERT INTO detail_transaction_requests 
                                      (trf_number, transaction_request_id, account_id, nama_pt, npwp, alamat, nama_pic, jabatan_pic, no_hp_pic, email_pic, units, term_of_payment, additional_cost, mediator_fee, grand_total, status, approval_level) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$trf_number, $transaction_request_id, $account_id, $nama_pt, $npwp, $alamat, $nama_pic, $jabatan_pic, $no_hp_pic, $email_pic, json_encode($units), json_encode($top), json_encode($additional_cost), json_encode($mediator_fee), $grand_total, $status, $approval_level]);
                setFlash('Data Detail TR berhasil disimpan!', 'success');
            }
        } catch (PDOException $e) {
            setFlash('Terjadi kesalahan: ' . $e->getMessage(), 'danger');
        }
        
        redirect('detailtr.php?tr_number=' . urlencode($trf_number) . '&tab=' . ($_POST['active_tab'] ?? 'summary'));
    }
    
    if ($action === 'approve') {
        $id = (int)$_POST['id'];
        $tr_number = $_POST['tr_number'] ?? '';
        $current_level = isset($_POST['approval_level']) ? (int)$_POST['approval_level'] : 0;
        $new_level = $current_level + 1;
        
        if ($new_level >= 4) {
            $stmt = $db->prepare("UPDATE detail_transaction_requests SET approval_level = ?, status = 'completed' WHERE id = ?");
            $stmt->execute([$new_level, $id]);
            setFlash('TR berhasil di-approve dan selesai!', 'success');
        } else {
            $stmt = $db->prepare("UPDATE detail_transaction_requests SET approval_level = ?, status = 'in_progress' WHERE id = ?");
            $stmt->execute([$new_level, $id]);
            setFlash('TR berhasil di-approve!', 'success');
        }
        redirect('detailtr.php?tr_number=' . urlencode($tr_number));
    }
    
    if ($action === 'reject') {
        $id = (int)$_POST['id'];
        $tr_number = $_POST['tr_number'] ?? '';
        $stmt = $db->prepare("UPDATE detail_transaction_requests SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('TR ditolak!', 'danger');
        redirect('detailtr.php?tr_number=' . urlencode($tr_number));
    }
}

// ============================================
// AMBIL DATA DETAIL TR
// ============================================
$detailData = null;
$trf_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';

if (!empty($trf_number)) {
    $stmt = $db->prepare("SELECT dtr.*, 
                          ad.sales_activity_id, sa.leads_number, sa.sales_id,
                          a.id as acc_id, a.nama_pt as account_nama_pt, a.npwp as account_npwp, 
                          a.alamat as account_alamat, a.nama_pic as account_nama_pic, 
                          a.jabatan_pic as account_jabatan_pic, a.no_hp_pic as account_no_hp_pic, 
                          a.email_pic as account_email_pic, a.badan_usaha,
                          u.full_name as sales_name,
                          ad.jenis_tugas, ad.due_date, ad.deskripsi as description, ad.subject as activity_subject
                          FROM detail_transaction_requests dtr
                          LEFT JOIN activity_details ad ON ad.tr_number = dtr.trf_number
                          LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                          LEFT JOIN accounts a ON dtr.account_id = a.id
                          LEFT JOIN users u ON sa.sales_id = u.id
                          WHERE dtr.trf_number = ?");
    $stmt->execute([$trf_number]);
    $detailData = $stmt->fetch();
    
    if (!$detailData) {
        // Jika belum ada di detail_transaction_requests, coba ambil dari activity_details
        $stmt = $db->prepare("SELECT ad.*, sa.leads_number, sa.sales_id,
                              a.id as acc_id, a.nama_pt as account_nama_pt, a.npwp as account_npwp, 
                              a.alamat as account_alamat, a.nama_pic as account_nama_pic, 
                              a.jabatan_pic as account_jabatan_pic, a.no_hp_pic as account_no_hp_pic, 
                              a.email_pic as account_email_pic, a.badan_usaha,
                              u.full_name as sales_name
                              FROM activity_details ad
                              LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
                              LEFT JOIN accounts a ON sa.account_id = a.id
                              LEFT JOIN users u ON sa.sales_id = u.id
                              WHERE ad.tr_number = ?");
        $stmt->execute([$trf_number]);
        $adData = $stmt->fetch();
        
        if ($adData) {
            $detailData = [
                'id' => null,
                'trf_number' => $adData['tr_number'],
                'transaction_request_id' => null,
                'account_id' => $adData['acc_id'] ?? null,
                'nama_pt' => $adData['account_nama_pt'] ?? '',
                'npwp' => $adData['account_npwp'] ?? '',
                'alamat' => $adData['account_alamat'] ?? '',
                'nama_pic' => $adData['account_nama_pic'] ?? '',
                'jabatan_pic' => $adData['account_jabatan_pic'] ?? '',
                'no_hp_pic' => $adData['account_no_hp_pic'] ?? '',
                'email_pic' => $adData['account_email_pic'] ?? '',
                'units' => '[]',
                'term_of_payment' => '{"booking_fee":0,"booking_fee_remark":"","nominal_po_leasing":0,"nominal_po_leasing_remark":"","down_payments":[],"installments":[],"grand_total_top":0}',
                'additional_cost' => '{"insurance_ops":0,"insurance_ops_remark":"","insurance_cargo":0,"insurance_cargo_remark":"","delivery_cost":0,"delivery_cost_remark":"","free_part":0,"free_part_remark":"","free_service":0,"free_service_remark":"","mediator_fee":0,"mediator_fee_remark":"","others":0,"others_remark":"","total_additional":0}',
                'mediator_fee' => '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}',
                'grand_total' => 0,
                'status' => 'draft',
                'approval_level' => 0,
                'leads_number' => $adData['leads_number'] ?? null,
                'account_nama_pt' => $adData['account_nama_pt'] ?? '',
                'account_npwp' => $adData['account_npwp'] ?? '',
                'account_alamat' => $adData['account_alamat'] ?? '',
                'account_nama_pic' => $adData['account_nama_pic'] ?? '',
                'account_jabatan_pic' => $adData['account_jabatan_pic'] ?? '',
                'account_no_hp_pic' => $adData['account_no_hp_pic'] ?? '',
                'account_email_pic' => $adData['account_email_pic'] ?? '',
                'sales_name' => $adData['sales_name'] ?? '',
                'jenis_tugas' => $adData['jenis_tugas'] ?? '',
                'due_date' => $adData['due_date'] ?? '',
                'description' => $adData['deskripsi'] ?? '',
                'subject' => $adData['subject'] ?? ''
            ];
        }
    }
}

// ============================================
// VARIABEL UNTUK HTML
// ============================================
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';
$editMode = isset($_GET['edit']) ? $_GET['edit'] : null;

$units = json_decode($detailData['units'] ?? '[]', true);
if (!is_array($units)) $units = [];

$top = json_decode($detailData['term_of_payment'] ?? '{"booking_fee":0,"booking_fee_remark":"","nominal_po_leasing":0,"nominal_po_leasing_remark":"","down_payments":[],"installments":[],"grand_total_top":0}', true);
if (!is_array($top)) $top = ['booking_fee'=>0,'booking_fee_remark'=>'','nominal_po_leasing'=>0,'nominal_po_leasing_remark'=>'','down_payments'=>[],'installments'=>[],'grand_total_top'=>0];

$additional = json_decode($detailData['additional_cost'] ?? '{"insurance_ops":0,"insurance_ops_remark":"","insurance_cargo":0,"insurance_cargo_remark":"","delivery_cost":0,"delivery_cost_remark":"","free_part":0,"free_part_remark":"","free_service":0,"free_service_remark":"","mediator_fee":0,"mediator_fee_remark":"","others":0,"others_remark":"","total_additional":0}', true);
if (!is_array($additional)) $additional = ['insurance_ops'=>0,'insurance_ops_remark'=>'','insurance_cargo'=>0,'insurance_cargo_remark'=>'','delivery_cost'=>0,'delivery_cost_remark'=>'','free_part'=>0,'free_part_remark'=>'','free_service'=>0,'free_service_remark'=>'','mediator_fee'=>0,'mediator_fee_remark'=>'','others'=>0,'others_remark'=>'','total_additional'=>0];

$mediator = json_decode($detailData['mediator_fee'] ?? '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}', true);
if (!is_array($mediator)) $mediator = ['name'=>'','id_card_no'=>'','npwp_no'=>'','bank_name'=>'','bank_account'=>'','amount'=>0];

$hasAdditional = false;
foreach ($additional as $key => $val) {
    if ($key != 'total_additional' && is_numeric($val) && $val > 0) {
        $hasAdditional = true;
        break;
    }
}

$approvalLevel = isset($detailData['approval_level']) ? (int)$detailData['approval_level'] : 0;
$status = $detailData['status'] ?? 'draft';
$isRejected = ($status === 'rejected');
$isCompleted = ($status === 'completed');

$approvalSteps = [
    1 => ['label' => 'Direktur Sales', 'role' => 'direktur_sales'],
    2 => ['label' => 'Business', 'role' => 'business'],
    3 => ['label' => 'Direktur Operasional', 'role' => 'direktur_operasional'],
    4 => ['label' => 'Direktur Utama', 'role' => 'direktur_utama']
];

$canApprove = canUserApprove($userRole, $approvalLevel, $status);
$canReject = canUserReject($userRole, $approvalLevel, $status);

$units_json = json_encode($units, JSON_UNESCAPED_UNICODE);
$top_json = json_encode($top, JSON_UNESCAPED_UNICODE);
$additional_json = json_encode($additional, JSON_UNESCAPED_UNICODE);
$mediator_json = json_encode($mediator, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Detail Transaction Request - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; padding-bottom: 70px; }
        .sidebar {
            width: 260px; height: 100vh; background: #0e1a2b;
            position: fixed; top: 0; left: 0; bottom: 0;
            padding: 30px 20px; overflow-y: auto; z-index: 1000; transition: all 0.3s ease;
        }
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 215, 0, 0.3); border-radius: 10px; }
        .sidebar .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; text-decoration: none; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .sidebar .brand .logo-wrapper { width: 42px; height: 42px; }
        .sidebar .brand .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .sidebar .brand .brand-text h5 { font-weight: 800; margin: 0; color: #fff; font-size: 16px; }
        .sidebar .brand .brand-text h5 span { color: #ffd700; }
        .sidebar .brand .brand-text small { font-size: 10px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; }
        .sidebar .nav-item { display: flex; align-items: center; padding: 12px 16px; color: rgba(255,255,255,0.6); text-decoration: none; border-radius: 10px; margin-bottom: 5px; transition: all 0.2s ease; font-weight: 500; font-size: 14px; }
        .sidebar .nav-item i { width: 24px; font-size: 16px; margin-right: 12px; text-align: center; }
        .sidebar .nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .sidebar .nav-item.active { background: rgba(255, 215, 0, 0.1); color: #ffd700; box-shadow: inset 3px 0 0 #ffd700; }
        .sidebar .user-profile { margin-top: 30px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; gap: 12px; }
        .sidebar .user-profile .avatar { width: 42px; height: 42px; border-radius: 50%; background: linear-gradient(135deg, #1a1a2e, #16213e); color: #ffd700; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; border: 2px solid rgba(255,215,0,0.2); }
        .sidebar .user-profile .user-info .name { font-size: 14px; font-weight: 600; color: #fff; }
        .sidebar .user-profile .user-info .role { font-size: 12px; color: rgba(255,255,255,0.4); }
        .sidebar .logout-btn { display: block; text-align: center; margin-top: 15px; padding: 10px; border-radius: 10px; color: #e74c3c; text-decoration: none; font-weight: 600; font-size: 14px; background: rgba(231, 76, 60, 0.1); }
        .sidebar .logout-btn:hover { background: rgba(231, 76, 60, 0.2); }
        .main-content { margin-left: 260px; padding: 30px; width: 100%; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
        .page-header h4 { font-weight: 800; color: #0e1a2b; font-size: 24px; margin:0; }
        .page-header h4 span { color: #ffd700; }
        .card-custom { background: #fff; border-radius: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.02); border: 1px solid #e0e4ea; margin-bottom: 20px; }
        .card-custom .card-header-custom { padding: 20px 24px; border-bottom: 1px solid #f0f2f5; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
        .card-custom .card-header-custom h6 { font-weight: 600; color: #0e1a2b; margin: 0; font-size: 16px; }
        .card-custom .card-header-custom h6 i { color: #ffd700; margin-right: 8px; }
        .card-custom .card-body-custom { padding: 20px; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f2f5; }
        .info-row:last-child { border-bottom: none; }
        .info-row .info-label { font-weight: 600; color: #555; width: 180px; flex-shrink: 0; font-size: 13px; }
        .info-row .info-value { color: #0e1a2b; font-size: 13px; word-break: break-word; }
        .badge-status { padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
        .badge-status.draft { background: rgba(149,165,166,0.15); color: #7f8c8d; }
        .badge-status.in_progress { background: rgba(241,196,15,0.15); color: #d4a017; }
        .badge-status.completed { background: rgba(46,204,113,0.15); color: #27ae60; }
        .badge-status.rejected { background: rgba(231,76,60,0.15); color: #c0392b; }
        .badge-status.pending { background: rgba(241, 196, 15, 0.15); color: #d4a017; }
        .tr-number-link { color: #d4a017; text-decoration: none; font-weight: 700; font-size: 14px; letter-spacing: 0.5px; }
        .tr-number-link:hover { color: #b7950b; }
        .btn-primary-custom { background: #0e1a2b; border: none; border-radius: 8px; padding: 10px 24px; font-weight: 600; font-size: 13px; color: #fff; }
        .btn-primary-custom:hover { background: #1a2d4a; color: #fff; }
        .btn-secondary-custom { background: #f0f2f5; border: none; border-radius: 8px; padding: 10px 24px; font-weight: 600; font-size: 13px; color: #555; }
        .btn-secondary-custom:hover { background: #e8edf2; color: #333; }
        .btn-success-custom { background: #27ae60; border: none; border-radius: 8px; padding: 8px 16px; font-weight: 600; font-size: 13px; color: #fff; }
        .btn-success-custom:hover { background: #219a52; color: #fff; }
        .btn-danger-custom { background: #e74c3c; border: none; border-radius: 8px; padding: 8px 16px; font-weight: 600; font-size: 13px; color: #fff; }
        .btn-danger-custom:hover { background: #c0392b; color: #777; }
        .nav-tabs-custom { border-bottom: 2px solid #e8edf2; padding: 0 16px; background: #f8f9fa; border-radius: 12px 12px 0 0; }
        .nav-tabs-custom .nav-link { border: none; padding: 10px 16px; font-weight: 600; font-size: 13px; color: #999; text-decoration: none; display: inline-block; }
        .nav-tabs-custom .nav-link.active { color: #ffd700; }
        .nav-tabs-custom .nav-link i { margin-right: 6px; }
        .tab-content-custom { padding: 16px 20px 20px; background: #fff; border-radius: 0 0 12px 12px; border: 1px solid #e8edf2; border-top: none; }
        .section-title { font-weight: 700; color: #0e1a2b; font-size: 15px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #f0f2f5; }
        .section-title i { color: #ffd700; margin-right: 8px; }
        .summary-item { display: flex; padding: 6px 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 4px; border: 1px solid #e8edf2; }
        .summary-item .label { font-weight: 600; color: #555; width: 160px; flex-shrink: 0; font-size: 12px; }
        .summary-item .value { color: #0e1a2b; font-size: 12px; }
        .approval-card { background: #f8f9fa; border-radius: 12px; padding: 16px 20px; border-left: 4px solid #ffd700; margin-bottom: 16px; }
        .approval-card .approval-title { font-weight: 700; color: #0e1a2b; font-size: 14px; margin-bottom: 10px; }
        .approval-card .approval-title i { color: #ffd700; margin-right: 8px; }
        .approval-step { display: flex; align-items: center; gap: 10px; padding: 6px 0; }
        .approval-step .step-number { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; flex-shrink: 0; }
        .approval-step .step-number.done { background: #27ae60; color: #fff; }
        .approval-step .step-number.active { background: #ffd700; color: #0e1a2b; }
        .approval-step .step-number.pending { background: #e0e4ea; color: #999; }
        .approval-step .step-number.rejected { background: #e74c3c; color: #fff; }
        .approval-step .step-info { flex: 1; }
        .approval-step .step-info .step-label { font-weight: 600; font-size: 13px; color: #0e1a2b; }
        .approval-step .step-info .step-status { font-size: 11px; color: #999; }
        .approval-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e0e4ea; }
        .mobile-toggle { display: none; }
        .footer-text { text-align: center; padding: 16px 0 8px; color: #999; font-size: 11px; }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }
        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }
        @media (max-width: 991px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 20px; }
            .mobile-toggle { display: flex !important; background: #0e1a2b; border: none; width: 40px; height: 40px; border-radius: 8px; color: #ffd700; font-size: 20px; align-items: center; justify-content: center; }
        }
        @media (max-width: 480px) {
            .card-custom .card-header-custom { padding: 12px 16px; }
            .card-custom .card-body-custom { padding: 15px; }
            .nav-tabs-custom .nav-link { padding: 8px 12px; font-size: 12px; }
            .info-row { flex-direction: column; }
            .info-row .info-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .summary-item .label { width: 100%; }
            .approval-step .step-number { width: 24px; height: 24px; font-size: 10px; }
        }
    </style>
</head>
<body>

    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <a href="dashboard.php" class="brand">
            <div class="logo-wrapper"><img src="images/logo.webp" alt="GET"></div>
            <div class="brand-text">
                <h5>CUSTOMER <span>RELATIONSHIP</span></h5>
                <small>PT Ganda Elang Tangguh</small>
            </div>
        </a>
        <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
        <?php if (canAccessMenu('sales_activity')): ?>
            <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        <?php if (canAccessMenu('account_management')): ?>
            <a href="account_management.php" class="nav-item"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        <?php if (canAccessMenu('transaction_request')): ?>
            <a href="transactionrequest.php" class="nav-item active"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-item"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
        <div class="user-profile">
            <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div style="display:flex; gap:15px; align-items:center;">
                <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
                    <i class="fas fa-bars"></i>
                </button>
                <h4><span><i class="fas fa-file-signature" style="color:#ffd700;"></i></span> Detail Transaction Request</h4>
            </div>
            <a href="transactionrequest.php" class="btn btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
        </div>

        <?= showFlash() ?>

        <?php if ($detailData): ?>
        
        <!-- TR NUMBER DISPLAY -->
        <div class="card-custom" style="margin-bottom: 20px;">
            <div class="card-body-custom text-center">
                <label class="form-label mb-2">Transaction Request Number</label>
                <div><span class="tr-number-link"><?= htmlspecialchars($detailData['trf_number']) ?></span></div>
            </div>
        </div>

        <!-- APPROVAL CARD -->
        <div class="approval-card">
            <div class="approval-title">
                <i class="fas fa-check-circle"></i> Status Approval
                <?php if ($isRejected): ?>
                    <span class="badge-status rejected ms-2">Rejected</span>
                <?php elseif ($isCompleted): ?>
                    <span class="badge-status completed ms-2">Completed</span>
                <?php elseif ($approvalLevel >= 1): ?>
                    <span class="badge-status in_progress ms-2">In Progress</span>
                <?php else: ?>
                    <span class="badge-status pending ms-2">Pending</span>
                <?php endif; ?>
            </div>
            
            <?php foreach ($approvalSteps as $level => $step): ?>
                <?php
                $isDone = ($approvalLevel >= $level);
                $isActive = ($approvalLevel == $level - 1 && !$isRejected && !$isCompleted);
                $isRejectedStep = ($isRejected && $approvalLevel < $level);
                $stepClass = $isRejectedStep ? 'rejected' : ($isDone ? 'done' : ($isActive ? 'active' : 'pending'));
                
                if ($isRejectedStep) {
                    $statusText = '<span class="text-danger">Rejected</span>';
                } elseif ($isDone) {
                    $statusText = '<span class="text-success"><i class="fas fa-check-circle"></i> Approved</span>';
                } elseif ($isActive) {
                    $statusText = '<span class="text-warning"><i class="fas fa-clock"></i> Menunggu Persetujuan</span>';
                } else {
                    $statusText = '<span class="text-muted"><i class="fas fa-hourglass"></i> Pending</span>';
                }
                ?>
                <div class="approval-step">
                    <div class="step-number <?= $stepClass ?>">
                        <?php if ($isDone): ?><i class="fas fa-check"></i>
                        <?php elseif ($isRejectedStep): ?><i class="fas fa-times"></i>
                        <?php else: ?><?= $level ?><?php endif; ?>
                    </div>
                    <div class="step-info">
                        <div class="step-label"><?= $step['label'] ?></div>
                        <div class="step-status">
                            <?= $statusText ?>
                            <?php if ($isActive && !$isRejected && !$isCompleted): ?>
                                <span class="text-muted ms-2">(<?= getApproverName($db, $step['role']) ?>)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if ($canApprove || $canReject): ?>
            <div class="approval-actions">
                <?php if ($canApprove): ?>
                    <button type="button" class="btn btn-success-custom" onclick="approveTR(<?= $detailData['id'] ?>, '<?= htmlspecialchars($detailData['trf_number']) ?>', <?= $approvalLevel ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                <?php endif; ?>
                <?php if ($canReject): ?>
                    <button type="button" class="btn btn-danger-custom" onclick="rejectTR(<?= $detailData['id'] ?>, '<?= htmlspecialchars($detailData['trf_number']) ?>')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- SUMMARY INFO -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-info-circle"></i> Summary Informasi</h6>
            </div>
            <div class="card-body-custom">
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-row"><span class="info-label">Leads Number</span><span class="info-value"><?= htmlspecialchars($detailData['leads_number'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Nama PT</span><span class="info-value"><?= htmlspecialchars($detailData['account_nama_pt'] ?? $detailData['nama_pt'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">No NPWP</span><span class="info-value"><?= htmlspecialchars($detailData['account_npwp'] ?? $detailData['npwp'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Alamat</span><span class="info-value"><?= htmlspecialchars($detailData['account_alamat'] ?? $detailData['alamat'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Nama PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_nama_pic'] ?? $detailData['nama_pic'] ?? '-') ?></span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-row"><span class="info-label">Jabatan PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_jabatan_pic'] ?? $detailData['jabatan_pic'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">No Telepon PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_no_hp_pic'] ?? $detailData['no_hp_pic'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Email PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_email_pic'] ?? $detailData['email_pic'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Jenis Tugas</span><span class="info-value"><?= htmlspecialchars($detailData['jenis_tugas'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Sales</span><span class="info-value"><?= htmlspecialchars($detailData['sales_name'] ?? '-') ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="card-custom" style="padding: 0; overflow: hidden;">
            <div class="nav-tabs-custom">
                <ul class="nav nav-tabs" style="border-bottom: none;">
                    <li class="nav-item">
                        <a href="detailtr.php?tr_number=<?= urlencode($trf_number) ?>&tab=summary" class="nav-link <?= $activeTab == 'summary' ? 'active' : '' ?>">
                            <i class="fas fa-info-circle"></i> Summary
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="detailtr.php?tr_number=<?= urlencode($trf_number) ?>&tab=unit" class="nav-link <?= $activeTab == 'unit' ? 'active' : '' ?>">
                            <i class="fas fa-box"></i> Detail Unit
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="detailtr.php?tr_number=<?= urlencode($trf_number) ?>&tab=top" class="nav-link <?= $activeTab == 'top' ? 'active' : '' ?>">
                            <i class="fas fa-money-bill-wave"></i> Term Of Payment
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="detailtr.php?tr_number=<?= urlencode($trf_number) ?>&tab=additional" class="nav-link <?= $activeTab == 'additional' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i> Additional Cost
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="detailtr.php?tr_number=<?= urlencode($trf_number) ?>&tab=mediator" class="nav-link <?= $activeTab == 'mediator' ? 'active' : '' ?>">
                            <i class="fas fa-user-tie"></i> Mediator Fee
                        </a>
                    </li>
                </ul>
            </div>

            <div class="tab-content-custom">
                <?php if ($activeTab == 'unit'): ?>
                    <h5 class="section-title"><i class="fas fa-box"></i> Detail Unit</h5>
                    <?php if (!empty($units)): ?>
                        <?php foreach ($units as $unit): ?>
                        <div class="summary-item"><span class="label">Unit</span><span class="value"><strong><?= htmlspecialchars($unit['unit_name'] ?? '-') ?></strong></span></div>
                        <div class="summary-item"><span class="label">QTY</span><span class="value"><?= $unit['qty'] ?? 0 ?></span></div>
                        <div class="summary-item"><span class="label">Price</span><span class="value">Rp <?= number_format($unit['price'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Grand Total</span><span class="value"><strong>Rp <?= number_format($unit['grand_total'] ?? 0, 0, ',', '.') ?></strong></span></div>
                        <hr class="my-2">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">Belum ada data unit.</div>
                    <?php endif; ?>
                <?php elseif ($activeTab == 'top'): ?>
                    <h5 class="section-title"><i class="fas fa-money-bill-wave"></i> Term Of Payment</h5>
                    <?php if (!empty($top['down_payments']) || !empty($top['installments']) || $top['booking_fee'] > 0): ?>
                        <div class="summary-item"><span class="label">Booking Fee</span><span class="value">Rp <?= number_format($top['booking_fee'] ?? 0, 0, ',', '.') ?></span></div>
                        <?php foreach ($top['down_payments'] ?? [] as $dp): ?>
                        <div class="summary-item"><span class="label"><?= htmlspecialchars($dp['name'] ?? 'DP') ?></span><span class="value">Rp <?= number_format($dp['value'] ?? 0, 0, ',', '.') ?></span></div>
                        <?php endforeach; ?>
                        <?php foreach ($top['installments'] ?? [] as $inst): ?>
                        <div class="summary-item"><span class="label"><?= htmlspecialchars($inst['name'] ?? 'Angsuran') ?></span><span class="value">Rp <?= number_format($inst['value'] ?? 0, 0, ',', '.') ?></span></div>
                        <?php endforeach; ?>
                        <div class="summary-item" style="background: #d4edda;"><span class="label" style="font-weight:700;">Grand Total TOP</span><span class="value" style="font-weight:700;">Rp <?= number_format($top['grand_total_top'] ?? 0, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">Belum ada data TOP.</div>
                    <?php endif; ?>
                <?php elseif ($activeTab == 'additional'): ?>
                    <h5 class="section-title"><i class="fas fa-plus-circle"></i> Additional Cost</h5>
                    <?php if ($hasAdditional): ?>
                        <div class="summary-item"><span class="label">Insurance Ops</span><span class="value">Rp <?= number_format($additional['insurance_ops'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Insurance Cargo</span><span class="value">Rp <?= number_format($additional['insurance_cargo'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Delivery Cost</span><span class="value">Rp <?= number_format($additional['delivery_cost'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Free Part</span><span class="value">Rp <?= number_format($additional['free_part'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Free Service</span><span class="value">Rp <?= number_format($additional['free_service'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Mediator Fee</span><span class="value">Rp <?= number_format($additional['mediator_fee'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Others</span><span class="value">Rp <?= number_format($additional['others'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item" style="background: #cce5ff;"><span class="label" style="font-weight:700;">Total Additional</span><span class="value" style="font-weight:700;">Rp <?= number_format($additional['total_additional'] ?? 0, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">Belum ada data Additional Cost.</div>
                    <?php endif; ?>
                <?php elseif ($activeTab == 'mediator'): ?>
                    <h5 class="section-title"><i class="fas fa-user-tie"></i> Mediator Fee</h5>
                    <?php if (!empty($mediator['name'])): ?>
                        <div class="summary-item"><span class="label">Name</span><span class="value"><?= htmlspecialchars($mediator['name'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">ID Card No</span><span class="value"><?= htmlspecialchars($mediator['id_card_no'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">NPWP No</span><span class="value"><?= htmlspecialchars($mediator['npwp_no'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Bank Name</span><span class="value"><?= htmlspecialchars($mediator['bank_name'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Bank Account</span><span class="value"><?= htmlspecialchars($mediator['bank_account'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Amount</span><span class="value"><strong>Rp <?= number_format($mediator['amount'] ?? 0, 0, ',', '.') ?></strong></span></div>
                    <?php else: ?>
                        <div class="text-center text-muted py-3">Belum ada data Mediator.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <h5 class="section-title"><i class="fas fa-info-circle"></i> Ringkasan</h5>
                    <div class="summary-item"><span class="label">TR Number</span><span class="value"><?= htmlspecialchars($detailData['trf_number']) ?></span></div>
                    <div class="summary-item"><span class="label">Leads Number</span><span class="value"><?= htmlspecialchars($detailData['leads_number'] ?? '-') ?></span></div>
                    <div class="summary-item"><span class="label">Nama PT</span><span class="value"><?= htmlspecialchars($detailData['account_nama_pt'] ?? '-') ?></span></div>
                    <div class="summary-item"><span class="label">Sales</span><span class="value"><?= htmlspecialchars($detailData['sales_name'] ?? '-') ?></span></div>
                    <div class="summary-item"><span class="label">Status</span><span class="value"><span class="badge-status <?= $status ?>"><?= ucfirst($status) ?></span></span></div>
                    <div class="summary-item"><span class="label">Approval Level</span><span class="value"><?= $approvalLevel ?> / 4</span></div>
                    <div class="summary-item"><span class="label">Grand Total</span><span class="value"><strong>Rp <?= number_format($detailData['grand_total'] ?? 0, 0, ',', '.') ?></strong></span></div>
                    <div class="summary-item"><span class="label">Due Date</span><span class="value"><?= !empty($detailData['due_date']) ? date('d/m/Y', strtotime($detailData['due_date'])) : '-' ?></span></div>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> TR Number tidak ditemukan. Silakan pilih dari <a href="transactionrequest.php">Transaction Request</a>.
        </div>
        <?php endif; ?>

        <div class="footer-text">&copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM</div>

    </div>

    <!-- Form Approve -->
    <form method="POST" id="formApprove" style="display:none;">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" id="approveId">
        <input type="hidden" name="tr_number" id="approveTrNumber">
        <input type="hidden" name="approval_level" id="approveLevel">
    </form>

    <!-- Form Reject -->
    <form method="POST" id="formReject" style="display:none;">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" id="rejectId">
        <input type="hidden" name="tr_number" id="rejectTrNumber">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function approveTR(id, trNumber, currentLevel) {
            if (confirm('Apakah Anda yakin ingin menyetujui TR ini?')) {
                document.getElementById('approveId').value = id;
                document.getElementById('approveTrNumber').value = trNumber;
                document.getElementById('approveLevel').value = currentLevel;
                document.getElementById('formApprove').submit();
            }
        }
        function rejectTR(id, trNumber) {
            if (confirm('Apakah Anda yakin ingin menolak TR ini?')) {
                document.getElementById('rejectId').value = id;
                document.getElementById('rejectTrNumber').value = trNumber;
                document.getElementById('formReject').submit();
            }
        }
    </script>
</body>
</html>