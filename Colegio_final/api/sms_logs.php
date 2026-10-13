<?php
// NOTE (Step 2 security fix): migrated from Stack B (config/session.php +
// includes/csrf.php, no idle timeout) onto Stack A, same as every other
// endpoint. The frontend's actual SMS log viewer calls api/settings.php
// (?logs=1), not this file — this endpoint is currently unreferenced by
// admin.js — but it is still reachable directly by an authenticated
// session, so it gets the same protection as the rest of the app.
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
// full-project grep. The live SMS log viewer calls
// api/settings.php?logs=1 (same `sms_logs` table, different endpoint),
// never this file. Kept in place rather than deleted, with the original
// logic intact below, so it stays reversible; direct access is disabled
// here to shrink attack surface. To re-enable, remove this block.
http_response_code(410);
echo json_encode(["success" => false, "message" => "This endpoint has been retired. Use api/settings.php?logs=1 instead."]);
exit();
// Staff must never reach it (per Phase 6: "No SMS for Guidance").
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

$res = $conn->query("
    SELECT sl.id, sl.recipient, sl.guardian_name, sl.message, sl.status, sl.sent_at,
           s.full_name AS student_name, s.student_code
    FROM sms_logs sl
    LEFT JOIN students s ON s.id = sl.student_id
    ORDER BY sl.sent_at DESC
    LIMIT 500
");
$logs = [];
while ($row = $res->fetch_assoc()) $logs[] = $row;

echo json_encode(["success" => true, "logs" => $logs]);
exit();
