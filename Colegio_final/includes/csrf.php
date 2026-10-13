<?php
/**
 * CSRF protection — additive security hardening.
 * Include after config/session.php (session must already be started).
 *
 * csrf_token()              — returns the per-session token, generating one if needed.
 * csrf_verify_if_unsafe()   — for state-changing requests (anything but GET/HEAD/OPTIONS),
 *                              rejects the request unless a valid token was supplied via the
 *                              X-CSRF-Token header (preferred, used by fetch()) or a
 *                              csrf_token POST field (classic form fallback).
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify_if_unsafe(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $sent)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your session could not be verified (invalid or missing CSRF token). Please refresh the page and try again.']);
        exit();
    }
}
