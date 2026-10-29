<?php
$staffNavigationPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$staffNavigationName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
$staffNavigationCaption = $store['store_name'] ?? $staffNavigationName;
$staffNavigationLinks = [
    'staff_dashboard.php' => 'Dashboard',
    'staff_menu.php' => 'My Store',
    'staff_profile.php' => 'My Profile',
    'logout.php' => 'Logout',
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<nav class="navbar cb-site-navbar cb-staff-navbar" aria-label="Staff navigation">
    <div class="cb-staff-brand-group">
        <a class="cb-site-brand cb-staff-brand cb-brand-logo" href="staff_dashboard.php" aria-label="CampusBite staff dashboard">
            <?php require __DIR__ . '/brand-mark.php'; ?>
        </a>
        <div class="cb-staff-identity">
            <span class="cb-staff-mode">Staff workspace</span>
            <span class="cb-staff-caption"><?= htmlspecialchars($staffNavigationCaption, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </div>
    <div class="cb-site-nav-pill">
        <?php foreach ($staffNavigationLinks as $staffNavigationHref => $staffNavigationLabel): ?>
            <a href="<?= $staffNavigationHref ?>"<?= $staffNavigationPage === $staffNavigationHref ? ' class="active" aria-current="page"' : '' ?>><?= $staffNavigationLabel ?></a>
        <?php endforeach; ?>
    </div>
</nav>
