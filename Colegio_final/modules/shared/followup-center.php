<?php
/**
 * STEP 5 — Follow-up Center. ONE implementation for Administrator,
 * Clinic Staff, and Guidance Staff — the data itself is role-scoped
 * server-side by api/followups.php, not by hiding markup here.
 *
 * STEP 5 CORRECTION: the Source filter options themselves must also
 * match what api/followups.php can actually return for this role —
 * previously all three options were shown to everyone regardless of
 * role, so a Clinic Staff user saw a "Counseling Follow-up" option that
 * could only ever return an empty result (the API already correctly
 * excluded that data — this was a UI-only mismatch, not an RBAC hole).
 */
$fcCanSeeClinic   = in_array($role, ['Administrator', 'Clinic Staff'], true);
$fcCanSeeGuidance = in_array($role, ['Administrator', 'Guidance Staff'], true);
?>
<div class="page" id="page-followup-center">
  <div class="page-header">
    <div>
      <h2>Follow-up Center</h2>
      <p>What needs your attention today.</p>
    </div>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <input type="text" id="fc-search" placeholder="Search student name or ID..." oninput="renderFollowupCenter()">
    </div>
    <select id="fc-bucket" onchange="renderFollowupCenter()">
      <option value="">All</option>
      <option value="overdue">Overdue</option>
      <option value="today">Today</option>
      <option value="upcoming">Upcoming</option>
      <option value="completed">Recently Completed</option>
    </select>
    <select id="fc-source" onchange="renderFollowupCenter()">
      <option value="">All Sources</option>
      <?php if ($fcCanSeeClinic): ?>
      <option value="clinic">Clinic Follow-up</option>
      <option value="referral">Referral</option>
      <?php endif; ?>
      <?php if ($fcCanSeeGuidance): ?>
      <option value="counseling">Counseling Follow-up</option>
      <?php endif; ?>
    </select>
  </div>

  <div class="report-stats-grid" style="margin-bottom:1rem">
    <div class="report-stat-card clickable" onclick="document.getElementById('fc-bucket').value='overdue';renderFollowupCenter()"><div class="rep-label">Overdue</div><div class="rep-value" id="fc-count-overdue" style="color:#c0392b">0</div></div>
    <div class="report-stat-card clickable" onclick="document.getElementById('fc-bucket').value='today';renderFollowupCenter()"><div class="rep-label">Due Today</div><div class="rep-value" id="fc-count-today">0</div></div>
    <div class="report-stat-card clickable" onclick="document.getElementById('fc-bucket').value='upcoming';renderFollowupCenter()"><div class="rep-label">Upcoming</div><div class="rep-value" id="fc-count-upcoming">0</div></div>
  </div>

  <div id="fc-list"></div>
</div>
