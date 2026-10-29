<?php
if (PHP_SAPI !== 'cli-server' || !getenv('CAMPUSBITE_RECEIPT_TEST_TOKEN')
    || !hash_equals(getenv('CAMPUSBITE_RECEIPT_TEST_TOKEN'), $_SERVER['HTTP_X_RECEIPT_TEST_TOKEN'] ?? '')
    || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/config/payment-receipts.php';
class ReceiptTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$pdo = new ReceiptTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY, role_id INTEGER, is_active INTEGER);
    CREATE TABLE stores (store_id INTEGER PRIMARY KEY, staff_user_id INTEGER);
    CREATE TABLE orders (order_id INTEGER PRIMARY KEY, student_id INTEGER, store_id INTEGER, status_id INTEGER);
    CREATE TABLE payments (payment_id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE, method TEXT, payment_status TEXT,
        reference_number TEXT UNIQUE, receipt_path TEXT, receipt_submitted_at TEXT);
    INSERT INTO users VALUES (1,1,1),(3,3,1),(4,3,1),(5,3,0),(7,2,1),(8,2,1);
    INSERT INTO stores VALUES (1,7),(2,8);
    INSERT INTO orders VALUES (101,3,1,1),(102,4,1,1),(103,3,1,2),(104,3,1,5),(105,3,1,1),(106,3,1,1),(107,5,1,1);
    INSERT INTO payments (payment_id,order_id,method,payment_status,reference_number) VALUES
        (201,101,"GCash","Unpaid",NULL),(202,102,"GCash","Unpaid",NULL),(203,103,"GCash","Paid","9988776655443"),
        (204,104,"GCash","Unpaid",NULL),(205,105,"Cash","Unpaid",NULL),(206,106,"GCash","Unpaid",NULL),(207,107,"GCash","Unpaid",NULL);');
$paths = [];
register_shutdown_function(static function () use (&$paths) {
    foreach ($paths as $path) cbDeleteReceipt($path);
});
$checks = [];
$check = static function ($condition, $message) use (&$checks) {
    if (!$condition) throw new LogicException($message);
    $checks[] = $message;
};
$reject = static function (callable $operation, string $message) use ($pdo, $check) {
    $pdo->beginTransaction();
    try {
        $operation();
        throw new LogicException('Invalid receipt action was accepted: ' . $message);
    } catch (RuntimeException $error) {
        $check(true, $message);
    } finally {
        $pdo->rollBack();
    }
};
try {
    $check(!cbRealGcashReference('GC-DEMO-123456') && cbReceiptPath('../config/connection.php') === '', 'Demo references and unsafe paths are rejected');
    $reject(fn() => cbSaveGcashReceipt($pdo, 4, 101, '1234567890123', $_FILES['receipt']), 'Another student cannot submit a receipt');
    $reject(fn() => cbSaveGcashReceipt($pdo, 5, 107, '1234567890123', $_FILES['receipt']), 'Deactivated students cannot submit receipts');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 103, '1234567890123', $_FILES['receipt']), 'Confirmed payments cannot be edited');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 104, '1234567890123', $_FILES['receipt']), 'Cancelled orders cannot accept receipts');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 105, '1234567890123', $_FILES['receipt']), 'Cash orders cannot accept GCash receipts');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 101, '9988776655443', $_FILES['receipt']), 'References already used by another order are rejected');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 101, '1234567890123', []), 'First submission requires a receipt image');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 101, '1234567890123', $_FILES['invalid']), 'A disguised text file is rejected');

    $pdo->beginTransaction();
    $first = cbSaveGcashReceipt($pdo, 3, 101, '123 456-7890123', $_FILES['receipt']);
    $paths[] = $first['new'];
    $pdo->commit();
    $payment = $pdo->query('SELECT * FROM payments WHERE order_id=101')->fetch();
    $check($payment['reference_number'] === '1234567890123' && is_file(dirname(__DIR__) . '/' . $first['new']), 'Actual reference and uploaded receipt are stored');
    $check($payment['payment_status'] === 'Unpaid' && $payment['receipt_submitted_at'], 'Submitting evidence never automatically marks payment paid');
    $check((bool) cbReceiptForViewer($pdo, 3, 101), 'The customer can view their receipt');
    $check((bool) cbReceiptForViewer($pdo, 7, 101), 'The store owner can view the receipt');
    $check((bool) cbReceiptForViewer($pdo, 1, 101), 'An administrator can review the receipt');
    $check(cbReceiptForViewer($pdo, 4, 101) === null, 'Other customers cannot view the receipt');
    $check(cbReceiptForViewer($pdo, 8, 101) === null, 'Other store owners cannot view the receipt');
    $pdo->exec('UPDATE users SET is_active=0 WHERE user_id=3');
    $check(cbReceiptForViewer($pdo, 3, 101) === null, 'A deactivated customer cannot view receipts');
    $pdo->exec('UPDATE users SET is_active=1 WHERE user_id=3');
    $reject(fn() => cbSaveGcashReceipt($pdo, 3, 106, '1234567890123', $_FILES['replacement']), 'The same payment reference cannot fund two orders');

    $pdo->beginTransaction();
    $repeat = cbSaveGcashReceipt($pdo, 3, 101, '1234567890123', []);
    $pdo->commit();
    $check($repeat['new'] === null && $pdo->query('SELECT receipt_path FROM payments WHERE order_id=101')->fetchColumn() === $first['new'], 'Revising a reference can retain the existing receipt');
    $pdo->beginTransaction();
    $replacement = cbSaveGcashReceipt($pdo, 3, 101, '1234567890123', $_FILES['replacement']);
    $paths[] = $replacement['new'];
    $pdo->rollBack();
    cbDeleteReceipt($replacement['new']);
    $check(is_file(dirname(__DIR__) . '/' . $first['new']) && !is_file(dirname(__DIR__) . '/' . $replacement['new']), 'A failed replacement preserves the prior receipt');

    $request = curl_init('http://localhost/campusbite/' . $first['new']);
    curl_setopt_array($request, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    curl_exec($request);
    $check(curl_getinfo($request, CURLINFO_HTTP_CODE) === 403, 'Direct HTTP access to the receipt file is blocked');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'checks' => $checks]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'message' => $error->getMessage(), 'checks' => $checks]);
}
