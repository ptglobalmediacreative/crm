<?php
require_once 'config.php';

// Jika sudah login, logout dulu agar bisa reset password
if (isLoggedIn()) {
    // Redirect ke logout dulu
    redirect('logout.php');
    exit();
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
            padding: 30px 20px 25px;
            text-align: center;
            border: none;
        }
        
        .card-header h4 {
            color: #ffffff;
            margin: 0;
            font-weight: 700;
            font-size: 22px;
        }
        
        .card-header p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 14px;
            margin: 8px 0 0;
        }
        
        .card-body {
            padding: 30px 25px 25px;
        }
        
        .form-label {
            font-weight: 600;
            font-size: 14px;
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
            font-size: 14px;
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
        
        .btn-minta-link {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            border: none;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            color: #fff;
            display: inline-block;
            text-align: center;
            text-decoration: none;
        }
        
        .btn-minta-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(22, 33, 62, 0.4);
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: #fff;
        }
        
        @media (max-width: 480px) {
            .reset-container {
                padding: 10px;
            }
            
            .card-body {
                padding: 20px 15px;
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
                    <h4>Reset Password</h4>
                    <p>Buat password baru untuk akun Anda</p>
                </div>
                
                <!-- Body -->
                <div class="card-body">
                    <?= showFlash() ?>
                    
                    <?php if ($valid): ?>
                        <!-- Informasi Email -->
                        <div class="info-box">
                            <div class="email-label">EMAIL AKUN</div>
                            <div class="email-value">
                                <?= htmlspecialchars($email) ?>
                            </div>
                        </div>
                        
                        <!-- Form Reset Password -->
                        <form method="POST" id="resetForm">
                            <div class="mb-3">
                                <label class="form-label">Password Baru</label>
                                <input 
                                    type="password" 
                                    name="password" 
                                    id="password"
                                    class="form-control" 
                                    placeholder="Minimal 6 karakter" 
                                    required
                                    minlength="6"
                                >
                                <div class="password-requirements">
                                    <span id="reqLengthText">Minimal 6 karakter</span>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label">Konfirmasi Password</label>
                                <input 
                                    type="password" 
                                    name="confirm_password" 
                                    id="confirmPassword"
                                    class="form-control" 
                                    placeholder="Ulangi password" 
                                    required
                                >
                                <div id="confirmFeedback" class="invalid-feedback" style="display: none;">
                                    Password tidak sama!
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-reset" id="resetBtn">
                                RESET PASSWORD
                            </button>
                        </form>
                        
                        <div class="text-center mt-3">
                            <a href="login.php" class="btn-back">
                                Kembali ke Login
                            </a>
                        </div>
                        
                    <?php else: ?>
                        <!-- Link Tidak Valid -->
                        <div class="text-center py-4">
                            <h5 style="color: #d63031;">Link Tidak Valid</h5>
                            <p class="text-muted" style="font-size: 14px;">
                                Link reset password tidak valid atau sudah kadaluarsa.
                                <br>Silakan minta link baru.
                            </p>
                            <a href="forgot_password.php" class="btn-minta-link">
                                Minta Link Baru
                            </a>
                            <br>
                            <a href="login.php" class="btn-back mt-3" style="display: inline-block;">
                                Kembali ke Login
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validasi Password
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const confirmFeedback = document.getElementById('confirmFeedback');
        const reqLengthText = document.getElementById('reqLengthText');
        
        // Cek panjang password
        password.addEventListener('input', function() {
            if (this.value.length >= 6) {
                reqLengthText.style.color = '#28a745';
                reqLengthText.innerHTML = '✓ Minimal 6 karakter';
            } else {
                reqLengthText.style.color = '#888';
                reqLengthText.innerHTML = 'Minimal 6 karakter';
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
                alert('Password tidak sama!');
            }
        });
    </script>
</body>
</html>