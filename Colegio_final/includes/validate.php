<?php
/**
 * Shared server-side validation helpers.
 *
 * The client-side checks in assets/js/validation.js are a UX
 * convenience only — every one of these must also be enforced here,
 * since JavaScript can always be bypassed. This file only adds the
 * reusable CHECKS; each API endpoint still decides which of its own
 * fields are required and what to do when a check fails (return the
 * same {"success": false, "message": "..."} shape it already uses).
 */

if (!function_exists('csf_require_fields')) {
    /**
     * $data: the decoded request body/array.
     * $map: ['field_key' => 'Human Label', ...]
     * Returns an array of missing labels (empty array = nothing missing).
     * Values are NOT trimmed here — callers already trim() when reading
     * each field into a local variable; pass the same trimmed values in
     * via $data if you want whitespace-only input caught too.
     */
    function csf_require_fields(array $data, array $map): array
    {
        $missing = [];
        foreach ($map as $key => $label) {
            $val = $data[$key] ?? '';
            if (is_string($val)) $val = trim($val);
            if ($val === '' || $val === null) $missing[] = $label;
        }
        return $missing;
    }
}

if (!function_exists('csf_valid_ph_mobile')) {
    // Accepts 09XXXXXXXXX (11 digits) or +639XXXXXXXXX. Strips spaces/
    // dashes first so "0917 123 4567" is treated the same as "09171234567".
    function csf_valid_ph_mobile(string $value): bool
    {
        $v = preg_replace('/[\s\-]/', '', $value);
        return (bool) preg_match('/^(\+63|0)9\d{9}$/', $v);
    }
}

if (!function_exists('csf_valid_email')) {
    function csf_valid_email(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }
}

if (!function_exists('csf_valid_date')) {
    // Strict Y-m-d check (rejects "2026-02-30", not just malformed strings).
    function csf_valid_date(string $value): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }
}

if (!function_exists('csf_date_not_future')) {
    function csf_date_not_future(string $value): bool
    {
        if (!csf_valid_date($value)) return true; // format errors are csf_valid_date's job
        return $value <= date('Y-m-d');
    }
}

if (!function_exists('csf_date_not_past')) {
    function csf_date_not_past(string $value): bool
    {
        if (!csf_valid_date($value)) return true;
        return $value >= date('Y-m-d');
    }
}

if (!function_exists('csf_fail')) {
    // Standard rejection response — matches the {"success":false,"message":...}
    // shape every endpoint in this project already uses.
    function csf_fail(string $message): void
    {
        echo json_encode(["success" => false, "message" => $message]);
        exit();
    }
}

if (!function_exists('csf_is_na')) {
    // True for an empty value or an "N/A" / "NA" placeholder (any case,
    // with or without the slash) — i.e. "no answer was given".
    function csf_is_na($value): bool
    {
        if ($value === null) return true;
        $v = strtoupper(trim((string)$value));
        return $v === '' || $v === 'N/A' || $v === 'NA';
    }
}

if (!function_exists('csf_na')) {
    // Optional free-text field: anything left blank is saved as "N/A"
    // so records never show an empty cell. A typed "NA" is normalised
    // to "N/A" so the same placeholder is stored everywhere.
    function csf_na($value): string
    {
        return csf_is_na($value) ? 'N/A' : trim((string)$value);
    }
}

if (!function_exists('csf_blank_if_na')) {
    // The reverse: turn the "N/A" placeholder back into '' for logic
    // that must treat it as "no value" (building a full name, deciding
    // whether an SMS can be sent, validating a phone number, ...).
    function csf_blank_if_na($value): string
    {
        return csf_is_na($value) ? '' : trim((string)$value);
    }
}

if (!function_exists('csf_public_error')) {
    /**
     * Message safe to show a user for a caught exception. Deliberate business
     * messages (e.g. "Not enough stock ...") pass through unchanged; raw database
     * errors (table/column/constraint names) are logged and replaced.
     */
    function csf_public_error(Throwable $e): string
    {
        if ($e instanceof mysqli_sql_exception) {
            error_log('DB error: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            return 'A database error occurred. Please try again or contact the system administrator.';
        }
        return $e->getMessage();
    }
}
