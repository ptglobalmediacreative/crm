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

// MASUKKAN PASSWORD DATABASE KAMU DI SINI
define('DB_PASS', 'GETGroup2023');

// ============================================
// APLIKASI
// ============================================
$httpHost = $_SERVER['HTTP_HOST'] ?? 'gandaelang.com';

define('APP_URL', 'https://' . $httpHost);
define('APP_NAME', 'GET CRM - PT Ganda Elang Tangguh');
define('APP_EMAIL', 'itsupport@gandaelang.co.id');

// ============================================
// EMAIL CONFIGURATION - HOSTINGER SMTP
// ============================================
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);

define('SMTP_USER', 'itsupport@gandaelang.co.id');

// MASUKKAN PASSWORD EMAIL HOSTINGER KAMU DI SINI
define('SMTP_PASS', 'Natanael110405!');

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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

} catch (PDOException $e) {

    error_log('[GET CRM DATABASE] ' . $e->getMessage());

    die('Koneksi database gagal. Silakan hubungi administrator.');

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

function bersihkan($data)
{
    return htmlspecialchars(
        strip_tags(trim($data)),
        ENT_QUOTES,
        'UTF-8'
    );
}


function hashPassword($password)
{
    return password_hash($password, PASSWORD_DEFAULT);
}


function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}


function redirect($url)
{
    header("Location: " . $url);
    exit();
}


function isLoggedIn()
{
    return isset($_SESSION['user_id'])
        && !empty($_SESSION['user_id']);
}


function setFlash($message, $type = 'success')
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}


function showFlash()
{
    if (isset($_SESSION['flash'])) {

        $msg = $_SESSION['flash']['message'];
        $type = $_SESSION['flash']['type'];

        $icon = $type === 'success'
            ? 'fa-check-circle'
            : (
                $type === 'danger'
                    ? 'fa-exclamation-circle'
                    : 'fa-info-circle'
            );

        unset($_SESSION['flash']);

        return "
            <div class='alert alert-{$type} alert-dismissible fade show' role='alert'>

                <i class='fas {$icon} alert-icon'></i>

                {$msg}

                <button
                    type='button'
                    class='btn-close'
                    data-bs-dismiss='alert'>
                </button>

            </div>
        ";
    }

    return '';
}


function getRole()
{
    return $_SESSION['role'] ?? null;
}


function generateToken()
{
    return bin2hex(random_bytes(32));
}


// ============================================
// PHPMailer AUTOLOAD
// ============================================
//
// Struktur yang kita gunakan:
//
// CRM/
// ├── config.php
// ├── forgot_password.php
// └── vendor/
//     ├── autoload.php
//     └── phpmailer/
//

function loadPHPMailer()
{
    static $loaded = false;

    if ($loaded) {
        return true;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!file_exists($autoload)) {

        error_log(
            '[GET CRM EMAIL] Composer autoload tidak ditemukan: '
            . $autoload
        );

        return false;
    }

    require_once $autoload;

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {

        error_log(
            '[GET CRM EMAIL] Class PHPMailer tidak ditemukan setelah autoload.'
        );

        return false;
    }

    $loaded = true;

    return true;
}


// ============================================
// FUNCTION KIRIM EMAIL
// ============================================

function sendEmail(
    $to,
    $subject,
    $message,
    $from = null,
    $fromName = null
) {

    // ----------------------------------------
    // LOAD PHPMailer
    // ----------------------------------------

    if (!loadPHPMailer()) {

        error_log(
            '[GET CRM EMAIL] PHPMailer gagal dimuat.'
        );

        return false;
    }


    // ----------------------------------------
    // DEFAULT SENDER
    // ----------------------------------------

    $from = $from ?? SMTP_FROM;
    $fromName = $fromName ?? SMTP_FROM_NAME;


    // ----------------------------------------
    // VALIDASI EMAIL
    // ----------------------------------------

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {

        error_log(
            '[GET CRM EMAIL] Email tujuan tidak valid: '
            . $to
        );

        return false;
    }


    // ----------------------------------------
    // PHPMailer
    // ----------------------------------------

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {

        // SMTP
        $mail->isSMTP();

        $mail->Host = SMTP_HOST;

        $mail->SMTPAuth = true;

        $mail->Username = SMTP_USER;

        $mail->Password = SMTP_PASS;


        // SSL
        $mail->SMTPSecure =
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;

        $mail->Port = SMTP_PORT;


        // ------------------------------------
        // CHARACTER SET
        // ------------------------------------

        $mail->CharSet = 'UTF-8';

        $mail->Encoding = 'base64';


        // ------------------------------------
        // SENDER
        // ------------------------------------

        $mail->setFrom(
            $from,
            $fromName
        );


        // ------------------------------------
        // RECIPIENT
        // ------------------------------------

        $mail->addAddress($to);


        // ------------------------------------
        // EMAIL CONTENT
        // ------------------------------------

        $mail->isHTML(true);

        $mail->Subject = $subject;

        $mail->Body = $message;


        // Plain text fallback
        $mail->AltBody = strip_tags(
            html_entity_decode($message)
        );


        // ------------------------------------
        // SEND
        // ------------------------------------

        $mail->send();

        return true;


    } catch (\Throwable $e) {

        error_log(
            '[GET CRM EMAIL] '
            . $e->getMessage()
        );

        return false;
    }
}


// ============================================
// FUNGSI PERMISSION - LENGKAP
// ============================================

function hasPermission($module, $action = 'view')
{
    global $db;

    if (!isLoggedIn()) {
        return false;
    }

    $role = $_SESSION['role'] ?? 'user';


    // IT Support punya akses penuh
    if ($role === 'it_support') {
        return true;
    }


    // Ambil permission
    $stmt = $db->prepare("
        SELECT p.*
        FROM permissions p

        JOIN modules m
            ON m.id = p.module_id

        WHERE m.module_name = ?
          AND p.role_name = ?
    ");

    $stmt->execute([
        $module,
        $role
    ]);

    $perm = $stmt->fetch();


    if (!$perm) {
        return false;
    }


    switch ($action) {

        case 'view':
            return $perm['can_view'] == 1;

        case 'add':
            return $perm['can_add'] == 1;

        case 'edit':
            return $perm['can_edit'] == 1;

        case 'delete':
            return $perm['can_delete'] == 1;

        default:
            return false;
    }
}


// ============================================
// MENU ACCESS
// ============================================

function canAccessMenu($module)
{
    return hasPermission($module, 'view');
}


function canAdd($module)
{
    return hasPermission($module, 'add');
}


function canEdit($module)
{
    return hasPermission($module, 'edit');
}


function canDelete($module)
{
    return hasPermission($module, 'delete');
}


// ============================================
// ROLE
// ============================================

function hasRole($roles)
{
    if (!isLoggedIn()) {
        return false;
    }

    if (is_array($roles)) {

        return in_array(
            $_SESSION['role'],
            $roles
        );
    }

    return $_SESSION['role'] === $roles;
}


function isFullAccess()
{
    return hasRole('it_support');
}


function canManageUser()
{
    return hasRole([
        'it_support',
        'admin'
    ]);
}


// ============================================
// AMBIL MENU USER
// ============================================

function getUserMenus()
{
    global $db;

    if (!isLoggedIn()) {
        return [];
    }

    $role = $_SESSION['role'] ?? 'user';


    // IT Support
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


    // User berdasarkan permission
    $stmt = $db->prepare("
        SELECT m.*
        FROM modules m

        JOIN permissions p
            ON p.module_id = m.id

        WHERE p.role_name = ?
          AND p.can_view = 1
          AND m.is_main_menu = 1
          AND m.is_active = 1

        ORDER BY m.module_order
    ");

    $stmt->execute([
        $role
    ]);

    return $stmt->fetchAll();
}


// ============================================
// MENU NAMES
// ============================================

function getUserMenuNames()
{
    $menus = getUserMenus();

    return array_column(
        $menus,
        'module_name'
    );
}


// ============================================
// REQUIRE PERMISSION
// ============================================

function requirePermission(
    $module,
    $action = 'view'
) {

    if (!isLoggedIn()) {

        setFlash(
            'Silakan login dulu!',
            'warning'
        );

        redirect('login.php');
    }


    if (!hasPermission($module, $action)) {

        setFlash(
            'Anda tidak memiliki akses ke halaman ini!',
            'danger'
        );

        redirect('dashboard.php');
    }
}


// ============================================
// FUNGSI UNTUK VIEW
// ============================================

function showIf(
    $module,
    $action = 'view'
) {

    return hasPermission(
        $module,
        $action
    );
}


function showAddButton($module)
{
    return hasPermission(
        $module,
        'add'
    );
}


function showEditButton($module)
{
    return hasPermission(
        $module,
        'edit'
    );
}


function showDeleteButton($module)
{
    return hasPermission(
        $module,
        'delete'
    );
}


// ============================================
// CUSTOMER / HELPER
// ============================================

function formatTanggal($date)
{
    return date(
        'd/m/Y H:i',
        strtotime($date)
    );
}


function formatRupiah($number)
{
    return 'Rp ' .
        number_format(
            $number,
            0,
            ',',
            '.'
        );
}


function createSlug($string)
{
    $string = strtolower($string);

    $string = preg_replace(
        '/[^a-z0-9-]/',
        '-',
        $string
    );

    $string = preg_replace(
        '/-+/',
        '-',
        $string
    );

    return trim(
        $string,
        '-'
    );
}