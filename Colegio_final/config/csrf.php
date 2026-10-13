<?php
/**
 * CSRF protection — Security > CSRF.
 * Include after session_start(). csrf_token() issues/returns the
 * per-session token (call from page files to embed it for JS).
 * csrf_verify() enforces it on state-changing API requests.
 */

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        // Only state-changing requests need a token — GET/HEAD are safe reads.
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        $sent     = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';

        if (!$expected || !$sent || !hash_equals($expected, $sent)) {
            http_response_code(403);
            header("Content-Type: application/json");
            echo json_encode(["success" => false, "message" => "Session expired or invalid request. Please refresh the page and try again."]);
            exit();
        }
    }
}
