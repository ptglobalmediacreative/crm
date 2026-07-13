<?php
require_once 'config.php';

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// Ambil data statistik
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalActive = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
$totalAdmin = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$recentUsers = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 5")->fetchAll();

$today = date('Y-m-d');
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE DATE(created_at) = ?");
$stmt->execute([$today]);
$newToday = $stmt->fetchColumn();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }
        
        /* ===== TOP NAVBAR ===== */
        .navbar-top {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 0 30px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.2);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-top .navbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            text-decoration: none;
        }
        
        .navbar-top .navbar-brand .brand-logo {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.1);
            border: 2px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .navbar-top .navbar-brand .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .navbar-top .navbar-brand .brand-text {
            color: #fff;
        }
        
        .navbar-top .navbar-brand .brand-text .brand-name {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .navbar-top .navbar-brand .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .navbar-top .navbar-brand .brand-text .brand-sub {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.5);
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        
        /* ===== NAVBAR MENU ===== */
        .navbar-top .nav-menu {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        
        .navbar-top .nav-menu .nav-item .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 20px 18px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            position: relative;
        }
        
        .navbar-top .nav-menu .nav-item .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .navbar-top .nav-menu .nav-item .nav-link.active {
            color: #ffd700;
            border-bottom-color: #ffd700;
        }
        
        .navbar-top .nav-menu .nav-item .nav-link i {
            font-size: 16px;
        }
        
        .navbar-top .nav-menu .nav-item .nav-link .badge-nav {
            background: #d63031;
            color: #fff;
            font-size: 10px;
            padding: 1px 7px;
            border-radius: 20px;
            margin-left: 4px;
        }
        
        /* ===== NAVBAR RIGHT ===== */
        .navbar-top .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .navbar-top .navbar-right .notification {
            position: relative;
            cursor: pointer;
            color: rgba(255, 255, 255, 0.6);
            transition: color 0.3s ease;
        }
        
        .navbar-top .navbar-right .notification:hover {
            color: #fff;
        }
        
        .navbar-top .navbar-right .notification i {
            font-size: 20px;
        }
        
        .navbar-top .navbar-right .notification .badge-notif {
            position: absolute;
            top: -8px;
            right: -8px;
            background: #d63031;
            color: #fff;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 50%;
            min-width: 18px;
            text-align: center;
        }
        
        .navbar-top .navbar-right .user-dropdown {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 10px;
            transition: background 0.3s ease;
            text-decoration: none;
        }
        
        .navbar-top .navbar-right .user-dropdown:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .navbar-top .navbar-right .user-dropdown .avatar-sm {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 16px;
        }
        
        .navbar-top .navbar-right .user-dropdown .user-info {
            text-align: right;
        }
        
        .navbar-top .navbar-right .user-dropdown .user-info .name {
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            line-height: 1.2;
        }
        
        .navbar-top .navbar-right .user-dropdown .user-info .role {
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
        }
        
        .navbar-top .navbar-right .user-dropdown .dropdown-arrow {
            color: rgba(255, 255, 255, 0.3);
            font-size: 12px;
            margin-left: 4px;
        }
        
        /* ===== MOBILE TOGGLE ===== */
        .navbar-top .navbar-toggler {
            background: none;
            border: none;
            color: #fff;
            font-size: 24px;
            padding: 8px;
            cursor: pointer;
            display: none;
        }
        
        .navbar-top .navbar-toggler:hover {
            color: #ffd700;
        }
        
        /* ===== MAIN CONTENT ===== */
        .main-content {
            padding: 0;
            min-height: 100vh;
        }
        
        .content {
            padding: 30px;
        }
        
        /* ===== STATISTIC CARDS ===== */
        .stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px 25px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border-left: 4px solid #ffd700;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 12px;
        }
        
        .stat-card .stat-icon.primary {
            background: rgba(26, 26, 46, 0.1);
            color: #1a1a2e;
        }
        
        .stat-card .stat-icon.success {
            background: rgba(46, 213, 115, 0.1);
            color: #2ed573;
        }
        
        .stat-card .stat-icon.warning {
            background: rgba(255, 215, 0, 0.15);
            color: #ffd700;
        }
        
        .stat-card .stat-icon.danger {
            background: rgba(214, 48, 49, 0.1);
            color: #d63031;
        }
        
        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 5px 0;
        }
        
        .stat-card .stat-label {
            font-size: 14px;
            color: #888;
            font-weight: 500;
        }
        
        .stat-card .stat-change {
            font-size: 12px;
            margin-top: 8px;
        }
        
        .stat-card .stat-change.positive {
            color: #2ed573;
        }
        
        /* ===== TABLES ===== */
        .card-custom {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: none;
        }
        
        .card-custom .card-header {
            background: transparent;
            border-bottom: 1px solid #f0f2f5;
            padding: 18px 25px;
            font-weight: 600;
        }
        
        .card-custom .card-body {
            padding: 20px 25px;
        }
        
        .table-custom {
            margin-bottom: 0;
        }
        
        .table-custom th {
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            border-bottom: 2px solid #f0f2f5;
            padding: 12px 15px;
        }
        
        .table-custom td {
            padding: 12px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .table-custom tr:hover {
            background: #f8f9fa;
        }
        
        .badge-role {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-role.admin {
            background: rgba(214, 48, 49, 0.1);
            color: #d63031;
        }
        
        .badge-role.staff {
            background: rgba(255, 215, 0, 0.15);
            color: #b7950b;
        }
        
        .badge-role.user {
            background: rgba(26, 26, 46, 0.08);
            color: #1a1a2e;
        }
        
        .badge-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-status.active {
            background: rgba(46, 213, 115, 0.15);
            color: #2ed573;
        }
        
        .badge-status.inactive {
            background: rgba(214, 48, 49, 0.1);
            color: #d63031;
        }
        
        /* ===== WELCOME BANNER ===== */
        .welcome-banner {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 12px;
            padding: 30px 35px;
            color: #fff;
            margin-bottom: 25px;
        }
        
        .welcome-banner h3 {
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .welcome-banner h3 span {
            color: #ffd700;
        }
        
        .welcome-banner p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            margin: 0;
        }
        
        .welcome-banner .welcome-icon {
            font-size: 50px;
            color: rgba(255, 215, 0, 0.2);
        }
        
        /* ===== FOOTER ===== */
        .footer-text {
            text-align: center;
            padding: 20px 0;
            color: #888;
            font-size: 13px;
        }
        
        .footer-text a {
            color: #16213e;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            color: #ffd700;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            .navbar-top {
                padding: 0 15px;
                position: relative;
            }
            
            .navbar-top .navbar-toggler {
                display: block;
            }
            
            .navbar-top .nav-menu {
                display: none;
                flex-direction: column;
                width: 100%;
                padding: 10px 0;
                background: rgba(26, 26, 46, 0.95);
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                border-top: 1px solid rgba(255, 255, 255, 0.05);
            }
            
            .navbar-top .nav-menu.show {
                display: flex;
            }
            
            .navbar-top .nav-menu .nav-item .nav-link {
                padding: 12px 20px;
                border-bottom: none;
                border-left: 3px solid transparent;
            }
            
            .navbar-top .nav-menu .nav-item .nav-link.active {
                border-bottom: none;
                border-left-color: #ffd700;
                background: rgba(255, 215, 0, 0.05);
            }
            
            .navbar-top .nav-menu .nav-item .nav-link .badge-nav {
                margin-left: auto;
            }
            
            .navbar-top .navbar-right .user-dropdown .user-info {
                display: none;
            }
            
            .navbar-top .navbar-right .user-dropdown .dropdown-arrow {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .content {
                padding: 15px;
            }
            
            .stat-card .stat-number {
                font-size: 22px;
            }
            
            .welcome-banner {
                padding: 20px;
            }
            
            .welcome-banner h3 {
                font-size: 20px;
            }
            
            .welcome-banner .welcome-icon {
                display: none;
            }
            
            .navbar-top .navbar-brand .brand-text .brand-name {
                font-size: 14px;
            }
            
            .navbar-top .navbar-brand .brand-text .brand-sub {
                font-size: 9px;
            }
            
            .navbar-top .navbar-brand .brand-logo {
                width: 38px;
                height: 38px;
            }
        }
        
        @media (max-width: 576px) {
            .navbar-top .navbar-right .notification .badge-notif {
                min-width: 16px;
                font-size: 9px;
                top: -6px;
                right: -6px;
            }
            
            .stat-card {
                padding: 15px 18px;
            }
            
            .stat-card .stat-number {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    
    <!-- ===== TOP NAVBAR ===== -->
    <nav class="navbar-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center justify-content-between w-100">
                
                <!-- Left: Brand -->
                <a href="dashboard.php" class="navbar-brand">
                    <div class="brand-logo">
                        <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
                    </div>
                    <div class="brand-text">
                        <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                        <div class="brand-sub">Dealer Management System</div>
                    </div>
                </a>
                
                <!-- Center: Menu (Desktop) -->
                <ul class="nav-menu" id="navMenu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link active">
                            <i class="fas fa-th-large"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fas fa-users"></i> Users
                            <span class="badge-nav"><?= $totalUsers ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fas fa-box"></i> Produk
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fas fa-truck"></i> Supplier
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fas fa-shopping-cart"></i> Transaksi
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    </li>
                </ul>
                
                <!-- Right: Notif & User -->
                <div class="navbar-right">
                    <div class="notification">
                        <i class="fas fa-bell"></i>
                        <span class="badge-notif">3</span>
                    </div>
                    
                    <a href="logout.php" class="user-dropdown text-decoration-none">
                        <div class="user-info">
                            <div class="name"><?= htmlspecialchars($fullName) ?></div>
                            <div class="role"><?= ucfirst($role) ?></div>
                        </div>
                        <div class="avatar-sm">
                            <?= strtoupper(substr($fullName, 0, 1)) ?>
                        </div>
                        <span class="dropdown-arrow">
                            <i class="fas fa-chevron-down"></i>
                        </span>
                    </a>
                    
                    <!-- Mobile Toggle -->
                    <button class="navbar-toggler" id="navToggler">
                        <i class="fas fa-bars"></i>
                    </button>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- ===== MAIN CONTENT ===== -->
    <main class="main-content">
        <div class="content">
            
            <!-- ===== WELCOME BANNER ===== -->
            <div class="welcome-banner">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h3>Selamat datang, <span><?= htmlspecialchars($fullName) ?></span>! 👋</h3>
                        <p>Selamat datang di sistem manajemen PT Ganda Elang Tangguh. Kelola data dan aktivitas Anda dengan mudah.</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <i class="fas fa-hard-hat welcome-icon"></i>
                    </div>
                </div>
            </div>
            
            <!-- ===== STATISTIK ===== -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-number"><?= number_format($totalUsers) ?></div>
                        <div class="stat-label">Total Users</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i> +<?= $newToday ?> hari ini
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" style="border-left-color: #2ed573;">
                        <div class="stat-icon success">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="stat-number"><?= number_format($totalActive) ?></div>
                        <div class="stat-label">Active Users</div>
                        <div class="stat-change positive">
                            <i class="fas fa-check-circle"></i> Aktif
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" style="border-left-color: #ffd700;">
                        <div class="stat-icon warning">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-number"><?= number_format($totalAdmin) ?></div>
                        <div class="stat-label">Total Admin</div>
                        <div class="stat-change">
                            <i class="fas fa-crown"></i> Administrator
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-6 col-md-6">
                    <div class="stat-card" style="border-left-color: #d63031;">
                        <div class="stat-icon danger">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-number"><?= date('H:i') ?></div>
                        <div class="stat-label">Waktu Sekarang</div>
                        <div class="stat-change">
                            <i class="fas fa-calendar"></i> <?= date('d M Y') ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- ===== TABEL USER TERBARU ===== -->
            <div class="card-custom">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-user-plus me-2" style="color: #ffd700;"></i>User Terbaru</span>
                        <a href="#" class="btn btn-sm btn-outline-primary">Lihat Semua</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Bergabung</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($recentUsers) > 0): ?>
                                    <?php $no = 1; ?>
                                    <?php foreach ($recentUsers as $user): ?>
                                        <tr>
                                            <td><?= $no++ ?></td>
                                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                            <td><?= htmlspecialchars($user['email']) ?></td>
                                            <td>
                                                <span class="badge-role <?= $user['role'] ?>">
                                                    <?= ucfirst($user['role']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge-status <?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                                    <?= $user['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                                                </span>
                                            </td>
                                            <td><?= date('d/m/Y H:i', strtotime($user['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted">
                                            <i class="fas fa-inbox me-2"></i> Belum ada user
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ===== FOOTER ===== -->
            <div class="footer-text">
                &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - Dealer Management System v1.0
                <br>
                <small>Powered by Global Media Creative</small>
            </div>
            
        </div>
    </main>
    
    <!-- ===== SCRIPTS ===== -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Mobile Menu
        const navToggler = document.getElementById('navToggler');
        const navMenu = document.getElementById('navMenu');
        
        navToggler.addEventListener('click', function() {
            navMenu.classList.toggle('show');
        });
        
        // Close menu on link click (mobile)
        document.querySelectorAll('.nav-menu .nav-link').forEach(function(link) {
            link.addEventListener('click', function() {
                navMenu.classList.remove('show');
            });
        });
    </script>
</body>
</html>