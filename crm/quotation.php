<?php
error_reporting(E_ALL);
// Jangan tampilkan error internal database ke browser pada production.
require_once 'config.php';
date_default_timezone_set('Asia/Jakarta');

if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// Mengikuti kontrol akses halaman Transaction Request.
requirePermission('transaction_request', 'view');

$userId = (int)($_SESSION['user_id'] ?? 0);
$userRole = (string)($_SESSION['role'] ?? 'user');
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Tampilkan satu baris per Quotation Number, beserta Account dan Sales terkait.
$where = "WHERE ad.quotation_number IS NOT NULL AND TRIM(ad.quotation_number) <> ''";
$params = [];
if ($userRole === 'sales') {
    $where .= " AND sa.sales_id = ?";
    $params[] = $userId;
}
if ($search !== '') {
    $where .= " AND (ad.quotation_number LIKE ? OR a.nama_pt LIKE ? OR u.full_name LIKE ?)";
    $needle = '%' . $search . '%';
    array_push($params, $needle, $needle, $needle);
}

$countSql = "SELECT COUNT(DISTINCT ad.quotation_number)
             FROM activity_details ad
             LEFT JOIN sales_activities sa ON sa.id = ad.sales_activity_id
             LEFT JOIN accounts a ON a.id = sa.account_id
             LEFT JOIN users u ON u.id = sa.sales_id
             $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$sql = "SELECT
            ad.quotation_number,
            MAX(a.nama_pt) AS nama_pt,
            MAX(a.badan_usaha) AS badan_usaha,
            MAX(u.full_name) AS sales_name,
            MIN(ad.created_at) AS quotation_created_at
        FROM activity_details ad
        LEFT JOIN sales_activities sa ON sa.id = ad.sales_activity_id
        LEFT JOIN accounts a ON a.id = sa.account_id
        LEFT JOIN users u ON u.id = sa.sales_id
        $where
        GROUP BY ad.quotation_number
        ORDER BY quotation_created_at DESC, ad.quotation_number DESC
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation - PT Ganda Elang Tangguh</title>
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/transactionrequest.css?v=20260918-2">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        .quotation-number-link { color:#8e44ad; font-weight:700; text-decoration:none; }
        .quotation-number-link:hover { text-decoration:underline; }
        .quotation-empty { text-align:center; padding:2rem 1rem; color:#6c757d; }

        /* Rata tengah kolom Account agar sejajar dan tidak terlihat miring. */
        .table-custom th.account-column,
        .table-custom td.account-column {
            text-align: center !important;
            vertical-align: middle !important;
        }
        .table-custom td.account-column .account-name {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            min-height: 100%;
            text-align: center;
            line-height: 1.45;
            overflow-wrap: anywhere;
            white-space: normal;
        }
        .table-custom td.account-column .account-name strong {
            display: block;
            width: 100%;
            margin: 0 auto;
            text-align: center;
            font-weight: 600;
        }
    </style>
</head>
<body class="page-transactionrequest">
<?php require_once 'navigation.php'; ?>
<main class="content">
    <div class="page-header">
        <h4><span><i class="fas fa-file-invoice-dollar"></i></span> Quotation Price</h4>
    </div>

    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon gold"><i class="fas fa-file-invoice-dollar"></i></div>
            <div class="stat-number"><?= number_format($totalRows) ?></div>
            <div class="stat-label">Total Quotation</div>
        </div>
    </div>

    <div class="card-custom">
        <div class="card-header-custom">
            <h6><i class="fas fa-list"></i> Daftar Quotation</h6>
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari quotation, account, sales..." value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn btn-primary-custom" aria-label="Cari"><i class="fas fa-search"></i></button>
                <?php if ($search !== ''): ?>
                    <a href="quotation.php" class="btn btn-secondary-custom" aria-label="Reset pencarian"><i class="fas fa-times"></i></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="card-body-custom">
            <?= function_exists('showFlash') ? showFlash() : '' ?>
            <div class="table-responsive">
                <table class="table table-custom">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>Quotation Number</th>
                            <th class="account-column">Account</th>
                            <th>Tanggal</th>
                            <th>Sales</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($quotations): ?>
                        <?php $no = $offset + 1; foreach ($quotations as $quotation): ?>
                            <tr>
                                <td><?= $no++ ?></td>
                                <td>
                                    <a class="quotation-number-link" href="detailquotation.php?quotation_number=<?= urlencode($quotation['quotation_number']) ?>">
                                        <?= htmlspecialchars($quotation['quotation_number'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </td>
                                <td class="account-column">
                                    <div class="account-name">
                                        <?php
                                            // Format nama customer: "PT, Merak Borindo Mandiri".
                                            $namaPerusahaan = trim((string)($quotation['nama_pt'] ?? ''));
                                            $jenisBadanUsaha = trim((string)($quotation['badan_usaha'] ?? ''));
                                            $jenisBadanUsaha = rtrim($jenisBadanUsaha, " ,.");

                                            if ($namaPerusahaan !== '' && $jenisBadanUsaha !== '') {
                                                $namaCustomerTampil = $namaPerusahaan . ', ' . $jenisBadanUsaha;
                                            } elseif ($namaPerusahaan !== '') {
                                                $namaCustomerTampil = $namaPerusahaan;
                                            } elseif ($jenisBadanUsaha !== '') {
                                                $namaCustomerTampil = $jenisBadanUsaha;
                                            } else {
                                                $namaCustomerTampil = '-';
                                            }
                                        ?>
                                        <strong><?= htmlspecialchars($namaCustomerTampil, ENT_QUOTES, 'UTF-8') ?></strong>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                        $quotationDate = $quotation['quotation_created_at'] ?? null;
                                        echo $quotationDate
                                            ? htmlspecialchars(date('d-m-Y', strtotime($quotationDate)), ENT_QUOTES, 'UTF-8')
                                            : '-';
                                    ?>
                                </td>
                                <td><?= htmlspecialchars($quotation['sales_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <a class="btn btn-primary-custom btn-sm" href="detailquotation.php?quotation_number=<?= urlencode($quotation['quotation_number']) ?>">
                                        <i class="fas fa-eye"></i> Detail
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="quotation-empty"><i class="fas fa-inbox me-2"></i>Belum ada data quotation.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-transparent border-top p-3">
                <nav aria-label="Navigasi halaman quotation">
                    <ul class="pagination pagination-sm justify-content-end mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Prev</a></li>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>">Next</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
    <?php require_once 'footer.php'; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
