<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');
ob_start();
require_once __DIR__ . "/config/connection.php";
ob_end_clean();

if (!isset($_SESSION["user_id"]) || (int) ($_SESSION["role_id"] ?? 0) !== 3) {
    header("Location: login.php");
    exit;
}
$stmt = $pdo->prepare("SELECT user_id, id_number, first_name, last_name, email, contact_number, grade_section, password_hash FROM users WHERE user_id = ? AND role_id = 3 AND is_active = 1 LIMIT 1");
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
if (empty($_SESSION["student_profile_csrf"])) {
    $_SESSION["student_profile_csrf"] = bin2hex(random_bytes(32));
}
$success = $_SESSION["student_profile_success"] ?? "";
unset($_SESSION["student_profile_success"]);
$error = "";
$values = $profile;
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    foreach (["first_name", "last_name", "email", "contact_number", "grade_section"] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : "";
    }
    $token = $_POST["csrf_token"] ?? "";
    $password = $_POST["current_password"] ?? "";
    if (!is_string($token) || !hash_equals($_SESSION["student_profile_csrf"], $token)) {
        $error = "Invalid request. Please refresh the page and try again.";
    } elseif ($values["first_name"] === "" || $values["last_name"] === "") {
        $error = "Enter your first and last name.";
    } elseif (!preg_match('/^.{1,50}$/us', $values["first_name"]) || !preg_match('/^.{1,50}$/us', $values["last_name"])) {
        $error = "Each name must be no more than 50 characters.";
    } elseif (strlen($values["email"]) > 100 || !filter_var($values["email"], FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address, no more than 100 characters.";
    } elseif ($values["contact_number"] !== "" && !preg_match('/^[0-9+() .-]{7,15}$/', $values["contact_number"])) {
        $error = "Enter a valid contact number (7 to 15 characters), or leave it blank.";
    } elseif (!preg_match('/^.{0,30}$/us', $values["grade_section"])) {
        $error = "School / course / section must be no more than 30 characters.";
    } elseif (!is_string($password) || !password_verify($password, $profile["password_hash"])) {
        $error = "Your current password is incorrect.";
    } else {
        try {
            $duplicate = $pdo->prepare("SELECT user_id FROM users WHERE lower(email) = lower(?) AND user_id <> ? LIMIT 1");
            $duplicate->execute([$values["email"], $profile["user_id"]]);
            if ($duplicate->fetchColumn()) {
                $error = "That email address belongs to another account.";
            } else {
                $update = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, contact_number = ?, grade_section = ? WHERE user_id = ? AND role_id = 3 AND is_active = 1 AND password_hash = ?");
                $update->execute([$values["first_name"], $values["last_name"], $values["email"], $values["contact_number"] ?: null, $values["grade_section"] ?: null, $profile["user_id"], $profile["password_hash"]]);
                $refresh = $pdo->prepare("SELECT first_name, last_name, email, contact_number, grade_section FROM users WHERE user_id = ? AND role_id = 3 AND is_active = 1");
                $refresh->execute([$profile["user_id"]]);
                $saved = $refresh->fetch(PDO::FETCH_ASSOC);
                if (!$saved || $saved["email"] !== $values["email"] || $saved["first_name"] !== $values["first_name"] || $saved["last_name"] !== $values["last_name"] || (string) $saved["contact_number"] !== $values["contact_number"] || (string) $saved["grade_section"] !== $values["grade_section"]) {
                    $error = "Your account changed while saving. Refresh and try again.";
                } else {
                    foreach ($saved as $field => $value) {
                        $_SESSION[$field] = $value;
                    }
                    $_SESSION["student_profile_csrf"] = bin2hex(random_bytes(32));
                    $_SESSION["student_profile_success"] = "Your profile has been updated.";
                    header("Location: profile.php");
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Profile - CampusBite</title>
    <link rel="stylesheet" href="student-pages.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="admin-profile.css?v=1">
    <link rel="stylesheet" href="profile-refresh.css?v=<?= (int) filemtime(__DIR__ . '/profile-refresh.css') ?>">
    <script src="profile-refresh.js?v=<?= (int) filemtime(__DIR__ . '/profile-refresh.js') ?>" defer></script>
</head>
<body class="cb-student cb-profile" data-profile-user="<?= (int) $_SESSION['user_id'] ?>">
<?php require __DIR__ . "/components/student-navbar.php"; ?>
<main class="container" id="profile-content">
    <header class="page-title">
        <span class="cb-page-eyebrow">YOUR ACCOUNT. YOUR DETAILS.</span>
        <h1>My <span>profile.</span></h1>
        <p>Keep your student profile up to date.</p>
    </header>
    <?php if ($success !== ""): ?>
        <div class="success-message" role="status" data-profile-notice="success" tabindex="-1"><?= profileEscape($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ""): ?>
        <div class="error-message" role="alert" data-profile-notice="error" tabindex="-1"><?= profileEscape($error) ?></div>
    <?php endif; ?>
    <div class="profile-layout">
        <aside class="profile-card profile-summary">
            <div class="profile-initial" aria-hidden="true"><?= profileEscape(strtoupper(substr($profile["first_name"], 0, 1))) ?></div>
            <h2><?= profileEscape($profile["first_name"] . " " . $profile["last_name"]) ?></h2>
            <p>Student</p>
            <dl>
                <dt>Account ID</dt><dd><?= profileEscape($profile["id_number"]) ?></dd>
            </dl>
            <p class="profile-note">Your account ID, role, and access permissions cannot be changed here.</p>
        </aside>
        <section class="profile-card" aria-labelledby="profile-details-title">
            <h2 id="profile-details-title">Account details</h2>
            <p class="profile-note">Update your information below. Your account ID and role stay the same.</p>
            <form method="POST" action="profile.php" id="student-profile-form" data-profile-baseline="<?= profileEscape(json_encode(array_intersect_key($profile, array_flip(['first_name','last_name','email','contact_number','grade_section'])), JSON_THROW_ON_ERROR)) ?>">
                <input type="hidden" name="csrf_token" value="<?= profileEscape($_SESSION["student_profile_csrf"]) ?>">
                <fieldset class="profile-group"><legend>Personal information</legend><div class="profile-fields">
                    <div><label for="first-name">First name</label><input id="first-name" name="first_name" autocomplete="given-name" maxlength="50" required value="<?= profileEscape($values["first_name"]) ?>"></div>
                    <div><label for="last-name">Last name</label><input id="last-name" name="last_name" autocomplete="family-name" maxlength="50" required value="<?= profileEscape($values["last_name"]) ?>"></div>
                    <div><label for="profile-email">Email</label><input id="profile-email" type="email" name="email" autocomplete="email" maxlength="100" required value="<?= profileEscape($values["email"]) ?>"></div>
                    <div><label for="contact-number">Contact number <span>(optional)</span></label><input id="contact-number" type="tel" name="contact_number" autocomplete="tel" maxlength="15" value="<?= profileEscape($values["contact_number"]) ?>"></div>
                </div></fieldset>
                <fieldset class="profile-group"><legend>School details</legend>
                    <label for="grade-section">School / course / section <span>(optional)</span></label><input id="grade-section" name="grade_section" maxlength="30" value="<?= profileEscape($values["grade_section"]) ?>">
                </fieldset>
                <div class="profile-password">
                    <label for="current-password">Confirm with your current password</label>
                    <p id="profile-password-help" class="profile-password-help">Required to authorize your changes. This does not change your password.</p>
                    <div class="profile-password-field"><input id="current-password" type="password" name="current_password" autocomplete="current-password" aria-describedby="profile-password-help" required><button type="button" data-profile-password-toggle aria-label="Show password" aria-pressed="false" aria-controls="current-password">Show</button></div>
                </div>
                <div class="profile-actions"><button class="btn" type="submit" id="profile-save">Save changes</button><span id="profile-save-state" role="status" aria-live="polite"></span></div>
            </form>
        </section>
    </div>
</main>
</body>
</html>
