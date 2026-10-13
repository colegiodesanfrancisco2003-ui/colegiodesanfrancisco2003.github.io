<?php
require_once __DIR__ . '/validate.php';
/**
 * Guardian SMS helper — Clinic module only.
 *
 * Automatic SMS is exclusive to the Clinic Module. The Guidance Module
 * must never call anything in this file.
 *
 * Usage from api/clinic.php (or a manual-send endpoint), AFTER $conn is
 * already connected and session_start() has already run:
 *
 *   require_once __DIR__ . "/../includes/sms.php";
 *   maybe_send_clinic_event_sms($conn, $studentId, $visitId, $referralId,
 *       ['is_referral' => true, 'is_sent_home' => false, 'is_emergency' => false],
 *       $chiefComplaint, 'Automatic');
 *
 * Every actual send always writes exactly one row to sms_logs (Student,
 * Guardian, Contact, Message, related Clinic Visit / Referral, Status,
 * Sent By, Date) — send_guardian_sms() decides Pending/Sent/Failed and
 * never throws, so a notification problem can never break the Clinic
 * Visit transaction it's describing (same principle as config/audit.php).
 *
 * Configuration lives in `system_settings` (category='sms') — the
 * same category the SMS Notifications page (Enable SMS Gateway
 * Provider / Sender Name / API Key) already reads and writes through
 * api/settings.php. There is no separate settings table to keep in
 * sync.
 *
 * Rules encoded here, in order:
 *   1. No guardian contact number on file → nothing to send to. Never
 *      dispatched, never logged (not an actual SMS attempt) — takes
 *      priority over every other rule below.
 *   2. Running on localhost (dev/testing) → ALWAYS Pending, never actually
 *      dispatched, regardless of the Enable SMS setting. This is an
 *      unconditional safety switch, not a configuration option.
 *   3. Not configured (Enable SMS off, or Provider/API Key/Sender Name
 *      incomplete) → Pending. Once an Administrator finishes configuring
 *      and enables it — and the app isn't on localhost — sending turns on
 *      automatically, with no further code change needed.
 *   4. Otherwise → actually attempt to dispatch, mark Sent/Failed based on
 *      the result.
 */

require_once __DIR__ . '/notify.php';

function _sms_is_localhost(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $addr = $_SERVER['SERVER_ADDR'] ?? '';
    return (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        $addr === '127.0.0.1' ||
        $addr === '::1'
    );
}

// Reads live SMS gateway configuration from the `system_settings`
// table (category='sms') — the same table the SMS Notifications form
// saves to (see api/settings.php).
function _sms_get_settings($conn): array
{
    $keys = ['sms_provider', 'sms_api_key', 'sms_sender_name', 'sms_enabled'];
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $types = str_repeat('s', count($keys));
    $stmt->bind_param($types, ...$keys);
    $stmt->execute();
    $res = $stmt->get_result();

    $raw = [];
    while ($row = $res->fetch_assoc()) $raw[$row['setting_key']] = $row['setting_value'];

    return [
        'provider'    => $raw['sms_provider']     ?? '',
        'api_key'     => $raw['sms_api_key']      ?? '',
        'sender_name' => $raw['sms_sender_name']  ?? '',
        'is_enabled'  => ($raw['sms_enabled']     ?? '0') === '1',
    ];
}

// Reads one of the per-event automatic-SMS toggles (SMS Settings page:
// "Automatically notify guardians for referrals / when sent home / for
// emergency cases"). Missing key defaults to enabled, so a fresh
// install behaves as "on" until an Administrator explicitly turns a
// specific event off.
function _sms_event_enabled($conn, string $settingKey): bool
{
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->bind_param("s", $settingKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return true; // not configured yet — default on
    return $row['setting_value'] !== '0';
}

/**
 * Attempts the actual network dispatch to the configured SMS provider.
 * Returns true on apparent success, false on failure. Never called at all
 * when on localhost or when not fully configured (see send_guardian_sms).
 *
 * NOTE: this project doesn't specify a particular SMS gateway, and "SMS
 * Provider" is a free-text/select configuration field an Administrator
 * fills in — there is no live provider to test against in this
 * environment. This performs a generic HTTP POST built from the
 * configured settings; an Administrator wiring up a specific real
 * gateway may need to adjust the request shape to match that
 * provider's actual API.
 */
function _sms_dispatch($provider, $apiKey, $senderName, $recipient, $message): bool
{
    if (!function_exists('curl_init')) {
        error_log("SMS dispatch skipped: curl extension not available.");
        return false;
    }

    $ch = curl_init("https://api.semaphore.co/api/v4/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POSTFIELDS     => http_build_query([
            'apikey'     => $apiKey,
            'number'     => $recipient,
            'message'    => $message,
            'sendername'=> $senderName,
        ]),
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("SMS dispatch error ({$provider}): {$curlError}");
        return false;
    }
    return $httpCode >= 200 && $httpCode < 300;
}

/**
 * Low-level send + log. Always writes one sms_logs row, never throws.
 * $sentBy is 'Automatic' for system-triggered sends, or the acting
 * staff member's name for a manual send.
 */
function send_guardian_sms(
    $conn,
    int $studentId,
    string $guardianName,
    string $guardianContact,
    string $message,
    ?int $visitId = null,
    ?int $referralId = null,
    string $sentBy = 'Automatic',
    string $studentName = ''
): string {
    // Guardian Contact Number is validated BEFORE every SMS attempt, and
    // takes priority over the localhost/configuration checks below. No
    // contact on file means nothing was actually attempted: never
    // dispatch, never write an sms_logs row (a missing contact is its
    // own state-based "Guardian Contact Missing" alert — see the
    // reconcile step in api/notifications.php — not a fake SMS history
    // entry). The manual-send endpoint already checks this up front and
    // shows the user a warning instead of calling this function at all;
    // this is the backstop for the automatic workflow, which has no
    // interactive user to warn.
    if (trim($guardianContact) === '') {
        return 'Skipped';
    }

    if (_sms_is_localhost()) {
        // Rule 1 — unconditional safety switch.
        $status = 'Pending';
    } else {
        $settings = _sms_get_settings($conn);
        $configured = !empty($settings['provider']) && !empty($settings['api_key']) && !empty($settings['sender_name']);

        if (!$settings['is_enabled'] || !$configured) {
            // Rule 2 — not configured/enabled yet.
            $status = 'Pending';
        } else {
            // Rule 3 — attempt the real send.
            $sent = _sms_dispatch($settings['provider'], $settings['api_key'], $settings['sender_name'], $guardianContact, $message);
            $status = $sent ? 'Sent' : 'Failed';
        }
    }

    $logId = null;
    try {
        $stmt = $conn->prepare("
            INSERT INTO sms_logs (recipient, student_id, visit_id, referral_id, guardian_name, message, status, sent_by, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("siiissss", $guardianContact, $studentId, $visitId, $referralId, $guardianName, $message, $status, $sentBy);
        $stmt->execute();
        $logId = $conn->insert_id;
    } catch (\Throwable $e) {
        error_log("Failed to write sms_logs entry: " . $e->getMessage());
    }

    // Notification Center — only for an actual dispatch attempt (Rule 3),
    // never for the "not configured yet" Pending no-op.
    $label = $studentName !== '' ? $studentName : "Student #$studentId";
    if ($status === 'Sent') {
        notify_upsert($conn, 'Clinic', 'sms_sent', 'success',
            "SMS Sent Successfully to {$label}'s guardian", "Delivered to " . ($guardianName ?: 'guardian') . ".",
            'sms', $logId, "sms_sent:{$logId}", date('Y-m-d'));
    } elseif ($status === 'Failed') {
        notify_upsert($conn, 'Clinic', 'sms_failed', 'critical',
            "SMS Delivery Failed for {$label}", "Could not reach " . ($guardianName ?: 'guardian') . ".",
            'sms', $logId, "sms_failed:{$logId}", date('Y-m-d'));
    }

    return $status;
}

/**
 * Builds the guardian-facing SMS body. Deliberately limited to the
 * Chief Complaint (Symptoms) — never Findings, Treatment, Assessment,
 * or Remarks, which are internal Clinic staff observations / notes.
 */
function build_clinic_sms_message(string $studentName, string $chiefComplaint, bool $isReferral, bool $isSentHome, bool $isEmergency): string
{
    $reason = $chiefComplaint !== '' ? $chiefComplaint : 'General clinic visit';

    if ($isEmergency) {
        $opening = "Your child, {$studentName}, was seen at the school clinic today for an urgent concern.";
    } elseif ($isSentHome) {
        $opening = "Your child, {$studentName}, was sent home today due to illness after a visit to the school clinic.";
    } else {
        $opening = "Your child, {$studentName}, visited the school clinic today.";
    }

    $lines = [
        "Colegio de San Francisco Clinic",
        "",
        "Good day!",
        "",
        $opening,
        "",
        "Reason:",
        $reason,
    ];

    if ($isReferral) {
        $lines[] = "";
        $lines[] = "A referral was issued — please coordinate with the school clinic for further instructions.";
    }

    $lines[] = "";
    $lines[] = "Thank you.";

    return implode("\n", $lines);
}

/**
 * The Clinic-event automatic SMS entry point. Call this AFTER a Clinic
 * Visit (and, when applicable, its Referral) has been successfully
 * saved. Decides whether to send at all — student-level "SMS Enabled"
 * toggle, per-event Administrator toggle, and guardian contact on file
 * are all checked — builds the message, and writes the log.
 *
 * $events is an array with boolean keys: is_referral, is_sent_home,
 * is_emergency. Returns null if nothing was sent (student opted out,
 * every matching event's automatic toggle is off, or no event applies
 * at all); otherwise returns the resulting status string ('Skipped' if
 * the student has no guardian contact on file — see send_guardian_sms
 * — or 'Pending'/'Sent'/'Failed' for an actual attempt).
 */
function maybe_send_clinic_event_sms(
    $conn,
    int $studentId,
    int $visitId,
    ?int $referralId,
    array $events,
    string $chiefComplaint,
    string $sentBy = 'Automatic'
): ?string {
    $isReferral  = !empty($events['is_referral']);
    $isSentHome  = !empty($events['is_sent_home']);
    $isEmergency = !empty($events['is_emergency']);

    if (!$isReferral && !$isSentHome && !$isEmergency) {
        return null; // no notification-worthy event on this visit
    }

    // Respect the per-event Administrator toggles (SMS Settings page).
    // A guardian only needs ONE message even if multiple events fired
    // on the same visit, so a reason "counts" if its own toggle is on.
    $sendReferral  = $isReferral  && _sms_event_enabled($conn, 'sms_notify_referral');
    $sendSentHome  = $isSentHome  && _sms_event_enabled($conn, 'sms_notify_sent_home');
    $sendEmergency = $isEmergency && _sms_event_enabled($conn, 'sms_notify_emergency');

    if (!$sendReferral && !$sendSentHome && !$sendEmergency) {
        return null; // every matching event is individually disabled
    }

    $stmt = $conn->prepare("
        SELECT full_name, guardian_name, guardian_contact, sms_enabled
        FROM students WHERE id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    if (!$student) return null;

    // Per-student "SMS Enabled" toggle (already existed on `students`)
    // is a hard opt-out — never overridden by the automatic workflow.
    if (!(int)$student['sms_enabled']) return null;

    $guardianName    = csf_blank_if_na($student['guardian_name'])    ?: 'Guardian';
    $guardianContact = csf_blank_if_na($student['guardian_contact']);

    $message = build_clinic_sms_message(
        $student['full_name'] ?? 'the student',
        $chiefComplaint,
        $sendReferral,
        $sendSentHome,
        $sendEmergency
    );

    return send_guardian_sms($conn, $studentId, $guardianName, $guardianContact, $message, $visitId, $referralId, $sentBy, $student['full_name'] ?? '');
}
