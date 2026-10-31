<?php
require_once __DIR__ . '/food-availability.php';

function cbAdminStats(PDO $pdo): array {
    return $pdo->query("SELECT accounts.*,menu.*,sales.* FROM
        (SELECT COUNT(*) AS total_users,COUNT(*) FILTER (WHERE is_active=1) AS active_users,
            COUNT(*) FILTER (WHERE role_id=3) AS total_students,
            COUNT(*) FILTER (WHERE role_id=2) AS total_staff FROM users) accounts
        CROSS JOIN (SELECT COUNT(*) AS total_menu_items,
            COUNT(*) FILTER (WHERE m.archived_at IS NULL AND (" . cbFoodAvailabilitySql('m') . ")=1) AS available_menu_items
            FROM menu_items m) menu
        CROSS JOIN (SELECT COUNT(*) FILTER (WHERE status_id=1) AS pending_orders,
            COUNT(*) FILTER (WHERE status_id=2) AS preparing_orders,
            COUNT(*) FILTER (WHERE status_id=3) AS ready_orders,
            COUNT(*) FILTER (WHERE status_id=4) AS completed_orders,
            COUNT(*) FILTER (WHERE status_id=5) AS cancelled_orders,
            COALESCE(SUM(total_amount) FILTER (WHERE status_id=4),0) AS total_sales,
            COALESCE(SUM(total_amount) FILTER (WHERE status_id=4 AND order_datetime>=CURRENT_DATE
                AND order_datetime<CURRENT_DATE+INTERVAL '1 day'),0) AS today_sales,
            COUNT(*) FILTER (WHERE order_datetime>=CURRENT_DATE
                AND order_datetime<CURRENT_DATE+INTERVAL '1 day') AS today_orders FROM orders) sales")->fetch();
}
