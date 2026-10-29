<?php
$signup = $authMode === "register";
function authEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CampusBite - <?= $signup ? "Register" : "Login" ?></title>
    <link rel="stylesheet" href="login-food.css?v=3">
    <link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/brand.css') ?>">
    <link rel="stylesheet" href="auth-minimal.css?v=<?= (int) filemtime(__DIR__ . '/auth-minimal.css') ?>">
    <script src="auth-switch.js?v=3" defer></script>
</head>
<body>
<main class="auth-shell <?= $signup ? "sign-up-mode" : "" ?>" id="auth-shell">
    <aside class="food-panel">
        <div class="auth-brand"><a class="cb-brand-logo" href="login.php" aria-label="CampusBite login"><?php require __DIR__ . '/components/brand-mark.php'; ?></a><span class="cb-brand-tagline">THE CAMPUS KITCHEN</span></div>
        <div class="food-panel-copy" data-panel="login" <?= $signup ? "hidden" : "" ?>>
            <span class="food-eyebrow">GOOD FOOD. LESS WAITING.</span>
            <h2>Your next bite,<br>between classes.</h2>
            <p>Pre-order your canteen favorites and make more time for your lunch break.</p>
            <a class="food-register" href="register.php" data-switch="register">Create an account &rarr;</a>
        </div>
        <div class="food-panel-copy" data-panel="register" <?= !$signup ? "hidden" : "" ?>>
            <span class="food-eyebrow">A SEAT AT THE TABLE.</span>
            <h2>Good to have<br>you here.</h2>
            <p>Already part of CampusBite? Sign in and pick up where your appetite left off.</p>
            <a class="food-register" href="login.php" data-switch="login">Back to login &rarr;</a>
        </div>
        <div class="food-panel-footer">A little less queue. A little more you.</div>
    </aside>
    <div class="auth-forms">
        <section class="auth-form-view" id="login-view" <?= $signup ? "hidden" : "" ?> aria-labelledby="login-title">
            <span class="form-eyebrow">WELCOME BACK</span>
            <h1 id="login-title" tabindex="-1">Sign in to CampusBite</h1>
            <p class="description">Log in to your CampusBite account.</p>
            <?php if (!$signup && $error !== ""): ?>
                <div class="error" role="alert"><?= authEscape($error) ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= authEscape(cbAuthToken()) ?>">
                <label for="login-email">Email</label>
                <input id="login-email" type="email" name="email" autocomplete="username" placeholder="Enter your email" value="<?= !$signup ? authEscape($_POST["email"] ?? "") : "" ?>" required>
                <label for="login-password">Password</label>
                <div class="password-field">
                    <input id="login-password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="toggle-login-password" aria-label="Show password" aria-pressed="false" aria-controls="login-password">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <button type="submit" class="login-button">Login</button>
            </form>
        </section>
        <section class="auth-form-view" id="register-view" <?= !$signup ? "hidden" : "" ?> aria-labelledby="register-title">
            <span class="form-eyebrow">JOIN THE TABLE</span>
            <h1 id="register-title" tabindex="-1">Create your account</h1>
            <p class="description">Your campus favorites are one step closer.</p>
            <?php if ($signup && $error !== ""): ?>
                <div class="error" role="alert"><?= authEscape($error) ?></div>
            <?php endif; ?>
            <?php if ($signup && !empty($message)): ?>
                <div class="success" role="status"><?= authEscape($message) ?> <a href="login.php" data-switch="login">Login now</a></div>
            <?php endif; ?>
            <form method="POST" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= authEscape(cbAuthToken()) ?>">
                <div class="registration-grid">
                    <?php
                    $fields = [
                        ["id_number", "ID Number", "text", "Example: 2026-0105", true, "off"],
                        ["email", "Email", "email", "example@school.edu.ph", true, "email"],
                        ["first_name", "First Name", "text", "First name", true, "given-name"],
                        ["last_name", "Last Name", "text", "Last name", true, "family-name"],
                        ["contact_number", "Contact Number", "text", "09XXXXXXXXX", false, "tel"],
                        ["grade_section", "School / Course / Section", "text", "USPF / BSIT / 3A", false, "off"],
                        ["password", "Password", "password", "At least 8 characters", true, "new-password"],
                        ["confirm_password", "Confirm Password", "password", "Re-enter password", true, "new-password"]
                    ];
                    foreach ($fields as [$name, $label, $type, $placeholder, $required, $autocomplete]):
                    ?>
                    <div class="form-group">
                        <label for="register-<?= $name ?>"><?= $label ?><?= !$required ? ' <span class="optional">(optional)</span>' : '' ?></label>
                        <input id="register-<?= $name ?>" name="<?= $name ?>" type="<?= $type ?>" placeholder="<?= $placeholder ?>" autocomplete="<?= $autocomplete ?>" <?= $required ? "required" : "" ?> value="<?= $signup && $type !== "password" ? authEscape($_POST[$name] ?? "") : "" ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="login-button">Create Account</button>
            </form>
            <p class="register">Already have an account? <a href="login.php" data-switch="login">Login</a></p>
        </section>
    </div>
</main>
</body>
</html>
