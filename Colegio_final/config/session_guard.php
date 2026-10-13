<?php
/**
 * Session guard — call AFTER session_start() on every page/endpoint
 * that requires a logged-in user (not on index.php/login.php).
 *
 * - Ends the session after 30 minutes of inactivity.
 * - Regenerates the session ID periodically so a long-lived session
 *   doesn't keep using the same ID forever (fixation hardening).
 */

if (!function_exists('enforce_session_timeout')) {
    function enforce_session_timeout(int $idleMinutes = 30): void
    {
        if (!isset($_SESSION['user_id'])) {
            return; // nothing to time out — the page's own auth check handles this
        }

        $now = time();
        $idleLimit = $idleMinutes * 60;

        if (!empty($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > $idleLimit) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
            }
            session_destroy();

            $isApi = str_starts_with($_SERVER['SCRIPT_NAME'] ?? '', '/api/') || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
            if ($isApi) {
                header("Content-Type: application/json");
                http_response_code(401);
                echo json_encode(["success" => false, "message" => "Session expired due to inactivity. Please log in again."]);
            } else {
                header("Location: index.php?error=timeout");
            }
            exit();
        }

        $_SESSION['last_activity'] = $now;

        // Rotate the session ID roughly every 30 minutes of active use —
        // keeps all existing $_SESSION data, just issues a fresh ID/cookie.
        if (empty($_SESSION['id_issued_at'])) {
            $_SESSION['id_issued_at'] = $now;
        } elseif (($now - $_SESSION['id_issued_at']) > 1800) {
            session_regenerate_id(true);
            $_SESSION['id_issued_at'] = $now;
        }
    }
}
