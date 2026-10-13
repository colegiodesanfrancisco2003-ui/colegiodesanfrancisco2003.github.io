<?php
/**
 * Manual Guardian SMS send — Clinic Module only.
 *
 * Automatic SMS (api/clinic.php) already covers Referral / Sent Home /
 * Emergency the moment a Clinic Visit is saved. This endpoint exists
 * for the case the SMS Notification Workflow spec calls out explicitly:
 * "If automatic SMS is disabled, Clinic Staff may still send SMS
 * manually from the Clinic Visit or Referral page." It also lets staff
 * manually (re)notify a guardian for any visit, regardless of the
 * automatic settings.
 */
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();
require_once "../config/audit.php";
require_once "../includes/sms.php";

// SMS is exclusive to the Clinic Module — Guidance Staff must not reach this.
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

$data    = json_decode(file_get_contents("php://input"), true);
$visitId = intval($data['visit_id'] ?? 0);
if (!$visitId) {
    echo json_encode(["success" => false, "message" => "No visit specified."]);
    exit();
}

$stmt = $conn->prepare("SELECT * FROM clinic_visits WHERE id = ?");
$stmt->bind_param("i", $visitId);
$stmt->execute();
$visit = $stmt->get_result()->fetch_assoc();
if (!$visit) {
    echo json_encode(["success" => false, "message" => "Visit not found."]);
    exit();
}

$studentStmt = $conn->prepare("SELECT full_name, guardian_name, guardian_contact, sms_enabled FROM students WHERE id = ?");
$studentStmt->bind_param("i", $visit['student_id']);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
if (!$student) {
    echo json_encode(["success" => false, "message" => "Student not found."]);
    exit();
}
if (!(int)$student['sms_enabled']) {
    echo json_encode(["success" => false, "message" => "This student's guardian has SMS notifications turned off."]);
    exit();
}
require_once "../includes/validate.php";
if (csf_blank_if_na($student['guardian_contact'] ?? '') === '') {
    echo json_encode(["success" => false, "message" => "No guardian contact number on file for this student."]);
    exit();
}

$referralRow = $conn->prepare("SELECT id FROM referrals WHERE visit_id = ? AND is_archived = 0 LIMIT 1");
$referralRow->bind_param("i", $visitId);
$referralRow->execute();
$referral = $referralRow->get_result()->fetch_assoc();

$isReferral  = !empty($visit['referral_required']);
$isSentHome  = strcasecmp($visit['status'], 'Sent Home') === 0;
$isEmergency = strcasecmp($visit['status'], 'Emergency') === 0;

$message = build_clinic_sms_message(
    $student['full_name'] ?? 'the student',
    $visit['symptoms'] ?? '',
    $isReferral,
    $isSentHome,
    $isEmergency
);

// "Sent By" records the acting staff member's name for a manual send
// (vs "Automatic" for the system-triggered workflow).
$sentByStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$sentByStmt->bind_param("i", $_SESSION['user_id']);
$sentByStmt->execute();
$actingUser = $sentByStmt->get_result()->fetch_assoc();
$sentBy = $actingUser['full_name'] ?? ($_SESSION['role'] ?? 'Staff');

$status = send_guardian_sms(
    $conn, (int)$visit['student_id'],
    csf_blank_if_na($student['guardian_name'] ?? '') ?: 'Guardian',
    $student['guardian_contact'],
    $message, $visitId, $referral['id'] ?? null, $sentBy,
    $student['full_name'] ?? ''
);

log_audit($conn, (int)$_SESSION['user_id'], 'Clinic',
    $status === 'Failed' ? 'Manual SMS Failed' : 'Manual SMS Sent',
    $visitId, "Guardian SMS manually sent by {$sentBy} for visit #$visitId — status: $status");

echo json_encode(["success" => true, "message" => "SMS $status", "status" => $status]);
exit();
