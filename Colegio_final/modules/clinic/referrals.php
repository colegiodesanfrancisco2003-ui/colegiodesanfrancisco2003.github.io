<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-referrals">
      <div class="page-header">
        <div>
          <h2>Referral History</h2>
          <p>Automatically generated from Clinic Visits marked Referral Required — view, search, and print only.</p>
        </div>
      </div>
      <div class="readonly-notice">
        <span class="icon">🔒</span>
        <span>Referral records are automatically generated from Clinic Visits and cannot be manually created, edited, or deleted.</span>
      </div>
      <div class="toolbar">
        <div class="search-wrap">
          <input type="text" id="search-referrals" placeholder="Search by student or destination..." oninput="filterReferrals()">
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Student</th>
              <th>Grade Level</th>
              <th>Referred To</th>
              <th>Reason</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="tbody-referrals"></tbody>
        </table>
      </div>
    </div>
