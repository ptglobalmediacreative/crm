<?php
require_once 'config.php';

// Cek login
if (!isLoggedIn()) {
    setFlash('Silakan login dulu!', 'warning');
    redirect('login.php');
}

// Ambil data untuk badge
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalActive = $db->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();

// Ambil data Sales Activity (contoh: total user aktif sebagai aktivitas sales)
$salesActivity = $totalActive;

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'user';
$username = $_SESSION['username'] ?? '';

// Cek banner
$bannerPath = 'images/banner.png';
$bannerExists = file_exists($bannerPath);

// Nama hari dalam bahasa Indonesia
$hari = [
    'Monday' => 'Senin',
    'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu',
    'Thursday' => 'Kamis',
    'Friday' => 'Jumat',
    'Saturday' => 'Sabtu',
    'Sunday' => 'Minggu'
];
$dayName = $hari[date('l')];
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding-bottom: 70px;
        }
        
        /* ============================================
           TOP HEADER - MOBILE
           ============================================ */
        .top-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 10px 20px;
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
            width: 36px;
            height: 36px;
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
        }
        
        .top-header .header-left .brand-text .brand-name {
            font-size: 13px;
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
            letter-spacing: 0.5px;
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
            font-size: 16px;
            cursor: pointer;
        }
        
        .top-header .header-right .notif-icon .badge-notif {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 16px;
            text-align: center;
        }
        
        .top-header .header-right .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-weight: 700;
            font-size: 13px;
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
            border-radius: 12px;
            padding: 16px 24px;
            color: #fff;
            margin-bottom: 16px;
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
            font-size: 18px;
            margin: 2px 0 0;
        }
        
        .welcome-banner .welcome-text h3 span {
            color: #ffd700;
        }
        
        .welcome-banner .welcome-icon {
            font-size: 32px;
            color: rgba(255, 215, 0, 0.05);
            position: absolute;
            right: 15px;
            bottom: 10px;
        }
        
        /* ============================================
           BANNER PROMO - DARI FILE
           ============================================ */
        .promo-banner {
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            background: #fff;
        }
        
        .promo-banner img {
            width: 100%;
            height: auto;
            max-height: 180px;
            display: block;
        }
        
        .promo-banner .banner-placeholder {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            padding: 24px 30px;
            text-align: center;
            color: #fff;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        
        .promo-banner .banner-placeholder .banner-title {
            font-size: 22px;
            font-weight: 800;
        }
        
        .promo-banner .banner-placeholder .banner-title span {
            color: #ffd700;
        }
        
        .promo-banner .banner-placeholder .banner-desc {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 4px;
        }
        
        /* ============================================
           SECTION TITLE
           ============================================ */
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        
        .section-title h5 {
            font-weight: 700;
            color: #1a1a2e;
            font-size: 15px;
            margin: 0;
        }
        
        .section-title h5 i {
            color: #ffd700;
            margin-right: 8px;
            font-size: 14px;
        }
        
        .section-title .see-all {
            font-size: 12px;
            color: #888;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
            cursor: pointer;
        }
        
        .section-title .see-all:hover {
            color: #ffd700;
        }
        
        /* ============================================
           MENU GRID
           ============================================ */
        .menu-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 16px;
        }
        
        .menu-grid.show-all {
            grid-template-columns: repeat(4, 1fr);
        }
        
        .menu-card {
            background: #fff;
            border-radius: 12px;
            padding: 16px 12px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .menu-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border-color: #ffd700;
        }
        
        .menu-card .menu-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 8px;
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
            color: #d4a017;
        }
        
        .menu-card .menu-icon.purple {
            background: rgba(155, 89, 182, 0.12);
            color: #8e44ad;
        }
        
        .menu-card .menu-icon.teal {
            background: rgba(0, 206, 209, 0.12);
            color: #16a085;
        }
        
        .menu-card .menu-title {
            font-weight: 600;
            font-size: 13px;
            color: #1a1a2e;
            margin: 0;
        }
        
        .menu-card .menu-sub {
            font-size: 10px;
            color: #999;
            margin: 2px 0 0;
        }
        
        /* Menu tambahan (hidden) */
        .menu-card.hidden-menu {
            display: none;
        }
        
        .menu-grid.show-all .menu-card.hidden-menu {
            display: flex;
        }
        
        /* ============================================
           MY ACTIVITY - SEPERTI GAMBAR PERSIS
           ============================================ */
        .my-activity {
            background: #fff;
            border-radius: 12px;
            padding: 18px 24px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 16px;
            transition: all 0.3s ease;
        }
        
        .my-activity:hover {
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        .my-activity .activity-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .my-activity .activity-left .activity-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: rgba(255, 215, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffd700;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .my-activity .activity-left .activity-info .activity-title {
            font-weight: 600;
            font-size: 15px;
            color: #1a1a2e;
            margin: 0;
        }
        
        .my-activity .activity-left .activity-info .activity-desc {
            font-size: 13px;
            color: #888;
            margin: 2px 0 0;
        }
        
        .my-activity .activity-right {
            text-align: right;
        }
        
        .my-activity .activity-right .activity-active {
            font-size: 13px;
            color: #888;
        }
        
        .my-activity .activity-right .activity-active strong {
            color: #1a1a2e;
            font-weight: 700;
        }
        
        .my-activity .activity-right .activity-date {
            font-size: 13px;
            color: #888;
            margin-top: 2px;
        }
        
        .my-activity .activity-right .activity-date strong {
            color: #1a1a2e;
            font-weight: 600;
        }
        
        /* ============================================
           BOTTOM NAVIGATION
           ============================================ */
        .bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            padding: 5px 0 env(safe-area-inset-bottom);
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
            padding: 3px 8px;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            min-width: 45px;
        }
        
        .bottom-nav .nav-item .nav-icon {
            font-size: 17px;
            color: #999;
            transition: all 0.3s ease;
        }
        
        .bottom-nav .nav-item .nav-label {
            font-size: 8px;
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
            width: 18px;
            height: 2px;
            background: #ffd700;
            border-radius: 0 0 2px 2px;
        }
        
        .bottom-nav .nav-item .badge-nav {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #d63031;
            color: #fff;
            font-size: 7px;
            padding: 1px 5px;
            border-radius: 50%;
            min-width: 15px;
            text-align: center;
        }
        
        .bottom-nav .nav-item:hover .nav-icon {
            color: #1a1a2e;
        }
        
        /* ============================================
           DESKTOP NAVBAR - LOGO TANPA LINGKARAN
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
            padding: 10px 0;
        }
        
        .desktop-nav-wrapper .brand-section .logo-wrapper {
            width: 38px;
            height: 38px;
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
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-sub {
            font-size: 8px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .desktop-nav-wrapper .desktop-menu {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .desktop-nav-wrapper .desktop-menu .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 6px;
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
            font-size: 14px;
        }
        
        .desktop-nav-wrapper .nav-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 17px;
            cursor: pointer;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon .badge-notif {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #d63031;
            color: #fff;
            font-size: 8px;
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
            font-size: 13px;
            line-height: 1.2;
        }
        
        .desktop-nav-wrapper .nav-right .user-info .role {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar {
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
        
        .desktop-nav-wrapper .nav-right .user-avatar:hover {
            border-color: #ffd700;
        }
        
        .desktop-nav-wrapper .nav-right .logout-btn {
            color: rgba(255, 255, 255, 0.5);
            padding: 5px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .desktop-nav-wrapper .nav-right .logout-btn:hover {
            color: #ff6b6b;
            background: rgba(214, 48, 49, 0.1);
            border-color: rgba(214, 48, 49, 0.3);
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
        
        /* Tablet & Mobile */
        @media (max-width: 768px) {
            .desktop-nav-wrapper {
                display: none !important;
            }
            body {
                padding-bottom: 65px;
            }
            
            .menu-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .menu-grid.show-all {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .menu-card {
                padding: 14px 10px;
            }
            
            .menu-card .menu-icon {
                width: 42px;
                height: 42px;
                font-size: 18px;
            }
            
            .menu-card .menu-title {
                font-size: 12px;
            }
            
            .welcome-banner {
                padding: 14px 18px;
            }
            
            .welcome-banner .welcome-text h3 {
                font-size: 16px;
            }
            
            .welcome-banner .welcome-icon {
                display: none;
            }
            
            .section-title h5 {
                font-size: 14px;
            }
            
            .my-activity {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
                padding: 14px 18px;
            }
            
            .my-activity .activity-right {
                text-align: left;
            }
            
            .promo-banner img {
                max-height: 120px;
            }
            
            .promo-banner .banner-placeholder {
                padding: 16px 20px;
                min-height: 80px;
            }
            
            .promo-banner .banner-placeholder .banner-title {
                font-size: 18px;
            }
            
            .promo-banner .banner-placeholder .banner-desc {
                font-size: 12px;
            }
        }
        
        @media (max-width: 480px) {
            .menu-grid {
                gap: 8px;
            }
            
            .menu-card {
                padding: 12px 6px;
                border-radius: 8px;
            }
            
            .menu-card .menu-icon {
                width: 36px;
                height: 36px;
                font-size: 15px;
                border-radius: 8px;
            }
            
            .menu-card .menu-title {
                font-size: 11px;
            }
            
            .menu-card .menu-sub {
                font-size: 9px;
            }
            
            .bottom-nav .nav-item .nav-label {
                font-size: 7px;
            }
            
            .bottom-nav .nav-item .nav-icon {
                font-size: 15px;
            }
            
            .top-header .header-left .brand-text .brand-name {
                font-size: 11px;
            }
            
            .top-header .header-left .logo-wrapper {
                width: 28px;
                height: 28px;
            }
            
            .my-activity {
                padding: 12px 14px;
            }
            
            .my-activity .activity-left .activity-icon {
                width: 34px;
                height: 34px;
                font-size: 15px;
            }
            
            .my-activity .activity-left .activity-info .activity-title {
                font-size: 13px;
            }
            
            .my-activity .activity-left .activity-info .activity-desc {
                font-size: 11px;
            }
            
            .my-activity .activity-right .activity-active {
                font-size: 12px;
            }
            
            .my-activity .activity-right .activity-date {
                font-size: 12px;
            }
            
            .promo-banner .banner-placeholder {
                padding: 12px 16px;
                min-height: 60px;
            }
            
            .promo-banner .banner-placeholder .banner-title {
                font-size: 15px;
            }
            
            .promo-banner .banner-placeholder .banner-desc {
                font-size: 11px;
            }
        }
        
        @media (min-width: 769px) and (max-width: 992px) {
            .menu-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .menu-grid.show-all {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        /* ============================================
           FOOTER
           ============================================ */
        .footer-text {
            text-align: center;
            padding: 16px 0 8px;
            color: #999;
            font-size: 11px;
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
                <div class="brand-sub">Customer Relationship Management System</div>
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
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
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
                <div class="brand-sub">Customer Relationship Management</div>
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
    <main style="padding: 16px 20px 0; max-width: 1400px; margin: 0 auto;">

        <!-- WELCOME BANNER -->
        <div class="welcome-banner">
            <div class="welcome-text">
                <div class="greeting">Selamat Datang,</div>
                <h3><?= htmlspecialchars($fullName) ?></h3>
            </div>
        </div>

        <!-- ============================================
        BANNER - DARI FILE images/banner.png
        ============================================ -->
        <div class="promo-banner">
            <?php if ($bannerExists): ?>
                <img src="images/banner.png" alt="Banner PT Ganda Elang Tangguh" loading="lazy">
            <?php else: ?>
                <div class="banner-placeholder">
                    <div class="banner-title">PT GANDA <span>ELANG TANGGUH</span></div>
                    <div class="banner-desc">Complete Heavy Equipment Solutions</div>
                </div>
            <?php endif; ?>
        </div>

        <!-- SECTION: MENU UTAMA -->
        <div class="section-title">
            <h5><i class="fas fa-th-large"></i>Menu Utama</h5>
            <a href="#" class="see-all" id="toggleMenu">Lainnya <i class="fas fa-chevron-down" style="font-size:10px;"></i></a>
        </div>

        <!-- MENU GRID -->
        <div class="menu-grid" id="menuGrid">
            <!-- Account Management -->
            <a href="#" class="menu-card">
                <div class="menu-icon orange"><i class="fas fa-building"></i></div>
                <div class="menu-title">Account Management</div>
                <div class="menu-sub">Kelola akun</div>
            </a>
            
            <!-- Sales Activity -->
            <a href="#" class="menu-card">
                <div class="menu-icon blue"><i class="fas fa-chart-bar"></i></div>
                <div class="menu-title">Sales Activity</div>
                <div class="menu-sub">Aktivitas sales</div>
            </a>
            
            <!-- Produk -->
            <a href="#" class="menu-card">
                <div class="menu-icon green"><i class="fas fa-box"></i></div>
                <div class="menu-title">Produk</div>
                <div class="menu-sub">Kelola produk</div>
            </a>
            
            <!-- Delivery Order -->
            <a href="#" class="menu-card">
                <div class="menu-icon gold"><i class="fas fa-truck"></i></div>
                <div class="menu-title">Delivery Order</div>
                <div class="menu-sub">Pengiriman</div>
            </a>
            
            <!-- Data User (Hidden) -->
            <a href="#" class="menu-card hidden-menu">
                <div class="menu-icon purple"><i class="fas fa-users"></i></div>
                <div class="menu-title">Data User</div>
                <div class="menu-sub">Kelola user</div>
            </a>
            
            <!-- Data Sales (Hidden) -->
            <a href="#" class="menu-card hidden-menu">
                <div class="menu-icon teal"><i class="fas fa-user-tie"></i></div>
                <div class="menu-title">Data Sales</div>
                <div class="menu-sub">Kelola sales</div>
            </a>
        </div>

        <!-- ============================================
        MY ACTIVITY - SEPERTI GAMBAR
        ============================================ -->
        <div class="section-title" style="margin-top: 0px;">
            <h5><i class="fas fa-clock" style="color:#ffd700;"></i>My Activity</h5>
            <span style="font-size:12px; color:#888;">Active: <strong style="color:#1a1a2e;"><?= $salesActivity ?> Activities</strong></span>
        </div>

        <div class="my-activity">
            <div class="activity-left">
                <div class="activity-icon">
                    <i class="fas fa-list"></i>
                </div>
                <div class="activity-info">
                    <div class="activity-title">My Activity</div>
                    <div class="activity-desc">Akses quick link aktivitas dan pengingat Anda</div>
                </div>
            </div>
            <div class="activity-right">
                <div class="activity-active">
                    <strong><?= $dayName ?>, <?= date('d M Y') ?></strong>
                </div>
                <div class="activity-date">
                    <strong>Active: <?= $salesActivity ?> Activities</strong>
                </div>
            </div>
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
            <i class="fas fa-building nav-icon"></i>
            <span class="nav-label">Account</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-chart-bar nav-icon"></i>
            <span class="nav-label">Sales</span>
            <span class="badge-nav"><?= $salesActivity ?></span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-box nav-icon"></i>
            <span class="nav-label">Produk</span>
        </a>
        <a href="#" class="nav-item">
            <i class="fas fa-truck nav-icon"></i>
            <span class="nav-label">DO</span>
            <span class="badge-nav">12</span>
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
        // Toggle Menu - Show/Hide hidden menus
        const toggleMenu = document.getElementById('toggleMenu');
        const menuGrid = document.getElementById('menuGrid');
        let isMenuOpen = false;

        toggleMenu.addEventListener('click', function(e) {
            e.preventDefault();
            isMenuOpen = !isMenuOpen;
            
            if (isMenuOpen) {
                menuGrid.classList.add('show-all');
                toggleMenu.innerHTML = 'Sembunyikan <i class="fas fa-chevron-up" style="font-size:10px;"></i>';
            } else {
                menuGrid.classList.remove('show-all');
                toggleMenu.innerHTML = 'Lainnya <i class="fas fa-chevron-down" style="font-size:10px;"></i>';
            }
        });

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