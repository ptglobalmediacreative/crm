<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    setFlash('Silakan login terlebih dahulu.', 'warning');
    redirect('login.php');
}

requirePermission('quotation', 'view');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$db = $db ?? (function_exists('getPDO') ? getPDO() : null);
if (!$db instanceof PDO) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = strtolower(trim((string)($_SESSION['role'] ?? '')));
$quotationNumber = trim((string)($_GET['quotation_number'] ?? $_POST['quotation_number'] ?? ''));

if ($quotationNumber === '' || strlen($quotationNumber) > 100) {
    setFlash('Quotation Number tidak valid.', 'danger');
    redirect('quotation.php');
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function rupiah($value): string {
    return 'Rp ' . number_format((float)$value, 0, ',', '.');
}
function hasColumn(PDO $db, string $table, string $column): bool {
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.columns
                          WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/*
 * Item quotation disimpan terpisah dari activity_details karena satu
 * quotation dapat memiliki banyak baris Model Unit/Description/Qty/Price.
 * Tabel dibuat jika belum ada. Bila hosting membatasi CREATE TABLE,
 * buat tabel ini melalui SQL yang disertakan di bawah halaman.
 */
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS quotation_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quotation_number VARCHAR(100) NOT NULL,
            line_no INT UNSIGNED NOT NULL DEFAULT 1,
            model_unit VARCHAR(255) NOT NULL,
            description TEXT NULL,
            qty DECIMAL(12,2) NOT NULL DEFAULT 1,
            price DECIMAL(15,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_quotation_number (quotation_number),
            KEY idx_quotation_line (quotation_number, line_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    error_log('[CRM quotation] Gagal memastikan tabel quotation_items: ' . $e->getMessage());
}

$parentSql = "
    SELECT ad.id, ad.quotation_number, ad.request_quotation, ad.sales_activity_id,
           sa.sales_id, a.nama_pt, u.full_name AS sales_name
    FROM activity_details ad
    LEFT JOIN sales_activities sa ON sa.id = ad.sales_activity_id
    LEFT JOIN accounts a ON a.id = sa.account_id
    LEFT JOIN users u ON u.id = sa.sales_id
    WHERE ad.quotation_number = ?
    ORDER BY ad.id DESC
    LIMIT 1
";
$parentStmt = $db->prepare($parentSql);
$parentStmt->execute([$quotationNumber]);
$quotation = $parentStmt->fetch(PDO::FETCH_ASSOC);

if (!$quotation) {
    http_response_code(404);
    exit('Quotation Number tidak ditemukan di Sales Activity.');
}

// Sales hanya dapat membuka quotation miliknya sendiri.
if ($userRole === 'sales' && (int)($quotation['sales_id'] ?? 0) !== $userId) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke quotation ini.');
}

$canEdit = function_exists('canEdit') ? (bool)canEdit('quotation') : in_array($userRole, ['admin', 'it_support'], true);
$flashMessage = '';
$flashType = 'success';

if (empty($_SESSION['quotation_csrf'])) {
    $_SESSION['quotation_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['quotation_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_items') {
    if (!$canEdit) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk mengedit quotation.');
    }
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        exit('Token keamanan tidak valid. Muat ulang halaman lalu coba kembali.');
    }

    $models = $_POST['model_unit'] ?? [];
    $descriptions = $_POST['description'] ?? [];
    $quantities = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];

    if (!is_array($models) || !is_array($descriptions) || !is_array($quantities) || !is_array($prices)) {
        $flashMessage = 'Format data input tidak valid.';
        $flashType = 'danger';
    } else {
        $rows = [];
        $errors = [];
        $count = max(count($models), count($descriptions), count($quantities), count($prices));
        if ($count > 200) {
            $errors[] = 'Maksimal 200 baris item per quotation.';
        } else {
            for ($i = 0; $i < $count; $i++) {
                $model = trim((string)($models[$i] ?? ''));
                $description = trim((string)($descriptions[$i] ?? ''));
                $qtyRaw = str_replace(',', '.', trim((string)($quantities[$i] ?? '1')));
                $priceRaw = str_replace(',', '.', trim((string)($prices[$i] ?? '0')));

                // Baris kosong seluruhnya dilewati, agar tombol tambah/hapus baris fleksibel.
                if ($model === '' && $description === '' && $qtyRaw === '' && $priceRaw === '') {
                    continue;
                }
                if ($model === '') {
                    $errors[] = 'Model Unit wajib diisi pada baris ' . ($i + 1) . '.';
                    continue;
                }
                if (!is_numeric($qtyRaw) || (float)$qtyRaw <= 0) {
                    $errors[] = 'Qty harus lebih besar dari 0 pada baris ' . ($i + 1) . '.';
                    continue;
                }
                if (!is_numeric($priceRaw) || (float)$priceRaw < 0) {
                    $errors[] = 'Price tidak boleh negatif pada baris ' . ($i + 1) . '.';
                    continue;
                }
                $rows[] = [
                    'model_unit' => mb_substr($model, 0, 255),
                    'description' => $description,
                    'qty' => (float)$qtyRaw,
                    'price' => (float)$priceRaw
                ];
            }
        }

        if (!$errors && !$rows) {
            $errors[] = 'Masukkan minimal satu item quotation.';
        }

        if ($errors) {
            $flashMessage = implode(' ', $errors);
            $flashType = 'danger';
        } else {
            try {
                $db->beginTransaction();
                $delete = $db->prepare("DELETE FROM quotation_items WHERE quotation_number = ?");
                $delete->execute([$quotationNumber]);

                $insert = $db->prepare("
                    INSERT INTO quotation_items
                        (quotation_number, line_no, model_unit, description, qty, price)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                foreach ($rows as $index => $row) {
                    $insert->execute([
                        $quotationNumber,
                        $index + 1,
                        $row['model_unit'],
                        $row['description'] !== '' ? $row['description'] : null,
                        $row['qty'],
                        $row['price']
                    ]);
                }
                $db->commit();

                // Redirect-after-POST mencegah penyimpanan ulang saat refresh.
                header('Location: detailquotation.php?quotation_number=' . rawurlencode($quotationNumber) . '&saved=1');
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('[CRM quotation] Simpan item gagal: ' . $e->getMessage());
                $flashMessage = 'Gagal menyimpan item quotation. Periksa tabel quotation_items dan coba kembali.';
                $flashType = 'danger';
            }
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $flashMessage = 'Item quotation berhasil disimpan.';
}

$items = [];
try {
    $itemStmt = $db->prepare("SELECT id, line_no, model_unit, description, qty, price
                              FROM quotation_items
                              WHERE quotation_number = ?
                              ORDER BY line_no ASC, id ASC");
    $itemStmt->execute([$quotationNumber]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[CRM quotation] Ambil item gagal: ' . $e->getMessage());
    $flashMessage = $flashMessage ?: 'Data item belum dapat dibaca. Pastikan tabel quotation_items tersedia.';
    $flashType = 'danger';
}
if (!$items) {
    $items = [[
        'model_unit' => '',
        'description' => '',
        'qty' => '1',
        'price' => '0'
    ]];
}

$totalQuotation = 0.0;
foreach ($items as $item) {
    $totalQuotation += (float)($item['qty'] ?? 0) * (float)($item['price'] ?? 0);
}
$companyName = trim((string)($quotation['nama_pt'] ?? '')) ?: 'Account tidak tersedia';
$salesName = trim((string)($quotation['sales_name'] ?? '')) ?: 'Sales tidak tersedia';
$displayRows = [];
foreach ($items as $item) {
    $displayRows[] = [
        'model_unit' => (string)($item['model_unit'] ?? ''),
        'description' => (string)($item['description'] ?? ''),
        'qty' => (string)($item['qty'] ?? '1'),
        'price' => (string)($item['price'] ?? '0')
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Quotation - <?= h($quotationNumber) ?></title>
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="stylesheet" href="css/navigation.css">
    <link rel="stylesheet" href="css/footer.css">
    <link rel="stylesheet" href="css/app.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --quotation-bg: #07101f;
            --quotation-panel: rgba(12, 23, 43, .96);
            --quotation-panel-deep: rgba(7, 14, 28, .98);
            --quotation-line: rgba(148, 163, 184, .12);
            --quotation-text: #dbe5f5;
            --quotation-muted: #7f8da5;
            --quotation-blue: #60a5fa;
            --quotation-gold: #e0b53d;
        }
        body.page-detailquotation { background: var(--quotation-bg); color: var(--quotation-text); }
        .page-detailquotation .content {
            margin-left: 245px; width: calc(100% - 245px); max-width: none;
            min-height: calc(100vh - 72px); padding: 28px 28px 50px;
        }
        .quotation-page { max-width: none; margin: 0 auto; padding: 0; }
        .quotation-head { display:flex; justify-content:space-between; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom:18px; }
        .quotation-title { display:flex; align-items:center; gap:10px; font-size:24px; line-height:1.1; letter-spacing:-.4px; font-weight:800; color:#f7f9ff; margin:0 0 7px; }
        .quotation-title i { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:11px; background:rgba(96,165,250,.1); border:1px solid rgba(96,165,250,.15); color:var(--quotation-blue); font-size:15px; }
        .quotation-subtitle { color:var(--quotation-muted); font-size:12px; margin:0; }
        .quotation-card { background:linear-gradient(145deg,var(--quotation-panel),var(--quotation-panel-deep)); border:1px solid var(--quotation-line); border-radius:16px; box-shadow:0 18px 45px rgba(0,0,0,.18); overflow:hidden; margin-bottom:18px; }
        .quotation-card-head { min-height:62px; padding:13px 17px; border-bottom:1px solid rgba(148,163,184,.1); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .quotation-card-head h5 { color:#f7f9ff; font-size:12px; font-weight:800; margin:0; }
        .quotation-card-head h5 i { color:var(--quotation-blue)!important; }
        .quotation-card-body { padding:20px; }
        .info-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
        .info-item { min-width:0; background:rgba(8,16,31,.72); border:1px solid rgba(148,163,184,.11); border-radius:11px; padding:14px; }
        .info-label { font-size:9px; text-transform:uppercase; letter-spacing:.8px; color:#71809a; font-weight:800; margin:0 0 6px; }
        .info-value { font-size:12px; font-weight:700; color:#e3eaf5; overflow-wrap:anywhere; line-height:1.55; }
        .items-table { min-width:1050px; margin:0!important; border-collapse:separate; border-spacing:0; --bs-table-bg:transparent; --bs-table-color:#cbd5e1; --bs-table-border-color:rgba(148,163,184,.09); }
        .items-table th { white-space:nowrap; background:rgba(5,12,25,.65)!important; color:#71809a!important; font-size:9px; text-transform:uppercase; letter-spacing:.6px; font-weight:800; padding:12px 13px!important; border-bottom:1px solid rgba(148,163,184,.12)!important; }
        .items-table td { vertical-align:middle; padding:10px 13px!important; color:#cbd5e1!important; background:transparent!important; border-bottom:1px solid rgba(148,163,184,.07)!important; }
        .items-table tbody tr:hover td { background:rgba(59,130,246,.035)!important; }
        .items-table .form-control { min-width:100px; min-height:38px; font-size:11px; border-radius:9px; border:1px solid rgba(148,163,184,.16); padding:9px 11px; background:#081426!important; color:#dbe5f5!important; box-shadow:none; }
        .items-table .form-control:focus { border-color:rgba(96,165,250,.48); box-shadow:0 0 0 3px rgba(59,130,246,.1); background:#0b172c!important; color:#fff!important; }
        .items-table .form-control::placeholder { color:#52627d; }
        .items-table .form-control[readonly] { opacity:.78; }
        .items-table .description-input { min-width:210px; }
        .items-table .model-input { min-width:180px; }
        .items-table .total-cell { min-width:145px; font-weight:800; white-space:nowrap; color:var(--quotation-gold)!important; font-size:11px; }
        .row-number { color:#8e9bb5!important; }
        .total-summary { display:flex; justify-content:flex-end; margin-top:18px; }
        .total-box { min-width:300px; background:linear-gradient(145deg,rgba(12,23,43,.98),rgba(7,14,28,.98)); border:1px solid var(--quotation-line); border-radius:11px; padding:13px 16px; box-shadow:0 10px 25px rgba(0,0,0,.12); }
        .total-box span { color:#8e9bb5; font-size:10px; text-transform:uppercase; letter-spacing:.7px; }
        .total-box strong { font-size:18px; color:var(--quotation-gold); font-weight:800; white-space:nowrap; }
        .btn-add-row, .btn-save-quotation, .page-detailquotation .btn-outline-secondary {
            display:inline-flex; align-items:center; justify-content:center; gap:7px; border-radius:9px;
            padding:9px 14px; font-size:11px; font-weight:800; text-decoration:none; transition:.2s;
        }
        .btn-add-row { background:rgba(37,99,235,.15); color:#9fc5ff; border:1px solid rgba(96,165,250,.25); }
        .btn-add-row:hover { background:rgba(37,99,235,.25); color:#fff; border-color:rgba(96,165,250,.4); }
        .btn-save-quotation { background:linear-gradient(135deg,#3b82f6,#6366f1); color:#fff; border:0; }
        .btn-save-quotation:hover { background:linear-gradient(135deg,#4f8df7,#6d70f3); color:#fff; transform:translateY(-1px); box-shadow:0 8px 22px rgba(59,130,246,.2); }
        .page-detailquotation .btn-outline-secondary { color:#aab7ca; border-color:rgba(148,163,184,.2); background:#111d31; }
        .page-detailquotation .btn-outline-secondary:hover { color:#fff; background:#17253d; border-color:rgba(148,163,184,.3); }
        .btn-remove-row { border-radius:8px; padding:6px 10px; font-size:10px; }
        .page-detailquotation .alert { border-radius:10px; border:1px solid rgba(96,165,250,.14); padding:11px 14px; font-size:11px; background:#0c1830; color:#cbd5e1; }
        .page-detailquotation .alert-danger { border-color:rgba(251,113,133,.2); background:rgba(74,18,35,.35); color:#fecdd3; }
        .page-detailquotation .alert-success { border-color:rgba(52,211,153,.2); background:rgba(6,78,59,.28); color:#a7f3d0; }
        .page-detailquotation .btn-close { filter:invert(1) grayscale(1); }
        @media(max-width:991px) {
            .page-detailquotation .content { margin-left:0; width:100%; padding:24px 18px 40px; }
        }
        @media(max-width:767px) {
            .page-detailquotation .content { padding:20px 14px 36px; }
            .quotation-head { align-items:flex-start; flex-direction:column; }
            .quotation-head > a { width:100%; }
            .info-grid { grid-template-columns:1fr; }
            .quotation-card-body { padding:14px; }
            .total-box { min-width:0; width:100%; }
            .total-summary { justify-content:stretch; }
        }
        @media(max-width:520px) {
            .page-detailquotation .content { padding:16px 10px 32px; }
            .quotation-title { font-size:20px; }
            .quotation-title i { width:34px; height:34px; border-radius:9px; }
            .quotation-card-head { padding:13px; }
        }
    </style>
</head>
<body class="page-detailquotation">
<div class="app">
    <?php require __DIR__ . '/navigation.php'; ?>
    <main class="content">
        <div class="quotation-page">
            <div class="quotation-head">
                <div>
                    <h1 class="quotation-title"><i class="fas fa-file-invoice-dollar me-2"></i>Detail Quotation</h1>
                    <p class="quotation-subtitle">Kelola baris item quotation. Total Price dihitung otomatis dari Price × Qty.</p>
                </div>
                <a class="btn btn-outline-secondary" href="quotation.php"><i class="fas fa-arrow-left me-2"></i>Kembali ke Quotation</a>
            </div>

            <?php if ($flashMessage !== ''): ?>
                <div class="alert alert-<?= h($flashType) ?> alert-dismissible fade show" role="alert">
                    <?= h($flashMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
                </div>
            <?php endif; ?>

            <section class="quotation-card">
                <div class="quotation-card-head">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-circle-info me-2 text-primary"></i>Informasi Quotation</h5>
                </div>
                <div class="quotation-card-body">
                    <div class="info-grid">
                        <div class="info-item"><div class="info-label">Quotation Number</div><div class="info-value"><?= h($quotationNumber) ?></div></div>
                        <div class="info-item"><div class="info-label">Account</div><div class="info-value"><?= h($companyName) ?></div></div>
                        <div class="info-item"><div class="info-label">Sales</div><div class="info-value"><?= h($salesName) ?></div></div>
                    </div>
                </div>
            </section>

            <section class="quotation-card">
                <div class="quotation-card-head">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-list-check me-2 text-primary"></i>Daftar Item</h5>
                    <?php if ($canEdit): ?>
                        <button type="button" class="btn-add-row" id="addRowBtn"><i class="fas fa-plus me-2"></i>Tambah Baris</button>
                    <?php endif; ?>
                </div>
                <div class="quotation-card-body">
                    <form method="post" id="quotationForm">
                        <input type="hidden" name="action" value="save_items">
                        <input type="hidden" name="quotation_number" value="<?= h($quotationNumber) ?>">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <div class="table-responsive">
                            <table class="table table-bordered items-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:65px">No</th>
                                        <th>Model Unit</th>
                                        <th>Description</th>
                                        <th style="width:115px">Qty</th>
                                        <th style="width:180px">Price</th>
                                        <th style="width:170px">Total Price</th>
                                        <?php if ($canEdit): ?><th style="width:90px">Aksi</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="quotationRows">
                                <?php foreach ($displayRows as $index => $item): ?>
                                    <tr class="quotation-row">
                                        <td class="row-number text-center fw-bold"><?= $index + 1 ?></td>
                                        <td><input class="form-control model-input" type="text" name="model_unit[]" maxlength="255" value="<?= h($item['model_unit']) ?>" placeholder="Contoh: GET-100" <?= $canEdit ? 'required' : 'readonly' ?>></td>
                                        <td><input class="form-control description-input" type="text" name="description[]" value="<?= h($item['description']) ?>" placeholder="Deskripsi item" <?= $canEdit ? '' : 'readonly' ?>></td>
                                        <td><input class="form-control qty-input" type="number" name="qty[]" min="0.01" step="0.01" value="<?= h($item['qty']) ?>" <?= $canEdit ? 'required' : 'readonly' ?>></td>
                                        <td><input class="form-control price-input" type="number" name="price[]" min="0" step="0.01" value="<?= h($item['price']) ?>" <?= $canEdit ? 'required' : 'readonly' ?>></td>
                                        <td class="total-cell">Rp 0</td>
                                        <?php if ($canEdit): ?><td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Hapus baris"><i class="fas fa-trash"></i></button></td><?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($canEdit): ?>
                            <div class="d-flex justify-content-end gap-2 mt-4 flex-wrap">
                                <button type="reset" class="btn btn-outline-secondary"><i class="fas fa-rotate-left me-2"></i>Reset Form</button>
                                <button type="submit" class="btn-save-quotation"><i class="fas fa-floppy-disk me-2"></i>Simpan Item</button>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mt-3 mb-0"><i class="fas fa-lock me-1"></i>Akun Anda hanya memiliki akses lihat.</p>
                        <?php endif; ?>
                    </form>
                </div>
            </section>
            <?php require __DIR__ . '/footer.php'; ?>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    const tbody = document.getElementById('quotationRows');
    const addButton = document.getElementById('addRowBtn');
    const canEdit = <?= $canEdit ? 'true' : 'false' ?>;

    function money(value) {
        return new Intl.NumberFormat('id-ID', {
            style: 'currency', currency: 'IDR', maximumFractionDigits: 2
        }).format(value || 0);
    }
    function updateRows() {
        let grand = 0;
        tbody.querySelectorAll('.quotation-row').forEach((row, index) => {
            row.querySelector('.row-number').textContent = index + 1;
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(row.querySelector('.price-input').value) || 0;
            const total = qty * price;
            row.querySelector('.total-cell').textContent = money(total);
            grand += total;
        });
        document.getElementById('grandTotal').textContent = money(grand);
    }
    function addRow(data = {}) {
        if (tbody.querySelectorAll('.quotation-row').length >= 200) {
            alert('Maksimal 200 baris item per quotation.');
            return;
        }
        const row = document.createElement('tr');
        row.className = 'quotation-row';
        row.innerHTML = `
            <td class="row-number text-center fw-bold"></td>
            <td><input class="form-control model-input" type="text" name="model_unit[]" maxlength="255" placeholder="Type Unit" required></td>
            <td><input class="form-control description-input" type="text" name="description[]" placeholder="Deskripsi item"></td>
            <td><input class="form-control qty-input" type="number" name="qty[]" min="0.01" step="0.01" value="1" required></td>
            <td><input class="form-control price-input" type="number" name="price[]" min="0" step="0.01" value="0" required></td>
            <td class="total-cell">Rp 0</td>
            ${canEdit ? '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm btn-remove-row" title="Hapus baris"><i class="fas fa-trash"></i></button></td>' : ''}
        `;
        row.querySelector('.model-input').value = data.model_unit ?? '';
        row.querySelector('.description-input').value = data.description ?? '';
        row.querySelector('.qty-input').value = data.qty ?? '1';
        row.querySelector('.price-input').value = data.price ?? '0';
        tbody.appendChild(row);
        updateRows();
    }

    if (addButton) addButton.addEventListener('click', () => addRow());
    tbody.addEventListener('input', event => {
        if (event.target.matches('.qty-input, .price-input')) updateRows();
    });
    tbody.addEventListener('click', event => {
        const button = event.target.closest('.btn-remove-row');
        if (!button) return;
        if (tbody.querySelectorAll('.quotation-row').length <= 1) {
            const row = button.closest('.quotation-row');
            row.querySelectorAll('input').forEach(input => {
                if (input.name === 'qty[]') input.value = '1';
                else if (input.name === 'price[]') input.value = '0';
                else input.value = '';
            });
        } else {
            button.closest('.quotation-row').remove();
        }
        updateRows();
    });
    const form = document.getElementById('quotationForm');
    form.addEventListener('reset', () => setTimeout(updateRows, 0));
    updateRows();
})();
</script>
</body>
</html>
