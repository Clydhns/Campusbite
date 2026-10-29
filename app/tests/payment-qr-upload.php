<?php
// Integration fixture for a temporary local PHP server, never Apache/public hosting.
if (PHP_SAPI !== 'cli-server' || !getenv('CAMPUSBITE_QR_TEST_TOKEN')
    || !hash_equals(getenv('CAMPUSBITE_QR_TEST_TOKEN'), $_SERVER['HTTP_X_QR_TEST_TOKEN'] ?? '')
    || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/config/payment-photos.php';

class QrTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$pdo = new QrTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE stores (store_id INTEGER PRIMARY KEY, staff_user_id INTEGER, gcash_qr_path TEXT);
    INSERT INTO stores VALUES (1, 7, NULL), (2, 8, NULL);');
$cleanup = [];
register_shutdown_function(static function () use (&$cleanup) {
    foreach ($cleanup as $path) cbDeletePaymentQr($path);
});
$checks = [];
$check = static function ($condition, $message) use (&$checks) {
    if (!$condition) throw new RuntimeException($message);
    $checks[] = $message;
};
$exists = static fn($path) => is_file(dirname(__DIR__) . '/' . $path);
try {
    $check(cbPaymentQrUrl('../config/connection.php') === '', 'Unsafe paths are rejected');
    try {
        cbSaveStorePaymentQr($pdo, 1, 7, $_FILES['qr'], false);
        throw new LogicException('Missing transaction was accepted');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'transaction'), 'A transaction is required');
    }
    $pdo->beginTransaction();
    try {
        cbSaveStorePaymentQr($pdo, 2, 7, $_FILES['qr'], false);
        throw new LogicException('Another store was accepted');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'does not belong'), 'Another store cannot be changed');
    }
    $pdo->rollBack();

    $pdo->beginTransaction();
    $first = cbSaveStorePaymentQr($pdo, 1, 7, $_FILES['qr'], false);
    $cleanup[] = $first['new'];
    $pdo->commit();
    $check($exists($first['new']) && cbPaymentQrUrl($first['new']) === $first['new'], 'A real multipart image is saved under a safe filename');
    $check($pdo->query('SELECT gcash_qr_path FROM stores WHERE store_id=1')->fetchColumn() === $first['new'], 'The image belongs to the selected store');

    $pdo->beginTransaction();
    $unchanged = cbSaveStorePaymentQr($pdo, 1, 7, [], false);
    $pdo->commit();
    $check($unchanged === ['new' => null, 'old' => null] && $exists($first['new']), 'Saving without a new image keeps the existing QR');

    $pdo->beginTransaction();
    $failed = cbSaveStorePaymentQr($pdo, 1, 7, $_FILES['replacement'], false);
    $cleanup[] = $failed['new'];
    $pdo->rollBack();
    cbDeletePaymentQr($failed['new']);
    $check(!$exists($failed['new']) && $exists($first['new']), 'A rolled-back replacement removes only the new image');

    $pdo->beginTransaction();
    $replacement = cbSaveStorePaymentQr($pdo, 1, 7, $_FILES['replacement2'], false);
    $cleanup[] = $replacement['new'];
    $check($replacement['old'] === $first['new'] && $exists($first['new']), 'The old QR remains until the replacement commits');
    $pdo->commit();
    cbDeletePaymentQr($replacement['old']);
    $check(!$exists($first['new']) && $exists($replacement['new']), 'A committed replacement retires the old image');

    $pdo->beginTransaction();
    try {
        cbSaveStorePaymentQr($pdo, 1, 7, $_FILES['invalid'], false);
        throw new LogicException('A fake image was accepted');
    } catch (RuntimeException $error) {
        $check(str_contains($error->getMessage(), 'actual JPEG'), 'A text file disguised as an image is rejected');
    }
    $pdo->rollBack();

    $pdo->beginTransaction();
    $removed = cbSaveStorePaymentQr($pdo, 1, 7, [], true);
    $pdo->commit();
    cbDeletePaymentQr($removed['old']);
    $check($pdo->query('SELECT gcash_qr_path FROM stores WHERE store_id=1')->fetchColumn() === null && !$exists($replacement['new']), 'Removing a QR clears the store path and image');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'checks' => $checks]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $error->getMessage(), 'checks' => $checks]);
}
