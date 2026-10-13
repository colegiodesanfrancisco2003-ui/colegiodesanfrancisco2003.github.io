<?php
/**
 * Offense Categories API — the masterlist behind the Behavioral Offenses
 * module. Each category carries a Severity Level and a Default Sanction,
 * both of which the "Log Incident" form pulls in automatically once a
 * category is picked (see admin.js's offense-category dropdown handler).
 *
 * GET             -> list all categories (active ones by default; pass
 *                    ?all=1 to include inactive ones too, for the
 *                    management screen).
 * GET ?id=        -> a single category.
 * POST            -> create a category (Administrator or Guidance Staff).
 * PUT             -> update a category.
 * DELETE ?id=     -> soft-deactivate a category (is_active = 0) rather
 *                    than hard-delete — student_offense_records rows keep
 *                    referencing it (ON DELETE RESTRICT at the DB level
 *                    would block a real delete anyway once any incident
 *                    uses it), it just stops showing up for NEW incidents.
 */
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

// Same Guidance-module boundary as offenses.php / behavioral.php.
if (!in_array($_SESSION['role'], ['Administrator', 'Guidance Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$ALLOWED_SEVERITIES = ['Minor', 'Moderate', 'Major', 'Severe'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("SELECT * FROM offense_categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_assoc() ?: []);
        exit();
    }

    $sql = "SELECT * FROM offense_categories";
    if (!(isset($_GET['all']) && $_GET['all'] == '1')) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY FIELD(severity_level,'Severe','Major','Moderate','Minor'), category_name ASC";
    $res  = $conn->query($sql);
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    echo json_encode($rows);
    exit();
}

// ── POST (Create) ────────────────────────────────────────────
if ($method === 'POST') {
    $data             = json_decode(file_get_contents("php://input"), true);
    $category_name    = trim($data['category_name']    ?? '');
    $severity_level   = trim($data['severity_level']   ?? '');
    $default_sanction = csf_na($data['default_sanction'] ?? '');
    $description      = csf_na($data['description'] ?? '');

    if (!$category_name || !in_array($severity_level, $ALLOWED_SEVERITIES, true)) {
        echo json_encode(["success" => false, "message" => "Category Name and a valid Severity Level are required."]);
        exit();
    }

    $dup = $conn->prepare("SELECT id FROM offense_categories WHERE category_name = ?");
    $dup->bind_param("s", $category_name);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "A category with that name already exists."]);
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO offense_categories (category_name, severity_level, default_sanction, description)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("ssss", $category_name, $severity_level, $default_sanction, $description);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Category Created', $conn->insert_id,
            "Category \"$category_name\" added ($severity_level)");
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true, "message" => "Category added", "id" => $conn->insert_id]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── PUT (Update) ──────────────────────────────────────────────
if ($method === 'PUT') {
    $data             = json_decode(file_get_contents("php://input"), true);
    $id               = intval($data['id'] ?? 0);
    $category_name    = trim($data['category_name']    ?? '');
    $severity_level   = trim($data['severity_level']   ?? '');
    $default_sanction = csf_na($data['default_sanction'] ?? '');
    $description      = csf_na($data['description'] ?? '');
    $is_active        = isset($data['is_active']) ? (intval($data['is_active']) ? 1 : 0) : 1;

    if (!$id || !$category_name || !in_array($severity_level, $ALLOWED_SEVERITIES, true)) {
        echo json_encode(["success" => false, "message" => "Category Name and a valid Severity Level are required."]);
        exit();
    }

    $stmt = $conn->prepare("
        UPDATE offense_categories
        SET category_name = ?, severity_level = ?, default_sanction = ?, description = ?, is_active = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssii", $category_name, $severity_level, $default_sanction, $description, $is_active, $id);
    $ok = $stmt->execute();
    if ($ok) {
        log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Category Updated', $id,
            "Category \"$category_name\" updated ($severity_level)" . ($is_active ? '' : ' — deactivated'));
    }
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true]
        : ["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
    exit();
}

// ── DELETE (Deactivate — never a hard delete) ───────────────────
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID"]); exit(); }

    $stmt = $conn->prepare("UPDATE offense_categories SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    if (!$stmt->execute()) {
        error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
        echo json_encode(["success" => false, "message" => "A database error occurred. Please try again or contact the system administrator."]);
        exit();
    }
    log_audit($conn, $_SESSION['user_id'], 'Guidance', 'Offense Category Deactivated', $id, "Category #$id deactivated");
    echo json_encode(["success" => true, "message" => "Category deactivated"]);
    exit();
}
