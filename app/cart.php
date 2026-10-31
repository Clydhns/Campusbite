<?php

if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";
require_once __DIR__ . "/config/cart-groups.php";

/*
|--------------------------------------------------------------------------
| STUDENT / USER ACCESS
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role_id"]) ||
    (int) $_SESSION["role_id"] !== 3
) {
    header("Location: login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| CART
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

$message = "";
$messageType = "";
if (($_GET['cancelled'] ?? '') === '1') {
    $message = 'Checkout cancelled for that store. Your other carts are unchanged.';
    $messageType = 'success';
}
$cartCsrf = cbCsrf("cart_csrf");
if ($_SERVER["REQUEST_METHOD"] === "POST" && !cbCheckCsrf("cart_csrf")) {
    $message = "Invalid request. Refresh and try again.";
    $messageType = "error";
    $_POST = [];
}
if (($_POST["action"] ?? "") === "clear_cart") {
    $_SESSION["cart"] = [];
    $message = "All store carts cleared.";
    $messageType = "success";
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_store') {
    try {
        $storeToClear = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $currentGroups = cbCartGroups($pdo, $_SESSION['cart']);
        if ($storeToClear === false || !isset($currentGroups[$storeToClear])) {
            throw new RuntimeException('This store cart could not be found. Reload your carts.');
        }
        cbRemoveCartItems($_SESSION['cart'], $currentGroups[$storeToClear]['items']);
        $message = 'This store cart was removed. Your other carts are unchanged.';
        $messageType = 'success';
    } catch (RuntimeException $exception) {
        $message = $exception instanceof PDOException ? 'Unable to update this store cart right now.' : $exception->getMessage();
        $messageType = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| UPDATE / REMOVE CART
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";
    $itemId = (int) ($_POST["item_id"] ?? 0);

    /*
    ----------------------------------------------------------------------
    REMOVE ITEM
    ----------------------------------------------------------------------
    */

    if (
        $action === "remove" &&
        $itemId > 0
    ) {

        if (isset($_SESSION["cart"][$itemId])) {

            $itemName =
                $_SESSION["cart"][$itemId]["item_name"]
                ?? "Item";

            unset($_SESSION["cart"][$itemId]);

            $message =
                $itemName .
                " was removed from your cart.";

            $messageType = "success";
        }
    }

    /*
    ----------------------------------------------------------------------
    UPDATE ITEM QUANTITY
    ----------------------------------------------------------------------
    */

    elseif (
        $action === "update" &&
        $itemId > 0
    ) {

        $quantity =
            (int) ($_POST["quantity"] ?? 1);

        if ($quantity < 1 || $quantity > 99) {

            $message =
                "Quantity must be between 1 and 99.";

            $messageType = "error";

        } elseif (
            !isset($_SESSION["cart"][$itemId])
        ) {

            $message =
                "The selected item is not in your cart.";

            $messageType = "error";

        } else {

            /*
            --------------------------------------------------------------
            Check current availability
            --------------------------------------------------------------
            */

            try {

                $stmt = $pdo->prepare("
                    SELECT
                        mi.item_id,
                        mi.item_name,
                        mi.price,
                        " . cbFoodAvailabilitySql('mi') . " AS general_available,
                        s.is_open,
                        owner.is_active AS owner_active,
                        owner.role_id AS owner_role
                    FROM menu_items mi
                    JOIN stores s ON s.store_id=mi.store_id
                    JOIN users owner ON owner.user_id=s.staff_user_id

                    WHERE mi.item_id = ? AND mi.archived_at IS NULL

                    LIMIT 1
                ");

                $stmt->execute([$itemId]);

                $item = $stmt->fetch();

                if (!$item) {

                    unset($_SESSION["cart"][$itemId]);

                    $message =
                        "The selected food is no longer available.";

                    $messageType = "error";

                } else {

                    $available = (int) $item['general_available'] === 1 && (int) $item['is_open'] === 1
                        && (int) $item['owner_active'] === 1 && (int) $item['owner_role'] === 2;

                    if (!$available) {

                        unset($_SESSION["cart"][$itemId]);

                        $message =
                            $item["item_name"] .
                            " is no longer available and was removed.";

                        $messageType = "error";

                    } else {

                        $_SESSION["cart"][$itemId]["quantity"] =
                            $quantity;

                        /*
                        Keep the latest database price.
                        */

                        $_SESSION["cart"][$itemId]["price"] =
                            (float) $item["price"];

                        $message =
                            "Cart updated successfully.";

                        $messageType = "success";
                    }
                }

            } catch (PDOException $e) {

                $message =
                    "Unable to update your cart.";

                $messageType = "error";
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| VALIDATE CURRENT CART AGAINST DATABASE
|--------------------------------------------------------------------------
*/

foreach ($_SESSION["cart"] as $itemId => &$cartItem) {

    try {

        $stmt = $pdo->prepare("
            SELECT
                mi.item_id,
                mi.item_name,
                mi.description,
                mi.image_path,
                mi.price,
                " . cbFoodAvailabilitySql('mi') . " AS general_available,
                c.category_name,
                s.is_open,
                owner.is_active AS owner_active,
                owner.role_id AS owner_role
            FROM menu_items mi
            JOIN stores s ON s.store_id=mi.store_id
            JOIN users owner ON owner.user_id=s.staff_user_id

            INNER JOIN categories c
                ON mi.category_id = c.category_id

            WHERE mi.item_id = ? AND mi.archived_at IS NULL

            LIMIT 1
        ");

        $stmt->execute([
            (int) $itemId
        ]);

        $item = $stmt->fetch();

        if (!$item) {

            unset($_SESSION["cart"][$itemId]);

            continue;
        }

        /*
        --------------------------------------------------------------
        Determine current availability
        --------------------------------------------------------------
        */

        $available = (int) $item['general_available'] === 1 && (int) $item['is_open'] === 1
            && (int) $item['owner_active'] === 1 && (int) $item['owner_role'] === 2;

        /*
        --------------------------------------------------------------
        Update cart information from database
        --------------------------------------------------------------
        */

        $cartItem["item_id"] =
            (int) $item["item_id"];

        $cartItem["item_name"] =
            $item["item_name"];

        $cartItem["description"] =
            $item["description"];

        $cartItem["price"] =
            (float) $item["price"];

        $cartItem["category_name"] =
            $item["category_name"];
        $cartItem['image_path'] = $item['image_path'];
        unset($cartItem['quantity_remaining']);

        /*
        --------------------------------------------------------------
        Handle unavailable items
        --------------------------------------------------------------
        */

        if (!$available) {

            $cartItem["unavailable"] = true;

        } else {

            $cartItem["unavailable"] = false;

            $cartItem['quantity'] = min(99, (int) $cartItem['quantity']);
        }

    } catch (PDOException $e) {

        $cartItem["unavailable"] = true;
    }
}

unset($cartItem);

/*
|--------------------------------------------------------------------------
| REMOVE ITEMS THAT NOW HAVE ZERO QUANTITY
|--------------------------------------------------------------------------
*/

foreach ($_SESSION["cart"] as $itemId => $cartItem) {

    if (
        !isset($cartItem["quantity"]) ||
        (int) $cartItem["quantity"] < 1
    ) {

        unset($_SESSION["cart"][$itemId]);
    }
}

/*
|--------------------------------------------------------------------------
| CALCULATE TOTAL
|--------------------------------------------------------------------------
*/

$subtotal = 0;

$cartCount = 0;

foreach ($_SESSION["cart"] as $cartItem) {

    $quantity =
        (int) ($cartItem["quantity"] ?? 0);

    $price =
        (float) ($cartItem["price"] ?? 0);

    $subtotal +=
        $price * $quantity;

    $cartCount +=
        $quantity;
}

$total = $subtotal;
$cartGroups = [];
try { $cartGroups = cbCartGroups($pdo, $_SESSION['cart']); }
catch (RuntimeException $exception) {
    $message = 'Unable to group your carts right now. Please reload before checking out.';
    $messageType = 'error';
}

/*
|--------------------------------------------------------------------------
| USER NAME
|--------------------------------------------------------------------------
*/

$firstName =
    $_SESSION["first_name"] ?? "Student";

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
        My Cart - CampusBite
    </title>

    <!-- CampusBite Food Theme -->

    <style>

        /* ==================================================
           CART PAGE
        ================================================== */

        .cart-header {
            margin-bottom: 25px;
        }

        .cart-header h1 {
            margin-bottom: 5px;
        }

        .cart-header p {
            color: var(--gray);
            font-size: 14px;
        }

        .cart-layout {
            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                350px;

            gap: 22px;

            align-items: start;
        }

        .cart-list {
            background: white;

            border: 1px solid var(--border);

            border-radius: var(--radius-lg);

            overflow: hidden;

            box-shadow: var(--shadow-sm);
        }

        .cart-list-header {
            padding: 18px 20px;

            border-bottom: 1px solid var(--border);

            background:
                linear-gradient(
                    180deg,
                    #fffdfb,
                    #fffaf7
                );

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 15px;
        }

        .cart-list-header h2 {
            margin: 0;

            font-size: 17px;
        }

        .cart-list-header span {
            color: var(--gray);

            font-size: 12px;
        }

        .cart-item {
            padding: 20px;

            border-bottom: 1px solid #f3ece6;

            display: grid;

            grid-template-columns: 80px minmax(0, 1fr) 145px 120px;

            gap: 18px;

            align-items: center;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .cart-food-icon {
            width: 80px;
            height: 80px;

            border-radius: 16px;

            background:
                linear-gradient(
                    135deg,
                    #fff7ed,
                    #ffedd5
                );

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 37px;
        }

        .cart-category {
            color: var(--orange-dark);

            font-size: 10px;

            font-weight: 800;

            text-transform: uppercase;

            margin-bottom: 4px;
        }

        .cart-food-name {
            color: var(--brown);

            font-size: 16px;

            font-weight: 800;

            margin-bottom: 4px;
        }

        .cart-food-description {
            color: var(--gray);

            font-size: 11px;

            line-height: 1.4;
        }

        .cart-price {
            color: var(--orange-dark);

            font-size: 14px;

            font-weight: 800;

            margin-top: 7px;
        }

        .cart-quantity-form {
            display: flex;

            flex-direction: column;

            gap: 7px;
        }

        .cart-quantity-label {
            color: var(--gray);

            font-size: 10px;

            font-weight: 700;
        }

        .cart-quantity-input {
            width: 75px;

            padding: 8px;

            text-align: center;
        }

        .update-button {
            width: 75px;

            padding: 7px 8px;

            font-size: 11px;
        }

        .remove-form {
            margin-top: 5px;
        }

        .remove-button {
            border: none;

            background: none;

            color: var(--tomato-dark);

            box-shadow: none;

            padding: 0;

            font-size: 11px;

            font-weight: 700;
        }

        .remove-button:hover {
            background: none;

            color: #991b1b;

            box-shadow: none;

            transform: none;
        }

        .cart-item-total {
            text-align: right;
        }

        .cart-item-total-label {
            display: block;

            color: var(--gray);

            font-size: 10px;

            margin-bottom: 4px;
        }

        .cart-item-total-value {
            color: var(--brown);

            font-size: 17px;

            font-weight: 900;
        }

        .unavailable-item {
            background: #fffafa;
        }

        .unavailable-warning {
            display: inline-block;

            margin-top: 7px;

            background: var(--tomato-light);

            color: var(--tomato-dark);

            border: 1px solid #fecaca;

            padding: 4px 7px;

            border-radius: 6px;

            font-size: 9px;

            font-weight: 800;
        }

        .summary-card {
            background: white;

            border: 1px solid var(--border);

            border-radius: var(--radius-lg);

            padding: 22px;

            box-shadow: var(--shadow-sm);

            position: sticky;

            top: 95px;
        }

        .summary-card h2 {
            font-size: 18px;

            margin-bottom: 18px;
        }

        .summary-row {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            padding: 9px 0;

            color: var(--brown-light);

            font-size: 13px;
        }

        .summary-divider {
            border: none;

            border-top: 1px solid var(--border);

            margin: 10px 0;
        }

        .summary-total {
            display: flex;

            justify-content: space-between;

            align-items: center;

            gap: 15px;

            padding: 8px 0 17px;
        }

        .summary-total span:first-child {
            color: var(--brown);

            font-size: 15px;

            font-weight: 800;
        }

        .summary-total span:last-child {
            color: var(--orange-dark);

            font-size: 24px;

            font-weight: 900;
        }

        .checkout-button {
            width: 100%;

            padding: 13px;
        }

        .continue-button {
            width: 100%;

            margin-top: 9px;
        }

        .empty-cart {
            background: white;

            border: 1px solid var(--border);

            border-radius: var(--radius-xl);

            box-shadow: var(--shadow-sm);

            text-align: center;

            padding: 65px 25px;
        }

        .empty-cart-icon {
            font-size: 65px;

            margin-bottom: 12px;
        }

        .empty-cart h2 {
            margin-bottom: 8px;
        }

        .empty-cart p {
            color: var(--gray);

            font-size: 13px;

            margin-bottom: 20px;
        }

        @media (max-width: 1000px) {

            .cart-layout {
                grid-template-columns: 1fr;
            }

            .summary-card {
                position: static;
            }

        }

        @media (max-width: 750px) {

            .cart-item {
                grid-template-columns: 65px minmax(0, 1fr);
            }

            .cart-food-icon {
                width: 65px;
                height: 65px;
                font-size: 30px;
            }

            .cart-quantity-form,
            .cart-item-total {
                grid-column: 2;
            }

            .cart-item-total {
                text-align: left;
            }

        }

        @media (max-width: 500px) {

            .cart-item {
                padding: 16px;
            }

        }

    </style>

    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="student-pages.css?v=1">
<link rel="stylesheet" href="stores.css?v=1">
    <link rel="stylesheet" href="cart-refresh.css?v=<?= (int) filemtime(__DIR__ . '/cart-refresh.css') ?>">
    <link rel="stylesheet" href="cart-groups.css?v=<?= (int) filemtime(__DIR__ . '/cart-groups.css') ?>">
    <script src="cart-refresh.js?v=<?= (int) filemtime(__DIR__ . '/cart-refresh.js') ?>" defer></script>
</head>

<body class="cb-student cb-cart" data-cart-user="<?= (int) $_SESSION['user_id'] ?>" data-cart-count="<?= (int) $cartCount ?>">

    <!-- ==================================================
         NAVIGATION
    =================================================== -->

    <?php require __DIR__ . "/components/student-navbar.php"; ?>


    <!-- ==================================================
         MAIN CONTENT
    =================================================== -->

    <main class="container" id="cart-content">

        <?php if (!empty($_SESSION["cart"])): ?>
        <div class="cb-store-notice"><span><strong><?= count($cartGroups) ?> store cart<?= count($cartGroups) === 1 ? '' : 's' ?></strong><small>Save picks from different kitchens. Check out each store separately.</small></span>
            <form method="POST">
<input type="hidden" name="csrf_token" value="<?= cbEscape($cartCsrf) ?>"><input type="hidden" name="action" value="clear_cart"><button class="btn btn-secondary" type="submit">Clear all carts</button></form>
        </div>
        <?php endif; ?>
        <!-- PAGE HEADER -->

        <div class="cart-header">
            <span class="cb-page-eyebrow">YOUR PICKS. READY TO GO.</span>

            <h1>
                Your <span>store carts.</span>
            </h1>

            <p>
                Hi,
                <?php
                echo htmlspecialchars($firstName);
                ?>!
                Your favorites, organized by kitchen. One checkout at a time.
            </p>

        </div>


        <!-- ==================================================
             MESSAGE
        =================================================== -->

        <?php if ($message !== ""): ?>

            <div
                role="status" aria-live="polite"
                data-cart-notice data-notice-type="<?= cbEscape($messageType) ?>" tabindex="-1"
                class="<?php

                    echo $messageType === "success"
                        ? "success"
                        : "error";

                ?>"
            >

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             EMPTY CART
        =================================================== -->

        <?php if (empty($_SESSION["cart"])): ?>

            <div class="empty-cart">

                <div class="empty-cart-icon" aria-hidden="true">
                    🛒
                </div>

                <h2>
                    Good food starts here.
                </h2>

                <p>
                    Your cart is empty for now. Discover a campus kitchen
                    and find something worth taking a break for.
                </p>

                <a
                    href="menu.php"
                    class="btn"
                >
                    Explore menus &#8594;
                </a>

            </div>

        <?php else: ?>


            <!-- ==================================================
                 CART LAYOUT
            =================================================== -->

            <div class="store-carts">
            <?php foreach ($cartGroups as $groupStoreId => $group):
                $cartStore = $group['store'];
                $canCheckout = $group['can_checkout'];
                $kitchenMenuUrl = $cartStore ? 'menu.php?store=' . (int) $groupStoreId : 'menu.php';
            ?>
            <section class="cart-store-group" data-cart-store="<?= (int) $groupStoreId ?>" aria-labelledby="cart-store-<?= (int) $groupStoreId ?>">
                <header class="cart-store-header">
                    <div><span class="cart-store-eyebrow">YOUR CAMPUS KITCHEN</span><h2 id="cart-store-<?= (int) $groupStoreId ?>"><?= cbEscape($cartStore['store_name'] ?? 'Unavailable items') ?></h2><p><?= (int) $group['quantity'] ?> item<?= $group['quantity'] === 1 ? '' : 's' ?> &middot; Separate checkout and pickup</p></div>
                    <form method="POST" action="cart.php">
                        <input type="hidden" name="csrf_token" value="<?= cbEscape($cartCsrf) ?>">
                        <input type="hidden" name="action" value="clear_store">
                        <input type="hidden" name="store_id" value="<?= (int) $groupStoreId ?>">
                        <button type="submit" class="remove-button" aria-label="Remove cart for <?= cbEscape($cartStore['store_name'] ?? 'unavailable items') ?>">Remove cart</button>
                    </form>
                </header>
            <div class="cart-layout">
                <div class="store-cart-foods">
                    <div class="store-cart-preview" aria-label="Food in this store cart">
                        <?php foreach (array_slice($group['items'], 0, 4) as $previewItem): ?>
                            <div class="store-cart-thumb">
                                <?php if ($preview = cbFoodPhotoUrl($previewItem['image_path'] ?? null)): ?>
                                    <img src="<?= cbEscape($preview) ?>" alt="<?= cbEscape($previewItem['item_name'] ?? 'Food') ?>" width="72" height="72" loading="lazy">
                                <?php else: ?><span><?= cbEscape($previewItem['item_name'] ?? 'Food') ?></span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <a href="<?= cbEscape($kitchenMenuUrl) ?>" class="store-cart-add" aria-label="Add food from <?= cbEscape($cartStore['store_name'] ?? 'a kitchen') ?>">+</a>
                    </div>


                <!-- ==================================================
                     CART ITEMS
                =================================================== -->

                <details class="cart-list" data-store-details="<?= (int) $groupStoreId ?>">

                    <summary class="cart-list-header">

                        <h2>
                            View / edit items
                        </h2>

                        <span>
                            <?php
                            echo number_format($group['quantity']);
                            ?>
                            item(s)
                        </span>

                    </summary>


                    <?php foreach (
                        $group['items'] as $itemId => $item
                    ): ?>

                        <?php

                        $quantity =
                            (int) ($item["quantity"] ?? 0);

                        $price =
                            (float) ($item["price"] ?? 0);

                        $itemTotal =
                            $price * $quantity;

                        $isUnavailable =
                            !empty($item["unavailable"]);

                        $foodEmoji = "🍽️";

                        switch (
                            $item["category_name"]
                            ?? ""
                        ) {

                            case "Rice Meals":
                                $foodEmoji = "🍛";
                                break;

                            case "Snacks":
                                $foodEmoji = "🥪";
                                break;

                            case "Beverages":
                                $foodEmoji = "🥤";
                                break;

                            case "Desserts":
                                $foodEmoji = "🍰";
                                break;
                        }

                        ?>

                        <div class="
                            cart-item
                            <?php

                            if ($isUnavailable) {
                                echo "unavailable-item";
                            }

                            ?>
                        ">


                            <!-- FOOD ICON -->

                            <div class="cart-food-icon">

                                <?php if ($photo = cbFoodPhotoUrl($item['image_path'] ?? null)): ?>
                                    <img src="<?= cbEscape($photo) ?>" alt="<?= cbEscape($item['item_name']) ?>" width="88" height="88" loading="lazy">
                                <?php else: ?><span aria-hidden="true"><?= $foodEmoji ?></span><?php endif; ?>

                            </div>


                            <!-- FOOD DETAILS -->

                            <div>

                                <div class="cart-category">

                                    <?php
                                    echo htmlspecialchars(
                                        $item["category_name"]
                                        ?? ""
                                    );
                                    ?>

                                </div>


                                <div class="cart-food-name">

                                    <?php
                                    echo htmlspecialchars(
                                        $item["item_name"]
                                        ?? "Food Item"
                                    );
                                    ?>

                                </div>


                                <div class="cart-food-description">

                                    <?php

                                    echo htmlspecialchars(
                                        $item["description"]
                                        ??
                                        "CampusBite food item."
                                    );

                                    ?>

                                </div>


                                <div class="cart-price">

                                    ₱<?php

                                    echo number_format(
                                        $price,
                                        2
                                    );

                                    ?>
                                    each

                                </div>


                                <?php if ($isUnavailable): ?>

                                    <div class="unavailable-warning">

                                        ⚠ Currently unavailable

                                    </div>

                                <?php endif; ?>

                            </div>


                            <!-- QUANTITY -->

                            <div>

                                <form
                                    method="POST"
                                    action="cart.php"
                                    class="cart-quantity-form"
                                >
<input type="hidden" name="csrf_token" value="<?= cbEscape($cartCsrf) ?>">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?php
                                            echo (int) $itemId;
                                        ?>"
                                    >

                                    <label
                                        class="cart-quantity-label"
                                        for="cart-quantity-<?= (int) $itemId ?>"
                                    >
                                        Quantity
                                    </label>

                                    <div class="cart-stepper">
                                    <button type="button" data-quantity-step="-1" aria-label="Decrease quantity for <?= cbEscape($item['item_name']) ?>">&#8722;</button>
                                    <input
                                        class="cart-quantity-input"
                                        id="cart-quantity-<?= (int) $itemId ?>"
                                        type="number"
                                        name="quantity"
                                        value="<?php
                                            echo $quantity;
                                        ?>"
                                        min="1"
                                        max="99"
                                        required
                                    >
                                    <button type="button" data-quantity-step="1" aria-label="Increase quantity for <?= cbEscape($item['item_name']) ?>">+</button>
                                    </div>

                                    <button
                                        type="submit"
                                        class="update-button"
                                    >
                                        Update
                                    </button>

                                </form>


                                <!-- REMOVE -->

                                <form
                                    method="POST"
                                    action="cart.php"
                                    class="remove-form"
                                >
<input type="hidden" name="csrf_token" value="<?= cbEscape($cartCsrf) ?>">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="remove"
                                    >

                                    <input
                                        type="hidden"
                                        name="item_id"
                                        value="<?php
                                            echo (int) $itemId;
                                        ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="remove-button"
                                        aria-label="Remove <?= cbEscape($item['item_name']) ?> from cart"
                                    >
                                        Remove
                                    </button>

                                </form>

                            </div>


                            <!-- ITEM TOTAL -->

                            <div class="cart-item-total">

                                <span class="cart-item-total-label">

                                    Item Total

                                </span>

                                <span
                                    class="cart-item-total-value"
                                >

                                    ₱<?php

                                    echo number_format(
                                        $itemTotal,
                                        2
                                    );

                                    ?>

                                </span>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </details>
                </div>


                <!-- ==================================================
                     ORDER SUMMARY
                =================================================== -->

                <div class="summary-card">

                    <h2>
                        Store subtotal
                    </h2>


                    <div class="summary-row">

                        <span>
                            Items
                        </span>

                        <strong>
                            <?php
                            echo number_format(
                                $group['quantity']
                            );
                            ?>
                        </strong>

                    </div>


                    <div class="summary-row">

                        <span>
                            Subtotal
                        </span>

                        <strong>
                            ₱<?php
                            echo number_format(
                                $group['subtotal'],
                                2
                            );
                            ?>
                        </strong>

                    </div>


                    <hr class="summary-divider">


                    <div class="summary-total">

                        <span>
                            Total
                        </span>

                        <span>
                            ₱<?php
                            echo number_format(
                                $group['subtotal'],
                                2
                            );
                            ?>
                        </span>

                    </div>


                    <?php if ($canCheckout): ?>
                    <a
                        href="checkout.php?store=<?= (int) $groupStoreId ?>"
                        class="btn checkout-button"
                    >
                        Check out this store &#8594;
                    </a>
                    <?php else: ?>
                        <button class="btn checkout-button" disabled>Checkout unavailable</button>
                        <p class="cart-checkout-note">Review unavailable items or check out another store. Other carts are unaffected.</p>
                    <?php endif; ?>


                    <a
                        href="<?= cbEscape($kitchenMenuUrl) ?>"
                        class="btn btn-secondary continue-button"
                    >
                        Add more from this kitchen
                    </a>
                    <p class="cart-checkout-note">Only this kitchen is included. Other carts stay saved.</p>

                </div>

            </div>
            </section>
            <?php endforeach; ?>
            </div>

        <?php endif; ?>


        <!-- FOOTER -->

        <div class="footer">

            CampusBite &copy;
            <?php echo date("Y"); ?>

            · Fresh food, easier ordering

        </div>

    </main>

</body>

</html>
