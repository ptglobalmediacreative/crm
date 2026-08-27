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
            padding: 15px 25px;
            background: #fff;
            color: #222;
            line-height: 1.8;
        }
        
        .kop-surat {
            text-align: center;
            border-bottom: 2px solid #222;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .kop-surat .logo-img {
            max-width: 180px;
            max-height: 60px;
            width: auto;
            height: auto;
        }
        
        .judul-laporan {
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
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
        
        .section-title {
            font-size: 11px;
            font-weight: 700;
            border-bottom: 1px solid #222;
            padding: 4px 0 2px 0;
            margin: 8px 0 5px 0;
            color: #222;
            text-transform: uppercase;
        }
        
        .info-row {
            padding: 1px 0;
            font-size: 9px;
        }
        .info-row .label {
            display: inline-block;
            font-weight: 600;
            color: #555;
            width: 120px;
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
            width: 100px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
            font-size: 9px;
        }
        table th {
            background: #222;
            color: #fff;
            font-weight: 600;
            font-size: 8px;
            text-transform: uppercase;
            padding: 3px 5px;
            text-align: center;
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
            padding: 4px 6px;
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
        
        .approval-status-approved { color: #28a745; font-weight: 700; }
        .approval-status-rejected { color: #dc3545; font-weight: 700; }
        .approval-status-pending { color: #ffc107; font-weight: 700; }
        
        .footer {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 2px solid #222;
            font-size: 7px;
            color: #555;
            text-align: center;
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
        
        .mediator-row {
            padding: 1px 0;
            font-size: 8px;
        }
        .mediator-row .label {
            display: inline-block;
            font-weight: 600;
            color: #555;
            width: 100px;
        }
        
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
    Nomor TR: <strong>' . htmlspecialchars($tr_number) . '</strong> &nbsp;|&nbsp; <span class="label">Request Date</span>: ' . date('d/m/Y', strtotime($request['request_date'])) . '
</div>

<!-- A. DATA ACCOUNT -->
<div class="section-title">A. DATA ACCOUNT</div>

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

<div class="info-row"><span class="label" style="width:100px;">Salesman</span>: ' . htmlspecialchars($request['sales_name'] ?? '-') . '</div>
<div class="info-row"><span class="label" style="width:100px;">Deskripsi</span>: ' . nl2br(htmlspecialchars($detailTR['deskripsi'] ?? '-')) . '</div>

<!-- B. DETAIL UNIT -->
<div class="section-title">B. DETAIL UNIT</div>
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
    </table>
    
    <div style="display:flex; flex-wrap:wrap; margin-top:3px; font-size:8px;">
        <div style="flex:1; min-width:30%;"><strong>Additional Attachment</strong>: ' . htmlspecialchars($detailUnits[0]['additional_attachment'] ?? '-') . '</div>
        <div style="flex:1; min-width:30%;"><strong>Waranty</strong>: ' . htmlspecialchars($detailUnits[0]['waranty'] ?? '-') . '</div>
        <div style="flex:1; min-width:30%;"><strong>Transaction Type</strong>: ' . htmlspecialchars($detailUnits[0]['transaction_type'] ?? '-') . '</div>
    </div>
    <div style="display:flex; flex-wrap:wrap; font-size:8px;">
        <div style="flex:1; min-width:30%;"><strong>Machine Location</strong>: ' . htmlspecialchars($detailUnits[0]['machine_location'] ?? '-') . '</div>
        <div style="flex:1; min-width:30%;"><strong>Delivery Terms</strong>: ' . htmlspecialchars($detailUnits[0]['delivery_terms'] ?? '-') . '</div>
        <div style="flex:1; min-width:30%;"><strong>Delivery Schedule</strong>: ' . (isset($detailUnits[0]['delivery_schedule']) ? date('d/m/Y', strtotime($detailUnits[0]['delivery_schedule'])) : '-') . '</div>
    </div>
    ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data Detail Unit</p>';
}

// C. TERM OF PAYMENT & D. ADDITIONAL COST
$html .= '
<div style="display:flex; flex-wrap:wrap; margin-top:5px;">
    <div style="flex:1; min-width:50%; padding-right:10px;">
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
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data TOP</p>';
}

$html .= '
    </div>
    <div style="flex:1; min-width:40%; padding-left:10px;">
        <div class="section-title" style="margin-top:0;">D. ADDITIONAL COST</div>
        ';

if ($additionalCost) {
    $html .= '
        <div style="font-size:8px; line-height:1.8; text-align:left;">
            <div><span style="display:inline-block; width:120px; font-weight:600;">Insurance Ops</span> : ' . formatRp($additionalCost['insurance_ops']) . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Insurance Cargo</span> : ' . formatRp($additionalCost['insurance_cargo']) . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Delivery Cost</span> : ' . formatRp($additionalCost['delivery_cost']) . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Mediator Fee</span> : ' . formatRp($additionalCost['mediator_fee']) . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Free Part</span> : ' . htmlspecialchars($additionalCost['free_part'] ?? '-') . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Free Service</span> : ' . htmlspecialchars($additionalCost['free_service'] ?? '-') . '</div>
            <div><span style="display:inline-block; width:120px; font-weight:600;">Others</span> : ' . htmlspecialchars($additionalCost['others'] ?? '-') . '</div>
        </div>
        ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data</p>';
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
    <div style="font-size:8px; line-height:1.8; text-align:left;">
        <div><span style="display:inline-block; width:120px; font-weight:600;">Name</span> : ' . htmlspecialchars($mediator['name']) . '</div>
        <div><span style="display:inline-block; width:120px; font-weight:600;">ID Card</span> : ' . htmlspecialchars($mediator['id_card_no'] ?? '-') . '</div>
        <div><span style="display:inline-block; width:120px; font-weight:600;">NPWP</span> : ' . htmlspecialchars($mediator['npwp_no'] ?? '-') . '</div>
        <div><span style="display:inline-block; width:120px; font-weight:600;">Bank Name</span> : ' . htmlspecialchars($mediator['bank_name'] ?? '-') . '</div>
        <div><span style="display:inline-block; width:120px; font-weight:600;">Bank Account</span> : ' . htmlspecialchars($mediator['bank_account'] ?? '-') . '</div>
        <div><span style="display:inline-block; width:120px; font-weight:600;">Amount</span> : <strong>' . formatRp($mediator['amount']) . '</strong></div>
    </div>
    ';
} else {
    $html .= '<p style="color:#999; padding:5px 0;">Belum ada data Mediator</p>';
}

// F. REKAPITULASI TOTAL
$html .= '
<div class="section-title">F. REKAPITULASI TOTAL</div>
<div class="three-col">
    <div class="col"><div class="box"><div class="label">Total Grand Total Unit</div><div class="value">' . formatRp($totalUnitGrandTotal) . '</div></div></div>
    <div class="col"><div class="box"><div class="label">Total Additional Cost</div><div class="value">' . formatRp($totalAdditionalCost) . '</div></div></div>
    <div class="col"><div class="box gold"><div class="label">TOTAL MASUKAN</div><div class="value">' . formatRp($totalMasukan) . '</div></div></div>
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

// FOOTER
$html .= '
<div class="footer">
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