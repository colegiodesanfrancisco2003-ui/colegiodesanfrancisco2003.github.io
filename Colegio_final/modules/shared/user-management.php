<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="page" id="page-users">
    <div class="page-header">
        <h2>User Management</h2>
        <button class="btn-primary" onclick="openAddUser()">+ Add User</button>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
            <input type="text" id="search-users" placeholder="Search by name or username..." oninput="filterUsers()">
        </div>
        <select id="filter-role" onchange="filterUsers()">
            <option value="">All Roles</option>
            <option value="Clinic Staff">Clinic Staff</option>
            <option value="Guidance Staff">Guidance Staff</option>
        </select>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Full Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="tbody-users"></tbody>
    </table>
    </div>
</div>

<div class="modal-overlay" id="userModal">
    <div class="modal-card">
        <div class="modal-header">
            <h2 id="userModalTitle">Add User</h2>
            <button onclick="closeUserModal()">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid">
                <input type="hidden" id="user-id">

                <div class="form-group">
                    <label>Full Name <span style="color:#c0392b">*</span></label>
                    <input type="text" id="user-name" placeholder="e.g. Juan Dela Cruz" oninput="this.value = capitalizeWords(this.value)">
                </div>

                <div class="form-group">
                    <label>Username <span style="color:#c0392b">*</span></label>
                    <input type="text" id="user-username" placeholder="e.g. jdelacruz">
                </div>

                <div class="form-group">
                    <label>Role <span style="color:#c0392b">*</span></label>
                    <select id="user-role">
                        <option value="">-- Select --</option>
                        <option value="Clinic Staff">Clinic Staff</option>
                        <option value="Guidance Staff">Guidance Staff</option>
                    </select>
                </div>

                <div class="form-group">
                    <label id="user-pass-label">Password</label>
                    <input type="password" id="user-password" placeholder="Enter password">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-light" onclick="closeUserModal()">Cancel</button>
            <button class="btn-primary" onclick="saveUser()">Save</button>
        </div>
    </div>
</div>
