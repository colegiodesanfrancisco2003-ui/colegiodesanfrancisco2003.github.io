<?php
// NOTE (Step 2 security fix): this was the one Stack-B file that mattered
// most — it aggregates both Clinic and Guidance data across the
// confidentiality boundary, yet was the only such endpoint that never
// enforced the 30-minute idle-session timeout. Migrated onto Stack A.
// (Separately: the live Student 360 modal in admin.js actually calls
// api/students.php?profile=, which already used Stack A correctly — this
// file is a parallel, currently-unreferenced implementation. Left in
// place and fixed rather than removed, per Step 2 scope.)
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

// ── Step 3 cleanup: LEGACY / DISABLED ───────────────────────────
// Confirmed unreferenced anywhere in the project — verified by
// full-project grep. The live Student 360 modal (openStudentProfile()
// in admin.js) calls api/students.php?profile=<id>, never this file.
// Kept in place rather than deleted, with the original aggregation
// logic intact below, so it stays reversible; direct access is disabled
// here to shrink attack surface. To re-enable, remove this block.
http_response_code(410);
echo json_encode(["success" => false, "message" => "This endpoint has been retired. Use api/students.php?profile=<id> instead."]);
exit();
// still go through the existing per-module APIs (clinic.php, counseling.php,
// etc.), unchanged.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(["success" => false, "message" => "Student id is required."]);
    exit();
}

// ── Personal / Academic / Guardian / Emergency Information ──
// This part of the profile is shared across every role — it's the same
// "single source of truth" students record regardless of who is viewing
// it, since none of it is Clinic- or Guidance-confidential.
$stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    echo json_encode(["success" => false, "message" => "Student not found."]);
    exit();
}

$role = $_SESSION['role'];
$canSeeClinic   = in_array($role, ['Administrator', 'Clinic Staff']);
$canSeeGuidance = in_array($role, ['Administrator', 'Guidance Staff']);

$timeline = [];
$response = [
    "success" => true,
    "student" => $student,
];

// ── CLINIC-SIDE HISTORY (Clinic Staff / Administrator only) ──
// The Clinic/Guidance confidentiality wall enforced everywhere else in
// this system (clinic.php, treatments.php, referrals.php, reports.php,
// etc.) applies here too — "ONE Integrated Student Profile" means one
// shared student record as the single source of truth, NOT that every
// role sees every module's data in it. Clinic Staff still never sees
// Behavioral/Offense/Counseling data, and Guidance Staff still never
// sees Clinic/Treatment/Referral data, exactly as established from the
// start of this project. Administrator continues to see both.
if ($canSeeClinic) {
    // Clinic Visits
    $stmt = $conn->prepare("
        SELECT id, symptoms, treatment, assessment, status, visited_at
        FROM clinic_visits
        WHERE student_id = ? AND is_archived = 0
        ORDER BY visited_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $visits = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $visits[] = $row;
    $response['clinic_visits'] = $visits;
    foreach ($visits as $v) {
        $timeline[] = [
            "date"    => $v['visited_at'],
            "type"    => "Clinic Visit",
            "summary" => $v['symptoms'] . ($v['status'] ? " ({$v['status']})" : ""),
        ];
    }

    // Treatment History + Medicine History — the same underlying record
    // (each treatment IS a medicine dispensed), presented together since
    // splitting them into two separate queries would just show the same
    // rows twice under different labels.
    $stmt = $conn->prepare("
        SELECT id, medicine, dosage, quantity_used, given_at
        FROM treatments
        WHERE student_id = ? AND is_archived = 0
        ORDER BY given_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $treatments = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $treatments[] = $row;
    $response['treatment_history'] = $treatments;
    foreach ($treatments as $t) {
        $timeline[] = [
            "date"    => $t['given_at'],
            "type"    => "Treatment",
            "summary" => "{$t['medicine']} — {$t['dosage']}",
        ];
    }

    // Referral History
    $stmt = $conn->prepare("
        SELECT id, referred_to, reason, status, created_at
        FROM referrals
        WHERE student_id = ? AND is_archived = 0
        ORDER BY created_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $referrals = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $referrals[] = $row;
    $response['referral_history'] = $referrals;
    foreach ($referrals as $r) {
        $timeline[] = [
            "date"    => $r['created_at'],
            "type"    => "Referral",
            "summary" => "Referred to {$r['referred_to']} ({$r['status']})",
        ];
    }
}

// ── GUIDANCE-SIDE HISTORY (Guidance Staff / Administrator only) ──
if ($canSeeGuidance) {
    // Behavioral Records (observation only — unchanged by this phase)
    $stmt = $conn->prepare("
        SELECT id, behavior, recorded_at
        FROM behavioral_records
        WHERE student_id = ? AND is_archived = 0
        ORDER BY recorded_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $behavioral = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $behavioral[] = $row;
    $response['behavioral_records'] = $behavioral;
    foreach ($behavioral as $b) {
        $timeline[] = [
            "date"    => $b['recorded_at'],
            "type"    => "Behavioral Record",
            "summary" => $b['behavior'],
        ];
    }

    // Offenses (disciplinary record — unchanged by this phase)
    $stmt = $conn->prepare("
        SELECT id, type, description, status, recorded_at
        FROM offenses
        WHERE student_id = ? AND is_archived = 0
        ORDER BY recorded_at DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $offenses = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $offenses[] = $row;
    $response['offenses'] = $offenses;
    foreach ($offenses as $o) {
        $timeline[] = [
            "date"    => $o['recorded_at'],
            "type"    => "Offense",
            "summary" => "{$o['type']} ({$o['status']})",
        ];
    }

    // Counseling Sessions (the intervention — Phase 3 fields included)
    $stmt = $conn->prepare("
        SELECT cs.id, cs.concern, cs.status, cs.session_date,
               cs.follow_up_required, cs.follow_up_date, u.full_name AS counselor_name
        FROM counseling_sessions cs
        LEFT JOIN users u ON u.id = cs.counselor_id
        WHERE cs.student_id = ? AND cs.is_archived = 0
        ORDER BY cs.session_date DESC
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $counseling = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $counseling[] = $row;
    $response['counseling_sessions'] = $counseling;
    foreach ($counseling as $c) {
        $timeline[] = [
            "date"    => $c['session_date'],
            "type"    => "Counseling Session",
            "summary" => 'Counseling Session' . ($c['concern'] ? " — {$c['concern']}" : "") . " ({$c['status']})",
        ];
    }
}

// ── Chronological Student Timeline ──
// Sorted newest-first, merged across every section this role is allowed
// to see (Administrator sees the full merged timeline across both
// modules; Clinic/Guidance Staff see only their own module's events).
usort($timeline, function ($a, $b) {
    return strcmp($b['date'] ?? '', $a['date'] ?? '');
});
$response['timeline'] = $timeline;

echo json_encode($response);
exit();
