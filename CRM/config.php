<?php
/**
 * Config untuk PT Ganda Elang Tangguh
 */

// ============================================
// DATABASE
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u475225363_crmget');
define('DB_USER', 'u475225363_crmget');
define('DB_PASS', 'Gandaelang123'); // Isi password Anda

// ============================================
// APLIKASI
// ============================================
define('APP_URL', 'https://' . $_SERVER['HTTP_HOST']);
define('APP_NAME', 'GET CRM - PT Ganda Elang Tangguh');
define('APP_EMAIL', 'noreply@crmget.com'); // Email pengirim

// ============================================
// EMAIL CONFIGURATION (SMTP)
// ============================================
define('SMTP_HOST', 'smtp.gmail.com'); // Ganti dengan SMTP Anda
define('SMTP_PORT', 587);
define('SMTP_USER', 'your-email@gmail.com'); // Ganti dengan email Anda
define('SMTP_PASS', 'your-app-password'); // Ganti dengan password/App Password
define('SMTP_FROM', 'noreply@crmget.com');
define('SMTP_FROM_NAME', 'PT Ganda Elang Tangguh');

// ============================================
// KONEKSI DATABASE
// ============================================
try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch(PDOException $e) {
    die("Koneksi gagal: " . $e->getMessage());
}

// ============================================
// FUNGSI BANTUAN
// ============================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function bersihkan($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function redirect($url) {
    header("Location: " . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function setFlash($message, $type = 'success') {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function showFlash() {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash']['message'];
        $type = $_SESSION['flash']['type'];
        $icon = $type === 'success' ? 'fa-check-circle' : ($type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle');
        unset($_SESSION['flash']);
        return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                    <i class='fas {$icon} alert-icon'></i>
                    {$msg}
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                </div>";
    }
    return '';
}

function getRole() {
    return $_SESSION['role'] ?? null;
}

function isAdmin() {
    return getRole() === 'admin';
}

// ============================================
// FUNCTION KIRIM EMAIL
// ============================================
function sendEmail($to, $subject, $message, $from = null, $fromName = null) {
    $from = $from ?? SMTP_FROM;
    $fromName = $fromName ?? SMTP_FROM_NAME;
    
    // Header email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: " . $fromName . " <" . $from . ">\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    
    // Kirim email
    return mail($to, $subject, $message, $headers);
}

// Fungsi generate token
function generateToken() {
    return bin2hex(random_bytes(32));
}
?>