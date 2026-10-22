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
        .quotation-page{max-width:1500px;margin:0 auto;padding:24px}
        .quotation-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:22px}
        .quotation-title{font-weight:800;color:#17243a;margin:0 0 6px}
        .quotation-subtitle{color:#6b7280;margin:0}
        .quotation-card{background:#fff;border:1px solid #e8edf3;border-radius:16px;box-shadow:0 8px 28px rgba(20,36,60,.06);overflow:hidden;margin-bottom:20px}
        .quotation-card-head{padding:18px 22px;border-bottom:1px solid #edf0f5;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
        .quotation-card-body{padding:22px}
        .info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
        .info-item{background:#f8fafc;border:1px solid #edf1f5;border-radius:12px;padding:14px}
        .info-label{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;font-weight:700;margin-bottom:5px}
        .info-value{font-weight:700;color:#17243a;overflow-wrap:anywhere}
        .items-table th{white-space:nowrap;background:#f6f8fb;color:#344054;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
        .items-table td{vertical-align:middle}
        .items-table .form-control{min-width:100px}
        .items-table .description-input{min-width:190px}
        .items-table .model-input{min-width:180px}
        .items-table .total-cell{min-width:145px;font-weight:700;white-space:nowrap;color:#17243a}
        .total-summary{display:flex;justify-content:flex-end;margin-top:18px}
        .total-box{min-width:280px;background:#f8fafc;border:1px solid #e5eaf1;border-radius:12px;padding:16px 20px}
        .total-box span{color:#667085}.total-box strong{font-size:20px;color:#17243a}
        .btn-add-row{background:#eef5ff;color:#155eef;border:1px solid #c7dcff;border-radius:9px;padding:9px 13px;font-weight:700}
        .btn-save-quotation{background:#155eef;color:#fff;border:0;border-radius:9px;padding:10px 17px;font-weight:700}
        .btn-save-quotation:hover{background:#004eeb;color:#fff}
        .btn-remove-row{border-radius:8px}
        @media(max-width:768px){.quotation-page{padding:14px}.quotation-card-body{padding:14px}.info-grid{grid-template-columns:1fr}.total-box{width:100%}.total-summary{justify-content:stretch}}
    </style>
</head>
<body>
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
                        <div class="total-summary">
                            <div class="total-box d-flex justify-content-between align-items-center gap-4">
                                <span class="fw-semibold">Total Quotation</span>
                                <strong id="grandTotal"><?= h(rupiah($totalQuotation)) ?></strong>
                            </div>
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
            <td><input class="form-control model-input" type="text" name="model_unit[]" maxlength="255" placeholder="Contoh: GET-100" required></td>
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
