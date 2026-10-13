<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-offenses">
      <div class="page-header">
        <div>
          <h2>Offenses &amp; Violations</h2>
          <p>Track uniform, ID, and other violations.</p>
        </div>
        <button class="btn-primary" onclick="openAddOffense()">+ Record Offense</button>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-offenses" placeholder="Search by student or category..." oninput="filterOffenses()">
        </div>
        <select id="filter-offense-type" onchange="filterOffenses()">
          <option value="">All Categories</option>
        </select>
        <select id="filter-offense-severity" onchange="filterOffenses()">
          <option value="">All Severities</option>
          <option value="Minor">Minor</option>
          <option value="Moderate">Moderate</option>
          <option value="Major">Major</option>
          <option value="Severe">Severe</option>
        </select>
        <select id="filter-offense-status" onchange="filterOffenses()">
          <option value="">All Status</option>
          <option value="Warned">Warned</option>
          <option value="Notice Required">Notice Required</option>
          <option value="Resolved">Resolved</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Student</th>
              <th>Category</th>
              <th>Severity</th>
              <th>Description</th>
              <th>Count</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tbody-offenses"></tbody>
        </table>
      </div>
    </div>
