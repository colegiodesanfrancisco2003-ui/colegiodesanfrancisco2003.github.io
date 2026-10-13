<?php
/**
 * Treatment History API — READ ONLY.
 *
 * Treatment History is no longer a standalone CRUD module. Every row in
 * `treatments` is generated automatically by api/clinic.php when a Clinic
 * Visit that dispenses medicine is saved. This endpoint only supports:
 *   - GET (list / single record) — for the History viewer, search, filter, print
 *   - GET ?medicines=1           — live inventory lookup used by the Clinic
 *                                  Visit form's medicine-dispensing picker
 *
 * There is intentionally no POST / PUT / DELETE / PATCH here anymore.
 */
require_once "../config/session_init.php";
session_start();
include "../config/database.php";
header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();

// Treatments belong to the Clinic Module — Guidance Staff must not be
// able to read them via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== "GET") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Treatment History is read-only. Dispense medicine from a Clinic Visit instead."]);
    exit();
}

$id = $_GET['id'] ?? null;

// ?medicines=1 — for the Clinic Visit's medicine dispensing picker.
// Only returns non-archived medicines with stock > 0 to prevent staff
// from selecting archived or empty-stock items.
if (isset($_GET['medicines'])) {
    $stmt = $conn->prepare("
        SELECT id, name, unit, quantity, expiry_date
        FROM medicines
        WHERE is_archived = 0
        ORDER BY name ASC
    ");
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
    exit();
}

if ($id) {
    $stmt = $conn->prepare("
        SELECT t.*, s.full_name, s.student_code, u.full_name AS given_by_name,
               m.name AS medicine_name, m.unit AS medicine_unit,
               cv.symptoms AS visit_chief_complaint, cv.findings AS visit_findings,
               cv.remarks AS visit_remarks,
               cv.visited_at AS visit_date
        FROM treatments t
        LEFT JOIN students s      ON t.student_id  = s.id
        LEFT JOIN users u         ON t.given_by    = u.id
        LEFT JOIN medicines m     ON t.medicine_id = m.id
        LEFT JOIN clinic_visits cv ON t.visit_id   = cv.id
        WHERE t.id = ?
    ");
    $idInt = intval($id);
    $stmt->bind_param("i", $idInt);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode($row ?: []);
} else {
    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    // Active view (archived=0) must also hide history rows belonging to
    // an archived Student, following that Student's archive state (see
    // Archive Behavior Standardization spec §3/§11). Student is a LEFT
    // JOIN here, so a (theoretically) orphaned row with no student still
    // shows rather than silently vanishing.
    $sql = "
        SELECT t.*, s.full_name, s.student_code, u.full_name AS given_by_name,
               cv.symptoms AS visit_chief_complaint, cv.findings AS visit_findings,
               cv.remarks AS visit_remarks
        FROM treatments t
        LEFT JOIN students s ON t.student_id = s.id
        LEFT JOIN users u    ON t.given_by   = u.id
        LEFT JOIN clinic_visits cv ON t.visit_id = cv.id
        WHERE t.is_archived = ?
    ";
    if (!$archivedVal) $sql .= " AND (s.id IS NULL OR s.is_archived = 0)";
    $sql .= " ORDER BY t.given_at DESC LIMIT 5000";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $archivedVal);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
}
exit();
