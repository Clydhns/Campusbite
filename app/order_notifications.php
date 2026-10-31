<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit(json_encode(['error' => 'Method not allowed.']));
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Sign in to view order notifications.']));
}
if ((int) ($_SESSION['role_id'] ?? 0) !== 3) {
    http_response_code(403);
    exit(json_encode(['error' => 'A student account is required.']));
}
$notificationUser = (int) $_SESSION['user_id'];
session_write_close();
ob_start(); require_once __DIR__ . '/config/connection.php'; ob_end_clean();
require_once __DIR__ . '/config/order-notifications.php';
echo json_encode(['user_id' => (string) $notificationUser,
    'confirmed_count' => cbConfirmedActiveOrderCount($pdo, $notificationUser)]);
