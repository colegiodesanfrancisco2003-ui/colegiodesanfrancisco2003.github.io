<?php
/**
 * Clinic Visit API — the ONLY primary transaction of the Clinic Module.
 *
 * A Clinic Visit is the single source of truth for a medical encounter.
 * Saving one automatically (in a single DB transaction):
 *   - validates stock and deducts inventory for every medicine dispensed
 *   - writes each dispensed medicine as a Treatment History row (`treatments`,
 *     linked back to this visit via visit_id) — Treatment History is a
 *     read-only VIEW of this data, never created/edited directly by a user.
 *   - writes a Referral History row (`referrals`) when Referral Required = Yes
 *   - writes an Audit Trail entry
 *
 * Medicine Dispensing, Treatment History and Referral History are NOT
 * separate CRUD modules — they only exist as a consequence of a Clinic
 * Visit being saved here.
 */
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();
require_once "../config/audit.php";
require_once "../includes/validate.php";
require_once "../includes/sms.php";
require_once "../includes/notify.php";

// Clinic Visits belong to the Clinic Module — Guidance Staff must not be
// able to read or write them via direct API calls.
if (!in_array($_SESSION['role'], ['Administrator', 'Clinic Staff'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

$method        = $_SERVER['REQUEST_METHOD'];
$currentUserId = (int)$_SESSION['user_id'];

// ── Helpers ──────────────────────────────────────────────────────
function fetch_dispensed_medicines(mysqli $conn, int $visitId): array {
    $stmt = $conn->prepare("
        SELECT t.id, t.medicine_id, t.medicine, t.dosage, t.quantity_used
        FROM treatments t
        WHERE t.visit_id = ? AND t.is_archived = 0
        ORDER BY t.id ASC
    ");
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $rows = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function fetch_referral(mysqli $conn, int $visitId): ?array {
    $stmt = $conn->prepare("
        SELECT id, referred_to, reason, created_at, is_archived
        FROM referrals
        WHERE visit_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $visitId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT cv.*, s.full_name, s.student_code, u.full_name AS clinic_staff_name
            FROM clinic_visits cv
            JOIN students s ON s.id = cv.student_id
            LEFT JOIN users u ON u.id = cv.clinic_staff_id
            WHERE cv.id = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) { echo json_encode([]); exit(); }

        $row['medicines_dispensed'] = fetch_dispensed_medicines($conn, $id);
        $row['referral']            = fetch_referral($conn, $id);
        echo json_encode($row);
        exit();
    }

    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    // Active view (archived=0) must also hide visits belonging to an
    // archived Student — the visit's own is_archived flag only tracks
    // whether the visit itself was archived. This does NOT touch the
    // visit's own archive state, so it stays correct if the student is
    // later restored (see Archive Behavior Standardization spec §3/§5).
    $sql = "
        SELECT cv.*, s.full_name, s.student_code, u.full_name AS clinic_staff_name
        FROM clinic_visits cv
        JOIN students s ON s.id = cv.student_id
        LEFT JOIN users u ON u.id = cv.clinic_staff_id
        WHERE cv.is_archived = ?
    ";
    if (!$archivedVal) $sql .= " AND s.is_archived = 0";
    $sql .= " ORDER BY cv.visited_at DESC LIMIT 5000";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $archivedVal);
    $stmt->execute();
    $visits = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $visits[] = $row;
    echo json_encode($visits);
    exit();
}

// ── POST (Create Visit — the ONLY way Treatment/Referral History rows
//    ever get created) ───────────────────────────────────────────
if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $studentId  = intval($data['student_id'] ?? 0);
    $symptoms   = csf_blank_if_na($data['symptoms'] ?? ''); // Chief Complaint
    $findings   = csf_blank_if_na($data['findings'] ?? ''); // Findings / Observation
    $treatment  = csf_blank_if_na($data['treatment'] ?? ''); // Treatment Given
    $assessment = trim($data['assessment'] ?? '');
    $status     = trim($data['status']     ?? '');
    $visitedAt  = trim($data['visited_at'] ?? '');
    $remarks    = csf_blank_if_na($data['remarks'] ?? '');

    $referralRequired = !empty($data['referral_required']);
    $referralDest      = csf_blank_if_na($data['referral_destination'] ?? '');
    $referralReason    = csf_blank_if_na($data['referral_reason'] ?? '');

    $medicines = is_array($data['medicines'] ?? null) ? $data['medicines'] : [];

    // `assessment` is an ENUM and rejects '' — treat "not selected" as NULL.
    $assessment = $assessment === '' ? null : $assessment;

    if (!$studentId || !$status || !$visitedAt) {
        echo json_encode(["success" => false, "message" => "Student, status, and date/time are required."]);
        exit();
    }
    if (!in_array($assessment, ['Mild', 'Moderate', 'Severe'], true)) {
        echo json_encode(["success" => false, "message" => "Assessment is required."]);
        exit();
    }
    if ($referralRequired && (!$referralDest || !$referralReason)) {
        echo json_encode(["success" => false, "message" => "Referral destination and reason are required when Referral Required is Yes."]);
        exit();
    }

    // Normalize + validate the dispensing lines up front (before touching the DB).
    $dispenseLines = []; // medicine_id => total quantity requested
    foreach ($medicines as $m) {
        $medId = intval($m['medicine_id'] ?? 0);
        $qty   = intval($m['quantity']    ?? 0);
        if (!$medId || $qty < 1) {
            echo json_encode(["success" => false, "message" => "Each dispensed medicine needs a valid selection and a quantity of at least 1."]);
            exit();
        }
        $dispenseLines[$medId] = ($dispenseLines[$medId] ?? 0) + $qty;
    }

    $conn->begin_transaction();
    try {
        // 1. Save the Clinic Visit.
        // Columns: student_id(i) symptoms(s) findings(s) treatment(s) assessment(s) status(s)
        //          clinic_staff_id(i) remarks(s) referral_required(i) referral_destination(s)
        //          referral_reason(s) visited_at(s)  => type string "isssssisisss"
        $referralRequiredInt = $referralRequired ? 1 : 0;
        // Optional text left blank is saved as "N/A" (the real $symptoms
        // is still used below for notifications / SMS).
        $symptomsDb = csf_na($symptoms); $findingsDb = csf_na($findings);
        $treatmentDb = csf_na($treatment); $remarksDb = csf_na($remarks);
        $refDestDb = csf_na($referralDest); $refReasonDb = csf_na($referralReason);
        $stmt = $conn->prepare("
            INSERT INTO clinic_visits
                (student_id, symptoms, findings, treatment, assessment, status,
                 clinic_staff_id, remarks, referral_required, referral_destination,
                 referral_reason, visited_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("isssssisisss",
            $studentId, $symptomsDb, $findingsDb, $treatmentDb, $assessment, $status,
            $currentUserId, $remarksDb, $referralRequiredInt, $refDestDb,
            $refReasonDb, $visitedAt
        );
        $stmt->execute();
        $visitId = $conn->insert_id;
        $visitDateOnly = substr($visitedAt, 0, 10);

        // 1b. Event-based notifications for this visit — Clinic Visit
        //     Today fires for every recorded visit; Student Sent Home /
        //     Emergency Case fire only when the visit is saved directly
        //     with that status (see the PUT handler below for edits that
        //     transition INTO one of these statuses later).
        $studentLabel = notify_student_label($conn, $studentId);
        notify_upsert($conn, 'Clinic', 'clinic_visit_today', 'info',
            "Clinic Visit: {$studentLabel}",
            "Visit recorded — status: {$status}",
            'clinic-visits', $visitId, "clinic_visit:{$visitId}", $visitDateOnly);

        if (strcasecmp($status, 'Sent Home') === 0) {
            notify_upsert($conn, 'Clinic', 'student_sent_home', 'warning',
                "Student Sent Home: {$studentLabel}",
                "Sent home from visit #{$visitId}" . ($symptoms ? " — {$symptoms}" : ""),
                'clinic-visits', $visitId, "sent_home:{$visitId}", $visitDateOnly);
        } elseif (strcasecmp($status, 'Emergency') === 0) {
            notify_upsert($conn, 'Clinic', 'emergency_case', 'critical',
                "Emergency Case: {$studentLabel}",
                "Flagged Emergency on visit #{$visitId}" . ($symptoms ? " — {$symptoms}" : ""),
                'clinic-visits', $visitId, "emergency:{$visitId}", $visitDateOnly);
        }

        // 2. Medicine Dispensing — validate stock, deduct, and write each
        //    line straight into Treatment History (`treatments`).
        $dispensedSummary = [];
        foreach ($dispenseLines as $medId => $qty) {
            $s = $conn->prepare("SELECT name, unit, quantity, expiry_date FROM medicines WHERE id = ? AND is_archived = 0 FOR UPDATE");
            $s->bind_param("i", $medId);
            $s->execute();
            $med = $s->get_result()->fetch_assoc();

            if (!$med) throw new Exception("Selected medicine (#$medId) not found in active inventory.");

            if ($med['expiry_date'] && $med['expiry_date'] !== '0000-00-00') {
                $expDate = new DateTime($med['expiry_date']);
                if ($expDate < new DateTime('today')) {
                    throw new Exception("Cannot dispense — \"{$med['name']}\" expired on {$med['expiry_date']}.");
                }
            }
            if ((int)$med['quantity'] < $qty) {
                throw new Exception("Not enough stock for \"{$med['name']}\". Only {$med['quantity']} {$med['unit']} left.");
            }

            $dosageText = "{$qty} {$med['unit']}";
            $t = $conn->prepare("
                INSERT INTO treatments (student_id, visit_id, medicine_id, medicine, dosage, quantity_used, given_by, given_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $t->bind_param("iiissiis", $studentId, $visitId, $medId, $med['name'], $dosageText, $qty, $currentUserId, $visitedAt);
            $t->execute();

            $d = $conn->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?");
            $d->bind_param("ii", $qty, $medId);
            $d->execute();

            $dispensedSummary[] = "{$med['name']} x{$qty}";
        }

        // 3. Referral History — auto-generated ONLY when required.
        $referralId = null;
        if ($referralRequired) {
            $r = $conn->prepare("
                INSERT INTO referrals (student_id, visit_id, clinic_staff_id, referred_to, reason, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $r->bind_param("iiisss", $studentId, $visitId, $currentUserId, $referralDest, $referralReason, $visitDateOnly);
            $r->execute();
            $referralId = $conn->insert_id;

            notify_upsert($conn, 'Clinic', 'referral_created', 'info',
                'Referral Created: ' . notify_student_label($conn, $studentId),
                "Referred to {$referralDest} — {$referralReason}",
                'referrals', $referralId, "referral_created:{$referralId}", $visitDateOnly);
            // NOTE: Referrals to Dentist/Hospital/Psychologist/Social
            // Worker/Other are a Clinic-only concern — Guidance Staff is
            // not responsible for following these up, so no Guidance
            // notification is raised here. Only create one in the future
            // if a referral's actual destination/action is Guidance-related.
        }

        $conn->commit();

        // Quantity just changed for every dispensed line above — reconcile
        // low_stock/out_of_stock immediately (same pattern as
        // api/inventory.php) so a medicine that just hit 0 shows Out of
        // Stock right away instead of waiting for the next notification
        // poll to self-heal it.
        if ($dispensedSummary) notify_reconcile_medicines($conn);

        // 4. Audit Trail (outside the transaction — never blocks the real action).
        $desc = "Visit #$visitId for student #$studentId" .
                ($dispensedSummary ? " | Dispensed: " . implode(", ", $dispensedSummary) : "") .
                ($referralRequired ? " | Referred to: $referralDest" : "");
        log_audit($conn, $currentUserId, 'Clinic', 'Record Clinic Visit', $visitId, $desc);

        // 5. Automatic Guardian SMS — Clinic Module only, sent AFTER the
        //    Clinic transaction is fully saved. Fires for Referral, Sent
        //    Home, or Emergency; each is individually gated by the
        //    Administrator's SMS Settings toggles and the student's own
        //    SMS Enabled flag (see includes/sms.php).
        $smsStatus = maybe_send_clinic_event_sms(
            $conn, $studentId, $visitId, $referralId,
            [
                'is_referral'  => $referralRequired,
                'is_sent_home' => strcasecmp($status, 'Sent Home') === 0,
                'is_emergency' => strcasecmp($status, 'Emergency') === 0,
            ],
            $symptoms,
            'Automatic'
        );
        if ($smsStatus !== null && $smsStatus !== 'Skipped') {
            log_audit($conn, $currentUserId, 'Clinic',
                $smsStatus === 'Failed' ? 'Automatic SMS Failed' : 'Automatic SMS Sent',
                $visitId, "Guardian SMS for visit #$visitId — status: $smsStatus");
        }

        echo json_encode(["success" => true, "message" => "Visit recorded", "id" => $visitId, "sms_status" => $smsStatus]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => csf_public_error($e)]);
    }
    exit();
}

// ── PUT (Update — visit-level fields only; dispensed medicines already
//    became Treatment History and are read-only, so they are not
//    editable here) ─────────────────────────────────────────────
if ($method === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);

    $id         = intval($data['id']         ?? 0);
    $studentId  = intval($data['student_id'] ?? 0);
    $symptoms   = csf_blank_if_na($data['symptoms'] ?? '');
    $findings   = csf_blank_if_na($data['findings'] ?? '');
    $treatment  = csf_blank_if_na($data['treatment'] ?? '');
    $assessment = trim($data['assessment']   ?? '');
    $status     = trim($data['status']       ?? '');
    $visitedAt  = trim($data['visited_at']   ?? '');
    $remarks    = csf_blank_if_na($data['remarks'] ?? '');

    $referralRequired = !empty($data['referral_required']);
    $referralDest     = csf_blank_if_na($data['referral_destination'] ?? '');
    $referralReason   = csf_blank_if_na($data['referral_reason'] ?? '');

    // `assessment` is an ENUM and rejects '' — treat "not selected" as NULL.
    $assessment = $assessment === '' ? null : $assessment;

    if (!$id || !$studentId || !$status || !$visitedAt) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }
    if (!in_array($assessment, ['Mild', 'Moderate', 'Severe'], true)) {
        echo json_encode(["success" => false, "message" => "Assessment is required."]);
        exit();
    }
    if ($referralRequired && (!$referralDest || !$referralReason)) {
        echo json_encode(["success" => false, "message" => "Referral destination and reason are required when Referral Required is Yes."]);
        exit();
    }

    // Snapshot the visit's PRIOR state before applying the edit — used
    // below to only fire automatic SMS for a NEWLY triggered event
    // (e.g. status just changed to "Sent Home"), not on every routine
    // re-save of an already-referred/already-sent-home visit.
    $priorStmt = $conn->prepare("SELECT status, referral_required FROM clinic_visits WHERE id = ?");
    $priorStmt->bind_param("i", $id);
    $priorStmt->execute();
    $priorVisit = $priorStmt->get_result()->fetch_assoc();

    $conn->begin_transaction();
    try {
        // Columns: student_id(i) symptoms(s) findings(s) treatment(s) assessment(s) status(s)
        //          remarks(s) referral_required(i) referral_destination(s) referral_reason(s)
        //          visited_at(s) WHERE id(i) => type string "isssssisssi"
        $referralRequiredInt = $referralRequired ? 1 : 0;
        $symptomsDb = csf_na($symptoms); $findingsDb = csf_na($findings);
        $treatmentDb = csf_na($treatment); $remarksDb = csf_na($remarks);
        $refDestDb = csf_na($referralDest); $refReasonDb = csf_na($referralReason);
        $stmt = $conn->prepare("
            UPDATE clinic_visits
            SET student_id=?, symptoms=?, findings=?, treatment=?, assessment=?, status=?,
                remarks=?, referral_required=?, referral_destination=?, referral_reason=?, visited_at=?
            WHERE id=?
        ");
        $stmt->bind_param("issssssisssi",
            $studentId, $symptomsDb, $findingsDb, $treatmentDb, $assessment, $status,
            $remarksDb, $referralRequiredInt, $refDestDb, $refReasonDb, $visitedAt, $id
        );
        $stmt->execute();

        // Keep Referral History in sync with the visit's own Referral
        // Required flag — this is still system-driven (part of saving the
        // visit), not a manual create/edit/delete of a referral by the user.
        $existingReferral = fetch_referral($conn, $id);
        $visitDateOnly = substr($visitedAt, 0, 10);

        $referralId = $existingReferral['id'] ?? null;
        if ($referralRequired) {
            if ($existingReferral) {
                $u = $conn->prepare("
                    UPDATE referrals SET referred_to=?, reason=?, is_archived=0 WHERE id=?
                ");
                $u->bind_param("ssi", $referralDest, $referralReason, $existingReferral['id']);
                $u->execute();
            } else {
                $r = $conn->prepare("
                    INSERT INTO referrals (student_id, visit_id, clinic_staff_id, referred_to, reason, created_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $r->bind_param("iiisss", $studentId, $id, $currentUserId, $referralDest, $referralReason, $visitDateOnly);
                $r->execute();
                $referralId = $conn->insert_id;

                notify_upsert($conn, 'Clinic', 'referral_created', 'info',
                    'Referral Created: ' . notify_student_label($conn, $studentId),
                    "Referred to {$referralDest} — {$referralReason}",
                    'referrals', $referralId, "referral_created:{$referralId}", $visitDateOnly);
                // NOTE: no Guidance notification — see the matching NOTE in
                // the POST handler above.
            }
        } elseif ($existingReferral && !$existingReferral['is_archived']) {
            // Referral no longer required — archive (never delete) the
            // auto-generated history row.
            $u = $conn->prepare("UPDATE referrals SET is_archived=1 WHERE id=?");
            $u->bind_param("i", $existingReferral['id']);
            $u->execute();
            notify_resolve_one($conn, 'Clinic', 'referral_created', "referral_created:{$existingReferral['id']}");
        }

        $conn->commit();
        log_audit($conn, $currentUserId, 'Clinic', 'Update Clinic Visit', $id, "Updated visit #$id");

        // Automatic Guardian SMS on edit — only for an event that just
        // became true on THIS save (comparing against the snapshot taken
        // before the update), so re-saving an already-referred or
        // already-sent-home visit never re-sends a duplicate message.
        $wasReferral  = !empty($priorVisit['referral_required']);
        $wasSentHome  = $priorVisit && strcasecmp($priorVisit['status'], 'Sent Home') === 0;
        $wasEmergency = $priorVisit && strcasecmp($priorVisit['status'], 'Emergency') === 0;

        $newlyReferral  = $referralRequired && !$wasReferral;
        $newlySentHome  = strcasecmp($status, 'Sent Home') === 0 && !$wasSentHome;
        $newlyEmergency = strcasecmp($status, 'Emergency') === 0 && !$wasEmergency;

        // Notification Center — Student Sent Home / Emergency Case, fired
        // only on the transition (same dedupe key as the POST/create path,
        // keyed on the visit id, so a visit created directly as Sent Home
        // and later re-saved unchanged never produces a duplicate row).
        if ($newlySentHome) {
            notify_upsert($conn, 'Clinic', 'student_sent_home', 'warning',
                'Student Sent Home: ' . notify_student_label($conn, $studentId),
                "Sent home from visit #{$id}" . ($symptoms ? " — {$symptoms}" : ""),
                'clinic-visits', $id, "sent_home:{$id}", substr($visitedAt, 0, 10));
        }
        if ($newlyEmergency) {
            notify_upsert($conn, 'Clinic', 'emergency_case', 'critical',
                'Emergency Case: ' . notify_student_label($conn, $studentId),
                "Flagged Emergency on visit #{$id}" . ($symptoms ? " — {$symptoms}" : ""),
                'clinic-visits', $id, "emergency:{$id}", substr($visitedAt, 0, 10));
        }

        $smsStatus = null;
        if ($newlyReferral || $newlySentHome || $newlyEmergency) {
            $smsStatus = maybe_send_clinic_event_sms(
                $conn, $studentId, $id, $referralId,
                [
                    'is_referral'  => $newlyReferral,
                    'is_sent_home' => $newlySentHome,
                    'is_emergency' => $newlyEmergency,
                ],
                $symptoms,
                'Automatic'
            );
            if ($smsStatus !== null && $smsStatus !== 'Skipped') {
                log_audit($conn, $currentUserId, 'Clinic',
                    $smsStatus === 'Failed' ? 'Automatic SMS Failed' : 'Automatic SMS Sent',
                    $id, "Guardian SMS for visit #$id — status: $smsStatus");
            }
        }

        echo json_encode(["success" => true, "message" => "Updated", "sms_status" => $smsStatus]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Update failed: " . csf_public_error($e)]);
    }
    exit();
}

// ── DELETE (Archive — cascades to Treatment History rows [restoring
//    stock] and the Referral History row, keeping everything consistent;
//    or Permanent Delete — see below) ──────────────────────────────
if ($method === 'DELETE') {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    // ── Permanent Delete ─────────────────────────────────────────
    // Only reachable from Archived Records (?permanent=1) on a visit
    // that is already archived. Treatments/Referrals are not an
    // independent module — they only ever exist as a consequence of
    // this visit, so they are deleted together with it (same
    // relationship the existing Archive/Restore actions already treat
    // as a single unit). Stock was already restored when this visit was
    // archived, so no inventory adjustment is needed here.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, is_archived FROM clinic_visits WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $visit = $chk->get_result()->fetch_assoc();
        if (!$visit) { echo json_encode(["success" => false, "message" => "Clinic visit not found."]); exit(); }
        if (!$visit['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        $conn->begin_transaction();
        try {
            $conn->query("DELETE FROM treatments WHERE visit_id = " . intval($id));
            $conn->query("DELETE FROM referrals WHERE visit_id = " . intval($id));
            $del = $conn->prepare("DELETE FROM clinic_visits WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $conn->commit();
            log_audit($conn, $currentUserId, 'Clinic', 'Clinic Visit Permanently Deleted', $id, "Visit #$id and its Treatment/Referral rows removed");
            echo json_encode(["success" => true, "message" => "Permanently deleted"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Delete failed: " . csf_public_error($e)]);
        }
        exit();
    }

    $conn->begin_transaction();
    try {
        // Restore stock for every dispensed medicine tied to this visit, then archive those rows.
        $stmt = $conn->prepare("SELECT id, medicine_id, quantity_used FROM treatments WHERE visit_id = ? AND is_archived = 0");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($lines as $line) {
            if ($line['medicine_id'] && $line['quantity_used']) {
                $s = $conn->prepare("UPDATE medicines SET quantity = quantity + ? WHERE id = ?");
                $s->bind_param("ii", $line['quantity_used'], $line['medicine_id']);
                $s->execute();
            }
        }
        $conn->query("UPDATE treatments SET is_archived = 1 WHERE visit_id = " . intval($id));
        $conn->query("UPDATE referrals SET is_archived = 1 WHERE visit_id = " . intval($id));

        $stmt = $conn->prepare("UPDATE clinic_visits SET is_archived = 1 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $conn->commit();
        // Stock was just restored for every dispensed line above —
        // reconcile immediately so a medicine that's back above 0 (or
        // above the low-stock threshold) drops its Out of Stock/Low
        // Stock notification right away.
        if ($lines) notify_reconcile_medicines($conn);
        log_audit($conn, $currentUserId, 'Clinic', 'Archive Clinic Visit', $id, "Archived visit #$id (stock restored)");
        echo json_encode(["success" => true, "message" => "Archived"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Archive failed: " . csf_public_error($e)]);
    }
    exit();
}

// ── PATCH (Restore — re-deducts stock; aborts if stock is now
//    insufficient for any dispensed line) ──────────────────────
if ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT id, medicine_id, quantity_used FROM treatments WHERE visit_id = ? AND is_archived = 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $lines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($lines as $line) {
            if ($line['medicine_id'] && $line['quantity_used']) {
                $s = $conn->prepare("SELECT quantity, name, unit FROM medicines WHERE id = ? FOR UPDATE");
                $s->bind_param("i", $line['medicine_id']);
                $s->execute();
                $med = $s->get_result()->fetch_assoc();
                if (!$med || (int)$med['quantity'] < (int)$line['quantity_used']) {
                    $name = $med['name'] ?? 'medicine';
                    throw new Exception("Cannot restore — insufficient stock for \"$name\".");
                }
                $d = $conn->prepare("UPDATE medicines SET quantity = quantity - ? WHERE id = ?");
                $d->bind_param("ii", $line['quantity_used'], $line['medicine_id']);
                $d->execute();
            }
        }
        $conn->query("UPDATE treatments SET is_archived = 0 WHERE visit_id = " . intval($id));
        $conn->query("UPDATE referrals SET is_archived = 0 WHERE visit_id = " . intval($id));

        $stmt = $conn->prepare("UPDATE clinic_visits SET is_archived = 0 WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        $conn->commit();
        // Stock was just re-deducted for every dispensed line above —
        // reconcile immediately so a medicine that just dropped to/below
        // 0 or below the low-stock threshold shows the right notification
        // right away.
        if ($lines) notify_reconcile_medicines($conn);
        log_audit($conn, $currentUserId, 'Clinic', 'Restore Clinic Visit', $id, "Restored visit #$id (stock re-deducted)");
        echo json_encode(["success" => true, "message" => "Restored"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => csf_public_error($e)]);
    }
    exit();
}
