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
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #1a3a5c 0%, #2a5f8f 50%, #1a3a5c 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            max-width: 420px;
            margin: 0 auto;
            width: 100%;
        }
        
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1a3a5c 0%, #2a5f8f 100%);
            padding: 30px 25px 25px;
            text-align: center;
            border: none;
        }
        
        .logo-container {
            margin-bottom: 15px;
        }
        
        .logo-icon {
            font-size: 48px;
            color: #ffd700;
            background: rgba(255,255,255,0.1);
            width: 80px;
            height: 80px;
            line-height: 80px;
            border-radius: 50%;
            display: inline-block;
            border: 2px solid rgba(255,215,0,0.3);
        }
        
        .company-name {
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }
        
        .company-sub {
            color: rgba(255,255,255,0.8);
            font-size: 13px;
            font-weight: 300;
            letter-spacing: 2px;
        }
        
        .card-body {
            padding: 30px 25px;
            background: #ffffff;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e8edf2;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: #2a5f8f;
            box-shadow: 0 0 0 0.2rem rgba(42, 95, 143, 0.15);
        }
        
        .form-control-lg {
            padding: 14px 18px;
            font-size: 15px;
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e8edf2;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #6c757d;
        }
        
        .input-group .form-control {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #1a3a5c 0%, #2a5f8f 100%);
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-weight: 600;
            font-size: 16px;
            letter-spacing: 1px;
            transition: all 0.3s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(42, 95, 143, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .form-check-input:checked {
            background-color: #2a5f8f;
            border-color: #2a5f8f;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
        }
        
        .footer-text {
            color: #6c757d;
            font-size: 12px;
            text-align: center;
            margin-top: 20px;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0;
            color: #adb5bd;
            font-size: 12px;
        }
        
        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #dee2e6;
        }
        
        .divider::before {
            margin-right: 15px;
        }
        
        .divider::after {
            margin-left: 15px;
        }
        
        .alert-icon {
            margin-right: 10px;
        }
        
        @media (max-width: 480px) {
            .card-body {
                padding: 20px 15px;
            }
            .company-name {
                font-size: 17px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="card">
                <!-- Header dengan Logo -->
                <div class="card-header">
                    <div class="logo-container">
                        <div class="logo-icon">
                            <i class="fas fa-hard-hat"></i>
                        </div>
                    </div>
                    <div class="company-name">PT GANDA ELANG TANGGUH</div>
                    <div class="company-sub">DEALER ALAT BERAT</div>
                </div>
                
                <!-- Body Form Login -->
                <div class="card-body">
                    <!-- Alert Message -->
                    <?= showFlash() ?>
                    
                    <!-- Form Login -->
                    <form method="POST" action="">
                        <!-- Email -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-envelope me-2 text-primary"></i>Email Address
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
                                    value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                >
                            </div>
                        </div>
                        
                        <!-- Password -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-lock me-2 text-primary"></i>Password
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
                                >
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
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
                                    <small>Ingat saya</small>
                                </label>
                            </div>
                            <a href="#" class="text-decoration-none small" style="color: #2a5f8f;">
                                <i class="fas fa-key me-1"></i>Lupa password?
                            </a>
                        </div>
                        
                        <!-- Tombol Login -->
                        <button type="submit" class="btn btn-primary btn-login w-100">
                            <i class="fas fa-sign-in-alt me-2"></i>MASUK
                        </button>
                        
                        <!-- Divider -->
                        <div class="divider">DEALER ALAT BERAT TERPERCAYA</div>
                        
                        <!-- Info Tambahan -->
                        <div class="text-center">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt me-1 text-success"></i>
                                Sistem Manajemen CRM
                            </small>
                            <br>
                            <small class="text-muted">
                                <i class="fas fa-building me-1"></i>
                                PT Ganda Elang Tangguh &copy; <?= date('Y') ?>
                            </small>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer-text">
                <i class="fas fa-cogs me-1"></i>
                Powered by GET CRM System v1.0
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
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Auto dismiss alert after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert) {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }
            });
        }, 5000);
    </script>
</body>
</html>