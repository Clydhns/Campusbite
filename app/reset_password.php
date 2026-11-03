<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/auth-security.php';
require_once __DIR__ . '/config/password-recovery.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
if ($_SERVER['REQUEST_METHOD']==='GET' && array_key_exists('token',$_GET)) {
    // Remove the secret from the address before rendering assets or a form. GET never consumes a link.
    unset($_SESSION['recovery_token']);
    if (is_string($_GET['token']) && preg_match('/^[a-f0-9]{64}$/D',$_GET['token'])) {
        $_SESSION['recovery_token'] = $_GET['token'];
        $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
    }
    header('Location: reset_password.php',true,303); exit;
}
$resetView = true;
$error = '';
$message = '';
$resetAllowed = false;
$token = $_SESSION['recovery_token'] ?? '';
try {
    $resetAllowed = is_string($token) && cbRecoveryAccount($pdo,$token)!==false;
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!cbAuthRequestValid()) throw new RuntimeException('Your form expired. Refresh and try again.');
        if (!cbRecoveryLimit($pdo,'reset-ip',$_SERVER['REMOTE_ADDR'] ?? 'unknown',30,3600)) {
            throw new RuntimeException('Too many attempts. Please try again later.');
        }
        $email = cbResetPassword($pdo,is_string($token) ? $token : '',$_POST);
        $_SESSION = [];
        session_regenerate_id(true);
        $resetAllowed = false;
        $message = 'Your password has been reset. All existing sessions have been signed out. Sign in with your new CampusBite password.';
        try {
            cbRecoveryMail($email,'Your CampusBite password was reset',
                'Your CampusBite password was changed using email recovery. All existing sessions have been signed out. '
                . 'If you did not do this, secure your email account and use Forgot password on CampusBite to recover access. '
                . 'CampusBite is an independent student project, not your school or email provider.', cbRecoveryEmailHtml(cbRecoveryOrigin().'/login.php',true));
        } catch (Throwable $exception) { error_log('CampusBite password-reset notification could not be sent.'); }
    }
} catch (PDOException $exception) {
    error_log('CampusBite password recovery database request failed.');
    $error = 'Password recovery is temporarily unavailable. Please try again later.';
    $resetAllowed = false;
} catch (RuntimeException $exception) { $error = $exception->getMessage(); }
if (!$resetAllowed && $message==='' && $error==='') $error='This reset link is invalid or expired. Request a new one.';
require __DIR__ . '/recovery-view.php';
