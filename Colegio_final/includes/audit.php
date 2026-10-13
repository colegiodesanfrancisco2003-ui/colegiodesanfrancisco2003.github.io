<?php
/**
 * Audit Trail helper — Phase 1 (Clinic Workflow).
 *
 * Usage from any api/*.php file, AFTER $conn is already connected and
 * session_start() has already run:
 *
 *   require_once __DIR__ . "/../includes/audit.php";
 *   log_audit($conn, "clinic_visit_created", "clinic_visits", $visitId, "Recorded visit for student #$studentId");
 *
 * Deliberately fails silently (never throws) — a logging failure must
 * never roll back or block the real operation it's describing. Errors
 * are still sent to the PHP error log so they aren't invisible.
 */
function log_audit($conn, string $action, string $entityType, ?int $entityId, string $description): void
{
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $role   = $_SESSION['role']    ?? null;

        $stmt = $conn->prepare("
            INSERT INTO audit_logs (user_id, role, action, entity_type, entity_id, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssis", $userId, $role, $action, $entityType, $entityId, $description);
        $stmt->execute();
    } catch (\Throwable $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
