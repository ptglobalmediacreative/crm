<?php
/**
 * GET CRM - Notification Email Service
 *
 * Mengirim notification CRM yang masih "Belum Dibaca"
 * ke email user aktif.
 *
 * Recipient:
 *   users.email
 *
 * Pengiriman:
 *   sendEmail() dari config.php
 *
 * Deduplication:
 *   notification_email_logs
 *   UNIQUE(user_id, notification_key)
 */

if (!function_exists('sendEmail')) {
    throw new RuntimeException(
        'sendEmail() belum tersedia. Pastikan config.php sudah di-load.'
    );
}

if (!function_exists('getNotificationEmailEsc')) {
    function getNotificationEmailEsc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('getNotificationEmailUrl')) {
    function getNotificationEmailUrl(string $url): string
    {
        if ($url === '') {
            return defined('APP_URL') ? APP_URL : '/';
        }

        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

        if (str_starts_with($url, '/')) {
            return $base . $url;
        }

        return $base . '/' . ltrim($url, '/');
    }
}

if (!function_exists('getNotificationEmailTypeLabel')) {
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
}

if (!function_exists('getNotificationEmailActionLabel')) {
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
}

if (!function_exists('getNotificationEmailSubject')) {
    function getNotificationEmailSubject(string $title): string
    {
        return '[GET CRM] ' . $title;
    }
}

/**
 * Mengklaim satu notification secara atomic.
 *
 * Return:
 *   > 0  = id log baru yang menjadi milik request ini
 *   0   = notification sudah pernah diklaim/diproses
 */
if (!function_exists('claimNotificationEmail')) {
    function claimNotificationEmail(
        PDO $db,
        int $userId,
        string $notificationKey,
        string $email,
        string $subject
    ): int {
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

        if ($stmt->rowCount() !== 1) {
            return 0;
        }

        return (int)$db->lastInsertId();
    }
}

/**
 * Kirim satu notification ke email user.
 *
 * Return:
 *   true  = berhasil dikirim
 *   false = gagal / sudah pernah diproses
 */
if (!function_exists('sendNotificationEmail')) {
    function sendNotificationEmail(
        PDO $db,
        int $userId,
        string $email,
        string $fullName,
        array $notification
    ): bool {
        $email = trim($email);

        if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $key = trim((string)($notification['key'] ?? ''));

        if ($key === '') {
            return false;
        }

        $title = trim(
            (string)($notification['title'] ?? 'Notifikasi CRM')
        );

        $message = trim(
            (string)($notification['message'] ?? 'Ada notifikasi baru di GET CRM.')
        );

        $type = trim(
            (string)($notification['type'] ?? 'general')
        );

        $url = trim(
            (string)($notification['url'] ?? '')
        );

        $subject = getNotificationEmailSubject($title);

        $logId = claimNotificationEmail(
            $db,
            $userId,
            $key,
            $email,
            $subject
        );

        // Sudah pernah diklaim/dikirim. Jangan kirim ulang.
        if ($logId <= 0) {
            return false;
        }

        $safeName = getNotificationEmailEsc(
            $fullName !== '' ? $fullName : 'User'
        );

        $safeTitle = getNotificationEmailEsc($title);
        $safeMessage = getNotificationEmailEsc($message);

        $typeLabel = getNotificationEmailTypeLabel($type);
        $safeTypeLabel = getNotificationEmailEsc($typeLabel);

        $actionLabel = getNotificationEmailActionLabel($type);
        $safeActionLabel = getNotificationEmailEsc($actionLabel);

        $targetUrl = getNotificationEmailUrl($url);
        $safeUrl = getNotificationEmailEsc($targetUrl);

        $currentYear = date('Y');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GET CRM Notification</title>
</head>

<body style="
    margin:0;
    padding:0;
    background:#eef2f7;
    font-family:Arial,Helvetica,sans-serif;
    color:#172033;
">

<table width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#eef2f7;padding:30px 12px;">
<tr>
<td align="center">

<table width="680" cellpadding="0" cellspacing="0" border="0"
       style="
           max-width:680px;
           width:100%;
           background:#ffffff;
           border-radius:16px;
           overflow:hidden;
           box-shadow:0 8px 30px rgba(15,23,42,.08);
       ">

    <!-- HEADER -->
    <tr>
        <td style="
            background:#07101f;
            padding:28px 32px;
        ">

            <div style="
                font-size:11px;
                line-height:16px;
                letter-spacing:2px;
                font-weight:700;
                color:#60a5fa;
            ">
                GET CRM NOTIFICATION
            </div>

            <div style="
                margin-top:8px;
                font-size:22px;
                line-height:30px;
                font-weight:800;
                color:#ffffff;
            ">
                PT GANDA ELANG TANGGUH
            </div>

            <div style="
                margin-top:4px;
                font-size:13px;
                line-height:20px;
                color:#94a3b8;
            ">
                Customer Relationship Management
            </div>

        </td>
    </tr>

    <!-- CONTENT -->
    <tr>
        <td style="padding:32px;">

            <div style="
                font-size:13px;
                line-height:20px;
                color:#64748b;
            ">
                Halo <strong style="color:#111827;">{$safeName}</strong>,
            </div>

            <div style="
                margin-top:8px;
                font-size:14px;
                line-height:22px;
                color:#475569;
            ">
                Anda memiliki notifikasi baru yang masih berstatus
                <strong style="color:#2563eb;">Belum Dibaca</strong>
                di GET CRM.
            </div>

            <!-- TYPE -->
            <div style="
                margin-top:24px;
                display:inline-block;
                padding:7px 12px;
                border-radius:999px;
                background:#eff6ff;
                color:#2563eb;
                font-size:10px;
                line-height:14px;
                font-weight:800;
                letter-spacing:.7px;
            ">
                {$safeTypeLabel}
            </div>

            <!-- TITLE -->
            <div style="
                margin-top:14px;
                font-size:22px;
                line-height:30px;
                font-weight:800;
                color:#111827;
            ">
                {$safeTitle}
            </div>

            <!-- MESSAGE CARD -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="
                       margin-top:18px;
                       background:#f8fafc;
                       border:1px solid #e5e7eb;
                       border-radius:12px;
                   ">
                <tr>
                    <td style="padding:20px;">

                        <div style="
                            font-size:11px;
                            line-height:16px;
                            color:#94a3b8;
                            text-transform:uppercase;
                            letter-spacing:1px;
                            font-weight:700;
                        ">
                            Detail Notifikasi
                        </div>

                        <div style="
                            margin-top:9px;
                            font-size:14px;
                            line-height:24px;
                            color:#334155;
                        ">
                            {$safeMessage}
                        </div>

                    </td>
                </tr>
            </table>

            <!-- BUTTON -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="margin-top:26px;">
                <tr>
                    <td align="center">

                        <a href="{$safeUrl}"
                           style="
                               display:inline-block;
                               padding:14px 25px;
                               background:#2563eb;
                               color:#ffffff;
                               text-decoration:none;
                               border-radius:9px;
                               font-size:13px;
                               line-height:18px;
                               font-weight:800;
                           ">
                            {$safeActionLabel}
                        </a>

                    </td>
                </tr>
            </table>

            <!-- FALLBACK LINK -->
            <div style="
                margin-top:24px;
                padding-top:18px;
                border-top:1px solid #e5e7eb;
                font-size:11px;
                line-height:18px;
                color:#94a3b8;
                word-break:break-all;
            ">
                Jika tombol di atas tidak dapat dibuka, gunakan link berikut:
                <br>
                <a href="{$safeUrl}"
                   style="color:#2563eb;text-decoration:none;">
                    {$safeUrl}
                </a>
            </div>

        </td>
    </tr>

    <!-- FOOTER -->
    <tr>
        <td style="
            background:#f8fafc;
            border-top:1px solid #e5e7eb;
            padding:20px 32px;
            text-align:center;
        ">

            <div style="
                font-size:11px;
                line-height:18px;
                color:#94a3b8;
            ">
                Email otomatis dari GET CRM.
                Mohon tidak membalas email ini.
            </div>

            <div style="
                margin-top:5px;
                font-size:11px;
                line-height:18px;
                color:#cbd5e1;
            ">
                &copy; {$currentYear} PT Ganda Elang Tangguh
            </div>

        </td>
    </tr>

</table>

</td>
</tr>
</table>

</body>
</html>
HTML;

        try {
            $sent = sendEmail(
                $email,
                $subject,
                $html
            );

            if ($sent) {
                $update = $db->prepare("
                    UPDATE notification_email_logs
                    SET status = 'sent',
                        sent_at = NOW(),
                        error_message = NULL
                    WHERE id = ?
                ");

                $update->execute([$logId]);

                return true;
            }

            $update = $db->prepare("
                UPDATE notification_email_logs
                SET status = 'failed',
                    error_message = ?
                WHERE id = ?
            ");

            $update->execute([
                'sendEmail() mengembalikan false.',
                $logId
            ]);

            return false;

        } catch (Throwable $e) {

            try {
                $update = $db->prepare("
                    UPDATE notification_email_logs
                    SET status = 'failed',
                        error_message = ?
                    WHERE id = ?
                ");

                $update->execute([
                    substr($e->getMessage(), 0, 2000),
                    $logId
                ]);

            } catch (Throwable $ignored) {
                // Jangan menimpa error utama.
            }

            return false;
        }
    }
}
