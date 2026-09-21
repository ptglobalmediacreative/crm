<?php
/**
 * GET CRM - Background Notification Worker
 *
 * Fungsi:
 * - Menjalankan proses notifikasi CRM secara background.
 * - Tidak membutuhkan user membuka CRM.
 * - Menggunakan notification engine yang sama dengan navigation.php.
 * - Memproses user yang mempunyai email valid.
 * - NotificationEmailService menangani pengiriman email.
 * - notification_email_logs menangani anti-duplicate.
 *
 * Schedule:
 *   Linux: setiap 1 menit via Cron
 *   Windows: Task Scheduler setiap 1 menit
 */

declare(strict_types=1);


// ============================================================
// 1. HANYA BOLEH DIJALANKAN VIA CLI / CRON
// ============================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}


// ============================================================
// 2. TENTUKAN ROOT CRM
// ============================================================
//
// File ini berada di:
//
// /CRM/cron/process_notifications.php
//
// Maka:
// __DIR__
// = /CRM/cron
//
// dirname(__DIR__)
// = /CRM
//

$crmRoot = dirname(__DIR__);

$configFile = $crmRoot . '/config.php';
$navigationFile = $crmRoot . '/navigation.php';


// ============================================================
// 3. CEK FILE PENTING
// ============================================================

if (!is_file($configFile)) {
    fwrite(
        STDERR,
        "[GET CRM] config.php tidak ditemukan: {$configFile}\n"
    );

    exit(1);
}

if (!is_file($navigationFile)) {
    fwrite(
        STDERR,
        "[GET CRM] navigation.php tidak ditemukan: {$navigationFile}\n"
    );

    exit(1);
}


// ============================================================
// 4. LOAD CONFIG CRM
// ============================================================
//
// config.php membuat koneksi PDO pada variable:
//
// $db
//
// Jangan menimpa $db dengan null setelah require_once.
//

try {

    require_once $configFile;

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "[GET CRM] Gagal load config.php: " .
        $e->getMessage() .
        "\n"
    );

    exit(1);
}


// ============================================================
// 5. AMBIL KONEKSI DATABASE
// ============================================================
//
// Prioritas:
//
// 1. $db
// 2. $pdo
// 3. $conn
// 4. getPDO()
//
// PENTING:
// Jangan melakukan:
//
// $db = null;
//
// karena config.php sudah membuat $db.
//

$notificationDb = null;


// ------------------------------------------------------------
// PRIORITAS 1: $db
// ------------------------------------------------------------

if (isset($db) && $db instanceof PDO) {

    $notificationDb = $db;
}


// ------------------------------------------------------------
// PRIORITAS 2: $pdo
// ------------------------------------------------------------

if (!$notificationDb && isset($pdo) && $pdo instanceof PDO) {

    $notificationDb = $pdo;
}


// ------------------------------------------------------------
// PRIORITAS 3: $conn
// ------------------------------------------------------------

if (!$notificationDb && isset($conn) && $conn instanceof PDO) {

    $notificationDb = $conn;
}


// ------------------------------------------------------------
// PRIORITAS 4: getPDO()
// ------------------------------------------------------------

if (!$notificationDb && function_exists('getPDO')) {

    try {

        $candidateDb = getPDO();

        if ($candidateDb instanceof PDO) {
            $notificationDb = $candidateDb;
        }

    } catch (Throwable $e) {

        $notificationDb = null;
    }
}


// ============================================================
// 6. VALIDASI DATABASE
// ============================================================

if (!$notificationDb instanceof PDO) {

    fwrite(
        STDERR,
        "[GET CRM] PDO database tidak tersedia.\n"
    );

    fwrite(
        STDERR,
        "[GET CRM] Config: {$configFile}\n"
    );

    exit(1);
}


// ============================================================
// 7. TEST DATABASE CONNECTION
// ============================================================
//
// Kita test koneksi sebelum melanjutkan worker.
//

try {

    $notificationDb->query("SELECT 1");

} catch (Throwable $e) {

    fwrite(
        STDERR,
        "[GET CRM] Database connection error: " .
        $e->getMessage() .
        "\n"
    );

    exit(1);
}


fwrite(
    STDOUT,
    "[GET CRM] Database connection OK.\n"
);


// ============================================================
// 8. GUNAKAN $db SEBAGAI KONEKSI UTAMA
// ============================================================
//
// navigation.php menggunakan $db.
// Jadi kita expose koneksi tersebut sebagai $db.
//

$db = $notificationDb;


// ============================================================
// 9. SESSION
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ============================================================
// 10. LOAD USER CRM
// ============================================================
//
// Kita ambil user yang mempunyai email valid.
//
// Karena beberapa versi database mungkin mempunyai kolom
// is_active dan beberapa versi mungkin belum mempunyai,
// kita cek terlebih dahulu.
//

$users = [];


// ------------------------------------------------------------
// CEK APAKAH users.is_active ADA
// ------------------------------------------------------------

$hasIsActiveColumn = false;

try {

    $checkColumn = $notificationDb->query("
        SHOW COLUMNS FROM users LIKE 'is_active'
    ");

    $hasIsActiveColumn = (bool)$checkColumn->fetch(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    $hasIsActiveColumn = false;
}


// ============================================================
// QUERY USER
// ============================================================

try {

    if ($hasIsActiveColumn) {

        /*
         * Jika kolom is_active tersedia,
         * hanya proses user aktif.
         */
        $userQuery = "
            SELECT
                id,
                username,
                email,
                full_name,
                phone,
                role
            FROM users
            WHERE email IS NOT NULL
              AND TRIM(email) <> ''
              AND is_active = 1
            ORDER BY id
        ";

    } else {

        /*
         * Jika kolom is_active belum tersedia,
         * proses semua user yang mempunyai email.
         */
        $userQuery = "
            SELECT
                id,
                username,
                email,
                full_name,
                phone,
                role
            FROM users
            WHERE email IS NOT NULL
              AND TRIM(email) <> ''
            ORDER BY id
        ";
    }


    $users = $notificationDb
        ->query($userQuery)
        ->fetchAll(PDO::FETCH_ASSOC);


} catch (Throwable $e) {

    fwrite(
        STDERR,
        "[GET CRM] Gagal mengambil user: " .
        $e->getMessage() .
        "\n"
    );

    exit(1);
}


// ============================================================
// 11. INFO AWAL WORKER
// ============================================================

$totalUsers = count($users);

fwrite(
    STDOUT,
    "[GET CRM] User ditemukan: {$totalUsers}\n"
);


// ============================================================
// 12. FLAG WORKER
// ============================================================
//
// Flag ini dapat dibaca oleh navigation.php.
//
// Tujuannya membedakan:
//
// Worker background
//
// dengan:
//
// Request halaman CRM biasa.
//

if (!defined('GET_CRM_NOTIFICATION_WORKER')) {
    define('GET_CRM_NOTIFICATION_WORKER', true);
}


// ============================================================
// 13. COUNTER
// ============================================================

$processed = 0;
$failed = 0;
$skipped = 0;

$started = microtime(true);


// ============================================================
// 14. PROCESS SETIAP USER
// ============================================================

foreach ($users as $user) {

    $userId = (int)($user['id'] ?? 0);

    $userEmail = trim(
        (string)($user['email'] ?? '')
    );


    // --------------------------------------------------------
    // VALIDASI USER ID
    // --------------------------------------------------------

    if ($userId <= 0) {

        $skipped++;

        continue;
    }


    // --------------------------------------------------------
    // VALIDASI EMAIL
    // --------------------------------------------------------

    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {

        $skipped++;

        fwrite(
            STDOUT,
            "[GET CRM] Skip user {$userId}: email tidak valid.\n"
        );

        continue;
    }


    // ========================================================
    // RESET SESSION PER USER
    // ========================================================

    $_SESSION = [];


    $_SESSION['user_id'] = $userId;

    $_SESSION['username'] = (string)(
        $user['username'] ?? ''
    );

    $_SESSION['email'] = $userEmail;

    $_SESSION['full_name'] = (string)(
        $user['full_name'] ?? 'User'
    );

    $_SESSION['phone'] = (string)(
        $user['phone'] ?? ''
    );

    $_SESSION['role'] = (string)(
        $user['role'] ?? ''
    );


    // ========================================================
    // GLOBAL VARIABLE
    // ========================================================
    //
    // Beberapa bagian CRM menggunakan variable global.
    // Kita isi agar navigation.php dapat bekerja seperti
    // ketika user membuka halaman CRM.
    //

    $GLOBALS['userId'] = $userId;

    $GLOBALS['userRole'] = (string)(
        $user['role'] ?? ''
    );

    $GLOBALS['role'] = (string)(
        $user['role'] ?? ''
    );


    // ========================================================
    // OUTPUT BUFFER
    // ========================================================
    //
    // navigation.php menghasilkan HTML.
    //
    // Worker tidak membutuhkan HTML tersebut.
    //
    // Kita hanya membutuhkan side-effect:
    //
    // notification ditemukan
    //        ↓
    // email diproses
    //

    ob_start();


    try {

        // ----------------------------------------------------
        // Jalankan notification engine yang sama
        // ----------------------------------------------------

        include $navigationFile;


        // ----------------------------------------------------
        // Bersihkan output HTML
        // ----------------------------------------------------

        if (ob_get_level() > 0) {
            ob_end_clean();
        }


        // ----------------------------------------------------
        // Berhasil
        // ----------------------------------------------------

        $processed++;


        fwrite(
            STDOUT,
            "[GET CRM] User {$userId} diproses: {$userEmail}\n"
        );


    } catch (Throwable $e) {

        // ----------------------------------------------------
        // Pastikan output buffer dibersihkan
        // ----------------------------------------------------

        if (ob_get_level() > 0) {
            ob_end_clean();
        }


        $failed++;


        fwrite(
            STDERR,
            "[GET CRM] Worker gagal user {$userId}: " .
            $e->getMessage() .
            "\n"
        );
    }
}


// ============================================================
// 15. DURASI
// ============================================================

$duration = round(
    microtime(true) - $started,
    3
);


// ============================================================
// 16. HASIL AKHIR
// ============================================================

fwrite(
    STDOUT,
    "[GET CRM] Background worker selesai.\n"
);

fwrite(
    STDOUT,
    "[GET CRM] Total user: {$totalUsers}\n"
);

fwrite(
    STDOUT,
    "[GET CRM] User diproses: {$processed}\n"
);

fwrite(
    STDOUT,
    "[GET CRM] User gagal: {$failed}\n"
);

fwrite(
    STDOUT,
    "[GET CRM] User dilewati: {$skipped}\n"
);

fwrite(
    STDOUT,
    "[GET CRM] Durasi: {$duration}s\n"
);


// ============================================================
// 17. SELESAI
// ============================================================

exit(0);