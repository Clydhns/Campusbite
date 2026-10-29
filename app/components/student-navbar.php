<?php
$navigationPage = basename($_SERVER["SCRIPT_NAME"] ?? "");
$navigationCartCount = 0;
foreach (($_SESSION["cart"] ?? []) as $navigationItem) {
    $navigationCartCount += is_array($navigationItem)
        ? max(0, (int) ($navigationItem["quantity"] ?? 0))
        : max(0, (int) $navigationItem);
}
$navigationLinks = [
    "dashboard.php" => "Home",
    "menu.php" => "🍔 Menu",
    "cart.php" => "🛒 Cart",
    "orders.php" => "📦 My Orders",
    "profile.php" => "My Profile",
    "logout.php" => "Logout",
];
?>
<link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/../brand.css') ?>">
<nav class="navbar cb-site-navbar" aria-label="Main navigation">
    <a class="cb-site-brand cb-brand-logo" href="dashboard.php" aria-label="CampusBite home"><?php require __DIR__ . '/brand-mark.php'; ?></a>
    <div class="cb-site-nav-pill">
        <?php foreach ($navigationLinks as $navigationHref => $navigationLabel): ?>
            <a href="<?= $navigationHref ?>"<?= $navigationPage === $navigationHref ? ' class="active" aria-current="page"' : "" ?>>
                <?= $navigationLabel ?>
                <?php if ($navigationHref === "cart.php" && $navigationCartCount > 0): ?>
                    <span class="cb-site-cart-count" aria-label="<?= $navigationCartCount ?> items in cart"><?= $navigationCartCount ?></span>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
