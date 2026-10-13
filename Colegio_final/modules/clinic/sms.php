<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
    <div class="page" id="page-sms">
      <div class="page-header">
        <div>
          <h2>SMS Notifications</h2>
          <p>Configure the SMS gateway used for parent/guardian notifications, and review sent message history.</p>
        </div>
      </div>

      <div class="card" style="max-width:560px">
        <div class="card-header"><span class="card-title">Gateway Configuration</span></div>
        <div class="form-grid" style="padding-top:.5rem">
          <div class="form-group full check-row">
            <input type="checkbox" id="sms-enabled">
            <label for="sms-enabled">Enable SMS Notifications</label>
          </div>
          <div class="form-group full">
            <label>SMS Gateway Provider</label>
            <select id="sms-provider">
              <option value="">Not configured</option>
              <option value="semaphore">Semaphore</option>
              <option value="twilio">Twilio</option>
              <option value="custom">Custom / Other</option>
            </select>
          </div>
          <div class="form-group full">
            <label>Sender Name</label>
            <input type="text" id="sms-sender-name" placeholder="e.g. CSF Clinic">
          </div>
          <div class="form-group full">
            <label>API Key / Token</label>
            <input type="password" id="sms-api-key" placeholder="Enter provider API key" autocomplete="off">
            <p style="font-size:.75rem;color:var(--muted);margin-top:4px">
              Stored server-side only, never shown again after saving. Actually sending messages requires an active account with your chosen provider; this screen only stores the connection details.
            </p>
          </div>
        </div>
        <div class="modal-footer" style="padding:1rem 0 0;border:none;background:transparent" id="sms-gateway-footer">
          <button class="btn-primary" onclick="saveSmsSettings()">Save Settings</button>
        </div>
      </div>

      <div class="card" style="max-width:560px">
        <div class="card-header"><span class="card-title">Automatic SMS Settings</span></div>
        <div class="form-grid" style="padding-top:.5rem">
          <div class="form-group full check-row">
            <input type="checkbox" id="sms-notify-referral">
            <label for="sms-notify-referral">Automatically notify guardians for referrals</label>
          </div>
          <div class="form-group full check-row">
            <input type="checkbox" id="sms-notify-sent-home">
            <label for="sms-notify-sent-home">Automatically notify guardians when students are sent home</label>
          </div>
          <div class="form-group full check-row">
            <input type="checkbox" id="sms-notify-emergency">
            <label for="sms-notify-emergency">Automatically notify guardians for emergency cases</label>
          </div>
          <p class="check-note">
            If a specific automatic notification is turned off, Clinic Staff can still send that guardian an SMS manually from the Clinic Visit page.
          </p>
        </div>
        <div class="modal-footer" style="padding:1rem 0 0;border:none;background:transparent" id="sms-toggles-footer">
          <button class="btn-primary" onclick="saveSmsSettings()">Save Settings</button>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <span class="card-title">Message History</span>
        </div>
        <div class="toolbar">
          <div class="search-wrap">
            <input type="text" id="search-sms-logs" placeholder="Search by student, guardian, or contact..." oninput="filterSmsLogs()">
          </div>
          <select id="filter-sms-status" onchange="filterSmsLogs()">
            <option value="">All Status</option>
            <option value="Sent">Sent</option>
            <option value="Pending">Pending</option>
            <option value="Failed">Failed</option>
          </select>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Sent</th><th>Student</th><th>Guardian</th><th>Contact Number</th>
                <th>Message Preview</th><th>Related Clinic Visit</th><th>Status</th><th>Sent By</th><th></th>
              </tr>
            </thead>
            <tbody id="tbody-sms-logs"></tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="modal-overlay" id="smsDetailsModal">
      <div class="modal-card">
        <div class="modal-header">
          <h2>SMS Details</h2>
          <button onclick="closeSmsDetailsModal()">&#x2715;</button>
        </div>
        <div class="modal-body">
          <div class="form-grid" id="sms-details-fields"></div>
        </div>
        <div class="modal-footer">
          <button class="btn-light" onclick="closeSmsDetailsModal()">Close</button>
        </div>
      </div>
    </div>
