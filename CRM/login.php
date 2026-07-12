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
    
    if (empty($email) || empty($password)) {
        setFlash('Email dan password wajib diisi!', 'danger');
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            
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
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #1a1a2e, #16213e, #0f3460);
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            max-width: 400px;
            margin: 0 auto;
            width: 100%;
            padding: 20px;
        }
        
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            background: #ffffff;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            padding: 30px 20px 25px;
            text-align: center;
            border: none;
        }
        
        .logo-wrapper {
            width: 120px;
            height: 120px;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            transition: all 0.3s ease;
        }
        
        .logo-wrapper:hover {
            transform: scale(1.05);
        }
        
        .logo-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .company-name {
            color: #ffffff;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .company-name span {
            color: #ffd700;
        }
        
        .company-sub {
            color: rgba(255, 255, 255, 0.6);
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
        }
        
        .card-body {
            padding: 30px 25px 25px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #333;
            margin-bottom: 6px;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e8edf2;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.15);
        }
        
        .input-group-text {
            background: #f8f9fa;
            border: 2px solid #e8edf2;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #888;
        }
        
        .input-group .form-control {
            border-radius: 0 10px 10px 0;
            border-left: none;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-weight: 600;
            font-size: 15px;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            color: #fff;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(22, 33, 62, 0.4);
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: #fff;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* ===== CHECKBOX STYLE ===== */
        .custom-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            user-select: none;
        }
        
        .custom-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            min-width: 18px;
            min-height: 18px;
            cursor: pointer;
            accent-color: #16213e;
            border: 2px solid #16213e;
            border-radius: 4px;
            background-color: #fff;
            transition: all 0.2s ease;
        }
        
        .custom-checkbox input[type="checkbox"]:checked {
            background-color: #16213e;
            border-color: #16213e;
        }
        
        .custom-checkbox input[type="checkbox"]:focus {
            box-shadow: 0 0 0 3px rgba(22, 33, 62, 0.2);
            outline: none;
        }
        
        .custom-checkbox input[type="checkbox"]:hover {
            border-color: #ffd700;
        }
        
        .custom-checkbox .checkmark {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .custom-checkbox .label-text {
            font-weight: 500;
            color: #333;
        }
        
        .custom-checkbox .label-text i {
            margin-right: 4px;
        }
        /* ===== END CHECKBOX ===== */
        
        .forgot-link {
            color: #16213e;
            font-size: 13px;
            text-decoration: none;
            transition: color 0.2s ease;
            font-weight: 500;
        }
        
        .forgot-link:hover {
            color: #ffd700;
            text-decoration: underline;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .divider {
            display: flex;
            align-items: center;
            margin: 20px 0 15px;
            color: #ccc;
            font-size: 11px;
            letter-spacing: 1px;
        }
        
        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            border-bottom: 1px solid #e8edf2;
        }
        
        .divider::before {
            margin-right: 15px;
        }
        
        .divider::after {
            margin-left: 15px;
        }
        
        .footer-text {
            color: rgba(255, 255, 255, 0.5);
            font-size: 11px;
            text-align: center;
            margin-top: 20px;
        }
        
        .footer-text a {
            color: #ffd700;
            text-decoration: none;
        }
        
        .footer-text a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 10px;
            }
            
            .card-body {
                padding: 20px 15px;
            }
            
            .logo-wrapper {
                width: 90px;
                height: 90px;
            }
            
            .company-name {
                font-size: 17px;
            }
            
            .custom-checkbox {
                font-size: 12px;
            }
            
            .custom-checkbox input[type="checkbox"] {
                width: 16px;
                height: 16px;
                min-width: 16px;
                min-height: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="card">
                <!-- Header -->
                <div class="card-header">
                    <div class="logo-wrapper">
                        <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
                    </div>
                    <div class="company-name">PT GANDA <span>ELANG</span> TANGGUH</div>
                    <div class="company-sub">Dealer Management System</div>
                </div>
                
                <!-- Body -->
                <div class="card-body">
                    <?= showFlash() ?>
                    
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-envelope me-1" style="color: #16213e;"></i> Email
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-envelope"></i>
                                </span>
                                <input 
                                    type="email" 
                                    name="email" 
                                    class="form-control" 
                                    placeholder="admin@email.com" 
                                    required
                                    value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                >
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">
                                <i class="fas fa-lock me-1" style="color: #16213e;"></i> Password
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-key"></i>
                                </span>
                                <input 
                                    type="password" 
                                    name="password" 
                                    class="form-control" 
                                    placeholder="••••••••" 
                                    required
                                >
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <!-- Checkbox "Ingat saya" -->
                            <label class="custom-checkbox">
                                <input type="checkbox" name="remember" id="remember">
                                <span class="label-text">
                                    <i class="fas fa-check-circle"></i> Ingat saya
                                </span>
                            </label>
                            
                            <a href="forgot_password.php" class="forgot-link">
                                <i class="fas fa-key me-1"></i>Lupa password?
                            </a>
                        </div>
                        
                        <button type="submit" class="btn-login">
                            <i class="fas fa-sign-in-alt me-2"></i> MASUK
                        </button>
                        
                        <div class="divider">PT GANDA ELANG TANGGUH</div>
                        
                        <div class="text-center">
                            <small class="text-muted" style="font-size: 12px;">
                                <i class="fas fa-shield-alt me-1" style="color: #ffd700;"></i>
                                CRM System v1.0
                            </small>
                            <br>
                            <small class="text-muted" style="font-size: 11px;">
                                &copy; <?= date('Y') ?> PT Ganda Elang Tangguh
                            </small>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="footer-text">
                <i class="fas fa-cogs me-1"></i>
                Powered by <a href="#">Global Media Creative</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
    </script>
</body>
</html>