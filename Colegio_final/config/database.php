<?php
/**
 * Database connection — CSF Integrated System
 *
 * Using mysqli with explicit charset to prevent charset-based
 * SQL injection.
 *
 * Credentials come from environment variables so the real production
 * values are never hardcoded/committed in this file. Each getenv()
 * falls back to the same localhost/root/blank/csf_db values this file
 * always used, so local development is completely unaffected — nothing
 * changes here unless you actually set these env vars.
 *
 * To deploy: set these on your production server (e.g. in Apache's
 * vhost config via SetEnv, in a .env loaded by your host, or in your
 * hosting panel's "Environment Variables" section) — do NOT edit the
 * fallback values below with real production credentials.
 *   DB_HOST, DB_USER, DB_PASS, DB_NAME
 */
$conn = new mysqli(
    getenv('DB_HOST') ?: "localhost",
    getenv('DB_USER') ?: "root",
    getenv('DB_PASS') ?: "",
    getenv('DB_NAME') ?: "csf_db"
);

if ($conn->connect_error) {
    // Log the real error server-side; never expose it to the client.
    error_log("DB connection failed: " . $conn->connect_error);
    http_response_code(503);
    echo json_encode(["success" => false, "message" => "Database unavailable."]);
    exit();
}

// Explicit UTF-8 charset prevents charset-based injection attacks.
$conn->set_charset("utf8mb4");
