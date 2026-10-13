<?php
// NOTE (Step 2 security fix): migrated from Stack B onto Stack A, and the
// audit logger swapped from includes/audit.php (`audit_logs`, invisible
// to the Admin Audit Trail UI) to config/audit.php (`audit_trail`).
// This endpoint duplicates api/reports.php?module=guidance and is
// currently unreferenced by admin.js (REPORTS_API points at
// api/reports.php only) — left in place and fixed rather than removed,
// per Step 2 scope. See final report for the recommended follow-up.
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

// ── Step 3 cleanup: LEGACY / DISABLED ───────────────────────────
// Confirmed unreferenced anywhere in the project — verified by
// full-project grep. The live Guidance Reports screen calls
// api/reports.php?module=guidance (REPORTS_API in admin.js), never this
// file. Kept in place rather than deleted, with the original logic
// intact below, so it stays reversible; direct access is disabled here
// to shrink attack surface. To re-enable, remove this block.
http_response_code(410);
echo json_encode(["success" => false, "message" => "This endpoint has been retired. Use api/reports.php?module=guidance instead."]);
exit();
// be able to read them via direct API calls (mirrors the same rule already
// enforced for behavioral.php, counseling.php, and offenses.php).
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

// ── DATE RANGE ──
// Defaults to "this month" when no range is supplied by the client.
// Client sends ?from=YYYY-MM-DD&to=YYYY-MM-DD
$from = $_GET['from'] ?? date('Y-m-01');               // first day of current month
$to   = $_GET['to']   ?? date('Y-m-d');                 // today

// counseling_sessions.session_date / offenses.recorded_at / behavioral_records.recorded_at
// are all plain DATE columns (no time component), so a simple string range works here
// (no need for the "23:59:59" widening that api/reports.php does for its DATETIME column).
$fromDate = $from;
$toDate   = $to;

// ── 1. TOTAL COUNSELING SESSIONS (within range, active only) ──
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM counseling_sessions
    WHERE session_date BETWEEN ? AND ? AND is_archived = 0
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$totalSessions = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

// ── 2. MOST COMMON OFFENSE CATEGORY (within range) ──
$stmt = $conn->prepare("
    SELECT oc.category_name AS type, COUNT(*) AS uses
    FROM student_offense_records sor JOIN offense_categories oc ON oc.id = sor.category_id
    WHERE sor.incident_date BETWEEN ? AND ? AND sor.is_archived = 0
    GROUP BY oc.category_name
    ORDER BY uses DESC
    LIMIT 1
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$topOffenseRow = $stmt->get_result()->fetch_assoc();
$mostCommonOffense = $topOffenseRow ? $topOffenseRow['type'] : null;
$mostCommonOffenseCount = $topOffenseRow ? (int)$topOffenseRow['uses'] : 0;

// ── 4. MOST COMMON GRADE LEVEL (students in counseling, within range) ──
$stmt = $conn->prepare("
    SELECT s.grade_section, COUNT(*) AS sessions
    FROM counseling_sessions cs
    JOIN students s ON s.id = cs.student_id
    WHERE cs.session_date BETWEEN ? AND ? AND cs.is_archived = 0
    GROUP BY s.grade_section
    ORDER BY sessions DESC
    LIMIT 1
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$topGradeRow = $stmt->get_result()->fetch_assoc();
$mostCommonGrade = $topGradeRow ? $topGradeRow['grade_section'] : null;
$mostCommonGradeCount = $topGradeRow ? (int)$topGradeRow['sessions'] : 0;

// ── 5. TOP 5 BREAKDOWN LISTS ──

// Top offense categories (up to 5)
$stmt = $conn->prepare("
    SELECT oc.category_name AS type, COUNT(*) AS uses
    FROM student_offense_records sor JOIN offense_categories oc ON oc.id = sor.category_id
    WHERE sor.incident_date BETWEEN ? AND ? AND sor.is_archived = 0
    GROUP BY oc.category_name
    ORDER BY uses DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
$topOffenses = [];
while ($row = $res->fetch_assoc()) $topOffenses[] = $row;

// Top grade levels in counseling (up to 5)
$stmt = $conn->prepare("
    SELECT s.grade_section, COUNT(*) AS sessions
    FROM counseling_sessions cs
    JOIN students s ON s.id = cs.student_id
    WHERE cs.session_date BETWEEN ? AND ? AND cs.is_archived = 0
    GROUP BY s.grade_section
    ORDER BY sessions DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
$topGrades = [];
while ($row = $res->fetch_assoc()) $topGrades[] = $row;

// ── 6. CURRENT SNAPSHOT (not date-filtered — current caseload, like the
// Clinic report's inventory snapshot) ──

// Ongoing / still-scheduled counseling sessions
$result = $conn->query("
    SELECT cs.id, cs.session_date, s.full_name, s.student_code
    FROM counseling_sessions cs
    JOIN students s ON s.id = cs.student_id
    WHERE cs.status IN ('Scheduled','Ongoing') AND cs.is_archived = 0
    ORDER BY cs.session_date ASC
");
$openSessions = [];
while ($row = $result->fetch_assoc()) $openSessions[] = $row;

// Unresolved offenses
$result = $conn->query("
    SELECT sor.id, oc.category_name AS type, sor.status, sor.incident_date AS recorded_at, s.full_name, s.student_code
    FROM student_offense_records sor
    JOIN students s ON s.id = sor.student_id
    JOIN offense_categories oc ON oc.id = sor.category_id
    WHERE sor.status != 'Resolved' AND sor.is_archived = 0
    ORDER BY sor.incident_date ASC
");
$openOffenses = [];
while ($row = $result->fetch_assoc()) $openOffenses[] = $row;

// ── RESPONSE ──
log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Report Generated (legacy guidance_reports endpoint)', null, "Generated Guidance Report ({$from} to {$to})");
echo json_encode([
    "success"   => true,
    "range"     => ["from" => $from, "to" => $to],
    "summary"   => [
        "total_sessions"              => $totalSessions,
        "most_common_offense"         => $mostCommonOffense,
        "most_common_offense_count"   => $mostCommonOffenseCount,
        "most_common_grade"           => $mostCommonGrade,
        "most_common_grade_count"     => $mostCommonGradeCount,
    ],
    "breakdown" => [
        "top_offenses"    => $topOffenses,
        "top_grades"      => $topGrades,
    ],
    "caseload" => [
        "open_sessions" => $openSessions,
        "open_offenses" => $openOffenses,
    ],
]);
exit();
