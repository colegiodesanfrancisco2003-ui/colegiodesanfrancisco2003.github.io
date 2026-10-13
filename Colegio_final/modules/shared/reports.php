<?php
// Partial template — must only be include()d by admin.php / clinic.php / guidance.php.
// Reject a direct browser request to this file (it has no auth check of its own).
if (isset($_SERVER['SCRIPT_FILENAME']) && strcasecmp(str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'])), str_replace('\\', '/', __FILE__)) === 0) { http_response_code(403); exit('Forbidden'); }
/**
 * Shared Reports page — ONE implementation for Administrator, Clinic
 * Staff, and Guidance Staff. The clinic report panel and the guidance
 * report panel each exist exactly once; role permissions decide which
 * panel(s) render and whether the module switch tabs appear.
 */
$isAdmin      = ($role === 'Administrator');
$hasClinic    = $isAdmin || $role === 'Clinic Staff';
$hasGuidance  = $isAdmin || $role === 'Guidance Staff';
$guidanceOnly = ($role === 'Guidance Staff');
?>
<div class="page" id="page-reports">
  <div class="page-header">
    <div>
      <h2>Reports</h2>
      <?php if ($isAdmin): ?>
      <p>Clinic activity, stock levels, behavioral analytics, and counselor performance.</p>
      <?php elseif ($hasClinic): ?>
      <p>Summary of clinic activity, stock levels, and trends.</p>
      <?php else: ?>
      <p>Behavioral analytics and counselor performance.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="report-tabs" style="display:flex;gap:.5rem;margin-bottom:1rem">
    <button class="btn-light report-tab active" id="rt-tab-clinic" onclick="switchReportModule('clinic', this)"> Clinic Reports</button>
    <button class="btn-light report-tab" id="rt-tab-guidance" onclick="switchReportModule('guidance', this)">Guidance Reports</button>
  </div>
  <?php endif; ?>

  <div class="report-toolbar">
    <label>From</label>
    <input type="date" id="report-from">
    <label>To</label>
    <input type="date" id="report-to">
    <button class="btn-primary" onclick="applyReportRange()">Apply</button>
    <button class="btn-light" onclick="resetReportRange()">This Month</button>
    <button class="btn-light" onclick="window.print()">Print</button>
  </div>

  <!-- Print-only header: hidden on screen, shown via @media print (admin.css).
       Keeps the on-screen toolbar (filters/tabs/buttons) out of the printed
       page while the printed page still carries the school name, report
       title, and the exact date range currently applied. -->
  <div class="report-print-header" id="report-print-header">
    <div class="report-print-school">Colegio de San Francisco</div>
    <div class="report-print-system">CSF Integrated System — Internal Document</div>
    <div class="report-print-title" id="report-print-title">Report</div>
    <div class="report-print-period" id="report-print-period">Report Period: —</div>
  </div>

  <?php if ($hasClinic): ?>
  <!-- ── CLINIC REPORTS PANEL ── -->
  <div id="reports-content">

    <div class="report-stats-grid">
      <div class="report-stat-card">
        <div class="rep-label">Total Clinic Visits</div>
        <div class="rep-value" id="rep-total-visits">0</div>
        <div class="rep-sub">within selected range</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Most Common Medicine</div>
        <div class="rep-value rep-value-text" id="rep-top-medicine">—</div>
        <div class="rep-sub" id="rep-top-medicine-sub">No data for this range.</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Most Common Concern</div>
        <div class="rep-value rep-value-text" id="rep-top-symptom">—</div>
        <div class="rep-sub" id="rep-top-symptom-sub">No data for this range.</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Most Common Grade Level</div>
        <div class="rep-value rep-value-text" id="rep-top-grade">—</div>
        <div class="rep-sub" id="rep-top-grade-sub">No data for this range.</div>
      </div>
    </div>

    <div class="report-grid-2">
      <div class="card">
        <div class="card-header"><span class="card-title">Top Medicines Given</span></div>
        <div id="rep-medicines-list"></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Top Concerns / Symptoms</span></div>
        <div id="rep-symptoms-list"></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Top Grade Levels Visiting</span></div>
        <div id="rep-grades-list"></div>
      </div>
    </div>

    <div class="report-grid-2">
      <div class="card">
        <div class="card-header"><span class="card-title">Medicine Usage Trend (Last 6 Months)</span></div>
        <div id="rep-medicine-trend-chart" class="chart-bars"></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Top Medicines by Quantity Dispensed</span></div>
        <div id="rep-medicine-qty-list"></div>
      </div>
      <div class="card">
        <div class="card-header">
          <span class="card-title">Stock Depletion Estimate</span>
          <span class="badge" id="rep-depletion-count">0</span>
        </div>
        <div id="rep-depletion-list"></div>
      </div>
    </div>

    <div class="report-grid-2">
      <div class="card">
        <div class="card-header">
          <span class="card-title">Low Stock Medicines</span>
          <span class="badge" id="rep-low-stock-count">0</span>
        </div>
        <div id="rep-low-stock-list"></div>
      </div>
      <div class="card">
        <div class="card-header">
          <span class="card-title">Out of Stock Medicines</span>
          <span class="badge" id="rep-out-stock-count">0</span>
        </div>
        <div id="rep-out-stock-list"></div>
      </div>
    </div>

  </div>
  <?php endif; ?>

  <?php if ($hasGuidance): ?>
  <!-- ── GUIDANCE REPORTS PANEL ──
       Administrator: second tab, hidden until chosen, id="guidance-reports-content".
       Guidance Staff: this IS the reports page, so it reuses id="reports-content"
       (matches the pre-refactor guidance.php exactly — no tabs, always visible). -->
  <div id="<?php echo $guidanceOnly ? 'reports-content' : 'guidance-reports-content'; ?>"<?php echo $guidanceOnly ? '' : ' style="display:none"'; ?>>

    <div class="report-stats-grid">
      <div class="report-stat-card">
        <div class="rep-label">Counseling Sessions</div>
        <div class="rep-value" id="gr-total-sessions">0</div>
        <div class="rep-sub">within selected range</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Behavioral Records</div>
        <div class="rep-value" id="gr-total-behavioral">0</div>
        <div class="rep-sub">within selected range</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Offenses Recorded</div>
        <div class="rep-value" id="gr-total-offenses">0</div>
        <div class="rep-sub">within selected range</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Cases Closed</div>
        <div class="rep-value" id="gr-total-closed">0</div>
        <div class="rep-sub">within selected range</div>
      </div>
      <div class="report-stat-card">
        <div class="rep-label">Most Common Offense</div>
        <div class="rep-value rep-value-text" id="gr-top-offense">—</div>
        <div class="rep-sub" id="gr-top-offense-sub">No data for this range.</div>
      </div>
    </div>

    <div class="report-grid-2">
      <div class="card">
        <div class="card-header"><span class="card-title">Offense Type Breakdown</span></div>
        <div id="gr-offense-list"></div>
      </div>
      <div class="card">
        <div class="card-header"><span class="card-title">Counselor Analytics</span></div>
        <div id="gr-counselor-list"></div>
      </div>
    </div>

    <div class="report-grid-2">
      <div class="card">
        <div class="card-header"><span class="card-title">Most Active Cases</span></div>
        <div id="gr-cases-list"></div>
      </div>
    </div>

  </div>
  <?php endif; ?>

  <!-- Print-only signature + footer (hidden on screen, shown via @media print). -->
  <div class="report-print-footer" id="report-print-footer">
    <div class="report-print-sig"><div class="report-print-sig-line"><span id="report-print-prepared">Prepared By</span><br>Prepared By</div></div>
    <div class="report-print-generated" id="report-print-generated"></div>
  </div>
</div>
