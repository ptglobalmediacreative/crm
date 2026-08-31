<?php
// ============================================
// START OUTPUT BUFFERING
// ============================================
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// SET ZONA WAKTU
// ============================================
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    ob_end_clean();
    die('Silakan login dulu!');
}

// ============================================
// AMBIL TR NUMBER DARI URL
// ============================================
$tr_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';

if (empty($tr_number)) {
    ob_end_clean();
    die('TR Number tidak ditemukan!');
}

// ============================================
// FUNGSI FORMAT RUPIAH
// ============================================
function formatRp($number) {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

// ============================================
// GET ROLE LABEL
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
// AMBIL DATA TRANSACTION REQUEST
// ============================================
$sql = "SELECT ad.tr_number, 
               ad.due_date,
               MIN(ad.created_at) as request_date,
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
               sa.id as sales_activity_id,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'rejected'
                   ) THEN 'rejected'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'pending'
                   ) THEN 'pending'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr 
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci AND dtr.status = 'approved'
                   ) THEN 'approved'
                   ELSE 'pending'
               END as status
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        WHERE ad.tr_number = ?
        GROUP BY ad.tr_number, sa.sales_id, sa.id
        LIMIT 1";
$stmt = $db->prepare($sql);
$stmt->execute([$tr_number]);
$request = $stmt->fetch();

if (!$request) {
    ob_end_clean();
    die('Data transaction request tidak ditemukan!');
}

// ============================================
// AMBIL DATA DETAIL TRANSACTION REQUEST
// ============================================
$detailTR = null;
try {
    $sqlDetail = "SELECT * FROM detail_transaction_requests WHERE trf_number = ?";
    $stmtDetail = $db->prepare($sqlDetail);
    $stmtDetail->execute([$tr_number]);
    $detailTR = $stmtDetail->fetch();
} catch (Exception $e) {
    $detailTR = null;
}

// ============================================
// AMBIL DATA PRODUK
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
// AMBIL DATA ADDITIONAL COST
// ============================================
$additionalCost = null;
try {
    $sqlCost = "SELECT * FROM tr_additional_costs WHERE trf_number = ?";
    $stmtCost = $db->prepare($sqlCost);
    $stmtCost->execute([$tr_number]);
    $additionalCost = $stmtCost->fetch();
} catch (Exception $e) {
    $additionalCost = null;
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
// AMBIL APPROVAL HISTORY (dengan nama lengkap)
// ============================================
$approvalHistory = [];
try {
    $sqlApproval = "SELECT ah.*, u.full_name as approver_name 
                    FROM tr_approval_history ah
                    LEFT JOIN users u ON ah.approved_by = u.id
                    WHERE ah.trf_number = ? 
                    ORDER BY ah.approval_order ASC";
    $stmtApproval = $db->prepare($sqlApproval);
    $stmtApproval->execute([$tr_number]);
    $approvalHistory = $stmtApproval->fetchAll();
} catch (Exception $e) {
    $approvalHistory = [];
}

// ============================================
// HITUNG TOTAL
// ============================================
$totalUnitGrandTotal = 0;
foreach ($detailUnits as $unit) {
    $totalUnitGrandTotal += (float)$unit['grand_total'];
}

$totalTOP = 0;
foreach ($termPayments as $top) {
    $totalTOP += (float)$top['amount'];
}

$totalAdditionalCost = 0;
if ($additionalCost) {
    $totalAdditionalCost = (float)$additionalCost['insurance_ops'] + (float)$additionalCost['insurance_cargo'] + (float)$additionalCost['delivery_cost'] + (float)$additionalCost['mediator_fee'];
}

// Tambahkan total mediator fee dari tabel tr_mediators
$totalMediatorFee = 0;
foreach ($mediators as $med) {
    $totalMediatorFee += (float)$med['amount'];
}
$totalAdditionalCost += $totalMediatorFee;

$totalMasukan = $totalUnitGrandTotal - $totalAdditionalCost;

// ============================================
// GET NAMA PRODUK
// ============================================
function getNamaProduk($unitId, $produkList) {
    foreach ($produkList as $produk) {
        if ($produk['id'] == $unitId) {
            return $produk['nama_produk'];
        }
    }
    return '-';
}

// ============================================
// APPROVAL LEVELS
// ============================================
$approvalLevels = [
    1 => 'Sales Manager',
    2 => 'Direktur Sales',
    3 => 'Divisi Business',
    4 => 'Direktur Operasional',
    5 => 'Direktur Utama'
];

// ============================================
// PATH LOGO - KOP SURAT
// ============================================
$logoPath = 'images/kopsurat.png';

if (!file_exists($logoPath)) {
    $logoHtml = '';
} else {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" class="logo-img" alt="Logo">';
}

// Gabungkan Nama PT dengan Badan Usaha
$namaPT = $request['nama_pt'] ?? '-';
$badanUsaha = $request['badan_usaha'] ?? '';
if (!empty($badanUsaha) && $namaPT != '-') {
    $namaPTDisplay = $namaPT . ', ' . $badanUsaha;
} else {
    $namaPTDisplay = $namaPT;
}

// ============================================
// CLEAN OUTPUT BUFFER SEBELUM PDF
// ============================================
ob_end_clean();

// ============================================
// BUILD HTML PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <title>Detail TR - ' . htmlspecialchars($tr_number) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: "Helvetica", Arial, sans-serif; 
            font-size: 10px;
            padding: 15px 20px;
            background: #fff;
            color: #222;
            line-height: 1.6;
        }
        
        /* KOP SURAT */
        .kop-surat {
            text-align: center;
            border-bottom: 3px double #222;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .kop-surat .logo-img {
            max-width: 100%;
            max-height: 70px;
            width: auto;
            height: auto;
        }
        
        /* JUDUL */
        .judul-laporan {
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #222;
            margin: 5px 0 3px 0;
        }
        .info-tr {
            text-align: center;
            font-size: 9px;
            color: #555;
            margin-bottom: 5px;
        }
        .info-tr .label {
            font-weight: 600;
            color: #222;
        }
        
        /* SECTION TITLE */
        .section-title {
            font-size: 10px;
            font-weight: 700;
            border-bottom: 1.5px solid #222;
            padding: 4px 5px 2px 5px;
            margin: 10px 0 5px 0;
            color: #222;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f5f5f5;
            border-radius: 2px;
        }
        
        /* INFO ROW */
        .info-row {
            padding: 1px 0;
            font-size: 9px;
        }
        .info-row .label {
            display: inline-block;
            font-weight: 600;
            color: #555;
            width: 110px;
        }
        
        .info-row-2col {
            display: flex;
            flex-wrap: wrap;
        }
        .info-row-2col .col {
            flex: 1;
            min-width: 45%;
            padding-right: 15px;
        }
        .info-row-2col .col:last-child {
            padding-right: 0;
        }
        .info-row-2col .info-row .label {
            width: 90px;
        }
        
        .info-row-left {
            padding: 1px 0;
            font-size: 9px;
        }
        .info-row-left .label {
            display: inline-block;
            font-weight: 600;
            color: #555;
            width: 110px;
        }
        
        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            font-size: 9px;
        }
        table th {
            background: #222;
            color: #fff;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            padding: 4px 5px;
            text-align: center;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 4px 5px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 9px;
            vertical-align: middle;
        }
        table tr:nth-child(even) td {
            background: #fafafa;
        }
        table tr:last-child td {
            border-bottom: none;
        }
        
        /* THREE COLUMN BOX */
        .three-col {
            display: flex;
            flex-wrap: wrap;
            margin: 5px 0;
            gap: 8px;
        }
        .three-col .col {
            flex: 1;
            min-width: 30%;
            text-align: center;
        }
        .three-col .col .box {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 6px 8px;
            background: #f9f9f9;
        }
        .three-col .col .box.gold {
            border: 2px solid #c9a84c;
            background: #faf8f0;
        }
        .three-col .col .box .label {
            font-size: 7px;
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
        }
        .three-col .col .box .value {
            font-size: 10px;
            font-weight: 700;
            color: #c9a84c;
        }
        .three-col .col .box.gold .value {
            font-size: 11px;
        }
        .three-col .col .box.gold .label {
            color: #222;
            font-weight: 700;
        }
        
        /* STATUS */
        .approval-status-approved { color: #28a745; font-weight: 700; }
        .approval-status-rejected { color: #dc3545; font-weight: 700; }
        .approval-status-pending { color: #ffc107; font-weight: 700; }
        
        /* FOOTER - HANYA DI AKHIR DOKUMEN */
        .footer-end {
            margin-top: 20px;
            padding: 8px 0;
            border-top: 2px solid #222;
            font-size: 7px;
            color: #555;
            text-align: center;
            background: #fff;
        }
        .footer-end .footer-alamat {
            font-size: 7px;
            color: #555;
            margin-top: 2px;
            line-height: 1.4;
        }
        .footer-end .footer-note {
            margin-top: 2px;
            font-size: 6px;
            color: #aaa;
        }
        
        /* UTILITY */
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: 700; }
        .mt-5 { margin-top: 5px; }
        .mb-5 { margin-bottom: 5px; }
        
        @page {
            margin: 10mm 15mm 10mm 15mm;
        }
    </style>
</head>
<body>

<!-- KOP SURAT -->
<div class="kop-surat">' . $logoHtml . '</div>

<!-- JUDUL -->
<div class="judul-laporan">DETAIL TRANSACTION REQUEST</div>
<div class="info-tr">
    Nomor TR: <strong>' . htmlspecialchars($tr_number) . '</strong> 
    &nbsp;|&nbsp; 
    <span class="label">Request Date</span>: ' . date('d/m/Y', strtotime($request['request_date'])) . '
    &nbsp;|&nbsp; 
    <span class="label">Status</span>: ' . ucfirst($request['status']) . '
</div>

<!-- A. DATA ACCOUNT -->
<div class="section-title">A. Data Account</div>

<div class="info-row-2col">
    <div class="col">
        <div class="info-row"><span class="label">Nama PT</span>: ' . htmlspecialchars($namaPTDisplay) . '</div>
        <div class="info-row"><span class="label">Alamat</span>: ' . htmlspecialchars($request['alamat'] ?? '-') . '</div>
        <div class="info-row"><span class="label">NPWP</span>: ' . htmlspecialchars($request['npwp'] ?? '-') . '</div>
    </div>
    <div class="col">
        <div class="info-row"><span class="label">Nama PIC</span>: ' . htmlspecialchars($request['nama_pic'] ?? '-') . '</div>
        <div class="info-row"><span class="label">Jabatan PIC</span>: ' . htmlspecialchars($request['jabatan_pic'] ?? '-') . '</div>
        <div class="info-row"><span class="label">No HP PIC</span>: ' . htmlspecialchars($request['no_hp_pic'] ?? '-') . '</div>
        <div class="info-row"><span class="label">Email PIC</span>: ' . htmlspecialchars($request['email_pic'] ?? '-') . '</div>
    </div>
</div>

<div class="info-row"><span class="label">Salesman</span>: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</div>
<div class="info-row"><span class="label">Deskripsi</span>: ' . nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) . '</div>

<!-- B. DETAIL UNIT -->
<div class="section-title">B. Detail Unit</div>
';

if (count($detailUnits) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:5%;">No</th>
                <th style="width:18%;">Unit</th>
                <th style="width:6%;">QTY</th>
                <th style="width:15%;">Price (Non PPN)</th>
                <th style="width:10%;">PPN 11%</th>
                <th style="width:16%;">Grand Total</th>
                <th style="width:30%;">Specification</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    foreach ($detailUnits as $unit) {
        $namaUnit = getNamaProduk($unit['unit_id'], $produkList);
        $html .= '
            <tr>
                <td class="text-center">' . $no++ . '</td>
                <td>' . htmlspecialchars($namaUnit) . '</td>
                <td class="text-center">' . $unit['qty'] . '</td>
                <td class="text-right">' . formatRp($unit['price']) . '</td>
                <td class="text-right">' . formatRp($unit['ppn']) . '</td>
                <td class="text-right fw-bold">' . formatRp($unit['grand_total']) . '</td>
                <td style="font-size:8px;">' . htmlspecialchars($unit['specification']) . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>';
    
    // Detail tambahan untuk setiap unit
    foreach ($detailUnits as $index => $unit) {
        $html .= '
    <div style="font-size:8px; margin-top:2px; padding:3px 5px; background:#f9f9f9; border-left:2px solid #c9a84c;">
        <strong>Unit ' . ($index + 1) . ' - ' . htmlspecialchars(getNamaProduk($unit['unit_id'], $produkList)) . ':</strong> 
        Additional Attachment: ' . htmlspecialchars($unit['additional_attachment'] ?? '-') . ' | 
        Waranty: ' . htmlspecialchars($unit['waranty'] ?? '-') . ' | 
        Machine Location: ' . htmlspecialchars($unit['machine_location'] ?? '-') . ' | 
        Delivery Terms: ' . htmlspecialchars($unit['delivery_terms'] ?? '-') . ' | 
        Delivery Schedule: ' . (isset($unit['delivery_schedule']) && !empty($unit['delivery_schedule']) ? date('d/m/Y', strtotime($unit['delivery_schedule'])) : '-') . ' | 
        Transaction Type: ' . htmlspecialchars($unit['transaction_type'] ?? '-') . '
    </div>';
    }
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data Detail Unit</p>';
}

// C. TERM OF PAYMENT & D. ADDITIONAL COST
$html .= '
<div style="display:flex; flex-wrap:wrap; margin-top:5px;">
    <div style="flex:1; min-width:50%; padding-right:10px;">
        <div class="section-title" style="margin-top:0;">C. Term Of Payment</div>
        ';

if (count($termPayments) > 0) {
    $html .= '
        <table>
            <thead>
                <tr>
                    <th style="width:6%;">No</th>
                    <th style="width:34%;">Payment</th>
                    <th style="width:28%;" class="text-right">Amount</th>
                    <th style="width:32%;">Keterangan</th>
                </tr>
            </thead>
            <tbody>';
    
    $no = 1;
    foreach ($termPayments as $top) {
        $html .= '
            <tr>
                <td class="text-center">' . $no++ . '</td>
                <td>' . htmlspecialchars($top['payment_label']) . '</td>
                <td class="text-right fw-bold">' . formatRp($top['amount']) . '</td>
                <td style="font-size:8px;">' . htmlspecialchars($top['keterangan'] ?? '-') . '</td>
            </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
        <div style="font-size:8px; text-align:right; margin-top:2px;">
            <strong>Total TOP: ' . formatRp($totalTOP) . '</strong>
        </div>
        ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data TOP</p>';
}

$html .= '
    </div>
    <div style="flex:1; min-width:40%; padding-left:10px;">
        <div class="section-title" style="margin-top:0;">D. Additional Cost</div>
        ';

if ($additionalCost) {
    $html .= '
        <div class="info-row-left"><span class="label">Insurance Ops</span>: ' . formatRp($additionalCost['insurance_ops']) . '</div>
        <div class="info-row-left"><span class="label">Insurance Cargo</span>: ' . formatRp($additionalCost['insurance_cargo']) . '</div>
        <div class="info-row-left"><span class="label">Delivery Cost</span>: ' . formatRp($additionalCost['delivery_cost']) . '</div>
        <div class="info-row-left"><span class="label">Mediator Fee (Additional)</span>: ' . formatRp($additionalCost['mediator_fee']) . '</div>
        <div class="info-row-left"><span class="label">Free Part</span>: ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</div>
        <div class="info-row-left"><span class="label">Free Service</span>: ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</div>
        <div class="info-row-left"><span class="label">Others</span>: ' . htmlspecialchars($additionalCost['others'] ?? '-') . '</div>
        ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data</p>';
}

$html .= '
    </div>
</div>
';

// E. DATA MEDIATOR (MULTIPLE)
$html .= '
<div class="section-title" style="margin-top:5px;">E. Data Mediator</div>
';

if (count($mediators) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:5%;">No</th>
                <th style="width:18%;">Name</th>
                <th style="width:15%;">ID Card</th>
                <th style="width:15%;">NPWP</th>
                <th style="width:15%;">Bank Name</th>
                <th style="width:15%;">Bank Account</th>
                <th style="width:17%;" class="text-right">Amount</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    foreach ($mediators as $med) {
        $html .= '
            <tr>
                <td class="text-center">' . $no++ . '</td>
                <td>' . htmlspecialchars($med['name']) . '</td>
                <td>' . htmlspecialchars($med['id_card_no'] ?? '-') . '</td>
                <td>' . htmlspecialchars($med['npwp_no'] ?? '-') . '</td>
                <td>' . htmlspecialchars($med['bank_name'] ?? '-') . '</td>
                <td>' . htmlspecialchars($med['bank_account'] ?? '-') . '</td>
                <td class="text-right fw-bold">' . formatRp($med['amount']) . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    <div style="font-size:8px; text-align:right; margin-top:2px;">
        <strong>Total Mediator Fee: ' . formatRp($totalMediatorFee) . '</strong>
    </div>
    ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data Mediator</p>';
}

// F. REKAPITULASI TOTAL
$html .= '
<div class="section-title">F. Rekapitulasi Total</div>
<div class="three-col">
    <div class="col">
        <div class="box">
            <div class="label">Total Grand Total Unit</div>
            <div class="value">' . formatRp($totalUnitGrandTotal) . '</div>
        </div>
    </div>
    <div class="col">
        <div class="box">
            <div class="label">Total Additional Cost</div>
            <div class="value">' . formatRp($totalAdditionalCost) . '</div>
        </div>
    </div>
    <div class="col">
        <div class="box gold">
            <div class="label">Total Masukan</div>
            <div class="value">' . formatRp($totalMasukan) . '</div>
        </div>
    </div>
</div>
';

// G. APPROVAL HISTORY
if (count($approvalHistory) > 0) {
    $html .= '
    <div class="section-title">G. Approval History</div>
    <table>
        <thead>
            <tr>
                <th style="width:10%;">Level</th>
                <th style="width:22%;">Role</th>
                <th style="width:18%;">Status</th>
                <th style="width:28%;">Approved By</th>
                <th style="width:22%;">Approved At</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($approvalHistory as $approval) {
        $levelNum = $approval['approval_order'];
        $levelLabel = $approvalLevels[$levelNum] ?? 'Level ' . $levelNum;
        $statusLabel = ucfirst($approval['status']);
        $statusClass = $approval['status'] == 'approved' ? 'approval-status-approved' : ($approval['status'] == 'rejected' ? 'approval-status-rejected' : 'approval-status-pending');
        $approverName = !empty($approval['approver_name']) ? $approval['approver_name'] : ($approval['approved_by'] ?? '-');
        $approvedAt = !empty($approval['approved_at']) ? date('d/m/Y H:i', strtotime($approval['approved_at'])) : '-';
        
        $html .= '
            <tr>
                <td class="text-center">Level ' . $levelNum . '</td>
                <td>' . htmlspecialchars($levelLabel) . '</td>
                <td class="text-center"><span class="' . $statusClass . '">' . $statusLabel . '</span></td>
                <td>' . htmlspecialchars($approverName) . '</td>
                <td class="text-center">' . $approvedAt . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    ';
}

// FOOTER - HANYA SEKALI DI AKHIR DOKUMEN (BUKAN FIXED)
$html .= '
<div class="footer-end">
    <div><strong>PT GANDA ELANG TANGGUH</strong> - CRM</div>
    <div class="footer-alamat">Jelambar Barat III Ruko 45R No. 16 RT 014, Jelambar Baru, Grogol Petamburan<br>Kota Adm. Jakarta Barat - DKI Jakarta | Phone : +62 812 8058 8567 | Email : info@gandaelang.com</div>
    <div class="footer-note">Dokumen ini dicetak dari sistem CRM. Mohon periksa keaslian dokumen.</div>
</div>

</body>
</html>
';

// ============================================
// GENERATE PDF
// ============================================
$options = new Options();
$options->set('defaultFont', 'helvetica');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('debugPng', false);
$options->set('debugKeepTemp', false);
$options->set('tempDir', sys_get_temp_dir());

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'TR_' . $tr_number . '_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;