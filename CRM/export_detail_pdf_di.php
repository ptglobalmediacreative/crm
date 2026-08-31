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

function getApprovalLabel($approvalLevels, $order) {
    return $approvalLevels[(int)$order]['label'] ?? ('Level ' . (int)$order);
}

function isCheckedValue($value) {
    $v = strtolower(trim((string)$value));
    return in_array($v, ['yes', 'ya', 'true', '1', 'checked', 'x'], true);
}

// ============================================
// AMBIL DATA DELIVERY INSTRUCTION
// Sama dengan detaildi.php terbaru
// ============================================
$sql = "SELECT ad.di_number,
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
// APPROVAL LEVELS - sama dengan detaildi.php
// ============================================
$approvalLevels = [
    1 => ['role' => 'admin', 'label' => 'Admin Sales'],
    2 => ['role' => 'business', 'label' => 'Business'],
    3 => ['role' => 'service_support', 'label' => 'Service Support'],
    4 => ['role' => 'part_support', 'label' => 'Part Support'],
    5 => ['role' => 'direktur_sales', 'label' => 'Direktur Sales'],
    6 => ['role' => 'direktur_utama', 'label' => 'Direktur Utama'],
];

// ============================================
// CURRENT / NEXT APPROVER - sama dengan detaildi.php
// ============================================
$currentApprovalOrder = 1;
$currentApproverLabel = '';
$nextApproverLabel = '';

if ($detailDI) {
    $lastApprovedOrder = 0;
    foreach ($approvalHistory as $approval) {
        if (($approval['status'] ?? '') === 'approved') {
            $lastApprovedOrder = max($lastApprovedOrder, (int)$approval['approval_order']);
        }
    }

    $isRejected = false;
    foreach ($approvalHistory as $approval) {
        if (($approval['status'] ?? '') === 'rejected') {
            $isRejected = true;
            break;
        }
    }

    if ($isRejected || ($detailDI['status'] ?? '') === 'rejected') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } elseif (($detailDI['status'] ?? '') === 'approved') {
        $currentApprovalOrder = 0;
        $currentApproverLabel = 'No More Approval';
        $nextApproverLabel = 'No More Approval';
    } else {
        $currentApprovalOrder = $lastApprovedOrder + 1;
        if ($currentApprovalOrder <= 6) {
            $currentApproverLabel = $approvalLevels[$currentApprovalOrder]['label'];
            $nextOrder = $currentApprovalOrder + 1;
            $nextApproverLabel = $nextOrder <= 6
                ? $approvalLevels[$nextOrder]['label']
                : 'No More Approval';
        } else {
            $currentApproverLabel = 'No More Approval';
            $nextApproverLabel = 'No More Approval';
        }
    }
} else {
    $currentApproverLabel = $approvalLevels[1]['label'];
    $nextApproverLabel = $approvalLevels[2]['label'];
}

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
    @page { margin: 8mm 10mm 8mm 10mm; }
    * { box-sizing: border-box; }
    body {
        font-family: Helvetica, Arial, sans-serif;
        font-size: 8px;
        line-height: 1.22;
        color: #111;
        margin: 0;
        padding: 0;
    }

    .logo-wrap {
        text-align: left;
        height: 24mm;
        padding-left: 3mm;
        padding-top: 1mm;
    }

    .logo-img {
        max-width: 72mm;
        max-height: 26mm;
    }

    .title,
    .section-title,
    .sub-title {
        border: 1px solid #222;
        text-align: center;
        font-weight: 700;
    }

    .title {
        font-size: 12px;
        padding: 4px 5px;
        background: #fff;
        margin-bottom: 0;
        letter-spacing: .2px;
    }

    .section-title {
        background: #fff;
        font-size: 9px;
        padding: 3px 4px;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    th, td {
        border: 1px solid #222;
        padding: 2.7px 3px;
        vertical-align: top;
        word-wrap: break-word;
    }

    th {
        text-align: center;
        font-weight: 700;
        background: #f6f6f6;
    }

    .label { font-weight: 700; }
    .center { text-align: center; }
    .right { text-align: right; }
    .bold { font-weight: 700; }
    .small { font-size: 7px; }
    .tiny { font-size: 6.5px; }
    .green { background: #e6f0dc; }
    .yellow { background: #fff200; }
    .gray { background: #efefef; }
    .nowrap { white-space: nowrap; }

    .meta-table td { height: 17px; }
    .meta-label { width: 18%; font-weight: 700; }
    .meta-value { width: 32%; }

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

    .two-col .left {
        width: 50%;
        padding-right: 2px;
    }

    .two-col .right-col {
        width: 50%;
        padding-left: 2px;
    }

    .unit-table td { text-align: center; height: 21px; }
    .unit-table .unit-body td { height: 62px; vertical-align: middle; }

    .accessory-table td { height: 18px; vertical-align: middle; }
    .accessory-empty { height: 70px !important; }

    .logistics-table td { height: 18px; }
    .support-table td { height: 18px; vertical-align: middle; }

    .support-label { width: 31%; font-weight: 700; }
    .support-value { width: 69%; }

    .approval-table th { background: #fff200; }
    .approval-table td { height: 17px; vertical-align: middle; }

    .status {
        display: inline-block;
        border: 1px solid #444;
        padding: 1px 5px;
        font-weight: 700;
        font-size: 7px;
    }
    .status-pending { background: #fff1bf; }
    .status-approved { background: #d9efd9; }
    .status-rejected { background: #f5d2d2; }

    .checkline {
        display: inline-block;
        min-width: 26mm;
        margin-right: 4mm;
    }

    .spacer-2 { height: 2mm; }
    .keep { page-break-inside: avoid; }
</style>
</head>
<body>

<div class="logo-wrap">' . $logoHtml . '</div>

<div class="title">DELIVERY INSTRUCTION</div>

<div class="section-title">DATA PENJUALAN</div>
<table class="meta-table">
    <tr>
        <td class="meta-label">No. DI</td>
        <td class="meta-value">' . h($di_number) . '</td>
        <td class="meta-label">Sales</td>
        <td class="meta-value">' . h($request['sales_name'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="meta-label">Tanggal</td>
        <td class="meta-value">' . formatDateLongId($request['request_date'] ?? null) . '</td>
        <td class="meta-label">Kode Sales</td>
        <td class="meta-value">-</td>
    </tr>
    <tr>
        <td class="meta-label">No. SO</td>
        <td class="meta-value">' . h($request['no_so'] ?? '-') . '</td>
        <td class="meta-label">Status</td>
        <td class="meta-value"><span class="status status-' . strtolower(h($request['status'] ?? 'pending')) . '">' . h(statusLabel($request['status'] ?? 'pending')) . '</span></td>
    </tr>
</table>

<div class="section-title">DATA CUSTOMER</div>
<table class="meta-table">
    <tr>
        <td class="meta-label">Customer</td>
        <td colspan="3">' . h($request['nama_pt'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="meta-label">Alamat</td>
        <td colspan="3">' . nl2br(h($request['alamat'] ?? '-')) . '</td>
    </tr>
    <tr>
        <td class="meta-label">PIC</td>
        <td style="width:32%">' . h($request['nama_pic'] ?? '-') . '</td>
        <td class="meta-label">No. Contact</td>
        <td style="width:32%">' . h($request['no_hp_pic'] ?? '-') . '</td>
    </tr>
</table>

<div class="section-title">DATA UNIT</div>
<table class="meta-table">
    <tr>
        <td class="meta-label">Lokasi Unit</td>
        <td>' . h($diUnits[0]['lokasi_unit'] ?? '-') . '</td>
        <td class="meta-label">Cabang</td>
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
    $html .= '<tr class="unit-body"><td colspan="6">Belum ada data unit</td></tr>';
}

$html .= '</table>

<table class="meta-table">
    <tr>
        <td class="meta-label">Aksesoris</td>
        <td colspan="3">' . (count($diAccessories) > 0 ? 'Ada data aksesoris di tabel berikut' : '-') . '</td>
    </tr>
</table>
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
    $html .= '<tr><td colspan="5" class="accessory-empty">Belum ada data aksesoris</td></tr>';
}

$html .= '</table>

<div class="section-title">LOGISTIK</div>
<table class="logistics-table">
    <tr>
        <td class="meta-label">Lokasi Pengambilan</td>
        <td colspan="3">' . h($diLogistics['lokasi_pengambilan'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="meta-label">Lokasi Pengiriman</td>
        <td colspan="3">' . h($diLogistics['lokasi_pengiriman'] ?? '-') . '</td>
    </tr>
    <tr>
        <td class="meta-label">Transportir</td>
        <td style="width:32%">' . h($diLogistics['transportir'] ?? '-') . '</td>
        <td class="meta-label">Waktu Pengiriman</td>
        <td style="width:32%">' . formatDateLongId($diLogistics['waktu_pengiriman'] ?? null) . '</td>
    </tr>
    <tr>
        <td class="meta-label">ETA</td>
        <td colspan="3">' . formatDateLongId($diLogistics['eta'] ?? null) . '</td>
    </tr>
</table>

<div class="section-title">PRODUCT SUPPORT</div>
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

<table class="meta-table">
    <tr>
        <td class="meta-label">Current Approver</td>
        <td style="width:32%">' . h($currentApproverLabel) . '</td>
        <td class="meta-label">Next Approver</td>
        <td style="width:32%">' . h($nextApproverLabel) . '</td>
    </tr>
</table>

<div class="section-title">APPROVAL HISTORY</div>';

if (count($approvalHistory) > 0) {
    $html .= '<table class="approval-table">
        <tr>
            <th style="width:9%">Level</th>
            <th style="width:28%">Approval</th>
            <th style="width:15%">Status</th>
            <th style="width:28%">Approved By</th>
            <th style="width:20%">Approved At</th>
        </tr>';

    foreach ($approvalHistory as $approval) {
        $order = (int)($approval['approval_order'] ?? 0);
        $status = strtolower((string)($approval['status'] ?? 'pending'));
        $statusClass = in_array($status, ['pending', 'approved', 'rejected'], true)
            ? 'status-' . $status
            : 'status-pending';

        $approvedBy = '-';
        if (!empty($approval['approved_by'])) {
            try {
                $stmtUser = $db->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmtUser->execute([$approval['approved_by']]);
                $userName = $stmtUser->fetchColumn();
                $approvedBy = $userName ?: ($approval['approval_label'] ?? '-');
            } catch (Exception $e) {
                $approvedBy = $approval['approval_label'] ?? '-';
            }
        }

        $html .= '<tr>
            <td class="center">' . $order . '</td>
            <td>' . h($approval['approval_label'] ?? getApprovalLabel($approvalLevels, $order)) . '</td>
            <td class="center"><span class="status ' . $statusClass . '">' . h(statusLabel($status)) . '</span></td>
            <td>' . h($approvedBy) . '</td>
            <td class="center">' . formatDateTimeId($approval['approved_at'] ?? null) . '</td>
        </tr>';
    }

    $html .= '</table>';
} else {
    $html .= '<table><tr><td class="center">Belum ada approval history</td></tr></table>';
}

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