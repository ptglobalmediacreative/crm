<?php
require_once 'config.php';

// Jika sudah login, logout dulu agar bisa reset password
if (isLoggedIn()) {
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
    <title>Reset Password — PT Ganda Elang Tangguh</title>
    <meta name="theme-color" content="#050a14">

    <link rel="icon" type="image/webp" href="images/favicon.webp">
    <link rel="shortcut icon" type="image/webp" href="images/favicon.webp">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #050a14;
            --bg-2: #081121;
            --panel: rgba(8, 16, 31, .86);
            --panel-2: #0b1425;
            --line: rgba(148, 163, 184, .13);
            --line-soft: rgba(148, 163, 184, .08);
            --text: #f1f5f9;
            --muted: #71809a;
            --muted-2: #52627a;
            --blue: #60a5fa;
            --blue-strong: #2563eb;
            --green: #34d399;
            --red: #fb7185;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { min-height: 100%; }

        body {
            min-height: 100vh;
            overflow-x: hidden;
            font-family: 'Inter', Arial, sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 18%, rgba(37, 99, 235, .16), transparent 30%),
                radial-gradient(circle at 88% 78%, rgba(30, 64, 175, .12), transparent 32%),
                linear-gradient(135deg, #030712 0%, #07101f 48%, #050a14 100%);
        }

        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            opacity: .23;
            background-image:
                linear-gradient(rgba(148,163,184,.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(148,163,184,.035) 1px, transparent 1px);
            background-size: 42px 42px;
        }

        .glow {
            position: fixed;
            z-index: 0;
            width: 430px;
            height: 430px;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(3px);
        }

        .glow.one {
            top: -220px;
            right: -160px;
            background: radial-gradient(circle, rgba(59,130,246,.16), transparent 68%);
        }

        .glow.two {
            bottom: -230px;
            left: -180px;
            background: radial-gradient(circle, rgba(30,64,175,.13), transparent 68%);
        }

        .page {
            position: relative;
            z-index: 2;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px 18px;
        }

        .reset-shell {
            width: 100%;
            max-width: 940px;
            min-height: 560px;
            display: grid;
            grid-template-columns: minmax(300px, .82fr) minmax(390px, 1fr);
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--panel);
            box-shadow: 0 35px 90px rgba(0,0,0,.46), 0 0 0 1px rgba(255,255,255,.012);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            animation: shellIn .5s ease both;
        }

        @keyframes shellIn {
            from { opacity: 0; transform: translateY(14px) scale(.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* LEFT BRAND */
        .brand-panel {
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 560px;
            padding: 42px 38px;
            overflow: hidden;
            border-right: 1px solid var(--line-soft);
            background: linear-gradient(145deg, rgba(14,29,53,.96), rgba(5,12,24,.97));
        }

        .brand-panel::before,
        .brand-panel::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .brand-panel::before {
            width: 310px;
            height: 310px;
            top: -170px;
            right: -150px;
            background: radial-gradient(circle, rgba(59,130,246,.18), transparent 68%);
        }

        .brand-panel::after {
            width: 240px;
            height: 240px;
            left: -130px;
            bottom: -140px;
            background: radial-gradient(circle, rgba(37,99,235,.10), transparent 68%);
        }

        .brand-content,
        .brand-footer { position: relative; z-index: 1; }

        .brand-logo {
            width: 92px;
            height: 92px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            border: 1px solid rgba(148,163,184,.12);
            border-radius: 19px;
            background: rgba(255,255,255,.025);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 18px 35px rgba(0,0,0,.2);
        }

        .brand-logo img {
            width: 73px;
            height: 73px;
            object-fit: contain;
        }

        .kicker {
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

        .kicker i { font-size: 6px; }

        .brand-title {
            max-width: 290px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 25px;
            line-height: 1.17;
            font-weight: 800;
            letter-spacing: -.8px;
            color: var(--text);
        }

        .brand-title span { color: var(--blue); }

        .brand-copy {
            max-width: 295px;
            margin-top: 13px;
            color: var(--muted);
            font-size: 9px;
            line-height: 1.7;
        }

        .brand-footer {
            display: grid;
            gap: 9px;
        }

        .brand-info {
            display: flex;
            align-items: center;
            gap: 9px;
            color: var(--muted);
            font-size: 7px;
        }

        .brand-info i {
            width: 23px;
            height: 23px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            background: rgba(96,165,250,.08);
            color: var(--blue);
            font-size: 8px;
        }

        .brand-info strong { color: #aab7ca; font-weight: 700; }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            margin-top: 4px;
            padding: 6px 9px;
            border: 1px solid rgba(52,211,153,.13);
            border-radius: 7px;
            background: rgba(52,211,153,.045);
            color: #6ee7b7;
            font-size: 6.5px;
            font-weight: 700;
            letter-spacing: .45px;
        }

        .status-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 8px rgba(52,211,153,.7);
        }

        /* RIGHT FORM */
        .form-panel {
            display: flex;
            align-items: center;
            padding: 45px 48px;
            background: rgba(7,13,25,.78);
        }

        .form-inner {
            width: 100%;
            max-width: 390px;
            margin: 0 auto;
        }

        .heading {
            margin-bottom: 25px;
        }

        .heading-badge {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
            border: 1px solid rgba(96,165,250,.14);
            border-radius: 10px;
            background: rgba(96,165,250,.08);
            color: var(--blue);
            font-size: 14px;
        }

        .heading h1 {
            margin: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--text);
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -.55px;
        }

        .heading p {
            margin-top: 7px;
            color: #66758d;
            font-size: 8.5px;
            line-height: 1.6;
        }

        /* FLASH */
        .flash {
            margin-bottom: 17px;
        }

        .flash .alert {
            display: flex;
            align-items: center;
            min-height: 38px;
            margin: 0;
            padding: 9px 11px;
            border-radius: 9px;
            font-size: 8px;
            border: 1px solid transparent;
        }

        .flash .alert-success {
            border-color: rgba(52,211,153,.16);
            background: rgba(6,78,59,.12);
            color: #6ee7b7;
        }

        .flash .alert-danger {
            border-color: rgba(248,113,113,.16);
            background: rgba(127,29,29,.12);
            color: #fca5a5;
        }

        /* ACCOUNT */
        .account-box {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 20px;
            padding: 11px 12px;
            border: 1px solid rgba(148,163,184,.09);
            border-radius: 10px;
            background: rgba(15,23,42,.5);
        }

        .account-icon {
            width: 32px;
            height: 32px;
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: rgba(96,165,250,.09);
            color: var(--blue);
            font-size: 11px;
        }

        .account-meta { min-width: 0; }

        .account-label {
            color: #52627a;
            font-size: 6.5px;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .account-email {
            margin-top: 3px;
            color: #cbd5e1;
            font-size: 8.5px;
            font-weight: 600;
            word-break: break-all;
        }

        /* FIELDS */
        .field { margin-bottom: 17px; }

        .field-label {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 7px;
            color: #aab7ca;
            font-size: 8px;
            font-weight: 700;
        }

        .field-label i { color: #64748b; font-size: 8px; }

        .input-wrap { position: relative; }

        .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #52627a;
            font-size: 10px;
            pointer-events: none;
            transition: color .2s ease;
        }

        .input {
            width: 100%;
            height: 44px;
            padding: 0 40px;
            border: 1px solid rgba(148,163,184,.14);
            border-radius: 9px;
            outline: none;
            background: rgba(15,23,42,.72);
            color: #e8eef9;
            font-family: inherit;
            font-size: 9px;
            transition: border-color .2s ease, background .2s ease, box-shadow .2s ease;
        }

        .input::placeholder { color: #3f4d63; }

        .input:hover { border-color: rgba(148,163,184,.22); }

        .input:focus {
            border-color: rgba(96,165,250,.55);
            background: rgba(15,23,42,.95);
            box-shadow: 0 0 0 3px rgba(59,130,246,.08);
        }

        .input-wrap:focus-within .input-icon { color: var(--blue); }

        .toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translateY(-50%);
            border: 0;
            outline: 0;
            background: transparent;
            color: #52627a;
            cursor: pointer;
            transition: color .2s ease;
        }

        .toggle:hover { color: var(--blue); }

        .requirements {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            color: #52627a;
            font-size: 7px;
            transition: color .2s ease;
        }

        .requirements.valid { color: #6ee7b7; }
        .requirements.invalid { color: #fb7185; }

        .requirements i { font-size: 7px; }

        .match-feedback {
            display: none;
            margin-top: 6px;
            font-size: 7px;
            color: #fb7185;
        }

        .match-feedback.valid { color: #6ee7b7; }

        /* BUTTON */
        .btn-reset {
            position: relative;
            width: 100%;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 4px;
            border: 0;
            border-radius: 9px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #fff;
            font-family: inherit;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .65px;
            cursor: pointer;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(37,99,235,.16);
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .btn-reset::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 70%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,.12), transparent);
            transform: skewX(-20deg);
            transition: left .5s ease;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            box-shadow: 0 14px 32px rgba(37,99,235,.24);
        }

        .btn-reset:hover::before { left: 140%; }
        .btn-reset:active { transform: translateY(0); }

        /* BACK */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 17px;
            color: #64748b;
            font-size: 8px;
            font-weight: 600;
            text-decoration: none;
            transition: color .2s ease, transform .2s ease;
        }

        .back-link:hover {
            color: var(--blue);
            text-decoration: none;
            transform: translateX(-2px);
        }

        /* SECURITY */
        .security {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 18px;
            padding: 10px 11px;
            border: 1px solid rgba(148,163,184,.07);
            border-radius: 8px;
            background: rgba(15,23,42,.45);
            color: #53627a;
            font-size: 6.8px;
            line-height: 1.55;
        }

        .security i {
            margin-top: 1px;
            color: var(--green);
            font-size: 8px;
        }

        /* INVALID STATE */
        .invalid-state {
            padding: 26px 4px 6px;
            text-align: center;
        }

        .invalid-icon {
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 17px;
            border: 1px solid rgba(251,113,133,.14);
            border-radius: 15px;
            background: rgba(251,113,133,.07);
            color: var(--red);
            font-size: 20px;
        }

        .invalid-state h2 {
            margin: 0;
            color: var(--text);
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 800;
        }

        .invalid-state p {
            max-width: 300px;
            margin: 8px auto 21px;
            color: #66758d;
            font-size: 8.5px;
            line-height: 1.65;
        }

        .btn-new-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 39px;
            padding: 0 16px;
            border: 1px solid rgba(96,165,250,.16);
            border-radius: 8px;
            background: rgba(37,99,235,.10);
            color: #93c5fd;
            font-size: 8px;
            font-weight: 700;
            text-decoration: none;
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
        }

        .btn-new-link:hover {
            border-color: rgba(96,165,250,.30);
            background: rgba(37,99,235,.16);
            color: #bfdbfe;
            text-decoration: none;
            transform: translateY(-1px);
        }

        .footer {
            margin-top: 21px;
            padding-top: 15px;
            border-top: 1px solid rgba(148,163,184,.07);
            color: #3f4d63;
            font-size: 6.5px;
            text-align: center;
        }

        .footer strong { color: #596980; }

        .footer .version {
            margin-top: 4px;
            color: #334155;
            font-size: 6px;
            letter-spacing: .45px;
        }

        /* MOBILE */
        @media (max-width: 850px) {
            .reset-shell {
                max-width: 540px;
                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: auto;
                padding: 30px;
                border-right: 0;
                border-bottom: 1px solid var(--line-soft);
            }

            .brand-logo {
                width: 76px;
                height: 76px;
                margin-bottom: 18px;
            }

            .brand-logo img { width: 60px; height: 60px; }
            .brand-title { font-size: 21px; }
            .brand-copy { max-width: 100%; }
            .brand-footer { margin-top: 27px; }
            .form-panel { padding: 34px 30px; }
        }

        @media (max-width: 520px) {
            .page { padding: 12px 9px; }
            .reset-shell { border-radius: 17px; }
            .brand-panel { padding: 24px 21px; }
            .form-panel { padding: 28px 21px; }
            .brand-logo { width: 68px; height: 68px; }
            .brand-logo img { width: 53px; height: 53px; }
            .brand-title { font-size: 19px; }
            .heading h1 { font-size: 20px; }
        }

        @media (max-width: 380px) {
            .brand-panel { padding: 21px 17px; }
            .form-panel { padding: 25px 17px; }
        }
    </style>
</head>
<body>
    <div class="bg-grid"></div>
    <div class="glow one"></div>
    <div class="glow two"></div>

    <main class="page">
        <div class="reset-shell">

            <section class="brand-panel">
                <div class="brand-content">
                    <div class="brand-logo">
                        <img src="images/logo.webp" alt="PT Ganda Elang Tangguh">
                    </div>

                    <div class="kicker">
                        <i class="fas fa-circle"></i>
                        Account Security
                    </div>

                    <h2 class="brand-title">
                        SECURE YOUR<br>
                        <span>CRM ACCOUNT</span>
                    </h2>

                    <p class="brand-copy">
                        Atur ulang password akun Anda untuk menjaga keamanan
                        akses ke platform Customer Relationship Management
                        PT Ganda Elang Tangguh.
                    </p>
                </div>

                <div class="brand-footer">
                    <div class="brand-info">
                        <i class="fas fa-shield-halved"></i>
                        <span><strong>Protected Access</strong> — Authorized Users Only</span>
                    </div>

                    <div class="brand-info">
                        <i class="fas fa-clock"></i>
                        <span><strong>Reset Link</strong> — One-time secure access</span>
                    </div>

                    <div class="status">
                        <span class="status-dot"></span>
                        SECURITY SYSTEM OPERATIONAL
                    </div>
                </div>
            </section>

            <section class="form-panel">
                <div class="form-inner">

                    <div class="heading">
                        <div class="heading-badge">
                            <i class="fas fa-key"></i>
                        </div>

                        <?php if ($valid): ?>
                            <h1>Reset Password</h1>
                            <p>Buat password baru yang aman untuk akun CRM Anda.</p>
                        <?php else: ?>
                            <h1>Link Tidak Valid</h1>
                            <p>Link reset password tidak dapat digunakan.</p>
                        <?php endif; ?>
                    </div>

                    <div class="flash">
                        <?= showFlash() ?>
                    </div>

                    <?php if ($valid): ?>

                        <div class="account-box">
                            <div class="account-icon">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="account-meta">
                                <div class="account-label">Akun CRM</div>
                                <div class="account-email"><?= htmlspecialchars($email) ?></div>
                            </div>
                        </div>

                        <form method="POST" id="resetForm" autocomplete="off">

                            <div class="field">
                                <label class="field-label" for="password">
                                    <i class="fas fa-lock"></i>
                                    Password Baru
                                </label>

                                <div class="input-wrap">
                                    <i class="fas fa-key input-icon"></i>

                                    <input
                                        type="password"
                                        name="password"
                                        id="password"
                                        class="input"
                                        placeholder="Minimal 6 karakter"
                                        minlength="6"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="toggle"
                                        id="togglePassword"
                                        aria-label="Tampilkan password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>

                                <div class="requirements" id="reqLength">
                                    <i class="fas fa-circle-check"></i>
                                    <span id="reqLengthText">Minimal 6 karakter</span>
                                </div>
                            </div>

                            <div class="field">
                                <label class="field-label" for="confirmPassword">
                                    <i class="fas fa-shield-halved"></i>
                                    Konfirmasi Password
                                </label>

                                <div class="input-wrap">
                                    <i class="fas fa-key input-icon"></i>

                                    <input
                                        type="password"
                                        name="confirm_password"
                                        id="confirmPassword"
                                        class="input"
                                        placeholder="Ulangi password baru"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="toggle"
                                        id="toggleConfirm"
                                        aria-label="Tampilkan konfirmasi password"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>

                                <div id="confirmFeedback" class="match-feedback">
                                    <i class="fas fa-circle-xmark"></i>
                                    <span>Password belum sama</span>
                                </div>
                            </div>

                            <button type="submit" class="btn-reset" id="resetBtn">
                                <i class="fas fa-rotate"></i>
                                SIMPAN PASSWORD BARU
                            </button>

                        </form>

                        <a href="login.php" class="back-link">
                            <i class="fas fa-arrow-left"></i>
                            Kembali ke Login
                        </a>

                        <div class="security">
                            <i class="fas fa-shield-halved"></i>
                            <span>
                                Setelah password berhasil diubah, link reset ini akan langsung
                                dinonaktifkan dan tidak dapat digunakan kembali.
                            </span>
                        </div>

                    <?php else: ?>

                        <div class="invalid-state">
                            <div class="invalid-icon">
                                <i class="fas fa-link-slash"></i>
                            </div>

                            <h2>Reset Link Expired</h2>

                            <p>
                                Link reset password tidak valid, tidak lengkap,
                                atau sudah kadaluarsa. Silakan minta link reset baru.
                            </p>

                            <a href="forgot_password.php" class="btn-new-link">
                                <i class="fas fa-paper-plane"></i>
                                MINTA LINK BARU
                            </a>

                            <br>

                            <a href="login.php" class="back-link">
                                <i class="fas fa-arrow-left"></i>
                                Kembali ke Login
                            </a>
                        </div>

                    <?php endif; ?>

                    <div class="footer">
                        <div>
                            <strong>PT Ganda Elang Tangguh</strong>
                            &nbsp;•&nbsp; Customer Relationship Management
                        </div>
                        <div class="version">CRM SYSTEM v1.0</div>
                    </div>

                </div>
            </section>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirmPassword');
        const confirmFeedback = document.getElementById('confirmFeedback');
        const reqLength = document.getElementById('reqLength');
        const reqLengthText = document.getElementById('reqLengthText');
        const resetForm = document.getElementById('resetForm');

        function setupToggle(buttonId, input) {
            const button = document.getElementById(buttonId);
            if (!button || !input) return;

            button.addEventListener('click', function () {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';

                const icon = this.querySelector('i');
                if (icon) {
                    icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
                }

                this.setAttribute(
                    'aria-label',
                    show ? 'Sembunyikan password' : 'Tampilkan password'
                );
            });
        }

        setupToggle('togglePassword', password);
        setupToggle('toggleConfirm', confirmPassword);

        function checkLength() {
            if (!password || !reqLength || !reqLengthText) return false;

            const validLength = password.value.length >= 6;

            reqLength.classList.toggle('valid', validLength);
            reqLength.classList.toggle('invalid', password.value.length > 0 && !validLength);

            reqLengthText.textContent = validLength
                ? 'Password memenuhi minimal 6 karakter'
                : 'Minimal 6 karakter';

            return validLength;
        }

        function checkConfirm() {
            if (!password || !confirmPassword || !confirmFeedback) return false;

            if (!confirmPassword.value) {
                confirmFeedback.style.display = 'none';
                confirmPassword.style.borderColor = '';
                return false;
            }

            const matched =
                password.value === confirmPassword.value &&
                password.value.length >= 6;

            confirmFeedback.style.display = 'block';
            confirmFeedback.classList.toggle('valid', matched);

            const icon = confirmFeedback.querySelector('i');
            const text = confirmFeedback.querySelector('span');

            if (matched) {
                confirmPassword.style.borderColor = 'rgba(52,211,153,.45)';
                if (icon) icon.className = 'fas fa-circle-check';
                if (text) text.textContent = 'Password sudah sama';
            } else {
                confirmPassword.style.borderColor = 'rgba(251,113,133,.45)';
                if (icon) icon.className = 'fas fa-circle-xmark';
                if (text) text.textContent = 'Password belum sama';
            }

            return matched;
        }

        if (password) {
            password.addEventListener('input', function () {
                checkLength();
                if (confirmPassword && confirmPassword.value) checkConfirm();
            });
        }

        if (confirmPassword) {
            confirmPassword.addEventListener('input', checkConfirm);
        }

        if (resetForm) {
            resetForm.addEventListener('submit', function (event) {
                const validLength = checkLength();
                const matched = checkConfirm();

                if (!validLength || !matched) {
                    event.preventDefault();
                    return;
                }

                const button = document.getElementById('resetBtn');
                if (button) {
                    button.disabled = true;
                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> MENYIMPAN PASSWORD...';
                }
            });
        }

        setTimeout(function () {
            document.querySelectorAll('.flash .alert').forEach(function (alert) {
                alert.style.transition = 'opacity .45s ease, transform .45s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-5px)';

                setTimeout(function () {
                    alert.remove();
                }, 450);
            });
        }, 5000);
    </script>
</body>
</html>
