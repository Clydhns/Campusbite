<?php
function recoveryEscape($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$title = $resetView ? 'Choose a new password' : 'Forgot your password?';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= recoveryEscape($title) ?> - CampusBite</title>
    <link rel="stylesheet" href="login-food.css?v=3">
    <link rel="stylesheet" href="brand.css?v=<?= (int)filemtime(__DIR__.'/brand.css') ?>">
    <link rel="stylesheet" href="recovery.css?v=<?= (int)filemtime(__DIR__.'/recovery.css') ?>">
</head>
<body>
<main class="recovery-card" aria-labelledby="recovery-title">
    <a class="cb-brand-logo recovery-brand" href="login.php" aria-label="CampusBite login"><?php require __DIR__ . '/components/brand-mark.php'; ?></a>
    <span class="form-eyebrow">YOUR ACCOUNT. A FRESH START.</span>
    <h1 id="recovery-title"><?= recoveryEscape($title) ?></h1>
    <p class="description"><?= $resetView ? 'Create a unique password for your CampusBite account.' : 'Enter your account email. Recovery is available for students, staff, and administrators.' ?></p>
    <?php if ($error!==''): ?><p class="error" role="alert"><?= recoveryEscape($error) ?></p><?php endif; ?>
    <?php if ($message!==''): ?><p class="success" role="status"><?= recoveryEscape($message) ?></p><?php endif; ?>
    <?php if (!$resetView): ?>
    <form method="POST" action="forgot_password.php">
        <input type="hidden" name="csrf_token" value="<?= recoveryEscape(cbAuthToken()) ?>">
        <label for="recovery-email">CampusBite account email</label>
        <input id="recovery-email" name="email" type="email" maxlength="100" autocomplete="email" required>
        <button class="login-button" type="submit">Send reset link</button>
    </form>
    <p class="recovery-note">If no email arrives, check spam and wait a minute before retrying. For your privacy, we cannot confirm whether an email is registered.</p>
    <?php elseif ($resetAllowed): ?>
    <form method="POST" action="reset_password.php">
        <input type="hidden" name="csrf_token" value="<?= recoveryEscape(cbAuthToken()) ?>">
        <label for="recovery-password">New password</label>
        <input id="recovery-password" name="new_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="recovery-help" required>
        <label for="recovery-confirm">Confirm new password</label>
        <input id="recovery-confirm" name="confirm_password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
        <p class="recovery-note" id="recovery-help">At least 8 characters, up to 72 bytes. Do not reuse your school, email, or GCash password.</p>
        <button class="login-button" type="submit">Reset password</button>
    </form>
    <?php elseif ($message===''): ?><a class="recovery-link" href="forgot_password.php">Request a new reset link</a><?php endif; ?>
    <a class="recovery-back" href="login.php">Back to sign in</a>
</main>
</body>
</html>
