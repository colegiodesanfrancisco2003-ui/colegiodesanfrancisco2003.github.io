<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared dashboard — ONE implementation for Administrator, Clinic Staff,
 * and Guidance Staff. Which stat cards and widgets appear is driven by
 * role permissions; the markup itself is never duplicated per role.
 *
 * Layout: a 4-card top metric grid (role-specific cards only — no more
 * than 4, so it never feels crowded), then a 60/40 two-column body:
 * Recent Activity feed on the left, Quick Actions + Pending Task Alerts
 * on the right.
 */
$isAdmin      = ($role === 'Administrator');
$hasClinic    = $isAdmin || $role === 'Clinic Staff';
$hasGuidance  = $isAdmin || $role === 'Guidance Staff';
$guidanceOnly = ($role === 'Guidance Staff');
$clinicOnly   = ($role === 'Clinic Staff');

$timelineTitle = $guidanceOnly ? 'Recent Guidance Activity' : ($isAdmin ? 'Recent Activity' : 'Recent Clinic Visits');

// Role-specific subtitle so each dashboard says what it covers.
$overviewLabel = $guidanceOnly ? "today's guidance overview" : ($isAdmin ? "today's system overview" : "today's clinic overview");
?>
<div class="page active" id="page-dashboard">
  <div class="page-header">
    <div>
      <h2 id="dash-greeting">Welcome back</h2>
      <p>Here's <?php echo $overviewLabel; ?> — <span id="dash-date">today</span>.</p>
    </div>
  </div>

  <!-- ══════════════ TOP METRIC GRID — per role card count ══════════════
       Admin, Clinic, and Guidance each render exactly 4 cards
       (stats-grid-4: fixed 4-up desktop grid, collapsing to 2-up then
       1-up — see admin.css). -->
  <div class="stats-grid stats-grid-4">
    <?php if ($isAdmin): ?>
      <div role="button" tabindex="0" class="stat-card gold clickable" onclick="goToDashboardCard('students')"><div class="icon-bg"><i class="fi fi-rr-student"></i></div><h3 id="ds-admin-students">0</h3><p>Total Students</p></div>
      <div role="button" tabindex="0" class="stat-card clickable" onclick="goToDashboardCard('users')"><div class="icon-bg"><i class="fi fi-rr-user-pen"></i></div><h3 id="ds-admin-users">0</h3><p>Staff Accounts</p></div>
      <div role="button" tabindex="0" class="stat-card green clickable" onclick="goToDashboardCard('counseling')"><div class="icon-bg"><i class="fi fi-rr-meeting-alt"></i></div><h3 id="ds-admin-active-sessions">0</h3><p>Active Counseling Sessions</p></div>
      <div role="button" tabindex="0" class="stat-card blue clickable" onclick="goToDashboardCard('audit-trail')"><div class="icon-bg"><i class="fi fi-rr-audit"></i></div><h3 id="ds-admin-audit-today">0</h3><p>Audit Trail Events Today</p></div>
    <?php elseif ($clinicOnly): ?>
      <div role="button" tabindex="0" class="stat-card gold clickable" onclick="goToClinicVisitsFiltered('')"><div class="icon-bg"><i class="fi fi-rr-visit"></i></div><h3 id="ds-visits">0</h3><p>Clinic Visits Today</p></div>
      <div role="button" tabindex="0" class="stat-card blue clickable" onclick="goToInventoryAlert('Low Stock')"><div class="icon-bg"><i class="fi fi-rr-box"></i></div><h3 id="ds-low">0</h3><p>Low Stock Items</p></div>
      <div role="button" tabindex="0" class="stat-card red clickable" onclick="goToInventoryAlert('Expiry Alert')"><div class="icon-bg"><i class="fi fi-rr-duration-alt"></i></div><h3 id="ds-expiry">0</h3><p>Expiry Alert Items</p></div>
      <div role="button" tabindex="0" class="stat-card clickable" onclick="goToDashboardCard('referrals')"><div class="icon-bg"><i class="fi fi-rr-referral-user"></i></div><h3 id="ds-pending-referrals">0</h3><p>Referrals This Month</p></div>
    <?php elseif ($guidanceOnly): ?>
      <div role="button" tabindex="0" class="stat-card green clickable" onclick="goToDashboardCard('counseling')"><div class="icon-bg"><i class="fi fi-rr-meeting-alt"></i></div><h3 id="ds-active-cases">0</h3><p>Active Counseling Cases</p></div>
      <div role="button" tabindex="0" class="stat-card gold clickable" onclick="goToBehavioralAttention()"><div class="icon-bg"><i class="fi fi-rr-file-user"></i></div><h3 id="ds-behavioral-open">0</h3><p>Behavioral Records Needing Attention</p></div>
      <div role="button" tabindex="0" class="stat-card red clickable" onclick="goToOffenseAlert()"><div class="icon-bg"><i class="fi fi-rr-triangle-warning"></i></div><h3 id="ds-notice-required">0</h3><p>Notice Required Offenses</p></div>
      <div role="button" tabindex="0" class="stat-card blue clickable" onclick="goToCasesClosedFiltered()"><div class="icon-bg"><i class="fi fi-rr-check"></i></div><h3 id="ds-cases-closed">0</h3><p>Cases Closed</p></div>
    <?php endif; ?>
  </div>

  <!-- ══════════════ MAIN BODY — 60/40 two-column ══════════════ -->
  <div class="dash-body-grid<?php echo $guidanceOnly ? ' fit-cols' : ''; ?>">

    <!-- LEFT (60%) — Recent Activity -->
    <div class="dash-col-main">
      <div class="card">
        <div class="card-header"><span class="card-title"><?php echo htmlspecialchars($timelineTitle); ?></span></div>
        <div class="timeline" id="dash-timeline"><div class="tl-empty">Loading…</div></div>
      </div>
    </div>

    <!-- RIGHT (40%) — Quick Actions + Upcoming Follow-ups (Guidance only) -->
    <div class="dash-col-side">
      <div class="card">
        <div class="card-header"><span class="card-title">Quick Actions</span></div>
        <div class="quick-actions">
          <?php if ($hasClinic || $hasGuidance): ?>
            <button class="quick-action-btn" onclick="openAddStudent()"><span class="qa-icon"><i class="fi fi-rr-student"></i></span> Add Student</button>
          <?php endif; ?>
          <?php if ($isAdmin): ?>
            <button class="quick-action-btn" onclick="openAddUser()"><span class="qa-icon"><i class="fi fi-rr-user-pen"></i></span> Add User</button>
            <button class="quick-action-btn" onclick="goToDashboardCard('audit-trail')"><span class="qa-icon"><i class="fi fi-rr-overview"></i></span> View Audit Trail</button>
            <button class="quick-action-btn" onclick="goToDashboardCard('sms')"><span class="qa-icon"><i class="fi fi-rr-settings"></i></span> SMS &amp; System Settings</button>
          <?php endif; ?>
          <?php if ($hasClinic): ?>
            <button class="quick-action-btn" onclick="quickLogVisit()"><span class="qa-icon"><i class="fi fi-rr-visit"></i></span> Quick Log Visit</button>
            <button class="quick-action-btn" onclick="goToDashboardCard('inventory')"><span class="qa-icon"><i class="fi fi-rr-inventory-alt"></i></span> Medicine Inventory</button>
          <?php endif; ?>
          <?php if ($hasGuidance): ?>
            <button class="quick-action-btn" onclick="quickLogOffense()"><span class="qa-icon"><i class="fi fi-rr-triangle-warning"></i></span> Log Offense</button>
            <button class="quick-action-btn" onclick="quickNewCounseling()"><span class="qa-icon"><i class="fi fi-rr-meeting-alt"></i></span> New Counseling Session</button>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($guidanceOnly): ?>
      <div class="card">
        <div class="card-header"><span class="card-title">Upcoming Follow-ups</span><span class="badge" id="upcoming-followups-count">0</span></div>
        <div id="upcoming-followups-list"><div class="tl-empty">Loading…</div></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

