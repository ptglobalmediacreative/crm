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
define('DB_PASS', 'Gandaelang123');

// ============================================
// APLIKASI
// ============================================
define('APP_URL', 'https://' . $_SERVER['HTTP_HOST']);
define('APP_NAME', 'GET CRM - PT Ganda Elang Tangguh');
define('APP_EMAIL', 'itsupport@gandaelang.co.id');

// ============================================
// EMAIL CONFIGURATION (SMTP)
// ============================================
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'itsupport@gandaelang.co.id');
define('SMTP_PASS', 'Natanael110405@');
define('SMTP_FROM', 'itsupport@gandaelang.co.id');
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

function generateToken() {
    return bin2hex(random_bytes(32));
}

// ============================================
// FUNCTION KIRIM EMAIL
// ============================================
function sendEmail($to, $subject, $message, $from = null, $fromName = null) {
    $from = $from ?? SMTP_FROM;
    $fromName = $fromName ?? SMTP_FROM_NAME;
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: " . $fromName . " <" . $from . ">\r\n";
    $headers .= "Reply-To: " . $from . "\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// ============================================
// FUNGSI PERMISSION - LENGKAP
// ============================================

/**
 * Cek apakah user memiliki akses ke module tertentu
 * @param string $module - Nama module (contoh: 'account_management')
 * @param string $action - Aksi (view, add, edit, delete)
 * @return bool
 */
function hasPermission($module, $action = 'view') {
    global $db;
    
    if (!isLoggedIn()) return false;
    
    $role = $_SESSION['role'] ?? 'user';
    
    // IT Support punya akses penuh
    if ($role === 'it_support') return true;
    
    // Jika role tidak ada di database, return false
    $stmt = $db->prepare("
        SELECT p.* FROM permissions p
        JOIN modules m ON m.id = p.module_id
        WHERE m.module_name = ? AND p.role_name = ?
    ");
    $stmt->execute([$module, $role]);
    $perm = $stmt->fetch();
    
    if (!$perm) return false;
    
    switch ($action) {
        case 'view': return $perm['can_view'] == 1;
        case 'add': return $perm['can_add'] == 1;
        case 'edit': return $perm['can_edit'] == 1;
        case 'delete': return $perm['can_delete'] == 1;
        default: return false;
    }
}

/**
 * Cek apakah user bisa mengakses menu (untuk hiding menu)
 */
function canAccessMenu($module) {
    return hasPermission($module, 'view');
}

/**
 * Cek apakah user bisa menambah data
 */
function canAdd($module) {
    return hasPermission($module, 'add');
}

/**
 * Cek apakah user bisa mengedit data
 */
function canEdit($module) {
    return hasPermission($module, 'edit');
}

/**
 * Cek apakah user bisa menghapus data
 */
function canDelete($module) {
    return hasPermission($module, 'delete');
}

/**
 * Cek apakah user memiliki salah satu role dari daftar
 */
function hasRole($roles) {
    if (!isLoggedIn()) return false;
    if (is_array($roles)) {
        return in_array($_SESSION['role'], $roles);
    }
    return $_SESSION['role'] === $roles;
}

/**
 * Cek apakah user punya akses penuh (IT Support)
 */
function isFullAccess() {
    return hasRole('it_support');
}

/**
 * Cek apakah user bisa mengelola user (IT Support atau Admin)
 */
function canManageUser() {
    return hasRole(['it_support', 'admin']);
}

// ============================================
// AMBIL MENU YANG BOLEH DIAKSES USER (HANYA MENU UTAMA)
// ============================================
function getUserMenus() {
    global $db;
    
    if (!isLoggedIn()) return [];
    
    $role = $_SESSION['role'] ?? 'user';
    
    // IT Support bisa lihat semua menu utama
    if ($role === 'it_support') {
        $stmt = $db->query("SELECT * FROM modules WHERE is_main_menu = 1 AND is_active = 1 ORDER BY module_order");
        return $stmt->fetchAll();
    }
    
    // Ambil permission berdasarkan role user (HANYA menu utama)
    $stmt = $db->prepare("
        SELECT m.* FROM modules m
        JOIN permissions p ON p.module_id = m.id
        WHERE p.role_name = ? 
        AND p.can_view = 1 
        AND m.is_main_menu = 1 
        AND m.is_active = 1
        ORDER BY m.module_order
    ");
    $stmt->execute([$role]);
    return $stmt->fetchAll();
}

/**
 * Ambil daftar menu names yang boleh diakses user
 */
function getUserMenuNames() {
    $menus = getUserMenus();
    return array_column($menus, 'module_name');
}

/**
 * Cek apakah user bisa akses halaman tertentu
 * Untuk digunakan di awal halaman
 */
function requirePermission($module, $action = 'view') {
    if (!isLoggedIn()) {
        setFlash('Silakan login dulu!', 'warning');
        redirect('login.php');
    }
    
    if (!hasPermission($module, $action)) {
        setFlash('Anda tidak memiliki akses ke halaman ini!', 'danger');
        redirect('dashboard.php');
    }
}

// ============================================
// FUNGSI UNTUK VIEW (MENYEMBUNYIKAN ELEMEN)
// ============================================

/**
 * Tampilkan elemen jika user punya permission
 * Digunakan untuk tombol/aksi di halaman
 */
function showIf($module, $action = 'view') {
    return hasPermission($module, $action);
}

/**
 * Tampilkan tombol tambah jika user punya akses
 */
function showAddButton($module) {
    return hasPermission($module, 'add');
}

/**
 * Tampilkan tombol edit jika user punya akses
 */
function showEditButton($module) {
    return hasPermission($module, 'edit');
}

/**
 * Tampilkan tombol hapus jika user punya akses
 */
function showDeleteButton($module) {
    return hasPermission($module, 'delete');
}

// ============================================
// FUNGSI CUSTOMER (Tambahan)
// ============================================

/**
 * Format tanggal Indonesia
 */
function formatTanggal($date) {
    return date('d/m/Y H:i', strtotime($date));
}

/**
 * Format rupiah
 */
function formatRupiah($number) {
    return 'Rp ' . number_format($number, 0, ',', '.');
}

/**
 * Generate slug
 */
function createSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}