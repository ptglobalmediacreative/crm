<?php
/**
 * GET CRM - Notification Email Service
 *
 * SINGLE SOURCE:
 *   Notification yang dibuat navigation.php.
 *
 * ANTI DUPLIKASI:
 *   notification_email_logs UNIQUE(user_id, notification_key)
 *
 * MODE:
 *   - Browser: navigation.php boleh mengirim notification unread.
 *   - Worker: process_notifications.php menjalankan navigation.php untuk
 *             setiap user tanpa membutuhkan user membuka CRM.
 */

if (!function_exists('sendEmail')) {
    throw new RuntimeException(
        'sendEmail() belum tersedia. Pastikan config.php sudah di-load.'
    );
}

function getNotificationEmailEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function notificationEmailTable(PDO $db): void
{
    static $ready = false;
    if ($ready) return;

    $db->exec("
        CREATE TABLE IF NOT EXISTS notification_email_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            notification_key VARCHAR(255) NOT NULL,
            recipient_email VARCHAR(320) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('processing','sent','failed') NOT NULL DEFAULT 'processing',
            sent_at DATETIME NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_notification_email_user_key (user_id, notification_key),
            KEY idx_notification_email_status (status),
            KEY idx_notification_email_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

function getNotificationEmailUrl(string $url): string
{
    if ($url === '') {
        return defined('APP_URL') ? APP_URL : '/';
    }

    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }

    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

    return $base . '/' . ltrim($url, '/');
}

function getNotificationEmailTypeLabel(string $type): string
{
    return match ($type) {
        'approval'   => 'MENUNGGU APPROVAL',
        'incomplete' => 'PERLU DILENGKAPI',
        'new'        => 'NOTIFIKASI BARU',
        'due'        => 'MENDEKATI DUE DATE',
        'approved'   => 'SUDAH DISETUJUI',
        default      => 'NOTIFIKASI CRM',
    };
}

function getNotificationEmailActionLabel(string $type): string
{
    return match ($type) {
        'approval'   => 'BUKA UNTUK APPROVAL',
        'incomplete' => 'LENGKAPI DATA',
        'new'        => 'BUKA NOTIFIKASI',
        'due'        => 'BUKA AKTIVITAS',
        'approved'   => 'LIHAT DETAIL',
        default      => 'BUKA NOTIFIKASI',
    };
}

/**
 * Atomic claim.
 *
 * Hanya request yang berhasil INSERT yang boleh mengirim email.
 * Request lain yang datang bersamaan akan mendapat 0 dan langsung skip.
 */
function claimNotificationEmail(
    PDO $db,
    int $userId,
    string $notificationKey,
    string $email,
    string $subject
): int {
    notificationEmailTable($db);

    $stmt = $db->prepare("
        INSERT IGNORE INTO notification_email_logs
            (user_id, notification_key, recipient_email, subject, status)
        VALUES (?, ?, ?, ?, 'processing')
    ");

    $stmt->execute([
        $userId,
        $notificationKey,
        $email,
        $subject
    ]);

    return $stmt->rowCount() === 1
        ? (int)$db->lastInsertId()
        : 0;
}

function sendNotificationEmail(
    PDO $db,
    int $userId,
    string $email,
    string $fullName,
    array $notification
): bool {
    $email = trim($email);
    $key = trim((string)($notification['key'] ?? ''));

    if (
        $userId <= 0 ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        $key === ''
    ) {
        return false;
    }

    $title = trim((string)($notification['title'] ?? 'Notifikasi CRM'));
    $message = trim((string)($notification['message'] ?? 'Ada notifikasi baru di GET CRM.'));
    $type = trim((string)($notification['type'] ?? 'general'));
    $url = trim((string)($notification['url'] ?? ''));

    $subject = '[GET CRM] ' . $title;

    $logId = claimNotificationEmail(
        $db,
        $userId,
        $key,
        $email,
        $subject
    );

    /*
     * Kalau 0 berarti notification ini sudah pernah diklaim.
     * Jangan kirim ulang.
     */
    if ($logId <= 0) {
        return false;
    }

    $safeName = getNotificationEmailEsc($fullName ?: 'User');
    $safeTitle = getNotificationEmailEsc($title);
    $safeMessage = nl2br(getNotificationEmailEsc($message));
    $safeType = getNotificationEmailEsc(getNotificationEmailTypeLabel($type));
    $safeAction = getNotificationEmailEsc(getNotificationEmailActionLabel($type));
    $safeUrl = getNotificationEmailEsc(getNotificationEmailUrl($url));
    $year = date('Y');

    $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>GET CRM Notification</title>
</head>
<body style="margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#172033;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:30px 12px;background:#eef2f7;">
<tr><td align="center">
<table width="680" cellpadding="0" cellspacing="0"
       style="max-width:680px;width:100%;background:#fff;border-radius:16px;overflow:hidden;">

<tr>
<td style="background:#07101f;padding:28px 32px;">
    <div style="font-size:11px;letter-spacing:2px;font-weight:700;color:#60a5fa;">
        GET CRM NOTIFICATION
    </div>
    <div style="margin-top:8px;font-size:22px;font-weight:800;color:#fff;">
        PT GANDA ELANG TANGGUH
    </div>
    <div style="margin-top:4px;font-size:13px;color:#94a3b8;">
        Customer Relationship Management
    </div>
</td>
</tr>

<tr>
<td style="padding:32px;">
    <div style="font-size:13px;color:#64748b;">
        Halo <strong style="color:#111827;">{$safeName}</strong>,
    </div>

    <div style="margin-top:8px;font-size:14px;line-height:22px;color:#475569;">
        Anda memiliki notification yang masih berstatus
        <strong style="color:#2563eb;">Belum Dibaca</strong> di GET CRM.
    </div>

    <div style="display:inline-block;margin-top:22px;padding:7px 12px;border-radius:999px;
                background:#eff6ff;color:#2563eb;font-size:10px;font-weight:800;letter-spacing:.7px;">
        {$safeType}
    </div>

    <div style="margin-top:14px;font-size:22px;line-height:30px;font-weight:800;color:#111827;">
        {$safeTitle}
    </div>

    <table width="100%" cellpadding="0" cellspacing="0"
           style="margin-top:18px;background:#f8fafc;border:1px solid #e5e7eb;">
    <tr><td style="padding:20px;">
        <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;font-weight:700;">
            Detail Notifikasi
        </div>
        <div style="margin-top:9px;font-size:14px;line-height:24px;color:#334155;">
            {$safeMessage}
        </div>
    </td></tr>
    </table>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top:26px;">
    <tr><td align="center">
        <a href="{$safeUrl}"
           style="display:inline-block;padding:14px 25px;background:#2563eb;color:#fff;
                  text-decoration:none;border-radius:9px;font-size:13px;font-weight:800;">
            {$safeAction}
        </a>
    </td></tr>
    </table>

    <div style="margin-top:24px;padding-top:18px;border-top:1px solid #e5e7eb;
                font-size:11px;line-height:18px;color:#94a3b8;word-break:break-all;">
        Jika tombol tidak dapat dibuka, gunakan link berikut:
        <br>
        <a href="{$safeUrl}" style="color:#2563eb;">{$safeUrl}</a>
    </div>
</td>
</tr>

<tr>
<td style="background:#f8fafc;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center;">
    <div style="font-size:11px;line-height:18px;color:#94a3b8;">
        Email otomatis dari GET CRM. Mohon tidak membalas email ini.
    </div>
    <div style="margin-top:5px;font-size:11px;color:#cbd5e1;">
        &copy; {$year} PT Ganda Elang Tangguh
    </div>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;

    try {
        $sent = sendEmail($email, $subject, $html);

        if ($sent) {
            $stmt = $db->prepare("
                UPDATE notification_email_logs
                SET status = 'sent',
                    sent_at = NOW(),
                    error_message = NULL
                WHERE id = ?
            ");
            $stmt->execute([$logId]);
            return true;
        }

        $stmt = $db->prepare("
            UPDATE notification_email_logs
            SET status = 'failed',
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute(['sendEmail() mengembalikan false.', $logId]);

        return false;

    } catch (Throwable $e) {
        try {
            $stmt = $db->prepare("
                UPDATE notification_email_logs
                SET status = 'failed',
                    error_message = ?
                WHERE id = ?
            ");
            $stmt->execute([
                substr($e->getMessage(), 0, 2000),
                $logId
            ]);
        } catch (Throwable $ignored) {}

        return false;
    }
}
