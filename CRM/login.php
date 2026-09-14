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
        $stmt = $db->prepare("
            SELECT *
            FROM users
            WHERE email = ?
            AND is_active = 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && verifyPassword($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            setFlash(
                'Selamat datang, ' . $user['full_name'] . '!',
                'success'
            );

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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Login CRM — PT Ganda Elang Tangguh
    </title>

    <meta
        name="theme-color"
        content="#050a14"
    >

    <link
        rel="icon"
        type="image/webp"
        href="images/favicon.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Font Awesome -->
    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
    >

    <!-- Google Font -->
    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        /* =====================================================
           RESET
           ===================================================== */

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            min-height: 100%;
        }

        body {
            min-height: 100vh;

            font-family:
                'Inter',
                Arial,
                sans-serif;

            color: #e8eef9;

            background:
                radial-gradient(
                    circle at 15% 20%,
                    rgba(37, 99, 235, .16),
                    transparent 30%
                ),
                radial-gradient(
                    circle at 85% 80%,
                    rgba(30, 64, 175, .13),
                    transparent 32%
                ),
                linear-gradient(
                    135deg,
                    #030712 0%,
                    #07101f 45%,
                    #050a14 100%
                );

            overflow-x: hidden;
        }


        /* =====================================================
           BACKGROUND DECORATION
           ===================================================== */

        .bg-grid {
            position: fixed;

            inset: 0;

            pointer-events: none;

            opacity: .24;

            background-image:
                linear-gradient(
                    rgba(148, 163, 184, .035) 1px,
                    transparent 1px
                ),
                linear-gradient(
                    90deg,
                    rgba(148, 163, 184, .035) 1px,
                    transparent 1px
                );

            background-size:
                42px 42px;
        }

        .bg-glow {
            position: fixed;

            width: 420px;
            height: 420px;

            top: -180px;
            right: -150px;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(59, 130, 246, .15),
                    transparent 68%
                );

            filter: blur(5px);

            pointer-events: none;
        }

        .bg-glow-bottom {
            position: fixed;

            width: 360px;
            height: 360px;

            left: -160px;
            bottom: -160px;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(30, 64, 175, .12),
                    transparent 68%
                );

            pointer-events: none;
        }


        /* =====================================================
           PAGE
           ===================================================== */

        .login-page {
            position: relative;

            z-index: 2;

            min-height: 100vh;

            display: flex;

            align-items: center;
            justify-content: center;

            padding: 30px 20px;
        }


        /* =====================================================
           LOGIN WRAPPER
           ===================================================== */

        .login-wrapper {
            width: 100%;
            max-width: 930px;

            display: grid;

            grid-template-columns:
                minmax(300px, .9fr)
                minmax(380px, 1fr);

            border:
                1px solid
                rgba(148, 163, 184, .13);

            border-radius: 22px;

            overflow: hidden;

            background:
                rgba(8, 15, 29, .82);

            box-shadow:
                0 35px 90px rgba(0, 0, 0, .45),
                0 0 0 1px rgba(255, 255, 255, .015);

            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);

            animation:
                loginAppear .55s ease both;
        }

        @keyframes loginAppear {

            from {
                opacity: 0;
                transform: translateY(15px) scale(.985);
            }

            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }

        }


        /* =====================================================
           BRAND PANEL
           ===================================================== */

        .brand-panel {
            position: relative;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            min-height: 570px;

            padding: 42px 38px;

            background:
                linear-gradient(
                    145deg,
                    rgba(14, 29, 53, .96),
                    rgba(5, 12, 24, .96)
                );

            border-right:
                1px solid
                rgba(148, 163, 184, .09);

            overflow: hidden;
        }

        .brand-panel::before {
            content: "";

            position: absolute;

            width: 280px;
            height: 280px;

            top: -130px;
            right: -120px;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(59, 130, 246, .18),
                    transparent 68%
                );
        }

        .brand-panel::after {
            content: "";

            position: absolute;

            width: 220px;
            height: 220px;

            bottom: -120px;
            left: -100px;

            border-radius: 50%;

            background:
                radial-gradient(
                    circle,
                    rgba(37, 99, 235, .10),
                    transparent 68%
                );
        }


        /* =====================================================
           BRAND
           ===================================================== */

        .brand-content {
            position: relative;
            z-index: 2;
        }

        .brand-logo {
            width: 105px;
            height: 105px;

            display: flex;

            align-items: center;
            justify-content: center;

            margin-bottom: 24px;

            border:
                1px solid
                rgba(148, 163, 184, .12);

            border-radius: 20px;

            background:
                rgba(255, 255, 255, .025);

            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .04),
                0 18px 35px rgba(0, 0, 0, .2);

            transition:
                transform .3s ease,
                border-color .3s ease;
        }

        .brand-logo:hover {
            transform: translateY(-3px);

            border-color:
                rgba(96, 165, 250, .28);
        }

        .brand-logo img {
            width: 82px;
            height: 82px;

            object-fit: contain;
        }


        .brand-kicker {
            display: inline-flex;

            align-items: center;

            gap: 7px;

            margin-bottom: 11px;

            color: #60a5fa;

            font-size: 9px;
            font-weight: 800;

            letter-spacing: 1.8px;

            text-transform: uppercase;
        }

        .brand-kicker i {
            font-size: 7px;
        }

        .brand-title {
            margin: 0;

            color: #f1f5f9;

            font-family:
                'Plus Jakarta Sans',
                sans-serif;

            font-size: 25px;

            font-weight: 800;

            line-height: 1.18;

            letter-spacing: -.8px;
        }

        .brand-title span {
            color: #60a5fa;
        }

        .brand-description {
            max-width: 290px;

            margin-top: 13px;

            color: #71809a;

            font-size: 10px;

            line-height: 1.7;
        }


        /* =====================================================
           SYSTEM INFO
           ===================================================== */

        .system-info {
            position: relative;

            z-index: 2;
        }

        .system-line {
            display: flex;

            align-items: center;

            gap: 9px;

            margin-bottom: 9px;

            color: #71809a;

            font-size: 8px;
        }

        .system-line i {
            width: 23px;
            height: 23px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 7px;

            background:
                rgba(96, 165, 250, .08);

            color: #60a5fa;

            font-size: 9px;
        }

        .system-line strong {
            color: #aab7ca;

            font-weight: 600;
        }

        .system-status {
            display: inline-flex;

            align-items: center;

            gap: 6px;

            margin-top: 10px;

            padding:
                6px
                9px;

            border:
                1px solid
                rgba(52, 211, 153, .12);

            border-radius: 7px;

            background:
                rgba(52, 211, 153, .045);

            color: #6ee7b7;

            font-size: 7px;

            letter-spacing: .4px;
        }

        .status-dot {
            width: 5px;
            height: 5px;

            border-radius: 50%;

            background: #34d399;

            box-shadow:
                0 0 8px
                rgba(52, 211, 153, .7);
        }


        /* =====================================================
           LOGIN PANEL
           ===================================================== */

        .login-panel {
            display: flex;

            align-items: center;

            padding:
                42px
                45px;

            background:
                rgba(7, 13, 25, .78);
        }

        .login-inner {
            width: 100%;
            max-width: 390px;

            margin: 0 auto;
        }


        /* =====================================================
           LOGIN HEADER
           ===================================================== */

        .login-heading {
            margin-bottom: 28px;
        }

        .login-heading h1 {
            margin: 0;

            color: #f1f5f9;

            font-family:
                'Plus Jakarta Sans',
                sans-serif;

            font-size: 22px;

            font-weight: 800;

            letter-spacing: -.5px;
        }

        .login-heading p {
            margin:
                7px
                0
                0;

            color: #66758d;

            font-size: 9px;

            line-height: 1.6;
        }


        /* =====================================================
           ALERT
           ===================================================== */

        .alert {
            display: flex;

            align-items: center;

            margin-bottom: 18px;

            padding:
                10px
                12px;

            border:
                1px solid
                rgba(248, 113, 113, .16);

            border-radius: 9px;

            background:
                rgba(127, 29, 29, .12);

            color: #fca5a5;

            font-size: 9px;
        }

        .alert-success {
            border-color:
                rgba(52, 211, 153, .16);

            background:
                rgba(6, 78, 59, .12);

            color: #6ee7b7;
        }

        .alert-danger {
            border-color:
                rgba(248, 113, 113, .16);

            background:
                rgba(127, 29, 29, .12);

            color: #fca5a5;
        }


        /* =====================================================
           FORM GROUP
           ===================================================== */

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;

            margin-bottom: 8px;

            color: #aab7ca;

            font-size: 9px;

            font-weight: 700;

            letter-spacing: .2px;
        }

        .form-label i {
            margin-right: 5px;

            color: #64748b;
        }


        /* =====================================================
           INPUT
           ===================================================== */

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;

            top: 50%;
            left: 13px;

            transform:
                translateY(-50%);

            color: #52627a;

            font-size: 11px;

            pointer-events: none;

            transition:
                color .2s ease;
        }

        .form-control {
            width: 100%;

            height: 44px;

            padding:
                0
                40px;

            border:
                1px solid
                rgba(148, 163, 184, .14);

            border-radius: 9px;

            outline: none;

            background:
                rgba(15, 23, 42, .72);

            color: #e8eef9;

            font-family: inherit;

            font-size: 10px;

            transition:
                border-color .2s ease,
                background .2s ease,
                box-shadow .2s ease;
        }

        .form-control::placeholder {
            color: #3f4d63;
        }

        .form-control:hover {
            border-color:
                rgba(148, 163, 184, .22);
        }

        .form-control:focus {
            border-color:
                rgba(96, 165, 250, .55);

            background:
                rgba(15, 23, 42, .95);

            box-shadow:
                0 0 0 3px
                rgba(59, 130, 246, .08);
        }

        .input-wrap:focus-within .input-icon {
            color: #60a5fa;
        }


        /* =====================================================
           PASSWORD TOGGLE
           ===================================================== */

        .password-toggle {
            position: absolute;

            top: 50%;
            right: 12px;

            width: 25px;
            height: 25px;

            display: flex;

            align-items: center;
            justify-content: center;

            transform:
                translateY(-50%);

            border: 0;

            background: transparent;

            color: #52627a;

            cursor: pointer;

            transition:
                color .2s ease;
        }

        .password-toggle:hover {
            color: #60a5fa;
        }


        /* =====================================================
           OPTIONS
           ===================================================== */

        .login-options {
            display: flex;

            align-items: center;
            justify-content: space-between;

            gap: 12px;

            margin:
                4px
                0
                22px;
        }


        /* Remember */

        .remember {
            display: inline-flex;

            align-items: center;

            gap: 7px;

            color: #66758d;

            font-size: 8px;

            cursor: pointer;

            user-select: none;
        }

        .remember input {
            width: 14px;
            height: 14px;

            margin: 0;

            appearance: none;

            border:
                1px solid
                rgba(148, 163, 184, .22);

            border-radius: 4px;

            background:
                rgba(15, 23, 42, .8);

            cursor: pointer;

            transition:
                background .2s ease,
                border-color .2s ease;
        }

        .remember input:checked {
            border-color: #3b82f6;

            background:
                #2563eb;

            box-shadow:
                inset 0 0 0 3px
                #0f172a;
        }


        /* Forgot */

        .forgot-link {
            color: #64748b;

            font-size: 8px;
            font-weight: 500;

            text-decoration: none;

            transition:
                color .2s ease;
        }

        .forgot-link:hover {
            color: #60a5fa;

            text-decoration: none;
        }


        /* =====================================================
           LOGIN BUTTON
           ===================================================== */

        .btn-login {
            position: relative;

            width: 100%;
            height: 45px;

            display: flex;

            align-items: center;
            justify-content: center;

            gap: 8px;

            border: 0;

            border-radius: 9px;

            background:
                linear-gradient(
                    135deg,
                    #2563eb,
                    #1d4ed8
                );

            color: #ffffff;

            font-family: inherit;

            font-size: 10px;

            font-weight: 800;

            letter-spacing: .6px;

            cursor: pointer;

            overflow: hidden;

            box-shadow:
                0 10px 25px
                rgba(37, 99, 235, .16);

            transition:
                transform .2s ease,
                box-shadow .2s ease,
                background .2s ease;
        }

        .btn-login::before {
            content: "";

            position: absolute;

            top: 0;
            left: -100%;

            width: 70%;
            height: 100%;

            background:
                linear-gradient(
                    90deg,
                    transparent,
                    rgba(255,255,255,.12),
                    transparent
                );

            transform: skewX(-20deg);

            transition:
                left .5s ease;
        }

        .btn-login:hover {
            transform: translateY(-2px);

            background:
                linear-gradient(
                    135deg,
                    #3b82f6,
                    #2563eb
                );

            box-shadow:
                0 14px 32px
                rgba(37, 99, 235, .24);
        }

        .btn-login:hover::before {
            left: 140%;
        }

        .btn-login:active {
            transform: translateY(0);
        }


        /* =====================================================
           SECURITY NOTE
           ===================================================== */

        .security-note {
            display: flex;

            align-items: flex-start;

            gap: 9px;

            margin-top: 18px;

            padding:
                10px
                11px;

            border:
                1px solid
                rgba(148, 163, 184, .07);

            border-radius: 8px;

            background:
                rgba(15, 23, 42, .45);

            color: #53627a;

            font-size: 7px;

            line-height: 1.55;
        }

        .security-note i {
            margin-top: 1px;

            color: #34d399;

            font-size: 9px;
        }


        /* =====================================================
           FOOTER
           ===================================================== */

        .login-footer {
            margin-top: 25px;

            padding-top: 17px;

            border-top:
                1px solid
                rgba(148, 163, 184, .07);

            color: #3f4d63;

            font-size: 7px;

            text-align: center;
        }

        .login-footer strong {
            color: #596980;

            font-weight: 700;
        }

        .login-footer .version {
            margin-top: 4px;

            color: #334155;

            font-size: 6px;

            letter-spacing: .5px;
        }


        /* =====================================================
           MOBILE
           ===================================================== */

        @media (max-width: 850px) {

            .login-wrapper {
                max-width: 520px;

                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: auto;

                padding:
                    30px;

                border-right: 0;

                border-bottom:
                    1px solid
                    rgba(148, 163, 184, .09);
            }

            .brand-logo {
                width: 78px;
                height: 78px;

                margin-bottom: 18px;
            }

            .brand-logo img {
                width: 62px;
                height: 62px;
            }

            .brand-title {
                font-size: 21px;
            }

            .brand-description {
                max-width: 100%;
            }

            .system-info {
                margin-top: 28px;
            }

            .login-panel {
                padding:
                    32px 30px;
            }

        }


        @media (max-width: 520px) {

            .login-page {
                padding:
                    15px 10px;
            }

            .login-wrapper {
                border-radius: 17px;
            }

            .brand-panel {
                padding:
                    25px 22px;
            }

            .login-panel {
                padding:
                    28px 22px;
            }

            .brand-logo {
                width: 70px;
                height: 70px;
            }

            .brand-title {
                font-size: 19px;
            }

            .brand-description {
                font-size: 9px;
            }

            .login-heading h1 {
                font-size: 20px;
            }

            .login-options {
                align-items: flex-start;
            }

        }


        @media (max-width: 380px) {

            .login-panel {
                padding:
                    25px 18px;
            }

            .brand-panel {
                padding:
                    22px 18px;
            }

            .login-options {
                flex-direction: column;

                align-items: flex-start;

                gap: 10px;
            }

        }

    </style>

</head>


<body>

    <!-- Background -->
    <div class="bg-grid"></div>
    <div class="bg-glow"></div>
    <div class="bg-glow-bottom"></div>


    <main class="login-page">

        <div class="login-wrapper">


            <!-- =================================================
                 BRAND PANEL
                 ================================================= -->

            <section class="brand-panel">

                <div class="brand-content">

                    <div class="brand-logo">

                        <img
                            src="images/logo.webp"
                            alt="PT Ganda Elang Tangguh"
                        >

                    </div>


                    <div class="brand-kicker">

                        <i class="fas fa-circle"></i>

                        Dealer Management System

                    </div>


                    <h2 class="brand-title">

                        PT GANDA
                        <span>ELANG</span>
                        TANGGUH

                    </h2>


                    <p class="brand-description">

                        Centralized customer relationship and
                        dealer management platform for managing
                        sales activity, accounts, transactions,
                        products, and business operations.

                    </p>

                </div>


                <div class="system-info">

                    <div class="system-line">

                        <i class="fas fa-chart-line"></i>

                        <span>
                            <strong>CRM Platform</strong>
                            &nbsp;—&nbsp; Business Management
                        </span>

                    </div>


                    <div class="system-line">

                        <i class="fas fa-shield-halved"></i>

                        <span>
                            <strong>Secure Access</strong>
                            &nbsp;—&nbsp; Authorized Users Only
                        </span>

                    </div>


                    <div class="system-status">

                        <span class="status-dot"></span>

                        SYSTEM OPERATIONAL

                    </div>

                </div>

            </section>


            <!-- =================================================
                 LOGIN PANEL
                 ================================================= -->

            <section class="login-panel">

                <div class="login-inner">


                    <div class="login-heading">

                        <h1>
                            Welcome back
                        </h1>

                        <p>
                            Sign in to access your CRM workspace.
                        </p>

                    </div>


                    <!-- Flash Message -->

                    <?= showFlash() ?>


                    <form
                        method="POST"
                        autocomplete="on"
                    >


                        <!-- EMAIL -->

                        <div class="form-group">

                            <label class="form-label">

                                <i class="fas fa-envelope"></i>

                                Email Address

                            </label>


                            <div class="input-wrap">

                                <i class="fas fa-envelope input-icon"></i>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    placeholder="admin@email.com"
                                    autocomplete="email"
                                    required
                                    value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>"
                                >

                            </div>

                        </div>


                        <!-- PASSWORD -->

                        <div class="form-group">

                            <label class="form-label">

                                <i class="fas fa-lock"></i>

                                Password

                            </label>


                            <div class="input-wrap">

                                <i class="fas fa-key input-icon"></i>

                                <input
                                    type="password"
                                    name="password"
                                    id="password"
                                    class="form-control"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                    required
                                >


                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="togglePassword"
                                    aria-label="Show password"
                                >

                                    <i class="fas fa-eye"></i>

                                </button>

                            </div>

                        </div>


                        <!-- OPTIONS -->

                        <div class="login-options">

                            <label class="remember">

                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                >

                                <span>
                                    Ingat saya
                                </span>

                            </label>


                            <a
                                href="forgot_password.php"
                                class="forgot-link"
                            >

                                Lupa password?

                            </a>

                        </div>


                        <!-- LOGIN -->

                        <button
                            type="submit"
                            class="btn-login"
                        >

                            <i class="fas fa-arrow-right-to-bracket"></i>

                            MASUK KE CRM

                        </button>


                        <!-- SECURITY -->

                        <div class="security-note">

                            <i class="fas fa-shield-halved"></i>

                            <span>
                                Akses sistem ini hanya diperuntukkan
                                bagi pengguna yang memiliki otorisasi.
                                Pastikan kredensial Anda tetap aman.
                            </span>

                        </div>


                        <!-- FOOTER -->

                        <div class="login-footer">

                            <div>
                                <strong>
                                    PT Ganda Elang Tangguh
                                </strong>
                                &nbsp;•&nbsp;
                                Customer Relationship Management
                            </div>

                            <div class="version">
                                CRM SYSTEM v1.0
                            </div>

                        </div>

                    </form>

                </div>

            </section>

        </div>

    </main>


    <!-- Bootstrap JS -->

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
    ></script>


    <script>

        /* =====================================================
           PASSWORD TOGGLE
           ===================================================== */

        const togglePassword =
            document.getElementById('togglePassword');

        const password =
            document.getElementById('password');

        if (togglePassword && password) {

            togglePassword.addEventListener(
                'click',
                function () {

                    const isPassword =
                        password.type === 'password';

                    password.type =
                        isPassword
                            ? 'text'
                            : 'password';

                    const icon =
                        this.querySelector('i');

                    icon.className =
                        isPassword
                            ? 'fas fa-eye-slash'
                            : 'fas fa-eye';

                    this.setAttribute(
                        'aria-label',
                        isPassword
                            ? 'Hide password'
                            : 'Show password'
                    );

                }
            );

        }


        /* =====================================================
           AUTO DISMISS ALERT
           ===================================================== */

        setTimeout(function () {

            const alerts =
                document.querySelectorAll('.alert');

            alerts.forEach(function (alert) {

                if (!alert) {
                    return;
                }

                alert.style.transition =
                    'opacity .5s ease, transform .5s ease';

                alert.style.opacity = '0';

                alert.style.transform =
                    'translateY(-5px)';

                setTimeout(function () {

                    alert.remove();

                }, 500);

            });

        }, 5000);

    </script>

</body>

</html>