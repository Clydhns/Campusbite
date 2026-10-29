<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/stores.php';
header('Cache-Control: private, no-store');
$ajax = ($_SERVER['HTTP_X_CAMPUSBITE_RECEIPT'] ?? '') === '1';
$studentId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = (int) ($_POST['order_id'] ?? 0);
$change = ['new' => null, 'old' => null];
$ok = false;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Submit your receipt using the order payment form.');
    if (!$studentId || (int) ($_SESSION['role_id'] ?? 0) !== 3) throw new RuntimeException('Sign in to your student account to submit a receipt.');
    if (!cbCheckCsrf('orders_csrf')) throw new RuntimeException('Invalid request. Refresh your orders and try again.');
    if ($orderId <= 0) throw new RuntimeException('Invalid order.');
    $reference = is_string($_POST['reference_number'] ?? null) ? $_POST['reference_number'] : '';
    $pdo->beginTransaction();
    $change = cbSaveGcashReceipt($pdo, $studentId, $orderId, $reference, $_FILES['receipt'] ?? []);
    $pdo->commit();
    cbDeleteReceipt($change['old']);
    $message = 'Receipt submitted. Staff will verify your GCash payment before confirming it.';
    $ok = true;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cbDeleteReceipt($change['new']);
    if ($error instanceof RuntimeException && !($error instanceof PDOException)) $message = $error->getMessage();
    else {
        error_log($error->getMessage());
        $message = 'Unable to submit your receipt right now. Please try again.';
    }
}
if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message, 'userId' => $studentId, 'orderId' => $orderId,
        'referenceNumber' => $change['reference'] ?? null,
        'receiptUrl' => 'payment_receipt.php?order_id=' . $orderId], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$_SESSION[$ok ? 'order_success_message' : 'order_error_message'] = $message;
header('Location: orders.php#payment-proof-' . $orderId, true, 303);
exit;
