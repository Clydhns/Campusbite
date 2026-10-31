<?php

if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";

// User must be logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$success_message = "";
$error_message = "";
$ordersCsrf = cbCsrf('orders_csrf');
$error_message = $_SESSION['order_error_message'] ?? '';
unset($_SESSION['order_error_message']);

if (isset($_SESSION["order_success_message"])) {
    $success_message = $_SESSION["order_success_message"];
    unset($_SESSION["order_success_message"]);
}


// =========================================================
// CANCEL ORDER
// =========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["action"]) &&
    $_POST["action"] === "cancel_order"
) {

    $order_id = isset($_POST["order_id"])
        ? (int) $_POST["order_id"]
        : 0;

    $cancel_reason = trim(
        $_POST["cancel_reason"] ?? ""
    );


    if (!cbCheckCsrf('orders_csrf')) {
        $error_message = 'Invalid request. Refresh and try again.';
    } elseif ($order_id <= 0) {

        $error_message = "Invalid order.";

    } else {

        try {

            $pdo->beginTransaction();


            // -------------------------------------------------
            // Get and lock the order
            // -------------------------------------------------

            $order_sql = "
                SELECT
                    o.order_id,
                    o.order_number, s.store_name,
                    o.student_id,
                    o.status_id,
                    o.order_datetime,
                    o.total_amount,
                    o.remarks,
                    p.payment_status, p.receipt_path

                FROM orders o JOIN stores s ON s.store_id=o.store_id

                LEFT JOIN payments p
                    ON o.order_id = p.order_id

                WHERE o.order_id = :order_id
                AND o.student_id = :student_id

                LIMIT 1

                FOR UPDATE OF o
            ";

            $order_stmt = $pdo->prepare($order_sql);

            $order_stmt->execute([
                "order_id" => $order_id,
                "student_id" => $_SESSION["user_id"]
            ]);

            $order = $order_stmt->fetch();


            if (!$order) {

                throw new Exception(
                    "Order not found."
                );
            }


            // -------------------------------------------------
            // Only Pending orders can be cancelled
            // -------------------------------------------------

            if ((int) $order["status_id"] !== 1) {

                throw new Exception(
                    "Only pending orders can be cancelled."
                );
            }


            // -------------------------------------------------
            // Only unpaid orders can be cancelled by user
            // -------------------------------------------------

            if (
                $order["payment_status"] !== null &&
                $order["payment_status"] !== "Unpaid"
            ) {

                throw new Exception(
                    "This order cannot be cancelled because its payment has already been confirmed. Please contact the canteen staff."
                );
            }
            if (cbReceiptPath($order['receipt_path'] ?? null)) {
                throw new Exception('Your payment receipt is awaiting verification. Contact the store to cancel this order.');
            }


            // -------------------------------------------------
            // Default reason
            // -------------------------------------------------

            if ($cancel_reason === "") {
                $cancel_reason = "Cancelled by customer";
            }


            // Limit to database column size
            $cancel_reason = substr(
                $cancel_reason,
                0,
                255
            );


            // -------------------------------------------------
            // Change order status to Cancelled
            // -------------------------------------------------

            $update_order_sql = "
                UPDATE orders

                SET
                    status_id = 5,
                    cancel_reason = :cancel_reason

                WHERE order_id = :order_id
                AND student_id = :student_id
                AND status_id = 1
            ";

            $update_order_stmt =
                $pdo->prepare($update_order_sql);

            $update_order_stmt->execute([
                "cancel_reason" => $cancel_reason,
                "order_id" => $order_id,
                "student_id" => $_SESSION["user_id"]
            ]);


            if ($update_order_stmt->rowCount() === 0) {

                throw new Exception(
                    "The order could not be cancelled."
                );
            }


            // -------------------------------------------------
            // Add status log
            // -------------------------------------------------

            $log_sql = "
                INSERT INTO order_status_logs
                (
                    order_id,
                    status_id,
                    changed_by,
                    note
                )

                VALUES
                (
                    :order_id,
                    5,
                    :changed_by,
                    :note
                )
            ";

            $log_stmt = $pdo->prepare($log_sql);

            $log_stmt->execute([
                "order_id" =>
                    $order_id,

                "changed_by" =>
                    $_SESSION["user_id"],

                "note" =>
                    $cancel_reason
            ]);


            // -------------------------------------------------
            // Commit
            // -------------------------------------------------

            $pdo->commit();


            $success_message =
                "Order " .
                $order["order_number"] .
                " has been cancelled successfully.";


        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error_message =
                $e->getMessage();
        }
    }
}


// =========================================================
// GET USER ORDERS
// =========================================================

$recent_order_id = isset($_SESSION['recent_order_id']) ? (int) $_SESSION['recent_order_id'] : 0;
unset($_SESSION['recent_order_id']);
session_write_close();

$sql = "
    SELECT
        o.order_id,
        o.order_number, s.store_name, s.gcash_number, s.gcash_qr_path,
        o.order_datetime,
        o.total_amount,
        o.remarks,
        o.cancel_reason,
        o.status_id,

        COALESCE(os.status_name, 'Status unavailable') AS status_name,

        p.method AS payment_method,
        p.payment_status,
        p.reference_number, p.receipt_path, p.receipt_submitted_at, p.payment_rejected_at, p.payment_rejection_reason

    FROM orders o JOIN stores s ON s.store_id=o.store_id

    LEFT JOIN order_statuses os
        ON o.status_id = os.status_id

    LEFT JOIN payments p
        ON o.order_id = p.order_id

    WHERE o.student_id = :student_id

    ORDER BY
        CASE WHEN o.status_id IN (1, 2, 3) THEN 0 ELSE 1 END,
        o.order_datetime DESC, o.order_id DESC
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    "student_id" => $_SESSION["user_id"]
]);

$orders = $stmt->fetchAll();


// =========================================================
// GET ORDER ITEMS
// =========================================================

$order_items = [];

if (!empty($orders)) {

    $items_sql = "
        SELECT
            oi.order_id,
            oi.item_id,
            oi.quantity,
            oi.unit_price,
            oi.subtotal,
            COALESCE(
                m.item_name,
                CONCAT('Unavailable item #', oi.item_id)
            ) AS item_name

        FROM order_items oi

        LEFT JOIN menu_items m
            ON oi.item_id = m.item_id

        INNER JOIN orders o
            ON oi.order_id = o.order_id

        WHERE o.student_id = :student_id

        ORDER BY
            oi.order_item_id ASC
    ";

    $items_stmt = $pdo->prepare($items_sql);

    $items_stmt->execute([
        "student_id" => $_SESSION["user_id"]
    ]);

    $all_items = $items_stmt->fetchAll();


    foreach ($all_items as $item) {

        $order_id =
            (int) $item["order_id"];

        if (!isset($order_items[$order_id])) {
            $order_items[$order_id] = [];
        }

        $order_items[$order_id][] = $item;
    }
}


// =========================================================
// CART COUNT
// =========================================================

$cart_count = 0;

if (
    isset($_SESSION["cart"]) &&
    is_array($_SESSION["cart"])
) {

    foreach ($_SESSION["cart"] as $quantity) {

        $cart_count +=
            (int) $quantity;
    }
}

$ordersVersion = hash('sha256', json_encode([(int) $_SESSION['user_id'], $ordersCsrf, $orders, $order_items]));
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['sync'] ?? '') === '1'
    && is_string($_GET['version'] ?? null) && hash_equals($ordersVersion, $_GET['version'])) {
    http_response_code(204);
    exit;
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
        CampusBite - My Orders
    </title>


    <style>

        /* =====================================================
           GLOBAL
        ===================================================== */

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: inherit;
        }

        body {
            background: #f7f7f7;
            color: #222;
        }


        /* =====================================================
           NAVBAR
        ===================================================== */

        .navbar {

            background: white;

            border-bottom:
                1px solid #e5e5e5;

            padding:
                15px 5%;

            display: flex;

            align-items: center;

            justify-content:
                space-between;

            position: sticky;

            top: 0;

            z-index: 100;
        }

        .logo {

            color: #ff7a00;

            font-size: 25px;

            font-weight: bold;

            text-decoration: none;
        }

        .nav-links {

            display: flex;

            align-items: center;

            gap: 20px;
        }

        .nav-links a {

            color: #333;

            text-decoration: none;

            font-weight: 600;
        }

        .nav-links a:hover {

            color: #ff7a00;
        }

        .cart-link {

            background:
                #ff7a00;

            color:
                white !important;

            padding:
                9px 14px;

            border-radius:
                8px;
        }

        .logout {

            border:
                1px solid #ff7a00;

            color:
                #ff7a00 !important;

            padding:
                8px 14px;

            border-radius:
                8px;
        }

        .logout:hover {

            background:
                #ff7a00;

            color:
                white !important;
        }


        /* =====================================================
           CONTAINER
        ===================================================== */

        .container {

            width:
                90%;

            max-width:
                1100px;

            margin:
                35px auto;
        }

        .page-title {

            margin-bottom:
                25px;
        }

        .page-title h1 {

            font-size:
                32px;

            margin-bottom:
                8px;
        }

        .page-title p {

            color:
                #777;

            font-size:
                15px;
        }


        /* =====================================================
           MESSAGES
        ===================================================== */

        .success-message {

            background:
                #e8f7e8;

            color:
                #2e7d32;

            padding:
                13px 15px;

            border-radius:
                8px;

            margin-bottom:
                20px;
        }

        .error-message {

            background:
                #ffe5e5;

            color:
                #c62828;

            padding:
                13px 15px;

            border-radius:
                8px;

            margin-bottom:
                20px;
        }


        /* =====================================================
           ORDER CARD
        ===================================================== */

        .order-card {

            background:
                white;

            border-radius:
                15px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.06);

            margin-bottom:
                20px;

            overflow:
                hidden;
        }


        /* =====================================================
           ORDER HEADER
        ===================================================== */

        .order-header {

            padding:
                20px;

            border-bottom:
                1px solid #eeeeee;

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            gap:
                15px;
        }

        .order-number {

            font-size:
                19px;

            font-weight:
                bold;
        }

        .order-date {

            color:
                #777;

            font-size:
                13px;

            margin-top:
                5px;
        }


        /* =====================================================
           STATUS
        ===================================================== */

        .status-badge {

            display:
                inline-block;

            padding:
                7px 11px;

            border-radius:
                20px;

            font-size:
                12px;

            font-weight:
                bold;

            white-space:
                nowrap;
        }

        .status-pending {

            background:
                #fff3cd;

            color:
                #856404;
        }

        .status-preparing {

            background:
                #e3f2fd;

            color:
                #1565c0;
        }

        .status-ready {

            background:
                #e8f5e9;

            color:
                #2e7d32;
        }

        .status-completed {

            background:
                #d1e7dd;

            color:
                #0f5132;
        }

        .status-cancelled {

            background:
                #f8d7da;

            color:
                #842029;
        }

        .status-unknown {

            background:
                #f1f1f1;

            color:
                #666;
        }


        /* =====================================================
           ORDER CONTENT
        ===================================================== */

        .order-content {

            padding:
                20px;
        }


        /* =====================================================
           ITEMS
        ===================================================== */

        .items-title {

            font-size:
                16px;

            margin-bottom:
                12px;
        }

        .order-item {

            display:
                flex;

            justify-content:
                space-between;

            gap:
                15px;

            padding:
                10px 0;

            border-bottom:
                1px solid #f0f0f0;
        }

        .order-item:last-child {

            border-bottom:
                none;
        }

        .item-name {

            font-weight:
                bold;
        }

        .item-quantity {

            color:
                #777;

            font-size:
                13px;

            margin-top:
                4px;
        }

        .item-subtotal {

            font-weight:
                bold;

            white-space:
                nowrap;
        }


        /* =====================================================
           DETAILS
        ===================================================== */

        .order-details {

            display:
                grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap:
                15px;

            margin-top:
                18px;

            padding-top:
                18px;

            border-top:
                1px solid #eeeeee;
        }

        .detail-box {

            background:
                #f8f8f8;

            padding:
                14px;

            border-radius:
                8px;
        }

        .detail-label {

            color:
                #777;

            font-size:
                12px;

            margin-bottom:
                5px;
        }

        .detail-value {

            font-weight:
                bold;

            font-size:
                14px;

            word-break:
                break-word;
        }


        /* =====================================================
           CANCEL REASON
        ===================================================== */

        .cancel-box {

            background:
                #fff5f5;

            border-left:
                4px solid #d32f2f;

            padding:
                12px;

            margin-top:
                15px;

            border-radius:
                4px;

            font-size:
                13px;
        }

        .cancel-box strong {

            color:
                #c62828;
        }


        /* =====================================================
           ORDER FOOTER
        ===================================================== */

        .order-footer {

            padding:
                16px 20px;

            background:
                #fafafa;

            border-top:
                1px solid #eeeeee;

            display:
                flex;

            align-items:
                center;

            justify-content:
                space-between;

            gap:
                15px;
        }

        .total-label {

            color:
                #777;

            font-size:
                13px;
        }

        .total-amount {

            color:
                #ff7a00;

            font-size:
                22px;

            font-weight:
                bold;

            margin-top:
                3px;
        }


        /* =====================================================
           CANCEL FORM
        ===================================================== */

        .cancel-form {

            display:
                flex;

            align-items:
                center;

            gap:
                8px;
        }

        .cancel-reason-input {

            width:
                190px;

            padding:
                9px 10px;

            border:
                1px solid #ccc;

            border-radius:
                7px;

            font-size:
                13px;
        }

        .cancel-button {

            border:
                1px solid #d32f2f;

            background:
                white;

            color:
                #d32f2f;

            padding:
                9px 12px;

            border-radius:
                7px;

            font-weight:
                bold;

            cursor:
                pointer;
        }

        .cancel-button:hover {

            background:
                #fff0f0;
        }

        .cancel-confirmation-overlay {
            position: fixed;
            inset: 0;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0, 0, 0, 0.55);
        }

        .cancel-confirmation-overlay[hidden] {
            display: none;
        }

        .cancel-confirmation-dialog {
            width: 100%;
            max-width: 440px;
            padding: 28px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.25);
        }

        .cancel-confirmation-dialog h2 {
            margin-bottom: 12px;
            color: #222;
        }

        .cancel-confirmation-dialog p {
            margin-bottom: 22px;
            color: #666;
            line-height: 1.5;
        }

        .cancel-confirmation-actions {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 10px;
        }

        .cancel-confirmation-actions button {
            margin: 0;
            padding: 11px 16px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
        }

        .keep-order-button {
            border: 1px solid #ddd;
            background: #fff;
            color: #333;
        }

        .keep-order-button:hover {
            background: #f5f5f5;
        }

        .confirm-cancel-button {
            border: 1px solid #d32f2f;
            background: #d32f2f;
            color: #fff;
        }

        .confirm-cancel-button:hover {
            background: #b71c1c;
        }


        /* =====================================================
           EMPTY
        ===================================================== */

        .empty-orders {

            background:
                white;

            border-radius:
                15px;

            box-shadow:
                0 4px 15px
                rgba(0, 0, 0, 0.06);

            padding:
                60px 25px;

            text-align:
                center;
        }

        .empty-icon {

            font-size:
                60px;

            margin-bottom:
                15px;
        }

        .empty-orders h2 {

            margin-bottom:
                10px;
        }

        .empty-orders p {

            color:
                #777;

            margin-bottom:
                20px;
        }

        .menu-button {

            display:
                inline-block;

            background:
                #ff7a00;

            color:
                white;

            text-decoration:
                none;

            padding:
                12px 20px;

            border-radius:
                8px;

            font-weight:
                bold;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 800px) {

            .order-details {

                grid-template-columns:
                    1fr 1fr;
            }

            .order-footer {

                flex-direction:
                    column;

                align-items:
                    stretch;
            }

            .cancel-form {

                width:
                    100%;
            }

            .cancel-reason-input {

                flex:
                    1;

                width:
                    auto;
            }
        }


        @media (max-width: 650px) {

            .nav-links {

                gap:
                    8px;
            }

            .nav-links a:not(.cart-link):not(.logout) {

                display:
                    none;
            }

            .cart-link,
            .logout {

                font-size:
                    13px;

                padding:
                    8px 10px;
            }

            .container {

                width:
                    92%;

                margin:
                    25px auto;
            }

            .page-title h1 {

                font-size:
                    27px;
            }

            .order-header {

                flex-direction:
                    column;

                align-items:
                    flex-start;
            }

            .order-details {

                grid-template-columns:
                    1fr;
            }

            .cancel-form {

                flex-direction:
                    column;

                align-items:
                    stretch;
            }

            .cancel-reason-input {

                width:
                    100%;

                flex:
                    none;
            }

            .cancel-button {

                width:
                    100%;
            }
        }

    </style>

    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="student-pages.css?v=1">
<link rel="stylesheet" href="stores.css?v=1">
    <link rel="stylesheet" href="orders-refresh.css?v=<?= (int) filemtime(__DIR__ . '/orders-refresh.css') ?>">
    <script src="orders-refresh.js?v=<?= (int) filemtime(__DIR__ . '/orders-refresh.js') ?>" defer></script>
    <link rel="stylesheet" href="payment-receipts.css?v=<?= (int) filemtime(__DIR__ . '/payment-receipts.css') ?>">
    <script src="payment-receipts.js?v=<?= (int) filemtime(__DIR__ . '/payment-receipts.js') ?>" defer></script>
    <link rel="stylesheet" href="payment-confirmation.css?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.css') ?>">
    <script src="payment-confirmation.js?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.js') ?>" defer></script>
</head>


<body class="cb-student cb-orders" data-orders-user="<?= (int) $_SESSION['user_id'] ?>" data-orders-version="<?= cbEscape($ordersVersion) ?>">


<!-- =====================================================
     NAVIGATION
===================================================== -->

<?php require __DIR__ . "/components/student-navbar.php"; ?>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="container">


    <div class="page-title">
        <span class="cb-page-eyebrow">PLACED. PREPARED. PICKED UP.</span>

        <h1>
            My <span>orders.</span>
        </h1>

        <p>
            View your orders, payment details,
            and order status.
        </p>

    </div>


    <?php if (!empty($success_message)): ?>

        <div class="success-message" role="status" aria-live="polite">

            <?= htmlspecialchars(
                $success_message
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($error_message)): ?>

        <div class="error-message" role="alert">

            <?= htmlspecialchars(
                $error_message
            ) ?>

        </div>

    <?php endif; ?>


    <div class="orders-toolbar">
        <div class="orders-filters" role="group" aria-label="Filter orders">
            <button type="button" data-order-filter="all" aria-pressed="true">All orders</button>
            <button type="button" data-order-filter="active" aria-pressed="false">Active</button>
            <button type="button" data-order-filter="completed" aria-pressed="false">Completed</button>
            <button type="button" data-order-filter="cancelled" aria-pressed="false">Cancelled</button>
        </div>
        <span id="orders-sync-status" role="status" aria-live="polite">Live order updates</span>
    </div>
    <div id="orders-live-list">
    <?php if (empty($orders)): ?>


        <!-- =================================================
             EMPTY ORDERS
        ================================================== -->

        <div class="empty-orders">

            <div class="empty-icon">
                📋
            </div>

            <h2>
                No orders yet
            </h2>

            <p>
                Your food orders will appear here
                after you place one.
            </p>

            <a
                href="menu.php"
                class="menu-button"
            >
                Browse Menu
            </a>

        </div>


    <?php else: ?>


        <!-- =================================================
             ORDERS
        ================================================== -->

        <?php foreach ($orders as $order): ?>

            <?php

            $status =
                strtolower(
                    $order["status_name"]
                );


            if ($status === "pending") {

                $status_class =
                    "status-pending";

            } elseif ($status === "preparing") {

                $status_class =
                    "status-preparing";

            } elseif (
                $status === "ready for pickup"
            ) {

                $status_class =
                    "status-ready";

            } elseif ($status === "completed") {

                $status_class =
                    "status-completed";

            } elseif ($status === "status unavailable") {

                $status_class =
                    "status-unknown";

            } else {

                $status_class =
                    "status-cancelled";
            }


            $can_cancel =
                (int) $order["status_id"] === 1 && !cbReceiptPath($order['receipt_path'] ?? null) &&
                (
                    $order["payment_status"] === null ||
                    $order["payment_status"] === "Unpaid"
                );

            ?>


            <?php
            $statusId = (int) $order['status_id'];
            $orderGroup = in_array($statusId, [1,2,3], true) ? 'active' : ($statusId === 4 ? 'completed' : ($statusId === 5 ? 'cancelled' : 'unknown'));
            $summaryItems = $order_items[(int) $order['order_id']] ?? [];
            $itemCount = array_sum(array_column($summaryItems, 'quantity'));
            $previewNames = implode(', ', array_slice(array_column($summaryItems, 'item_name'), 0, 2));
            ?>
            <article class="order-card" data-order-id="<?= (int) $order['order_id'] ?>" data-order-group="<?= $orderGroup ?>">


                <!-- =========================================
                     HEADER
                ========================================== -->

                <div class="order-header">

                    <div>

                        <div class="order-number">

                            <?= htmlspecialchars(
                                $order["order_number"]
                            ) ?>

                        </div>

                        <span class="cb-food-store"><?= cbEscape($order["store_name"]) ?></span>
                        <div class="order-date">

                            <?= htmlspecialchars(
                                date(
                                    "F j, Y g:i A",
                                    strtotime(
                                        $order["order_datetime"]
                                    )
                                )
                            ) ?>

                        </div>

                    </div>


                    <div>

                        <span
                            class="status-badge
                            <?= $status_class ?>"
                        >

                            <?= htmlspecialchars(
                                $order["status_name"]
                            ) ?>

                        </span>

                    </div>

                </div>


                <!-- =========================================
                     CONTENT
                ========================================== -->

                <div class="order-overview">
                    <div><strong><?= (int) $itemCount ?> <?= $itemCount === 1 ? 'item' : 'items' ?></strong><p><?= cbEscape($previewNames ?: 'View order details') ?><?= count($summaryItems) > 2 ? ' and more' : '' ?></p></div>
                    <strong class="order-overview-total">PHP <?= number_format((float) $order['total_amount'], 2) ?></strong>
                </div>
                <?php if (in_array($orderGroup, ['active', 'completed'], true)): ?>
                    <ol class="order-progress" aria-label="Order progress: <?= cbEscape($order['status_name']) ?>">
                        <?php foreach ([1=>'Placed',2=>'Preparing',3=>'Ready to pickup',4=>'Completed'] as $step=>$label): ?>
                            <li class="<?= $step <= $statusId ? 'is-reached' : '' ?>" <?= $step === $statusId && $orderGroup === 'active' ? 'aria-current="step"' : '' ?>><span aria-hidden="true"><?= $step < $statusId || $orderGroup === 'completed' ? '&#10003;' : $step ?></span><?= $label ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="order-pickup-note">
                        <?php if ($statusId === 4): ?>Your order has been completed. Thank you for ordering from <?= cbEscape($order['store_name']) ?>.
                        <?php elseif ($statusId === 3): ?>Your food is ready. Pick it up at <?= cbEscape($order['store_name']) ?>.
                        <?php elseif ($statusId === 2): ?><?= cbEscape($order['store_name']) ?> is preparing your food. Watch here for pickup updates.
                        <?php else: ?>Your order has been placed with <?= cbEscape($order['store_name']) ?>. Waiting for the kitchen to start preparing it.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($order['payment_status'] === 'Paid' && empty($order['payment_rejected_at']) && $statusId !== 5): ?>
                    <div class="order-payment-confirmed" data-payment-confirmed="<?= (int) $order['order_id'] ?>">
                        <strong>Payment confirmed</strong>
                        <p><?= cbEscape($order['store_name']) ?> has confirmed your payment.</p>
                    </div>
                <?php endif; ?>
                <?php require __DIR__ . '/components/gcash-payment-proof.php'; ?>
                <details class="order-expand" data-order-details="<?= (int) $order['order_id'] ?>">
                    <summary><span>View details</span><span class="order-expand-close">Hide details</span><span aria-hidden="true" class="order-expand-arrow">&#8964;</span></summary>
                <div class="order-content">


                    <h3 class="items-title">

                        Order Items

                    </h3>


                    <?php
                    $current_order_id =
                        (int) $order["order_id"];

                    $items =
                        $order_items[
                            $current_order_id
                        ] ?? [];
                    ?>


                    <?php if (!empty($items)): ?>

                        <?php foreach ($items as $item): ?>

                            <div class="order-item">

                                <div>

                                    <div class="item-name">

                                        <?= htmlspecialchars(
                                            $item["item_name"]
                                        ) ?>

                                    </div>

                                    <div class="item-quantity">

                                        Quantity:
                                        <?= (int)
                                            $item["quantity"] ?>

                                        ×

                                        ₱<?= number_format(
                                            (float)
                                            $item["unit_price"],
                                            2
                                        ) ?>

                                    </div>

                                </div>


                                <div class="item-subtotal">

                                    ₱<?= number_format(
                                        (float)
                                        $item["subtotal"],
                                        2
                                    ) ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <p style="color:#777;">
                            No order items found.
                        </p>

                    <?php endif; ?>


                    <!-- =====================================
                         DETAILS
                    ====================================== -->

                    <div class="order-details">


                        <div class="detail-box">

                            <div class="detail-label">
                                Payment Method
                            </div>

                            <div class="detail-value">

                                <?= htmlspecialchars(
                                    $order["payment_method"]
                                    ?? "Not available"
                                ) ?>

                            </div>

                        </div>


                        <div class="detail-box">

                            <div class="detail-label">
                                Payment Status
                            </div>

                            <div class="detail-value" data-payment-status>

                                <?= cbEscape(!empty($order['payment_rejected_at']) ? 'Rejected' : ($order['payment_status'] === 'Unpaid' && cbReceiptPath($order['receipt_path'] ?? null) ? 'Awaiting verification' : ($order['payment_status'] ?? 'Not available'))) ?>

                            </div>

                        </div>


                        <div class="detail-box">

                            <div class="detail-label">
                                GCash Reference
                            </div>

                            <div class="detail-value" data-gcash-reference>

                                <?php if (
                                    !empty(
                                        $order["reference_number"]
                                    )
                                    && cbRealGcashReference($order['reference_number'])
                                ): ?>

                                    <?= htmlspecialchars(
                                        $order["reference_number"]
                                    ) ?>

                                <?php else: ?>

                                    <?= $order['payment_method'] === 'GCash' ? 'Not submitted' : 'N/A' ?>

                                <?php endif; ?>

                            </div>

                        </div>


                    </div>


                    <!-- =====================================
                         REMARKS
                    ====================================== -->

                    <?php if (
                        !empty(
                            $order["remarks"]
                        )
                    ): ?>

                        <div
                            style="
                                margin-top:15px;
                                padding:12px;
                                background:#f8f8f8;
                                border-radius:8px;
                                font-size:13px;
                            "
                        >

                            <strong>
                                Remarks:
                            </strong>

                            <?= htmlspecialchars(
                                $order["remarks"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <!-- =====================================
                         CANCEL REASON
                    ====================================== -->

                    <?php if (
                        !empty(
                            $order["cancel_reason"]
                        )
                    ): ?>

                        <div class="cancel-box">

                            <strong>
                                Cancellation Reason:
                            </strong>

                            <?= htmlspecialchars(
                                $order["cancel_reason"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                </div>


                <!-- =========================================
                     FOOTER
                ========================================== -->

                <div class="order-footer">


                    <div>

                        <div class="total-label">
                            Total Amount
                        </div>

                        <div class="total-amount">

                            ₱<?= number_format(
                                (float)
                                $order["total_amount"],
                                2
                            ) ?>

                        </div>

                    </div>


                    <?php if ($can_cancel): ?>

                        <form
                            method="POST"
                            class="cancel-form"
                        >
                            <input type="hidden" name="csrf_token" value="<?= cbEscape($ordersCsrf) ?>">

                            <input
                                type="hidden"
                                name="action"
                                value="cancel_order"
                            >

                            <input
                                type="hidden"
                                name="order_id"
                                value="<?= (int)
                                    $order["order_id"] ?>"
                            >

                            <input
                                type="text"
                                name="cancel_reason"
                                class="cancel-reason-input"
                                aria-label="Cancellation reason (optional)"
                                maxlength="255"
                                placeholder="Reason (optional)"
                            >

                            <button
                                type="button"
                                class="cancel-button"
                                data-open-cancel-confirmation
                            >
                                Cancel Order
                            </button>

                        </form>

                    <?php endif; ?>


                </div>

                </details>
            </article>

        <?php endforeach; ?>

    <?php endif; ?>

    </div>
    <p id="orders-filter-empty" class="empty-orders" role="status" hidden>No orders in this category yet.</p>
        <div
            class="cancel-confirmation-overlay"
            id="cancelConfirmation"
            hidden
        >
            <div
                class="cancel-confirmation-dialog"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="cancelConfirmationTitle"
                aria-describedby="cancelConfirmationMessage"
                tabindex="-1"
            >
                <h2 id="cancelConfirmationTitle">
                    Cancel this order?
                </h2>

                <p id="cancelConfirmationMessage">
                    This will cancel your order. Do you want to continue?
                </p>

                <div class="cancel-confirmation-actions">
                    <button
                        type="button"
                        class="keep-order-button"
                        id="keepOrder"
                    >
                        Keep Order
                    </button>

                    <button
                        type="button"
                        class="confirm-cancel-button"
                        id="confirmCancelOrder"
                    >
                        Yes, Cancel Order
                    </button>
                </div>
            </div>
        </div>

</main>

<div id="payment-proof-notice" class="payment-proof-notice" role="status" aria-live="polite" aria-atomic="true" hidden></div>

<script>
        const cancelConfirmation =
            document.getElementById("cancelConfirmation");
        const keepOrderButton =
            document.getElementById("keepOrder");
        const confirmCancelButton =
            document.getElementById("confirmCancelOrder");
        let activeCancelForm = null;

        function closeCancelConfirmation() {
            cancelConfirmation.hidden = true;
            activeCancelForm = null;
        }

        document.addEventListener("click", function (event) {
            const button = event.target.closest("[data-open-cancel-confirmation]");
            if (button) {
                    activeCancelForm = button.closest("form");
                    cancelConfirmation.hidden = false;
                    cancelConfirmation
                        .querySelector(".cancel-confirmation-dialog")
                        .focus();
            }
        });

        keepOrderButton.addEventListener(
            "click",
            closeCancelConfirmation
        );

        confirmCancelButton.addEventListener("click", function () {
            if (activeCancelForm) {
                activeCancelForm.requestSubmit();
            }
        });

        cancelConfirmation.addEventListener("click", function (event) {
            if (event.target === cancelConfirmation) {
                closeCancelConfirmation();
            }
        });

        document.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && !cancelConfirmation.hidden) {
                closeCancelConfirmation();
            }
        });
</script>


<?php require __DIR__ . '/components/payment-confirmation.php'; ?>
</body>

</html>
