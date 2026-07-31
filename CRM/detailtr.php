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
requirePermission('detail_transaction_request', 'view');

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
// AMBIL DATA TRANSACTION REQUEST UNTUK DROPDOWN
// ============================================
if ($userRole === 'sales') {
    $stmt = $db->prepare("SELECT tr.id, tr.trf_number, tr.subject, a.nama_pt 
                          FROM transaction_requests tr 
                          LEFT JOIN accounts a ON tr.account_id = a.id 
                          WHERE tr.sales_id = ? AND tr.status = 'approved'
                          ORDER BY tr.created_at DESC");
    $stmt->execute([$userId]);
} else {
    $stmt = $db->prepare("SELECT tr.id, tr.trf_number, tr.subject, a.nama_pt 
                          FROM transaction_requests tr 
                          LEFT JOIN accounts a ON tr.account_id = a.id 
                          WHERE tr.status = 'approved'
                          ORDER BY tr.created_at DESC");
    $stmt->execute();
}
$trRequests = $stmt->fetchAll();

// ============================================
// AMBIL DATA PRODUK UNTUK DROPDOWN UNIT
// ============================================
try {
    $stmt = $db->query("SELECT id, nama_produk, kode_produk FROM products ORDER BY nama_produk");
    $produkList = $stmt->fetchAll();
} catch(PDOException $e) {
    $produkList = [];
    if (strpos($e->getMessage(), 'Base table or view not found') !== false) {
        setFlash('Tabel products belum dibuat. Silakan buat tabel products terlebih dahulu.', 'warning');
    }
}

// ============================================
// PROSES SIMPAN / UPDATE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'save') {
        $trf_number = bersihkan($_POST['trf_number']);
        $transaction_request_id = (int)$_POST['transaction_request_id'];
        $account_id = (int)$_POST['account_id'];
        
        // Data Customer
        $nama_pt = bersihkan($_POST['nama_pt']);
        $npwp = bersihkan($_POST['npwp']);
        $alamat = bersihkan($_POST['alamat']);
        $nama_pic = bersihkan($_POST['nama_pic']);
        $jabatan_pic = bersihkan($_POST['jabatan_pic']);
        $no_hp_pic = bersihkan($_POST['no_hp_pic']);
        $email_pic = bersihkan($_POST['email_pic']);
        
        // Detail Unit
        $units = [];
        if (isset($_POST['unit_name']) && is_array($_POST['unit_name'])) {
            for ($i = 0; $i < count($_POST['unit_name']); $i++) {
                if (!empty($_POST['unit_name'][$i])) {
                    $qty = (int)$_POST['qty'][$i];
                    $price = (float)str_replace(['.', ','], '', $_POST['price'][$i]);
                    $ppn_percent = 11;
                    $ppn = $price * ($ppn_percent / 100);
                    $grand_total_unit = ($price + $ppn) * $qty;
                    
                    // Ambil transaction type
                    $transaction_type = $_POST['transaction_type'][$i] ?? '';
                    if ($transaction_type === 'Other' && !empty($_POST['transaction_type_other'][$i])) {
                        $transaction_type = 'Other - ' . $_POST['transaction_type_other'][$i];
                    }
                    
                    $units[] = [
                        'unit_name' => $_POST['unit_name'][$i],
                        'qty' => $qty,
                        'price' => $price,
                        'ppn_percent' => $ppn_percent,
                        'ppn' => $ppn,
                        'grand_total' => $grand_total_unit,
                        'specification' => $_POST['specification'][$i] ?? '',
                        'additional_attachment' => $_POST['additional_attachment'][$i] ?? '',
                        'warranty' => $_POST['warranty'][$i] ?? '',
                        'machine_location' => $_POST['machine_location'][$i] ?? '',
                        'delivery_terms' => $_POST['delivery_terms'][$i] ?? '',
                        'delivery_schedule' => $_POST['delivery_schedule'][$i] ?? '',
                        'transaction_type' => $transaction_type
                    ];
                }
            }
        }
        
        // Term Of Payment
        $top = [
            'booking_fee' => (float)str_replace(['.', ','], '', $_POST['booking_fee'] ?? 0),
            'down_payments' => [],
            'installments' => [],
            'nominal_po_leasing' => (float)str_replace(['.', ','], '', $_POST['nominal_po_leasing'] ?? 0),
            'grand_total_top' => 0
        ];
        
        // Down Payments
        if (isset($_POST['dp_name']) && is_array($_POST['dp_name'])) {
            for ($i = 0; $i < count($_POST['dp_name']); $i++) {
                if (!empty($_POST['dp_name'][$i]) && !empty($_POST['dp_value'][$i])) {
                    $top['down_payments'][] = [
                        'name' => $_POST['dp_name'][$i],
                        'value' => (float)str_replace(['.', ','], '', $_POST['dp_value'][$i])
                    ];
                }
            }
        }
        
        // Installments
        if (isset($_POST['installment_name']) && is_array($_POST['installment_name'])) {
            for ($i = 0; $i < count($_POST['installment_name']); $i++) {
                if (!empty($_POST['installment_name'][$i]) && !empty($_POST['installment_value'][$i])) {
                    $top['installments'][] = [
                        'name' => $_POST['installment_name'][$i],
                        'value' => (float)str_replace(['.', ',''], '', $_POST['installment_value'][$i])
                    ];
                }
            }
        }
        
        // Calculate TOP Grand Total
        $top_grand_total = $top['booking_fee'] + $top['nominal_po_leasing'];
        foreach ($top['down_payments'] as $dp) {
            $top_grand_total += $dp['value'];
        }
        foreach ($top['installments'] as $inst) {
            $top_grand_total += $inst['value'];
        }
        $top['grand_total_top'] = $top_grand_total;
        
        // Additional Cost
        $additional_cost = [
            'insurance_ops' => (float)str_replace(['.', ','], '', $_POST['insurance_ops'] ?? 0),
            'insurance_cargo' => (float)str_replace(['.', ','], '', $_POST['insurance_cargo'] ?? 0),
            'delivery_cost' => (float)str_replace(['.', ','], '', $_POST['delivery_cost'] ?? 0),
            'free_part' => (float)str_replace(['.', ','], '', $_POST['free_part'] ?? 0),
            'free_service' => (float)str_replace(['.', ','], '', $_POST['free_service'] ?? 0),
            'mediator_fee' => (float)str_replace(['.', ','], '', $_POST['mediator_fee'] ?? 0),
            'others' => (float)str_replace(['.', ','], '', $_POST['others_cost'] ?? 0),
            'total_additional' => 0
        ];
        
        $additional_cost['total_additional'] = 
            $additional_cost['insurance_ops'] + 
            $additional_cost['insurance_cargo'] + 
            $additional_cost['delivery_cost'] + 
            $additional_cost['free_part'] + 
            $additional_cost['free_service'] + 
            $additional_cost['mediator_fee'] + 
            $additional_cost['others'];
        
        // Mediator Fee
        $mediator_fee = [
            'name' => bersihkan($_POST['mediator_name'] ?? ''),
            'id_card_no' => bersihkan($_POST['mediator_id_card'] ?? ''),
            'npwp_no' => bersihkan($_POST['mediator_npwp'] ?? ''),
            'bank_name' => bersihkan($_POST['mediator_bank'] ?? ''),
            'bank_account' => bersihkan($_POST['mediator_bank_account'] ?? ''),
            'amount' => (float)str_replace(['.', ','], '', $_POST['mediator_fee'] ?? 0)
        ];
        
        // Grand Total (dari units)
        $grand_total = 0;
        foreach ($units as $unit) {
            $grand_total += $unit['grand_total'];
        }
        
        // Status
        $status = $_POST['status'] ?? 'draft';
        
        // Cek apakah sudah ada
        $stmt = $db->prepare("SELECT id FROM detail_transaction_requests WHERE trf_number = ?");
        $stmt->execute([$trf_number]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update
            $stmt = $db->prepare("UPDATE detail_transaction_requests SET 
                                  transaction_request_id = ?,
                                  account_id = ?,
                                  nama_pt = ?,
                                  npwp = ?,
                                  alamat = ?,
                                  nama_pic = ?,
                                  jabatan_pic = ?,
                                  no_hp_pic = ?,
                                  email_pic = ?,
                                  units = ?,
                                  term_of_payment = ?,
                                  additional_cost = ?,
                                  mediator_fee = ?,
                                  grand_total = ?,
                                  status = ?
                                  WHERE trf_number = ?");
            $stmt->execute([
                $transaction_request_id,
                $account_id,
                $nama_pt,
                $npwp,
                $alamat,
                $nama_pic,
                $jabatan_pic,
                $no_hp_pic,
                $email_pic,
                json_encode($units),
                json_encode($top),
                json_encode($additional_cost),
                json_encode($mediator_fee),
                $grand_total,
                $status,
                $trf_number
            ]);
            setFlash('Data Detail TR berhasil diupdate!', 'success');
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO detail_transaction_requests 
                                  (trf_number, transaction_request_id, account_id, 
                                   nama_pt, npwp, alamat, nama_pic, jabatan_pic, no_hp_pic, email_pic,
                                   units, term_of_payment, additional_cost, mediator_fee, grand_total, status) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $trf_number,
                $transaction_request_id,
                $account_id,
                $nama_pt,
                $npwp,
                $alamat,
                $nama_pic,
                $jabatan_pic,
                $no_hp_pic,
                $email_pic,
                json_encode($units),
                json_encode($top),
                json_encode($additional_cost),
                json_encode($mediator_fee),
                $grand_total,
                $status
            ]);
            setFlash('Data Detail TR berhasil disimpan!', 'success');
        }
        redirect('detailtr.php?trf=' . $trf_number);
    }
    
    if ($action === 'submit') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("UPDATE detail_transaction_requests SET status = 'submitted' WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Detail TR berhasil disubmit!', 'success');
        redirect('detailtr.php');
    }
}

// ============================================
// AMBIL DATA DETAIL TR
// ============================================
$detailData = null;
$trf_number = isset($_GET['trf']) ? bersihkan($_GET['trf']) : '';

if (!empty($trf_number)) {
    $stmt = $db->prepare("SELECT dtr.*, tr.subject, tr.jenis_tugas, tr.description, tr.due_date, 
                          a.id as account_id, a.nama_pt as account_nama_pt, a.npwp as account_npwp, 
                          a.alamat as account_alamat, a.nama_pic as account_nama_pic, 
                          a.jabatan_pic as account_jabatan_pic, a.no_hp_pic as account_no_hp_pic, 
                          a.email_pic as account_email_pic, a.badan_usaha
                          FROM detail_transaction_requests dtr
                          LEFT JOIN transaction_requests tr ON dtr.transaction_request_id = tr.id
                          LEFT JOIN accounts a ON dtr.account_id = a.id
                          WHERE dtr.trf_number = ?");
    $stmt->execute([$trf_number]);
    $detailData = $stmt->fetch();
    
    // Jika belum ada data, buat data dari transaction_requests
    if (!$detailData) {
        $stmt = $db->prepare("SELECT tr.*, a.id as account_id, a.nama_pt, a.npwp, a.alamat, 
                              a.nama_pic, a.jabatan_pic, a.no_hp_pic, a.email_pic, a.badan_usaha
                              FROM transaction_requests tr
                              LEFT JOIN accounts a ON tr.account_id = a.id
                              WHERE tr.trf_number = ?");
        $stmt->execute([$trf_number]);
        $trData = $stmt->fetch();
        
        if ($trData) {
            // Buat data awal
            $detailData = [
                'id' => null,
                'trf_number' => $trData['trf_number'],
                'transaction_request_id' => $trData['id'],
                'account_id' => $trData['account_id'],
                'nama_pt' => $trData['nama_pt'],
                'npwp' => $trData['npwp'],
                'alamat' => $trData['alamat'],
                'nama_pic' => $trData['nama_pic'],
                'jabatan_pic' => $trData['jabatan_pic'],
                'no_hp_pic' => $trData['no_hp_pic'],
                'email_pic' => $trData['email_pic'],
                'units' => '[]',
                'term_of_payment' => '{"booking_fee":0,"down_payments":[],"installments":[],"nominal_po_leasing":0,"grand_total_top":0}',
                'additional_cost' => '{"insurance_ops":0,"insurance_cargo":0,"delivery_cost":0,"free_part":0,"free_service":0,"mediator_fee":0,"others":0,"total_additional":0}',
                'mediator_fee' => '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}',
                'grand_total' => 0,
                'status' => 'draft',
                'subject' => $trData['subject'],
                'jenis_tugas' => $trData['jenis_tugas'],
                'description' => $trData['description'],
                'due_date' => $trData['due_date'],
                'account_nama_pt' => $trData['nama_pt'],
                'account_npwp' => $trData['npwp'],
                'account_alamat' => $trData['alamat'],
                'account_nama_pic' => $trData['nama_pic'],
                'account_jabatan_pic' => $trData['jabatan_pic'],
                'account_no_hp_pic' => $trData['no_hp_pic'],
                'account_email_pic' => $trData['email_pic'],
                'badan_usaha' => $trData['badan_usaha']
            ];
        }
    }
}

// ============================================
// AMBIL DATA UNTUK VIEW
// ============================================
$requests = [];
if ($userRole === 'sales') {
    $stmt = $db->prepare("SELECT dtr.*, tr.subject, a.nama_pt 
                          FROM detail_transaction_requests dtr
                          LEFT JOIN transaction_requests tr ON dtr.transaction_request_id = tr.id
                          LEFT JOIN accounts a ON dtr.account_id = a.id
                          WHERE tr.sales_id = ?
                          ORDER BY dtr.created_at DESC");
    $stmt->execute([$userId]);
} else {
    $stmt = $db->prepare("SELECT dtr.*, tr.subject, a.nama_pt 
                          FROM detail_transaction_requests dtr
                          LEFT JOIN transaction_requests tr ON dtr.transaction_request_id = tr.id
                          LEFT JOIN accounts a ON dtr.account_id = a.id
                          ORDER BY dtr.created_at DESC");
    $stmt->execute();
}
$requests = $stmt->fetchAll();
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
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
        .top-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-header .header-left { display: flex; align-items: center; gap: 10px; }
        .top-header .header-left .logo-wrapper { width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .top-header .header-left .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .top-header .header-left .brand-text .brand-name { font-size: 13px; font-weight: 700; color: #fff; line-height: 1.2; }
        .top-header .header-left .brand-text .brand-name span { color: #ffd700; }
        .top-header .header-left .brand-text .brand-sub { font-size: 8px; color: rgba(255, 255, 255, 0.4); letter-spacing: 0.5px; text-transform: uppercase; }
        
        .top-header .header-right { display: flex; align-items: center; gap: 12px; }
        .top-header .header-right .notif-icon { position: relative; color: rgba(255, 255, 255, 0.6); font-size: 16px; cursor: pointer; }
        .top-header .header-right .notif-icon .badge-notif { position: absolute; top: -5px; right: -6px; background: #d63031; color: #fff; font-size: 8px; padding: 1px 5px; border-radius: 50%; min-width: 16px; text-align: center; }
        .top-header .header-right .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: rgba(255, 215, 0, 0.2); display: flex; align-items: center; justify-content: center; color: #ffd700; font-weight: 700; font-size: 13px; text-decoration: none; border: 2px solid rgba(255, 215, 0, 0.2); transition: border-color 0.3s ease; }
        .top-header .header-right .user-avatar:hover { border-color: #ffd700; }
        
        .welcome-banner {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 12px;
            padding: 16px 24px;
            color: #fff;
            margin-bottom: 16px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner .welcome-text .greeting { font-size: 12px; color: rgba(255, 255, 255, 0.4); font-weight: 400; }
        .welcome-banner .welcome-text h3 { font-weight: 700; font-size: 18px; margin: 2px 0 0; }
        .welcome-banner .welcome-text h3 span { color: #ffd700; }
        .welcome-banner .welcome-icon { font-size: 32px; color: rgba(255, 215, 0, 0.05); position: absolute; right: 15px; bottom: 10px; }
        
        .card-custom {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 20px;
        }
        
        .card-custom .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .card-custom .card-header-custom h6 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 14px;
        }
        
        .card-custom .card-header-custom h6 i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .card-custom .card-body-custom {
            padding: 20px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        
        .form-label .optional {
            font-weight: 400;
            color: #999;
            font-size: 11px;
        }
        
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
        
        .form-control[readonly] {
            background: #f8f9fa;
        }
        
        .btn-primary-custom {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border: none;
            border-radius: 8px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(26, 26, 46, 0.3);
            color: #fff;
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
        
        .btn-secondary-custom:hover {
            background: #e8edf2;
            color: #333;
        }
        
        .btn-success-custom {
            background: #27ae60;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-success-custom:hover {
            background: #219a52;
            color: #fff;
        }
        
        .btn-warning-custom {
            background: #f39c12;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-warning-custom:hover {
            background: #e67e22;
            color: #fff;
        }
        
        .btn-danger-custom {
            background: #e74c3c;
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        
        .btn-danger-custom:hover {
            background: #c0392b;
            color: #fff;
        }
        
        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .badge-status.draft { background: rgba(149, 165, 166, 0.15); color: #7f8c8d; }
        .badge-status.submitted { background: rgba(241, 196, 15, 0.15); color: #d4a017; }
        .badge-status.approved { background: rgba(52, 152, 219, 0.15); color: #2980b9; }
        .badge-status.rejected { background: rgba(231, 76, 60, 0.15); color: #c0392b; }
        .badge-status.completed { background: rgba(46, 204, 113, 0.15); color: #27ae60; }
        
        .badge-trf {
            background: rgba(52, 152, 219, 0.12);
            color: #2980b9;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        
        .unit-row {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e8edf2;
        }
        
        .unit-row .btn-remove-unit {
            color: #e74c3c;
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
        }
        
        .unit-row .btn-remove-unit:hover {
            color: #c0392b;
        }
        
        .dp-row, .installment-row {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .dp-row .form-control, .installment-row .form-control {
            flex: 1;
        }
        
        /* TAB STYLES */
        .nav-tabs-custom {
            border-bottom: 2px solid #e8edf2;
            padding: 0 20px;
            background: #f8f9fa;
            border-radius: 12px 12px 0 0;
        }
        
        .nav-tabs-custom .nav-link {
            border: none;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 13px;
            color: #999;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .nav-tabs-custom .nav-link:hover {
            color: #1a1a2e;
            background: transparent;
        }
        
        .nav-tabs-custom .nav-link.active {
            color: #ffd700;
            background: transparent;
        }
        
        .nav-tabs-custom .nav-link.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: #ffd700;
            border-radius: 3px 3px 0 0;
        }
        
        .nav-tabs-custom .nav-link i {
            margin-right: 6px;
        }
        
        .tab-content-custom {
            padding: 20px;
            background: #fff;
            border-radius: 0 0 12px 12px;
            border: 1px solid #e8edf2;
            border-top: none;
        }
        
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 0 env(safe-area-inset-bottom);
            z-index: 999;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.06);
        }
        
        .bottom-nav .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            padding: 3px 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            min-width: 45px;
        }
        
        .bottom-nav .nav-item .nav-icon { font-size: 17px; color: #999; transition: all 0.3s ease; }
        .bottom-nav .nav-item .nav-label { font-size: 8px; color: #999; font-weight: 500; margin-top: 2px; transition: all 0.3s ease; }
        .bottom-nav .nav-item.active .nav-icon { color: #ffd700; }
        .bottom-nav .nav-item.active .nav-label { color: #1a1a2e; font-weight: 600; }
        .bottom-nav .nav-item.active::before { content: ''; position: absolute; top: -2px; left: 50%; transform: translateX(-50%); width: 18px; height: 2px; background: #ffd700; border-radius: 0 0 2px 2px; }
        
        .desktop-nav-wrapper {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .desktop-nav-wrapper .brand-section { display: flex; align-items: center; gap: 12px; padding: 10px 0; }
        .desktop-nav-wrapper .brand-section .logo-wrapper { width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; overflow: hidden; flex-shrink: 0; }
        .desktop-nav-wrapper .brand-section .logo-wrapper img { width: 100%; height: 100%; object-fit: contain; }
        .desktop-nav-wrapper .brand-section .brand-text .brand-name { font-size: 15px; font-weight: 700; color: #fff; line-height: 1.2; }
        .desktop-nav-wrapper .brand-section .brand-text .brand-name span { color: #ffd700; }
        .desktop-nav-wrapper .brand-section .brand-text .brand-sub { font-size: 8px; color: rgba(255, 255, 255, 0.4); letter-spacing: 1px; text-transform: uppercase; }
        
        .desktop-nav-wrapper .desktop-menu { display: flex; align-items: center; gap: 4px; }
        .desktop-nav-wrapper .desktop-menu .nav-link { color: rgba(255, 255, 255, 0.6); padding: 8px 16px; display: flex; align-items: center; gap: 6px; text-decoration: none; font-size: 13px; font-weight: 500; border-radius: 8px; transition: all 0.3s ease; }
        .desktop-nav-wrapper .desktop-menu .nav-link:hover { color: #fff; background: rgba(255, 255, 255, 0.05); }
        .desktop-nav-wrapper .desktop-menu .nav-link.active { color: #ffd700; background: rgba(255, 215, 0, 0.08); }
        .desktop-nav-wrapper .desktop-menu .nav-link i { font-size: 14px; }
        
        .desktop-nav-wrapper .nav-right { display: flex; align-items: center; gap: 16px; }
        .desktop-nav-wrapper .nav-right .notif-icon { position: relative; color: rgba(255, 255, 255, 0.6); font-size: 17px; cursor: pointer; }
        .desktop-nav-wrapper .nav-right .notif-icon .badge-notif { position: absolute; top: -5px; right: -6px; background: #d63031; color: #fff; font-size: 8px; padding: 1px 5px; border-radius: 50%; min-width: 16px; text-align: center; }
        .desktop-nav-wrapper .nav-right .user-info { text-align: right; color: #fff; }
        .desktop-nav-wrapper .nav-right .user-info .name { font-weight: 600; font-size: 13px; line-height: 1.2; }
        .desktop-nav-wrapper .nav-right .user-info .role { font-size: 10px; color: rgba(255, 255, 255, 0.4); }
        .desktop-nav-wrapper .nav-right .user-avatar { width: 34px; height: 34px; border-radius: 50%; background: rgba(255, 215, 0, 0.2); display: flex; align-items: center; justify-content: center; color: #ffd700; font-weight: 700; font-size: 14px; text-decoration: none; border: 2px solid rgba(255, 215, 0, 0.2); transition: border-color 0.3s ease; }
        .desktop-nav-wrapper .nav-right .user-avatar:hover { border-color: #ffd700; }
        .desktop-nav-wrapper .nav-right .logout-btn { color: rgba(255, 255, 255, 0.5); padding: 5px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.3s ease; border: 1px solid rgba(255, 255, 255, 0.1); }
        .desktop-nav-wrapper .nav-right .logout-btn:hover { color: #ff6b6b; background: rgba(214, 48, 49, 0.1); border-color: rgba(214, 48, 49, 0.3); }
        
        .footer-text {
            text-align: center;
            padding: 16px 0 8px;
            color: #999;
            font-size: 11px;
        }
        
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }
        
        .section-title {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 16px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f2f5;
        }
        
        .section-title i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
        }
        
        .info-row {
            display: flex;
            padding: 8px 0;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-row .info-label {
            font-weight: 600;
            color: #555;
            width: 180px;
            flex-shrink: 0;
            font-size: 13px;
        }
        
        .info-row .info-value {
            color: #1a1a2e;
            font-size: 13px;
            word-break: break-word;
        }
        
        @media (min-width: 769px) {
            .bottom-nav { display: none !important; }
            body { padding-bottom: 0; }
            .top-header { display: none !important; }
        }
        
        @media (max-width: 768px) {
            .desktop-nav-wrapper { display: none !important; }
            body { padding-bottom: 65px; }
            .welcome-banner { padding: 14px 18px; }
            .welcome-banner .welcome-text h3 { font-size: 16px; }
            .welcome-banner .welcome-icon { display: none; }
            .card-custom .card-header-custom { padding: 12px 16px; }
            .card-custom .card-body-custom { padding: 15px; }
            .dp-row, .installment-row { flex-wrap: wrap; }
            .nav-tabs-custom .nav-link { padding: 10px 12px; font-size: 12px; }
            .info-row { flex-wrap: wrap; }
            .info-row .info-label { width: 100%; }
        }
        
        @media (max-width: 480px) {
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .unit-row { padding: 10px; }
            .nav-tabs-custom .nav-link { padding: 8px 10px; font-size: 11px; }
        }
    </style>
</head>
<body>

    <!-- DESKTOP NAVBAR -->
    <div class="desktop-nav-wrapper">
        <div class="brand-section">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Customer Relationship Management System</div>
            </div>
        </div>
        
        <div class="desktop-menu">
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            
            <?php if (canAccessMenu('account_management')): ?>
                <a href="account_management.php" class="nav-link">
                    <i class="fas fa-building"></i> Account
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('sales_activity')): ?>
                <a href="salesactivity.php" class="nav-link">
                    <i class="fas fa-chart-bar"></i> Sales Activity
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('transaction_request')): ?>
                <a href="transactionrequest.php" class="nav-link">
                    <i class="fas fa-file-signature"></i> TR Request
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('detail_transaction_request')): ?>
                <a href="detailtr.php" class="nav-link active">
                    <i class="fas fa-file-alt"></i> Detail TR
                </a>
            <?php endif; ?>
            
            <?php if (canAccessMenu('produk')): ?>
                <a href="produk.php" class="nav-link">
                    <i class="fas fa-box"></i> Produk
                </a>
            <?php endif; ?>
        </div>
        
        <div class="nav-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif">0</span>
            </div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= getRoleLabel($role) ?></div>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- MOBILE HEADER -->
    <header class="top-header">
        <div class="header-left">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Customer Relationship Management</div>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif">0</span>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main style="padding: 16px 20px 0; max-width: 1400px; margin: 0 auto;">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Detail Transaction Request</div>
                <h3>Detail Transaction Request Form</h3>
            </div>
            <i class="fas fa-file-alt welcome-icon"></i>
        </div>

        <?= showFlash() ?>

        <!-- SELECT TRF NUMBER -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-search"></i> Pilih Transaction Request</h6>
            </div>
            <div class="card-body-custom">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <select name="trf" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Pilih TRF Number --</option>
                            <?php foreach ($trRequests as $tr): ?>
                                <option value="<?= htmlspecialchars($tr['trf_number']) ?>" <?= ($trf_number == $tr['trf_number']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tr['trf_number']) ?> - <?= htmlspecialchars($tr['subject']) ?> (<?= htmlspecialchars($tr['nama_pt'] ?? '-') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <?php if (!empty($trf_number)): ?>
                            <a href="detailtr.php" class="btn btn-secondary-custom w-100">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
                <?php if (empty($trRequests)): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle"></i> Belum ada Transaction Request yang di-approve. Silakan buat di Sales Activity terlebih dahulu.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($detailData): ?>
        <form method="POST" id="formDetailTR">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $detailData['id'] ?? '' ?>">
            <input type="hidden" name="trf_number" value="<?= htmlspecialchars($detailData['trf_number']) ?>">
            <input type="hidden" name="transaction_request_id" value="<?= $detailData['transaction_request_id'] ?? '' ?>">
            <input type="hidden" name="account_id" value="<?= $detailData['account_id'] ?? '' ?>">
            <input type="hidden" name="status" id="formStatus" value="<?= $detailData['status'] ?? 'draft' ?>">

            <!-- TAB NAVIGATION -->
            <div class="card-custom" style="padding: 0; overflow: hidden;">
                <div class="nav-tabs-custom">
                    <ul class="nav nav-tabs" id="detailTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" type="button" role="tab">
                                <i class="fas fa-info-circle"></i> Summary
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="unit-tab" data-bs-toggle="tab" data-bs-target="#unit" type="button" role="tab">
                                <i class="fas fa-box"></i> Detail Unit
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="top-tab" data-bs-toggle="tab" data-bs-target="#top" type="button" role="tab">
                                <i class="fas fa-money-bill-wave"></i> Term Of Payment
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="additional-tab" data-bs-toggle="tab" data-bs-target="#additional" type="button" role="tab">
                                <i class="fas fa-plus-circle"></i> Additional Cost
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="mediator-tab" data-bs-toggle="tab" data-bs-target="#mediator" type="button" role="tab">
                                <i class="fas fa-user-tie"></i> Mediator Fee
                            </button>
                        </li>
                    </ul>
                </div>

                <div class="tab-content tab-content-custom" id="detailTabContent">
                    <!-- TAB 1: SUMMARY -->
                    <div class="tab-pane fade show active" id="summary" role="tabpanel">
                        <h5 class="section-title"><i class="fas fa-info-circle"></i> Summary</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">TR Number</span>
                                    <span class="info-value"><span class="badge-trf"><i class="fas fa-file-signature"></i> <?= htmlspecialchars($detailData['trf_number']) ?></span></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Nama PT</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_nama_pt'] ?? $detailData['nama_pt'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">No NPWP</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_npwp'] ?? $detailData['npwp'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Alamat</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_alamat'] ?? $detailData['alamat'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Nama PIC</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_nama_pic'] ?? $detailData['nama_pic'] ?? '-') ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Jabatan PIC</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_jabatan_pic'] ?? $detailData['jabatan_pic'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">No Telepon PIC</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_no_hp_pic'] ?? $detailData['no_hp_pic'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Email PIC</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['account_email_pic'] ?? $detailData['email_pic'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Subject</span>
                                    <span class="info-value"><?= htmlspecialchars($detailData['subject'] ?? '-') ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Due Date</span>
                                    <span class="info-value"><?= !empty($detailData['due_date']) ? date('d/m/Y', strtotime($detailData['due_date'])) : '-' ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <div class="info-row">
                                    <span class="info-label">Status</span>
                                    <span class="info-value">
                                        <span class="badge-status <?= $detailData['status'] ?? 'draft' ?>">
                                            <?= ucfirst($detailData['status'] ?? 'Draft') ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: DETAIL UNIT -->
                    <div class="tab-pane fade" id="unit" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="section-title mb-0"><i class="fas fa-box"></i> Detail Unit</h5>
                            <button type="button" class="btn btn-sm btn-primary-custom" onclick="addUnit()">
                                <i class="fas fa-plus"></i> Tambah Unit
                            </button>
                        </div>
                        <div id="unitContainer">
                            <?php 
                            $units = json_decode($detailData['units'] ?? '[]', true);
                            if (empty($units)) {
                                $units = [['unit_name' => '', 'qty' => 1, 'price' => 0, 'ppn_percent' => 11, 'ppn' => 0, 'grand_total' => 0, 'specification' => '', 'additional_attachment' => '', 'warranty' => '', 'machine_location' => '', 'delivery_terms' => '', 'delivery_schedule' => '', 'transaction_type' => '']];
                            }
                            $unitIndex = 0;
                            foreach ($units as $unit):
                            ?>
                            <div class="unit-row" data-index="<?= $unitIndex ?>">
                                <div class="row">
                                    <div class="col-md-12 text-end">
                                        <?php if (count($units) > 1): ?>
                                            <button type="button" class="btn-remove-unit" onclick="removeUnit(this)" title="Hapus Unit">
                                                <i class="fas fa-times-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                                        <select name="unit_name[]" class="form-select" required>
                                            <option value="">-- Pilih Unit --</option>
                                            <?php foreach ($produkList as $produk): ?>
                                                <option value="<?= htmlspecialchars($produk['nama_produk']) ?>" <?= ($unit['unit_name'] == $produk['nama_produk']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($produk['kode_produk'] ?? '') ?> - <?= htmlspecialchars($produk['nama_produk']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-2">
                                        <label class="form-label">QTY <span class="text-danger">*</span></label>
                                        <input type="number" name="qty[]" class="form-control qty" value="<?= $unit['qty'] ?? 1 ?>" min="1" required onchange="calculateUnit(this)">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Price (Non PPN) <span class="text-danger">*</span></label>
                                        <input type="text" name="price[]" class="form-control price" value="<?= number_format($unit['price'] ?? 0, 0, ',', '.') ?>" required oninput="calculateUnit(this)">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">PPN (11%)</label>
                                        <input type="text" name="ppn[]" class="form-control ppn" value="<?= number_format($unit['ppn'] ?? 0, 0, ',', '.') ?>" readonly>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label">Grand Total (Include PPN)</label>
                                        <input type="text" name="grand_total_unit[]" class="form-control grand-total-unit" value="<?= number_format($unit['grand_total'] ?? 0, 0, ',', '.') ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Spesification <span class="text-danger">*</span></label>
                                        <input type="text" name="specification[]" class="form-control" value="<?= htmlspecialchars($unit['specification'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Additional Attachment / Safety Devices</label>
                                        <input type="text" name="additional_attachment[]" class="form-control" value="<?= htmlspecialchars($unit['additional_attachment'] ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Warranty</label>
                                        <input type="text" name="warranty[]" class="form-control" value="<?= htmlspecialchars($unit['warranty'] ?? '') ?>">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Machine Location Works <span class="text-danger">*</span></label>
                                        <input type="text" name="machine_location[]" class="form-control" value="<?= htmlspecialchars($unit['machine_location'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Delivery Terms <span class="text-danger">*</span></label>
                                        <input type="text" name="delivery_terms[]" class="form-control" placeholder="Contoh: Loco Jakarta atau Franco Kalimantan" value="<?= htmlspecialchars($unit['delivery_terms'] ?? '') ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="form-label">Delivery Schedule Plan <span class="text-danger">*</span></label>
                                        <input type="date" name="delivery_schedule[]" class="form-control" value="<?= htmlspecialchars($unit['delivery_schedule'] ?? '') ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-12 mb-2">
                                        <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <select name="transaction_type[]" class="form-select transaction-type-select" required onchange="showOtherInput(this)">
                                                    <option value="">-- Pilih Transaction Type --</option>
                                                    <option value="Cash On Delivery" <?= ($unit['transaction_type'] == 'Cash On Delivery') ? 'selected' : '' ?>>Cash On Delivery</option>
                                                    <option value="Leasing" <?= ($unit['transaction_type'] == 'Leasing') ? 'selected' : '' ?>>Leasing</option>
                                                    <option value="Direct Credit" <?= ($unit['transaction_type'] == 'Direct Credit') ? 'selected' : '' ?>>Direct Credit</option>
                                                    <option value="Other" <?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false) ? 'selected' : '' ?>>Other</option>
                                                </select>
                                            </div>
                                            <div class="col-md-4" id="otherInputContainer_<?= $unitIndex ?>" style="display: <?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false) ? 'block' : 'none' ?>">
                                                <input type="text" name="transaction_type_other[]" class="form-control" placeholder="Spesifikasi Other" value="<?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false && strpos($unit['transaction_type'] ?? '', '-') !== false) ? trim(substr($unit['transaction_type'], strpos($unit['transaction_type'], '-') + 1)) : '' ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php 
                            $unitIndex++;
                            endforeach; 
                            ?>
                        </div>
                    </div>

                    <!-- TAB 3: TERM OF PAYMENT -->
                    <div class="tab-pane fade" id="top" role="tabpanel">
                        <h5 class="section-title"><i class="fas fa-money-bill-wave"></i> Term Of Payment</h5>
                        <?php 
                        $top = json_decode($detailData['term_of_payment'] ?? '{"booking_fee":0,"down_payments":[],"installments":[],"nominal_po_leasing":0,"grand_total_top":0}', true);
                        ?>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Booking Fee</label>
                                <input type="text" name="booking_fee" class="form-control top-input" value="<?= number_format($top['booking_fee'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Nominal PO Leasing</label>
                                <input type="text" name="nominal_po_leasing" class="form-control top-input" value="<?= number_format($top['nominal_po_leasing'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()">
                            </div>
                            <div class="col-md-6 mb-3 text-end">
                                <label class="form-label">Grand Total TOP</label>
                                <h4 class="text-success" id="grandTotalTOP">Rp <?= number_format($top['grand_total_top'] ?? 0, 0, ',', '.') ?></h4>
                                <input type="hidden" name="grand_total_top" id="grandTotalTOPHidden" value="<?= $top['grand_total_top'] ?? 0 ?>">
                            </div>
                        </div>

                        <!-- Down Payments -->
                        <div class="mb-3">
                            <label class="form-label">Down Payment</label>
                            <div id="dpContainer">
                                <?php 
                                $dpIndex = 0;
                                foreach ($top['down_payments'] ?? [] as $dp): 
                                ?>
                                <div class="dp-row">
                                    <input type="text" name="dp_name[]" class="form-control" placeholder="Nama DP (contoh: DP 1)" value="<?= htmlspecialchars($dp['name'] ?? '') ?>">
                                    <input type="text" name="dp_value[]" class="form-control top-input" placeholder="Nilai" value="<?= number_format($dp['value'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeDP(this)"><i class="fas fa-times"></i></button>
                                </div>
                                <?php 
                                $dpIndex++;
                                endforeach; 
                                ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary-custom mt-2" onclick="addDP()">
                                <i class="fas fa-plus"></i> Tambah DP
                            </button>
                        </div>

                        <!-- Installments -->
                        <div class="mb-3">
                            <label class="form-label">Angsuran</label>
                            <div id="installmentContainer">
                                <?php 
                                $instIndex = 0;
                                foreach ($top['installments'] ?? [] as $inst): 
                                ?>
                                <div class="installment-row">
                                    <input type="text" name="installment_name[]" class="form-control" placeholder="Nama Angsuran (contoh: Angsuran 1)" value="<?= htmlspecialchars($inst['name'] ?? '') ?>">
                                    <input type="text" name="installment_value[]" class="form-control top-input" placeholder="Nilai" value="<?= number_format($inst['value'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeInstallment(this)"><i class="fas fa-times"></i></button>
                                </div>
                                <?php 
                                $instIndex++;
                                endforeach; 
                                ?>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary-custom mt-2" onclick="addInstallment()">
                                <i class="fas fa-plus"></i> Tambah Angsuran
                            </button>
                        </div>
                    </div>

                    <!-- TAB 4: ADDITIONAL COST -->
                    <div class="tab-pane fade" id="additional" role="tabpanel">
                        <h5 class="section-title"><i class="fas fa-plus-circle"></i> Additional Cost / Machines</h5>
                        <?php 
                        $additional = json_decode($detailData['additional_cost'] ?? '{"insurance_ops":0,"insurance_cargo":0,"delivery_cost":0,"free_part":0,"free_service":0,"mediator_fee":0,"others":0,"total_additional":0}', true);
                        ?>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Insurance Ops</label>
                                <input type="text" name="insurance_ops" class="form-control additional-input" value="<?= number_format($additional['insurance_ops'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Insurance Cargo <span class="text-danger">*</span></label>
                                <input type="text" name="insurance_cargo" class="form-control additional-input" value="<?= number_format($additional['insurance_cargo'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Delivery Cost</label>
                                <input type="text" name="delivery_cost" class="form-control additional-input" value="<?= number_format($additional['delivery_cost'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Free Part</label>
                                <input type="text" name="free_part" class="form-control additional-input" value="<?= number_format($additional['free_part'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Free Service</label>
                                <input type="text" name="free_service" class="form-control additional-input" value="<?= number_format($additional['free_service'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Mediator Fee</label>
                                <input type="text" name="mediator_fee" class="form-control additional-input" id="mediatorFeeInput" value="<?= number_format($additional['mediator_fee'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional(); updateMediatorAmount()">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Others</label>
                                <input type="text" name="others_cost" class="form-control additional-input" value="<?= number_format($additional['others'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <label class="form-label">Total Additional Cost</label>
                                <h4 class="text-primary" id="totalAdditional">Rp <?= number_format($additional['total_additional'] ?? 0, 0, ',', '.') ?></h4>
                                <input type="hidden" name="total_additional" id="totalAdditionalHidden" value="<?= $additional['total_additional'] ?? 0 ?>">
                            </div>
                        </div>
                    </div>

                    <!-- TAB 5: MEDIATOR FEE -->
                    <div class="tab-pane fade" id="mediator" role="tabpanel">
                        <h5 class="section-title"><i class="fas fa-user-tie"></i> Data Mediator Fee</h5>
                        <?php 
                        $mediator = json_decode($detailData['mediator_fee'] ?? '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}', true);
                        ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" name="mediator_name" class="form-control" value="<?= htmlspecialchars($mediator['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">ID Card No <span class="text-danger">*</span></label>
                                <input type="text" name="mediator_id_card" class="form-control" value="<?= htmlspecialchars($mediator['id_card_no'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">NPWP No <span class="text-danger">*</span></label>
                                <input type="text" name="mediator_npwp" class="form-control" value="<?= htmlspecialchars($mediator['npwp_no'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Name <span class="text-danger">*</span></label>
                                <input type="text" name="mediator_bank" class="form-control" value="<?= htmlspecialchars($mediator['bank_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bank Account <span class="text-danger">*</span></label>
                                <input type="text" name="mediator_bank_account" class="form-control" value="<?= htmlspecialchars($mediator['bank_account'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Amount</label>
                                <input type="text" name="mediator_amount" id="mediatorAmount" class="form-control" value="<?= number_format($mediator['amount'] ?? 0, 0, ',', '.') ?>" readonly>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ACTION BUTTONS -->
            <div class="card-custom">
                <div class="card-body-custom text-center">
                    <button type="submit" class="btn btn-primary-custom" onclick="document.getElementById('formStatus').value='draft'">
                        <i class="fas fa-save"></i> Simpan Draft
                    </button>
                    
                    <?php if ($detailData['status'] == 'draft'): ?>
                        <button type="submit" class="btn btn-warning-custom" onclick="document.getElementById('formStatus').value='submitted'">
                            <i class="fas fa-paper-plane"></i> Submit
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($detailData['status'] == 'submitted' && ($isDirektur || $hasFullAccess)): ?>
                        <button type="submit" class="btn btn-success-custom" onclick="document.getElementById('formStatus').value='approved'">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="submit" class="btn btn-danger-custom" onclick="document.getElementById('formStatus').value='rejected'">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($detailData['status'] == 'approved' && ($userRole === 'sales' || $hasFullAccess)): ?>
                        <button type="submit" class="btn btn-success-custom" onclick="document.getElementById('formStatus').value='completed'">
                            <i class="fas fa-check-double"></i> Complete
                        </button>
                    <?php endif; ?>
                    
                    <a href="detailtr.php" class="btn btn-secondary-custom">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </a>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

    </main>

    <!-- BOTTOM NAVIGATION -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
            <i class="fas fa-th-large nav-icon"></i>
            <span class="nav-label">Home</span>
        </a>
        
        <?php if (canAccessMenu('account_management')): ?>
            <a href="account_management.php" class="nav-item">
                <i class="fas fa-building nav-icon"></i>
                <span class="nav-label">Account</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('sales_activity')): ?>
            <a href="salesactivity.php" class="nav-item">
                <i class="fas fa-chart-bar nav-icon"></i>
                <span class="nav-label">Sales Activity</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('transaction_request')): ?>
            <a href="transactionrequest.php" class="nav-item">
                <i class="fas fa-file-signature nav-icon"></i>
                <span class="nav-label">TR Request</span>
            </a>
        <?php endif; ?>
        
        <?php if (canAccessMenu('detail_transaction_request')): ?>
            <a href="detailtr.php" class="nav-item active">
                <i class="fas fa-file-alt nav-icon"></i>
                <span class="nav-label">Detail TR</span>
            </a>
        <?php endif; ?>
        
        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i>
            <span class="nav-label" style="color:#d63031;">Logout</span>
        </a>
    </nav>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // UNIT FUNCTIONS
        // ============================================
        let unitIndex = <?= $unitIndex ?? 0 ?>;

        function addUnit() {
            const container = document.getElementById('unitContainer');
            const template = `
            <div class="unit-row" data-index="${unitIndex}">
                <div class="row">
                    <div class="col-md-12 text-end">
                        <button type="button" class="btn-remove-unit" onclick="removeUnit(this)" title="Hapus Unit">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Unit <span class="text-danger">*</span></label>
                        <select name="unit_name[]" class="form-select" required>
                            <option value="">-- Pilih Unit --</option>
                            <?php foreach ($produkList as $produk): ?>
                                <option value="<?= htmlspecialchars($produk['nama_produk']) ?>">
                                    <?= htmlspecialchars($produk['kode_produk'] ?? '') ?> - <?= htmlspecialchars($produk['nama_produk']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">QTY <span class="text-danger">*</span></label>
                        <input type="number" name="qty[]" class="form-control qty" value="1" min="1" required onchange="calculateUnit(this)">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Price (Non PPN) <span class="text-danger">*</span></label>
                        <input type="text" name="price[]" class="form-control price" value="0" required oninput="calculateUnit(this)">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <label class="form-label">PPN (11%)</label>
                        <input type="text" name="ppn[]" class="form-control ppn" value="0" readonly>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">Grand Total (Include PPN)</label>
                        <input type="text" name="grand_total_unit[]" class="form-control grand-total-unit" value="0" readonly>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Spesification <span class="text-danger">*</span></label>
                        <input type="text" name="specification[]" class="form-control" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Additional Attachment / Safety Devices</label>
                        <input type="text" name="additional_attachment[]" class="form-control">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Warranty</label>
                        <input type="text" name="warranty[]" class="form-control">
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Machine Location Works <span class="text-danger">*</span></label>
                        <input type="text" name="machine_location[]" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Delivery Terms <span class="text-danger">*</span></label>
                        <input type="text" name="delivery_terms[]" class="form-control" placeholder="Contoh: Loco Jakarta atau Franco Kalimantan" required>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Delivery Schedule Plan <span class="text-danger">*</span></label>
                        <input type="date" name="delivery_schedule[]" class="form-control" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12 mb-2">
                        <label class="form-label">Transaction Type <span class="text-danger">*</span></label>
                        <div class="row">
                            <div class="col-md-4">
                                <select name="transaction_type[]" class="form-select transaction-type-select" required onchange="showOtherInput(this)">
                                    <option value="">-- Pilih Transaction Type --</option>
                                    <option value="Cash On Delivery">Cash On Delivery</option>
                                    <option value="Leasing">Leasing</option>
                                    <option value="Direct Credit">Direct Credit</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="otherInputContainer_${unitIndex}" style="display:none">
                                <input type="text" name="transaction_type_other[]" class="form-control" placeholder="Spesifikasi Other">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            `;
            container.insertAdjacentHTML('beforeend', template);
            unitIndex++;
        }

        function removeUnit(btn) {
            const row = btn.closest('.unit-row');
            if (document.querySelectorAll('.unit-row').length > 1) {
                row.remove();
            } else {
                alert('Minimal 1 unit harus ada!');
            }
        }

        function calculateUnit(input) {
            const row = input.closest('.unit-row');
            const qty = parseFloat(row.querySelector('.qty').value) || 0;
            const price = parseFloat(row.querySelector('.price').value.replace(/\./g, '').replace(/,/g, '')) || 0;
            const ppnPercent = 11;
            const ppn = price * (ppnPercent / 100);
            const grandTotal = (price + ppn) * qty;
            
            row.querySelector('.ppn').value = formatNumber(ppn);
            row.querySelector('.grand-total-unit').value = formatNumber(grandTotal);
        }

        // ============================================
        // TRANSACTION TYPE FUNCTIONS
        // ============================================
        function showOtherInput(select) {
            const row = select.closest('.unit-row');
            const index = row.dataset.index;
            const otherContainer = document.getElementById('otherInputContainer_' + index);
            
            if (select.value === 'Other') {
                otherContainer.style.display = 'block';
                const otherInput = otherContainer.querySelector('input');
                otherInput.required = true;
            } else {
                otherContainer.style.display = 'none';
                const otherInput = otherContainer.querySelector('input');
                otherInput.required = false;
                otherInput.value = '';
            }
        }

        // ============================================
        // TERM OF PAYMENT FUNCTIONS
        // ============================================
        function addDP() {
            const container = document.getElementById('dpContainer');
            const template = `
            <div class="dp-row">
                <input type="text" name="dp_name[]" class="form-control" placeholder="Nama DP (contoh: DP 1)">
                <input type="text" name="dp_value[]" class="form-control top-input" placeholder="Nilai" oninput="calculateTOP()">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeDP(this)"><i class="fas fa-times"></i></button>
            </div>
            `;
            container.insertAdjacentHTML('beforeend', template);
        }

        function removeDP(btn) {
            const row = btn.closest('.dp-row');
            if (document.querySelectorAll('.dp-row').length > 0) {
                row.remove();
                calculateTOP();
            }
        }

        function addInstallment() {
            const container = document.getElementById('installmentContainer');
            const template = `
            <div class="installment-row">
                <input type="text" name="installment_name[]" class="form-control" placeholder="Nama Angsuran (contoh: Angsuran 1)">
                <input type="text" name="installment_value[]" class="form-control top-input" placeholder="Nilai" oninput="calculateTOP()">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeInstallment(this)"><i class="fas fa-times"></i></button>
            </div>
            `;
            container.insertAdjacentHTML('beforeend', template);
        }

        function removeInstallment(btn) {
            const row = btn.closest('.installment-row');
            if (document.querySelectorAll('.installment-row').length > 0) {
                row.remove();
                calculateTOP();
            }
        }

        function calculateTOP() {
            let total = 0;
            
            const bookingFee = parseFloat(document.querySelector('input[name="booking_fee"]').value.replace(/\./g, '').replace(/,/g, '')) || 0;
            total += bookingFee;
            
            const poLeasing = parseFloat(document.querySelector('input[name="nominal_po_leasing"]').value.replace(/\./g, '').replace(/,/g, '')) || 0;
            total += poLeasing;
            
            document.querySelectorAll('input[name="dp_value[]"]').forEach(input => {
                total += parseFloat(input.value.replace(/\./g, '').replace(/,/g, '')) || 0;
            });
            
            document.querySelectorAll('input[name="installment_value[]"]').forEach(input => {
                total += parseFloat(input.value.replace(/\./g, '').replace(/,/g, '')) || 0;
            });
            
            document.getElementById('grandTotalTOP').textContent = 'Rp ' + formatNumber(total);
            document.getElementById('grandTotalTOPHidden').value = total;
        }

        // ============================================
        // ADDITIONAL COST FUNCTIONS
        // ============================================
        function calculateAdditional() {
            let total = 0;
            
            document.querySelectorAll('.additional-input').forEach(input => {
                const val = parseFloat(input.value.replace(/\./g, '').replace(/,/g, '')) || 0;
                total += val;
            });
            
            document.getElementById('totalAdditional').textContent = 'Rp ' + formatNumber(total);
            document.getElementById('totalAdditionalHidden').value = total;
        }

        function updateMediatorAmount() {
            const mediatorFee = parseFloat(document.getElementById('mediatorFeeInput').value.replace(/\./g, '').replace(/,/g, '')) || 0;
            document.getElementById('mediatorAmount').value = formatNumber(mediatorFee);
        }

        // ============================================
        // FORMAT NUMBER HELPER
        // ============================================
        function formatNumber(num) {
            return num.toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        // ============================================
        // AUTO FORMAT INPUT (Rupiah)
        // ============================================
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('price') || 
                e.target.classList.contains('top-input') || 
                e.target.classList.contains('additional-input') ||
                e.target.id === 'mediatorFeeInput') {
                let val = e.target.value.replace(/\./g, '').replace(/,/g, '');
                if (!isNaN(val) && val !== '') {
                    e.target.value = formatNumber(parseFloat(val) || 0);
                }
            }
        });

        // ============================================
        // INITIAL CALCULATIONS
        // ============================================
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.qty, .price').forEach(input => {
                const row = input.closest('.unit-row');
                if (row) {
                    calculateUnit(input);
                }
            });
            
            calculateTOP();
            calculateAdditional();
            updateMediatorAmount();
            
            // Tampilkan input Other jika sudah terpilih
            document.querySelectorAll('.transaction-type-select').forEach(select => {
                if (select.value === 'Other') {
                    showOtherInput(select);
                }
            });
            
            document.querySelectorAll('input[name="delivery_schedule[]"]').forEach(input => {
                if (!input.value) {
                    const date = new Date();
                    date.setDate(date.getDate() + 30);
                    input.value = date.toISOString().split('T')[0];
                }
            });
        });
    </script>
</body>
</html>