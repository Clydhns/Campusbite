<?php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "config/connection.php";
require_once __DIR__ . '/config/admin-stats.php';

// Only Administrator can access this page
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role_name"]) ||
    $_SESSION["role_name"] !== "Administrator"
) {
    header("Location: login.php");
    exit;
}

$adminName = ($_SESSION["first_name"] ?? "Admin") . " " . ($_SESSION["last_name"] ?? "");

// --------------------------------------------------
// DASHBOARD STATISTICS
// --------------------------------------------------

try {
    session_write_close();
    $stats = cbAdminStats($pdo);
    $totalUsers = (int) $stats['total_users'];
    $activeUsers = (int) $stats['active_users'];
    $totalStudents = (int) $stats['total_students'];
    $totalStaff = (int) $stats['total_staff'];
    $totalMenuItems = (int) $stats['total_menu_items'];
    $availableMenuItems = (int) $stats['available_menu_items'];
    $pendingOrders = (int) $stats['pending_orders'];
    $preparingOrders = (int) $stats['preparing_orders'];
    $readyOrders = (int) $stats['ready_orders'];
    $completedOrders = (int) $stats['completed_orders'];
    $cancelledOrders = (int) $stats['cancelled_orders'];
    $totalSales = (float) $stats['total_sales'];
    $todaySales = (float) $stats['today_sales'];
    $todayOrders = (int) $stats['today_orders'];

} catch (PDOException $e) {
    die("Dashboard error: " . htmlspecialchars($e->getMessage()));
}

// --------------------------------------------------
// RECENT ORDERS
// --------------------------------------------------

try {
    $stmt = $pdo->query("
        SELECT
            o.order_id,
            o.order_number,
            o.total_amount,
            o.order_datetime,
            u.first_name,
            u.last_name,
            os.status_name,
            COALESCE(
                (
                    SELECT SUM(oi.quantity)
                    FROM order_items oi
                    WHERE oi.order_id = o.order_id
                ),
                0
            ) AS item_count,
            (
                SELECT p.method
                FROM payments p
                WHERE p.order_id = o.order_id
                ORDER BY p.paid_at DESC, p.payment_id DESC
                LIMIT 1
            ) AS payment_method,
            (
                SELECT p.payment_status
                FROM payments p
                WHERE p.order_id = o.order_id
                ORDER BY p.paid_at DESC, p.payment_id DESC
                LIMIT 1
            ) AS payment_status
        FROM orders o
        INNER JOIN users u
            ON o.student_id = u.user_id
        INNER JOIN order_statuses os
            ON o.status_id = os.status_id
        ORDER BY o.order_datetime DESC
        LIMIT 8
    ");

    $recentOrders = $stmt->fetchAll();

} catch (PDOException $e) {
    $recentOrders = [];
}

// --------------------------------------------------
// RECENT USERS
// --------------------------------------------------

try {
    $stmt = $pdo->query("
        SELECT
            u.user_id,
            u.id_number,
            u.first_name,
            u.last_name,
            u.email,
            u.is_active,
            u.created_at,
            r.role_name
        FROM users u
        INNER JOIN roles r
            ON u.role_id = r.role_id
        ORDER BY u.created_at DESC
        LIMIT 6
    ");

    $recentUsers = $stmt->fetchAll();

} catch (PDOException $e) {
    $recentUsers = [];
}

// --------------------------------------------------
// HELPERS
// --------------------------------------------------

function statusClass($status)
{
    switch ($status) {
        case "Pending":
            return "status-pending";

        case "Preparing":
            return "status-preparing";

        case "Ready for Pickup":
            return "status-ready";

        case "Completed":
            return "status-completed";

        case "Cancelled":
            return "status-cancelled";

        default:
            return "status-default";
    }
}

function paymentClass($status)
{
    if ($status === "Paid") {
        return "payment-paid";
    }

    if ($status === "Refunded") {
        return "payment-refunded";
    }

    return "payment-unpaid";
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard - CampusBite</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f7fb;
            color: #1f2937;
        }

        a {
            text-decoration: none;
        }

        /* -----------------------------------
           SIDEBAR
        ----------------------------------- */

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 250px;
            height: 100vh;
            background: #111827;
            color: white;
            padding: 24px 16px;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 10px 28px;
            border-bottom: 1px solid rgba(255,255,255,0.10);
            margin-bottom: 22px;
        }

        .brand-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .brand-text h2 {
            font-size: 19px;
            margin-bottom: 3px;
        }

        .brand-text span {
            color: #9ca3af;
            font-size: 12px;
        }

        .nav-title {
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 20px 10px 10px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 12px 13px;
            margin-bottom: 5px;
            border-radius: 9px;
            color: #d1d5db;
            font-size: 14px;
            transition: 0.2s;
        }

        .nav-link:hover {
            background: #1f2937;
            color: white;
        }

        .nav-link.active {
            background: #2563eb;
            color: white;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .logout-link {
            margin-top: 25px;
            color: #fca5a5;
        }

        .logout-link:hover {
            background: #3f1d1d;
            color: #fecaca;
        }

        /* -----------------------------------
           MAIN
        ----------------------------------- */

        .main {
            margin-left: 250px;
            min-height: 100vh;
        }

        .topbar {
            background: white;
            border-bottom: 1px solid #e5e7eb;
            height: 74px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .topbar-title {
            font-size: 21px;
            font-weight: 700;
        }

        .topbar-subtitle {
            color: #6b7280;
            font-size: 13px;
            margin-top: 3px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            background: #dbeafe;
            color: #1d4ed8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .profile-text strong {
            display: block;
            font-size: 14px;
        }

        .profile-text span {
            color: #6b7280;
            font-size: 12px;
        }

        .content {
            padding: 30px;
        }

        /* -----------------------------------
           WELCOME
        ----------------------------------- */

        .welcome-box {
            background: linear-gradient(135deg, #1d4ed8, #2563eb);
            border-radius: 18px;
            padding: 28px;
            color: white;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
        }

        .welcome-box h1 {
            font-size: 27px;
            margin-bottom: 7px;
        }

        .welcome-box p {
            color: #dbeafe;
            font-size: 14px;
            line-height: 1.5;
        }

        .welcome-icon {
            font-size: 65px;
            opacity: 0.2;
        }

        /* -----------------------------------
           STAT CARDS
        ----------------------------------- */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 15px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            min-height: 125px;
        }

        .stat-info small {
            color: #6b7280;
            font-size: 12px;
            display: block;
            margin-bottom: 8px;
        }

        .stat-info h2 {
            font-size: 27px;
            margin-bottom: 5px;
        }

        .stat-info span {
            color: #6b7280;
            font-size: 12px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 22px;
        }

        .icon-blue {
            background: #dbeafe;
            color: #2563eb;
        }

        .icon-green {
            background: #dcfce7;
            color: #16a34a;
        }

        .icon-orange {
            background: #ffedd5;
            color: #ea580c;
        }

        .icon-purple {
            background: #f3e8ff;
            color: #9333ea;
        }

        /* -----------------------------------
           SECONDARY STATS
        ----------------------------------- */

        .secondary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .mini-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 13px;
            padding: 17px;
        }

        .mini-card-title {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 7px;
        }

        .mini-card-value {
            font-size: 22px;
            font-weight: 700;
        }

        .mini-card-value.green {
            color: #15803d;
        }

        .mini-card-value.orange {
            color: #c2410c;
        }

        .mini-card-value.blue {
            color: #1d4ed8;
        }

        .mini-card-value.red {
            color: #dc2626;
        }

        /* -----------------------------------
           QUICK ACTIONS
        ----------------------------------- */

        .section {
            margin-bottom: 25px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .section-header h2 {
            font-size: 18px;
        }

        .section-header p {
            color: #6b7280;
            font-size: 13px;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .action-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 20px;
            transition: 0.2s;
        }

        .action-card:hover {
            border-color: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.05);
        }

        .action-icon {
            width: 45px;
            height: 45px;
            background: #eff6ff;
            color: #2563eb;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 13px;
        }

        .action-card h3 {
            font-size: 15px;
            margin-bottom: 6px;
        }

        .action-card p {
            color: #6b7280;
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 14px;
        }

        .action-button {
            display: inline-block;
            background: #2563eb;
            color: white;
            padding: 9px 13px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
        }

        .action-button:hover {
            background: #1d4ed8;
        }

        /* -----------------------------------
           TWO COLUMN SECTION
        ----------------------------------- */

        .two-column {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 20px;
        }

        .panel {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 15px;
            overflow: hidden;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .panel-header h2 {
            font-size: 16px;
        }

        .panel-link {
            font-size: 12px;
            color: #2563eb;
            font-weight: 600;
        }

        /* -----------------------------------
           TABLE
        ----------------------------------- */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        th {
            text-align: left;
            padding: 12px 16px;
            background: #f9fafb;
            color: #6b7280;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        td {
            padding: 14px 16px;
            border-top: 1px solid #f0f1f3;
            font-size: 13px;
            white-space: nowrap;
        }

        .customer-name {
            font-weight: 600;
        }

        .order-number {
            color: #2563eb;
            font-weight: 600;
        }

        .amount {
            font-weight: 700;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-preparing {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-ready {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-default {
            background: #f3f4f6;
            color: #374151;
        }

        .payment {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
        }

        .payment-paid {
            background: #dcfce7;
            color: #166534;
        }

        .payment-unpaid {
            background: #fef3c7;
            color: #92400e;
        }

        .payment-refunded {
            background: #fee2e2;
            color: #991b1b;
        }

        /* -----------------------------------
           USER LIST
        ----------------------------------- */

        .user-list {
            padding: 6px 0;
        }

        .user-row {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 13px 18px;
            border-bottom: 1px solid #f0f1f3;
        }

        .user-row:last-child {
            border-bottom: none;
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .user-details {
            min-width: 0;
            flex: 1;
        }

        .user-details strong {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .user-details span {
            color: #6b7280;
            font-size: 11px;
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .user-role {
            text-align: right;
            flex-shrink: 0;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 7px;
            background: #f3f4f6;
            border-radius: 5px;
            color: #374151;
            font-size: 9px;
            font-weight: 700;
        }

        .user-status {
            display: block;
            font-size: 9px;
            margin-top: 4px;
        }

        .active {
            color: #16a34a;
        }

        .inactive {
            color: #dc2626;
        }

        /* -----------------------------------
           FOOTER
        ----------------------------------- */

        .footer {
            text-align: center;
            color: #9ca3af;
            font-size: 11px;
            padding: 25px 10px 10px;
        }

        /* -----------------------------------
           MOBILE MENU
        ----------------------------------- */

        .mobile-topbar {
            display: none;
        }

        /* -----------------------------------
           RESPONSIVE
        ----------------------------------- */

        @media (max-width: 1150px) {

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .secondary-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .two-column {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 800px) {

            .sidebar {
                width: 220px;
            }

            .main {
                margin-left: 220px;
            }

            .actions-grid {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 20px;
            }

            .topbar {
                padding: 0 20px;
            }

        }

        @media (max-width: 650px) {

            .sidebar {
                position: static;
                width: 100%;
                height: auto;
                padding: 14px;
            }

            .brand {
                padding-bottom: 15px;
                margin-bottom: 12px;
            }

            .nav-title {
                display: none;
            }

            .nav-link {
                display: inline-flex;
                width: auto;
                margin-right: 4px;
                margin-bottom: 4px;
                padding: 9px 10px;
            }

            .logout-link {
                margin-top: 5px;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                height: auto;
                padding: 15px;
            }

            .topbar-title {
                font-size: 18px;
            }

            .profile-text {
                display: none;
            }

            .content {
                padding: 15px;
            }

            .welcome-box {
                padding: 22px;
            }

            .welcome-box h1 {
                font-size: 22px;
            }

            .welcome-icon {
                display: none;
            }

            .stats-grid,
            .secondary-grid {
                grid-template-columns: 1fr;
            }

        }

    </style>
    <link rel="stylesheet" href="assets/order-tracking/tracking.css?v=1">
    <?php if ($recentOrders): ?><script src="assets/order-tracking/tracking.js?v=1" defer></script><?php endif; ?>
    <link rel="stylesheet" href="dashboard-commerce.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="admin-navbar.css?v=<?= (int) filemtime(__DIR__ . '/admin-navbar.css') ?>">
    <link rel="stylesheet" href="admin-ui.css?v=<?= (int) filemtime(__DIR__ . '/admin-ui.css') ?>">
</head>

<body class="cb-commerce cb-admin">

    <!-- ==========================================
         SIDEBAR
    =========================================== -->

    <?php require __DIR__ . '/components/admin-navbar.php'; ?>

    <!-- ==========================================
         MAIN AREA
    =========================================== -->

    <main class="main">

    <section class="cb-hero" aria-labelledby="cb-hero-title">
        <span class="cb-hero-kicker">CAMPUSBITE MANAGEMENT</span>
        <h1 id="cb-hero-title"><span>Everything you manage,</span>in one simple view.</h1>
        <p>Keep an eye on your campus kitchen, from orders and sales to the people behind them.</p>
    </section>

<?php
require_once __DIR__ . "/components/order-tracking.php";
campusBiteTracking($pdo, $recentOrders);
?>

        <!-- TOP BAR -->

        <header class="topbar">

            <div>
                <div class="topbar-title">
                    Admin Dashboard
                </div>

                <div class="topbar-subtitle">
                    CampusBite Management System
                </div>
            </div>

            <a href="admin_profile.php" class="admin-profile" aria-label="Edit my profile">

                <div class="profile-avatar">
                    <?php
                    echo strtoupper(
                        substr($_SESSION["first_name"] ?? "A", 0, 1)
                    );
                    ?>
                </div>

                <div class="profile-text">
                    <strong>
                        <?php echo htmlspecialchars($adminName); ?>
                    </strong>

                    <span>
                        Administrator
                    </span>
                </div>

            </a>

        </header>

        <!-- CONTENT -->

        <div class="content">

            <!-- WELCOME -->

            <section class="welcome-box">

                <div>

                    <h1>
                        Welcome, <?php echo htmlspecialchars($_SESSION["first_name"] ?? "Admin"); ?>!
                    </h1>

                    <p>
                        Manage CampusBite users, orders, menu information,
                        and monitor the overall canteen ordering system.
                    </p>

                </div>

                <div class="welcome-icon">
                    📊
                </div>

            </section>


            <!-- MAIN STATISTICS -->

            <section class="stats-grid">

                <!-- USERS -->

                <div class="stat-card">

                    <div class="stat-info">

                        <small>Total Users</small>

                        <h2>
                            <?php echo number_format($totalUsers); ?>
                        </h2>

                        <span>
                            <?php echo number_format($activeUsers); ?> active accounts
                        </span>

                    </div>

                    <div class="stat-icon icon-blue">
                        👥
                    </div>

                </div>


                <!-- MENU -->

                <div class="stat-card">

                    <div class="stat-info">

                        <small>Menu Items</small>

                        <h2>
                            <?php echo number_format($totalMenuItems); ?>
                        </h2>

                        <span>
                            <?php echo number_format($availableMenuItems); ?> available
                        </span>

                    </div>

                    <div class="stat-icon icon-green">
                        🍔
                    </div>

                </div>


                <!-- ORDERS -->

                <div class="stat-card">

                    <div class="stat-info">

                        <small>Pending Orders</small>

                        <h2>
                            <?php echo number_format($pendingOrders); ?>
                        </h2>

                        <span>
                            Orders waiting for processing
                        </span>

                    </div>

                    <div class="stat-icon icon-orange">
                        📦
                    </div>

                </div>


                <!-- SALES -->

                <div class="stat-card">

                    <div class="stat-info">

                        <small>Total Sales</small>

                        <h2>
                            ₱<?php echo number_format($totalSales, 2); ?>
                        </h2>

                        <span>
                            From completed orders
                        </span>

                    </div>

                    <div class="stat-icon icon-purple">
                        💰
                    </div>

                </div>

            </section>


            <!-- SECONDARY STATISTICS -->

            <section class="secondary-grid">

                <div class="mini-card">

                    <div class="mini-card-title">
                        Today's Orders
                    </div>

                    <div class="mini-card-value blue">
                        <?php echo number_format($todayOrders); ?>
                    </div>

                </div>


                <div class="mini-card">

                    <div class="mini-card-title">
                        Today's Sales
                    </div>

                    <div class="mini-card-value green">
                        ₱<?php echo number_format($todaySales, 2); ?>
                    </div>

                </div>


                <div class="mini-card">

                    <div class="mini-card-title">
                        Preparing
                    </div>

                    <div class="mini-card-value orange">
                        <?php echo number_format($preparingOrders); ?>
                    </div>

                </div>


                <div class="mini-card">

                    <div class="mini-card-title">
                        Cancelled
                    </div>

                    <div class="mini-card-value red">
                        <?php echo number_format($cancelledOrders); ?>
                    </div>

                </div>

            </section>


            <!-- QUICK ACTIONS -->

            <section class="section">

                <div class="section-header">

                    <div>
                        <h2>Quick Actions</h2>

                        <p>
                            Manage important parts of the system.
                        </p>
                    </div>

                </div>


                <div class="actions-grid">

                    <!-- USERS -->

                    <div class="action-card">

                        <div class="action-icon">
                            👥
                        </div>

                        <h3>
                            Manage Users
                        </h3>

                        <p>
                            Create staff accounts, activate or deactivate
                            users, and view registered CampusBite accounts.
                        </p>

                        <a
                            href="admin_users.php"
                            class="action-button"
                        >
                            Open Users
                        </a>

                    </div>


                    <!-- ORDERS -->

                    <div class="action-card">

                        <div class="action-icon">
                            📦
                        </div>

                        <h3>
                            Manage Orders
                        </h3>

                        <p>
                            Monitor customer orders, payment status,
                            and order progress.
                        </p>

                        <a
                            href="staff_dashboard.php"
                            class="action-button"
                        >
                            Open Orders
                        </a>

                    </div>


                    <!-- MENU -->

                    <div class="action-card">

                        <div class="action-icon">
                            🍔
                        </div>

                        <h3>
                            Manage Menu
                        </h3>

                        <p>
                            View menu items and manage current menu
                            food availability.
                        </p>

                        <a
                            href="staff_menu.php"
                            class="action-button"
                        >
                            Open Menu
                        </a>

                    </div>

                </div>

            </section>


            <!-- RECENT DATA -->

            <section class="two-column">

                <!-- RECENT ORDERS -->

                <div class="panel">

                    <div class="panel-header">

                        <div>
                            <h2>Recent Orders</h2>
                        </div>

                        <a
                            href="staff_dashboard.php"
                            class="panel-link"
                        >
                            View All
                        </a>

                    </div>


                    <?php if (!empty($recentOrders)): ?>

                        <div class="table-wrapper">

                            <table>

                                <thead>

                                    <tr>

                                        <th>
                                            Order
                                        </th>

                                        <th>
                                            Customer
                                        </th>

                                        <th>
                                            Items
                                        </th>

                                        <th>
                                            Total
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php foreach ($recentOrders as $order): ?>

                                        <tr>

                                            <td>

                                                <div class="order-number">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $order["order_number"] ?? "N/A"
                                                    );
                                                    ?>

                                                </div>

                                                <small style="color:#9ca3af;">

                                                    <?php
                                                    echo date(
                                                        "M d, Y h:i A",
                                                        strtotime($order["order_datetime"])
                                                    );
                                                    ?>

                                                </small>

                                            </td>


                                            <td>

                                                <div class="customer-name">

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $order["first_name"] . " " . $order["last_name"]
                                                    );
                                                    ?>

                                                </div>

                                            </td>


                                            <td>

                                                <?php
                                                echo number_format(
                                                    (int) $order["item_count"]
                                                );
                                                ?>

                                            </td>


                                            <td>

                                                <span class="amount">

                                                    ₱<?php
                                                    echo number_format(
                                                        (float) $order["total_amount"],
                                                        2
                                                    );
                                                    ?>

                                                </span>

                                            </td>


                                            <td>

                                                <span
                                                    class="status <?php echo statusClass($order["status_name"]); ?>"
                                                >

                                                    <?php
                                                    echo htmlspecialchars(
                                                        $order["status_name"]
                                                    );
                                                    ?>

                                                </span>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div style="padding:30px;text-align:center;color:#9ca3af;">
                            No orders found.
                        </div>

                    <?php endif; ?>

                </div>


                <!-- RECENT USERS -->

                <div class="panel">

                    <div class="panel-header">

                        <div>
                            <h2>Recent Users</h2>
                        </div>

                        <a
                            href="admin_users.php"
                            class="panel-link"
                        >
                            View All
                        </a>

                    </div>


                    <?php if (!empty($recentUsers)): ?>

                        <div class="user-list">

                            <?php foreach ($recentUsers as $user): ?>

                                <?php
                                $initials =
                                    strtoupper(substr($user["first_name"], 0, 1)) .
                                    strtoupper(substr($user["last_name"], 0, 1));
                                ?>

                                <div class="user-row">

                                    <div class="user-avatar">

                                        <?php
                                        echo htmlspecialchars($initials);
                                        ?>

                                    </div>


                                    <div class="user-details">

                                        <strong>

                                            <?php
                                            echo htmlspecialchars(
                                                $user["first_name"] . " " . $user["last_name"]
                                            );
                                            ?>

                                        </strong>

                                        <span>
                                            <?php
                                            echo htmlspecialchars($user["email"]);
                                            ?>
                                        </span>

                                    </div>


                                    <div class="user-role">

                                        <span class="role-badge">

                                            <?php
                                            echo htmlspecialchars(
                                                $user["role_name"]
                                            );
                                            ?>

                                        </span>

                                        <?php if ((int)$user["is_active"] === 1): ?>

                                            <span class="user-status active">
                                                ● Active
                                            </span>

                                        <?php else: ?>

                                            <span class="user-status inactive">
                                                ● Inactive
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <div style="padding:30px;text-align:center;color:#9ca3af;">
                            No users found.
                        </div>

                    <?php endif; ?>

                </div>

            </section>


            <!-- SYSTEM SUMMARY -->

            <section class="section" style="margin-top:25px;">

                <div class="panel">

                    <div class="panel-header">

                        <div>

                            <h2>
                                System Summary
                            </h2>

                        </div>

                    </div>

                    <div style="padding:20px;">

                        <div style="
                            display:grid;
                            grid-template-columns:repeat(3,1fr);
                            gap:15px;
                        ">

                            <div style="
                                padding:16px;
                                background:#f9fafb;
                                border-radius:10px;
                            ">

                                <small style="
                                    color:#6b7280;
                                    display:block;
                                    margin-bottom:5px;
                                ">
                                    Students
                                </small>

                                <strong style="font-size:22px;">
                                    <?php echo number_format($totalStudents); ?>
                                </strong>

                            </div>


                            <div style="
                                padding:16px;
                                background:#f9fafb;
                                border-radius:10px;
                            ">

                                <small style="
                                    color:#6b7280;
                                    display:block;
                                    margin-bottom:5px;
                                ">
                                    Canteen Staff
                                </small>

                                <strong style="font-size:22px;">
                                    <?php echo number_format($totalStaff); ?>
                                </strong>

                            </div>


                            <div style="
                                padding:16px;
                                background:#f9fafb;
                                border-radius:10px;
                            ">

                                <small style="
                                    color:#6b7280;
                                    display:block;
                                    margin-bottom:5px;
                                ">
                                    Ready for Pickup
                                </small>

                                <strong style="
                                    font-size:22px;
                                    color:#7c3aed;
                                ">
                                    <?php echo number_format($readyOrders); ?>
                                </strong>

                            </div>

                        </div>

                    </div>

                </div>

            </section>


            <div class="footer">
                CampusBite &copy; <?php echo date("Y"); ?> — Administrator Panel
            </div>

        </div>

    </main>

</body>
</html>
