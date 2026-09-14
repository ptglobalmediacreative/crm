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
            $resetLink = APP_URL . '/CRM/reset_password.php?token=' . $token . '&email=' . urlencode($email);

            // Template email
            $subject = "Reset Password - PT Ganda Elang Tangguh";
            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #07101f; color: #fff; padding: 20px; text-align: center; }
                    .content { background: #f9f9f9; padding: 30px; }
                    .button {
                        display: inline-block;
                        background: #2563eb;
                        color: #fff;
                        padding: 12px 30px;
                        text-decoration: none;
                        border-radius: 6px;
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
                        <p style='color: #60a5fa;'>Dealer Alat Berat</p>
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
    <title>Lupa Password — PT Ganda Elang Tangguh</title>

    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg: #030712;
            --bg2: #07101f;
            --panel: #0a1221;
            --panel-soft: #0d1728;
            --line: rgba(148, 163, 184, .12);
            --text: #e8eef9;
            --muted: #71809a;
            --muted2: #52627a;
            --blue: #60a5fa;
            --blue-strong: #2563eb;
            --green: #34d399;
            --danger: #fca5a5;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            min-height: 100vh;
            overflow-x: hidden;

            font-family: 'Inter', Arial, sans-serif;
            color: var(--text);

            background:
                radial-gradient(circle at 10% 15%, rgba(37, 99, 235, .15), transparent 29%),
                radial-gradient(circle at 88% 82%, rgba(30, 64, 175, .13), transparent 31%),
                linear-gradient(135deg, var(--bg) 0%, var(--bg2) 48%, #050a14 100%);
        }

        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: .22;

            background-image:
                linear-gradient(rgba(148, 163, 184, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148, 163, 184, .035) 1px, transparent 1px);
            background-size: 42px 42px;
        }

        .bg-glow {
            position: fixed;
            z-index: 0;
            width: 430px;
            height: 430px;
            top: -190px;
            right: -160px;
            border-radius: 50%;
            pointer-events: none;

            background: radial-gradient(circle, rgba(59, 130, 246, .15), transparent 68%);
        }

        .bg-glow-bottom {
            position: fixed;
            z-index: 0;
            width: 360px;
            height: 360px;
            left: -170px;
            bottom: -170px;
            border-radius: 50%;
            pointer-events: none;

            background: radial-gradient(circle, rgba(37, 99, 235, .10), transparent 68%);
        }

        .page {
            position: relative;
            z-index: 2;

            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;

            padding: 30px 20px;
        }

        .auth-shell {
            width: 100%;
            max-width: 900px;

            display: grid;
            grid-template-columns: minmax(300px, .9fr) minmax(390px, 1fr);

            overflow: hidden;

            border: 1px solid var(--line);
            border-radius: 22px;

            background: rgba(8, 15, 29, .84);

            box-shadow:
                0 35px 90px rgba(0, 0, 0, .46),
                0 0 0 1px rgba(255, 255, 255, .015);

            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);

            animation: appear .55s ease both;
        }

        @keyframes appear {
            from {
                opacity: 0;
                transform: translateY(14px) scale(.985);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* LEFT BRAND PANEL */
        .brand-panel {
            position: relative;
            min-height: 540px;
            padding: 40px 36px;

            display: flex;
            flex-direction: column;
            justify-content: space-between;

            overflow: hidden;

            background:
                linear-gradient(145deg, rgba(14, 29, 53, .97), rgba(5, 12, 24, .97));

            border-right: 1px solid rgba(148, 163, 184, .09);
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            width: 290px;
            height: 290px;
            top: -145px;
            right: -130px;
            border-radius: 50%;

            background: radial-gradient(circle, rgba(59, 130, 246, .18), transparent 68%);
        }

        .brand-panel::after {
            content: "";
            position: absolute;
            width: 240px;
            height: 240px;
            left: -120px;
            bottom: -130px;
            border-radius: 50%;

            background: radial-gradient(circle, rgba(37, 99, 235, .10), transparent 68%);
        }

        .brand-content,
        .brand-bottom {
            position: relative;
            z-index: 2;
        }

        .logo-box {
            width: 92px;
            height: 92px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 24px;

            border: 1px solid rgba(148, 163, 184, .12);
            border-radius: 19px;

            background: rgba(255, 255, 255, .025);

            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .04),
                0 18px 35px rgba(0, 0, 0, .20);
        }

        .logo-box img {
            width: 72px;
            height: 72px;
            object-fit: contain;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;

            margin-bottom: 10px;

            color: var(--blue);

            font-size: 8px;
            font-weight: 800;
            letter-spacing: 1.7px;
            text-transform: uppercase;
        }

        .eyebrow i {
            font-size: 6px;
        }

        .brand-title {
            margin: 0;

            color: #f1f5f9;

            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -.8px;
        }

        .brand-title span {
            color: var(--blue);
        }

        .brand-copy {
            max-width: 290px;
            margin-top: 13px;

            color: var(--muted);

            font-size: 9px;
            line-height: 1.7;
        }

        .brand-point {
            display: flex;
            align-items: center;
            gap: 9px;

            margin-top: 13px;

            color: #8b99ae;
            font-size: 8px;
        }

        .brand-point i {
            width: 24px;
            height: 24px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 7px;
            background: rgba(96, 165, 250, .08);
            color: var(--blue);

            font-size: 9px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;

            margin-top: 18px;
            padding: 6px 9px;

            border: 1px solid rgba(52, 211, 153, .12);
            border-radius: 7px;

            background: rgba(52, 211, 153, .045);
            color: #6ee7b7;

            font-size: 7px;
            letter-spacing: .35px;
        }

        .status-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px rgba(52, 211, 153, .75);
        }

        /* RIGHT PANEL */
        .form-panel {
            min-height: 540px;
            display: flex;
            align-items: center;

            padding: 42px 46px;

            background: rgba(7, 13, 25, .78);
        }

        .form-inner {
            width: 100%;
            max-width: 390px;
            margin: auto;
        }

        .form-heading {
            margin-bottom: 25px;
        }

        .icon-title {
            width: 43px;
            height: 43px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 16px;

            border: 1px solid rgba(96, 165, 250, .14);
            border-radius: 11px;

            background: rgba(96, 165, 250, .08);
            color: var(--blue);

            font-size: 14px;
        }

        .form-heading h1 {
            margin: 0;

            color: #f1f5f9;

            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -.5px;
        }

        .form-heading p {
            margin: 7px 0 0;

            color: #66758d;
            font-size: 9px;
            line-height: 1.6;
        }

        .flash-wrap .alert {
            margin-bottom: 17px;

            padding: 10px 12px;

            border-radius: 9px;
            border: 1px solid rgba(248, 113, 113, .16);

            background: rgba(127, 29, 29, .12);
            color: var(--danger);

            font-size: 8px;
        }

        .flash-wrap .alert-success {
            border-color: rgba(52, 211, 153, .16);
            background: rgba(6, 78, 59, .12);
            color: #6ee7b7;
        }

        .info-box {
            display: flex;
            gap: 10px;
            align-items: flex-start;

            margin-bottom: 20px;
            padding: 11px 12px;

            border: 1px solid rgba(96, 165, 250, .10);
            border-radius: 9px;

            background: rgba(30, 64, 175, .055);

            color: #687892;
            font-size: 8px;
            line-height: 1.6;
        }

        .info-box i {
            margin-top: 1px;
            color: var(--blue);
            font-size: 9px;
        }

        .form-group {
            margin-bottom: 17px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;

            color: #aab7ca;

            font-size: 8px;
            font-weight: 700;
            letter-spacing: .2px;
        }

        .form-label i {
            margin-right: 5px;
            color: #64748b;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            z-index: 2;

            top: 50%;
            left: 13px;

            transform: translateY(-50%);

            color: #52627a;
            font-size: 10px;

            pointer-events: none;
            transition: color .2s ease;
        }

        .form-control {
            width: 100%;
            height: 44px;

            padding: 0 13px 0 39px;

            border: 1px solid rgba(148, 163, 184, .14);
            border-radius: 9px;

            outline: none;

            background: rgba(15, 23, 42, .72);
            color: #e8eef9;

            font-family: inherit;
            font-size: 9px;

            transition:
                border-color .2s ease,
                background .2s ease,
                box-shadow .2s ease;
        }

        .form-control::placeholder {
            color: #3f4d63;
        }

        .form-control:hover {
            border-color: rgba(148, 163, 184, .22);
        }

        .form-control:focus {
            border-color: rgba(96, 165, 250, .55);
            background: rgba(15, 23, 42, .95);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .08);
        }

        .input-wrap:focus-within .input-icon {
            color: var(--blue);
        }

        .submit-btn {
            position: relative;

            width: 100%;
            height: 45px;

            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            margin-top: 5px;

            border: 0;
            border-radius: 9px;

            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;

            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .7px;

            cursor: pointer;
            overflow: hidden;

            box-shadow: 0 10px 25px rgba(37, 99, 235, .16);

            transition:
                transform .2s ease,
                box-shadow .2s ease,
                background .2s ease;
        }

        .submit-btn::before {
            content: "";

            position: absolute;
            top: 0;
            left: -100%;

            width: 70%;
            height: 100%;

            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, .12),
                transparent
            );

            transform: skewX(-20deg);
            transition: left .5s ease;
        }

        .submit-btn:hover {
            transform: translateY(-2px);

            background: linear-gradient(135deg, #3b82f6, #2563eb);

            box-shadow: 0 14px 32px rgba(37, 99, 235, .24);
        }

        .submit-btn:hover::before {
            left: 140%;
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .back-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;

            margin-top: 18px;

            color: #64748b;

            font-size: 8px;
            font-weight: 600;

            text-decoration: none;

            transition:
                color .2s ease,
                transform .2s ease;
        }

        .back-link:hover {
            color: var(--blue);
            transform: translateX(-2px);
            text-decoration: none;
        }

        .success-state {
            text-align: center;
        }

        .success-icon {
            width: 65px;
            height: 65px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin: 0 auto 18px;

            border: 1px solid rgba(52, 211, 153, .14);
            border-radius: 17px;

            background: rgba(52, 211, 153, .07);
            color: var(--green);

            font-size: 24px;

            box-shadow: 0 14px 35px rgba(0, 0, 0, .18);
        }

        .success-state h2 {
            margin: 0;

            color: #f1f5f9;

            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 800;
        }

        .success-state p {
            margin: 8px auto 0;
            max-width: 300px;

            color: #71809a;
            font-size: 8px;
            line-height: 1.7;
        }

        .success-note {
            margin: 17px 0 20px;
            padding: 10px;

            border: 1px solid rgba(148, 163, 184, .08);
            border-radius: 8px;

            background: rgba(15, 23, 42, .48);

            color: #596980;
            font-size: 7px;
            line-height: 1.55;
        }

        .security-note {
            display: flex;
            gap: 9px;
            align-items: flex-start;

            margin-top: 17px;
            padding: 10px 11px;

            border: 1px solid rgba(148, 163, 184, .07);
            border-radius: 8px;

            background: rgba(15, 23, 42, .42);

            color: #53627a;
            font-size: 7px;
            line-height: 1.55;
        }

        .security-note i {
            margin-top: 1px;
            color: var(--green);
            font-size: 9px;
        }

        .footer {
            margin-top: 23px;
            padding-top: 15px;

            border-top: 1px solid rgba(148, 163, 184, .07);

            color: #3f4d63;

            font-size: 7px;
            line-height: 1.6;

            text-align: center;
        }

        .footer strong {
            color: #596980;
        }

        .footer .version {
            margin-top: 3px;
            color: #334155;
            font-size: 6px;
            letter-spacing: .5px;
        }

        @media (max-width: 850px) {
            .auth-shell {
                max-width: 520px;
                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: auto;
                padding: 30px;
                border-right: 0;
                border-bottom: 1px solid rgba(148, 163, 184, .09);
            }

            .form-panel {
                min-height: auto;
                padding: 34px 30px;
            }

            .brand-copy {
                max-width: 100%;
            }

            .brand-bottom {
                margin-top: 25px;
            }
        }

        @media (max-width: 520px) {
            .page {
                padding: 14px 10px;
            }

            .auth-shell {
                border-radius: 17px;
            }

            .brand-panel {
                padding: 25px 22px;
            }

            .form-panel {
                padding: 28px 22px;
            }

            .logo-box {
                width: 74px;
                height: 74px;
                margin-bottom: 18px;
            }

            .logo-box img {
                width: 59px;
                height: 59px;
            }

            .brand-title {
                font-size: 20px;
            }

            .form-heading h1 {
                font-size: 19px;
            }
        }

        @media (max-width: 380px) {
            .brand-panel,
            .form-panel {
                padding-left: 18px;
                padding-right: 18px;
            }
        }
    </style>
</head>

<body>
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>
    <div class="bg-glow-bottom"></div>

    <main class="page">
        <div class="auth-shell">

            <!-- BRAND -->
            <section class="brand-panel">
                <div class="brand-content">

                    <div class="logo-box">
                        <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
                    </div>

                    <div class="eyebrow">
                        <i class="fas fa-circle"></i>
                        CRM Account Recovery
                    </div>

                    <h2 class="brand-title">
                        PT GANDA
                        <span>ELANG</span>
                        TANGGUH
                    </h2>

                    <p class="brand-copy">
                        Recover your CRM account securely and regain
                        access to your sales, customer, transaction,
                        and business management workspace.
                    </p>
                </div>

                <div class="brand-bottom">
                    <div class="brand-point">
                        <i class="fas fa-shield-halved"></i>
                        <span>Secure account recovery process</span>
                    </div>

                    <div class="brand-point">
                        <i class="fas fa-clock"></i>
                        <span>Reset link is valid for 1 hour</span>
                    </div>

                    <div class="status">
                        <span class="status-dot"></span>
                        CRM SYSTEM OPERATIONAL
                    </div>
                </div>
            </section>

            <!-- FORM -->
            <section class="form-panel">
                <div class="form-inner">

                    <div class="form-heading">
                        <div class="icon-title">
                            <i class="fas fa-key"></i>
                        </div>

                        <h1>
                            Lupa Password?
                        </h1>

                        <p>
                            Jangan khawatir. Masukkan email akun CRM Anda
                            dan kami akan mengirimkan link untuk membuat
                            password baru.
                        </p>
                    </div>

                    <div class="flash-wrap">
                        <?= showFlash() ?>
                    </div>

                    <?php if (!$success): ?>

                        <div class="info-box">
                            <i class="fas fa-circle-info"></i>
                            <span>
                                Gunakan email yang terdaftar dan masih aktif
                                pada sistem CRM PT Ganda Elang Tangguh.
                            </span>
                        </div>

                        <form method="POST" id="forgotForm">

                            <div class="form-group">
                                <label class="form-label" for="email">
                                    <i class="fas fa-envelope"></i>
                                    Email Address
                                </label>

                                <div class="input-wrap">
                                    <i class="fas fa-envelope input-icon"></i>

                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        class="form-control"
                                        placeholder="admin@email.com"
                                        autocomplete="email"
                                        required
                                        value="<?= htmlspecialchars($email) ?>"
                                    >
                                </div>
                            </div>

                            <button
                                type="submit"
                                class="submit-btn"
                                id="submitBtn"
                            >
                                <i class="fas fa-paper-plane"></i>
                                KIRIM LINK RESET
                            </button>

                        </form>

                        <a href="login.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Kembali ke Login
                        </a>

                        <div class="security-note">
                            <i class="fas fa-shield-halved"></i>
                            <span>
                                Demi keamanan, link reset hanya berlaku
                                selama 1 jam. Jika Anda tidak meminta reset,
                                abaikan email tersebut.
                            </span>
                        </div>

                    <?php else: ?>

                        <div class="success-state">

                            <div class="success-icon">
                                <i class="fas fa-check"></i>
                            </div>

                            <h2>
                                Email Terkirim
                            </h2>

                            <p>
                                Link reset password telah dikirim ke
                                alamat email Anda. Silakan periksa
                                inbox atau folder spam.
                            </p>

                            <div class="success-note">
                                <i class="fas fa-clock me-1"></i>
                                Link reset akan kadaluarsa dalam
                                <strong>1 jam</strong>.
                            </div>

                            <a
                                href="login.php"
                                class="submit-btn"
                                style="text-decoration:none;"
                            >
                                <i class="fas fa-arrow-right-to-bracket"></i>
                                KEMBALI KE LOGIN
                            </a>

                        </div>

                    <?php endif; ?>

                    <div class="footer">
                        <div>
                            <strong>PT Ganda Elang Tangguh</strong>
                            &nbsp;•&nbsp;
                            Customer Relationship Management
                        </div>

                        <div class="version">
                            CRM SYSTEM v1.0
                        </div>
                    </div>

                </div>
            </section>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const form = document.getElementById('forgotForm');
        const submitBtn = document.getElementById('submitBtn');

        if (form && submitBtn) {
            form.addEventListener('submit', function () {
                submitBtn.disabled = true;
                submitBtn.innerHTML =
                    '<i class="fas fa-spinner fa-spin"></i> MENGIRIM...';
                submitBtn.style.opacity = '0.75';
                submitBtn.style.cursor = 'wait';
            });
        }

        setTimeout(function () {
            document.querySelectorAll('.alert').forEach(function (alert) {
                alert.style.transition =
                    'opacity .5s ease, transform .5s ease';

                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-5px)';

                setTimeout(function () {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>
