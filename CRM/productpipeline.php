<?php
/**
 * productpipeline.php
 *
 * Product Pipeline:
 * Prospect -> Hot Prospect -> Deal
 *
 * Sumber:
 * 1. activity_details + activity_detail_units
 *    - Prospecting = sumber Prospect
 *    - sales_activity_id = Activity Number
 * 2. activity_details + detail_transaction_requests + tr_detail_units
 *    - TR Number = tahap lanjut
 *    - customer_deal != yes = Hot Prospect
 *    - customer_deal = yes = Deal
 *
 * Aturan anti-double:
 * Jika sebuah Activity Number sudah memiliki TR dan Detail Unit TR,
 * Qty Prospecting untuk unit yang sama tidak lagi ditampilkan sebagai Prospect.
 * Qty tersebut dianggap berpindah ke Hot Prospect / Deal sesuai Customer Deal.
 *
 * Tidak membutuhkan tabel DB baru karena seluruh data dihitung live
 * dari tabel existing agar selalu mengikuti perubahan Detail Aktivitas / Detail TR.
 */

require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

requirePermission('sales_activity', 'view');

$userRole = $_SESSION['role'] ?? 'user';
$fullName  = $_SESSION['full_name'] ?? 'User';

/**
 * Escape output.
 */
function pp_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Ambil customer_deal TERBARU untuk sebuah TR.
 */
function pp_get_latest_deal(PDO $db, string $trNumber): ?array {
    $stmt = $db->prepare("
        SELECT id, customer_deal, customer_deal_keterangan, status
        FROM detail_transaction_requests
        WHERE trf_number = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$trNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/**
 * ============================================================
 * 1. PROSPECT DARI DETAIL AKTIVITAS
 * ============================================================
 *
 * Satu baris activity_detail_units menunjuk:
 * activity_detail_id -> activity_details.id -> sales_activity_id
 *
 * Kita hanya mengambil Prospecting.
 */
$prospectRows = [];

try {
    $stmt = $db->query("
        SELECT
            ad.id AS activity_detail_id,
            ad.sales_activity_id,
            ad.tr_number,
            ad.jenis_tugas,
            ad.created_at AS activity_created_at,
            ad.subject,
            adu.product_id,
            adu.quantity,
            p.nama_produk
        FROM activity_details ad
        INNER JOIN activity_detail_units adu
            ON adu.activity_detail_id = ad.id
        INNER JOIN products p
            ON p.id = adu.product_id
        WHERE ad.jenis_tugas = 'Prospecting'
          AND adu.quantity > 0
        ORDER BY ad.sales_activity_id ASC, ad.id ASC, p.nama_produk ASC
    ");

    $prospectRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prospectRows = [];
}

/**
 * ============================================================
 * 2. MAP TR PER ACTIVITY NUMBER
 * ============================================================
 *
 * TR bisa muncul pada lebih dari satu activity_detail dalam
 * Activity Number yang sama. Kita deduplicate berdasarkan:
 * sales_activity_id + tr_number
 */
$activityTRMap = [];

try {
    $stmt = $db->query("
        SELECT DISTINCT
            ad.sales_activity_id,
            TRIM(ad.tr_number) AS tr_number,
            ad.id AS activity_detail_id,
            ad.created_at
        FROM activity_details ad
        WHERE ad.tr_number IS NOT NULL
          AND TRIM(ad.tr_number) <> ''
        ORDER BY ad.sales_activity_id ASC, ad.id DESC
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $activityId = (int)$row['sales_activity_id'];
        $trNumber   = trim((string)$row['tr_number']);

        if ($activityId <= 0 || $trNumber === '') {
            continue;
        }

        if (!isset($activityTRMap[$activityId])) {
            $activityTRMap[$activityId] = [];
        }

        /*
         * Satu TR hanya dihitung satu kali untuk satu Activity Number.
         */
        if (!isset($activityTRMap[$activityId][$trNumber])) {
            $activityTRMap[$activityId][$trNumber] = [
                'tr_number' => $trNumber,
                'activity_detail_id' => (int)$row['activity_detail_id'],
                'created_at' => $row['created_at']
            ];
        }
    }
} catch (PDOException $e) {
    $activityTRMap = [];
}

/**
 * ============================================================
 * 3. AMBIL DETAIL UNIT TR
 * ============================================================
 *
 * Kita mengambil unit dari seluruh TR yang memang terhubung
 * ke Activity Number.
 *
 * Deduplicate:
 * TR Number + product/unit.
 *
 * Karena detailtr menggunakan:
 * tr_detail_units.trf_number
 * tr_detail_units.unit_id
 * tr_detail_units.qty
 */
$trUnitRows = [];

try {
    $stmt = $db->query("
        SELECT
            tdu.id,
            TRIM(tdu.trf_number) AS tr_number,
            tdu.unit_id AS product_id,
            tdu.qty,
            p.nama_produk
        FROM tr_detail_units tdu
        INNER JOIN products p
            ON p.id = tdu.unit_id
        WHERE tdu.trf_number IS NOT NULL
          AND TRIM(tdu.trf_number) <> ''
          AND tdu.qty > 0
        ORDER BY tdu.trf_number ASC, tdu.id ASC
    ");

    $trUnitRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trUnitRows = [];
}

/**
 * ============================================================
 * 4. CUSTOMER DEAL UNTUK SETIAP TR
 * ============================================================
 */
$dealMap = [];

foreach ($trUnitRows as $row) {
    $tr = trim((string)$row['tr_number']);

    if ($tr === '' || isset($dealMap[$tr])) {
        continue;
    }

    $dealMap[$tr] = pp_get_latest_deal($db, $tr);
}

/**
 * ============================================================
 * 5. UNIT TR DIAGREGASI
 * ============================================================
 *
 * Key:
 * tr_number + product_id
 *
 * Jika ada beberapa detail unit dengan produk yang sama,
 * Qty dijumlahkan.
 */
$trUnitsByTr = [];

foreach ($trUnitRows as $row) {
    $tr = trim((string)$row['tr_number']);
    $productId = (int)$row['product_id'];
    $qty = (int)$row['qty'];

    if ($tr === '' || $productId <= 0 || $qty <= 0) {
        continue;
    }

    if (!isset($trUnitsByTr[$tr])) {
        $trUnitsByTr[$tr] = [];
    }

    if (!isset($trUnitsByTr[$tr][$productId])) {
        $trUnitsByTr[$tr][$productId] = [
            'product_id' => $productId,
            'nama_produk' => $row['nama_produk'],
            'qty' => 0
        ];
    }

    $trUnitsByTr[$tr][$productId]['qty'] += $qty;
}

/**
 * ============================================================
 * 6. CARI TR YANG RELEVAN UNTUK SETIAP ACTIVITY NUMBER
 * ============================================================
 */
$activityStageTR = [];

foreach ($activityTRMap as $activityId => $trList) {
    foreach ($trList as $trNumber => $trInfo) {
        if (empty($trUnitsByTr[$trNumber])) {
            /*
             * TR ada tetapi belum mempunyai Detail Unit.
             * Belum bisa dipindahkan berdasarkan tipe unit.
             */
            continue;
        }

        $deal = $dealMap[$trNumber] ?? null;
        $customerDeal = strtolower(trim((string)($deal['customer_deal'] ?? '')));

        /*
         * Yes = Deal.
         * No / kosong / belum dipilih = Hot Prospect.
         *
         * Ini sengaja mengikuti status Customer Deal di Detail TR,
         * bukan status approval.
         */
        $stage = ($customerDeal === 'yes') ? 'deal' : 'hot_prospect';

        if (!isset($activityStageTR[$activityId])) {
            $activityStageTR[$activityId] = [];
        }

        $activityStageTR[$activityId][$trNumber] = [
            'tr_number' => $trNumber,
            'stage' => $stage,
            'customer_deal' => $customerDeal,
            'units' => $trUnitsByTr[$trNumber]
        ];
    }
}

/**
 * ============================================================
 * 7. HITUNG PIPELINE
 * ============================================================
 *
 * Aturan inti:
 *
 * 1. Prospecting pada Activity Number menjadi Prospect.
 *
 * 2. Jika Activity Number tersebut mempunyai TR yang memiliki
 *    Detail Unit untuk produk yang sama:
 *       Prospect -> Hot Prospect / Deal
 *
 * 3. Qty yang digunakan setelah pindah tahap adalah Qty pada
 *    Detail TR (tr_detail_units.qty), bukan dijumlahkan lagi
 *    dengan Qty Prospecting.
 *
 * 4. Jika ada lebih dari satu TR untuk Activity Number yang sama,
 *    satu produk hanya memakai TR TERBARU yang mempunyai Detail
 *    Unit. Ini mencegah double count.
 *
 * 5. Jika TR mempunyai produk yang tidak ada pada Prospecting,
 *    produk tersebut tetap masuk Hot Prospect / Deal.
 */

/**
 * Pipeline hasil akhir.
 */
$pipeline = [];

/**
 * Activity/Product yang sudah mempunyai sumber TR.
 * Format:
 *   activity_id:product_id => [
 *       tr_number,
 *       stage
 *   ]
 */
$activityProductStage = [];

/**
 * Pilih TR terbaru untuk setiap Activity Number + Product.
 *
 * activityTRMap dibangun dari ORDER BY ad.id DESC,
 * sehingga elemen pertama yang ditemukan adalah relasi activity
 * terbaru. Kita tetap melakukan pemilihan eksplisit agar aman.
 */
foreach ($activityStageTR as $activityId => $trList) {

    foreach ($trList as $trInfo) {
        $trNumber = (string)$trInfo['tr_number'];
        $stage = (string)$trInfo['stage'];

        foreach ($trInfo['units'] as $productId => $unit) {
            $productId = (int)$productId;

            if ($productId <= 0 || (int)$unit['qty'] <= 0) {
                continue;
            }

            $key = (int)$activityId . ':' . $productId;

            /*
             * Hanya TR pertama/terbaru untuk Activity + Product
             * yang dipakai. TR berikutnya tidak dihitung lagi.
             */
            if (!isset($activityProductStage[$key])) {
                $activityProductStage[$key] = [
                    'tr_number' => $trNumber,
                    'stage' => $stage,
                    'product_id' => $productId,
                    'nama_produk' => (string)$unit['nama_produk'],
                    'qty' => (int)$unit['qty']
                ];
            }
        }
    }
}

/**
 * Helper untuk membuat baris produk.
 */
$ensureProduct = static function (int $productId, string $name) use (&$pipeline): void {
    if (!isset($pipeline[$productId])) {
        $pipeline[$productId] = [
            'product_id' => $productId,
            'nama_produk' => $name,
            'prospect' => 0,
            'hot_prospect' => 0,
            'deal' => 0
        ];
    }
};

/**
 * ============================================================
 * 7A. HITUNG PROSPECT
 * ============================================================
 *
 * Prospect hanya berasal dari Prospecting yang BELUM pindah
 * ke TR untuk Activity Number + Product tersebut.
 */
foreach ($prospectRows as $row) {
    $activityId = (int)$row['sales_activity_id'];
    $productId = (int)$row['product_id'];
    $qty = (int)$row['quantity'];

    if ($activityId <= 0 || $productId <= 0 || $qty <= 0) {
        continue;
    }

    $ensureProduct($productId, (string)$row['nama_produk']);

    $key = $activityId . ':' . $productId;

    /*
     * Jika sudah ada TR untuk product yang sama pada Activity ini,
     * Qty Prospecting dipindahkan ke tahap TR dan tidak dihitung
     * sebagai Prospect lagi.
     */
    if (isset($activityProductStage[$key])) {
        continue;
    }

    $pipeline[$productId]['prospect'] += $qty;
}

/**
 * ============================================================
 * 7B. HITUNG HOT PROSPECT / DEAL DARI DETAIL TR
 * ============================================================
 */
foreach ($activityProductStage as $stageData) {
    $productId = (int)$stageData['product_id'];
    $qty = (int)$stageData['qty'];

    if ($productId <= 0 || $qty <= 0) {
        continue;
    }

    $ensureProduct($productId, (string)$stageData['nama_produk']);

    if ($stageData['stage'] === 'deal') {
        $pipeline[$productId]['deal'] += $qty;
    } else {
        /*
         * Customer Deal:
         * - yes  = Deal
         * - no   = Hot Prospect
         * - null = Hot Prospect
         * - kosong = Hot Prospect
         */
        $pipeline[$productId]['hot_prospect'] += $qty;
    }
}

/**
 * Hapus produk tanpa pipeline quantity.
 */
$pipeline = array_filter($pipeline, static function ($row) {
    return ($row['prospect'] + $row['hot_prospect'] + $row['deal']) > 0;
});

/*
 * Sort berdasarkan nama produk.
 */
usort($pipeline, static function ($a, $b) {
    return strcasecmp($a['nama_produk'], $b['nama_produk']);
});

/**
 * Summary.
 */
$totalProspect = 0;
$totalHotProspect = 0;
$totalDeal = 0;

foreach ($pipeline as $row) {
    $totalProspect += (int)$row['prospect'];
    $totalHotProspect += (int)$row['hot_prospect'];
    $totalDeal += (int)$row['deal'];
}

$totalPipeline = $totalProspect + $totalHotProspect + $totalDeal;

/**
 * Filter pencarian.
 */
$search = trim((string)($_GET['search'] ?? ''));

if ($search !== '') {
    $pipeline = array_values(array_filter($pipeline, static function ($row) use ($search) {
        return stripos($row['nama_produk'], $search) !== false;
    }));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Pipeline</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/footer.css">
    <style id="productpipeline-style">
/* =========================================================
   PRODUCT PIPELINE — SAME VISUAL SYSTEM AS "PRODUK"
   Layout intentionally mirrors the supplied Produk screenshot.
   ========================================================= */

html,
body {
    background: #060b18 !important;
    color: #dce5f5 !important;
}

/* Main area — navigation-safe shell.
   navigation.php owns the fixed header/sidebar; this page only owns content. */
:root {
    --crm-topbar-height: 72px;
    --crm-rail-width: 245px;
    --crm-rail-width-tablet: 220px;
}

.pp-page.page-productpipeline {
    display: block;
    position: relative;
    float: none;
    clear: both;
    width: calc(100% - var(--crm-rail-width, 245px));
    min-width: 0;
    min-height: calc(100vh - var(--crm-topbar-height, 72px));
    margin: 0 0 0 var(--crm-rail-width, 245px);
    padding: calc(var(--crm-topbar-height, 72px) + 28px) 30px 50px;
    box-sizing: border-box;
    background: #060b18;
    color: #dce5f5;
}

.pp-container {
    width: 100% !important;
    max-width: none !important;
    margin: 0 !important;
}

/* Header exactly in the spirit of Produk */
.pp-page-header {
    min-height: 52px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 35px;
}

.pp-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}

.pp-title-icon {
    width: 55px;
    height: 55px;
    border-radius: 17px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0d1d34;
    border: 1px solid rgba(96,165,250,.18);
    color: #f7f9ff;
    font-size: 22px;
    box-shadow: inset 0 0 0 1px rgba(96,165,250,.03);
}

.pp-title-wrap h4 {
    margin: 0;
    color: #f7f9ff;
    font-size: 28px;
    font-weight: 800;
    letter-spacing: -.7px;
}

/* Summary card */
.pp-summary-card {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    margin-bottom: 20px;
    padding: 5px 0;
    background: linear-gradient(145deg, rgba(12,23,43,.94), rgba(7,14,28,.96));
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 17px;
    box-shadow: 0 18px 45px rgba(0,0,0,.18);
}

.pp-summary-item {
    min-height: 78px;
    padding: 14px 22px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 4px;
    border-right: 1px solid rgba(148,163,184,.08);
}

.pp-summary-item:last-child {
    border-right: 0;
}

.pp-summary-label {
    color: #687791;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
}

.pp-summary-item strong {
    font-size: 16px;
    font-weight: 800;
}

.pp-summary-total { color: #e8eef9; }
.pp-summary-prospect { color: #60a5fa; }
.pp-summary-hot { color: #fbbf24; }
.pp-summary-deal { color: #34d399; }

/* Main table card */
.pp-card {
    width: 100%;
    background: linear-gradient(145deg, rgba(12,23,43,.94), rgba(7,14,28,.96));
    border: 1px solid rgba(148,163,184,.12);
    border-radius: 17px;
    box-shadow: 0 18px 45px rgba(0,0,0,.18);
    overflow: hidden;
    color: #eaf0f8;
}

.pp-card-header {
    min-height: 74px;
    padding: 13px 17px;
    border-bottom: 1px solid rgba(148,163,184,.10);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
}

.pp-card-header h6 {
    margin: 0;
    color: #f7f9ff;
    font-size: 13px;
    font-weight: 700;
}

.pp-card-header h6 i {
    color: #60a5fa;
    margin-right: 8px;
}

.pp-search-form {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
}

.pp-search-form input {
    width: 330px !important;
    height: 38px;
    border-radius: 10px !important;
    background: #0a1427 !important;
    border: 1px solid rgba(148,163,184,.16) !important;
    color: #dbe5f5 !important;
    font-size: 11px !important;
}

.pp-search-form input::placeholder {
    color: #52627d !important;
}

.pp-search-form input:focus {
    border-color: rgba(96,165,250,.55) !important;
    box-shadow: 0 0 0 3px rgba(59,130,246,.10) !important;
    background: #0b172d !important;
    color: #fff !important;
}

.pp-search-btn {
    height: 38px;
    min-width: 48px;
    border: 0 !important;
    border-radius: 11px !important;
    background: linear-gradient(135deg,#3b82f6,#6366f1) !important;
    color: #fff !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.pp-clear-btn {
    height: 38px;
    width: 38px;
    padding: 0 !important;
    border-radius: 10px !important;
    background: #111d31 !important;
    border: 1px solid rgba(148,163,184,.13) !important;
    color: #8f9db4 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* Table */
.pp-card-body {
    padding: 0;
    background: rgba(3,8,18,.12);
    overflow-x: auto;
}

.pp-table {
    width: 100%;
    min-width: 980px;
    margin: 0 !important;
    font-size: 10px;
    color: #cbd5e1;
    --bs-table-bg: transparent;
    --bs-table-color: #cbd5e1;
}

.pp-table th {
    height: 52px;
    padding: 11px 16px;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .55px;
    color: #66758f;
    background: rgba(5,12,25,.48) !important;
    border-bottom: 1px solid rgba(148,163,184,.10);
    white-space: nowrap;
}

.pp-table td {
    height: 62px;
    padding: 12px 16px;
    vertical-align: middle;
    color: #cbd5e1 !important;
    background: transparent !important;
    border-bottom: 1px solid rgba(148,163,184,.07);
}

.pp-table tbody tr {
    transition: background .15s ease;
}

.pp-table tbody tr:hover td {
    background: rgba(59,130,246,.045) !important;
}

.pp-table tbody tr:last-child td {
    border-bottom: 0;
}

.pp-table th:first-child,
.pp-table td:first-child {
    width: 64px;
    text-align: center;
}

.pp-table th:nth-child(2) {
    width: 34%;
}

.pp-table th:nth-child(3),
.pp-table th:nth-child(4),
.pp-table th:nth-child(5),
.pp-table th:nth-child(6) {
    width: 105px;
}

.pp-table th:last-child {
    width: 260px;
}

.pp-no {
    color: #7f8da5 !important;
}

.pp-product-name {
    display: block;
    color: #e8eef9 !important;
    font-weight: 700;
    line-height: 1.45;
    white-space: normal;
}

.pp-qty {
    font-size: 15px;
    font-weight: 800;
}

.pp-prospect { color: #60a5fa !important; }
.pp-hot { color: #fbbf24 !important; }
.pp-deal { color: #34d399 !important; }

.pp-unit {
    color: #52627d;
    font-size: 9px;
}

.pp-total {
    color: #e8eef9 !important;
    font-size: 14px;
}

.pp-stage-bar {
    width: 220px;
    height: 7px;
    display: flex;
    overflow: hidden;
    border-radius: 999px;
    background: #111d31;
}

.pp-stage-bar span {
    height: 100%;
    min-width: 0;
}

.pp-stage-prospect { background: #60a5fa; }
.pp-stage-hot { background: #fbbf24; }
.pp-stage-deal { background: #34d399; }

.pp-stage-legend {
    margin-top: 7px;
    display: flex;
    align-items: center;
    gap: 9px;
    color: #52627d;
    font-size: 8px;
}

.pp-stage-legend span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.pp-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.pp-dot-prospect { background: #60a5fa; }
.pp-dot-hot { background: #fbbf24; }
.pp-dot-deal { background: #34d399; }

.pp-empty {
    min-height: 250px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 8px;
    color: #64748b;
}

.pp-empty i {
    font-size: 34px;
    color: #52627d;
}

.pp-empty strong {
    color: #8f9db4;
    font-size: 12px;
}

.pp-empty span {
    font-size: 10px;
}

/* Footer */
.pp-container > footer,
.pp-container .footer-text {
    margin-top: 20px;
}

/* =========================================================
   PRODUCT PIPELINE — NAVIGATION-SAFE RESPONSIVE LAYOUT
   navigation.css owns .topbar / .rail.
   This file owns only the Product Pipeline content shell.
   ========================================================= */

.pp-page.page-productpipeline,
.pp-page.page-productpipeline * {
    box-sizing: border-box;
}

.pp-page.page-productpipeline {
    visibility: visible;
    opacity: 1;
}

@media (min-width: 769px) and (max-width: 1200px) {
    .pp-page.page-productpipeline {
        width: calc(100% - var(--crm-rail-width-tablet, 220px));
        margin-left: var(--crm-rail-width-tablet, 220px);
        min-height: calc(100vh - var(--crm-topbar-height, 72px));
        padding: calc(var(--crm-topbar-height, 72px) + 28px) 22px 46px;
    }

    .pp-summary-card {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pp-summary-item:nth-child(2) {
        border-right: 0;
    }

    .pp-summary-item:nth-child(-n+2) {
        border-bottom: 1px solid rgba(148,163,184,.08);
    }
}

@media (max-width: 768px) {
    .pp-page.page-productpipeline {
        width: 100%;
        margin-left: 0;
        min-height: calc(100vh - 64px);
        padding: 84px 14px 40px;
    }

    .pp-page-header {
        margin-bottom: 20px;
    }

    .pp-title-wrap {
        min-width: 0;
    }

    .pp-title-wrap h4 {
        font-size: 22px;
    }

    .pp-title-icon {
        width: 46px;
        height: 46px;
        flex: 0 0 46px;
        border-radius: 14px;
        font-size: 18px;
    }

    .pp-summary-card {
        grid-template-columns: 1fr;
    }

    .pp-summary-item,
    .pp-summary-item:nth-child(2) {
        border-right: 0;
        border-bottom: 1px solid rgba(148,163,184,.08);
    }

    .pp-summary-item:last-child {
        border-bottom: 0;
    }

    .pp-card-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .pp-search-form {
        width: 100%;
    }

    .pp-search-form input {
        width: 100% !important;
        flex: 1 1 auto;
    }

    .pp-card-body {
        overflow-x: auto;
    }

    .pp-table {
        min-width: 900px;
    }
}

@media (max-width: 480px) {
    .pp-page.page-productpipeline {
        padding: 80px 10px 32px;
    }

    .pp-page-header {
        margin-bottom: 16px;
    }

    .pp-title-wrap {
        gap: 10px;
    }

    .pp-title-wrap h4 {
        font-size: 20px;
    }

    .pp-title-icon {
        width: 42px;
        height: 42px;
        flex-basis: 42px;
        border-radius: 12px;
        font-size: 16px;
    }

    .pp-summary-item {
        padding: 13px 15px;
    }

    .pp-card-header {
        padding: 12px;
    }

    .pp-search-form {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto auto;
        width: 100%;
    }

    .pp-search-form input {
        min-width: 0;
    }
}

    </style>
</head>
<body class="page-productpipeline">

<?php require_once 'navigation.php'; ?>

<!-- Product Pipeline FINAL layout override.
     Wajib diletakkan SETELAH navigation.php karena navigation.php memuat
     navigation.css/app.css setelah halaman mulai dirender. -->
<style id="productpipeline-final-layout">
html, body {
    margin: 0 !important;
    width: 100% !important;
    min-width: 0 !important;
    overflow-x: hidden !important;
    background: #060b18 !important;
}

/* Desktop / laptop: content area is an independent panel to the RIGHT
   of the fixed CRM sidebar and BELOW the fixed topbar. */
@media (min-width: 769px) {
    .pp-page.page-productpipeline {
        display: block !important;
        position: fixed !important;
        top: 72px !important;
        right: 0 !important;
        bottom: 0 !important;
        left: 245px !important;
        width: auto !important;
        height: auto !important;
        margin: 0 !important;
        padding: 28px 30px 50px !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        box-sizing: border-box !important;
        visibility: visible !important;
        opacity: 1 !important;
        float: none !important;
        clear: none !important;
        z-index: 100 !important;
        background: #060b18 !important;
        color: #dce5f5 !important;
    }

    .pp-page.page-productpipeline .pp-container {
        display: block !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}

/* Tablet / small laptop: navigation.css uses a 220px rail. */
@media (min-width: 769px) and (max-width: 1200px) {
    .pp-page.page-productpipeline {
        left: 220px !important;
        padding: 28px 22px 46px !important;
    }
}

/* Phone: sidebar becomes an overlay, so page uses full width. */
@media (max-width: 768px) {
    .pp-page.page-productpipeline {
        display: block !important;
        position: fixed !important;
        top: 64px !important;
        right: 0 !important;
        bottom: 0 !important;
        left: 0 !important;
        width: auto !important;
        height: auto !important;
        margin: 0 !important;
        padding: 20px 14px 40px !important;
        overflow-x: hidden !important;
        overflow-y: auto !important;
        box-sizing: border-box !important;
        visibility: visible !important;
        opacity: 1 !important;
        z-index: 100 !important;
        background: #060b18 !important;
    }

    .pp-page.page-productpipeline .pp-container {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }
}

@media (max-width: 360px) {
    .pp-page.page-productpipeline {
        top: 60px !important;
        padding: 16px 10px 32px !important;
    }
}
</style>

<main class="pp-page page-productpipeline">
    <div class="pp-container">

        <!-- HEADER: dibuat mengikuti halaman Produk -->
        <div class="pp-page-header">
            <div class="pp-title-wrap">
                <span class="pp-title-icon">
                    <i class="fas fa-chart-column"></i>
                </span>
                <h4>Product Pipeline</h4>
            </div>
        </div>

        <!-- SUMMARY: tetap mempertahankan informasi pipeline, tetapi tampil
             sebagai satu card gelap seperti card "Daftar Produk". -->
        <div class="pp-summary-card">
            <div class="pp-summary-item">
                <span class="pp-summary-label">Total Pipeline</span>
                <strong class="pp-summary-total"><?= number_format($totalPipeline, 0, ',', '.') ?> Unit</strong>
            </div>
            <div class="pp-summary-item">
                <span class="pp-summary-label">Prospect</span>
                <strong class="pp-summary-prospect"><?= number_format($totalProspect, 0, ',', '.') ?> Unit</strong>
            </div>
            <div class="pp-summary-item">
                <span class="pp-summary-label">Hot Prospect</span>
                <strong class="pp-summary-hot"><?= number_format($totalHotProspect, 0, ',', '.') ?> Unit</strong>
            </div>
            <div class="pp-summary-item">
                <span class="pp-summary-label">Deal</span>
                <strong class="pp-summary-deal"><?= number_format($totalDeal, 0, ',', '.') ?> Unit</strong>
            </div>
        </div>

        <!-- TABLE -->
        <div class="pp-card">
            <div class="pp-card-header">
                <h6><i class="fas fa-list"></i> Pipeline Tipe Unit</h6>

                <form method="get" class="pp-search-form">
                    <input
                        type="text"
                        name="search"
                        class="form-control form-control-sm"
                        placeholder="Cari tipe unit..."
                        value="<?= pp_h($search) ?>"
                    >
                    <button type="submit" class="btn pp-search-btn">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if (!empty($search)): ?>
                        <a href="productpipeline.php" class="btn pp-clear-btn" title="Reset pencarian">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="pp-card-body">
                <div class="table-responsive">
                    <?php if (empty($pipeline)): ?>
                        <div class="pp-empty">
                            <i class="fas fa-inbox"></i>
                            <strong>Belum ada data Product Pipeline</strong>
                            <span>Data akan muncul dari aktivitas Prospecting dan Detail Unit pada TR.</span>
                        </div>
                    <?php else: ?>
                        <table class="table pp-table">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Tipe Unit</th>
                                    <th>Prospect</th>
                                    <th>Hot Prospect</th>
                                    <th>Deal</th>
                                    <th>Total</th>
                                    <th>Pipeline</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($pipeline as $index => $row): ?>
                                <?php
                                $prospect = (int)$row['prospect'];
                                $hot = (int)$row['hot_prospect'];
                                $deal = (int)$row['deal'];
                                $total = $prospect + $hot + $deal;

                                $pPct = $total > 0 ? ($prospect / $total) * 100 : 0;
                                $hPct = $total > 0 ? ($hot / $total) * 100 : 0;
                                $dPct = $total > 0 ? ($deal / $total) * 100 : 0;
                                ?>
                                <tr>
                                    <td class="pp-no"><?= $index + 1 ?></td>
                                    <td>
                                        <strong class="pp-product-name">
                                            <?= pp_h($row['nama_produk']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="pp-qty pp-prospect">
                                            <?= number_format($prospect, 0, ',', '.') ?>
                                        </span>
                                        <span class="pp-unit">unit</span>
                                    </td>
                                    <td>
                                        <span class="pp-qty pp-hot">
                                            <?= number_format($hot, 0, ',', '.') ?>
                                        </span>
                                        <span class="pp-unit">unit</span>
                                    </td>
                                    <td>
                                        <span class="pp-qty pp-deal">
                                            <?= number_format($deal, 0, ',', '.') ?>
                                        </span>
                                        <span class="pp-unit">unit</span>
                                    </td>
                                    <td>
                                        <strong class="pp-total">
                                            <?= number_format($total, 0, ',', '.') ?>
                                        </strong>
                                        <span class="pp-unit">unit</span>
                                    </td>
                                    <td>
                                        <div class="pp-stage-bar" title="Prospect / Hot Prospect / Deal">
                                            <span class="pp-stage-prospect" style="width: <?= $pPct ?>%"></span>
                                            <span class="pp-stage-hot" style="width: <?= $hPct ?>%"></span>
                                            <span class="pp-stage-deal" style="width: <?= $dPct ?>%"></span>
                                        </div>
                                        <div class="pp-stage-legend">
                                            <span><i class="pp-dot pp-dot-prospect"></i>Prospect</span>
                                            <span><i class="pp-dot pp-dot-hot"></i>Hot</span>
                                            <span><i class="pp-dot pp-dot-deal"></i>Deal</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<?php require_once 'footer.php'; ?>

</body>
</html>
