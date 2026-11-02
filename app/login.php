<?php

if (session_status() === PHP_SESSION_NONE) session_start();

require_once "config/connection.php";
require_once __DIR__ . '/config/auth-security.php';
require_once __DIR__ . '/config/staff-password.php';

$error = "";

if ($_SERVER['REQUEST_METHOD']==='POST' && !cbAuthRequestValid()) {
    $error='Your form expired. Please try again.';
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if ($email === "" || $password === "") {

        $error = "Please enter your email and password.";

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT
                    u.user_id,
                    u.role_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.password_hash,
                    u.is_active,
                    r.role_name
                FROM users u
                INNER JOIN roles r
                    ON u.role_id = r.role_id
                WHERE lower(u.email) = lower(?)
                LIMIT 1
            ");

            $stmt->execute([
                $email
            ]);

            $user = $stmt->fetch();

            if (!$user) {

                $error = "Invalid email or password.";

            } elseif ((int) $user["is_active"] !== 1) {

                $error = "This account is inactive.";

            } elseif (
                !password_verify(
                    $password,
                    $user["password_hash"]
                )
            ) {

                $error = "Invalid email or password.";

            } else {

                session_regenerate_id(true);
                unset($_SESSION['staff_credential_stamp'], $_SESSION['student_credential_stamp']);
                $stampKey = cbCredentialStampKey((int) $user['role_id']);
                if ($stampKey !== null) $_SESSION[$stampKey] = cbStaffCredentialStamp($user['password_hash']);

                /*
                |--------------------------------------------------------------------------
                | SAVE USER SESSION
                |--------------------------------------------------------------------------
                */

                $_SESSION["user_id"] =
                    (int) $user["user_id"];

                $_SESSION["role_id"] =
                    (int) $user["role_id"];

                $_SESSION["first_name"] =
                    $user["first_name"];

                $_SESSION["last_name"] =
                    $user["last_name"];

                $_SESSION["email"] =
                    $user["email"];

                $_SESSION["role_name"] =
                    $user["role_name"];


                /*
                |--------------------------------------------------------------------------
                | ROLE REDIRECT
                |--------------------------------------------------------------------------
                */

                if (
                    $user["role_name"] ===
                    "Administrator"
                ) {

                    header(
                        "Location: admin_dashboard.php"
                    );

                    exit;

                } elseif (
                    $user["role_name"] ===
                    "Canteen Staff"
                ) {

                    header(
                        "Location: staff_dashboard.php"
                    );

                    exit;

                } elseif (
                    $user["role_name"] ===
                    "Student"
                ) {

                    header(
                        "Location: dashboard.php"
                    );

                    exit;

                } else {

                    $error =
                        "Invalid account role.";
                }
            }

        } catch (PDOException $e) {

            $error =
                "Database connection error.";
        }
    }
}

$authMode = "login";
require __DIR__ . "/auth-view.php";
