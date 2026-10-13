<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<!--
  CLOSE CASE — Guidance Module
  ─────────────────────────────────────────────────────────────────
  This page does NOT introduce a new record type or table. It reads
  the exact same `counseling_sessions` rows as the Counseling Sessions
  page (via api/counseling.php), filtered client-side to status =
  'Closed'. Its only purpose is to give Guidance Staff a dedicated,
  uncluttered place to browse/search cases that are already finished,
  instead of scanning them alongside Scheduled/Ongoing/Overdue ones.

  "View" reuses the existing openEditCounseling() modal — since the
  session's own status is Closed, that modal already renders itself
  read-only (see setCounselingFormReadOnly() in admin.js), so no new
  view-only UI was built for this page.
-->
<div class="page" id="page-close-case">
  <div class="page-header">
    <div>
      <h2>Close Case</h2>
      <p>Counseling sessions that have already been closed.</p>
    </div>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <input type="text" id="search-close-case" placeholder="Search by student name or ID..." oninput="filterCloseCase()">
    </div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Date Closed</th>
          <th>Student</th>
          <th>Counselor</th>
          <th>Source</th>
          <th>Concern</th>
          <th>Outcome / Closing Notes</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="tbody-close-case"></tbody>
    </table>
  </div>
</div>
