<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
?>
<div class="modal-overlay" id="studentModal">
  <div class="modal-card">

    <div class="modal-header">
      <h2 id="modalTitle">Add Student</h2>
      <button onclick="closeStudentModal()">✕</button>
    </div>

    <div class="modal-body">
      <div class="form-grid">

        <div class="form-group">
          <label>Student ID <span style="color:#c0392b">*</span></label>
          <input type="text" id="stu-code" placeholder="e.g. 202312345">
        </div>

        <div class="form-group">
          <label>First Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="stu-first-name" placeholder="e.g. Juan" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Middle Name</label>
          <input type="text" id="stu-middle-name" placeholder="e.g. Santos" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Last Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="stu-last-name" placeholder="e.g. Dela Cruz" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Suffix</label>
          <input type="text" id="stu-suffix" placeholder="e.g. Jr., III">
        </div>

        <div class="form-group">
          <label>Sex <span style="color:#c0392b">*</span></label>
          <select id="stu-sex">
            <option value="">-- Select --</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
          </select>
        </div>

        <div class="form-group">
          <label>Birth Date <span style="color:#c0392b">*</span></label>
          <input type="date" id="stu-birthdate">
        </div>

        <div class="form-group">
          <label>Grade Level <span style="color:#c0392b">*</span></label>
          <select id="stu-grade-level">
            <option value="">-- Select --</option>
            <option value="Daycare">Daycare</option>
            <option value="Kinder">Kinder</option>
            <option value="Grade 1">Grade 1</option>
            <option value="Grade 2">Grade 2</option>
            <option value="Grade 3">Grade 3</option>
            <option value="Grade 4">Grade 4</option>
            <option value="Grade 5">Grade 5</option>
            <option value="Grade 6">Grade 6</option>
            <option value="Grade 7">Grade 7</option>
            <option value="Grade 8">Grade 8</option>
            <option value="Grade 9">Grade 9</option>
            <option value="Grade 10">Grade 10</option>
            <option value="Grade 11">Grade 11</option>
            <option value="Grade 12">Grade 12</option>
          
          </select>
        </div>

        <div class="form-group">
          <label>Section <span style="color:#c0392b">*</span></label>
          <input type="text" id="stu-section" placeholder="e.g. Section A" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group full">
          <label>Address <span style="color:#c0392b">*</span></label>
          <input type="text" id="stu-address" placeholder="e.g. 123 Sampaguita St., General Trias, Cavite" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Guardian Name</label>
          <input type="text" id="stu-guardian-name" placeholder="e.g. Maria Dela Cruz" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Guardian Contact Number</label>
          <input type="text" id="stu-guardian-contact" placeholder="e.g. 09171234567">
        </div>

        <div class="form-group">
          <label>Emergency Contact Name</label>
          <input type="text" id="stu-emergency-name" placeholder="e.g. Pedro Santos" oninput="this.value = capitalizeWords(this.value)">
        </div>

        <div class="form-group">
          <label>Emergency Contact Number</label>
          <input type="text" id="stu-emergency-number" placeholder="e.g. 09171234567">
        </div>

      </div>
    </div>

    <div class="modal-footer">
      <button class="btn-light" onclick="closeStudentModal()">Cancel</button>
      <button class="btn-primary" onclick="saveStudent()">Save</button>
    </div>

  </div>
</div>

<div class="modal-overlay" id="visitModal">
    <div class="modal-card modal-card-wide">
        <div class="modal-header">
            <h2 id="visitModalTitle">Record Clinic Visit</h2>
            <button onclick="closeVisitModal()">✕</button>
        </div>

        <div class="modal-body">
            <div class="form-grid">
                <input type="hidden" id="visit-id">
                <div class="form-group full">
                    <label>Student ID or Name <span style="color:#c0392b">*</span></label>
                    <input type="text" id="visit-student-search" placeholder="Type ID or Name..." autocomplete="off">
                    <input type="hidden" id="visit-student-id">
                </div>
                <div class="form-group full">
                    <label>Symptoms / Chief Complaint</label>
                    <input type="text" id="visit-symptoms" placeholder="e.g. Headache, Fever, Stomachache..." oninput="applyLiveSentenceCap(this)">
                </div>
                <div class="form-group">
                    <label>Date &amp; Time <span style="color:#c0392b">*</span></label>
                    <input type="datetime-local" id="visit-date">
                </div>
                <div class="form-group">
                    <label>Clinic Staff</label>
                    <input type="text" id="visit-clinic-staff-display" readonly>
                </div>
                <div class="form-group">
                    <label>Status <span style="color:#c0392b">*</span></label>
                    <select id="visit-status" onchange="handleVisitStatusChange()">
                        <option value="">-- Select --</option>
                        <option value="Treated">Treated</option>
                        <option value="Referred">Referred</option>
                        <option value="For Monitoring">For Monitoring</option>
                        <option value="Sent Home">Sent Home</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assessment <span style="color:#c0392b">*</span></label>
                    <select id="visit-assessment">
                        <option value="">-- Select --</option>
                        <option value="Mild">Mild</option>
                        <option value="Moderate">Moderate</option>
                        <option value="Severe">Severe</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Findings / Observation</label>
                    <textarea id="visit-findings" placeholder="Clinic staff's observations, vitals, etc..." oninput="applyLiveSentenceCap(this)"></textarea>
                </div>
                <div class="form-group full">
                    <label>Treatment Given</label>
                    <textarea id="visit-treatment" placeholder="e.g. Paracetamol 500mg given, advised rest" oninput="applyLiveSentenceCap(this)"></textarea>
                </div>

                <div class="form-group full">
                    <label>Medicines Dispensed</label>
                    <div id="visit-dispense-rows"></div>
                    <button type="button" class="btn-light" onclick="addDispenseRow()" style="margin-top:6px">+ Add Medicine</button>
                </div>

                <div class="form-group full">
                    <label>Remarks</label>
                    <textarea id="visit-remarks" placeholder="Additional remarks..." oninput="applyLiveSentenceCap(this)"></textarea>
                </div>

                <div class="form-group">
                    <label>Referral Required?</label>
                    <select id="visit-referral-required" onchange="toggleReferralFields()">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
                <div class="form-group"></div>

                <div id="visit-referral-fields" style="display:none" class="form-group full">
                    <div class="form-grid" style="padding:0">
                        <div class="form-group">
                            <label>Referral Destination <span style="color:#c0392b">*</span></label>
                            <select id="visit-referral-destination">
                                <option value="">-- Select --</option>
                                <option value="Dentist">Dentist</option>
                                <option value="Hospital">Hospital</option>
                                <option value="Psychologist">Psychologist</option>
                                <option value="Social Worker">Social Worker</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-group full">
                            <label>Referral Reason <span style="color:#c0392b">*</span></label>
                            <textarea id="visit-referral-reason" placeholder="Reason for referral..." oninput="applyLiveSentenceCap(this)"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-light" onclick="closeVisitModal()">Cancel</button>
            <button class="btn-primary" onclick="saveVisit()">Save Visit</button>
        </div>
    </div>
</div>

<div class="modal-overlay" id="treatmentModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2>Treatment History Detail</h2>
      <button onclick="closeTreatmentModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid" id="treatment-view-fields">
        <!-- populated read-only by viewTreatment() in admin.js -->
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeTreatmentModal()">Close</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="medicineModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="medicineModalTitle">Add Medicine</h2>
      <button onclick="closeMedicineModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <input type="hidden" id="med-id">
        <div class="form-group full">
          <label>Medicine Name <span style="color:#c0392b">*</span></label>
          <input type="text" id="med-name" placeholder="e.g. Paracetamol 500mg" oninput="this.value = capitalizeWords(this.value)">
        </div>
        <div class="form-group">
          <label>Quantity <span style="color:#c0392b">*</span></label>
          <input type="number" id="med-quantity" placeholder="0" min="0">
        </div>
        <div class="form-group">
          <label>Unit <span style="color:#c0392b">*</span></label>
          <select id="med-unit">
            <option value="">-- Select --</option>
            <option value="tablets">Tablets</option>
            <option value="capsules">Capsules</option>
            <option value="bottles">Bottles</option>
            <option value="pieces">Pieces</option>
            <option value="sachets">Sachets</option>
            <option value="ampules">Ampules</option>
            <option value="vials">Vials</option>
          </select>
        </div>
        <div class="form-group full">
          <label>Expiry Date <span style="color:#c0392b">*</span></label>
          <input type="date" id="med-expiry" required>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeMedicineModal()">Cancel</button>
      <button class="btn-primary" onclick="saveMedicine()">Save</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="studentProfileModal">
  <div class="modal-card">
    <div class="modal-header">
      <div>
        <h2 id="sp-name">Student Profile</h2>
        <div id="sp-meta"></div>
      </div>
      <button onclick="closeStudentProfile()">✕</button>
    </div>
    <div class="modal-body" id="sp-body"></div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeStudentProfile()">Close</button>
    </div>
  </div>
</div>

<div class="modal-overlay" id="referralModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2>Referral History Detail</h2>
      <button onclick="closeReferralModal()">&#x2715;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid" id="referral-view-fields">
        <!-- populated read-only by viewReferral() in admin.js -->
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeReferralModal()">Close</button>
      <button class="btn-primary" onclick="openReferralLetterModal()">Generate Referral Letter</button>
    </div>
  </div>
</div>

<!-- STEP 6 — Parent/Guardian Documents. One shared modal for both
     document types; which fields show is toggled in JS by document type.
     Content is generated entirely from data already loaded by the
     entry point (Student 360 / Referral view) — no confidential notes
     are auto-filled; the Purpose/Summary fields are always editable and
     only ever pre-filled from already-non-confidential source fields
     (referral reason, counseling concern/type — never notes, action
     plan, recommendations, or outcome). -->
<div class="modal-overlay" id="documentModal">
  <div class="modal-card">
    <div class="modal-header">
      <h2 id="documentModalTitle">Generate Document</h2>
      <button onclick="closeDocumentModal()">&#x2715;</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="form-group full">
          <label>Student</label>
          <input type="text" id="doc-student-display" readonly>
        </div>

        <!-- Parent/Guardian Conference Notice ONLY. A Referral Letter is
             addressed to the receiving destination/professional, not the
             parent/guardian — these fields must not apply there. -->
        <div id="doc-guardian-fields" style="display:contents">
          <div class="form-group">
            <label>Parent/Guardian Name <span style="color:#c0392b">*</span></label>
            <input type="text" id="doc-guardian-name" placeholder="e.g. Maria Dela Cruz" oninput="this.value = capitalizeWords(this.value)">
          </div>
          <div class="form-group">
            <label>Relationship to Student</label>
            <select id="doc-guardian-relationship">
              <option value="">-- Select --</option>
              <option value="Mother">Mother</option>
              <option value="Father">Father</option>
              <option value="Legal Guardian">Legal Guardian</option>
              <option value="Grandparent">Grandparent</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="form-group full">
            <label>Contact Number</label>
            <input type="text" id="doc-guardian-contact" placeholder="e.g. 09171234567">
          </div>
        </div>

        <!-- Conference Notice only -->
        <div class="form-group full" id="doc-conference-fields" style="display:none">
          <div class="form-grid" style="padding:0">
            <div class="form-group">
              <label>Purpose</label>
              <select id="doc-purpose">
                <option value="Behavioral Concern">Behavioral Concern</option>
                <option value="Disciplinary Concern">Disciplinary Concern</option>
                <option value="Counseling Follow-up">Counseling Follow-up</option>
                <option value="Academic Concern">Academic Concern</option>
                <option value="Attendance Concern">Attendance Concern</option>
                <option value="Parent/Guardian Conference">Parent/Guardian Conference</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="form-group">
              <label>Conference Date <span style="color:#c0392b">*</span></label>
              <input type="date" id="doc-conference-date">
            </div>
            <div class="form-group">
              <label>Conference Time <span style="color:#c0392b">*</span></label>
              <input type="time" id="doc-conference-time">
            </div>
            <div class="form-group">
              <label>Venue</label>
              <input type="text" id="doc-venue" value="Guidance Office">
            </div>
          </div>
        </div>

        <!-- Referral Letter only -->
        <div class="form-group full" id="doc-referral-fields" style="display:none">
          <div class="form-grid" style="padding:0">
            <div class="form-group">
              <label>Referred To</label>
              <input type="text" id="doc-referral-destination" readonly>
            </div>
            <div class="form-group">
              <label>Referral Date</label>
              <input type="text" id="doc-referral-date" readonly>
            </div>
            <div class="form-group">
              <label>Referring Staff / Office</label>
              <input type="text" id="doc-referring-staff" readonly>
            </div>
            <div class="form-group full">
              <label>Requested Service / Intervention</label>
              <textarea id="doc-requested-service" placeholder="e.g. Evaluation and management" oninput="applyLiveSentenceCap(this)"></textarea>
            </div>
          </div>
        </div>

        <div class="form-group full">
          <label id="doc-summary-label">Purpose / Concern Summary <span style="color:#c0392b">*</span></label>
          <textarea id="doc-summary" placeholder="Reviewed and editable before printing — only this text appears on the document." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
        <div class="form-group full">
          <label>Requested Action / Next Step</label>
          <textarea id="doc-action" placeholder="What the parent/guardian is being asked to do..." oninput="applyLiveSentenceCap(this)"></textarea>
        </div>
      </div>
      <p style="color:#6b7280;font-size:12px;margin-top:.5rem">Only the fields above will appear on the printed document. Internal notes, case files, and other confidential records are never included automatically.</p>
    </div>
    <div class="modal-footer">
      <button class="btn-light" onclick="closeDocumentModal()">Cancel</button>
      <button class="btn-primary" onclick="generateDocument()"> Preview &amp; Print</button>
    </div>
  </div>
</div>
