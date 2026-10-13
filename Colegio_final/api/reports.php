<?php
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
require_once "../includes/notify.php";

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

// ── MODULE + ROLE GATE ──────────────────────────────────────────
// Clinic Staff must never see Guidance data and vice versa; only
// Administrator sees both. Defaults to "clinic" for backward
// compatibility with any existing caller that doesn't pass ?module=.
$module = $_GET['module'] ?? 'clinic';
// Final audit: whitelist the module. Previously any value other than the exact strings
// 'clinic'/'guidance' skipped BOTH role gates below and fell into the Clinic branch, letting
// Guidance Staff read Clinic reports (e.g. ?module=foo). Unknown values now mean 'clinic'.
if (!is_string($module) || !in_array($module, ['clinic', 'guidance'], true)) $module = 'clinic';
$role   = $_SESSION['role'] ?? '';

if ($module === 'guidance' && !in_array($role, ['Administrator', 'Guidance Staff'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}
if ($module === 'clinic' && !in_array($role, ['Administrator', 'Clinic Staff'], true)) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden"]);
    exit();
}

// ── DATE RANGE ──
// Defaults to "this month" when no range is supplied by the client.
// Client sends ?from=YYYY-MM-DD&to=YYYY-MM-DD
$from = $_GET['from'] ?? date('Y-m-01');               // first day of current month
$to   = $_GET['to']   ?? date('Y-m-d');                 // today

// Normalize: $to should cover the entire day, not just midnight.
$toInclusive   = $to . " 23:59:59";
$fromInclusive = $from . " 00:00:00";
// Guidance tables store dates as DATE (not DATETIME), so use plain
// $from/$to for those queries instead of the *Inclusive datetime strings.

if ($module === 'guidance') {
    // ════════════════════════════════════════════════════════════
    // GUIDANCE REPORTS: Behavioral Analytics, Case History volume,
    // and Counselor Analytics.
    // ════════════════════════════════════════════════════════════

    // ── 1. TOTAL COUNSELING SESSIONS (within range) ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM counseling_sessions
        WHERE session_date BETWEEN ? AND ? AND is_archived = 0
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $totalSessions = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // ── 2. TOTAL BEHAVIORAL RECORDS (within range) ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM behavioral_records
        WHERE recorded_at BETWEEN ? AND ? AND is_archived = 0
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $totalBehavioral = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // ── 3. TOTAL OFFENSES (within range) ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM student_offense_records
        WHERE incident_date BETWEEN ? AND ? AND is_archived = 0
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $totalOffenses = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // ── 4. MOST COMMON OFFENSE CATEGORY (within range) ──
    $stmt = $conn->prepare("
        SELECT oc.category_name AS type, COUNT(*) AS occurrences
        FROM student_offense_records sor JOIN offense_categories oc ON oc.id = sor.category_id
        WHERE sor.incident_date BETWEEN ? AND ? AND sor.is_archived = 0
        GROUP BY oc.category_name ORDER BY occurrences DESC LIMIT 1
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $topOffenseRow = $stmt->get_result()->fetch_assoc();
    $mostCommonOffense = $topOffenseRow ? $topOffenseRow['type'] : null;
    $mostCommonOffenseCount = $topOffenseRow ? (int)$topOffenseRow['occurrences'] : 0;

    // ── 5. TOP 5 BREAKDOWNS: offense categories ──
    $stmt = $conn->prepare("
        SELECT oc.category_name AS type, COUNT(*) AS occurrences
        FROM student_offense_records sor JOIN offense_categories oc ON oc.id = sor.category_id
        WHERE sor.incident_date BETWEEN ? AND ? AND sor.is_archived = 0
        GROUP BY oc.category_name ORDER BY occurrences DESC LIMIT 5
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $topOffenseTypes = [];
    while ($row = $res->fetch_assoc()) $topOffenseTypes[] = $row;

    // ── 6. COUNSELOR ANALYTICS: sessions handled per counselor ──
    $stmt = $conn->prepare("
        SELECT u.full_name AS counselor_name,
               COUNT(*) AS total_sessions,
               SUM(cs.status = 'Closed') AS closed_sessions
        FROM counseling_sessions cs
        LEFT JOIN users u ON u.id = cs.counselor_id
        WHERE cs.session_date BETWEEN ? AND ? AND cs.is_archived = 0
        GROUP BY cs.counselor_id
        ORDER BY total_sessions DESC
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $counselorAnalytics = [];
    while ($row = $res->fetch_assoc()) {
        $row['counselor_name']   = $row['counselor_name'] ?? 'Unassigned';
        $row['total_sessions']   = (int)$row['total_sessions'];
        $row['closed_sessions']  = (int)$row['closed_sessions'];
        $counselorAnalytics[] = $row;
    }

    // ── 7b. CASES CLOSED (within range) — Case Closure workflow step ──
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM counseling_sessions
        WHERE status = 'Closed' AND session_date BETWEEN ? AND ? AND is_archived = 0
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $totalClosedCases = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

    // ── 8. CASE HISTORY VOLUME: students with the most combined records ──
    // (behavioral + offenses + counseling), within range — surfaces
    // repeat cases for the Case History view.
    $stmt = $conn->prepare("
        SELECT s.id, s.full_name, s.student_code,
            (SELECT COUNT(*) FROM behavioral_records br WHERE br.student_id = s.id AND br.recorded_at BETWEEN ? AND ? AND br.is_archived = 0) +
            (SELECT COUNT(*) FROM student_offense_records o WHERE o.student_id = s.id AND o.incident_date BETWEEN ? AND ? AND o.is_archived = 0) +
            (SELECT COUNT(*) FROM counseling_sessions cs WHERE cs.student_id = s.id AND cs.session_date BETWEEN ? AND ? AND cs.is_archived = 0)
            AS case_count
        FROM students s
        WHERE s.is_archived = 0
        HAVING case_count > 0
        ORDER BY case_count DESC
        LIMIT 5
    ");
    $stmt->bind_param("ssssss", $from, $to, $from, $to, $from, $to);
    $stmt->execute();
    $res = $stmt->get_result();
    $topCases = [];
    while ($row = $res->fetch_assoc()) {
        $row['case_count'] = (int)$row['case_count'];
        $topCases[] = $row;
    }

    echo json_encode([
        "success" => true,
        "range"   => ["from" => $from, "to" => $to],
        "summary" => [
            "total_sessions"            => $totalSessions,
            "total_behavioral"          => $totalBehavioral,
            "total_offenses"            => $totalOffenses,
            "total_closed_cases"        => $totalClosedCases,
            "most_common_offense"       => $mostCommonOffense,
            "most_common_offense_count" => $mostCommonOffenseCount,
        ],
        "breakdown" => [
            "top_offense_types"   => $topOffenseTypes,
            "counselor_analytics" => $counselorAnalytics,
            "top_cases"           => $topCases,
        ],
    ]);

    if ($role === 'Administrator') {
        $rDate = date('Y-m-d');
        notify_upsert($conn, 'System', 'reports_ready', 'success',
            'Reports Ready: Guidance Report',
            "Guidance report for {$from} to {$to} is ready to view.",
            'reports', null, "reports_ready:guidance:{$rDate}", $rDate);
    }

    exit();
}

// ════════════════════════════════════════════════════════════════
// CLINIC REPORTS (module=clinic, the default / original behavior)
// ════════════════════════════════════════════════════════════════

// ── 1. TOTAL CLINIC VISITS (within range) ──
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM clinic_visits
    WHERE visited_at BETWEEN ? AND ? AND is_archived = 0
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$totalVisits = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);

// ── 2. MOST COMMON MEDICINE GIVEN (from treatments, within range) ──
$stmt = $conn->prepare("
    SELECT medicine, COUNT(*) AS uses
    FROM treatments
    WHERE given_at BETWEEN ? AND ? AND is_archived = 0
    GROUP BY medicine
    ORDER BY uses DESC
    LIMIT 1
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$topMedRow = $stmt->get_result()->fetch_assoc();
$mostCommonMedicine = $topMedRow ? $topMedRow['medicine'] : null;
$mostCommonMedicineCount = $topMedRow ? (int)$topMedRow['uses'] : 0;

// ── 3. MOST COMMON SYMPTOM / CONCERN (from clinic_visits, within range) ──
$stmt = $conn->prepare("
    SELECT symptoms, COUNT(*) AS occurrences
    FROM clinic_visits
    WHERE visited_at BETWEEN ? AND ? AND is_archived = 0
    GROUP BY symptoms
    ORDER BY occurrences DESC
    LIMIT 1
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$topSymptomRow = $stmt->get_result()->fetch_assoc();
$mostCommonSymptom = $topSymptomRow ? $topSymptomRow['symptoms'] : null;
$mostCommonSymptomCount = $topSymptomRow ? (int)$topSymptomRow['occurrences'] : 0;

// ── 4. MOST COMMON GRADE LEVEL (students who visited, within range) ──
$stmt = $conn->prepare("
    SELECT s.grade_section, COUNT(*) AS visits
    FROM clinic_visits cv
    JOIN students s ON s.id = cv.student_id
    WHERE cv.visited_at BETWEEN ? AND ? AND cv.is_archived = 0
    GROUP BY s.grade_section
    ORDER BY visits DESC
    LIMIT 1
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$topGradeRow = $stmt->get_result()->fetch_assoc();
$mostCommonGrade = $topGradeRow ? $topGradeRow['grade_section'] : null;
$mostCommonGradeCount = $topGradeRow ? (int)$topGradeRow['visits'] : 0;

// ── 5. TOP 5 BREAKDOWN LISTS (for chart/table display, not just the #1) ──

// Top medicines (up to 5)
$stmt = $conn->prepare("
    SELECT medicine, COUNT(*) AS uses
    FROM treatments
    WHERE given_at BETWEEN ? AND ? AND is_archived = 0
    GROUP BY medicine
    ORDER BY uses DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$res = $stmt->get_result();
$topMedicines = [];
while ($row = $res->fetch_assoc()) $topMedicines[] = $row;

// Top symptoms (up to 5)
$stmt = $conn->prepare("
    SELECT symptoms, COUNT(*) AS occurrences
    FROM clinic_visits
    WHERE visited_at BETWEEN ? AND ? AND is_archived = 0
    GROUP BY symptoms
    ORDER BY occurrences DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$res = $stmt->get_result();
$topSymptoms = [];
while ($row = $res->fetch_assoc()) $topSymptoms[] = $row;

// Top grade levels (up to 5)
$stmt = $conn->prepare("
    SELECT s.grade_section, COUNT(*) AS visits
    FROM clinic_visits cv
    JOIN students s ON s.id = cv.student_id
    WHERE cv.visited_at BETWEEN ? AND ? AND cv.is_archived = 0
    GROUP BY s.grade_section
    ORDER BY visits DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$res = $stmt->get_result();
$topGrades = [];
while ($row = $res->fetch_assoc()) $topGrades[] = $row;

// ── 6. INVENTORY: LOW STOCK (quantity > 0 but below threshold) ──
// Same threshold convention used by admin.js (LOW_STOCK_THRESHOLD = 10).
// Not date-filtered — inventory is a current-state snapshot, not historical.
$LOW_STOCK_THRESHOLD = 10;

$stmt = $conn->prepare("
    SELECT id, name, quantity, unit, expiry_date
    FROM medicines
    WHERE quantity > 0 AND quantity < ? AND is_archived = 0
    ORDER BY quantity ASC
");
$stmt->bind_param("i", $LOW_STOCK_THRESHOLD);
$stmt->execute();
$res = $stmt->get_result();
$lowStock = [];
while ($row = $res->fetch_assoc()) $lowStock[] = $row;

// ── 7. INVENTORY: OUT OF STOCK (quantity = 0, literal) ──
$result = $conn->query("
    SELECT id, name, quantity, unit, expiry_date
    FROM medicines
    WHERE quantity = 0 AND is_archived = 0
    ORDER BY name ASC
");
$outOfStock = [];
while ($row = $result->fetch_assoc()) $outOfStock[] = $row;

// ── 9. MEDICINE ANALYTICS: monthly dispensing trend (last 6 months) ──
// Independent of the from/to toolbar range — this is always "the last
// 6 calendar months" so the trend chart has a stable, comparable window.
$stmt = $conn->prepare("
    SELECT DATE_FORMAT(given_at, '%Y-%m') AS ym, SUM(quantity_used) AS total_qty
    FROM treatments
    WHERE given_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
      AND is_archived = 0
    GROUP BY ym
    ORDER BY ym ASC
");
$stmt->execute();
$res = $stmt->get_result();
$monthlyByYm = [];
while ($row = $res->fetch_assoc()) $monthlyByYm[$row['ym']] = (int)$row['total_qty'];

$monthlyTrend = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthlyTrend[] = [
        'label' => date('M', strtotime("-$i months")),
        'qty'   => $monthlyByYm[$ym] ?? 0,
    ];
}

// ── 10. MEDICINE ANALYTICS: top medicines by total quantity dispensed ──
// (distinct from "most uses" above — a medicine given in small amounts
// often vs. one given in large amounts rarely are different signals.)
$stmt = $conn->prepare("
    SELECT medicine, SUM(quantity_used) AS total_qty, COUNT(*) AS uses
    FROM treatments
    WHERE given_at BETWEEN ? AND ? AND is_archived = 0
    GROUP BY medicine
    ORDER BY total_qty DESC
    LIMIT 5
");
$stmt->bind_param("ss", $fromInclusive, $toInclusive);
$stmt->execute();
$res = $stmt->get_result();
$topMedicinesByQty = [];
while ($row = $res->fetch_assoc()) {
    $row['total_qty'] = (int)$row['total_qty'];
    $row['uses']      = (int)$row['uses'];
    $topMedicinesByQty[] = $row;
}

// ── 11. MEDICINE ANALYTICS: stock depletion estimate ──
// avg daily usage over the last 30 days -> estimated days remaining
// at the current stock level. Flags anything under 14 days as urgent.
$stmt = $conn->prepare("
    SELECT m.id, m.name, m.quantity, m.unit,
        COALESCE(SUM(t.quantity_used), 0) AS used_last_30d
    FROM medicines m
    LEFT JOIN treatments t
        ON t.medicine = m.name
        AND t.given_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND t.is_archived = 0
    WHERE m.is_archived = 0
    GROUP BY m.id
    HAVING used_last_30d > 0
");
$stmt->execute();
$res = $stmt->get_result();
$depletionEstimates = [];
while ($row = $res->fetch_assoc()) {
    $avgDaily = $row['used_last_30d'] / 30;
    $daysLeft = $avgDaily > 0 ? (int)floor($row['quantity'] / $avgDaily) : null;
    $depletionEstimates[] = [
        'name'          => $row['name'],
        'quantity'      => (int)$row['quantity'],
        'unit'          => $row['unit'],
        'used_last_30d' => (int)$row['used_last_30d'],
        'days_left'     => $daysLeft,
    ];
}
// Soonest-to-run-out first; medicines with no usage data are excluded
// above since a depletion estimate isn't meaningful without a usage rate.
usort($depletionEstimates, fn($a, $b) => ($a['days_left'] ?? PHP_INT_MAX) <=> ($b['days_left'] ?? PHP_INT_MAX));
$depletionEstimates = array_slice($depletionEstimates, 0, 5);

// ── 8. INVENTORY: EXPIRING SOON (within 30 days) / EXPIRED ──
$stmt = $conn->prepare("
    SELECT id, name, quantity, unit, expiry_date
    FROM medicines
    WHERE expiry_date IS NOT NULL
      AND expiry_date >= CURDATE()
      AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
      AND is_archived = 0
    ORDER BY expiry_date ASC
");
$stmt->execute();
$res = $stmt->get_result();
$expiringSoon = [];
while ($row = $res->fetch_assoc()) $expiringSoon[] = $row;

$stmt = $conn->prepare("
    SELECT id, name, quantity, unit, expiry_date
    FROM medicines
    WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND is_archived = 0
    ORDER BY expiry_date DESC
");
$stmt->execute();
$res = $stmt->get_result();
$expired = [];
while ($row = $res->fetch_assoc()) $expired[] = $row;

// ── RESPONSE ──
echo json_encode([
    "success"   => true,
    "range"     => ["from" => $from, "to" => $to],
    "summary"   => [
        "total_visits"               => $totalVisits,
        "most_common_medicine"       => $mostCommonMedicine,
        "most_common_medicine_count" => $mostCommonMedicineCount,
        "most_common_symptom"        => $mostCommonSymptom,
        "most_common_symptom_count"  => $mostCommonSymptomCount,
        "most_common_grade"          => $mostCommonGrade,
        "most_common_grade_count"    => $mostCommonGradeCount,
    ],
    "breakdown" => [
        "top_medicines" => $topMedicines,
        "top_symptoms"  => $topSymptoms,
        "top_grades"    => $topGrades,
    ],
    "medicine_analytics" => [
        "monthly_trend"        => $monthlyTrend,
        "top_medicines_by_qty" => $topMedicinesByQty,
        "depletion_estimates"  => $depletionEstimates,
    ],
    "inventory" => [
        "low_stock"     => $lowStock,
        "out_of_stock"  => $outOfStock,
        "expiring_soon" => $expiringSoon,
        "expired"       => $expired,
    ],
]);

if ($role === 'Administrator') {
    $rDate = date('Y-m-d');
    notify_upsert($conn, 'System', 'reports_ready', 'success',
        'Reports Ready: Clinic Report',
        "Clinic report for {$from} to {$to} is ready to view.",
        'reports', null, "reports_ready:clinic:{$rDate}", $rDate);
}

exit();