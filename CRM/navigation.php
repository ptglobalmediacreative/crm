<?php
// Shared CRM navigation: topbar + sidebar.
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

// ============================================
// MENU YANG BOLEH DIAKSES USER
// Semua halaman cukup include navigation.php.
// Navigation yang menentukan menu berdasarkan permission user.
// ============================================
if (!isset($userMenus) || !is_array($userMenus)) {
    $userMenus = function_exists('getUserMenus') ? getUserMenus() : [];
}

$menuNames = array_values(array_unique(array_filter(array_column($userMenus, 'module_name'))));

// Jika permission belum tersedia, jangan sembunyikan seluruh navigasi.
$showMenu = static function($name) use ($menuNames) {
    return empty($menuNames) || in_array($name, $menuNames, true);
};

/* ==========================================================
   NOTIFICATION SYSTEM
   Data notification dibuat dari record CRM yang sebenarnya.
   Setiap item menampilkan:
   - TR/DI Number
   - Nama PT
   - status / tindakan yang diperlukan
   - hanya record yang relevan dengan role user
   ========================================================== */

$notifItems = [];
$notifUnread = 0;

$notifDb = null;
foreach (['db', 'pdo', 'conn'] as $candidate) {
    if (isset($$candidate) && $$candidate instanceof PDO) {
        $notifDb = $$candidate;
        break;
    }
}
if (!$notifDb && function_exists('getPDO')) {
    try { $notifDb = getPDO(); } catch (Throwable $e) {}
}

/* ==========================================================
   USER PROFILE SYSTEM
   Profile dibuka dari avatar di topbar.
   Data tersimpan ke tabel users.
   Kolom profile_photo dibuat otomatis bila belum ada.
   ========================================================== */
$profileUserId = (int)($_SESSION['user_id'] ?? 0);
$profileDb = $notifDb instanceof PDO ? $notifDb : null;
$profileMessage = '';
$profileMessageType = 'success';

if ($profileDb instanceof PDO && $profileUserId > 0) {
    try {
        // Tambahkan kolom foto profil jika database versi lama belum memilikinya.
        $profileDb->exec("
            ALTER TABLE users
            ADD COLUMN IF NOT EXISTS profile_photo VARCHAR(255) NULL
            AFTER phone
        ");

        // Simpan perubahan profile.
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
            $profileName  = trim((string)($_POST['profile_full_name'] ?? ''));
            $profileEmail = trim((string)($_POST['profile_email'] ?? ''));
            $profilePhone = trim((string)($_POST['profile_phone'] ?? ''));

            if ($profileName === '') {
                throw new RuntimeException('Nama lengkap wajib diisi.');
            }

            if ($profileEmail !== '' && !filter_var($profileEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Format email tidak valid.');
            }

            // Pastikan email tidak dipakai user lain.
            if ($profileEmail !== '') {
                $checkEmail = $profileDb->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
                $checkEmail->execute([$profileEmail, $profileUserId]);
                if ($checkEmail->fetchColumn()) {
                    throw new RuntimeException('Email sudah digunakan oleh user lain.');
                }
            }

            $photoPath = null;
            if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Upload foto profil gagal.');
                }

                if ((int)$_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('Ukuran foto maksimal 2 MB.');
                }

                $tmp = $_FILES['profile_photo']['tmp_name'];
                $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : '';
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed[$mime])) {
                    throw new RuntimeException('Foto harus JPG, PNG, atau WEBP.');
                }

                $uploadDir = __DIR__ . '/images/uploads/profile/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    throw new RuntimeException('Folder upload foto profil tidak dapat dibuat.');
                }

                $extension = $allowed[$mime];
                $filename = 'profile_' . $profileUserId . '_' . time() . '.' . $extension;
                $destination = $uploadDir . $filename;

                if (!move_uploaded_file($tmp, $destination)) {
                    throw new RuntimeException('Foto profil tidak dapat disimpan.');
                }

                $photoPath = 'images/uploads/profile/' . $filename;
            }

            if ($photoPath !== null) {
                $updateProfile = $profileDb->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?, profile_photo = ?
                    WHERE id = ?
                ");
                $updateProfile->execute([
                    $profileName,
                    $profileEmail !== '' ? $profileEmail : null,
                    $profilePhone !== '' ? $profilePhone : null,
                    $photoPath,
                    $profileUserId
                ]);
            } else {
                $updateProfile = $profileDb->prepare("
                    UPDATE users
                    SET full_name = ?, email = ?, phone = ?
                    WHERE id = ?
                ");
                $updateProfile->execute([
                    $profileName,
                    $profileEmail !== '' ? $profileEmail : null,
                    $profilePhone !== '' ? $profilePhone : null,
                    $profileUserId
                ]);
            }

            // Update session supaya nama/avatar langsung berubah tanpa login ulang.
            $_SESSION['full_name'] = $profileName;
            if ($profileEmail !== '') {
                $_SESSION['email'] = $profileEmail;
            }
            $_SESSION['phone'] = $profilePhone;

            $profileMessage = 'Profile berhasil diperbarui.';
            $profileMessageType = 'success';

            // Password bersifat opsional pada form Edit Profile.
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '') {
                if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                    throw new RuntimeException('Lengkapi semua kolom password untuk mengganti password.');
                }
                if (strlen($newPassword) < 8) {
                    throw new RuntimeException('Password baru minimal 8 karakter.');
                }
                if ($newPassword !== $confirmPassword) {
                    throw new RuntimeException('Konfirmasi password tidak cocok.');
                }

                $passwordColumn = null;
                foreach (['password', 'password_hash', 'user_password'] as $candidateColumn) {
                    $checkColumn = $profileDb->prepare("
                        SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema = DATABASE()
                          AND table_name = 'users'
                          AND column_name = ?
                    ");
                    $checkColumn->execute([$candidateColumn]);
                    if ((int)$checkColumn->fetchColumn() > 0) {
                        $passwordColumn = $candidateColumn;
                        break;
                    }
                }

                if ($passwordColumn === null) {
                    throw new RuntimeException('Kolom password pada tabel users tidak ditemukan.');
                }

                $passwordStmt = $profileDb->prepare("SELECT `" . $passwordColumn . "` FROM users WHERE id = ? LIMIT 1");
                $passwordStmt->execute([$profileUserId]);
                $storedPassword = (string)$passwordStmt->fetchColumn();

                if ($storedPassword === '' || !password_verify($currentPassword, $storedPassword)) {
                    throw new RuntimeException('Password saat ini tidak benar.');
                }

                $updatePassword = $profileDb->prepare(
                    "UPDATE users SET `" . $passwordColumn . "` = ? WHERE id = ?"
                );
                $updatePassword->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $profileUserId
                ]);

                $profileMessage = 'Profile dan password berhasil diperbarui.';
            }
        }

        // Ambil data profile terbaru.
        $profileStmt = $profileDb->prepare("
            SELECT id, username, email, full_name, phone, role, profile_photo
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $profileStmt->execute([$profileUserId]);
        $profileData = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } catch (Throwable $e) {
        $profileData = $profileData ?? [];
        $profileMessage = $e->getMessage();
        $profileMessageType = 'danger';
    }
}

$profileFullName = trim((string)($profileData['full_name'] ?? $_SESSION['full_name'] ?? 'User')) ?: 'User';
$profileUsername = trim((string)($profileData['username'] ?? $_SESSION['username'] ?? ''));
$profileEmail = trim((string)($profileData['email'] ?? $_SESSION['email'] ?? ''));
$profilePhone = trim((string)($profileData['phone'] ?? $_SESSION['phone'] ?? ''));
$profilePhoto = trim((string)($profileData['profile_photo'] ?? ''));
$profileInitial = strtoupper(substr($profileFullName, 0, 1));

/*
 * PERSISTENT NOTIFICATION READ STATE
 * ----------------------------------
 * Setiap user mempunyai daftar notification key yang sudah pernah dibuka.
 * Key disimpan di database supaya status "Sudah Dibaca" tetap ada walaupun
 * halaman di-refresh atau user logout/login kembali.
 */
$notifUserId = (int)($_SESSION['user_id'] ?? ($userId ?? 0));

if ($notifDb instanceof PDO && $notifUserId > 0) {
    try {
        $notifDb->exec("
            CREATE TABLE IF NOT EXISTS user_notification_reads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                notification_key VARCHAR(255) NOT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_user_notification (user_id, notification_key),
                KEY idx_user_read_at (user_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        /*
         * Saat user klik notification, URL tujuan membawa:
         * ?notification_read=<key>
         * Karena halaman tujuan juga include navigation.php, key langsung
         * dicatat sebagai sudah dibaca sebelum daftar notification dirender.
         */
        $notificationReadKey = trim((string)($_GET['notification_read'] ?? ''));
        if ($notificationReadKey !== '' && strlen($notificationReadKey) <= 255) {
            $markRead = $notifDb->prepare("
                INSERT INTO user_notification_reads (user_id, notification_key, read_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)
            ");
            $markRead->execute([$notifUserId, $notificationReadKey]);
        }
    } catch (Throwable $e) {
        // Status baca tidak boleh menghentikan halaman CRM.
    }
}

$notifRole = strtolower(trim((string)($userRole ?? ($role ?? ''))));
$notifRoleAliases = [
    'sales manager' => 'sales_manager',
    'salesmanager' => 'sales_manager',
    'sales_manager' => 'sales_manager',
    'part support' => 'part_support',
    'partsupport' => 'part_support',
    'part_support' => 'part_support',
    'service support' => 'service_support',
    'servicesupport' => 'service_support',
    'service_support' => 'service_support',
    'direktur sales' => 'direktur_sales',
    'direktursales' => 'direktur_sales',
    'direktur_sales' => 'direktur_sales',
    'direktur operasional' => 'direktur_operasional',
    'direkturoperasional' => 'direktur_operasional',
    'direktur_operasional' => 'direktur_operasional',
    'direktur utama' => 'direktur_utama',
    'direkturutama' => 'direktur_utama',
    'direktur_utama' => 'direktur_utama',
];
$notifRoleKey = $notifRoleAliases[$notifRole] ?? $notifRole;

$notifAdd = static function(
    string $title,
    string $message,
    string $url,
    string $key,
    string $type = 'general'
) use (&$notifItems) {
    $notifItems[] = [
        'title' => $title,
        'message' => $message,
        'url' => $url,
        'key' => $key,
        'type' => $type
    ];
};

/*
 * Ambil kolom yang benar-benar ada.
 * Ini membuat navigation aman ketika ada perbedaan kecil antar versi database.
 */
$notifHasTable = static function(PDO $db, string $table): bool {
    try {
        $q = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?"
        );
        $q->execute([$table]);
        return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
};

$notifHasColumn = static function(PDO $db, string $table, string $column): bool {
    try {
        $q = $db->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
             AND table_name = ? AND column_name = ?"
        );
        $q->execute([$table, $column]);
        return (int)$q->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
};

$notifEsc = static function($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

/*
 * TR:
 * Sumber utama nomor TR dan nama PT mengikuti detailtr.php:
 * activity_details -> sales_activities -> accounts.
 */
$notifTR = [];
$notifTRComplete = [];

try {
    if (
        $notifDb instanceof PDO &&
        $notifHasTable($notifDb, 'activity_details') &&
        $notifHasTable($notifDb, 'sales_activities') &&
        $notifHasTable($notifDb, 'accounts') &&
        $notifHasTable($notifDb, 'detail_transaction_requests')
    ) {
        $sqlTR = "
            SELECT
                ad.tr_number,
                ad.due_date,
                ad.subject AS activity_subject,
                ad.jenis_tugas AS activity_jenis_tugas,
                ad.created_at AS request_date,
                ad.id AS activity_detail_id,
                sa.id AS sales_activity_id,
                sa.sales_id,
                a.nama_pt,
                COALESCE(dtr.status, 'pending') AS tr_status,
                dtr.updated_at AS tr_updated_at
            FROM activity_details ad
            LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
            LEFT JOIN accounts a ON sa.account_id = a.id
            LEFT JOIN detail_transaction_requests dtr
                ON dtr.id = (
                    SELECT MAX(d2.id)
                    FROM detail_transaction_requests d2
                    WHERE d2.trf_number = ad.tr_number
                )
            WHERE ad.tr_number IS NOT NULL
              AND TRIM(ad.tr_number) <> ''
            GROUP BY
                ad.tr_number, ad.due_date, ad.subject, ad.jenis_tugas, ad.created_at, ad.id,
                sa.id, sa.sales_id, a.nama_pt, dtr.status, dtr.updated_at
            ORDER BY ad.id DESC
        ";
        $q = $notifDb->query($sqlTR);
        $notifTR = $q->fetchAll(PDO::FETCH_ASSOC);

        foreach ($notifTR as &$tr) {
            $tr['_jenis_tugas'] = $tr['activity_jenis_tugas'] ?? '';
            $tr['_subject'] = $tr['activity_subject'] ?? '';
        }
        unset($tr);

        /*
         * Validasi kelengkapan TR mengikuti validateTRApprovalData()
         * pada detailtr.php:
         * 1. Summary/Deskripsi
         * 2. Detail Unit
         * 3. Term of Payment
         * 4. Additional Cost
         */
        foreach ($notifTR as &$tr) {
            $trNumber = (string)$tr['tr_number'];
            $missing = [];

            $summary = '';
            try {
                $s = $notifDb->prepare("
                    SELECT deskripsi
                    FROM detail_transaction_requests
                    WHERE trf_number = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $s->execute([$trNumber]);
                $summary = (string)$s->fetchColumn();
            } catch (Throwable $e) {}

            if (trim($summary) === '') {
                $missing[] = 'Summary / Deskripsi';
            }

            $units = [];
            if ($notifHasTable($notifDb, 'tr_detail_units')) {
                try {
                    $s = $notifDb->prepare("
                        SELECT unit_id, qty, price
                        FROM tr_detail_units
                        WHERE trf_number = ?
                        ORDER BY id ASC
                    ");
                    $s->execute([$trNumber]);
                    $units = $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }

            if (!$units) {
                $missing[] = 'Detail Unit';
            } else {
                foreach ($units as $unit) {
                    if ((int)($unit['unit_id'] ?? 0) <= 0) {
                        $missing[] = 'Detail Unit - Produk';
                        break;
                    }
                    if ((int)($unit['qty'] ?? 0) <= 0) {
                        $missing[] = 'Detail Unit - Quantity';
                        break;
                    }
                    if ((float)($unit['price'] ?? 0) <= 0) {
                        $missing[] = 'Detail Unit - Harga';
                        break;
                    }
                }
            }

            $payments = [];
            if ($notifHasTable($notifDb, 'tr_term_of_payments')) {
                try {
                    $s = $notifDb->prepare("
                        SELECT amount
                        FROM tr_term_of_payments
                        WHERE trf_number = ?
                        ORDER BY id ASC
                    ");
                    $s->execute([$trNumber]);
                    $payments = $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }

            if (!$payments) {
                $missing[] = 'Term of Payment';
            } else {
                $positivePayment = false;
                foreach ($payments as $payment) {
                    if ((float)($payment['amount'] ?? 0) > 0) {
                        $positivePayment = true;
                        break;
                    }
                }
                if (!$positivePayment) {
                    $missing[] = 'Term of Payment - Nominal';
                }
            }

            $costs = [];
            if ($notifHasTable($notifDb, 'tr_additional_cost_items')) {
                try {
                    $s = $notifDb->prepare("
                        SELECT id
                        FROM tr_additional_cost_items
                        WHERE trf_number = ?
                        ORDER BY id ASC
                    ");
                    $s->execute([$trNumber]);
                    $costs = $s->fetchAll(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {}
            }

            if (!$costs) {
                $missing[] = 'Additional Cost';
            }

            $tr['_missing'] = $missing;
            $tr['_complete'] = empty($missing);
        }
        unset($tr);

        /*
         * Hanya TR yang benar-benar lengkap masuk daftar approval.
         * Current approval ditentukan dari approval history, persis seperti
         * alur detailtr.php.
         */
        $trApprovalLevels = [
            1 => 'sales_manager',
            2 => 'direktur_sales',
            3 => 'direktur_operasional',
            4 => 'direktur_utama',
        ];

        foreach ($notifTR as &$tr) {
            $tr['_current_role'] = null;

            if (($tr['tr_status'] ?? 'pending') !== 'pending') {
                continue;
            }

            try {
                $s = $notifDb->prepare("
                    SELECT approval_order, approval_role, status
                    FROM tr_approval_history
                    WHERE trf_number = ?
                    ORDER BY approval_order ASC
                ");
                $s->execute([$tr['tr_number']]);
                $history = $s->fetchAll(PDO::FETCH_ASSOC);

                $approved = [];
                $rejected = false;

                foreach ($history as $h) {
                    $order = (int)($h['approval_order'] ?? 0);
                    if (!isset($trApprovalLevels[$order])) continue;
                    if ((string)($h['approval_role'] ?? '') !== $trApprovalLevels[$order]) continue;

                    if (($h['status'] ?? '') === 'approved') {
                        $approved[$order] = true;
                    } elseif (($h['status'] ?? '') === 'rejected') {
                        $rejected = true;
                    }
                }

                if (!$rejected) {
                    $currentOrder = 1;
                    for ($i = 1; $i <= 4; $i++) {
                        if (!empty($approved[$i])) {
                            $currentOrder = $i + 1;
                        } else {
                            break;
                        }
                    }
                    $tr['_current_role'] = $trApprovalLevels[$currentOrder] ?? null;
                }
            } catch (Throwable $e) {}
        }
        unset($tr);

        $notifTRComplete = array_values(array_filter(
            $notifTR,
            static fn($row) => !empty($row['_complete'])
        ));
    }
} catch (Throwable $e) {
    $notifTR = [];
    $notifTRComplete = [];
}

/*
 * DI:
 * Sumber nomor DI dan nama PT mengikuti detaildi.php:
 * activity_details -> sales_activities -> accounts.
 */
$notifDI = [];

try {
    if (
        $notifDb instanceof PDO &&
        $notifHasTable($notifDb, 'activity_details') &&
        $notifHasTable($notifDb, 'sales_activities') &&
        $notifHasTable($notifDb, 'accounts') &&
        $notifHasTable($notifDb, 'detail_delivery_instructions')
    ) {
        $sqlDI = "
            SELECT
                ad.di_number,
                ad.created_at AS request_date,
                ad.id AS activity_detail_id,
                sa.id AS sales_activity_id,
                sa.sales_id,
                a.nama_pt,
                COALESCE(ddi.status, 'pending') AS di_status,
                COALESCE(ddi.current_approval_order, 1) AS current_approval_order
            FROM activity_details ad
            LEFT JOIN sales_activities sa ON ad.sales_activity_id = sa.id
            LEFT JOIN accounts a ON sa.account_id = a.id
            LEFT JOIN detail_delivery_instructions ddi
                ON ddi.id = (
                    SELECT MAX(d2.id)
                    FROM detail_delivery_instructions d2
                    WHERE d2.di_number = ad.di_number
                )
            WHERE ad.di_number IS NOT NULL
              AND TRIM(ad.di_number) <> ''
            GROUP BY
                ad.di_number, ad.created_at, ad.id,
                sa.id, sa.sales_id, a.nama_pt,
                ddi.status, ddi.current_approval_order
            ORDER BY ad.id DESC
        ";
        $q = $notifDb->query($sqlDI);
        $notifDI = $q->fetchAll(PDO::FETCH_ASSOC);

        /*
         * DI approval mengikuti detaildi.php:
         * 1 Admin, 2 Business, 3 Service Support, 4 Part Support,
         * 5 Direktur Sales, 6 Direktur Utama.
         */
        $diApprovalLevels = [
            1 => 'admin',
            2 => 'business',
            3 => 'service_support',
            4 => 'part_support',
            5 => 'direktur_sales',
            6 => 'direktur_utama',
        ];

        foreach ($notifDI as &$di) {
            $di['_current_role'] = null;

            if (($di['di_status'] ?? 'pending') !== 'pending') {
                continue;
            }

            $currentOrder = (int)($di['current_approval_order'] ?? 1);

            /*
             * Gunakan current_approval_order dari detail DI.
             * Jika nilainya tidak tersedia/valid, hitung ulang dari history.
             */
            if ($currentOrder < 1 || $currentOrder > 6) {
                $currentOrder = 1;

                try {
                    $s = $notifDb->prepare("
                        SELECT approval_order, approval_role, status
                        FROM di_approval_history
                        WHERE di_number = ?
                        ORDER BY approval_order ASC
                    ");
                    $s->execute([$di['di_number']]);
                    $history = $s->fetchAll(PDO::FETCH_ASSOC);

                    $approved = [];
                    $rejected = false;

                    foreach ($history as $h) {
                        $order = (int)($h['approval_order'] ?? 0);
                        if (!isset($diApprovalLevels[$order])) continue;
                        if (($h['status'] ?? '') === 'approved') {
                            $approved[$order] = true;
                        } elseif (($h['status'] ?? '') === 'rejected') {
                            $rejected = true;
                        }
                    }

                    if (!$rejected) {
                        for ($i = 1; $i <= 6; $i++) {
                            if (!empty($approved[$i])) {
                                $currentOrder = $i + 1;
                            } else {
                                break;
                            }
                        }
                    }
                } catch (Throwable $e) {}
            }

            $di['_current_role'] = $diApprovalLevels[$currentOrder] ?? null;

            /*
             * Detail DI dianggap perlu dilengkapi apabila:
             * - no_so kosong
             * - belum ada unit DI
             * - logistics belum ada
             *
             * Ini mengikuti bagian data yang memang ada pada detaildi.php.
             */
            $missing = [];

            try {
                $s = $notifDb->prepare("
                    SELECT no_so
                    FROM detail_delivery_instructions
                    WHERE di_number = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $s->execute([$di['di_number']]);
                $noSO = trim((string)$s->fetchColumn());
                if ($noSO === '') {
                    $missing[] = 'No. SO';
                }
            } catch (Throwable $e) {
                $missing[] = 'Data Penjualan';
            }

            if ($notifHasTable($notifDb, 'di_units')) {
                try {
                    $s = $notifDb->prepare("SELECT COUNT(*) FROM di_units WHERE di_number = ?");
                    $s->execute([$di['di_number']]);
                    if ((int)$s->fetchColumn() <= 0) {
                        $missing[] = 'Unit';
                    }
                } catch (Throwable $e) {
                    $missing[] = 'Unit';
                }
            }

            if ($notifHasTable($notifDb, 'di_logistics')) {
                try {
                    $s = $notifDb->prepare("SELECT COUNT(*) FROM di_logistics WHERE di_number = ?");
                    $s->execute([$di['di_number']]);
                    if ((int)$s->fetchColumn() <= 0) {
                        $missing[] = 'Logistics';
                    }
                } catch (Throwable $e) {
                    $missing[] = 'Logistics';
                }
            }

            $di['_missing'] = $missing;
            $di['_complete'] = empty($missing);
        }
        unset($di);
    }
} catch (Throwable $e) {
    $notifDI = [];
}

/* ==========================================================
   SALES ACTIVITY DUE-DATE NOTIFICATIONS
   Menampilkan Nama PT + Jenis Tugas + Subject + Due Date.
   Sumbernya langsung dari activity_details sehingga semua jenis
   tugas (bukan hanya yang memiliki TR) ikut ter-cover.
   ========================================================== */

$notifActivitiesDue = [];

try {
    if (
        $notifDb instanceof PDO &&
        $notifHasTable($notifDb, 'activity_details') &&
        $notifHasTable($notifDb, 'sales_activities') &&
        $notifHasTable($notifDb, 'accounts')
    ) {
        $sqlActivityDue = "
            SELECT
                ad.id AS activity_detail_id,
                ad.sales_activity_id,
                ad.subject,
                ad.jenis_tugas,
                ad.due_date,
                ad.status,
                sa.sales_id,
                a.nama_pt
            FROM activity_details ad
            INNER JOIN sales_activities sa ON ad.sales_activity_id = sa.id
            LEFT JOIN accounts a ON sa.account_id = a.id
            WHERE ad.due_date IS NOT NULL
              AND ad.status = 'in_progress'
              AND DATE(ad.due_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY ad.due_date ASC, ad.id DESC
        ";

        $q = $notifDb->query($sqlActivityDue);
        $notifActivitiesDue = $q->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $notifActivitiesDue = [];
}

/* ==========================================================
   BUILD NOTIFICATIONS PER ROLE
   ========================================================== */

try {
    if ($notifDb instanceof PDO) {

        /* ---------------- SALES ---------------- */
        if ($notifRoleKey === 'sales') {
            foreach ($notifActivitiesDue as $activityDue) {
                if ((int)($activityDue['sales_id'] ?? 0) !== $notifUserId) continue;

                $company = trim((string)($activityDue['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                $taskType = trim((string)($activityDue['jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                $subject = trim((string)($activityDue['subject'] ?? '')) ?: 'Aktivitas';

                $notifAdd(
                    'Sales Activity Mendekati Due Date',
                    $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($activityDue['due_date'])),
                    'detailaktivitas.php?leads_id=' . (int)$activityDue['sales_activity_id'],
                    'activity_due_' . $activityDue['activity_detail_id'],
                    'due'
                );
            }

            foreach ($notifTR as $tr) {
                if ((int)($tr['sales_id'] ?? 0) !== $notifUserId) continue;

                $due = $tr['due_date'] ?? null;
                if ($due && strtotime($due) !== false) {
                    $days = (strtotime(date('Y-m-d', strtotime($due))) - strtotime(date('Y-m-d'))) / 86400;
                    if ($days >= 0 && $days <= 7 && !in_array(($tr['tr_status'] ?? ''), ['approved','rejected'], true)) {
                        $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                        $taskType = trim((string)($tr['_jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                        $subject = trim((string)($tr['_subject'] ?? '')) ?: 'Aktivitas';
                        $notifAdd(
                            'Sales Activity Mendekati Due Date',
                            $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($due)),
                            'detailaktivitas.php?leads_id=' . (int)($tr['sales_activity_id'] ?? 0),
                            'sales_due_' . $tr['activity_detail_id'],
                            'due'
                        );
                    }
                }

                if (!$tr['_complete'] && ($tr['tr_status'] ?? 'pending') !== 'approved') {
                    $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                    $missing = implode(', ', $tr['_missing']);
                    $notifAdd(
                        'Harus Melengkapi Detail TR',
                        $tr['tr_number'] . ' • ' . $company . ' • Kurang: ' . $missing,
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'sales_tr_incomplete_' . $tr['tr_number'],
                        'incomplete'
                    );
                }

                if (($tr['tr_status'] ?? '') === 'approved') {
                    $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                    $notifAdd(
                        'Transaction Request Sudah Approved',
                        $tr['tr_number'] . ' • ' . $company . ' • Seluruh approval TR selesai.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'sales_tr_approved_' . $tr['tr_number'],
                        'approved'
                    );
                }
            }

            foreach ($notifDI as $di) {
                if ((int)($di['sales_id'] ?? 0) !== $notifUserId) continue;

                if (($di['di_status'] ?? '') === 'approved') {
                    $company = trim((string)($di['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                    $notifAdd(
                        'Delivery Instruction Sudah Approved',
                        $di['di_number'] . ' • ' . $company . ' • Seluruh approval DI selesai.',
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        'sales_di_approved_' . $di['di_number'],
                        'approved'
                    );
                }
            }
        }

        /* ---------------- SALES MANAGER ---------------- */
        if ($notifRoleKey === 'sales_manager') {
            foreach ($notifActivitiesDue as $activityDue) {
                $company = trim((string)($activityDue['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                $taskType = trim((string)($activityDue['jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                $subject = trim((string)($activityDue['subject'] ?? '')) ?: 'Aktivitas';

                $notifAdd(
                    'Sales Activity Mendekati Due Date',
                    $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($activityDue['due_date'])),
                    'detailaktivitas.php?leads_id=' . (int)$activityDue['sales_activity_id'],
                    'activity_due_' . $activityDue['activity_detail_id'],
                    'due'
                );
            }

            foreach ($notifTR as $tr) {
                $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';

                if (
                    !empty($tr['request_date']) &&
                    strtotime($tr['request_date']) >= strtotime('-7 days')
                ) {
                    $notifAdd(
                        'Transaction Request Number Baru',
                        $tr['tr_number'] . ' • ' . $company . ' • TR baru masuk.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'sm_new_tr_' . $tr['tr_number'],
                        'new'
                    );
                }

                if ($tr['_complete'] && ($tr['_current_role'] ?? '') === 'sales_manager') {
                    $notifAdd(
                        'Harus Approve Transaction Request',
                        $tr['tr_number'] . ' • ' . $company . ' • TR lengkap dan menunggu approval Sales Manager.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'sm_approve_tr_' . $tr['tr_number'],
                        'approval'
                    );
                }

                if (!$tr['_complete'] && ($tr['tr_status'] ?? 'pending') === 'pending') {
                    $notifAdd(
                        'Harus Melengkapi Detail TR',
                        $tr['tr_number'] . ' • ' . $company . ' • Kurang: ' . implode(', ', $tr['_missing']),
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'sm_incomplete_tr_' . $tr['tr_number'],
                        'incomplete'
                    );
                }
            }

            foreach ($notifTR as $tr) {
                if (($tr['tr_status'] ?? '') === 'pending' && $tr['due_date']) {
                    $days = (strtotime(date('Y-m-d', strtotime($tr['due_date']))) - strtotime(date('Y-m-d'))) / 86400;
                    if ($days >= 0 && $days <= 7) {
                        $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                        $taskType = trim((string)($tr['_jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                        $subject = trim((string)($tr['_subject'] ?? '')) ?: 'Aktivitas';
                        $notifAdd(
                            'Sales Activity Mendekati Due Date',
                            $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($tr['due_date'])),
                            'detailaktivitas.php?leads_id=' . (int)($tr['sales_activity_id'] ?? 0),
                            'sm_due_' . $tr['activity_detail_id'],
                            'due'
                        );
                    }
                }
            }
        }

        /* ---------------- BUSINESS ---------------- */
        if ($notifRoleKey === 'business') {
            foreach ($notifActivitiesDue as $activityDue) {
                $company = trim((string)($activityDue['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                $taskType = trim((string)($activityDue['jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                $subject = trim((string)($activityDue['subject'] ?? '')) ?: 'Aktivitas';

                $notifAdd(
                    'Sales Activity Mendekati Due Date',
                    $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($activityDue['due_date'])),
                    'detailaktivitas.php?leads_id=' . (int)$activityDue['sales_activity_id'],
                    'activity_due_' . $activityDue['activity_detail_id'],
                    'due'
                );
            }

            foreach ($notifTR as $tr) {
                $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';

                if (
                    !empty($tr['request_date']) &&
                    strtotime($tr['request_date']) >= strtotime('-7 days')
                ) {
                    $notifAdd(
                        'Transaction Request Number Baru',
                        $tr['tr_number'] . ' • ' . $company . ' • TR baru masuk.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'business_new_tr_' . $tr['tr_number'],
                        'new'
                    );
                }

                if (!$tr['_complete'] && ($tr['tr_status'] ?? 'pending') === 'pending') {
                    $notifAdd(
                        'Harus Melengkapi Detail TR',
                        $tr['tr_number'] . ' • ' . $company . ' • Kurang: ' . implode(', ', $tr['_missing']),
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        'business_incomplete_tr_' . $tr['tr_number'],
                        'incomplete'
                    );
                }
            }
        }

        /* ---------------- ADMIN ---------------- */
        if ($notifRoleKey === 'admin') {
            foreach ($notifDI as $di) {
                $company = trim((string)($di['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';

                if (
                    !empty($di['request_date']) &&
                    strtotime($di['request_date']) >= strtotime('-7 days')
                ) {
                    $notifAdd(
                        'Delivery Instruction Baru',
                        $di['di_number'] . ' • ' . $company . ' • DI baru masuk.',
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        'admin_new_di_' . $di['di_number'],
                        'new'
                    );
                }

                if (!$di['_complete'] && ($di['di_status'] ?? 'pending') === 'pending') {
                    $notifAdd(
                        'Harus Melengkapi Detail DI',
                        $di['di_number'] . ' • ' . $company . ' • Kurang: ' . implode(', ', $di['_missing']),
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        'admin_incomplete_di_' . $di['di_number'],
                        'incomplete'
                    );
                }

                if (
                    ($di['_current_role'] ?? '') === 'admin' &&
                    ($di['di_status'] ?? 'pending') === 'pending'
                ) {
                    $notifAdd(
                        'Harus Approve Delivery Instruction',
                        $di['di_number'] . ' • ' . $company . ' • DI menunggu approval Admin.',
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        'admin_approve_di_' . $di['di_number'],
                        'approval'
                    );
                }
            }
        }

        /* ---------------- PART / SERVICE SUPPORT ---------------- */
        if (in_array($notifRoleKey, ['part_support', 'service_support'], true)) {
            foreach ($notifDI as $di) {
                if (($di['_current_role'] ?? '') !== $notifRoleKey) continue;
                if (($di['di_status'] ?? 'pending') !== 'pending') continue;

                $company = trim((string)($di['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                $label = $notifRoleKey === 'part_support' ? 'Part Support' : 'Service Support';

                $notifAdd(
                    'Harus Approve Delivery Instruction',
                    $di['di_number'] . ' • ' . $company . ' • DI menunggu approval ' . $label . '.',
                    'detaildi.php?di_number=' . urlencode($di['di_number']),
                    $notifRoleKey . '_approve_di_' . $di['di_number'],
                    'approval'
                );
            }
        }

        /* ---------------- DIREKTUR ---------------- */
        if (in_array($notifRoleKey, ['direktur_sales', 'direktur_operasional', 'direktur_utama'], true)) {
            foreach ($notifActivitiesDue as $activityDue) {
                $company = trim((string)($activityDue['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';
                $taskType = trim((string)($activityDue['jenis_tugas'] ?? '')) ?: 'Jenis tugas tidak tersedia';
                $subject = trim((string)($activityDue['subject'] ?? '')) ?: 'Aktivitas';

                $notifAdd(
                    'Sales Activity Mendekati Due Date',
                    $company . ' • ' . $taskType . ' • ' . $subject . ' • Due ' . date('d-m-Y', strtotime($activityDue['due_date'])),
                    'detailaktivitas.php?leads_id=' . (int)$activityDue['sales_activity_id'],
                    'activity_due_' . $activityDue['activity_detail_id'],
                    'due'
                );
            }

            foreach ($notifTR as $tr) {
                $company = trim((string)($tr['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';

                if (
                    !empty($tr['request_date']) &&
                    strtotime($tr['request_date']) >= strtotime('-7 days')
                ) {
                    $notifAdd(
                        'Transaction Request Number Baru',
                        $tr['tr_number'] . ' • ' . $company . ' • TR baru masuk.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        $notifRoleKey . '_new_tr_' . $tr['tr_number'],
                        'new'
                    );
                }

                if (
                    $tr['_complete'] &&
                    ($tr['_current_role'] ?? '') === $notifRoleKey
                ) {
                    $notifAdd(
                        'Harus Approve Transaction Request',
                        $tr['tr_number'] . ' • ' . $company . ' • TR lengkap dan menunggu approval ' .
                        ucwords(str_replace('_', ' ', $notifRoleKey)) . '.',
                        'detailtr.php?tr_number=' . urlencode($tr['tr_number']),
                        $notifRoleKey . '_approve_tr_' . $tr['tr_number'],
                        'approval'
                    );
                }
            }

            foreach ($notifDI as $di) {
                $company = trim((string)($di['nama_pt'] ?? '')) ?: 'Nama PT tidak tersedia';

                if (
                    !empty($di['request_date']) &&
                    strtotime($di['request_date']) >= strtotime('-7 days')
                ) {
                    $notifAdd(
                        'Delivery Instruction Baru',
                        $di['di_number'] . ' • ' . $company . ' • DI baru masuk.',
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        $notifRoleKey . '_new_di_' . $di['di_number'],
                        'new'
                    );
                }

                if (
                    ($di['_current_role'] ?? '') === $notifRoleKey &&
                    ($di['di_status'] ?? 'pending') === 'pending'
                ) {
                    $notifAdd(
                        'Harus Approve Delivery Instruction',
                        $di['di_number'] . ' • ' . $company . ' • DI menunggu approval ' .
                        ucwords(str_replace('_', ' ', $notifRoleKey)) . '.',
                        'detaildi.php?di_number=' . urlencode($di['di_number']),
                        $notifRoleKey . '_approve_di_' . $di['di_number'],
                        'approval'
                    );
                }
            }
        }
    }
} catch (Throwable $e) {
    // Notification tidak boleh menghentikan halaman utama CRM.
}

/* Hilangkan item yang benar-benar duplikat */
$uniqueNotif = [];
foreach ($notifItems as $item) {
    $uniqueNotif[$item['key']] = $item;
}
$notifItems = array_values($uniqueNotif);

/*
 * Ambil daftar notification key yang sudah dibaca oleh user aktif.
 */
$notifReadKeys = [];
if ($notifDb instanceof PDO && $notifUserId > 0 && $notifItems) {
    try {
        $placeholders = implode(',', array_fill(0, count($notifItems), '?'));
        $params = [$notifUserId];
        foreach ($notifItems as $item) {
            $params[] = (string)$item['key'];
        }

        $readStmt = $notifDb->prepare("
            SELECT notification_key
            FROM user_notification_reads
            WHERE user_id = ?
              AND notification_key IN ($placeholders)
        ");
        $readStmt->execute($params);

        while ($row = $readStmt->fetch(PDO::FETCH_ASSOC)) {
            $notifReadKeys[(string)$row['notification_key']] = true;
        }
    } catch (Throwable $e) {
        $notifReadKeys = [];
    }
}

/* Tandai masing-masing item sebagai read/unread untuk tampilan. */
foreach ($notifItems as &$item) {
    $item['is_read'] = isset($notifReadKeys[(string)$item['key']]);
}
unset($item);

$notifUnreadItems = array_values(array_filter(
    $notifItems,
    static function ($item) {
        return empty($item['is_read']);
    }
));

$notifReadItems = array_values(array_filter(
    $notifItems,
    static function ($item) {
        return !empty($item['is_read']);
    }
));

$notifUnread = count($notifUnreadItems);
?>
<link rel="stylesheet" href="css/navigation.css">
<link rel="stylesheet" href="css/notification.css">
<link rel="stylesheet" href="css/app.css">

<header class="topbar">
    <button class="mobile-menu-toggle"
            type="button"
            aria-label="Buka menu navigasi"
            aria-controls="crmSidebar"
            aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <a class="brand" href="dashboard.php">
        <img src="images/logo.webp" alt="GET">
        <div><strong>PT GANDA ELANG TANGGUH</strong><small>Customer Relationship Management</small></div>
    </a>
    <div class="top-actions">
        <div class="notification-wrap">
            <button class="icon-btn notification-btn" type="button" aria-label="Notifications" aria-expanded="false">
                <i class="far fa-bell"></i>
                <?php if ($notifUnread > 0): ?>
                    <span class="notif"><?= $notifUnread > 99 ? '99+' : $notifUnread ?></span>
                <?php endif; ?>
            </button>

            <div class="notification-panel" hidden>
                <div class="notification-head">
                    <div>
                        <strong>Notifikasi</strong>
                        <small><span class="notification-unread-total"><?= $notifUnread ?></span> belum dibaca</small>
                    </div>
                    <button type="button" class="notification-close" aria-label="Tutup">&times;</button>
                </div>

                <div class="notification-tabs" role="tablist" aria-label="Status notifikasi">
                    <button type="button"
                            class="notification-tab active"
                            data-notification-tab="unread"
                            role="tab"
                            aria-selected="true">
                        Belum Dibaca
                        <span class="notification-tab-count"><?= $notifUnread ?></span>
                    </button>
                    <button type="button"
                            class="notification-tab"
                            data-notification-tab="read"
                            role="tab"
                            aria-selected="false">
                        Sudah Dibaca
                        <span class="notification-tab-count"><?= count($notifReadItems) ?></span>
                    </button>
                </div>

                <div class="notification-list notification-list-unread" data-notification-list="unread">
                    <?php if (!$notifUnreadItems): ?>
                        <div class="notification-empty">
                            <i class="far fa-check-circle"></i>
                            <strong>Semua sudah dibaca</strong>
                            <span>Tidak ada pemberitahuan baru yang belum dibaca.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifUnreadItems as $item): ?>
                            <a class="notification-item notification-unread notification-type-<?= htmlspecialchars($item['type'] ?? 'general') ?>"
                               href="<?= htmlspecialchars($item['url'] . (strpos($item['url'], '?') !== false ? '&' : '?') . 'notification_read=' . rawurlencode($item['key'])) ?>"
                               data-notification-key="<?= htmlspecialchars($item['key']) ?>">
                                <span class="notification-icon">
                                    <?php
                                    $icon = 'fa-bell';
                                    if (($item['type'] ?? '') === 'approval') $icon = 'fa-check-circle';
                                    elseif (($item['type'] ?? '') === 'incomplete') $icon = 'fa-exclamation-circle';
                                    elseif (($item['type'] ?? '') === 'new') $icon = 'fa-file-circle-plus';
                                    elseif (($item['type'] ?? '') === 'due') $icon = 'fa-clock';
                                    elseif (($item['type'] ?? '') === 'approved') $icon = 'fa-circle-check';
                                    ?>
                                    <i class="fas <?= $icon ?>"></i>
                                </span>
                                <span class="notification-content">
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <span><?= htmlspecialchars($item['message']) ?></span>
                                    <small class="notification-status-label">Belum dibaca</small>
                                </span>
                                <i class="fas fa-chevron-right notification-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="notification-list notification-list-read" data-notification-list="read" hidden>
                    <?php if (!$notifReadItems): ?>
                        <div class="notification-empty">
                            <i class="far fa-folder-open"></i>
                            <strong>Belum ada yang dibaca</strong>
                            <span>Notification yang sudah Anda buka akan muncul di sini.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifReadItems as $item): ?>
                            <a class="notification-item notification-read notification-type-<?= htmlspecialchars($item['type'] ?? 'general') ?>"
                               href="<?= htmlspecialchars($item['url']) ?>"
                               data-notification-key="<?= htmlspecialchars($item['key']) ?>">
                                <span class="notification-icon">
                                    <?php
                                    $icon = 'fa-bell';
                                    if (($item['type'] ?? '') === 'approval') $icon = 'fa-check-circle';
                                    elseif (($item['type'] ?? '') === 'incomplete') $icon = 'fa-exclamation-circle';
                                    elseif (($item['type'] ?? '') === 'new') $icon = 'fa-file-circle-plus';
                                    elseif (($item['type'] ?? '') === 'due') $icon = 'fa-clock';
                                    elseif (($item['type'] ?? '') === 'approved') $icon = 'fa-circle-check';
                                    ?>
                                    <i class="fas <?= $icon ?>"></i>
                                </span>
                                <span class="notification-content">
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <span><?= htmlspecialchars($item['message']) ?></span>
                                    <small class="notification-status-label">Sudah dibaca</small>
                                </span>
                                <i class="fas fa-chevron-right notification-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <button class="avatar profile-trigger" type="button" aria-label="Buka profile" aria-haspopup="dialog" aria-controls="profileModal">
            <?php if ($profilePhoto !== ''): ?>
                <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="Profile">
            <?php else: ?>
                <span><?= htmlspecialchars($profileInitial) ?></span>
            <?php endif; ?>
        </button>
    </div>
</header>

<aside class="rail" id="crmSidebar">
    <div class="rail-label">Main Menu</div>
    <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if ($showMenu('sales_activity')): ?>
        <a class="<?= in_array($currentPage, ['salesactivity.php', 'detailaktivitas.php'], true) ? 'active' : '' ?>"
        href="salesactivity.php">
            <i class="fas fa-chart-line"></i><span>Sales Activity</span>
        </a>
    <?php endif; ?>
    <?php if ($showMenu('account_management')): ?><a class="<?= $currentPage === 'account_management.php' ? 'active' : '' ?>" href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a><?php endif; ?>
    <?php if ($showMenu('transaction_request')): ?><a class="<?= in_array($currentPage, ['transactionrequest.php', 'detailtr.php'], true) ? 'active' : '' ?>" href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a><?php endif; ?>
    <?php if ($showMenu('produk')): ?><a class="<?= $currentPage === 'produk.php' ? 'active' : '' ?>" href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a><?php endif; ?>
    <?php if ($showMenu('delivery_order')): ?><a class="<?= in_array($currentPage, ['deliveryinstruction.php', 'detaildi.php'], true) ? 'active' : '' ?>" href="deliveryinstruction.php"><i class="fas fa-truck"></i><span>Delivery Instruction</span></a><?php endif; ?>
    <div class="rail-label">Administration</div>
    <?php if ($showMenu('data_user')): ?><a class="<?= $currentPage === 'data_user.php' ? 'active' : '' ?>" href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a><?php endif; ?>
    <?php if ($showMenu('data_sales') && file_exists('data_sales.php')): ?><a class="<?= $currentPage === 'data_sales.php' ? 'active' : '' ?>" href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a><?php endif; ?>
    <div class="spacer"></div>
</aside>

<!-- =========================================================
     PROFILE PANEL — PREVIEW / EDIT
     ========================================================= -->
<div class="profile-modal" id="profileModal" hidden>
    <div class="profile-modal-backdrop" data-profile-close></div>

    <section class="profile-card" role="dialog" aria-modal="false" aria-labelledby="profileModalTitle">
        <!-- PREVIEW MODE -->
        <div class="profile-preview" id="profilePreview">
            <div class="profile-preview-top">
                <div>
                    <span class="profile-eyebrow">ACCOUNT</span>
                    <h2 id="profileModalTitle">Profile Pribadi</h2>
                    <p>Informasi akun Anda.</p>
                </div>
                <button type="button" class="profile-close" data-profile-close aria-label="Tutup">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="profile-identity">
                <div class="profile-avatar-large">
                    <?php if ($profilePhoto !== ''): ?>
                        <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="Foto profile">
                    <?php else: ?>
                        <span><?= htmlspecialchars($profileInitial) ?></span>
                    <?php endif; ?>
                </div>
                <div class="profile-identity-text">
                    <strong><?= htmlspecialchars($profileFullName) ?></strong>
                    <span>@<?= htmlspecialchars($profileUsername) ?></span>
                    <small><i class="fas fa-shield-alt"></i> <?= htmlspecialchars(getRoleLabel($role)) ?></small>
                </div>
            </div>

            <div class="profile-info-list">
                <div class="profile-info-item">
                    <span class="profile-info-icon"><i class="fas fa-envelope"></i></span>
                    <div><small>Email</small><strong><?= htmlspecialchars($profileEmail ?: '-') ?></strong></div>
                </div>
                <div class="profile-info-item">
                    <span class="profile-info-icon"><i class="fas fa-phone"></i></span>
                    <div><small>No. Telepon</small><strong><?= htmlspecialchars($profilePhone ?: '-') ?></strong></div>
                </div>
                <div class="profile-info-item">
                    <span class="profile-info-icon"><i class="fas fa-user"></i></span>
                    <div><small>Username</small><strong><?= htmlspecialchars($profileUsername ?: '-') ?></strong></div>
                </div>
            </div>

            <div class="profile-preview-footer">
                <button type="button" class="profile-btn profile-btn-edit" id="profileEditBtn">
                    <i class="fas fa-pen"></i> Edit Profile
                </button>
                <a href="logout.php" class="profile-btn profile-btn-logout">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <!-- EDIT MODE -->
        <div class="profile-edit" id="profileEdit" hidden>
            <div class="profile-card-header">
                <div>
                    <span class="profile-eyebrow">ACCOUNT SETTINGS</span>
                    <h2>Edit Profile</h2>
                    <p>Perbarui informasi dan keamanan akun.</p>
                </div>
                <button type="button" class="profile-close" data-profile-close aria-label="Tutup">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <?php if ($profileMessage !== ''): ?>
                <div class="profile-alert profile-alert-<?= htmlspecialchars($profileMessageType) ?>">
                    <i class="fas <?= $profileMessageType === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <span><?= htmlspecialchars($profileMessage) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" class="profile-form">
                <input type="hidden" name="action" value="update_profile">

                <div class="profile-photo-section">
                    <div class="profile-photo-wrap">
                        <div class="profile-photo-preview" id="profilePhotoPreview">
                            <?php if ($profilePhoto !== ''): ?>
                                <img src="<?= htmlspecialchars($profilePhoto) ?>" alt="Foto profile">
                            <?php else: ?>
                                <span><?= htmlspecialchars($profileInitial) ?></span>
                            <?php endif; ?>
                        </div>
                        <label class="profile-photo-upload" for="profilePhotoInput" title="Ganti foto profile">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="profilePhotoInput" name="profile_photo"
                               accept="image/jpeg,image/png,image/webp" hidden>
                    </div>
                    <div class="profile-photo-info">
                        <strong>Foto Profile</strong>
                        <span>JPG, PNG atau WEBP</span>
                        <small>Maksimal 2 MB</small>
                        <label for="profilePhotoInput" class="profile-upload-btn">
                            <i class="fas fa-upload"></i> Pilih Foto
                        </label>
                    </div>
                </div>

                <div class="profile-grid">
                    <div class="profile-field profile-field-full">
                        <label>Nama Lengkap</label>
                        <div class="profile-input-wrap">
                            <i class="fas fa-user"></i>
                            <input type="text" name="profile_full_name" value="<?= htmlspecialchars($profileFullName) ?>" required>
                        </div>
                    </div>

                    <div class="profile-field">
                        <label>Username</label>
                        <div class="profile-input-wrap">
                            <i class="fas fa-at"></i>
                            <input type="text" value="<?= htmlspecialchars($profileUsername) ?>" readonly>
                        </div>
                    </div>

                    <div class="profile-field">
                        <label>Email</label>
                        <div class="profile-input-wrap">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="profile_email" value="<?= htmlspecialchars($profileEmail) ?>">
                        </div>
                    </div>

                    <div class="profile-field profile-field-full">
                        <label>No. Telepon</label>
                        <div class="profile-input-wrap">
                            <i class="fas fa-phone"></i>
                            <input type="text" name="profile_phone" value="<?= htmlspecialchars($profilePhone) ?>" placeholder="Masukkan nomor telepon">
                        </div>
                    </div>
                </div>

                <div class="password-section">
                    <div class="password-section-title">
                        <span class="password-section-icon"><i class="fas fa-lock"></i></span>
                        <div>
                            <strong>Reset Password</strong>
                            <small>Ganti password akun Anda secara aman.</small>
                        </div>
                    </div>

                    <div class="password-grid">
                        <div class="profile-field password-full">
                            <label>Password Saat Ini</label>
                            <div class="profile-input-wrap password-wrap">
                                <i class="fas fa-key"></i>
                                <input type="password" name="current_password" autocomplete="current-password" placeholder="Masukkan password saat ini">
                                <button type="button" class="password-toggle" data-password-target="current_password" aria-label="Tampilkan password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="profile-field">
                            <label>Password Baru</label>
                            <div class="profile-input-wrap password-wrap">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="new_password" minlength="8" autocomplete="new-password" placeholder="Min. 8 karakter">
                                <button type="button" class="password-toggle" data-password-target="new_password" aria-label="Tampilkan password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="profile-field">
                            <label>Konfirmasi Password</label>
                            <div class="profile-input-wrap password-wrap">
                                <i class="fas fa-check"></i>
                                <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Ulangi password baru">
                                <button type="button" class="password-toggle" data-password-target="confirm_password" aria-label="Tampilkan password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <small class="profile-help password-help">Kosongkan kolom password jika tidak ingin menggantinya.</small>
                </div>

                <div class="profile-card-footer">
                    <button type="button" class="profile-btn profile-btn-secondary" id="profileBackBtn">
                        <i class="fas fa-arrow-left"></i> Kembali
                    </button>
                    <button type="submit" class="profile-btn profile-btn-primary">
                        <i class="fas fa-save"></i> Simpan Profile
                    </button>
                </div>
            </form>
        </div>
    </section>
</div>

<script>
(function () {
    const toggle = document.querySelector('.mobile-menu-toggle');
    const sidebar = document.getElementById('crmSidebar');

    if (!toggle || !sidebar) return;

    let overlay = document.querySelector('.mobile-menu-overlay');

    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'mobile-menu-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        document.body.appendChild(overlay);
    }

    function openMenu() {
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.body.classList.add('mobile-menu-open');
        toggle.classList.add('active');
        toggle.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-label', 'Tutup menu navigasi');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeMenu() {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.classList.remove('mobile-menu-open');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Buka menu navigasi');
        overlay.setAttribute('aria-hidden', 'true');
    }

    toggle.addEventListener('click', function (event) {
        event.stopPropagation();
        sidebar.classList.contains('open') ? closeMenu() : openMenu();
    });

    overlay.addEventListener('click', closeMenu);

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', closeMenu);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMenu();
    });

    window.addEventListener('resize', function () {
        if (window.innerWidth > 768) closeMenu();
    });
})();

(function () {
    const wrap = document.querySelector('.notification-wrap');
    if (!wrap) return;

    const btn = wrap.querySelector('.notification-btn');
    const panel = wrap.querySelector('.notification-panel');
    const close = wrap.querySelector('.notification-close');
    const tabs = wrap.querySelectorAll('[data-notification-tab]');
    const lists = wrap.querySelectorAll('[data-notification-list]');

    function openNotif() {
        panel.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => panel.classList.add('show'));
    }

    function closeNotif() {
        panel.classList.remove('show');
        btn.setAttribute('aria-expanded', 'false');
        setTimeout(() => { panel.hidden = true; }, 160);
    }

    function switchNotificationTab(tabName) {
        tabs.forEach(function (tab) {
            const active = tab.getAttribute('data-notification-tab') === tabName;
            tab.classList.toggle('active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });

        lists.forEach(function (list) {
            list.hidden = list.getAttribute('data-notification-list') !== tabName;
        });
    }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.hidden) openNotif();
        else closeNotif();
    });

    if (close) close.addEventListener('click', closeNotif);

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            switchNotificationTab(tab.getAttribute('data-notification-tab'));
        });
    });

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target) && !panel.hidden) closeNotif();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) closeNotif();
    });
})();

(function () {
    const modal = document.getElementById('profileModal');
    const trigger = document.querySelector('.profile-trigger');
    if (!modal || !trigger) return;

    const previewMode = document.getElementById('profilePreview');
    const editMode = document.getElementById('profileEdit');
    const editBtn = document.getElementById('profileEditBtn');
    const backBtn = document.getElementById('profileBackBtn');
    const closeButtons = modal.querySelectorAll('[data-profile-close]');
    const fileInput = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('profilePhotoPreview');

    function showPreview() {
        previewMode.hidden = false;
        editMode.hidden = true;
    }

    function showEdit() {
        previewMode.hidden = true;
        editMode.hidden = false;
    }

    function openProfile() {
        modal.hidden = false;
        showPreview();
        trigger.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => modal.classList.add('show'));
    }

    function closeProfile() {
        modal.classList.remove('show');
        trigger.setAttribute('aria-expanded', 'false');
        setTimeout(() => { modal.hidden = true; }, 180);
    }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        if (modal.hidden) openProfile();
        else closeProfile();
    });

    editBtn?.addEventListener('click', function () {
        showEdit();
    });

    backBtn?.addEventListener('click', function () {
        showPreview();
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeProfile);
    });

    if (fileInput && preview) {
        fileInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;

            if (file.size > 2 * 1024 * 1024) {
                alert('Ukuran foto maksimal 2 MB.');
                this.value = '';
                return;
            }

            if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
                alert('Foto harus JPG, PNG, atau WEBP.');
                this.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function (event) {
                preview.innerHTML = '<img src="' + event.target.result + '" alt="Preview foto profile">';
            };
            reader.readAsDataURL(file);
        });
    }

    modal.querySelectorAll('.password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.querySelector('[name="' + this.dataset.passwordTarget + '"]');
            if (!input) return;

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            this.innerHTML = isPassword
                ? '<i class="fas fa-eye-slash"></i>'
                : '<i class="fas fa-eye"></i>';
        });
    });

    // Clicking outside the actual card closes the panel.
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeProfile();
    });

    document.addEventListener('click', function (e) {
        if (!modal.hidden && !modal.querySelector('.profile-card').contains(e.target) && !trigger.contains(e.target)) {
            closeProfile();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) closeProfile();
    });
})();

</script>
