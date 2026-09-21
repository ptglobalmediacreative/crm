<?php

require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {

    // ==========================================
    // SMTP HOSTINGER
    // ==========================================

    $mail->isSMTP();

    $mail->Host       = 'smtp.hostinger.com';
    $mail->SMTPAuth   = true;

    $mail->Username   = 'itsupport@gandaelang.co.id';

    // MASUKKAN PASSWORD EMAIL DI SINI
    $mail->Password   = 'Natanael110405@';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = 465;


    // ==========================================
    // PENGIRIM
    // ==========================================

    $mail->setFrom(
        'itsupport@gandaelang.co.id',
        'GET CRM'
    );


    // ==========================================
    // PENERIMA TEST
    // ==========================================

    // GANTI DENGAN EMAIL KAMU SENDIRI
    $mail->addAddress(
        'nezhaathian5@gmail.com'
    );


    // ==========================================
    // EMAIL
    // ==========================================

    $mail->isHTML(true);

    $mail->Subject = '[GET CRM] Test SMTP Hostinger';

    $mail->Body = '
        <div style="
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
        ">

            <h2 style="margin-top:0;">
                GET CRM
            </h2>

            <p>
                SMTP berhasil terhubung.
            </p>

            <p>
                Email ini merupakan email test dari
                sistem GET CRM.
            </p>

            <hr>

            <small>
                Sender: itsupport@gandaelang.co.id
            </small>

        </div>
    ';


    // ==========================================
    // KIRIM
    // ==========================================

    $mail->send();

    echo '
        <div style="
            font-family:Arial;
            padding:30px;
            color:green;
        ">
            <h2>✅ EMAIL BERHASIL DIKIRIM</h2>

            <p>
                SMTP Hostinger berhasil digunakan.
            </p>
        </div>
    ';

} catch (Exception $e) {

    echo '
        <div style="
            font-family:Arial;
            padding:30px;
            color:red;
        ">
            <h2>❌ EMAIL GAGAL DIKIRIM</h2>

            <p>
                Error:
            </p>

            <pre>' .
                htmlspecialchars($mail->ErrorInfo)
            . '</pre>

        </div>
    ';
}