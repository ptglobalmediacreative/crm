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
            line-height: 1.3;
        }
        
        /* KOP SURAT - Dengan Logo */
        .kop-surat {
            text-align: center;
            border-bottom: 3px double #0e1a2b;
            padding-bottom: 8px;
            margin-bottom: 10px;
            position: relative;
        }
        .kop-surat .logo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        .kop-surat .logo-placeholder {
            width: 70px;
            height: 70px;
            border: 2px solid #0e1a2b;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            font-weight: 900;
            color: #0e1a2b;
            flex-shrink: 0;
            background: #f8f8f8;
        }
        .kop-surat .logo-placeholder span {
            color: #c9a84c;
        }
        .kop-surat .title-section {
            text-align: left;
        }
        .kop-surat .title-section .nama-pt {
            font-size: 20px;
            font-weight: 900;
            letter-spacing: 1px;
            color: #0e1a2b;
        }
        .kop-surat .title-section .nama-pt span {
            color: #c9a84c;
        }
        .kop-surat .title-section .sub-title {
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 2px;
            color: #555;
        }
        .kop-surat .title-section .alamat {
            font-size: 9px;
            color: #666;
            margin-top: 2px;
        }
        .kop-surat .garis-bawah {
            border-bottom: 2px solid #0e1a2b;
            margin-top: 3px;
        }
        
        /* JUDUL LAPORAN */
        .judul-laporan {
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 6px 0 4px 0;
            color: #0e1a2b;
        }
        .sub-judul {
            text-align: center;
            font-size: 10px;
            color: #666;
            margin-bottom: 8px;
        }
        .status-badge {
            display: inline-block;
            padding: 1px 10px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-badge.pending { background: #fef9e7; color: #b7950b; }
        .status-badge.approved { background: #ebf5fb; color: #1a5276; }
        .status-badge.rejected { background: #fdedec; color: #922b21; }
        
        /* TABEL DATA */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
            font-size: 9px;
        }
        table th {
            background: #0e1a2b;
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
        table tr:nth-child(even) td {
            background: #fafafa;
        }
        .label-cell {
            font-weight: 600;
            color: #555;
            width: 25%;
        }
        .value-cell {
            width: 75%;
        }
        .table-info td {
            padding: 2px 4px;
            border: none;
            background: transparent !important;
            font-size: 9px;
        }
        .table-info .label-cell {
            width: 20%;
        }
        .table-info .value-cell {
            width: 80%;
        }
        
        /* SECTION TITLE */
        .section-title {
            font-size: 10px;
            font-weight: 700;
            background: #f0f2f5;
            padding: 3px 8px;
            margin: 6px 0 4px 0;
            border-left: 3px solid #c9a84c;
            color: #0e1a2b;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        
        /* TOTAL BOX */
        .total-box {
            background: #0e1a2b;
            color: #fff;
            padding: 4px 10px;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 3px 0 2px 0;
        }
        .total-box .total-label {
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: rgba(255,255,255,0.7);
        }
        .total-box .total-value {
            font-size: 11px;
            font-weight: 700;
            color: #ffd700;
        }
        
        .grand-total-row {
            border-top: 2px solid #0e1a2b !important;
        }
        .grand-total-row td {
            font-size: 12px !important;
            font-weight: 800 !important;
            color: #c9a84c !important;
        }
        
        /* FOOTER */
        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 2px solid #0e1a2b;
            font-size: 8px;
            color: #888;
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
        
        @page {
            margin: 12mm 15mm 12mm 15mm;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: 700; }
        .mt-1 { margin-top: 3px; }
        .mb-1 { margin-bottom: 3px; }
        .text-muted { color: #999; }
        .text-success { color: #27ae60; }
        .text-danger { color: #e74c3c; }
        .text-warning { color: #f39c12; }
        
        .approval-status-approved { color: #27ae60; font-weight: 700; }
        .approval-status-rejected { color: #e74c3c; font-weight: 700; }
        .approval-status-pending { color: #f39c12; font-weight: 700; }
        
        /* 2 column layout untuk ringkas */
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
    </style>
</head>
<body>

<!-- ========================================== -->
<!-- KOP SURAT DENGAN LOGO -->
<!-- ========================================== -->
<div class="kop-surat">
    <div class="logo-container">
        <div class="logo-placeholder">
            <span>G</span>E<span>T</span>
        </div>
        <div class="title-section">
            <div class="nama-pt">PT GANDA ELANG <span>TANGGUH</span></div>
            <div class="sub-title">CUSTOMER RELATIONSHIP MANAGEMENT</div>
            <div class="alamat">
                Jl. Raya Industrial Estate, Blok A No. 12, Jakarta 12345<br>
                Telp: (021) 1234-5678 | Fax: (021) 1234-5679 | Email: crm@get.co.id
            </div>
        </div>
    </div>
    <div class="garis-bawah"></div>
</div>

<!-- ========================================== -->
<!-- JUDUL -->
<!-- ========================================== -->
<div class="judul-laporan">DETAIL TRANSACTION REQUEST</div>
<div class="sub-judul">
    Nomor TR: <strong>' . htmlspecialchars($tr_number) . '</strong> 
    | Status: <span class="status-badge ' . $request['status'] . '">' . ucfirst($request['status']) . '</span>
    | Tanggal Cetak: ' . date('d/m/Y H:i') . '
</div>

<!-- ========================================== -->
<!-- SECTION A: DATA ACCOUNT (2 KOLOM) -->
<!-- ========================================== -->
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
<table class="table-info" style="margin-top:2px;">
    <tr><td class="label-cell" style="width:15%;">Salesman</td><td class="value-cell">: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</td></tr>
    <tr><td class="label-cell" style="width:15%;">Deskripsi</td><td class="value-cell">: ' . nl2br(htmlspecialchars(substr($detailTR['deskripsi'] ?? '-', 0, 150))) . '</td></tr>
</table>

<!-- ========================================== -->
<!-- SECTION B: DETAIL UNIT -->
<!-- ========================================== -->
<div class="section-title">B. DETAIL UNIT</div>
';

if (count($detailUnits) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:4%;">No</th>
                <th style="width:18%;">Unit</th>
                <th style="width:6%;">QTY</th>
                <th style="width:14%;">Price (Non PPN)</th>
                <th style="width:12%;">PPN 11%</th>
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
                <td style="text-align:center;">' . $no++ . '</td>
                <td>' . htmlspecialchars($namaUnit) . '</td>
                <td style="text-align:center;">' . $unit['qty'] . '</td>
                <td style="text-align:right;">' . formatRp($unit['price']) . '</td>
                <td style="text-align:right;">' . formatRp($unit['ppn']) . '</td>
                <td style="text-align:right; font-weight:700;">' . formatRp($unit['grand_total']) . '</td>
                <td style="font-size:8px;">' . htmlspecialchars(substr($unit['specification'], 0, 50)) . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div class="total-box">
        <span class="total-label">Total Grand Total Unit</span>
        <span class="total-value">' . formatRp($totalUnitGrandTotal) . '</span>
    </div>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:5px 0;">Belum ada data Detail Unit</p>';
}

// ========================================== -->
<!-- SECTION C: TERM OF PAYMENT & D: ADDITIONAL COST (2 KOLOM) -->
<!-- ========================================== -->
$html .= '
<div style="display:table; width:100%; margin-top:4px;">
    <div style="display:table-cell; width:55%; vertical-align:top; padding-right:8px;">
        <div class="section-title" style="margin-top:0;">C. TERM OF PAYMENT</div>
        ';

if (count($termPayments) > 0) {
    $html .= '
        <table>
            <thead>
                <tr>
                    <th style="width:5%;">No</th>
                    <th style="width:35%;">Payment</th>
                    <th style="text-align:right; width:30%;">Amount</th>
                    <th style="width:30%;">Keterangan</th>
                </tr>
            </thead>
            <tbody>';
    
    $no = 1;
    foreach ($termPayments as $top) {
        $paymentType = str_replace('_', ' ', ucfirst($top['payment_type']));
        $html .= '
            <tr>
                <td style="text-align:center;">' . $no++ . '</td>
                <td>' . htmlspecialchars($top['payment_label']) . '</td>
                <td style="text-align:right; font-weight:600;">' . formatRp($top['amount']) . '</td>
                <td style="font-size:8px;">' . htmlspecialchars($top['keterangan'] ?? '-') . '</td>
            </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
        
        <div class="total-box" style="margin-top:2px;">
            <span class="total-label">Total TOP</span>
            <span class="total-value">' . formatRp($totalTOP) . '</span>
        </div>
        ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:5px 0;">Belum ada data TOP</p>';
}

$html .= '
    </div>
    <div style="display:table-cell; width:45%; vertical-align:top; padding-left:8px;">
        <div class="section-title" style="margin-top:0;">D. ADDITIONAL COST</div>
        ';

if ($additionalCost) {
    $html .= '
        <table class="table-info">
            <tr><td class="label-cell" style="width:40%;">Insurance Ops</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_ops']) . '</td></tr>
            <tr><td class="label-cell">Insurance Cargo</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_cargo']) . '</td></tr>
            <tr><td class="label-cell">Delivery Cost</td><td class="value-cell">: ' . formatRp($additionalCost['delivery_cost']) . '</td></tr>
            <tr><td class="label-cell">Mediator Fee</td><td class="value-cell">: ' . formatRp($additionalCost['mediator_fee']) . '</td></tr>
            <tr><td class="label-cell">Free Part</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</td></tr>
            <tr><td class="label-cell">Free Service</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</td></tr>
        </table>
        
        <div class="total-box" style="margin-top:2px;">
            <span class="total-label">Total Additional Cost</span>
            <span class="total-value">' . formatRp($totalAdditionalCost) . '</span>
        </div>
        ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:5px 0;">Belum ada data</p>';
}

$html .= '
    </div>
</div>
';

// ========================================== -->
<!-- SECTION E: DATA MEDIATOR -->
<!-- ========================================== -->
$html .= '
<div class="section-title" style="margin-top:4px;">E. DATA MEDIATOR</div>
';

if ($mediator) {
    $html .= '
    <table class="table-info">
        <tr><td class="label-cell" style="width:15%;">Name</td><td class="value-cell">: ' . htmlspecialchars($mediator['name']) . '</td></tr>
        <tr><td class="label-cell">Bank Name / Account</td><td class="value-cell">: ' . htmlspecialchars($mediator['bank_name']) . ' - ' . htmlspecialchars($mediator['bank_account']) . '</td></tr>
        <tr><td class="label-cell">Amount</td><td class="value-cell">: <strong>' . formatRp($mediator['amount']) . '</strong></td></tr>
    </table>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:5px 0;">Belum ada data Mediator</p>';
}

// ========================================== -->
<!-- SECTION F: REKAPITULASI TOTAL -->
<!-- ========================================== -->
$html .= '
<div class="section-title">F. REKAPITULASI TOTAL</div>
<table class="table-info" style="width:60%; margin:0 auto;">
    <tr><td class="label-cell" style="width:50%;">Total Grand Total Unit</td><td class="value-cell" style="text-align:right; font-weight:700;">' . formatRp($totalUnitGrandTotal) . '</td></tr>
    <tr><td class="label-cell">Total Additional Cost</td><td class="value-cell" style="text-align:right; font-weight:700;">' . formatRp($totalAdditionalCost) . '</td></tr>
    <tr class="grand-total-row">
        <td class="label-cell" style="font-size:12px; font-weight:800; color:#0e1a2b;">TOTAL MASUKAN</td>
        <td class="value-cell" style="text-align:right; font-size:14px; font-weight:800; color:#c9a84c;">' . formatRp($totalMasukan) . '</td>
    </tr>
</table>
';

// ========================================== -->
<!-- SECTION G: APPROVAL HISTORY -->
<!-- ========================================== -->
if (count($approvalHistory) > 0) {
    $html .= '
    <div class="section-title">G. APPROVAL HISTORY</div>
    <table>
        <thead>
            <tr>
                <th style="width:12%;">Level</th>
                <th style="width:20%;">Role</th>
                <th style="width:20%;">Status</th>
                <th style="width:25%;">Approved By</th>
                <th style="width:23%;">Approved At</th>
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
                <td style="text-align:center;">Level ' . $levelNum . '</td>
                <td>' . htmlspecialchars($levelLabel) . '</td>
                <td><span class="' . $statusClass . '">' . $statusLabel . '</span></td>
                <td>' . htmlspecialchars($approverName) . '</td>
                <td>' . ($approval['approved_at'] ? date('d/m/Y H:i', strtotime($approval['approved_at'])) : '-') . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    ';
}

// ========================================== -->
<!-- FOOTER -->
<!-- ========================================== -->
$html .= '
<div class="footer">
    <div class="footer-left">
        <strong>PT GANDA ELANG TANGGUH</strong> - Customer Relationship Management
    </div>
    <div class="footer-right">
        Dicetak: ' . date('d/m/Y H:i') . ' | Halaman {PAGE_NUM}
    </div>
    <div class="clearfix"></div>
    <div style="margin-top:3px; font-size:7px; color:#aaa;">
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

// Output PDF
$filename = 'TR_' . $tr_number . '_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;