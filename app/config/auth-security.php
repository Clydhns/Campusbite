<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function cbAuthToken(): string {
    if (empty($_SESSION['auth_csrf'])) $_SESSION['auth_csrf']=bin2hex(random_bytes(32));
    return $_SESSION['auth_csrf'];
}
function cbAuthRequestValid(): bool {
    $token=$_POST['csrf_token'] ?? null;
    return is_string($token) && hash_equals(cbAuthToken(),$token);
}
