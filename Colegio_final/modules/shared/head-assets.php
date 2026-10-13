<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared <head> assets — Administrator, Clinic Staff, and Guidance Staff
 * all load the exact same CSS/JS. The only thing that differs per role is
 * the page <title> and the CSF_ROLE JS flag, both passed in by the caller.
 *
 * Expects (set by the including page before this include):
 *   $pageTitle  string  e.g. "CSF – Administrator"
 *   $csfRole    string  one of: "admin", "clinic", "guidance"
 */

// Cache-busting for admin.js only: append the file's own last-modified
// time as a version query string (?v=<mtime>). This guarantees that
// every deploy which actually changes admin.js's on-disk content gets a
// new URL, so browsers (and any intermediate cache/CDN) that already
// cached an older admin.js under the plain "assets/js/admin.js" URL are
// forced to fetch the current one instead of silently continuing to run
// stale JS. Falls back to the current timestamp if the file can't be
// stat'd for any reason, so a filesystem hiccup can never break page
// load — it just disables caching for that one request instead.
// Does NOT touch search-dropdown.js, validation.js, or any notification
// behavior; those script tags are unchanged.
$adminJsPath    = __DIR__ . '/../../assets/js/admin.js';
$adminJsVersion = @filemtime($adminJsPath);
if ($adminJsVersion === false) {
    $adminJsVersion = time();
}
?>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?php echo htmlspecialchars($pageTitle); ?></title>

<link rel="stylesheet" href="assets/css/admin.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link rel='stylesheet' href='https://cdn-uicons.flaticon.com/4.0.0/uicons-regular-rounded/css/uicons-regular-rounded.css'>

  <script src="assets/js/search-dropdown.js" defer></script>
  <script src="assets/js/dialogs.js" defer></script>
  <script src="assets/js/validation.js" defer></script>
  <script>const CSF_ROLE = "<?php echo htmlspecialchars($csfRole); ?>";</script>
  <script>window.CSRF_TOKEN = "<?php echo csrf_token(); ?>";</script>
  <script src="assets/js/admin.js?v=<?php echo (int)$adminJsVersion; ?>" defer></script>
  <script src="assets/js/ui-enhancements.js" defer></script>
