<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/payment-receipts.php';
class RejectionTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function rejectionFixture(): PDO {
    $pdo = new RejectionTestPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
    $pdo->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY,role_id INTEGER,is_active INTEGER);
        CREATE TABLE stores (store_id INTEGER PRIMARY KEY,staff_user_id INTEGER);
        CREATE TABLE orders (order_id INTEGER PRIMARY KEY,order_number TEXT,order_datetime TEXT,status_id INTEGER,store_id INTEGER,student_id INTEGER,staff_id INTEGER,cancel_reason TEXT);
        CREATE TABLE payments (payment_id INTEGER PRIMARY KEY,order_id INTEGER,method TEXT,payment_status TEXT,reference_number TEXT,receipt_path TEXT,receipt_submitted_at TEXT,payment_rejected_at TEXT,payment_rejection_reason TEXT);
        CREATE TABLE daily_menu (daily_menu_id INTEGER PRIMARY KEY,item_id INTEGER,menu_date TEXT,quantity_prepared INTEGER,quantity_remaining INTEGER);
        CREATE TABLE order_items (order_id INTEGER,item_id INTEGER,quantity INTEGER);
        CREATE TABLE order_status_logs (order_id INTEGER,status_id INTEGER,changed_by INTEGER,note TEXT);
        INSERT INTO users VALUES (3,3,1),(7,2,1),(8,2,1);
        INSERT INTO stores VALUES (1,7),(2,8);
        INSERT INTO orders VALUES (101,"ORD-TEST","2026-10-26 12:00:00",1,1,3,NULL,NULL);
        INSERT INTO payments VALUES (201,101,"GCash","Unpaid","1234567890123","uploads/receipts/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png","2026-10-26 12:05:00",NULL,NULL);
        INSERT INTO daily_menu VALUES (1,10,"2026-10-26",10,5),(2,10,"2026-10-27",10,8);
        INSERT INTO order_items VALUES (101,10,2);');
    return $pdo;
}
$checks = 0;
$check = static function ($condition, $message) use (&$checks) {
    if (!$condition) throw new LogicException($message);
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
};
$pdo = rejectionFixture();
$token = cbReceiptReviewToken($pdo->query('SELECT * FROM payments WHERE payment_id=201')->fetch());
$blocked = static function (callable $operation, string $message) use ($pdo, $check) {
    $pdo->beginTransaction();
    try { $operation(); throw new LogicException('Unsafe rejection accepted: ' . $message); }
    catch (RuntimeException $error) { $check(true, $message); }
    finally { $pdo->rollBack(); }
};
$blocked(fn() => cbRejectGcashPayment($pdo,8,1,101,'Invalid receipt',$token), 'Another staff account cannot reject this order');
$blocked(fn() => cbRejectGcashPayment($pdo,7,2,101,'Invalid receipt',$token), 'Another store ID is rejected');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'   ',$token), 'A rejection reason is required');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,str_repeat('x',201),$token), 'Overlong reasons are rejected');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'Invalid receipt','stale-token'), 'A stale receipt review cannot reject a changed payment');
$pdo->exec('UPDATE users SET is_active=0 WHERE user_id=7');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'Invalid receipt',$token), 'Inactive owners cannot reject orders');
$pdo->exec('UPDATE users SET is_active=1 WHERE user_id=7');
$pdo->exec('UPDATE payments SET payment_status="Paid"');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'Invalid receipt',$token), 'Confirmed payments cannot be cancelled through rejection');
$pdo->exec('UPDATE payments SET payment_status="Unpaid",method="Cash"');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'Invalid receipt',$token), 'Cash orders cannot use GCash rejection');
$pdo->exec('UPDATE payments SET method="GCash"');
$pdo->beginTransaction();
$number = cbRejectGcashPayment($pdo,7,1,101,'The receipt amount does not match.',$token);
$pdo->commit();
$order = $pdo->query('SELECT * FROM orders WHERE order_id=101')->fetch();
$payment = $pdo->query('SELECT * FROM payments WHERE payment_id=201')->fetch();
$check($number === 'ORD-TEST' && (int)$order['status_id'] === 5 && (int)$order['staff_id'] === 7, 'Rejection cancels the order and records its owner');
$check((int)$pdo->query('SELECT quantity_remaining FROM daily_menu WHERE daily_menu_id=1')->fetchColumn() === 7, 'Reserved servings are restored');
$check((int)$pdo->query('SELECT quantity_remaining FROM daily_menu WHERE daily_menu_id=2')->fetchColumn() === 8, 'Stock is restored to the original order date');
$check($payment['payment_rejected_at'] && $payment['payment_rejection_reason'] === 'The receipt amount does not match.', 'The rejection decision and customer reason are saved');
$check($payment['payment_status'] === 'Unpaid' && $payment['reference_number'] === '1234567890123' && cbReceiptPath($payment['receipt_path']), 'The original evidence is retained without claiming a payment or refund');
$check((int)$pdo->query('SELECT COUNT(*) FROM order_status_logs WHERE status_id=5 AND changed_by=7')->fetchColumn() === 1, 'Cancellation creates an audit log');
$blocked(fn() => cbRejectGcashPayment($pdo,7,1,101,'Repeated request',$token), 'Repeated rejection cannot restore stock twice');
$check((int)$pdo->query('SELECT quantity_remaining FROM daily_menu WHERE daily_menu_id=1')->fetchColumn() === 7, 'Stock remains unchanged after a repeated request');
$blocked(fn() => cbSaveGcashReceipt($pdo,3,101,'1234567890123',[]), 'Customers cannot resubmit payment on a rejected order');

$capped = rejectionFixture();
$capped->exec('UPDATE daily_menu SET quantity_remaining=9 WHERE daily_menu_id=1');
$capped->beginTransaction(); cbRejectGcashPayment($capped,7,1,101,'Invalid receipt',$token); $capped->commit();
$check((int)$capped->query('SELECT quantity_remaining FROM daily_menu WHERE daily_menu_id=1')->fetchColumn() === 10, 'Stock restoration never exceeds the prepared servings');
$rollback = rejectionFixture();
$rollback->exec('DROP TABLE order_status_logs');
$rollback->beginTransaction();
try { cbRejectGcashPayment($rollback,7,1,101,'Invalid receipt',$token); throw new LogicException('Expected audit failure'); }
catch (PDOException $error) { $rollback->rollBack(); }
$check((int)$rollback->query('SELECT status_id FROM orders')->fetchColumn() === 1
    && (int)$rollback->query('SELECT quantity_remaining FROM daily_menu WHERE daily_menu_id=1')->fetchColumn() === 5
    && $rollback->query('SELECT payment_rejected_at FROM payments')->fetchColumn() === null, 'Failure rolls back cancellation, stock, and rejection together');
echo "$checks rejection checks passed.\n";
