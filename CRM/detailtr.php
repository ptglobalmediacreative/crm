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
    $stmt = $db->query("SELECT id, nama_produk FROM products ORDER BY nama_produk");
    $produkList = $stmt->fetchAll();
} catch(PDOException $e) {
    $produkList = [];
    if (strpos($e->getMessage(), 'Base table or view not found') !== false) {
        setFlash('Tabel products belum dibuat. Silakan buat tabel products terlebih dahulu.', 'warning');
    }
}

// ============================================
// FUNGSI UNTUK MENDAPATKAN STATUS APPROVAL
// ============================================
function getApprovalStatus($detailData) {
    if (!$detailData) return 'pending';
    
    $status = $detailData['status'] ?? 'draft';
    
    if ($status === 'completed') return 'success';
    if ($status === 'rejected') return 'rejected';
    
    $approval_level = isset($detailData['approval_level']) ? (int)$detailData['approval_level'] : 0;
    
    if ($approval_level >= 5) return 'success';
    if ($approval_level > 0) return 'in_progress';
    
    return 'pending';
}

function getCurrentApprover($approval_level) {
    $approvers = [
        1 => ['job_title' => 'Sales Manager', 'role' => 'sales_manager'],
        2 => ['job_title' => 'Direktur Sales', 'role' => 'direktur_sales'],
        3 => ['job_title' => 'Business', 'role' => 'business'],
        4 => ['job_title' => 'Direktur Operasional', 'role' => 'direktur_operasional'],
        5 => ['job_title' => 'Direktur Utama', 'role' => 'direktur_utama']
    ];
    return $approvers[$approval_level] ?? null;
}

function getNextApprover($approval_level) {
    $next_level = $approval_level + 1;
    $approvers = [
        1 => ['job_title' => 'Sales Manager', 'role' => 'sales_manager'],
        2 => ['job_title' => 'Direktur Sales', 'role' => 'direktur_sales'],
        3 => ['job_title' => 'Business', 'role' => 'business'],
        4 => ['job_title' => 'Direktur Operasional', 'role' => 'direktur_operasional'],
        5 => ['job_title' => 'Direktur Utama', 'role' => 'direktur_utama']
    ];
    return $approvers[$next_level] ?? null;
}

function getApproverName($db, $role) {
    try {
        $stmt = $db->prepare("SELECT full_name FROM users WHERE role = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$role]);
        $result = $stmt->fetch();
        return $result ? $result['full_name'] : '-';
    } catch(PDOException $e) {
        return '-';
    }
}

// ============================================
// TAMBAHKAN KOLOM APPROVAL_LEVEL JIKA BELUM ADA
// ============================================
try {
    $stmt = $db->query("SHOW COLUMNS FROM detail_transaction_requests LIKE 'approval_level'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE detail_transaction_requests ADD COLUMN approval_level INT DEFAULT 0");
    }
} catch(PDOException $e) {
    // Tabel mungkin belum ada
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
        
        // Data Customer - dengan pengecekan isset
        $nama_pt = isset($_POST['nama_pt']) ? bersihkan($_POST['nama_pt']) : '';
        $npwp = isset($_POST['npwp']) ? bersihkan($_POST['npwp']) : '';
        $alamat = isset($_POST['alamat']) ? bersihkan($_POST['alamat']) : '';
        $nama_pic = isset($_POST['nama_pic']) ? bersihkan($_POST['nama_pic']) : '';
        $jabatan_pic = isset($_POST['jabatan_pic']) ? bersihkan($_POST['jabatan_pic']) : '';
        $no_hp_pic = isset($_POST['no_hp_pic']) ? bersihkan($_POST['no_hp_pic']) : '';
        $email_pic = isset($_POST['email_pic']) ? bersihkan($_POST['email_pic']) : '';
        
        // ============================================
        // CEK DATA DARI HIDDEN FIELDS ATAU POST
        // ============================================
        
        // Detail Unit - PRIORITAS dari hidden fields, lalu POST, lalu database
        $units = [];
        if (isset($_POST['units_data']) && !empty($_POST['units_data'])) {
            $units = json_decode($_POST['units_data'], true);
            if (!is_array($units)) $units = [];
        }
        
        // Jika units kosong dan ada data POST dari form unit
        if (empty($units) && isset($_POST['unit_name']) && is_array($_POST['unit_name'])) {
            for ($i = 0; $i < count($_POST['unit_name']); $i++) {
                if (!empty($_POST['unit_name'][$i])) {
                    $qty = (int)$_POST['qty'][$i];
                    $price = (float)str_replace(['.', ','], '', $_POST['price'][$i]);
                    $ppn_percent = 11;
                    $ppn = $price * ($ppn_percent / 100);
                    $grand_total_unit = ($price + $ppn) * $qty;
                    
                    $transaction_type = isset($_POST['transaction_type'][$i]) ? $_POST['transaction_type'][$i] : '';
                    if ($transaction_type === 'Other' && isset($_POST['transaction_type_other'][$i]) && !empty($_POST['transaction_type_other'][$i])) {
                        $transaction_type = 'Other - ' . $_POST['transaction_type_other'][$i];
                    }
                    
                    $units[] = [
                        'unit_name' => $_POST['unit_name'][$i],
                        'qty' => $qty,
                        'price' => $price,
                        'ppn_percent' => $ppn_percent,
                        'ppn' => $ppn,
                        'grand_total' => $grand_total_unit,
                        'specification' => isset($_POST['specification'][$i]) ? $_POST['specification'][$i] : '',
                        'additional_attachment' => isset($_POST['additional_attachment'][$i]) ? $_POST['additional_attachment'][$i] : '',
                        'warranty' => isset($_POST['warranty'][$i]) ? $_POST['warranty'][$i] : '',
                        'machine_location' => isset($_POST['machine_location'][$i]) ? $_POST['machine_location'][$i] : '',
                        'delivery_terms' => isset($_POST['delivery_terms'][$i]) ? $_POST['delivery_terms'][$i] : '',
                        'delivery_schedule' => isset($_POST['delivery_schedule'][$i]) ? $_POST['delivery_schedule'][$i] : '',
                        'transaction_type' => $transaction_type
                    ];
                }
            }
        }
        
        // Jika masih kosong, ambil dari database
        if (empty($units)) {
            $units = json_decode($detailData['units'] ?? '[]', true);
            if (!is_array($units)) $units = [];
        }
        
        // Term Of Payment - PRIORITAS dari hidden fields, lalu POST, lalu database
        $top = [];
        if (isset($_POST['top_data']) && !empty($_POST['top_data'])) {
            $top = json_decode($_POST['top_data'], true);
            if (!is_array($top)) {
                $top = [
                    'booking_fee' => 0,
                    'booking_fee_remark' => '',
                    'nominal_po_leasing' => 0,
                    'nominal_po_leasing_remark' => '',
                    'down_payments' => [],
                    'installments' => [],
                    'grand_total_top' => 0
                ];
            }
        } else {
            // Proses dari POST
            $top = [
                'booking_fee' => (float)str_replace(['.', ','], '', isset($_POST['booking_fee']) ? $_POST['booking_fee'] : 0),
                'booking_fee_remark' => isset($_POST['booking_fee_remark']) ? bersihkan($_POST['booking_fee_remark']) : '',
                'nominal_po_leasing' => (float)str_replace(['.', ','], '', isset($_POST['nominal_po_leasing']) ? $_POST['nominal_po_leasing'] : 0),
                'nominal_po_leasing_remark' => isset($_POST['nominal_po_leasing_remark']) ? bersihkan($_POST['nominal_po_leasing_remark']) : '',
                'down_payments' => [],
                'installments' => [],
                'grand_total_top' => 0
            ];
            
            // Down Payments dengan Remark
            if (isset($_POST['dp_name']) && is_array($_POST['dp_name'])) {
                for ($i = 0; $i < count($_POST['dp_name']); $i++) {
                    if (!empty($_POST['dp_name'][$i]) && !empty($_POST['dp_value'][$i])) {
                        $top['down_payments'][] = [
                            'name' => $_POST['dp_name'][$i],
                            'value' => (float)str_replace(['.', ','], '', $_POST['dp_value'][$i]),
                            'remark' => isset($_POST['dp_remark'][$i]) ? bersihkan($_POST['dp_remark'][$i]) : ''
                        ];
                    }
                }
            }
            
            // Installments dengan Remark
            if (isset($_POST['installment_name']) && is_array($_POST['installment_name'])) {
                for ($i = 0; $i < count($_POST['installment_name']); $i++) {
                    if (!empty($_POST['installment_name'][$i]) && !empty($_POST['installment_value'][$i])) {
                        $top['installments'][] = [
                            'name' => $_POST['installment_name'][$i],
                            'value' => (float)str_replace(['.', ','], '', $_POST['installment_value'][$i]),
                            'remark' => isset($_POST['installment_remark'][$i]) ? bersihkan($_POST['installment_remark'][$i]) : ''
                        ];
                    }
                }
            }
            
            $top_grand_total = $top['booking_fee'] + $top['nominal_po_leasing'];
            foreach ($top['down_payments'] as $dp) {
                $top_grand_total += $dp['value'];
            }
            foreach ($top['installments'] as $inst) {
                $top_grand_total += $inst['value'];
            }
            $top['grand_total_top'] = $top_grand_total;
        }
        
        // Jika top kosong, ambil dari database
        if (empty($top) || (isset($top['booking_fee']) && $top['booking_fee'] == 0 && empty($top['down_payments']) && empty($top['installments']))) {
            $top = json_decode($detailData['term_of_payment'] ?? '{"booking_fee":0,"booking_fee_remark":"","nominal_po_leasing":0,"nominal_po_leasing_remark":"","down_payments":[],"installments":[],"grand_total_top":0}', true);
            if (!is_array($top)) {
                $top = [
                    'booking_fee' => 0,
                    'booking_fee_remark' => '',
                    'nominal_po_leasing' => 0,
                    'nominal_po_leasing_remark' => '',
                    'down_payments' => [],
                    'installments' => [],
                    'grand_total_top' => 0
                ];
            }
        }
        
        // Additional Cost - PRIORITAS dari hidden fields, lalu POST, lalu database
        $additional_cost = [];
        if (isset($_POST['additional_data']) && !empty($_POST['additional_data'])) {
            $additional_cost = json_decode($_POST['additional_data'], true);
            if (!is_array($additional_cost)) {
                $additional_cost = [
                    'insurance_ops' => 0,
                    'insurance_ops_remark' => '',
                    'insurance_cargo' => 0,
                    'insurance_cargo_remark' => '',
                    'delivery_cost' => 0,
                    'delivery_cost_remark' => '',
                    'free_part' => 0,
                    'free_part_remark' => '',
                    'free_service' => 0,
                    'free_service_remark' => '',
                    'mediator_fee' => 0,
                    'mediator_fee_remark' => '',
                    'others' => 0,
                    'others_remark' => '',
                    'total_additional' => 0
                ];
            }
        } else {
            $additional_cost = [
                'insurance_ops' => (float)str_replace(['.', ','], '', isset($_POST['insurance_ops']) ? $_POST['insurance_ops'] : 0),
                'insurance_ops_remark' => isset($_POST['insurance_ops_remark']) ? bersihkan($_POST['insurance_ops_remark']) : '',
                'insurance_cargo' => (float)str_replace(['.', ','], '', isset($_POST['insurance_cargo']) ? $_POST['insurance_cargo'] : 0),
                'insurance_cargo_remark' => isset($_POST['insurance_cargo_remark']) ? bersihkan($_POST['insurance_cargo_remark']) : '',
                'delivery_cost' => (float)str_replace(['.', ','], '', isset($_POST['delivery_cost']) ? $_POST['delivery_cost'] : 0),
                'delivery_cost_remark' => isset($_POST['delivery_cost_remark']) ? bersihkan($_POST['delivery_cost_remark']) : '',
                'free_part' => (float)str_replace(['.', ','], '', isset($_POST['free_part']) ? $_POST['free_part'] : 0),
                'free_part_remark' => isset($_POST['free_part_remark']) ? bersihkan($_POST['free_part_remark']) : '',
                'free_service' => (float)str_replace(['.', ','], '', isset($_POST['free_service']) ? $_POST['free_service'] : 0),
                'free_service_remark' => isset($_POST['free_service_remark']) ? bersihkan($_POST['free_service_remark']) : '',
                'mediator_fee' => (float)str_replace(['.', ','], '', isset($_POST['mediator_fee']) ? $_POST['mediator_fee'] : 0),
                'mediator_fee_remark' => isset($_POST['mediator_fee_remark']) ? bersihkan($_POST['mediator_fee_remark']) : '',
                'others' => (float)str_replace(['.', ','], '', isset($_POST['others_cost']) ? $_POST['others_cost'] : 0),
                'others_remark' => isset($_POST['others_remark']) ? bersihkan($_POST['others_remark']) : '',
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
        }
        
        // Jika additional_cost kosong, ambil dari database
        $hasAdditional = false;
        foreach ($additional_cost as $key => $val) {
            if ($key != 'total_additional' && $val > 0) {
                $hasAdditional = true;
                break;
            }
        }
        if (!$hasAdditional) {
            $additional_cost = json_decode($detailData['additional_cost'] ?? '{"insurance_ops":0,"insurance_ops_remark":"","insurance_cargo":0,"insurance_cargo_remark":"","delivery_cost":0,"delivery_cost_remark":"","free_part":0,"free_part_remark":"","free_service":0,"free_service_remark":"","mediator_fee":0,"mediator_fee_remark":"","others":0,"others_remark":"","total_additional":0}', true);
            if (!is_array($additional_cost)) {
                $additional_cost = [
                    'insurance_ops' => 0,
                    'insurance_ops_remark' => '',
                    'insurance_cargo' => 0,
                    'insurance_cargo_remark' => '',
                    'delivery_cost' => 0,
                    'delivery_cost_remark' => '',
                    'free_part' => 0,
                    'free_part_remark' => '',
                    'free_service' => 0,
                    'free_service_remark' => '',
                    'mediator_fee' => 0,
                    'mediator_fee_remark' => '',
                    'others' => 0,
                    'others_remark' => '',
                    'total_additional' => 0
                ];
            }
        }
        
        // Mediator Fee - PRIORITAS dari hidden fields, lalu POST, lalu database
        $mediator_fee = [];
        if (isset($_POST['mediator_data']) && !empty($_POST['mediator_data'])) {
            $mediator_fee = json_decode($_POST['mediator_data'], true);
            if (!is_array($mediator_fee)) {
                $mediator_fee = [
                    'name' => '',
                    'id_card_no' => '',
                    'npwp_no' => '',
                    'bank_name' => '',
                    'bank_account' => '',
                    'amount' => 0
                ];
            }
        } else {
            $mediator_fee = [
                'name' => isset($_POST['mediator_name']) ? bersihkan($_POST['mediator_name']) : '',
                'id_card_no' => isset($_POST['mediator_id_card']) ? bersihkan($_POST['mediator_id_card']) : '',
                'npwp_no' => isset($_POST['mediator_npwp']) ? bersihkan($_POST['mediator_npwp']) : '',
                'bank_name' => isset($_POST['mediator_bank']) ? bersihkan($_POST['mediator_bank']) : '',
                'bank_account' => isset($_POST['mediator_bank_account']) ? bersihkan($_POST['mediator_bank_account']) : '',
                'amount' => (float)str_replace(['.', ','], '', isset($_POST['mediator_fee']) ? $_POST['mediator_fee'] : 0)
            ];
        }
        
        // Jika mediator_fee kosong, ambil dari database
        if (empty($mediator_fee['name']) && empty($mediator_fee['id_card_no'])) {
            $mediator_fee = json_decode($detailData['mediator_fee'] ?? '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}', true);
            if (!is_array($mediator_fee)) {
                $mediator_fee = [
                    'name' => '',
                    'id_card_no' => '',
                    'npwp_no' => '',
                    'bank_name' => '',
                    'bank_account' => '',
                    'amount' => 0
                ];
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
                                      status = ?,
                                      approval_level = ?
                                      WHERE trf_number = ?");
                $result = $stmt->execute([
                    $transaction_request_id,
                    $account_id,
                    $nama_pt,
                    $npwp,
                    $alamat,
                    $nama_pic,
                    $jabatan_pic,
                    $no_hp_pic,
                    $email_pic,
                    json_encode($units, JSON_UNESCAPED_UNICODE),
                    json_encode($top, JSON_UNESCAPED_UNICODE),
                    json_encode($additional_cost, JSON_UNESCAPED_UNICODE),
                    json_encode($mediator_fee, JSON_UNESCAPED_UNICODE),
                    $grand_total,
                    $status,
                    $approval_level,
                    $trf_number
                ]);
                
                if ($result) {
                    setFlash('Data Detail TR berhasil diupdate!', 'success');
                } else {
                    setFlash('Gagal mengupdate data!', 'danger');
                }
            } else {
                $stmt = $db->prepare("INSERT INTO detail_transaction_requests 
                                      (trf_number, transaction_request_id, account_id, 
                                       nama_pt, npwp, alamat, nama_pic, jabatan_pic, no_hp_pic, email_pic,
                                       units, term_of_payment, additional_cost, mediator_fee, grand_total, status, approval_level) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([
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
                    json_encode($units, JSON_UNESCAPED_UNICODE),
                    json_encode($top, JSON_UNESCAPED_UNICODE),
                    json_encode($additional_cost, JSON_UNESCAPED_UNICODE),
                    json_encode($mediator_fee, JSON_UNESCAPED_UNICODE),
                    $grand_total,
                    $status,
                    $approval_level
                ]);
                
                if ($result) {
                    setFlash('Data Detail TR berhasil disimpan!', 'success');
                } else {
                    setFlash('Gagal menyimpan data!', 'danger');
                }
            }
        } catch (PDOException $e) {
            error_log("Error saving detail TR: " . $e->getMessage());
            setFlash('Terjadi kesalahan: ' . $e->getMessage(), 'danger');
        }
        
        // Redirect ke mode VIEW (tanpa edit parameter) setelah save
        redirect('detailtr.php?trf=' . $trf_number . '&tab=' . ($_POST['active_tab'] ?? 'summary'));
    }
    
    if ($action === 'approve') {
        $id = (int)$_POST['id'];
        $current_level = isset($_POST['approval_level']) ? (int)$_POST['approval_level'] : 0;
        $new_level = $current_level + 1;
        
        $role_mapping = [
            1 => 'sales_manager',
            2 => 'direktur_sales',
            3 => 'business',
            4 => 'direktur_operasional',
            5 => 'direktur_utama'
        ];
        
        $required_role = $role_mapping[$new_level] ?? null;
        
        if ($required_role && $userRole !== $required_role && !$hasFullAccess) {
            setFlash('Anda tidak memiliki akses untuk approve di level ini!', 'danger');
            redirect('detailtr.php');
        }
        
        if ($new_level >= 5) {
            $stmt = $db->prepare("UPDATE detail_transaction_requests SET approval_level = ?, status = 'completed' WHERE id = ?");
            $stmt->execute([$new_level, $id]);
            setFlash('TR berhasil di-approve dan selesai!', 'success');
        } else {
            $stmt = $db->prepare("UPDATE detail_transaction_requests SET approval_level = ? WHERE id = ?");
            $stmt->execute([$new_level, $id]);
            setFlash('TR berhasil di-approve ke level ' . $new_level . '!', 'success');
        }
        redirect('detailtr.php?trf=' . $_POST['trf_number']);
    }
    
    if ($action === 'reject') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("UPDATE detail_transaction_requests SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('TR ditolak!', 'danger');
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
    
    if (!$detailData) {
        $stmt = $db->prepare("SELECT tr.*, a.id as account_id, a.nama_pt, a.npwp, a.alamat, 
                              a.nama_pic, a.jabatan_pic, a.no_hp_pic, a.email_pic, a.badan_usaha
                              FROM transaction_requests tr
                              LEFT JOIN accounts a ON tr.account_id = a.id
                              WHERE tr.trf_number = ?");
        $stmt->execute([$trf_number]);
        $trData = $stmt->fetch();
        
        if ($trData) {
            $detailData = [
                'id' => null,
                'trf_number' => $trData['trf_number'],
                'transaction_request_id' => $trData['id'],
                'account_id' => $trData['account_id'],
                'nama_pt' => $trData['nama_pt'] ?? '',
                'npwp' => $trData['npwp'] ?? '',
                'alamat' => $trData['alamat'] ?? '',
                'nama_pic' => $trData['nama_pic'] ?? '',
                'jabatan_pic' => $trData['jabatan_pic'] ?? '',
                'no_hp_pic' => $trData['no_hp_pic'] ?? '',
                'email_pic' => $trData['email_pic'] ?? '',
                'units' => '[]',
                'term_of_payment' => '{"booking_fee":0,"booking_fee_remark":"","nominal_po_leasing":0,"nominal_po_leasing_remark":"","down_payments":[],"installments":[],"grand_total_top":0}',
                'additional_cost' => '{"insurance_ops":0,"insurance_ops_remark":"","insurance_cargo":0,"insurance_cargo_remark":"","delivery_cost":0,"delivery_cost_remark":"","free_part":0,"free_part_remark":"","free_service":0,"free_service_remark":"","mediator_fee":0,"mediator_fee_remark":"","others":0,"others_remark":"","total_additional":0}',
                'mediator_fee' => '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}',
                'grand_total' => 0,
                'status' => 'draft',
                'approval_level' => 0,
                'subject' => $trData['subject'] ?? '',
                'jenis_tugas' => $trData['jenis_tugas'] ?? '',
                'description' => $trData['description'] ?? '',
                'due_date' => $trData['due_date'] ?? '',
                'account_nama_pt' => $trData['nama_pt'] ?? '',
                'account_npwp' => $trData['npwp'] ?? '',
                'account_alamat' => $trData['alamat'] ?? '',
                'account_nama_pic' => $trData['nama_pic'] ?? '',
                'account_jabatan_pic' => $trData['jabatan_pic'] ?? '',
                'account_no_hp_pic' => $trData['no_hp_pic]' ?? '',
                'account_email_pic' => $trData['email_pic'] ?? '',
                'badan_usaha' => $trData['badan_usaha'] ?? ''
            ];
        }
    }
}

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

// ============================================
// FUNGSI UNTUK MENAMPILKAN DATA DENGAN VIEW/EDIT MODE
// ============================================
$editMode = isset($_GET['edit']) ? $_GET['edit'] : null;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';

// JIKA DATA SUDAH ADA DI DATABASE, DEFAULTNYA VIEW MODE (editMode = null)
// KECUALI JIKA ADA PARAMETER edit=yang diinginkan
if ($detailData && isset($detailData['id']) && $detailData['id'] !== null) {
    // HANYA SET editMode JIKA ADA PARAMETER edit DI URL
    if (isset($_GET['edit']) && in_array($_GET['edit'], ['unit', 'top', 'additional', 'mediator'])) {
        $editMode = $_GET['edit'];
    } else {
        // DEFAULT: VIEW MODE (TIDAK EDIT)
        $editMode = null;
    }
}

// AMBIL DATA DARI DATABASE
$units = json_decode($detailData['units'] ?? '[]', true);
$top = json_decode($detailData['term_of_payment'] ?? '{"booking_fee":0,"booking_fee_remark":"","nominal_po_leasing":0,"nominal_po_leasing_remark":"","down_payments":[],"installments":[],"grand_total_top":0}', true);
$additional = json_decode($detailData['additional_cost'] ?? '{"insurance_ops":0,"insurance_ops_remark":"","insurance_cargo":0,"insurance_cargo_remark":"","delivery_cost":0,"delivery_cost_remark":"","free_part":0,"free_part_remark":"","free_service":0,"free_service_remark":"","mediator_fee":0,"mediator_fee_remark":"","others":0,"others_remark":"","total_additional":0}', true);
$mediator = json_decode($detailData['mediator_fee'] ?? '{"name":"","id_card_no":"","npwp_no":"","bank_name":"","bank_account":"","amount":0}', true);

// CEK APAKAH ADA ADDITIONAL COST - DIPINDAHKAN KE SINI
$hasAdditional = false;
if (is_array($additional)) {
    foreach ($additional as $key => $val) {
        if ($key != 'total_additional' && $val > 0) {
            $hasAdditional = true;
            break;
        }
    }
}

// Pastikan data tidak null
if (!is_array($units)) $units = [];
if (!is_array($top)) {
    $top = [
        'booking_fee' => 0,
        'booking_fee_remark' => '',
        'nominal_po_leasing' => 0,
        'nominal_po_leasing_remark' => '',
        'down_payments' => [],
        'installments' => [],
        'grand_total_top' => 0
    ];
}
if (!is_array($additional)) {
    $additional = [
        'insurance_ops' => 0,
        'insurance_ops_remark' => '',
        'insurance_cargo' => 0,
        'insurance_cargo_remark' => '',
        'delivery_cost' => 0,
        'delivery_cost_remark' => '',
        'free_part' => 0,
        'free_part_remark' => '',
        'free_service' => 0,
        'free_service_remark' => '',
        'mediator_fee' => 0,
        'mediator_fee_remark' => '',
        'others' => 0,
        'others_remark' => '',
        'total_additional' => 0
    ];
}
if (!is_array($mediator)) {
    $mediator = [
        'name' => '',
        'id_card_no' => '',
        'npwp_no' => '',
        'bank_name' => '',
        'bank_account' => '',
        'amount' => 0
    ];
}

// Cek apakah data sudah ada di database
$isDataExists = ($detailData && isset($detailData['id']) && $detailData['id'] !== null);

// Siapkan data untuk hidden fields - selalu gunakan data dari database sebagai default
$units_json = json_encode($units);
$top_json = json_encode($top);
$additional_json = json_encode($additional);
$mediator_json = json_encode($mediator);

// Hanya update hidden fields dengan data POST jika ada dan valid
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (isset($_POST['units_data']) && !empty($_POST['units_data'])) {
        $temp_units = json_decode($_POST['units_data'], true);
        if (is_array($temp_units) && !empty($temp_units)) {
            $units_json = json_encode($temp_units);
        }
    }
    if (isset($_POST['top_data']) && !empty($_POST['top_data'])) {
        $temp_top = json_decode($_POST['top_data'], true);
        if (is_array($temp_top) && !empty($temp_top)) {
            $top_json = json_encode($temp_top);
        }
    }
    if (isset($_POST['additional_data']) && !empty($_POST['additional_data'])) {
        $temp_additional = json_decode($_POST['additional_data'], true);
        if (is_array($temp_additional) && !empty($temp_additional)) {
            $additional_json = json_encode($temp_additional);
        }
    }
    if (isset($_POST['mediator_data']) && !empty($_POST['mediator_data'])) {
        $temp_mediator = json_decode($_POST['mediator_data'], true);
        if (is_array($temp_mediator) && !empty($temp_mediator)) {
            $mediator_json = json_encode($temp_mediator);
        }
    }
}
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
        .top-header .header-left .brand-text .brand-sub { font-size: 8px; color: rgba(255,255,255,0.4); letter-spacing: 0.5px; text-transform: uppercase; }
        .top-header .header-right { display: flex; align-items: center; gap: 12px; }
        .top-header .header-right .notif-icon { position: relative; color: rgba(255,255,255,0.6); font-size: 16px; cursor: pointer; }
        .top-header .header-right .notif-icon .badge-notif { position: absolute; top: -5px; right: -6px; background: #d63031; color: #fff; font-size: 8px; padding: 1px 5px; border-radius: 50%; min-width: 16px; text-align: center; }
        .top-header .header-right .user-avatar { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,215,0,0.2); display: flex; align-items: center; justify-content: center; color: #ffd700; font-weight: 700; font-size: 13px; text-decoration: none; border: 2px solid rgba(255,215,0,0.2); transition: border-color 0.3s ease; }
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
        .welcome-banner .welcome-text .greeting { font-size: 12px; color: rgba(255,255,255,0.4); font-weight: 400; }
        .welcome-banner .welcome-text h3 { font-weight: 700; font-size: 18px; margin: 2px 0 0; }
        .welcome-banner .welcome-text h3 span { color: #ffd700; }
        .welcome-banner .welcome-icon { font-size: 32px; color: rgba(255,215,0,0.05); position: absolute; right: 15px; bottom: 10px; }
        .card-custom {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
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
            font-size: 12px;
            color: #333;
            margin-bottom: 4px;
        }
        .form-label .required { color: #e74c3c; }
        .form-label .optional { font-weight: 400; color: #999; font-size: 10px; }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 8px 12px;
            border: 2px solid #e0e4ea;
            transition: all 0.3s ease;
            font-size: 13px;
            background: #ffffff;
        }
        .form-control:focus, .form-select:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255,215,0,0.1);
        }
        .form-control[readonly] {
            background: #f8f9fa;
            border-color: #d0d5dc;
        }
        .form-control-sm { font-size: 12px; padding: 6px 10px; }
        .btn-primary-custom {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(26,26,46,0.3); color: #fff; }
        .btn-primary-custom i { margin-right: 6px; }
        .btn-secondary-custom {
            background: #f0f2f5;
            border: none;
            border-radius: 8px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s ease;
            color: #555;
        }
        .btn-secondary-custom:hover { background: #e8edf2; color: #333; }
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
        .btn-success-custom:hover { background: #219a52; color: #fff; }
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
        .btn-warning-custom:hover { background: #e67e22; color: #fff; }
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
        .btn-danger-custom:hover { background: #c0392b; color: #fff; }
        .btn-edit-custom {
            background: #3498db;
            border: none;
            border-radius: 8px;
            padding: 5px 12px;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.3s ease;
            color: #fff;
        }
        .btn-edit-custom:hover { background: #2980b9; color: #fff; }
        .btn-sm-custom { padding: 4px 12px; font-size: 12px; border-radius: 6px; }
        .badge-status {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-status.draft { background: rgba(149,165,166,0.15); color: #7f8c8d; }
        .badge-status.in_progress { background: rgba(241,196,15,0.15); color: #d4a017; }
        .badge-status.success { background: rgba(46,204,113,0.15); color: #27ae60; }
        .badge-status.rejected { background: rgba(231,76,60,0.15); color: #c0392b; }
        .badge-trf {
            background: rgba(52,152,219,0.12);
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
            border: 1px solid #e0e4ea;
        }
        .unit-row .btn-remove-unit {
            color: #e74c3c;
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
        }
        .unit-row .btn-remove-unit:hover { color: #c0392b; }
        .unit-row .form-control, .unit-row .form-select { font-size: 12px; padding: 6px 10px; }
        .dp-row, .installment-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 6px;
            padding: 6px;
            background: #fafafa;
            border-radius: 6px;
            border: 1px solid #e8edf2;
        }
        .dp-row .form-control, .installment-row .form-control { flex: 1; font-size: 12px; padding: 6px 10px; }
        .dp-row .btn, .installment-row .btn { padding: 4px 8px; font-size: 12px; }
        .summary-item {
            display: flex;
            padding: 6px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 4px;
            border: 1px solid #e8edf2;
            align-items: center;
            flex-wrap: wrap;
        }
        .summary-item .label {
            font-weight: 600;
            color: #555;
            width: 160px;
            flex-shrink: 0;
            font-size: 12px;
        }
        .summary-item .value { color: #1a1a2e; font-size: 12px; word-break: break-word; }
        .summary-item .value .badge { font-size: 11px; }
        .summary-item .text-muted { font-size: 11px; }
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #999;
        }
        .empty-state i { font-size: 36px; color: #ddd; margin-bottom: 8px; }
        .empty-state p { font-size: 13px; margin: 0; }
        .edit-form-container {
            background: #ffffff;
            border-radius: 8px;
            padding: 16px;
            border: 1px solid #e0e4ea;
        }
        .info-row {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid #f0f2f5;
            flex-wrap: wrap;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row .info-label {
            font-weight: 600;
            color: #555;
            width: 180px;
            flex-shrink: 0;
            font-size: 13px;
        }
        .info-row .info-value { color: #1a1a2e; font-size: 13px; word-break: break-word; }
        .nav-tabs-custom {
            border-bottom: 2px solid #e8edf2;
            padding: 0 16px;
            background: #f8f9fa;
            border-radius: 12px 12px 0 0;
        }
        .nav-tabs-custom .nav-link {
            border: none;
            padding: 10px 16px;
            font-weight: 600;
            font-size: 13px;
            color: #999;
            transition: all 0.3s ease;
            position: relative;
            text-decoration: none;
            display: inline-block;
        }
        .nav-tabs-custom .nav-link:hover { color: #1a1a2e; background: transparent; }
        .nav-tabs-custom .nav-link.active { color: #ffd700; background: transparent; }
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
        .nav-tabs-custom .nav-link i { margin-right: 6px; }
        .tab-content-custom {
            padding: 16px 20px 20px;
            background: #fff;
            border-radius: 0 0 12px 12px;
            border: 1px solid #e8edf2;
            border-top: none;
        }
        .section-title {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 15px;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f2f5;
        }
        .section-title i { color: #ffd700; margin-right: 8px; }
        .alert { border-radius: 10px; border: none; padding: 10px 14px; font-size: 13px; }
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid rgba(0,0,0,0.05);
            padding: 5px 0 env(safe-area-inset-bottom);
            z-index: 999;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.06);
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
        .desktop-nav-wrapper .brand-section .brand-text .brand-sub { font-size: 8px; color: rgba(255,255,255,0.4); letter-spacing: 1px; text-transform: uppercase; }
        .desktop-nav-wrapper .desktop-menu { display: flex; align-items: center; gap: 4px; }
        .desktop-nav-wrapper .desktop-menu .nav-link { color: rgba(255,255,255,0.6); padding: 8px 16px; display: flex; align-items: center; gap: 6px; text-decoration: none; font-size: 13px; font-weight: 500; border-radius: 8px; transition: all 0.3s ease; }
        .desktop-nav-wrapper .desktop-menu .nav-link:hover { color: #fff; background: rgba(255,255,255,0.05); }
        .desktop-nav-wrapper .desktop-menu .nav-link.active { color: #ffd700; background: rgba(255,215,0,0.08); }
        .desktop-nav-wrapper .desktop-menu .nav-link i { font-size: 14px; }
        .desktop-nav-wrapper .nav-right { display: flex; align-items: center; gap: 16px; }
        .desktop-nav-wrapper .nav-right .notif-icon { position: relative; color: rgba(255,255,255,0.6); font-size: 17px; cursor: pointer; }
        .desktop-nav-wrapper .nav-right .notif-icon .badge-notif { position: absolute; top: -5px; right: -6px; background: #d63031; color: #fff; font-size: 8px; padding: 1px 5px; border-radius: 50%; min-width: 16px; text-align: center; }
        .desktop-nav-wrapper .nav-right .user-info { text-align: right; color: #fff; }
        .desktop-nav-wrapper .nav-right .user-info .name { font-weight: 600; font-size: 13px; line-height: 1.2; }
        .desktop-nav-wrapper .nav-right .user-info .role { font-size: 10px; color: rgba(255,255,255,0.4); }
        .desktop-nav-wrapper .nav-right .user-avatar { width: 34px; height: 34px; border-radius: 50%; background: rgba(255,215,0,0.2); display: flex; align-items: center; justify-content: center; color: #ffd700; font-weight: 700; font-size: 14px; text-decoration: none; border: 2px solid rgba(255,215,0,0.2); transition: border-color 0.3s ease; }
        .desktop-nav-wrapper .nav-right .user-avatar:hover { border-color: #ffd700; }
        .desktop-nav-wrapper .nav-right .logout-btn { color: rgba(255,255,255,0.5); padding: 5px 14px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.3s ease; border: 1px solid rgba(255,255,255,0.1); }
        .desktop-nav-wrapper .nav-right .logout-btn:hover { color: #ff6b6b; background: rgba(214,48,49,0.1); border-color: rgba(214,48,49,0.3); }
        .footer-text {
            text-align: center;
            padding: 16px 0 8px;
            color: #999;
            font-size: 11px;
        }
        .footer-text a { color: #16213e; text-decoration: none; font-weight: 500; }
        .footer-text a:hover { color: #ffd700; }
        .currency-input { position: relative; }
        .currency-input .currency-prefix {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-weight: 600;
            font-size: 12px;
        }
        .currency-input .form-control { padding-left: 32px; }
        .alert-info-custom {
            background: #e8f4fd;
            border: 1px solid #c5e0f7;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 12px;
            color: #2c7fb8;
        }
        .alert-info-custom i { margin-right: 6px; }
        
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
            .nav-tabs-custom .nav-link { padding: 8px 12px; font-size: 12px; }
            .info-row { flex-wrap: wrap; }
            .info-row .info-label { width: 100%; }
            .summary-item { flex-wrap: wrap; }
            .summary-item .label { width: 100%; }
            .tab-content-custom { padding: 12px 14px; }
            .unit-row { padding: 10px; }
        }
        @media (max-width: 480px) {
            .modal-body { padding: 14px 16px; }
            .modal-header { padding: 14px 16px; }
            .unit-row { padding: 8px; }
            .nav-tabs-custom .nav-link { padding: 6px 10px; font-size: 11px; }
            .info-row .info-label { width: 100%; }
            .summary-item .label { width: 100%; }
            .dp-row .form-control, .installment-row .form-control { font-size: 11px; }
            .dp-row { flex-wrap: wrap; }
            .installment-row { flex-wrap: wrap; }
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
        <a href="dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> Dashboard</a>
        <?php if (canAccessMenu('account_management')): ?>
            <a href="account_management.php" class="nav-link"><i class="fas fa-building"></i> Account</a>
        <?php endif; ?>
        <?php if (canAccessMenu('sales_activity')): ?>
            <a href="salesactivity.php" class="nav-link"><i class="fas fa-chart-bar"></i> Sales Activity</a>
        <?php endif; ?>
        <?php if (canAccessMenu('transaction_request')): ?>
            <a href="transactionrequest.php" class="nav-link"><i class="fas fa-file-signature"></i> TR Request</a>
        <?php endif; ?>
        <?php if (canAccessMenu('produk')): ?>
            <a href="produk.php" class="nav-link"><i class="fas fa-box"></i> Produk</a>
        <?php endif; ?>
    </div>
    <div class="nav-right">
        <div class="notif-icon"><i class="fas fa-bell"></i><span class="badge-notif">0</span></div>
        <div class="user-info"><div class="name"><?= htmlspecialchars($fullName) ?></div><div class="role"><?= getRoleLabel($role) ?></div></div>
        <a href="logout.php" class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></a>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- MOBILE HEADER -->
<header class="top-header">
    <div class="header-left">
        <div class="logo-wrapper"><img src="images/logo.webp" alt="PT Ganda Elang Tangguh"></div>
        <div class="brand-text"><div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div><div class="brand-sub">Customer Relationship Management</div></div>
    </div>
    <div class="header-right">
        <div class="notif-icon"><i class="fas fa-bell"></i><span class="badge-notif">0</span></div>
        <a href="logout.php" class="user-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></a>
    </div>
</header>

<!-- MAIN CONTENT -->
<main style="padding: 12px 16px 0; max-width: 1400px; margin: 0 auto;">

    <div class="welcome-banner">
        <div class="welcome-text">
            <div class="greeting">Detail Transaction Request</div>
            <h3>Detail Transaction Request Form</h3>
        </div>
        <i class="fas fa-file-alt welcome-icon"></i>
    </div>

    <?= showFlash() ?>

    <?php if ($detailData): ?>
    <form method="POST" id="formDetailTR">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $detailData['id'] ?? '' ?>">
        <input type="hidden" name="trf_number" value="<?= htmlspecialchars($detailData['trf_number']) ?>">
        <input type="hidden" name="transaction_request_id" value="<?= $detailData['transaction_request_id'] ?? '' ?>">
        <input type="hidden" name="account_id" value="<?= $detailData['account_id'] ?? '' ?>">
        <input type="hidden" name="status" id="formStatus" value="<?= $detailData['status'] ?? 'draft' ?>">
        <input type="hidden" name="approval_level" id="formApprovalLevel" value="<?= $detailData['approval_level'] ?? 0 ?>">
        <input type="hidden" name="active_tab" id="activeTabInput" value="<?= $activeTab ?>">
        <input type="hidden" name="edit_mode" id="editMode" value="<?= $editMode ?>">
        
        <!-- HIDDEN FIELDS UNTUK MENYIMPAN SEMUA DATA - DIISI DARI DATABASE -->
        <input type="hidden" name="units_data" id="units_data" value='<?= htmlspecialchars($units_json) ?>'>
        <input type="hidden" name="top_data" id="top_data" value='<?= htmlspecialchars($top_json) ?>'>
        <input type="hidden" name="additional_data" id="additional_data" value='<?= htmlspecialchars($additional_json) ?>'>
        <input type="hidden" name="mediator_data" id="mediator_data" value='<?= htmlspecialchars($mediator_json) ?>'>

        <!-- TAB NAVIGATION - TANPA PARAMETER EDIT -->
        <div class="card-custom" style="padding: 0; overflow: hidden;">
            <div class="nav-tabs-custom">
                <ul class="nav nav-tabs" id="detailTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a href="detailtr.php?trf=<?= $trf_number ?>&tab=summary" class="nav-link <?= $activeTab == 'summary' ? 'active' : '' ?>">
                            <i class="fas fa-info-circle"></i> Summary
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a href="detailtr.php?trf=<?= $trf_number ?>&tab=unit" class="nav-link <?= $activeTab == 'unit' ? 'active' : '' ?>">
                            <i class="fas fa-box"></i> Detail Unit
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a href="detailtr.php?trf=<?= $trf_number ?>&tab=top" class="nav-link <?= $activeTab == 'top' ? 'active' : '' ?>">
                            <i class="fas fa-money-bill-wave"></i> Term Of Payment
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a href="detailtr.php?trf=<?= $trf_number ?>&tab=additional" class="nav-link <?= $activeTab == 'additional' ? 'active' : '' ?>">
                            <i class="fas fa-plus-circle"></i> Additional Cost
                        </a>
                    </li>
                    <li class="nav-item" role="presentation">
                        <a href="detailtr.php?trf=<?= $trf_number ?>&tab=mediator" class="nav-link <?= $activeTab == 'mediator' ? 'active' : '' ?>">
                            <i class="fas fa-user-tie"></i> Mediator Fee
                        </a>
                    </li>
                </ul>
            </div>

            <div class="tab-content tab-content-custom" id="detailTabContent">
                <!-- TAB 1: SUMMARY -->
                <div class="tab-pane fade <?= $activeTab == 'summary' ? 'show active' : '' ?>" id="summary" role="tabpanel">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row"><span class="info-label">TR Number</span><span class="info-value"><span class="badge-trf"><i class="fas fa-file-signature"></i> <?= htmlspecialchars($detailData['trf_number']) ?></span></span></div>
                            <div class="info-row"><span class="info-label">Nama PT</span><span class="info-value"><?= htmlspecialchars($detailData['account_nama_pt'] ?? $detailData['nama_pt'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">No NPWP</span><span class="info-value"><?= htmlspecialchars($detailData['account_npwp'] ?? $detailData['npwp'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">Alamat</span><span class="info-value"><?= htmlspecialchars($detailData['account_alamat'] ?? $detailData['alamat'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">Nama PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_nama_pic'] ?? $detailData['nama_pic'] ?? '-') ?></span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row"><span class="info-label">Jabatan PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_jabatan_pic'] ?? $detailData['jabatan_pic'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">No Telepon PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_no_hp_pic'] ?? $detailData['no_hp_pic'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">Email PIC</span><span class="info-value"><?= htmlspecialchars($detailData['account_email_pic'] ?? $detailData['email_pic'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">Subject</span><span class="info-value"><?= htmlspecialchars($detailData['subject'] ?? '-') ?></span></div>
                            <div class="info-row"><span class="info-label">Due Date</span><span class="info-value"><?= !empty($detailData['due_date']) ? date('d/m/Y', strtotime($detailData['due_date'])) : '-' ?></span></div>
                        </div>
                    </div>
                    
                    <!-- STATUS & APPROVAL INFORMATION -->
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <?php
                            $approvalLevel = isset($detailData['approval_level']) ? (int)$detailData['approval_level'] : 0;
                            $status = $detailData['status'] ?? 'draft';
                            $approvalStatus = getApprovalStatus($detailData);
                            ?>
                            <div class="info-row"><span class="info-label">Status</span><span class="info-value"><span class="badge-status <?= $approvalStatus ?>"><?php if ($status === 'rejected') { echo 'Rejected'; } elseif ($approvalStatus === 'success') { echo 'Success'; } elseif ($approvalStatus === 'in_progress') { echo 'In Progress'; } else { echo 'Pending'; } ?></span></span></div>
                            <?php if ($status !== 'rejected' && $status !== 'completed'): ?>
                            <div class="info-row"><span class="info-label">Current Approver Job Title</span><span class="info-value"><?php if ($approvalLevel >= 5) { echo 'Completed'; } else { $current = getCurrentApprover($approvalLevel + 1); echo $current ? $current['job_title'] : '-'; } ?></span></div>
                            <div class="info-row"><span class="info-label">Current Approver Resource Name</span><span class="info-value"><?php if ($approvalLevel >= 5) { echo 'Completed'; } else { $current = getCurrentApprover($approvalLevel + 1); echo $current ? getApproverName($db, $current['role']) : '-'; } ?></span></div>
                            <div class="info-row"><span class="info-label">Next Approver Job Title</span><span class="info-value"><?php if ($approvalLevel >= 5) { echo 'Completed'; } else { $next = getNextApprover($approvalLevel); echo $next ? $next['job_title'] : '-'; } ?></span></div>
                            <?php endif; ?>
                            <div class="info-row"><span class="info-label">Approval Level</span><span class="info-value"><?= $approvalLevel ?> / 5 <?php if ($approvalLevel > 0 && $approvalLevel < 5): ?><span class="badge bg-warning text-dark ms-2">In Progress</span><?php elseif ($approvalLevel >= 5): ?><span class="badge bg-success ms-2">Completed</span><?php elseif ($status === 'rejected'): ?><span class="badge bg-danger ms-2">Rejected</span><?php else: ?><span class="badge bg-secondary ms-2">Pending</span><?php endif; ?></span></div>
                        </div>
                    </div>
                </div>

                <!-- TAB 2: DETAIL UNIT -->
                <div class="tab-pane fade <?= $activeTab == 'unit' ? 'show active' : '' ?>" id="unit" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0"><i class="fas fa-box"></i> Detail Unit</h5>
                        <?php if ($editMode == 'unit'): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&tab=unit" class="btn btn-sm btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
                        <?php elseif ($isDataExists && !empty($units)): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=unit&tab=unit" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Edit Unit</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($editMode == 'unit'): ?>
                        <!-- EDIT MODE UNIT -->
                        <div class="edit-form-container">
                            <div id="unitContainer">
                                <?php 
                                $unitIndex = 0;
                                if (empty($units)) {
                                    $units = [['unit_name' => '', 'qty' => 1, 'price' => 0, 'ppn_percent' => 11, 'ppn' => 0, 'grand_total' => 0, 'specification' => '', 'additional_attachment' => '', 'warranty' => '', 'machine_location' => '', 'delivery_terms' => '', 'delivery_schedule' => '', 'transaction_type' => '']];
                                }
                                foreach ($units as $unit):
                                ?>
                                <div class="unit-row" data-index="<?= $unitIndex ?>">
                                    <div class="row">
                                        <div class="col-md-12 text-end">
                                            <?php if (count($units) > 1): ?>
                                                <button type="button" class="btn-remove-unit" onclick="removeUnit(this)" title="Hapus Unit"><i class="fas fa-times-circle"></i></button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Unit <span class="required">*</span></label>
                                            <select name="unit_name[]" class="form-select form-select-sm" required>
                                                <option value="">-- Pilih Unit --</option>
                                                <?php foreach ($produkList as $produk): ?>
                                                    <option value="<?= htmlspecialchars($produk['nama_produk']) ?>" <?= ($unit['unit_name'] == $produk['nama_produk']) ? 'selected' : '' ?>><?= htmlspecialchars($produk['nama_produk']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 mb-2">
                                            <label class="form-label">QTY <span class="required">*</span></label>
                                            <input type="number" name="qty[]" class="form-control form-control-sm qty" value="<?= $unit['qty'] ?? 1 ?>" min="1" required onchange="calculateUnit(this)">
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Price (Non PPN) <span class="required">*</span></label>
                                            <div class="currency-input">
                                                <span class="currency-prefix">Rp</span>
                                                <input type="text" name="price[]" class="form-control form-control-sm price" value="<?= number_format($unit['price'] ?? 0, 0, ',', '.') ?>" required oninput="calculateUnit(this)">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-3 mb-2">
                                            <label class="form-label">PPN (11%)</label>
                                            <input type="text" name="ppn[]" class="form-control form-control-sm ppn" value="<?= number_format($unit['ppn'] ?? 0, 0, ',', '.') ?>" readonly>
                                        </div>
                                        <div class="col-md-3 mb-2">
                                            <label class="form-label">Grand Total (Include PPN)</label>
                                            <input type="text" name="grand_total_unit[]" class="form-control form-control-sm grand-total-unit" value="<?= number_format($unit['grand_total'] ?? 0, 0, ',', '.') ?>" readonly>
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Spesification <span class="required">*</span></label>
                                            <input type="text" name="specification[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unit['specification'] ?? '') ?>" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Additional Attachment / Safety Devices</label>
                                            <input type="text" name="additional_attachment[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unit['additional_attachment'] ?? '') ?>">
                                        </div>
                                        <div class="col-md-6 mb-2">
                                            <label class="form-label">Warranty</label>
                                            <input type="text" name="warranty[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unit['warranty'] ?? '') ?>">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Machine Location Works <span class="required">*</span></label>
                                            <input type="text" name="machine_location[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unit['machine_location'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Delivery Terms <span class="required">*</span></label>
                                            <input type="text" name="delivery_terms[]" class="form-control form-control-sm" placeholder="Contoh: Loco Jakarta atau Franco Kalimantan" value="<?= htmlspecialchars($unit['delivery_terms'] ?? '') ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-2">
                                            <label class="form-label">Delivery Schedule Plan <span class="required">*</span></label>
                                            <input type="date" name="delivery_schedule[]" class="form-control form-control-sm" value="<?= htmlspecialchars($unit['delivery_schedule'] ?? '') ?>" required>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12 mb-2">
                                            <label class="form-label">Transaction Type <span class="required">*</span></label>
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <select name="transaction_type[]" class="form-select form-select-sm transaction-type-select" required onchange="showOtherInput(this)">
                                                        <option value="">-- Pilih Transaction Type --</option>
                                                        <option value="Cash On Delivery" <?= ($unit['transaction_type'] == 'Cash On Delivery') ? 'selected' : '' ?>>Cash On Delivery</option>
                                                        <option value="Leasing" <?= ($unit['transaction_type'] == 'Leasing') ? 'selected' : '' ?>>Leasing</option>
                                                        <option value="Direct Credit" <?= ($unit['transaction_type'] == 'Direct Credit') ? 'selected' : '' ?>>Direct Credit</option>
                                                        <option value="Other" <?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false) ? 'selected' : '' ?>>Other</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-4" id="otherInputContainer_<?= $unitIndex ?>" style="display: <?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false) ? 'block' : 'none' ?>">
                                                    <input type="text" name="transaction_type_other[]" class="form-control form-control-sm" placeholder="Spesifikasi Other" value="<?= (strpos($unit['transaction_type'] ?? '', 'Other') !== false && strpos($unit['transaction_type'] ?? '', '-') !== false) ? trim(substr($unit['transaction_type'], strpos($unit['transaction_type'], '-') + 1)) : '' ?>">
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
                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-sm btn-success-custom" onclick="saveDataToHidden()"><i class="fas fa-save"></i> Simpan Unit</button>
                                <a href="detailtr.php?trf=<?= $trf_number ?>&tab=unit" class="btn btn-sm btn-secondary-custom ms-2"><i class="fas fa-times"></i> Batal</a>
                            </div>
                            <div class="alert-info-custom mt-2"><i class="fas fa-info-circle"></i> Jumlah unit sudah ditentukan dan tidak dapat ditambahkan.</div>
                        </div>
                    <?php elseif (!empty($units)): ?>
                        <!-- VIEW MODE UNIT -->
                        <?php foreach ($units as $unit): ?>
                        <div class="summary-item"><span class="label">Unit</span><span class="value"><strong><?= htmlspecialchars($unit['unit_name']) ?></strong></span></div>
                        <div class="summary-item"><span class="label">QTY</span><span class="value"><?= $unit['qty'] ?? 0 ?></span></div>
                        <div class="summary-item"><span class="label">Price (Non PPN)</span><span class="value">Rp <?= number_format($unit['price'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">PPN (11%)</span><span class="value">Rp <?= number_format($unit['ppn'] ?? 0, 0, ',', '.') ?></span></div>
                        <div class="summary-item"><span class="label">Grand Total (Include PPN)</span><span class="value"><strong>Rp <?= number_format($unit['grand_total'] ?? 0, 0, ',', '.') ?></strong></span></div>
                        <div class="summary-item"><span class="label">Spesification</span><span class="value"><?= htmlspecialchars($unit['specification'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Additional Attachment</span><span class="value"><?= htmlspecialchars($unit['additional_attachment'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Warranty</span><span class="value"><?= htmlspecialchars($unit['warranty'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Machine Location</span><span class="value"><?= htmlspecialchars($unit['machine_location'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Delivery Terms</span><span class="value"><?= htmlspecialchars($unit['delivery_terms'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Delivery Schedule</span><span class="value"><?= !empty($unit['delivery_schedule']) ? date('d/m/Y', strtotime($unit['delivery_schedule'])) : '-' ?></span></div>
                        <div class="summary-item"><span class="label">Transaction Type</span><span class="value"><span class="badge bg-info"><?= htmlspecialchars($unit['transaction_type'] ?? '-') ?></span></span></div>
                        <hr class="my-2">
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-box-open"></i><p>Belum ada data unit. Klik Edit Unit untuk menambahkan.</p></div>
                    <?php endif; ?>
                </div>

                <!-- TAB 3: TERM OF PAYMENT -->
                <div class="tab-pane fade <?= $activeTab == 'top' ? 'show active' : '' ?>" id="top" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0"><i class="fas fa-money-bill-wave"></i> Term Of Payment</h5>
                        <?php if ($editMode == 'top'): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&tab=top" class="btn btn-sm btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
                        <?php elseif ($isDataExists && (!empty($top['down_payments']) || !empty($top['installments']) || $top['booking_fee'] > 0 || $top['nominal_po_leasing'] > 0)): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=top&tab=top" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Edit TOP</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($editMode == 'top'): ?>
                        <!-- EDIT MODE TOP -->
                        <div class="edit-form-container">
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Booking Fee</label>
                                    <div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="booking_fee" class="form-control form-control-sm top-input" value="<?= number_format($top['booking_fee'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()" placeholder="Nominal"></div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Remark Booking Fee</label>
                                    <input type="text" name="booking_fee_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($top['booking_fee_remark'] ?? '') ?>" placeholder="Keterangan">
                                </div>
                                <div class="col-md-4 mb-2 text-end">
                                    <label class="form-label">Grand Total TOP</label>
                                    <h4 class="text-success" id="grandTotalTOP" style="font-size: 18px; margin: 0;">Rp <?= number_format($top['grand_total_top'] ?? 0, 0, ',', '.') ?></h4>
                                    <input type="hidden" name="grand_total_top" id="grandTotalTOPHidden" value="<?= $top['grand_total_top'] ?? 0 ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Nominal PO Leasing</label>
                                    <div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="nominal_po_leasing" class="form-control form-control-sm top-input" value="<?= number_format($top['nominal_po_leasing'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()" placeholder="Nominal"></div>
                                </div>
                                <div class="col-md-4 mb-2">
                                    <label class="form-label">Remark PO Leasing</label>
                                    <input type="text" name="nominal_po_leasing_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($top['nominal_po_leasing_remark'] ?? '') ?>" placeholder="Keterangan">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Down Payment</label>
                                <div id="dpContainer">
                                    <?php foreach ($top['down_payments'] ?? [] as $dp): ?>
                                    <div class="dp-row">
                                        <input type="text" name="dp_name[]" class="form-control form-control-sm" placeholder="Nama DP" value="<?= htmlspecialchars($dp['name'] ?? '') ?>">
                                        <div class="currency-input" style="flex:1;"><span class="currency-prefix">Rp</span><input type="text" name="dp_value[]" class="form-control form-control-sm top-input" placeholder="Nilai" value="<?= number_format($dp['value'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()"></div>
                                        <input type="text" name="dp_remark[]" class="form-control form-control-sm" placeholder="Remark" value="<?= htmlspecialchars($dp['remark'] ?? '') ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeDP(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary-custom mt-1" onclick="addDP()"><i class="fas fa-plus"></i> Tambah DP</button>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Angsuran</label>
                                <div id="installmentContainer">
                                    <?php foreach ($top['installments'] ?? [] as $inst): ?>
                                    <div class="installment-row">
                                        <input type="text" name="installment_name[]" class="form-control form-control-sm" placeholder="Nama Angsuran" value="<?= htmlspecialchars($inst['name'] ?? '') ?>">
                                        <div class="currency-input" style="flex:1;"><span class="currency-prefix">Rp</span><input type="text" name="installment_value[]" class="form-control form-control-sm top-input" placeholder="Nilai" value="<?= number_format($inst['value'] ?? 0, 0, ',', '.') ?>" oninput="calculateTOP()"></div>
                                        <input type="text" name="installment_remark[]" class="form-control form-control-sm" placeholder="Remark" value="<?= htmlspecialchars($inst['remark'] ?? '') ?>">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeInstallment(this)"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-primary-custom mt-1" onclick="addInstallment()"><i class="fas fa-plus"></i> Tambah Angsuran</button>
                            </div>
                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-sm btn-success-custom" onclick="saveDataToHidden()"><i class="fas fa-save"></i> Simpan TOP</button>
                                <a href="detailtr.php?trf=<?= $trf_number ?>&tab=top" class="btn btn-sm btn-secondary-custom ms-2"><i class="fas fa-times"></i> Batal</a>
                            </div>
                        </div>
                    <?php elseif (!empty($top['down_payments']) || !empty($top['installments']) || $top['booking_fee'] > 0 || $top['nominal_po_leasing'] > 0): ?>
                        <!-- VIEW MODE TOP -->
                        <div class="summary-item"><span class="label">Booking Fee</span><span class="value">Rp <?= number_format($top['booking_fee'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($top['booking_fee_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($top['booking_fee_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Nominal PO Leasing</span><span class="value">Rp <?= number_format($top['nominal_po_leasing'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($top['nominal_po_leasing_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($top['nominal_po_leasing_remark']) ?>)</span><?php endif; ?></div>
                        <?php foreach ($top['down_payments'] ?? [] as $dp): ?>
                        <div class="summary-item"><span class="label"><?= htmlspecialchars($dp['name'] ?? 'Down Payment') ?></span><span class="value">Rp <?= number_format($dp['value'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($dp['remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($dp['remark']) ?>)</span><?php endif; ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($top['installments'] ?? [] as $inst): ?>
                        <div class="summary-item"><span class="label"><?= htmlspecialchars($inst['name'] ?? 'Angsuran') ?></span><span class="value">Rp <?= number_format($inst['value'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($inst['remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($inst['remark']) ?>)</span><?php endif; ?></div>
                        <?php endforeach; ?>
                        <div class="summary-item" style="background: #d4edda; border-radius: 6px;"><span class="label" style="font-weight: 700; color: #155724;">Grand Total TOP</span><span class="value" style="font-weight: 700; color: #155724;">Rp <?= number_format($top['grand_total_top'] ?? 0, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-file-invoice-dollar"></i><p>Belum ada data Term Of Payment. Klik Edit TOP untuk menambahkan.</p></div>
                    <?php endif; ?>
                </div>

                <!-- TAB 4: ADDITIONAL COST - DIPERBAIKI -->
                <div class="tab-pane fade <?= $activeTab == 'additional' ? 'show active' : '' ?>" id="additional" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0"><i class="fas fa-plus-circle"></i> Additional Cost / Machines</h5>
                        <?php if ($editMode == 'additional'): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&tab=additional" class="btn btn-sm btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
                        <?php elseif ($isDataExists && $hasAdditional): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=additional&tab=additional" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Edit Additional Cost</a>
                        <?php elseif ($isDataExists): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=additional&tab=additional" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Tambah Additional Cost</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($editMode == 'additional'): ?>
                        <!-- EDIT MODE ADDITIONAL -->
                        <div class="edit-form-container">
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Insurance Ops</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="insurance_ops" class="form-control form-control-sm additional-input" value="<?= number_format($additional['insurance_ops'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Insurance Ops</label><input type="text" name="insurance_ops_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['insurance_ops_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Insurance Cargo <span class="required">*</span></label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="insurance_cargo" class="form-control form-control-sm additional-input" value="<?= number_format($additional['insurance_cargo'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" required placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Insurance Cargo</label><input type="text" name="insurance_cargo_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['insurance_cargo_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Delivery Cost</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="delivery_cost" class="form-control form-control-sm additional-input" value="<?= number_format($additional['delivery_cost'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Delivery Cost</label><input type="text" name="delivery_cost_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['delivery_cost_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Free Part</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="free_part" class="form-control form-control-sm additional-input" value="<?= number_format($additional['free_part'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Free Part</label><input type="text" name="free_part_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['free_part_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Free Service</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="free_service" class="form-control form-control-sm additional-input" value="<?= number_format($additional['free_service'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Free Service</label><input type="text" name="free_service_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['free_service_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Mediator Fee</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="mediator_fee" class="form-control form-control-sm additional-input" id="mediatorFeeInput" value="<?= number_format($additional['mediator_fee'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional(); updateMediatorAmount()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Mediator Fee</label><input type="text" name="mediator_fee_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['mediator_fee_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-6"><label class="form-label">Others</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="others_cost" class="form-control form-control-sm additional-input" value="<?= number_format($additional['others'] ?? 0, 0, ',', '.') ?>" oninput="calculateAdditional()" placeholder="Nominal"></div></div>
                                <div class="col-md-6"><label class="form-label">Remark Others</label><input type="text" name="others_remark" class="form-control form-control-sm" value="<?= htmlspecialchars($additional['others_remark'] ?? '') ?>" placeholder="Keterangan"></div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-md-12 text-end">
                                    <label class="form-label" style="font-weight: 700;">Total Additional Cost</label>
                                    <h4 class="text-primary" id="totalAdditional" style="font-size: 20px; margin: 0;">Rp <?= number_format($additional['total_additional'] ?? 0, 0, ',', '.') ?></h4>
                                    <input type="hidden" name="total_additional" id="totalAdditionalHidden" value="<?= $additional['total_additional'] ?? 0 ?>">
                                </div>
                            </div>
                            <div class="text-end mt-3">
                                <button type="submit" class="btn btn-sm btn-success-custom" onclick="saveDataToHidden()"><i class="fas fa-save"></i> Simpan Additional Cost</button>
                                <a href="detailtr.php?trf=<?= $trf_number ?>&tab=additional" class="btn btn-sm btn-secondary-custom ms-2"><i class="fas fa-times"></i> Batal</a>
                            </div>
                        </div>
                    <?php elseif ($hasAdditional): ?>
                        <!-- VIEW MODE ADDITIONAL -->
                        <div class="summary-item"><span class="label">Insurance Ops</span><span class="value">Rp <?= number_format($additional['insurance_ops'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['insurance_ops_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['insurance_ops_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Insurance Cargo</span><span class="value">Rp <?= number_format($additional['insurance_cargo'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['insurance_cargo_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['insurance_cargo_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Delivery Cost</span><span class="value">Rp <?= number_format($additional['delivery_cost'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['delivery_cost_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['delivery_cost_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Free Part</span><span class="value">Rp <?= number_format($additional['free_part'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['free_part_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['free_part_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Free Service</span><span class="value">Rp <?= number_format($additional['free_service'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['free_service_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['free_service_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Mediator Fee</span><span class="value">Rp <?= number_format($additional['mediator_fee'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['mediator_fee_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['mediator_fee_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item"><span class="label">Others</span><span class="value">Rp <?= number_format($additional['others'] ?? 0, 0, ',', '.') ?></span><?php if (!empty($additional['others_remark'])): ?><span class="text-muted ms-2">(<?= htmlspecialchars($additional['others_remark']) ?>)</span><?php endif; ?></div>
                        <div class="summary-item" style="background: #cce5ff; border-radius: 6px; margin-top: 8px;"><span class="label" style="font-weight: 700; color: #004085;">Total Additional Cost</span><span class="value" style="font-weight: 700; color: #004085;">Rp <?= number_format($additional['total_additional'] ?? 0, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-coins"></i>
                            <p>Belum ada data Additional Cost.</p>
                            <?php if ($isDataExists): ?>
                                <div class="mt-2">
                                    <a href="detailtr.php?trf=<?= $trf_number ?>&edit=additional&tab=additional" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Tambah Additional Cost</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- TAB 5: MEDIATOR FEE - DIPERBAIKI -->
                <div class="tab-pane fade <?= $activeTab == 'mediator' ? 'show active' : '' ?>" id="mediator" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="section-title mb-0"><i class="fas fa-user-tie"></i> Data Mediator Fee</h5>
                        <?php if ($editMode == 'mediator'): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&tab=mediator" class="btn btn-sm btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
                        <?php elseif ($isDataExists && !empty($mediator['name']) && !empty($mediator['id_card_no'])): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=mediator&tab=mediator" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Edit Mediator</a>
                        <?php elseif ($isDataExists): ?>
                            <a href="detailtr.php?trf=<?= $trf_number ?>&edit=mediator&tab=mediator" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Tambah Mediator</a>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($editMode == 'mediator'): ?>
                        <!-- EDIT MODE MEDIATOR -->
                        <div class="edit-form-container">
                            <div class="row">
                                <div class="col-md-6 mb-2"><label class="form-label">Name <span class="required">*</span></label><input type="text" name="mediator_name" class="form-control form-control-sm" value="<?= htmlspecialchars($mediator['name'] ?? '') ?>" required></div>
                                <div class="col-md-6 mb-2"><label class="form-label">ID Card No <span class="required">*</span></label><input type="text" name="mediator_id_card" class="form-control form-control-sm" value="<?= htmlspecialchars($mediator['id_card_no'] ?? '') ?>" required></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2"><label class="form-label">NPWP No <span class="required">*</span></label><input type="text" name="mediator_npwp" class="form-control form-control-sm" value="<?= htmlspecialchars($mediator['npwp_no'] ?? '') ?>" required></div>
                                <div class="col-md-6 mb-2"><label class="form-label">Bank Name <span class="required">*</span></label><input type="text" name="mediator_bank" class="form-control form-control-sm" value="<?= htmlspecialchars($mediator['bank_name'] ?? '') ?>" required></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2"><label class="form-label">Bank Account <span class="required">*</span></label><input type="text" name="mediator_bank_account" class="form-control form-control-sm" value="<?= htmlspecialchars($mediator['bank_account'] ?? '') ?>" required></div>
                                <div class="col-md-6 mb-2"><label class="form-label">Amount</label><div class="currency-input"><span class="currency-prefix">Rp</span><input type="text" name="mediator_amount" id="mediatorAmount" class="form-control form-control-sm" value="<?= number_format($mediator['amount'] ?? 0, 0, ',', '.') ?>" readonly></div></div>
                            </div>
                            <div class="text-end mt-2">
                                <button type="submit" class="btn btn-sm btn-success-custom" onclick="saveDataToHidden()"><i class="fas fa-save"></i> Simpan Mediator</button>
                                <a href="detailtr.php?trf=<?= $trf_number ?>&tab=mediator" class="btn btn-sm btn-secondary-custom ms-2"><i class="fas fa-times"></i> Batal</a>
                            </div>
                        </div>
                    <?php elseif (!empty($mediator['name']) && !empty($mediator['id_card_no'])): ?>
                        <!-- VIEW MODE MEDIATOR -->
                        <div class="summary-item"><span class="label">Name</span><span class="value"><?= htmlspecialchars($mediator['name'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">ID Card No</span><span class="value"><?= htmlspecialchars($mediator['id_card_no'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">NPWP No</span><span class="value"><?= htmlspecialchars($mediator['npwp_no'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Bank Name</span><span class="value"><?= htmlspecialchars($mediator['bank_name'] ?? '-') ?></span></div>
                        <div class="summary-item"><span class="label">Bank Account</span><span class="value"><?= htmlspecialchars($mediator['bank_account'] ?? '-') ?></span></div>
                        <div class="summary-item" style="background: #fff3cd; border-radius: 6px;"><span class="label" style="font-weight: 700; color: #856404;">Amount</span><span class="value" style="font-weight: 700; color: #856404;">Rp <?= number_format($mediator['amount'] ?? 0, 0, ',', '.') ?></span></div>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-user"></i>
                            <p>Belum ada data Mediator Fee.</p>
                            <?php if ($isDataExists): ?>
                                <div class="mt-2">
                                    <a href="detailtr.php?trf=<?= $trf_number ?>&edit=mediator&tab=mediator" class="btn btn-sm btn-edit-custom"><i class="fas fa-edit"></i> Tambah Mediator</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ACTION BUTTONS -->
        <div class="card-custom">
            <div class="card-body-custom text-center">
                <?php 
                $approvalLevel = isset($detailData['approval_level']) ? (int)$detailData['approval_level'] : 0;
                $status = $detailData['status'] ?? 'draft';
                
                $canApprove = false;
                $role_mapping = [
                    1 => 'sales_manager',
                    2 => 'direktur_sales',
                    3 => 'business',
                    4 => 'direktur_operasional',
                    5 => 'direktur_utama'
                ];
                
                $nextLevel = $approvalLevel + 1;
                if ($nextLevel <= 5 && $status !== 'rejected' && $status !== 'completed') {
                    $nextRole = $role_mapping[$nextLevel] ?? null;
                    if ($nextRole && ($userRole === $nextRole || $hasFullAccess)) {
                        $canApprove = true;
                    }
                }
                ?>
                
                <?php if ($canApprove && $approvalLevel < 5 && $status !== 'rejected' && $status !== 'completed'): ?>
                    <button type="button" class="btn btn-success-custom" onclick="approveTR(<?= $detailData['id'] ?>, '<?= $detailData['trf_number'] ?>', <?= $approvalLevel ?>)">
                        <i class="fas fa-check"></i> Approve
                    </button>
                <?php endif; ?>
                
                <?php if ($approvalLevel < 5 && $status !== 'rejected' && $status !== 'completed' && ($hasFullAccess || $userRole === 'sales')): ?>
                    <button type="button" class="btn btn-danger-custom" onclick="rejectTR(<?= $detailData['id'] ?>, '<?= $detailData['trf_number'] ?>')">
                        <i class="fas fa-times"></i> Reject
                    </button>
                <?php endif; ?>
                
                <a href="detailtr.php" class="btn btn-secondary-custom"><i class="fas fa-arrow-left"></i> Kembali</a>
            </div>
        </div>
    </form>
    
    <!-- Form Approve -->
    <form method="POST" id="formApprove" style="display:none;">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="id" id="approveId">
        <input type="hidden" name="trf_number" id="approveTrfNumber">
        <input type="hidden" name="approval_level" id="approveLevel">
    </form>
    
    <!-- Form Reject -->
    <form method="POST" id="formReject" style="display:none;">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="id" id="rejectId">
        <input type="hidden" name="trf_number" id="rejectTrfNumber">
    </form>
    
    <?php else: ?>
        <div class="alert alert-info"><i class="fas fa-info-circle"></i> Silakan pilih TRF Number terlebih dahulu.</div>
    <?php endif; ?>

    <div class="footer-text">&copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM</div>

</main>

<!-- BOTTOM NAVIGATION -->
<nav class="bottom-nav">
    <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i><span class="nav-label">Home</span></a>
    <?php if (canAccessMenu('account_management')): ?>
        <a href="account_management.php" class="nav-item"><i class="fas fa-building nav-icon"></i><span class="nav-label">Account</span></a>
    <?php endif; ?>
    <?php if (canAccessMenu('sales_activity')): ?>
        <a href="salesactivity.php" class="nav-item"><i class="fas fa-chart-bar nav-icon"></i><span class="nav-label">Sales Activity</span></a>
    <?php endif; ?>
    <?php if (canAccessMenu('transaction_request')): ?>
        <a href="transactionrequest.php" class="nav-item"><i class="fas fa-file-signature nav-icon"></i><span class="nav-label">TR Request</span></a>
    <?php endif; ?>
    <?php if (canAccessMenu('detail_transaction_request')): ?>
        <a href="detailtr.php" class="nav-item active"><i class="fas fa-file-alt nav-icon"></i><span class="nav-label">Detail TR</span></a>
    <?php endif; ?>
    <a href="logout.php" class="nav-item"><i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i><span class="nav-label" style="color:#d63031;">Logout</span></a>
</nav>

<!-- SCRIPTS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // ============================================
    // TAB FUNCTIONS
    // ============================================
    function setActiveTab(tab) {
        document.getElementById('activeTabInput').value = tab;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        // HAPUS PARAMETER EDIT SAAT BERGANTI TAB
        url.searchParams.delete('edit');
        window.history.pushState({}, '', url);
    }

    // ============================================
    // UNIT FUNCTIONS
    // ============================================
    let unitIndex = <?= isset($unitIndex) ? $unitIndex : 0 ?>;

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

    function showOtherInput(select) {
        const row = select.closest('.unit-row');
        const index = row.dataset.index;
        const otherContainer = document.getElementById('otherInputContainer_' + index);
        
        if (otherContainer) {
            if (select.value === 'Other') {
                otherContainer.style.display = 'block';
                const otherInput = otherContainer.querySelector('input');
                if (otherInput) otherInput.required = true;
            } else {
                otherContainer.style.display = 'none';
                const otherInput = otherContainer.querySelector('input');
                if (otherInput) {
                    otherInput.required = false;
                    otherInput.value = '';
                }
            }
        }
    }

    // ============================================
    // TERM OF PAYMENT FUNCTIONS
    // ============================================
    function addDP() {
        const container = document.getElementById('dpContainer');
        const template = `
        <div class="dp-row">
            <input type="text" name="dp_name[]" class="form-control form-control-sm" placeholder="Nama DP">
            <div class="currency-input" style="flex:1;">
                <span class="currency-prefix">Rp</span>
                <input type="text" name="dp_value[]" class="form-control form-control-sm top-input" placeholder="Nilai" oninput="calculateTOP()">
            </div>
            <input type="text" name="dp_remark[]" class="form-control form-control-sm" placeholder="Remark">
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
            <input type="text" name="installment_name[]" class="form-control form-control-sm" placeholder="Nama Angsuran">
            <div class="currency-input" style="flex:1;">
                <span class="currency-prefix">Rp</span>
                <input type="text" name="installment_value[]" class="form-control form-control-sm top-input" placeholder="Nilai" oninput="calculateTOP()">
            </div>
            <input type="text" name="installment_remark[]" class="form-control form-control-sm" placeholder="Remark">
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
    // SAVE DATA TO HIDDEN FIELDS BEFORE SUBMIT - DIPERBAIKI
    // ============================================
    function saveDataToHidden() {
        // 1. SAVE UNITS DATA - hanya jika ada unit di form
        const unitRows = document.querySelectorAll('.unit-row');
        if (unitRows.length > 0) {
            const units = [];
            unitRows.forEach(row => {
                const unitData = {
                    unit_name: row.querySelector('select[name="unit_name[]"]')?.value || '',
                    qty: parseInt(row.querySelector('input[name="qty[]"]')?.value) || 0,
                    price: parseFloat(row.querySelector('input[name="price[]"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                    ppn_percent: 11,
                    ppn: parseFloat(row.querySelector('input[name="ppn[]"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                    grand_total: parseFloat(row.querySelector('input[name="grand_total_unit[]"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                    specification: row.querySelector('input[name="specification[]"]')?.value || '',
                    additional_attachment: row.querySelector('input[name="additional_attachment[]"]')?.value || '',
                    warranty: row.querySelector('input[name="warranty[]"]')?.value || '',
                    machine_location: row.querySelector('input[name="machine_location[]"]')?.value || '',
                    delivery_terms: row.querySelector('input[name="delivery_terms[]"]')?.value || '',
                    delivery_schedule: row.querySelector('input[name="delivery_schedule[]"]')?.value || '',
                    transaction_type: row.querySelector('select[name="transaction_type[]"]')?.value || ''
                };
                if (unitData.unit_name) {
                    units.push(unitData);
                }
            });
            if (units.length > 0) {
                document.getElementById('units_data').value = JSON.stringify(units);
            }
        }
        
        // 2. SAVE TOP DATA - hanya jika ada data TOP di form
        const hasTopData = document.querySelector('input[name="booking_fee"]') || 
                           document.querySelector('input[name="nominal_po_leasing"]') ||
                           document.querySelectorAll('input[name="dp_name[]"]').length > 0 ||
                           document.querySelectorAll('input[name="installment_name[]"]').length > 0;
        
        if (hasTopData) {
            const topData = {
                booking_fee: parseFloat(document.querySelector('input[name="booking_fee"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                booking_fee_remark: document.querySelector('input[name="booking_fee_remark"]')?.value || '',
                nominal_po_leasing: parseFloat(document.querySelector('input[name="nominal_po_leasing"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                nominal_po_leasing_remark: document.querySelector('input[name="nominal_po_leasing_remark"]')?.value || '',
                down_payments: [],
                installments: [],
                grand_total_top: parseFloat(document.getElementById('grandTotalTOPHidden')?.value) || 0
            };
            
            // DP
            const dpNames = document.querySelectorAll('input[name="dp_name[]"]');
            const dpValues = document.querySelectorAll('input[name="dp_value[]"]');
            const dpRemarks = document.querySelectorAll('input[name="dp_remark[]"]');
            for (let i = 0; i < dpNames.length; i++) {
                if (dpNames[i]?.value && dpValues[i]?.value) {
                    topData.down_payments.push({
                        name: dpNames[i].value,
                        value: parseFloat(dpValues[i].value.replace(/\./g, '').replace(/,/g, '')) || 0,
                        remark: dpRemarks[i]?.value || ''
                    });
                }
            }
            
            // Installments
            const instNames = document.querySelectorAll('input[name="installment_name[]"]');
            const instValues = document.querySelectorAll('input[name="installment_value[]"]');
            const instRemarks = document.querySelectorAll('input[name="installment_remark[]"]');
            for (let i = 0; i < instNames.length; i++) {
                if (instNames[i]?.value && instValues[i]?.value) {
                    topData.installments.push({
                        name: instNames[i].value,
                        value: parseFloat(instValues[i].value.replace(/\./g, '').replace(/,/g, '')) || 0,
                        remark: instRemarks[i]?.value || ''
                    });
                }
            }
            
            document.getElementById('top_data').value = JSON.stringify(topData);
        }
        
        // 3. SAVE ADDITIONAL DATA - hanya jika ada data Additional di form
        const additionalInputs = document.querySelectorAll('.additional-input');
        if (additionalInputs.length > 0) {
            const additionalData = {
                insurance_ops: parseFloat(document.querySelector('input[name="insurance_ops"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                insurance_ops_remark: document.querySelector('input[name="insurance_ops_remark"]')?.value || '',
                insurance_cargo: parseFloat(document.querySelector('input[name="insurance_cargo"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                insurance_cargo_remark: document.querySelector('input[name="insurance_cargo_remark"]')?.value || '',
                delivery_cost: parseFloat(document.querySelector('input[name="delivery_cost"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                delivery_cost_remark: document.querySelector('input[name="delivery_cost_remark"]')?.value || '',
                free_part: parseFloat(document.querySelector('input[name="free_part"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                free_part_remark: document.querySelector('input[name="free_part_remark"]')?.value || '',
                free_service: parseFloat(document.querySelector('input[name="free_service"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                free_service_remark: document.querySelector('input[name="free_service_remark"]')?.value || '',
                mediator_fee: parseFloat(document.querySelector('input[name="mediator_fee"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                mediator_fee_remark: document.querySelector('input[name="mediator_fee_remark"]')?.value || '',
                others: parseFloat(document.querySelector('input[name="others_cost"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0,
                others_remark: document.querySelector('input[name="others_remark"]')?.value || '',
                total_additional: parseFloat(document.getElementById('totalAdditionalHidden')?.value) || 0
            };
            document.getElementById('additional_data').value = JSON.stringify(additionalData);
        }
        
        // 4. SAVE MEDIATOR DATA - hanya jika ada data Mediator di form
        const mediatorName = document.querySelector('input[name="mediator_name"]');
        const mediatorIdCard = document.querySelector('input[name="mediator_id_card"]');
        if (mediatorName || mediatorIdCard) {
            const mediatorData = {
                name: mediatorName?.value || '',
                id_card_no: mediatorIdCard?.value || '',
                npwp_no: document.querySelector('input[name="mediator_npwp"]')?.value || '',
                bank_name: document.querySelector('input[name="mediator_bank"]')?.value || '',
                bank_account: document.querySelector('input[name="mediator_bank_account"]')?.value || '',
                amount: parseFloat(document.querySelector('input[name="mediator_amount"]')?.value.replace(/\./g, '').replace(/,/g, '')) || 0
            };
            document.getElementById('mediator_data').value = JSON.stringify(mediatorData);
        }
    }

    // ============================================
    // APPROVAL FUNCTIONS
    // ============================================
    function approveTR(id, trfNumber, currentLevel) {
        if (confirm('Apakah Anda yakin ingin menyetujui TR ini?')) {
            document.getElementById('approveId').value = id;
            document.getElementById('approveTrfNumber').value = trfNumber;
            document.getElementById('approveLevel').value = currentLevel;
            document.getElementById('formApprove').submit();
        }
    }

    function rejectTR(id, trfNumber) {
        if (confirm('Apakah Anda yakin ingin menolak TR ini?')) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectTrfNumber').value = trfNumber;
            document.getElementById('formReject').submit();
        }
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
    // HANDLE TAB CLICKS - HAPUS PARAMETER EDIT
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        // Handle semua klik pada tab
        document.querySelectorAll('.nav-tabs-custom .nav-link').forEach(function(link) {
            link.addEventListener('click', function(e) {
                const url = new URL(this.href);
                // Hapus parameter edit jika ada
                if (url.searchParams.has('edit')) {
                    url.searchParams.delete('edit');
                    this.href = url.toString();
                }
            });
        });
        
        // Initial calculations
        const form = document.getElementById('formDetailTR');
        if (form) {
            form.addEventListener('submit', function(e) {
                saveDataToHidden();
            });
        }
        
        document.querySelectorAll('.qty, .price').forEach(input => {
            const row = input.closest('.unit-row');
            if (row) {
                calculateUnit(input);
            }
        });
        
        calculateTOP();
        calculateAdditional();
        updateMediatorAmount();
        
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