<?php
/**
 * Session hardening — Security > Session Hardening.
 * Include BEFORE session_start() in every entry point (index.php,
 * login.php, logout.php, admin.php, clinic.php, guidance.php, and
 * every api/*.php).
 */

if (!defined('CSF_SESSION_HARDENED')) {
    define('CSF_SESSION_HARDENED', true);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,            // expires when the browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,     // only sent over HTTPS once the site is deployed behind TLS
        'httponly' => true,         // JavaScript (document.cookie) can never read the session cookie
        'samesite' => 'Lax',        // blocks the cookie being sent on cross-site requests
    ]);

    // Reject any session ID the client invents instead of one PHP issued.
    ini_set('session.use_strict_mode', '1');
}

/**
 * Production error handling (final audit).
 * Errors stay visible on-screen only for requests that really come from this
 * machine (loopback address) so development is unchanged; for every other
 * client file paths / stack traces / SQL errors are hidden from the browser
 * but still written to the server error log (nothing is silently lost).
 */
if (!defined('CSF_ERRORS_CONFIGURED')) {
    define('CSF_ERRORS_CONFIGURED', true);
    $csfIsLoopback = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    ini_set('display_errors', $csfIsLoopback ? '1' : '0');
    ini_set('log_errors', '1');
}
