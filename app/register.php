<?php
require_once 'config/connection.php';
require_once __DIR__ . '/config/auth-security.php';
require_once __DIR__ . '/config/registration-verification.php';
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!cbAuthRequestValid()) {
        $error = 'Your form expired. Please try again.';
    } else {
        $clean = static fn($name) => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
        $details = [];
        foreach (['id_number','first_name','last_name','email','contact_number','grade_section'] as $name) $details[$name] = $clean($name);
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        if ($details['id_number']==='' || $details['first_name']==='' || $details['last_name']==='' || $details['email']==='' || $password==='' || $confirm==='') {
            $error = 'Please fill in all required fields.';
        } elseif (!filter_var($details['email'], FILTER_VALIDATE_EMAIL) || strlen($details['email'])>100) {
            $error = 'Please enter a valid email address.';
        } elseif (!preg_match('/^.{1,20}$/us', $details['id_number']) || !preg_match('/^.{1,50}$/us', $details['first_name'])
            || !preg_match('/^.{1,50}$/us', $details['last_name']) || !preg_match('/^.{0,30}$/us', $details['grade_section'])
            || ($details['contact_number']!=='' && !preg_match('/^[0-9+() .-]{7,15}$/', $details['contact_number']))) {
            $error = 'Check the length and format of your account details.';
        } elseif (!preg_match('/^.{8,}$/us', $password) || strlen($password)>72 || str_contains($password,"\0")) {
            $error = 'Password must be at least 8 characters and no more than 72 bytes.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $check = $pdo->prepare('SELECT user_id FROM users WHERE id_number=? OR lower(email)=lower(?) LIMIT 1');
                $check->execute([$details['id_number'], $details['email']]);
                if ($check->fetch()) {
                    $error = 'ID number or email is already registered.';
                } else {
                    $details['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    cbRequestRegistration($pdo, $details, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
                    $message = 'Check your inbox and spam folder. We sent a verification link valid for 15 minutes. Your account will be created only after you verify your email and confirm your CampusBite password.';
                }
            } catch (PDOException $exception) {
                error_log('CampusBite registration: database_failed');
                $error = 'Registration is temporarily unavailable. Please try again later.';
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}
$authMode = 'register';
require __DIR__ . '/auth-view.php';
