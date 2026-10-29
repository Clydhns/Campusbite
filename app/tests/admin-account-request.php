<?php
// Exercise the account controller with an isolated in-memory database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$case = $argv[1] ?? 'deactivate';
$root = dirname(__DIR__);
// Mark the normal bootstrap as included, then replace its connection before
// executing any account queries. No real account records are read or changed.
require $root . '/config/connection.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$conn = $pdo;
$pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT);
    CREATE TABLE users (user_id INTEGER PRIMARY KEY, role_id INTEGER, id_number TEXT,
        first_name TEXT, last_name TEXT, email TEXT, password_hash TEXT, is_active INTEGER);
    CREATE TABLE stores (store_id INTEGER PRIMARY KEY, staff_user_id INTEGER);
    CREATE TABLE orders (order_id INTEGER PRIMARY KEY, student_id INTEGER);
    INSERT INTO roles VALUES (1, "Administrator"), (2, "Canteen Staff"), (3, "Student");
    INSERT INTO users VALUES
        (1, 1, "ADM-TEST", "Test", "Admin", "admin@example.test", "unused", 1),
        (2, 2, "STF-TEST", "Test", "Staff", "staff@example.test", "unused", 1),
        (3, 3, "STU-TEST", "Test", "Student", "student@example.test", "unused", 1);');

session_set_save_handler(new class implements SessionHandlerInterface {
    private string $data = '';
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return $this->data; }
    public function write(string $id, string $data): bool { $this->data = $data; return true; }
    public function destroy(string $id): bool { $this->data = ''; return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
}, true);
session_id('account-check-' . bin2hex(random_bytes(12)));
session_start();
$_SESSION = ['user_id' => 1, 'role_name' => 'Administrator', 'admin_csrf' => 'test-token'];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_CAMPUSBITE_ACCOUNTS'] = '1';
$_POST = ['action' => 'toggle_user', 'user_id' => '3', 'is_active' => '0', 'csrf_token' => 'test-token'];
$expectedOk = true;
$expectedStatus = 0;
$expectedMessage = '';

switch ($case) {
    case 'deactivate': break;
    case 'activate':
        $pdo->exec('UPDATE users SET is_active = 0 WHERE user_id = 3');
        $_POST['is_active'] = '1';
        $expectedStatus = 1;
        break;
    case 'repeat-deactivation':
        $pdo->exec('UPDATE users SET is_active = 0 WHERE user_id = 3');
        break;
    case 'delete':
        $_POST['action'] = 'delete_user';
        $pdo->exec('UPDATE users SET is_active = 0 WHERE user_id = 3');
        $expectedStatus = null;
        break;
    case 'delete-history':
        $_POST['action'] = 'delete_user';
        $pdo->exec('INSERT INTO orders VALUES (1, 3)');
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'order history';
        break;
    case 'delete-store-owner':
        $_POST['action'] = 'delete_user'; $_POST['user_id'] = '2';
        $pdo->exec('INSERT INTO stores VALUES (1, 2)');
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'owns a store';
        break;
    case 'own-account':
        $_POST['user_id'] = '1';
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'cannot change';
        break;
    case 'bad-csrf':
        $_POST['csrf_token'] = 'invalid';
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'Invalid request';
        break;
    case 'malformed-csrf':
        $_POST['csrf_token'] = ['invalid'];
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'Invalid request';
        break;
    case 'session-expired':
        $_SESSION = [];
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'session expired';
        break;
    case 'non-admin':
        $_SESSION['role_name'] = 'Student';
        $expectedOk = false; $expectedStatus = 1; $expectedMessage = 'Administrator access';
        break;
    default: throw new RuntimeException('Unknown test case.');
}

session_write_close();
register_shutdown_function(static function () {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
$targetId = (int) $_POST['user_id'];
ob_start(static function ($output) use ($pdo, $case, $targetId, $expectedOk, $expectedStatus, $expectedMessage) {
    $result = json_decode($output, true);
    $check = static function ($condition, $message) {
        if (!$condition) throw new RuntimeException($message);
    };
    $check(is_array($result), 'Response is not JSON: ' . $output);
    $check($result['ok'] === $expectedOk, 'Unexpected result for ' . $case);
    $actual = $pdo->query('SELECT is_active FROM users WHERE user_id = ' . $targetId)->fetchColumn();
    $check($expectedStatus === null ? $actual === false : (int) $actual === $expectedStatus, 'Unexpected database state');
    if ($expectedMessage !== '') $check(str_contains($result['message'], $expectedMessage), 'Incorrect error feedback');
    if ($expectedOk) {
        $check($result['userId'] === $targetId && $result['adminId'] === 1, 'Incorrect response identity');
        $check(!isset($_SESSION['admin_users_flash']), 'Async action created a native-page flash');
        $check($result['counts']['Students'] === ($case === 'delete' ? 0 : 1), 'Incorrect account counts');
        if ($case === 'delete') {
            $check($result['action'] === 'deleted' && $result['rowHtml'] === '', 'Deletion response is incorrect');
        } else {
            $check($result['action'] === 'updated', 'Missing update result');
            $check(str_contains($result['rowHtml'], 'data-account-id="3"'), 'Updated row is missing');
            $check(str_contains($result['rowHtml'], $expectedStatus ? 'status-active' : 'status-inactive'), 'Row status is incorrect');
            $check(str_contains($result['rowHtml'], 'name="is_active" value="' . ($expectedStatus ? '0' : '1') . '"'), 'Next action has incorrect target status');
        }
    }
    return 'PASS ' . $case . PHP_EOL;
});
require $root . '/admin_users.php';
