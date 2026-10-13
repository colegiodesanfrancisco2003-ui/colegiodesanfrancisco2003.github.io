<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="page" id="page-audit-trail">
      <div class="page-header">
        <div>
          <h2>Audit Trail</h2>
          <p>A record of key actions taken across the system.</p>
        </div>
      </div>

      <div class="toolbar">
        <select id="filter-audit-module" onchange="renderAuditTrail()">
          <option value="">All Modules</option>
        </select>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date/Time</th><th>User</th><th>Module</th><th>Action</th><th>Details</th></tr>
          </thead>
          <tbody id="tbody-audit-trail"></tbody>
        </table>
      </div>
    </div>
