<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared top header — ONE implementation for Administrator, Clinic Staff,
 * and Guidance Staff. Only the avatar letter and role label change, and
 * both are derived directly from $role/$currentUserName set by the
 * including page.
 */
$avatarLetter = ($role === 'Administrator') ? 'A' : (($role === 'Clinic Staff') ? 'C' : 'G');
?>
<header>

  <button class="sidebar-toggle" onclick="toggleSidebar()" aria-label="Toggle menu">☰</button>
  <div class="header-title" id="headerTitle">Dashboard</div>


  <div class="header-right">
  <div class="notif-bell-wrap">
    <button class="notif-bell" id="notifBellTrigger" onclick="toggleNotifPanel(event)" aria-label="Notifications" aria-haspopup="true">
      <i class="fi fi-rr-bell"></i><span class="notif-bell-badge" id="notifBellBadge" style="display:none">0</span>
    </button>
    <div class="notif-panel" id="notifPanel">
      <div class="notif-panel-header">
        <span>Notifications</span>
        <button type="button" class="notif-mark-all" onclick="markAllNotificationsRead(event)">Mark all read</button>
      </div>
      <div class="notif-panel-body" id="notifPanelBody">
        <div class="rep-empty">Loading...</div>
      </div>
      <div class="notif-panel-footer">
        <a href="#" onclick="event.preventDefault(); openNotifHistory();">View All Notifications</a>
      </div>
    </div>
  </div>
  <div class="user-menu-wrap">
    <div class="user-pill" id="userMenuTrigger" role="button" tabindex="0" aria-haspopup="menu" onclick="toggleUserMenu(event)">
        <div class="avatar"><?php echo $avatarLetter; ?></div>
        <span><b><?php echo htmlspecialchars($role); ?></b></span>
        <span class="user-menu-caret">▾</span>
    </div>
    <div class="user-dropdown" id="userDropdown">
      <div class="user-dropdown-header">
        <div class="avatar"><?php echo $avatarLetter; ?></div>
        <div>
          <div class="user-dropdown-name"><?php echo htmlspecialchars($currentUserName); ?></div>
          <div class="user-dropdown-role"><?php echo htmlspecialchars($role); ?></div>
        </div>
      </div>
      <div class="user-dropdown-divider"></div>
      <div class="user-dropdown-item" role="button" tabindex="0" onclick="openChangePasswordModal()">
        <span class="icon"><i class="fi fi-rr-lock"></i></span> Change Password
      </div>
      <div class="user-dropdown-divider"></div>
      <a href="logout.php" class="user-dropdown-item danger">
        <span class="icon"><i class="fi fi-rr-user-logout"></i></span> Logout
      </a>
    </div>
  </div>
</div>
</header>
