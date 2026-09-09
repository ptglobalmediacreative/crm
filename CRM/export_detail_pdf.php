<?php
// ============================================
// EXPORT DETAIL TR -> PDF
// Struktur disesuaikan dengan detailtr.php
// ============================================
ob_start();

error_reporting(0);
ini_set('display_errors', 0);

require_once 'config.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

date_default_timezone_set('Asia/Jakarta');

// ============================================
// CEK LOGIN
// ============================================
if (!isLoggedIn()) {
    ob_end_clean();
    die('Silakan login dulu!');
}

// ============================================
// AMBIL TR NUMBER
// ============================================
$tr_number = isset($_GET['tr_number']) ? bersihkan($_GET['tr_number']) : '';

if (empty($tr_number)) {
    ob_end_clean();
    die('TR Number tidak ditemukan!');
}

// ============================================
// HELPER
// ============================================
function h($value, $default = '-') {
    $value = ($value === null || $value === '') ? $default : $value;
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatRp($number) {
    return 'Rp ' . number_format((float)$number, 0, ',', '.');
}

function formatNumber($number) {
    return number_format((float)$number, 0, ',', '.');
}

function formatDateId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function formatDateTimeId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function getNamaProduk($unitId, $produkList) {
    foreach ($produkList as $produk) {
        if ((string)$produk['id'] === (string)$unitId) {
            return $produk['nama_produk'];
        }
    }
    return '-';
}

function statusLabel($status) {
    $status = strtolower((string)$status);
    if ($status === 'approved') return 'Approved';
    if ($status === 'rejected') return 'Rejected';
    return 'Pending';
}

function statusClass($status) {
    $status = strtolower((string)$status);
    if (in_array($status, ['approved', 'rejected', 'pending'], true)) {
        return 'status-' . $status;
    }
    return 'status-pending';
}

// ============================================
// DATA TRANSACTION REQUEST
// ============================================
$sql = "SELECT ad.tr_number,
               ad.due_date,
               ad.created_at AS request_date,
               ad.id AS latest_activity_id,
               a.id AS account_id,
               a.nama_pt,
               a.badan_usaha,
               a.alamat,
               a.npwp,
               a.nama_pic,
               a.jabatan_pic,
               a.no_hp_pic,
               a.email_pic,
               u.full_name AS sales_name,
               u.id AS sales_user_id,
               sa.sales_id,
               sa.id AS sales_activity_id
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
        LEFT JOIN accounts a ON sa.account_id = a.id
        LEFT JOIN users u ON sa.sales_id = u.id
        WHERE ad.tr_number = ?
        ORDER BY ad.id DESC
        LIMIT 1";

$stmt = $db->prepare($sql);
$stmt->execute([$tr_number]);
$request = $stmt->fetch();

if (!$request) {
    ob_end_clean();
    die('Data transaction request tidak ditemukan!');
}

// ============================================
// DETAIL TRANSACTION REQUEST / SUMMARY
// ============================================
$detailTR = null;
try {
    $stmtDetail = $db->prepare(
        "SELECT * FROM detail_transaction_requests
         WHERE trf_number = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmtDetail->execute([$tr_number]);
    $detailTR = $stmtDetail->fetch();
} catch (Exception $e) {
    $detailTR = null;
}

$statusTR = !empty($detailTR['status']) ? $detailTR['status'] : 'pending';
$request['status'] = $statusTR;

// ============================================
// PRODUK
// ============================================
$produkList = [];
try {
    $stmtProduk = $db->prepare(
        "SELECT id, nama_produk FROM products ORDER BY nama_produk ASC"
    );
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
    $stmtUnit = $db->prepare(
        "SELECT * FROM tr_detail_units
         WHERE trf_number = ?
         ORDER BY id ASC"
    );
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
    $stmtTOP = $db->prepare(
        "SELECT * FROM tr_term_of_payments
         WHERE trf_number = ?
         ORDER BY id ASC"
    );
    $stmtTOP->execute([$tr_number]);
    $termPayments = $stmtTOP->fetchAll();
} catch (Exception $e) {
    $termPayments = [];
}

// ============================================
// ADDITIONAL COST - MULTIPLE
// ============================================
$additionalCostItems = [];
try {
    $stmtCost = $db->prepare(
        "SELECT * FROM tr_additional_cost_items
         WHERE trf_number = ?
         ORDER BY id ASC"
    );
    $stmtCost->execute([$tr_number]);
    $additionalCostItems = $stmtCost->fetchAll();
} catch (Exception $e) {
    $additionalCostItems = [];
}

// ============================================
// MEDIATOR - MULTIPLE
// ============================================
$mediators = [];
try {
    $stmtMediator = $db->prepare(
        "SELECT * FROM tr_mediators
         WHERE trf_number = ?
         ORDER BY id ASC"
    );
    $stmtMediator->execute([$tr_number]);
    $mediators = $stmtMediator->fetchAll();
} catch (Exception $e) {
    $mediators = [];
}

// ============================================
// PRODUCT SUPPORT - MULTIPLE
// Sesuai detailtr.php:
// support_name + keterangan
// ============================================
$trSupports = [];
try {
    $stmtSupport = $db->prepare(
        "SELECT * FROM tr_product_supports
         WHERE trf_number = ?
         ORDER BY id ASC"
    );
    $stmtSupport->execute([$tr_number]);
    $trSupports = $stmtSupport->fetchAll();
} catch (Exception $e) {
    $trSupports = [];
}

// ============================================
// COST CALCULATION
// Sesuai detailtr.php
// ============================================
$costCalculation = null;
try {
    $stmtCC = $db->prepare(
        "SELECT * FROM tr_cost_calculations
         WHERE trf_number = ?
         ORDER BY id DESC LIMIT 1"
    );
    $stmtCC->execute([$tr_number]);
    $costCalculation = $stmtCC->fetch();
} catch (Exception $e) {
    $costCalculation = null;
}

// ============================================
// APPROVAL HISTORY
// Struktur approval TERBARU detailtr.php:
// 1 Sales Manager
// 2 Direktur Sales
// 3 Direktur Operasional
// 4 Direktur Utama
// ============================================
$approvalHistory = [];
try {
    $stmtApproval = $db->prepare(
        "SELECT ah.*, u.full_name AS approver_name
         FROM tr_approval_history ah
         LEFT JOIN users u ON ah.approved_by = u.id
         WHERE ah.trf_number = ?
         ORDER BY ah.approval_order ASC"
    );
    $stmtApproval->execute([$tr_number]);
    $approvalHistory = $stmtApproval->fetchAll();
} catch (Exception $e) {
    $approvalHistory = [];
}

$approvalLevels = [
    1 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    2 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    3 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    4 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// HITUNG TOTAL
// ============================================
$totalUnitGrandTotal = 0;
$totalUnitQty = 0;

foreach ($detailUnits as $unit) {
    $totalUnitGrandTotal += (float)($unit['grand_total'] ?? 0);
    $totalUnitQty += (int)($unit['qty'] ?? 0);
}

$totalTOP = 0;
foreach ($termPayments as $top) {
    $totalTOP += (float)($top['amount'] ?? 0);
}

$totalAdditionalCost = 0;
foreach ($additionalCostItems as $item) {
    $totalAdditionalCost += (float)($item['amount'] ?? 0);
}

$totalMediatorFee = 0;
foreach ($mediators as $med) {
    $totalMediatorFee += (float)($med['amount'] ?? 0);
}

// ============================================
// REKAP
// Mengikuti rumus yang dipakai detailtr.php:
// Total Masukan = Grand Total Unit - Additional Cost
// ============================================
$totalMasukan = $totalUnitGrandTotal - $totalAdditionalCost;

// Jika cost calculation tersedia, gunakan data tersimpan
$ccDealerPrice = $costCalculation['dealer_price'] ?? 0;
$ccPersentase = $costCalculation['persentase'] ?? 0;
$ccSupportPrice = $costCalculation['support_price'] ?? 0;
$ccTotalCogs = $costCalculation['total_cogs'] ?? 0;
$ccSellingPrice = $costCalculation['selling_price'] ?? $totalUnitGrandTotal;
$ccDealerProfitRequest = $costCalculation['dealer_profit_request'] ?? 0;
$ccDealerProfitNet = $costCalculation['dealer_profit_net'] ?? (
    $ccSellingPrice > 0 ? ($ccDealerProfitRequest / $ccSellingPrice) * 100 : 0
);

// ============================================
// NAMA CUSTOMER
// ============================================
$namaPT = $request['nama_pt'] ?? '-';
$badanUsaha = $request['badan_usaha'] ?? '';

$namaPTDisplay = (!empty($badanUsaha) && $namaPT !== '-')
    ? $namaPT . ', ' . $badanUsaha
    : $namaPT;

// ============================================
// LOGO
// ============================================
$logoHtml = '';
$logoPath = 'images/kopsurat.png';

if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoHtml =
        '<img src="data:image/png;base64,' . $logoData .
        '" class="logo-img" alt="Logo">';
}

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
    @page {
        margin: 7mm 7mm 7mm 7mm;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 7.2px;
        line-height: 1.25;
        color: #111;
        margin: 0;
        padding: 0;
    }

    .logo-wrap {
        text-align: right;
        margin-bottom: 8px;
        padding-right: 3px;
    }

    .logo-img {
        max-width: 270px;
        max-height: 38px;
        display: inline-block;
    }

    .title {
        border: 1px solid #1f1f1f;
        background: #16b5ea;
        text-align: center;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 5px;
        margin-bottom: 4px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    th, td {
        border: 1px solid #222;
        padding: 2.2px 3px;
        vertical-align: top;
        word-wrap: break-word;
    }

    th {
        background: #f0f0f0;
        text-align: center;
        font-weight: 700;
    }

    .label {
        background: #f3f3f3;
        font-weight: 700;
    }

    .section {
        background: #dfe8ef;
        border: 1px solid #222;
        border-bottom: 0;
        font-weight: 700;
        padding: 3px 4px;
        margin-top: 5px;
        text-transform: uppercase;
    }

    .green {
        background: #e6f0dc;
    }

    .yellow {
        background: #fff200;
    }

    .blue {
        background: #dff3fb;
    }

    .center {
        text-align: center;
    }

    .right {
        text-align: right;
    }

    .bold {
        font-weight: 700;
    }

    .money {
        white-space: nowrap;
    }

    .meta-table td {
        height: 15px;
    }

    .two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .two-col > tbody > tr > td {
        border: 0;
        padding: 0;
        vertical-align: top;
    }

    .two-col .col-left {
        width: 50%;
        padding-right: 2.5px;
    }

    .two-col .col-right {
        width: 50%;
        padding-left: 2.5px;
    }

    .unit-main td {
        vertical-align: middle;
    }

    .unit-detail td {
        min-height: 15px;
    }

    .summary-total td {
        font-size: 8px;
        font-weight: 700;
    }

    .top-table td {
        vertical-align: middle;
    }

    .top-total {
        font-weight: 700;
        background: #f3f3f3;
    }

    .cost-item-table th,
    .mediator-table th,
    .support-table th {
        background: #fff200;
    }

    .status {
        display: inline-block;
        border: 1px solid #555;
        padding: 1px 4px;
        font-weight: 700;
        font-size: 6.5px;
    }

    .status-pending {
        background: #fff1bf;
    }

    .status-approved {
        background: #d9efd9;
    }

    .status-rejected {
        background: #f5d2d2;
    }

    .keep {
        page-break-inside: avoid;
    }

    .page-break {
        page-break-before: always;
    }

    .calc-highlight {
        font-weight: 700;
        background: #e6f0dc;
    }

    .profit {
        font-weight: 700;
        background: #d9efd9;
    }

    .negative {
        background: #f5d2d2;
        font-weight: 700;
    }

    .small-note {
        font-size: 6.5px;
        color: #555;
    }
</style>
</head>

<body>

<div class="logo-wrap">' . $logoHtml . '</div>

<div class="title">TRANSACTION REQUEST FORM</div>

<!-- ===================================================== -->
<!-- A. SUMMARY / CUSTOMER + UNIT SUMMARY -->
<!-- ===================================================== -->
<div class="section" style="margin-top:0;">A. SUMMARY</div>

<table class="two-col">
    <tr>
        <td class="col-left">
            <table class="meta-table">
                <tr>
                    <td class="label" style="width:31%">TR Number</td>
                    <td class="bold">' . h($tr_number) . '</td>
                </tr>
                <tr>
                    <td class="label">Request Date</td>
                    <td>' . formatDateId($request['request_date'] ?? null) . '</td>
                </tr>
                <tr>
                    <td class="label">Due Date</td>
                    <td>' . formatDateId($request['due_date'] ?? null) . '</td>
                </tr>
                <tr>
                    <td class="label">Customer</td>
                    <td class="green bold">' . h($namaPTDisplay) . '</td>
                </tr>
                <tr>
                    <td class="label">NPWP</td>
                    <td>' . h($request['npwp'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">Address</td>
                    <td>' . nl2br(h($request['alamat'] ?? '-')) . '</td>
                </tr>
                <tr>
                    <td class="label">Customer PIC (Signer)</td>
                    <td>' . h($request['nama_pic'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">Position</td>
                    <td>' . h($request['jabatan_pic'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">Phone</td>
                    <td>' . h($request['no_hp_pic'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">e-Mail</td>
                    <td>' . h($request['email_pic'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">Salesman</td>
                    <td>' . h($request['sales_name'] ?? '-') . '</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td>
                        <span class="status ' . statusClass($request['status'] ?? 'pending') . '">
                            ' . h(statusLabel($request['status'] ?? 'pending')) . '
                        </span>
                    </td>
                </tr>
            </table>
        </td>

        <td class="col-right">
            <table class="unit-main">
                <tr>
                    <th style="width:27%">Model Unit</th>
                    <th style="width:8%">Qty</th>
                    <th style="width:7%">Curr.</th>
                    <th style="width:20%">Price / Unit<br>(Non PPN)</th>
                    <th style="width:15%">PPN 11%</th>
                    <th style="width:23%">Grand Total<br>Include PPN</th>
                </tr>';

if (count($detailUnits) > 0) {
    foreach ($detailUnits as $unit) {
        $priceNonPPN = (float)($unit['price'] ?? 0);
        $ppn = isset($unit['ppn'])
            ? (float)$unit['ppn']
            : ($priceNonPPN * 0.11);

        $html .= '
                <tr>
                    <td>' . h(getNamaProduk($unit['unit_id'] ?? null, $produkList)) . '</td>
                    <td class="center">' . h($unit['qty'] ?? '-') . '</td>
                    <td class="center">IDR</td>
                    <td class="right money">' . formatNumber($priceNonPPN) . '</td>
                    <td class="right money">' . formatNumber($ppn) . '</td>
                    <td class="right money bold">' . formatNumber($unit['grand_total'] ?? 0) . '</td>
                </tr>';
    }
} else {
    $html .= '
                <tr>
                    <td colspan="6" class="center">Belum ada detail unit</td>
                </tr>';
}

$html .= '
                <tr class="summary-total">
                    <td colspan="5" class="right">TOTAL GRAND TOTAL UNIT</td>
                    <td class="right money">' . formatRp($totalUnitGrandTotal) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- ===================================================== -->
<!-- B. DETAIL UNIT -->
<!-- ===================================================== -->
<div class="section">B. DETAIL UNIT</div>';

if (count($detailUnits) > 0) {
    foreach ($detailUnits as $index => $unit) {
        $html .= '
<table class="unit-detail keep">
    <tr>
        <td class="label" style="width:18%">Model Unit</td>
        <td style="width:32%">' . h(getNamaProduk($unit['unit_id'] ?? null, $produkList)) . '</td>
        <td class="label" style="width:18%">QTY</td>
        <td style="width:32%">' . h($unit['qty'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label">Price (Non PPN)</td>
        <td>' . formatRp($unit['price'] ?? 0) . '</td>
        <td class="label">PPN (11%)</td>
        <td>' . formatRp($unit['ppn'] ?? 0) . '</td>
    </tr>
    <tr>
        <td class="label">Grand Total Include PPN</td>
        <td class="green bold">' . formatRp($unit['grand_total'] ?? 0) . '</td>
        <td class="label">Transaction Type</td>
        <td>' . h($unit['transaction_type'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label">Specification</td>
        <td colspan="3">' . nl2br(h($unit['specification'] ?? '-')) . '</td>
    </tr>
    <tr>
        <td class="label">Additional Attachment / Safety Devices</td>
        <td colspan="3">' . nl2br(h($unit['additional_attachment'] ?? '-')) . '</td>
    </tr>
    <tr>
        <td class="label">Waranty</td>
        <td>' . h($unit['waranty'] ?? '-') . '</td>
        <td class="label">Free Part/Service</td>
        <td>' . h($unit['free_part_service'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label">Machine Location Works</td>
        <td>' . h($unit['machine_location'] ?? '-') . '</td>
        <td class="label">Delivery Terms</td>
        <td>' . h($unit['delivery_terms'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label">Delivery Schedule Plan</td>
        <td class="green bold">' . formatDateId($unit['delivery_schedule'] ?? null) . '</td>
        <td class="label">Unit Record</td>
        <td>Unit ' . ($index + 1) . ' of ' . count($detailUnits) . '</td>
    </tr>
</table>';
    }
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada detail unit</td>
    </tr>
</table>';
}

$html .= '
<table class="summary-total" style="margin-top:3px;">
    <tr>
        <td class="label" style="width:70%">TOTAL GRAND TOTAL UNIT</td>
        <td class="right money" style="width:30%">' . formatRp($totalUnitGrandTotal) . '</td>
    </tr>
</table>

<!-- ===================================================== -->
<!-- C. TERM OF PAYMENT -->
<!-- ===================================================== -->
<div class="section">C. TERM OF PAYMENT</div>';

if (count($termPayments) > 0) {
    $html .= '
<table class="top-table">
    <tr>
        <th style="width:25%">Payment Type</th>
        <th style="width:30%">Payment / Label</th>
        <th style="width:20%">Amount</th>
        <th style="width:25%">Keterangan</th>
    </tr>';

    foreach ($termPayments as $top) {
        $paymentType = strtolower((string)($top['payment_type'] ?? ''));
        $paymentTypeLabel = [
            'booking_fee' => 'Booking Fee',
            'down_payment' => 'Down Payment',
            'angsuran' => 'Angsuran',
            'nominal_po' => 'Nominal PO Leasing'
        ];

        $typeDisplay = $paymentTypeLabel[$paymentType]
            ?? ucwords(str_replace('_', ' ', $paymentType));

        $html .= '
    <tr>
        <td>' . h($typeDisplay) . '</td>
        <td>' . h($top['payment_label'] ?? '-') . '</td>
        <td class="right money bold">' . formatRp($top['amount'] ?? 0) . '</td>
        <td>' . nl2br(h($top['keterangan'] ?? '-')) . '</td>
    </tr>';
    }

    $html .= '
    <tr class="top-total">
        <td colspan="2" class="right">TOTAL TOP</td>
        <td class="right money">' . formatRp($totalTOP) . '</td>
        <td></td>
    </tr>
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada data Term of Payment</td>
    </tr>
</table>';
}

// =====================================================
// D. ADDITIONAL COST
// =====================================================
$html .= '
<div class="section">D. ADDITIONAL COST</div>';

if (count($additionalCostItems) > 0) {
    $html .= '
<table class="cost-item-table">
    <tr>
        <th style="width:6%">No</th>
        <th style="width:28%">Nama Item</th>
        <th style="width:23%">Nominal</th>
        <th style="width:43%">Keterangan</th>
    </tr>';

    foreach ($additionalCostItems as $i => $item) {
        $html .= '
    <tr>
        <td class="center">' . ($i + 1) . '</td>
        <td>' . h($item['item_name'] ?? '-') . '</td>
        <td class="right money bold">' . formatRp($item['amount'] ?? 0) . '</td>
        <td>' . nl2br(h($item['keterangan'] ?? '-')) . '</td>
    </tr>';
    }

    $html .= '
    <tr class="top-total">
        <td colspan="2" class="right bold">TOTAL ADDITIONAL COST</td>
        <td class="right money bold green">' . formatRp($totalAdditionalCost) . '</td>
        <td></td>
    </tr>
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada data Additional Cost</td>
    </tr>
</table>';
}

// =====================================================
// E. DATA MEDIATOR
// =====================================================
$html .= '
<div class="section">E. DATA MEDIATOR</div>';

if (count($mediators) > 0) {
    $html .= '
<table class="mediator-table">
    <tr>
        <th style="width:5%">No</th>
        <th style="width:19%">Name</th>
        <th style="width:15%">ID Card No</th>
        <th style="width:15%">NPWP No</th>
        <th style="width:14%">Bank Name</th>
        <th style="width:17%">Bank Account</th>
        <th style="width:15%">Amount</th>
    </tr>';

    foreach ($mediators as $i => $med) {
        $html .= '
    <tr>
        <td class="center">' . ($i + 1) . '</td>
        <td>' . h($med['name'] ?? '-') . '</td>
        <td>' . h($med['id_card_no'] ?? '-') . '</td>
        <td>' . h($med['npwp_no'] ?? '-') . '</td>
        <td>' . h($med['bank_name'] ?? '-') . '</td>
        <td>' . h($med['bank_account'] ?? '-') . '</td>
        <td class="right money bold">' . formatRp($med['amount'] ?? 0) . '</td>
    </tr>';
    }

    $html .= '
    <tr class="top-total">
        <td colspan="6" class="right bold">TOTAL MEDIATOR FEE</td>
        <td class="right money bold">' . formatRp($totalMediatorFee) . '</td>
    </tr>
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada data Mediator</td>
    </tr>
</table>';
}

// =====================================================
// F. PRODUCT SUPPORT
// =====================================================
$html .= '
<div class="section">F. PRODUCT SUPPORT</div>';

if (count($trSupports) > 0) {
    $html .= '
<table class="support-table">
    <tr>
        <th style="width:7%">No</th>
        <th style="width:33%">Nama Support</th>
        <th style="width:60%">Keterangan</th>
    </tr>';

    foreach ($trSupports as $i => $support) {
        $html .= '
    <tr>
        <td class="center">' . ($i + 1) . '</td>
        <td class="bold">' . h($support['support_name'] ?? '-') . '</td>
        <td>' . nl2br(h($support['keterangan'] ?? '-')) . '</td>
    </tr>';
    }

    $html .= '
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada data Product Support</td>
    </tr>
</table>';
}

// =====================================================
// G. COST CALCULATION
// =====================================================
$html .= '
<div class="section">G. COST CALCULATION</div>';

if ($costCalculation) {
    $html .= '
<table class="keep">
    <tr>
        <td class="label" style="width:30%">Dealer Price</td>
        <td class="right money" style="width:20%">' . formatRp($ccDealerPrice) . '</td>
        <td class="label" style="width:30%">Persentase Diskon</td>
        <td class="right" style="width:20%">' . number_format((float)$ccPersentase, 2, ',', '.') . '%</td>
    </tr>
    <tr>
        <td class="label">Support Price</td>
        <td class="right money">' . formatRp($ccSupportPrice) . '</td>
        <td class="label">Additional Cost</td>
        <td class="right money">' . formatRp($totalAdditionalCost) . '</td>
    </tr>
    <tr>
        <td class="label">Total COGS</td>
        <td class="right money bold">' . formatRp($ccTotalCogs) . '</td>
        <td class="label">Selling Price to Customer</td>
        <td class="right money bold">' . formatRp($ccSellingPrice) . '</td>
    </tr>
    <tr>
        <td class="label">Dealer Profit Request</td>
        <td class="right money profit">' . formatRp($ccDealerProfitRequest) . '</td>
        <td class="label">Dealer Profit Net</td>
        <td class="right profit">' . number_format((float)$ccDealerProfitNet, 2, ',', '.') . '%</td>
    </tr>
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada data Cost Calculation</td>
    </tr>
</table>';
}

// =====================================================
// H. REKAPITULASI
// =====================================================
$html .= '
<div class="section">H. REKAPITULASI</div>

<table class="summary-total">
    <tr>
        <td class="label" style="width:32%">Total Unit Qty</td>
        <td class="right" style="width:18%">' . formatNumber($totalUnitQty) . '</td>

        <td class="label" style="width:32%">Grand Total Include PPN</td>
        <td class="right money" style="width:18%">' . formatRp($totalUnitGrandTotal) . '</td>
    </tr>
    <tr>
        <td class="label">Total TOP</td>
        <td class="right money">' . formatRp($totalTOP) . '</td>

        <td class="label">Total Additional Cost</td>
        <td class="right money">' . formatRp($totalAdditionalCost) . '</td>
    </tr>
    <tr>
        <td class="label">Total Mediator Fee</td>
        <td class="right money">' . formatRp($totalMediatorFee) . '</td>

        <td class="label">Total Masukan</td>
        <td class="right money green">' . formatRp($totalMasukan) . '</td>
    </tr>
</table>';

// =====================================================
// I. APPROVAL HISTORY
// =====================================================
$html .= '
<div class="section">I. APPROVAL HISTORY</div>';

if (count($approvalHistory) > 0) {
    $html .= '
<table class="keep">
    <tr>
        <th style="width:8%">Level</th>
        <th style="width:24%">Role</th>
        <th style="width:16%">Status</th>
        <th style="width:27%">Approved By</th>
        <th style="width:25%">Approved At</th>
    </tr>';

    foreach ($approvalHistory as $approval) {
        $levelNum = (int)($approval['approval_order'] ?? 0);

        // Hanya tampilkan role yang sesuai struktur approval terbaru.
        $levelLabel = $approvalLevels[$levelNum]['label']
            ?? ('Level ' . $levelNum);

        $status = strtolower((string)($approval['status'] ?? 'pending'));

        $approverName = !empty($approval['approver_name'])
            ? $approval['approver_name']
            : (
                ($approval['approved_by'] ?? '') !== ''
                    ? $approval['approved_by']
                    : '-'
            );

        $approvedAt = formatDateTimeId($approval['approved_at'] ?? '');

        $html .= '
    <tr>
        <td class="center">Level ' . $levelNum . '</td>
        <td>' . h($levelLabel) . '</td>
        <td class="center">
            <span class="status ' . statusClass($status) . '">
                ' . h(statusLabel($status)) . '
            </span>
        </td>
        <td>' . h($approverName) . '</td>
        <td class="center">' . h($approvedAt) . '</td>
    </tr>';
    }

    $html .= '
</table>';
} else {
    $html .= '
<table>
    <tr>
        <td class="center">Belum ada approval history.</td>
    </tr>
</table>';
}

// =====================================================
// FOOTER
// =====================================================
$html .= '
<table style="margin-top:4px;">
    <tr>
        <td class="label" style="width:18%">Generated</td>
        <td>' . date('d/m/Y H:i') . ' WIB</td>
        <td class="label" style="width:18%">TR Number</td>
        <td class="bold">' . h($tr_number) . '</td>
    </tr>
</table>

<div class="small-note" style="margin-top:4px;">
    Document generated from Transaction Request detail data.
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

$filename = 'TR_' .
    preg_replace('/[^A-Za-z0-9_-]/', '_', $tr_number) .
    '_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;
