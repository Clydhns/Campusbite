<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/profile-photos.php';
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

// With ?order_id, the customer's photo for that order: visible to the student, that kitchen's staff, or an admin.
// Without it, the signed-in student's own photo.
$viewerId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = filter_var($_GET['order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$path = null;
if ($viewerId && $orderId) {
    $stmt = $pdo->prepare('SELECT customer.avatar_path FROM orders o
        JOIN users customer ON customer.user_id=o.student_id JOIN stores s ON s.store_id=o.store_id
        JOIN users viewer ON viewer.user_id=?
        WHERE o.order_id=? AND viewer.is_active=1 AND (
            (viewer.role_id=3 AND o.student_id=viewer.user_id) OR
            (viewer.role_id=2 AND s.staff_user_id=viewer.user_id) OR viewer.role_id=1)');
    $stmt->execute([$viewerId, $orderId]);
    $path = $stmt->fetchColumn();
} elseif ($viewerId) {
    $stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE user_id=? AND role_id=3 AND is_active=1');
    $stmt->execute([$viewerId]);
    $path = $stmt->fetchColumn();
}
session_write_close();
$relative = cbProfilePhotoPath($path);
if (!$relative) {
    http_response_code(404);
    header('Cache-Control: private, no-store');
    exit('Photo not found.');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($relative, PATHINFO_EXTENSION)]);
header('Content-Disposition: inline; filename="profile-photo.' . pathinfo($relative, PATHINFO_EXTENSION) . '"');
header("Content-Security-Policy: default-src 'none'; sandbox");
try {
    echo cbStorageRequest('GET', $relative)['body'];
} catch (RuntimeException $error) {
    http_response_code(404);
}
