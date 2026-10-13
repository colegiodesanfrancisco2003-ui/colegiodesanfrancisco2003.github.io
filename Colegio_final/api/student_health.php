<?php
/**
 * Student Health Record API.
 *
 * Deliberately separate from api/students.php (basic registration info).
 * Holds ONLY: Current Medication, Medical Notes.
 *
 * If a student has no Student Health Record yet (both fields are NULL),
 * authorized Clinic Staff or Administrator can create one from the
 * Student Health Records page. Guidance Staff must never view or
 * modify this data.
 */
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

// Student Health Records are clinical/medical data — Guidance Staff must
// never view or modify them.
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET (by student_id) ─────────────────────────────────────────
if ($method === 'GET') {
    $studentId = intval($_GET['student_id'] ?? 0);
    if (!$studentId) {
        echo json_encode(["success" => false, "message" => "student_id is required."]);
        exit();
    }

    $stmt = $conn->prepare("
        SELECT id, student_code, full_name, current_medication, medical_notes
        FROM students
        WHERE id = ?
    ");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) { echo json_encode([]); exit(); }

    // "No Student Health Record yet" = every clinical field is empty.
    $row['has_record'] =
        (trim((string)($row['current_medication'] ?? '')) !== '') ||
        (trim((string)($row['medical_notes'] ?? '')) !== '');

    echo json_encode($row);
    exit();
}

// ── PUT (create or update the health record for a student) ──────
if ($method === 'PUT') {
    $data       = json_decode(file_get_contents("php://input"), true);
    $studentId  = intval($data['student_id'] ?? 0);
    $currentMed = trim($data['current_medication'] ?? '');
    $notes      = trim($data['medical_notes']       ?? '');

    if (!$studentId) {
        echo json_encode(["success" => false, "message" => "student_id is required."]);
        exit();
    }

    // Store empty inputs as NULL so "no data yet" stays a clean, detectable state.
    $currentMedVal = $currentMed === '' ? null : $currentMed;
    $notesVal      = $notes      === '' ? null : $notes;

    $stmt = $conn->prepare("
        UPDATE students
        SET current_medication = ?, medical_notes = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $currentMedVal, $notesVal, $studentId);
    $ok = $stmt->execute();

    if ($ok && $stmt->affected_rows === 0) {
        // affected_rows is 0 both when the student doesn't exist AND when
        // the values didn't change — check existence explicitly.
        $chk = $conn->prepare("SELECT id FROM students WHERE id = ?");
        $chk->bind_param("i", $studentId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "Student not found."]);
            exit();
        }
    }

    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Clinic', 'Update Student Health Record', $studentId, "Updated health record for student #$studentId");
        echo json_encode(["success" => true, "message" => "Student Health Record saved."]);
    } else {
        echo json_encode(["success" => false, "message" => "Save failed: " . $conn->error]);
    }
    exit();
}

http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed."]);
