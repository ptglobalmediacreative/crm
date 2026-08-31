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
    $pattern = "%/GET-TR/JKT/{$bulanRomawi}/{$tahun}%";
    $stmt = $db->prepare("SELECT tr_number FROM activity_details WHERE tr_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        $parts = explode('/', $lastNumber);
        $nextSequence = (int)$parts[0] + 1;
        $sequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    } else {
        $sequence = '0001';
    }
    
    return "{$sequence}/GET-TR/JKT/{$bulanRomawi}/{$tahun}";
}

function generateDINumber($db) {
    $tahun = date('Y');
    $bulanRomawi = getBulanRomawi(date('n'));
    $pattern = "%/GET-DI/JKT/{$bulanRomawi}/{$tahun}%";
    $stmt = $db->prepare("SELECT di_number FROM activity_details WHERE di_number LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$pattern]);
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        $parts = explode('/', $lastNumber);
        $nextSequence = (int)$parts[0] + 1;
        $sequence = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
    } else {
        $sequence = '0001';
    }
    
    return "{$sequence}/GET-DI/JKT/{$bulanRomawi}/{$tahun}";
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
        if (strlen($deskripsi) < 80) $errors[] = 'Deskripsi minimal 80 karakter!';
        
        // Generate TR Number jika jenis_tugas = Negosiasi
        $tr_number = NULL;
        if ($jenis_tugas === 'Negosiasi') {
            $tr_number = generateTRNumber($db);
        }
        
        // Untuk Kontrak & After Sales, ambil TR Number dari Negosiasi sebelumnya
        if ($jenis_tugas === 'Kontrak' || $jenis_tugas === 'After Sales') {
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
            $stmt = $db->prepare("INSERT INTO activity_details (sales_activity_id, subject, jenis_tugas, deskripsi, due_date, tr_number, status) VALUES (?, ?, ?, ?, ?, ?, 'in_progress')");
            $stmt->execute([$leadsId, $subject, $jenis_tugas, $deskripsi, $due_date, $tr_number]);
            
            setFlash('Aktivitas berhasil ditambahkan!', 'success');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
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
        if (strlen($result) < 80) $errors[] = 'Result minimal 80 karakter!';
        
        // Ambil data detail untuk cek jenis_tugas
        $stmt = $db->prepare("SELECT * FROM activity_details WHERE id = ?");
        $stmt->execute([$detail_id]);
        $detail = $stmt->fetch();
        
        if (!$detail) {
            $errors[] = 'Data detail tidak ditemukan!';
        }
        
        // Jika jenis_tugas = Negosiasi, wajib isi customer_deal
        if ($detail && $detail['jenis_tugas'] === 'Negosiasi') {
            if (empty($customer_deal)) $errors[] = 'Customer Deal wajib dipilih!';
            // Generate DI Number hanya jika Customer Deal = Yes
            if ($customer_deal === 'Yes') {
                $di_number = generateDINumber($db);
            }
        }
        
        // Jika jenis_tugas = Kontrak atau After Sales, ambil TR & DI Number dari Negosiasi sebelumnya
        if ($detail && ($detail['jenis_tugas'] === 'Kontrak' || $detail['jenis_tugas'] === 'After Sales')) {
            $stmt = $db->prepare("SELECT tr_number, di_number, customer_deal FROM activity_details 
                                  WHERE sales_activity_id = ? AND jenis_tugas = 'Negosiasi' 
                                  ORDER BY id DESC LIMIT 1");
            $stmt->execute([$detail['sales_activity_id']]);
            $negosiasiData = $stmt->fetch();
            
            if ($negosiasiData) {
                $tr_number = $negosiasiData['tr_number'];
                
                if ($negosiasiData['customer_deal'] === 'Yes' && !empty($negosiasiData['di_number'])) {
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
            
            foreach ($_FILES['attachment_file']['name'] as $key => $filename) {
                if ($_FILES['attachment_file']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    if (in_array($file_extension, $allowed_extensions)) {
                        $new_filename = $target_dir . time() . '_' . uniqid() . '_' . $key . '.' . $file_extension;
                        move_uploaded_file($_FILES['attachment_file']['tmp_name'][$key], $new_filename);
                        $attachment_files[] = $new_filename;
                    } else {
                        $errors[] = 'Format file ' . $filename . ' tidak didukung!';
                    }
                }
            }
        }
        
        if (empty($attachment_files)) {
            $errors[] = 'Attachment File wajib diupload minimal 1 file!';
        }
        
        $attachment_file = !empty($attachment_files) ? implode(',', $attachment_files) : NULL;
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE activity_details SET result = ?, attachment_file = ?, customer_deal = ?, di_number = ?, tr_number = COALESCE(?, tr_number), status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->execute([$result, $attachment_file, $customer_deal, $di_number, $tr_number, $detail_id]);
            
            setFlash('Aktivitas berhasil diselesaikan!', 'success');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        } else {
            setFlash(implode('<br>', $errors), 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
    }
    
    if ($action === 'delete') {
        // Sales tidak bisa delete
        if ($role === 'sales' || !canDelete('sales_activity')) {
            setFlash('Anda tidak memiliki akses!', 'danger');
            redirect('detailaktivitas.php?leads_id=' . $leadsId);
        }
        
        $detail_id = (int)$_POST['detail_id'];
        $stmt = $db->prepare("DELETE FROM activity_details WHERE id = ?");
        $stmt->execute([$detail_id]);
        setFlash('Aktivitas berhasil dihapus!', 'success');
        redirect('detailaktivitas.php?leads_id=' . $leadsId);
    }
}

// ============================================
// AMBIL DATA DETAIL AKTIVITAS
// ============================================
$details = $db->prepare("SELECT * FROM activity_details WHERE sales_activity_id = ? ORDER BY created_at DESC");
$details->execute([$leadsId]);
$detailsList = $details->fetchAll();

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

        .info-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
            margin-bottom: 24px;
        }
        .info-card .info-item {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        .info-card .info-item:last-child { border-bottom: none; }
        .info-card .info-label { font-weight: 600; color: #555; width: 180px; flex-shrink: 0; font-size: 13px; }
        .info-card .info-value { color: #0e1a2b; font-size: 13px; }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02);
            border: 1px solid #e0e4ea;
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
            font-weight: 600;
            color: #0e1a2b;
            margin: 0;
            font-size: 16px;
        }
        .card-custom .card-header-custom h6 i {
            color: #ffd700;
            margin-right: 8px;
        }
        .card-custom .card-body-custom { padding: 0; overflow-x: auto; }
        
        .table-custom { margin-bottom: 0; font-size: 13px; }
        .table-custom th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #7f8c8d;
            border-bottom: 1px solid #f0f2f5;
            padding: 12px 16px;
            background: #fafafa;
            white-space: nowrap;
        }
        .table-custom td {
            padding: 12px 16px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        .table-custom tr:last-child td { border-bottom: none; }
        .table-custom tr:hover { background: #f8f9fa; }

        .badge-tugas {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-tugas.Perkenalan { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-tugas.Visit\/Meeting { background: rgba(155, 89, 182, 0.12); color: #8e44ad; }
        .badge-tugas.Prospecting { background: rgba(241, 196, 15, 0.12); color: #d4a017; }
        .badge-tugas.Negosiasi { background: rgba(231, 76, 60, 0.12); color: #c0392b; }
        .badge-tugas.Kontrak { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-tugas.After.Sales { background: rgba(26, 188, 156, 0.12); color: #16a085; }

        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-status.in_progress { background: rgba(52, 152, 219, 0.12); color: #2980b9; }
        .badge-status.completed { background: rgba(46, 204, 113, 0.12); color: #27ae60; }
        .badge-status.overdue { background: rgba(231, 76, 60, 0.12); color: #c0392b; }

        .btn-action {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            transition: all 0.3s ease;
            font-size: 13px;
            cursor: pointer;
        }
        .btn-action:hover { transform: scale(1.1); }
        .btn-action.detail { background: rgba(46, 204, 113, 0.1); color: #27ae60; }
        .btn-action.detail:hover { background: rgba(46, 204, 113, 0.2); }
        .btn-action.edit { background: rgba(52, 152, 219, 0.1); color: #2980b9; }
        .btn-action.edit:hover { background: rgba(52, 152, 219, 0.2); }
        .btn-action.delete { background: rgba(231, 76, 60, 0.1); color: #c0392b; }
        .btn-action.delete:hover { background: rgba(231, 76, 60, 0.2); }
        .btn-action.complete { background: rgba(46, 204, 113, 0.15); color: #27ae60; }
        .btn-action.complete:hover { background: rgba(46, 204, 113, 0.25); }

        .modal-content { border: none; border-radius: 12px; }
        .modal-header { border-bottom: 1px solid #f0f2f5; padding: 18px 24px; }
        .modal-header .modal-title { font-weight: 700; font-size: 18px; color: #0e1a2b; }
        .modal-header .modal-title i { color: #ffd700; margin-right: 8px; }
        .modal-body { padding: 20px 24px; }
        .modal-footer { border-top: 1px solid #f0f2f5; padding: 14px 24px; }

        .form-label { font-weight: 600; font-size: 13px; color: #333; }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 14px;
            border: 2px solid #e8edf2;
            transition: all 0.3s ease;
            font-size: 13px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1);
        }
        .form-control[readonly] { background: #f8f9fa; cursor: not-allowed; }

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

        .alert { border-radius: 10px; border: none; padding: 12px 16px; font-size: 14px; }

        .tr-number-display, .di-number-display {
            background: rgba(255, 215, 0, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            color: #d4a017;
            text-align: center;
            font-size: 14px;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }

        .info-negosiasi-container {
            background: #f8f9fa;
            border: 2px solid #ffd700;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .info-negosiasi-container h6 {
            color: #d4a017;
            font-weight: 700;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .info-negosiasi-container h6 i {
            margin-right: 8px;
        }

        .customer-deal-field, .di-number-field { display: none; }
        .customer-deal-field.show, .di-number-field.show { display: block; }

        .mobile-toggle { display: none; }
        .footer-text { text-align: center; padding: 16px 0 8px; color: #999; font-size: 11px; }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }

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

        @media (max-width: 480px) {
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .table-custom { font-size: 11px; }
            .table-custom th, .table-custom td { padding: 6px 8px; }
            .btn-action { width: 26px; height: 26px; font-size: 11px; }
            .info-card .info-item { flex-direction: column; }
            .info-card .info-label { width: 100%; font-size: 11px; color: #999; margin-bottom: 2px; }
            .info-card .info-value { font-size: 12px; }
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
            <a href="salesactivity.php" class="nav-item active"><i class="fas fa-chart-bar"></i> Sales Activity</a>
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
                    <h4><span><i class="fas fa-chart-bar" style="color:#ffd700;"></i></span> Detail Aktivitas</h4>
                </div>
            </div>
            <div class="d-flex gap-2">
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
                                            <span class="badge-tugas <?= str_replace('/', '\/', $detail['jenis_tugas']) ?>">
                                                <?= htmlspecialchars($detail['jenis_tugas']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($detail['tr_number'])): ?>
                                                <a href="detailtr.php?tr_number=<?= urlencode($detail['tr_number']) ?>" style="color: #2980b9; text-decoration: none; font-weight: 600;">
                                                    <?= htmlspecialchars($detail['tr_number']) ?>
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td><?= !empty($detail['di_number']) ? htmlspecialchars($detail['di_number']) : '-' ?></td>
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
                                                            <button class="btn-action edit" onclick="editDetail(<?= htmlspecialchars(json_encode($detail)) ?>)">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
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
                                    <td colspan="10" class="text-center py-4 text-muted">
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
                <form method="POST">
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
                                <option value="After Sales">After Sales</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Deskripsi <span class="text-danger">*</span> <small class="text-muted">(Minimal 80 karakter)</small></label>
                            <textarea name="deskripsi" id="deskripsi_add" class="form-control" rows="5" placeholder="Masukkan deskripsi minimal 80 karakter..." minlength="80" required></textarea>
                            <small class="text-muted" id="wordCountAdd">0 karakter</small>
                        </div>
                        
                        <div class="mb-3" id="trNumberFieldAdd" style="display: none;">
                            <label class="form-label">Transaction Request Form</label>
                            <div class="tr-number-display">
                                <?= generateTRNumber($db) ?>
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
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="detail_id" id="completeDetailId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Result <span class="text-danger">*</span> <small class="text-muted">(Minimal 80 karakter)</small></label>
                            <textarea name="result" id="result_complete" class="form-control" rows="5" placeholder="Masukkan result minimal 80 karakter..." minlength="80" required></textarea>
                            <small class="text-muted" id="wordCountComplete">0 karakter</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Attachment File <span class="text-danger">*</span> <small class="text-muted">(Bisa pilih banyak file)</small></label>
                            <input type="file" name="attachment_file[]" id="attachment_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" multiple required>
                            <small class="text-muted">Tahan tombol Ctrl untuk memilih banyak file (JPG, PNG, PDF, DOC, XLS)</small>
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
                                    <?= generateDINumber($db) ?>
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
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="detail_id" id="deleteDetailId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var negosiasiCompletedList = <?= json_encode(array_values($negosiasiCompleted)) ?>;
        
        document.getElementById('deskripsi_add').addEventListener('input', function() {
            var chars = this.value.length;
            document.getElementById('wordCountAdd').textContent = chars + ' karakter';
            if (chars < 80) {
                document.getElementById('wordCountAdd').style.color = '#e74c3c';
            } else {
                document.getElementById('wordCountAdd').style.color = '#27ae60';
            }
        });
        
        document.getElementById('result_complete').addEventListener('input', function() {
            var chars = this.value.length;
            document.getElementById('wordCountComplete').textContent = chars + ' karakter';
            if (chars < 80) {
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
            } else if (this.value === 'Kontrak' || this.value === 'After Sales') {
                trNumberField.style.display = 'none';
                negosiasiInfoAdd.style.display = 'block';
                
                var infoHtml = '';
                if (negosiasiCompletedList.length > 0) {
                    var lastNegosiasi = negosiasiCompletedList[negosiasiCompletedList.length - 1];
                    
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-link"></i>Data dari Negosiasi Sebelumnya</h6>';
                    
                    if (lastNegosiasi.tr_number) {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> <a href="detailtr.php?tr_number=' + encodeURIComponent(lastNegosiasi.tr_number) + '" style="color: #2980b9;">' + lastNegosiasi.tr_number + '</a></div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> -</div>';
                    }
                    
                    if (lastNegosiasi.di_number) {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> ' + lastNegosiasi.di_number + '</div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> -</div>';
                    }
                    
                    if (lastNegosiasi.customer_deal) {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> ' + lastNegosiasi.customer_deal + '</div>';
                    } else {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> -</div>';
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
                        <div class="info-value"><a href="detailtr.php?tr_number=${encodeURIComponent(data.tr_number)}" style="color: #2980b9; font-weight: 600;">${data.tr_number}</a></div>
                    </div>` : ''}
                    ${data.di_number ? `
                    <div class="info-item">
                        <div class="info-label">DI Number</div>
                        <div class="info-value"><strong>${data.di_number}</strong></div>
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
            
            if (data.jenis_tugas === 'Negosiasi') {
                document.getElementById('customerDealFieldComplete').style.display = 'block';
                document.getElementById('customer_deal_complete').required = true;
            }
            
            if (data.jenis_tugas === 'Kontrak' || data.jenis_tugas === 'After Sales') {
                var infoHtml = '';
                
                if (negosiasiCompletedList.length > 0) {
                    var lastNegosiasi = negosiasiCompletedList[negosiasiCompletedList.length - 1];
                    
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-link"></i>Data dari Negosiasi Sebelumnya</h6>';
                    
                    if (lastNegosiasi.tr_number) {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> <a href="detailtr.php?tr_number=' + encodeURIComponent(lastNegosiasi.tr_number) + '" style="color: #2980b9;">' + lastNegosiasi.tr_number + '</a></div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>TR Number:</strong> -</div>';
                    }
                    
                    if (lastNegosiasi.di_number) {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> ' + lastNegosiasi.di_number + '</div>';
                    } else {
                        infoHtml += '<div class="mb-2"><strong>DI Number:</strong> -</div>';
                    }
                    
                    if (lastNegosiasi.customer_deal) {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> ' + lastNegosiasi.customer_deal + '</div>';
                    } else {
                        infoHtml += '<div class="mb-0"><strong>Customer Deal:</strong> -</div>';
                    }
                    
                    infoHtml += '</div>';
                } else {
                    infoHtml += '<div class="info-negosiasi-container">';
                    infoHtml += '<h6><i class="fas fa-info-circle"></i>Data dari Negosiasi Sebelumnya</h6>';
                    infoHtml += '<div class="text-muted">Tidak ada data Negosiasi yang completed.</div>';
                    infoHtml += '</div>';
                }
                
                var modalBody = document.querySelector('#modalComplete .modal-body');
                var existingContainer = document.getElementById('negosiasiInfoContainer');
                if (existingContainer) {
                    existingContainer.remove();
                }
                
                var infoContainer = document.createElement('div');
                infoContainer.id = 'negosiasiInfoContainer';
                infoContainer.innerHTML = infoHtml;
                modalBody.appendChild(infoContainer);
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
        
        function editDetail(data) {
            alert('Fitur edit akan segera hadir!');
        }
    </script>
</body>
</html>