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
requirePermission('data_user', 'view');

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER
// ============================================
$userMenus = getUserMenus();

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
        'sales' => 'Sales',
        'service_support' => 'Service Support',
        'part_support' => 'Part Support'
    ];
    return $roleLabels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

// ============================================
// EXPORT DATA USER KE EXCEL
// ============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Data_User_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    $exportSearch = isset($_GET['search']) ? bersihkan($_GET['search']) : '';
    $exportWhere = 'WHERE 1=1';
    $exportParams = [];

    if ($exportSearch !== '') {
        $exportWhere .= ' AND (username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)';
        $exportParams = [
            "%$exportSearch%",
            "%$exportSearch%",
            "%$exportSearch%",
            "%$exportSearch%"
        ];
    }

    $stmt = $db->prepare("SELECT id, username, email, full_name, phone, role, is_active, created_at FROM users $exportWhere ORDER BY created_at DESC");
    $stmt->execute($exportParams);
    $exportUsers = $stmt->fetchAll();

    echo '<html><head><meta charset="UTF-8"></head><body class="page-data-user">';
    echo '<h2>Data User - PT Ganda Elang Tangguh</h2>';
    echo '<p>Tanggal Export: ' . date('d-m-Y H:i:s') . '</p>';
    if ($exportSearch !== '') {
        echo '<p>Filter Pencarian: ' . htmlspecialchars($exportSearch) . '</p>';
    }
    echo '<table border="1" cellpadding="6" cellspacing="0">';
    echo '<thead><tr style="background-color:#0c1626;color:#ffffff;">';
    echo '<th>No</th><th>Username</th><th>Nama Lengkap</th><th>Email</th><th>No HP</th><th>Divisi</th><th>Status</th><th>Tanggal Dibuat</th>';
    echo '</tr></thead><tbody>';

    $exportNo = 1;
    foreach ($exportUsers as $exportUser) {
        echo '<tr>';
        echo '<td>' . $exportNo++ . '</td>';
        echo '<td>' . htmlspecialchars($exportUser['username']) . '</td>';
        echo '<td>' . htmlspecialchars($exportUser['full_name']) . '</td>';
        echo '<td>' . htmlspecialchars($exportUser['email']) . '</td>';
        echo '<td>' . htmlspecialchars($exportUser['phone'] ?? '-') . '</td>';
        echo '<td>' . htmlspecialchars(getRoleLabel($exportUser['role'])) . '</td>';
        echo '<td>' . ($exportUser['is_active'] ? 'Aktif' : 'Nonaktif') . '</td>';
        echo '<td>' . date('d-m-Y H:i', strtotime($exportUser['created_at'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p style="margin-top:20px;font-size:12px;color:#777;">* Data diekspor dari CRM PT Ganda Elang Tangguh.</p>';
    echo '</body></html>';
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
    $where .= " AND (username LIKE ? OR email LIKE ? OR full_name LIKE ? OR phone LIKE ?)";
    $params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// Get total data
$countSql = "SELECT COUNT(*) FROM users $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalData = $stmt->fetchColumn();
$totalPages = ceil($totalData / $limit);

// Get data
$sql = "SELECT id, username, email, full_name, phone, role, is_active, created_at FROM users $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// Proses tambah user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add') {
        // Cek permission tambah
        if (!canAdd('data_user')) {
            setFlash('Anda tidak memiliki akses untuk menambah user!', 'danger');
            redirect('data_user.php');
        }
        
        $username = bersihkan($_POST['username']);
        $email = bersihkan($_POST['email']);
        $full_name = bersihkan($_POST['full_name']);
        $phone = bersihkan($_POST['phone']);
        $password = $_POST['password'];
        $role_name = bersihkan($_POST['role_name']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validasi
        $errors = [];
        if (empty($username)) $errors[] = 'Username wajib diisi!';
        if (empty($email)) $errors[] = 'Email wajib diisi!';
        if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi!';
        if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter!';
        if (empty($role_name)) $errors[] = 'Divisi wajib dipilih!';
        
        // Cek username/email sudah ada
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Username atau email sudah terdaftar!';
        }
        
        if (empty($errors)) {
            $hash = hashPassword($password);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash, full_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$username, $email, $hash, $full_name, $phone, $role_name, $is_active]);
            
            setFlash('User berhasil ditambahkan!', 'success');
            redirect('data_user.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'edit') {
        // Cek permission edit
        if (!canEdit('data_user')) {
            setFlash('Anda tidak memiliki akses untuk mengedit user!', 'danger');
            redirect('data_user.php');
        }
        
        $id = (int)$_POST['id'];
        $username = bersihkan($_POST['username']);
        $email = bersihkan($_POST['email']);
        $full_name = bersihkan($_POST['full_name']);
        $phone = bersihkan($_POST['phone']);
        $role_name = bersihkan($_POST['role_name']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $password = $_POST['password'];
        
        $errors = [];
        if (empty($username)) $errors[] = 'Username wajib diisi!';
        if (empty($email)) $errors[] = 'Email wajib diisi!';
        if (empty($full_name)) $errors[] = 'Nama lengkap wajib diisi!';
        if (empty($role_name)) $errors[] = 'Divisi wajib dipilih!';
        
        // Cek username/email sudah ada (kecuali dirinya sendiri)
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id != ?");
        $stmt->execute([$username, $email, $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Username atau email sudah digunakan oleh user lain!';
        }
        
        if (empty($errors)) {
            if (!empty($password)) {
                $hash = hashPassword($password);
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, password_hash = ?, full_name = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$username, $email, $hash, $full_name, $phone, $role_name, $is_active, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET username = ?, email = ?, full_name = ?, phone = ?, role = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$username, $email, $full_name, $phone, $role_name, $is_active, $id]);
            }
            
            setFlash('User berhasil diupdate!', 'success');
            redirect('data_user.php');
        } else {
            setFlash(implode('<br>', $errors), 'danger');
        }
    }
    
    if ($action === 'delete') {
        // Cek permission delete
        if (!canDelete('data_user')) {
            setFlash('Anda tidak memiliki akses untuk menghapus user!', 'danger');
            redirect('data_user.php');
        }
        
        $id = (int)$_POST['id'];
        // Cek jangan hapus user utama
        if ($id == 1) {
            setFlash('Tidak dapat menghapus user utama!', 'danger');
            redirect('data_user.php');
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        setFlash('User berhasil dihapus!', 'success');
        redirect('data_user.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Data User - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    <link rel="stylesheet" href="css/data_user.css">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
</head>
<body class="page-data-user">

    <div class="app">
        <?php require_once 'navigation.php'; ?>

        <main class="content">

        
        <!-- HEADER — SAME VISUAL SYSTEM AS SALES ACTIVITY -->
        <div class="page-header">
            <div class="page-title">
                <h4><span><i class="fas fa-users"></i></span> Data User</h4>
            </div>
            <div class="header-actions">
                <a href="data_user.php?export=excel<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn-export">
                    <i class="fas fa-file-excel me-2"></i>Export Excel
                </a>
                <?php if (canAdd('data_user')): ?>
                    <button class="btn-add" data-bs-toggle="modal" data-bs-target="#modalUser">
                        <i class="fas fa-plus me-2"></i>Tambah User
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar User</h6>
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                    <input type="text" name="search" class="form-control form-control-sm search-input" style="width: 240px;" placeholder="Cari user..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn-primary-custom btn-search"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="data_user.php" class="btn-secondary-custom btn-search btn-clear"><i class="fas fa-times"></i></a>
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
                                <th>Username</th>
                                <th>Nama Lengkap</th>
                                <th>Email</th>
                                <th>No HP</th>
                                <th>Divisi</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) > 0): ?>
                                <?php $no = $offset + 1; ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                        <td><?= htmlspecialchars($user['full_name']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= htmlspecialchars($user['phone'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge-role <?= htmlspecialchars($user['role']) ?>">
                                                <?= getRoleLabel($user['role']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-status <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                                <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <?php if (canEdit('data_user')): ?>
                                                    <button class="btn-action edit" onclick="editUser(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (canManageUser()): ?>
                                                    <button class="btn-action permission" onclick="showPermission(<?= htmlspecialchars(json_encode($user)) ?>)">
                                                        <i class="fas fa-lock"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <?php if (canDelete('data_user') && $user['id'] != 1): ?>
                                                    <button class="btn-action delete" onclick="deleteUser(<?= $user['id'] ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada data user
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

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - CRM
        </div>

        </main>
    </div>

    <!-- ============================================
    MODAL TAMBAH / EDIT USER
    ============================================ -->
    <div class="modal fade" id="modalUser" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-plus"></i> Tambah User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formUser">
                    <div class="modal-body">
                        <input type="hidden" name="action" id="formAction" value="add">
                        <input type="hidden" name="id" id="formId" value="">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" name="username" id="username" class="form-control" placeholder="Masukkan username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" name="email" id="email" class="form-control" placeholder="user@email.com" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" id="full_name" class="form-control" placeholder="Masukkan nama lengkap" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nomor HP <span class="optional">(Optional)</span></label>
                                <input type="text" name="phone" id="phone" class="form-control" placeholder="08123456789">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Minimal 6 karakter" minlength="6">
                                <small class="text-muted" id="passwordHint">Minimal 6 karakter</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Divisi <span class="text-danger">*</span></label>
                                <select name="role_name" id="role_name" class="form-select" required>
                                    <option value="">Pilih Divisi</option>
                                    <option value="it_support">IT Support</option>
                                    <option value="admin">Admin</option>
                                    <option value="finance">Finance</option>
                                    <option value="business">Business</option>
                                    <option value="direktur_utama">Direktur Utama</option>
                                    <option value="direktur_sales">Direktur Sales</option>
                                    <option value="direktur_operasional">Direktur Operasional</option>
                                    <option value="sales_manager">Sales Manager</option>
                                    <option value="sales">Sales</option>
                                    <option value="service_support">Service Support</option>
                                    <option value="part_support">Part Support</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="is_active" class="form-check-input" checked>
                                <label class="form-check-label" for="is_active">Aktif</label>
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

    <!-- ============================================
    MODAL PERMISSION - HANYA MENU UTAMA
    ============================================ -->
    <div class="modal fade" id="modalPermission" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-lock"></i> Atur Akses Menu Utama</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="permissionBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Memuat data...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary-custom" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary-custom" onclick="savePermission()"><i class="fas fa-save"></i> Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================
    MODAL DELETE
    ============================================ -->
    <div class="modal fade" id="modalDelete" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash text-danger"></i> Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Apakah Anda yakin ingin menghapus user ini?</p>
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

    <!-- SCRIPTS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentUserId = null;
        let currentUserRole = null;
        
        // Edit User
        function editUser(data) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit User';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('formId').value = data.id;
            document.getElementById('username').value = data.username;
            document.getElementById('email').value = data.email;
            document.getElementById('full_name').value = data.full_name;
            document.getElementById('phone').value = data.phone || '';
            document.getElementById('role_name').value = data.role;
            document.getElementById('is_active').checked = data.is_active == 1;
            
            document.getElementById('password').required = false;
            document.getElementById('password').placeholder = 'Kosongkan jika tidak diubah';
            document.getElementById('passwordHint').textContent = 'Kosongkan jika tidak ingin mengubah password';
            
            var modal = new bootstrap.Modal(document.getElementById('modalUser'));
            modal.show();
        }
        
        document.getElementById('modalUser').addEventListener('hidden.bs.modal', function() {
            document.getElementById('formUser').reset();
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus"></i> Tambah User';
            document.getElementById('password').required = true;
            document.getElementById('password').placeholder = 'Minimal 6 karakter';
            document.getElementById('passwordHint').textContent = 'Minimal 6 karakter';
        });
        
        function deleteUser(id) {
            document.getElementById('deleteId').value = id;
            var modal = new bootstrap.Modal(document.getElementById('modalDelete'));
            modal.show();
        }
        
        function showPermission(data) {
            currentUserId = data.id;
            currentUserRole = data.role;
            
            var modal = new bootstrap.Modal(document.getElementById('modalPermission'));
            modal.show();
            
            document.getElementById('permissionBody').innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Memuat data...</p>
                </div>
            `;
            
            fetch('api/get_permission.php?user_id=' + data.id)
                .then(response => response.json())
                .then(data => {
                    var html = '';
                    if (data.modules && data.modules.length > 0) {
                        html = '<p class="text-muted mb-3">Atur akses menu utama untuk divisi <strong>' + data.role + '</strong></p>';
                        html += '<p class="text-warning small"><i class="fas fa-info-circle"></i> Centang menu yang ingin ditampilkan di dashboard</p>';
                        html += '<div class="table-responsive">';
                        html += '<table class="table table-bordered table-sm">';
                        html += '<thead><tr><th>Menu Utama</th><th>Tampil di Dashboard</th></tr></thead>';
                        html += '<tbody>';
                        data.modules.forEach(function(module) {
                            var checked = module.can_view == 1 ? 'checked' : '';
                            html += '<tr>';
                            html += '<td><strong>' + module.module_label + '</strong></td>';
                            html += '<td>';
                            html += '<input type="checkbox" class="perm-check form-check-input" data-module="' + module.module_name + '" ' + checked + '>';
                            html += '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                    } else {
                        html = '<div class="text-center py-4"><i class="fas fa-inbox fa-3x text-muted mb-3"></i><p>Belum ada data menu utama</p></div>';
                    }
                    document.getElementById('permissionBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('permissionBody').innerHTML = '<div class="text-center py-4 text-danger">Gagal memuat data!</div>';
                });
        }
        
        function savePermission() {
            var permissions = [];
            document.querySelectorAll('.perm-check').forEach(function(checkbox) {
                var module = checkbox.dataset.module;
                var checked = checkbox.checked ? 1 : 0;
                permissions.push({module: module, value: checked});
            });
            
            fetch('api/save_permission.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    role_name: currentUserRole,
                    permissions: permissions
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Permission berhasil disimpan!');
                    var modal = bootstrap.Modal.getInstance(document.getElementById('modalPermission'));
                    modal.hide();
                    location.reload();
                } else {
                    alert('Gagal menyimpan permission: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                alert('Terjadi kesalahan!');
            });
        }
    </script>
</body>
</html>