<?php
// NOTE (Step 2 security fix): this endpoint previously bootstrapped via
// config/session.php + includes/csrf.php ("Stack B"), which meant it never
// received the 30-minute idle-session timeout every other endpoint gets.
// It has also never been called by admin.js (the Audit Trail screen reads
// api/audit.php / `audit_trail`, not this file / `audit_logs`) — but since
// it is reachable directly by any authenticated Administrator session, it
// is migrated onto the same session/CSRF stack as the rest of the app.
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
// Confirmed unreferenced anywhere in the project (frontend admin.js,
// every module/*.php page, every other api/*.php, includes/, config/) —
// verified by full-project grep, not assumed. The live Audit Trail
// screen reads api/audit.php (`audit_trail`), never this file
// (`audit_logs`). Kept in place rather than deleted, with the original
// logic intact below, so it stays reversible; direct access is disabled
// here to shrink attack surface. To re-enable, remove this block.
http_response_code(410);
echo json_encode(["success" => false, "message" => "This endpoint has been retired. Use api/audit.php instead."]);
exit();
if ($_SESSION['role'] !== 'Administrator') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

$res = $conn->query("
    SELECT al.id, al.user_id, al.role, al.action, al.entity_type, al.entity_id,
           al.description, al.created_at, u.full_name AS user_name
    FROM audit_logs al
    LEFT JOIN users u ON u.id = al.user_id
    ORDER BY al.created_at DESC
    LIMIT 1000
");
$logs = [];
while ($row = $res->fetch_assoc()) $logs[] = $row;

echo json_encode(["success" => true, "logs" => $logs]);
exit();
