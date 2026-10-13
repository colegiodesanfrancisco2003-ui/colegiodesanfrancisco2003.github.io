<?php
require_once "../config/session_init.php";
session_start();
header("Content-Type: application/json");
include "../config/database.php";
include "../config/audit.php";

// All student endpoints require authentication.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["success" => false, "message" => "Unauthorized"]);
    exit();
}

require_once "../config/csrf.php";
csrf_verify();
require_once "../config/session_guard.php";
enforce_session_timeout();
require_once "../includes/validate.php";


$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────
if ($method === 'GET') {

    // ── INTEGRATED STUDENT PROFILE ──────────────────────────────
    // One combined view of a student across modules. Visibility is
    // role-based: Administrator sees everything; Clinic Staff sees
    // only clinic-side records; Guidance Staff sees only guidance-side
    // records — never the other module's confidential data.
    if (isset($_GET['profile'])) {
        $id   = intval($_GET['profile']);
        $role = $_SESSION['role'] ?? '';

        $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();

        if (!$student) {
            echo json_encode(["success" => false, "message" => "Student not found"]);
            exit();
        }

        $canSeeClinic   = in_array($role, ['Administrator', 'Clinic Staff'], true);
        $canSeeGuidance = in_array($role, ['Administrator', 'Guidance Staff'], true);

        // The Administrator accesses Clinic and Guidance data through two
        // separate, non-merged modules that mirror Clinic Staff / Guidance
        // Staff exactly (see admin.php nav: "Clinic Module" vs "Guidance
        // Module"), plus a Student Management master list that shows
        // neither. The `module` param scopes an Administrator request to
        // whichever module page it was opened from so this single endpoint
        // never returns a combined Clinic+Guidance profile for one screen.
        // Clinic Staff / Guidance Staff are unaffected — their role alone
        // already restricts them to their own side.
        if ($role === 'Administrator') {
            $module = $_GET['module'] ?? '';
            if ($module === 'clinic') {
                $canSeeGuidance = false;
            } elseif ($module === 'guidance') {
                $canSeeClinic = false;
            } else {
                $canSeeClinic   = false;
                $canSeeGuidance = false;
            }
        }

        $profile = [

            "success" => true,
            "student" => $student,
            "timeline" => [],
            "alerts" => [],
        ];

        if ($canSeeClinic) {
            $stmt = $conn->prepare("
                SELECT id, symptoms, findings, treatment, assessment, status, visited_at
                FROM clinic_visits WHERE student_id = ? AND is_archived = 0
                ORDER BY visited_at DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $visits = [];
            while ($row = $res->fetch_assoc()) $visits[] = $row;

            $stmt = $conn->prepare("
                SELECT t.id, t.medicine, t.dosage, t.quantity_used, t.given_at, u.full_name AS given_by_name
                FROM treatments t LEFT JOIN users u ON u.id = t.given_by
                WHERE t.student_id = ? AND t.is_archived = 0
                ORDER BY t.given_at DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $treatments = [];
            while ($row = $res->fetch_assoc()) $treatments[] = $row;

            $stmt = $conn->prepare("
                SELECT id, referred_to, reason, created_at
                FROM referrals WHERE student_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $referrals = [];
            while ($row = $res->fetch_assoc()) $referrals[] = $row;

            // Most frequent non-empty symptom across this student's visit
            // history — the aggregate the Students table used to show as
            // "Common Symptom" (now surfaced on the Profile instead).
            $symptomCounts = [];
            foreach ($visits as $v) {
                $sym = trim($v['symptoms'] ?? '');
                if ($sym === '') continue;
                $symptomCounts[$sym] = ($symptomCounts[$sym] ?? 0) + 1;
            }
            $commonSymptom = null;
            if ($symptomCounts) {
                arsort($symptomCounts);
                $commonSymptom = array_key_first($symptomCounts);
            }

            $profile['clinic'] = [
                'visits'        => $visits,
                'treatments'    => $treatments,
                'referrals'     => $referrals,
                'summary' => [
                    'visit_count'      => count($visits),
                    'medicines_count'  => count($treatments),
                    'referral_count'   => count($referrals),
                    'last_visit_date'  => $visits[0]['visited_at'] ?? null,
                    'common_symptom'   => $commonSymptom,
                ],
            ];

            foreach ($visits as $v) {
                $profile['timeline'][] = ['date' => $v['visited_at'], 'module' => 'Clinic', 'type' => 'Clinic Visit', 'summary' => ($v['symptoms'] ?: '(no symptoms noted)')];
            }
            foreach ($treatments as $t) {
                $profile['timeline'][] = ['date' => $t['given_at'], 'module' => 'Clinic', 'type' => 'Treatment', 'summary' => $t['medicine'] . ($t['dosage'] ? " ({$t['dosage']})" : '')];
            }
            foreach ($referrals as $r) {
                $profile['timeline'][] = ['date' => $r['created_at'], 'module' => 'Clinic', 'type' => 'Referral', 'summary' => 'Referred to ' . $r['referred_to']];
            }
        }

        if ($canSeeGuidance) {
            $stmt = $conn->prepare("
                SELECT id, behavior, status, recorded_at
                FROM behavioral_records WHERE student_id = ? AND is_archived = 0
                ORDER BY recorded_at DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $behavioral = [];
            while ($row = $res->fetch_assoc()) $behavioral[] = $row;

            $stmt = $conn->prepare("
                SELECT sor.id, oc.category_name AS type, oc.severity_level, sor.description,
                       sor.count, sor.status, sor.incident_date AS recorded_at
                FROM student_offense_records sor
                JOIN offense_categories oc ON oc.id = sor.category_id
                WHERE sor.student_id = ? AND sor.is_archived = 0
                ORDER BY sor.incident_date DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $offenses = [];
            while ($row = $res->fetch_assoc()) $offenses[] = $row;

            $stmt = $conn->prepare("
                SELECT cs.id, cs.status, cs.concern, cs.notes, cs.outcome, cs.recommendations,
                       cs.session_date,
                       u.full_name AS counselor_name
                FROM counseling_sessions cs LEFT JOIN users u ON u.id = cs.counselor_id
                WHERE cs.student_id = ? AND cs.is_archived = 0
                ORDER BY cs.session_date DESC
            ");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $sessions = [];
            while ($row = $res->fetch_assoc()) $sessions[] = $row;

            $lastSession = null;
            foreach ($sessions as $s) {
                if ($s['status'] === 'Closed') { $lastSession = $s['session_date']; break; }
            }

            $closedCaseCount = 0;
            foreach ($sessions as $s) if ($s['status'] === 'Closed') $closedCaseCount++;

            // Times Reopened — how many times this student's counseling
            // sessions were reopened, read from the existing Audit Trail
            // ('Session Reopened' rows written by api/counseling.php).
            // Read-only; does not touch Counseling Sessions / Cases Closed.
            $reopenCount = 0;
            $sessionIds  = array_map('intval', array_column($sessions, 'id'));
            if ($sessionIds) {
                $ph   = implode(',', array_fill(0, count($sessionIds), '?'));
                $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM audit_trail WHERE module = 'Guidance' AND action = 'Session Reopened' AND record_id IN ($ph)");
                if ($stmt) {
                    $stmt->bind_param(str_repeat('i', count($sessionIds)), ...$sessionIds);
                    $stmt->execute();
                    $reopenCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
                }
            }

            $profile['guidance'] = [
                'behavioral'    => $behavioral,
                'offenses'      => $offenses,
                'sessions'      => $sessions,
                'summary' => [
                    'behavioral_count'   => count($behavioral),
                    'offense_count'      => count($offenses),
                    'session_count'      => count($sessions),
                    'closed_case_count'  => $closedCaseCount,
                    'reopened_count'     => $reopenCount,
                    'last_session_date'  => $lastSession,
                ],
            ];

            foreach ($behavioral as $b) {
                $profile['timeline'][] = ['date' => $b['recorded_at'], 'module' => 'Guidance', 'type' => 'Behavioral Record', 'summary' => $b['behavior'] . ' (' . ($b['status'] ?? 'Open') . ')'];
            }
            foreach ($offenses as $o) {
                $profile['timeline'][] = ['date' => $o['recorded_at'], 'module' => 'Guidance', 'type' => 'Offense', 'summary' => $o['type'] . ($o['count'] > 1 ? " (x{$o['count']})" : '')];
            }
            foreach ($sessions as $s) {
                $profile['timeline'][] = ['date' => $s['session_date'], 'module' => 'Guidance', 'type' => 'Counseling Session', 'summary' => $s['status']];
            }

            // Step 4 — Active/Pending Items. Pure read of existing Step 3
            // fields (behavioral_records.status, offenses.status) — no new
            // status logic invented here.
            foreach ($behavioral as $b) {
                if (($b['status'] ?? 'Open') === 'Open') {
                    $profile['alerts'][] = [
                        'module' => 'Guidance', 'type' => 'Open Behavioral Record',
                        'summary' => $b['behavior'],
                        'date' => $b['recorded_at'], 'severity' => 'warning',
                    ];
                }
            }
            foreach ($offenses as $o) {
                if (($o['status'] ?? '') !== 'Resolved') {
                    $profile['alerts'][] = [
                        'module' => 'Guidance', 'type' => 'Unresolved Offense',
                        'summary' => $o['type'] . ' — ' . ($o['status'] ?? 'Pending'),
                        'date' => $o['recorded_at'], 'severity' => $o['status'] === 'Notice Required' ? 'critical' : 'warning',
                    ];
                }
            }
        }

        // Merge and sort the combined timeline newest-first (each date
        // string sorts correctly whether it's a date or datetime).
        usort($profile['timeline'], fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

        // Alerts: most urgent first (critical > warning > info).
        $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($profile['alerts'], fn($a, $b) =>
            ($severityRank[$a['severity']] ?? 3) <=> ($severityRank[$b['severity']] ?? 3));

        echo json_encode($profile);
        exit();
    }

    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $stmt = $conn->prepare("
            SELECT s.*,
                COUNT(v.id) AS total_visits,
                SUM(CASE
                    WHEN MONTH(v.visited_at) = MONTH(NOW())
                    AND YEAR(v.visited_at) = YEAR(NOW())
                    THEN 1 ELSE 0
                END) AS visits_this_month
            FROM students s
            LEFT JOIN clinic_visits v ON v.student_id = s.id AND v.is_archived = 0
            WHERE s.id = ?
            GROUP BY s.id
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        echo json_encode($row ?: []);
        exit();
    }

    // Use prepared statement with bound param to avoid SQL injection
    // (the previous version interpolated $archivedVal directly into the query).
    $archivedVal = (isset($_GET['archived']) && $_GET['archived'] == '1') ? 1 : 0;

    $stmt = $conn->prepare("
        SELECT
            s.*,
            COUNT(v.id) AS total_visits,
            SUM(CASE
                WHEN MONTH(v.visited_at) = MONTH(NOW())
                AND YEAR(v.visited_at) = YEAR(NOW())
                THEN 1 ELSE 0
            END) AS visits_this_month
        FROM students s
        LEFT JOIN clinic_visits v ON v.student_id = s.id AND v.is_archived = 0
        WHERE s.is_archived = ?
        GROUP BY s.id
        ORDER BY s.id DESC
        LIMIT 2000
    ");
    $stmt->bind_param("i", $archivedVal);
    $stmt->execute();
    $students = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $students[] = $row;
    echo json_encode($students);
    exit();
}

// ── Shared Student Records permission matrix ────────────────────
// Administrator, Clinic Staff, and Guidance Staff all read/write the
// SAME single `students` table through this one endpoint — there is
// no separate Clinic-only or Guidance-only student implementation.
// Create + edit-basic-info: Administrator, Clinic Staff, Guidance Staff.
// Archive/Restore (DELETE/PATCH below): Administrator only.
$STUDENT_WRITE_ROLES = ['Administrator', 'Clinic Staff', 'Guidance Staff'];

// Builds full_name / grade_section from the granular fields so every
// other module (Clinic Visit Logs, Treatments, Counseling Sessions,
// Behavioral Records, Offenses, Referrals, Reports,
// dashboards) keeps working unchanged — they all still read
// full_name / grade_section exactly as before.
function buildLegacyNameAndGrade(string $first, string $middle, string $last, string $suffix, string $gradeLevel, string $section): array {
    $namePart = trim($last . ', ' . trim($first . ' ' . $middle));
    if ($suffix !== '') $namePart .= ' ' . $suffix;
    $fullName = trim($namePart, ', ');

    $gradeSection = $section !== '' ? trim($gradeLevel . ' - ' . $section) : $gradeLevel;

    return [$fullName, $gradeSection];
}

// ── POST (Create) ─────────────────────────────────────────────
if ($method === 'POST') {
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $STUDENT_WRITE_ROLES, true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You don't have permission to add students."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);

    // ── Standardized Add Student fields (shared by all three roles) ──
    $studentCode = trim($data['student_code'] ?? '');
    $firstName   = trim($data['first_name']   ?? '');
    $middleName  = csf_blank_if_na($data['middle_name'] ?? '');
    $lastName    = trim($data['last_name']    ?? '');
    $suffix      = csf_blank_if_na($data['suffix'] ?? '');
    $sex         = trim($data['sex']          ?? '');
    $birthDate   = trim($data['birth_date']   ?? '');
    $gradeLevel  = trim($data['grade_level']  ?? '');
    $section     = trim($data['section']      ?? '');
    $address     = trim($data['address']      ?? '');
    $guardianName    = csf_blank_if_na($data['guardian_name'] ?? '');
    $guardianContact = csf_blank_if_na($data['guardian_contact'] ?? '');
    $emergencyName   = csf_blank_if_na($data['emergency_contact_name'] ?? '');
    $emergencyNumber = csf_blank_if_na($data['emergency_contact_number'] ?? '');

    if (!$studentCode || !$firstName || !$lastName || !$sex || !$birthDate || !$gradeLevel || !$section || !$address) {
        echo json_encode(["success" => false, "message" => "Student ID, First Name, Last Name, Sex, Birth Date, Grade Level, Section, and Address are required."]);
        exit();
    }
    if (!csf_date_not_future($birthDate)) {
        csf_fail("Birth Date cannot be in the future.");
    }
    if ($guardianContact !== '' && !csf_valid_ph_mobile($guardianContact)) {
        csf_fail("Guardian Contact Number must be a valid Philippine mobile number (e.g. 09171234567).");
    }
    if ($emergencyNumber !== '' && !csf_valid_ph_mobile($emergencyNumber)) {
        csf_fail("Emergency Contact Number must be a valid Philippine mobile number (e.g. 09171234567).");
    }

    // Check for duplicate student code
    $check = $conn->prepare("SELECT id FROM students WHERE student_code = ?");
    $check->bind_param("s", $studentCode);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "Student ID already exists!"]);
        exit();
    }

    [$fullName, $gradeSection] = buildLegacyNameAndGrade($firstName, $middleName, $lastName, $suffix, $gradeLevel, $section);

    // Optional fields left blank are saved as "N/A" (full_name above was
    // already built from the real, possibly-blank values).
    $middleName      = csf_na($middleName);
    $suffix          = csf_na($suffix);
    $guardianName    = csf_na($guardianName);
    $guardianContact = csf_na($guardianContact);
    $emergencyName   = csf_na($emergencyName);
    $emergencyNumber = csf_na($emergencyNumber);

    // Source reflects who actually created the record — derived from
    // the logged-in session's role, never trusted from client input.
    $source = $role === 'Guidance Staff' ? 'Guidance' : ($role === 'Administrator' ? 'Administrator' : 'Clinic');

    // Note: this INSERT intentionally sticks to the standardized
    // basic-info fields only. There is currently no separate clinical/
    // health-fields feature — those columns exist on the students table
    // but nothing in the app reads or writes them.
    $stmt = $conn->prepare("
        INSERT INTO students
            (student_code, full_name, first_name, middle_name, last_name, suffix, sex, birth_date,
             grade_section, grade_level, section, address,
             guardian_name, guardian_contact, emergency_contact_name, emergency_contact_number, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sssssssssssssssss",
        $studentCode, $fullName, $firstName, $middleName, $lastName, $suffix, $sex, $birthDate,
        $gradeSection, $gradeLevel, $section, $address,
        $guardianName, $guardianContact, $emergencyName, $emergencyNumber, $source
    );
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Students', 'Create Student', $conn->insert_id, $fullName);
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Student added"]
        : ["success" => false, "message" => "Insert failed. Please try again or contact the system administrator."]);
    exit();
}

// ── PUT (Update) ──────────────────────────────────────────────
// Administrator, Clinic Staff, and Guidance Staff may all edit a
// student's basic information here. This endpoint only ever touches the
// standardized basic-info fields, so a Clinic/Guidance Staff edit can
// never clobber Student Health Records data (a separate feature).
if ($method === 'PUT') {
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $STUDENT_WRITE_ROLES, true)) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You don't have permission to edit students."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);

    $id          = intval($data['id'] ?? 0);
    $studentCode = trim($data['student_code'] ?? '');
    $firstName   = trim($data['first_name']   ?? '');
    $middleName  = csf_blank_if_na($data['middle_name'] ?? '');
    $lastName    = trim($data['last_name']    ?? '');
    $suffix      = csf_blank_if_na($data['suffix'] ?? '');
    $sex         = trim($data['sex']          ?? '');
    $birthDate   = trim($data['birth_date']   ?? '');
    $gradeLevel  = trim($data['grade_level']  ?? '');
    $section     = trim($data['section']      ?? '');
    $address     = trim($data['address']      ?? '');
    $guardianName    = csf_blank_if_na($data['guardian_name'] ?? '');
    $guardianContact = csf_blank_if_na($data['guardian_contact'] ?? '');
    $emergencyName   = csf_blank_if_na($data['emergency_contact_name'] ?? '');
    $emergencyNumber = csf_blank_if_na($data['emergency_contact_number'] ?? '');

    if (!$id || !$studentCode || !$firstName || !$lastName || !$sex || !$birthDate || !$gradeLevel || !$section || !$address) {
        echo json_encode(["success" => false, "message" => "Missing required fields."]);
        exit();
    }
    if (!csf_date_not_future($birthDate)) {
        csf_fail("Birth Date cannot be in the future.");
    }
    if ($guardianContact !== '' && !csf_valid_ph_mobile($guardianContact)) {
        csf_fail("Guardian Contact Number must be a valid Philippine mobile number (e.g. 09171234567).");
    }
    if ($emergencyNumber !== '' && !csf_valid_ph_mobile($emergencyNumber)) {
        csf_fail("Emergency Contact Number must be a valid Philippine mobile number (e.g. 09171234567).");
    }

    [$fullName, $gradeSection] = buildLegacyNameAndGrade($firstName, $middleName, $lastName, $suffix, $gradeLevel, $section);

    // Optional fields left blank are saved as "N/A" (full_name above was
    // already built from the real, possibly-blank values).
    $middleName      = csf_na($middleName);
    $suffix          = csf_na($suffix);
    $guardianName    = csf_na($guardianName);
    $guardianContact = csf_na($guardianContact);
    $emergencyName   = csf_na($emergencyName);
    $emergencyNumber = csf_na($emergencyNumber);

    // Editing/accepting a legacy Pending record clears the badge by
    // setting source back to the editor's own role.
    $source = $role === 'Guidance Staff' ? 'Guidance' : ($role === 'Administrator' ? 'Administrator' : 'Clinic');

    $stmt = $conn->prepare("
        UPDATE students
        SET student_code=?, full_name=?, first_name=?, middle_name=?, last_name=?, suffix=?, sex=?, birth_date=?,
            grade_section=?, grade_level=?, section=?, address=?,
            guardian_name=?, guardian_contact=?, emergency_contact_name=?, emergency_contact_number=?,
            source=?
        WHERE id=?
    ");
    $stmt->bind_param("sssssssssssssssssi",
        $studentCode, $fullName, $firstName, $middleName, $lastName, $suffix, $sex, $birthDate,
        $gradeSection, $gradeLevel, $section, $address,
        $guardianName, $guardianContact, $emergencyName, $emergencyNumber,
        $source, $id
    );
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Students', 'Edit Student', $id, $fullName);
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Updated"]
        : ["success" => false, "message" => "Update failed. Please try again or contact the system administrator."]);
    exit();
}

// ── DELETE (Archive / Soft-delete, or Permanent Delete) ────────
// Only Administrator may archive/restore/permanently delete a student
// record — Clinic Staff and Guidance Staff can create/search/view/edit,
// but never archive, restore, or delete, per the shared Student Records
// permission matrix.
if ($method === 'DELETE') {
    if (($_SESSION['role'] ?? '') !== 'Administrator') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only an Administrator can archive student records."]);
        exit();
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    // ── Permanent Delete ─────────────────────────────────────────
    // Only ever reachable from Archived Records (?permanent=1) and only
    // for a record that is ALREADY archived. Irreversible, so it also
    // requires the typed "DELETE" confirmation from the UI.
    if (isset($_GET['permanent']) && $_GET['permanent'] == '1') {
        $body    = json_decode(file_get_contents("php://input"), true);
        $confirm = trim($body['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            echo json_encode(["success" => false, "message" => "Type DELETE to confirm permanent deletion."]);
            exit();
        }

        $chk = $conn->prepare("SELECT id, full_name, is_archived FROM students WHERE id = ?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $student = $chk->get_result()->fetch_assoc();
        if (!$student) { echo json_encode(["success" => false, "message" => "Student not found."]); exit(); }
        if (!$student['is_archived']) {
            echo json_encode(["success" => false, "message" => "Only an archived record can be permanently deleted."]);
            exit();
        }

        // A Student is the parent record for a large amount of Clinic and
        // Guidance history, none of which cascade-deletes at the database
        // level (every FK back to `students` is RESTRICT, not CASCADE — see
        // clinic_visits_ibfk_1, treatments_ibfk_1, referrals_ibfk_1,
        // behavioral_records_ibfk_1, fk_sor_student, counseling_sessions_
        // ibfk_1, sms_logs_ibfk_1). So the child records owned exclusively
        // by this student are deleted manually here, in dependency order,
        // inside one transaction — child rows first, student row last —
        // exactly like the existing per-module Permanent Delete branches
        // (see api/clinic.php's visit+treatments+referrals cascade) already
        // do for their own single-parent case. Nothing belonging to any
        // OTHER student is touched, since every DELETE below is scoped to
        // this $id.
        $conn->begin_transaction();
        try {
            // Collect this student's own record IDs first — needed to
            // safely defuse two relationships that are NOT simple
            // "belongs to this student" FKs before their target rows are
            // removed:
            //  - counseling_sessions.parent_session_id can point at
            //    another counseling session (e.g. a reopened-case chain) —
            //    including, in theory, one belonging to a different
            //    student. It carries an ON DELETE SET NULL FK, but it's
            //    cleared explicitly here too, before the batch DELETE below.
            //  - counseling_sessions.offense_id -> student_offense_records is a
            //    hard FK. If any session anywhere still references an
            //    offense this student owns, deleting that offense record
            //    would fail, so those references are cleared first for
            //    any session that isn't itself being deleted along with
            //    this student.
            $sessionIds = [];
            $r = $conn->prepare("SELECT id FROM counseling_sessions WHERE student_id = ?");
            $r->bind_param("i", $id);
            $r->execute();
            $res = $r->get_result();
            while ($row = $res->fetch_assoc()) $sessionIds[] = (int)$row['id'];

            $offenseIds = [];
            $r = $conn->prepare("SELECT id FROM student_offense_records WHERE student_id = ?");
            $r->bind_param("i", $id);
            $r->execute();
            $res = $r->get_result();
            while ($row = $res->fetch_assoc()) $offenseIds[] = (int)$row['id'];

            $visitIds = [];
            $r = $conn->prepare("SELECT id FROM clinic_visits WHERE student_id = ?");
            $r->bind_param("i", $id);
            $r->execute();
            $res = $r->get_result();
            while ($row = $res->fetch_assoc()) $visitIds[] = (int)$row['id'];

            if ($sessionIds) {
                $ph = implode(',', array_fill(0, count($sessionIds), '?'));
                $types = str_repeat('i', count($sessionIds));
                $stmt = $conn->prepare("UPDATE counseling_sessions SET parent_session_id = NULL WHERE parent_session_id IN ($ph)");
                $stmt->bind_param($types, ...$sessionIds);
                $stmt->execute();
            }
            if ($offenseIds) {
                $ph = implode(',', array_fill(0, count($offenseIds), '?'));
                $types = 'i' . str_repeat('i', count($offenseIds));
                $stmt = $conn->prepare("UPDATE counseling_sessions SET offense_id = NULL WHERE student_id != ? AND offense_id IN ($ph)");
                $stmt->bind_param($types, $id, ...$offenseIds);
                $stmt->execute();
            }

            // SMS Logs — leaf table, no incoming references.
            $conn->query("DELETE FROM sms_logs WHERE student_id = " . intval($id));

            // Treatments / Referrals — leaf tables under Clinic Visits.
            // Scoped by student_id directly (every row already carries
            // it), with the visit_id list as a belt-and-suspenders catch
            // for the (invalid) case of a row missing its own student_id.
            $visitClause = $visitIds ? (" OR visit_id IN (" . implode(',', array_map('intval', $visitIds)) . ")") : "";
            $conn->query("DELETE FROM treatments WHERE student_id = " . intval($id) . $visitClause);
            $conn->query("DELETE FROM referrals  WHERE student_id = " . intval($id) . $visitClause);

            // Counseling Sessions — safe now: their own parent_session_id/
            // offense_id concerns were resolved above, and
            // behavioral_record_id auto-clears via the existing
            // ON DELETE SET NULL when Behavioral Records are removed next.
            $conn->query("DELETE FROM counseling_sessions WHERE student_id = " . intval($id));

            // Behavioral Records / Offense Records — safe now: this student's
            // own Counseling Sessions are gone, and any reference from
            // another student's session was cleared above.
            $conn->query("DELETE FROM behavioral_records WHERE student_id = " . intval($id));
            $conn->query("DELETE FROM student_offense_records WHERE student_id = " . intval($id));

            // Clinic Visits — safe now: their Treatments/Referrals are gone.
            $conn->query("DELETE FROM clinic_visits WHERE student_id = " . intval($id));

            // Audit Trail entry recorded before the student row itself is
            // removed, per the existing audit architecture. audit_trail.
            // record_id is a plain int with no foreign key back to any
            // table (it's a polymorphic per-module reference), so this
            // entry — and every earlier entry mentioning this student —
            // remains valid and readable after the row is gone.
            log_audit($conn, $_SESSION['user_id'], 'Students', 'Student Permanently Deleted', $id,
                $student['full_name'] . ' — permanently deleted with all exclusively-owned Clinic and Guidance records');

            // Student — last.
            $del = $conn->prepare("DELETE FROM students WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();

            $conn->commit();
            echo json_encode(["success" => true, "message" => "Permanently deleted"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success" => false, "message" => "Permanent delete failed — nothing was removed: " . csf_public_error($e)]);
        }
        exit();
    }

    $stmt = $conn->prepare("UPDATE students SET is_archived = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Students', 'Archive Student', $id, '');
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Archived"]
        : ["success" => false, "message" => "Archive failed. Please try again or contact the system administrator."]);
    exit();
}

// ── PATCH (Restore) ───────────────────────────────────────────
// Same rule — Administrator only.
if ($method === 'PATCH') {
    if (($_SESSION['role'] ?? '') !== 'Administrator') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only an Administrator can restore student records."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $id   = intval($data['id'] ?? 0);
    if (!$id) { echo json_encode(["success" => false, "message" => "No ID provided"]); exit(); }

    $stmt = $conn->prepare("UPDATE students SET is_archived = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    if ($ok) log_audit($conn, $_SESSION['user_id'], 'Students', 'Restore Student', $id, '');
    if (!$ok) error_log(__FILE__ . ':' . __LINE__ . ' - ' . $conn->error);
    echo json_encode($ok
        ? ["success" => true,  "message" => "Restored"]
        : ["success" => false, "message" => "Restore failed. Please try again or contact the system administrator."]);
    exit();
}