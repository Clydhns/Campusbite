<?php
if (session_status() === PHP_SESSION_NONE) session_start();
// The existing database include emits output; buffer it so redirects remain valid.
ob_start();
require_once __DIR__ . "/config/connection.php";
ob_end_clean();

if (!isset($_SESSION["user_id"]) || (int) ($_SESSION["role_id"] ?? 0) !== 2) {
    header("Location: login.php");
    exit;
}
$stmt = $pdo->prepare("SELECT user_id, id_number, first_name, last_name, email, contact_number, password_hash FROM users WHERE user_id = ? AND role_id = 2 AND is_active = 1 LIMIT 1");
$stmt->execute([(int) $_SESSION["user_id"]]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    $_SESSION = [];
    session_destroy();
    header("Location: login.php");
    exit;
}
function profileEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
if (empty($_SESSION["staff_profile_csrf"])) {
    $_SESSION["staff_profile_csrf"] = bin2hex(random_bytes(32));
}
$success = $_SESSION["staff_profile_success"] ?? "";
unset($_SESSION["staff_profile_success"]);
$error = "";
$values = $profile;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach (["first_name", "last_name", "email", "contact_number"] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : "";
    }
    $token = $_POST["csrf_token"] ?? "";
    $password = $_POST["current_password"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["staff_profile_csrf"], $token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif ($values["first_name"] === "" || $values["last_name"] === "") {
        $error = "Enter your first and last name.";
    } elseif (!preg_match('/^.{1,50}$/us', $values["first_name"]) || !preg_match('/^.{1,50}$/us', $values["last_name"])) {
        $error = "Each name must be no more than 50 characters.";
    } elseif (strlen($values["email"]) > 100 || !filter_var($values["email"], FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address, no more than 100 characters.";
    } elseif ($values["contact_number"] !== "" && !preg_match('/^[0-9+() .-]{7,15}$/', $values["contact_number"])) {
        $error = "Enter a valid contact number (7 to 15 characters), or leave it blank.";
    } elseif (!is_string($password) || !password_verify($password, $profile["password_hash"])) {
        $error = "Your current password is incorrect.";
    } else {
        try {
            $duplicate = $pdo->prepare("SELECT user_id FROM users WHERE lower(email) = lower(?) AND user_id <> ? LIMIT 1");
            $duplicate->execute([$values["email"], $profile["user_id"]]);
            if ($duplicate->fetchColumn()) {
                $error = "That email address belongs to another account.";
            } else {
                $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ? WHERE user_id = ? AND role_id = 2 AND is_active = 1 AND password_hash = ?");
                $update->execute([$values["first_name"], $values["last_name"], $values["email"], $values["contact_number"] !== "" ? $values["contact_number"] : null, $profile["user_id"], $profile["password_hash"]]);
                $refresh = $pdo->prepare("SELECT first_name, last_name, email, contact_number FROM users WHERE user_id = ? AND role_id = 2 AND is_active = 1");
                $refresh->execute([$profile["user_id"]]);
                $saved = $refresh->fetch(PDO::FETCH_ASSOC);
                if (!$saved || $saved["email"] !== $values["email"] || $saved["first_name"] !== $values["first_name"] || $saved["last_name"] !== $values["last_name"] || (string) $saved["contact_number"] !== $values["contact_number"]) {
                    $error = "Your account changed while saving. Refresh and try again.";
                } else {
                    foreach ($saved as $field => $value) {
                        $_SESSION[$field] = $value;
                    }
                    $_SESSION["staff_profile_csrf"] = bin2hex(random_bytes(32));
                    $_SESSION["staff_profile_success"] = "Your profile has been updated.";
                    header("Location: staff_profile.php");
                    exit;
                }
            }
        } catch (PDOException $exception) {
            error_log($exception->getMessage());
            $error = "Unable to save your profile. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - CampusBite</title>
    <link rel="stylesheet" href="student-pages.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="admin-profile.css?v=1">
    <link rel="stylesheet" href="staff-navbar.css?v=<?= (int) filemtime(__DIR__ . '/staff-navbar.css') ?>">
</head>
<body class="cb-student">
<?php require __DIR__ . '/components/staff-navbar.php'; ?>
<main class="container">
    <header class="page-title">
        <span class="cb-page-eyebrow">YOUR ACCOUNT. YOUR DETAILS.</span>
        <h1>Make yourself at home.</h1>
        <p>Keep your staff profile up to date.</p>
    </header>
    <?php if ($success !== ""): ?>
        <div class="success-message" role="status"><?= profileEscape($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <div class="error-message" role="alert"><?= profileEscape($error) ?></div>
    <?php endif; ?>
    <div class="profile-layout">
        <aside class="profile-card profile-summary">
            <div class="profile-initial" aria-hidden="true"><?= profileEscape(strtoupper(substr($profile["first_name"], 0, 1))) ?></div>
            <h2><?= profileEscape($profile["first_name"] . " " . $profile["last_name"]) ?></h2>
            <p>Canteen Staff</p>
            <dl>
                <dt>Account ID</dt><dd><?= profileEscape($profile["id_number"]) ?></dd>
                <dt>Email</dt><dd><?= profileEscape($profile["email"]) ?></dd>
            </dl>
            <p class="profile-note">Your account ID, role, and access permissions cannot be changed here.</p>
        </aside>
        <section class="profile-card" aria-labelledby="profile-details-title">
            <h2 id="profile-details-title">Personal details</h2>
            <p class="profile-note">Confirm with your current password to save changes.</p>
            <form method="POST" action="staff_profile.php">
                <input type="hidden" name="csrf_token" value="<?= profileEscape($_SESSION["staff_profile_csrf"]) ?>">
                <div class="profile-fields">
                    <div><label for="first-name">First name</label><input id="first-name" name="first_name" autocomplete="given-name" maxlength="50" required value="<?= profileEscape($values["first_name"]) ?>"></div>
                    <div><label for="last-name">Last name</label><input id="last-name" name="last_name" autocomplete="family-name" maxlength="50" required value="<?= profileEscape($values["last_name"]) ?>"></div>
                    <div><label for="profile-email">Email</label><input id="profile-email" type="email" name="email" autocomplete="email" maxlength="100" required value="<?= profileEscape($values["email"]) ?>"></div>
                    <div><label for="contact-number">Contact number <span>(optional)</span></label><input id="contact-number" type="tel" name="contact_number" autocomplete="tel" maxlength="15" value="<?= profileEscape($values["contact_number"]) ?>"></div>
                </div>
                <div class="profile-password"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div>
                <div class="profile-actions"><button class="btn" type="submit">Save changes</button><a class="btn btn-secondary" href="staff_dashboard.php">Back to dashboard</a></div>
            </form>
        </section>
    </div>
</main>
</body>
</html>
