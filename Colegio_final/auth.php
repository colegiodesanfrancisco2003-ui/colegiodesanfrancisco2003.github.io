<?php
/**
 * ── Step 3 cleanup finding: CONFIRMED DEAD CODE ──────────────────────
 * This file is never require()'d or include()'d by any other file in
 * the project (verified by full-project grep — index.php, login.php,
 * admin.php, clinic.php, guidance.php, and every api/*.php all use
 * config/session_init.php + config/session_guard.php instead).
 * It also hardcodes a redirect to "/Colegio/index.php", a path that
 * does not match this project's actual folder name, so even if it were
 * ever wired up, the redirect itself is stale/broken.
 * Left in place rather than deleted (nothing depends on it either way,
 * so deleting carries no benefit over documenting it) — safe candidate
 * for removal whenever the project does a file-level cleanup pass.
 */
session_start();

if (!isset($_SESSION['user_id'])) {
    // Redirect to the login page at the project root.
    // Using an absolute path from document root avoids broken
    // relative paths when auth.php is included from api/ files.
    header("Location: /Colegio/index.php");
    exit();
}