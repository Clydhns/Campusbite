<?php
function cbConfirmedActiveOrderCount(PDO $pdo, int $studentId): int {
    $statement = $pdo->prepare("SELECT COUNT(*) FROM orders o
        JOIN payments p ON p.order_id=o.order_id
        WHERE o.student_id=? AND o.status_id IN (1,2,3)
          AND p.payment_status='Paid' AND p.payment_rejected_at IS NULL");
    $statement->execute([$studentId]);
    return (int) $statement->fetchColumn();
}
