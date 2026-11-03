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
    <?php if ($resetView && $resetAllowed): ?><script src="recovery.js?v=<?= (int)filemtime(__DIR__.'/recovery.js') ?>" defer></script><?php endif; ?>
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
        <?php foreach ([['recovery-password','new_password','New password'],['recovery-confirm','confirm_password','Confirm new password']] as [$id,$name,$label]): ?>
        <label for="<?= $id ?>"><?= $label ?></label>
        <div class="password-field recovery-password-field">
            <input id="<?= $id ?>" name="<?= $name ?>" type="password" minlength="8" maxlength="72" autocomplete="new-password" aria-describedby="recovery-help" required>
            <button type="button" class="password-toggle recovery-toggle" data-recovery-toggle data-password-label="<?= strtolower($label) ?>" aria-controls="<?= $id ?>" aria-label="Show <?= strtolower($label) ?>" aria-pressed="false">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle><path class="recovery-eye-slash" d="M3 3l18 18"></path></svg>
            </button>
        </div>
        <?php endforeach; ?>
        <p class="recovery-note" id="recovery-help">At least 8 characters, up to 72 bytes. Do not reuse your school, email, or GCash password.</p>
        <button class="login-button" type="submit">Reset password</button>
    </form>
    <?php elseif ($message===''): ?><a class="recovery-link" href="forgot_password.php">Request a new reset link</a><?php endif; ?>
    <a class="recovery-back" href="login.php">Back to sign in</a>
</main>
</body>
</html>
