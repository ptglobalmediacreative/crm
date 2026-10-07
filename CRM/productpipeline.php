<?php
require_once 'config.php';

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// ============================================
// CEK AKSES HALAMAN
// ============================================
requirePermission('produk', 'view');

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================

// ============================================
// BUAT TABEL PRODUCTS (HANYA NAMA PRODUK DAN HARGA JUAL SALES)
// ============================================
try {
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nama_produk VARCHAR(200) NOT NULL,
        harga_jual_sales DECIMAL(15,2) DEFAULT 0,
        updated_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch(PDOException $e) {
    // Abaikan error jika tabel sudah ada
}

// ============================================
// CEK APAKAH KOLOM updated_by ADA
// ============================================
try {
    $db->query("SELECT updated_by FROM products LIMIT 1");
} catch(PDOException $e) {
    $db->exec("ALTER TABLE products ADD COLUMN updated_by INT NULL");
}

// ============================================
// FUNGSI UNTUK MENGUBAH ROLE MENJADI LABEL DIVISI
// ============================================
function getRoleLabel($role) {
    $roleLabels = [
        'it_support' => 'IT Support',
        'admin' => 'Admin',
        'finance' => 'Finance',
        'direktur_utama' => 'Direktur Utama',
        'direktur_operasional' => 'Direktur Operasional',
        'direktur_sales' => 'Direktur Sales',
        'business' => 'Business',
        'sales_manager' => 'Sales Manager',
        'sales' => 'Sales'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// CEK USER YANG BISA AKSES PENUH
// ============================================
$fullAccessRoles = ['finance', 'business', 'it_support', 'admin', 'direktur_utama', 'direktur_sales', 'direktur_operasional'];
$userRole = $_SESSION['role'] ?? 'user';
$hasFullAccess = in_array($userRole, $fullAccessRoles);

// ============================================
// EXPORT TO EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="Data_Produk_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $sql = "SELECT p.*, u.full_name as updated_by_name 
            FROM products p 
            LEFT JOIN users u ON p.updated_by = u.id 
            ORDER BY p.created_at DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $allProducts = $stmt->fetchAll();
    
    echo '<html>';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<h2>Data Produk - PT Ganda Elang Tangguh</h2>';
    echo '<p>Tanggal Export: ' . date('d-m-Y H:i:s') . '</p>';
    echo '<table border="1" cellpadding="5" cellspacing="0">';
    echo '<thead>';
    echo '<tr style="background-color: #1a1a2e; color: #ffffff;">';
    echo '<th>No</th>';
    echo '<th>Nama Produk</th>';
    echo '<th>Harga Jual Sales</th>';
    echo '<th>Tanggal Dibuat</th>';
    echo '<th>Terakhir Update</th>';
    echo '<th>Diupdate Oleh</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    $no = 1;
    foreach ($allProducts as $product) {
        echo '<tr>';
        echo '<td>' . $no++ . '</td>';
        echo '<td>' . htmlspecialchars($product['nama_produk']) . '</td>';
        echo '<td>Rp ' . number_format($product['harga_jual_sales'], 0, ',', '.') . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($product['created_at'])) . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($product['updated_at'])) . '</td>';
        echo '<td>' . htmlspecialchars($product['updated_by_name'] ?? '-') . '</td>';
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '<p style="margin-top: 20px; font-size: 12px; color: #999;">* Data di export pada ' . date('d-m-Y H:i:s') . '</p>';
    echo '</body>';
    echo '</html>';
    exit;
}

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? bersihkan($_GET['search']) : '';

// Build query
$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND nama_produk LIKE ?";
    $params = ["%$search%"];
}

// Get total data
$countSql = "SELECT COUNT(*) FROM products $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT p.*, u.full_name as updated_by_name 
        FROM products p 
        LEFT JOIN users u ON p.updated_by = u.id 
        $where 
        ORDER BY p.created_at DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// ============================================
// STATISTIK
// ============================================
$totalProducts = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// Proses tambah/edit/hapus produk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if (!$hasFullAccess) {
        setFlash('Anda tidak memiliki akses untuk melakukan tindakan ini!', 'danger');
        redirect('produk.php');
    }
    
    if ($action === 'add') {
        if (!canAdd('produk')) {
            setFlash('Anda tidak memiliki akses untuk menambah produk!', 'danger');
            redirect('produk.php');
        }
        
        $nama_produk = bersihkan($_POST['nama_produk']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO products (nama_produk, harga_jual_sales, updated_by) VALUES (?, ?, ?)");
            $stmt->execute([$nama_produk, $harga_jual_sales, $userId]);
            
            setFlash('Produk berhasil ditambahkan!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        if (!canEdit('produk')) {
            setFlash('Anda tidak memiliki akses untuk mengedit produk!', 'danger');
            redirect('produk.php');
        }
        
        $id = (int)$_POST['id'];
        $nama_produk = bersihkan($_POST['nama_produk']);
        $harga_jual_sales = str_replace(['.', ','], '', $_POST['harga_jual_sales']);
        
        $errors = [];
        if (empty($nama_produk)) $errors[] = 'Nama produk wajib diisi!';
        if ($harga_jual_sales < 0) $errors[] = 'Harga jual sales tidak boleh negatif!';
        
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE products SET nama_produk = ?, harga_jual_sales = ?, updated_by = ? WHERE id = ?");
            $stmt->execute([$nama_produk, $harga_jual_sales, $userId, $id]);
            
            setFlash('Produk berhasil diupdate!', 'success');
            redirect('produk.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        if (!canDelete('produk')) {
            setFlash('Anda tidak memiliki akses untuk menghapus produk!', 'danger');
            redirect('produk.php');
        }
        
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('Produk berhasil dihapus!', 'success');
        redirect('produk.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Produk - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/produk.css">
    <link rel="stylesheet" href="css/footer.css">
</head>
<body>

    <?php require_once 'navigation.php'; ?>

    <main class="content">
        
        <!-- HEADER -->
        <div class="page-header">
            <div>
                <h4><span><i class="fas fa-box"></i></span> Produk</h4>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="produk.php?export=excel" class="btn btn-success-custom">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <?php if ($hasFullAccess && canAdd('produk')): ?>
                    <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalProduk">
                        <i class="fas fa-plus"></i> Tambah Produk
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar Produk</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari produk..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary-custom"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="produk.php" class="btn btn-secondary-custom"><i class="fas fa-times"></i></a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body-custom">
                <?= showFlash() ?>
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Nama Produk</th>
                                <th>Harga Jual Sales</th>
                                <?php if ($hasFullAccess): ?>
                                    <th>Aksi</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($products) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($product['nama_produk']) ?></strong></td>
                                        <td class="product-price">Rp <?= number_format($product['harga_jual_sales'], 0, ',', '.') ?></td>
                                        <?php if ($hasFullAccess): ?>
                                            <td>
                                                <div class="d-flex gap-1">
                                                    <button class="btn-action detail" onclick="detailProduk(<?= htmlspecialchars(json_encode($product)) ?>)">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if (canEdit('produk')): ?>
                                                        <button class="btn-action edit" onclick="editProduk(<?= htmlspecialchars(json_encode($product)) ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (canDelete('produk')): ?>
                                                        <button class="btn-action delete" onclick="deleteProduk(<?= $product['id'] ?>)">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $hasFullAccess ? 4 : 3 ?>" class="text-center py-4 product-empty">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data produk
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-transparent border-top p-3">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-end mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>">Prev</a></li>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                                </li>
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

    <!-- MODAL TAMBAH / EDIT PRODUK -->
    <?php if ($hasFullAccess): ?>
    <div class="modal fade" id="modalProduk" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formProduk">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="mb-3">
                            <label class="form-label">Nama Produk <span class="text-danger">*</span></label>
                            <input type="text" name="nama_produk" id="nama_produk" class="form-control" placeholder="Masukkan nama produk" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Harga Jual Sales <span class="text-danger">*</span></label>
                            <div class="currency-input">
                                <span class="currency-prefix">Rp</span>
                                <input type="text" name="harga_jual_sales" id="harga_jual_sales" class="form-control currency-mask" placeholder="0" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary-custom"><i class="fas fa-save"></i> Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL DETAIL -->
    <div class="modal fade" id="modalDetail" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-box" style="color:#ffd700;"></i> Detail Produk</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL DELETE -->
    <?php if ($hasFullAccess): ?>
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus produk ini?</p>
                    <p class="text-muted small">Data yang dihapus tidak dapat dikembalikan!</p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" id="deleteId" value="">
                        <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Hapus</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Format Rupiah untuk input
        document.querySelectorAll('.currency-mask').forEach(function(input) {
            input.addEventListener('input', function(e) {
                let value = this.value.replace(/[^0-9]/g, '');
                if (value) {
                    this.value = new Intl.NumberFormat('id-ID').format(value);
                } else {
                    this.value = '';
                }
            });
        });
        
        // Detail Produk
        function detailProduk(data) {
            var updatedByName = data.updated_by_name || '-';
            var html = `
                <div class="detail-item">
                    <div class="detail-label">Nama Produk</div>
                    <div class="detail-value"><strong>${data.nama_produk}</strong></div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Harga Jual Sales</div>
                    <div class="detail-value">Rp ${new Intl.NumberFormat('id-ID').format(data.harga_jual_sales)}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Tanggal Dibuat</div>
                    <div class="detail-value">${new Date(data.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Terakhir Update</div>
                    <div class="detail-value">${new Date(data.updated_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Diupdate Oleh</div>
                    <div class="detail-value">
                        <i class="fas fa-user-edit" style="color:#2980b9;"></i>
                        ${updatedByName}
                    </div>
                </div>
            `;
            document.getElementById('detailBody').innerHTML = html;
            var modal = new bootstrap.Modal(document.getElementById('modalDetail'));
            modal.show();
        }
        
        // Edit Produk
        function editProduk(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Produk';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('nama_produk').value = data.nama_produk;
            document.getElementById('harga_jual_sales').value = new Intl.NumberFormat('id-ID').format(data.harga_jual_sales);
            
            var modal = new bootstrap.Modal(document.getElementById('modalProduk'));
            modal.show();
        }
        
        // Reset form when modal closed
        document.getElementById('modalProduk').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formProduk').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah Produk';
            document.querySelectorAll('.currency-mask').forEach(function(input) {
                input.value = '';
            });
        });
        
        // Delete Produk
        function deleteProduk(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
    </script>
</body>
</html>