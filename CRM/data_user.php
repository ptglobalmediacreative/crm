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
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
/* =========================================================
   DATA USER — CRM MODULE
   Visual language aligned with dashboard + shared navigation.
   ========================================================= */

.content{
    max-width: 100%;
}

.page-header{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:20px;
    margin-bottom:24px;
    padding-top:4px;
}

.page-title{display:flex;align-items:center;gap:14px;min-width:0}
.page-title-icon{
    width:46px;height:46px;flex:0 0 46px;border-radius:13px;
    display:flex;align-items:center;justify-content:center;
    color:#60a5fa;background:rgba(59,130,246,.10);
    border:1px solid rgba(96,165,250,.16);
    box-shadow:0 8px 24px rgba(0,0,0,.10);
}
.page-title-icon i{font-size:17px}
.eyebrow{
    color:#64748b;font-size:9px;font-weight:800;letter-spacing:1.5px;
    text-transform:uppercase;margin-bottom:5px;
}
.page-title h1{margin:0;color:#eef4ff;font-size:25px;font-weight:800;letter-spacing:-.7px}
.page-title p{margin:4px 0 0;color:#687892;font-size:11px}

.stat-grid{display:grid;grid-template-columns:minmax(210px,260px);gap:14px;margin-bottom:18px}
.stat-card{
    position:relative;overflow:hidden;background:linear-gradient(145deg,#0d1729,#0a1120);
    border:1px solid rgba(148,163,184,.14);border-radius:15px;padding:17px 18px;
    box-shadow:0 10px 28px rgba(0,0,0,.13);transition:.2s;
}
.stat-card::after{content:"";position:absolute;right:-30px;bottom:-45px;width:110px;height:110px;border-radius:50%;background:rgba(96,165,250,.06)}
.stat-card:hover{transform:translateY(-2px);border-color:rgba(96,165,250,.28)}
.stat-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;margin-bottom:11px}
.stat-icon.gold{background:rgba(251,191,36,.10);color:#fbbf24;border:1px solid rgba(251,191,36,.12)}
.stat-number{font-size:24px;font-weight:800;color:#f2f6ff;line-height:1.1}
.stat-label{font-size:10px;color:#6f7e96;margin-top:4px}

.card-custom{
    background:#0a1220;border:1px solid rgba(148,163,184,.13);border-radius:15px;
    overflow:hidden;box-shadow:0 12px 30px rgba(0,0,0,.12);
}
.card-header-custom{
    min-height:68px;padding:14px 18px;border-bottom:1px solid rgba(148,163,184,.10);
    display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;
    background:rgba(13,23,41,.55);
}
.card-header-custom h6{margin:0;color:#eaf1fc;font-size:12px;font-weight:750;letter-spacing:.1px}
.card-header-custom h6 i{color:#60a5fa;margin-right:7px}
.card-header-custom form{margin-left:auto}
.search-input{width:240px!important;min-height:34px!important}
.btn-search{height:34px!important;min-width:36px;padding:6px 11px!important;display:inline-flex;align-items:center;justify-content:center}
.btn-clear{color:#a9b5c8!important}

.card-body-custom{padding:0;overflow-x:auto}
.table-responsive{overflow-x:auto}
.table-custom{margin:0!important;width:100%;font-size:11px;color:#aeb9cb}
.table-custom th{
    padding:12px 14px!important;background:#0c1626!important;color:#61708a!important;
    border-bottom:1px solid rgba(148,163,184,.10)!important;border-top:0!important;
    font-size:9px!important;font-weight:800!important;letter-spacing:1px;text-transform:uppercase;
    white-space:nowrap;
}
.table-custom td{
    padding:13px 14px!important;border-bottom:1px solid rgba(148,163,184,.07)!important;
    color:#aeb9cb!important;vertical-align:middle;background:transparent!important;
}
.table-custom tbody tr{transition:.16s}
.table-custom tbody tr:hover td{background:rgba(59,130,246,.035)!important;color:#d7e1ef!important}
.table-custom tbody tr:last-child td{border-bottom:0!important}
.table-custom td strong{color:#e5edf8;font-weight:700}

.badge-role,.badge-status{display:inline-flex;align-items:center;min-height:22px;padding:3px 9px;border-radius:999px;font-size:9px;font-weight:700;white-space:nowrap;border:1px solid transparent}
.badge-role.it_support{background:rgba(167,139,250,.10);color:#b9a4ff;border-color:rgba(167,139,250,.14)}
.badge-role.admin{background:rgba(96,165,250,.10);color:#8bc1ff;border-color:rgba(96,165,250,.14)}
.badge-role.finance{background:rgba(34,211,238,.10);color:#73e4f2;border-color:rgba(34,211,238,.14)}
.badge-role.direktur_sales,.badge-role.direktur_operasional,.badge-role.service_support{background:rgba(45,212,191,.09);color:#6ee7d2;border-color:rgba(45,212,191,.13)}
.badge-role.direktur_utama{background:rgba(251,191,36,.10);color:#f6cf63;border-color:rgba(251,191,36,.14)}
.badge-role.business,.badge-role.sales_manager{background:rgba(148,163,184,.09);color:#b6c1d1;border-color:rgba(148,163,184,.12)}
.badge-role.sales{background:rgba(52,211,153,.09);color:#69e5b0;border-color:rgba(52,211,153,.13)}
.badge-role.part_support{background:rgba(251,146,60,.09);color:#f8b37c;border-color:rgba(251,146,60,.13)}
.badge-status.active{background:rgba(52,211,153,.09);color:#67dfaa;border-color:rgba(52,211,153,.13)}
.badge-status.inactive{background:rgba(251,113,133,.09);color:#ff8da0;border-color:rgba(251,113,133,.13)}

.btn-action{width:30px;height:30px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;cursor:pointer;transition:.18s;font-size:11px}
.btn-action:hover{transform:translateY(-1px);filter:brightness(1.08)}
.btn-action.edit{background:rgba(96,165,250,.09);color:#78b7ff;border-color:rgba(96,165,250,.13)}
.btn-action.delete{background:rgba(251,113,133,.08);color:#ff8297;border-color:rgba(251,113,133,.12)}
.btn-action.permission{background:rgba(167,139,250,.09);color:#b39aff;border-color:rgba(167,139,250,.13)}

.btn-primary-custom{background:#2563eb!important;color:#fff!important;border:1px solid #3b82f6!important;border-radius:9px!important;font-size:11px!important;font-weight:700!important;padding:9px 15px!important;transition:.18s!important}
.btn-primary-custom:hover{background:#1d4ed8!important;transform:translateY(-1px);box-shadow:0 8px 20px rgba(37,99,235,.20)}
.btn-secondary-custom{background:#111b2d!important;color:#9aa8bd!important;border:1px solid rgba(148,163,184,.14)!important;border-radius:9px!important;font-size:11px!important;font-weight:700!important;padding:9px 15px!important}
.btn-secondary-custom:hover{background:#172238!important;color:#d6deea!important}

.pagination{margin:0!important}
.pagination .page-link{background:#0d1727;color:#8998ae;border:1px solid rgba(148,163,184,.10);font-size:10px;padding:6px 9px}
.pagination .page-link:hover{background:#14213a;color:#fff}
.pagination .page-item.active .page-link{background:#2563eb;border-color:#3b82f6;color:#fff}
.card-footer{background:rgba(10,18,32,.75)!important;border-color:rgba(148,163,184,.08)!important}

.alert{border-radius:9px!important;border:1px solid rgba(148,163,184,.10)!important;font-size:11px!important;margin:12px 14px!important}
.footer-text{text-align:center;padding:16px 0 8px;color:#4f5e74;font-size:9px}
.footer-text a{color:#71819a;text-decoration:none}.footer-text a:hover{color:#60a5fa}

.modal-content{background:#0b1423!important;color:#b7c3d4;border:1px solid rgba(148,163,184,.14)!important;border-radius:14px!important;box-shadow:0 25px 70px rgba(0,0,0,.45)}
.modal-header,.modal-footer{border-color:rgba(148,163,184,.10)!important}
.modal-header{padding:16px 20px}.modal-body{padding:20px}.modal-footer{padding:13px 20px}
.modal-header .modal-title{font-size:14px;font-weight:750;color:#eaf1fc}.modal-header .modal-title i{color:#60a5fa;margin-right:7px}
.btn-close{filter:invert(1);opacity:.55}.btn-close:hover{opacity:.9}
.form-label{font-size:10px!important;font-weight:700!important;color:#93a2b8!important;margin-bottom:6px!important}.form-label .optional{color:#5e6c82!important}
.form-control,.form-select{background:#0a1220!important;color:#dbe5f2!important;border:1px solid rgba(148,163,184,.15)!important;border-radius:8px!important;font-size:11px!important;padding:9px 11px!important}
.form-control::placeholder{color:#53627a!important}.form-control:focus,.form-select:focus{border-color:rgba(96,165,250,.55)!important;box-shadow:0 0 0 3px rgba(59,130,246,.10)!important}
.form-select option{background:#0b1423;color:#dbe5f2}
.form-check-input{background-color:#0a1220;border-color:rgba(148,163,184,.25)}.form-check-input:checked{background-color:#2563eb;border-color:#3b82f6}.form-check-label{font-size:11px;color:#aab6c8}

#permissionBody .table{color:#aeb9cb}#permissionBody .table th{background:#0d1727;color:#71809a;border-color:rgba(148,163,184,.10);font-size:9px}#permissionBody .table td{background:transparent;color:#aeb9cb;border-color:rgba(148,163,184,.08);font-size:11px}
#permissionBody .text-muted{color:#71809a!important}#permissionBody .text-warning{color:#f6c85f!important}

@media(max-width:800px){
    .page-header{align-items:flex-start;flex-direction:column}
    .page-header>.btn{width:100%}
    .card-header-custom{align-items:stretch}
    .card-header-custom form{width:100%;margin:0;display:flex}
    .search-input{width:100%!important;flex:1}
}
@media(max-width:560px){
    .page-title h1{font-size:21px}.page-title p{font-size:10px}
    .page-title-icon{width:40px;height:40px;flex-basis:40px}
    .stat-grid{grid-template-columns:1fr}.stat-card{padding:14px}
    .table-custom{min-width:780px}
    .modal-dialog{margin:10px}
}

    </style>

</head>
<body>

    <div class="app">
        <?php require_once 'navigation.php'; ?>

        <main class="content">

        
        <!-- HEADER -->
        <div class="page-header">
            <div class="page-title">
                <div class="page-title-icon"><i class="fas fa-users"></i></div>
                <div>
                    <div class="eyebrow">ADMINISTRATION · USER MANAGEMENT</div>
                    <h1>Data User</h1>
                    <p>Kelola akun, divisi, status pengguna, dan akses menu CRM.</p>
                </div>
            </div>
            <?php if (canAdd('data_user')): ?>
                <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalUser">
                    <i class="fas fa-plus"></i> Tambah User
                </button>
            <?php endif; ?>
        </div>

        <!-- STATISTIK -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= number_format($totalData) ?></div>
                <div class="stat-label">Total User</div>
            </div>
        </div>

        <!-- TABLE -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-list"></i> Daftar User</h6>
                <form method="GET" class="d-flex gap-2">
                    <input type="text" name="search" class="form-control form-control-sm search-input" placeholder="Cari user..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-primary-custom btn-search"><i class="fas fa-search"></i></button>
                    <?php if (!empty($search)): ?>
                        <a href="data_user.php" class="btn btn-secondary-custom btn-search btn-clear"><i class="fas fa-times"></i></a>
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