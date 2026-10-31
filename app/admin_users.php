<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";

$isAccountRequest = $_SERVER["REQUEST_METHOD"] === "POST"
    && ($_SERVER["HTTP_X_CAMPUSBITE_ACCOUNTS"] ?? "") === "1";

function cbAdminAccountResponse(array $payload, int $status = 200): void {
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// ADMIN ACCESS
if (!isset($_SESSION["user_id"])) {
    if ($isAccountRequest) cbAdminAccountResponse(["ok" => false, "message" => "Your session expired. Sign in again.", "sessionChanged" => true], 401);
    header("Location: login.php");
    exit;
}

if (($_SESSION["role_name"] ?? "") !== "Administrator") {
    if ($isAccountRequest) cbAdminAccountResponse(["ok" => false, "message" => "Administrator access is required. Sign in again.", "sessionChanged" => true], 403);
    header("Location: dashboard.php");
    exit;
}

$admin_id = (int) $_SESSION["user_id"];
$success = "";
$error = "";
$staffForm = [];
$actionType = "";
$actionUserId = 0;

if (
    $_SERVER["REQUEST_METHOD"] === "GET" &&
    isset($_SESSION["admin_users_flash"])
) {
    $flash = $_SESSION["admin_users_flash"];
    unset($_SESSION["admin_users_flash"]);
    $success = $flash["success"] ?? "";
    $error = $flash["error"] ?? "";
    $staffForm = $flash["staff_form"] ?? [];
    $actionType = $flash["action_type"] ?? "";
    $actionUserId = (int) ($flash["action_user_id"] ?? 0);
}

if (empty($_SESSION["admin_csrf"])) {
    $_SESSION["admin_csrf"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["admin_csrf"];

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function validPassword($password) {
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[^a-zA-Z0-9]/', $password);
}

// GET CURRENT ADMIN
$stmt = $pdo->prepare("
    SELECT user_id, first_name, last_name,
           email, password_hash
    FROM users
    WHERE user_id = :id
      AND role_id = 1
      AND is_active = 1
    LIMIT 1
");
$stmt->execute(["id" => $admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    session_destroy();
    if ($isAccountRequest) cbAdminAccountResponse(["ok" => false, "message" => "Your administrator session is no longer active. Sign in again.", "sessionChanged" => true], 401);
    header("Location: login.php");
    exit;
}

// HANDLE ACTIONS
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!is_string($_POST["csrf_token"] ?? null) || !hash_equals(
        $csrf,
        $_POST["csrf_token"] ?? ""
    )) {
        $error = "Invalid request. Please refresh.";
    } else {
        $action = $_POST["action"] ?? "";

        try {
            if ($isAccountRequest && !in_array($action, ["toggle_user", "delete_user"], true)) {
                throw new Exception("Unsupported account action.");
            }

            // CREATE STAFF
            if ($action === "create_staff") {
                $id_number = trim($_POST["id_number"] ?? "");
                $first_name = trim($_POST["first_name"] ?? "");
                $last_name = trim($_POST["last_name"] ?? "");
                $email = trim($_POST["email"] ?? "");
                $contact = trim($_POST["contact_number"] ?? "");
                $password = $_POST["password"] ?? "";
                $confirm = $_POST["confirm_password"] ?? "";
                $staffForm = [
                    "id_number" => $id_number,
                    "first_name" => $first_name,
                    "last_name" => $last_name,
                    "email" => $email,
                    "contact_number" => $contact
                ];

                if (
                    $id_number === "" ||
                    $first_name === "" ||
                    $last_name === "" ||
                    $email === "" ||
                    $password === ""
                ) {
                    throw new Exception(
                        "Complete all required fields."
                    );
                }

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Invalid email.");
                }

                if (!validPassword($password)) {
                    throw new Exception(
                        "Password needs 8 characters, an uppercase letter and a special character."
                    );
                }

                if ($password !== $confirm) {
                    throw new Exception(
                        "Passwords do not match."
                    );
                }

                $check = $pdo->prepare("
                    SELECT user_id FROM users
                    WHERE lower(email) = lower(:email)
                       OR id_number = :id_number
                    LIMIT 1
                ");
                $check->execute([
                    "email" => $email,
                    "id_number" => $id_number
                ]);

                if ($check->fetch()) {
                    throw new Exception(
                        "Email or ID number already exists."
                    );
                }

                $hash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $pdo->beginTransaction();
                $insert = $pdo->prepare("
                    INSERT INTO users
                    (role_id, id_number, first_name,
                     last_name, email, password_hash,
                     contact_number, grade_section, is_active)
                    VALUES
                    (2, :id_number, :first_name,
                     :last_name, :email, :password_hash,
                     :contact_number, NULL, 1) RETURNING user_id
                ");

                $insert->execute([
                    "id_number" => $id_number,
                    "first_name" => $first_name,
                    "last_name" => $last_name,
                    "email" => $email,
                    "password_hash" => $hash,
                    "contact_number" =>
                        $contact !== "" ? $contact : null
                ]);

                cbEnsureStore($pdo, (int) $insert->fetchColumn());
                $pdo->commit();
                $success = "Staff account and store created successfully.";
            }

            // ACTIVATE / DEACTIVATE
            elseif ($action === "toggle_user") {
                $user_id = (int) ($_POST["user_id"] ?? 0);

                if ($user_id <= 0 || $user_id === $admin_id) {
                    throw new Exception(
                        "You cannot change this account."
                    );
                }

                $desiredActive = $_POST["is_active"] ?? null;
                if (!in_array($desiredActive, ["0", "1"], true)) {
                    throw new Exception("Invalid account status. Refresh the page and try again.");
                }

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET is_active = :active
                    WHERE user_id = :id
                ");
                $stmt->execute(["id" => $user_id, "active" => (int) $desiredActive]);

                $check = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
                $check->execute([$user_id]);
                if (!$check->fetchColumn()) {
                    throw new Exception("No matching user found. Refresh the account list.");
                }
                $success = (int) $desiredActive === 1 ? "Account activated successfully." : "Account deactivated successfully.";
                $actionType = "updated";
                $actionUserId = $user_id;
            }

            // DELETE STAFF OR STUDENT ACCOUNT
            elseif ($action === "delete_user") {
                $user_id = (int) ($_POST["user_id"] ?? 0);

                if ($user_id <= 0 || $user_id === $admin_id) {
                    throw new Exception(
                        "You cannot delete this account."
                    );
                }

                $target = $pdo->prepare("
                    SELECT user_id, role_id, first_name, last_name
                    FROM users
                    WHERE user_id = :id
                    LIMIT 1
                ");
                $target->execute(["id" => $user_id]);
                $targetUser = $target->fetch(PDO::FETCH_ASSOC);

                if (
                    !$targetUser ||
                    !in_array((int) $targetUser["role_id"], [2, 3], true)
                ) {
                    throw new Exception(
                        "Only staff or student accounts can be deleted."
                    );
                }

                if ((int) $targetUser["role_id"] === 2) {
                    $ownedStore = $pdo->prepare("SELECT store_id FROM stores WHERE staff_user_id=?");
                    $ownedStore->execute([$user_id]);
                    if ($ownedStore->fetchColumn()) throw new Exception("This staff account owns a store. Deactivate it to preserve its menus and orders.");
                }
                if ((int) $targetUser["role_id"] === 3) {
                    $orders = $pdo->prepare("
                        SELECT 1
                        FROM orders
                        WHERE student_id = :id
                        LIMIT 1
                    ");
                    $orders->execute(["id" => $user_id]);

                    if ($orders->fetchColumn()) {
                        throw new Exception(
                            "This student has order history and cannot be deleted. Deactivate the account to preserve its records."
                        );
                    }
                }

                $delete = $pdo->prepare("
                    DELETE FROM users
                    WHERE user_id = :id
                      AND role_id IN (2, 3)
                ");
                $delete->execute(["id" => $user_id]);

                if ($delete->rowCount() !== 1) {
                    throw new Exception(
                        "The account could not be deleted."
                    );
                }

                $success = "Account deleted successfully.";
                $actionType = "deleted";
                $actionUserId = $user_id;
            }

            // UPDATE ADMIN ACCOUNT
            elseif ($action === "update_admin") {
                $email = trim($_POST["admin_email"] ?? "");
                $current = $_POST["current_password"] ?? "";
                $new = $_POST["new_password"] ?? "";
                $confirm = $_POST["confirm_new_password"] ?? "";

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception("Enter a valid email.");
                }

                if (!password_verify(
                    $current,
                    $admin["password_hash"]
                )) {
                    throw new Exception(
                        "Current password is incorrect."
                    );
                }

                $check = $pdo->prepare("
                    SELECT user_id FROM users
                    WHERE lower(email) = lower(:email)
                      AND user_id <> :id
                    LIMIT 1
                ");
                $check->execute([
                    "email" => $email,
                    "id" => $admin_id
                ]);

                if ($check->fetch()) {
                    throw new Exception(
                        "Email already belongs to another user."
                    );
                }

                if ($new !== "") {
                    if (!validPassword($new)) {
                        throw new Exception(
                            "New password needs 8 characters, an uppercase letter and a special character."
                        );
                    }

                    if ($new !== $confirm) {
                        throw new Exception(
                            "New passwords do not match."
                        );
                    }

                    $hash = password_hash(
                        $new,
                        PASSWORD_DEFAULT
                    );

                    $update = $pdo->prepare("
                        UPDATE users
                        SET email = :email,
                            password_hash = :hash
                        WHERE user_id = :id
                          AND role_id = 1
                    ");
                    $update->execute([
                        "email" => $email,
                        "hash" => $hash,
                        "id" => $admin_id
                    ]);
                } else {
                    $update = $pdo->prepare("
                        UPDATE users
                        SET email = :email
                        WHERE user_id = :id
                          AND role_id = 1
                    ");
                    $update->execute([
                        "email" => $email,
                        "id" => $admin_id
                    ]);
                }

                $_SESSION["admin_csrf"] =
                    bin2hex(random_bytes(32));
                $csrf = $_SESSION["admin_csrf"];

                $admin["email"] = $email;
                $success = "Admin credentials updated successfully.";
            }

        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $ex->getMessage();
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !$isAccountRequest) {
    $_SESSION["admin_users_flash"] = [
        "success" => $success,
        "error" => $error,
        "staff_form" => $staffForm,
        "action_type" => $actionType,
        "action_user_id" => $actionUserId
    ];
    header("Location: admin_users.php");
    exit;
}

// FETCH USERS
$users = $pdo->query("
    SELECT u.user_id, u.role_id, u.id_number,
           u.first_name, u.last_name,
           u.email, u.is_active,
           r.role_name
    FROM users u
    INNER JOIN roles r ON u.role_id = r.role_id
    ORDER BY u.user_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// STATISTICS
$counts = [
    "Administrator" => 0,
    "Canteen Staff" => 0,
    "Students" => 0
];

foreach ($users as $user) {
    if ($user["role_name"] === "Administrator") {
        $counts["Administrator"]++;
    } elseif (
        $user["role_name"] === "Canteen Staff"
    ) {
        $counts["Canteen Staff"]++;
    } else {
        $counts["Students"]++;
    }
}

if ($isAccountRequest) {
    $rowHtml = "";
    if ($error === "" && $actionType === "updated") {
        foreach ($users as $user) {
            if ((int) $user["user_id"] === $actionUserId) {
                ob_start();
                require __DIR__ . "/components/admin-user-row.php";
                $rowHtml = ob_get_clean();
                break;
            }
        }
    }
    cbAdminAccountResponse([
        "ok" => $error === "",
        "message" => $error !== "" ? $error : $success,
        "adminId" => $admin_id,
        "action" => $actionType,
        "userId" => $actionUserId,
        "rowHtml" => $rowHtml,
        "counts" => $counts
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Campusbite | User Management</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f7f7f7;
    color: #252525;
}

.navbar {
    background: #fff;
    border-bottom: 1px solid #eee;
    padding: 16px 5%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.brand {
    color: #ff7900;
    font-size: 25px;
    font-weight: bold;
    text-decoration: none;
}

.nav-links {
    display: flex;
    gap: 12px;
    align-items: center;
}

.nav-links a {
    text-decoration: none;
    color: #333;
    padding: 10px 14px;
    font-weight: bold;
    border-radius: 8px;
}

.nav-links a:hover {
    background: #fff0df;
}

.nav-links .active {
    background: #ff7900;
    color: white;
}

.container {
    width: 92%;
    max-width: 1200px;
    margin: 30px auto;
}

h1 {
    font-size: 30px;
    margin-bottom: 8px;
}

.subtitle {
    color: #777;
    margin-bottom: 28px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.stat,
.card {
    background: white;
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(0,0,0,.05);
}

.stat {
    padding: 22px;
}

.stat span {
    display: block;
    color: #777;
    font-size: 13px;
}

.stat strong {
    display: block;
    margin-top: 10px;
    color: #ff7900;
    font-size: 29px;
}

.card {
    margin-bottom: 25px;
    overflow: hidden;
}

.card-header {
    padding: 22px;
    border-bottom: 1px solid #eee;
}

.card-header h2 {
    margin: 0 0 8px;
    font-size: 21px;
}

.card-header p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.card-content {
    padding: 22px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

label {
    font-size: 13px;
    font-weight: bold;
}

input {
    width: 100%;
    border: 1px solid #d5d5d5;
    border-radius: 8px;
    padding: 12px;
    font-size: 14px;
}

input:focus {
    outline: none;
    border-color: #ff7900;
}

.password-input {
    position: relative;
}

.password-input input {
    padding-right: 48px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 8px;
    display: grid;
    width: 32px;
    height: 32px;
    place-items: center;
    padding: 0;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #666;
    cursor: pointer;
    transform: translateY(-50%);
}

.password-toggle:hover {
    color: #df6800;
    background: #fff0df;
}

.password-toggle:focus-visible {
    outline: 2px solid #df6800;
    outline-offset: 2px;
}

.password-toggle svg {
    width: 20px;
    height: 20px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.note {
    color: #777;
    font-size: 12px;
    line-height: 1.5;
}

.btn {
    background: #ff7900;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 12px 20px;
    cursor: pointer;
    font-weight: bold;
}

.btn:hover {
    background: #df6800;
}

.submit-area {
    margin-top: 20px;
}

.message {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
}

.message-notice {
    position: fixed;
    top: 20px;
    right: 24px;
    z-index: 20;
    width: min(420px, calc(100% - 32px));
    margin: 0;
    box-shadow: 0 8px 28px rgba(0, 0, 0, .14);
    animation: notice-life 5.3s ease forwards;
}

.success {
    background: #e8f7ea;
    color: #247b32;
}

.error {
    background: #ffe6e6;
    color: #ad2424;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    border-collapse: collapse;
    width: 100%;
    text-align: left;
}

th,
td {
    padding: 15px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

th {
    background: #fafafa;
    color: #666;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    background: #f0f0f0;
    font-size: 12px;
}

.status-active {
    background: #e5f6e8;
    color: #247b32;
}

.status-inactive {
    background: #ffeded;
    color: #bd3333;
}

.action-btn {
    border: 0;
    padding: 8px 11px;
    border-radius: 7px;
    font-weight: bold;
    cursor: pointer;
}

.activate {
    background: #e5f6e8;
    color: #247b32;
}

.deactivate {
    background: #ffeded;
    color: #bd3333;
}

tr.account-pending {
    opacity: .55;
    transition: opacity .2s ease;
}

tr.account-updated td {
    animation: account-row-highlight 2.4s ease-out;
}

tr.account-removing {
    animation: account-row-remove .28s ease forwards;
}

@keyframes notice-life {
    0% { opacity: 0; transform: translateY(-10px); }
    8%, 86% { opacity: 1; transform: translateY(0); }
    100% { opacity: 0; transform: translateY(-6px); }
}

@keyframes account-row-highlight {
    0% { background-color: #fff0df; }
    100% { background-color: transparent; }
}

@keyframes account-row-remove {
    to { opacity: 0; transform: translateX(12px); }
}

.delete-confirmation {
    width: min(420px, calc(100% - 32px));
    padding: 24px;
    border: 0;
    border-radius: 14px;
    color: #252525;
    box-shadow: 0 16px 48px rgba(0, 0, 0, .24);
}

.delete-confirmation::backdrop {
    background: rgba(25, 20, 16, .48);
}

.delete-confirmation h2 {
    margin: 0 0 10px;
    font-size: 20px;
}

.delete-confirmation p {
    margin: 0;
    color: #666;
    line-height: 1.5;
}

.confirmation-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
}

.confirmation-actions button {
    padding: 10px 14px;
    border: 0;
    border-radius: 8px;
    font-weight: bold;
    cursor: pointer;
}

.confirmation-cancel {
    background: #eee;
    color: #333;
}

.confirmation-delete {
    background: #bd3333;
    color: #fff;
}

@media(prefers-reduced-motion: reduce) {
    .message-notice,
    tr.account-pending,
    tr.account-updated td,
    tr.account-removing {
        animation: none;
        transition: none;
    }
}

@media(max-width: 750px) {
    .navbar {
        flex-direction: column;
    }

    .nav-links {
        flex-wrap: wrap;
        justify-content: center;
    }

    .stats,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .container {
        width: 94%;
    }
}
</style>
<link rel="stylesheet" href="dashboard-commerce.css?v=1">
<link rel="stylesheet" href="student-navbar.css?v=1">
<link rel="stylesheet" href="admin-navbar.css?v=<?= (int) filemtime(__DIR__ . '/admin-navbar.css') ?>">
<link rel="stylesheet" href="admin-ui.css?v=<?= (int) filemtime(__DIR__ . '/admin-ui.css') ?>">
<script src="admin-users.js?v=<?= (int) filemtime(__DIR__ . '/admin-users.js') ?>" defer></script>
</head>

<body class="cb-commerce cb-admin cb-admin-users" data-admin-users-id="<?= $admin_id ?>">

<?php require __DIR__ . '/components/admin-navbar.php'; ?>

<main class="container">

    <header class="cb-hero" aria-labelledby="admin-users-title">
        <span class="cb-hero-kicker">CAMPUSBITE MANAGEMENT</span>
        <h1 id="admin-users-title"><span>Your campus people,</span>all in one place.</h1>
        <p>Create staff accounts, manage registered users, and keep your administrator settings up to date.</p>
    </header>

    <?php if ($success): ?>
        <div class="message message-notice success" role="status" aria-live="polite">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="message message-notice error" role="alert" aria-live="assertive">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">
        <?php foreach ($counts as $label => $count): ?>
            <div class="stat">
                <span><?= e($label) ?></span>
                <strong data-account-count="<?= e($label) ?>"><?= (int) $count ?></strong>
            </div>
        <?php endforeach; ?>
    </section>

    <!-- CREATE STAFF -->
    <section class="card">
        <div class="card-header">
            <h2>Create Canteen Staff Account</h2>
            <p>Register a new Campusbite staff member.</p>
        </div>

        <div class="card-content">
            <form method="POST">
                <input type="hidden" name="action"
                       value="create_staff">
                <input type="hidden" name="csrf_token"
                       value="<?= e($csrf) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="new-staff-id">Staff ID</label>
                        <input id="new-staff-id" name="id_number"
                               placeholder="STF-0003"
                               value="<?= e($staffForm["id_number"] ?? "") ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="new-staff-email">Email</label>
                        <input id="new-staff-email" type="email" name="email"
                               value="<?= e($staffForm["email"] ?? "") ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="new-staff-first-name">First Name</label>
                        <input id="new-staff-first-name" name="first_name"
                               value="<?= e($staffForm["first_name"] ?? "") ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="new-staff-last-name">Last Name</label>
                        <input id="new-staff-last-name" name="last_name"
                               value="<?= e($staffForm["last_name"] ?? "") ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="new-staff-contact">Contact Number</label>
                        <input id="new-staff-contact" name="contact_number"
                               value="<?= e($staffForm["contact_number"] ?? "") ?>">
                    </div>

                    <div class="form-group">
                        <label for="staff-password">Password</label>
                        <div class="password-input">
                            <input id="staff-password" type="password"
                                   name="password"
                                   minlength="8" required>
                            <button type="button" class="password-toggle"
                                    data-password-toggle
                                    aria-label="Show password"
                                    aria-pressed="false"
                                    aria-controls="staff-password">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm-staff-password">Confirm Password</label>
                        <div class="password-input">
                            <input id="confirm-staff-password" type="password"
                                   name="confirm_password"
                                   minlength="8" required>
                            <button type="button" class="password-toggle"
                                    data-password-toggle
                                    aria-label="Show confirm password"
                                    aria-pressed="false"
                                    aria-controls="confirm-staff-password">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <p class="note">
                    Password requires at least 8 characters,
                    one uppercase letter and one special character.
                </p>

                <div class="submit-area">
                    <button class="btn" type="submit">
                        Create Staff Account
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- ADMIN SETTINGS -->
    <section class="card">
        <div class="card-header">
            <h2>Administrator Account Settings</h2>
            <p>
                Change your administrator email or password.
                Your current password is required.
            </p>
        </div>

        <div class="card-content">
            <form method="POST">
                <input type="hidden" name="action"
                       value="update_admin">
                <input type="hidden" name="csrf_token"
                       value="<?= e($csrf) ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label for="admin-settings-email">Admin Email</label>
                        <input id="admin-settings-email" type="email"
                               name="admin_email"
                               value="<?= e($admin["email"]) ?>"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="admin-settings-current-password">Current Password</label>
                        <input id="admin-settings-current-password" type="password"
                               name="current_password"
                               required>
                    </div>

                    <div class="form-group">
                        <label for="admin-settings-new-password">New Password</label>
                        <input id="admin-settings-new-password" type="password"
                               name="new_password"
                               minlength="8">
                    </div>

                    <div class="form-group">
                        <label for="admin-settings-confirm-password">Confirm New Password</label>
                        <input id="admin-settings-confirm-password" type="password"
                               name="confirm_new_password">
                    </div>
                </div>

                <p class="note">
                    Leave the new password blank if you
                    only want to change your email.
                </p>

                <div class="submit-area">
                    <button class="btn" type="submit">
                        Save Admin Changes
                    </button>
                </div>
            </form>
        </div>
    </section>

    <!-- REGISTERED USERS -->
    <section class="card" id="registered-accounts">
        <div class="card-header">
            <h2 id="registered-users-title">Registered Users</h2>
            <p>View and manage all Campusbite accounts.</p>
        </div>

        <div class="table-wrapper" tabindex="0" role="region" aria-labelledby="registered-users-title">
            <table>
                <caption class="cb-admin-sr-only">Registered CampusBite accounts and account management actions</caption>
                <thead>
                    <tr>
                        <th scope="col">ID Number</th>
                        <th scope="col">Name</th>
                        <th scope="col">Email</th>
                        <th scope="col">Role</th>
                        <th scope="col">Status</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($users as $user): ?>
                    <?php require __DIR__ . "/components/admin-user-row.php"; ?>
                <?php endforeach; ?>

                <?php if (!$users): ?>
                    <tr>
                        <td colspan="6">
                            No registered users found.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>
<dialog class="delete-confirmation" id="delete-confirmation"
        aria-labelledby="delete-confirmation-title"
        aria-describedby="delete-confirmation-message">
    <h2 id="delete-confirmation-title">Delete this account?</h2>
    <p id="delete-confirmation-message">
        Permanently delete <strong id="delete-user-name"></strong>?
        This action cannot be undone.
    </p>
    <div class="confirmation-actions">
        <button type="button" class="confirmation-cancel"
                data-cancel-delete>Cancel</button>
        <button type="button" class="confirmation-delete"
                data-confirm-delete>Delete account</button>
    </div>
</dialog>
<div class="message account-action-notice" id="account-action-notice" role="status" aria-live="polite" aria-atomic="true" hidden></div>
</body>
</html>
