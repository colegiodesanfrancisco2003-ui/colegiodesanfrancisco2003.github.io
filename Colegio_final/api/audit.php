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

// Audit Trail is an Administrator-only view — it surfaces actions taken
// across every module (Clinic, Guidance, Users, etc.), so it must not
// be reachable by Clinic Staff or Guidance Staff.
if (($_SESSION['role'] ?? '') !== 'Administrator') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

// Optional filters
$module = trim($_GET['module'] ?? '');
$limit  = min(500, max(1, intval($_GET['limit'] ?? 200)));

$sql = "
    SELECT a.id, a.module, a.action, a.record_id, a.details, a.created_at,
           u.full_name AS user_name, u.role AS user_role
    FROM audit_trail a
    LEFT JOIN users u ON u.id = a.user_id
";
$params = [];
$types  = "";

if ($module !== '') {
    $sql .= " WHERE a.module = ? ";
    $params[] = $module;
    $types   .= "s";
}

$sql .= " ORDER BY a.created_at DESC LIMIT ?";
$params[] = $limit;
$types   .= "i";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $row['user_name'] = $row['user_name'] ?? 'System';
    $rows[] = $row;
}

// Distinct modules for the filter dropdown, so it only ever shows
// modules that actually have logged activity.
$moduleResult = $conn->query("SELECT DISTINCT module FROM audit_trail ORDER BY module ASC");
$modules = [];
while ($m = $moduleResult->fetch_assoc()) $modules[] = $m['module'];

echo json_encode([
    "success" => true,
    "entries" => $rows,
    "modules" => $modules,
]);
exit();
