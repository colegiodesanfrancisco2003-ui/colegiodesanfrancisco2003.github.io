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

// SMS Configuration & logs are part of the Clinic Module only.
// Guidance Staff must never reach this endpoint at all (no SMS access).
// Clinic Staff may READ (view settings status + message history) but
// only an Administrator may WRITE (change gateway config / toggles).
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['Administrator', 'Clinic Staff'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// Only these keys may be read/written through this endpoint — an
// allowlist keeps this from becoming an arbitrary key-value store.
$ALLOWED_KEYS = [
    'sms_enabled', 'sms_provider', 'sms_sender_name', 'sms_api_key',
    'sms_notify_referral', 'sms_notify_sent_home', 'sms_notify_emergency',
];

if ($method === 'GET') {
    if (isset($_GET['logs'])) {
        // Clinic SMS logs only — Guidance Staff is already blocked above.
        $result = $conn->query("
            SELECT sl.id, sl.recipient, sl.guardian_name, sl.message, sl.status, sl.sent_by, sl.sent_at,
                   sl.visit_id, sl.referral_id,
                   s.full_name AS student_name, s.student_code
            FROM sms_logs sl
            LEFT JOIN students s ON s.id = sl.student_id
            ORDER BY sl.sent_at DESC
            LIMIT 500
        ");
        $logs = [];
        while ($row = $result->fetch_assoc()) $logs[] = $row;
        echo json_encode(["success" => true, "logs" => $logs]);
        exit();
    }

    $placeholders = implode(',', array_fill(0, count($ALLOWED_KEYS), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $types = str_repeat('s', count($ALLOWED_KEYS));
    $stmt->bind_param($types, ...$ALLOWED_KEYS);
    $stmt->execute();
    $res = $stmt->get_result();

    $settings = [];
    while ($row = $res->fetch_assoc()) {
        // Never echo back a stored API key/secret verbatim — the UI only
        // needs to know one is configured, not what it is. Clinic Staff
        // in particular must never see it at all (Admin-only credential).
        if ($row['setting_key'] === 'sms_api_key') {
            $settings[$row['setting_key']] = $row['setting_value'] !== '' ? '••••••••' : '';
        } else {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    // Per-event toggles default to "on" until an Administrator has
    // explicitly saved a value for them (mirrors includes/sms.php's
    // _sms_event_enabled() fallback).
    foreach (['sms_notify_referral', 'sms_notify_sent_home', 'sms_notify_emergency'] as $k) {
        if (!array_key_exists($k, $settings)) $settings[$k] = '1';
    }

    echo json_encode(["success" => true, "settings" => $settings]);
    exit();
}

if ($method === 'POST') {
    if ($role !== 'Administrator') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only an Administrator can change SMS Configuration."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true) ?? [];

    foreach ($data as $key => $value) {
        if (!in_array($key, $ALLOWED_KEYS, true)) continue;

        // Don't overwrite a real stored API key with the masked
        // placeholder the GET handler sends back to the browser.
        if ($key === 'sms_api_key' && $value === '••••••••') continue;

        // system_settings is category-scoped (category, setting_key); every
        // key this endpoint manages is an SMS key, so it always writes
        // under 'sms'. Kept explicit (not inferred from the key name) so
        // this endpoint can't silently start writing into other categories.
        $stmt = $conn->prepare("
            INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('sms', ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $strValue = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        $stmt->bind_param("ss", $key, $strValue);
        $stmt->execute();
    }

    log_audit($conn, $_SESSION['user_id'], 'Admin', 'SMS Settings Updated', null, 'SMS configuration updated');

    echo json_encode(["success" => true, "message" => "Settings saved"]);
    exit();
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
exit();
