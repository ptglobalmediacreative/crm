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
            font-size: 9px;
            padding: 15px 25px;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.3;
        }
        
        .kop-surat {
            border-bottom: 3px double #1a1a2e;
            padding-bottom: 8px;
            margin-bottom: 10px;
            text-align: center;
        }
        .kop-surat .logo-img {
            max-width: 220px;
            max-height: 80px;
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
            font-size: 10px;
            font-weight: 700;
            background: #f0f2f5;
            padding: 3px 10px;
            margin: 6px 0 4px 0;
            border-left: 3px solid #c9a84c;
            color: #1a1a2e;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
            font-size: 8px;
        }
        table th {
            background: #1a1a2e;
            color: #fff;
            font-weight: 600;
            font-size: 7px;
            text-transform: uppercase;
            padding: 3px 5px;
            text-align: center;
            letter-spacing: 0.3px;
        }
        table td {
            padding: 3px 5px;
            border-bottom: 1px solid #e8edf2;
            font-size: 8px;
            vertical-align: middle;
        }
        table tr:nth-child(even) td {
            background: #fafafa;
        }
        
        .table-info td {
            padding: 1px 3px;
            border: none !important;
            background: transparent !important;
            font-size: 8px;
        }
        .table-info .label-cell {
            font-weight: 600;
            color: #555;
            width: 20%;
        }
        .table-info .value-cell {
            width: 80%;
        }
        
        .two-col {
            display: table;
            width: 100%;
        }
        .two-col .col {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
        }
        .two-col .col:last-child {
            padding-right: 0;
            padding-left: 10px;
        }
        
        .three-col {
            display: table;
            width: 100%;
        }
        .three-col .col {
            display: table-cell;
            width: 33.33%;
            vertical-align: top;
            padding-right: 8px;
        }
        .three-col .col:last-child {
            padding-right: 0;
        }
        
        .grand-total-row {
            border-top: 2px solid #1a1a2e !important;
        }
        .grand-total-row td {
            font-size: 12px !important;
            font-weight: 800 !important;
            color: #c9a84c !important;
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
        .footer .footer-left {
            float: left;
        }
        .footer .footer-right {
            float: right;
        }
        .footer .clearfix {
            clear: both;
        }
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
        
        .total-small {
            font-size: 9px;
            font-weight: 700;
            color: #1a1a2e;
            padding: 2px 0;
        }
        .total-small .label {
            color: #555;
            font-weight: 600;
        }
        .total-small .value {
            color: #c9a84c;
        }
        
        @page {
            margin: 12mm 15mm 12mm 15mm;
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
<div class="two-col">
    <div class="col">
        <table class="table-info">
            <tr><td class="label-cell">Nama PT</td><td class="value-cell">: ' . htmlspecialchars($request['nama_pt'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Badan Usaha</td><td class="value-cell">: ' . htmlspecialchars($request['badan_usaha'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Alamat</td><td class="value-cell">: ' . htmlspecialchars($request['alamat'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">NPWP</td><td class="value-cell">: ' . htmlspecialchars($request['npwp'] ?? '-') . '</td></tr>
        </table>
    </div>
    <div class="col">
        <table class="table-info">
            <tr><td class="label-cell">Nama PIC</td><td class="value-cell">: ' . htmlspecialchars($request['nama_pic'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Jabatan PIC</td><td class="value-cell">: ' . htmlspecialchars($request['jabatan_pic'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">No HP PIC</td><td class="value-cell">: ' . htmlspecialchars($request['no_hp_pic'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Email PIC</td><td class="value-cell">: ' . htmlspecialchars($request['email_pic'] ?? '-') . '</td></tr>
        </table>
    </div>
</div>
<table class="table-info" style="margin-top:1px; width:100%;">
    <tr>
        <td class="label-cell" style="width:10%;">Salesman</td>
        <td class="value-cell" style="width:23%;">: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</td>
        <td class="label-cell" style="width:10%;">Request Date</td>
        <td class="value-cell" style="width:23%;">: ' . date('d/m/Y', strtotime($request['request_date'])) . '</td>
        <td class="label-cell" style="width:10%;">Due Date</td>
        <td class="value-cell" style="width:24%;">: ' . date('d/m/Y', strtotime($request['due_date'])) . '</td>
    </tr>
    <tr>
        <td class="label-cell">Deskripsi</td>
        <td class="value-cell" colspan="5">: ' . nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) . '</td>
    </tr>
</table>

<!-- B. DETAIL UNIT -->
<div class="section-title">B. DETAIL UNIT</div>
';

if (count($detailUnits) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:4%;">No</th>
                <th style="width:16%;">Unit</th>
                <th style="width:5%;">QTY</th>
                <th style="width:13%;">Price (Non PPN)</th>
                <th style="width:10%;">PPN 11%</th>
                <th style="width:14%;">Grand Total</th>
                <th style="width:38%;">Specification</th>
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
                <td style="font-size:7px;">' . htmlspecialchars($unit['specification']) . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <table class="table-info" style="margin-top:2px; width:100%;">
        <tr>
            <td class="label-cell" style="width:12%;">Additional Attachment</td>
            <td class="value-cell" style="width:21%;">: ' . htmlspecialchars($detailUnits[0]['additional_attachment'] ?? '-') . '</td>
            <td class="label-cell" style="width:10%;">Waranty</td>
            <td class="value-cell" style="width:20%;">: ' . htmlspecialchars($detailUnits[0]['waranty'] ?? '-') . '</td>
            <td class="label-cell" style="width:12%;">Transaction Type</td>
            <td class="value-cell" style="width:25%;">: ' . htmlspecialchars($detailUnits[0]['transaction_type'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="label-cell">Machine Location</td>
            <td class="value-cell">: ' . htmlspecialchars($detailUnits[0]['machine_location'] ?? '-') . '</td>
            <td class="label-cell">Delivery Terms</td>
            <td class="value-cell">: ' . htmlspecialchars($detailUnits[0]['delivery_terms'] ?? '-') . '</td>
            <td class="label-cell">Delivery Schedule</td>
            <td class="value-cell">: ' . (isset($detailUnits[0]['delivery_schedule']) ? date('d/m/Y', strtotime($detailUnits[0]['delivery_schedule'])) : '-') . '</td>
        </tr>
    </table>
    ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data Detail Unit</p>';
}

// C. TERM OF PAYMENT & D. ADDITIONAL COST
$html .= '
<div style="display:table; width:100%; margin-top:3px;">
    <div style="display:table-cell; width:55%; vertical-align:top; padding-right:8px;">
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
                <td style="font-size:7px;">' . htmlspecialchars($top['keterangan'] ?? '-') . '</td>
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
    <div style="display:table-cell; width:45%; vertical-align:top; padding-left:8px;">
        <div class="section-title" style="margin-top:0;">D. ADDITIONAL COST</div>
        ';

if ($additionalCost) {
    $html .= '
        <table class="table-info" style="width:100%;">
            <tr><td class="label-cell" style="width:40%;">Insurance Ops</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_ops']) . '</td></tr>
            <tr><td class="label-cell">Insurance Cargo</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_cargo']) . '</td></tr>
            <tr><td class="label-cell">Delivery Cost</td><td class="value-cell">: ' . formatRp($additionalCost['delivery_cost']) . '</td></tr>
            <tr><td class="label-cell">Mediator Fee</td><td class="value-cell">: ' . formatRp($additionalCost['mediator_fee']) . '</td></tr>
            <tr><td class="label-cell">Free Part</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Free Service</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Others</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['others'] ?? '-') . '</td></tr>
        </table>
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
<div class="section-title" style="margin-top:3px;">E. DATA MEDIATOR</div>
';

if ($mediator) {
    $html .= '
    <table class="table-info" style="width:80%; margin:0 auto;">
        <tr>
            <td class="label-cell" style="width:15%;">Name</td>
            <td class="value-cell" style="width:35%;">: ' . htmlspecialchars($mediator['name']) . '</td>
            <td class="label-cell" style="width:15%;">Amount</td>
            <td class="value-cell" style="width:35%;">: <strong>' . formatRp($mediator['amount']) . '</strong></td>
        </tr>
        <tr>
            <td class="label-cell">ID Card</td>
            <td class="value-cell">: ' . htmlspecialchars($mediator['id_card_no'] ?? '-') . '</td>
            <td class="label-cell">NPWP</td>
            <td class="value-cell">: ' . htmlspecialchars($mediator['npwp_no'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="label-cell">Bank Name</td>
            <td class="value-cell" colspan="3">: ' . htmlspecialchars($mediator['bank_name'] ?? '-') . ' - ' . htmlspecialchars($mediator['bank_account'] ?? '-') . '</td>
        </tr>
    </table>
    ';
} else {
    $html .= '<p class="text-center text-muted" style="padding:5px 0;">Belum ada data Mediator</p>';
}

// F. REKAPITULASI TOTAL - TANPA BLOK BIRU BESAR
$html .= '
<div class="section-title">F. REKAPITULASI TOTAL</div>
<div class="three-col" style="margin:3px 0;">
    <div class="col">
        <div class="total-small">
            <span class="label">Total Grand Total Unit</span><br>
            <span class="value" style="font-size:11px;">' . formatRp($totalUnitGrandTotal) . '</span>
        </div>
    </div>
    <div class="col">
        <div class="total-small">
            <span class="label">Total Additional Cost</span><br>
            <span class="value" style="font-size:11px;">' . formatRp($totalAdditionalCost) . '</span>
        </div>
    </div>
    <div class="col">
        <div class="total-small" style="border:2px solid #c9a84c; border-radius:4px; padding:4px 8px; background:#faf8f0;">
            <span class="label" style="color:#1a1a2e;">TOTAL MASUKAN</span><br>
            <span class="value" style="font-size:14px; color:#c9a84c;">' . formatRp($totalMasukan) . '</span>
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