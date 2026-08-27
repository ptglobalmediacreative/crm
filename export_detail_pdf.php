<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';
require_once 'vendor/autoload.php'; // Dompdf

use Dompdf\Dompdf;
use Dompdf\Options;

// ============================================
// SET ZONA WAKTU
// ============================================
date_default_timezone_set('Asia/Jakarta');

// Cek login
if (!isLoggedIn()) {
    die('Silakan login dulu!');
}

// ============================================
// AMBIL TR NUMBER DARI URL
// ============================================
$tr_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';

if (empty($tr_number)) {
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
// AMBIL APPROVAL HISTORY
// ============================================
$approvalHistory = [];
try {
    $sqlApproval = "SELECT * FROM tr_approval_history WHERE trf_number = ? ORDER BY approval_order ASC";
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
// BUILD HTML PDF
// ============================================
$html = '
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Detail TR - ' . htmlspecialchars($tr_number) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: "Times New Roman", Times, serif; 
            font-size: 11px;
            padding: 20px 30px;
            background: #fff;
            color: #1a1a1a;
            line-height: 1.4;
        }
        
        /* KOP SURAT */
        .kop-surat {
            text-align: center;
            border-bottom: 3px double #0e1a2b;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .kop-surat .logo {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 2px;
            color: #0e1a2b;
        }
        .kop-surat .logo span {
            color: #c9a84c;
        }
        .kop-surat .sub {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 3px;
            color: #555;
            margin-top: 2px;
        }
        .kop-surat .alamat {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
        }
        .kop-surat .garis-bawah {
            border-bottom: 2px solid #0e1a2b;
            margin-top: 3px;
        }
        
        /* JUDUL */
        .judul-laporan {
            text-align: center;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 10px 0 5px 0;
            color: #0e1a2b;
        }
        .sub-judul {
            text-align: center;
            font-size: 11px;
            color: #666;
            margin-bottom: 15px;
        }
        .status-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-badge.pending { background: #fef9e7; color: #b7950b; }
        .status-badge.approved { background: #ebf5fb; color: #1a5276; }
        .status-badge.rejected { background: #fdedec; color: #922b21; }
        
        /* SECTION */
        .section-title {
            font-size: 12px;
            font-weight: 700;
            background: #f0f2f5;
            padding: 5px 10px;
            margin: 14px 0 8px 0;
            border-left: 4px solid #c9a84c;
            color: #0e1a2b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
        }
        table th {
            background: #0e1a2b;
            color: #fff;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            padding: 5px 6px;
            text-align: left;
            letter-spacing: 0.5px;
        }
        table td {
            padding: 4px 6px;
            border-bottom: 1px solid #e8edf2;
            font-size: 10px;
            vertical-align: top;
        }
        table tr:nth-child(even) td {
            background: #fafafa;
        }
        .label-cell {
            font-weight: 600;
            color: #555;
            width: 30%;
        }
        .value-cell {
            width: 70%;
        }
        .table-info td {
            padding: 3px 5px;
            border: none;
            background: transparent !important;
        }
        
        /* TOTAL BOX */
        .total-box {
            background: #0e1a2b;
            color: #fff;
            padding: 8px 14px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 5px 0 3px 0;
        }
        .total-box .total-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.7);
        }
        .total-box .total-value {
            font-size: 14px;
            font-weight: 700;
            color: #ffd700;
        }
        
        .grand-total-row {
            border-top: 2px solid #0e1a2b !important;
        }
        .grand-total-row td {
            font-size: 14px !important;
            font-weight: 800 !important;
            color: #c9a84c !important;
        }
        
        hr {
            border: none;
            border-top: 1px dashed #ddd;
            margin: 8px 0;
        }
        
        /* FOOTER */
        .footer {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 2px solid #0e1a2b;
            font-size: 9px;
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
            margin: 15mm 20mm 15mm 20mm;
        }
        
        .print-date {
            text-align: right;
            font-size: 10px;
            color: #666;
            margin-bottom: 5px;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .fw-bold { font-weight: 700; }
        .mt-2 { margin-top: 5px; }
        .mb-2 { margin-bottom: 5px; }
        .text-muted { color: #999; }
    </style>
</head>
<body>

<!-- KOP SURAT -->
<div class="kop-surat">
    <div class="logo">PT GANDA ELANG <span>TANGGUH</span></div>
    <div class="sub">CUSTOMER RELATIONSHIP MANAGEMENT</div>
    <div class="alamat">
        Jl. Raya Industrial Estate, Blok A No. 12, Jakarta 12345<br>
        Telp: (021) 1234-5678 | Fax: (021) 1234-5679 | Email: crm@get.co.id
    </div>
    <div class="garis-bawah"></div>
</div>

<!-- JUDUL -->
<div class="judul-laporan">DETAIL TRANSACTION REQUEST</div>
<div class="sub-judul">
    Nomor TR: <strong>' . htmlspecialchars($tr_number) . '</strong> 
    | Status: <span class="status-badge ' . $request['status'] . '">' . ucfirst($request['status']) . '</span>
    | Tanggal Cetak: ' . date('d/m/Y H:i') . '
</div>

<!-- SECTION A: DATA ACCOUNT -->
<div class="section-title">A. DATA ACCOUNT</div>
<table class="table-info">
    <tr><td class="label-cell">Nama PT</td><td class="value-cell">: ' . htmlspecialchars($request['nama_pt'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Badan Usaha</td><td class="value-cell">: ' . htmlspecialchars($request['badan_usaha'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Alamat</td><td class="value-cell">: ' . htmlspecialchars($request['alamat'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">NPWP</td><td class="value-cell">: ' . htmlspecialchars($request['npwp'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Nama PIC</td><td class="value-cell">: ' . htmlspecialchars($request['nama_pic'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Jabatan PIC</td><td class="value-cell">: ' . htmlspecialchars($request['jabatan_pic'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">No HP PIC</td><td class="value-cell">: ' . htmlspecialchars($request['no_hp_pic'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Email PIC</td><td class="value-cell">: ' . htmlspecialchars($request['email_pic'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Salesman</td><td class="value-cell">: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</td></tr>
    <tr><td class="label-cell">Request Date</td><td class="value-cell">: ' . date('d/m/Y', strtotime($request['request_date'])) . '</td></tr>
    <tr><td class="label-cell">Due Date</td><td class="value-cell">: ' . date('d/m/Y', strtotime($request['due_date'])) . '</td></tr>
    <tr><td class="label-cell">Deskripsi</td><td class="value-cell">: ' . nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) . '</td></tr>
</table>

<!-- SECTION B: DETAIL UNIT -->
<div class="section-title">B. DETAIL UNIT</div>
';

if (count($detailUnits) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="text-align:center; width:5%;">No</th>
                <th style="width:20%;">Unit</th>
                <th style="text-align:center; width:8%;">QTY</th>
                <th style="text-align:right; width:15%;">Price (Non PPN)</th>
                <th style="text-align:right; width:12%;">PPN 11%</th>
                <th style="text-align:right; width:18%;">Grand Total</th>
                <th style="width:22%;">Specification</th>
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
                <td style="font-size:9px;">' . htmlspecialchars($unit['specification']) . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div class="total-box">
        <span class="total-label">Total Grand Total Unit</span>
        <span class="total-value">' . formatRp($totalUnitGrandTotal) . '</span>
    </div>
    
    <table style="margin-top:5px; font-size:9px;">
        <tr>
            <td style="width:20%; font-weight:600;">Additional Attachment</td>
            <td style="width:30%;">' . htmlspecialchars($detailUnits[0]['additional_attachment'] ?? '-') . '</td>
            <td style="width:20%; font-weight:600;">Waranty</td>
            <td style="width:30%;">' . htmlspecialchars($detailUnits[0]['waranty'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="font-weight:600;">Machine Location</td>
            <td>' . htmlspecialchars($detailUnits[0]['machine_location'] ?? '-') . '</td>
            <td style="font-weight:600;">Delivery Terms</td>
            <td>' . htmlspecialchars($detailUnits[0]['delivery_terms'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="font-weight:600;">Delivery Schedule</td>
            <td>' . (isset($detailUnits[0]['delivery_schedule']) ? date('d/m/Y', strtotime($detailUnits[0]['delivery_schedule'])) : '-') . '</td>
            <td style="font-weight:600;">Transaction Type</td>
            <td>' . htmlspecialchars($detailUnits[0]['transaction_type'] ?? '-') . '</td>
        </tr>
    </table>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:10px 0;">Belum ada data Detail Unit</p>';
}

// SECTION C: TERM OF PAYMENT
$html .= '
<div class="section-title">C. TERM OF PAYMENT</div>
';

if (count($termPayments) > 0) {
    $html .= '
    <table>
        <thead>
            <tr>
                <th style="width:5%;">No</th>
                <th style="width:25%;">Payment Type</th>
                <th style="width:30%;">Label</th>
                <th style="text-align:right; width:25%;">Amount</th>
                <th style="width:15%;">Keterangan</th>
            </tr>
        </thead>
        <tbody>';
    
    $no = 1;
    foreach ($termPayments as $top) {
        $paymentType = str_replace('_', ' ', ucfirst($top['payment_type']));
        $html .= '
            <tr>
                <td style="text-align:center;">' . $no++ . '</td>
                <td>' . htmlspecialchars($paymentType) . '</td>
                <td>' . htmlspecialchars($top['payment_label']) . '</td>
                <td style="text-align:right; font-weight:600;">' . formatRp($top['amount']) . '</td>
                <td style="font-size:9px;">' . htmlspecialchars($top['keterangan'] ?? '-') . '</td>
            </tr>';
    }
    
    $html .= '
        </tbody>
    </table>
    
    <div class="total-box">
        <span class="total-label">Total Term of Payment</span>
        <span class="total-value">' . formatRp($totalTOP) . '</span>
    </div>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:10px 0;">Belum ada data Term of Payment</p>';
}

// SECTION D: ADDITIONAL COST
$html .= '
<div class="section-title">D. ADDITIONAL COST / MACHINES</div>
';

if ($additionalCost) {
    $html .= '
    <table class="table-info">
        <tr><td class="label-cell" style="width:30%;">Insurance Ops</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_ops']) . '</td></tr>
        <tr><td class="label-cell">Insurance Cargo</td><td class="value-cell">: ' . formatRp($additionalCost['insurance_cargo']) . '</td></tr>
        <tr><td class="label-cell">Delivery Cost</td><td class="value-cell">: ' . formatRp($additionalCost['delivery_cost']) . '</td></tr>
        <tr><td class="label-cell">Free Part</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</td></tr>
        <tr><td class="label-cell">Free Service</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</td></tr>
        <tr><td class="label-cell">Mediator Fee</td><td class="value-cell">: ' . formatRp($additionalCost['mediator_fee']) . '</td></tr>
        <tr><td class="label-cell">Others</td><td class="value-cell">: ' . htmlspecialchars($additionalCost['others'] ?? '-') . '</td></tr>
    </table>
    
    <div class="total-box">
        <span class="total-label">Total Additional Cost</span>
        <span class="total-value">' . formatRp($totalAdditionalCost) . '</span>
    </div>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:10px 0;">Belum ada data Additional Cost</p>';
}

// SECTION E: DATA MEDIATOR
$html .= '
<div class="section-title">E. DATA MEDIATOR</div>
';

if ($mediator) {
    $html .= '
    <table class="table-info">
        <tr><td class="label-cell" style="width:30%;">Name</td><td class="value-cell">: ' . htmlspecialchars($mediator['name']) . '</td></tr>
        <tr><td class="label-cell">ID Card No</td><td class="value-cell">: ' . htmlspecialchars($mediator['id_card_no']) . '</td></tr>
        <tr><td class="label-cell">NPWP No</td><td class="value-cell">: ' . htmlspecialchars($mediator['npwp_no']) . '</td></tr>
        <tr><td class="label-cell">Bank Name</td><td class="value-cell">: ' . htmlspecialchars($mediator['bank_name']) . '</td></tr>
        <tr><td class="label-cell">Bank Account</td><td class="value-cell">: ' . htmlspecialchars($mediator['bank_account']) . '</td></tr>
        <tr><td class="label-cell">Amount</td><td class="value-cell">: ' . formatRp($mediator['amount']) . '</td></tr>
    </table>
    ';
} else {
    $html .= '<p style="text-align:center; color:#999; font-style:italic; padding:10px 0;">Belum ada data Mediator</p>';
}

// SECTION F: REKAPITULASI TOTAL
$html .= '
<div class="section-title">F. REKAPITULASI TOTAL</div>
<table class="table-info">
    <tr><td class="label-cell" style="width:40%;">Total Grand Total Unit</td><td class="value-cell" style="font-weight:700; font-size:12px;">: ' . formatRp($totalUnitGrandTotal) . '</td></tr>
    <tr><td class="label-cell">Total Additional Cost</td><td class="value-cell" style="font-weight:700; font-size:12px;">: ' . formatRp($totalAdditionalCost) . '</td></tr>
    <tr class="grand-total-row">
        <td class="label-cell" style="font-size:14px; font-weight:800; color:#0e1a2b;">TOTAL MASUKAN</td>
        <td class="value-cell" style="font-size:18px; font-weight:800; color:#c9a84c;">: ' . formatRp($totalMasukan) . '</td>
    </tr>
</table>
';

// SECTION G: APPROVAL HISTORY
if (count($approvalHistory) > 0) {
    $html .= '
    <div class="section-title">G. APPROVAL HISTORY</div>
    <table>
        <thead>
            <tr>
                <th style="width:10%;">Level</th>
                <th style="width:25%;">Role</th>
                <th style="width:20%;">Status</th>
                <th style="width:20%;">Approved By</th>
                <th style="width:25%;">Approved At</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($approvalHistory as $approval) {
        $statusLabel = ucfirst($approval['status']);
        $statusColor = $approval['status'] == 'approved' ? '#27ae60' : ($approval['status'] == 'rejected' ? '#e74c3c' : '#f39c12');
        $html .= '
            <tr>
                <td style="text-align:center;">Level ' . $approval['approval_order'] . '</td>
                <td>' . htmlspecialchars(getRoleLabel($approval['approval_role'])) . '</td>
                <td><span style="color:' . $statusColor . '; font-weight:700;">' . $statusLabel . '</span></td>
                <td>' . htmlspecialchars($approval['approved_by'] ?? '-') . '</td>
                <td>' . ($approval['approved_at'] ? date('d/m/Y H:i', strtotime($approval['approved_at'])) : '-') . '</td>
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
        <strong>PT GANDA ELANG TANGGUH</strong> - Customer Relationship Management
    </div>
    <div class="footer-right">
        Dicetak: ' . date('d/m/Y H:i') . ' | Halaman {PAGE_NUM}
    </div>
    <div class="clearfix"></div>
    <div style="margin-top:5px; font-size:8px; color:#aaa;">
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
$options->set('defaultFont', 'times');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output PDF
$filename = 'TR_' . $tr_number . '_' . date('Ymd_His') . '.pdf';
$dompdf->stream($filename, array('Attachment' => true));
exit;