<?php

if (session_status() === PHP_SESSION_NONE) session_start();

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";


// =========================================================
// STAFF ACCESS CHECK
// =========================================================

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

if (
    !isset($_SESSION["role_name"]) ||
    $_SESSION["role_name"] !== "Canteen Staff"
) {
    header("Location: dashboard.php");
    exit;
}

$store = cbStaffStore($pdo);
$store_id = (int) $store["store_id"];
$staffToken = cbCsrf("staff_orders_csrf");
$staff_id = (int) $_SESSION["user_id"];

$staff_name =
    ($_SESSION["first_name"] ?? "Staff") .
    " " .
    ($_SESSION["last_name"] ?? "");

$success_message = "";
$error_message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && !cbCheckCsrf("staff_orders_csrf")) {
    $error_message = "Invalid request. Refresh and try again.";
    $_POST = [];
}


// =========================================================
// CONFIRM PAYMENT
// =========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "confirm_payment"
) {

    $order_id = (int) ($_POST["order_id"] ?? 0);

    if ($order_id <= 0) {

        $error_message = "Invalid order.";

    } else {

        try {

            $pdo->beginTransaction();

            $sql = "
                SELECT
                    o.order_id,
                    o.order_number,
                    o.status_id,
                    p.payment_id,
                    p.payment_status, p.method, p.reference_number, p.receipt_path, p.receipt_submitted_at
                FROM orders o
                INNER JOIN payments p
                    ON o.order_id = p.order_id
                WHERE o.order_id = :order_id AND o.store_id = :store_id
                LIMIT 1
                FOR UPDATE OF o
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "order_id" => $order_id,
                "store_id" => $store_id
            ]);

            $order = $stmt->fetch();

            if (!$order) {
                throw new Exception("Order or payment was not found.");
            }

            if ((int) $order["status_id"] !== 1) {
                throw new Exception(
                    "Only pending orders can be confirmed."
                );
            }

            if ($order["payment_status"] !== "Unpaid") {
                throw new Exception(
                    "This payment is already confirmed."
                );
            }
            if ($order['method'] === 'GCash' && (!cbRealGcashReference($order['reference_number']) || !cbReceiptPath($order['receipt_path'] ?? null))) {
                throw new Exception('The customer must submit their actual GCash reference number and receipt before payment can be confirmed.');
            }
            if ($order['method'] === 'GCash' && !hash_equals(cbReceiptReviewToken($order), is_string($_POST['review_token'] ?? null) ? $_POST['review_token'] : '')) {
                throw new Exception('The payment details changed. Refresh and review the latest receipt before confirming.');
            }


            $update_sql = "
                UPDATE payments
                SET
                    payment_status = 'Paid',
                    received_by = :received_by,
                    paid_at = NOW()
                WHERE payment_id = :payment_id
                AND payment_status = 'Unpaid'
            ";

            $update_stmt = $pdo->prepare($update_sql);

            $update_stmt->execute([
                "received_by" => $staff_id,
                "payment_id" => $order["payment_id"]
            ]);

            if ($update_stmt->rowCount() === 0) {
                throw new Exception(
                    "Payment could not be confirmed."
                );
            }


            $pdo->commit();

            $success_message =
                "Payment for " .
                $order["order_number"] .
                " has been confirmed.";

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error_message = $e->getMessage();
        }
    }
}


// =========================================================
// REJECT AN UNVERIFIED GCASH PAYMENT AND CANCEL ITS ORDER
// =========================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reject_payment') {
    try {
        $pdo->beginTransaction();
        $rejectedNumber = cbRejectGcashPayment($pdo, $staff_id, $store_id, (int) ($_POST['order_id'] ?? 0),
            is_string($_POST['reject_reason'] ?? null) ? $_POST['reject_reason'] : '',
            is_string($_POST['review_token'] ?? null) ? $_POST['review_token'] : '');
        $pdo->commit();
        $success_message = $rejectedNumber . ' payment rejected. The order was cancelled.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof RuntimeException && !($exception instanceof PDOException)) $error_message = $exception->getMessage();
        else { error_log($exception->getMessage()); $error_message = 'Unable to reject this payment. Please try again.'; }
    }
}

// =========================================================
// UPDATE ORDER STATUS
// =========================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    ($_POST["action"] ?? "") === "update_status"
) {

    $order_id =
        (int) ($_POST["order_id"] ?? 0);

    $new_status_id =
        (int) ($_POST["status_id"] ?? 0);

    if (
        $order_id <= 0 ||
        !in_array($new_status_id, [2, 3, 4], true)
    ) {

        $error_message = "Invalid order status.";

    } else {

        try {

            $pdo->beginTransaction();

            $sql = "
                SELECT
                    o.order_id,
                    o.order_number,
                    o.status_id,
                    p.payment_status
                FROM orders o
                LEFT JOIN payments p
                    ON o.order_id = p.order_id
                WHERE o.order_id = :order_id AND o.store_id = :store_id
                LIMIT 1
                FOR UPDATE OF o
            ";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                "order_id" => $order_id,
                "store_id" => $store_id
            ]);

            $order = $stmt->fetch();

            if (!$order) {
                throw new Exception("Order not found.");
            }

            if ($order["payment_status"] !== "Paid") {
                throw new Exception(
                    "Payment must be confirmed before the order can be prepared."
                );
            }


            $current_status =
                (int) $order["status_id"];

            $valid_transition = false;

            if (
                $current_status === 1 &&
                $new_status_id === 2
            ) {
                $valid_transition = true;
            }

            if (
                $current_status === 2 &&
                $new_status_id === 3
            ) {
                $valid_transition = true;
            }

            if (
                $current_status === 3 &&
                $new_status_id === 4
            ) {
                $valid_transition = true;
            }

            if (!$valid_transition) {
                throw new Exception(
                    "Invalid order status transition."
                );
            }


            $update_sql = "
                UPDATE orders
                SET
                    status_id = :status_id,
                    staff_id = :staff_id
                WHERE order_id = :order_id AND store_id = :store_id
            ";

            $update_stmt = $pdo->prepare($update_sql);

            $update_stmt->execute([
                "status_id" => $new_status_id,
                "staff_id" => $staff_id,
                "order_id" => $order_id,
                "store_id" => $store_id
            ]);


            $status_names = [
                2 => "Preparing",
                3 => "Ready for Pickup",
                4 => "Completed"
            ];

            $note =
                "Order status changed to " .
                $status_names[$new_status_id];


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
                    :status_id,
                    :changed_by,
                    :note
                )
            ";

            $log_stmt = $pdo->prepare($log_sql);

            $log_stmt->execute([
                "order_id" => $order_id,
                "status_id" => $new_status_id,
                "changed_by" => $staff_id,
                "note" => $note
            ]);


            $pdo->commit();

            $success_message =
                $order["order_number"] .
                " is now " .
                $status_names[$new_status_id] .
                ".";

        } catch (Exception $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $error_message = $e->getMessage();
        }
    }
}


// =========================================================
// DASHBOARD COUNTS
// =========================================================

session_write_close();

$count_sql = "
    SELECT

        COALESCE(
            SUM(
                CASE
                    WHEN status_id = 1
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS pending_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status_id = 2
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS preparing_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status_id = 3
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS ready_count,

        COALESCE(
            SUM(
                CASE
                    WHEN status_id = 4
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS completed_count

    FROM orders WHERE store_id = :store_id
";

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute(["store_id" => $store_id]);

$counts = $count_stmt->fetch();


$pending_count =
    (int) $counts["pending_count"];

$preparing_count =
    (int) $counts["preparing_count"];

$ready_count =
    (int) $counts["ready_count"];

$completed_count =
    (int) $counts["completed_count"];


// =========================================================
// GET RECENT ORDERS
// =========================================================

$orders_sql = "
    SELECT

        o.order_id,
        o.order_number,
        o.order_datetime,
        o.total_amount,
        o.remarks, o.cancel_reason,
        o.status_id,

        os.status_name,

        u.first_name,
        u.last_name,
        u.email,

        p.method AS payment_method,
        p.payment_status,
        p.reference_number, p.receipt_path, p.receipt_submitted_at, p.payment_rejected_at, p.payment_rejection_reason

    FROM orders o

    INNER JOIN order_statuses os
        ON o.status_id = os.status_id

    INNER JOIN users u
        ON o.student_id = u.user_id

    LEFT JOIN payments p
        ON o.order_id = p.order_id

    WHERE o.store_id = :store_id
    ORDER BY
        o.order_datetime DESC, o.order_id DESC

    LIMIT 50
";

$orders_stmt = $pdo->prepare($orders_sql);
$orders_stmt->execute(["store_id" => $store_id]);

$orders = $orders_stmt->fetchAll();


// =========================================================
// GET ORDER ITEMS
// =========================================================

$order_items = [];

if (!empty($orders)) {

    $items_sql = "
        SELECT

            oi.order_id,
            oi.quantity,
            oi.unit_price,
            oi.subtotal,
            m.item_name

        FROM order_items oi

        INNER JOIN menu_items m
            ON oi.item_id = m.item_id

        JOIN orders scoped_order ON scoped_order.order_id=oi.order_id
        WHERE scoped_order.store_id=:store_id
          AND oi.order_id IN (" . implode(',', array_map(static fn($order) => (int) $order['order_id'], $orders)) . ")
        ORDER BY
            oi.order_item_id ASC
    ";

    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute(["store_id" => $store_id]);

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

$staffVersion = hash('sha256', json_encode([$staff_id, $store_id, $staffToken, $store, $counts, $orders, $order_items]));
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['sync'] ?? '') === '1'
    && is_string($_GET['version'] ?? null) && hash_equals($staffVersion, $_GET['version'])) {
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
        CampusBite - Staff Dashboard
    </title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f6f6f6;
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
                14px 5%;

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            position:
                sticky;

            top:
                0;

            z-index:
                100;
        }


        .brand-section {
            display: flex;
            align-items: center;
            gap: 25px;
        }


        .logo {

            color:
                #ff7a00;

            font-size:
                25px;

            font-weight:
                bold;

            text-decoration:
                none;
        }


        .staff-name {

            color:
                #777;

            font-size:
                13px;
        }


        .nav-links {

            display:
                flex;

            align-items:
                center;

            gap:
                8px;
        }


        .nav-links a {

            text-decoration:
                none;

            color:
                #333;

            font-weight:
                600;

            padding:
                8px 12px;

            border-radius:
                7px;
        }


        .nav-links a:hover {

            background:
                #fff3e7;

            color:
                #ff7a00;
        }


        .nav-links a.active {

            background:
                #ff7a00;

            color:
                white;
        }


        .logout {

            border:
                1px solid #ff7a00;
        }


        .logout:hover {

            background:
                #ff7a00 !important;

            color:
                white !important;
        }


        /* =====================================================
           MAIN
        ===================================================== */

        .container {

            width:
                90%;

            max-width:
                1250px;

            margin:
                30px auto;
        }


        .header {

            margin-bottom:
                25px;
        }


        .header h1 {

            font-size:
                30px;

            margin-bottom:
                8px;
        }


        .header p {

            color:
                #777;
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
           STATISTICS
        ===================================================== */

        .stats {

            display:
                grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap:
                15px;

            margin-bottom:
                30px;
        }


        .stat-card {

            background:
                white;

            padding:
                20px;

            border-radius:
                12px;

            box-shadow:
                0 4px 15px
                rgba(0,0,0,0.06);
        }


        .stat-label {

            color:
                #777;

            font-size:
                13px;

            margin-bottom:
                8px;
        }


        .stat-number {

            font-size:
                28px;

            font-weight:
                bold;

            color:
                #ff7a00;
        }


        /* =====================================================
           ORDERS
        ===================================================== */

        .section-title {

            font-size:
                21px;

            margin-bottom:
                15px;
        }


        .order-card {

            background:
                white;

            border-radius:
                14px;

            box-shadow:
                0 4px 15px
                rgba(0,0,0,0.06);

            margin-bottom:
                18px;

            overflow:
                hidden;
        }


        .order-header {

            padding:
                18px 20px;

            border-bottom:
                1px solid #eee;

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
                18px;

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

        .status {

            padding:
                7px 11px;

            border-radius:
                20px;

            font-size:
                12px;

            font-weight:
                bold;
        }


        .pending {

            background:
                #fff3cd;

            color:
                #856404;
        }


        .preparing {

            background:
                #e3f2fd;

            color:
                #1565c0;
        }


        .ready {

            background:
                #e8f5e9;

            color:
                #2e7d32;
        }


        .completed {

            background:
                #d1e7dd;

            color:
                #0f5132;
        }


        .cancelled {

            background:
                #f8d7da;

            color:
                #842029;
        }


        /* =====================================================
           ORDER CONTENT
        ===================================================== */

        .order-content {

            padding:
                20px;
        }


        .customer-name {

            font-weight:
                bold;

            margin-bottom:
                3px;
        }


        .customer-email {

            color:
                #777;

            font-size:
                13px;

            margin-bottom:
                18px;
        }


        .item-row {

            display:
                flex;

            justify-content:
                space-between;

            gap:
                15px;

            padding:
                9px 0;

            border-bottom:
                1px solid #f0f0f0;
        }


        .item-row:last-child {

            border-bottom:
                none;
        }


        .item-name {

            font-weight:
                bold;
        }


        .item-qty {

            color:
                #777;

            font-size:
                13px;

            margin-top:
                3px;
        }


        .item-price {

            font-weight:
                bold;

            white-space:
                nowrap;
        }


        /* =====================================================
           INFORMATION
        ===================================================== */

        .information {

            display:
                grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap:
                12px;

            margin-top:
                18px;
        }


        .info-box {

            background:
                #f8f8f8;

            padding:
                12px;

            border-radius:
                8px;
        }


        .info-label {

            color:
                #777;

            font-size:
                12px;

            margin-bottom:
                5px;
        }


        .info-value {

            font-weight:
                bold;

            font-size:
                14px;

            word-break:
                break-word;
        }


        /* =====================================================
           ACTIONS
        ===================================================== */

        .actions {

            padding:
                16px 20px;

            background:
                #fafafa;

            border-top:
                1px solid #eee;

            display:
                flex;

            justify-content:
                space-between;

            align-items:
                center;

            gap:
                15px;
        }


        .payment-paid {

            color:
                #2e7d32;

            font-weight:
                bold;
        }


        .payment-unpaid {

            color:
                #c62828;

            font-weight:
                bold;
        }


        .action-group {

            display:
                flex;

            gap:
                8px;

            flex-wrap:
                wrap;
        }


        .action-button {

            border:
                none;

            border-radius:
                7px;

            padding:
                9px 13px;

            cursor:
                pointer;

            font-weight:
                bold;
        }


        .pay-button {

            background:
                #2e7d32;

            color:
                white;
        }


        .prepare-button {

            background:
                #1565c0;

            color:
                white;
        }


        .ready-button {

            background:
                #ff7a00;

            color:
                white;
        }


        .complete-button {

            background:
                #6a1b9a;

            color:
                white;
        }


        .action-button:hover {

            opacity:
                0.9;
        }


        /* =====================================================
           EMPTY
        ===================================================== */

        .empty {

            background:
                white;

            padding:
                50px 25px;

            text-align:
                center;

            border-radius:
                14px;

            color:
                #777;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

        @media (max-width: 950px) {

            .brand-section {

                gap:
                    12px;
            }


            .staff-name {

                display:
                    none;
            }


            .stats {

                grid-template-columns:
                    repeat(2, 1fr);
            }


            .information {

                grid-template-columns:
                    1fr 1fr;
            }
        }


        @media (max-width: 700px) {

            .navbar {

                flex-direction:
                    column;

                gap:
                    12px;

                align-items:
                    stretch;
            }


            .brand-section {

                justify-content:
                    space-between;
            }


            .nav-links {

                width:
                    100%;

                justify-content:
                    space-between;
            }


            .nav-links a {

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


            .stats {

                grid-template-columns:
                    1fr;
            }


            .order-header {

                flex-direction:
                    column;

                align-items:
                    flex-start;
            }


            .information {

                grid-template-columns:
                    1fr;
            }


            .actions {

                flex-direction:
                    column;

                align-items:
                    stretch;
            }


            .action-group {

                flex-direction:
                    column;

                width:
                    100%;
            }


            .action-group form {

                width:
                    100%;
            }


            .action-button {

                width:
                    100%;
            }
        }

    </style>

    <link rel="stylesheet" href="assets/order-tracking/tracking.css?v=1">
    <?php if ($orders): ?><script src="assets/order-tracking/tracking.js?v=<?= (int) filemtime(__DIR__ . '/assets/order-tracking/tracking.js') ?>" defer></script><?php endif; ?>
    <script src="staff-orders.js?v=<?= (int) filemtime(__DIR__ . '/staff-orders.js') ?>" defer></script>
    <script src="staff-orders-sync.js?v=<?= (int) filemtime(__DIR__ . '/staff-orders-sync.js') ?>" defer></script>
    <link rel="stylesheet" href="staff-orders.css?v=<?= (int) filemtime(__DIR__ . '/staff-orders.css') ?>">
    <link rel="stylesheet" href="payment-confirmation.css?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.css') ?>">
    <script src="payment-confirmation.js?v=<?= (int) filemtime(__DIR__ . '/payment-confirmation.js') ?>" defer></script>
    <link rel="stylesheet" href="payment-receipts.css?v=<?= (int) filemtime(__DIR__ . '/payment-receipts.css') ?>">
    <link rel="stylesheet" href="dashboard-commerce.css?v=1">
<link rel="stylesheet" href="stores.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="staff-navbar.css?v=<?= (int) filemtime(__DIR__ . '/staff-navbar.css') ?>">
</head>


<body class="cb-commerce" data-staff-orders-user="<?= $staff_id ?>" data-staff-orders-store="<?= $store_id ?>" data-staff-orders-version="<?= cbEscape($staffVersion) ?>">


<!-- =====================================================
     STAFF NAVIGATION
===================================================== -->

<?php require __DIR__ . '/components/staff-navbar.php'; ?>


<!-- =====================================================
     MAIN
===================================================== -->

<main class="container">

    <section class="cb-hero" aria-labelledby="cb-hero-title">
        <span class="cb-hero-kicker">THE CAMPUS KITCHEN</span>
        <h1 id="cb-hero-title"><span>Good food starts</span>with a smooth service.</h1>
        <p>Confirm payments, prepare orders, and keep every pickup moving.</p>
    </section>

<div id="staff-order-tracking">
<?php
require_once __DIR__ . "/components/order-tracking.php";
campusBiteTracking($pdo, $orders, true);
?>
</div>


    <div class="header">

        <h1>
            Staff Dashboard
        </h1>

        <p>
            Manage customer orders and payment confirmation.
        </p>

        <div class="cb-store-payment-summary">
            <span><strong>Accepted payments:</strong> <?= empty($store['gcash_number']) ? 'Cash' : 'Cash and GCash' ?></span>
            <a href="staff_menu.php#store-payments"><?= empty($store['gcash_number']) ? 'Set up GCash' : 'Manage payment methods' ?></a>
        </div>

    </div>


    <?php if (!empty($success_message)): ?>

        <div class="success-message" data-staff-result="success">

            <?= htmlspecialchars(
                $success_message
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($error_message)): ?>

        <div class="error-message" data-staff-result="error">

            <?= htmlspecialchars(
                $error_message
            ) ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         STATISTICS
    ====================================================== -->

    <section class="stats" id="staff-order-stats">


        <div class="stat-card">

            <div class="stat-label">
                Pending
            </div>

            <div class="stat-number">
                <?= $pending_count ?>
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Preparing
            </div>

            <div class="stat-number">
                <?= $preparing_count ?>
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Ready for Pickup
            </div>

            <div class="stat-number">
                <?= $ready_count ?>
            </div>

        </div>


        <div class="stat-card">

            <div class="stat-label">
                Completed
            </div>

            <div class="stat-number">
                <?= $completed_count ?>
            </div>

        </div>

    </section>


    <!-- =====================================================
         RECENT ORDERS
    ====================================================== -->

    <div class="staff-orders-toolbar">
    <h2 class="section-title">
        Recent Orders
    </h2>
    <div class="staff-orders-sync-controls">
        <span id="staff-orders-sync-status" role="status" aria-live="polite">Checking for new orders shortly.</span>
        <button id="staff-orders-sync-refresh" type="button">Check now</button>
    </div>
    </div>

    <div id="staff-orders-list">
    <noscript><p>Reload this page to see new orders and payment updates.</p></noscript>
    <?php if (empty($orders)): ?>

        <div class="empty">

            No orders have been placed yet.

        </div>

    <?php else: ?>


        <?php foreach ($orders as $order): ?>

            <?php

            $status =
                strtolower(
                    $order["status_name"]
                );


            if ($status === "pending") {

                $status_class =
                    "pending";

            } elseif ($status === "preparing") {

                $status_class =
                    "preparing";

            } elseif (
                $status === "ready for pickup"
            ) {

                $status_class =
                    "ready";

            } elseif (
                $status === "completed"
            ) {

                $status_class =
                    "completed";

            } else {

                $status_class =
                    "cancelled";
            }


            $order_id =
                (int) $order["order_id"];


            $items =
                $order_items[$order_id] ?? [];

            ?>


            <article class="order-card" data-staff-order="<?= $order_id ?>" tabindex="-1">


                <!-- ORDER HEADER -->

                <div class="order-header">

                    <div>

                        <div class="order-number">

                            <?= htmlspecialchars(
                                $order["order_number"]
                            ) ?>

                        </div>


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


                    <span
                        class="status
                        <?= $status_class ?>"
                    >

                        <?= htmlspecialchars(
                            $order["status_name"]
                        ) ?>

                    </span>

                </div>


                <!-- ORDER CONTENT -->

                <div class="order-content">


                    <div class="customer-name">

                        Customer:

                        <?= htmlspecialchars(
                            $order["first_name"] .
                            " " .
                            $order["last_name"]
                        ) ?>

                    </div>


                    <div class="customer-email">

                        <?= htmlspecialchars(
                            $order["email"]
                        ) ?>

                    </div>


                    <?php foreach ($items as $item): ?>

                        <div class="item-row">

                            <div>

                                <div class="item-name">

                                    <?= htmlspecialchars(
                                        $item["item_name"]
                                    ) ?>

                                </div>


                                <div class="item-qty">

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


                            <div class="item-price">

                                ₱<?= number_format(
                                    (float)
                                    $item["subtotal"],
                                    2
                                ) ?>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <div class="information">


                        <div class="info-box">

                            <div class="info-label">
                                Payment Method
                            </div>

                            <div class="info-value">

                                <?= htmlspecialchars(
                                    $order[
                                        "payment_method"
                                    ] ?? "N/A"
                                ) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                Payment Status
                            </div>

                            <div class="info-value">

                                <?= cbEscape(!empty($order['payment_rejected_at']) ? 'Rejected' : ($order['payment_status'] === 'Unpaid' && cbReceiptPath($order['receipt_path'] ?? null) ? 'Awaiting verification' : ($order['payment_status'] ?? 'N/A'))) ?>

                            </div>

                        </div>


                        <div class="info-box">

                            <div class="info-label">
                                GCash Reference
                            </div>

                            <div class="info-value">

                                <?= cbEscape(cbRealGcashReference($order['reference_number']) ? $order['reference_number'] : ($order['payment_method'] === 'GCash' ? 'Not submitted' : 'N/A')) ?>

                            </div>

                        </div>


                    </div>


                    <?php if ($order['payment_method'] === 'GCash'): ?>
                        <div class="gcash-staff-proof">
                            <?php if (!empty($order['payment_rejected_at'])): ?><p><strong>Payment rejected:</strong> <?= cbEscape($order['payment_rejection_reason']) ?></p><?php endif; ?>
                            <?php if (cbReceiptPath($order['receipt_path'] ?? null)): ?>
                                <a href="payment_receipt.php?order_id=<?= (int) $order['order_id'] ?>" target="_blank" rel="noopener">View customer's GCash receipt</a>
                                <p>Check the receiving account, amount, and reference number before confirming payment.</p>
                            <?php else: ?>
                                <p>Awaiting the customer's GCash reference number and receipt.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (
                        !empty(
                            $order["remarks"]
                        )
                    ): ?>

                        <div
                            style="
                                margin-top:15px;
                                background:#f8f8f8;
                                padding:12px;
                                border-radius:8px;
                                font-size:13px;
                            "
                        >

                            <strong>
                                Customer Remarks:
                            </strong>

                            <?= htmlspecialchars(
                                $order["remarks"]
                            ) ?>

                        </div>

                    <?php endif; ?>


                </div>


                <!-- ORDER ACTIONS -->

                <div class="actions">


                    <div>

                        Payment:

                        <?php if (
                            $order[
                                "payment_status"
                            ] === "Paid"
                        ): ?>

                            <span class="payment-paid">
                                Paid
                            </span>

                        <?php else: ?>

                            <span class="payment-unpaid">
                                <?= !empty($order['payment_rejected_at']) ? 'Rejected' : (cbReceiptPath($order['receipt_path'] ?? null) ? 'Awaiting verification' : 'Unpaid') ?>
                            </span>

                        <?php endif; ?>


                        <br>


                        <strong>

                            Total:
                            ₱<?= number_format(
                                (float)
                                $order[
                                    "total_amount"
                                ],
                                2
                            ) ?>

                        </strong>

                    </div>


                    <div class="action-group">


                        <!-- CONFIRM PAYMENT -->

                        <?php if (
                            $order[
                                "payment_status"
                            ] === "Unpaid" &&
                            (int)
                            $order["status_id"] === 1
                        ): ?>

                            <form method="POST" data-staff-order-action data-payment-confirm="verify" data-payment-method="<?= cbEscape($order['payment_method']) ?>" data-payment-store="<?= cbEscape($store['store_name']) ?>" data-payment-order="<?= cbEscape($order['order_number']) ?>" data-payment-reference="<?= cbEscape(cbRealGcashReference($order['reference_number']) ? $order['reference_number'] : '') ?>" data-payment-amount="<?= number_format((float) $order['total_amount'], 2, '.', '') ?>">
<input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">
<input type="hidden" name="review_token" value="<?= cbReceiptReviewToken($order) ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="confirm_payment"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= $order_id ?>"
                                >

                                <button
                                    type="submit"
                                    class="
                                        action-button
                                        pay-button
                                    "
                                >
                                    <?= $order['payment_method'] === 'GCash' ? 'Confirm GCash Payment' : 'Confirm Cash Payment' ?>
                                </button>

                            </form>

                        <?php endif; ?>


                        <!-- START PREPARING -->

                        <?php if ((int) $order['status_id'] === 1 && $order['payment_method'] === 'GCash' && $order['payment_status'] === 'Unpaid' && cbReceiptPath($order['receipt_path'] ?? null) && cbRealGcashReference($order['reference_number'])): ?>
                            <form method="POST" data-staff-order-action data-payment-confirm="reject" data-payment-store="<?= cbEscape($store['store_name']) ?>" data-payment-order="<?= cbEscape($order['order_number']) ?>" data-payment-reference="<?= cbEscape($order['reference_number']) ?>" data-payment-amount="<?= number_format((float) $order['total_amount'], 2, '.', '') ?>">
                                <input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">
                                <input type="hidden" name="action" value="reject_payment">
                                <input type="hidden" name="order_id" value="<?= $order_id ?>">
                                <input type="hidden" name="review_token" value="<?= cbReceiptReviewToken($order) ?>">
                                <input type="hidden" name="reject_reason" value="">
                                <button class="action-button reject-payment-button" type="submit">Reject payment / cancel order</button>
                            </form>
                        <?php endif; ?>

                        <?php if (
                            (int)
                            $order["status_id"] === 1 &&
                            $order[
                                "payment_status"
                            ] === "Paid"
                        ): ?>

                            <form method="POST" data-staff-order-action>
<input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_status"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= $order_id ?>"
                                >

                                <input
                                    type="hidden"
                                    name="status_id"
                                    value="2"
                                >

                                <button
                                    type="submit"
                                    class="
                                        action-button
                                        prepare-button
                                    "
                                >
                                    Start Preparing
                                </button>

                            </form>

                        <?php endif; ?>


                        <!-- READY FOR PICKUP -->

                        <?php if (
                            (int)
                            $order["status_id"] === 2
                        ): ?>

                            <form method="POST" data-staff-order-action>
<input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_status"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= $order_id ?>"
                                >

                                <input
                                    type="hidden"
                                    name="status_id"
                                    value="3"
                                >

                                <button
                                    type="submit"
                                    class="
                                        action-button
                                        ready-button
                                    "
                                >
                                    Ready for Pickup
                                </button>

                            </form>

                        <?php endif; ?>


                        <!-- COMPLETED -->

                        <?php if (
                            (int)
                            $order["status_id"] === 3
                        ): ?>

                            <form method="POST" data-staff-order-action>
<input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">

                                <input
                                    type="hidden"
                                    name="action"
                                    value="update_status"
                                >

                                <input
                                    type="hidden"
                                    name="order_id"
                                    value="<?= $order_id ?>"
                                >

                                <input
                                    type="hidden"
                                    name="status_id"
                                    value="4"
                                >

                                <button
                                    type="submit"
                                    class="
                                        action-button
                                        complete-button
                                    "
                                >
                                    Mark Completed
                                </button>

                            </form>

                        <?php endif; ?>


                    </div>

                </div>

            </article>

        <?php endforeach; ?>


    <?php endif; ?>

    </div>
</main>

<div class="staff-order-notice" id="staff-order-notice" role="status" aria-live="polite" aria-atomic="true" hidden></div>

<?php require __DIR__ . '/components/payment-confirmation.php'; ?>
</body>

</html>
