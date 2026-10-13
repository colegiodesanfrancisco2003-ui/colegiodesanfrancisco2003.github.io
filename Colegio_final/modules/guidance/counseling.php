<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="page" id="page-counseling">
  <div class="page-header">
    <div>
      <h2>Counseling Sessions</h2>
      <p>Record and manage counseling sessions.</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <button class="btn-primary" onclick="openAddCounseling()">+ New Session</button>
    </div>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <input type="text" id="search-counseling" placeholder="Search by student name or ID..." oninput="filterCounseling()">
    </div>
    <select id="filter-counseling-status" onchange="filterCounseling()">
      <option value="">All Status</option>
      <option value="Scheduled">Scheduled</option>
      <option value="Ongoing">Ongoing</option>
      <option value="Overdue">Overdue</option>
    </select>
    <!-- Closed removed: Closed sessions no longer load into this page at
         all (see renderCounseling()'s status=Active fetch), so a Closed
         filter option would always show an empty table. Closed cases now
         live exclusively in the Close Case module (see close-case.php). -->
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Student</th>
          <th>Counselor</th>
          <th>Linked Records</th>
          <th>Source</th>
          <th>Concern</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="tbody-counseling"></tbody>
    </table>
  </div>
</div>
