<?php
/**
 * Request security bootstrap — session hardening + error handling.
 *
 * Included in place of every bare `session_start()` call across the app.
 * Two responsibilities, both needed on every request, both environment-
 * aware so nothing changes for local development:
 *
 * SESSION COOKIE HARDENING
 * Cookie parameters must be set BEFORE session_start() — PHP ignores
 * changes made after the session has already started.
 * - HttpOnly: always on. JavaScript never needs to read the session
 *   cookie, so this closes off one XSS-escalation path for free.
 * - SameSite=Lax: blocks the cookie being sent on cross-site requests
 *   (CSRF hardening) while still allowing normal same-app navigation and
 *   the login page's own redirects to work exactly as before.
 * - Secure: only forced on when the request is actually over HTTPS.
 *   Forcing it unconditionally would break plain-HTTP localhost
 *   development (the browser silently drops "Secure" cookies over HTTP),
 *   so this detects the scheme instead of hardcoding either value.
 *
 * PRODUCTION ERROR HANDLING
 * On localhost, errors stay visible on-screen — useful during
 * development, matching how the app already behaved before this
 * hardening pass. Anywhere else, errors are hidden from the browser
 * (never leak file paths/stack traces to a user) but still written to
 * the server's error log so they're not silently lost.
 */
$__isLocalRequest = (
    strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false
);

ini_set('display_errors', $__isLocalRequest ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ||
        (($_SERVER['SERVER_PORT'] ?? '') == 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
