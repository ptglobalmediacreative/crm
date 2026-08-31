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

date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    ob_end_clean();
    die('Silakan login dulu!');
}

$tr_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';

if (empty($tr_number)) {
    ob_end_clean();
    die('TR Number tidak ditemukan!');
}

function formatRp($number) {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

function h($value, $default = '-') {
    $value = ($value === null || $value === '') ? $default : $value;
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateId($date) {
    if (empty($date)) return '-';
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

function formatDateTimeId($date) {
    if (empty($date)) return '-';
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : '-';
}

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
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
                         AND dtr.status = 'rejected'
                   ) THEN 'rejected'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
                         AND dtr.status = 'pending'
                   ) THEN 'pending'
                   WHEN EXISTS (
                       SELECT 1 FROM detail_transaction_requests dtr
                       WHERE dtr.trf_number COLLATE utf8mb4_unicode_ci = ad.tr_number COLLATE utf8mb4_unicode_ci
                         AND dtr.status = 'approved'
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
// DETAIL TR
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
// PRODUK
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
// DETAIL UNIT
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
// TERM OF PAYMENT
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
// ADDITIONAL COST
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
// MEDIATORS
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
// APPROVAL HISTORY
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
// TOTAL
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
    $totalAdditionalCost =
        (float)($additionalCost['insurance_ops'] ?? 0) +
        (float)($additionalCost['insurance_cargo'] ?? 0) +
        (float)($additionalCost['delivery_cost'] ?? 0) +
        (float)($additionalCost['mediator_fee'] ?? 0);
}

$totalMediatorFee = 0;
foreach ($mediators as $med) {
    $totalMediatorFee += (float)$med['amount'];
}
$totalAdditionalCost += $totalMediatorFee;

$totalMasukan = $totalUnitGrandTotal - $totalAdditionalCost;

function getNamaProduk($unitId, $produkList) {
    foreach ($produkList as $produk) {
        if ($produk['id'] == $unitId) {
            return $produk['nama_produk'];
        }
    }
    return '-';
}

$approvalLevels = [
    1 => 'Sales Manager',
    2 => 'Direktur Sales',
    3 => 'Divisi Business',
    4 => 'Direktur Operasional',
    5 => 'Direktur Utama'
];

// ============================================
// LOGO
// ============================================
$logoPath = 'images/kopsurat.png';
$logoHtml = '';
if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" class="logo-img" alt="Logo">';
}

$namaPT = $request['nama_pt'] ?? '-';
$badanUsaha = $request['badan_usaha'] ?? '';
$namaPTDisplay = (!empty($badanUsaha) && $namaPT !== '-')
    ? $namaPT . ', ' . $badanUsaha
    : $namaPT;

ob_end_clean();

// ============================================
// BUILD HTML
// ============================================
$html = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Transaction Request Form - ' . h($tr_number) . '</title>
<style>
    @page { margin: 8mm 8mm 8mm 8mm; }
    * { box-sizing: border-box; }
    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 7.5px;
        color: #111;
        margin: 0;
        padding: 0;
        line-height: 1.22;
    }
    .page { width: 100%; }
    .title-bar {
        width: 100%;
        background: #16b5ea;
        color: #111;
        border: 1px solid #111;
        text-align: center;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 6px;
        margin-bottom: 5px;
    }
    .logo-wrap { text-align:center; margin-bottom:3px; }
    .logo-img { max-width: 260px; max-height: 42px; }

    table { width:100%; border-collapse:collapse; table-layout:fixed; }
    th, td { border:1px solid #222; padding:2.5px 3px; vertical-align:middle; word-wrap:break-word; }
    th { font-weight:700; text-align:center; background:#f3f3f3; }
    .label { background:#f4f4f4; font-weight:700; }
    .green { background:#e7f1dd; }
    .blue { background:#dce6f1; }
    .yellow { background:#fff200; }
    .gray { background:#eeeeee; }
    .center { text-align:center; }
    .right { text-align:right; }
    .bold { font-weight:700; }
    .nowrap { white-space:nowrap; }
    .no-border { border:0 !important; }
    .section-title {
        background:#e9eef3;
        border:1px solid #222;
        border-bottom:0;
        font-weight:700;
        padding:3px 5px;
        margin-top:5px;
        text-transform:uppercase;
        letter-spacing:.2px;
    }
    .small { font-size:6.8px; }
    .tiny { font-size:6.2px; }
    .value-money { font-weight:700; }
    .status {
        display:inline-block;
        padding:1px 4px;
        border:1px solid #555;
        font-weight:700;
        font-size:6.5px;
    }
    .status-pending { background:#fff1bf; }
    .status-approved { background:#d9f0d9; }
    .status-rejected { background:#f6d2d2; }

    .top-grid > tbody > tr > td { vertical-align:top; }
    .top-grid .left-col { width:48%; padding:0; border:0; }
    .top-grid .right-col { width:52%; padding:0 0 0 5px; border:0; }

    .account-table td { height:16px; }
    .account-table .field { width:28%; font-weight:700; background:#f4f4f4; }
    .account-table .field-wide { width:36%; font-weight:700; background:#f4f4f4; }

    .product-summary th { background:#f2f2f2; }
    .product-summary .subtotal td { font-weight:700; }
    .product-summary .grand td { font-size:8px; font-weight:700; }

    .machine-table td { height:17px; }
    .machine-table .row-label { width:28%; font-weight:700; background:#f4f4f4; }
    .machine-table .content { width:72%; }
    .spec-table td { vertical-align:top; }

    .section-gap { margin-top:5px; }

    .bottom-grid > tbody > tr > td { vertical-align:top; }
    .bottom-grid .left { width:49%; padding:0; border:0; }
    .bottom-grid .right { width:51%; padding:0 0 0 5px; border:0; }

    .mediator-table th { background:#fff200; }
    .mediator-table td { height:16px; }

    .signature-table td { height:43px; text-align:center; vertical-align:bottom; }
    .signature-name { font-weight:700; font-size:7px; }
    .signature-role { font-size:6.5px; }

    .note-box { padding:4px; border:1px solid #222; min-height:35px; }
    .checkbox { font-size:8px; font-weight:700; }

    .page-break { page-break-before:always; }
    .keep { page-break-inside:avoid; }
</style>
</head>
<body>
<div class="page">
    <div class="logo-wrap">' . $logoHtml . '</div>
    <div class="title-bar">TRANSACTION REQUEST FORM</div>

    <table class="top-grid">
        <tr>
            <td class="left-col">
                <table class="account-table">
                    <tr><td class="field">TR Number</td><td>' . h($tr_number) . '</td></tr>
                    <tr><td class="field">Request Date</td><td>' . formatDateId($request['request_date'] ?? null) . '</td></tr>
                    <tr><td class="field">Customer</td><td class="green bold">' . h($namaPTDisplay) . '</td></tr>
                    <tr><td class="field">NPWP</td><td>' . h($request['npwp'] ?? '-') . '</td></tr>
                    <tr><td class="field">Address</td><td>' . nl2br(h($request['alamat'] ?? '-')) . '</td></tr>
                    <tr><td class="field">Customer PIC (Signer)</td><td>' . h($request['nama_pic'] ?? '-') . '</td></tr>
                    <tr><td class="field">Position</td><td>' . h($request['jabatan_pic'] ?? '-') . '</td></tr>
                    <tr><td class="field">Phone</td><td>' . h($request['no_hp_pic'] ?? '-') . '</td></tr>
                    <tr><td class="field">e-Mail</td><td>' . h($request['email_pic'] ?? '-') . '</td></tr>
                    <tr><td class="field">Sales</td><td>' . h($request['sales_name'] ?? '-') . '</td></tr>
                    <tr><td class="field">TR Status</td><td>' . h(ucfirst($request['status'] ?? 'pending')) . '</td></tr>
                </table>
            </td>
            <td class="right-col">
                <table class="product-summary">
                    <tr>
                        <th style="width:26%">Model Unit</th>
                        <th style="width:10%">Qty</th>
                        <th style="width:10%">Curr.</th>
                        <th style="width:22%">Amount / Unit</th>
                        <th style="width:30%">Amount</th>
                    </tr>';

if (count($detailUnits) > 0) {
    foreach ($detailUnits as $unit) {
        $qty = (float)($unit['qty'] ?? 0);
        $price = (float)($unit['price'] ?? 0);
        $grand = (float)($unit['grand_total'] ?? 0);
        $html .= '<tr>
            <td>' . h(getNamaProduk($unit['unit_id'], $produkList)) . '</td>
            <td class="center">' . rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',') . '</td>
            <td class="center">IDR</td>
            <td class="right">' . number_format($price, 0, ',', '.') . '</td>
            <td class="right bold">' . number_format($grand, 0, ',', '.') . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="5" class="center">Belum ada detail unit</td></tr>';
}

$html .= '<tr class="subtotal"><td colspan="4" class="right">TOTAL</td><td class="right">' . formatRp($totalUnitGrandTotal) . '</td></tr>
                    <tr class="subtotal"><td colspan="4" class="right">PPN</td><td class="right">-</td></tr>
                    <tr class="grand"><td colspan="4" class="right">GRAND TOTAL</td><td class="right">' . formatRp($totalUnitGrandTotal) . '</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">A. MACHINE / UNIT INFORMATION</div>
    <table class="machine-table">
        <tr>
            <td class="row-label">Machine</td>
            <td class="content">
                <table class="no-border">
                    <tr>
                        <th style="width:14%">Qty</th>
                        <th style="width:40%">Model</th>
                        <th style="width:46%">Specification</th>
                    </tr>';

if (count($detailUnits) > 0) {
    $specJoined = [];
    foreach ($detailUnits as $unit) {
        $specJoined[] = trim((string)($unit['specification'] ?? ''));
    }
    $specText = implode("\n", array_filter($specJoined));
    $firstUnit = $detailUnits[0];
    $html .= '<tr>
        <td class="center">' . h($firstUnit['qty'] ?? '-') . '</td>
        <td>' . h(getNamaProduk($firstUnit['unit_id'], $produkList)) . '</td>
        <td>' . nl2br(h($specText ?: '-')) . '</td>
    </tr>';
} else {
    $html .= '<tr><td colspan="3" class="center">Belum ada data</td></tr>';
}

$html .= '</table>
            </td>
        </tr>
        <tr>
            <td class="row-label">Additional Equipment</td>
            <td class="content">';

if (count($detailUnits) > 0) {
    $equip = [];
    foreach ($detailUnits as $unit) {
        if (!empty($unit['additional_attachment'])) $equip[] = $unit['additional_attachment'];
    }
    $html .= nl2br(h(implode(', ', array_unique($equip)) ?: '-'));
} else {
    $html .= '-';
}

$html .= '</td></tr>
        <tr><td class="row-label">Warranty</td><td class="content">' . h($detailUnits[0]['waranty'] ?? '-') . '</td></tr>
        <tr><td class="row-label">Machine Location / Works</td><td class="content">' . h($detailUnits[0]['machine_location'] ?? '-') . '</td></tr>
        <tr><td class="row-label">Delivery Terms</td><td class="content">' . h($detailUnits[0]['delivery_terms'] ?? '-') . '</td></tr>
        <tr><td class="row-label">Delivery Schedule</td><td class="content green bold">' . formatDateId($detailUnits[0]['delivery_schedule'] ?? null) . '</td></tr>
        <tr><td class="row-label">Transaction Type</td><td class="content">' . h($detailUnits[0]['transaction_type'] ?? '-') . '</td></tr>
        <tr><td class="row-label">Description</td><td class="content">' . nl2br(h($detailTR['deskripsi'] ?? '-')) . '</td></tr>
    </table>

    <table class="section-gap bottom-grid">
        <tr>
            <td class="left">
                <div class="section-title">B. TERM OF PAYMENT</div>
                <table>
                    <tr>
                        <th style="width:34%">Schedule</th>
                        <th style="width:13%">Percent</th>
                        <th style="width:29%">Amount</th>
                        <th style="width:24%">Remarks</th>
                    </tr>';

if (count($termPayments) > 0) {
    foreach ($termPayments as $top) {
        $html .= '<tr>
            <td>' . h($top['payment_label'] ?? '-') . '</td>
            <td class="center">' . rtrim(rtrim(number_format((float)($top['percentage'] ?? 0), 2, ',', '.'), '0'), ',') . '%</td>
            <td class="right bold">' . formatRp($top['amount'] ?? 0) . '</td>
            <td>' . nl2br(h($top['keterangan'] ?? '-')) . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="4" class="center">Belum ada data TOP</td></tr>';
}

$html .= '<tr><td colspan="2" class="right bold">TOTAL TOP</td><td class="right bold">' . formatRp($totalTOP) . '</td><td></td></tr>
                </table>

                <div class="section-title">C. DELIVERY / AVAILABILITY</div>
                <table>
                    <tr><td class="label" style="width:55%">Is the machine available?</td><td class="center" style="width:45%">☐ Yes &nbsp;&nbsp; ☐ No</td></tr>
                    <tr><td class="label">If yes put S/N ; if no put days</td><td>';

if (count($detailUnits) > 0) {
    $sn = 1;
    foreach ($detailUnits as $unit) {
        $html .= 'S/N ' . sprintf('%02d', $sn++) . ' : ' . h($unit['serial_number'] ?? '-') . '<br>';
    }
} else {
    $html .= '-';
}

$html .= '</td></tr>
                    <tr><td class="label">Lead Time Propose (days)</td><td>' . h($detailTR['lead_time'] ?? $detailTR['lead_time_propose'] ?? '-') . '</td></tr>
                </table>
            </td>

            <td class="right">
                <div class="section-title">D. ADDITIONAL COST / MACHINES</div>
                <table>
                    <tr><th style="width:48%">Item</th><th style="width:14%">Percent</th><th style="width:38%">Amount</th></tr>
                    <tr><td>Insurance Ops / Cargo</td><td class="center">' . h($additionalCost['insurance_percent'] ?? '-') . '</td><td class="right">' . formatRp(($additionalCost['insurance_ops'] ?? 0) + ($additionalCost['insurance_cargo'] ?? 0)) . '</td></tr>
                    <tr><td>Modification</td><td class="center">IDR</td><td class="right">-</td></tr>
                    <tr><td>Part Vouchers</td><td class="center">IDR</td><td class="right">' . formatRp($additionalCost['part_vouchers'] ?? 0) . '</td></tr>
                    <tr><td>Free Service</td><td class="center">IDR</td><td class="right">' . formatRp($additionalCost['free_service_amount'] ?? 0) . '</td></tr>
                    <tr><td>Transport</td><td class="center">IDR</td><td class="right">' . formatRp($additionalCost['delivery_cost'] ?? 0) . '</td></tr>
                    <tr><td>Third Party Commission / Mediator</td><td class="center">IDR</td><td class="right">' . formatRp($totalMediatorFee + (float)($additionalCost['mediator_fee'] ?? 0)) . '</td></tr>
                    <tr><td class="bold">TOTAL ADDITIONAL COST</td><td class="center">IDR</td><td class="right bold">' . formatRp($totalAdditionalCost) . '</td></tr>
                </table>

                <table class="section-gap">
                    <tr><td class="label" style="width:48%">Interest / Price-Payment Difference</td><td>' . h($additionalCost['interest'] ?? '-') . '</td></tr>
                    <tr><td class="label">Free Part</td><td>' . nl2br(h($additionalCost['free_part'] ?? '-')) . '</td></tr>
                    <tr><td class="label">Other Notes</td><td>' . nl2br(h($additionalCost['others'] ?? '-')) . '</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">E. THIRD PARTY COMMISSION / MEDIATOR LIST</div>
    <table class="mediator-table">
        <tr>
            <th style="width:13%">Field</th>';

$mediatorCount = max(count($mediators), 2);
for ($i = 0; $i < $mediatorCount; $i++) {
    $html .= '<th style="width:' . (87 / $mediatorCount) . '%">' . h($mediators[$i]['name'] ?? ('Mediator ' . ($i + 1))) . '</th>';
}

$html .= '</tr>';
$mediatorRows = [
    'id_card_no' => 'ID Card No',
    'npwp_no' => 'NPWP No',
    'bank_name' => 'Bank Name',
    'bank_account' => 'Bank Account',
    'amount' => 'Amount'
];
foreach ($mediatorRows as $field => $label) {
    $html .= '<tr><td class="bold">' . $label . '</td>';
    for ($i = 0; $i < $mediatorCount; $i++) {
        $med = $mediators[$i] ?? null;
        $value = $med[$field] ?? '-';
        $display = ($field === 'amount') ? formatRp($value) : h($value);
        $align = ($field === 'amount') ? 'right' : '';
        $html .= '<td class="' . $align . '">' . $display . '</td>';
    }
    $html .= '</tr>';
}

$html .= '</table>

    <table class="section-gap">
        <tr>
            <td class="label" style="width:18%">Transaction Type</td>
            <td style="width:82%">1. Cash before delivery &nbsp;&nbsp; 2. Leasing &nbsp;&nbsp; 3. Direct Credit &nbsp;&nbsp; 4. Others: ' . h($detailTR['transaction_type_note'] ?? '-') . '</td>
        </tr>
        <tr>
            <td class="label">Payment Note</td>
            <td>' . nl2br(h($detailTR['payment_note'] ?? '-')) . '</td>
        </tr>
    </table>

    <div class="section-title">F. RECAPITULATION</div>
    <table>
        <tr>
            <td class="label" style="width:30%">Total Grand Total Unit</td>
            <td class="right" style="width:20%">' . formatRp($totalUnitGrandTotal) . '</td>
            <td class="label" style="width:30%">Total Additional Cost</td>
            <td class="right bold" style="width:20%">' . formatRp($totalAdditionalCost) . '</td>
        </tr>
        <tr>
            <td class="label">Total Masukan</td>
            <td class="right bold green">' . formatRp($totalMasukan) . '</td>
            <td class="label">Request Number</td>
            <td class="bold center">' . h($tr_number) . '</td>
        </tr>
    </table>

    <div class="section-title">G. APPROVAL HISTORY</div>';

if (count($approvalHistory) > 0) {
    $html .= '<table>
        <tr>
            <th style="width:10%">Level</th>
            <th style="width:26%">Role</th>
            <th style="width:16%">Status</th>
            <th style="width:24%">Approved By</th>
            <th style="width:24%">Approved At</th>
        </tr>';
    foreach ($approvalHistory as $approval) {
        $levelNum = (int)$approval['approval_order'];
        $levelLabel = $approvalLevels[$levelNum] ?? 'Level ' . $levelNum;
        $status = strtolower((string)($approval['status'] ?? 'pending'));
        $statusClass = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'status-' . $status : 'status-pending';
        $approverName = !empty($approval['approver_name']) ? $approval['approver_name'] : ($approval['approved_by'] ?? '-');
        $html .= '<tr>
            <td class="center">Level ' . $levelNum . '</td>
            <td>' . h($levelLabel) . '</td>
            <td class="center"><span class="status ' . $statusClass . '">' . h(ucfirst($status)) . '</span></td>
            <td>' . h($approverName) . '</td>
            <td class="center">' . formatDateTimeId($approval['approved_at'] ?? null) . '</td>
        </tr>';
    }
    $html .= '</table>';
} else {
    $html .= '<table><tr><td class="center">Belum ada approval history.</td></tr></table>';
}

$html .= '
    <div class="section-title">H. APPROVAL / SIGNATURE</div>
    <table class="signature-table">
        <tr>
            <td style="width:25%"></td>
            <td style="width:25%"></td>
            <td style="width:25%"></td>
            <td style="width:25%"></td>
        </tr>
        <tr>
            <td class="signature-name">-</td>
            <td class="signature-name">-</td>
            <td class="signature-name">-</td>
            <td class="signature-name">-</td>
        </tr>
        <tr>
            <td class="signature-role">Sales Manager</td>
            <td class="signature-role">Sales Director</td>
            <td class="signature-role">Operational Director</td>
            <td class="signature-role">President Director</td>
        </tr>
    </table>

    <div class="small" style="margin-top:4px; text-align:right;">Generated: ' . date('d/m/Y H:i') . ' WIB</div>
</div>
</body>
</html>';

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

$filename = 'TR_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $tr_number) . '_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;