<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";
require_once __DIR__ . "/config/cart-groups.php";

// Only students can access checkout
if (!isset($_SESSION["user_id"]) || $_SESSION["role_id"] != 3) {
    header("Location: login.php");
    exit;
}

$userId = (int) $_SESSION["user_id"];

// Make sure cart exists
if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

$cart = [];
$selectedStoreId = 0;
$error = "";
if (($_GET['success'] ?? '') !== '1') {
    try {
        $requestedStore = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['store_id'] ?? null) : ($_GET['store'] ?? null);
        $selection = cbSelectedCheckoutCart($pdo, $_SESSION['cart'], $requestedStore);
        $cart = $selection['items'];
        $selectedStoreId = $selection['store_id'];
    } catch (RuntimeException $exception) {
        $error = $exception instanceof PDOException ? 'Unable to load this store cart right now.' : $exception->getMessage();
    }
}
$checkoutCsrfKey = $selectedStoreId > 0 ? 'checkout_csrf_store_' . $selectedStoreId : 'checkout_csrf';
$checkoutToken = cbCsrf($checkoutCsrfKey);
$checkoutRequestValid = $_SERVER['REQUEST_METHOD'] !== 'POST' || cbCheckCsrf($checkoutCsrfKey);
if (!$checkoutRequestValid) $error = 'Invalid request. Refresh and try again.';

/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/
if (isset($_GET["success"]) && $_GET["success"] === "1") {

    $successData = $_SESSION["last_checkout"] ?? null;

    if (!$successData) {
        header("Location: dashboard.php");
        exit;
    }
    // Read the current order/payment state instead of the old checkout snapshot.
    $confirmation = $pdo->prepare('SELECT o.order_id, o.order_number, o.total_amount, o.status_id,
        s.store_name, p.method AS payment_method, p.reference_number, p.payment_status,
        p.receipt_path, p.payment_rejected_at, p.payment_rejection_reason, os.status_name AS order_status_name
        FROM orders o JOIN stores s ON s.store_id=o.store_id
        JOIN payments p ON p.order_id=o.order_id
        JOIN order_statuses os ON os.status_id=o.status_id
        WHERE o.order_id=? AND o.student_id=? LIMIT 1');
    $confirmation->execute([(int) ($successData['order_id'] ?? 0), $userId]);
    $successData = $confirmation->fetch();
    if (!$successData) {
        header('Location: orders.php');
        exit;
    }
    $paymentState = (int) $successData['status_id'] === 5 ? 'cancelled'
        : ($successData['payment_status'] === 'Refunded' ? 'refunded'
        : ($successData['payment_status'] === 'Paid' ? 'paid'
        : (cbReceiptPath($successData['receipt_path']) ? 'review' : 'unpaid')));
    $confirmationTitles = [
        'unpaid' => 'Order placed — payment pending',
        'review' => 'Payment awaiting verification',
        'paid' => 'Payment confirmed',
        'cancelled' => 'Order cancelled',
        'refunded' => 'Payment refunded'
    ];
    $confirmationMessages = [
        'unpaid' => $successData['payment_method'] === 'GCash'
            ? 'Your order is saved. Complete your GCash payment and submit the reference number and receipt in My Orders.'
            : 'Your order is saved. Pay at the canteen so staff can confirm your payment.',
        'review' => 'Your receipt has been submitted. Staff will verify your payment before preparing your order.',
        'paid' => 'The store has confirmed your payment. View My Orders for preparation and pickup updates.',
        'cancelled' => 'This order has been cancelled. View My Orders for its details.',
        'refunded' => 'Your payment is marked as refunded. Contact the store if you need help.'
    ];
    if (!empty($successData['payment_rejected_at'])) {
        $confirmationTitles['cancelled'] = 'Payment rejected — order cancelled';
        $confirmationMessages['cancelled'] = 'The store could not verify your payment. Reason: ' . $successData['payment_rejection_reason'];
    }
    $confirmationPaymentLabel = !empty($successData['payment_rejected_at']) ? 'Rejected' : ($paymentState === 'review' ? 'Awaiting verification'
        : ($successData['payment_status'] === 'Unpaid' ? 'Unpaid' : $successData['payment_status']));
}

/*
|--------------------------------------------------------------------------
| CANCEL CHECKOUT
|--------------------------------------------------------------------------
*/
if ($checkoutRequestValid && $error === '' && $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "cancel_checkout") {

    cbRemoveCartItems($_SESSION['cart'], $cart);

    header("Location: cart.php?cancelled=1");
    exit;
}

/*
|--------------------------------------------------------------------------
| PLACE ORDER
|--------------------------------------------------------------------------
*/
if ($checkoutRequestValid && $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "place_order") {

    $paymentMethod = is_string($_POST["payment_method"] ?? null) ? $_POST["payment_method"] : '';
    $remarks = is_string($_POST["remarks"] ?? null) ? trim($_POST["remarks"]) : '';

    // Validate payment method
    if (!in_array($paymentMethod, ["Cash", "GCash"], true)) {
        $error = "Please choose a valid payment method.";
    }

    // Validate cart
    if (empty($cart)) {
        $error = "Your cart is empty.";
    }

    // Validate remarks
    if (strlen($remarks) > 255) {
        $error = "Remarks cannot exceed 255 characters.";
    }

    if ($error === "") {

        try {

            /*
            |--------------------------------------------------------------------------
            | START DATABASE TRANSACTION
            |--------------------------------------------------------------------------
            */
            $pdo->beginTransaction();

            $checkoutStore = cbCartStore($pdo, $cart);
            if (!$checkoutStore || (int) $checkoutStore['store_id'] !== $selectedStoreId) {
                throw new RuntimeException('This food changed stores. Review your carts before checking out.');
            }
            if ($paymentMethod === "GCash" && empty($checkoutStore["gcash_number"])) {
                throw new Exception("This store accepts Cash only until it sets a GCash number.");
            }
            $validatedItems = [];
            $totalAmount = 0;

            /*
            |--------------------------------------------------------------------------
            | VALIDATE EVERY CART ITEM AGAINST DATABASE
            |--------------------------------------------------------------------------
            */
            foreach ($cart as $cartItem) {

                $itemId = isset($cartItem["item_id"])
                    ? (int) $cartItem["item_id"]
                    : 0;

                $requestedQty = isset($cartItem["quantity"])
                    ? (int) $cartItem["quantity"]
                    : 0;

                if ($itemId <= 0 || $requestedQty <= 0 || $requestedQty > 99) {
                    throw new Exception("Invalid item found in your cart.");
                }

                /*
                |--------------------------------------------------------------------------
                | GET CURRENT MENU ITEM
                |--------------------------------------------------------------------------
                */
                $stmt = $pdo->prepare("
                    SELECT
                        mi.item_id,
                        mi.item_name,
                        mi.description,
                        mi.price,
                        mi.is_available,
                        c.category_name
                    FROM menu_items mi
                    LEFT JOIN categories c
                        ON mi.category_id = c.category_id
                    WHERE mi.item_id = ?
                    LIMIT 1
                ");

                $stmt->execute([$itemId]);
                $item = $stmt->fetch();

                if (!$item) {
                    throw new Exception(
                        "One of the items in your cart no longer exists."
                    );
                }

                // Check master menu availability
                if ((int) $item["is_available"] !== 1) {
                    throw new Exception(
                        $item["item_name"] . " is currently unavailable."
                    );
                }

                $unitPrice = (float) $item["price"];
                $subtotal = $unitPrice * $requestedQty;

                $totalAmount += $subtotal;

                $validatedItems[] = [
                    "item_id" => $itemId,
                    "item_name" => $item["item_name"],
                    "category_name" => $item["category_name"],
                    "quantity" => $requestedQty,
                    "unit_price" => $unitPrice,
                    "subtotal" => $subtotal
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | CREATE ORDER
            |
            | order_number is intentionally empty.
            | Your database trigger automatically generates:
            | ORD-YYYYMMDD-NNN
            |--------------------------------------------------------------------------
            */
            $orderStmt = $pdo->prepare("
                INSERT INTO orders
                (
                    order_number,
                    student_id,
                    store_id,
                    status_id,
                    order_datetime,
                    total_amount,
                    remarks
                )
                VALUES
                (
                    '',
                    ?,
                    ?,
                    1,
                    NOW(),
                    ?,
                    ?
                ) RETURNING order_id
            ");

            $orderStmt->execute([
                $userId,
                $checkoutStore["store_id"],
                $totalAmount,
                $remarks !== "" ? $remarks : null
            ]);

            $orderId = (int) $orderStmt->fetchColumn();

            /*
            |--------------------------------------------------------------------------
            | GET GENERATED ORDER NUMBER
            |--------------------------------------------------------------------------
            */
            $numberStmt = $pdo->prepare("
                SELECT order_number
                FROM orders
                WHERE order_id = ?
                LIMIT 1
            ");

            $numberStmt->execute([$orderId]);

            $orderNumber = $numberStmt->fetchColumn();

            if (!$orderNumber) {
                throw new Exception("Unable to generate order number.");
            }

            /*
            |--------------------------------------------------------------------------
            | INSERT ORDER ITEMS
            |--------------------------------------------------------------------------
            */
            $orderItemStmt = $pdo->prepare("
                INSERT INTO order_items
                (
                    order_id,
                    item_id,
                    quantity,
                    unit_price
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?
                )
            ");

            foreach ($validatedItems as $item) {

                $orderItemStmt->execute([
                    $orderId,
                    $item["item_id"],
                    $item["quantity"],
                    $item["unit_price"]
                ]);
            }

            // The customer submits their actual reference after the order is placed.
            $referenceNumber = null;

            /*
            |--------------------------------------------------------------------------
            | INSERT PAYMENT
            |
            | New orders begin as Unpaid.
            | Staff will confirm payment before the order moves to Preparing.
            |--------------------------------------------------------------------------
            */
            $paymentStmt = $pdo->prepare("
                INSERT INTO payments
                (
                    order_id,
                    method,
                    amount,
                    reference_number,
                    payment_status
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    'Unpaid'
                )
            ");

            $paymentStmt->execute([
                $orderId,
                $paymentMethod,
                $totalAmount,
                $referenceNumber
            ]);

            /*
            |--------------------------------------------------------------------------
            | INSERT INITIAL ORDER STATUS LOG
            |--------------------------------------------------------------------------
            */
            $logStmt = $pdo->prepare("
                INSERT INTO order_status_logs
                (
                    order_id,
                    status_id,
                    changed_by,
                    note
                )
                VALUES
                (
                    ?,
                    1,
                    ?,
                    ?
                )
            ");

            $logStmt->execute([
                $orderId,
                $userId,
                "Order placed by student"
            ]);

            /*
            |--------------------------------------------------------------------------
            | COMMIT EVERYTHING
            |--------------------------------------------------------------------------
            */
            $pdo->commit();

            /*
            |--------------------------------------------------------------------------
            | SAVE SUCCESS INFORMATION
            |--------------------------------------------------------------------------
            */
            $_SESSION["last_checkout"] = [
                "order_id" => $orderId,
                "store_name" => $checkoutStore["store_name"],
                "order_number" => $orderNumber,
                "total_amount" => $totalAmount,
                "payment_method" => $paymentMethod,
                "reference_number" => $referenceNumber,
                "remarks" => $remarks
            ];

            /*
            |--------------------------------------------------------------------------
            | CLEAR CART
            |--------------------------------------------------------------------------
            */
            cbRemoveCartItems($_SESSION['cart'], $cart);
            $_SESSION[$checkoutCsrfKey] = bin2hex(random_bytes(32));

            /*
            |--------------------------------------------------------------------------
            | REDIRECT TO SUCCESS PAGE
            |--------------------------------------------------------------------------
            */
            header("Location: checkout.php?success=1");
            exit;

        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ROLLBACK IF SOMETHING FAILED
            |--------------------------------------------------------------------------
            */
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| CALCULATE CURRENT CART TOTAL
|--------------------------------------------------------------------------
*/
$cartTotal = 0;
$displayCart = [];

if (!empty($cart)) {

    foreach ($cart as $cartItem) {

        $itemId = isset($cartItem["item_id"])
            ? (int) $cartItem["item_id"]
            : 0;

        $quantity = isset($cartItem["quantity"])
            ? (int) $cartItem["quantity"]
            : 0;

        if ($itemId <= 0 || $quantity <= 0) {
            continue;
        }

        $stmt = $pdo->prepare("
            SELECT
                mi.item_id,
                mi.item_name,
                mi.description,
                mi.price,
                c.category_name
            FROM menu_items mi
            LEFT JOIN categories c
                ON mi.category_id = c.category_id
            WHERE mi.item_id = ?
            LIMIT 1
        ");

        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        if (!$item) {
            continue;
        }

        $price = (float) $item["price"];
        $subtotal = $price * $quantity;

        $cartTotal += $subtotal;

        $displayCart[] = [
            "item_id" => $item["item_id"],
            "item_name" => $item["item_name"],
            "description" => $item["description"],
            "category_name" => $item["category_name"],
            "price" => $price,
            "quantity" => $quantity,
            "subtotal" => $subtotal
        ];
    }
}
$displayStore = null;
try { $displayStore = cbCartStore($pdo, $cart); }
catch (RuntimeException $exception) { if ($error === "") $error = $exception->getMessage(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Checkout - CampusBite</title>



    <style>
        .checkout-page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px 60px;
        }

        .checkout-header {
            margin-bottom: 25px;
        }

        .checkout-header h1 {
            margin-bottom: 6px;
        }

        .checkout-header p {
            color: #6b4f42;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: 1.35fr 0.85fr;
            gap: 25px;
            align-items: start;
        }

        .checkout-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(59, 36, 26, 0.08);
            border: 1px solid rgba(249, 115, 22, 0.12);
        }

        .checkout-card h2 {
            margin-top: 0;
            margin-bottom: 18px;
        }

        .checkout-item {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #f0e3d8;
        }

        .checkout-item:last-child {
            border-bottom: none;
        }

        .checkout-item-name {
            font-weight: 700;
            color: #3b241a;
        }

        .checkout-item-meta {
            font-size: 13px;
            color: #7b6257;
            margin-top: 4px;
        }

        .checkout-item-price {
            font-weight: 700;
            color: #f97316;
            white-space: nowrap;
        }

        .total-box {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 2px dashed #f2d8c5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .total-label {
            font-size: 18px;
            font-weight: 700;
        }

        .total-value {
            font-size: 28px;
            font-weight: 800;
            color: #f97316;
        }

        .payment-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            margin-bottom: 12px;
            border: 2px solid #f0dfd3;
            border-radius: 14px;
            cursor: pointer;
            transition: 0.2s;
        }

        .payment-option:hover {
            border-color: #f97316;
            background: #fffaf6;
        }

        .payment-option input {
            width: 18px;
            height: 18px;
        }

        .payment-option strong {
            display: block;
            margin-bottom: 3px;
        }

        .payment-option small {
            color: #745f55;
        }

        .gcash-info {
            display: none;
            margin-top: 12px;
            padding: 15px;
            border-radius: 14px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
        }

        .gcash-number {
            font-size: 22px;
            font-weight: 800;
            color: #ea580c;
            margin: 5px 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-weight: 700;
        }

        .form-group textarea {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid #e3d4cb;
            border-radius: 12px;
            resize: vertical;
            font: inherit;
            box-sizing: border-box;
        }

        .form-group textarea:focus {
            outline: none;
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.12);
        }

        .error-box {
            margin-bottom: 20px;
            padding: 14px 16px;
            border-radius: 12px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .checkout-actions {
            display: flex;
            gap: 12px;
            margin-top: 22px;
        }

        .checkout-actions button,
        .checkout-actions a {
            flex: 1;
            text-align: center;
        }

        .success-page {
            max-width: 700px;
            margin: 50px auto;
            padding: 20px;
        }

        .success-card {
            background: #ffffff;
            border-radius: 22px;
            padding: 35px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(59, 36, 26, 0.10);
            border: 1px solid rgba(249, 115, 22, 0.12);
        }

        .success-icon {
            width: 75px;
            height: 75px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #dcfce7;
            color: #16a34a;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 38px;
            font-weight: 800;
        }

        .success-card h1 {
            margin-bottom: 8px;
        }

        .success-card p {
            color: #6b4f42;
        }

        .order-number-box {
            margin: 25px 0;
            padding: 20px;
            border-radius: 16px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
        }

        .order-number-label {
            font-size: 13px;
            color: #7b6257;
        }

        .order-number {
            font-size: 28px;
            font-weight: 800;
            color: #ea580c;
            margin-top: 5px;
        }

        .success-details {
            text-align: left;
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px solid #f0e3d8;
        }

        .success-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 10px 0;
        }

        .success-row span:first-child {
            color: #745f55;
        }

        .success-row span:last-child {
            font-weight: 700;
            color: #3b241a;
            text-align: right;
        }

        .success-buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }

        .success-buttons a {
            flex: 1;
        }

        .empty-checkout {
            background: #ffffff;
            padding: 35px;
            text-align: center;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(59, 36, 26, 0.08);
        }

        @media (max-width: 800px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }

            .checkout-actions,
            .success-buttons {
                flex-direction: column;
            }

            .checkout-item {
                align-items: flex-start;
            }

            .success-row {
                flex-direction: column;
                gap: 4px;
            }

            .success-row span:last-child {
                text-align: left;
            }
        }
    </style>
    <link rel="stylesheet" href="student-pages.css?v=1">
    <link rel="stylesheet" href="checkout-food.css?v=<?= (int) filemtime(__DIR__ . '/checkout-food.css') ?>">
    <link rel="stylesheet" href="payment-confirmation.css?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.css') ?>">
    <script src="payment-confirmation.js?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.js') ?>" defer></script>
    <link rel="stylesheet" href="student-navbar.css?v=1">
<link rel="stylesheet" href="stores.css?v=1">
</head>

<body class="cb-student">

<?php require __DIR__ . "/components/student-navbar.php"; ?>

<?php if (isset($_GET["success"]) && $_GET["success"] === "1" && $successData): ?>

    <!-- ==============================================================
         ORDER SUCCESS
    ============================================================== -->

    <div class="success-page">

        <div class="success-card" data-payment-state="<?= cbEscape($paymentState) ?>">

            <div class="success-icon" aria-hidden="true">
                <?php if ($paymentState === 'paid'): ?>
                    &#10003;
                <?php elseif ($paymentState === 'cancelled'): ?>
                    &times;
                <?php elseif ($paymentState === 'refunded'): ?>
                    &#8630;
                <?php else: ?>
                    <svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="M12 6v6l4 2"></path></svg>
                <?php endif; ?>
            </div>

            <h1><?= cbEscape($confirmationTitles[$paymentState]) ?></h1>
            <p><?= cbEscape($successData["store_name"] ?? "") ?></p>

            <p>
                <?= cbEscape($confirmationMessages[$paymentState]) ?>
            </p>

            <div class="order-number-box">

                <div class="order-number-label">
                    Your Order Number
                </div>

                <div class="order-number">
                    <?= htmlspecialchars($successData["order_number"]) ?>
                </div>

            </div>

            <div class="success-details">

                <div class="success-row">
                    <span>Payment Method</span>

                    <span>
                        <?= htmlspecialchars($successData["payment_method"]) ?>
                    </span>
                </div>

                <div class="success-row">
                    <span>Total Amount</span>

                    <span>
                        ₱<?= number_format($successData["total_amount"], 2) ?>
                    </span>
                </div>

                <?php if (cbRealGcashReference($successData['reference_number'] ?? null)): ?>

                    <div class="success-row">
                        <span>GCash Reference</span>

                        <span>
                            <?= htmlspecialchars($successData["reference_number"]) ?>
                        </span>
                    </div>

                <?php endif; ?>

                <div class="success-row">
                    <span>Order status</span>

                    <span>
                        <?= cbEscape($successData['order_status_name']) ?>
                    </span>
                </div>

                <div class="success-row">
                    <span>Payment status</span>
                    <span class="confirmation-payment-state"><?= cbEscape($confirmationPaymentLabel) ?></span>
                </div>

            </div>

            <?php if ($paymentState === 'unpaid' && $successData["payment_method"] === "GCash"): ?>

                <div
                    style="
                        margin-top:20px;
                        padding:15px;
                        border-radius:12px;
                        background:#fff7ed;
                        color:#7c2d12;
                        text-align:left;
                    "
                >
                    <strong>GCash Reminder</strong><br>

                    Your order is reserved. Open My Orders to pay this store and submit
                    the actual reference number and receipt from your GCash payment.
                    Staff will verify it before preparing your order.
                </div>

            <?php elseif ($paymentState === 'unpaid'): ?>

                <div
                    style="
                        margin-top:20px;
                        padding:15px;
                        border-radius:12px;
                        background:#fff7ed;
                        color:#7c2d12;
                        text-align:left;
                    "
                >
                    <strong>Cash Payment</strong><br>

                    Please pay at the canteen when required.
                    Staff will confirm the payment before preparing your order.
                </div>

            <?php endif; ?>

            <div class="success-buttons">
                <?php if (!empty($_SESSION['cart'])): ?>
                    <a href="cart.php" class="btn btn-secondary">View remaining store carts</a>
                <?php endif; ?>

                <a
                    href="orders.php<?= $successData['payment_method'] === 'GCash' && in_array($paymentState, ['unpaid', 'review'], true) ? '#payment-proof-' . (int) $successData['order_id'] : '' ?>"
                    class="btn btn-primary"
                >
                    <?= $paymentState === 'unpaid' && $successData['payment_method'] === 'GCash' ? 'Pay / submit GCash receipt' : ($paymentState === 'review' ? 'View payment submission' : 'View My Orders') ?>
                </a>

                <a
                    href="menu.php"
                    class="btn btn-secondary"
                >
                    Order More Food
                </a>

            </div>

        </div>

    </div>

<?php else: ?>

    <!-- ==============================================================
         CHECKOUT PAGE
    ============================================================== -->

    <div class="checkout-page">

        <div class="checkout-header">
            <span class="cb-page-eyebrow">ONE LAST STEP. THEN LUNCH.</span>

            <h1>Good food, almost yours.</h1>

            <p>
                Review your order and choose your payment method.
            </p>

        </div>

        <?php if ($error !== ""): ?>

            <div class="error-box" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>

        <?php if (empty($displayCart)): ?>

            <div class="empty-checkout">

                <h2><?= empty($_SESSION['cart']) ? 'Your cart is empty' : 'Choose a store cart' ?></h2>

                <p>
                    <?= empty($_SESSION['cart']) ? 'Add food from a campus kitchen before checking out.' : 'Each kitchen has its own checkout. Review your carts to select one.' ?>
                </p>

                <br>

                <a
                    href="<?= empty($_SESSION['cart']) ? 'menu.php' : 'cart.php' ?>"
                    class="btn btn-primary"
                >
                    <?= empty($_SESSION['cart']) ? 'Browse Menu' : 'View your carts' ?>
                </a>

            </div>

        <?php else: ?>

            <div class="checkout-grid">

                <!-- ======================================================
                     ORDER SUMMARY
                ======================================================= -->

                <div class="checkout-card">

                    <h2>Order Summary</h2>
                    <p class="cb-food-store"><?= cbEscape($displayStore["store_name"] ?? "Review your cart") ?> &middot; One store per checkout</p>

                    <?php foreach ($displayCart as $item): ?>

                        <div class="checkout-item">

                            <div>

                                <div class="checkout-item-name">
                                    <?= htmlspecialchars($item["item_name"]) ?>
                                </div>

                                <div class="checkout-item-meta">

                                    <?= htmlspecialchars(
                                        $item["category_name"] ?? "Food"
                                    ) ?>

                                    ·

                                    <?= $item["quantity"] ?> ×
                                    ₱<?= number_format($item["price"], 2) ?>

                                </div>

                            </div>

                            <div class="checkout-item-price">

                                ₱<?= number_format(
                                    $item["subtotal"],
                                    2
                                ) ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                    <div class="total-box">

                        <span class="total-label">
                            Total
                        </span>

                        <span class="total-value">
                            ₱<?= number_format($cartTotal, 2) ?>
                        </span>

                    </div>

                </div>

                <!-- ======================================================
                     PAYMENT
                ======================================================= -->

                <div class="checkout-card">

                    <h2>Payment</h2>
                    <p class="checkout-payment-note">Staff will confirm your payment before preparing your order.</p>

                    <form
                        action="checkout.php"
                        method="POST"
                        id="checkoutForm"
                        data-payment-confirm="checkout"
                        data-payment-store="<?= cbEscape($displayStore['store_name'] ?? '') ?>"
                        data-payment-amount="<?= number_format($cartTotal, 2, '.', '') ?>"
                        data-payment-number="<?= cbEscape($displayStore['gcash_number'] ?? '') ?>"
                    >
<input type="hidden" name="csrf_token" value="<?= cbEscape($checkoutToken) ?>">
<input type="hidden" name="store_id" value="<?= (int) $selectedStoreId ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="place_order"
                        >

                        <fieldset class="form-group payment-methods">
                            <legend>Choose Payment Method</legend>

                            <label class="payment-option">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="Cash"
                                    checked
                                    onchange="showPaymentInfo()"
                                >

                                <div>

                                    <strong>
                                        💵 Cash
                                    </strong>

                                    <small>
                                        Pay at the canteen.
                                    </small>

                                </div>

                            </label>

                            <label class="payment-option">

                                <input
                                    type="radio"
                                    name="payment_method"
                                    value="GCash"
                                    <?= empty($displayStore["gcash_number"]) ? "disabled" : "" ?>
                                    onchange="showPaymentInfo()"
                                >

                                <div>

                                    <strong>
                                        📱 GCash
                                    </strong>

                                    <small>
                                        <?= empty($displayStore["gcash_number"]) ? "This store currently accepts Cash only." : "Pay this store directly using GCash." ?>
                                    </small>

                                </div>

                            </label>

                        </fieldset>

                        <!-- GCash Information -->

                        <div
                            id="gcashInfo"
                            class="gcash-info"
                        >

                            <strong>
                                GCash Payment
                            </strong>

                            <div class="gcash-number">
                                <?= cbEscape($displayStore["gcash_number"] ?? "") ?>
                            </div>

                            <?php if ($gcashQr = cbPaymentQrUrl($displayStore['gcash_qr_path'] ?? null)): ?>
                                <figure class="checkout-gcash-qr">
                                    <a href="<?= cbEscape($gcashQr) ?>" target="_blank" rel="noopener" aria-label="Open this store's GCash QR image">
                                        <img src="<?= cbEscape($gcashQr) ?>" alt="GCash payment QR for <?= cbEscape($displayStore['store_name']) ?>" width="300" height="300">
                                    </a>
                                    <figcaption>Scan this store's QR to pay, or <a href="<?= cbEscape($gcashQr) ?>" download>save the QR image</a>.</figcaption>
                                </figure>
                            <?php endif; ?>

                            <small>
                                Send payment to the store number above. Payments are confirmed manually.
                            </small>

                            <br><br>

                            <small>
                                Place your order first. Then submit the actual GCash
                                reference number and receipt in My Orders after paying.
                                Staff will verify your payment before preparing the order.
                            </small>

                        </div>

                        <!-- Remarks -->

                        <div class="form-group">

                            <label for="remarks">
                                Order Remarks
                            </label>

                            <textarea
                                id="remarks"
                                name="remarks"
                                maxlength="255"
                                placeholder="Example: No onions, extra sauce, etc."
                            ></textarea>

                        </div>

                        <!-- Buttons -->

                        <div class="checkout-actions">

                            <a
                                href="cart.php"
                                class="btn btn-secondary"
                            >
                                Back to Cart
                            </a>

                            <button
                                type="submit"
                                class="btn btn-primary"
                            >
                                Place Order
                            </button>

                        </div>

                    </form>

                    <form
                        action="checkout.php"
                        method="POST"
                        id="cancelCheckoutForm"
                        style="margin-top:12px;"
                    >
<input type="hidden" name="csrf_token" value="<?= cbEscape($checkoutToken) ?>">
<input type="hidden" name="store_id" value="<?= (int) $selectedStoreId ?>">

                        <input
                            type="hidden"
                            name="action"
                            value="cancel_checkout"
                        >

                        <button
                            type="button"
                            class="btn btn-danger"
                            style="width:100%;"
                            onclick="cancelCheckout()"
                        >
                            Cancel this store checkout
                        </button>

                    </form>

                </div>

            </div>

        <?php endif; ?>

    </div>

<?php endif; ?>

<script>

function showPaymentInfo() {

    const gcashRadio =
        document.querySelector(
            'input[name="payment_method"][value="GCash"]'
        );

    const gcashInfo =
        document.getElementById("gcashInfo");

    if (!gcashRadio || !gcashInfo) {
        return;
    }

    if (gcashRadio.checked) {

        gcashInfo.style.display = "block";

    } else {

        gcashInfo.style.display = "none";
    }
}

function cancelCheckout() {

    const confirmed = confirm(
        "Remove this store's items and cancel its checkout? Your other store carts will stay unchanged."
    );

    if (confirmed) {

        document
            .getElementById("cancelCheckoutForm")
            .submit();
    }
}

document.addEventListener(
    "DOMContentLoaded",
    function () {

        showPaymentInfo();

    }
);

</script>

<?php require __DIR__ . '/components/payment-confirmation.php'; ?>
</body>
</html>
