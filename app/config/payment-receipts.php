<?php
require_once __DIR__ . '/food-photos.php';

function cbReceiptPath($path): string {
    return is_string($path) && preg_match('~^uploads/receipts/[a-f0-9]{32}\.(jpg|png|webp)$~D', $path) ? $path : '';
}

function cbRealGcashReference($reference): bool {
    return is_string($reference) && preg_match('/^[0-9]{6,30}$/D', $reference) === 1;
}

function cbReceiptReviewToken(array $payment): string {
    return hash('sha256', ($payment['reference_number'] ?? '') . "\n" . ($payment['receipt_path'] ?? '') . "\n" . ($payment['receipt_submitted_at'] ?? ''));
}

function cbRejectGcashPayment(PDO $pdo, int $staffId, int $storeId, int $orderId, string $reason, string $reviewToken): string {
    if (!$pdo->inTransaction()) throw new RuntimeException('Payment rejection requires a transaction.');
    $reason = trim($reason);
    if (!preg_match('/^.{1,200}$/us', $reason)) throw new RuntimeException('Enter a rejection reason of 1 to 200 characters.');
    $stmt = $pdo->prepare('SELECT o.order_id,o.order_number,o.order_datetime,o.status_id,p.payment_id,
        p.method,p.payment_status,p.reference_number,p.receipt_path,p.receipt_submitted_at
        FROM orders o JOIN payments p ON p.order_id=o.order_id JOIN stores s ON s.store_id=o.store_id
        JOIN users staff ON staff.user_id=s.staff_user_id
        WHERE o.order_id=? AND o.store_id=? AND s.staff_user_id=? AND staff.role_id=2 AND staff.is_active=1 FOR UPDATE');
    $stmt->execute([$orderId, $storeId, $staffId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) throw new RuntimeException('This order does not belong to your active store account.');
    if ((int) $payment['status_id'] !== 1 || $payment['method'] !== 'GCash' || $payment['payment_status'] !== 'Unpaid') {
        throw new RuntimeException('Only pending, unconfirmed GCash orders can be rejected.');
    }
    if (!cbRealGcashReference($payment['reference_number']) || !cbReceiptPath($payment['receipt_path'])) {
        throw new RuntimeException('There is no submitted GCash payment to review.');
    }
    if (!hash_equals(cbReceiptReviewToken($payment), $reviewToken)) {
        throw new RuntimeException('The customer changed their payment details. Refresh and review the latest receipt before deciding.');
    }
    $note = 'GCash payment rejected: ' . $reason;
    $cancel = $pdo->prepare('UPDATE orders SET status_id=5,staff_id=?,cancel_reason=? WHERE order_id=? AND store_id=? AND status_id=1');
    $cancel->execute([$staffId, $note, $orderId, $storeId]);
    if ($cancel->rowCount() !== 1) throw new RuntimeException('The order changed. Refresh before trying again.');
    $reject = $pdo->prepare('UPDATE payments SET payment_rejected_at=CURRENT_TIMESTAMP,payment_rejection_reason=? WHERE payment_id=?');
    $reject->execute([$reason, $payment['payment_id']]);
    $log = $pdo->prepare('INSERT INTO order_status_logs (order_id,status_id,changed_by,note) VALUES (?,5,?,?)');
    $log->execute([$orderId, $staffId, $note]);
    return $payment['order_number'];
}

function cbDeleteReceipt($path): void {
    $relative = cbReceiptPath($path);
    if (!$relative) return;
    cbStorageDelete($relative);
}

function cbReceiptForViewer(PDO $pdo, int $viewerId, int $orderId): ?array {
    $stmt = $pdo->prepare('SELECT p.receipt_path, o.order_id FROM orders o
        JOIN payments p ON p.order_id=o.order_id JOIN stores s ON s.store_id=o.store_id
        JOIN users viewer ON viewer.user_id=?
        WHERE o.order_id=? AND viewer.is_active=1 AND (
            (viewer.role_id=3 AND o.student_id=viewer.user_id) OR
            (viewer.role_id=2 AND s.staff_user_id=viewer.user_id) OR viewer.role_id=1)');
    $stmt->execute([$viewerId, $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cbSaveGcashReceipt(PDO $pdo, int $studentId, int $orderId, string $reference, array $file): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Receipt submission requires a transaction.');
    $reference = preg_replace('/[\s-]+/', '', trim($reference));
    if (!cbRealGcashReference($reference)) throw new RuntimeException('Enter the numeric reference number from your GCash receipt (6 to 30 digits).');
    $stmt = $pdo->prepare('SELECT o.status_id, p.payment_id, p.method, p.payment_status, p.receipt_path
        FROM orders o JOIN payments p ON p.order_id=o.order_id
        JOIN users customer ON customer.user_id=o.student_id
        WHERE o.order_id=? AND o.student_id=? AND customer.role_id=3 AND customer.is_active=1 FOR UPDATE');
    $stmt->execute([$orderId, $studentId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) throw new RuntimeException('Order not found or your account is no longer active.');
    if ($payment['method'] !== 'GCash' || (int) $payment['status_id'] !== 1 || $payment['payment_status'] !== 'Unpaid') {
        throw new RuntimeException('Receipts can only be submitted for pending GCash orders awaiting payment verification.');
    }
    $duplicate = $pdo->prepare('SELECT payment_id FROM payments WHERE reference_number=? AND payment_id<>? LIMIT 1');
    $duplicate->execute([$reference, $payment['payment_id']]);
    if ($duplicate->fetchColumn()) throw new RuntimeException('This reference number is already attached to another order. Check your receipt.');

    $old = cbReceiptPath($payment['receipt_path']);
    $hasUpload = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasUpload && !$old) throw new RuntimeException('Upload your GCash receipt image along with the reference number.');
    $new = null;
    if ($hasUpload) {
        $extension = cbValidateFoodPhoto($file);
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid receipt upload.');
        $new = cbStorageUpload('receipts',$file,$extension);
    }
    try {
        $update = $pdo->prepare('UPDATE payments SET reference_number=?, receipt_path=?, receipt_submitted_at=CURRENT_TIMESTAMP WHERE payment_id=? AND payment_status=\'Unpaid\'');
        $update->execute([$reference, $new ?: $old, $payment['payment_id']]);
    } catch (Throwable $error) {
        cbDeleteReceipt($new);
        if ($error instanceof PDOException && $error->getCode() === '23505') {
            throw new RuntimeException('This reference number is already attached to another order. Check your receipt.');
        }
        throw $error;
    }
    return ['new' => $new, 'old' => $new ? $old : null, 'reference' => $reference];
}
