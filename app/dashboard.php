<?php

if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";

/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| GET CURRENT USER FROM DATABASE
|--------------------------------------------------------------------------
*/
try {

    $userStmt = $pdo->prepare("
        SELECT
            u.user_id,
            u.role_id,
            u.id_number,
            u.first_name,
            u.last_name,
            u.email,
            u.contact_number,
            u.grade_section,
            u.is_active,
            r.role_name
        FROM users u
        INNER JOIN roles r
            ON u.role_id = r.role_id
        WHERE u.user_id = ?
        LIMIT 1
    ");

    $userStmt->execute([
        (int) $_SESSION["user_id"]
    ]);

    $user = $userStmt->fetch();

} catch (PDOException $e) {

    die("Unable to load user information: " . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| USER VALIDATION
|--------------------------------------------------------------------------
*/
if (!$user) {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

if ((int) $user["is_active"] !== 1) {
    session_unset();
    session_destroy();

    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| DASHBOARD IS FOR STUDENTS ONLY
|--------------------------------------------------------------------------
*/
if ((int) $user["role_id"] !== 3) {

    if ((int) $user["role_id"] === 1) {
        header("Location: admin_dashboard.php");
        exit;
    }

    if ((int) $user["role_id"] === 2) {
        header("Location: staff_dashboard.php");
        exit;
    }

    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| KEEP SESSION INFORMATION UPDATED
|--------------------------------------------------------------------------
*/
$_SESSION["user_id"] = (int) $user["user_id"];
$_SESSION["role_id"] = (int) $user["role_id"];
$_SESSION["first_name"] = $user["first_name"];
$_SESSION["last_name"] = $user["last_name"];
$_SESSION["email"] = $user["email"];
$_SESSION["role_name"] = $user["role_name"];

// Rendering below only reads session data; let navigation proceed during live-menu queries.
session_write_close();

/*
|--------------------------------------------------------------------------
| CART COUNT
|--------------------------------------------------------------------------
*/
$cartCount = 0;

if (isset($_SESSION["cart"]) && is_array($_SESSION["cart"])) {

    foreach ($_SESSION["cart"] as $cartItem) {

        $cartCount += isset($cartItem["quantity"])
            ? (int) $cartItem["quantity"]
            : 0;
    }
}

/*
|--------------------------------------------------------------------------
| DEFAULT DASHBOARD VALUES
|--------------------------------------------------------------------------
*/
$totalOrders = 0;
$activeOrders = 0;
$todayOrders = 0;

$stores = cbVisibleStores($pdo);
$menuItems = [];
$recentOrders = [];

/*
|--------------------------------------------------------------------------
| TOTAL ORDERS
|--------------------------------------------------------------------------
*/
try {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE student_id = ?
    ");

    $stmt->execute([
        $user["user_id"]
    ]);

    $totalOrders = (int) $stmt->fetchColumn();

} catch (PDOException $e) {

    $totalOrders = 0;
}

/*
|--------------------------------------------------------------------------
| ACTIVE ORDERS
|--------------------------------------------------------------------------
|
| 1 = Pending
| 2 = Preparing
| 3 = Ready for Pickup
|
*/
try {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE student_id = ?
          AND status_id IN (1, 2, 3)
    ");

    $stmt->execute([
        $user["user_id"]
    ]);

    $activeOrders = (int) $stmt->fetchColumn();

} catch (PDOException $e) {

    $activeOrders = 0;
}

/*
|--------------------------------------------------------------------------
| TODAY'S ORDERS
|--------------------------------------------------------------------------
*/
try {

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM orders
        WHERE student_id = ?
          AND DATE(order_datetime) = CURRENT_DATE
    ");

    $stmt->execute([
        $user["user_id"]
    ]);

    $todayOrders = (int) $stmt->fetchColumn();

} catch (PDOException $e) {

    $todayOrders = 0;
}

/*
|--------------------------------------------------------------------------
| GET AVAILABLE FOOD
|--------------------------------------------------------------------------
|
| If today's daily_menu exists:
|     use its stock and availability.
|
| If no daily_menu exists:
|     fall back to menu_items.is_available.
|
*/
try {

    $menuStmt = $pdo->prepare("
        SELECT
            mi.item_id, mi.store_id, mi.image_path, s.store_name,
            mi.item_name,
            mi.description,
            mi.price,
            mi.is_available,
            c.category_name,
            dm.daily_menu_id,
            dm.quantity_prepared,
            dm.quantity_remaining,
            dm.is_available AS daily_is_available

        FROM menu_items mi
        JOIN stores s ON s.store_id=mi.store_id
        JOIN users store_owner ON store_owner.user_id=s.staff_user_id

        INNER JOIN categories c
            ON mi.category_id = c.category_id

        LEFT JOIN daily_menu dm
            ON dm.item_id = mi.item_id
            AND dm.menu_date = CURRENT_DATE

        WHERE mi.archived_at IS NULL AND mi.is_available = 1 AND s.is_open=1 AND store_owner.is_active=1

          AND (
                dm.daily_menu_id IS NOT NULL
                AND (
                    dm.is_available = 1
                    AND dm.quantity_remaining > 0
                )
              )

        ORDER BY c.category_name ASC,
                 mi.item_name ASC


    ");

    $menuStmt->execute();

    $menuItems = $menuStmt->fetchAll();

} catch (PDOException $e) {

    $menuItems = [];
}

/*
|--------------------------------------------------------------------------
| RECENT ORDERS
|--------------------------------------------------------------------------
*/
try {

    $recentStmt = $pdo->prepare("
        SELECT
            o.order_id,
            o.order_number,
            o.order_datetime,
            o.total_amount,
            o.status_id,
            os.status_name,
            p.method AS payment_method,
            p.payment_status

        FROM orders o

        INNER JOIN order_statuses os
            ON o.status_id = os.status_id

        LEFT JOIN payments p
            ON o.order_id = p.order_id

        WHERE o.student_id = ?

        ORDER BY o.order_datetime DESC

        LIMIT 5
    ");

    $recentStmt->execute([
        $user["user_id"]
    ]);

    $recentOrders = $recentStmt->fetchAll();

} catch (PDOException $e) {

    $recentOrders = [];
}

/*
|--------------------------------------------------------------------------
| FOOD EMOJIS
|--------------------------------------------------------------------------
*/
function getFoodEmoji($category)
{
    $category = strtolower(trim($category));

    switch ($category) {

        case "rice meals":
            return "🍚";

        case "snacks":
            return "🍟";

        case "beverages":
            return "🥤";

        case "desserts":
            return "🍰";

        default:
            return "🍴";
    }
}

/*
|--------------------------------------------------------------------------
| STATUS CLASS
|--------------------------------------------------------------------------
*/
function getStatusClass($status)
{
    $status = strtolower(trim($status));

    if ($status === "pending") {
        return "status-pending";
    }

    if ($status === "preparing") {
        return "status-preparing";
    }

    if ($status === "ready for pickup") {
        return "status-ready";
    }

    if ($status === "completed") {
        return "status-completed";
    }

    if ($status === "cancelled") {
        return "status-cancelled";
    }

    return "status-default";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        CampusBite - Dashboard
    </title>


    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #fff7ed;
            color: #3b241a;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        .student-navbar {
            background: linear-gradient(
                135deg,
                #f97316,
                #fb923c
            );

            color: white;
            padding: 15px 25px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 20px;
            flex-wrap: wrap;

            box-shadow:
                0 4px 15px
                rgba(59, 36, 26, 0.12);
        }

        .student-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-brand-logo {
            width: 40px;
            height: 40px;
            object-fit: contain;
            background: white;
            border-radius: 10px;
            padding: 4px;
        }

        .student-brand h2 {
            margin: 0;
            font-size: 21px;
        }

        .student-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .student-nav a {
            color: white;
            text-decoration: none;
            padding: 9px 13px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 14px;
        }

        .student-nav a:hover {
            background: rgba(255,255,255,0.18);
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .welcome-section {
            background:
                linear-gradient(
                    135deg,
                    #fff4df,
                    #ffffff
                );

            border: 1px solid #fed7aa;
            border-radius: 22px;

            padding: 30px;

            margin-bottom: 25px;

            box-shadow:
                0 8px 25px
                rgba(59, 36, 26, 0.07);
        }

        .welcome-section h1 {
            margin: 0 0 8px;
            font-size: 32px;
        }

        .welcome-section p {
            margin: 0;
            color: #70574b;
            font-size: 16px;
        }

        .stats-grid {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 18px;
            padding: 22px;

            border: 1px solid #f2dfd0;

            box-shadow:
                0 7px 20px
                rgba(59, 36, 26, 0.06);
        }

        .stat-card-icon {
            font-size: 28px;
            margin-bottom: 10px;
        }

        .stat-card-number {
            font-size: 28px;
            font-weight: 800;
            color: #f97316;
        }

        .stat-card-title {
            margin-top: 4px;
            color: #745f55;
            font-size: 14px;
        }

        .quick-actions {
            display: grid;

            grid-template-columns:
                repeat(3, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 35px;
        }

        .quick-card {
            text-decoration: none;
            color: inherit;

            background: white;

            border-radius: 18px;

            padding: 22px;

            border: 1px solid #f2dfd0;

            transition:
                transform 0.2s,
                box-shadow 0.2s,
                border-color 0.2s;

            box-shadow:
                0 7px 20px
                rgba(59, 36, 26, 0.06);
        }

        .quick-card:hover {
            transform: translateY(-3px);

            border-color: #f97316;

            box-shadow:
                0 12px 25px
                rgba(249, 115, 22, 0.15);
        }

        .quick-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }

        .quick-card h3 {
            margin: 0 0 6px;
        }

        .quick-card p {
            margin: 0;
            color: #745f55;
            font-size: 14px;
        }

        .section-heading {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            margin-bottom: 16px;
        }

        .section-heading h2 {
            margin: 0;
        }

        .section-heading a {
            color: #ea580c;
            font-weight: 700;
            text-decoration: none;
        }

        .menu-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 35px;
        }

        .food-card {
            background: white;

            border-radius: 18px;

            padding: 20px;

            border: 1px solid #f2dfd0;

            box-shadow:
                0 7px 20px
                rgba(59, 36, 26, 0.06);

            display: flex;
            flex-direction: column;
        }

        .food-emoji {
            width: 58px;
            height: 58px;

            background: #fff7ed;

            border-radius: 16px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 30px;

            margin-bottom: 12px;
        }

        .food-category {
            display: inline-block;

            background: #fef3c7;

            color: #92400e;

            border-radius: 20px;

            padding: 5px 9px;

            font-size: 11px;

            font-weight: 700;

            width: fit-content;

            margin-bottom: 9px;
        }

        .food-card h3 {
            margin: 0 0 7px;

            font-size: 17px;

            line-height: 1.3;
        }

        .food-description {
            color: #745f55;

            font-size: 13px;

            line-height: 1.45;

            min-height: 38px;

            margin-bottom: 12px;
        }

        .food-bottom {
            margin-top: auto;
        }

        .food-price {
            color: #f97316;

            font-size: 21px;

            font-weight: 800;

            margin-bottom: 8px;
        }

        .food-available {
            color: #16a34a;

            font-size: 12px;

            font-weight: 700;

            margin-bottom: 12px;
        }

        .food-stock {
            color: #c2410c;

            font-size: 12px;

            font-weight: 700;

            margin-bottom: 12px;
        }

        .recent-card {
            background: white;

            border-radius: 18px;

            border: 1px solid #f2dfd0;

            box-shadow:
                0 7px 20px
                rgba(59, 36, 26, 0.06);

            overflow: hidden;
        }

        .recent-table-wrapper {
            overflow-x: auto;
        }

        .recent-table {
            width: 100%;

            border-collapse: collapse;

            min-width: 720px;
        }

        .recent-table th {
            text-align: left;

            padding: 15px 18px;

            background: #fff7ed;

            color: #6b4f42;

            font-size: 13px;
        }

        .recent-table td {
            padding: 15px 18px;

            border-top: 1px solid #f3e7df;

            font-size: 14px;
        }

        .recent-table td strong {
            color: #3b241a;
        }

        .order-total {
            font-weight: 800;
            color: #ea580c;
        }

        .payment-text {
            font-size: 12px;
            color: #745f55;
        }

        .status-badge {
            display: inline-block;

            padding: 6px 10px;

            border-radius: 20px;

            font-size: 12px;

            font-weight: 800;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-preparing {
            background: #ffedd5;
            color: #c2410c;
        }

        .status-ready {
            background: #dcfce7;
            color: #166534;
        }

        .status-completed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background: #f3f4f6;
            color: #374151;
        }

        .empty-box {
            background: white;

            border-radius: 18px;

            padding: 30px;

            text-align: center;

            border: 1px solid #f2dfd0;

            color: #745f55;
        }

        .btn-campus {
            display: inline-block;

            border: none;

            text-decoration: none;

            cursor: pointer;

            padding: 11px 16px;

            border-radius: 11px;

            font-weight: 800;

            text-align: center;
        }

        .btn-orange {
            background: #f97316;
            color: white;
        }

        .btn-orange:hover {
            background: #ea580c;
        }

        .btn-outline {
            background: white;
            color: #ea580c;
            border: 1px solid #fdba74;
        }

        .btn-outline:hover {
            background: #fff7ed;
        }

        .mobile-only-cart {
            display: none;
        }

        @media (max-width: 1000px) {

            .menu-grid {
                grid-template-columns:
                    repeat(3, minmax(0, 1fr));
            }

        }

        @media (max-width: 780px) {

            .stats-grid {
                grid-template-columns:
                    1fr;
            }

            .quick-actions {
                grid-template-columns:
                    1fr;
            }

            .menu-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .student-navbar {
                padding: 14px 16px;
            }

            .student-nav {
                width: 100%;
            }

            .student-nav a {
                flex: 1;
                text-align: center;
                background: rgba(255,255,255,0.10);
            }

            .welcome-section h1 {
                font-size: 26px;
            }

        }

        @media (max-width: 520px) {

            .dashboard-container {
                padding: 20px 14px 45px;
            }

            .menu-grid {
                grid-template-columns:
                    1fr;
            }

            .welcome-section {
                padding: 22px;
            }

            .student-brand {
                width: 100%;
            }

            .student-nav a {
                font-size: 12px;
                padding: 8px 5px;
            }

        }

    </style>

    <link rel="stylesheet" href="assets/order-tracking/tracking.css?v=1">
    <script src="assets/order-tracking/tracking.js?v=1" defer></script>
    <link rel="stylesheet" href="dashboard-commerce.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="student-pages.css?v=1">
<link rel="stylesheet" href="stores.css?v=<?= (int) filemtime(__DIR__ . '/stores.css') ?>">
    <link rel="stylesheet" href="dashboard-food-preview.css?v=<?= (int) filemtime(__DIR__ . '/dashboard-food-preview.css') ?>">
    <link rel="stylesheet" href="store-switch.css?v=<?= (int) filemtime(__DIR__ . '/store-switch.css') ?>">
    <script src="dashboard-store-tabs.js?v=<?= (int) filemtime(__DIR__ . '/dashboard-store-tabs.js') ?>" defer></script>
    <script src="dashboard-sync.js?v=<?= (int) filemtime(__DIR__ . '/dashboard-sync.js') ?>" defer></script>
</head>

<body class="cb-commerce cb-student" data-dashboard-user="<?= (int) $user['user_id'] ?>">

    <!-- ============================================================
         NAVIGATION
    ============================================================= -->

    <?php require __DIR__ . "/components/student-navbar.php"; ?>


    <!-- ============================================================
         DASHBOARD CONTENT
    ============================================================= -->

    <main class="dashboard-container">

    <?php
    require_once __DIR__ . '/config/meal-highlights.php';
    $storeMeals = cbStoreMealHighlights($menuItems);
    $heroFoods = [];
    $storePhotos = [];
    foreach ($stores as $highlightStore) {
        $meal = $storeMeals[$highlightStore['store_id']] ?? null;
        if ($meal) {
            $heroFoods[] = $meal;
            $photo = cbFoodPhotoUrl($meal['image_path'] ?? null);
            if ($photo) $storePhotos[$highlightStore['store_id']] = $photo;
        } else {
            $heroFoods[] = ['store_id'=>$highlightStore['store_id'], 'store_name'=>$highlightStore['store_name'], 'item_name'=>null, 'image_path'=>null];
        }
    }
    ?>
    <div data-dashboard-live="hero">
    <section class="cb-hero cb-food-hero" aria-labelledby="cb-hero-title">
        <div class="cb-hero-copy">
        <span class="cb-hero-kicker">YOUR CAMPUS. YOUR BREAK.</span>
        <h1 id="cb-hero-title"><span>Your favorites,</span>without the waiting.</h1>
        <p>Find your next meal, track your order, and make the most of every lunch break.</p>
        <a class="cb-hero-explore" href="#campus-kitchens">Explore the kitchens <span aria-hidden="true">&rarr;</span></a>
        </div>
        <div class="cb-hero-showcase" data-photo-count="<?= count($heroFoods) ?>">
        <?php foreach ($heroFoods as $food): ?>
            <a class="cb-hero-food" href="menu.php?store=<?= (int) $food['store_id'] ?>" aria-label="Browse <?= cbEscape($food['store_name']) ?><?= $food['item_name'] ? ': ' . cbEscape($food['item_name']) : '' ?>">
                <?php if ($photo = cbFoodPhotoUrl($food['image_path'])): ?>
                <img src="<?= cbEscape($photo) ?>" alt="<?= cbEscape($food['item_name']) ?>" width="640" height="420" decoding="async">
                <?php else: ?><div class="cb-meal-placeholder"><span>YOUR NEXT LUNCH</span><strong><?= cbEscape($food['item_name'] ?: 'Meals coming soon') ?></strong></div><?php endif; ?>
                <div><span class="cb-highlight-store"><?= cbEscape($food['store_name']) ?></span><strong><?= cbEscape($food['item_name'] ?: 'Explore this kitchen') ?></strong><?php if (isset($food['price'])): ?><span>PHP <?= number_format((float) $food['price'], 2) ?></span><?php endif; ?><span class="cb-highlight-link">View store &rarr;</span></div>
            </a>
        <?php endforeach; ?>
        <?php if (!$heroFoods): ?>
            <div class="cb-hero-no-photo"><span class="cb-hero-stamp" aria-hidden="true">CB</span><span>MADE ON CAMPUS</span><strong>Good food.<br>Close to class.</strong><p>Explore your campus kitchens for today's menu.</p></div>
        <?php endif; ?>
        </div>
    </section>
    </div>

        <!-- Welcome -->

        <section class="welcome-section">

            <h1>
                Welcome,
                <?= htmlspecialchars($user["first_name"]) ?>! 👋
            </h1>

            <p>
                Ready to grab something delicious?
                Browse the menu and order your food before break time.
            </p>

        </section>


        <details id="dashboard-sync-panel" class="cb-sync-panel" data-sync-state="connecting">
            <summary><span class="cb-sync-dot" aria-hidden="true"></span><span id="dashboard-sync-label">Connecting</span><span class="cb-sync-info">Details</span></summary>
        <div class="cb-sync-detail">
            <p id="dashboard-sync-status" role="status" aria-live="polite" style="margin:0;">Connecting to live store updates...</p>
            <span id="dashboard-sync-time" aria-live="off"></span>
            <button id="dashboard-sync-refresh" type="button" class="btn-campus">Check updates</button>
            <a id="dashboard-sync-login" href="login.php" hidden>Sign in again</a>
        </div>
        </details>
        <div class="cb-dashboard-store-controls"><button id="dashboard-all-stores" type="button" class="btn-campus" aria-pressed="true">All kitchens</button><span id="dashboard-store-status" role="status" aria-live="polite">Showing today's picks from all kitchens.</span></div>
        <div data-dashboard-live="stores">
            <section id="campus-kitchens" class="cb-store-section" aria-labelledby="stores-title">
                <h2 id="stores-title">Your campus kitchens</h2>
                <p>Choose a store. One kitchen, one checkout, one pickup.</p>
                <div class="cb-store-grid cb-kitchen-grid">
                <?php foreach ($stores as $store): ?>
                    <a class="cb-store-tile cb-kitchen-tile" href="menu.php?store=<?= (int) $store['store_id'] ?>">
                        <?php if (isset($storePhotos[$store['store_id']])): ?><img class="cb-kitchen-photo" src="<?= cbEscape($storePhotos[$store['store_id']]) ?>" alt="" loading="lazy" width="160" height="160"><?php endif; ?>
                        <div><span class="cb-store-kicker">CAMPUS STORE</span><h3><?= cbEscape($store['store_name']) ?></h3><p><?= cbEscape($store['description'] ?: 'Fresh picks from your campus kitchen.') ?></p><span class="cb-kitchen-link">Browse menu &rarr;</span></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$stores): ?><p>No stores are open right now.</p><?php endif; ?>
                </div>
            </section>
        </div>
        <!-- ========================================================
             MENU
        ========================================================= -->
        <div data-dashboard-live="menu">

        <div class="section-heading">

            <h2>
                🍴 Today's Picks
            </h2>

            <a href="menu.php">
                View Full Menu →
            </a>

        </div>


        <?php if (!empty($menuItems)): ?>

            <section class="menu-grid">

                <?php foreach ($menuItems as $item): ?>

                    <a class="food-card cb-food-display" data-store="<?= (int) $item['store_id'] ?>" href="menu.php?store=<?= (int) $item['store_id'] ?>" aria-label="<?= cbEscape('View ' . $item['item_name'] . ' at ' . $item['store_name']) ?>">

                        <?php if ($photo = cbFoodPhotoUrl($item['image_path'])): ?>
                        <img class="cb-food-photo" src="<?= cbEscape($photo) ?>" alt="<?= cbEscape($item['item_name']) ?>" loading="lazy" width="640" height="400">
                        <?php else: ?>
                        <div class="food-emoji">

                            <?= getFoodEmoji(
                                $item["category_name"]
                            ) ?>

                        </div>

                        <?php endif; ?>
                        <span class="cb-food-store"><?= cbEscape($item["store_name"]) ?></span>
                        <span class="food-category">

                            <?= htmlspecialchars(
                                $item["category_name"]
                            ) ?>

                        </span>

                        <h3>

                            <?= htmlspecialchars(
                                $item["item_name"]
                            ) ?>

                        </h3>

                        <p class="food-description">

                            <?= htmlspecialchars(
                                $item["description"]
                                ?: "Delicious food from CampusBite."
                            ) ?>

                        </p>

                        <div class="food-bottom">

                            <div class="food-price">

                                ₱<?= number_format(
                                    (float) $item["price"],
                                    2
                                ) ?>

                            </div>

                            <?php if (
                                $item["daily_menu_id"] !== null
                            ): ?>

                                <div class="food-stock">

                                    🟠
                                    <?= (int) $item[
                                        "quantity_remaining"
                                    ] ?>
                                    available today

                                </div>

                            <?php else: ?>

                                <div class="food-available">

                                    ● Available

                                </div>

                            <?php endif; ?>

                            <span class="cb-food-display-link">View kitchen &rarr;</span>

                        </div>

                    </a>

                <?php endforeach; ?>

            </section>

        <?php else: ?>

            <div class="empty-box">

                <h3>
                    🍽️ No food available right now
                </h3>

                <p>
                    Please check the menu again later.
                </p>

                <a
                    href="menu.php"
                    class="btn-campus btn-orange"
                >
                    Open Menu
                </a>

            </div>

        <?php endif; ?>


        </div>
        <?php
        require_once __DIR__ . "/components/order-tracking.php";
        campusBiteTracking($pdo, $recentOrders);
        ?>

        <!-- ========================================================
             RECENT ORDERS
        ========================================================= -->

        <div class="section-heading">

            <h2>
                📋 Recent Orders
            </h2>

            <a href="orders.php">
                View All →
            </a>

        </div>


        <?php if (!empty($recentOrders)): ?>

            <section class="recent-card">

                <div class="recent-table-wrapper">

                    <table class="recent-table">

                        <thead>

                            <tr>

                                <th>
                                    Order
                                </th>

                                <th>
                                    Date
                                </th>

                                <th>
                                    Total
                                </th>

                                <th>
                                    Payment
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach (
                                $recentOrders
                                as $order
                            ): ?>

                                <tr>

                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                $order[
                                                    "order_number"
                                                ]
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        <?= date(
                                            "M d, Y h:i A",
                                            strtotime(
                                                $order[
                                                    "order_datetime"
                                                ]
                                            )
                                        ) ?>

                                    </td>


                                    <td class="order-total">

                                        ₱<?= number_format(
                                            (float) $order[
                                                "total_amount"
                                            ],
                                            2
                                        ) ?>

                                    </td>


                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                $order[
                                                    "payment_method"
                                                ] ?? "—"
                                            ) ?>

                                        </strong>

                                        <div class="payment-text">

                                            <?= htmlspecialchars(
                                                $order[
                                                    "payment_status"
                                                ] ?? "—"
                                            ) ?>

                                        </div>

                                    </td>


                                    <td>

                                        <span
                                            class="
                                                status-badge
                                                <?= getStatusClass(
                                                    $order[
                                                        "status_name"
                                                    ]
                                                ) ?>
                                            "
                                        >

                                            <?= htmlspecialchars(
                                                $order[
                                                    "status_name"
                                                ]
                                            ) ?>

                                        </span>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            </section>

        <?php else: ?>

            <div class="empty-box">

                <h3>
                    📋 No orders yet
                </h3>

                <p>
                    Your recent orders will appear here.
                </p>

                <a
                    href="menu.php"
                    class="btn-campus btn-orange"
                >
                    Explore menus
                </a>

            </div>

        <?php endif; ?>

    </main>

</body>

</html>
