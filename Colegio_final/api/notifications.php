<?php
/**
 * Notification Center API.
 *
 * GET                       -> active, role-scoped notifications for the
 *                              current user (bell panel), each flagged
 *                              is_read/read_at for THIS user only, plus
 *                              unread counts by severity.
 * GET ?history=1            -> same role scope, but every notification
 *                              (active AND resolved) - backs the
 *                              Notification History page.
 * POST {action:"mark_read", id}       -> marks one notification read for
 *                                        the current user only.
 * POST {action:"mark_all_read"}       -> marks every currently-unread,
 *                                        role-visible notification read
 *                                        for the current user only.
 *
 * Every GET first "reconciles" the state-based alert types (stock/
 * expiry/session-today/guardian-contact) against live data - see the
 * RECONCILE section below - so the `notifications` table always
 * reflects current reality before it's queried. Event-based types
 * (referral created, SMS sent/failed, session rescheduled) are written
 * once, at the source, by includes/notify.php callers elsewhere.
 */
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
// This endpoint is polled in the background every 60s (see admin.js) —
// it must never be served from cache, or a newly created notification
// silently won't show up until the user manually refreshes the page.
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
include "../config/database.php";
include "../includes/notify.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();

$userId         = (int)$_SESSION['user_id'];
$role           = $_SESSION['role'] ?? '';
$canSeeClinic   = in_array($role, ['Administrator', 'Clinic Staff'], true);
$canSeeGuidance = in_array($role, ['Administrator', 'Guidance Staff'], true);
// Administrator also sees System-module notifications - nobody else does.
$visibleModules = array_values(array_filter([
    $canSeeClinic   ? 'Clinic'   : null,
    $canSeeGuidance ? 'Guidance' : null,
    $role === 'Administrator' ? 'System' : null,
]));

if (empty($visibleModules)) {
    echo json_encode(["success" => true, "notifications" => [], "counts" => ["critical" => 0, "warning" => 0, "info" => 0, "success" => 0]]);
    exit();
}

// ================================================================
// RECONCILE - state-based alerts, re-detected on every fetch.
// ================================================================

if ($canSeeClinic) {
    // Low stock / out of stock / expired / expiring soon — shared with
    // api/inventory.php (see includes/notify.php) so the same reconcile
    // also runs immediately after a medicine is saved, not just here.
    notify_reconcile_medicines($conn);

    // Guardian contact number missing - only for students SMS is
    // actually enabled for (a student with SMS off has nothing to
    // notify anyone about).
    $activeKeys = [];
    $res = $conn->query("
        SELECT id, full_name, student_code FROM students
        WHERE is_archived = 0 AND sms_enabled = 1
          AND (guardian_contact IS NULL OR TRIM(guardian_contact) = '')
    ");
    while ($row = $res->fetch_assoc()) {
        $key = "guardian_missing:{$row['id']}";
        $activeKeys[] = $key;
        notify_upsert($conn, 'Clinic', 'guardian_missing', 'warning',
            "Guardian Contact Missing: {$row['full_name']}",
            "No guardian contact number on file for {$row['student_code']}.",
            'student_edit', (int)$row['id'], $key);
    }
    notify_resolve_missing($conn, 'Clinic', 'guardian_missing', $activeKeys);

    // "Referral Awaiting Outcome" alert removed along with the Outcome/
    // Resolution workflow itself (api/referrals.php no longer has a
    // PATCH) — a referral now has no status to ever leave "Pending", so
    // this would otherwise fire permanently, for every referral older
    // than 2 days, with no way to ever resolve it. Any already-active
    // 'referral_pending' rows from before this change are resolved here
    // once, so they don't linger forever as stale notifications.
    notify_resolve_missing($conn, 'Clinic', 'referral_pending', []);
}

if ($canSeeGuidance) {
    // Counseling Session Today — status auto-transitions Scheduled ->
    // Ongoing the moment its date arrives (see api/counseling.php's
    // auto_transition_counseling_sessions(), run on every page load),
    // so "today's session" now reads as Ongoing + session_date = today
    // rather than still-Scheduled. Clears once the date passes (no
    // longer matches CURDATE()) or the session is archived.
    $activeKeys = [];
    $stmt = $conn->prepare("
        SELECT cs.id, s.full_name
        FROM counseling_sessions cs JOIN students s ON s.id = cs.student_id
        WHERE cs.session_date = CURDATE() AND cs.status = 'Ongoing' AND cs.is_archived = 0
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = "session_today:{$row['id']}";
        $activeKeys[] = $key;
        notify_upsert($conn, 'Guidance', 'session_today', 'info',
            "Counseling Session Today: {$row['full_name']}", "Counseling session scheduled for today",
            'counseling', (int)$row['id'], $key, date('Y-m-d'));
    }
    notify_resolve_missing($conn, 'Guidance', 'session_today', $activeKeys);

    // Overdue Session — status auto-flips to 'Overdue' once its own
    // session_date passes without ever being started (same auto-transition
    // function referenced above). Clears once started (-> Ongoing),
    // rescheduled to a future date (-> Scheduled), or archived.
    $overdueSessionKeys = [];
    $stmt = $conn->prepare("
        SELECT cs.id, cs.session_date, s.full_name
        FROM counseling_sessions cs JOIN students s ON s.id = cs.student_id
        WHERE cs.status = 'Overdue' AND cs.is_archived = 0
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = "session_overdue:{$row['id']}";
        $overdueSessionKeys[] = $key;
        notify_upsert($conn, 'Guidance', 'session_overdue', 'critical',
            "Overdue Session: {$row['full_name']}",
            'Was scheduled for ' . date('M j, Y', strtotime($row['session_date'])),
            'counseling', (int)$row['id'], $key, $row['session_date']);
    }
    notify_resolve_missing($conn, 'Guidance', 'session_overdue', $overdueSessionKeys);

    // Case Needs Attention — an Ongoing case with no recorded activity
    // (per its own Audit Trail history) in a while. Configurable threshold;
    // clears automatically once the case is updated, completed, or closed.
    $CASE_INACTIVITY_DAYS = 10;
    $staleCaseKeys = [];
    $stmt = $conn->prepare("
        SELECT cs.id, s.full_name,
               COALESCE(MAX(a.created_at), cs.session_date) AS last_activity
        FROM counseling_sessions cs
        JOIN students s ON s.id = cs.student_id
        LEFT JOIN audit_trail a ON a.module = 'Guidance' AND a.record_id = cs.id
            AND a.action IN (
                'Counseling Session Created', 'Session Started', 'Session Edited',
                'Session Completed', 'Counseling Session Closed', 'Session Reopened',
                'Case Closed', 'Case Reopened'
            )
        WHERE cs.status = 'Ongoing' AND cs.is_archived = 0
        GROUP BY cs.id, s.full_name, cs.session_date
        HAVING last_activity <= DATE_SUB(NOW(), INTERVAL {$CASE_INACTIVITY_DAYS} DAY)
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $key = "case_inactive:{$row['id']}";
        $staleCaseKeys[] = $key;
        $daysAgo = max(1, floor((time() - strtotime($row['last_activity'])) / 86400));
        notify_upsert($conn, 'Guidance', 'case_inactive', 'warning',
            "Case Needs Attention: {$row['full_name']}",
            "No activity in {$daysAgo}+ days",
            'counseling', (int)$row['id'], $key);
    }
    notify_resolve_missing($conn, 'Guidance', 'case_inactive', $staleCaseKeys);
}

if ($role === 'Administrator') {
    // System Alert — SMS gateway enabled but no API key on file.
    // State-based, same pattern as the Clinic/Guidance reconcile blocks
    // above: re-checked on every fetch, closes itself the moment an
    // Administrator saves a key in SMS Settings.
    $activeKeys = [];
    $cfg = [];
    $res = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('sms_enabled','sms_api_key')");
    while ($row = $res->fetch_assoc()) $cfg[$row['setting_key']] = $row['setting_value'];
    if (($cfg['sms_enabled'] ?? '0') === '1' && trim($cfg['sms_api_key'] ?? '') === '') {
        $key = 'system_alert:sms_gateway';
        $activeKeys[] = $key;
        notify_upsert($conn, 'System', 'system_alert', 'critical',
            'System Alert: SMS Gateway Not Configured',
            'SMS notifications are enabled but no API key is set — guardian messages will fail to send.',
            'sms', null, $key);
    }
    notify_resolve_missing($conn, 'System', 'system_alert', $activeKeys);
}

// ================================================================
// QUERY - role-scoped, per-user read status.
// ================================================================

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $isHistory = isset($_GET['history']) && $_GET['history'] == '1';

    $modulePlaceholders = implode(',', array_fill(0, count($visibleModules), '?'));
    $sql = "
        SELECT n.*, nr.read_at
        FROM notifications n
        LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
        WHERE n.module IN ($modulePlaceholders)
    ";
    if (!$isHistory) $sql .= " AND n.is_active = 1 ";
    $sql .= " ORDER BY (nr.read_at IS NULL) DESC,
              n.is_active DESC,
              FIELD(n.severity, 'critical','warning','info','success'),
              COALESCE(n.event_date, n.created_at) DESC
              LIMIT 500";

    $types  = "i" . str_repeat("s", count($visibleModules));
    $params = array_merge([$userId], $visibleModules);

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $notifications = [];
    $counts = ["critical" => 0, "warning" => 0, "info" => 0, "success" => 0];
    while ($row = $res->fetch_assoc()) {
        $isRead = $row['read_at'] !== null;
        $notifications[] = [
            "id"          => (int)$row['id'],
            "module"      => $row['module'],
            "type"        => $row['type'],
            "severity"    => $row['severity'],
            "title"       => $row['title'],
            "detail"      => $row['detail'],
            "target_page" => $row['target_page'],
            "target_id"   => $row['target_id'] !== null ? (int)$row['target_id'] : null,
            "date"        => $row['event_date'],
            "created_at"  => $row['created_at'],
            "is_active"   => (bool)$row['is_active'],
            "is_read"     => $isRead,
            "read_at"     => $row['read_at'],
        ];
        if (!$isRead && $row['is_active']) $counts[$row['severity']]++;
    }

    echo json_encode(["success" => true, "notifications" => $notifications, "counts" => $counts]);
    exit();
}

if ($method === 'POST') {
    $data   = json_decode(file_get_contents("php://input"), true);
    $action = trim($data['action'] ?? '');

    if ($action === 'mark_read') {
        $id = intval($data['id'] ?? 0);
        if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }
        // Only notifications in modules this role can actually see may be
        // marked read; unknown IDs simply insert nothing (no FK error).
        $modulePlaceholders = implode(',', array_fill(0, count($visibleModules), '?'));
        $stmt = $conn->prepare("
            INSERT INTO notification_reads (notification_id, user_id, read_at)
            SELECT n.id, ?, NOW() FROM notifications n
            WHERE n.id = ? AND n.module IN ($modulePlaceholders)
            ON DUPLICATE KEY UPDATE read_at = read_at
        ");
        $stmt->bind_param("ii" . str_repeat("s", count($visibleModules)), $userId, $id, ...$visibleModules);
        $ok = $stmt->execute();
        if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode($ok
            ? ["success" => true]
            : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    if ($action === 'mark_all_read') {
        $modulePlaceholders = implode(',', array_fill(0, count($visibleModules), '?'));
        $sql = "
            INSERT INTO notification_reads (notification_id, user_id, read_at)
            SELECT n.id, ?, NOW() FROM notifications n
            WHERE n.module IN ($modulePlaceholders)
              AND NOT EXISTS (SELECT 1 FROM notification_reads nr WHERE nr.notification_id = n.id AND nr.user_id = ?)
            ON DUPLICATE KEY UPDATE read_at = read_at
        ";
        $types  = "i" . str_repeat("s", count($visibleModules)) . "i";
        $params = array_merge([$userId], $visibleModules, [$userId]);
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode($ok
            ? ["success" => true]
            : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    echo json_encode(["success" => false, "message" => "Unknown action"]);
    exit();
}

echo json_encode(["success" => false, "message" => "Method not allowed"]);
exit();