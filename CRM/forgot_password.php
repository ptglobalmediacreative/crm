<?php
require_once 'config.php';

// Jika sudah login, redirect ke dashboard
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$email = '';
$success = false;

// Proses kirim link reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = bersihkan($_POST['email']);
    
    if (empty($email)) {
        setFlash('Email wajib diisi!', 'danger');
    } else {
        // Cek apakah email terdaftar
        $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Generate token
            $token = generateToken();
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Hapus token lama
            $stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
            $stmt->execute([$email]);
            
            // Simpan token baru
            $stmt = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $stmt->execute([$email, $token, $expires]);
            
            // Buat link reset
            $resetLink = APP_URL . 'reset_password.php?token=' . $token . '&email=' . urlencode($email);
            
            // Template email
            $subject = "Reset Password - PT Ganda Elang Tangguh";
            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #1a1a2e; color: #fff; padding: 20px; text-align: center; }
                    .content { background: #f9f9f9; padding: 30px; }
                    .button { 
                        display: inline-block; 
                        background: #1a1a2e; 
                        color: #fff; 
                        padding: 12px 30px; 
                        text-decoration: none; 
                        border-radius: 5px;
                        margin: 20px 0;
                    }
                    .footer { text-align: center; padding: 20px; font-size: 12px; color: #999; }
                    .warning { color: #d63031; font-size: 13px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h2>PT GANDA ELANG TANGGUH</h2>
                        <p style='color: #ffd700;'>Dealer Alat Berat</p>
                    </div>
                    <div class='content'>
                        <h3>Reset Password</h3>
                        <p>Halo <strong>" . $user['full_name'] . "</strong>,</p>
                        <p>Kami menerima permintaan untuk mereset password akun Anda.</p>
                        <p>Klik tombol di bawah untuk mereset password:</p>
                        <p style='text-align: center;'>
                            <a href='{$resetLink}' class='button'>RESET PASSWORD</a>
                        </p>
                        <p>Atau copy link ini ke browser:</p>
                        <p><small style='word-break: break-all;'>{$resetLink}</small></p>
                        <p class='warning'>⚠️ Link ini akan kadaluarsa dalam 1 jam.</p>
                        <p>Jika Anda tidak meminta reset password, abaikan email ini.</p>
                    </div>
                    <div class='footer'>
                        <p>&copy; " . date('Y') . " PT Ganda Elang Tangguh</p>
                        <p>Jl. Pluit Karang Manis VI No.1E, RT.6/RW.8, Kecamatan Penjaringan Utara, Kecamatan Penjaringan, Jkt Utara, Daerah Khusus Ibukota Jakarta 14450</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Kirim email
            if (sendEmail($email, $subject, $message)) {
                setFlash('Link reset password telah dikirim ke email Anda. Cek inbox atau spam!', 'success');
                $success = true;
            } else {
                setFlash('Gagal mengirim email. Silakan coba lagi!', 'danger');
            }
        } else {
            setFlash('Email tidak terdaftar atau akun tidak aktif!', 'danger');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Password - PT Ganda Elang Tangguh</title>
    
    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
            padding: 25px 20px;
            text-align: center;
            border: none;
        }
        
        .card-header h4 {
            color: #fff;
            margin: 0;
        }
        
        .card-header h4 i {
            color: #ffd700;
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
        
        .btn-submit {
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
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(22, 33, 62, 0.4);
            background: linear-gradient(135deg, #16213e, #0f3460);
            color: #fff;
        }
        
        .btn-back {
            color: #16213e;
            text-decoration: none;
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
        
        .info-text {
            font-size: 13px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="card">
                <div class="card-header">
                    <h4><i class="fas fa-key me-2"></i>Lupa Password</h4>
                </div>
                <div class="card-body">
                    <?= showFlash() ?>
                    
                    <?php if (!$success): ?>
                    <p class="info-text mb-4">
                        Masukkan email Anda, kami akan kirim link untuk reset password.
                    </p>
                    
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
                                    value="<?= htmlspecialchars($email) ?>"
                                >
                            </div>
                        </div>
                        
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-paper-plane me-2"></i> KIRIM LINK RESET
                        </button>
                    </form>
                    
                    <div class="text-center mt-3">
                        <a href="login.php" class="btn-back">
                            <i class="fas fa-arrow-left me-1"></i> Kembali ke Login
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="text-center">
                        <i class="fas fa-check-circle" style="font-size: 60px; color: #28a745;"></i>
                        <h5 class="mt-3">Email Terkirim!</h5>
                        <p class="text-muted" style="font-size: 14px;">
                            Cek email Anda untuk link reset password.
                            <br><small>(Cek juga folder spam)</small>
                        </p>
                        <a href="login.php" class="btn-submit mt-3" style="display: inline-block; text-align: center; text-decoration: none;">
                            <i class="fas fa-sign-in-alt me-2"></i> Kembali ke Login
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>