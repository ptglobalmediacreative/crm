<?php
/**
 * Config Sederhana untuk CRM
 * Database: u475225363_crmget
 */

// ============================================
// DATABASE
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u475225363_crmget');
define('DB_USER', 'u475225363_crmget');
define('DB_PASS', 'Gandaelang123'); // Isi dengan password database Anda

// ============================================
// APLIKASI
// ============================================
define('APP_URL', 'https://' . $_SERVER['HTTP_HOST']); // Auto detect domain
define('APP_NAME', 'CRM Sederhana');

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

// Mulai session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fungsi untuk amankan input
function bersihkan($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

// Fungsi hash password
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Fungsi verifikasi password
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Fungsi redirect
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Cek login
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Fungsi flash message
function setFlash($message, $type = 'success') {
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

// Tampilkan flash message
function showFlash() {
    if (isset($_SESSION['flash'])) {
        $msg = $_SESSION['flash']['message'];
        $type = $_SESSION['flash']['type'];
        unset($_SESSION['flash']);
        return "<div class='alert alert-{$type}'>{$msg}</div>";
    }
    return '';
}

// Cek role user
function getRole() {
    return $_SESSION['role'] ?? null;
}

// Cek apakah admin
function isAdmin() {
    return getRole() === 'admin';
}
?>