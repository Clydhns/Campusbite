<?php
function campusBiteTracking(PDO $pdo, array $orders, bool $live = false): void {
    $orders = array_slice($orders, 0, 3);
    $logs = [];
    if ($orders) {
        $ids = array_map(static function ($order) { return (int) $order["order_id"]; }, $orders);
        try {
            $stmt = $pdo->prepare("SELECT order_id, status_id, changed_at FROM order_status_logs WHERE order_id IN (" . implode(",", array_fill(0, count($ids), "?")) . ") ORDER BY changed_at ASC, log_id ASC");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $log) {
                $logs[(int) $log["order_id"]][(int) $log["status_id"]] = $log["changed_at"];
            }
        } catch (PDOException $e) {
            error_log("CampusBite tracking history unavailable: " . $e->getMessage());
        }
    }
    $statuses = ["Pending" => 1, "Preparing" => 2, "Ready for Pickup" => 3, "Completed" => 4, "Cancelled" => 5];
    $labels = [1 => "Order Placed", 2 => "Preparing", 3 => "Ready for Pickup", 4 => "Completed"];
    $payload = [];
    foreach ($orders as $order) {
        $status = (int) ($order["status_id"] ?? ($statuses[$order["status_name"] ?? ""] ?? 0));
        $history = $logs[(int) $order["order_id"]] ?? [];
        $steps = [];
        foreach ($labels as $id => $name) {
            $reached = $id === 1 || isset($history[$id]) || ($status >= $id && $status <= 4);
            $date = $id === 1 ? ($order["order_datetime"] ?? null) : ($history[$id] ?? null);
            $steps[] = ["name" => $name, "timestamp" => $date ?: ($reached ? "Reached - time not recorded" : ($status === 5 ? "Not proceeding" : "Pending")), "isCompleted" => $reached];
        }
        $payload[] = ["number" => (string) ($order["order_number"] ?? "Order"), "status" => (string) ($order["status_name"] ?? "Status unavailable"), "cancelled" => $status === 5, "steps" => $steps];
    }
    ?>
    <section class="cb-tracking-section" aria-label="Order tracking">
        <h2 class="cb-tracking-heading">Order tracking</h2>
        <p class="cb-tracking-intro">The latest three orders, from the kitchen to pickup. <?= $live ? 'Status updates automatically while this page is open.' : 'Refresh to see status updates.' ?></p>
        <?php if (!$payload): ?>
            <p>No orders to track yet.</p>
        <?php else: ?>
            <div data-cb-tracking>
                <script type="application/json"><?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
                <noscript>
                    <?php foreach ($payload as $order): ?>
                        <h3><?= htmlspecialchars($order["number"], ENT_QUOTES, "UTF-8") ?></h3>
                        <p><?= htmlspecialchars($order["status"], ENT_QUOTES, "UTF-8") ?></p>
                        <ol><?php foreach ($order["steps"] as $step): ?><li><?= htmlspecialchars($step["name"] . ": " . $step["timestamp"], ENT_QUOTES, "UTF-8") ?></li><?php endforeach; ?></ol>
                    <?php endforeach; ?>
                </noscript>
            </div>
        <?php endif; ?>
    </section>
    <?php
}
