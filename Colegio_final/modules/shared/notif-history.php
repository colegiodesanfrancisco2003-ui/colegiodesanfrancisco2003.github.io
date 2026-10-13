<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-notif-history">
      <div class="page-header">
        <div>
          <h2>Notification History</h2>
          <p>Every current system alert — search, filter by severity or read status, and jump straight to the record.</p>
        </div>
      </div>

      <div class="notif-history-toolbar">
        <div class="search-wrap">
          <input type="text" id="search-notif-history" placeholder="Search notifications..." oninput="filterNotifHistory()">
        </div>
        <select id="filter-notif-severity" onchange="filterNotifHistory()">
          <option value="">All Severities</option>
          <option value="critical"> Critical</option>
          <option value="warning"> Warning</option>
          <option value="info"> Information</option>
          <option value="success"> Success</option>
        </select>
        <select id="filter-notif-read" onchange="filterNotifHistory()">
          <option value="">All</option>
          <option value="unread">Unread</option>
          <option value="read">Read</option>
        </select>
        <input type="date" id="filter-notif-date" onchange="filterNotifHistory()">
      </div>

      <div class="card">
        <div class="notif-history-list" id="notif-history-list">
          <div class="rep-empty">Loading...</div>
        </div>
      </div>
    </div>
