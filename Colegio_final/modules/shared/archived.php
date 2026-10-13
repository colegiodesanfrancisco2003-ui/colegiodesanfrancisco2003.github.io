<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared Archived Records page — ONE implementation for Administrator,
 * Clinic Staff, and Guidance Staff. Which archive tabs (categories) are
 * visible is driven entirely by role permissions; the table, search,
 * filters, and Restore/View behavior are identical for everyone.
 *
 * Treatment History and Referral History are intentionally absent from
 * every role — they are read-only history generated automatically from
 * their parent Clinic Visit and are never archived independently. They
 * follow the archive state of that Clinic Visit automatically.
 *
 * The tab bar itself IS the "Module Filter" required for this page: each
 * role only ever sees tabs for the categories it's permitted to view, so
 * switching tabs already filters archived records to one category at a
 * time, scoped by role. A Date filter is provided alongside Search.
 */
$isAdmin      = ($role === 'Administrator');
$hasClinic    = $isAdmin || $role === 'Clinic Staff';
$hasGuidance  = $isAdmin || $role === 'Guidance Staff';
?>
<div class="page" id="page-archived">
  <div class="page-header">
    <div>
      <h2>Archived Records</h2>
      <p>Records that were "deleted" are kept here instead of being permanently removed, since other records (visits, treatments, sessions) may still reference them. Restore any record to bring it back to its active list.</p>
    </div>
  </div>

  <div class="archive-tabs">
    <button class="archive-tab active" data-archtab="students" onclick="switchArchiveTab('students', this)"> <i class="fi fi-rr-student"></i> Students</button>
    <?php if ($hasClinic): ?>
    <button class="archive-tab" data-archtab="clinic-visits" onclick="switchArchiveTab('clinic-visits', this)"><i class="fi fi-rr-visit"></i> Clinic Visits</button>
    <button class="archive-tab" data-archtab="inventory" onclick="switchArchiveTab('inventory', this)"><i class="fi fi-rr-inventory-alt"></i> Medicine Inventory</button>
    <?php endif; ?>
    <?php if ($hasGuidance): ?>
    <button class="archive-tab" data-archtab="counseling" onclick="switchArchiveTab('counseling', this)"><i class="fi fi-rr-meeting-alt"></i> Counseling Sessions</button>
    <button class="archive-tab" data-archtab="behavioral" onclick="switchArchiveTab('behavioral', this)"><i class="fi fi-rr-file-user"></i> Behavioral Records</button>
    <button class="archive-tab" data-archtab="offenses" onclick="switchArchiveTab('offenses', this)"> <i class="fi fi-rr-triangle-warning"></i> Offenses & Violations</button>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <button class="archive-tab" data-archtab="users" onclick="switchArchiveTab('users', this)"> <i class="fi fi-rr-users-alt"></i>Users</button>
    <?php endif; ?>
  </div>

  <div class="toolbar">
    <div class="search-wrap">
      <input type="text" id="search-archived" placeholder="Search archived records..." oninput="filterArchived()">
    </div>
    <input type="date" id="filter-archived-date" onchange="filterArchived()" title="Filter by record date">
  </div>

  <div class="table-wrap">
    <table>
      <thead id="thead-archived"></thead>
      <tbody id="tbody-archived"></tbody>
    </table>
  </div>
</div>

<!-- Permanent Delete confirmation — only ever opened from a row's
     "Permanently Delete" action on this page. Irreversible, so it
     requires the user to type DELETE exactly before the button enables. -->
<div class="modal-overlay" id="permanentDeleteModal">
  <div class="modal-card" style="max-width:440px">
    <div class="modal-header">
      <h2>⚠️ Permanently Delete Record</h2>
      <button type="button" onclick="closePermanentDeleteModal()">✕</button>
    </div>
    <div class="modal-body">
      <p id="permanentDeleteLabel">This action cannot be undone.</p>
      <div class="form-group full">
        <label for="permanentDeleteConfirmInput">Type <strong>DELETE</strong> to permanently delete this record.</label>
        <input type="text" id="permanentDeleteConfirmInput" autocomplete="off"
               oninput="onPermanentDeleteConfirmInput()" placeholder="DELETE">
      </div>
      <p id="permanentDeleteError" style="color:#c0392b;font-size:.85rem;display:none"></p>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closePermanentDeleteModal()">Cancel</button>
      <button class="btn-danger" id="permanentDeleteConfirmBtn" disabled onclick="confirmPermanentDelete()">Permanently Delete</button>
    </div>
  </div>
</div>
