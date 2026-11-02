<?php
$navigationPage = basename($_SERVER["SCRIPT_NAME"] ?? "");
$navigationUser = (int) ($_SESSION['user_id'] ?? 0);
$navigationConfirmedCount = 0;
$navigationActiveCount = 0;
if ((int) ($_SESSION['role_id'] ?? 0) === 3 && isset($pdo) && $pdo instanceof PDO) {
    if ($navigationPage === 'orders.php' && isset($orders) && is_array($orders)) {
        foreach ($orders as $navigationOrder) {
            if (in_array((int) $navigationOrder['status_id'], [1,2,3], true)) {
                $navigationActiveCount++;
                if ($navigationOrder['payment_status'] === 'Paid' && empty($navigationOrder['payment_rejected_at'])) $navigationConfirmedCount++;
            }
        }
    } else {
        require_once __DIR__ . '/../config/order-notifications.php';
        try {
            $navigationCounts = cbStudentOrderNotificationCounts($pdo, $navigationUser);
            $navigationActiveCount = $navigationCounts['active_count'];
            $navigationConfirmedCount = $navigationCounts['confirmed_count'];
        }
        catch (PDOException $error) { error_log('CampusBite order notification count unavailable.'); }
    }
}
$navigationCartCount = 0;
foreach (($_SESSION["cart"] ?? []) as $navigationItem) {
    $navigationCartCount += is_array($navigationItem)
        ? max(0, (int) ($navigationItem["quantity"] ?? 0))
        : max(0, (int) $navigationItem);
}
// Label, desktop-only prefix, and the icon shown in the mobile tab bar.
$navigationLinks = [
    "dashboard.php" => ["Home", "", '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>'],
    "menu.php" => ["Menu", "", '<path d="M4 3v8a3 3 0 0 0 6 0V3"/><path d="M7 3v18"/><path d="M17 3c-2 2-3 4.5-3 7.5V13h3v8"/>'],
    "cart.php" => ["Cart", "", '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.5h11.6L21 7H6.3"/>'],
    "orders.php" => ["Orders", "My ", '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>'],
    "profile.php" => ["Profile", "My ", '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/>'],
    "logout.php" => ["Logout", "", ""],
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<link rel="stylesheet" href="student-order-notifications.css?v=<?= (int) filemtime(__DIR__ . '/../student-order-notifications.css') ?>">
<link rel="stylesheet" href="student-mobile-nav.css?v=<?= (int) filemtime(__DIR__ . '/../student-mobile-nav.css') ?>">
<script src="student-order-notifications.js?v=<?= (int) filemtime(__DIR__ . '/../student-order-notifications.js') ?>" defer></script>
<nav class="navbar cb-site-navbar cb-student-navbar" aria-label="Main navigation" data-order-notifications-user="<?= $navigationUser ?>" data-confirmed-payments="<?= $navigationConfirmedCount ?>">
    <a class="cb-site-brand cb-brand-logo" href="dashboard.php" aria-label="CampusBite home"><?php require __DIR__ . '/brand-mark.php'; ?></a>
    <div class="cb-site-nav-pill">
        <?php foreach ($navigationLinks as $navigationHref => [$navigationLabel, $navigationPrefix, $navigationIcon]): ?>
            <a href="<?= $navigationHref ?>"<?= $navigationPage === $navigationHref ? ' class="active" aria-current="page"' : "" ?>>
                <?php if ($navigationIcon !== ""): ?><svg class="cb-site-nav-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><?= $navigationIcon ?></svg><?php endif; ?>
                <span class="cb-site-nav-label"><?php if ($navigationPrefix !== ""): ?><span class="cb-site-nav-prefix"><?= $navigationPrefix ?></span><?php endif; ?><?= $navigationLabel ?></span>
                <?php if ($navigationHref === "cart.php" && $navigationCartCount > 0): ?>
                    <span class="cb-site-cart-count" aria-label="<?= $navigationCartCount ?> items in cart"><?= $navigationCartCount ?></span>
                <?php endif; ?>
                <?php if ($navigationHref === "orders.php"): ?>
                    <span id="student-order-notifications-count" class="cb-site-payment-count" aria-label="<?= $navigationActiveCount ?> active orders" title="Active orders - track your order and payment status"<?= $navigationActiveCount === 0 ? ' hidden' : '' ?>><?= $navigationActiveCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<span id="student-order-notifications-status" class="cb-payment-sr-status" role="status" aria-live="polite" aria-atomic="true"></span>
