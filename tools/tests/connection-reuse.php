<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CAMPUSBITE_LOCAL_TEST') !== '1'
    || getenv('PGHOST') !== '127.0.0.1' || getenv('PGPORT') !== '18758') exit(1);
require dirname(__DIR__, 2) . '/app/config/cloud-database.php';
$first = cbApplicationDatabase();
$second = cbApplicationDatabase();
$sessions = cbCloudDatabase();
echo json_encode([
    'application_connection_reused' => $first === $second,
    'session_connection_separate' => $first !== $sessions,
    'application_role' => $first->query('SELECT current_user')->fetchColumn() === 'campusbite_app',
]);
