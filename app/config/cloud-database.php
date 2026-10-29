<?php
function cbCloudDatabase(): PDO {
    date_default_timezone_set('Asia/Manila');
    $host = getenv('PGHOST');
    $database = getenv('PGDATABASE');
    $user = getenv('PGUSER');
    $password = getenv('PGPASSWORD');
    $port = getenv('PGPORT') ?: '5432';
    if (!$host || !$database || !$user || $password === false || $password === '') {
        throw new RuntimeException('PostgreSQL credentials are not configured.');
    }
    if (!preg_match('/^[a-zA-Z0-9.:-]+$/D', $host) || !ctype_digit($port)
        || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database)) {
        throw new RuntimeException('Invalid PostgreSQL connection settings.');
    }
    $ssl = getenv('CAMPUSBITE_LOCAL_TEST') === '1' ? 'prefer' : 'require';
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$database;sslmode=$ssl;connect_timeout=10", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // Use the session pooler: role/timezone settings must last for this connection.
        PDO::ATTR_EMULATE_PREPARES => true,
        PDO::ATTR_PERSISTENT => false,
    ]);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
    $pdo->exec('SET ROLE campusbite_app');
    $pdo->exec('SET search_path TO public, pg_catalog');
    $pdo->exec("SET statement_timeout TO '15s'");
    $pdo->exec("SET lock_timeout TO '8s'");
    return $pdo;
}
