<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// Untuk sementara mengikuti akses Sales Activity karena sumber data Pipeline
// berasal dari Sales Activity + Detail TR.
requirePermission('sales_activity', 'view');

/**
 * Product Pipeline
 *
 * Sumber data:
 * 1. activity_detail_units  -> Prospecting / Prospect
 * 2. tr_detail_units        -> Detail Unit TR
 * 3. detail_transaction_requests.customer_deal
 *
 * Aturan utama:
 * - Prospect berasal dari aktivitas Prospecting.
 * - Jika pada Activity Number yang sama sudah ada TR dengan Tipe Unit yang sama,
 *   Prospect tidak dihitung lagi (tidak double count).
 * - Jika Customer Deal belum Yes, qty TR menjadi Hot Prospect.
 * - Jika Customer Deal = Yes, qty TR menjadi Deal.
 * - Jika Customer Deal = No, TR tidak masuk Hot Prospect/Deal; Prospect yang
 *   belum mempunyai TR aktif tetap berada di Prospect.
 *
 * Tidak membuat tabel cache baru. Data dihitung langsung dari sumber aslinya
 * agar selalu mengikuti perubahan pada Detail Aktivitas dan Detail TR.
 */

$pipeline = [];
$activityCount = 0;
$trCount = 0;
$pipelineErrors = [];

// Helper: normalisasi nama/ID produk.
function pipelineAdd(&$bucket, $productId, $productName, $qty) {
    $productId = (int)$productId;
    $qty = (int)$qty;
    if ($productId <= 0 || $qty <= 0) return;

    if (!isset($bucket[$productId])) {
        $bucket[$productId] = [
            'product_id' => $productId,
            'nama_produk' => trim((string)$productName),
            'prospect' => 0,
            'hot_prospect' => 0,
            'deal' => 0,
        ];
    }
}

// ============================================================
// 1. AMBIL SEMUA ACTIVITY PROSPECTING + TIPE UNIT
// ============================================================
$prospectRows = [];
try {
    $sqlProspect = "
        SELECT
            ad.id AS activity_detail_id,
            ad.sales_activity_id,
            ad.jenis_tugas,
            adu.product_id,
            COALESCE(p.nama_produk, '-') AS nama_produk,
            COALESCE(adu.quantity, 1) AS quantity
        FROM activity_details ad
        INNER JOIN activity_detail_units adu
            ON adu.activity_detail_id = ad.id
        LEFT JOIN products p
            ON p.id = adu.product_id
        WHERE LOWER(TRIM(ad.jenis_tugas)) = 'prospecting'
          AND adu.product_id > 0
        ORDER BY ad.sales_activity_id ASC, ad.id ASC, p.nama_produk ASC
    ";
    $stmtProspect = $db->query($sqlProspect);
    $prospectRows = $stmtProspect->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $prospectRows = [];
    $pipelineErrors[] = 'Query Prospecting: ' . $e->getMessage();
}

// Group Prospect berdasarkan Activity Number + Product.
$prospectByActivity = [];
foreach ($prospectRows as $row) {
    $activityId = (int)$row['sales_activity_id'];
    $productId = (int)$row['product_id'];
    $qty = max(0, (int)$row['quantity']);
    if ($activityId <= 0 || $productId <= 0 || $qty <= 0) continue;

    if (!isset($prospectByActivity[$activityId])) {
        $prospectByActivity[$activityId] = [];
    }
    if (!isset($prospectByActivity[$activityId][$productId])) {
        $prospectByActivity[$activityId][$productId] = [
            'product_id' => $productId,
            'nama_produk' => (string)$row['nama_produk'],
            'qty' => 0,
        ];
    }
    $prospectByActivity[$activityId][$productId]['qty'] += $qty;
}

$activityCount = count($prospectByActivity);

// ============================================================
// 2. AMBIL TR + STATUS CUSTOMER DEAL + DETAIL UNIT
// ============================================================
// Kita ambil Activity Number dari activity_details yang terhubung
// dengan TR. Customer Deal diambil dari Detail TR TERBARU untuk TR Number.
$trRows = [];
try {
    $sqlTR = "
        SELECT
            ad.sales_activity_id,
            ad.tr_number,
            ad.id AS activity_detail_id,
            dtr.status AS tr_status,
            LOWER(TRIM(COALESCE(dtr.customer_deal, ''))) AS customer_deal,
            tdu.id AS tr_detail_unit_id,
            tdu.unit_id AS product_id,
            tdu.qty AS quantity,
            COALESCE(p.nama_produk, '-') AS nama_produk
        FROM activity_details ad
        INNER JOIN tr_detail_units tdu
            ON tdu.trf_number = ad.tr_number
        LEFT JOIN products p
            ON p.id = tdu.unit_id
        LEFT JOIN detail_transaction_requests dtr
            ON dtr.trf_number = ad.tr_number
           AND dtr.id = (
                SELECT MAX(dtr2.id)
                FROM detail_transaction_requests dtr2
                WHERE dtr2.trf_number = ad.tr_number
           )
        WHERE ad.tr_number IS NOT NULL
          AND TRIM(ad.tr_number) <> ''
          AND tdu.unit_id > 0
        ORDER BY ad.sales_activity_id ASC, ad.id DESC, tdu.id ASC
    ";
    $stmtTR = $db->query($sqlTR);
    $trRows = $stmtTR->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $trRows = [];
    $pipelineErrors[] = 'Query TR: ' . $e->getMessage();
}

// ============================================================
// 3. DEDUP TR
// ============================================================
// Satu Activity Number + Product cukup diwakili oleh TR aktif/terbaru.
// Ini mencegah histori TR lama membuat angka Hot/Deal menjadi double.
$trByActivityProduct = [];
$seenTRNumber = [];

foreach ($trRows as $row) {
    $activityId = (int)$row['sales_activity_id'];
    $productId = (int)$row['product_id'];
    $trNumber = trim((string)$row['tr_number']);
    $qty = max(0, (int)$row['quantity']);

    if ($activityId <= 0 || $productId <= 0 || $trNumber === '' || $qty <= 0) continue;

    $key = $activityId . ':' . $productId;

    // Karena query ORDER BY ad.id DESC, baris pertama untuk key adalah
    // hubungan TR paling baru pada Activity Number tersebut.
    if (!isset($trByActivityProduct[$key])) {
        $trByActivityProduct[$key] = [
            'activity_id' => $activityId,
            'tr_number' => $trNumber,
            'product_id' => $productId,
            'nama_produk' => (string)$row['nama_produk'],
            'quantity' => 0,
            'customer_deal' => strtolower(trim((string)$row['customer_deal'])),
            'tr_status' => strtolower(trim((string)$row['tr_status'])),
        ];
    }

    // Detail Unit dalam satu TR bisa lebih dari satu baris untuk tipe unit
    // yang sama. Qty-nya dijumlahkan.
    if ($trByActivityProduct[$key]['tr_number'] === $trNumber) {
        $trByActivityProduct[$key]['quantity'] += $qty;
    }
}

$trCount = count(array_unique(array_map(
    static function ($row) { return $row['tr_number']; },
    array_values($trByActivityProduct)
)));

// ============================================================
// 4. HITUNG PIPELINE
// ============================================================
// Mulai dari seluruh Prospect.
foreach ($prospectByActivity as $activityId => $products) {
    foreach ($products as $productId => $row) {
        pipelineAdd($pipeline, $productId, $row['nama_produk'], $row['qty']);
        $pipeline[$productId]['prospect'] += (int)$row['qty'];
    }
}

// Kemudian setiap TR yang relevan "memindahkan" Prospect menjadi Hot/Deal.
foreach ($trByActivityProduct as $trRow) {
    $activityId = (int)$trRow['activity_id'];
    $productId = (int)$trRow['product_id'];
    $qtyTR = (int)$trRow['quantity'];
    $deal = strtolower(trim((string)$trRow['customer_deal']));

    pipelineAdd($pipeline, $productId, $trRow['nama_produk'], $qtyTR);

    // Customer Deal = No berarti Lost Deal, bukan Hot Prospect/Deal.
    // Prospect hanya dipindahkan jika TR belum Lost Deal.
    if ($deal === 'no') {
        continue;
    }

    // Jika tipe unit yang sama ada di Prospect pada Activity Number yang sama,
    // jangan dihitung double. Hapus qty Prospect untuk tipe unit tersebut.
    if (isset($prospectByActivity[$activityId][$productId])) {
        $prospectQty = (int)$prospectByActivity[$activityId][$productId]['qty'];
        $pipeline[$productId]['prospect'] -= $prospectQty;
        if ($pipeline[$productId]['prospect'] < 0) {
            $pipeline[$productId]['prospect'] = 0;
        }
    }

    if ($deal === 'yes') {
        $pipeline[$productId]['deal'] += $qtyTR;
    } else {
        // TR sudah ada tetapi Customer Deal belum Yes/No -> Hot Prospect.
        $pipeline[$productId]['hot_prospect'] += $qtyTR;
    }
}

// Jika ada produk hanya muncul di TR tanpa Prospect, sudah ditambahkan oleh
// pipelineAdd() dan langsung masuk Hot/Deal sesuai statusnya.

// Buang baris yang seluruh qty-nya 0.
foreach ($pipeline as $productId => $row) {
    if (($row['prospect'] + $row['hot_prospect'] + $row['deal']) <= 0) {
        unset($pipeline[$productId]);
    }
}

// Urutkan berdasarkan nama produk.
usort($pipeline, static function ($a, $b) {
    return strcasecmp($a['nama_produk'], $b['nama_produk']);
});

$totalProspect = 0;
$totalHot = 0;
$totalDeal = 0;
foreach ($pipeline as $row) {
    $totalProspect += (int)$row['prospect'];
    $totalHot += (int)$row['hot_prospect'];
    $totalDeal += (int)$row['deal'];
}
$totalPipeline = $totalProspect + $totalHot + $totalDeal;

function formatQty($qty) {
    return number_format((int)$qty, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Pipeline - PT Ganda Elang Tangguh</title>
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --pp-bg: #f6f8fb;
            --pp-card: #ffffff;
            --pp-border: #e7ebf1;
            --pp-text: #172033;
            --pp-muted: #697386;
            --pp-primary: #1d4ed8;
            --pp-primary-soft: #eff6ff;
            --pp-warning: #d97706;
            --pp-warning-soft: #fff7ed;
            --pp-success: #15803d;
            --pp-success-soft: #f0fdf4;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--pp-text);
            font-family: Inter, sans-serif;
        }

        /* Product Pipeline page: jangan bergantung pada .content global CRM. */
        main.product-pipeline-content {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
            position: relative !important;
            z-index: 50 !important;
            box-sizing: border-box !important;
            width: calc(100% - 245px) !important;
            min-width: 0 !important;
            min-height: calc(100vh - 72px) !important;
            margin-left: 245px !important;
            margin-right: 0 !important;
            padding: 100px 34px 52px !important;
            background: #f6f8fb !important;
            color: #172033 !important;
        }

        main.product-pipeline-content * {
            visibility: visible;
        }

        main.product-pipeline-content .page-title,
        main.product-pipeline-content .toolbar-title,
        main.product-pipeline-content .summary-value,
        main.product-pipeline-content .summary-label,
        main.product-pipeline-content .summary-note,
        main.product-pipeline-content .toolbar-desc,
        main.product-pipeline-content .product-cell,
        main.product-pipeline-content .product-id,
        main.product-pipeline-content .logic-note {
            position: relative;
            z-index: 51;
        }
        .page-header {
            display:flex; justify-content:space-between; align-items:center; gap:20px;
            margin-bottom:22px;
        }
        .page-title { margin:0; font-size:25px; font-weight:800; letter-spacing:-.4px; }
        .page-subtitle { margin:6px 0 0; color:var(--pp-muted); font-size:13px; }
        .title-icon {
            width:42px; height:42px; border-radius:12px; display:inline-flex;
            align-items:center; justify-content:center; margin-right:10px;
            background:var(--pp-primary-soft); color:var(--pp-primary);
        }
        .summary-grid {
            display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:15px; margin-bottom:20px;
        }
        .summary-card {
            background:var(--pp-card); border:1px solid var(--pp-border); border-radius:16px;
            padding:18px 20px; box-shadow:0 5px 18px rgba(16,24,40,.04);
        }
        .summary-label { color:var(--pp-muted); font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
        .summary-value { margin-top:6px; font-size:27px; font-weight:800; }
        .summary-note { margin-top:4px; font-size:11px; color:var(--pp-muted); }
        .summary-card.prospect .summary-value { color:var(--pp-primary); }
        .summary-card.hot .summary-value { color:var(--pp-warning); }
        .summary-card.deal .summary-value { color:var(--pp-success); }
        .pipeline-card {
            background:var(--pp-card); border:1px solid var(--pp-border); border-radius:18px;
            box-shadow:0 7px 24px rgba(16,24,40,.05); overflow:hidden;
        }
        .pipeline-toolbar {
            padding:18px 20px; border-bottom:1px solid var(--pp-border);
            display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;
        }
        .toolbar-title { font-size:15px; font-weight:750; }
        .toolbar-desc { color:var(--pp-muted); font-size:12px; margin-top:3px; }
        .search-wrap { position:relative; width:min(330px,100%); }
        .search-wrap i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:#98a2b3; }
        .search-wrap input { width:100%; border:1px solid var(--pp-border); border-radius:10px; padding:10px 12px 10px 38px; outline:none; font-size:13px; }
        .search-wrap input:focus { border-color:#93c5fd; box-shadow:0 0 0 3px rgba(59,130,246,.10); }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:720px; }
        th, td { padding:15px 20px; border-bottom:1px solid #edf0f4; }
        th { background:#fafbfc; color:#667085; font-size:11px; text-transform:uppercase; letter-spacing:.55px; font-weight:750; white-space:nowrap; }
        td { font-size:13px; vertical-align:middle; }
        tbody tr:hover { background:#fafcff; }
        tbody tr:last-child td { border-bottom:0; }
        .product-cell { font-weight:650; }
        .product-id { display:block; color:#98a2b3; font-size:10px; margin-top:3px; font-weight:500; }
        .qty-cell { text-align:center; font-weight:750; font-size:14px; }
        .badge-qty { display:inline-flex; min-width:54px; justify-content:center; padding:7px 11px; border-radius:9px; }
        .badge-prospect { color:var(--pp-primary); background:var(--pp-primary-soft); }
        .badge-hot { color:var(--pp-warning); background:var(--pp-warning-soft); }
        .badge-deal { color:var(--pp-success); background:var(--pp-success-soft); }
        .empty-state { padding:55px 20px; text-align:center; color:var(--pp-muted); }
        .empty-state i { font-size:38px; margin-bottom:12px; color:#b8c0cc; }
        .logic-note { margin-top:16px; color:var(--pp-muted); font-size:11px; line-height:1.65; }
        .logic-note strong { color:#475467; }
        @media (max-width: 900px) {
            main.product-pipeline-content {
                width: 100% !important;
                margin-left: 0 !important;
                padding: 92px 18px 40px !important;
            }
            .summary-grid { grid-template-columns:repeat(2, minmax(0,1fr)); }
        }
        @media (max-width: 560px) {
            main.product-pipeline-content { padding: 82px 12px 32px !important; }
            .summary-grid { grid-template-columns:1fr; }
            .page-header { align-items:flex-start; flex-direction:column; }
        }
    </style>
</head>
<body>
<?php require_once 'navigation.php'; ?>

<main class="product-pipeline-content">
    <div class="page-header">
        <div>
            <h1 class="page-title"><span class="title-icon"><i class="fas fa-chart-line"></i></span>Product Pipeline</h1>
            <p class="page-subtitle">Rekap Tipe Unit dari seluruh Activity Number dan TR Number tanpa double count.</p>
        </div>
    </div>

    <?= showFlash() ?>

    <?php if (!empty($pipelineErrors)): ?>
        <div style="background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:12px;">
            <strong>Product Pipeline gagal membaca data.</strong>
            <ul style="margin:8px 0 0;padding-left:18px;">
                <?php foreach ($pipelineErrors as $pipelineError): ?>
                    <li><?= htmlspecialchars($pipelineError) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;font-size:11px;color:#667085;">
        <span style="background:#fff;border:1px solid #e7ebf1;border-radius:999px;padding:6px 10px;">Prospecting rows: <?= count($prospectRows) ?></span>
        <span style="background:#fff;border:1px solid #e7ebf1;border-radius:999px;padding:6px 10px;">Activity: <?= (int)$activityCount ?></span>
        <span style="background:#fff;border:1px solid #e7ebf1;border-radius:999px;padding:6px 10px;">TR rows: <?= count($trRows) ?></span>
        <span style="background:#fff;border:1px solid #e7ebf1;border-radius:999px;padding:6px 10px;">TR: <?= (int)$trCount ?></span>
    </div>

    <div class="summary-grid">
        <div class="summary-card prospect">
            <div class="summary-label">Prospect</div>
            <div class="summary-value"><?= formatQty($totalProspect) ?></div>
            <div class="summary-note">Tipe unit dari aktivitas Prospecting</div>
        </div>
        <div class="summary-card hot">
            <div class="summary-label">Hot Prospect</div>
            <div class="summary-value"><?= formatQty($totalHot) ?></div>
            <div class="summary-note">TR yang belum Customer Deal</div>
        </div>
        <div class="summary-card deal">
            <div class="summary-label">Deal</div>
            <div class="summary-value"><?= formatQty($totalDeal) ?></div>
            <div class="summary-note">TR dengan Customer Deal = Yes</div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Pipeline</div>
            <div class="summary-value"><?= formatQty($totalPipeline) ?></div>
            <div class="summary-note"><?= formatQty(count($pipeline)) ?> tipe unit</div>
        </div>
    </div>

    <section class="pipeline-card">
        <div class="pipeline-toolbar">
            <div>
                <div class="toolbar-title">Pipeline Berdasarkan Tipe Unit</div>
                <div class="toolbar-desc">Data otomatis mengikuti Detail Aktivitas Prospecting dan Detail Unit TR.</div>
            </div>
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="search" id="pipelineSearch" placeholder="Cari tipe unit..." autocomplete="off">
            </div>
        </div>

        <div class="table-wrap">
            <?php if (empty($pipeline)): ?>
                <div class="empty-state">
                    <div><i class="fas fa-box-open"></i></div>
                    <strong>Belum ada data Product Pipeline</strong>
                    <div class="mt-1">Data akan muncul setelah ada Tipe Unit pada aktivitas Prospecting atau Detail TR.</div>
                </div>
            <?php else: ?>
                <table id="pipelineTable">
                    <thead>
                        <tr>
                            <th style="width:44%;">Tipe Unit</th>
                            <th style="width:18%; text-align:center;">Prospect</th>
                            <th style="width:18%; text-align:center;">Hot Prospect</th>
                            <th style="width:20%; text-align:center;">Deal</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pipeline as $row): ?>
                        <tr data-product="<?= htmlspecialchars(strtolower($row['nama_produk'])) ?>">
                            <td>
                                <div class="product-cell"><?= htmlspecialchars($row['nama_produk']) ?></div>
                                <span class="product-id">Product ID #<?= (int)$row['product_id'] ?></span>
                            </td>
                            <td class="qty-cell">
                                <?php if ((int)$row['prospect'] > 0): ?>
                                    <span class="badge-qty badge-prospect"><?= formatQty($row['prospect']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="qty-cell">
                                <?php if ((int)$row['hot_prospect'] > 0): ?>
                                    <span class="badge-qty badge-hot"><?= formatQty($row['hot_prospect']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="qty-cell">
                                <?php if ((int)$row['deal'] > 0): ?>
                                    <span class="badge-qty badge-deal"><?= formatQty($row['deal']) ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <div class="logic-note">
        <strong>Logika pipeline:</strong> Prospecting menjadi Prospect. Saat tipe unit yang sama sudah masuk TR pada Activity Number yang sama, qty Prospect tersebut tidak dihitung lagi dan berpindah menjadi Hot Prospect. Jika Customer Deal pada TR = Yes, qty tersebut berpindah menjadi Deal.
        Data dihitung langsung dari tabel sumber sehingga tidak memerlukan tabel database pipeline tambahan.
    </div>
</main>

<script>
(function () {
    const input = document.getElementById('pipelineSearch');
    const rows = document.querySelectorAll('#pipelineTable tbody tr');
    if (!input) return;
    input.addEventListener('input', function () {
        const keyword = this.value.toLowerCase().trim();
        rows.forEach(function (row) {
            const product = row.getAttribute('data-product') || '';
            row.style.display = product.includes(keyword) ? '' : 'none';
        });
    });
})();
</script>
</body>
</html>