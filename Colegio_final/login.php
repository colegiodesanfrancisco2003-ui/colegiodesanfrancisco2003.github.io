<?php
require_once "config/session_init.php";
session_start();
include "config/database.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = $_POST['username'];
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $_SESSION['form_username'] = $username;
        header("Location: index.php?error=empty");
        exit();
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && isset($user['password'])) {

        if (!password_verify($password, $user['password'])) {
            $_SESSION['form_username'] = $username;
            header("Location: index.php?error=invalid");
            exit();
        }

        // Archived (soft-deleted) accounts are status='Inactive' — block
        // login instead of just hiding them from User Management, so a
        // "deleted" staff member can't still access the system.
        if (isset($user['status']) && $user['status'] === 'Inactive') {
            $_SESSION['form_username'] = $username;
            header("Location: index.php?error=inactive");
            exit();
        }

        // Issue a fresh session ID now that the user is authenticated
        // (prevents session fixation); existing session data is kept.
        session_regenerate_id(true);

        // SET SESSION
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        // REDIRECT
        if ($user['role'] === "Administrator") {
            header("Location: admin.php");
        } elseif ($user['role'] === "Clinic Staff") {
            header("Location: clinic.php");
        } elseif ($user['role'] === "Guidance Staff") {
            header("Location: guidance.php");
        }

        exit();

    } else {
        $_SESSION['form_username'] = $username;
        header("Location: index.php?error=invalid");
        exit();
    }
}
?>