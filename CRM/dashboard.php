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
            padding-bottom: 80px; /* Space for bottom nav */
        }
        
        /* ============================================
           TOP HEADER
           ============================================ */
        .top-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 16px 20px;
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
            gap: 12px;
        }
        
        .top-header .header-left .logo-wrapper {
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
        
        .top-header .header-left .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .top-header .header-left .brand-text {
            color: #fff;
        }
        
        .top-header .header-left .brand-text .brand-name {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }
        
        .top-header .header-left .brand-text .brand-name span {
            color: #ffd700;
        }
        
        .top-header .header-left .brand-text .brand-sub {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.4);
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        
        .top-header .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .top-header .header-right .notif-icon {
            position: relative;
            color: rgba(255, 255, 255, 0.6);
            font-size: 18px;
            cursor: pointer;
            transition: color 0.3s ease;
        }
        
        .top-header .header-right .notif-icon:hover {
            color: #fff;
        }
        
        .top-header .header-right .notif-icon .badge-notif {
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
        
        .top-header .header-right .user-avatar {
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
            cursor: pointer;
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
            border-radius: 16px;
            padding: 24px 28px;
            color: #fff;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.03);
        }
        
        .welcome-banner .welcome-text {
            position: relative;
            z-index: 1;
        }
        
        .welcome-banner .welcome-text .greeting {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 400;
        }
        
        .welcome-banner .welcome-text h3 {
            font-weight: 700;
            font-size: 22px;
            margin: 2px 0 4px;
        }
        
        .welcome-banner .welcome-text h3 span {
            color: #ffd700;
        }
        
        .welcome-banner .welcome-text p {
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
            margin: 0;
        }
        
        .welcome-banner .welcome-icon {
            font-size: 50px;
            color: rgba(255, 215, 0, 0.08);
            position: absolute;
            right: 20px;
            bottom: 10px;
        }
        
        /* ============================================
           STATISTIC CARDS - STYLE SEPERTI GAMBAR
           ============================================ */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .stat-card-mini {
            background: #fff;
            border-radius: 12px;
            padding: 16px 14px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .stat-card-mini:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
        }
        
        .stat-card-mini .stat-icon {
            font-size: 22px;
            margin-bottom: 6px;
        }
        
        .stat-card-mini .stat-icon.blue { color: #1a1a2e; }
        .stat-card-mini .stat-icon.green { color: #2ed573; }
        .stat-card-mini .stat-icon.gold { color: #ffd700; }
        .stat-card-mini .stat-icon.red { color: #d63031; }
        
        .stat-card-mini .stat-number {
            font-size: 20px;
            font-weight: 800;
            color: #1a1a2e;
            line-height: 1.2;
        }
        
        .stat-card-mini .stat-label {
            font-size: 11px;
            color: #999;
            font-weight: 500;
            margin-top: 2px;
        }
        
        .stat-card-mini .stat-change {
            font-size: 10px;
            margin-top: 4px;
        }
        
        .stat-card-mini .stat-change.positive {
            color: #2ed573;
        }
        
        /* ============================================
           SECTION TITLE - SEPERTI GAMBAR
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
           HORIZONTAL SCROLL CARDS - SEPERTI GAMBAR
           ============================================ */
        .scroll-horizontal {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            padding: 4px 2px 10px;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        
        .scroll-horizontal::-webkit-scrollbar {
            display: none;
        }
        
        .scroll-card {
            min-width: 160px;
            flex-shrink: 0;
            background: #fff;
            border-radius: 12px;
            padding: 18px 16px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            scroll-snap-align: start;
            transition: all 0.3s ease;
        }
        
        .scroll-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.08);
        }
        
        .scroll-card .card-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .scroll-card .card-icon .icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .scroll-card .card-icon .icon-circle.orange {
            background: rgba(255, 165, 0, 0.12);
            color: #e67e22;
        }
        
        .scroll-card .card-icon .icon-circle.blue {
            background: rgba(26, 26, 46, 0.08);
            color: #1a1a2e;
        }
        
        .scroll-card .card-icon .icon-circle.green {
            background: rgba(46, 213, 115, 0.12);
            color: #2ed573;
        }
        
        .scroll-card .card-icon .icon-circle.gold {
            background: rgba(255, 215, 0, 0.15);
            color: #ffd700;
        }
        
        .scroll-card .card-title {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a2e;
            margin: 0;
        }
        
        .scroll-card .card-sub {
            font-size: 11px;
            color: #999;
            margin: 2px 0 0;
        }
        
        .scroll-card .card-count {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin-top: 6px;
        }
        
        /* ============================================
           TABLE - SEPERTI GAMBAR
           ============================================ */
        .card-custom {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-top: 20px;
        }
        
        .card-custom .card-header-custom {
            padding: 16px 20px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-custom .card-header-custom h6 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
            font-size: 14px;
        }
        
        .card-custom .card-header-custom h6 i {
            color: #ffd700;
            margin-right: 8px;
        }
        
        .card-custom .card-body-custom {
            padding: 0;
            overflow-x: auto;
        }
        
        .table-custom {
            margin-bottom: 0;
            font-size: 13px;
        }
        
        .table-custom th {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #999;
            border-bottom: 1px solid #f0f2f5;
            padding: 10px 15px;
            background: #fafafa;
        }
        
        .table-custom td {
            padding: 10px 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f0f2f5;
        }
        
        .table-custom tr:last-child td {
            border-bottom: none;
        }
        
        .table-custom tr:hover {
            background: #f8f9fa;
        }
        
        .badge-role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
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
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 10px;
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
            min-width: 55px;
        }
        
        .bottom-nav .nav-item .nav-icon {
            font-size: 20px;
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
        
        .bottom-nav .nav-item.active {
            position: relative;
        }
        
        /* Indicator active seperti gambar */
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
        
        .bottom-nav .nav-item:hover .nav-label {
            color: #1a1a2e;
        }
        
        /* ============================================
           RESPONSIVE
           ============================================ */
        
        /* Desktop - tampilan normal */
        @media (min-width: 769px) {
            .bottom-nav {
                display: none !important;
            }
            
            body {
                padding-bottom: 0;
            }
            
            /* Desktop menu di navbar */
            .desktop-menu {
                display: flex !important;
                align-items: center;
                gap: 8px;
            }
            
            .desktop-menu .nav-link {
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
            
            .desktop-menu .nav-link:hover {
                color: #fff;
                background: rgba(255, 255, 255, 0.05);
            }
            
            .desktop-menu .nav-link.active {
                color: #ffd700;
                background: rgba(255, 215, 0, 0.08);
            }
            
            .desktop-menu .nav-link i {
                font-size: 15px;
            }
        }
        
        /* Mobile - tampilan dengan bottom nav */
        @media (max-width: 768px) {
            .desktop-menu {
                display: none !important;
            }
            
            body {
                padding-bottom: 75px;
            }
            
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
            
            .stat-card-mini {
                padding: 14px 12px;
            }
            
            .stat-card-mini .stat-number {
                font-size: 18px;
            }
            
            .welcome-banner {
                padding: 18px 20px;
                margin-bottom: 16px;
            }
            
            .welcome-banner .welcome-text h3 {
                font-size: 18px;
            }
            
            .welcome-banner .welcome-icon {
                display: none;
            }
            
            .scroll-card {
                min-width: 140px;
                padding: 14px 14px;
            }
            
            .top-header .header-left .brand-text .brand-name {
                font-size: 14px;
            }
            
            .top-header .header-left .brand-text .brand-sub {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .stat-grid {
                gap: 8px;
            }
            
            .stat-card-mini {
                padding: 12px 10px;
                border-radius: 10px;
            }
            
            .stat-card-mini .stat-number {
                font-size: 16px;
            }
            
            .stat-card-mini .stat-label {
                font-size: 10px;
            }
            
            .stat-card-mini .stat-icon {
                font-size: 18px;
            }
            
            .scroll-card {
                min-width: 120px;
                padding: 12px 12px;
            }
            
            .scroll-card .card-title {
                font-size: 12px;
            }
            
            .scroll-card .card-count {
                font-size: 17px;
            }
            
            .bottom-nav .nav-item .nav-label {
                font-size: 8px;
            }
            
            .bottom-nav .nav-item .nav-icon {
                font-size: 18px;
            }
        }
        
        @media (min-width: 769px) and (max-width: 992px) {
            .stat-grid {
                grid-template-columns: repeat(2, 1fr);
            }
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
        
        .desktop-nav-wrapper .brand-section .brand-text {
            color: #fff;
        }
        
        .desktop-nav-wrapper .brand-section .brand-text .brand-name {
            font-size: 16px;
            font-weight: 700;
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
            transition: color 0.3s ease;
        }
        
        .desktop-nav-wrapper .nav-right .notif-icon:hover {
            color: #fff;
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
            cursor: pointer;
            border: 2px solid rgba(255, 215, 0, 0.2);
            transition: border-color 0.3s ease;
        }
        
        .desktop-nav-wrapper .nav-right .user-avatar:hover {
            border-color: #ffd700;
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
        
        @media (max-width: 768px) {
            .desktop-nav-wrapper {
                display: none !important;
            }
        }
        
        @media (min-width: 769px) {
            .top-header {
                display: none !important;
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
        
        <div class="desktop-menu" style="display:flex; align-items:center; gap:4px;">
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
            <a href="logout.php" class="user-avatar" style="text-decoration:none;">
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
                <div class="brand-sub">Dealer Management System</div>
            </div>
        </div>
        <div class="header-right">
            <div class="notif-icon">
                <i class="fas fa-bell"></i>
                <span class="badge-notif">3</span>
            </div>
            <a href="logout.php" class="user-avatar" style="text-decoration:none;">
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
                <p>Kelola data dan aktivitas Anda dengan mudah</p>
            </div>
            <i class="fas fa-hard-hat welcome-icon"></i>
        </div>

        <!-- STATISTIK - GRID 4 KOLOM -->
        <div class="stat-grid">
            <div class="stat-card-mini">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div class="stat-number"><?= number_format($totalUsers) ?></div>
                <div class="stat-label">Total Users</div>
                <div class="stat-change positive"><i class="fas fa-arrow-up"></i> +<?= $newToday ?></div>
            </div>
            <div class="stat-card-mini">
                <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
                <div class="stat-number"><?= number_format($totalActive) ?></div>
                <div class="stat-label">Active</div>
                <div class="stat-change positive"><i class="fas fa-check-circle"></i> Aktif</div>
            </div>
            <div class="stat-card-mini">
                <div class="stat-icon gold"><i class="fas fa-user-tie"></i></div>
                <div class="stat-number"><?= number_format($totalAdmin) ?></div>
                <div class="stat-label">Admin</div>
                <div class="stat-change"><i class="fas fa-crown"></i> Admin</div>
            </div>
            <div class="stat-card-mini">
                <div class="stat-icon red"><i class="fas fa-clock"></i></div>
                <div class="stat-number"><?= date('H:i') ?></div>
                <div class="stat-label">Waktu</div>
                <div class="stat-change"><i class="fas fa-calendar"></i> <?= date('d M') ?></div>
            </div>
        </div>

        <!-- SECTION: PRODUCT, LEAD, ACCOUNT MANAGEMENT, ACTIVITY - SEPERTI GAMBAR -->
        <div class="section-title">
            <h5><i class="fas fa-th-large" style="color:#ffd700; margin-right:8px;"></i>Menu Utama</h5>
            <a href="#" class="see-all">More <i class="fas fa-chevron-right" style="font-size:10px;"></i></a>
        </div>

        <div class="scroll-horizontal">
            <div class="scroll-card">
                <div class="card-icon">
                    <div class="icon-circle orange"><i class="fas fa-box"></i></div>
                </div>
                <div class="card-title">Product</div>
                <div class="card-sub">Kelola produk</div>
                <div class="card-count"><?= $totalUsers ?></div>
            </div>
            <div class="scroll-card">
                <div class="card-icon">
                    <div class="icon-circle blue"><i class="fas fa-users"></i></div>
                </div>
                <div class="card-title">Lead</div>
                <div class="card-sub">Data lead</div>
                <div class="card-count">12</div>
            </div>
            <div class="scroll-card">
                <div class="card-icon">
                    <div class="icon-circle green"><i class="fas fa-building"></i></div>
                </div>
                <div class="card-title">Account</div>
                <div class="card-sub">Management</div>
                <div class="card-count">8</div>
            </div>
            <div class="scroll-card">
                <div class="card-icon">
                    <div class="icon-circle gold"><i class="fas fa-chart-line"></i></div>
                </div>
                <div class="card-title">Activity</div>
                <div class="card-sub">Aktivitas</div>
                <div class="card-count">24</div>
            </div>
            <div class="scroll-card">
                <div class="card-icon">
                    <div class="icon-circle" style="background:rgba(214,48,49,0.1); color:#d63031;"><i class="fas fa-file-invoice"></i></div>
                </div>
                <div class="card-title">Report</div>
                <div class="card-sub">Laporan</div>
                <div class="card-count">5</div>
            </div>
        </div>

        <!-- TABLE USER TERBARU -->
        <div class="card-custom">
            <div class="card-header-custom">
                <h6><i class="fas fa-user-plus"></i> User Terbaru</h6>
                <a href="#" class="see-all" style="font-size:12px;">Lihat Semua</a>
            </div>
            <div class="card-body-custom">
                <div class="table-responsive">
                    <table class="table table-custom">
                        <thead>
                            <tr>
                                <th>No</th>
                                <th>Username</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentUsers) > 0): ?>
                                <?php $no = 1; ?>
                                <?php foreach ($recentUsers as $user): ?>
                                    <tr>
                                        <td><?= $no++ ?></td>
                                        <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
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
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox me-2"></i> Belum ada user
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer-text">
            &copy; <?= date('Y') ?> <a href="#">PT Ganda Elang Tangguh</a> - DMS v1.0
        </div>

    </main>

    <!-- ============================================
    BOTTOM NAVIGATION - MOBILE (SEPERTI GAMBAR)
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