<?php
declare(strict_types=1);

/**
 * Export Detail Quotation ke Microsoft Word (.doc).
 * Parameter URL: export_detail_word_qt.php?quotation_number=...
 */

ob_start();
error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';


if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(401);
    exit('Silakan login terlebih dahulu.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = $db ?? (function_exists('getPDO') ? getPDO() : null);
if (!$db instanceof PDO) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$quotationNumber = trim((string)($_GET['quotation_number'] ?? ''));

if ($quotationNumber === '' || strlen($quotationNumber) > 100) {
    if (ob_get_length()) ob_end_clean();
    http_response_code(400);
    exit('Quotation Number tidak valid.');
}

function qtH($value, string $default = '-'): string {
    $value = ($value === null || $value === '') ? $default : (string)$value;
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function qtMoney($value): string {
    return number_format((float)$value, 0, ',', '.');
}
function qtHasColumn(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}
function qtFormatQty($value): string {
    $n = (float)$value;
    if (abs($n - round($n)) < 0.000001) return number_format($n, 0, ',', '.');
    return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}

/* Deteksi kolom nomor aktivitas yang tersedia di instalasi CRM. */
$activityNumberColumn = null;
foreach (['activity_number', 'leads_number', 'lead_number', 'sales_activity_number', 'activity_no'] as $candidate) {
    if (qtHasColumn($db, 'sales_activities', $candidate)) {
        $activityNumberColumn = $candidate;
        break;
    }
}
$activityNumberSelect = $activityNumberColumn !== null
    ? 'sa.`' . $activityNumberColumn . '` AS activity_number'
    : "CAST(sa.id AS CHAR) AS activity_number";

try {
    $sql = "
        SELECT ad.id, ad.quotation_number, ad.request_quotation, ad.sales_activity_id,
               $activityNumberSelect,
               sa.sales_id, a.id AS account_id, a.nama_pt, a.badan_usaha,
               a.nama_pic AS pic_name, u.full_name AS sales_name
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON sa.id = ad.sales_activity_id
        LEFT JOIN accounts a ON a.id = sa.account_id
        LEFT JOIN users u ON u.id = sa.sales_id
        WHERE ad.quotation_number = ?
        ORDER BY ad.id DESC
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$quotationNumber]);
    $quotation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quotation) {
        if (ob_get_length()) ob_end_clean();
        http_response_code(404);
        exit('Quotation Number tidak ditemukan.');
    }

    // Selaras dengan pembatasan halaman Detail Quotation untuk akun Sales.
    if ($userRole === 'sales' && (int)($quotation['sales_id'] ?? 0) !== $userId) {
        if (ob_get_length()) ob_end_clean();
        http_response_code(403);
        exit('Anda tidak memiliki akses ke quotation ini.');
    }

    $itemsStmt = $db->prepare("
        SELECT line_no, model_unit, description, qty, price
        FROM quotation_items
        WHERE quotation_number = ?
        ORDER BY line_no ASC, id ASC
    ");
    $itemsStmt->execute([$quotationNumber]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $notesStmt = $db->prepare("
        SELECT note_text
        FROM quotation_notes
        WHERE quotation_number = ?
        ORDER BY line_no ASC, id ASC
    ");
    $notesStmt->execute([$quotationNumber]);
    $notes = $notesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('[CRM quotation PDF] ' . $e->getMessage());
    if (ob_get_length()) ob_end_clean();
    http_response_code(500);
    exit('Data quotation gagal dibaca. Pastikan tabel quotation_items dan quotation_notes tersedia.');
}

$accountName = trim((string)($quotation['nama_pt'] ?? ''));
if ($accountName === '') $accountName = 'Account tidak tersedia';
$businessType = trim((string)($quotation['badan_usaha'] ?? ''));
if ($businessType !== '') $accountName .= ' ' . $businessType;
$picName = trim((string)($quotation['pic_name'] ?? '')) ?: '-';
$salesName = trim((string)($quotation['sales_name'] ?? '')) ?: 'Sales';
$quotationDate = date('d/m/Y');

$subtotal = 0.0;
foreach ($items as $item) {
    $qty = (float)($item['qty'] ?? 0);
    $price = (float)($item['price'] ?? 0);
    $subtotal += $qty * $price;
}
$vat = $subtotal * 0.11;
$grandTotal = $subtotal + $vat;

$stampPath = __DIR__ . '/images/stempel.png';
$stampHtml = '';
if (is_file($stampPath) && is_readable($stampPath)) {
    $stampData = base64_encode((string)file_get_contents($stampPath));
    $stampHtml = '<img class="stamp" src="data:image/png;base64,' . $stampData . '" alt="Stempel">';
}

$html = '<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
    @page { size: A4; margin: 1.2cm 1.3cm 1.4cm 1.3cm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
    .header { text-align: center; font-size: 16px; font-weight: bold; text-decoration: underline; margin: 0 0 16px; }
    .meta { width: 100%; margin-bottom: 15px; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta .right { text-align: right; }
    .recipient { margin: 12px 0 15px; line-height: 1.7; }
    .recipient .label { display: inline-block; width: 75px; font-weight: bold; }
    .intro { margin: 12px 0 12px; line-height: 1.5; }
    table.items { border-collapse: collapse; width: 100%; table-layout: fixed; mso-table-lspace:0pt; mso-table-rspace:0pt; }
    table.items th, table.items td { border: 1px solid #222; padding: 5px 4px; vertical-align: top; }
    table.items th { text-align: center; font-weight: bold; background: #f4f4f4; }
    .c-no { width: 5%; text-align: center; }
    .c-model { width: 19%; }
    .c-desc { width: 34%; }
    .c-qty { width: 7%; text-align: center; }
    .c-price { width: 16%; text-align: right; }
    .c-total { width: 19%; text-align: right; }
    .money { text-align: right; white-space: nowrap; }
    .totals td { font-weight: bold; }
    .totals .label { text-align: right; }
    .grand td { background: #fff6d9; }
    .notes-title { font-weight: bold; margin-top: 17px; margin-bottom: 5px; }
    .notes { margin: 0; padding-left: 18px; line-height: 1.5; }
    .notes li { padding-left: 2px; margin-bottom: 3px; }
    .signoff { margin-top: 25px; text-align: right; page-break-inside: avoid; }
    .signoff .regards { margin-bottom: 3px; }
    .stamp { display: block; width: 105px; height: auto; margin: 0 18px 2px auto; }
    .sales-name { font-weight: bold; text-align: right; margin-right: 26px; }
</style>
</head>
<body>
<div class="header">QUOTATION</div>
<table class="meta">
    <tr><td></td><td class="right"><strong>Date:</strong> ' . qtH($quotationDate) . '</td></tr>
    <tr><td></td><td class="right"><strong>Quotation No:</strong> ' . qtH($quotationNumber) . '</td></tr>
</table>

<div class="recipient">
    <div><span class="label">To</span>: ' . qtH('PT. ' . $accountName) . '</div>
    <div><span class="label">Attention</span>: ' . qtH($picName) . '</div>
</div>

<div class="intro">We would like to offer the equipment(s), as specified below, with the following condition:</div>

<table class="items">
    <thead>
        <tr>
            <th class="c-no">No</th>
            <th class="c-model">Model Unit</th>
            <th class="c-desc">Description</th>
            <th class="c-qty">Qty</th>
            <th class="c-price">Price</th>
            <th class="c-total">Total Price</th>
        </tr>
    </thead>
    <tbody>';

if ($items) {
    $i = 1;
    foreach ($items as $item) {
        $qty = (float)($item['qty'] ?? 0);
        $price = (float)($item['price'] ?? 0);
        $lineTotal = $qty * $price;
        $html .= '<tr>
            <td class="c-no">' . $i . '</td>
            <td>' . nl2br(qtH($item['model_unit'] ?? '')) . '</td>
            <td>' . nl2br(qtH($item['description'] ?? '')) . '</td>
            <td class="c-qty">' . qtH(qtFormatQty($qty)) . '</td>
            <td class="money">' . qtMoney($price) . '</td>
            <td class="money">' . qtMoney($lineTotal) . '</td>
        </tr>';
        $i++;
    }
} else {
    $html .= '<tr><td colspan="6" style="text-align:center">Belum ada item pada Detail Quotation.</td></tr>';
}

$html .= '
        <tr class="totals">
            <td colspan="4" style="border-right:0"></td>
            <td class="label" colspan="1" style="border-left:0">Sub Total</td>
            <td class="money">' . qtMoney($subtotal) . '</td>
        </tr>
        <tr class="totals">
            <td colspan="4" style="border-right:0"></td>
            <td class="label" style="border-left:0">VAT = PPN 11%</td>
            <td class="money">' . qtMoney($vat) . '</td>
        </tr>
        <tr class="totals grand">
            <td colspan="4" style="border-right:0"></td>
            <td class="label" style="border-left:0">Grand Total</td>
            <td class="money">' . qtMoney($grandTotal) . '</td>
        </tr>
    </tbody>
</table>

<div class="notes-title">Notes:</div>';

$nonEmptyNotes = [];
foreach ($notes as $note) {
    $note = trim((string)$note);
    if ($note !== '') $nonEmptyNotes[] = $note;
}
if ($nonEmptyNotes) {
    $html .= '<ol class="notes">';
    foreach ($nonEmptyNotes as $note) {
        $html .= '<li>' . nl2br(qtH($note)) . '</li>';
    }
    $html .= '</ol>';
} else {
    $html .= '<div>-</div>';
}

$html .= '
<div class="signoff">
    <div class="regards">Regards,</div>
    ' . $stampHtml . '
    <div class="sales-name">' . qtH($salesName) . '</div>
</div>
</body>
</html>';

// Word dapat membuka HTML berformat tabel yang dikirim dengan ekstensi .doc.
// Ini tidak membutuhkan library tambahan seperti PHPWord.
if (ob_get_length()) ob_end_clean();

$filename = 'QUOTATION_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $quotationNumber) . '_' . date('Ymd_His') . '.doc';
header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
echo "\\xEF\\xBB\\xBF" . $html;
exit;
