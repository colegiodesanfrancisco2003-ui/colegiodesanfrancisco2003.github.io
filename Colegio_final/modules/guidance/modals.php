<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="modal-overlay" id="counselingModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="counselingModalTitle">Add Counseling Session</h2>
      <button onclick="closeCounselingModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">

        <!-- ── Student Search ── -->
        <div class="form-group full">
          <label>Student ID or Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="counseling-student-search" placeholder="Type ID or Name..." autocomplete="off">
          <input type="hidden" id="counseling-student-id">
        </div>

        <!-- ── Optional links back to what prompted this session ── -->
        <div class="form-group">
          <label>Linked Behavioral Record (Optional)</label>
          <select id="counseling-behavioral-id">
            <option value="">-- None --</option>
          </select>
        </div>

        <div class="form-group">
          <label>Linked Offense / Violation (Optional)</label>
          <select id="counseling-offense-id">
            <option value="">-- None --</option>
          </select>
        </div>

        <div class="form-group">
          <label>Counselor</label>
          <input type="text" id="counseling-counselor-display" readonly>
        </div>

        <div class="form-group">
          <label>Referred By (Teacher/Adviser)</label>
          <input type="text" id="counseling-referred-by" placeholder="e.g. Mr. Santos" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group" id="counseling-status-group" style="display:none">
          <label>Status</label>
          <input type="text" id="counseling-status-display" readonly>
          <small style="color:#888">New sessions start as Scheduled. Use "▶ Start Session" to begin, and "✓ Done" to complete.</small>
        </div>

        <div class="form-group">
          <label>Session Date <span style="color:#c0392b">*</span></label>
          <input type="date" id="counseling-date">
        </div>

        <!-- Source identifies where the counseling case originated. -->
        <div class="form-group">
          <label>Source <span style="color:#c0392b">*</span></label>
          <select id="counseling-source" onchange="toggleOtherSourceField()">
            <option value="">-- Select --</option>
            <option value="Behavioral Concern">Behavioral Concern</option>
            <option value="Behavioral Record">Behavioral Record</option>
            <option value="Offense / Violation">Offense / Violation</option>
            <option value="Teacher Referral">Teacher Referral</option>
            <option value="Parent Referral">Parent Referral</option>
            <option value="Self Referral">Self Referral</option>
            <option value="Academic Concern">Academic Concern</option>
            <option value="Personal Concern">Personal Concern</option>
            <option value="Other Guidance Concerns">Other Guidance Concerns</option>
          </select>
        </div>

        <!-- Shown only when Source = "Other Guidance Concerns" -->
        <div class="form-group full" id="counseling-other-source-wrap" style="display:none">
          <label>Other Source <span style="color:#c0392b">*</span></label>
          <input type="text" id="counseling-other-source" placeholder="Describe where this came from..." oninput="applyLiveSentenceCap(this)">
        </div>

        <!-- Concern describes the student's actual issue — kept as free text,
             separate from Source. -->
        <div class="form-group full">
          <label>Concern</label>
          <textarea id="counseling-concern" placeholder="e.g. Frequent absenteeism, Bullying incident, Family problems..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>

        <!-- Session Notes intentionally removed from this Create/Edit
             form — Session Notes describe what actually happened during
             the counseling session, and a session may not have happened
             yet at creation/edit time. Documentation (Session Notes,
             Action Taken, Recommendations, Outcome) is captured only in
             the Complete Counseling Session modal below, once the
             session is actually conducted. -->

        <!-- ── Timeline (existing sessions only) ──
             Case history built from this session's own Audit Trail
             entries — Session Created, Started, Completed,
             Case Closed, etc. Helps counselors quickly understand the
             case history at a glance. -->
        <div class="form-group full" id="counseling-timeline-wrap" style="display:none">
          <label>Timeline</label>
          <div id="counseling-timeline" class="timeline" style="max-height:200px;overflow-y:auto"></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeCounselingModal()">Cancel</button>
      <button class="btn-primary" id="counseling-save-btn" onclick="saveCounseling()">Save</button>
    </div>
  </div>
</div>


<div class="modal-overlay" id="completeCounselingModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2>Complete Counseling Session</h2>
      <button onclick="closeCompleteCounselingModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Session Notes <span style="color:#888;font-weight:normal">(Optional)</span></label>
          <textarea id="complete-cs-notes" placeholder="What was discussed in this session..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
        <div class="form-group full">
          <label>Action Taken <span style="color:#888;font-weight:normal">(Optional)</span></label>
          <textarea id="complete-cs-action-plan" placeholder="What was done during/after this session..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
        <div class="form-group full">
          <label>Recommendations <span style="color:#888;font-weight:normal">(Optional)</span></label>
          <textarea id="complete-cs-recommendations" placeholder="Recommended next steps for the student, parents, or teachers..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
        <div class="form-group full">
          <label>Outcome <span style="color:#888;font-weight:normal">(Optional)</span></label>
          <textarea id="complete-cs-outcome" placeholder="Result of the session..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeCompleteCounselingModal()">Cancel</button>
      <button class="btn-primary" onclick="submitCompleteCounseling()">✓ Done</button>
    </div>
  </div>
</div>



<!-- ── Counseling Session Details (read-only) ──
     Filled by openCounselingDetails() in admin.js from a fresh
     GET api/counseling.php?id=<session id> every time it opens. Nothing
     in here is editable — the Edit / Complete modals stay separate. -->
<div class="modal-overlay" id="counselingDetailsModal">
  <div class="modal-card modal-card-wide">
    <div class="modal-header">
      <h2>Counseling Session Details</h2>
      <button type="button" onclick="closeCounselingDetails()" aria-label="Close">&#x2715;</button>
    </div>
    <div class="modal-body" id="csd-body"></div>
    <div class="modal-footer">
      <button type="button" class="btn-light" onclick="closeCounselingDetails()">Close</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="behavioralModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="behavioralModalTitle">Add Behavioral Record</h2>
      <button onclick="closeBehavioralModal()">&#x2715;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Student <span style="color:#c0392b">*</span></label>
          <input type="text" id="beh-student-search" placeholder="Type ID or Name..." autocomplete="off">
          <input type="hidden" id="beh-student-id">
        </div>
        <div class="form-group full">
          <label>Behavior Observed <span style="color:#c0392b">*</span></label>
          <textarea id="beh-behavior" placeholder="Describe the behavior..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
        <div class="form-group full">
          <label>Date</label>
          <input type="date" id="beh-date">
        </div>
        <div class="form-group full" id="beh-status-wrap" style="display:none">
          <label>Status</label>
          <select id="beh-status">
            <option value="Open">Open — needs attention</option>
            <option value="Addressed">Addressed</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeBehavioralModal()">Cancel</button>
      <button class="btn-primary" onclick="saveBehavioral()">Save</button>
    </div>
  </div>
</div>


<div class="modal-overlay" id="offenseModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="offenseModalTitle">Record Offense / Violation</h2>
      <button onclick="closeOffenseModal()">&#x2715;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group">
          <label>Date</label>
          <input type="date" id="off-date">
        </div>
        <div class="form-group full">
          <label>Student Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="off-student-search" placeholder="Type ID or Name..." autocomplete="off">
          <input type="hidden" id="off-student-id">
        </div>
        <div class="form-group full">
          <label>Offense Category <span style="color:#c0392b">*</span></label>
          <select id="off-category-id" onchange="onOffenseCategoryChange()">
            <option value="">-- Select a category --</option>
          </select>
        </div>
        <!-- Live preview of the picked category's Severity + Default
             Sanction — read-only context, not separate form fields, so
             staff never re-type something the masterlist already knows.
             See onOffenseCategoryChange() in admin.js. -->
        <div class="form-group full" id="off-category-preview" style="display:none">
          <div class="stock-note info-note" id="off-category-preview-text"></div>
        </div>
        <div class="form-group full">
          <label>Description</label>
          <input type="text" id="off-description" placeholder="e.g. No ID worn" oninput="applyLiveSentenceCap(this)">
        </div>
        <div class="form-group full" id="off-status-wrap" style="display:none">
          <label>Status</label>
          <select id="off-status">
            <option value="Warned">Warned</option>
            <option value="Notice Required">Notice Required</option>
            <option value="Resolved">Resolved</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeOffenseModal()">Cancel</button>
      <button class="btn-primary" id="offenseSaveBtn" onclick="saveOffense()">Record</button>
    </div>
  </div>
</div>

