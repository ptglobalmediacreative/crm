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
    $number = (float)$number;
    return number_format($number, 0, ',', '.');
}

function formatDateId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '-';
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
// AMBIL DATA TRANSACTION REQUEST
// ============================================
$sql = "SELECT ad.tr_number,
               ad.due_date,
               ad.created_at as request_date,
               ad.id as latest_activity_id,
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
               sa.id as sales_activity_id
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
// DAPATKAN STATUS
// ============================================
$statusTR = 'pending';
try {
    $checkStatus = $db->prepare("SELECT status FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
    $checkStatus->execute([$tr_number]);
    $statusData = $checkStatus->fetch();
    if ($statusData && !empty($statusData['status'])) {
        $statusTR = $statusData['status'];
    }
} catch (Exception $e) {
    $statusTR = 'pending';
}
$request['status'] = $statusTR;

// ============================================
// DETAIL TRANSACTION REQUEST
// ============================================
$detailTR = null;
try {
    $stmtDetail = $db->prepare("SELECT * FROM detail_transaction_requests WHERE trf_number = ? ORDER BY id DESC LIMIT 1");
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
    $stmtProduk = $db->prepare("SELECT id, nama_produk FROM products ORDER BY nama_produk ASC");
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
    $stmtUnit = $db->prepare("SELECT * FROM tr_detail_units WHERE trf_number = ? ORDER BY id ASC");
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
    $stmtTOP = $db->prepare("SELECT * FROM tr_term_of_payments WHERE trf_number = ? ORDER BY id ASC");
    $stmtTOP->execute([$tr_number]);
    $termPayments = $stmtTOP->fetchAll();
} catch (Exception $e) {
    $termPayments = [];
}

// ============================================
// ADDITIONAL COST ITEMS (TABEL BARU)
// ============================================
$additionalCostItems = [];
try {
    $stmtCostItems = $db->prepare("SELECT * FROM tr_additional_cost_items WHERE trf_number = ? ORDER BY id ASC");
    $stmtCostItems->execute([$tr_number]);
    $additionalCostItems = $stmtCostItems->fetchAll();
} catch (Exception $e) {
    $additionalCostItems = [];
}

// ============================================
// MEDIATOR
// ============================================
$mediators = [];
try {
    $stmtMediator = $db->prepare("SELECT * FROM tr_mediators WHERE trf_number = ? ORDER BY id ASC");
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
    $stmtApproval = $db->prepare(
        "SELECT ah.*, u.full_name as approver_name
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

// ============================================
// APPROVAL LEVELS
// ============================================
$approvalLevels = [
    1 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    2 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    3 => ['role' => 'business', 'label' => 'Divisi Business'],
    4 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    5 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// HITUNG TOTAL
// ============================================
$totalUnitGrandTotal = 0;
foreach ($detailUnits as $unit) {
    $totalUnitGrandTotal += (float)($unit['grand_total'] ?? 0);
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

$totalMasukan = $totalUnitGrandTotal - $totalAdditionalCost;

// ============================================
// NAMA PT DISPLAY
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
    $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" class="logo-img" alt="Logo">';
}

ob_end_clean();

// ============================================
// BUILD HTML PDF
// ============================================
$html = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<title>Transaction Request Form - ' . h($tr_number) . '</title>
<style>
    @page { margin: 7mm 7mm 7mm 7mm; }
    * { box-sizing: border-box; }
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
        margin-bottom: 10px;
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

    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: 700; }

    .meta-table td {
        height: 15px;
    }

    .two-col {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 0;
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

    .money {
        white-space: nowrap;
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

    .cost-item-table th {
        background: #fff200;
    }

    .mediator-table th {
        background: #fff200;
    }

    .status {
        display: inline-block;
        border: 1px solid #555;
        padding: 1px 4px;
        font-weight: 700;
        font-size: 6.5px;
    }

    .status-pending { background: #fff1bf; }
    .status-approved { background: #d9efd9; }
    .status-rejected { background: #f5d2d2; }

    .keep {
        page-break-inside: avoid;
    }
</style>
</head>
<body>

<div class="logo-wrap">' . $logoHtml . '</div>
<div class="title">TRANSACTION REQUEST FORM</div>

<!-- HEADER / CUSTOMER + UNIT SUMMARY -->
<table class="two-col">
    <tr>
        <td class="col-left">
            <table class="meta-table">
                <tr><td class="label" style="width:31%">TR Number</td><td class="bold">' . h($tr_number) . '</td></tr>
                <tr><td class="label">Request Date</td><td>' . formatDateId($request['request_date'] ?? null) . '</td></tr>
                <tr><td class="label">Customer</td><td class="green bold">' . h($namaPTDisplay) . '</td></tr>
                <tr><td class="label">NPWP</td><td>' . h($request['npwp'] ?? '-') . '</td></tr>
                <tr><td class="label">Address</td><td>' . nl2br(h($request['alamat'] ?? '-')) . '</td></tr>
                <tr><td class="label">Customer PIC (Signer)</td><td>' . h($request['nama_pic'] ?? '-') . '</td></tr>
                <tr><td class="label">Position</td><td>' . h($request['jabatan_pic'] ?? '-') . '</td></tr>
                <tr><td class="label">Phone</td><td>' . h($request['no_hp_pic'] ?? '-') . '</td></tr>
                <tr><td class="label">e-Mail</td><td>' . h($request['email_pic'] ?? '-') . '</td></tr>
                <tr><td class="label">Salesman</td><td>' . h($request['sales_name'] ?? '-') . '</td></tr>
                <tr>
                    <td class="label">Deskripsi</td>
                    <td>' . nl2br(h($detailTR['deskripsi'] ?? '-')) . '</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td><span class="status ' . statusClass($request['status'] ?? 'pending') . '">' . h(statusLabel($request['status'] ?? 'pending')) . '</span></td>
                </tr>
            </table>
        </td>

        <td class="col-right">
            <table class="unit-main">
                <tr>
                    <th style="width:28%">Model Unit</th>
                    <th style="width:8%">Qty</th>
                    <th style="width:8%">Curr.</th>
                    <th style="width:18%">Price / Unit (Non PPN)</th>
                    <th style="width:14%">PPN 11%</th>
                    <th style="width:24%">Grand Total</th>
                </tr>';

if (count($detailUnits) > 0) {
    foreach ($detailUnits as $unit) {
        $priceNonPPN = (float)($unit['price'] ?? 0);
        $ppn = $priceNonPPN * 0.11;
        $html .= '<tr>
            <td>' . h(getNamaProduk($unit['unit_id'] ?? null, $produkList)) . '</td>
            <td class="center">' . h($unit['qty'] ?? '-') . '</td>
            <td class="center">IDR</td>
            <td class="right money">' . formatNumber($priceNonPPN) . '</td>
            <td class="right money">' . formatNumber($ppn) . '</td>
            <td class="right money bold">' . formatNumber($unit['grand_total'] ?? 0) . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="6" class="center">Belum ada detail unit</td></tr>';
}

$html .= '
                <tr class="summary-total">
                    <td colspan="5" class="right">TOTAL GRAND TOTAL UNIT</td>
                    <td class="right money">' . formatRp($totalUnitGrandTotal) . '</td>
                </tr>
            </table>

            <div class="section" style="margin-top:5px;">C. TERM OF PAYMENT</div>
            <table class="top-table">
                <tr>
                    <th style="width:34%">Payment</th>
                    <th style="width:30%">Amount</th>
                    <th style="width:36%">Keterangan</th>
                </tr>';

if (count($termPayments) > 0) {
    foreach ($termPayments as $top) {
        $html .= '<tr>
            <td>' . h($top['payment_label'] ?? '-') . '</td>
            <td class="right money bold">' . formatRp($top['amount'] ?? 0) . '</td>
            <td>' . nl2br(h($top['keterangan'] ?? '-')) . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="3" class="center">Belum ada data TOP</td></tr>';
}

$html .= '<tr class="top-total">
                <td class="right">TOTAL TOP</td>
                <td class="right money">' . formatRp($totalTOP) . '</td>
                <td></td>
            </tr>
            </table>
        </td>
    </tr>
</table>

<!-- B. DETAIL UNIT -->
<div class="section">B. DETAIL UNIT</div>';

if (count($detailUnits) > 0) {
    foreach ($detailUnits as $index => $unit) {
        $html .= '<table class="unit-detail keep">
            <tr>
                <td class="label" style="width:18%">Model Unit</td>
                <td style="width:32%">' . h(getNamaProduk($unit['unit_id'] ?? null, $produkList)) . '</td>
                <td class="label" style="width:18%">QTY</td>
                <td style="width:32%">' . h($unit['qty'] ?? '-') . '</td>
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
                <td class="label">Machine Location Works</td>
                <td>' . h($unit['machine_location'] ?? '-') . '</td>
            </tr>
            <tr>
                <td class="label">Delivery Terms</td>
                <td>' . h($unit['delivery_terms'] ?? '-') . '</td>
                <td class="label">Delivery Schedule Plan</td>
                <td class="green bold">' . formatDateId($unit['delivery_schedule'] ?? null) . '</td>
            </tr>
            <tr>
                <td class="label">Transaction Type</td>
                <td colspan="3">' . h($unit['transaction_type'] ?? '-') . '</td>
            </tr>
        </table>';
    }
} else {
    $html .= '<table><tr><td class="center">Belum ada detail unit</td></tr></table>';
}

$html .= '
<table class="summary-total" style="margin-top:3px;">
    <tr>
        <td class="label" style="width:70%">TOTAL GRAND TOTAL UNIT</td>
        <td class="right money" style="width:30%">' . formatRp($totalUnitGrandTotal) . '</td>
    </tr>
</table>

<!-- D. ADDITIONAL COST (MULTIPLE ITEMS) -->
<div class="section">D. ADDITIONAL COST</div>';

if (count($additionalCostItems) > 0) {
    $html .= '<table class="cost-item-table">
        <tr>
            <th style="width:5%">No</th>
            <th style="width:25%">Nama Item</th>
            <th style="width:25%">Nominal</th>
            <th style="width:45%">Keterangan</th>
        </tr>';
    foreach ($additionalCostItems as $i => $item) {
        $html .= '<tr>
            <td class="center">' . ($i + 1) . '</td>
            <td>' . h($item['item_name'] ?? '-') . '</td>
            <td class="right money bold">' . formatRp($item['amount'] ?? 0) . '</td>
            <td>' . nl2br(h($item['keterangan'] ?? '-')) . '</td>
        </tr>';
    }
    $html .= '<tr class="top-total">
        <td colspan="2" class="right bold">TOTAL ADDITIONAL COST</td>
        <td class="right money bold green">' . formatRp($totalAdditionalCost) . '</td>
        <td></td>
    </tr>
    </table>';
} else {
    $html .= '<table><tr><td class="center">Belum ada data Additional Cost</td></tr></table>';
}

// E. MEDIATOR
$html .= '
<div class="section">E. DATA MEDIATOR</div>';

if (count($mediators) > 0) {
    $html .= '<table class="mediator-table">
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
        $html .= '<tr>
            <td class="center">' . ($i + 1) . '</td>
            <td>' . h($med['name'] ?? '-') . '</td>
            <td>' . h($med['id_card_no'] ?? '-') . '</td>
            <td>' . h($med['npwp_no'] ?? '-') . '</td>
            <td>' . h($med['bank_name'] ?? '-') . '</td>
            <td>' . h($med['bank_account'] ?? '-') . '</td>
            <td class="right money bold">' . formatRp($med['amount'] ?? 0) . '</td>
        </tr>';
    }
    $html .= '<tr class="top-total">
        <td colspan="6" class="right bold">TOTAL MEDIATOR FEE</td>
        <td class="right money bold">' . formatRp($totalMediatorFee) . '</td>
    </tr>
    </table>';
} else {
    $html .= '<table><tr><td class="center">Belum ada data mediator</td></tr></table>';
}

// F. RECAP
$html .= '
<div class="section">F. REKAPITULASI</div>
<table class="summary-total">
    <tr>
        <td class="label" style="width:34%">Grand Total Include PPN</td>
        <td class="right money" style="width:16%">' . formatRp($totalUnitGrandTotal) . '</td>
        <td class="label" style="width:34%">Total Additional Cost</td>
        <td class="right money" style="width:16%">' . formatRp($totalAdditionalCost) . '</td>
    </tr>
    <tr>
        <td class="label" style="width:34%">Total Masukan</td>
        <td class="right money green" colspan="3">' . formatRp($totalMasukan) . '</td>
    </tr>
</table>

<!-- G. APPROVAL HISTORY -->
<div class="section">G. APPROVAL HISTORY</div>';

if (count($approvalHistory) > 0) {
    $html .= '<table>
        <tr>
            <th style="width:9%">Level</th>
            <th style="width:23%">Role</th>
            <th style="width:16%">Status</th>
            <th style="width:27%">Approved By</th>
            <th style="width:25%">Approved At</th>
        </tr>';
    foreach ($approvalHistory as $approval) {
        $levelNum = (int)($approval['approval_order'] ?? 0);
        $levelLabel = $approvalLevels[$levelNum]['label'] ?? ('Level ' . $levelNum);
        $status = strtolower((string)($approval['status'] ?? 'pending'));
        $approverName = !empty($approval['approver_name'])
            ? $approval['approver_name']
            : (($approval['approved_by'] ?? '') !== '' ? $approval['approved_by'] : '-');
        
        $approvedAtRaw = $approval['approved_at'] ?? '';
        $approvedAtDisplay = '-';
        if (!empty($approvedAtRaw) && strtotime($approvedAtRaw) !== false) {
            $approvedAtDisplay = date('d/m/Y H:i', strtotime($approvedAtRaw));
        }

        $html .= '<tr>
            <td class="center">Level ' . $levelNum . '</td>
            <td>' . h($levelLabel) . '</td>
            <td class="center"><span class="status ' . statusClass($status) . '">' . h(statusLabel($status)) . '</span></td>
            <td>' . h($approverName) . '</td>
            <td class="center">' . $approvedAtDisplay . '</td>
        </tr>';
    }
    $html .= '</table>';
} else {
    $html .= '<table><tr><td class="center">Belum ada approval history.</td></tr></table>';
}

// FOOTER
$html .= '
<table style="margin-top:3px;">
    <tr>
        <td class="label" style="width:18%">Generated</td>
        <td>' . date('d/m/Y H:i') . ' WIB</td>
        <td class="label" style="width:18%">TR Number</td>
        <td class="bold">' . h($tr_number) . '</td>
    </tr>
</table>


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