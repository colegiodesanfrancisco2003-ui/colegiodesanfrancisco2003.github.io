<?php
/**
 * Audit Trail helper — Administrator > Audit Trail.
 * Include after config/database.php ($conn must already exist).
 */

require_once __DIR__ . "/../includes/notify.php";

// Which Archived Records tab (see ARCHIVE_CONFIG in admin.js) actually
// holds a given archived entity, derived from the real module/action
// log_audit() already receives — not the rendered notification title.
// Every archive-triggering log_audit() call site is listed here; add a
// line whenever a new one is introduced elsewhere. Unrecognized
// module/action combos fall back to 'students' (existing default).
if (!function_exists('_archive_tab_for')) {
    function _archive_tab_for(string $module, string $action): string
    {
        $key = $module . '|' . $action;
        $map = [
            'Clinic|Archive Clinic Visit'        => 'clinic-visits',
            'Clinic|Archive Medicine'            => 'inventory',
            'Guidance|Session Archived'          => 'counseling',
            'Guidance|Behavioral Record Archived' => 'behavioral',
            'Guidance|Offense Record Archived'   => 'offenses',
            'Students|Archive Student'           => 'students',
            'Admin|Archive User'                 => 'users',
        ];
        return $map[$key] ?? 'students';
    }
}

if (!function_exists('log_audit')) {
    function log_audit(mysqli $conn, ?int $user_id, string $module, string $action, ?int $record_id, string $details = ''): void
    {
        $stmt = $conn->prepare(
            "INSERT INTO audit_trail (user_id, module, action, record_id, details) VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) return; // audit_trail table not migrated yet — fail silently, never block the main action
        $stmt->bind_param("issis", $user_id, $module, $action, $record_id, $details);
        $stmt->execute();
        $auditId = $conn->insert_id;

        // Archive Events (Administrator notification) — a single choke
        // point instead of a notify_upsert() call added to every module
        // that archives something (Students, Clinic Visits, Medicines,
        // Users, Counseling Sessions, Behavioral Records, Offenses).
        // Detected generically off the action label so no existing CRUD
        // file needs to be touched to pick this up.
        //
        // target_page carries "archived:{tab}" (tab from _archive_tab_for
        // above) instead of a bare "archived" — this is what lets the
        // frontend jump straight to the correct Archived Records tab
        // instead of defaulting to Students every time.
        if (stripos($action, 'archiv') !== false) {
            $tab = _archive_tab_for($module, $action);
            notify_upsert(
                $conn, 'System', 'archive_event', 'info',
                "Archive Event: {$action}",
                $details !== '' ? $details : "{$module} record #{$record_id} archived",
                "archived:{$tab}", $record_id, "archive_event:{$auditId}"
            );
        }
    }
}