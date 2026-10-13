<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="modal-overlay" id="changePasswordModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2>Change Password</h2>
      <button onclick="closeChangePasswordModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Current Password <span style="color:#c0392b">*</span></label>
          <input type="password" id="cp-current" placeholder="Enter current password" autocomplete="off">
        </div>
        <div class="form-group full">
          <label>New Password <span style="color:#c0392b">*</span></label>
          <input type="password" id="cp-new" placeholder="At least 8 characters" autocomplete="off">
        </div>
        <div class="form-group full">
          <label>Confirm New Password <span style="color:#c0392b">*</span></label>
          <input type="password" id="cp-confirm" placeholder="Re-enter new password" autocomplete="off">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeChangePasswordModal()">Cancel</button>
      <button class="btn-primary" onclick="submitChangePassword()">Update Password</button>
    </div>
  </div>
</div>
