<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-clinic-visits">
    <div class="page-header">
        <div>
            <h2>Clinic Visit Logs</h2>
            <p>Record and track daily clinic visits.</p>
        </div>
        <button class="btn-primary" onclick="openAddVisit()">+ Record Visit</button>
    </div>

    <div class="toolbar">
        <div class="search-wrap">
        <input type="text" id="search-visits" placeholder="Search by name or ID..." oninput="filterVisits()">
      </div>
        <select id="filter-status" onchange="filterVisits()">
            <option value="">All Status</option>
            <option value="Treated">Treated</option>
            <option value="Referred">Referred</option>
            <option value="For Monitoring">For Monitoring</option>
            <option value="Sent Home">Sent Home</option>
            <option value="Emergency">Emergency</option>
        </select>
    </div>

    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Student</th>
                <th>Symptoms</th>
                <th>Findings</th>
                <th>Treatment Given</th>
                <th>Assessment</th>
                <th>Status</th>
                <th>Referral</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody id="tbody-visits"></tbody>
    </table>
    </div>
</div>
