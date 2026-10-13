<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared sidebar navigation — ONE implementation for Administrator,
 * Clinic Staff, and Guidance Staff. Sections are shown/hidden based on
 * role permissions instead of maintaining three separate sidebars.
 *
 * Expects $role to be set by the including page (Administrator|Clinic Staff|Guidance Staff).
 */
$isAdmin      = ($role === 'Administrator');
$hasClinic    = $isAdmin || $role === 'Clinic Staff';
$hasGuidance  = $isAdmin || $role === 'Guidance Staff';
?>
<aside id="sidebar">
  <div class="brand">
    <img class="brand-logo" src="images/colegiologo.jpg" alt="Colegio de San Francisco logo">
    <div class="brand-text">
      <h1>CSF Integrated System</h1>
      <p>Colegio de San Francisco</p>
    </div>
  </div>

  <nav aria-label="Main navigation">
    <div role="button" tabindex="0" class="nav-item active" onclick="showPage('dashboard',this)"><span class="icon"><i class="fi fi-rr-home"></i></span> Dashboard</div>

    <?php if ($hasClinic): ?>
    <div class="nav-section">Clinic Module</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('students',this)"><span class="icon"><i class="fi fi-rr-student"></i> </span> Student Health Records</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('clinic-visits',this)"><span class="icon"><i class="fi fi-rr-visit"></i> </span> Clinic Visit Logs</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('inventory',this)"><span class="icon"><i class="fi fi-rr-inventory-alt"></i></span> Medicine Inventory</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('treatments',this)"><span class="icon"><i class="fi fi-rr-medicine"></i></span> Treatment History</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('referrals',this)"><span class="icon"><i class="fi fi-rr-time-past"></i></span> Referral History</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('sms',this)"><span class="icon"><i class="fi fi-rr-message-sms"></i></span> SMS Notifications</div>
    <?php endif; ?>

    <?php if ($hasGuidance): ?>
    <div class="nav-section">Guidance Module</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('guidance-students',this)"><span class="icon"><i class="fi fi-rr-student"></i></span> <?php echo $isAdmin ? 'Student Records' : 'Students Records'; ?></div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('behavioral',this)"><span class="icon"><i class="fi fi-rr-file-user"></i></span> Behavioral Records</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('offenses',this)"><span class="icon"><i class="fi fi-rr-triangle-warning"></i></span> Offenses &amp; Violations</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('counseling',this)"><span class="icon"><i class="fi fi-rr-meeting-alt"></i></span> Counseling Sessions</div>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('close-case',this)"><span class="icon"><i class="fi fi-rr-lock"></i></span> Close Case</div>
    <?php endif; ?>

    <div class="nav-section">System</div>
    <?php if ($isAdmin): ?>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('users',this)"><span class="icon"><i class="fi fi-rr-user-pen"></i></span> User Management</div>
    <?php endif; ?>
    <?php if ($hasGuidance): ?>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('offense-categories',this)"><span class="icon"><i class="fi fi-rr-list-check"></i></span> Offense Categories</div>
    <?php endif; ?>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('reports',this)"><span class="icon"><i class="fi fi-rr-data-report"></i></span> Reports</div>
    <?php if ($isAdmin): ?>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('audit-trail',this)"><span class="icon"><i class="fi fi-rr-audit"></i></span> Audit Trail</div>
    <?php endif; ?>
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('archived',this)"><span class="icon"><i class="fi fi-rr-folder-open"></i></span> Archived Records</div>
  </nav>

  <div class="nav-pinned">
    <div role="button" tabindex="0" class="nav-item" onclick="showPage('about',this)"><span class="icon"><i class="fi fi-rr-info"></i></span> About Us</div>
  </div>

</aside>
<div class="sidebar-backdrop" onclick="toggleSidebar()"></div>
