<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/payment-receipts.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT);
$receipt = $orderId && isset($_SESSION['user_id'])
    ? cbReceiptForViewer($pdo, (int) $_SESSION['user_id'], (int) $orderId) : null;
$relative = cbReceiptPath($receipt['receipt_path'] ?? null);
if (!$relative) {
    http_response_code(404);
    exit('Receipt not found.');
}
$extension = pathinfo($relative, PATHINFO_EXTENSION);
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$extension]);
header('Content-Disposition: inline; filename="receipt-' . (int) $orderId . '.' . $extension . '"');
header("Content-Security-Policy: default-src 'none'; sandbox");
session_write_close();
try {
    $image=cbStorageRequest('GET',$relative);
    echo $image['body'];
} catch (RuntimeException $error) {
    http_response_code(404);
}
