<?php
require_once "../config/session_init.php";
session_start();
include "../config/database.php";
header("Content-Type: application/json");

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();
include "../config/audit.php";
include "../includes/notify.php";


// Medicine Inventory belongs to the Clinic Module — Guidance Staff must not
// be able to read or write it via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === "GET") {
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;

    if ($id) {
        $stmt = $conn->prepare("SELECT * FROM medicines WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode($row ?: []);
    } else {
        $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;
        $stmt = $conn->prepare("SELECT * FROM medicines WHERE is_archived = ? ORDER BY name ASC LIMIT 2000");
        $stmt->bind_param("i", $archivedVal);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        echo json_encode($rows);
    }
    exit();
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === "POST") {
    $data       = json_decode(file_get_contents("php://input"), true);
    $name       = trim($data['name']        ?? '');
    $quantity   = intval($data['quantity']  ?? 0);
    $unit       = trim($data['unit']        ?? '');
    $expiryDate = trim($data['expiry_date'] ?? '');

    if (!$name)       { echo json_encode(["success" => false, "message" => "Medicine name is required."]); exit(); }
    if (!$unit)       { echo json_encode(["success" => false, "message" => "Unit is required."]); exit(); }
    if (!$expiryDate) { echo json_encode(["success" => false, "message" => "Expiry date is required."]); exit(); }
    if ($quantity < 0){ echo json_encode(["success" => false, "message" => "Quantity cannot be negative."]); exit(); }

    $stmt = $conn->prepare("INSERT INTO medicines (name, quantity, unit, expiry_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("siss", $name, $quantity, $unit, $expiryDate);
    $ok = $stmt->execute();
    // Re-detect low_stock/out_of_stock/expired/expiring_soon right now,
    // in this same request, instead of waiting for the next notification
    // poll — this is what makes a newly-added low-stock medicine show up
    // in the bell immediately.
    if ($ok) notify_reconcile_medicines($conn);
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === "PUT") {
    $data       = json_decode(file_get_contents("php://input"), true);
    $id         = intval($data['id']         ?? 0);
    $name       = trim($data['name']         ?? '');
    $quantity   = intval($data['quantity']   ?? 0);
    $unit       = trim($data['unit']         ?? '');
    $expiryDate = trim($data['expiry_date']  ?? '');

    if (!$id || !$name || !$unit || !$expiryDate) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }
    // Same rule as the POST branch (final audit: PUT previously accepted negative stock).
    if ($quantity < 0) { echo json_encode(["success" => false, "message" => "Quantity cannot be negative."]); exit(); }

    $stmt = $conn->prepare("UPDATE medicines SET name=?, quantity=?, unit=?, expiry_date=? WHERE id=?");
    $stmt->bind_param("sissi", $name, $quantity, $unit, $expiryDate, $id);
    $ok = $stmt->execute();
    // Same immediate reconcile as the POST branch above — a quantity
    // edit that drops below/rises above the low-stock threshold (or an
    // expiry_date edit) needs to be reflected in notifications.php's
    // notifications table right away, not on the next poll.
    if ($ok) notify_reconcile_medicines($conn);
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── DELETE (Archive, or Permanent Delete) ──────────────────────
if ($method === "DELETE") {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    // ── Permanent Delete ─────────────────────────────────────────
    // Only reachable from Archived Records (?permanent=1) on a medicine
    // that is already archived. treatments.medicine_id has no ON DELETE
    // action (RESTRICT), so deleting a medicine still referenced by
    // Treatment History would fail the FK constraint — checked and
    // blocked here with a clear message instead of a raw DB error.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, name, is_archived FROM medicines WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $rec = $chk->get_result()->fetch_assoc();
        if (!$rec) { echo json_encode(["success" => false, "message" => "Medicine not found."]); exit(); }
        if (!$rec['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        $linkChk = $conn->prepare("SELECT COUNT(*) c FROM treatments WHERE medicine_id = ?");
        $linkChk->bind_param("i", $id);
        $linkChk->execute();
        $linkedCount = (int)$linkChk->get_result()->fetch_assoc()['c'];
        if ($linkedCount > 0) {
            echo json_encode(["success" => false, "message" =>
                "Cannot permanently delete — referenced by $linkedCount Treatment History record(s)."]);
            exit();
        }

        $del = $conn->prepare("DELETE FROM medicines WHERE id = ?");
        $del->bind_param("i", $id);
        $ok = $del->execute();
        if ($ok) log_audit($conn, $_SESSION['user_id'], 'Clinic', 'Medicine Permanently Deleted', $id, $rec['name']);
        if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode($ok
            ? ["success" => true,  "message" => "Permanently deleted"]
            : ["success" => false, "message" => "Delete failed. Please try again or contact the system administrator."]);
        exit();
    }

    $stmt = $conn->prepare("UPDATE medicines SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Clinic', 'Archive Medicine', $id, '');
        // Archived medicines are excluded from the reconcile queries, so
        // this resolves any active low_stock/out_of_stock/expired/
        // expiring_soon notification for it immediately.
        notify_reconcile_medicines($conn);
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Archived"]
        : ["success" => false, "message" => "Archive failed. Please try again or contact the system administrator."]);
    exit();
}

// ── PATCH (Restore) ───────────────────────────────────────────
if ($method === "PATCH") {
    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }
    $stmt = $conn->prepare("UPDATE medicines SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Clinic', 'Restore Medicine', $id, '');
        // A restored medicine that is still low/out of stock or expired
        // should reappear in notifications immediately, not on next poll.
        notify_reconcile_medicines($conn);
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Restored"]
        : ["success" => false, "message" => "Restore failed. Please try again or contact the system administrator."]);
    exit();
}