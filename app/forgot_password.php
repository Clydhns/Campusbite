<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/auth-security.php';
require_once __DIR__ . '/config/password-recovery.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$resetView = false;
$error = '';
$message = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $started = microtime(true);
    if (!cbAuthRequestValid()) {
        $error = 'Your form expired. Refresh and try again.';
    } else {
        try {
            cbRequestPasswordRecovery($pdo, is_string($_POST['email'] ?? null) ? $_POST['email'] : '', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        } catch (Throwable $exception) {
            error_log('CampusBite recovery: ' . ($exception instanceof PDOException ? 'database_failed' : 'request_failed'));
        }
        // Identical response for active, inactive, unknown, throttled, and failed-delivery requests.
        $message = 'If an active CampusBite account matches that email, we will send a reset link. Check your inbox and spam folder. The link expires in 15 minutes.';
        // SMTP is capped at four seconds. Pad short paths to avoid disclosing account existence by timing.
        if (getenv('CAMPUSBITE_LOCAL_TEST')!=='1') {
            $remaining = 6 - (microtime(true)-$started);
            if ($remaining>0) usleep((int)($remaining*1000000));
        }
    }
}
require __DIR__ . '/recovery-view.php';
