<?php
require_once "config/session_init.php";
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== "Guidance Staff") {
  header("Location: index.php");
  exit();
}

include "config/database.php";
include "config/csrf.php";
include "config/session_guard.php";
enforce_session_timeout();

// Always pull the current user's name fresh from the database —
// don't rely on $_SESSION['full_name'] possibly being unset.
$currentUserName = "Staff";
$stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if ($row && !empty($row['full_name'])) {
    $currentUserName = $row['full_name'];
}

// ── Single source of truth ────────────────────────────────────────────
// Guidance Staff renders through the SAME shared partials as Administrator
// (modules/shared/*.php). Only the Guidance Module is visible; permissions
// are enforced by $role inside each shared partial.
$role    = "Guidance Staff";
$csfRole = "guidance";
$pageTitle = "CSF – Guidance Staff";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php include __DIR__ . "/modules/shared/head-assets.php"; ?>
</head>

<body>

<?php include __DIR__ . "/modules/shared/sidebar.php"; ?>

<div id="main">
  <?php include __DIR__ . "/modules/shared/topbar.php"; ?>

  <div id="content">

    <?php include __DIR__ . "/modules/shared/dashboard.php"; ?>

    <?php include __DIR__ . "/modules/guidance/students.php"; ?>

    <?php include __DIR__ . "/modules/guidance/behavioral.php"; ?>

    <?php include __DIR__ . "/modules/guidance/offenses.php"; ?>

    <?php include __DIR__ . "/modules/guidance/offense-categories.php"; ?>

    <?php include __DIR__ . "/modules/guidance/counseling.php"; ?>

    <?php include __DIR__ . "/modules/guidance/close-case.php"; ?>

    <?php include __DIR__ . "/modules/shared/reports.php"; ?>

    <?php include __DIR__ . "/modules/shared/archived.php"; ?>

    <?php include __DIR__ . "/modules/shared/notif-history.php"; ?>

    <?php include __DIR__ . "/modules/shared/about.php"; ?>

  </div>
</div>

<?php include __DIR__ . "/modules/clinic/modals.php"; ?>

<?php include __DIR__ . "/modules/guidance/modals.php"; ?>

<?php include __DIR__ . "/modules/shared/change-password-modal.php"; ?>

<div class="notif" id="notif"></div>

<script>
  const CURRENT_USER_NAME = <?php echo json_encode($currentUserName); ?>;
</script>

</body>
</html>
