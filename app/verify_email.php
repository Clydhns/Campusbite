<?php
require_once __DIR__ . '/config/connection.php';
require_once __DIR__ . '/config/auth-security.php';
require_once __DIR__ . '/config/registration-verification.php';
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
if ($_SERVER['REQUEST_METHOD']==='GET' && array_key_exists('token', $_GET)) {
    unset($_SESSION['registration_token']);
    if (is_string($_GET['token']) && preg_match('/^[a-f0-9]{64}$/D', $_GET['token'])) {
        $_SESSION['registration_token'] = $_GET['token'];
        $_SESSION['auth_csrf'] = bin2hex(random_bytes(32));
    }
    header('Location: verify_email.php', true, 303);
    exit;
}
$error = '';
$pending = false;
$token = is_string($_SESSION['registration_token'] ?? null) ? $_SESSION['registration_token'] : '';
try {
    $pending = cbPendingRegistration($pdo, $token);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!cbAuthRequestValid()) throw new RuntimeException('Your form expired. Refresh and try again.');
        if (!cbRecoveryLimit($pdo,'signup-verify-ip',$_SERVER['REMOTE_ADDR'] ?? 'unknown',30,3600)
            || !cbRecoveryLimit($pdo,'signup-verify-token',hash('sha256',$token),10,900)) {
            throw new RuntimeException('Too many attempts. Please try again later.');
        }
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $account = cbCompleteRegistration($pdo, $token, $password);
        $_SESSION = [];
        if (!session_regenerate_id(true)) {
            header('Location: login.php', true, 303);
            exit;
        }
        $_SESSION = ['user_id'=>(int)$account['user_id'],'role_id'=>3,'first_name'=>$account['first_name'],
            'last_name'=>$account['last_name'],'email'=>$account['email'],'role_name'=>'Student',
            'student_credential_stamp'=>cbStaffCredentialStamp($account['password_hash'])];
        session_write_close();
        header('Location: dashboard.php', true, 303);
        exit;
    }
} catch (PDOException $exception) {
    error_log('CampusBite registration: verification_database_failed');
    $error = 'Email verification is temporarily unavailable. Please try again later.';
    $pending = false;
} catch (RuntimeException $exception) { $error = $exception->getMessage(); }
function verificationEscape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en"><head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">
    <title>Verify email - CampusBite</title>
    <link rel="stylesheet" href="login-food.css?v=3">
    <link rel="stylesheet" href="brand.css?v=<?= (int)filemtime(__DIR__.'/brand.css') ?>">
    <link rel="stylesheet" href="recovery.css?v=<?= (int)filemtime(__DIR__.'/recovery.css') ?>">
    <script src="recovery.js?v=<?= (int)filemtime(__DIR__.'/recovery.js') ?>" defer></script>
</head><body><main class="recovery-card" aria-labelledby="verification-title">
    <a class="cb-brand-logo recovery-brand" href="index.php" aria-label="CampusBite home"><?php require __DIR__ . '/components/brand-mark.php'; ?></a>
    <h1 id="verification-title">Finish verifying your email</h1>
    <?php require __DIR__ . '/components/project-identity.php'; ?>
    <?php if ($error!==''): ?><p class="error" role="alert"><?= verificationEscape($error) ?></p><?php endif; ?>
    <?php if ($pending): ?>
    <p class="description">Create your account for <strong><?= verificationEscape($pending['email']) ?></strong> by confirming the CampusBite password you chose during registration.</p>
    <form method="POST" action="verify_email.php">
        <input type="hidden" name="csrf_token" value="<?= verificationEscape(cbAuthToken()) ?>">
        <label for="verification-password">Your CampusBite password</label>
        <div class="password-field recovery-password-field">
            <input type="password" id="verification-password" name="password" autocomplete="new-password" maxlength="72" aria-describedby="verification-help" required>
            <button type="button" class="password-toggle recovery-toggle" data-recovery-toggle data-password-label="password" aria-controls="verification-password" aria-label="Show password" aria-pressed="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="recovery-eye-slash" d="M3 3l18 18"/></svg>
            </button>
        </div>
        <p class="recovery-note" id="verification-help">Not your email-account password. If you did not start this registration, do not continue.</p>
        <button class="login-button" type="submit">Verify email and create account</button>
    </form>
    <?php else: ?>
    <p class="description">This link is invalid, expired or already used. Verification links last 15 minutes.</p>
    <?php endif; ?>
    <a class="recovery-back" href="register.php">Start registration again</a>
    <a class="recovery-back" href="login.php">Back to sign in</a>
</main></body></html>
