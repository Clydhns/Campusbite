<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start(); require_once __DIR__ . '/config/connection.php'; ob_end_clean();
require_once __DIR__ . '/config/stores.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Administrator') {
    header('Location: login.php'); exit;
}
$check = $pdo->prepare('SELECT user_id FROM users WHERE user_id=? AND role_id=1 AND is_active=1');
$check->execute([(int) $_SESSION['user_id']]);
if (!$check->fetch()) { header('Location: login.php'); exit; }
$stores = $pdo->query('SELECT s.*,u.first_name,u.last_name,u.is_active AS staff_active,(SELECT COUNT(*) FROM menu_items m WHERE m.store_id=s.store_id AND m.archived_at IS NULL) AS food_count FROM stores s JOIN users u ON u.user_id=s.staff_user_id ORDER BY s.store_name')->fetchAll();
$selected = max(0,(int) ($_GET['store'] ?? 0));
$stmt = $pdo->prepare('SELECT m.*,s.store_name,c.category_name,' . cbFoodAvailabilitySql('m') . ' AS available_today FROM menu_items m JOIN stores s ON s.store_id=m.store_id JOIN categories c ON c.category_id=m.category_id WHERE m.archived_at IS NULL AND (?=0 OR m.store_id=?) ORDER BY s.store_name,m.item_name');
$stmt->execute([$selected,$selected]);
$foods = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT o.order_number,o.total_amount,o.order_datetime,s.store_name,u.first_name,u.last_name,os.status_name FROM orders o JOIN stores s ON s.store_id=o.store_id JOIN users u ON u.user_id=o.student_id JOIN order_statuses os ON os.status_id=o.status_id WHERE (?=0 OR o.store_id=?) ORDER BY o.order_datetime DESC LIMIT 100');
$stmt->execute([$selected,$selected]);
$orders = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stores - CampusBite Admin</title>
<?php require __DIR__ . '/components/favicon.php'; ?>
<link rel="stylesheet" href="student-pages.css?v=1"><link rel="stylesheet" href="student-navbar.css?v=1"><link rel="stylesheet" href="stores.css?v=<?= (int) filemtime(__DIR__ . '/stores.css') ?>">
<link rel="stylesheet" href="admin-navbar.css?v=<?= (int) filemtime(__DIR__ . '/admin-navbar.css') ?>">
<link rel="stylesheet" href="admin-store-switch.css?v=<?= (int) filemtime(__DIR__ . '/admin-store-switch.css') ?>">
<script src="admin-store-switch.js?v=<?= (int) filemtime(__DIR__ . '/admin-store-switch.js') ?>" defer></script>
</head><body class="cb-student" data-admin-stores-user="<?= (int) $_SESSION['user_id'] ?>">
<?php require __DIR__ . '/components/admin-navbar.php'; ?>
<main class="container"><header class="page-title"><span class="cb-page-eyebrow">EVERY KITCHEN. ONE CAMPUS.</span><h1>Your campus stores.</h1><p>Review all stores, food menus, and recent orders. Staff manage their own menus.</p></header>
<section class="cb-store-section"><div class="cb-store-grid">
<?php foreach ($stores as $store): ?><a class="cb-store-tile" data-admin-store="<?= (int) $store['store_id'] ?>" href="admin_stores.php?store=<?= (int) $store['store_id'] ?>"><h3><?= cbEscape($store['store_name']) ?></h3><p><?= cbEscape($store['first_name'] . ' ' . $store['last_name']) ?></p><span><?= $store['staff_active'] && cbStoreAcceptingOrders($store) ? 'Open now' : 'Closed / inactive' ?> &middot; <?= (int) $store['food_count'] ?> foods</span><p class="cb-store-hours"><?= cbEscape(cbStoreHoursLabel($store)) ?></p></a><?php endforeach; ?>
</div></section>
<form method="GET" class="cb-store-filter"><label for="store-filter">Review store</label><select name="store" id="store-filter"><option value="0">All stores</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['store_id'] ?>" <?= $selected === (int) $store['store_id'] ? 'selected' : '' ?>><?= cbEscape($store['store_name']) ?></option><?php endforeach; ?></select><button class="btn">View</button></form>
<p class="cb-admin-store-status" id="admin-store-status" role="status" aria-live="polite" aria-atomic="true"><?= $selected ? 'Showing the selected store.' : 'Showing all stores.' ?></p>
<div id="admin-store-results" data-store="<?= $selected ?>">
<section class="cb-store-section"><h2>Food menus</h2><div class="cb-store-grid"><?php foreach ($foods as $food): ?><article class="cb-store-panel">
<?php if ($photo = cbFoodPhotoUrl($food['image_path'])): ?><img class="cb-food-photo" src="<?= cbEscape($photo) ?>" alt="<?= cbEscape($food['item_name']) ?>" loading="lazy" width="640" height="400"><?php endif; ?>
<span class="cb-store-kicker"><?= cbEscape($food['store_name']) ?></span><h3><?= cbEscape($food['item_name']) ?></h3><p><?= cbEscape($food['category_name']) ?> &middot; <?= $food['available_today'] ? 'Available' : 'Unavailable' ?></p><strong>PHP <?= number_format((float) $food['price'],2) ?></strong></article><?php endforeach; ?><?php if (!$foods): ?><p>No food listed for this store.</p><?php endif; ?></div></section>
<section class="cb-store-section"><h2>Recent orders</h2><div class="cb-store-grid"><?php foreach ($orders as $order): ?><article class="cb-store-panel"><span class="cb-store-kicker"><?= cbEscape($order['store_name']) ?></span><h3><?= cbEscape($order['order_number']) ?></h3><p><?= cbEscape($order['first_name'] . ' ' . $order['last_name']) ?></p><p><?= cbEscape($order['status_name']) ?> &middot; <?= cbEscape($order['order_datetime']) ?></p><strong>PHP <?= number_format((float) $order['total_amount'],2) ?></strong></article><?php endforeach; ?><?php if (!$orders): ?><p>No orders for this store.</p><?php endif; ?></div></section></div></main></body></html>
