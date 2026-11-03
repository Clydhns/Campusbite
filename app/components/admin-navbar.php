<?php
$adminNavigationPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminNavigationName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$adminNavigationLinks = [
    'admin_dashboard.php' => 'Dashboard',
    'admin_users.php' => 'Users',
    'admin_stores.php' => 'Stores',
    'admin_categories.php' => 'Categories',
    'admin_profile.php' => 'My Profile',
    'logout.php' => 'Logout',
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<nav class="navbar cb-site-navbar cb-admin-navbar" aria-label="Admin navigation">
    <div class="cb-admin-brand-group">
        <a class="cb-site-brand cb-admin-brand cb-brand-logo" href="admin_dashboard.php" aria-label="CampusBite admin dashboard">
            <?php require __DIR__ . '/brand-mark.php'; ?>
        </a>
        <div class="cb-admin-identity">
            <span class="cb-admin-mode">Admin workspace</span>
            <span class="cb-admin-caption"><?= htmlspecialchars($adminNavigationName ?: 'Administrator', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <div class="cb-site-nav-pill">
        <?php foreach ($adminNavigationLinks as $adminNavigationHref => $adminNavigationLabel): ?>
            <a href="<?= $adminNavigationHref ?>"<?= $adminNavigationPage === $adminNavigationHref ? ' class="active" aria-current="page"' : '' ?>><?= $adminNavigationLabel ?></a>
        <?php endforeach; ?>
    </div>
</nav>
