<?php
/**
 * GET CRM - Background Notification Worker
 *
 * Fungsi:
 * - Menjalankan proses notification CRM secara background.
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
// File:
//
// /CRM/cron/process_notifications.php
//
// dirname(__DIR__) = /CRM
//

$crmRoot = dirname(__DIR__);

$configFile    = $crmRoot . '/config.php';
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
// config.php milik CRM membuat koneksi PDO.
//
// Biasanya:
//
// $db = new PDO(...)
//
// JANGAN mengosongkan $db setelah require_once.
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

if (
    !$notificationDb &&
    isset($pdo) &&
    $pdo instanceof PDO
) {

    $notificationDb = $pdo;
}


// ------------------------------------------------------------
// PRIORITAS 3: $conn
// ------------------------------------------------------------

if (
    !$notificationDb &&
    isset($conn) &&
    $conn instanceof PDO
) {

    $notificationDb = $conn;
}


// ------------------------------------------------------------
// PRIORITAS 4: getPDO()
// ------------------------------------------------------------

if (
    !$notificationDb &&
    function_exists('getPDO')
) {

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
//
// Karena itu kita expose koneksi sebagai $db.
//

$db = $notificationDb;


// ============================================================
// 9. FALLBACK getRoleLabel()
// ============================================================
//
// Saat CRM dibuka normal, beberapa halaman CRM mendefinisikan
// getRoleLabel() sebelum navigation.php dipanggil.
//
// Background worker langsung menjalankan navigation.php,
// sehingga fungsi tersebut belum tentu tersedia.
//
// Kita hanya membuat fallback jika fungsi belum tersedia.
//
// Jika fungsi sudah ada, fungsi asli tetap digunakan.
//

if (!function_exists('getRoleLabel')) {

    function getRoleLabel($role)
    {
        $roleLabels = [

            'it_support'
                => 'IT Support',

            'admin'
                => 'Admin',

            'finance'
                => 'Finance',

            'direktur_utama'
                => 'Direktur Utama',

            'direktur_operasional'
                => 'Direktur Operasional',

            'direktur_sales'
                => 'Direktur Sales',

            'business'
                => 'Business',

            'sales_manager'
                => 'Sales Manager',

            'sales'
                => 'Sales',

            'part_support'
                => 'Part Support',

            'service_support'
                => 'Service Support'
        ];


        $role = strtolower(
            trim(
                (string)$role
            )
        );


        return $roleLabels[$role]
            ?? ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $role
                )
            );
    }
}


// ============================================================
// 10. SESSION
// ============================================================

if (session_status() === PHP_SESSION_NONE) {

    session_start();
}


// ============================================================
// 11. CEK KOLOM is_active
// ============================================================
//
// Beberapa versi database mungkin memiliki:
//
// users.is_active
//
// Jika ada, kita hanya memproses user aktif.
//
// Jika tidak ada, kita tetap memproses user yang memiliki email.
//

$hasIsActiveColumn = false;


try {

    $checkColumn = $notificationDb->query(
        "SHOW COLUMNS FROM users LIKE 'is_active'"
    );

    $hasIsActiveColumn = (bool)$checkColumn->fetch(
        PDO::FETCH_ASSOC
    );

} catch (Throwable $e) {

    $hasIsActiveColumn = false;
}


// ============================================================
// 12. AMBIL USER CRM
// ============================================================

$users = [];


try {

    if ($hasIsActiveColumn) {

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
// 13. INFORMASI USER
// ============================================================

$totalUsers = count($users);


fwrite(
    STDOUT,
    "[GET CRM] User ditemukan: {$totalUsers}\n"
);


// ============================================================
// 14. FLAG WORKER
// ============================================================
//
// Flag ini dapat dibaca navigation.php.
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

    define(
        'GET_CRM_NOTIFICATION_WORKER',
        true
    );
}


// ============================================================
// 15. COUNTER
// ============================================================

$processed = 0;

$failed = 0;

$skipped = 0;

$started = microtime(true);


// ============================================================
// 16. PROCESS SETIAP USER
// ============================================================

foreach ($users as $user) {

    $userId = (int)(
        $user['id'] ?? 0
    );


    $userEmail = trim(
        (string)(
            $user['email'] ?? ''
        )
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

    if (
        !filter_var(
            $userEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {

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
    // GLOBAL USER DATA
    // ========================================================
    //
    // navigation.php dapat menggunakan:
    //
    // $userId
    // $userRole
    // $role
    //

    $GLOBALS['userId'] = $userId;


    $GLOBALS['userRole'] = (string)(
        $user['role'] ?? ''
    );


    $GLOBALS['role'] = (string)(
        $user['role'] ?? ''
    );


    // ========================================================
    // VARIABLE LOKAL UNTUK navigation.php
    // ========================================================

    $userRole = (string)(
        $user['role'] ?? ''
    );


    $role = (string)(
        $user['role'] ?? ''
    );


    $fullName = (string)(
        $user['full_name'] ?? 'User'
    );


    // ========================================================
    // OUTPUT BUFFER
    // ========================================================
    //
    // navigation.php menghasilkan HTML.
    //
    // Worker tidak membutuhkan HTML.
    //
    // Kita hanya membutuhkan side-effect:
    //
    // notification ditemukan
    //       ↓
    // NotificationEmailService
    //       ↓
    // email dikirim
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
        // Bersihkan output buffer
        // ----------------------------------------------------

        if (ob_get_level() > 0) {

            ob_end_clean();
        }


        // ----------------------------------------------------
        // Catat error
        // ----------------------------------------------------

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
// 17. HITUNG DURASI
// ============================================================

$duration = round(
    microtime(true) - $started,
    3
);


// ============================================================
// 18. HASIL AKHIR WORKER
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
// 19. SELESAI
// ============================================================

exit(0);