<?php
/**
 * STEP 5 — Follow-up Center.
 *
 * Read-only aggregator. Every follow-up here already lives in an
 * existing table from Steps 1-3 (clinic_visits, counseling_sessions,
 * referrals) — this endpoint does not store anything of its own. All
 * actions (Complete / Reschedule / Resolve) reuse the existing APIs
 * (api/clinic.php, api/counseling.php, api/referrals.php); this file is
 * GET-only.
 *
 * Role boundary is enforced exactly like api/students.php?profile=:
 * Clinic Staff never receives Guidance rows, Guidance Staff never
 * receives Clinic rows, and an Administrator's ?module= param is the
 * only thing that scopes an Administrator's own view (it has no effect
 * for Clinic Staff / Guidance Staff — their role alone already
 * restricts them server-side).
 */
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Follow-up Center is read-only. Use api/clinic.php, api/counseling.php, or api/referrals.php for actions."]);
    exit();
}

$role = $_SESSION['role'] ?? '';
$canSeeClinic   = in_array($role, ['Administrator', 'Clinic Staff'], true);
$canSeeGuidance = in_array($role, ['Administrator', 'Guidance Staff'], true);

// Same Administrator module-scoping convention as api/students.php?profile=
// — an Admin viewing the Clinic-module Follow-up Center sees only Clinic
// items, and vice versa. No module param (e.g. opened from the shared
// Administrator Dashboard) means the merged view, matching how the
// Administrator Dashboard already shows both Clinic and Guidance
// follow-up counters together (see modules/shared/dashboard.php).
if ($role === 'Administrator') {
    $module = $_GET['module'] ?? '';
    if ($module === 'clinic')   $canSeeGuidance = false;
    if ($module === 'guidance') $canSeeClinic   = false;
}

$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekEnd  = date('Y-m-d', strtotime('+7 day'));

function bucket_for(string $date, string $today, string $weekEnd): string {
    if ($date < $today) return 'overdue';
    if ($date === $today) return 'today';
    if ($date <= $weekEnd) return 'upcoming';
    return 'later';
}

$items = [];

// ── CLINIC follow-ups (clinic_visits.follow_up_* — Step 2) ──────────
if ($canSeeClinic) {
    $stmt = $conn->prepare("
        SELECT cv.id, cv.student_id, s.full_name, s.student_code,
               cv.follow_up_date, cv.follow_up_reason, cv.follow_up_status,
               u.full_name AS assigned_to
        FROM clinic_visits cv
        JOIN students s ON s.id = cv.student_id
        LEFT JOIN users u ON u.id = cv.clinic_staff_id
        WHERE cv.follow_up_required = 1 AND cv.follow_up_date IS NOT NULL
          AND cv.is_archived = 0 AND s.is_archived = 0
        ORDER BY cv.follow_up_date ASC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $isDone = $row['follow_up_status'] === 'Completed';
        $items[] = [
            'source'       => 'clinic',
            'source_id'    => (int)$row['id'],
            'module'       => 'Clinic',
            'student_id'   => (int)$row['student_id'],
            'student_name' => $row['full_name'],
            'student_code' => $row['student_code'],
            'assigned_to'  => $row['assigned_to'],
            'reason'       => $row['follow_up_reason'] ?: 'Clinic Follow-up',
            'due_date'     => $row['follow_up_date'],
            'status'       => $isDone ? 'Completed' : 'Pending',
            'bucket'       => $isDone ? 'completed' : bucket_for($row['follow_up_date'], $today, $weekEnd),
            'target_page'  => 'clinic-visits',
        ];
    }

    // Pending Referrals — an unresolved referral is functionally a
    // follow-up ("has this been acted on yet?"), reusing Step 1's
    // referral outcome fields. Due date is treated as the day the
    // referral was created, since referrals don't carry a separate due
    // date of their own.
    //
    // FIX (final cleanup pass): this previously only selected
    // status='Pending', so a resolved/cancelled referral could never
    // appear under "Recently Completed" in the Follow-up Center even
    // though it went through the exact same Complete/Resolve action as
    // every other follow-up source. Now also includes referrals
    // resolved within the last 14 days, mirroring how a Clinic Follow-up
    // remains visible (as 'completed') after being marked done.
    $stmt = $conn->prepare("
        SELECT r.id, r.student_id, s.full_name, s.student_code,
               r.referred_to, r.reason, r.created_at, r.status, r.resolved_at,
               u.full_name AS assigned_to
        FROM referrals r
        JOIN students s ON s.id = r.student_id
        LEFT JOIN users u ON u.id = r.clinic_staff_id
        WHERE r.is_archived = 0 AND s.is_archived = 0
          AND (r.status = 'Pending' OR r.resolved_at >= DATE_SUB(NOW(), INTERVAL 14 DAY))
        ORDER BY r.created_at ASC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $dueDate = substr($row['created_at'], 0, 10);
        $isResolved = $row['status'] !== 'Pending';
        $items[] = [
            'source'       => 'referral',
            'source_id'    => (int)$row['id'],
            'module'       => 'Clinic',
            'student_id'   => (int)$row['student_id'],
            'student_name' => $row['full_name'],
            'student_code' => $row['student_code'],
            'assigned_to'  => $row['assigned_to'],
            'reason'       => 'Referred to ' . $row['referred_to'] . ($row['reason'] ? ' — ' . $row['reason'] : ''),
            'due_date'     => $dueDate,
            'status'       => $row['status'],
            // Referrals don't have an "overdue" concept of their own the
            // way a dated follow-up does — a 2-day-old Pending referral
            // is already flagged by notify (see api/notifications.php
            // referral_pending), so treat any Pending referral as
            // needing attention now rather than compute today/upcoming.
            'bucket'       => $isResolved ? 'completed' : ($dueDate < $today ? 'overdue' : 'today'),
            'target_page'  => 'referrals',
        ];
    }
}

// ── GUIDANCE follow-ups (counseling_sessions.follow_up_* — Step 3) ──
if ($canSeeGuidance) {
    $stmt = $conn->prepare("
        SELECT cs.id, cs.student_id, s.full_name, s.student_code,
               cs.follow_up_date, cs.follow_up_reason, cs.status,
               u.full_name AS assigned_to
        FROM counseling_sessions cs
        JOIN students s ON s.id = cs.student_id
        LEFT JOIN users u ON u.id = cs.counselor_id
        WHERE cs.follow_up_required = 1 AND cs.follow_up_date IS NOT NULL
          AND cs.status = 'Ongoing' AND cs.is_archived = 0 AND s.is_archived = 0
        ORDER BY cs.follow_up_date ASC
    ");
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $items[] = [
            'source'       => 'counseling',
            'source_id'    => (int)$row['id'],
            'module'       => 'Guidance',
            'student_id'   => (int)$row['student_id'],
            'student_name' => $row['full_name'],
            'student_code' => $row['student_code'],
            'assigned_to'  => $row['assigned_to'],
            'reason'       => $row['follow_up_reason'] ?: 'Counseling Follow-up',
            'due_date'     => $row['follow_up_date'],
            'status'       => 'Pending',
            'bucket'       => bucket_for($row['follow_up_date'], $today, $weekEnd),
            'target_page'  => 'counseling',
        ];
    }
}

// Sort: overdue first (oldest first), then today, then upcoming, then
// later/completed — matches the doc's "oldest overdue first" priority.
$bucketRank = ['overdue' => 0, 'today' => 1, 'upcoming' => 2, 'later' => 3, 'completed' => 4];
usort($items, function ($a, $b) use ($bucketRank) {
    $rankA = $bucketRank[$a['bucket']] ?? 5;
    $rankB = $bucketRank[$b['bucket']] ?? 5;
    if ($rankA !== $rankB) return $rankA <=> $rankB;
    return strcmp($a['due_date'] ?? '', $b['due_date'] ?? '');
});

echo json_encode([
    "success" => true,
    "today"    => date('Y-m-d'),
    "items"    => $items,
    "counts"   => [
        "overdue"  => count(array_filter($items, fn($i) => $i['bucket'] === 'overdue')),
        "today"    => count(array_filter($items, fn($i) => $i['bucket'] === 'today')),
        "upcoming" => count(array_filter($items, fn($i) => in_array($i['bucket'], ['upcoming', 'later'], true))),
    ],
]);
exit();
