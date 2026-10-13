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


// Behavioral Records belong to the Guidance Module — Clinic Staff must not
// be able to read or write them via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT br.*, s.full_name, s.student_code, s.grade_section,
                   u.full_name AS recorded_by_name,
                   (SELECT cs.id FROM counseling_sessions cs
                    WHERE cs.behavioral_record_id = br.id AND cs.is_archived = 0
                    ORDER BY cs.id DESC LIMIT 1) AS linked_session_id,
                   (SELECT cs.status FROM counseling_sessions cs
                    WHERE cs.behavioral_record_id = br.id AND cs.is_archived = 0
                    ORDER BY cs.id DESC LIMIT 1) AS linked_session_status
            FROM behavioral_records br
            JOIN students s    ON s.id = br.student_id
            LEFT JOIN users u  ON u.id = br.recorded_by
            WHERE br.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
        exit();
    }

    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    // Optional filter used by the Counseling Session modal's "Link
    // Behavioral Record" picker — narrows the list to one student's
    // own records instead of the full table.
    $studentFilter = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

    // Active view (archived=0) must also hide records belonging to an
    // archived Student — see Archive Behavior Standardization spec §3/§5.
    $sql = "
        SELECT br.*, s.full_name, s.student_code, s.grade_section,
               u.full_name AS recorded_by_name,
               (SELECT COUNT(*) FROM counseling_sessions cs
                WHERE cs.behavioral_record_id = br.id AND cs.is_archived = 0) AS linked_session_count,
               (SELECT cs.id FROM counseling_sessions cs
                WHERE cs.behavioral_record_id = br.id AND cs.is_archived = 0
                ORDER BY cs.id DESC LIMIT 1) AS linked_session_id,
               (SELECT cs.status FROM counseling_sessions cs
                WHERE cs.behavioral_record_id = br.id AND cs.is_archived = 0
                ORDER BY cs.id DESC LIMIT 1) AS linked_session_status
        FROM behavioral_records br
        JOIN students s    ON s.id = br.student_id
        LEFT JOIN users u  ON u.id = br.recorded_by
        WHERE br.is_archived = ?
    ";
    if (!$archivedVal) $sql .= " AND s.is_archived = 0";
    if ($studentFilter) $sql .= " AND br.student_id = ? ";
    $sql .= " ORDER BY br.recorded_at DESC LIMIT 5000";

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
if ($method === 'POST') {
    $data        = json_decode(file_get_contents("php://input"), true);
    $student_id  = intval($data['student_id']  ?? 0);
    $behavior    = trim($data['behavior']       ?? '');
    $recorded_at = trim($data['recorded_at']    ?? date('Y-m-d'));
    $recorded_by = intval($_SESSION['user_id']);

    if (!$student_id || !$behavior) {
        echo json_encode(["success" => false, "message" => "Student and behavior are required."]);
        exit();
    }

    // New records always start Open — same principle as a new Offense
    // never asking staff for a status up front (see api/offenses.php).
    $stmt = $conn->prepare("
        INSERT INTO behavioral_records (student_id, behavior, status, recorded_by, recorded_at)
        VALUES (?, ?, 'Open', ?, ?)
    ");
    $stmt->bind_param("isis", $student_id, $behavior, $recorded_by, $recorded_at);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Created', $conn->insert_id,
            "Behavioral record recorded for student #$student_id — $behavior");
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true, "message" => "Behavioral record added"]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── PUT ───────────────────────────────────────────────────────
if ($method === 'PUT') {
    $data        = json_decode(file_get_contents("php://input"), true);
    $id          = intval($data['id']           ?? 0);
    $student_id  = intval($data['student_id']   ?? 0);
    $behavior    = trim($data['behavior']        ?? '');
    $recorded_at = trim($data['recorded_at']     ?? '');
    $status      = trim($data['status']          ?? '');
    if (!in_array($status, ['Open', 'Addressed'], true)) $status = 'Open';

    if (!$id || !$student_id || !$behavior) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }

    $prevStmt = $conn->prepare("SELECT status FROM behavioral_records WHERE id = ?");
    $prevStmt->bind_param("i", $id);
    $prevStmt->execute();
    $prevStatus = $prevStmt->get_result()->fetch_assoc()['status'] ?? null;

    // Addressed -> Open is a REOPEN, same rule as Reopen Session in Close
    // Case: a reason is mandatory before it is allowed to go through.
    $isReopen     = ($prevStatus === 'Addressed' && $status === 'Open');
    $reopenReason = trim($data['reopen_reason'] ?? '');
    if ($isReopen && $reopenReason === '') {
        echo json_encode(["success" => false, "message" => "Reason for Reopening is required."]);
        exit();
    }

    $conn->begin_transaction();
    $stmt = $conn->prepare("
        UPDATE behavioral_records SET student_id=?, behavior=?, status=?, recorded_at=? WHERE id=?
    ");
    $stmt->bind_param("isssi", $student_id, $behavior, $status, $recorded_at, $id);
    $ok = $stmt->execute();

    // Reopening sends the record back to "needs a counseling session" (the
    // Create Counseling (+) button comes back). Its earlier Closed case is
    // left alone — it stays in Close Case as history.

    // Open -> Addressed via Edit: the concern is settled by hand, so any
    // still-active linked session (Scheduled / Ongoing / Overdue) is simply
    // deleted — it disappears from Counseling Sessions, is NOT sent to Close
    // Case and is NOT archived. Closed sessions are never touched.
    $removedSessionIds = [];
    if ($ok && $prevStatus === 'Open' && $status === 'Addressed') {
        $act = $conn->prepare("SELECT id FROM counseling_sessions WHERE behavioral_record_id = ? AND status != 'Closed' AND is_archived = 0");
        $act->bind_param("i", $id);
        $act->execute();
        $removedSessionIds = array_column($act->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        foreach ($removedSessionIds as $sid) {
            $sid = (int)$sid;
            // Clear anything that still points at this session first.
            $conn->query("UPDATE counseling_sessions SET parent_session_id = NULL WHERE parent_session_id = $sid");
            $conn->query("UPDATE student_offense_records SET counseling_session_id = NULL WHERE counseling_session_id = $sid");
            $del = $conn->prepare("DELETE FROM counseling_sessions WHERE id = ?");
            $del->bind_param("i", $sid);
            if (!$del->execute()) { $ok = false; break; }
        }
    }
    if ($ok) { $conn->commit(); } else { $conn->rollback(); }

    if ($ok) {
        foreach ($removedSessionIds as $sid) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Counseling Session Deleted', (int)$sid,
                "Session #$sid auto-deleted — linked Behavioral Record #$id marked Addressed");
        }
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Updated', $id,
            "Behavioral record #$id updated");
        if ($prevStatus !== null && $prevStatus !== $status) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Status Changed', $id,
                "Behavioral Record Status Changed: $prevStatus \u{2192} $status");
        }
        if ($isReopen) {
            log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Reopened', $id, $reopenReason);
        }
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
    // that is already archived. counseling_sessions.behavioral_record_id
    // is ON DELETE SET NULL at the database level, so any linked
    // Counseling Sessions safely keep their own history — they just lose
    // the link back to this behavioral record, same as the existing
    // "linked_session_count" already expects for an archived record.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, is_archived FROM behavioral_records WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(["success" => false, "message" => "Behavioral record not found."]); exit(); }
        if (!$rec['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        $del = $conn->prepare("DELETE FROM behavioral_records WHERE id = ?");
        $del->bind_param("i", $id);
        $ok = $del->execute();
        if ($ok) log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Permanently Deleted', $id, "Behavioral record #$id permanently deleted");
        if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode($ok
            ? ["success" => true, "message" => "Permanently deleted"]
            : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }

    $stmt = $conn->prepare("UPDATE behavioral_records SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Archived', $id, "Behavioral record #$id archived");
    echo json_encode(["success" => true, "message" => "Archived"]);
    exit();
}

// ── PATCH (Restore) ──────────────────────────────────────────
if ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }
    $stmt = $conn->prepare("UPDATE behavioral_records SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Behavioral Record Restored', $id, "Behavioral record #$id restored");
    echo json_encode(["success" => true, "message" => "Restored"]);
    exit();
}