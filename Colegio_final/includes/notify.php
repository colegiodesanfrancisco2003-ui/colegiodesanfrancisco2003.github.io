<?php
/**
 * Notification Center helper.
 *
 * Two kinds of alerts flow through the same `notifications` table:
 *
 *  - STATE-BASED (low stock, expiring medicine, overdue referral,
 *    session today, guardian contact missing): re-detected every time
 *    api/notifications.php is polled. notify_upsert() is keyed on a
 *    stable `dedupe_key` so re-detecting the same condition updates
 *    the existing row (and re-activates it if it had been resolved)
 *    instead of creating a duplicate. notify_resolve_missing() then
 *    closes out any previously-active alert of that module+type whose
 *    dedupe_key did NOT show up in this pass — the condition cleared.
 *
 *  - EVENT-BASED (referral created, SMS sent/failed, session
 *    rescheduled): written once, at the moment the triggering action
 *    happens, by calling notify_upsert() directly from that API. A
 *    unique dedupe_key (built from the source record's own id) keeps
 *    a retried/duplicate call from ever creating a second row.
 *
 * Every call is wrapped so a Notification Center problem can never
 * break the real operation it's describing — same principle as
 * config/audit.php.
 */

/**
 * Create or refresh one notification row.
 */
if (!function_exists('notify_upsert')) {
function notify_upsert(
    mysqli $conn,
    string $module,
    string $type,
    string $severity,
    string $title,
    string $detail,
    ?string $targetPage,
    ?int $targetId,
    string $dedupeKey,
    ?string $eventDate = null
): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications
                (module, type, severity, title, detail, target_page, target_id, dedupe_key, is_active, event_date, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                title    = VALUES(title),
                detail   = VALUES(detail),
                target_page = VALUES(target_page),
                target_id   = VALUES(target_id),
                event_date  = VALUES(event_date),
                is_active   = 1,
                resolved_at = NULL
        ");
        $stmt->bind_param(
            "ssssssiss",
            $module, $type, $severity, $title, $detail, $targetPage, $targetId, $dedupeKey, $eventDate
        );
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log("Notification upsert failed: " . $e->getMessage());
    }
}
}

/**
 * Marks inactive any currently-active notification of the given
 * module+type whose dedupe_key is NOT in $stillActiveDedupeKeys —
 * i.e. the condition that created it no longer holds. Only ever call
 * this for STATE-BASED types right after re-scanning every currently
 * true instance of that condition; never for event-based types.
 */
if (!function_exists('notify_resolve_missing')) {
function notify_resolve_missing(mysqli $conn, string $module, string $type, array $stillActiveDedupeKeys): void
{
    try {
        if (empty($stillActiveDedupeKeys)) {
            $stmt = $conn->prepare("
                UPDATE notifications SET is_active = 0, resolved_at = NOW()
                WHERE module = ? AND type = ? AND is_active = 1
            ");
            $stmt->bind_param("ss", $module, $type);
            $stmt->execute();
            return;
        }
        $placeholders = implode(',', array_fill(0, count($stillActiveDedupeKeys), '?'));
        $types = 'ss' . str_repeat('s', count($stillActiveDedupeKeys));
        $stmt = $conn->prepare("
            UPDATE notifications SET is_active = 0, resolved_at = NOW()
            WHERE module = ? AND type = ? AND is_active = 1 AND dedupe_key NOT IN ($placeholders)
        ");
        $stmt->bind_param($types, $module, $type, ...$stillActiveDedupeKeys);
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log("Notification resolve failed: " . $e->getMessage());
    }
}
}

/**
 * Resolves exactly one EVENT-BASED notification (by its stable dedupe_key)
 * once the action it was reminding about has actually been taken — e.g. a
 * "Session Rescheduled" alert should disappear the moment that session is
 * started, completed, or reopened, not linger forever. Safe to call even
 * if no matching active row exists.
 */
if (!function_exists('notify_resolve_one')) {
function notify_resolve_one(mysqli $conn, string $module, string $type, string $dedupeKey): void
{
    try {
        $stmt = $conn->prepare("
            UPDATE notifications SET is_active = 0, resolved_at = NOW()
            WHERE module = ? AND type = ? AND dedupe_key = ? AND is_active = 1
        ");
        $stmt->bind_param("sss", $module, $type, $dedupeKey);
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log("Notification resolve-one failed: " . $e->getMessage());
    }
}
}

/**
 * Re-detects the four medicine-stock/expiry alert conditions (low stock,
 * out of stock, expired, expiring soon) against the CURRENT state of the
 * `medicines` table and upserts/resolves the matching Clinic notification
 * rows accordingly — the exact same state-based reconcile logic that used
 * to live only inline inside api/notifications.php's GET handler.
 *
 * Pulled out into its own function so it can be called from two places
 * without duplicating the query/upsert logic itself:
 *   1) api/notifications.php  — on every poll/bell-open/page-load, so the
 *      Notification Center still self-heals even if nothing was ever
 *      written directly (e.g. a threshold config change, or a medicine
 *      edited by a route other than api/inventory.php).
 *   2) api/inventory.php      — immediately after a medicine is created,
 *      updated, archived, or restored, so the notification row exists in
 *      the SAME request as the triggering save instead of waiting for
 *      the next poll. This is what makes the Low Stock notification
 *      appear right after saving a medicine, without a page refresh.
 *
 * Calling it twice in a row (e.g. inventory.php writes it, then the
 * client's refreshNotifBadge() call hits notifications.php a moment
 * later) is always safe — notify_upsert()/notify_resolve_missing() are
 * both idempotent upserts keyed on dedupe_key, so re-running this never
 * creates a duplicate row.
 */
if (!function_exists('notify_reconcile_medicines')) {
function notify_reconcile_medicines(mysqli $conn): void
{
    $LOW_STOCK_THRESHOLD = 10;

    // Low stock (below threshold, still > 0)
    $activeKeys = [];
    $res = $conn->query("SELECT id, name, quantity, unit FROM medicines WHERE quantity > 0 AND quantity < {$LOW_STOCK_THRESHOLD} AND is_archived = 0");
    while ($row = $res->fetch_assoc()) {
        $key = "low_stock:{$row['id']}";
        $activeKeys[] = $key;
        notify_upsert($conn, 'Clinic', 'low_stock', 'warning',
            "Low Stock: {$row['name']}", "Only {$row['quantity']} {$row['unit']} remaining.",
            'inventory', (int)$row['id'], $key);
    }
    notify_resolve_missing($conn, 'Clinic', 'low_stock', $activeKeys);

    // Out of stock
    $activeKeys = [];
    $res = $conn->query("SELECT id, name FROM medicines WHERE quantity = 0 AND is_archived = 0");
    while ($row = $res->fetch_assoc()) {
        $key = "out_of_stock:{$row['id']}";
        $activeKeys[] = $key;
        notify_upsert($conn, 'Clinic', 'out_of_stock', 'critical',
            "Out of Stock: {$row['name']}", "No units remaining - restock needed.",
            'inventory', (int)$row['id'], $key);
    }
    notify_resolve_missing($conn, 'Clinic', 'out_of_stock', $activeKeys);

    // Expired / expiring within 30 days
    $expiredKeys  = [];
    $expiringKeys = [];
    $res = $conn->query("
        SELECT id, name, expiry_date FROM medicines
        WHERE expiry_date IS NOT NULL AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND is_archived = 0
    ");
    while ($row = $res->fetch_assoc()) {
        $isExpired = strtotime($row['expiry_date']) < strtotime(date('Y-m-d'));
        if ($isExpired) {
            $key = "expired:{$row['id']}";
            $expiredKeys[] = $key;
            notify_upsert($conn, 'Clinic', 'expired', 'critical',
                "Expired: {$row['name']}", 'Expired on ' . date('M j, Y', strtotime($row['expiry_date'])),
                'inventory', (int)$row['id'], $key, $row['expiry_date']);
        } else {
            $key = "expiring_soon:{$row['id']}";
            $expiringKeys[] = $key;
            notify_upsert($conn, 'Clinic', 'expiring_soon', 'warning',
                "Expiring Soon: {$row['name']}", 'Expires ' . date('M j, Y', strtotime($row['expiry_date'])),
                'inventory', (int)$row['id'], $key, $row['expiry_date']);
        }
    }
    notify_resolve_missing($conn, 'Clinic', 'expired', $expiredKeys);
    notify_resolve_missing($conn, 'Clinic', 'expiring_soon', $expiringKeys);
}
}

/**
 * "Name (STUDENT-CODE)" label, for notification titles/details that
 * reference a student — cheap single-row lookup for call sites (e.g.
 * Referral Created) that don't already have the student's name in scope.
 */
if (!function_exists('notify_student_label')) {
function notify_student_label(mysqli $conn, int $studentId): string
{
    try {
        $stmt = $conn->prepare("SELECT full_name, student_code FROM students WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) return "Student #$studentId";
        return $row['student_code'] ? "{$row['full_name']} ({$row['student_code']})" : $row['full_name'];
    } catch (\Throwable $e) {
        return "Student #$studentId";
    }
}
}
