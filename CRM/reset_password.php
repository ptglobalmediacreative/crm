<?php
require_once 'config.php';

// Jika sudah login, redirect ke dashboard
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$token = $_GET['token'] ?? '';
$email = $_GET['email'] ?? '';
$valid = false;
$userData = null;

// Validasi token
if (!empty($token) && !empty($email)) {
    $stmt = $db->prepare("SELECT * FROM password_resets WHERE email = ? AND token = ? AND used_at IS NULL AND expires_at > NOW()");
    $stmt->execute([$email, $token]);
    $reset = $stmt->fetch();
    
    if ($reset) {
        // Cek user
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $valid = true;
            $userData = $user;
        } else {
            setFlash('Akun tidak ditemukan atau tidak aktif!', 'danger');
        }
    } else {
        setFlash('Link reset tidak valid atau sudah kadaluarsa!', 'danger');
    }
} else {
    setFlash('Link reset tidak lengkap!', 'danger');
}

// Proses reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    
    if (strlen($password) < 6) {
        setFlash('Password minimal 6 karakter!', 'danger');
    } elseif ($password !== $confirm) {
        setFlash('Password tidak sama!', 'danger');
    } else {
        // Update password
        $hash = hashPassword($password);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hash, $email]);
        
        // Tandai token sudah digunakan
        $stmt = $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE email = ? AND token = ?");
        $stmt->execute([$email, $token]);
        
        setFlash('Password berhasil direset! Silakan login.', 'success');
        redirect('login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - PT Ganda Elang Tangguh</title>
    
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
        
        .reset-container {
            max-width: 420px;
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
            padding: 25px 20px;
            text-align: center;
            border: none;
        }
        
        .card-header .icon-wrapper {
            width: 70px;
            height: 70px;
            margin: 0 auto 10px;
            border-radius: 50%;
            background: rgba(255, 215, 0, 0.1);
            border: 2px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .card-header .icon-wrapper i {
            font-size: 32px;
            color: #ffd700;
        }
        
        .card-header h4 {
            color: #ffffff;
            margin: 0;
            font-weight: 700;
        }
        
        .card-header p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 13px;
            margin: 5px 0 0;
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
        
        .form-label i {
            color: #16213e;
            width: 18px;
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
        
        .btn-toggle-password {
            border: 2px solid #e8edf2;
            border-left: none;
            border-radius: 0 10px 10px 0;
            background: #f8f9fa;
            color: #888;
            padding: 0 15px;
            transition: all 0.3s ease;
        }
        
        .btn-toggle-password:hover {
            background: #e9ecef;
            color: #16213e;
        }
        
        .btn-reset {
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
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(22, 33, 62, 0.4);
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: #fff;
        }
        
        .btn-reset:active {
            transform: translateY(0);
        }
        
        .btn-back {
            color: #16213e;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.2s ease;
        }
        
        .btn-back:hover {
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
        
        .info-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #ffd700;
        }
        
        .info-box .email-label {
            font-size: 12px;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .info-box .email-value {
            font-size: 16px;
            font-weight: 600;
            color: #16213e;
            margin: 5px 0 0;
            word-break: break-all;
        }
        
        .password-requirements {
            font-size: 12px;
            color: #888;
            margin-top: 5px;
        }
        
        .password-requirements i {
            margin-right: 5px;
        }
        
        .password-requirements .valid {
            color: #28a745;
        }
        
        .password-requirements .invalid {
            color: #dc3545;
        }
        
        .invalid-feedback {
            font-size: 12px;
            color: #dc3545;
            margin-top: 5px;
        }
        
        @media (max-width: 480px) {
            .reset-container {
                padding: 10px;
            }
            
            .card-body {
                padding: 20px 15px;
            }
            
            .card-header .icon-wrapper {
                width: 60px;
                height: 60px;
            }
            
            .card-header .icon-wrapper i {
                font-size: 26px;
            }
            
            .card-header h4 {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="reset-container">
            <div class="card">
                <!-- Header -->
                <div class="card-header">
                    <div class="icon-wrapper">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h4>Reset Password</h4>
                    <p>Buat password baru untuk akun Anda</p>
                </div>
                
                <!-- Body -->
                <div class="card-body">
                    <?= showFlash() ?>
                    
                    <?php if ($valid): ?>
                        <!-- Informasi Email -->
                        <div class="info-box">
                            <div class="email-label">
                                <i class="fas fa-envelope me-1"></i> EMAIL AKUN
                            </div>
                            <div class="email-value">
                                <?= htmlspecialchars($email) ?>
                            </div>
                        </div>
                        
                        <!-- Form Reset Password -->
                        <form method="POST" id="resetForm">
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-lock"></i> Password Baru
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-key"></i>
                                    </span>
                                    <input 
                                        type="password" 
                                        name="password" 
                                        id="password"
                                        class="form-control" 
                                        placeholder="Minimal 6 karakter" 
                                        required
                                        minlength="6"
                                    >
                                    <button class="btn btn-toggle-password" type="button" id="togglePassword1">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-requirements">
                                    <i class="fas fa-circle" id="reqLength" style="color: #dc3545; font-size: 8px;"></i>
                                    <span id="reqLengthText">Minimal 6 karakter</span>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">
                                    <i class="fas fa-check"></i> Konfirmasi Password
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-check"></i>
                                    </span>
                                    <input 
                                        type="password" 
                                        name="confirm_password" 
                                        id="confirmPassword"
                                        class="form-control" 
                                        placeholder="Ulangi password" 
                                        required
                                    >
                                    <button class="btn btn-toggle-password" type="button" id="togglePassword2">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div id="confirmFeedback" class="invalid-feedback" style="display: none;">
                                    <i class="fas fa-times me-1"></i> Password tidak sama!
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-reset" id="resetBtn">
                                <i class="fas fa-save me-2"></i> RESET PASSWORD
                            </button>
                        </form>
                        
                        <div class="text-center mt-3">
                            <a href="login.php" class="btn-back">
                                <i class="fas fa-arrow-left me-1"></i> Kembali ke Login
                            </a>
                        </div>
                        
                    <?php else: ?>
                        <!-- Link Tidak Valid -->
                        <div class="text-center py-4">
                            <i class="fas fa-exclamation-triangle" style="font-size: 60px; color: #d63031;"></i>
                            <h5 class="mt-3">Link Tidak Valid</h5>
                            <p class="text-muted" style="font-size: 14px;">
                                Link reset password tidak valid atau sudah kadaluarsa.
                                <br>Silakan minta link baru.
                            </p>
                            <a href="forgot_password.php" class="btn-reset mt-3" style="display: inline-block; text-align: center; text-decoration: none; padding: 12px 30px;">
                                <i class="fas fa-key me-2"></i> Minta Link Baru
                            </a>
                            <br>
                            <a href="login.php" class="btn-back mt-3" style="display: inline-block;">
                                <i class="fas fa-arrow-left me-1"></i> Kembali ke Login
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle Password Visibility
        document.getElementById('togglePassword1').addEventListener('click', function() {
            const input = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        document.getElementById('togglePassword2').addEventListener('click', function() {
            const input = document.getElementById('confirmPassword');
            const icon = this.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });
        
        // Validasi Password
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const confirmFeedback = document.getElementById('confirmFeedback');
        const reqLength = document.getElementById('reqLength');
        const reqLengthText = document.getElementById('reqLengthText');
        
        // Cek panjang password
        password.addEventListener('input', function() {
            if (this.value.length >= 6) {
                reqLength.style.color = '#28a745';
                reqLength.className = 'fas fa-check-circle valid';
                reqLengthText.style.color = '#28a745';
            } else {
                reqLength.style.color = '#dc3545';
                reqLength.className = 'fas fa-circle invalid';
                reqLengthText.style.color = '#888';
            }
            
            // Cek konfirmasi jika sudah diisi
            if (confirmPassword.value.length > 0) {
                checkConfirm();
            }
        });
        
        // Cek konfirmasi password
        function checkConfirm() {
            if (password.value === confirmPassword.value && password.value.length >= 6) {
                confirmFeedback.style.display = 'none';
                confirmPassword.style.borderColor = '#28a745';
                return true;
            } else {
                confirmFeedback.style.display = 'block';
                confirmPassword.style.borderColor = '#dc3545';
                return false;
            }
        }
        
        confirmPassword.addEventListener('input', checkConfirm);
        
        // Submit form
        document.getElementById('resetForm').addEventListener('submit', function(e) {
            if (!checkConfirm()) {
                e.preventDefault();
                setFlash('Password tidak sama!', 'danger');
            }
        });
    </script>
</body>
</html>