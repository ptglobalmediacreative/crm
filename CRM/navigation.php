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
?>
<header class="topbar">
    <a class="brand" href="dashboard.php">
        <img src="images/logo.webp" alt="GET">
        <div><strong>PT Ganda Elang Tangguh</strong><small>Customer Relationship Management</small></div>
    </a>
    <div class="top-actions">
        <button class="icon-btn" type="button" aria-label="Notifications"><i class="far fa-bell"></i><span class="notif">!</span></button>
        <div class="avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div>
    </div>
</header>

<aside class="rail">
    <div class="rail-label">Main Menu</div>
    <a class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php"><i class="fas fa-th-large"></i><span>Dashboard</span></a>
    <?php if ($showMenu('sales_activity')): ?><a class="<?= ($currentPage === 'salesactivity.php') ? 'active' : '' ?>" href="salesactivity.php"><i class="fas fa-chart-line"></i><span>Sales Activity</span></a><?php endif; ?>
    <?php if ($showMenu('account_management')): ?><a class="<?= $currentPage === 'account_management.php' ? 'active' : '' ?>" href="account_management.php"><i class="fas fa-building"></i><span>Account Management</span></a><?php endif; ?>
    <?php if ($showMenu('transaction_request')): ?><a class="<?= $currentPage === 'transactionrequest.php' ? 'active' : '' ?>" href="transactionrequest.php"><i class="fas fa-file-signature"></i><span>Transaction Request</span></a><?php endif; ?>
    <?php if ($showMenu('produk')): ?><a class="<?= $currentPage === 'produk.php' ? 'active' : '' ?>" href="produk.php"><i class="fas fa-box"></i><span>Produk</span></a><?php endif; ?>
    <?php if ($showMenu('delivery_order')): ?><a class="<?= $currentPage === 'deliveryinstruction.php' ? 'active' : '' ?>" href="deliveryinstruction.php"><i class="fas fa-truck-moving"></i><span>Delivery Order</span></a><?php endif; ?>
    <div class="rail-label">Administration</div>
    <?php if ($showMenu('data_user')): ?><a class="<?= $currentPage === 'data_user.php' ? 'active' : '' ?>" href="data_user.php"><i class="fas fa-users"></i><span>Data User</span></a><?php endif; ?>
    <?php if ($showMenu('data_sales') && file_exists('data_sales.php')): ?><a class="<?= $currentPage === 'data_sales.php' ? 'active' : '' ?>" href="data_sales.php"><i class="fas fa-user-tie"></i><span>Data Sales</span></a><?php endif; ?>
    <div class="spacer"></div>
    <div class="rail-user"><div class="mini-avatar"><?= strtoupper(substr($fullName, 0, 1)) ?></div><div><strong><?= htmlspecialchars($fullName) ?></strong><span><?= htmlspecialchars(getRoleLabel($role)) ?></span></div></div>
    <a class="<?= $currentPage === 'logout.php' ? 'active' : '' ?>" href="logout.php"><i class="fas fa-power-off"></i><span>Logout</span></a>
</aside>
