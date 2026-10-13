<?php
require_once "../config/session_init.php";
session_start();
include "../config/database.php";

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "Administrator") {
    http_response_code(403);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();
include "../config/audit.php";
require_once "../includes/notify.php";
require_once "../includes/validate.php";


$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'list') {
    // By default, only Active users are shown — this keeps the normal
    // User Management table clean. Pass ?archived=1 (or action=list with
    // archived=1) to fetch Inactive (archived) users instead, used by
    // the Archived Records page.
    $showArchived = (($_POST['archived'] ?? $_GET['archived'] ?? '') == '1');
    $statusVal    = $showArchived ? 'Inactive' : 'Active';

    $stmt = $conn->prepare("SELECT id, full_name, username, role, status, created_at FROM users WHERE role != 'Administrator' AND status = ?");
    $stmt->bind_param("s", $statusVal);
    $stmt->execute();
    $result = $stmt->get_result();
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    echo json_encode($users);
    exit();
}

if ($action === 'add') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $rawPassword = $_POST['password'] ?? '';
    $role      = trim($_POST['role'] ?? '');

    $missing = csf_require_fields(
        ['full_name' => $full_name, 'username' => $username, 'role' => $role],
        ['full_name' => 'Full Name', 'username' => 'Username', 'role' => 'Role']
    );
    if ($rawPassword === '') $missing[] = 'Password';
    if ($missing) csf_fail(implode(', ', $missing) . ' ' . (count($missing) > 1 ? 'are' : 'is') . ' required.');
    if ($rawPassword !== '' && strlen($rawPassword) < 8) {
        csf_fail('Password must be at least 8 characters.');
    }

    if (!in_array($role, ['Clinic Staff', 'Guidance Staff'], true)) {
        csf_fail('Please choose a valid Role.');
    }

    $password = password_hash($rawPassword, PASSWORD_DEFAULT);

    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists.']);
        exit();
    }

    $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $full_name, $username, $password, $role);
    $stmt->execute();
    $newUserId = $conn->insert_id;
    log_audit($conn, $_SESSION['user_id'], 'Admin', 'Create User', $newUserId, "{$full_name} ({$role})");

    notify_upsert($conn, 'System', 'new_user', 'info',
        "New User: {$full_name}",
        "{$role} account created (username: {$username})",
        'users', $newUserId, "new_user:{$newUserId}");

    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'edit') {
    $id        = intval($_POST['id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $role      = trim($_POST['role'] ?? '');

    $missing = csf_require_fields(
        ['full_name' => $full_name, 'username' => $username, 'role' => $role],
        ['full_name' => 'Full Name', 'username' => 'Username', 'role' => 'Role']
    );
    if ($missing) csf_fail(implode(', ', $missing) . ' ' . (count($missing) > 1 ? 'are' : 'is') . ' required.');

    if (!in_array($role, ['Clinic Staff', 'Guidance Staff'], true)) {
        csf_fail('Please choose a valid Role.');
    }

    // Same guard as delete/restore/permanent_delete: this endpoint only ever manages
    // Clinic/Guidance staff accounts — never an Administrator account (the Users list
    // already excludes them), so a crafted id can't demote/lock out the Administrator.
    $own = $conn->prepare("SELECT id FROM users WHERE id = ? AND role != 'Administrator'");
    $own->bind_param("i", $id);
    $own->execute();
    if (!$own->get_result()->fetch_assoc()) csf_fail('User not found.');

    $check = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->bind_param("si", $username, $id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'Username already exists.']);
        exit();
    }

    if (!empty($_POST['password'])) {
        if (strlen($_POST['password']) < 8) {
            csf_fail('New password must be at least 8 characters.');
        }
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, password=?, role=? WHERE id=?");
        $stmt->bind_param("ssssi", $full_name, $username, $password, $role, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET full_name=?, username=?, role=? WHERE id=?");
        $stmt->bind_param("sssi", $full_name, $username, $role, $id);
    }

    $stmt->execute();
    log_audit($conn, $_SESSION['user_id'], 'Admin', 'Edit User', (int)$id, "{$full_name} ({$role})");
    echo json_encode(['success' => true]);
    exit();
}

// "Delete" (Archive) — sets status to 'Inactive' instead of removing the
// row, since users.id is referenced by treatments.given_by,
// counseling_sessions.counselor_id, and behavioral_records.recorded_by.
// Hard-deleting would either fail on the foreign key or silently orphan
// historical records. The user can be restored anytime from the Archived
// Records page.
if ($action === 'delete') {
    $id = $_POST['id'];
    $stmt = $conn->prepare("UPDATE users SET status = 'Inactive' WHERE id = ? AND role != 'Administrator'");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Admin', 'Archive User', (int)$id, '');
    echo json_encode(['success' => $ok]);
    exit();
}

// Restore — brings an archived (Inactive) user back to Active.
if ($action === 'restore') {
    $id = $_POST['id'] ?? $_GET['id'] ?? null;
    if (!$id) { echo json_encode(['success' => false, 'message' => 'No ID']); exit(); }
    $stmt = $conn->prepare("UPDATE users SET status = 'Active' WHERE id = ? AND role != 'Administrator'");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Admin', 'Restore User', (int)$id, '');
    echo json_encode(['success' => $ok]);
    exit();
}

// Permanent Delete — only reachable from Archived Records, and only for
// a user that is already archived (Inactive). Only ever irreversible, so
// it also requires the typed "DELETE" confirmation from the UI.
//
// A user's id is referenced — more importantly — by historical
// Clinic/Guidance records the user created (clinic_visits.clinic_staff_id,
// treatments.given_by, counseling_sessions.counselor_id,
// behavioral_records.recorded_by), plus audit_trail.
// None of those foreign keys cascade-delete, which is exactly why
// Archive (deactivate) was chosen over a hard delete for Users in the
// first place (see the comment on the 'delete' action above). Permanent
// deletion is therefore only allowed once none of that history remains
// tied to the account.
if ($action === 'permanent_delete') {
    $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['success' => false, 'message' => 'No ID']); exit(); }

    $confirm = trim($_POST['confirm'] ?? $_GET['confirm'] ?? '');
    if ($confirm !== 'DELETE') {
        echo json_encode(['success' => false, 'message' => 'Type DELETE to confirm permanent deletion.']);
        exit();
    }

    $chk = $conn->prepare("SELECT id, full_name, status FROM users WHERE id = ? AND role != 'Administrator'");
    $chk->bind_param("i", $id);
    $chk->execute();
    $user = $chk->get_result()->fetch_assoc();
    if (!$user) { echo json_encode(['success' => false, 'message' => 'User not found.']); exit(); }
    if ($user['status'] !== 'Inactive') {
        echo json_encode(['success' => false, 'message' => 'Only an archived (Inactive) user can be permanently deleted.']);
        exit();
    }

    $depCounts = [];
    $depQueries = [
        'Clinic Visits logged'       => "SELECT COUNT(*) c FROM clinic_visits WHERE clinic_staff_id = ?",
        'Treatments given'           => "SELECT COUNT(*) c FROM treatments WHERE given_by = ?",
        'Counseling Sessions'        => "SELECT COUNT(*) c FROM counseling_sessions WHERE counselor_id = ?",
        'Behavioral Records'         => "SELECT COUNT(*) c FROM behavioral_records WHERE recorded_by = ?",
        // Step 3 fix: this previously queried `audit_logs` — a table that
        // (a) has had zero writers since the Step 2 stack migration and
        // (b) is empty/orphaned in general — so it always reported 0
        // regardless of the account's real audit history. The Audit Trail
        // the Administrator actually sees is `audit_trail`, so that's the
        // table this safety check needs to look at.
        'Audit Trail entries'        => "SELECT COUNT(*) c FROM audit_trail WHERE user_id = ?",
    ];
    foreach ($depQueries as $label => $q) {
        $s = $conn->prepare($q);
        if (!$s) continue; // table not present in this install — skip safely
        $s->bind_param("i", $id);
        $s->execute();
        $c = (int)$s->get_result()->fetch_assoc()['c'];
        if ($c > 0) $depCounts[] = "$label ($c)";
    }
    if ($depCounts) {
        echo json_encode(['success' => false, 'message' =>
            "Cannot permanently delete — this account still has related records: " . implode(', ', $depCounts) .
            ". Permanent deletion is only allowed for accounts with no history on record."]);
        exit();
    }

    $del = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'Administrator'");
    $del->bind_param("i", $id);
    $ok = $del->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Admin', 'User Permanently Deleted', (int)$id, $user['full_name']);
    echo json_encode(['success' => $ok]);
    exit();
}
?> 