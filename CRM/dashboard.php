<?php
require_once 'config.php';

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// Ambil data untuk badge (opsional)
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            padding-bottom: 80px;
        }
        
        /* ============================================
           TOP HEADER
           ============================================ */
        .top-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 14px 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .top-header .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .top-header .header-left .logo-wrapper {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.1);
            border: 2px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .top-header .header-left .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .top-header .header-left .brand-text .brand-name {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        
        .top-header .header-left .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .top-header .header-left .brand-text .brand-sub {
            font-size: 8px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .top-header .header-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .top-header .header-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 17px;
            cursor: pointer;
        }
        
        .top-header .header-right .notif-icon .badge-notif {
            position: absolute;
            top: -6px;
            right: -8px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .top-header .header-right .user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 14px;
            text-decoration: none;
            border: 2px solid rgba(255, 215, 0, 0.2);
            transition: border-color 0.3s ease;
        }
        
        .top-header .header-right .user-avatar:hover {
            border-color: #ffd700;
        }
        
        /* ============================================
           WELCOME BANNER
           ============================================ */
        .welcome-banner {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border-radius: 14px;
            padding: 20px 24px;
            color: #fff;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner .welcome-text .greeting {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            font-weight: 400;
        }
        
        .welcome-banner .welcome-text h3 {
            font-weight: 700;
            font-size: 20px;
            margin: 2px 0 0;
        }
        
        .welcome-banner .welcome-text h3 span {
            color: #ffd700;
        }
        
        .welcome-banner .welcome-icon {
            font-size: 40px;
            color: rgba(255, 215, 0, 0.06);
            position: absolute;
            right: 15px;
            bottom: 10px;
        }
        
        /* ============================================
           SECTION TITLE
           ============================================ */
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }
        
        .section-title h5 {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 16px;
            margin: 0;
        }
        
        .section-title h5 i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .section-title .see-all {
            font-size: 12px;
            color: #888;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .section-title .see-all:hover {
            color: #ffd700;
        }
        
        /* ============================================
           MENU GRID - SEPERTI GAMBAR
           ============================================ */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        
        .menu-card {
            background: #fff;
            border-radius: 14px;
            padding: 20px 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .menu-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            border-color: #ffd700;
        }
        
        .menu-card .menu-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .menu-card .menu-icon.orange {
            background: rgba(255, 165, 0, 0.12);
            color: #e67e22;
        }
        
        .menu-card .menu-icon.blue {
            background: rgba(26, 26, 46, 0.08);
            color: #1a1a2e;
        }
        
        .menu-card .menu-icon.green {
            background: rgba(46, 213, 115, 0.12);
            color: #2ed573;
        }
        
        .menu-card .menu-icon.gold {
            background: rgba(255, 215, 0, 0.15);
            color: #ffd700;
        }
        
        .menu-card .menu-icon.purple {
            background: rgba(155, 89, 182, 0.12);
            color: #8e44ad;
        }
        
        .menu-card .menu-icon.red {
            background: rgba(214, 48, 49, 0.1);
            color: #d63031;
        }
        
        .menu-card .menu-icon.teal {
            background: rgba(0, 206, 209, 0.12);
            color: #16a085;
        }
        
        .menu-card .menu-icon.pink {
            background: rgba(233, 30, 99, 0.1);
            color: #e91e63;
        }
        
        .menu-card .menu-title {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
            margin: 0;
        }
        
        .menu-card .menu-sub {
            font-size: 11px;
            color: #999;
            margin: 2px 0 0;
        }
        
        .menu-card .menu-badge {
            font-size: 10px;
            background: #ffd700;
            color: #1a1a2e;
            padding: 1px 10px;
            border-radius: 20px;
            font-weight: 600;
            margin-top: 6px;
        }
        
        /* ============================================
           BOTTOM NAVIGATION - SEPERTI GAMBAR
           ============================================ */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 6px 0 env(safe-area-inset-bottom);
            z-index: 999;
            display: flex;
            justify-content: space-around;
            align-items: center;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.06);
        }
        
        .bottom-nav .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-decoration: none;
            padding: 4px 10px;
            border-radius: 10px;
            transition: all 0.3s ease;
            position: relative;
            min-width: 50px;
        }
        
        .bottom-nav .nav-item .nav-icon {
            font-size: 18px;
            color: #999;
            transition: all 0.3s ease;
        }
        
        .bottom-nav .nav-item .nav-label {
            font-size: 9px;
            color: #999;
            font-weight: 500;
            margin-top: 2px;
            transition: all 0.3s ease;
        }
        
        .bottom-nav .nav-item.active .nav-icon {
            color: #ffd700;
        }
        
        .bottom-nav .nav-item.active .nav-label {
            color: #1a1a2e;
            font-weight: 600;
        }
        
        .bottom-nav .nav-item.active::before {
            content: '';
            position: absolute;
            top: -2px;
            left: 50%;
            transform: translateX(-50%);
            width: 20px;
            height: 3px;
            background: #ffd700;
            border-radius: 0 0 3px 3px;
        }
        
        .bottom-nav .nav-item .badge-nav {
            position: absolute;
            top: 0;
            right: 2px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .bottom-nav .nav-item:hover .nav-icon {
            color: #1a1a2e;
        }
        
        /* ============================================
           DESKTOP NAVBAR
           ============================================ */
        .desktop-nav-wrapper {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .desktop-nav-wrapper .brand-section {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
        }
        
        .desktop-nav-wrapper .brand-section .logo-wrapper {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.1);
            border: 2px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .desktop-nav-wrapper .brand-section .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name {
            font-size: 16px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-sub {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        
        .desktop-nav-wrapper .desktop-menu {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link:hover {
            color: #fff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link.active {
            color: #ffd700;
            background: rgba(255, 215, 0, 0.08);
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link i {
            font-size: 15px;
        }
        
        .desktop-nav-wrapper .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 18px;
            cursor: pointer;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon .badge-notif {
            position: absolute;
            top: -6px;
            right: -8px;
            background: #d63031;
            color: #fff;
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .desktop-nav-wrapper .nav-right .user-info {
            text-align: right;
            color: #fff;
        }
        
        .desktop-nav-wrapper .nav-right .user-info .name {
            font-weight: 600;
            font-size: 14px;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .nav-right .user-info .role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 15px;
            text-decoration: none;
            border: 2px solid rgba(255, 215, 0, 0.2);
            transition: border-color 0.3s ease;
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar:hover {
            border-color: #ffd700;
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        
        /* Desktop */
        @media (min-width: 769px) {
            .bottom-nav {
                display: none !important;
            }
            body {
                padding-bottom: 0;
            }
            .top-header {
                display: none !important;
            }
        }
        
        /* Mobile */
        @media (max-width: 768px) {
            .desktop-nav-wrapper {
                display: none !important;
            }
            body {
                padding-bottom: 70px;
            }
            
            .menu-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            
            .menu-card {
                padding: 16px 10px;
            }
            
            .menu-card .menu-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }
            
            .menu-card .menu-title {
                font-size: 13px;
            }
            
            .welcome-banner {
                padding: 16px 18px;
            }
            
            .welcome-banner .welcome-text h3 {
                font-size: 17px;
            }
            
            .welcome-banner .welcome-icon {
                display: none;
            }
            
            .section-title h5 {
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .menu-grid {
                gap: 10px;
            }
            
            .menu-card {
                padding: 14px 8px;
                border-radius: 12px;
            }
            
            .menu-card .menu-icon {
                width: 42px;
                height: 42px;
                font-size: 18px;
                border-radius: 12px;
            }
            
            .menu-card .menu-title {
                font-size: 12px;
            }
            
            .menu-card .menu-sub {
                font-size: 10px;
            }
            
            .bottom-nav .nav-item .nav-label {
                font-size: 8px;
            }
            
            .bottom-nav .nav-item .nav-icon {
                font-size: 16px;
            }
            
            .top-header .header-left .brand-text .brand-name {
                font-size: 12px;
            }
            
            .top-header .header-left .logo-wrapper {
                width: 32px;
                height: 32px;
            }
        }
        
        @media (min-width: 769px) and (max-width: 992px) {
            .menu-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .footer-text {
            text-align: center;
            padding: 20px 0 10px;
            color: #999;
            font-size: 12px;
        }
        
        .footer-text a {
            color: #16213e;
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            color: #ffd700;
        }
    </style>
</head>
<body>

    <!-- ============================================
    DESKTOP NAVBAR
    ============================================ -->
    <div class="desktop-nav-wrapper">
        <div class="brand-section">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Dealer Management System</div>
            </div>
        </div>
        
        <div class="desktop-menu">
            <a href="dashboard.php" class="nav-link active">
                <i class="fas fa-th-large"></i> Dashboard
            </a>
            <a href="#" class="nav-link">
                <i class="fas fa-users"></i> Users
            </a>
            <a href="#" class="nav-link">
                <i class="fas fa-box"></i> Produk
            </a>
            <a href="#" class="nav-link">
                <i class="fas fa-truck"></i> Supplier
            </a>
            <a href="#" class="nav-link">
                <i class="fas fa-shopping-cart"></i> Transaksi
            </a>
        </div>
        
        <div class="nav-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif">3</span>
            </div>
            <div class="user-info">
                <div class="name"><?= htmlspecialchars($fullName) ?></div>
                <div class="role"><?= ucfirst($role) ?></div>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
        </div>
    </div>

    <!-- ============================================
    MOBILE HEADER
    ============================================ -->
    <header class="top-header">
        <div class="header-left">
            <div class="logo-wrapper">
                <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
            </div>
            <div class="brand-text">
                <div class="brand-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                <div class="brand-sub">Dealer Management</div>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif">3</span>
            </div>
            <a href="logout.php" class="user-avatar">
                <?= strtoupper(substr($fullName, 0, 1)) ?>
            </a>
        </div>
    </header>

    <!-- ============================================
    MAIN CONTENT
    ============================================ -->
    <main style="padding: 16px 16px 0; max-width: 1200px; margin: 0 auto;">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Welcome Back,</div>
                <h3><?= htmlspecialchars($fullName) ?>! 👋</h3>
            </div>
            <i class="fas fa-hard-hat welcome-icon"></i>
        </div>

        <!-- SECTION: MENU UTAMA -->
        <div class="section-title">
            <h5><i class="fas fa-th-large"></i>Menu Utama</h5>
            <a href="#" class="see-all">More <i class="fas fa-chevron-right" style="font-size:10px;"></i></a>
        </div>

        <!-- MENU GRID -->
        <div class="menu-grid">
            <a href="#" class="menu-card">
                <div class="menu-icon orange"><i class="fas fa-box"></i></div>
                <div class="menu-title">Product</div>
                <div class="menu-sub">Kelola produk</div>
                <span class="menu-badge"><?= $totalUsers ?></span>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon blue"><i class="fas fa-users"></i></div>
                <div class="menu-title">Lead</div>
                <div class="menu-sub">Data lead</div>
                <span class="menu-badge">12</span>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon green"><i class="fas fa-building"></i></div>
                <div class="menu-title">Account</div>
                <div class="menu-sub">Management</div>
                <span class="menu-badge">8</span>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon gold"><i class="fas fa-chart-line"></i></div>
                <div class="menu-title">Activity</div>
                <div class="menu-sub">Aktivitas</div>
                <span class="menu-badge">24</span>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon purple"><i class="fas fa-file-invoice"></i></div>
                <div class="menu-title">Report</div>
                <div class="menu-sub">Laporan</div>
                <span class="menu-badge">5</span>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon teal"><i class="fas fa-cog"></i></div>
                <div class="menu-title">Settings</div>
                <div class="menu-sub">Pengaturan</div>
            </a>
            <a href="#" class="menu-card">
                <div class="menu-icon pink"><i class="fas fa-envelope"></i></div>
                <div class="menu-title">Inbox</div>
                <div class="menu-sub">Pesan masuk</div>
                <span class="menu-badge">3</span>
            </a>
            <a href="logout.php" class="menu-card">
                <div class="menu-icon red"><i class="fas fa-sign-out-alt"></i></div>
                <div class="menu-title">Logout</div>
                <div class="menu-sub">Keluar</div>
            </a>
        </div>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - DMS v1.0
        </div>

    </main>

    <!-- ============================================
    BOTTOM NAVIGATION - MOBILE
    ============================================ -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <i class="fas fa-th-large nav-icon"></i>
            <span class="nav-label">Home</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-box nav-icon"></i>
            <span class="nav-label">Product</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-users nav-icon"></i>
            <span class="nav-label">Lead</span>
            <span class="badge-nav">12</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-building nav-icon"></i>
            <span class="nav-label">Account</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-chart-line nav-icon"></i>
            <span class="nav-label">Activity</span>
            <span class="badge-nav">3</span>
        </a>
        <a href="logout.php" class="nav-item">
            <i class="fas fa-sign-out-alt nav-icon" style="color:#d63031;"></i>
            <span class="nav-label" style="color:#d63031;">Logout</span>
        </a>
    </nav>

    <!-- ============================================
    SCRIPTS
    ============================================ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Active state untuk bottom nav
        document.querySelectorAll('.bottom-nav .nav-item').forEach(function(item) {
            item.addEventListener('click', function(e) {
                document.querySelectorAll('.bottom-nav .nav-item').forEach(function(el) {
                    el.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
    </script>
</body>
</html>