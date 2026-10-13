<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-behavioral">
      <div class="page-header">
        <div>
          <h2>Behavioral Records</h2>
          <p>Track and manage student behavioral observations.</p>
        </div>
        <button class="btn-primary" onclick="openAddBehavioral()">+ Add Record</button>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-behavioral" placeholder="Search by student or behavior..." oninput="filterBehavioral()">
        </div>
        <select id="filter-behavioral-status" onchange="filterBehavioral()">
          <option value="">All Status</option>
          <option value="Open">Open</option>
          <option value="Addressed">Addressed</option>
        </select>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Student</th>
              <th>Grade Level</th>
              <th>Behavior Observed</th>
              <th>Status</th>
              <th>Recorded By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tbody-behavioral"></tbody>
        </table>
      </div>
    </div>
