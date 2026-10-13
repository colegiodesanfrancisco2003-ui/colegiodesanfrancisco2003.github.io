<?php
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";
include "../config/audit.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();

// ── Step 3 cleanup: server-side role gate ──────────────────────
// Appointments feed directly into Counseling Sessions (PUT ?action=arrive
// opens one) — this is Guidance-module functionality, same boundary
// already enforced on counseling.php, behavioral.php, and offenses.php.
// There was previously NO role check here at all (any authenticated
// user, including Clinic Staff, could reach it) — never exploited only
// because the endpoint is currently unwired from the UI, but fixed here
// rather than relying on that.
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}


$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT a.*, s.full_name, s.student_code, s.grade_section,
                   u.full_name AS assigned_to_name
            FROM appointments a
            JOIN students s    ON s.id = a.student_id
            LEFT JOIN users u  ON u.id = a.assigned_to
            WHERE a.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT a.*, s.full_name, s.student_code, s.grade_section,
               u.full_name AS assigned_to_name
        FROM appointments a
        JOIN students s    ON s.id = a.student_id
        LEFT JOIN users u  ON u.id = a.assigned_to
        ORDER BY a.appointment_date ASC, a.appointment_time ASC
        LIMIT 5000
    ");
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
    exit();
}

// ── POST ──────────────────────────────────────────────────────
if ($method === 'POST') {
    $data            = json_decode(file_get_contents("php://input"), true);
    $student_id      = intval($data['student_id']        ?? 0);
    $type            = trim($data['type']                 ?? '');
    $assigned_to     = intval($data['assigned_to']        ?? 0) ?: null;
    $appointment_date= trim($data['appointment_date']     ?? '');
    $appointment_time= trim($data['appointment_time']     ?? '');
    $status          = trim($data['status']               ?? 'Scheduled');

    if (!$student_id || !$type || !$appointment_date) {
        echo json_encode(["success" => false, "message" => "Student, type, and date are required."]);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO appointments (student_id, type, assigned_to, appointment_date, appointment_time, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    // NOTE: was "isiiss" — bound appointment_date (a string) as an integer,
    // which mysqli silently coerced to 0 and corrupted the saved date to
    // 0000-00-00. Fixed to match the actual param order: i,s,i,s,s,s.
    $stmt->bind_param("isisss", $student_id, $type, $assigned_to, $appointment_date, $appointment_time, $status);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Schedule Appointment', $conn->insert_id, "Scheduled {$type} for student #{$student_id}");
    }
    echo json_encode($ok
        ? ["success" => true, "message" => "Appointment scheduled"]
        : ["success" => false, "message" => $conn->error]);
    exit();
}

// ── PUT: action=arrive → open/return the linked Counseling Session ──
// Guidance workflow: Appointment is scheduling only. Once the student
// arrives, the appointment transitions into a Counseling Session
// instead of duplicating it. Calling this twice is safe — it returns
// the existing linked session instead of creating a second one.
if ($method === 'PUT') {
    $putData = json_decode(file_get_contents("php://input"), true);

    if (($putData['action'] ?? '') === 'arrive') {
        $id = intval($putData['id'] ?? 0);
        if (!$id) { echo json_encode(["success" => false, "message" => "No appointment ID"]); exit(); }

        $stmt = $conn->prepare("SELECT * FROM appointments WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $apt = $stmt->get_result()->fetch_assoc();
        if (!$apt) { echo json_encode(["success" => false, "message" => "Appointment not found"]); exit(); }

        if (!empty($apt['counseling_session_id'])) {
            // Session already opened for this appointment — just return it.
            echo json_encode(["success" => true, "counseling_session_id" => (int)$apt['counseling_session_id']]);
            exit();
        }

        $counselor_id = $apt['assigned_to'] ?: $_SESSION['user_id'];
        $today = date('Y-m-d');
        $ins = $conn->prepare("
            INSERT INTO counseling_sessions (student_id, appointment_id, counselor_id, status, session_date)
            VALUES (?, ?, ?, 'Ongoing', ?)
        ");
        $ins->bind_param("iiis", $apt['student_id'], $id, $counselor_id, $today);
        if (!$ins->execute()) {
            echo json_encode(["success" => false, "message" => $conn->error]);
            exit();
        }
        $sessionId = $conn->insert_id;

        $upd = $conn->prepare("UPDATE appointments SET status = 'In Session', counseling_session_id = ? WHERE id = ?");
        $upd->bind_param("ii", $sessionId, $id);
        $upd->execute();

        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Open Counseling Session', $sessionId, "Opened from appointment #{$id}");

        echo json_encode(["success" => true, "counseling_session_id" => $sessionId]);
        exit();
    }

    // ── plain PUT (edit appointment) ─────────────────────────────
    $data            = $putData;
    $id              = intval($data['id']                 ?? 0);
    $student_id      = intval($data['student_id']         ?? 0);
    $type            = trim($data['type']                  ?? '');
    $assigned_to     = intval($data['assigned_to']         ?? 0) ?: null;
    $appointment_date= trim($data['appointment_date']      ?? '');
    $appointment_time= trim($data['appointment_time']      ?? '');
    $status          = trim($data['status']                ?? '');

    if (!$id || !$student_id || !$type || !$appointment_date) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE appointments
        SET student_id=?, type=?, assigned_to=?, appointment_date=?, appointment_time=?, status=?
        WHERE id=?
    ");
    // Same fix as the INSERT above: appointment_date is a string, not an int.
    $stmt->bind_param("isisssi", $student_id, $type, $assigned_to, $appointment_date, $appointment_time, $status, $id);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Update Appointment', $id, "Status: {$status}");
    }
    echo json_encode($ok
        ? ["success" => true]
        : ["success" => false, "message" => $conn->error]);
    exit();
}

// ── DELETE ────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID"]); exit(); }
    $stmt = $conn->prepare("DELETE FROM appointments WHERE id = ?");
    $stmt->bind_param("i", $id);
    echo json_encode($stmt->execute()
        ? ["success" => true]
        : ["success" => false, "message" => $conn->error]);
    exit();
}