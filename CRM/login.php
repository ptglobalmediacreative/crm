<?php
require_once 'config.php';

// Jika sudah login, redirect ke dashboard
if (isLoggedIn()) {
    redirect('dashboard.php');
}

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = bersihkan($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;
    
    // Validasi input
    if (empty($email) || empty($password)) {
        setFlash('Email dan password wajib diisi!', 'danger');
    } else {
        // Cari user di database
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password_hash'])) {
            // Set session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['login_time'] = time();
            
            // Remember me (cookie 7 hari)
            if ($remember) {
                $token = bin2hex(random_bytes(32));
                setcookie('remember_token', $token, time() + (86400 * 7), '/');
                
                // Simpan token di database
                $stmt = $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                $stmt->execute([$token, $user['id']]);
            }
            
            // Log aktivitas
            $stmt = $db->prepare("INSERT INTO user_logs (user_id, activity, ip_address, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $user['id'],
                'Login',
                $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            setFlash('Selamat datang, ' . $user['full_name'] . '!', 'success');
            redirect('dashboard.php');
        } else {
            setFlash('Email atau password salah!', 'danger');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PT Ganda Elang Tangguh</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/webp" href="crm/images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="crm/images/favicon.webp">
    
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
            background: linear-gradient(135deg, #8B6914 0%, #DAA520 30%, #FFD700 60%, #F0C000 80%, #8B6914 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Background Pattern - Emas */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255, 215, 0, 0.3) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(255, 215, 0, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 50% 100%, rgba(139, 105, 20, 0.2) 0%, transparent 40%);
            z-index: 0;
        }
        
        /* Animated Background Orbs - Emas */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            z-index: 0;
            animation: float 20s ease-in-out infinite;
        }
        
        .orb-1 {
            width: 500px;
            height: 500px;
            background: rgba(255, 215, 0, 0.3);
            top: -150px;
            right: -150px;
            animation-delay: 0s;
        }
        
        .orb-2 {
            width: 400px;
            height: 400px;
            background: rgba(218, 165, 32, 0.25);
            bottom: -100px;
            left: -100px;
            animation-delay: -7s;
        }
        
        .orb-3 {
            width: 300px;
            height: 300px;
            background: rgba(255, 215, 0, 0.15);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -14s;
        }
        
        @keyframes float {
            0%, 100% {
                transform: translate(0, 0) scale(1);
            }
            33% {
                transform: translate(30px, -30px) scale(1.1);
            }
            66% {
                transform: translate(-20px, 20px) scale(0.9);
            }
        }
        
        /* Sparkle Effect */
        .sparkle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: #FFD700;
            border-radius: 50%;
            box-shadow: 0 0 10px #FFD700, 0 0 20px #FFD700;
            animation: sparkle 3s ease-in-out infinite;
            z-index: 0;
        }
        
        .sparkle:nth-child(1) { top: 10%; left: 10%; animation-delay: 0s; }
        .sparkle:nth-child(2) { top: 20%; right: 15%; animation-delay: 1s; }
        .sparkle:nth-child(3) { bottom: 30%; left: 5%; animation-delay: 2s; }
        .sparkle:nth-child(4) { bottom: 20%; right: 10%; animation-delay: 0.5s; }
        .sparkle:nth-child(5) { top: 50%; left: 5%; animation-delay: 1.5s; }
        .sparkle:nth-child(6) { top: 40%; right: 5%; animation-delay: 2.5s; }
        
        @keyframes sparkle {
            0%, 100% {
                opacity: 0;
                transform: scale(0);
            }
            50% {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .login-container {
            max-width: 440px;
            margin: 0 auto;
            width: 100%;
            position: relative;
            z-index: 1;
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 24px;
            box-shadow: 
                0 30px 80px rgba(0, 0, 0, 0.4),
                0 0 0 1px rgba(255, 215, 0, 0.2),
                0 0 60px rgba(255, 215, 0, 0.1);
            overflow: hidden;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.98);
        }
        
        .card-header {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 40%, #4a4a4a 70%, #2d2d2d 100%);
            padding: 35px 30px 30px;
            text-align: center;
            border: none;
            position: relative;
            overflow: hidden;
        }
        
        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at center, rgba(255, 215, 0, 0.05) 0%, transparent 70%);
            animation: rotateGlow 20s linear infinite;
        }
        
        @keyframes rotateGlow {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .card-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, #FFD700, #FFA500, #FFD700, transparent);
            background-size: 200% 100%;
            animation: shimmer 3s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% {
                background-position: -200% 0;
            }
            50% {
                background-position: 200% 0;
            }
        }
        
        .logo-container {
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }
        
        .logo-wrapper {
            width: 100px;
            height: 100px;
            margin: 0 auto;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            border: 3px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 0 40px rgba(255, 215, 0, 0.05);
        }
        
        .logo-wrapper:hover {
            transform: scale(1.05) rotate(-5deg);
            border-color: rgba(255, 215, 0, 0.8);
            box-shadow: 0 0 60px rgba(255, 215, 0, 0.2);
        }
        
        .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 50%;
        }
        
        .logo-wrapper .logo-placeholder {
            font-size: 52px;
            color: #FFD700;
        }
        
        .company-name {
            color: #ffffff;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 1.5px;
            margin-bottom: 4px;
            text-shadow: 0 2px 20px rgba(255, 215, 0, 0.1);
            position: relative;
            z-index: 1;
        }
        
        .company-name span {
            color: #FFD700;
        }
        
        .company-sub {
            color: rgba(255, 215, 0, 0.7);
            font-size: 13px;
            font-weight: 400;
            letter-spacing: 3px;
            text-transform: uppercase;
            position: relative;
            z-index: 1;
        }
        
        .company-sub i {
            margin: 0 5px;
            color: #FFD700;
            font-size: 8px;
            opacity: 0.5;
        }
        
        .card-body {
            padding: 35px 30px 30px;
            background: #ffffff;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 14px;
            color: #1a1a1a;
            margin-bottom: 8px;
        }
        
        .form-label i {
            color: #DAA520;
            width: 20px;
        }
        
        .form-control {
            border-radius: 12px;
            padding: 13px 16px;
            border: 2px solid #e8edf2;
            transition: all 0.3s ease;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }
        
        .form-control:focus {
            border-color: #DAA520;
            box-shadow: 0 0 0 4px rgba(218, 165, 32, 0.1);
        }
        
        .form-control-lg {
            padding: 15px 18px;
            font-size: 15px;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e8edf2;
            border-right: none;
            border-radius: 12px 0 0 12px;
            color: #6c757d;
            padding: 0 16px;
        }
        
        .input-group .form-control {
            border-radius: 0 12px 12px 0;
            border-left: none;
        }
        
        .input-group .form-control:focus + .input-group-text {
            border-color: #DAA520;
        }
        
        .btn-toggle-password {
            border: 2px solid #e8edf2;
            border-left: none;
            border-radius: 0 12px 12px 0;
            background: #f8f9fa;
            color: #6c757d;
            padding: 0 16px;
            transition: all 0.3s ease;
        }
        
        .btn-toggle-password:hover {
            background: #e9ecef;
            color: #DAA520;
        }
        
        .btn-toggle-password:focus {
            box-shadow: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #8B6914 0%, #DAA520 40%, #FFD700 70%, #F0C000 100%);
            border: none;
            border-radius: 12px;
            padding: 16px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            color: #1a1a1a;
            text-shadow: 0 1px 2px rgba(255, 215, 0, 0.3);
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(218, 165, 32, 0.5);
            background: linear-gradient(135deg, #DAA520 0%, #FFD700 40%, #FFE44D 70%, #DAA520 100%);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            border: 2px solid #d1d5db;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .form-check-input:checked {
            background-color: #DAA520;
            border-color: #DAA520;
        }
        
        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(218, 165, 32, 0.15);
        }
        
        .form-check-label {
            font-size: 14px;
            color: #4a5568;
            cursor: pointer;
            user-select: none;
        }
        
        .forgot-link {
            color: #DAA520;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .forgot-link:hover {
            color: #8B6914;
            text-decoration: underline !important;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 14px 18px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .alert-icon {
            margin-right: 10px;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 25px 0 20px;
            color: #DAA520;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            border-bottom: 2px solid #f0e6d3;
        }
        
        .divider::before {
            margin-right: 20px;
        }
        
        .divider::after {
            margin-left: 20px;
        }
        
        .footer-text {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            text-align: center;
            margin-top: 25px;
            font-weight: 300;
            letter-spacing: 0.5px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .footer-text a {
            color: #FFD700;
            text-decoration: none;
            transition: color 0.2s ease;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            color: #FFA500;
            text-decoration: underline;
        }
        
        /* Loading animation for button */
        .btn-login.loading {
            pointer-events: none;
            opacity: 0.8;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(0, 0, 0, 0.1);
            border-radius: 50%;
            border-top-color: #1a1a1a;
            animation: spin 0.8s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Gold accent text */
        .text-gold {
            color: #DAA520;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 10px;
            }
            
            .card-body {
                padding: 25px 20px 25px;
            }
            
            .card-header {
                padding: 25px 20px 20px;
            }
            
            .logo-wrapper {
                width: 80px;
                height: 80px;
                padding: 10px;
            }
            
            .company-name {
                font-size: 18px;
            }
            
            .company-sub {
                font-size: 11px;
                letter-spacing: 2px;
            }
            
            .form-control-lg {
                padding: 12px 14px;
                font-size: 14px;
            }
            
            .btn-login {
                padding: 14px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 360px) {
            .logo-wrapper {
                width: 65px;
                height: 65px;
                padding: 8px;
            }
            
            .company-name {
                font-size: 16px;
            }
            
            .card-body {
                padding: 20px 15px 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Sparkle Effects -->
    <div class="sparkle"></div>
    <div class="sparkle"></div>
    <div class="sparkle"></div>
    <div class="sparkle"></div>
    <div class="sparkle"></div>
    <div class="sparkle"></div>
    
    <!-- Animated Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    
    <div class="container">
        <div class="login-container">
            <div class="card">
                <!-- Header dengan Logo -->
                <div class="card-header">
                    <div class="logo-container">
                        <div class="logo-wrapper">
                            <?php if (file_exists('crm/images/logo.webp')): ?>
                                <img src="crm/images/logo.webp" alt="PT Ganda Elang Tangguh" loading="lazy">
                            <?php else: ?>
                                <i class="fas fa-hard-hat logo-placeholder"></i>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="company-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                    <div class="company-sub">
                        <i class="fas fa-circle"></i>
                        DEALER ALAT BERAT
                        <i class="fas fa-circle"></i>
                    </div>
                </div>
                
                <!-- Body Form Login -->
                <div class="card-body">
                    <!-- Alert Message -->
                    <?= showFlash() ?>
                    
                    <!-- Form Login -->
                    <form method="POST" action="" id="loginForm">
                        <!-- Email -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input 
                                    type="email" 
                                    name="email" 
                                    class="form-control form-control-lg" 
                                    placeholder="masukkan@email.com" 
                                    required
                                    autocomplete="email"
                                    value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                >
                            </div>
                        </div>
                        
                        <!-- Password -->
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-lock"></i> Password
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-key"></i>
                                </span>
                                <input 
                                    type="password" 
                                    name="password" 
                                    class="form-control form-control-lg" 
                                    placeholder="••••••••" 
                                    required
                                    autocomplete="current-password"
                                >
                                <button class="btn btn-toggle-password" type="button" id="togglePassword" aria-label="Toggle password visibility">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Remember Me & Forgot Password -->
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input 
                                    type="checkbox" 
                                    name="remember" 
                                    class="form-check-input" 
                                    id="rememberMe"
                                >
                                <label class="form-check-label" for="rememberMe">
                                    <i class="fas fa-check-circle me-1" style="color: #DAA520; opacity: 0.6;"></i>
                                    Ingat saya
                                </label>
                            </div>
                            <a href="#" class="forgot-link text-decoration-none small">
                                <i class="fas fa-key me-1"></i>Lupa password?
                            </a>
                        </div>
                        
                        <!-- Tombol Login -->
                        <button type="submit" class="btn btn-primary btn-login w-100" id="loginBtn">
                            <i class="fas fa-sign-in-alt me-2"></i>
                            <span id="btnText">MASUK</span>
                        </button>
                        
                        <!-- Divider -->
                        <div class="divider">
                            <i class="fas fa-star me-2" style="color: #DAA520;"></i>
                            DEALER ALAT BERAT TERPERCAYA
                            <i class="fas fa-star ms-2" style="color: #DAA520;"></i>
                        </div>
                        
                        <!-- Info Tambahan -->
                        <div class="text-center">
                            <small class="text-muted" style="font-size: 12px;">
                                <i class="fas fa-shield-alt me-1" style="color: #DAA520;"></i>
                                Sistem Manajemen CRM &bull; v1.0
                            </small>
                            <br>
                            <small class="text-muted" style="font-size: 11px;">
                                <i class="fas fa-building me-1" style="color: #DAA520;"></i>
                                PT Ganda Elang Tangguh &copy; <?= date('Y') ?>
                            </small>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer-text">
                <i class="fas fa-cogs me-1"></i>
                Powered by <a href="#">GET CRM System</a>
                <br>
                <small>
                    <i class="fas fa-map-marker-alt me-1"></i>
                    Jl. Raya Industri No. 123, Jakarta
                </small>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Password Visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.querySelector('input[name="password"]');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                this.setAttribute('aria-label', 'Sembunyikan password');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                this.setAttribute('aria-label', 'Tampilkan password');
            }
        });
        
        // Form Submit Loading State
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btnText');
            
            // Cek jika form valid
            if (this.checkValidity()) {
                btn.classList.add('loading');
                btnText.innerHTML = '<span class="spinner"></span> Memproses...';
                btn.disabled = true;
            }
        });
        
        // Auto dismiss alert after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert) {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }
            });
        }, 5000);
        
        // Keyboard shortcut: Enter to submit
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && document.activeElement.tagName === 'INPUT') {
                const form = document.getElementById('loginForm');
                if (form) {
                    form.submit();
                }
            }
        });
    </script>
</body>
</html>