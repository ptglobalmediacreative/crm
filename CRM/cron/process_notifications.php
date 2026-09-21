<?php
/**
 * GET CRM - Background Notification Worker
 *
 * IMPORTANT:
 * Notification list is NOT duplicated here.
 * Worker runs the same navigation.php notification engine for each
 * active user, then navigation sends unread items through the same
 * NotificationEmailService.
 *
 * Schedule:
 *   Linux: every 1 minute via cron
 *   Windows: Task Scheduler every 1 minute
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$crmRoot = dirname(__DIR__);
$config = $crmRoot . '/config.php';
$navigation = $crmRoot . '/navigation.php';

if (!is_file($config) || !is_file($navigation)) {
    fwrite(STDERR, "[GET CRM] config.php/navigation.php tidak ditemukan.\n");
    exit(1);
}

require_once $config;

$db = null;
foreach (['db', 'pdo', 'conn'] as $candidate) {
    if (isset($$candidate) && $$candidate instanceof PDO) {
        $db = $$candidate;
        break;
    }
}
if (!$db && function_exists('getPDO')) {
    try { $db = getPDO(); } catch (Throwable $e) {}
}
if (!$db instanceof PDO) {
    fwrite(STDERR, "[GET CRM] PDO database tidak tersedia.\n");
    exit(1);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$users = $db->query("
    SELECT id, username, email, full_name, phone, role
    FROM users
    WHERE email IS NOT NULL
      AND TRIM(email) <> ''
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$processed = 0;
$started = microtime(true);

/*
 * Flag ini dibaca navigation.php.
 * Tujuannya membedakan eksekusi worker dari page request biasa.
 */
if (!defined('GET_CRM_NOTIFICATION_WORKER')) {
    define('GET_CRM_NOTIFICATION_WORKER', true);
}

foreach ($users as $user) {
    $userId = (int)$user['id'];

    if ($userId <= 0 || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        continue;
    }

    /*
     * Reset session per user.
     */
    $_SESSION = [];
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = (string)($user['username'] ?? '');
    $_SESSION['email'] = (string)($user['email'] ?? '');
    $_SESSION['full_name'] = (string)($user['full_name'] ?? 'User');
    $_SESSION['phone'] = (string)($user['phone'] ?? '');

    $GLOBALS['userId'] = $userId;
    $GLOBALS['userRole'] = (string)($user['role'] ?? '');
    $GLOBALS['role'] = (string)($user['role'] ?? '');

    /*
     * Navigation menghasilkan notification yang sama seperti saat
     * user membuka CRM. Output HTML dibuang karena worker hanya
     * membutuhkan side effect pengiriman email.
     */
    ob_start();

    try {
        include $navigation;
        ob_end_clean();
        $processed++;
    } catch (Throwable $e) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }

        fwrite(
            STDERR,
            "[GET CRM] Worker gagal user {$userId}: {$e->getMessage()}\n"
        );
    }
}

$duration = round(microtime(true) - $started, 3);

fwrite(
    STDOUT,
    "[GET CRM] Background worker selesai. " .
    "User diproses: {$processed}; Durasi: {$duration}s\n"
);

exit(0);
