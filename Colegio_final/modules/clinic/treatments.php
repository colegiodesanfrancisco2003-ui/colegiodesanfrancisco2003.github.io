<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-treatments">
      <div class="page-header">
        <div>
          <h2>Treatment History</h2>
          <p>Automatically generated from Clinic Visits — view, search, filter, and print only.</p>
        </div>
      </div>

      <div class="readonly-notice">
        <span class="icon">🔒</span>
        <span>Treatment records are automatically generated from Clinic Visits and cannot be manually created, edited, or deleted.</span>
      </div>

      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-treatments" placeholder="Search by student or medicine..." oninput="filterTreatments()">
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Student</th>
              <th>Symptoms</th>
              <th>Findings</th>
              <th>Medicine Given</th>
              <th>Dosage</th>
              <th>Given By</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tbody-treatments"></tbody>
        </table>
      </div>
    </div>
