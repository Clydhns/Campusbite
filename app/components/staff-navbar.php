<?php
$staffNavigationPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$staffNavigationName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$staffNavigationCaption = $store['store_name'] ?? $staffNavigationName;
$staffNavigationSettings = $staffNavigationPage === 'staff_menu.php' && !empty($settingsView);
$staffNavigationLinks = [
    ['staff_dashboard.php', 'Dashboard', $staffNavigationPage === 'staff_dashboard.php'],
    ['staff_menu.php', 'Menu', $staffNavigationPage === 'staff_menu.php' && !$staffNavigationSettings],
    ['staff_menu.php?view=settings', 'Store Profile', $staffNavigationSettings],
    ['staff_profile.php', 'Account details', $staffNavigationPage === 'staff_profile.php'],
    ['logout.php', 'Logout', false],
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<nav class="navbar cb-site-navbar cb-staff-navbar" aria-label="Store navigation">
    <div class="cb-staff-brand-group">
        <a class="cb-site-brand cb-staff-brand cb-brand-logo" href="staff_dashboard.php" aria-label="CampusBite store dashboard">
            <?php require __DIR__ . '/brand-mark.php'; ?>
        </a>
        <div class="cb-staff-identity">
            <span class="cb-staff-mode">Store workspace</span>
            <span class="cb-staff-caption"><?= htmlspecialchars($staffNavigationCaption, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <div class="cb-site-nav-pill">
        <?php foreach ($staffNavigationLinks as [$staffNavigationHref, $staffNavigationLabel, $staffNavigationActive]): ?>
            <a href="<?= $staffNavigationHref ?>"<?= $staffNavigationActive ? ' class="active" aria-current="page"' : '' ?>><?= $staffNavigationLabel ?></a>
        <?php endforeach; ?>
    </div>
</nav>
