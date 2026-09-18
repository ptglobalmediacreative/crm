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
    <link rel="stylesheet" href="css/login.css">
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