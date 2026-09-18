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
   ==========================================================
   Dibuat kompatibel dengan struktur CRM yang sudah digunakan:
   - sales_activity / detail sales activity
   - detail_transaction_requests / transaction_request
   - delivery_instructions / detail DI
   - approval berdasarkan role
   ========================================================== */

$notifItems = [];
$notifUnread = 0;

$addNotif = static function(string $title, string $message, string $url, string $key) use (&$notifItems) {
    $notifItems[] = [
        'title'   => $title,
        'message' => $message,
        'url'     => $url,
        'key'     => $key
    ];
};

/* Cari koneksi PDO yang sudah dipakai halaman */
$notifDb = null;
foreach (['pdo', 'conn', 'db'] as $candidate) {
    if (isset($$candidate) && $$candidate instanceof PDO) {
        $notifDb = $$candidate;
        break;
    }
}
if (!$notifDb && function_exists('getPDO')) {
    try { $notifDb = getPDO(); } catch (Throwable $e) {}
}

/* Helper aman: cek tabel/kolom tanpa mengganggu halaman */
$notifTableExists = static function(PDO $db, string $table): bool {
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
};
$notifColumns = static function(PDO $db, string $table): array {
    try {
        $st = $db->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        return array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return [];
    }
};
$notifPickCol = static function(array $cols, array $names): ?string {
    foreach ($names as $name) {
        if (in_array(strtolower($name), $cols, true)) return $name;
    }
    return null;
};

$notifRole = strtolower(trim((string)($role ?? '')));
$notifUserId = (int)($userId ?? ($id_user ?? ($id ?? 0)));
$notifFullName = (string)($fullName ?? '');

try {
    if ($notifDb instanceof PDO) {
        /* --------------------------------------------------
           SALES
           -------------------------------------------------- */
        if (in_array($notifRole, ['sales', 'sales marketing', 'salesmarketing'], true)) {

            // Detail Sales Activity mendekati due date (7 hari ke depan)
            if ($notifTableExists($notifDb, 'sales_activities')) {
                $c = $notifColumns($notifDb, 'sales_activities');
                $idc = $notifPickCol($c, ['id','activity_id']);
                $userc = $notifPickCol($c, ['user_id','sales_id','id_user','created_by']);
                $duec = $notifPickCol($c, ['due_date','deadline','tanggal_due_date','end_date']);
                if ($idc && $duec) {
                    $where = "DATE(`$duec`) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
                    $params = [];
                    if ($userc && $notifUserId > 0) { $where .= " AND `$userc` = ?"; $params[] = $notifUserId; }
                    $st = $notifDb->prepare("SELECT COUNT(*) FROM `sales_activities` WHERE $where");
                    $st->execute($params);
                    $n = (int)$st->fetchColumn();
                    if ($n > 0) $addNotif('Sales Activity Mendekati Due Date', "Ada $n detail Sales Activity yang due date-nya dalam 7 hari.", 'salesactivity.php', 'sales_due');
                }
            }

            // Harus melengkapi Detail TR
            if ($notifTableExists($notifDb, 'transaction_requests')) {
                $c = $notifColumns($notifDb, 'transaction_requests');
                $idc = $notifPickCol($c, ['id','transaction_request_id']);
                $userc = $notifPickCol($c, ['user_id','sales_id','id_user','created_by']);
                $statusc = $notifPickCol($c, ['status','tr_status']);
                if ($idc) {
                    $where = '1=1'; $params = [];
                    if ($userc && $notifUserId > 0) { $where .= " AND `$userc` = ?"; $params[] = $notifUserId; }
                    if ($statusc) $where .= " AND LOWER(`$statusc`) IN ('draft','pending detail','need detail','incomplete')";
                    $st = $notifDb->prepare("SELECT COUNT(*) FROM `transaction_requests` WHERE $where");
                    $st->execute($params);
                    $n = (int)$st->fetchColumn();
                    if ($n > 0) $addNotif('Lengkapi Detail TR', "Ada $n Transaction Request yang masih harus dilengkapi Detail TR.", 'transactionrequest.php', 'tr_detail');
                }
            }

            // TR sudah approve semua
            if ($notifTableExists($notifDb, 'transaction_requests')) {
                $c = $notifColumns($notifDb, 'transaction_requests');
                $statusc = $notifPickCol($c, ['status','tr_status']);
                $userc = $notifPickCol($c, ['user_id','sales_id','id_user','created_by']);
                if ($statusc) {
                    $where = "LOWER(`$statusc`) IN ('approved','approve','fully approved','completed')"; $params = [];
                    if ($userc && $notifUserId > 0) { $where .= " AND `$userc` = ?"; $params[] = $notifUserId; }
                    $st = $notifDb->prepare("SELECT COUNT(*) FROM `transaction_requests` WHERE $where");
                    $st->execute($params);
                    $n = (int)$st->fetchColumn();
                    if ($n > 0) $addNotif('Transaction Request Approved', "$n Transaction Request milik Anda sudah di-approve semua.", 'transactionrequest.php', 'tr_approved');
                }
            }

            // DI sudah approve semua
            if ($notifTableExists($notifDb, 'delivery_instructions')) {
                $c = $notifColumns($notifDb, 'delivery_instructions');
                $statusc = $notifPickCol($c, ['status','di_status']);
                $userc = $notifPickCol($c, ['user_id','sales_id','id_user','created_by']);
                if ($statusc) {
                    $where = "LOWER(`$statusc`) IN ('approved','approve','fully approved','completed')"; $params = [];
                    if ($userc && $notifUserId > 0) { $where .= " AND `$userc` = ?"; $params[] = $notifUserId; }
                    $st = $notifDb->prepare("SELECT COUNT(*) FROM `delivery_instructions` WHERE $where");
                    $st->execute($params);
                    $n = (int)$st->fetchColumn();
                    if ($n > 0) $addNotif('Delivery Instruction Approved', "$n Delivery Instruction milik Anda sudah di-approve semua.", 'deliveryinstruction.php', 'di_approved');
                }
            }
        }

        /* --------------------------------------------------
           SALES MANAGER
           -------------------------------------------------- */
        if (in_array($notifRole, ['sales manager','sales_manager','salesmanager'], true)) {
            if ($notifTableExists($notifDb, 'transaction_requests')) {
                $c = $notifColumns($notifDb, 'transaction_requests');
                $idc = $notifPickCol($c, ['id','transaction_request_id']);
                $statusc = $notifPickCol($c, ['status','tr_status']);
                $createdc = $notifPickCol($c, ['created_at','created_date','tanggal_dibuat']);
                if ($idc) {
                    $where = '1=1'; $params = [];
                    if ($statusc) $where .= " AND LOWER(`$statusc`) NOT IN ('rejected','cancelled')";
                    if ($createdc) $where .= " AND `$createdc` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                    $st = $notifDb->prepare("SELECT COUNT(*) FROM `transaction_requests` WHERE $where");
                    $st->execute($params);
                    $n = (int)$st->fetchColumn();
                    if ($n > 0) $addNotif('Transaction Request Baru', "Ada $n Transaction Request Number baru yang masuk.", 'transactionrequest.php', 'tr_new');
                }
            }
            // Approval TR untuk role Sales Manager
            $addNotif('Approval Transaction Request', 'Periksa Transaction Request yang menunggu approval Anda.', 'transactionrequest.php?status=pending', 'tr_approval');
            // Sales Activity & Detail TR
            if ($notifTableExists($notifDb, 'sales_activities')) {
                $c = $notifColumns($notifDb, 'sales_activities');
                $duec = $notifPickCol($c, ['due_date','deadline','tanggal_due_date','end_date']);
                if ($duec) {
                    $st = $notifDb->query("SELECT COUNT(*) FROM `sales_activities` WHERE DATE(`$duec`) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
                    if ((int)$st->fetchColumn() > 0) $addNotif('Sales Activity Mendekati Due Date', 'Ada Sales Activity yang mendekati due date.', 'salesactivity.php', 'sm_due');
                }
            }
            $addNotif('Lengkapi Detail TR', 'Periksa Transaction Request yang masih membutuhkan Detail TR.', 'transactionrequest.php', 'sm_tr_detail');
        }

        /* --------------------------------------------------
           PART SUPPORT / SERVICE SUPPORT
           -------------------------------------------------- */
        if (in_array($notifRole, ['part support','part_support','partsupport','service support','service_support','servicesupport'], true)) {
            $label = str_contains($notifRole, 'part') ? 'Part Support' : 'Service Support';
            $addNotif('Approval Delivery Instruction', "$label memiliki Delivery Instruction yang menunggu approval.", 'deliveryinstruction.php?status=pending', 'di_approval');
        }

        /* --------------------------------------------------
           ADMIN
           -------------------------------------------------- */
        if ($notifRole === 'admin') {
            if ($notifTableExists($notifDb, 'delivery_instructions')) {
                $c = $notifColumns($notifDb, 'delivery_instructions');
                $createdc = $notifPickCol($c, ['created_at','created_date','tanggal_dibuat']);
                $statusc = $notifPickCol($c, ['status','di_status']);
                $where = '1=1';
                if ($createdc) $where .= " AND `$createdc` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                if ($statusc) $where .= " AND LOWER(`$statusc`) NOT IN ('rejected','cancelled')";
                $st = $notifDb->query("SELECT COUNT(*) FROM `delivery_instructions` WHERE $where");
                if ((int)$st->fetchColumn() > 0) $addNotif('Delivery Instruction Baru', 'Ada Delivery Instruction baru yang masuk.', 'deliveryinstruction.php', 'di_new');
            }
            $addNotif('Lengkapi Detail DI', 'Periksa Delivery Instruction yang masih harus dilengkapi Detail DI.', 'deliveryinstruction.php', 'di_detail');
            $addNotif('Approval Delivery Instruction', 'Periksa Delivery Instruction yang menunggu approval.', 'deliveryinstruction.php?status=pending', 'di_approval');
        }

        /* --------------------------------------------------
           BUSINESS
           -------------------------------------------------- */
        if ($notifRole === 'business') {
            if ($notifTableExists($notifDb, 'transaction_requests')) {
                $c = $notifColumns($notifDb, 'transaction_requests');
                $createdc = $notifPickCol($c, ['created_at','created_date','tanggal_dibuat']);
                $statusc = $notifPickCol($c, ['status','tr_status']);
                $where = '1=1';
                if ($createdc) $where .= " AND `$createdc` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                if ($statusc) $where .= " AND LOWER(`$statusc`) NOT IN ('rejected','cancelled')";
                $st = $notifDb->query("SELECT COUNT(*) FROM `transaction_requests` WHERE $where");
                if ((int)$st->fetchColumn() > 0) $addNotif('Transaction Request Baru', 'Ada Transaction Request Number baru yang masuk.', 'transactionrequest.php', 'business_tr_new');
            }
            $addNotif('Lengkapi Detail TR', 'Periksa Transaction Request yang masih harus dilengkapi Detail TR.', 'transactionrequest.php', 'business_tr_detail');
        }

        /* --------------------------------------------------
           DIREKTUR OPERASIONAL / SALES / UTAMA
           -------------------------------------------------- */
        if (in_array($notifRole, ['direktur operasional','direktur_operasional','direkturoperasional','direktur sales','direktur_sales','direktursales','direktur utama','direktur_utama','direkturutama'], true)) {
            $isDO = str_contains($notifRole, 'operasional');
            $isDS = str_contains($notifRole, 'sales');
            $isDU = str_contains($notifRole, 'utama');
            $roleTitle = $isDO ? 'Direktur Operasional' : ($isDS ? 'Direktur Sales' : 'Direktur Utama');

            if ($notifTableExists($notifDb, 'transaction_requests')) {
                $c = $notifColumns($notifDb, 'transaction_requests');
                $createdc = $notifPickCol($c, ['created_at','created_date','tanggal_dibuat']);
                $statusc = $notifPickCol($c, ['status','tr_status']);
                $where = '1=1';
                if ($createdc) $where .= " AND `$createdc` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                if ($statusc) $where .= " AND LOWER(`$statusc`) NOT IN ('rejected','cancelled')";
                $st = $notifDb->query("SELECT COUNT(*) FROM `transaction_requests` WHERE $where");
                if ((int)$st->fetchColumn() > 0) $addNotif('Transaction Request Number Baru', "Ada Transaction Request Number baru untuk diperiksa oleh $roleTitle.", 'transactionrequest.php', 'dir_tr_new');
            }

            if ($notifTableExists($notifDb, 'delivery_instructions')) {
                $c = $notifColumns($notifDb, 'delivery_instructions');
                $createdc = $notifPickCol($c, ['created_at','created_date','tanggal_dibuat']);
                $statusc = $notifPickCol($c, ['status','di_status']);
                $where = '1=1';
                if ($createdc) $where .= " AND `$createdc` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                if ($statusc) $where .= " AND LOWER(`$statusc`) NOT IN ('rejected','cancelled')";
                $st = $notifDb->query("SELECT COUNT(*) FROM `delivery_instructions` WHERE $where");
                if ((int)$st->fetchColumn() > 0) $addNotif('Delivery Instruction Baru', "Ada Delivery Instruction baru untuk diperiksa oleh $roleTitle.", 'deliveryinstruction.php', 'dir_di_new');
            }

            $addNotif('Approval Transaction Request', "Ada Transaction Request yang menunggu approval $roleTitle.", 'transactionrequest.php?status=pending', 'dir_tr_approval');
            $addNotif('Approval Delivery Instruction', "Ada Delivery Instruction yang menunggu approval $roleTitle.", 'deliveryinstruction.php?status=pending', 'dir_di_approval');
        }
    }
} catch (Throwable $e) {
    // Notification gagal tidak boleh membuat halaman CRM error.
}

/* Hapus duplikasi */
$unique = [];
foreach ($notifItems as $item) {
    $unique[$item['key']] = $item;
}
$notifItems = array_values($unique);
$notifUnread = count($notifItems);
?>
<link rel="stylesheet" href="css/navigation.css">
<link rel="stylesheet" href="css/notifications.css">

<header class="topbar">
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
                        <small><?= $notifUnread ?> pemberitahuan</small>
                    </div>
                    <button type="button" class="notification-close" aria-label="Tutup">&times;</button>
                </div>

                <div class="notification-list">
                    <?php if (!$notifItems): ?>
                        <div class="notification-empty">
                            <i class="far fa-bell-slash"></i>
                            <strong>Tidak ada notifikasi</strong>
                            <span>Belum ada aktivitas yang membutuhkan perhatian Anda.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notifItems as $item): ?>
                            <a class="notification-item" href="<?= htmlspecialchars($item['url']) ?>">
                                <span class="notification-icon"><i class="far fa-bell"></i></span>
                                <span class="notification-content">
                                    <strong><?= htmlspecialchars($item['title']) ?></strong>
                                    <span><?= htmlspecialchars($item['message']) ?></span>
                                </span>
                                <i class="fas fa-chevron-right notification-arrow"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
    </div>
</header>

<aside class="rail">
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
    <div class="rail-user"><div class="mini-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div><div><strong><?= htmlspecialchars($fullName) ?></strong><span><?= htmlspecialchars(getRoleLabel($role)) ?></span></div></div>
    <a class="<?= $currentPage === 'logout.php' ? 'active' : '' ?>" href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
</aside>

<script>
(function () {
    const wrap = document.querySelector('.notification-wrap');
    if (!wrap) return;

    const btn = wrap.querySelector('.notification-btn');
    const panel = wrap.querySelector('.notification-panel');
    const close = wrap.querySelector('.notification-close');

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

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.hidden) openNotif();
        else closeNotif();
    });

    if (close) close.addEventListener('click', closeNotif);

    document.addEventListener('click', function (e) {
        if (!wrap.contains(e.target) && !panel.hidden) closeNotif();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) closeNotif();
    });
})();
</script>
