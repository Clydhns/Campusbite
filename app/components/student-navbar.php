<?php
$navigationPage = basename($_SERVER["SCRIPT_NAME"] ?? "");
$navigationUser = (int) ($_SESSION['user_id'] ?? 0);
$navigationConfirmedCount = 0;
if ((int) ($_SESSION['role_id'] ?? 0) === 3 && isset($pdo) && $pdo instanceof PDO) {
    if ($navigationPage === 'orders.php' && isset($orders) && is_array($orders)) {
        foreach ($orders as $navigationOrder) {
            if (in_array((int) $navigationOrder['status_id'], [1,2,3], true)
                && $navigationOrder['payment_status'] === 'Paid' && empty($navigationOrder['payment_rejected_at'])) $navigationConfirmedCount++;
        }
    } else {
        require_once __DIR__ . '/../config/order-notifications.php';
        try { $navigationConfirmedCount = cbConfirmedActiveOrderCount($pdo, $navigationUser); }
        catch (PDOException $error) { error_log('CampusBite order notification count unavailable.'); }
    }
}
$navigationCartCount = 0;
foreach (($_SESSION["cart"] ?? []) as $navigationItem) {
    $navigationCartCount += is_array($navigationItem)
        ? max(0, (int) ($navigationItem["quantity"] ?? 0))
        : max(0, (int) $navigationItem);
}
$navigationLinks = [
    "dashboard.php" => "Home",
    "menu.php" => "Menu",
    "cart.php" => "Cart",
    "orders.php" => "My Orders",
    "profile.php" => "My Profile",
    "logout.php" => "Logout",
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<link rel="stylesheet" href="student-order-notifications.css?v=<?= (int) filemtime(__DIR__ . '/../student-order-notifications.css') ?>">
<script src="student-order-notifications.js?v=<?= (int) filemtime(__DIR__ . '/../student-order-notifications.js') ?>" defer></script>
<nav class="navbar cb-site-navbar" aria-label="Main navigation" data-order-notifications-user="<?= $navigationUser ?>">
    <a class="cb-site-brand cb-brand-logo" href="dashboard.php" aria-label="CampusBite home"><?php require __DIR__ . '/brand-mark.php'; ?></a>
    <div class="cb-site-nav-pill">
        <?php foreach ($navigationLinks as $navigationHref => $navigationLabel): ?>
            <a href="<?= $navigationHref ?>"<?= $navigationPage === $navigationHref ? ' class="active" aria-current="page"' : "" ?>>
                <?= $navigationLabel ?>
                <?php if ($navigationHref === "cart.php" && $navigationCartCount > 0): ?>
                    <span class="cb-site-cart-count" aria-label="<?= $navigationCartCount ?> items in cart"><?= $navigationCartCount ?></span>
                <?php endif; ?>
                <?php if ($navigationHref === "orders.php"): ?>
                    <span id="student-order-notifications-count" class="cb-site-payment-count" aria-label="<?= $navigationConfirmedCount ?> active orders with confirmed payment" title="Active orders with confirmed payment"<?= $navigationConfirmedCount === 0 ? ' hidden' : '' ?>><?= $navigationConfirmedCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
<span id="student-order-notifications-status" class="cb-payment-sr-status" role="status" aria-live="polite" aria-atomic="true"></span>
