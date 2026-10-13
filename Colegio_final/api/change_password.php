<?php
require_once "../config/session_init.php";
session_start();
include "../config/database.php";

// Any authenticated user (Administrator, Clinic Staff, or Guidance Staff)
// may change their OWN password — the "Change Password" menu item and
// modal exist in all three roles' UI (admin.php, clinic.php, guidance.php).
// Previously this was mistakenly locked to Administrator only, silently
// breaking the feature for Clinic Staff and Guidance Staff.
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();


// IMPORTANT: the user being updated is always the one in the session,
// never an id supplied by the client. This guarantees a user can only
// ever change their own password through this endpoint.
$user_id         = $_SESSION['user_id'];
$current_password = $_POST['current_password'] ?? '';
$new_password      = $_POST['new_password'] ?? '';

if ($current_password === '' || $new_password === '') {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit();
}

if (strlen($new_password) < 8) {
    echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
    exit();
}

// Fetch the current hashed password for this user.
$stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit();
}

if (!password_verify($current_password, $row['password'])) {
    echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
    exit();
}

$new_hash = password_hash($new_password, PASSWORD_DEFAULT);

$update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
$update->bind_param("si", $new_hash, $user_id);
$update->execute();

echo json_encode(['success' => true]);