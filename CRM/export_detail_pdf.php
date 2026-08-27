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
    return 'Rp ' . number_format($number, 0, ',', '.');
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
// AMBIL DATA MEDIATOR
// ============================================
$mediator = null;
try {
    $sqlMediator = "SELECT * FROM tr_mediators WHERE trf_number = ?";
    $stmtMediator = $db->prepare($sqlMediator);
    $stmtMediator->execute([$tr_number]);
    $mediator = $stmtMediator->fetch();
} catch (Exception $e) {
    $mediator = null;
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
    $totalUnitGrandTotal += $unit['grand_total'];
}

$totalTOP = 0;
foreach ($termPayments as $top) {
    $totalTOP += $top['amount'];
}

$totalAdditionalCost = 0;
if ($additionalCost) {
    $totalAdditionalCost = $additionalCost['insurance_ops'] + $additionalCost['insurance_cargo'] + $additionalCost['delivery_cost'] + $additionalCost['mediator_fee'];
}

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
    $logoHtml = '<div style="color:red; font-size:12px;">LOGO TIDAK DITEMUKAN</div>';
} else {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" class="logo-img" alt="Logo">';
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
            font-family: "Times New Roman", Times, serif; 
            font-size: 10px;
            padding: 15px 25px;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.5;
        }
        
        .kop-surat {
            border-bottom: 3px double #1a1a2e;
            padding-bottom: 8px;
            margin-bottom: 10px;
            text-align: center;
        }
        .kop-surat .logo-img {
            max-width: 200px;
            max-height: 70px;
            width: auto;
            height: auto;
            object-fit: contain;
        }
        
        .judul-laporan {
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #1a1a2e;
            margin: 6px 0 3px 0;
        }
        .sub-judul {
            text-align: center;
            font-size: 9px;
            color: #666;
            margin-bottom: 8px;
        }
        .status-badge {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-badge.pending { background: #fef9e7; color: #b7950b; }
        .status-badge.approved { background: #ebf5fb; color: #1a5276; }
        .status-badge.rejected { background: #fdedec; color: #922b21; }
        
        .section-title {
            font-size: 11px;
            font-weight: 700;
            border-bottom: 1px solid #1a1a2e;
            padding: 4px 0 2px 0;
            margin: 8px 0 5px 0;
            color: #1a1a2e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 1px;
        }
        .info-row .label {
            display: table-cell;
            width: 18%;
            font-weight: 600;
            color: #555;
            padding: 1px 0;
        }
        .info-row .value {
            display: table-cell;
            width: 82%;
            padding: 1px 0;
        }
        
        .info-row-2col {
            display: table;
            width: 100%;
        }
        .info-row-2col .col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 15px;
        }
        .info-row-2col .col:last-child {
            padding-right: 0;
        }
        .info-row-2col .info-row .label {
            width: 25%;
        }
        .info-row-2col .info-row .value {
            width: 75%;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
            font-size: 9px;
        }
        table th {
            background: #1a1a2e;
            color: #fff;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            padding: 3px 5px;
            text-align: center;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 3px 5px;
            border-bottom: 1px solid #e8edf2;
            font-size: 9px;
            vertical-align: middle;
        }
        table tr:last-child td {
            border-bottom: none;
        }
        
        .three-col {
            display: table;
            width: 100%;
            margin: 5px 0;
        }
        .three-col .col {
            display: table-cell;
            width: 33.33%;
            vertical-align: top;
            text-align: center;
            padding: 0 5px;
        }
        .three-col .col .box {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 6px 4px;
            background: #fafafa;
        }
        .three-col .col .box.gold {
            border: 2px solid #c9a84c;
            background: #faf8f0;
        }
        .three-col .col .box .label {
            font-size: 8px;
            font-weight: 600;
            color: #555;
        }
        .three-col .col .box .value {
            font-size: 12px;
            font-weight: 700;
            color: #c9a84c;
        }
        .three-col .col .box.gold .value {
            font-size: 14px;
        }
        .three-col .col .box.gold .label {
            color: #1a1a2e;
            font-weight: 700;
        }
        
        .approval-status-approved { color: #27ae60; font-weight: 700; }
        .approval-status-rejected { color: #e74c3c; font-weight: 700; }
        .approval-status-pending { color: #f39c12; font-weight: 700; }
        
        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 2px solid #1a1a2e;
            font-size: 7px;
            color: #555;
            text-align: center;
        }
        .footer .footer-left { float: left; }
        .footer .footer-right { float: right; }
        .footer .clearfix { clear: both; }
        .footer .footer-alamat {
            font-size: 7px;
            color: #555;
            margin-top: 2px;
            line-height: 1.4;
        }
        .footer .footer-note {
            margin-top: 2px;
            font-size: 6px;
            color: #aaa;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .fw-bold { font-weight: 700; }
        .text-muted { color: #999; }
        
        @page {
            margin: 10mm 15mm 10mm 15mm;
        }
    </style>
</head>
<body>

<!-- KOP SURAT -->
<div class="kop-surat">
    ' . $logoHtml . '
</div>

<!-- JUDUL -->
<div class="judul-laporan">DETAIL TRANSACTION REQUEST</div>
<div class="sub-judul">
    Nomor TR: <strong>' . htmlspecialchars($tr_number) . '</strong> 
    | Status: <span class="status-badge ' . $request['status'] . '">' . ucfirst($request['status']) . '</span>
    | Tanggal Cetak: ' . date('d/m/Y H:i') . '
</div>

<!-- A. DATA ACCOUNT -->
<div class="section-title">A. DATA ACCOUNT</div>

<div class="info-row-2col">
    <div class="col">
        <div class="info-row">
            <span class="label">Nama PT</span>
            <span class="value">: ' . htmlspecialchars($request['nama_pt'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">Badan Usaha</span>
            <span class="value">: ' . htmlspecialchars($request['badan_usaha'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">Alamat</span>
            <span class="value">: ' . htmlspecialchars($request['alamat'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">NPWP</span>
            <span class="value">: ' . htmlspecialchars($request['npwp'] ?? '-') . '</span>
        </div>
    </div>
    <div class="col">
        <div class="info-row">
            <span class="label">Nama PIC</span>
            <span class="value">: ' . htmlspecialchars($request['nama_pic'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">Jabatan PIC</span>
            <span class="value">: ' . htmlspecialchars($request['jabatan_pic'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">No HP PIC</span>
            <span class="value">: ' . htmlspecialchars($request['no_hp_pic'] ?? '-') . '</span>
        </div>
        <div class="info-row">
            <span class="label">Email PIC</span>
            <span class="value">: ' . htmlspecialchars($request['email_pic'] ?? '-') . '</span>
        </div>
    </div>
</div>

<div class="info-row">
    <span class="label" style="width:10%;">Salesman</span>
    <span class="value" style="width:23%;">: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</span>
    <span class="label" style="width:10%;">Request Date</span>
    <span class="value" style="width:23%;">: ' . date('d/m/Y', strtotime($request['request_date'])) . '</span>
    <span class="label" style="width:10%;">Due Date</span>
    <span class="value" style="width:24%;">: ' . date('d/m/Y', strtotime($request['due_date'])) . '</span>
</div>

<div class="info-row">
    <span class="label" style="width:10%;">Deskripsi</span>
    <span class="value" style="width:90%;">: ' . nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) . '</span>
</div>

<!-- B. DETAIL UNIT -->
<div class="section-title">B. DETAIL UNIT</div>
';

if (count($detailUnits) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:4%;">No</th>
                <th style="width:18%;">Unit</th>
                <th style="width:5%;">QTY</th>
                <th style="width:14%;">Price (Non PPN)</th>
                <th style="width:10%;">PPN 11%</th>
                <th style="width:15%;">Grand Total</th>
                <th style="width:34%;">Specification</th>
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
    </table>
    
    <div style="display:table; width:100%; margin-top:3px; font-size:8px;">
        <div style="display:table-cell; width:33%;">
            <strong>Additional Attachment</strong> : ' . htmlspecialchars($detailUnits[0]['additional_attachment'] ?? '-') . '
        </div>
        <div style="display:table-cell; width:33%;">
            <strong>Waranty</strong> : ' . htmlspecialchars($detailUnits[0]['waranty'] ?? '-') . '
        </div>
        <div style="display:table-cell; width:34%;">
            <strong>Transaction Type</strong> : ' . htmlspecialchars($detailUnits[0]['transaction_type'] ?? '-') . '
        </div>
    </div>
    <div style="display:table; width:100%; font-size:8px;">
        <div style="display:table-cell; width:33%;">
            <strong>Machine Location</strong> : ' . htmlspecialchars($detailUnits[0]['machine_location'] ?? '-') . '
        </div>
        <div style="display:table-cell; width:33%;">
            <strong>Delivery Terms</strong> : ' . htmlspecialchars($detailUnits[0]['delivery_terms'] ?? '-') . '
        </div>
        <div style="display:table-cell; width:34%;">
            <strong>Delivery Schedule</strong> : ' . (isset($detailUnits[0]['delivery_schedule']) ? date('d/m/Y', strtotime($detailUnits[0]['delivery_schedule'])) : '-') . '
        </div>
    </div>
    ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data Detail Unit</p>';
}

// C. TERM OF PAYMENT & D. ADDITIONAL COST
$html .= '
<div style="display:table; width:100%; margin-top:5px;">
    <div style="display:table-cell; width:55%; vertical-align:top; padding-right:10px;">
        <div class="section-title" style="margin-top:0;">C. TERM OF PAYMENT</div>
        ';

if (count($termPayments) > 0) {
    $html .= '
        <table>
            <thead>
                <tr>
                    <th style="width:6%;">No</th>
                    <th style="width:34%;">Payment</th>
                    <th style="width:30%;" class="text-right">Amount</th>
                    <th style="width:30%;">Keterangan</th>
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
        ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data TOP</p>';
}

$html .= '
    </div>
    <div style="display:table-cell; width:45%; vertical-align:top; padding-left:10px;">
        <div class="section-title" style="margin-top:0;">D. ADDITIONAL COST</div>
        ';

if ($additionalCost) {
    $html .= '
        <div style="font-size:8px; line-height:1.6;">
            <div><strong>Insurance Ops</strong> : ' . formatRp($additionalCost['insurance_ops']) . '</div>
            <div><strong>Insurance Cargo</strong> : ' . formatRp($additionalCost['insurance_cargo']) . '</div>
            <div><strong>Delivery Cost</strong> : ' . formatRp($additionalCost['delivery_cost']) . '</div>
            <div><strong>Mediator Fee</strong> : ' . formatRp($additionalCost['mediator_fee']) . '</div>
            <div><strong>Free Part</strong> : ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</div>
            <div><strong>Free Service</strong> : ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</div>
            <div><strong>Others</strong> : ' . htmlspecialchars($additionalCost['others'] ?? '-') . '</div>
        </div>
        ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data</p>';
}

$html .= '
    </div>
</div>
';

// E. DATA MEDIATOR
$html .= '
<div class="section-title" style="margin-top:5px;">E. DATA MEDIATOR</div>
';

if ($mediator) {
    $html .= '
    <div style="display:table; width:80%; margin:0 auto; font-size:8px;">
        <div style="display:table-row;">
            <div style="display:table-cell; width:15%; font-weight:600; color:#555;">Name</div>
            <div style="display:table-cell; width:35%;">: ' . htmlspecialchars($mediator['name']) . '</div>
            <div style="display:table-cell; width:15%; font-weight:600; color:#555;">Amount</div>
            <div style="display:table-cell; width:35%;">: <strong>' . formatRp($mediator['amount']) . '</strong></div>
        </div>
        <div style="display:table-row;">
            <div style="display:table-cell; font-weight:600; color:#555;">ID Card</div>
            <div style="display:table-cell;">: ' . htmlspecialchars($mediator['id_card_no'] ?? '-') . '</div>
            <div style="display:table-cell; font-weight:600; color:#555;">NPWP</div>
            <div style="display:table-cell;">: ' . htmlspecialchars($mediator['npwp_no'] ?? '-') . '</div>
        </div>
        <div style="display:table-row;">
            <div style="display:table-cell; font-weight:600; color:#555;">Bank Name</div>
            <div style="display:table-cell;" colspan="3">: ' . htmlspecialchars($mediator['bank_name'] ?? '-') . ' - ' . htmlspecialchars($mediator['bank_account'] ?? '-') . '</div>
        </div>
    </div>
    ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data Mediator</p>';
}

// F. REKAPITULASI TOTAL
$html .= '
<div class="section-title">F. REKAPITULASI TOTAL</div>
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
            <div class="label">TOTAL MASUKAN</div>
            <div class="value">' . formatRp($totalMasukan) . '</div>
        </div>
    </div>
</div>
';

// G. APPROVAL HISTORY
if (count($approvalHistory) > 0) {
    $html .= '
    <div class="section-title">G. APPROVAL HISTORY</div>
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
        
        $html .= '
            <tr>
                <td class="text-center">Level ' . $levelNum . '</td>
                <td>' . htmlspecialchars($levelLabel) . '</td>
                <td class="text-center"><span class="' . $statusClass . '">' . $statusLabel . '</span></td>
                <td>' . htmlspecialchars($approverName) . '</td>
                <td class="text-center">' . ($approval['approved_at'] ? date('d/m/Y H:i', strtotime($approval['approved_at'])) : '-') . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    ';
}

// FOOTER
$html .= '
<div class="footer">
    <div class="footer-left">
        <strong>PT GANDA ELANG TANGGUH</strong> - CRM
    </div>
    <div class="footer-right">
        Dicetak: ' . date('d/m/Y H:i') . ' | Halaman {PAGE_NUM}
    </div>
    <div class="clearfix"></div>
    <div class="footer-alamat">
        Jelambar Barat III Ruko 45R No. 16 RT 014, Jelambar Baru, Grogol Petamburan<br>
        Kota Adm. Jakarta Barat - DKI Jakarta | Phone : +62 812 8058 8567 | Email : info@gandaelang.com
    </div>
    <div class="footer-note">
        Dokumen ini dicetak dari sistem CRM. Mohon periksa keaslian dokumen.
    </div>
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