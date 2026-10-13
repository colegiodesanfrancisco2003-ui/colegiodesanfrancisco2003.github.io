<?php
// NOTE (Step 2 security fix): migrated from Stack B onto Stack A.
// This also fixes a real gap: any action here previously logged via
// includes/audit.php into `audit_logs`, a table the Administrator's Audit
// Trail screen never reads (it only reads `audit_trail`), so "SMS
// Configuration updated" events from this endpoint were invisible.
// Now uses config/audit.php -> `audit_trail`, same as every other module.
// (Separately: the live SMS Configuration screen in admin.js actually
// calls api/settings.php, which writes to the `settings` table and
// already logs to `audit_trail` correctly — this file/`sms_settings`
// table is a parallel, currently-unreferenced implementation. Left in
// place and fixed rather than removed, per Step 2 scope — see final
// report for the recommended follow-up.)
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
// full-project grep. The live SMS Configuration screen calls
// api/settings.php, which reads/writes the `settings` key-value table
// (sms_enabled, sms_provider, sms_api_key, etc.), never this file's
// `sms_settings` table. That `sms_settings` table itself also has zero
// other readers/writers anywhere (confirmed) — it is dead storage, not
// just a dead API — but it is left untouched here since dropping a
// table is out of scope for this step and not required to fix anything.
// Kept in place rather than deleted, with the original logic intact
// below, so it stays reversible; direct access is disabled here to
// shrink attack surface. To re-enable, remove this block.
http_response_code(410);
echo json_encode(["success" => false, "message" => "This endpoint has been retired. Use api/settings.php instead."]);
exit();
// must never reach it (per Phase 6: "No SMS for Guidance").
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $res = $conn->query("SELECT provider, api_key, sender_name, is_enabled, updated_at FROM sms_settings WHERE id = 1 LIMIT 1");
    $settings = $res->fetch_assoc() ?: ["provider" => null, "api_key" => null, "sender_name" => null, "is_enabled" => 0, "updated_at" => null];

    // Clinic Staff can see WHETHER SMS is configured/enabled (useful
    // context on the Visit modal) but never the raw API key — only an
    // Administrator manages credentials.
    if ($_SESSION['role'] !== 'Administrator') {
        $settings['api_key'] = $settings['api_key'] ? '••••••••' : null;
    }
    $settings['is_localhost'] = (
        strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false
    );

    echo json_encode(["success" => true, "settings" => $settings]);
    exit();
}

if ($method === 'PUT') {
    if ($_SESSION['role'] !== 'Administrator') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only an Administrator can change SMS Configuration."]);
        exit();
    }

    $data       = json_decode(file_get_contents("php://input"), true);
    $provider   = trim($data['provider']    ?? '');
    $apiKey     = trim($data['api_key']     ?? '');
    $senderName = trim($data['sender_name'] ?? '');
    $isEnabled  = !empty($data['is_enabled']) ? 1 : 0;

    // Don't overwrite a real API key with the masked placeholder the
    // frontend might echo back if the Admin didn't touch that field.
    if ($apiKey === '••••••••') {
        $existing = $conn->query("SELECT api_key FROM sms_settings WHERE id = 1")->fetch_assoc();
        $apiKey = $existing['api_key'] ?? '';
    }

    $stmt = $conn->prepare("
        UPDATE sms_settings
        SET provider = ?, api_key = ?, sender_name = ?, is_enabled = ?
        WHERE id = 1
    ");
    $stmt->bind_param("sssi", $provider, $apiKey, $senderName, $isEnabled);
    $ok = $stmt->execute();

    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Admin', 'SMS Settings Updated (legacy sms_settings table)', 1,
            "SMS Configuration updated (provider: " . ($provider ?: 'none') . ", enabled: " . ($isEnabled ? 'yes' : 'no') . ")");
    }

    echo json_encode($ok
        ? ["success" => true, "message" => "SMS Configuration saved."]
        : ["success" => false, "message" => $conn->error]);
    exit();
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
exit();
