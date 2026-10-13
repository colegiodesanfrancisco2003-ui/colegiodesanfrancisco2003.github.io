<?php
require_once "config/session_init.php";
session_start();
include "config/database.php";

$saved_username = '';
if (isset($_SESSION['form_username'])) {
    $saved_username = htmlspecialchars($_SESSION['form_username']);
    unset($_SESSION['form_username']);
}
?>

<!DOCTYPE html>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSF – LOG IN</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
    
    
</head>

<body >
    <div class="container">

        <h1>Clinic & Guidance System Login</h1>

        <p class="subtitle">
            Web-Based Integrated Clinic and Guidance Office
            Management System for Colegio de San Francisco
        </p>

        <div class="notice-box">
            Secure access is required. Only authorized Clinic Staff,
            Guidance Personnel, and Administrators are allowed
            to enter the system.
        </div>

        <form id="loginForm" action="login.php" method="post">

            <label>Username <span style="color:#c0392b">*</span></label>
            <input type="text" id="username" name="username"
                placeholder="Enter Username"
                value="<?= htmlspecialchars($saved_username) ?>">

            <div class="password-container">
                <label>Password <span style="color:#c0392b">*</span></label>
                <input type="password" id="password" name="password" placeholder="Enter Password">
                <i class="fa-solid fa-eye-slash" id="togglePassword"></i>
            </div>

            <button type="submit" class="login-btn">Login</button>

        </form>

        <?php if (isset($_GET['error'])): ?>
            <div class="error-msg" id="errorBox">
                <?php
                if ($_GET['error'] == "empty") {
                    echo "Please fill in all fields.";
                } elseif ($_GET['error'] == "invalid") {
                    echo "Invalid username or password.";
                } elseif ($_GET['error'] == "role") {
                    echo "Selected role does not match your account.";
                } elseif ($_GET['error'] == "inactive") {
                    echo "This account has been deactivated. Please contact an Administrator.";
                }
                ?>
            </div>
        <?php endif; ?>

        <div class="footer">
            © 2026 Colegio de San Francisco |
            Clinic and Guidance Office Management System
        </div>

        <script src="assets/js/script.js"></script>

    </div>
    <div id="toast"></div>
</body>
</html>