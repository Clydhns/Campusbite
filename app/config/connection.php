<?php

require_once __DIR__ . '/cloud-database.php';

try {

    $pdo = cbApplicationDatabase();

    $pdo->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $pdo->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

    /*
    |--------------------------------------------------------------------------
    | COMPATIBILITY
    |--------------------------------------------------------------------------
    | Some of the existing pages use $conn instead of $pdo.
    | Both variables now point to the same PDO connection.
    |--------------------------------------------------------------------------
    */

    $conn = $pdo;

} catch (Throwable $e) {

    error_log('CampusBite database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('CampusBite is temporarily unavailable. Please try again later.');
}
?>
