<?php
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";
include "../config/audit.php";
include "../includes/notify.php";
include "../includes/validate.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();


// Counseling Sessions belong to the Guidance Module — Clinic Staff must not
// be able to read or write them via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Counseling Session vocabulary ──────────────────────────────
// Source identifies where the counseling case originated. It is a
// required dropdown, kept separate from the free-text Concern field.
// "Other Guidance Concerns" additionally requires Other Source.
$ALLOWED_SOURCES = [
    'Behavioral Concern', 'Behavioral Record', 'Offense / Violation',
    'Teacher Referral', 'Parent Referral', 'Self Referral',
    'Academic Concern', 'Personal Concern', 'Other Guidance Concerns'
];

// Status flow is Scheduled -> Ongoing -> Closed (with an Overdue branch
// off Scheduled), and is never set directly by the Add/Edit form — it's
// derived automatically from session_date (see derive_session_status()
// below), or moved forward explicitly via the "Start Session"/"Complete
// Session"/"Reopen" actions further down.

// Counseling Session is the primary transaction of the Guidance workflow:
//   Student -> Behavioral Record (optional) -> Offense/Violation (optional)
//     -> Counseling Session (if needed) -> Session Closed -> Reports
// A session may optionally link back to the Behavioral Record and/or the
// Offense/Violation that prompted it. "Closed" only finalizes THAT one
// session — a later need for counseling is a brand new session, not a
// reopening of this one (see action=reopen below for its actual purpose:
// correcting/completing an already-closed session's own record).

// Automatic date-based status. Called on create, and on a plain edit
// whenever session_date changes (see the PUT handler below) — a session
// is never manually assigned a status through the form, it's always
// read off its own date:
//   session_date == today  -> Ongoing  (the day has arrived; no separate
//                              "Start Session" click needed)
//   session_date  > today  -> Scheduled
//   session_date  < today  -> Overdue  (was never started/completed
//                              before its own date passed)
function derive_session_status(string $session_date): string
{
    $today = date('Y-m-d');
    if ($session_date === $today) return 'Ongoing';
    if ($session_date > $today)   return 'Scheduled';
    return 'Overdue';
}

// Day-of auto-transition — run at the top of every GET (i.e. on every
// page load / list refresh) so status always reflects today's date even
// for sessions nobody has touched since they were created:
//   Scheduled, date has arrived (== today)      -> Ongoing
//   Scheduled, date has passed without starting -> Overdue
// Ongoing and Closed sessions are untouched — once a session actually
// started or was completed, its status reflects real staff action, not
// just the calendar, and must never be silently reverted by this check.
function auto_transition_counseling_sessions(mysqli $conn, ?int $user_id): void
{
    $toOngoing = $conn->prepare("
        SELECT id FROM counseling_sessions
        WHERE status = 'Scheduled' AND session_date = CURDATE() AND is_archived = 0
    ");
    $toOngoing->execute();
    $ongoingIds = array_column($toOngoing->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    if ($ongoingIds) {
        $conn->query("UPDATE counseling_sessions SET status = 'Ongoing' WHERE status = 'Scheduled' AND session_date = CURDATE() AND is_archived = 0");
        foreach ($ongoingIds as $sid) {
            log_audit($conn, $user_id, 'Guidance', 'Session Started', (int)$sid,
                "Auto-transitioned Scheduled \u{2192} Ongoing — session date arrived");
        }
    }

    $toOverdue = $conn->prepare("
        SELECT id FROM counseling_sessions
        WHERE status = 'Scheduled' AND session_date < CURDATE() AND is_archived = 0
    ");
    $toOverdue->execute();
    $overdueIds = array_column($toOverdue->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    if ($overdueIds) {
        $conn->query("UPDATE counseling_sessions SET status = 'Overdue' WHERE status = 'Scheduled' AND session_date < CURDATE() AND is_archived = 0");
        foreach ($overdueIds as $sid) {
            log_audit($conn, $user_id, 'Guidance', 'Session Overdue', (int)$sid,
                "Auto-flagged Overdue — session date passed without being started");
        }
    }
}

// ── GET ───────────────────────────────────────────────────────
if ($method === "GET") {
    // Every list/detail fetch first reconciles date-based statuses
    // against today's actual date — see auto_transition_counseling_sessions()
    // above. This is the "page load" hook: admin.js calls this endpoint
    // every time the Counseling Sessions page (or the dashboard, or any
    // widget reading this list) loads.
    auto_transition_counseling_sessions($conn, $_SESSION['user_id'] ?? null);

    // Per-session Timeline (Session Created → Started → Updated →
    // Case Closed, etc). Scoped to this one session's own audit_trail
    // rows only — this is the one way Guidance Staff can see any audit
    // history at all, since the full Audit Trail page (api/audit.php)
    // is Administrator-only.
    if (isset($_GET['timeline'])) {
        $sessionId = intval($_GET['timeline']);
        if (!$sessionId) { echo json_encode([]); exit(); }
        // Behavioral Records and Offenses also log under module='Guidance'
        // and can share the same numeric id as a session, so the action
        // name itself — not just module + record_id — must scope this to
        // counseling-session events only.
        $stmt = $conn->prepare("
            SELECT a.action, a.details, a.created_at, u.full_name AS user_name
            FROM audit_trail a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.module = 'Guidance' AND a.record_id = ?
              AND a.action IN (
                'Counseling Session Created', 'Session Started', 'Session Edited', 'Session Overdue',
                'Session Completed', 'Counseling Session Closed', 'Session Reopened', 'Session Archived', 'Session Restored',
                'Case Closed', 'Case Reopened'
              )
            ORDER BY a.created_at ASC, a.id ASC
        ");
        $stmt->bind_param("i", $sessionId);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
        exit();
    }

    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT cs.*, s.full_name AS student_name, s.student_code,
                   u.full_name AS counselor_name,
                   br.behavior AS behavioral_summary,
                   oc.category_name AS offense_type, o.description AS offense_description
            FROM counseling_sessions cs
            JOIN students s              ON s.id = cs.student_id
            LEFT JOIN users u             ON u.id = cs.counselor_id
            LEFT JOIN behavioral_records br ON br.id = cs.behavioral_record_id
            LEFT JOIN student_offense_records o ON o.id = cs.offense_id
            LEFT JOIN offense_categories oc     ON oc.id = o.category_id
            WHERE cs.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode($row ?: []);
        exit();
    }

    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    // Optional filter used by the "link an existing record" pickers in
    // the Add/Edit Counseling Session modal (student_id narrows results
    // to one student's own sessions when checking prior links).
    $studentFilter = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

    // Optional slice of statuses this call wants. Every other existing
    // caller of this endpoint (dashboards, KPI cards, the Recent
    // Activity timeline, the Archived Records view, etc.) keeps getting
    // every status exactly as before when this param is omitted — this
    // is purely an opt-in narrowing, not a change to the default query:
    //   status=Active -> everything EXCEPT Closed (used by the
    //       Counseling Sessions list — Closed cases live exclusively in
    //       Close Case now, so this is how they're kept out of the
    //       active list at the data source instead of hidden with CSS/JS)
    //   status=Closed -> only Closed sessions (used by the Close Case
    //       list, in place of the previous client-side-only filter)
    //   omitted        -> unfiltered by status (unchanged prior behavior)
    $statusView = isset($_GET['status']) ? trim($_GET['status']) : '';

    // Active view (archived=0) must also hide sessions belonging to an
    // archived Student — a session's own is_archived flag only tracks
    // whether the session itself was archived (see Archive Behavior
    // Standardization spec §3/§5). This does not modify the session's
    // own flag, so restoring the student later reveals it unchanged.
    $sql = "
        SELECT cs.*, s.full_name AS student_name, s.student_code,
               u.full_name AS counselor_name,
               br.behavior AS behavioral_summary,
               oc.category_name AS offense_type, o.description AS offense_description
        FROM counseling_sessions cs
        JOIN students s              ON s.id = cs.student_id
        LEFT JOIN users u             ON u.id = cs.counselor_id
        LEFT JOIN behavioral_records br ON br.id = cs.behavioral_record_id
        LEFT JOIN student_offense_records o ON o.id = cs.offense_id
        LEFT JOIN offense_categories oc     ON oc.id = o.category_id
        WHERE cs.is_archived = ?
    ";
    if (!$archivedVal) $sql .= " AND s.is_archived = 0";
    if ($statusView === 'Active') {
        $sql .= " AND cs.status <> 'Closed'";
    } elseif ($statusView === 'Closed') {
        $sql .= " AND cs.status = 'Closed'";
    }
    if ($studentFilter) $sql .= " AND cs.student_id = ? ";
    $sql .= " ORDER BY cs.session_date DESC, cs.id DESC LIMIT 5000";

    $stmt = $conn->prepare($sql);
    if ($studentFilter) {
        $stmt->bind_param("ii", $archivedVal, $studentFilter);
    } else {
        $stmt->bind_param("i", $archivedVal);
    }
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
    exit();
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === "POST") {
    $data = json_decode(file_get_contents("php://input"), true);

    $student_id           = intval($data['student_id']           ?? 0);
    $behavioral_record_id = !empty($data['behavioral_record_id']) ? intval($data['behavioral_record_id']) : null;
    $offense_id           = !empty($data['offense_id'])           ? intval($data['offense_id'])           : null;
    $counselor_id         = (int)$_SESSION['user_id'];
    $referred_by          = csf_na($data['referred_by'] ?? '');
    $source               = trim($data['source']        ?? '');
    $other_source         = trim($data['other_source']  ?? '');
    $concern              = csf_na($data['concern'] ?? '');
    // Session Notes is deliberately NOT read from the create payload.
    // A newly created session has not necessarily happened yet, so it
    // can only carry the Concern that justified creating it — Notes/
    // Action Taken/Recommendations/Outcome are documentation captured
    // later, only through the Complete Counseling Session workflow.
    $notes                = '';
    $session_date         = trim($data['session_date']   ?? '');
    // Status is never chosen manually — it's read straight off the date
    // (see derive_session_status() above). A same-day session commits
    // straight to Ongoing, no separate confirmation step.
    $status               = derive_session_status($session_date);

    if (!$student_id || !$session_date) {
        echo json_encode(["success" => false, "message" => "Student and date are required."]);
        exit();
    }
    if (!$source || !in_array($source, $ALLOWED_SOURCES, true)) {
        echo json_encode(["success" => false, "message" => "Source is required."]);
        exit();
    }
    if ($source === 'Other Guidance Concerns' && !$other_source) {
        echo json_encode(["success" => false, "message" => "Other Source is required."]);
        exit();
    }
    if ($source !== 'Other Guidance Concerns') $other_source = '';

    // Step 4 follow-up fix — server-side half of the action matrix: a
    // Behavioral Record can only ever be the trigger for ONE counseling
    // session (this is what buildBehavioralActionButtons' linked_session_id
    // check in admin.js already assumes/displays). Reject a second one
    // even if the request is crafted directly, bypassing the UI button
    // that's already hidden once linked_session_id is set. This does NOT
    // touch offense_id linking (unchanged, Step 3 scope) and does NOT
    // block linking a behavioral record that's merely "Addressed" —
    // Guidance Staff can still attach a session to it manually from the
    // Counseling module; only a genuine duplicate is rejected.
    if ($behavioral_record_id) {
        $dupStmt = $conn->prepare("
            SELECT id FROM counseling_sessions
            WHERE behavioral_record_id = ? AND is_archived = 0
              AND status <> 'Closed' LIMIT 1
        ");
        $dupStmt->bind_param("i", $behavioral_record_id);
        $dupStmt->execute();
        if ($dupStmt->get_result()->fetch_assoc()) {
            echo json_encode(["success" => false, "message" => "This Behavioral Record already has an active counseling session."]);
            exit();
        }
    }

    $stmt = $conn->prepare("
        INSERT INTO counseling_sessions
            (student_id, behavioral_record_id, offense_id, counselor_id, referred_by, source, other_source, concern, notes, status, session_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiisssssss",
        $student_id, $behavioral_record_id, $offense_id, $counselor_id,
        $referred_by, $source, $other_source, $concern, $notes, $status, $session_date
    );
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    $newId = $conn->insert_id;
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Counseling Session Created', $newId,
        $behavioral_record_id ? "Linked to Behavioral Record #{$behavioral_record_id}"
        : ($offense_id ? "Linked to Offense #{$offense_id}" : "Standalone session"));

    // Direct linkage (the actual "Launch Counseling Case from an
    // Offense" requirement): the offense record now points straight at
    // the session that was opened for it, and gets a Handling Guidance
    // Staff — the counselor this session was assigned to — instead of
    // needing a separate manual assignment step.
    if ($offense_id) {
        $offLink = $conn->prepare("UPDATE student_offense_records SET counseling_session_id = ?, handled_by = ? WHERE id = ?");
        $offLink->bind_param("iii", $newId, $counselor_id, $offense_id);
        $offLink->execute();
    }

    echo json_encode(["success" => true, "message" => "Session added", "id" => $newId, "status" => $status]);
    exit();
}

// All PUT actions (start / complete / plain update) share one request
// body — read it once here so php://input is never read twice.
if ($method === "PUT") {
    $peekComplete = json_decode(file_get_contents("php://input"), true);
}

// ── PUT: action=start → "Start Session" workflow ───────────────
// Moves a session to Ongoing. Available while Scheduled (normal case)
// or Overdue (a session whose date already passed without being
// started — starting it late is still a valid way to act on it).
if ($method === "PUT") {
    if (($peekComplete['action'] ?? '') === 'start') {
        $id = intval($peekComplete['id'] ?? 0);
        if (!$id) { echo json_encode(["success" => false, "message" => "No session ID"]); exit(); }

        $stmt = $conn->prepare("SELECT status FROM counseling_sessions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        if (!$session) { echo json_encode(["success" => false, "message" => "Session not found"]); exit(); }

        if (!in_array($session['status'], ['Scheduled', 'Overdue'], true)) {
            echo json_encode(["success" => false, "message" => "Only a Scheduled or Overdue session can be started."]);
            exit();
        }

        $upd = $conn->prepare("UPDATE counseling_sessions SET status = 'Ongoing' WHERE id = ?");
        $upd->bind_param("i", $id);
        if (!$upd->execute()) {
            error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
            echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
            exit();
        }

        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Started', $id,
            "Session moved from {$session['status']} to Ongoing");

        // The person has now acted on this session — any lingering
        // "Session Today" / "Overdue Session" / "Rescheduled" reminders
        // for it are no longer relevant.
        notify_resolve_one($conn, 'Guidance', 'session_rescheduled', "session_rescheduled:{$id}");
        notify_resolve_one($conn, 'Guidance', 'session_overdue', "session_overdue:{$id}");

        echo json_encode(["success" => true, "message" => "Session started", "status" => "Ongoing"]);
        exit();
    }
}

// ── PUT: action=complete → "Complete Counseling Session" workflow ──
// Saves Session Notes / Action Taken / Recommendations / Outcome onto
// the SAME counseling session and closes the case. Status becomes
// Closed, the session is locked (read-only from then on), and
// completion_date is set. There is no follow-up loop any more — if a
// case needs another meeting later, Guidance Staff reopens it
// (action=reopen) or opens a new session against the same student.
if ($method === "PUT") {
    if (($peekComplete['action'] ?? '') === 'complete') {
        $data = $peekComplete;

        $id              = intval($data['id'] ?? 0);
        $notes           = trim($data['notes']           ?? '');
        $action_plan     = trim($data['action_plan']     ?? '');
        $recommendations = trim($data['recommendations'] ?? '');
        $outcome         = trim($data['outcome']          ?? '');

        if (!$id) { echo json_encode(["success" => false, "message" => "No session ID"]); exit(); }

        $stmt = $conn->prepare("SELECT * FROM counseling_sessions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        if (!$session) { echo json_encode(["success" => false, "message" => "Session not found"]); exit(); }

        // A Closed session is locked — it can never be completed again.
        if ($session['status'] === 'Closed') {
            echo json_encode(["success" => false, "message" => "This counseling session is already closed and read-only."]);
            exit();
        }

        // Status flow is always Scheduled -> Ongoing -> Closed. The "✓ Done"
        // action only applies to a session that's already Ongoing — a
        // Scheduled session must be moved to Ongoing first via "Start Session".
        if ($session['status'] !== 'Ongoing') {
            echo json_encode(["success" => false, "message" => "Only an Ongoing session can be marked Done. Start the session first."]);
            exit();
        }

        $completionDateParam = date('Y-m-d');

        $upd = $conn->prepare("
            UPDATE counseling_sessions
            SET notes = ?, action_plan = ?, recommendations = ?, outcome = ?, completion_date = ?, status = 'Closed'
            WHERE id = ?
        ");
        $upd->bind_param("sssssi",
            $notes, $action_plan, $recommendations, $outcome, $completionDateParam, $id
        );
        if (!$upd->execute()) {
            error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
            echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
            exit();
        }

        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Completed', $id,
            "Session notes, action taken, recommendations, and outcome recorded");
        // "Counseling Session Closed" (not "Case Closed") — this only
        // finalizes THIS particular session. It does not mean the
        // student's counseling case/process is finished forever; a
        // future need for counseling is handled by creating a new
        // session, not by implying this one closing ends everything.
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Counseling Session Closed', $id,
            "Counseling session closed — locked read-only");

        // Closing the case is what actually "resolves" the Behavioral
        // Record and/or Offense that started it — so whichever one is
        // linked (if any) auto-updates here. Without this, Behavioral
        // Records kept showing "Open" and Offenses kept showing
        // "Warned"/"Notice Required" forever even after their counseling
        // session was already Closed.
        if (!empty($session['behavioral_record_id'])) {
            $behUpd = $conn->prepare("UPDATE behavioral_records SET status = 'Addressed' WHERE id = ? AND status != 'Addressed'");
            $behUpd->bind_param("i", $session['behavioral_record_id']);
            $behUpd->execute();
            if ($behUpd->affected_rows > 0) {
                log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Addressed', $session['behavioral_record_id'],
                    "Auto-marked Addressed — linked Counseling Session #{$id} closed");
            }
        }
        if (!empty($session['offense_id'])) {
            $offUpd = $conn->prepare("UPDATE student_offense_records SET status = 'Resolved' WHERE id = ? AND status != 'Resolved'");
            $offUpd->bind_param("i", $session['offense_id']);
            $offUpd->execute();
            if ($offUpd->affected_rows > 0) {
                log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Resolved', $session['offense_id'],
                    "Auto-marked Resolved — linked Counseling Session #{$id} closed");
            }
        }

        echo json_encode([
            "success" => true,
            "message" => "Session completed — case closed",
            "status"  => "Closed"
        ]);
        exit();
    }
}

// ── PUT: action=reopen → "Reopen Session" workflow ──────────────
// The only way a Closed Counseling Session becomes active again.
// Available to both Administrator and Guidance Staff — both already
// pass the role check at the top of this file, so no extra guard is
// needed here.
if ($method === "PUT") {
    if (($peekComplete['action'] ?? '') === 'reopen') {
        $id     = intval($peekComplete['id'] ?? 0);
        $reason = trim($peekComplete['reason'] ?? '');

        if (!$id) { echo json_encode(["success" => false, "message" => "No session ID"]); exit(); }
        if ($reason === '') {
            echo json_encode(["success" => false, "message" => "Reason for Reopening is required."]);
            exit();
        }

        $stmt = $conn->prepare("SELECT status, behavioral_record_id, offense_id FROM counseling_sessions WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        if (!$session) { echo json_encode(["success" => false, "message" => "Session not found"]); exit(); }

        if ($session['status'] !== 'Closed') {
            echo json_encode(["success" => false, "message" => "Only a Closed session can be reopened."]);
            exit();
        }

        // Reopening clears completion_date — the case is active again,
        // so it should no longer read as "closed on <date>".
        $upd = $conn->prepare("UPDATE counseling_sessions SET status = 'Ongoing', completion_date = NULL WHERE id = ?");
        $upd->bind_param("i", $id);
        if (!$upd->execute()) {
            error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
            echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
            exit();
        }

        // Mirror of the auto-Addressed/Resolved done in action=complete:
        // reopening the case means the linked Behavioral Record and/or
        // Offense is active again too, so it goes back to its
        // "needs attention" status rather than staying stuck on
        // Addressed/Resolved while its case is actually back in progress.
        if (!empty($session['behavioral_record_id'])) {
            $behUpd = $conn->prepare("UPDATE behavioral_records SET status = 'Open' WHERE id = ? AND status != 'Open'");
            $behUpd->bind_param("i", $session['behavioral_record_id']);
            $behUpd->execute();
            if ($behUpd->affected_rows > 0) {
                log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Reopened', $session['behavioral_record_id'],
                    "Auto-reverted to Open — linked Counseling Session #{$id} reopened");
            }
        }
        if (!empty($session['offense_id'])) {
            $offUpd = $conn->prepare("UPDATE student_offense_records SET status = 'Notice Required' WHERE id = ? AND status = 'Resolved'");
            $offUpd->bind_param("i", $session['offense_id']);
            $offUpd->execute();
            if ($offUpd->affected_rows > 0) {
                log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Reopened', $session['offense_id'],
                    "Auto-reverted to Notice Required — linked Counseling Session #{$id} reopened");
            }
        }

        // Reopened By (the logged-in user) and Reopened Date & Time are
        // already captured by this Audit Trail row itself (user_id +
        // created_at), and Reason for Reopening is stored in details —
        // no new counseling_sessions columns are needed for any of this.
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Reopened', $id, $reason);

        echo json_encode(["success" => true, "message" => "Session reopened", "status" => "Ongoing"]);
        exit();
    }
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === "PUT") {
    $data = $peekComplete;

    $id                   = intval($data['id']                   ?? 0);
    $student_id           = intval($data['student_id']           ?? 0);
    $behavioral_record_id = !empty($data['behavioral_record_id']) ? intval($data['behavioral_record_id']) : null;
    $offense_id           = !empty($data['offense_id'])           ? intval($data['offense_id'])           : null;
    $referred_by          = csf_na($data['referred_by'] ?? '');
    $source               = trim($data['source']          ?? '');
    $other_source         = trim($data['other_source']    ?? '');
    $concern              = csf_na($data['concern'] ?? '');
    $session_date         = trim($data['session_date']   ?? '');
    // Session Notes/Action Taken/Recommendations/Outcome are NOT part of
    // this plain "session details" edit — they belong to the Complete
    // Counseling Session workflow (action=complete) only. Leaving them
    // out of this UPDATE means an ordinary edit (e.g. fixing the Concern
    // text or Referred By) can never overwrite documentation that was
    // already recorded via Complete/Reopen.

    if (!$id || !$student_id || !$session_date) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }
    if (!$source || !in_array($source, $ALLOWED_SOURCES, true)) {
        echo json_encode(["success" => false, "message" => "Source is required."]);
        exit();
    }
    if ($source === 'Other Guidance Concerns' && !$other_source) {
        echo json_encode(["success" => false, "message" => "Other Source is required."]);
        exit();
    }
    if ($source !== 'Other Guidance Concerns') $other_source = '';

    // Closed Counseling Sessions are locked — not editable, not
    // reopenable. Only the archive/restore actions may still touch them.
    $chk = $conn->prepare("SELECT status, session_date, student_id FROM counseling_sessions WHERE id = ?");
    $chk->bind_param("i", $id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if (!$existing) { echo json_encode(["success" => false, "message" => "Session not found"]); exit(); }
    if ($existing['status'] === 'Closed') {
        echo json_encode(["success" => false, "message" => "This counseling session is closed and read-only."]);
        exit();
    }

    // A session that has already actually begun/finished (Ongoing or,
    // per the guard above, Closed) keeps its status regardless of the
    // date field — Ongoing means counseling genuinely started, and
    // editing e.g. the concern text afterward shouldn't revert that just
    // because the date now reads as "past". Scheduled/Overdue sessions
    // haven't started yet, so their status is still purely date-derived
    // and gets re-evaluated against whatever date was just submitted.
    $status = in_array($existing['status'], ['Scheduled', 'Overdue'], true)
        ? derive_session_status($session_date)
        : $existing['status'];

    $stmt = $conn->prepare("
        UPDATE counseling_sessions
        SET student_id=?, behavioral_record_id=?, offense_id=?, referred_by=?, source=?, other_source=?, concern=?, status=?, session_date=?
        WHERE id=?
    ");
    $stmt->bind_param("iiissssssi",
        $student_id, $behavioral_record_id, $offense_id, $referred_by, $source, $other_source, $concern, $status, $session_date, $id
    );
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Edited', $id, "Session details updated");
    if ($existing['status'] !== $status) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Started', $id,
            "Status recalculated from new date: {$existing['status']} \u{2192} {$status}");
    }

    // Session Rescheduled — only for a genuine date change on a still-
    // upcoming session (Scheduled/Overdue re-derived above), not every
    // routine re-save. Dedupe key is stable per-session (no date in the
    // key) so a second reschedule updates this same alert in place
    // instead of leaving the old date's notification stuck active.
    if ($existing['session_date'] !== $session_date) {
        notify_upsert($conn, 'Guidance', 'session_rescheduled', 'warning',
            'Session Rescheduled: ' . notify_student_label($conn, $student_id),
            'Moved to ' . date('M j, Y', strtotime($session_date)),
            'counseling', $id, "session_rescheduled:{$id}", $session_date);
    }

    echo json_encode(["success" => true, "message" => "Updated", "status" => $status]);
    exit();
}

// ── DELETE (Archive, or Permanent Delete) ──────────────────────
if ($method === "DELETE") {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    // ── Permanent Delete ─────────────────────────────────────────
    // Only reachable from Archived Records (?permanent=1) on a session
    // that is already archived. Another session's parent_session_id
    // (case-reopen lineage) can point at this one; its FK is ON DELETE
    // SET NULL, but it's cleared explicitly here too so the intent is
    // obvious without relying on the schema default.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, is_archived FROM counseling_sessions WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(["success" => false, "message" => "Counseling session not found."]); exit(); }
        if (!$rec['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        $conn->begin_transaction();
        try {
            $conn->query("UPDATE counseling_sessions SET parent_session_id = NULL WHERE parent_session_id = " . intval($id));
            $del = $conn->prepare("DELETE FROM counseling_sessions WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $conn->commit();
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Counseling Session Permanently Deleted', $id, "Session #$id permanently deleted");
            echo json_encode(["success" => true, "message" => "Permanently deleted"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Delete failed: " . csf_public_error($e)]);
        }
        exit();
    }

    $stmt = $conn->prepare("UPDATE counseling_sessions SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Archived', $id, "Session archived");
    echo json_encode(["success" => true, "message" => "Archived"]);
    exit();
}

// ── PATCH (Restore) ───────────────────────────────────────────
if ($method === "PATCH") {
    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }
    $stmt = $conn->prepare("UPDATE counseling_sessions SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Session Restored', $id, '');
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Restored"]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}