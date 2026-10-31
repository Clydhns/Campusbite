<?php
function cbStudentOrderNotificationCounts(PDO $pdo, int $studentId): array {
    $statement = $pdo->prepare("SELECT COUNT(*) AS active_count,
        COUNT(*) FILTER (WHERE p.payment_status='Paid' AND p.payment_rejected_at IS NULL) AS confirmed_count
        FROM orders o LEFT JOIN payments p ON p.order_id=o.order_id
        WHERE o.student_id=? AND o.status_id IN (1,2,3)");
    $statement->execute([$studentId]);
    $counts = $statement->fetch(PDO::FETCH_ASSOC);
    return ['active_count' => (int) $counts['active_count'], 'confirmed_count' => (int) $counts['confirmed_count']];
}
