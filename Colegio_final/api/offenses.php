<?php
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";
include "../config/audit.php";
require_once "../includes/validate.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();


// Offenses & Violations belong to the Guidance Module — Clinic Staff must
// not be able to read or write them via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// Every row this endpoint returns aliases category_name AS `type` and
// incident_date AS `recorded_at` — this keeps every existing consumer
// (Offenses table, Student 360 profile, Reports, dashboard alerts,
// Archived Records) working unchanged even though the underlying table
// is now `student_offense_records` joined to the `offense_categories`
// masterlist instead of a flat free-text column. New consumers (the
// category-driven Log Incident form) read the additional category_id /
// severity_level / default_sanction fields directly.
const SOR_SELECT_COLUMNS = "
    sor.id, sor.student_id, sor.category_id, sor.description,
    sor.incident_date AS recorded_at, sor.count, sor.status,
    sor.reported_by, sor.handled_by, sor.counseling_session_id, sor.is_archived,
    oc.category_name AS type, oc.severity_level, oc.default_sanction
";

// ── Status rule by severity ──────────────────────────────────────
// Which status an offense gets for its Nth occurrence (per student +
// category) depends on the category's Severity Level:
//   Severe             -> Notice Required immediately (no Warned stage)
//   Major              -> Warned on the 1st, Notice Required from the 2nd
//   Minor / Moderate   -> Warned for the 1st and 2nd, Notice Required from
//                         the 3rd (up to three warnings is fine for these)
function offense_auto_status(?string $severity, int $count): string
{
    if ($severity === 'Severe') return 'Notice Required';
    $threshold = ($severity === 'Major') ? 2 : 3;
    return $count >= $threshold ? 'Notice Required' : 'Warned';
}

function offense_category_severity(mysqli $conn, int $category_id): ?string
{
    $st = $conn->prepare("SELECT severity_level FROM offense_categories WHERE id = ?");
    $st->bind_param("i", $category_id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    return $r['severity_level'] ?? null;
}

// ── Occurrence recalculation ─────────────────────────────────────
// `count` is a stored column (not calculated on the fly) so it can be
// displayed/sorted cheaply, but that means anything that changes which
// rows are "active" for a student+category group (archive, restore, a
// category edit moving a row between groups, or even a backdated
// incident_date) must resync every affected row or the stored
// count/status goes stale.
//
// Scope is always student_id + category_id, ACTIVE (is_archived = 0)
// rows only, ordered chronologically (incident_date, then id) — that
// ordering is what "occurrence #1, #2, #3..." means.
//
// $pinnedId (optional): the row the caller just wrote a status for via
// an explicit staff action (e.g. the record being edited, whose Status
// field the staff just set in the form). That row's own status is left
// alone here — only its occurrence number is resynced. Every other row
// in the group has its status re-derived from its recalculated count,
// unless it's already "Resolved" — Resolved is an intentional, final
// staff decision (or an automatic one from Case Closed — see
// api/counseling.php) and must never be silently reopened by a
// recalculation that was only about occurrence numbers.
function recalc_offense_group(mysqli $conn, int $student_id, int $category_id, ?int $user_id, ?int $pinnedId = null): void
{
    if (!$student_id || !$category_id) return;

    $stmt = $conn->prepare("
        SELECT id, count, status FROM student_offense_records
        WHERE student_id = ? AND category_id = ? AND is_archived = 0
        ORDER BY incident_date ASC, id ASC
    ");
    $stmt->bind_param("ii", $student_id, $category_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $severity = offense_category_severity($conn, $category_id);

    $i = 0;
    foreach ($rows as $row) {
        $i++;
        $newCount  = $i;
        $oldCount  = (int)$row['count'];
        $oldStatus = $row['status'];
        $isPinned  = ($pinnedId !== null && (int)$row['id'] === $pinnedId);

        if ($isPinned) {
            // Occurrence number only — status was just explicitly set
            // by the caller for this row and must not be overwritten.
            if ($oldCount !== $newCount) {
                $upd = $conn->prepare("UPDATE student_offense_records SET count = ? WHERE id = ?");
                $upd->bind_param("ii", $newCount, $row['id']);
                $upd->execute();
            }
            continue;
        }

        $newStatus = ($oldStatus === 'Resolved') ? 'Resolved' : offense_auto_status($severity, $newCount);

        if ($oldCount !== $newCount || $oldStatus !== $newStatus) {
            $upd = $conn->prepare("UPDATE student_offense_records SET count = ?, status = ? WHERE id = ?");
            $upd->bind_param("isi", $newCount, $newStatus, $row['id']);
            $upd->execute();

            if ($oldStatus !== $newStatus) {
                log_audit($conn, $user_id, 'Guidance', 'Offense Status Changed', (int)$row['id'],
                    "Offense Status Changed: $oldStatus \u{2192} $newStatus (recalculated — occurrence count changed to $newCount)");
            }
        }
    }
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT " . SOR_SELECT_COLUMNS . ", s.full_name, s.student_code, s.grade_section,
                   (SELECT cs.id FROM counseling_sessions cs
                    WHERE cs.offense_id = sor.id AND cs.is_archived = 0
                    ORDER BY cs.id DESC LIMIT 1) AS linked_session_id,
                   (SELECT cs.status FROM counseling_sessions cs
                    WHERE cs.offense_id = sor.id AND cs.is_archived = 0
                    ORDER BY cs.id DESC LIMIT 1) AS linked_session_status
            FROM student_offense_records sor
            JOIN students s              ON s.id = sor.student_id
            JOIN offense_categories oc   ON oc.id = sor.category_id
            WHERE sor.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
        exit();
    }

    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    // Optional filter used by the Counseling Session modal's "Link
    // Offense / Violation" picker — narrows the list to one student's
    // own records instead of the full table.
    $studentFilter = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

    // Active view (archived=0) must also hide records belonging to an
    // archived Student — see Archive Behavior Standardization spec §3/§5.
    $sql = "
        SELECT " . SOR_SELECT_COLUMNS . ", s.full_name, s.student_code, s.grade_section,
               (SELECT COUNT(*) FROM counseling_sessions cs
                WHERE cs.offense_id = sor.id AND cs.is_archived = 0) AS linked_session_count,
               (SELECT cs.id FROM counseling_sessions cs
                WHERE cs.offense_id = sor.id AND cs.is_archived = 0
                ORDER BY cs.id DESC LIMIT 1) AS linked_session_id,
               (SELECT cs.status FROM counseling_sessions cs
                WHERE cs.offense_id = sor.id AND cs.is_archived = 0
                ORDER BY cs.id DESC LIMIT 1) AS linked_session_status
        FROM student_offense_records sor
        JOIN students s              ON s.id = sor.student_id
        JOIN offense_categories oc   ON oc.id = sor.category_id
        WHERE sor.is_archived = ?
    ";
    if (!$archivedVal) $sql .= " AND s.is_archived = 0";
    if ($studentFilter) $sql .= " AND sor.student_id = ? ";
    $sql .= " ORDER BY sor.incident_date DESC, sor.id DESC LIMIT 5000";

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

// ── POST ──────────────────────────────────────────────────────
// New offenses don't ask staff for a Count or Status up front (see the
// "Record Offense / Violation" form) — both are worked out here:
//   count  = how many times this student has this same offense
//            category on record, including the one just added.
//   status = depends on the category's severity (see
//            offense_auto_status): Severe is Notice Required right away,
//            Major from the 2nd occurrence, Minor/Moderate from the 3rd.
//            Staff can still override the status later via Edit.
if ($method === 'POST') {
    $data        = json_decode(file_get_contents("php://input"), true);
    $student_id  = intval($data['student_id']  ?? 0);
    $category_id = intval($data['category_id'] ?? 0);
    $description = csf_na($data['description'] ?? '');
    $recorded_at = trim($data['recorded_at']     ?? date('Y-m-d'));

    if (!$student_id || !$category_id) {
        echo json_encode(["success" => false, "message" => "Student and Offense Category are required."]);
        exit();
    }

    $catChk = $conn->prepare("SELECT id FROM offense_categories WHERE id = ?");
    $catChk->bind_param("i", $category_id);
    $catChk->execute();
    if (!$catChk->get_result()->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "That offense category no longer exists."]);
        exit();
    }

    // Occurrence count must only reflect ACTIVE records — an archived
    // occurrence should not inflate the number a brand-new record gets.
    // This starting value also gets reconciled by recalc_offense_group()
    // right after insert (handles a backdated incident_date landing this
    // new row in the middle of the existing sequence instead of the end).
    $countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM student_offense_records WHERE student_id = ? AND category_id = ? AND is_archived = 0");
    $countStmt->bind_param("ii", $student_id, $category_id);
    $countStmt->execute();
    $priorCount = intval($countStmt->get_result()->fetch_assoc()['c'] ?? 0);
    $count  = $priorCount + 1;
    $status = offense_auto_status(offense_category_severity($conn, $category_id), $count);

    $stmt = $conn->prepare("
        INSERT INTO student_offense_records (student_id, category_id, description, count, status, incident_date, reported_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iisissi", $student_id, $category_id, $description, $count, $status, $recorded_at, $_SESSION['user_id']);
    $ok = $stmt->execute();
    if ($ok) {
        $newId = $conn->insert_id;
        $nameStmt = $conn->prepare("SELECT category_name FROM offense_categories WHERE id = ?");
        $nameStmt->bind_param("i", $category_id);
        $nameStmt->execute();
        $catName = $nameStmt->get_result()->fetch_assoc()['category_name'] ?? '';
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Created', $newId,
            "Offense recorded for student #$student_id ($catName) — status: $status");
        recalc_offense_group($conn, $student_id, $category_id, $_SESSION['user_id']);
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true, "message" => "Offense recorded", "id" => $newId ?? null]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    $data        = json_decode(file_get_contents("php://input"), true);
    $id          = intval($data['id']           ?? 0);
    $student_id  = intval($data['student_id']   ?? 0);
    $category_id = intval($data['category_id']  ?? 0);
    $description = csf_na($data['description'] ?? '');
    $status      = trim($data['status']           ?? '');
    $recorded_at = trim($data['recorded_at']      ?? '');

    if (!$id || !$student_id || !$category_id || !$status) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }

    // Fetch the current row first so a status/category change can be
    // audited with its old -> new value, and so both the old and new
    // occurrence groups (student_id + category_id) can be recalculated
    // afterward.
    $prevStmt = $conn->prepare("SELECT student_id, category_id, status FROM student_offense_records WHERE id = ?");
    $prevStmt->bind_param("i", $id);
    $prevStmt->execute();
    $prevRow          = $prevStmt->get_result()->fetch_assoc();
    $prevStatus       = $prevRow['status']      ?? null;
    $prevCategoryId   = isset($prevRow['category_id']) ? (int)$prevRow['category_id'] : null;
    $prevStudentId    = isset($prevRow['student_id']) ? (int)$prevRow['student_id'] : null;

    // Leaving Resolved is a REOPEN, same rule as Reopen Session in Close
    // Case: a reason is mandatory before it is allowed to go through.
    $isReopen     = ($prevStatus === 'Resolved' && $status !== 'Resolved');
    $reopenReason = trim($data['reopen_reason'] ?? '');
    if ($isReopen && $reopenReason === '') {
        echo json_encode(["success" => false, "message" => "Reason for Reopening is required."]);
        exit();
    }

    $conn->begin_transaction();
    $stmt = $conn->prepare("
        UPDATE student_offense_records SET student_id=?, category_id=?, description=?, status=?, incident_date=? WHERE id=?
    ");
    $stmt->bind_param("iisssi", $student_id, $category_id, $description, $status, $recorded_at, $id);
    $ok = $stmt->execute();

    // Back to Notice Required = the case must be launched again (Launch Case
    // (+) comes back). The earlier Closed case is left alone — it stays in
    // Close Case as history.

    if ($ok) { $conn->commit(); } else { $conn->rollback(); }

    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Updated', $id,
            "Offense record #$id updated");

        if ($prevCategoryId !== null && $prevCategoryId !== $category_id) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Category Changed', $id,
                "Offense Category Changed (category #$prevCategoryId \u{2192} #$category_id)");
        }
        if ($prevStatus !== null && $prevStatus !== $status) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Status Changed', $id,
                "Offense Status Changed: $prevStatus \u{2192} $status");
            if ($status === 'Resolved') {
                log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Resolved', $id,
                    "Offense record #$id marked Resolved");
            }
        }
        if ($isReopen) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Reopened', $id, $reopenReason);
        }

        // This row's status was just explicitly set by staff above — pin
        // it in the group it now belongs to so recalculation only fixes
        // its occurrence number, not the status staff just chose. Every
        // other row in the old and/or new group gets both resynced.
        $movedGroups = ($prevStudentId !== $student_id) || ($prevCategoryId !== $category_id);
        if ($movedGroups && $prevStudentId !== null && $prevCategoryId !== null) {
            recalc_offense_group($conn, $prevStudentId, $prevCategoryId, $_SESSION['user_id']);
        }
        recalc_offense_group($conn, $student_id, $category_id, $_SESSION['user_id'], $id);
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── DELETE (Archive, or Permanent Delete) ──────────────────────
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID"]); exit(); }

    // ── Permanent Delete ─────────────────────────────────────────
    // Only reachable from Archived Records (?permanent=1) on a record
    // that is already archived. counseling_sessions.offense_id has no
    // ON DELETE action (RESTRICT) at the database level — deleting an
    // offense still linked to a Counseling Session would fail the FK
    // constraint, so it's checked and blocked here with a clear message
    // instead of a raw DB error.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, is_archived FROM student_offense_records WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(["success" => false, "message" => "Offense record not found."]); exit(); }
        if (!$rec['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        $linkChk = $conn->prepare("SELECT COUNT(*) c FROM counseling_sessions WHERE offense_id = ?");
        $linkChk->bind_param("i", $id);
        $linkChk->execute();
        $linkedCount = (int)$linkChk->get_result()->fetch_assoc()['c'];
        if ($linkedCount > 0) {
            echo json_encode(["success" => false, "message" =>
                "Cannot permanently delete — still linked to $linkedCount Counseling Session(s). Unlink or remove those sessions first."]);
            exit();
        }

        $del = $conn->prepare("DELETE FROM student_offense_records WHERE id = ?");
        $del->bind_param("i", $id);
        $ok = $del->execute();
        if ($ok) log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Record Permanently Deleted', $id, "Offense record #$id permanently deleted");
        if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode($ok
            ? ["success" => true, "message" => "Permanently deleted"]
            : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    // Grab the group this record belongs to before archiving it, so the
    // remaining ACTIVE occurrences in that same student+category group
    // can be renumbered right after — otherwise they'd keep stale counts.
    $grpStmt = $conn->prepare("SELECT student_id, category_id FROM student_offense_records WHERE id = ?");
    $grpStmt->bind_param("i", $id);
    $grpStmt->execute();
    $grpRow = $grpStmt->get_result()->fetch_assoc();

    $stmt = $conn->prepare("UPDATE student_offense_records SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Record Archived', $id, "Offense record #$id archived");
    if ($grpRow) {
        recalc_offense_group($conn, (int)$grpRow['student_id'], (int)$grpRow['category_id'], $_SESSION['user_id']);
    }
    echo json_encode(["success" => true, "message" => "Archived"]);
    exit();
}

// ── PATCH (Restore) ──────────────────────────────────────────
if ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }
    $stmt = $conn->prepare("UPDATE student_offense_records SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Record Restored', $id, "Offense record #$id restored");

    // Restored record rejoins its student+category group — recalculate
    // the whole group so occurrence numbers (and, unless a row is
    // already Resolved, status) reflect the correct chronological order
    // again.
    $grpStmt = $conn->prepare("SELECT student_id, category_id FROM student_offense_records WHERE id = ?");
    $grpStmt->bind_param("i", $id);
    $grpStmt->execute();
    $grpRow = $grpStmt->get_result()->fetch_assoc();
    if ($grpRow) {
        recalc_offense_group($conn, (int)$grpRow['student_id'], (int)$grpRow['category_id'], $_SESSION['user_id']);
    }
    echo json_encode(["success" => true, "message" => "Restored"]);
    exit();
}
