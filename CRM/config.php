<?php
/**
 * Config untuk PT Ganda Elang Tangguh
 *
 * Catatan:
 * 1. Jangan gunakan password DB/email yang pernah dibagikan di chat.
 * 2. Isi DB_PASS dengan password database aktif.
 * 3. Isi SMTP_PASS dengan password email Hostinger aktif.
 */

// ============================================
// DATABASE
// ============================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'u475225363_crmget');
define('DB_USER', 'u475225363_crmget');
define('DB_PASS', 'GETGroup2023');

// ============================================
// APLIKASI
// ============================================
$httpHost = $_SERVER['HTTP_HOST'] ?? 'gandaelang.co.id';
define('APP_URL', 'https://' . $httpHost);
define('APP_NAME', 'GET CRM - PT Ganda Elang Tangguh');
define('APP_EMAIL', 'itsupport@gandaelang.co.id');

// ============================================
// EMAIL CONFIGURATION - HOSTINGER SMTP
// ============================================
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_USER', 'itsupport@gandaelang.co.id');
define('SMTP_PASS', 'Natanael110405!');
define('SMTP_FROM', 'itsupport@gandaelang.co.id');
define('SMTP_FROM_NAME', 'PT Ganda Elang Tangguh');

// ============================================
// KONEKSI DATABASE
// ============================================
try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log('[GET CRM DB] ' . $e->getMessage());
    die('Koneksi database gagal. Silakan hubungi IT Support.');
}

// ============================================
// SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// FUNGSI BANTUAN
// ============================================
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
    header('Location: ' . $url);
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
        $icon = $type === 'success'
            ? 'fa-check-circle'
            : ($type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle');

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
// LOAD PHPMailer
// ============================================
function loadPHPMailer() {
    static $loaded = false;

    if ($loaded) {
        return true;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        error_log('[GET CRM EMAIL] vendor/autoload.php tidak ditemukan: ' . $autoload);
        return false;
    }

    require_once $autoload;

    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('[GET CRM EMAIL] Class PHPMailer tidak ditemukan.');
        return false;
    }

    $loaded = true;
    return true;
}

// ============================================
// FUNGSI KIRIM EMAIL - PHPMailer SMTP
// ============================================
function sendEmail($to, $subject, $message, $from = null, $fromName = null) {

    if (!loadPHPMailer()) {
        return false;
    }

    $from = $from ?? SMTP_FROM;
    $fromName = $fromName ?? SMTP_FROM_NAME;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // ----------------------------------------
        // SMTP HOSTINGER
        // ----------------------------------------
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;

        // Hostinger SMTP port 465 = SSL
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = SMTP_PORT;

        // Timeout supaya proses tidak menggantung terlalu lama
        $mail->Timeout = 30;
        $mail->SMTPKeepAlive = false;

        // ----------------------------------------
        // DEBUG
        // 0 = normal
        // 2 = aktif untuk troubleshooting
        // ----------------------------------------
        $mail->SMTPDebug = 0;

        // ----------------------------------------
        // SENDER
        // ----------------------------------------
        $mail->setFrom($from, $fromName);

        // ----------------------------------------
        // RECIPIENT
        // ----------------------------------------
        $mail->addAddress($to);

        // ----------------------------------------
        // EMAIL CONTENT
        // ----------------------------------------
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->Subject = $subject;
        $mail->Body = $message;

        // Versi text jika client email tidak membaca HTML
        $mail->AltBody = trim(
            html_entity_decode(
                strip_tags(
                    preg_replace('/<br\s*\/?>/i', "\n", $message)
                ),
                ENT_QUOTES,
                'UTF-8'
            )
        );

        // ----------------------------------------
        // SEND
        // ----------------------------------------
        $mail->send();

        return true;

    } catch (\Throwable $e) {
        error_log(
            '[GET CRM EMAIL] Gagal kirim email ke ' .
            $to .
            ' | Subject: ' .
            $subject .
            ' | Error: ' .
            $e->getMessage()
        );

        return false;
    }
}

// ============================================
// FUNGSI PERMISSION
// ============================================

/**
 * Cek apakah user memiliki akses ke module tertentu.
 *
 * @param string $module Nama module
 * @param string $action view, add, edit, delete
 */
function hasPermission($module, $action = 'view') {
    global $db;

    if (!isLoggedIn()) {
        return false;
    }

    $role = $_SESSION['role'] ?? 'user';

    // IT Support memiliki akses penuh
    if ($role === 'it_support') {
        return true;
    }

    $stmt = $db->prepare("
        SELECT p.*
        FROM permissions p
        JOIN modules m ON m.id = p.module_id
        WHERE m.module_name = ?
          AND p.role_name = ?
    ");

    $stmt->execute([$module, $role]);
    $perm = $stmt->fetch();

    if (!$perm) {
        return false;
    }

    switch ($action) {
        case 'view':
            return (int)$perm['can_view'] === 1;

        case 'add':
            return (int)$perm['can_add'] === 1;

        case 'edit':
            return (int)$perm['can_edit'] === 1;

        case 'delete':
            return (int)$perm['can_delete'] === 1;

        default:
            return false;
    }
}

/**
 * Cek apakah user bisa mengakses menu.
 */
function canAccessMenu($module) {
    return hasPermission($module, 'view');
}

/**
 * Cek apakah user bisa menambah data.
 */
function canAdd($module) {
    return hasPermission($module, 'add');
}

/**
 * Cek apakah user bisa mengedit data.
 */
function canEdit($module) {
    return hasPermission($module, 'edit');
}

/**
 * Cek apakah user bisa menghapus data.
 */
function canDelete($module) {
    return hasPermission($module, 'delete');
}

/**
 * Cek apakah user memiliki salah satu role.
 */
function hasRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }

    if (is_array($roles)) {
        return in_array($_SESSION['role'], $roles, true);
    }

    return $_SESSION['role'] === $roles;
}

/**
 * Cek apakah user punya akses penuh.
 */
function isFullAccess() {
    return hasRole('it_support');
}

/**
 * Cek apakah user bisa mengelola user.
 */
function canManageUser() {
    return hasRole(['it_support', 'admin']);
}

/**
 * Ambil menu yang boleh diakses user.
 */
function getUserMenus() {
    global $db;

    if (!isLoggedIn()) {
        return [];
    }

    $role = $_SESSION['role'] ?? 'user';

    // IT Support bisa melihat semua menu utama
    if ($role === 'it_support') {
        $stmt = $db->query("
            SELECT *
            FROM modules
            WHERE is_main_menu = 1
              AND is_active = 1
            ORDER BY module_order
        ");

        return $stmt->fetchAll();
    }

    // Menu berdasarkan permission
    $stmt = $db->prepare("
        SELECT m.*
        FROM modules m
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
 * Ambil daftar nama menu yang boleh diakses.
 */
function getUserMenuNames() {
    $menus = getUserMenus();
    return array_column($menus, 'module_name');
}

/**
 * Cek permission halaman.
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
// FUNGSI UNTUK VIEW
// ============================================

function showIf($module, $action = 'view') {
    return hasPermission($module, $action);
}

function showAddButton($module) {
    return hasPermission($module, 'add');
}

function showEditButton($module) {
    return hasPermission($module, 'edit');
}

function showDeleteButton($module) {
    return hasPermission($module, 'delete');
}

// ============================================
// FUNGSI CUSTOMER / FORMAT
// ============================================

function formatTanggal($date) {
    return date('d/m/Y H:i', strtotime($date));
}

function formatRupiah($number) {
    return 'Rp ' . number_format($number, 0, ',', '.');
}

function createSlug($string) {
    $string = strtolower($string);
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);

    return trim($string, '-');
}
?>
