<?php
// ============================================
// EXPORT DETAIL DELIVERY INSTRUCTION -> PDF
// Sumber data mengikuti detaildi.php terbaru
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
// AMBIL DI NUMBER
// ============================================
$di_number = isset($_GET['di_number']) ? bersihkan($_GET['di_number']) : '';

if (empty($di_number)) {
    ob_end_clean();
    die('DI Number tidak ditemukan!');
}

// ============================================
// HELPER
// ============================================
function h($value, $default = '-') {
    $value = ($value === null || $value === '') ? $default : $value;
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function formatDateLongId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d F Y', $ts) : '-';
}

function formatDateTimeId($date) {
    if (empty($date)) return '-';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
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

function getApprovalLabel($approvalLevels, $order) {
    return $approvalLevels[(int)$order]['label'] ?? ('Level ' . (int)$order);
}

// ============================================
// AMBIL DATA DELIVERY INSTRUCTION
// ============================================
$sql = "SELECT ad.di_number,
               ad.tr_number,
               sa.leads_number AS activity_number,
               ad.due_date,
               ad.created_at as request_date,
               ad.id as activity_detail_id,
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
        WHERE ad.di_number = ?
        ORDER BY ad.id DESC
        LIMIT 1";

$stmt = $db->prepare($sql);
$stmt->execute([$di_number]);
$request = $stmt->fetch();

if (!$request) {
    ob_end_clean();
    die('Data delivery instruction tidak ditemukan!');
}

// ============================================
// DETAIL DI
// ============================================
$detailDI = null;
try {
    $stmtDetail = $db->prepare(
        "SELECT *
         FROM detail_delivery_instructions
         WHERE di_number = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmtDetail->execute([$di_number]);
    $detailDI = $stmtDetail->fetch();
} catch (Exception $e) {
    $detailDI = null;
}

$request['status'] = $detailDI['status'] ?? 'pending';
$request['no_so'] = $detailDI['no_so'] ?? '';

// ============================================
// APPROVAL HISTORY
// ============================================
$approvalHistory = [];
try {
    $stmtApproval = $db->prepare(
        "SELECT *
         FROM di_approval_history
         WHERE di_number = ?
         ORDER BY approval_order ASC"
    );
    $stmtApproval->execute([$di_number]);
    $approvalHistory = $stmtApproval->fetchAll();
} catch (Exception $e) {
    $approvalHistory = [];
}

// ============================================
// APPROVAL LEVELS
// ============================================
$approvalLevels = [
    1 => ['role' => 'business', 'label' => 'Business'],
    2 => ['role' => 'part_support', 'label' => 'Part Support'],
    3 => ['role' => 'service_support', 'label' => 'Service Support'],
    4 => ['role' => 'finance', 'label' => 'Finance'],
    5 => ['role' => 'sales_manager', 'label' => 'Sales Manager'],
    6 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    7 => ['role' => 'direktur_operasional', 'label' => 'Direktur Operasional'],
    8 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// DATA UNITS
// ============================================
$diUnits = [];
try {
    $stmtUnit = $db->prepare(
        "SELECT * FROM di_units
         WHERE di_number = ?
         ORDER BY id ASC"
    );
    $stmtUnit->execute([$di_number]);
    $diUnits = $stmtUnit->fetchAll();
} catch (Exception $e) {
    $diUnits = [];
}

// ============================================
// DATA ACCESSORIES
// ============================================
$diAccessories = [];
try {
    $stmtAcc = $db->prepare(
        "SELECT * FROM di_accessories
         WHERE di_number = ?
         ORDER BY id ASC"
    );
    $stmtAcc->execute([$di_number]);
    $diAccessories = $stmtAcc->fetchAll();
} catch (Exception $e) {
    $diAccessories = [];
}

// ============================================
// DATA LOGISTICS
// ============================================
$diLogistics = null;
try {
    $stmtLog = $db->prepare(
        "SELECT * FROM di_logistics
         WHERE di_number = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmtLog->execute([$di_number]);
    $diLogistics = $stmtLog->fetch();
} catch (Exception $e) {
    $diLogistics = null;
}

// ============================================
 // DATA DETAIL PART
 // ============================================
 $diParts = [];
 try {
     $stmtPart = $db->prepare(
         "SELECT * FROM di_parts
          WHERE di_number = ?
          ORDER BY id ASC"
     );
     $stmtPart->execute([$di_number]);
     $diParts = $stmtPart->fetchAll();
 } catch (Exception $e) {
     $diParts = [];
 }

 // ============================================
 // DATA KOMPARASI LOGISTIK
 // ============================================
 $diLogisticsComparisons = [];
 try {
     $stmtLogComp = $db->prepare(
         "SELECT * FROM di_logistics_comparisons
          WHERE di_number = ?
          ORDER BY id ASC"
     );
     $stmtLogComp->execute([$di_number]);
     $diLogisticsComparisons = $stmtLogComp->fetchAll();
 } catch (Exception $e) {
     $diLogisticsComparisons = [];
 }

 $selectedLogisticsVendor = null;
 foreach ($diLogisticsComparisons as $comparison) {
     if ((int)($comparison['is_selected'] ?? 0) === 1) {
         $selectedLogisticsVendor = $comparison;
         break;
     }
 }

 // ============================================
 // DATA PRODUCT SUPPORTS
// ============================================
$diSupports = [];
try {
    $stmtSup = $db->prepare(
        "SELECT * FROM di_product_supports
         WHERE di_number = ?
         ORDER BY id ASC"
    );
    $stmtSup->execute([$di_number]);
    $diSupports = $stmtSup->fetchAll();
} catch (Exception $e) {
    $diSupports = [];
}

$supportsGrouped = [
    'free_filter_engine' => [],
    'jarak_service' => [],
    'catatan' => [],
    'free_service' => [],
    'warranty' => []
];

foreach ($diSupports as $support) {
    $type = $support['support_type'] ?? '';
    if (isset($supportsGrouped[$type])) {
        $supportsGrouped[$type][] = $support['value'] ?? '';
    }
}

// ============================================
// LOGO
// ============================================
$logoHtml = '';
$logoPath = 'images/kopsurat.png';
if (file_exists($logoPath)) {
    $logoData = base64_encode(file_get_contents($logoPath));
    $logoHtml = '<img src="data:image/png;base64,' . $logoData . '" class="logo-img" alt="PT Ganda Elang Tangguh">';
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
<title>Delivery Instruction - ' . h($di_number) . '</title>
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

    .section {
        background: #dfe8ef;
        border: 1px solid #222;
        border-bottom: 0;
        font-weight: 700;
        padding: 3px 4px;
        margin-top: 5px;
        text-transform: uppercase;
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

    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: 700; }

    .green { background: #e6f0dc; }
    .yellow { background: #fff200; }

    .meta-table td {
        height: 15px;
    }

    .meta-label { width: 18%; font-weight: 700; }
    .meta-value { width: 32%; }

    .unit-table td { text-align: center; height: 18px; }
    .unit-table .unit-body td { height: 50px; vertical-align: middle; }

    .accessory-table td { height: 16px; vertical-align: middle; }
    .accessory-empty { height: 60px !important; }

    .logistics-table td { height: 16px; }
    .support-table td { height: 16px; vertical-align: middle; }

    .support-label { width: 31%; font-weight: 700; background: #f3f3f3; }
    .support-value { width: 69%; }

    .approval-table th { background: #fff200; }
    .approval-table td { height: 16px; vertical-align: middle; }

    .status {
        display: inline-block;
        border: 1px solid #555;
        padding: 1px 5px;
        font-weight: 700;
        font-size: 6.5px;
    }
    .status-pending { background: #fff1bf; }
    .status-approved { background: #d9efd9; }
    .status-rejected { background: #f5d2d2; }

    .checkline {
        display: inline-block;
        min-width: 26mm;
        margin-right: 4mm;
    }

    .keep { page-break-inside: avoid; }
</style>
</head>
<body>

<div class="logo-wrap">' . $logoHtml . '</div>

<div class="title">DELIVERY INSTRUCTION</div>

<!-- DATA PENJUALAN -->
<div class="section">A. DATA PENJUALAN</div>
<table class="meta-table">
    <tr>
        <td class="label meta-label">Activity Number</td>
        <td class="meta-value">' . h($request['activity_number'] ?? '-') . '</td>
        <td class="label meta-label">No. DI</td>
        <td class="bold meta-value">' . h($di_number) . '</td>
    </tr>
    <tr>
        <td class="label meta-label">No. TR</td>
        <td class="meta-value">' . h($request['tr_number'] ?? '-') . '</td>
        <td class="label meta-label">Tanggal</td>
        <td class="meta-value">' . formatDateLongId($request['request_date'] ?? null) . '</td>
    </tr>
    <tr>
        <td class="label meta-label">No. SO</td>
        <td class="meta-value">' . h($request['no_so'] ?? '-') . '</td>
        <td class="label meta-label">Sales</td>
        <td class="meta-value">' . h($request['sales_name'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label meta-label">Status</td>
        <td colspan="3" class="meta-value"><span class="status ' . statusClass($request['status'] ?? 'pending') . '">' . h(statusLabel($request['status'] ?? 'pending')) . '</span></td>
    </tr>
</table>

<!-- DATA CUSTOMER -->
<div class="section">B. DATA CUSTOMER</div>
<table class="meta-table">
    <tr>
        <td class="label meta-label">Customer</td>
        <td colspan="3" class="green bold">' . h($request['nama_pt'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label meta-label">Alamat</td>
        <td colspan="3">' . nl2br(h($request['alamat'] ?? '-')) . '</td>
    </tr>
    <tr>
        <td class="label meta-label">PIC</td>
        <td style="width:32%">' . h($request['nama_pic'] ?? '-') . '</td>
        <td class="label meta-label">No. Contact</td>
        <td style="width:32%">' . h($request['no_hp_pic'] ?? '-') . '</td>
    </tr>
</table>

<!-- DATA UNIT -->
<div class="section">C. DATA UNIT</div>
<table class="meta-table">
    <tr>
        <td class="label meta-label">Lokasi Unit</td>
        <td>' . h($diUnits[0]['lokasi_unit'] ?? '-') . '</td>
        <td class="label meta-label">Cabang</td>
        <td>' . h($diUnits[0]['cabang'] ?? '-') . '</td>
    </tr>
</table>
<table class="unit-table">
    <tr>
        <th style="width:16%">Kode Unit</th>
        <th style="width:16%">Brand</th>
        <th style="width:16%">Tipe</th>
        <th style="width:18%">Serial Number</th>
        <th style="width:17%">Engine Number</th>
        <th style="width:17%">Keterangan</th>
    </tr>';

if (count($diUnits) > 0) {
    foreach ($diUnits as $unit) {
        $html .= '<tr class="unit-body">
            <td>' . h($unit['kode_unit'] ?? '-') . '</td>
            <td>' . h($unit['brand'] ?? '-') . '</td>
            <td>' . h($unit['tipe'] ?? '-') . '</td>
            <td>' . h($unit['serial_number'] ?? '-') . '</td>
            <td>' . h($unit['engine_number'] ?? '-') . '</td>
            <td>' . nl2br(h($unit['keterangan'] ?? '-')) . '</td>
        </tr>';
    }
} else {
    $html .= '<tr class="unit-body"><td colspan="6" class="center">Belum ada data unit</td></tr>';
}

$html .= '</table>

<!-- AKSESORIS -->
<div class="section">D. AKSESORIS</div>
<table class="accessory-table">
    <tr>
        <th style="width:10%">No</th>
        <th style="width:32%">Uraian</th>
        <th style="width:18%">Satuan</th>
        <th style="width:15%">Jumlah</th>
        <th style="width:25%">Keterangan</th>
    </tr>';

if (count($diAccessories) > 0) {
    foreach ($diAccessories as $acc) {
        $html .= '<tr>
            <td class="center">' . h($acc['no'] ?? '-') . '</td>
            <td>' . h($acc['uraian'] ?? '-') . '</td>
            <td class="center">' . h($acc['satuan'] ?? '-') . '</td>
            <td class="center">' . h($acc['jumlah'] ?? '0') . '</td>
            <td>' . nl2br(h($acc['keterangan'] ?? '-')) . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="5" class="accessory-empty center">Belum ada data aksesoris</td></tr>';
}

$html .= '</table>

<!-- LOGISTIK -->
<div class="section">E. LOGISTIK</div>
<table class="logistics-table">
    <tr>
        <td class="label meta-label">Lokasi Pengambilan</td>
        <td colspan="3">' . h($diLogistics['lokasi_pengambilan'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label meta-label">Lokasi Pengiriman</td>
        <td colspan="3">' . h($diLogistics['lokasi_pengiriman'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="label meta-label">Transportir</td>
        <td style="width:32%">' . h($diLogistics['transportir'] ?? '-') . '</td>
        <td class="label meta-label">Waktu Pengiriman</td>
        <td style="width:32%">' . formatDateLongId($diLogistics['waktu_pengiriman'] ?? null) . '</td>
    </tr>
    <tr>
        <td class="label meta-label">ETA</td>
        <td colspan="3">' . formatDateLongId($diLogistics['eta'] ?? null) . '</td>
    </tr>
</table>

<!-- DETAIL PART -->
<div class="section">F. DETAIL PART</div>
<table class="accessory-table">
    <tr>
        <th style="width:7%">No</th>
        <th style="width:18%">Part Number</th>
        <th style="width:25%">Description</th>
        <th style="width:14%">Price</th>
        <th style="width:9%">Qty</th>
        <th style="width:12%">Jumlah Unit</th>
        <th style="width:15%">Total Amount</th>
    </tr>';

if (count($diParts) > 0) {
    $grandTotalParts = 0;
    foreach ($diParts as $idx => $part) {
        $grandTotalParts += (float)($part['total_amount'] ?? 0);
        $html .= '<tr>
            <td class="center">' . ($idx + 1) . '</td>
            <td>' . h($part['part_number'] ?? '-') . '</td>
            <td>' . h($part['description'] ?? '-') . '</td>
            <td class="right">Rp ' . number_format((float)($part['price'] ?? 0), 0, ',', '.') . '</td>
            <td class="center">' . h($part['qty'] ?? '0') . '</td>
            <td class="center">' . count($diUnits) . '</td>
            <td class="right bold">Rp ' . number_format((float)($part['total_amount'] ?? 0), 0, ',', '.') . '</td>
        </tr>';
    }
    $html .= '<tr>
        <td colspan="6" class="right bold">Grand Total</td>
        <td class="right bold">Rp ' . number_format($grandTotalParts, 0, ',', '.') . '</td>
    </tr>';
} else {
    $html .= '<tr><td colspan="7" class="center accessory-empty">Belum ada data detail part</td></tr>';
}

$html .= '</table>

<!-- KOMPARASI LOGISTIK -->
<div class="section">G. KOMPARASI HARGA LOGISTIK UNIT</div>
<table class="accessory-table">
    <tr>
        <th style="width:9%">Vendor</th>
        <th style="width:22%">Nama Vendor</th>
        <th style="width:18%">Metode Pembayaran</th>
        <th style="width:13%">ETA Kirim</th>
        <th style="width:15%">Harga</th>
        <th style="width:23%">Keterangan</th>
    </tr>';

if (count($diLogisticsComparisons) > 0) {
    foreach ($diLogisticsComparisons as $idx => $vendor) {
        $isSelected = (int)($vendor['is_selected'] ?? 0) === 1;
        $html .= '<tr' . ($isSelected ? ' class="green"' : '') . '>
            <td class="center bold">Vendor ' . chr(65 + $idx) . '</td>
            <td>' . h($vendor['vendor_name'] ?? '-') . '</td>
            <td>' . h($vendor['payment_method'] ?? '-') . '</td>
            <td class="center">' . ($vendor['eta_kirim'] ? formatDateId($vendor['eta_kirim']) : '-') . '</td>
            <td class="right">Rp ' . number_format((float)($vendor['harga'] ?? 0), 0, ',', '.') . '</td>
            <td>' . h($vendor['keterangan'] ?? '-') . '</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="6" class="center accessory-empty">Belum ada komparasi harga logistik</td></tr>';
}

if ($selectedLogisticsVendor) {
    $html .= '<tr>
        <td class="label" colspan="2">Vendor Terpilih</td>
        <td colspan="4" class="green bold">' . h($selectedLogisticsVendor['vendor_name'] ?? '-') . '</td>
    </tr>';
}

$html .= '</table>

<!-- PRODUCT SUPPORT -->
<div class="section">H. PRODUCT SUPPORT</div>
<table class="support-table">
    <tr>
        <td class="support-label">Free Filter (Engine)</td>
        <td class="support-value">';

$filterValues = $supportsGrouped['free_filter_engine'];
if (count($filterValues) > 0) {
    foreach ($filterValues as $value) {
        $html .= '<span class="checkline">[X] ' . h($value) . '</span>';
    }
} else {
    $html .= '[ ] 250 HM&nbsp;&nbsp;&nbsp;&nbsp; [ ] 500 HM&nbsp;&nbsp;&nbsp;&nbsp; [ ] 1000 HM';
}

$html .= '</td>
    </tr>
    <tr>
        <td class="support-label">Free Service</td>
        <td class="support-value">';

$serviceValues = $supportsGrouped['free_service'];
if (count($serviceValues) > 0) {
    foreach ($serviceValues as $value) {
        $html .= '<span class="checkline">[X] ' . h($value) . '</span>';
    }
} else {
    $html .= '-';
}

$html .= '</td>
    </tr>
    <tr>
        <td class="support-label">Jarak Service</td>
        <td class="support-value">';

$jarakValues = $supportsGrouped['jarak_service'];
$html .= count($jarakValues) > 0 ? nl2br(h(implode("\n", $jarakValues))) : '-';

$html .= '</td>
    </tr>
    <tr>
        <td class="support-label">Warranty</td>
        <td class="support-value">';

$warrantyValues = $supportsGrouped['warranty'];
$html .= count($warrantyValues) > 0 ? nl2br(h(implode("\n", $warrantyValues))) : '-';

$html .= '</td>
    </tr>
    <tr>
        <td class="support-label">Catatan</td>
        <td class="support-value">';

$noteValues = $supportsGrouped['catatan'];
$html .= count($noteValues) > 0 ? nl2br(h(implode("\n", $noteValues))) : '-';

$html .= '</td>
    </tr>
</table>

<!-- APPROVAL HISTORY -->
<div class="section">I. APPROVAL HISTORY</div>';

$approvalByOrder = [];
foreach ($approvalHistory as $approval) {
    $approvalByOrder[(int)($approval['approval_order'] ?? 0)] = $approval;
}

$html .= '<table class="approval-table">
    <tr>
        <th style="width:9%">Level</th>
        <th style="width:28%">Approval</th>
        <th style="width:15%">Status</th>
        <th style="width:28%">Approved By</th>
        <th style="width:20%">Approved At</th>
    </tr>';

for ($order = 1; $order <= 8; $order++) {
    $approval = $approvalByOrder[$order] ?? null;
    $status = $approval ? strtolower((string)($approval['status'] ?? 'pending')) : 'pending';

    $approvedBy = '-';
    if ($approval && !empty($approval['approved_by'])) {
        try {
            $stmtUser = $db->prepare("SELECT full_name FROM users WHERE id = ?");
            $stmtUser->execute([$approval['approved_by']]);
            $userName = $stmtUser->fetchColumn();
            $approvedBy = $userName ?: '-';
        } catch (Exception $e) {
            $approvedBy = '-';
        }
    }

    $approvedAt = $approval['approved_at'] ?? null;

    $html .= '<tr>
        <td class="center">Level ' . $order . '</td>
        <td>' . h($approvalLevels[$order]['label']) . '</td>
        <td class="center"><span class="status ' . statusClass($status) . '">' . h(statusLabel($status)) . '</span></td>
        <td>' . h($approvedBy) . '</td>
        <td class="center">' . formatDateTimeId($approvedAt) . '</td>
    </tr>';
}

$html .= '</table>';

// FOOTER
$html .= '
<table style="margin-top:3px;">
    <tr>
        <td class="label" style="width:18%">Generated</td>
        <td>' . date('d/m/Y H:i') . ' WIB</td>
        <td class="label" style="width:18%">DI Number</td>
        <td class="bold">' . h($di_number) . '</td>
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

$filename = 'DI_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $di_number) . '_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;