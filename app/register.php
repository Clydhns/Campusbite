<?php

require_once "config/connection.php";
require_once __DIR__ . '/config/auth-security.php';

$message = "";
$error = "";

if ($_SERVER['REQUEST_METHOD']==='POST' && !cbAuthRequestValid()) {
    $error='Your form expired. Please try again.';
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {

    $clean = static fn($name) => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
    $id_number=$clean('id_number'); $first_name=$clean('first_name'); $last_name=$clean('last_name');
    $email=$clean('email'); $contact_number=$clean('contact_number'); $grade_section=$clean('grade_section');
    $password=is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirm=is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

    // Check required fields
    if (
        empty($id_number) ||
        empty($first_name) ||
        empty($last_name) ||
        empty($email) ||
        empty($password) ||
        empty($confirm)
    ) {
        $error = "Please fill in all required fields.";
    }

    // Check email
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>100) {
        $error = "Please enter a valid email address.";
    }

    elseif (!preg_match('/^.{1,20}$/us',$id_number) || !preg_match('/^.{1,50}$/us',$first_name)
        || !preg_match('/^.{1,50}$/us',$last_name) || !preg_match('/^.{0,30}$/us',$grade_section)
        || ($contact_number!=='' && !preg_match('/^[0-9+() .-]{7,15}$/',$contact_number))) {
        $error='Check the length and format of your account details.';
    }
    // Check password
    elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    }

    // Check password confirmation
    elseif ($password !== $confirm) {
        $error = "Passwords do not match.";
    }

    else {

        // Check if ID number or email already exists
        $check = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE id_number = :id_number
               OR lower(email) = lower(:email)
            LIMIT 1
        ");

        $check->execute([
            "id_number" => $id_number,
            "email" => $email
        ]);

        if ($check->fetch()) {

            $error = "ID number or email is already registered.";

        } else {

            // Student role = 3
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $sql = "
                INSERT INTO users
                (
                    role_id,
                    id_number,
                    first_name,
                    last_name,
                    email,
                    password_hash,
                    contact_number,
                    grade_section,
                    is_active
                )
                VALUES
                (
                    3,
                    :id_number,
                    :first_name,
                    :last_name,
                    :email,
                    :password_hash,
                    :contact_number,
                    :grade_section,
                    1
                )
            ";

            $stmt = $pdo->prepare($sql);

            try { $stmt->execute([
                "id_number" => $id_number,
                "first_name" => $first_name,
                "last_name" => $last_name,
                "email" => $email,
                "password_hash" => $password_hash,
                "contact_number" => $contact_number,
                "grade_section" => $grade_section
            ]);
                $message = 'Registration successful! You can now log in.';
            } catch (PDOException $exception) {
                if ($exception->getCode()==='23505') $error='ID number or email is already registered.';
                else { error_log($exception->getMessage()); $error='Unable to register right now. Please try again.'; }
            }
        }
    }
}

$authMode = "register";
require __DIR__ . "/auth-view.php";
