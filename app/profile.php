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
$stmt = $pdo->prepare("SELECT user_id, id_number, first_name, last_name, email, contact_number, grade_section, password_hash, avatar_path FROM users WHERE user_id = ? AND role_id = 3 AND is_active = 1 LIMIT 1");
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

// The profile photo is chosen in Edit details and saved with the other details.
require_once __DIR__ . "/config/profile-photos.php";
// PHP drops the whole form when an upload is over the server limit, so an empty POST with a body is an oversized photo.
$photoTooLarge = $_SERVER["REQUEST_METHOD"] === "POST" && empty($_POST) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0;
if ($photoTooLarge) $error = "Choose a photo up to 3 MB.";

require_once __DIR__ . "/config/staff-password.php";
if (empty($_SESSION["student_password_csrf"])) {
    $_SESSION["student_password_csrf"] = bin2hex(random_bytes(32));
}
$passwordSuccess = $_SESSION["student_password_success"] ?? "";
unset($_SESSION["student_password_success"]);
$passwordError = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "change_password") {
    try {
        $token = $_POST["csrf_token"] ?? "";
        if (!is_string($token) || !hash_equals($_SESSION["student_password_csrf"], $token)) {
            throw new RuntimeException("Invalid request. Please refresh the page and try again.");
        }
        if (time() < (int) ($_SESSION["student_password_retry_after"] ?? 0)) {
            throw new RuntimeException("Too many attempts. Please wait a minute before trying again.");
        }
        $hash = cbChangeAccountPassword($pdo, (int) $profile["user_id"], 3, $_POST);
        // The new stamp keeps this session signed in while sessions on other devices are rejected.
        $_SESSION["student_credential_stamp"] = cbStaffCredentialStamp($hash);
        unset($_SESSION["student_password_csrf"], $_SESSION["student_profile_csrf"], $_SESSION["student_password_failures"], $_SESSION["student_password_retry_after"]);
        session_regenerate_id(true);
        $_SESSION["student_password_success"] = "Password changed. You're still signed in here, and other devices have been signed out.";
        header("Location: profile.php#change-password");
        exit;
    } catch (PDOException $exception) {
        error_log("Unable to change student password.");
        $passwordError = "Unable to change your password right now. Please try again.";
    } catch (RuntimeException $exception) {
        $passwordError = $exception->getMessage();
        if ($passwordError === "Your current password is incorrect.") {
            $_SESSION["student_password_failures"] = (int) ($_SESSION["student_password_failures"] ?? 0) + 1;
            if ($_SESSION["student_password_failures"] >= 5) {
                $_SESSION["student_password_retry_after"] = time() + 60;
                $_SESSION["student_password_failures"] = 0;
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$photoTooLarge && ($_POST["action"] ?? "") !== "change_password") {
    foreach (["first_name", "last_name", "email", "contact_number", "grade_section"] as $field) {
        $values[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : "";
    }
    $token = $_POST["csrf_token"] ?? "";
    $password = $_POST["current_password"] ?? "";
    $photoFile = $_FILES["profile_photo"] ?? [];
    $hasPhoto = ($photoFile["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $removePhoto = !$hasPhoto && ($_POST["remove_photo"] ?? "") === "1";
    $photoProblem = "";
    if ($hasPhoto) {
        try { cbValidateFoodPhoto($photoFile); } catch (RuntimeException $exception) { $photoProblem = $exception->getMessage(); }
    }
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
    } elseif ($photoProblem !== "") {
        $error = $photoProblem;
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
                    if ($hasPhoto || $removePhoto) {
                        try {
                            $oldPhoto = cbSaveProfilePhoto($pdo, (int) $profile["user_id"], $photoFile, $removePhoto);
                            if ($oldPhoto) cbStorageDelete($oldPhoto);
                        } catch (RuntimeException $exception) {
                            $_SESSION["student_profile_success"] = "Your details were saved, but the photo could not be: " . $exception->getMessage();
                            header("Location: profile.php");
                            exit;
                        }
                    }
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
// Details and password are shown read-only until the student chooses to edit them, or a save fails.
$editMode = is_string($_GET["edit"] ?? null) ? $_GET["edit"] : "";
$editingDetails = $editMode === "details" || $error !== "";
$changingPassword = $editMode === "password" || $passwordError !== "";
$initials = "";
foreach ([$profile["first_name"], $profile["last_name"]] as $namePart) {
    if (preg_match('/\S/u', (string) $namePart, $letter)) $initials .= strtoupper($letter[0]);
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
    <section class="profile-card profile-summary" aria-label="Your account">
        <div class="profile-summary-main">
            <?php if ($photoUrl = cbProfilePhotoUrl($profile["avatar_path"])): ?>
            <img class="profile-initial profile-photo" src="<?= profileEscape($photoUrl) ?>" alt="Your profile photo" width="76" height="76">
            <?php else: ?>
            <div class="profile-initial" aria-hidden="true"><?= profileEscape($initials) ?></div>
            <?php endif; ?>
            <div class="profile-identity">
                <h2><?= profileEscape($profile["first_name"] . " " . $profile["last_name"]) ?></h2>
                <p>Student &middot; ID <?= profileEscape($profile["id_number"]) ?></p>
            </div>
        </div>
    </section>
    <div class="profile-layout">
        <section class="profile-card profile-details-card" id="account-details" aria-labelledby="profile-details-title">
            <div class="profile-card-heading">
                <h2 id="profile-details-title">Account details</h2>
                <?php if (!$editingDetails): ?><a class="btn btn-secondary profile-edit-link" href="profile.php?edit=details#account-details">Edit details</a><?php endif; ?>
            </div>
            <?php if (!$editingDetails): ?>
            <dl class="profile-details">
                <div><dt>First name</dt><dd><?= profileEscape($profile["first_name"]) ?></dd></div>
                <div><dt>Last name</dt><dd><?= profileEscape($profile["last_name"]) ?></dd></div>
                <div><dt>Email</dt><dd><?= profileEscape($profile["email"]) ?></dd></div>
                <div><dt>Contact number</dt><dd><?= $profile["contact_number"] ? profileEscape($profile["contact_number"]) : '<span class="profile-empty">Not added</span>' ?></dd></div>
                <div class="profile-details-wide"><dt>School / course / section</dt><dd><?= $profile["grade_section"] ? profileEscape($profile["grade_section"]) : '<span class="profile-empty">Not added</span>' ?></dd></div>
            </dl>
            <p class="profile-note profile-fixed-note">Your account ID and role can't be changed here.</p>
            <?php else: ?>
            <form method="POST" action="profile.php" id="student-profile-form" data-profile-baseline="<?= profileEscape(json_encode(array_intersect_key($profile, array_flip(['first_name','last_name','email','contact_number','grade_section'])), JSON_THROW_ON_ERROR)) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= profileEscape($_SESSION["student_profile_csrf"]) ?>">
                <div class="profile-photo-edit">
                    <label class="profile-photo-picker" for="profile-photo-input">
                        <?php if ($photoUrl): ?>
                        <img class="profile-photo-preview" src="<?= profileEscape($photoUrl) ?>" alt="" width="112" height="112" data-profile-photo-preview>
                        <?php else: ?>
                        <span class="profile-photo-preview profile-photo-placeholder" data-profile-photo-preview><?= profileEscape($initials) ?></span>
                        <?php endif; ?>
                        <span class="profile-photo-badge" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg></span>
                    </label>
                    <input class="profile-photo-input" id="profile-photo-input" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="profile-photo-help" data-profile-photo-input>
                    <label class="profile-photo-change" for="profile-photo-input"><?= $photoUrl ? "Change profile photo" : "Add profile photo" ?></label>
                    <?php if ($photoUrl): ?><label class="profile-photo-remove"><input type="checkbox" name="remove_photo" value="1" data-profile-photo-remove> Remove photo</label><?php endif; ?>
                    <p class="profile-photo-help" id="profile-photo-help">Kitchen staff see this photo on your orders so they can hand your food to the right person. JPEG, PNG or WebP, up to 3 MB.</p>
                </div>
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
                <div class="profile-actions"><button class="btn" type="submit" id="profile-save">Save changes</button><a class="btn btn-secondary" href="profile.php#account-details">Cancel</a><span id="profile-save-state" role="status" aria-live="polite"></span></div>
            </form>
            <?php endif; ?>
        </section>
        <section class="profile-card profile-security-card" id="change-password" aria-labelledby="profile-password-title">
            <div class="profile-card-heading">
                <h2 id="profile-password-title">Password</h2>
                <?php if (!$changingPassword): ?><a class="btn btn-secondary profile-edit-link" href="profile.php?edit=password#change-password">Change password</a><?php endif; ?>
            </div>
            <?php if ($passwordSuccess !== ""): ?><p class="success-message" role="status"><?= profileEscape($passwordSuccess) ?></p><?php endif; ?>
            <?php if ($passwordError !== ""): ?><p class="error-message" role="alert"><?= profileEscape($passwordError) ?></p><?php endif; ?>
            <?php if (!$changingPassword): ?>
            <p class="profile-note">Use a password you don't use for your school account, email or GCash. Changing it signs you out on other devices.</p>
            <?php else: ?>
            <form method="POST" action="profile.php#change-password" class="profile-password-form">
                <input type="hidden" name="action" value="change_password"><input type="hidden" name="csrf_token" value="<?= profileEscape($_SESSION["student_password_csrf"]) ?>">
                <div><label for="password-current">Current password</label><input id="password-current" name="current_password" type="password" autocomplete="current-password" required></div>
                <div><label for="password-new">New password</label><input id="password-new" name="new_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" aria-describedby="password-new-help" required></div>
                <div><label for="password-confirm">Confirm new password</label><input id="password-confirm" name="confirm_password" type="password" autocomplete="new-password" minlength="8" maxlength="72" required></div>
                <p class="profile-note" id="password-new-help">At least 8 characters, different from your current password. You'll stay signed in on this device.</p>
                <div class="profile-actions"><button class="btn" type="submit">Change password</button><a class="btn btn-secondary" href="profile.php#change-password">Cancel</a></div>
            </form>
            <?php endif; ?>
        </section>
    </div>
    <div class="profile-logout">
        <a class="btn profile-logout-button" href="logout.php" data-logout-confirm>Log out</a>
    </div>
    <dialog class="profile-logout-dialog" id="logout-dialog" aria-labelledby="logout-dialog-title" aria-describedby="logout-dialog-text">
        <h2 id="logout-dialog-title">Log out?</h2>
        <p id="logout-dialog-text">Are you sure you want to log out? You'll need to sign in again, and items in your cart will be cleared.</p>
        <div class="profile-logout-actions">
            <button type="button" class="btn btn-secondary" data-logout-cancel>No</button>
            <a class="btn" href="logout.php">Yes, log out</a>
        </div>
    </dialog>
</main>
</body>
</html>
