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
    <link rel="stylesheet" href="css/productpipeline.css?v=20261007b">
</head>
<body class="page-productpipeline">
<!-- Product Pipeline FINAL layout override.
     Wajib diletakkan SETELAH navigation.php karena navigation.php memuat
     navigation.css/app.css setelah halaman mulai dirender. -->
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
