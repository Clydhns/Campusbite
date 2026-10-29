<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| CURRENT USER
|--------------------------------------------------------------------------
*/

function current_user()
{
    return $_SESSION["user"] ?? null;
}


/*
|--------------------------------------------------------------------------
| CHECK LOGIN
|--------------------------------------------------------------------------
*/

function require_login()
{
    if (!isset($_SESSION["user"])) {

        header("Location: login.php");
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| CHECK ROLE
|--------------------------------------------------------------------------
|
| Supported values:
|
| student
| staff
| admin
| administrator
| canteen staff
|
*/

function require_role($requiredRole)
{
    require_login();

    $user = $_SESSION["user"];

    $userRole = strtolower(
        trim($user["role"] ?? "")
    );

    $requiredRole = strtolower(
        trim($requiredRole)
    );

    /*
    |--------------------------------------------------------------------------
    | Normalize role names
    |--------------------------------------------------------------------------
    */

    if ($userRole === "administrator") {
        $userRole = "admin";
    }

    if ($userRole === "canteen staff") {
        $userRole = "staff";
    }

    if ($requiredRole === "administrator") {
        $requiredRole = "admin";
    }

    if ($requiredRole === "canteen staff") {
        $requiredRole = "staff";
    }

    /*
    |--------------------------------------------------------------------------
    | DENY WRONG ROLE
    |--------------------------------------------------------------------------
    */

    if ($userRole !== $requiredRole) {

        if ($userRole === "student") {

            header("Location: ../dashboard.php");

        } elseif ($userRole === "staff") {

            header("Location: ../staff_dashboard.php");

        } elseif ($userRole === "admin") {

            header("Location: ../admin_dashboard.php");

        } else {

            header("Location: ../login.php");
        }

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| GET USER ID
|--------------------------------------------------------------------------
*/

function user_id()
{
    return (int) (
        $_SESSION["user"]["user_id"]
        ?? $_SESSION["user"]["id"]
        ?? $_SESSION["user_id"]
        ?? 0
    );
}


/*
|--------------------------------------------------------------------------
| GET USER ROLE
|--------------------------------------------------------------------------
*/

function user_role()
{
    return strtolower(
        trim(
            $_SESSION["user"]["role"]
            ?? $_SESSION["role_name"]
            ?? ""
        )
    );
}

?>