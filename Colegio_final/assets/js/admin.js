// "N/A" is what the server stores for optional fields left blank. Forms
// and prefills treat it as "no value" so the placeholder never ends up
// in an input box or gets validated as a phone number.
function _naToBlank(v) {
  const t = (v ?? "").toString().trim();
  return /^(n\/?a)$/i.test(t) ? "" : t;
}

// ── Shared A4 print window ──────────────────────────────────────
// Every popup print (referral record, SMS record/history, Parent/Guardian
// documents) goes through this so they all print on A4 portrait with the
// same margins, and only print AFTER the web font has loaded (otherwise
// the first print can come out in a fallback font).
const CSF_PRINT_BASE_CSS = `
  @page{size:A4 portrait;margin:15mm 14mm;}
  *{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  html,body{margin:0;padding:0;background:#fff;}
  body{font-family:'DM Sans',sans-serif;color:#1a1a1a;font-size:12pt;line-height:1.5;}
  h2{margin:0 0 12px;font-size:16pt;}
  p{margin:0 0 6px;overflow-wrap:anywhere;word-break:break-word;}
  table{width:100%;border-collapse:collapse;}
  thead{display:table-header-group;}
  tr,.doc-signatures,.doc-sig{break-inside:avoid;page-break-inside:avoid;}
  body{font-size:14px;}
  p{line-height:1.6;font-size:14px;}
  /* On screen, show the page like an A4 sheet; on paper @page handles margins. */
  @media screen{body{max-width:210mm;margin:0 auto;padding:15mm 14mm;}}

  /* Official document look (same as Referral Letter / Conference Notice) */
  .doc-header{text-align:center;border-bottom:3px double #7a1f2b;padding-bottom:14px;margin-bottom:24px}
  .doc-school{font-size:20px;font-weight:bold;color:#7a1f2b;letter-spacing:.5px}
  .doc-system{font-size:12px;color:#555;margin-top:2px}
  .doc-title{font-size:16px;font-weight:bold;margin-top:14px;text-transform:uppercase;letter-spacing:1px}
  .doc-info-table{width:100%;border-collapse:collapse;margin:14px 0;font-size:13px}
  .doc-info-table td{border:1px solid #ccc;padding:6px 10px}
  .doc-info-table td:nth-child(1),.doc-info-table td:nth-child(3){font-weight:bold;background:#faf6ec;width:18%}
  .doc-info-table td[colspan]{width:auto !important;background:#fff !important;font-weight:normal !important}
  .doc-signatures{display:flex;justify-content:space-between;margin-top:60px}
  .doc-sig{width:45%;text-align:center}
  .doc-sig-line{border-top:1px solid #333;margin-top:40px;padding-top:4px;font-size:12px}
  .doc-footer{margin-top:40px;font-size:11px;color:#888;text-align:center;border-top:1px solid #ddd;padding-top:10px}
`;

// Wraps a document body in the standard CSF header + footer.
function csfDocShell(title, innerHtml, signaturesHtml) {
  const today = formatDateLong(new Date().toISOString().slice(0, 10));
  return `
    <div class="doc-header">
      <div class="doc-school">Colegio de San Francisco</div>
      <div class="doc-system">CSF Integrated System — Internal Document</div>
      <div class="doc-title">${title}</div>
    </div>
    ${innerHtml}
    ${signaturesHtml || ''}
    <div class="doc-footer">Generated via CSF Integrated System on ${today}. For internal school use only.</div>`;
}

function csfMultiline(v) {
  return escapeHtml(v || 'N/A').replace(/\n/g, '<br>');
}

function csfPrintWindow(title, extraCss, bodyHtml) {
  const w = window.open('', '_blank');
  if (!w) { showNotif('⚠️ Please allow pop-ups to print.', 'warn'); return; }
  w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8">
    <title>${title}</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <style>${CSF_PRINT_BASE_CSS}${extraCss || ''}</style>
    </head><body>${bodyHtml}</body></html>`);
  w.document.close();
  let printed = false;
  const go = () => {
    if (printed) return; printed = true;
    const fire = () => setTimeout(() => { w.focus(); w.print(); }, 150);
    try { (w.document.fonts && w.document.fonts.ready ? w.document.fonts.ready : Promise.resolve()).then(fire, fire); }
    catch (e) { fire(); }
  };
  if (w.document.readyState === 'complete') go(); else w.addEventListener('load', go);
  setTimeout(go, 2500); // offline / slow font host: print anyway
}

// ── CSRF protection + global notification sync ──────────────────
// window.CSRF_TOKEN is embedded server-side (see admin.php / clinic.php /
// guidance.php) via csrf_token(). Every POST/PUT/PATCH/DELETE call in this
// file uses the plain fetch() below, so we wrap it once here instead of
// touching every individual call site — same-origin requests only.
//
// That same chokepoint also fixes a real bug: saving a visit, a user, a
// counseling session, a behavioral record, an offense, sending an SMS,
// or archiving/restoring almost anything can all cause the backend to
// upsert or resolve a notification (see notify_upsert / notify_resolve_missing
// / notify_reconcile_medicines in includes/notify.php) — but only a
// handful of save functions (saveMedicine, deleteMedicine, the inventory
// restore path) ever called refreshNotifBadge() to pull
// that into the bell right away. Every other action silently relied on
// the 60-second poll (see setInterval(refreshNotifBadge, ...) below),
// which reads as "stale until I manually refresh the page" — because for
// most of a minute, it is. Rather than hunt down and hand-add
// refreshNotifBadge() to every current and future save/delete/restore
// function (the exact pattern that caused the gap), any successful,
// non-GET request to this app's own api/*.php now triggers one right
// here — reusing the SAME api/notifications.php GET, the SAME
// _notifCache, and the SAME renderNotifPanelBody()/updateNotifBadge()
// rendering path that loadNotifications() and the 60s poll already use.
// Nothing new is created; the 60s poll stays as a backstop for tabs left
// idle with nothing happening in them.
//
// The Dashboard had the exact same gap the bell used to have: its KPI
// cards only refreshed on navigating TO the page, plus a few hand-picked
// counseling actions (start/reopen) that happened to call
// loadDashboardStats() directly. Any other action (a new offense, a new
// clinic visit, a medicine restock, another admin's edit, etc.) left the
// cards stale until the user left the page and came back. Same fix,
// same chokepoint: if the Dashboard is the page currently on screen,
// refresh it right alongside the bell.
(function () {
    const _fetch = window.fetch;
    const guarded = ['POST', 'PUT', 'PATCH', 'DELETE'];
    window.fetch = function (input, init) {
        init = init || {};
        const method = (init.method || 'GET').toUpperCase();
        if (guarded.includes(method) && typeof window.CSRF_TOKEN !== 'undefined') {
            init.headers = Object.assign({}, init.headers, { 'X-CSRF-Token': window.CSRF_TOKEN });
        }
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        const isOwnApiCall = /(^|\/)api\/[^/?]+\.php(\?|$)/.test(url);
        const result = _fetch(input, init);
        if (guarded.includes(method) && isOwnApiCall) {
            result.then((res) => {
                if (res && res.ok && typeof refreshNotifBadge === 'function') refreshNotifBadge();
                if (res && res.ok && typeof loadDashboardStats === 'function'
                    && document.getElementById('page-dashboard')?.classList.contains('active')) {
                    loadDashboardStats();
                }
            }).catch(() => { /* request failed — nothing changed server-side, nothing to sync */ });
        }
        return result;
    };
})();

// Dashboard backstop poll — same idea and same 60s cadence as
// setInterval(refreshNotifBadge, ...) below: the fetch-wrapper refresh
// above only fires for actions taken in THIS tab, so another user's
// change elsewhere (or a status that flips purely from the passage of
// time, e.g. Scheduled -> Overdue) would otherwise sit stale until the
// page is revisited. Only polls while the Dashboard is actually the
// page on screen, so it's a no-op everywhere else.
setInterval(() => {
    if (typeof loadDashboardStats === 'function'
        && document.getElementById('page-dashboard')?.classList.contains('active')) {
        loadDashboardStats();
    }
}, 60 * 1000);


const API = "api/students.php";
let editingId = null;
let originalData = {};
let editingUserData = {};


let allStudents = [];

function capitalizeWords(str) {
  if (!str) return '';
  return str.replace(/(^\w|\s\w)/g, m => m.toUpperCase());
}

// ── Sentence-style auto-capitalization ──────────────────────────────
// For free-text fields (concerns, notes, remarks, descriptions, etc.)
// where every-word Title Case reads wrong. Capitalizes only the first
// letter of each sentence — after the start of the field and after any
// ". ", "? ", or "! " — and leaves everything else exactly as typed, so
// acronyms (CSF, PHP, MySQL), names, and mid-sentence words the user
// already capitalized are never touched. `capitalizeWords()` above is
// unrelated and still used for actual name fields, which intentionally
// keep Title Case.
const SENTENCE_CAP_ABBREVIATIONS = [
    'mr', 'mrs', 'ms', 'dr', 'prof', 'etc', 'i.e', 'e.g', 'vs', 'jr', 'sr'
];

// True if the text ending at `periodIndex` (the position of a '.') is
// the end of a known abbreviation (e.g. "...Mr.", "...e.g.") rather
// than the end of a sentence.
function sentenceCapEndsWithAbbreviation(text, periodIndex) {
    const before = text.slice(0, periodIndex).toLowerCase();
    return SENTENCE_CAP_ABBREVIATIONS.some(abbr => before.endsWith(abbr));
}

// True if the word starting at `i` is itself a known abbreviation (e.g.
// text starting with "e.g. ..." shouldn't have its own "e" force-capped
// just because it's the first character of the field).
function sentenceCapStartsWithAbbreviation(text, i) {
    const rest = text.slice(i).toLowerCase();
    return SENTENCE_CAP_ABBREVIATIONS.some(abbr => rest.startsWith(abbr + '.'));
}

function sentenceCase(str) {
    if (!str) return str;

    let result = str;
    let capitalizeNext = true; // start of the field always counts as a sentence start
    let i = 0;

    while (i < result.length) {
        const ch = result[i];

        if (/[a-zA-Z]/.test(ch)) {
            if (capitalizeNext) {
                if (!sentenceCapStartsWithAbbreviation(result, i) && /[a-z]/.test(ch)) {
                    result = result.slice(0, i) + ch.toUpperCase() + result.slice(i + 1);
                }
                capitalizeNext = false;
            }
            i++;
            continue;
        }

        if (ch === '.' || ch === '?' || ch === '!') {
            if (ch === '.' && sentenceCapEndsWithAbbreviation(result, i)) {
                i++;
                continue; // abbreviation period — not a sentence boundary
            }
            const next = result[i + 1];
            // Only a real sentence boundary once followed by whitespace
            // (or end of field) — keeps "3.5" and similar untouched.
            if (next === undefined || /\s/.test(next)) {
                capitalizeNext = true;
            }
            i++;
            continue;
        }

        i++; // digits, whitespace, other punctuation — just advance
    }

    return result;
}

// oninput handler for sentence-case fields. Only ever changes letter
// case in place (never inserts/deletes characters), so the string
// length never changes and the cursor/selection can be restored to the
// exact same indices after the value swap — no jumping while typing.
function applyLiveSentenceCap(el) {
    const original = el.value;
    const transformed = sentenceCase(original);
    if (transformed === original) return;

    const start = el.selectionStart;
    const end   = el.selectionEnd;
    el.value = transformed;
    if (start !== null && end !== null) {
        el.setSelectionRange(start, end);
    }
}

// Formats a "YYYY-MM-DD" (or "YYYY-MM-DD HH:MM:SS") date string as
// "MM/DD/YYYY" for display. Parses the parts manually (not `new Date(str)`)
// to avoid UTC/local timezone shifting the date by a day.
// Returns '' for empty/invalid input.
function formatDateMDY(dateStr) {
  if (!dateStr) return '';
  const datePart = String(dateStr).split(' ')[0]; // strip any time component
  const parts = datePart.split('-');
  if (parts.length !== 3) return dateStr;
  const [y, m, d] = parts;
  if (!y || !m || !d) return dateStr;
  return `${m.padStart(2,'0')}/${d.padStart(2,'0')}/${y}`;
}

const _LONG_MONTHS = ['January','February','March','April','May','June','July',
                       'August','September','October','November','December'];

// Formats a "YYYY-MM-DD" (or "YYYY-MM-DD HH:MM:SS") date string as
// "August 08, 2026" — the standard readable display format used across
// the UI (tables, cards, modals, notifications, timelines). Same manual
// parsing as formatDateMDY above, for the same timezone-safety reason.
// Reports & Printing intentionally keep formatDateMDY's MM/DD/YYYY —
// this task is presentation-only for the rest of the system, not those.
// Returns '' for empty/invalid input.
function formatDateLong(dateStr) {
  if (!dateStr) return '';
  const datePart = String(dateStr).split(' ')[0];
  const parts = datePart.split('-');
  if (parts.length !== 3) return dateStr;
  const [y, m, d] = parts;
  const mi = parseInt(m, 10) - 1;
  if (!y || !d || isNaN(mi) || mi < 0 || mi > 11) return dateStr;
  return `${_LONG_MONTHS[mi]} ${d.padStart(2,'0')}, ${y}`;
}

// Formats a 24-hour "HH:MM" (from <input type="time">, e.g. "13:14") as
// "1:14 PM" for user-facing display — printed documents in particular
// must never show military time. Returns '' for empty/invalid input.
function formatTime12h(timeStr) {
  if (!timeStr) return '';
  const parts = String(timeStr).split(':');
  if (parts.length < 2) return timeStr;
  let h = parseInt(parts[0], 10);
  const m = parts[1].padStart(2, '0');
  if (isNaN(h)) return timeStr;
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12;
  if (h === 0) h = 12;
  return `${h}:${m} ${ampm}`;
}

// Returns today's date as "YYYY-MM-DD" using the BROWSER'S LOCAL calendar
// date. Do NOT use `new Date().toISOString().split('T')[0]` for this —
// toISOString() always converts to UTC first, so for any timezone ahead of
// UTC (e.g. UTC+8) it reports the *previous* calendar day for several hours
// every day (roughly local 00:00–08:00 for UTC+8). Several dashboard
// widgets compare a record's date against this to decide "is this today?",
// so that mismatch would silently miscount them for part of the day.
function getLocalDateStr(d = new Date()) {
  const year  = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day   = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Formats a "YYYY-MM-DD HH:MM:SS" datetime string as Facebook-style
// relative time ("Just now", "5m", "2h", "Yesterday", "3d ago"), falling
// back to formatDateLong's absolute "August 08, 2026" once it's more than
// a week old. Parses the parts manually and builds the Date with the
// local-time constructor (never `new Date(str)` on the raw string) for
// the same timezone-safety reason as getLocalDateStr/formatDateLong above
// — this always reflects the *unmodified* created_at, which notify_upsert
// deliberately never rewrites on re-detection, so it won't reset to
// "Just now" every time a state-based alert (e.g. Low Stock) is re-polled.
function formatRelativeTime(dateTimeStr) {
  if (!dateTimeStr) return '';
  const [datePart, timePart] = String(dateTimeStr).split(' ');
  const dp = (datePart || '').split('-');
  const tp = (timePart || '0:0:0').split(':');
  if (dp.length !== 3) return formatDateLong(dateTimeStr);
  const then = new Date(
    parseInt(dp[0], 10), parseInt(dp[1], 10) - 1, parseInt(dp[2], 10),
    parseInt(tp[0] || 0, 10), parseInt(tp[1] || 0, 10), parseInt(tp[2] || 0, 10)
  );
  if (isNaN(then.getTime())) return formatDateLong(dateTimeStr);

  const diffMs  = Date.now() - then.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  const diffHr  = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffMin < 1)  return 'Just now';
  if (diffMin < 60) return `${diffMin}m ago`;
  if (diffHr < 24)  return `${diffHr}h ago`;
  if (diffDay === 1) return 'Yesterday';
  if (diffDay < 7)  return `${diffDay}d ago`;
  return formatDateLong(dateTimeStr);
}

function openStudentModal() {
  document.getElementById("studentModal").style.display = "flex";
}

let isAcceptingStudent = false;

// Wire the "Accept" button to this instead of openEditStudent() —
// opens the same Edit modal, but flags that the save should succeed
// even with no changes (confirming the record as-is is the whole point).
async function acceptStudent(id) {
    isAcceptingStudent = true;
    await openEditStudent(id);
}

function closeStudentModal() {
  document.getElementById("studentModal").style.display = "none";
  editingId = null;
  originalData = {};
  isAcceptingStudent = false;
  clearStudentForm();
}

function openAddStudent() {
  editingId = null;
  originalData = {};
  clearStudentForm();
  document.getElementById("modalTitle").innerText = "Add Student";
  openStudentModal();
}

// Student Health Records table. When source === "Pending" (student was
// quick-registered from the Counseling modal and hasn't been reviewed
// yet by Clinic Staff): shows a "Pending" pill under the student's name,
// and ONLY an "Accept" button (no Edit) that jumps straight into the
// Edit modal so Clinic Staff can confirm/complete the record. Saving
// (PUT in api/students.php) flips source back to "Clinic", which makes
// the row show the normal "Edit" button again.
// Resolves which student-list layout to render.
//   'clinic'   -> full 9-column health-records table (Clinic Staff, and
//                 Administrator -> Clinic Module -> Student Health Records)
//   'guidance' -> trimmed 4-column table (Guidance Staff, and
//                 Administrator -> Guidance Module -> Student Records)
//   'master'   -> trimmed 4-column table whose profile shows NO
//                 clinic/guidance data (Administrator -> Student
//                 Management -> Student Records, master list only)
// An explicit mode always wins. With no explicit mode (plain clinic.php /
// guidance.php pages, which never pass one), it falls back to CSF_ROLE
// exactly as before.
function resolveStudentsMode(explicitMode) {
  if (explicitMode) return explicitMode;
  return (typeof CSF_ROLE !== 'undefined' && CSF_ROLE === 'guidance') ? 'guidance' : 'clinic';
}

// The module to scope api/students.php?profile= to, so an Administrator
// viewing a student from one module page never sees the other module's
// (or the master list's) data merged into the same profile screen.
function profileModuleForStudentsMode(mode) {
  if (mode === 'clinic')   return 'clinic';
  if (mode === 'guidance') return 'guidance';
  return ''; // master list -> neither Clinic nor Guidance data
}

function buildRows(data, explicitMode) {
  const mode = resolveStudentsMode(explicitMode);
  const isTrimmedView = (mode === 'guidance' || mode === 'master');
  const profileModule = profileModuleForStudentsMode(mode);

  if (!data.length) {
    return emptyRow(4, "No students found", "Try a different search or level, or add a new student.");
  }
  return data.map(s => {
    const isPending = s.source === 'Pending';
    const editOrAcceptBtn = isPending
      ? `<button class="btn-sm" style="border-color:#2e7d5a;color:#2e7d5a" onclick="acceptStudent(${s.id})">Accept</button>`
      : `<button class="btn-sm icon-only" onclick="openEditStudent(${s.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>`;

    if (isTrimmedView) {
      return `
      <tr>
        <td>${escapeHtml(s.student_code)}</td>
        <td>
          ${escapeHtml(s.full_name)}
          ${isPending ? '<br><span class="pill pill-yellow" style="margin-top:3px">Pending</span>' : ''}
        </td>
        <td>${escapeHtml(s.grade_section)}</td>
        <td class="actions-cell">
          ${editOrAcceptBtn}
          <button class="btn-sm icon-only" style="border-color:#2e6ea5;color:#2e6ea5" onclick="openStudentProfile(${s.id}, '${profileModule}')" aria-label="Profile" title="Profile"><i class="fi fi-rr-user btn-ico" aria-hidden="true"></i></button>
          ${isAdminRole() ? `<button class="btn-sm danger" onclick="deleteStudent(${s.id})">Archive</button>` : ''}
        </td>
      </tr>
    `;
    }

    return `
    <tr>
      <td>${escapeHtml(s.student_code)}</td>
      <td>
        ${escapeHtml(s.full_name)}
        ${isPending ? '<br><span class="pill pill-yellow" style="margin-top:3px">Pending</span>' : ''}
      </td>
      <td>${escapeHtml(s.grade_section)}</td>
      <td class="actions-cell">
        ${editOrAcceptBtn}
        <button class="btn-sm icon-only" style="border-color:#2e6ea5;color:#2e6ea5" onclick="openStudentProfile(${s.id}, '${profileModule}')" aria-label="Profile" title="Profile"><i class="fi fi-rr-user btn-ico" aria-hidden="true"></i></button>
        ${isAdminRole() ? `<button class="btn-sm danger" onclick="deleteStudent(${s.id})">Archive</button>` : ''}
      </td>
    </tr>
  `;
  }).join("");
}


// Default element ids used by clinic.php, guidance.php, and Admin's own
// Clinic Module "Student Health Records" page — all three markup blocks
// use these same ids. Admin's Student Management and Guidance Module
// "Student Records" pages pass their own id sets (see admin.php) so all
// three student lists stay fully independent — no shared/merged DOM.
const DEFAULT_STUDENTS_IDS = { tbody: "tbody-students", search: "search-students", grade: "filter-grade" };

// Remembers which student-list page was rendered most recently, so that
// saveStudent()/deleteStudent() (triggered from the one shared Add/Edit
// modal, no matter which list opened it) refresh the correct table.
let currentStudentsView = { mode: null, ids: DEFAULT_STUDENTS_IDS };

async function renderStudents(explicitMode, ids) {
  ids = ids || DEFAULT_STUDENTS_IDS;
  const mode = resolveStudentsMode(explicitMode);
  currentStudentsView = { mode, ids };

  const res   = await fetch(API);
  allStudents = await res.json();

  // Reset search box + filters back to "All" every time this page is
  // (re)loaded, instead of keeping whatever was selected before leaving.
  document.getElementById(ids.search).value = "";

  if (ids.grade) {
    populateGradeFilter(ids.grade);
    document.getElementById(ids.grade).value = "";
  }

  document.getElementById(ids.tbody).innerHTML = buildRows(allStudents, mode);
}

function populateGradeFilter(gradeId) {
  const sel = document.getElementById(gradeId || "filter-grade");
  const current = sel.value;
  const grades = [...new Set(allStudents.map(s => s.grade_level).filter(Boolean))].sort();
  sel.innerHTML = '<option value="">All Levels</option>';
  grades.forEach(g => {
    const o = document.createElement("option");
    o.value = g;
    o.textContent = g;
    if (g === current) o.selected = true;
    sel.appendChild(o);
  });
}

// ── STUDENT PROFILE ───────────────────────────────────────────
// `module` scopes what the server returns: 'clinic' → Clinic-only data,
// 'guidance' → Guidance-only data, '' / omitted → neither (the
// Administrator Student Management master list — profile, guardian,
// and emergency contact only). This keeps the Administrator's Clinic
// and Guidance module profiles exactly as separate as the Clinic Staff
// and Guidance Staff pages they mirror; nothing is ever merged onto one
// screen. Clinic Staff / Guidance Staff are unaffected — their role
// alone already restricts them server-side to their own side.
async function openStudentProfile(id, module) {
    const modal = document.getElementById("studentProfileModal");
    modal.style.display = "flex";
    document.getElementById("sp-body").innerHTML = `<div class="rep-empty">Loading student profile...</div>`;
    window._currentProfileModule = module;

    try {
        const url = `api/students.php?profile=${id}` + (module ? `&module=${encodeURIComponent(module)}` : '');
        const res  = await fetch(url);
        const data = await res.json();

        if (!data.success) {
            document.getElementById("sp-body").innerHTML = `<div class="rep-empty">${escapeHtml(data.message || 'Could not load this profile.')}</div>`;
            return;
        }

        renderStudentProfile(data);
    } catch (err) {
        document.getElementById("sp-body").innerHTML = `<div class="rep-empty">Could not reach the server.</div>`;
    }
}

function closeStudentProfile() {
    document.getElementById("studentProfileModal").style.display = "none";
}

function renderStudentProfile(data) {
    const s = data.student;
    window._currentProfileStudentId = s.id;
    window._currentProfileStudent = s;

    document.getElementById("sp-name").textContent = s.full_name || '';
    document.getElementById("sp-meta").textContent =
        `${s.student_code || ''} · ${s.grade_section || ''} · Added ${formatDateLong(s.created_at)} via ${s.source || ''}`;

    let html = '';

    // ── Active / Pending Items (Step 4) — role-scoped alerts computed
    //    server-side purely from existing Step 1/2/3 status fields
    //    (referrals.status, behavioral_records.status, offenses.status).
    //    Most urgent first. ──
    if (data.alerts && data.alerts.length) {
        const alertColor = { critical: '#c0392b', warning: '#8a6a00', info: '#1a4a7a' };
        const alertBg     = { critical: '#fdecea', warning: '#fef9e0', info: '#e0eef8' };
        html += `
          <div class="card">
            <div class="card-header"><span class="card-title">⚠️ Active / Pending Items</span><span class="badge">${data.alerts.length}</span></div>
            <div class="sp-alerts-list">
              ${data.alerts.map(a => `
                <div class="sp-record-row" style="background:${alertBg[a.severity] || '#f5f5f5'};border-radius:6px;margin-bottom:6px;padding:8px 12px">
                  <div class="sp-record-main" style="color:${alertColor[a.severity] || '#555'}"><strong>${escapeHtml(a.type)}</strong> — ${escapeHtml(a.summary)}</div>
                  <div class="sp-record-sub">${escapeHtml(a.module)}${a.date ? ' · ' + formatDateLong(a.date) : ''}</div>
                </div>`).join('')}
            </div>
          </div>`;
    }

    // ── Summary Cards (role-dependent, shown at the top like a dashboard) ──
    if (data.clinic || data.guidance) {
        html += `<div class="report-stats-grid">`;
        if (data.clinic) {
            const cs = data.clinic.summary;
            html += `
              <div class="report-stat-card"><div class="rep-label">Clinic Visits</div><div class="rep-value">${cs.visit_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Medicines Dispensed</div><div class="rep-value">${cs.medicines_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Referrals</div><div class="rep-value">${cs.referral_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Common Symptom</div><div class="rep-value" style="font-size:1.1rem">${cs.common_symptom ? escapeHtml(cs.common_symptom) : 'N/A'}</div></div>
              <div class="report-stat-card"><div class="rep-label">Last Visit</div><div class="rep-value" style="font-size:1.1rem">${cs.last_visit_date ? formatDateLong(cs.last_visit_date) : 'N/A'}</div></div>`;
        }
        if (data.guidance) {
            const gs = data.guidance.summary;
            html += `
              <div class="report-stat-card"><div class="rep-label">Behavioral Records</div><div class="rep-value">${gs.behavioral_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Offenses</div><div class="rep-value">${gs.offense_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Counseling Sessions</div><div class="rep-value">${gs.session_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Cases Closed</div><div class="rep-value">${gs.closed_case_count}</div></div>
              <div class="report-stat-card"><div class="rep-label">Times Reopened</div><div class="rep-value">${gs.reopened_count || 0}</div></div>
              <div class="report-stat-card"><div class="rep-label">Last Session</div><div class="rep-value" style="font-size:1.1rem">${gs.last_session_date ? formatDateLong(gs.last_session_date) : 'N/A'}</div></div>`;
        }
        html += `</div>`;
    }

    // ── Basic Info + Guardian + Emergency Contact (always shown — not
    //    module-confidential, visible to all three roles) ──
    html += `
      <div class="card">
        <div class="card-header">
          <span class="card-title">Student Information</span>
        </div>
        <div class="sp-info-grid">
          <div><span class="sp-info-label">Sex</span><span class="sp-info-value">${escapeHtml(s.sex) || 'N/A'}</span></div>
          <div><span class="sp-info-label">Birth Date</span><span class="sp-info-value">${s.birth_date ? formatDateLong(s.birth_date) : 'N/A'}</span></div>
          <div><span class="sp-info-label">Section</span><span class="sp-info-value">${escapeHtml(s.section) || 'N/A'}</span></div>
          <div><span class="sp-info-label">Address</span><span class="sp-info-value">${escapeHtml(s.address) || 'N/A'}</span></div>
          <div><span class="sp-info-label">Status</span><span class="sp-info-value">${s.is_archived == 1 ? 'Archived' : 'Active'}</span></div>
          <div><span class="sp-info-label">Guardian</span><span class="sp-info-value">${s.guardian_name && s.guardian_name.trim() ? escapeHtml(s.guardian_name) : 'N/A'}</span></div>
          <div><span class="sp-info-label">Guardian Contact</span><span class="sp-info-value">${s.guardian_contact && s.guardian_contact.trim() ? escapeHtml(s.guardian_contact) : 'N/A'}</span></div>
          <div><span class="sp-info-label">Emergency Contact</span><span class="sp-info-value">${s.emergency_contact_name && s.emergency_contact_name.trim() ? escapeHtml(s.emergency_contact_name) : 'N/A'}</span></div>
          <div><span class="sp-info-label">Emergency Number</span><span class="sp-info-value">${s.emergency_contact_number && s.emergency_contact_number.trim() ? escapeHtml(s.emergency_contact_number) : 'N/A'}</span></div>
        </div>
      </div>`;

    // ── Documents (Step 6) — Conference Notice generation, shown only
    //    when this profile response actually included Guidance data
    //    (i.e. the role is authorized for Guidance — Administrator or
    //    Guidance Staff). Referral Letters are generated from the
    //    Referral History view instead (see openReferralLetterModal),
    //    since a letter needs a specific referral record. ──
    if (data.guidance) {
        html += `
          <div class="card">
            <div class="card-header"><span class="card-title">Documents</span></div>
            <p style="color:#6b7280;font-size:13px;margin:0 0 10px">Internal use only — generates a printable notice for the parent/guardian. Nothing is sent automatically.</p>
            <button class="btn-primary" onclick="openConferenceNoticeModal(window._currentProfileStudent)">📄 Create Conference Notice</button>
          </div>`;
    }

    // ── Clinic section (only present if the server sent it) ──
    if (data.clinic) {
        html += `
          <div class="card">
            <div class="card-header"><span class="card-title">Clinic Visits</span><span class="badge">${data.clinic.visits.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.clinic.visits.length ? `<div class="rep-empty">No clinic visits recorded.</div>` : data.clinic.visits.map(v => `
                <div class="sp-record-row">
                  <div class="sp-record-main"><strong>Symptoms:</strong> ${escapeHtml(v.symptoms) || 'N/A'} &nbsp;|&nbsp; <strong>Findings:</strong> ${escapeHtml(v.findings) || 'N/A'}</div>
                  <div class="sp-record-sub">${formatDateLong(v.visited_at)} · <span class="pill ${v.status === 'Treated' ? 'pill-green' : v.status === 'Referred' ? 'pill-red' : 'pill-yellow'}">${escapeHtml(v.status)}</span></div>
                </div>`).join('')}
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">Treatment History</span><span class="badge">${data.clinic.treatments.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.clinic.treatments.length ? `<div class="rep-empty">No treatments recorded.</div>` : data.clinic.treatments.map(t => `
                <div class="sp-record-row">
                  <div class="sp-record-main">${escapeHtml(t.medicine)} ${t.dosage ? `(${escapeHtml(t.dosage)})` : ''}</div>
                  <div class="sp-record-sub">${formatDateLong(t.given_at)} · Given by ${escapeHtml(t.given_by_name) || 'N/A'}</div>
                </div>`).join('')}
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">Referrals</span><span class="badge">${data.clinic.referrals.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.clinic.referrals.length ? `<div class="rep-empty">No referrals recorded.</div>` : data.clinic.referrals.map(r => `
                <div class="sp-record-row">
                  <div class="sp-record-main">Referred to ${escapeHtml(r.referred_to)}</div>
                  <div class="sp-record-sub">${formatDateLong(r.created_at)}</div>
                </div>`).join('')}
            </div>
          </div>`;
    }

    // ── Guidance section (only present if the server sent it) ──
    if (data.guidance) {
        const gs = data.guidance.summary;
        html += `
          <div class="card">
            <div class="card-header"><span class="card-title">Behavioral Records</span><span class="badge">${data.guidance.behavioral.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.guidance.behavioral.length ? `<div class="rep-empty">No behavioral records.</div>` : data.guidance.behavioral.map(b => `
                <div class="sp-record-row">
                  <div class="sp-record-main">${escapeHtml(b.behavior)}</div>
                  <div class="sp-record-sub">${formatDateLong(b.recorded_at)} · <span class="pill ${BEHAVIORAL_STATUS_CLASS[b.status] || 'pill-yellow'}">${escapeHtml(b.status || 'Open')}</span></div>
                </div>`).join('')}
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">Offenses</span><span class="badge">${data.guidance.offenses.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.guidance.offenses.length ? `<div class="rep-empty">No offenses recorded.</div>` : data.guidance.offenses.map(o => `
                <div class="sp-record-row">
                  <div class="sp-record-main">${escapeHtml(o.type)} ${o.count > 1 ? `(x${o.count})` : ''} ${o.severity_level ? `<span class="pill ${SEVERITY_PILL_CLASS[o.severity_level] || 'pill-gray'}">${escapeHtml(o.severity_level)}</span>` : ''}</div>
                  <div class="sp-record-sub">${formatDateLong(o.recorded_at)} · <span class="pill ${OFFENSE_STATUS_CLASS[o.status] || 'pill-yellow'}">${escapeHtml(o.status)}</span></div>
                </div>`).join('')}
            </div>
          </div>
          <div class="card">
            <div class="card-header"><span class="card-title">Counseling Sessions</span><span class="badge">${data.guidance.sessions.length}</span></div>
            <div class="sp-scroll-list">
              ${!data.guidance.sessions.length ? `<div class="rep-empty">No counseling sessions.</div>` : data.guidance.sessions.map(cs => `
                <div class="sp-record-row">
                  <div class="sp-record-main">Counseling Session — ${escapeHtml(cs.counselor_name) || 'Unassigned'}${cs.concern ? `<br><span style="font-size:.8rem;color:#777">${escapeHtml(cs.concern)}</span>` : ''}</div>
                  <div class="sp-record-sub">${formatDateLong(cs.session_date)} · <span class="pill ${counselingStatusPillClass(cs.status)}">${escapeHtml(cs.status)}</span></div>
                </div>`).join('')}
            </div>
          </div>`;
    }

    // ── Combined Timeline (already merged + role-filtered server-side).
    //    Heading adapts to what's actually visible to this role. ──
    const timelineTitle = (data.clinic && data.guidance) ? 'Timeline'
        : data.guidance ? 'Case Timeline'
        : data.clinic ? 'Medical Timeline'
        : 'Timeline';
    html += `
      <div class="card">
        <div class="card-header"><span class="card-title">${timelineTitle}</span></div>
        <div class="sp-scroll-list">
          ${!data.timeline.length ? `<div class="rep-empty">No activity recorded yet.</div>` : data.timeline.map(t => `
            <div class="tl-item">
              <div class="tl-dot ${t.module === 'Clinic' ? 'blue' : 'yellow'}"></div>
              <div class="tl-body">
                <div class="tl-name">${escapeHtml(t.type)} <span style="color:#aaa;font-size:.75rem">(${escapeHtml(t.module)})</span></div>
                <div class="tl-meta">${escapeHtml(t.summary)}</div>
                <div class="tl-time">${formatDateLong(t.date)}</div>
              </div>
            </div>`).join('')}
        </div>
      </div>`;

    document.getElementById("sp-body").innerHTML = html;
}

function filterStudents(explicitMode, ids) {
  ids = ids || currentStudentsView.ids || DEFAULT_STUDENTS_IDS;
  const mode = resolveStudentsMode(explicitMode || currentStudentsView.mode);

  const q     = document.getElementById(ids.search).value.toLowerCase();
  const grade = ids.grade ? document.getElementById(ids.grade).value : '';

  const filtered = allStudents.filter(s => {
    const matchSearch = !q ||
      s.full_name.toLowerCase().includes(q) ||
      s.student_code.toLowerCase().includes(q);
    const matchGrade = !grade || s.grade_level === grade;
    return matchSearch && matchGrade;
  });

  document.getElementById(ids.tbody).innerHTML = buildRows(filtered, mode);
}

async function saveStudent() {
  const payload = {
    id:            editingId,
    student_code:  document.getElementById("stu-code").value.trim(),
    first_name:    capitalizeWords(document.getElementById("stu-first-name").value.trim()),
    middle_name:   capitalizeWords(document.getElementById("stu-middle-name").value.trim()),
    last_name:     capitalizeWords(document.getElementById("stu-last-name").value.trim()),
    suffix:        document.getElementById("stu-suffix").value.trim(),
    sex:           document.getElementById("stu-sex").value,
    birth_date:    document.getElementById("stu-birthdate").value,
    grade_level:   document.getElementById("stu-grade-level").value,
    section:       capitalizeWords(document.getElementById("stu-section").value.trim()),
    address:       capitalizeWords(document.getElementById("stu-address").value.trim()),
    guardian_name:             capitalizeWords(document.getElementById("stu-guardian-name")?.value.trim() || ""),
    guardian_contact:          document.getElementById("stu-guardian-contact")?.value.trim() || "",
    emergency_contact_name:    capitalizeWords(document.getElementById("stu-emergency-name")?.value.trim() || ""),
    emergency_contact_number:  document.getElementById("stu-emergency-number")?.value.trim() || "",
  };

  const validation = CSFValidate.validateFields([
    { id: 'stu-code',        label: 'Student ID',  required: true },
    { id: 'stu-first-name',  label: 'First Name',  required: true },
    { id: 'stu-last-name',   label: 'Last Name',   required: true },
    { id: 'stu-sex',         label: 'Sex',         required: true },
    { id: 'stu-birthdate',   label: 'Birth Date',  required: true, type: 'date', notFuture: true },
    { id: 'stu-grade-level', label: 'Grade Level', required: true },
    { id: 'stu-section',     label: 'Section',     required: true },
    { id: 'stu-address',     label: 'Address',     required: true },
    { id: 'stu-guardian-contact', label: 'Guardian Contact Number',  type: 'phone' },
    { id: 'stu-emergency-number', label: 'Emergency Contact Number', type: 'phone' },
  ]);
  if (!validation.valid) { showNotif(`⚠️ ${validation.firstError}`, 'warn'); return; }

  // Skip the "no changes" guard when in Accept mode — confirming an
  // unchanged Pending record is the whole point of clicking Accept.
  if (editingId && !isAcceptingStudent) {
    const noChanges =
      payload.student_code  === originalData.student_code  &&
      payload.first_name    === originalData.first_name    &&
      payload.middle_name   === originalData.middle_name   &&
      payload.last_name     === originalData.last_name     &&
      payload.suffix        === originalData.suffix        &&
      payload.sex           === originalData.sex           &&
      payload.birth_date    === originalData.birth_date    &&
      payload.grade_level   === originalData.grade_level   &&
      payload.section       === originalData.section       &&
      payload.address       === originalData.address       &&
      payload.guardian_name            === originalData.guardian_name            &&
      payload.guardian_contact         === originalData.guardian_contact         &&
      payload.emergency_contact_name   === originalData.emergency_contact_name   &&
      payload.emergency_contact_number === originalData.emergency_contact_number;

    if (noChanges) {
      showNotif("⚠️ Walang nabago.", "warn");
      return;
    }
  }

  const method = editingId ? "PUT" : "POST";
  const res = await fetch(API, {
    method,
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });

  const result = await res.json();
  if (!result.success) {
    showNotif(`⚠️ ${result.message}`, "warn");
    return;
  }

  const wasAccepting = isAcceptingStudent;
  isAcceptingStudent = false;

  showNotif(
    wasAccepting ? "✅ Student record accepted!" : (editingId ? "✏️ Student updated!" : "✅ Student added!"),
    wasAccepting ? "success" : (editingId ? "update" : "success")
  );
  closeStudentModal();
  renderStudents(currentStudentsView.mode, currentStudentsView.ids);
}

async function openEditStudent(id) {
  const res = await fetch(`${API}?id=${id}`);
  const s   = await res.json();

  if (!s || !s.id) { showNotif('⚠️ This student record no longer exists.', 'warn'); return; }

  editingId = s.id;
  originalData = {
    student_code:  s.student_code  ?? "",
    first_name:    s.first_name    ?? "",
    middle_name:   _naToBlank(s.middle_name),
    last_name:     s.last_name     ?? "",
    suffix:        _naToBlank(s.suffix),
    sex:           s.sex           ?? "",
    birth_date:    s.birth_date    ? String(s.birth_date).split(' ')[0] : "",
    grade_level:   s.grade_level   ?? "",
    section:       s.section       ?? "",
    address:       s.address       ?? "",
    guardian_name:             _naToBlank(s.guardian_name),
    guardian_contact:          _naToBlank(s.guardian_contact),
    emergency_contact_name:    _naToBlank(s.emergency_contact_name),
    emergency_contact_number:  _naToBlank(s.emergency_contact_number),
  };

  document.getElementById("modalTitle").innerText = "Edit Student";
  document.getElementById("stu-code").value        = originalData.student_code;
  document.getElementById("stu-first-name").value  = originalData.first_name;
  document.getElementById("stu-middle-name").value = originalData.middle_name;
  document.getElementById("stu-last-name").value   = originalData.last_name;
  document.getElementById("stu-suffix").value      = originalData.suffix;
  document.getElementById("stu-sex").value         = originalData.sex;
  document.getElementById("stu-birthdate").value   = originalData.birth_date;
  document.getElementById("stu-grade-level").value = originalData.grade_level;
  document.getElementById("stu-section").value     = originalData.section;
  document.getElementById("stu-address").value     = originalData.address;
  if (document.getElementById("stu-guardian-name"))    document.getElementById("stu-guardian-name").value    = originalData.guardian_name;
  if (document.getElementById("stu-guardian-contact")) document.getElementById("stu-guardian-contact").value = originalData.guardian_contact;
  if (document.getElementById("stu-emergency-name"))   document.getElementById("stu-emergency-name").value   = originalData.emergency_contact_name;
  if (document.getElementById("stu-emergency-number")) document.getElementById("stu-emergency-number").value = originalData.emergency_contact_number;

  openStudentModal();
}

async function deleteStudent(id) {
  if (!await csfConfirm({ title: 'Archive Student?', message: 'They will be hidden from active records but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;

  const res    = await fetch(`${API}?id=${id}`, { method: "DELETE" });
  const result = await res.json();

  if (result.success) {
    renderStudents(currentStudentsView.mode, currentStudentsView.ids);
    showNotif("🗄️ Student archived. Find it in Archived Records.", "delete");
  } else {
    showNotif(`⚠️ ${result.message || "Operation failed."}`, "warn");
  }
}

function clearStudentForm() {
  document.getElementById("stu-code").value        = "";
  document.getElementById("stu-first-name").value  = "";
  document.getElementById("stu-middle-name").value = "";
  document.getElementById("stu-last-name").value   = "";
  document.getElementById("stu-suffix").value      = "";
  document.getElementById("stu-sex").value         = "";
  document.getElementById("stu-birthdate").value   = "";
  document.getElementById("stu-grade-level").value = "";
  document.getElementById("stu-section").value     = "";
  document.getElementById("stu-address").value     = "";
  if (document.getElementById("stu-guardian-name"))    document.getElementById("stu-guardian-name").value    = "";
  if (document.getElementById("stu-guardian-contact")) document.getElementById("stu-guardian-contact").value = "";
  if (document.getElementById("stu-emergency-name"))   document.getElementById("stu-emergency-name").value   = "";
  if (document.getElementById("stu-emergency-number")) document.getElementById("stu-emergency-number").value = "";
}


// ── MOBILE SIDEBAR (Responsive Layout) ───────────────────────
function toggleSidebar() {
  document.body.classList.toggle("sidebar-open");
}

// Only Administrator may archive/restore student records — Clinic Staff
// and Guidance Staff can create/search/view/edit, matching the shared
// Student Records permission matrix (also enforced server-side).
function isAdminRole() {
  return typeof CSF_ROLE !== 'undefined' && CSF_ROLE === 'admin';
}

async function showPage(page, el) {
  document.querySelectorAll(".page").forEach(p => p.classList.remove("active"));

  const target = document.getElementById("page-" + page);
  if (target) target.classList.add("active");

  document.querySelectorAll(".nav-item").forEach(n => n.classList.remove("active"));
  if (el) el.classList.add("active");

  document.getElementById("headerTitle").innerText =
    // textContent (not innerText): on phones the closed sidebar drawer is
    // visibility:hidden, and innerText returns "" for hidden elements.
    el ? el.textContent.replace(/\s+/g, ' ').trim() : page.charAt(0).toUpperCase() + page.slice(1);

  // On mobile, the sidebar is an off-canvas drawer — close it after
  // picking a page, same as any standard mobile nav drawer.
  document.body.classList.remove("sidebar-open");

  if (page === "students") await renderStudents();
  if (page === "guidance-students") await renderStudents('guidance', { tbody: "tbody-guidance-students", search: "search-guidance-students", grade: "filter-grade-guidance-students" });
  if (page === "users") await loadUsers();
  if (page === "clinic-visits") await renderVisits();
  if (page === "treatments") { loadStaffOptions(); await renderTreatments(); if (!allStudents.length) await renderStudents(); }
  if (page === "inventory") await renderInventory();
  if (page === "dashboard") loadDashboardStats();
  if (page === "counseling") await renderCounseling();
  if (page === "close-case") await renderCloseCase();
  if (page === "reports") {
      if (typeof CSF_ROLE !== 'undefined' && CSF_ROLE === 'guidance') loadGuidanceReports();
      else loadReports();
  }
  if (page === "archived")     loadArchivedPage();
  if (page === "behavioral")   renderBehavioral();
  if (page === "offenses")     renderOffenses();
  if (page === "offense-categories") renderOffenseCategories();
  if (page === "referrals")    renderReferrals();
  if (page === "sms") loadSmsPage();
  if (page === "audit-trail") renderAuditTrail();
  if (page === "notif-history") loadNotifHistoryPage();
}

function showNotif(msg, type = "success") {
  const el = document.getElementById("notif");
  el.innerText = msg;
  el.className = "notif show " + type;
  // Longer messages (e.g. detailed error explanations) need more time to
  // read than the default 4s — scale duration with message length, capped
  // at 8s so it never lingers too long.
  const duration = Math.min(8000, Math.max(4000, msg.length * 60));
  clearTimeout(el._notifTimer);
  el._notifTimer = setTimeout(() => el.classList.remove("show"), duration);
}


document.addEventListener("DOMContentLoaded", () => {
  showPage("dashboard", document.querySelector(".nav-item.active"));
  fetch(API).then(r => r.json()).then(data => { allStudents = data; }).catch(() => {});
});


let allUsers = [];

function loadUsers() {
    return fetch('api/users.php?action=list')
        .then(r => r.json())
        .then(data => {
            allUsers = data;

            // Reset search box + role filter back to "All" every time
            // this page is (re)loaded, instead of keeping the previous
            // selection.
            document.getElementById('search-users').value = "";
            document.getElementById('filter-role').value  = "";

            renderUsers(data);
        });
}

function renderUsers(data) {
    const tbody = document.getElementById('tbody-users');
    tbody.innerHTML = '';
    if (data.length === 0) {
        tbody.innerHTML = emptyRow(4, "No users found", "Try a different name, username, or role.");
        return;
    }
    data.forEach(u => {
        tbody.innerHTML += `
        <tr id="user-row-${u.id}">
            <td>${escapeHtml(u.full_name)}</td>
            <td>${escapeHtml(u.username)}</td>
            <td>${escapeHtml(u.role)}</td>
            <td class="actions-cell">
                <button class="btn-sm icon-only" onclick="openEditUser(${u.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>
                <button class="btn-sm danger" onclick="deleteUser(${u.id})">Archive</button>
            </td>
        </tr>`;
    });
}

function filterUsers() {
    const search = document.getElementById('search-users').value.toLowerCase();
    const role   = document.getElementById('filter-role').value;
    const filtered = allUsers.filter(u =>
        (u.full_name.toLowerCase().includes(search) || u.username.toLowerCase().includes(search)) &&
        (role === '' || u.role === role)
    );
    renderUsers(filtered);
}

function openAddUser() {
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('user-id').value = '';
    document.getElementById('user-name').value = '';
    document.getElementById('user-username').value = '';
    document.getElementById('user-role').value = '';
    document.getElementById('user-password').value = '';
    document.getElementById('user-pass-label').innerHTML = 'Password <span style="color:#c0392b">*</span>';
    document.getElementById('userModal').style.display = 'flex';
}


function openEditUser(id) {
    const u = allUsers.find(x => x.id == id);
    if (!u) { showNotif('⚠️ This user no longer exists.', 'warn'); return; }

      editingUserData = {
        full_name: u.full_name,
        username:  u.username,
        role:      u.role
    };

    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('user-id').value = u.id;
    document.getElementById('user-name').value = u.full_name;
    document.getElementById('user-username').value = u.username;
    document.getElementById('user-role').value = u.role;
    document.getElementById('user-password').value = '';
    document.getElementById('user-pass-label').textContent = 'New Password (leave blank to keep current)';
    document.getElementById('userModal').style.display = 'flex';
}

function closeUserModal() {
    document.getElementById('userModal').style.display = 'none';
    editingUserData = {}; 
}

function saveUser() {
    const id       = document.getElementById('user-id').value;
    const fullName = document.getElementById('user-name').value.trim();
    const username = document.getElementById('user-username').value.trim();
    const role     = document.getElementById('user-role').value;
    const password = document.getElementById('user-password').value;

    const validation = CSFValidate.validateFields([
        { id: 'user-name',     label: 'Full Name', required: true },
        { id: 'user-username', label: 'Username',  required: true },
        { id: 'user-role',     label: 'Role',      required: true },
    ]);
    if (!validation.valid) { showNotif(`⚠️ ${validation.firstError}`, 'warn'); return; }
    if (!id && !password) {
        CSFValidate.validateFields([{ id: 'user-password', label: 'Password', required: true }]);
        showNotif('Password is required for new users.', 'warn');
        return;
    }

    if (id) {
        const noChanges =
            fullName === editingUserData.full_name &&
            username === editingUserData.username  &&
            role     === editingUserData.role      &&
            !password;
        if (noChanges) {
            showNotif("⚠️ Walang nabago.", "warn");
            return;
        }
    }

    const form = new FormData();
    form.append('action', id ? 'edit' : 'add');
    if (id) form.append('id', id);
    form.append('full_name', fullName);
    form.append('username', username);
    form.append('role', role);
    if (password) form.append('password', password);

    fetch('api/users.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                closeUserModal();
                loadUsers();
                showNotif(id ? 'User updated.' : 'User added.', 'success');
            } else {
                showNotif(res.message, 'error');
            }
        });
}

async function deleteUser(id) {
    if (!await csfConfirm({ title: 'Archive User?', message: 'Their account will be deactivated and hidden from User Management, but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
    const form = new FormData();
    form.append('action', 'delete');
    form.append('id', id);
    fetch('api/users.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                loadUsers();
                showNotif('🗄️ User archived. Find them in Archived Records.', 'success');
            }
        });
}

const VISIT_API = "api/clinic.php";
let allVisits = [];
let editingVisitId = null;
let originalVisitData = {};
let availableMedicines = []; // loaded from api/treatments.php?medicines=1
let dispenseRowSeq = 0;

async function openAddVisit() {
    editingVisitId = null;
    originalVisitData = {};
    clearVisitForm();
    if (!allStudents.length) await renderStudents();
    visitStudentDropdown.setData(allStudents);
    await ensureMedicinesLoaded();
    document.getElementById('visitModalTitle').textContent = 'Record Clinic Visit';
    document.getElementById('visit-date').value = new Date().toISOString().slice(0, 16);
    document.getElementById('visit-clinic-staff-display').value =
        typeof CURRENT_USER_NAME !== "undefined" ? CURRENT_USER_NAME : "";
    addDispenseRow();
    document.getElementById('visitModal').style.display = 'flex';
}

function closeVisitModal() {
    document.getElementById('visitModal').style.display = 'none';
    editingVisitId = null;
    originalVisitData = {};
    clearVisitForm();
}

function clearVisitForm() {
    visitStudentDropdown.clear();
    document.getElementById('visit-id').value                = '';
    document.getElementById('visit-symptoms').value           = '';
    document.getElementById('visit-date').value               = '';
    document.getElementById('visit-findings').value            = '';
    document.getElementById('visit-treatment').value           = '';
    document.getElementById('visit-assessment').value          = '';
    document.getElementById('visit-status').value               = '';
    document.getElementById('visit-remarks').value              = '';
    document.getElementById('visit-referral-required').value   = '0';
    document.getElementById('visit-referral-destination').value = '';
    document.getElementById('visit-referral-reason').value      = '';
    document.getElementById('visit-dispense-rows').innerHTML    = '';
    handleVisitStatusChange();
}

// Shared instance: powers the Student search box inside the Record/Edit
// Visit modal. Initialized once below (after allStudents loads) and kept
// in sync via .setData()/.setSelectedById() everywhere a student needs
// to be pre-filled or refreshed.
const visitStudentDropdown = new SearchDropdown({
    input: '#visit-student-search',
    hidden: '#visit-student-id',
    data: [],
    type: 'student',
    valueField: 'id',
    titleField: 'full_name',
    subtitleField: 'student_code',
    extraField: 'grade_section',
    placeholder: 'Type ID or Name...',
    noResultsText: 'No matching students.'
});

// NOTE: Manual "resolve typed text to a student" logic
// (resolveStudentFromInput / lookupStudentBySearch / lookupTreatStudentBySearch /
// lookupCounselingStudentBySearch) has been removed. Selection is now handled
// directly by the SearchDropdown component instances (see search-dropdown.js),
// which write the selected ID straight into each hidden input via onSelect.

async function ensureMedicinesLoaded() {
    const res = await fetch('api/treatments.php?medicines=1');
    availableMedicines = await res.json();
}

function medicineOptionsHtml(selectedId) {
    let html = `<option value="">-- Select medicine --</option>`;
    availableMedicines.forEach(m => {
        const sel = String(m.id) === String(selectedId) ? 'selected' : '';
        html += `<option value="${m.id}" ${sel}>${escapeHtml(m.name)} (${m.quantity} ${escapeHtml(m.unit)} left)</option>`;
    });
    return html;
}

function addDispenseRow(medicineId = '', qty = '') {
    const rowId = `dispense-row-${++dispenseRowSeq}`;
    const wrap = document.createElement('div');
    wrap.className = 'dispense-row';
    wrap.id = rowId;
    wrap.innerHTML = `
        <select onchange="updateDispenseStockNote('${rowId}')">${medicineOptionsHtml(medicineId)}</select>
        <input type="number" min="1" placeholder="Qty" value="${qty ? escapeHtml(String(qty)) : ''}" oninput="updateDispenseStockNote('${rowId}')">
        <button type="button" class="remove-row-btn" onclick="removeDispenseRow('${rowId}')">✕</button>
        <div class="stock-note"></div>
    `;
    document.getElementById('visit-dispense-rows').appendChild(wrap);
    updateDispenseStockNote(rowId);
}

function removeDispenseRow(rowId) {
    const el = document.getElementById(rowId);
    if (el) el.remove();
}

function updateDispenseStockNote(rowId) {
    const row = document.getElementById(rowId);
    if (!row) return;
    const select = row.querySelector('select');
    const note   = row.querySelector('.stock-note');
    const med    = availableMedicines.find(m => String(m.id) === String(select.value));
    note.textContent = med ? `In stock: ${med.quantity} ${med.unit}` : '';
}

function collectDispensedMedicines() {
    const rows = document.querySelectorAll('#visit-dispense-rows .dispense-row');
    const meds = [];
    rows.forEach(row => {
        const medId = row.querySelector('select').value;
        const qty   = parseInt(row.querySelector('input').value, 10);
        if (medId && qty > 0) meds.push({ medicine_id: medId, quantity: qty });
    });
    return meds;
}

function toggleReferralFields() {
    const required = document.getElementById('visit-referral-required').value === '1';
    document.getElementById('visit-referral-fields').style.display = required ? 'block' : 'none';
}

// When Status = "Referred", a referral is implied by definition — force
// Referral Required to "Yes" and lock the dropdown so it can't be set
// back to "No" while Referred is selected. Unlocks again for any other
// status, leaving whatever value was already there.
function handleVisitStatusChange() {
    const statusSel   = document.getElementById('visit-status');
    const referralSel = document.getElementById('visit-referral-required');
    if (statusSel.value === 'Referred') {
        referralSel.value = '1';
        referralSel.disabled = true;
    } else {
        referralSel.disabled = false;
    }
    toggleReferralFields();
}

function buildVisitRows(data) {
    if (!data.length) {
        return emptyRow(9, "No clinic visits found", "Try a different search or status, or record a new visit.");
    }
    return data.map(v => {
        const smsWorthy = Number(v.referral_required) === 1 ||
            v.status === 'Sent Home' || v.status === 'Emergency';
        const smsBtn = smsWorthy
            ? `<button class="btn-sm sms icon-only" onclick="sendSmsForVisit(${v.id})" aria-label="Send SMS" title="Send SMS"><i class="fi fi-rr-paper-plane btn-ico" aria-hidden="true"></i></button>`
            : '';
        return `
        <tr id="visit-row-${v.id}">
            <td>${formatDateLong(v.visited_at)}</td>
            <td><strong>${escapeHtml(v.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(v.student_code)}</span></td>
            <td>${escapeHtml(v.symptoms) || 'N/A'}</td>
            <td>${escapeHtml(v.findings) || 'N/A'}</td>
            <td>${v.treatment && v.treatment.trim() !== '' ? escapeHtml(v.treatment) : 'None'}</td>
            <td>${escapeHtml(v.assessment)}</td>
            <td><span class="pill ${v.status === 'Treated' ? 'pill-green' : v.status === 'Referred' ? 'pill-red' : v.status === 'Emergency' ? 'pill-red' : v.status === 'Sent Home' ? 'pill-yellow' : 'pill-yellow'}">${escapeHtml(v.status)}</span></td>
            <td>${Number(v.referral_required) === 1 ? '<span class="pill pill-red">Yes</span>' : '<span class="pill pill-green">No</span>'}</td>
            <td class="actions-cell">
                <button class="btn-sm icon-only" onclick="openEditVisit(${v.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>
                ${smsBtn}
                <button class="btn-sm danger" onclick="deleteVisit(${v.id})">Archive</button>
            </td>
        </tr>
    `;
    }).join('');
}

async function renderVisits() {
    const res  = await fetch(VISIT_API);
    allVisits  = await res.json();

    // Reset search box + status filter back to "All" every time this
    // page is (re)loaded, instead of keeping the previous selection.
    document.getElementById('search-visits').value = "";
    document.getElementById('filter-status').value = "";

    document.getElementById('tbody-visits').innerHTML = buildVisitRows(allVisits);
}

function filterVisits() {
    const q        = document.getElementById('search-visits').value.toLowerCase();
    const status   = document.getElementById('filter-status').value;
    const filtered = allVisits.filter(v => {
        const matchSearch = !q ||
            v.full_name.toLowerCase().includes(q) ||
            v.student_code.toLowerCase().includes(q);
        const matchStatus = !status || v.status === status;
        return matchSearch && matchStatus;
    });
    document.getElementById('tbody-visits').innerHTML = buildVisitRows(filtered);
}

async function saveVisit() {
    const referralRequired = document.getElementById('visit-referral-required').value === '1';

    const payload = {
        id:                     editingVisitId,
        student_id:             document.getElementById('visit-student-id').value,
        symptoms:               document.getElementById('visit-symptoms').value.trim(),
        visited_at:             document.getElementById('visit-date').value,
        findings:                document.getElementById('visit-findings').value.trim(),
        treatment:               document.getElementById('visit-treatment').value.trim(),
        assessment:              document.getElementById('visit-assessment').value,
        status:                  document.getElementById('visit-status').value,
        remarks:                 document.getElementById('visit-remarks').value.trim(),
        referral_required:       referralRequired,
        referral_destination:    document.getElementById('visit-referral-destination').value,
        referral_reason:         document.getElementById('visit-referral-reason').value.trim(),
        medicines:               editingVisitId ? undefined : collectDispensedMedicines(),
    };

    if (!payload.student_id) { showNotif('⚠️ Student not found.', 'warn'); return; }

    const visitRules = [
        { id: 'visit-date',   label: 'Date & Time', required: true },
        { id: 'visit-status', label: 'Status',      required: true },
        { id: 'visit-assessment', label: 'Assessment', required: true },
    ];
    if (referralRequired) {
        visitRules.push(
            { id: 'visit-referral-destination', label: 'Referral Destination', required: true },
            { id: 'visit-referral-reason',      label: 'Referral Reason',      required: true },
        );
    }
    const visitValidation = CSFValidate.validateFields(visitRules);
    if (!visitValidation.valid) { showNotif(`⚠️ ${visitValidation.firstError}`, 'warn'); return; }

    if (editingVisitId) {
        const noChanges =
            payload.student_id == originalVisitData.student_id &&
            payload.symptoms   === originalVisitData.symptoms   &&
            payload.visited_at === originalVisitData.visited_at &&
            payload.findings   === originalVisitData.findings   &&
            payload.treatment  === originalVisitData.treatment  &&
            payload.assessment === originalVisitData.assessment &&
            payload.status     === originalVisitData.status     &&
            payload.remarks    === originalVisitData.remarks    &&
            payload.referral_required    === originalVisitData.referral_required &&
            payload.referral_destination === originalVisitData.referral_destination &&
            payload.referral_reason      === originalVisitData.referral_reason;
        if (noChanges) { showNotif('⚠️ Walang nabago.', 'warn'); return; }
    }

    const method = editingVisitId ? 'PUT' : 'POST';
    const res    = await fetch(VISIT_API, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }

    let smsNote = '';
    if (result.sms_status === 'Sent') smsNote = ' Guardian SMS sent.';
    else if (result.sms_status === 'Failed') smsNote = ' ⚠️ Guardian SMS failed to send.';
    else if (result.sms_status === 'Pending') smsNote = ' Guardian SMS queued.';
    else if (result.sms_status === 'Skipped') smsNote = ' ⚠️ Guardian SMS not sent — no contact number on file.';

    // Only mention the history logs that were actually written: a Treatment
    // row exists only if medicine was dispensed, a Referral row only if
    // Referral Required was Yes (see api/clinic.php).
    let savedNote = '';
    if (!editingVisitId) {
        const logged = [];
        if (payload.medicines && payload.medicines.length) logged.push('Treatment History');
        if (referralRequired) logged.push('Referral History');
        if (logged.length) savedNote = ` ${logged.join(' and ')} updated automatically.`;
    }
    showNotif(
        (editingVisitId ? '✏️ Visit updated!' : '✅ Visit recorded!' + savedNote) + smsNote,
        editingVisitId ? 'update' : 'success'
    );
    closeVisitModal();
    renderVisits();
}

async function openEditVisit(id) {
    const res = await fetch(`${VISIT_API}?id=${id}`);
    const v   = await res.json();

    if (!v || !v.id) { showNotif('⚠️ This clinic visit record no longer exists.', 'warn'); return; }
    if (Number(v.is_archived) === 1) { showNotif('⚠️ This clinic visit has been archived. Open it from Archived Records.', 'warn'); return; }

    editingVisitId = v.id;
    originalVisitData = {
        student_id:            v.student_id,
        symptoms:               _naToBlank(v.symptoms),
        // Same "YYYY-MM-DDTHH:MM" format the datetime-local input returns —
        // comparing the raw DB value ("YYYY-MM-DD HH:MM:SS") never matched,
        // so the "no changes" check below could never trigger.
        visited_at:            v.visited_at ? v.visited_at.replace(' ', 'T').slice(0, 16) : '',
        findings:               _naToBlank(v.findings),
        treatment:              _naToBlank(v.treatment),
        assessment:             v.assessment ?? '',
        status:                 v.status     ?? '',
        remarks:                _naToBlank(v.remarks),
        referral_required:      !!Number(v.referral_required),
        referral_destination:   _naToBlank(v.referral_destination),
        referral_reason:        _naToBlank(v.referral_reason),
    };

    if (!allStudents.length) await renderStudents();
    visitStudentDropdown.setData(allStudents);
    await ensureMedicinesLoaded();

    document.getElementById('visitModalTitle').textContent       = 'Edit Clinic Visit';
    document.getElementById('visit-id').value                    = v.id;
    visitStudentDropdown.setSelectedById(v.student_id);
    document.getElementById('visit-symptoms').value              = _naToBlank(v.symptoms);
    document.getElementById('visit-date').value                  = v.visited_at ? v.visited_at.replace(' ', 'T').slice(0, 16) : '';
    document.getElementById('visit-clinic-staff-display').value  = v.clinic_staff_name ?? '';
    document.getElementById('visit-findings').value              = _naToBlank(v.findings);
    document.getElementById('visit-treatment').value             = _naToBlank(v.treatment);
    document.getElementById('visit-assessment').value            = v.assessment ?? '';
    document.getElementById('visit-status').value                = v.status     ?? '';
    document.getElementById('visit-remarks').value                = _naToBlank(v.remarks);
    document.getElementById('visit-referral-required').value     = Number(v.referral_required) === 1 ? '1' : '0';
    document.getElementById('visit-referral-destination').value  = _naToBlank(v.referral_destination);
    document.getElementById('visit-referral-reason').value       = _naToBlank(v.referral_reason);
    handleVisitStatusChange();

    // Medicines already dispensed are Treatment History — read-only display,
    // not editable dispensing rows (a visit's dispensing is final once saved).
    const rowsWrap = document.getElementById('visit-dispense-rows');
    if (v.medicines_dispensed && v.medicines_dispensed.length) {
        rowsWrap.innerHTML = `<p style="color:#6b7280;font-size:13px;margin:0 0 8px">Already dispensed for this visit (read-only — this became Treatment History):</p>` +
            v.medicines_dispensed.map(m => `<div class="stock-note">• ${escapeHtml(m.medicine)} — ${escapeHtml(m.dosage)}</div>`).join('');
    } else {
        rowsWrap.innerHTML = `<p style="color:#6b7280;font-size:13px;margin:0">No medicine was dispensed for this visit.</p>`;
    }

    document.getElementById('visitModal').style.display = 'flex';
}

async function deleteVisit(id) {
    if (!await csfConfirm({ title: 'Archive Clinic Visit?', message: 'Its Treatment History and Referral History will be archived too (stock will be restored), and everything can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
    const res    = await fetch(`${VISIT_API}?id=${id}`, { method: 'DELETE' });
    const result = await res.json();
    if (result.success) {
        renderVisits();
        showNotif('🗄️ Visit archived. Find it in Archived Records.', 'delete');
    } else {
        showNotif(`⚠️ ${result.message || 'Operation failed.'}`, 'warn');
    }
}

// ── TREATMENTS (now linked to Medicine Inventory) ──
const TREAT_API = "api/treatments.php";
let allTreatments = [];
let _currentTreatmentDetail = null;

function loadStaffOptions() {
  // No longer needed — "Given By" is the logged-in user, not a selectable dropdown.
}

function buildTreatmentRows(data) {
  if (!data.length) {
    return emptyRow(8, "No treatment history found", "Treatments are added automatically when a clinic visit records medicine given.");
  }
  return data.map(t => `
    <tr>
      <td>${formatDateLong(t.given_at)}</td>
      <td><strong>${escapeHtml(t.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(t.student_code)}</span></td>
      <td>${escapeHtml(t.visit_chief_complaint) || 'N/A'}</td>
      <td>${escapeHtml(t.visit_findings) || 'N/A'}</td>
      <td>${escapeHtml(t.medicine)}</td>
      <td>${escapeHtml(t.dosage)}</td>
      <td>${escapeHtml(t.given_by_name)}</td>
      <td>
        <button class="btn-sm icon-only" onclick="viewTreatment(${t.id})" aria-label="View Details" title="View Details"><i class="fi fi-rr-eye btn-ico" aria-hidden="true"></i></button>
      </td>
    </tr>
  `).join("");
}

async function renderTreatments() {
  const res    = await fetch(TREAT_API);
  allTreatments = await res.json();

  // Reset search box back to empty every time this page is (re)loaded,
  // instead of keeping the previous search text.
  document.getElementById("search-treatments").value = "";

  document.getElementById("tbody-treatments").innerHTML = buildTreatmentRows(allTreatments);
}

function filterTreatments() {
  const q = document.getElementById("search-treatments").value.toLowerCase();
  const filtered = allTreatments.filter(t =>
    !q || (t.full_name ?? "").toLowerCase().includes(q) || (t.medicine ?? "").toLowerCase().includes(q)
  );
  document.getElementById("tbody-treatments").innerHTML = buildTreatmentRows(filtered);
}

async function viewTreatment(id) {
  const res = await fetch(`${TREAT_API}?id=${id}`);
  const t   = await res.json();
  _currentTreatmentDetail = t;

  document.getElementById("treatment-view-fields").innerHTML = `
    <div class="form-group full"><label>Student</label><input type="text" readonly value="${escapeHtml(t.full_name || '')} (${escapeHtml(t.student_code || '')})"></div>
    <div class="form-group"><label>Visit Date</label><input type="text" readonly value="${formatDateLong(t.visit_date)}"></div>
    <div class="form-group"><label>Given By</label><input type="text" readonly value="${escapeHtml(t.given_by_name || '')}"></div>
    <div class="form-group full"><label>Symptoms / Chief Complaint</label><textarea readonly>${escapeHtml(t.visit_chief_complaint || '')}</textarea></div>
    <div class="form-group full"><label>Findings / Observation</label><textarea readonly>${escapeHtml(t.visit_findings || '')}</textarea></div>
    <div class="form-group"><label>Medicine Given</label><input type="text" readonly value="${escapeHtml(t.medicine_name || t.medicine || '')}"></div>
    <div class="form-group"><label>Dosage</label><input type="text" readonly value="${escapeHtml(t.dosage || '')}"></div>
    <div class="form-group full"><label>Remarks (from visit)</label><textarea readonly>${escapeHtml(t.visit_remarks || '')}</textarea></div>
  `;
  document.getElementById("treatmentModal").style.display = "flex";
}

function closeTreatmentModal() {
  document.getElementById("treatmentModal").style.display = "none";
  _currentTreatmentDetail = null;
}

// MEDICINE INVENTORY
const INV_API = "api/inventory.php";
let allInventory = [];
let editingMedId = null;
let originalMedData = {};

// Thresholds — change here if requirements change later.
const LOW_STOCK_THRESHOLD   = 10; // qty below this counts as "Low Stock"
const EXPIRY_WARNING_DAYS   = 15; // days-before-expiry that counts as "Expiring Soon"

// Returns the applicable statuses for a medicine PLUS the real days-left
// count (or null if there's no expiry date / it's already expired).
// daysLeft keeps counting down day by day (15, 14, 13... 1) as expiry
// approaches — nothing about it is static.
function getMedStatuses(qty, expiry) {
  const statuses = [];
  const today = new Date();
  today.setHours(0,0,0,0);

  // Parse "YYYY-MM-DD" as a LOCAL date, not UTC — new Date("2026-06-22")
  // parses as UTC midnight, which can shift a day off depending on the
  // user's timezone. Splitting into parts avoids that entirely.
  let exp = null;
  if (expiry) {
    const [y, m, d] = expiry.split('-').map(Number);
    exp = new Date(y, m - 1, d);
    exp.setHours(0,0,0,0);
  }

  let daysLeft = null;

  if (exp && exp <= today) {
    // Expiry date has arrived or passed — expired as of today.
    statuses.push("Expired");
  } else if (exp) {
    const msPerDay = 1000 * 60 * 60 * 24;
    daysLeft = Math.ceil((exp - today) / msPerDay);
    if (daysLeft <= EXPIRY_WARNING_DAYS) statuses.push("Expiring Soon");
  }

  // Zero stock MUST always be checked before the low-stock threshold —
  // otherwise quantity=0 falls into "qty < LOW_STOCK_THRESHOLD" and gets
  // mislabeled "Low Stock" instead of "Out of Stock". The two statuses
  // are mutually exclusive: a medicine is never both at once.
  if (qty <= 0) {
    statuses.push("Out of Stock");
  } else if (qty < LOW_STOCK_THRESHOLD) {
    statuses.push("Low Stock");
  }

  if (!statuses.length) statuses.push("Valid");

  return { statuses, daysLeft };
}

function getStatusPillClass(status) {
  return status === "Valid" ? "pill-green"
       : status === "Expired" ? "pill-red"
       : status === "Out of Stock" ? "pill-red"
       : status === "Expiring Soon" ? "pill-blue"
       : "pill-yellow"; // Low Stock
}

// Renders one pill per applicable status (e.g. "Expiring Soon" + "Low Stock"
// side by side) instead of collapsing to a single status.
function getStatusPill(statuses) {
  const list = Array.isArray(statuses) ? statuses : [statuses];
  return list.map(s => `<span class="pill ${getStatusPillClass(s)}">${s}</span>`).join(" ");
}

function getStockLevel(qty) {
  const pct = Math.min(100, Math.round((qty / 200) * 100));
  const color = qty < LOW_STOCK_THRESHOLD ? "#c0392b" : qty <= 20 ? "#f59e0b" : "#2e7d5a";
  return `
    <span style="font-size:.78rem;color:${color};font-weight:600">${qty} left</span>
    <div class="inv-bar"><div class="inv-fill" style="width:${pct}%;background:${color}"></div></div>
  `;
}

// Splits inventory into the three alert buckets used by the banner.
// A medicine that is both expired/expiring AND low stock is only counted
// once per bucket. Each "Expiring Soon" entry carries its own daysLeft
// so the banner can show a per-medicine countdown instead of one shared
// number for the whole group.
function getInventoryAlerts(data) {
  const lowStock = [];
  const expiringSoon = [];
  const expired = [];

  data.forEach(m => {
    const { statuses, daysLeft } = getMedStatuses(m.quantity, m.expiry_date);
    if (statuses.includes("Expired")) expired.push(m);
    if (statuses.includes("Expiring Soon")) expiringSoon.push({ ...m, daysLeft });
    if (statuses.includes("Low Stock")) lowStock.push(m);
  });

  return { lowStock, expiringSoon, expired };
}

// Shared empty/loading/error row for every table — a short headline plus
// one line on what to do next. Styled by td.table-empty in admin.css.
function emptyRow(colspan, title, hint) {
  return `<tr><td colspan="${colspan}" class="table-empty"><strong>${title}</strong>${hint ? `<span>${hint}</span>` : ''}</td></tr>`;
}

function escapeHtml(str) {
  if (str === null || str === undefined) return "";
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

// Jumps straight to Inventory, filtered to a given status — used by the
// Notification Center's click-through for low stock / expiring / expired
// alerts. Awaits showPage() so allInventory is fully loaded before
// filtering.
async function goToInventoryAlert(status) {
  const navItem = document.querySelector('.nav-item[onclick*="inventory"]');
  await showPage('inventory', navItem);

  const filterEl = document.getElementById('filter-inv-status');
  if (filterEl) {
    filterEl.value = status;
    filterInventory();
  }
}

function buildInventoryRows(data) {
  if (!data.length) {
    return emptyRow(7, "No medicines found", "Try a different search or status, or add a medicine.");
  }
  return data.map(m => {
    const { statuses } = getMedStatuses(m.quantity, m.expiry_date);
    return `
      <tr id="med-row-${m.id}">
        <td><strong>${escapeHtml(m.name)}</strong></td>
        <td>${m.quantity ?? 0}</td>
        <td>${escapeHtml(capitalizeWords(m.unit ?? ''))}</td>
        <td>${formatDateLong(m.expiry_date)}</td>
        <td style="min-width:120px">${getStockLevel(m.quantity)}</td>
        <td>${getStatusPill(statuses)}</td>
        <td class="actions-cell">
          <button class="btn-sm icon-only" onclick="openEditMedicine(${m.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>
          <button class="btn-sm danger" onclick="deleteMedicine(${m.id})">Archive</button>
        </td>
      </tr>
    `;
  }).join("");
}

async function renderInventory() {
  const res    = await fetch(INV_API);
  allInventory = await res.json();

  // Reset search box + status filter back to "All" every time this page
  // is (re)loaded, instead of keeping the previous selection.
  document.getElementById("search-inventory").value   = "";
  document.getElementById("filter-inv-status").value  = "";

  document.getElementById("tbody-inventory").innerHTML = buildInventoryRows(allInventory);
}

function filterInventory() {
  const q      = document.getElementById("search-inventory").value.toLowerCase();
  const status = document.getElementById("filter-inv-status").value;
  const filtered = allInventory.filter(m => {
    const { statuses } = getMedStatuses(m.quantity, m.expiry_date);
    const matchQ  = !q || (m.name ?? "").toLowerCase().includes(q);
    // "Expiry Alert" is the same combined Expired + Expiring Soon count
    // shown on the Dashboard's "Expiry Alert Items" card — a medicine
    // can carry either (or, for Expired specifically, only that one —
    // see getMedStatuses) so this is an OR match, not a single status.
    const matchS  = !status
      || (status === 'Expiry Alert'
            ? (statuses.includes('Expired') || statuses.includes('Expiring Soon'))
            : statuses.includes(status));
    return matchQ && matchS;
  });
  document.getElementById("tbody-inventory").innerHTML = buildInventoryRows(filtered);
}

// Loads inventory data for the dashboard: populates the low-stock /
// expiry stat cards. All banners (critical included) have been removed
// from the Dashboard — alerts now live exclusively in the Notification
// Center bell, so this only ever updates the two stat numbers.
async function loadDashboardInventoryAlerts() {
  try {
    const res  = await fetch(INV_API);
    const data = await res.json();
    if (Array.isArray(data)) {
      const { lowStock, expiringSoon, expired } = getInventoryAlerts(data);
      const dsLow = document.getElementById("ds-low");
      const dsExp = document.getElementById("ds-expiry");
      if (dsLow) dsLow.textContent = lowStock.length;
      if (dsExp) dsExp.textContent = expired.length + expiringSoon.length;
    }
  } catch (err) { console.error("Failed to load inventory alerts:", err); }
}



function openAddMedicine() {
  editingMedId = null;
  originalMedData = {};
  clearMedicineForm();
  document.getElementById("medicineModalTitle").textContent = "Add Medicine";
  document.getElementById("medicineModal").style.display = "flex";
}

function closeMedicineModal() {
  document.getElementById("medicineModal").style.display = "none";
  editingMedId = null;
  originalMedData = {};
  clearMedicineForm();
}

function clearMedicineForm() {
  document.getElementById("med-id").value      = "";
  document.getElementById("med-name").value    = "";
  document.getElementById("med-quantity").value = "";
  document.getElementById("med-unit").value    = "";
  document.getElementById("med-expiry").value  = "";
}

async function saveMedicine() {
  const payload = {
    id:          editingMedId,
    name:        capitalizeWords(document.getElementById("med-name").value.trim()),
    quantity:    parseInt(document.getElementById("med-quantity").value),
    unit:        document.getElementById("med-unit").value,
    expiry_date: document.getElementById("med-expiry").value
  };

  const medValidation = CSFValidate.validateFields([
    { id: 'med-name',     label: 'Medicine Name', required: true },
    { id: 'med-quantity', label: 'Quantity',      required: true, type: 'numeric', min: 0 },
    { id: 'med-unit',     label: 'Unit',          required: true },
    { id: 'med-expiry',   label: 'Expiry Date',   required: true, type: 'date' },
  ]);
  if (!medValidation.valid) { showNotif(`⚠️ ${medValidation.firstError}`, 'warn'); return; }

  if (editingMedId) {
    const noChanges =
      payload.name        === originalMedData.name        &&
      payload.quantity    == originalMedData.quantity     &&
      payload.unit        === originalMedData.unit        &&
      payload.expiry_date === originalMedData.expiry_date;
    if (noChanges) { showNotif("⚠️ Walang nabago.", "warn"); return; }
  }

  const method = editingMedId ? "PUT" : "POST";
  try {
    const res    = await fetch(INV_API, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, "warn"); return; }

    showNotif(editingMedId ? "✏️ Medicine updated!" : "✅ Medicine added!", editingMedId ? "update" : "success");
    closeMedicineModal();
    renderInventory();
    // The server already re-detected low_stock/out_of_stock/expiry alerts
    // for this save (see api/inventory.php) — the global fetch wrapper at
    // the top of this file pulls that into the bell/badge automatically.
  } catch (err) {
    showNotif("⚠️ Could not reach the server. Check api/inventory.php.", "warn");
  }
}

async function openEditMedicine(id) {
  const res = await fetch(`${INV_API}?id=${id}`);
  const m   = await res.json();
  editingMedId = m.id;
  originalMedData = {
    name:        m.name        ?? "",
    quantity:    m.quantity    ?? 0,
    unit:        m.unit        ?? "",
    expiry_date: m.expiry_date ?? ""
  };
  document.getElementById("medicineModalTitle").textContent = "Edit Medicine";
  document.getElementById("med-id").value       = m.id;
  document.getElementById("med-name").value     = m.name        ?? "";
  document.getElementById("med-quantity").value = m.quantity    ?? "";
  document.getElementById("med-unit").value     = m.unit        ?? "";
  document.getElementById("med-expiry").value   = m.expiry_date ?? "";
  document.getElementById("medicineModal").style.display = "flex";
}

async function deleteMedicine(id) {
  if (!await csfConfirm({ title: 'Archive Medicine?', message: 'It will be hidden from inventory and the treatment dropdown, but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
  const res    = await fetch(`${INV_API}?id=${id}`, { method: "DELETE" });
  const result = await res.json();
  if (result.success) {
    renderInventory();
    showNotif("🗄️ Medicine archived. Find it in Archived Records.", "delete");
    // Server already resolved any active alert for this medicine (see
    // api/inventory.php); the global fetch wrapper reflects that in the
    // bell automatically.
  } else {
    showNotif(`⚠️ ${result.message || "Delete failed."}`, "warn");
  }
}

// ── USER DROPDOWN MENU ──
function toggleUserMenu(event) {
  event.stopPropagation();
  document.querySelector(".user-menu-wrap").classList.toggle("open");
}

// Close the dropdown when clicking anywhere outside it.
document.addEventListener("click", (event) => {
  const wrap = document.querySelector(".user-menu-wrap");
  if (wrap && wrap.classList.contains("open") && !wrap.contains(event.target)) {
    wrap.classList.remove("open");
  }
});

// ── NOTIFICATION CENTER ──────────────────────────────────────
// Aggregates real, actionable alerts (low/out of stock, expiring
// medicine, guardian contact missing, referral created, SMS sent/
// failed, session today/rescheduled). The server filters by role —
// Clinic Staff never receives Guidance alerts and vice versa,
// Administrator receives both plus System — and now also tracks
// read/unread PER USER server-side (notification_reads), so one
// person opening a notification never affects anyone else's badge
// or unread list.
let _notifCache = [];

// Icon per alert type, matching the priority palette: 🔴 Critical,
// 🟠 Warning, 🔵 Information, 🟢 Success. Falls back to a
// severity-based icon for any future type not listed here.
function notifIcon(n) {
  const map = {
    expired: '🔴', out_of_stock: '🔴', sms_failed: '🔴', session_overdue: '🔴',
    emergency_case: '🔴', system_alert: '🔴',
    low_stock: '🟠', expiring_soon: '🟠', guardian_missing: '🟠', session_rescheduled: '🟠', case_inactive: '🟠',
    student_sent_home: '🟠', referral_pending: '🟠',
    session_today: '🔵', referral_created: '🔵',
    clinic_visit_today: '🔵', new_user: '🔵', archive_event: '🔵',
    sms_sent: '🟢', reports_ready: '🟢', referral_resolved: '🟢',
  };
  if (map[n.type]) return map[n.type];
  return { critical: '🔴', warning: '🟠', info: '🔵', success: '🟢' }[n.severity] || '🔵';
}

// Briefly scrolls to and highlights one exact table row after a
// notification click lands on its module — this is what "locate the
// exact record" means. It never opens any modal or edit form; the user
// still has to click that row's own Edit button themselves.
function highlightRecordRow(rowId) {
  const el = document.getElementById(rowId);
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.classList.add('notif-target-row');
  setTimeout(() => el.classList.remove('notif-target-row'), 2500);
}

// Where each alert type sends the user when clicked, using the
// target_page/target_id the server already resolved.
async function goToNotification(n) {
  if (!n.is_read) await markNotifRead(n.id, /*silent*/ true);
  const wrap = document.querySelector(".notif-bell-wrap");
  if (wrap) wrap.classList.remove("open");
  resetNotifPanelScroll();

  // Zero-stock and low-stock are mutually-exclusive statuses (see
  // getMedStatuses' own qty<=0-first priority above) — an out_of_stock
  // notification must route to the "Out of Stock" filter, not "Low
  // Stock", or clicking it would land on a filtered view that hides the
  // very medicine the notification was about.
  const INV_STATUS = { low_stock: 'Low Stock', out_of_stock: 'Out of Stock', expiring_soon: 'Expiring Soon', expired: 'Expired' };

  if (n.target_page === 'inventory') {
    // Filters to the alert's category (existing behavior) AND scrolls
    // straight to the exact medicine the notification was about.
    await goToInventoryAlert(INV_STATUS[n.type] || '');
    if (n.target_id) highlightRecordRow('med-row-' + n.target_id);
  } else if (n.target_page === 'counseling') {
    // Navigate to Counseling Sessions only — do NOT auto-open Edit.
    // The notification's job is to point the user at the right record;
    // they choose Edit manually from there via the existing Edit button.
    const navItem = document.querySelector('.nav-item[onclick*="\'counseling\'"]');
    await showPage('counseling', navItem);
    if (n.target_id) highlightRecordRow('counseling-row-' + n.target_id);
  } else if (n.target_page === 'referrals') {
    const navItem = document.querySelector('.nav-item[onclick*="\'referrals\'"]');
    await showPage('referrals', navItem);
    if (n.target_id && typeof viewReferral === 'function') viewReferral(n.target_id);
  } else if (n.target_page === 'sms') {
    const navItem = document.querySelector('.nav-item[onclick*="\'sms\'"]');
    await showPage('sms', navItem);
    if (n.target_id && typeof viewSmsDetails === 'function') viewSmsDetails(n.target_id);
  } else if (n.target_page === 'student_profile') {
    if (n.target_id && typeof openStudentProfile === 'function') openStudentProfile(n.target_id);
  } else if (n.target_page === 'student_edit') {
    // Guardian Contact Missing routes to the existing (read-only)
    // Student Profile view, scoped to Clinic data, so staff can review
    // the guardian contact field before deciding to edit it themselves
    // — it must NOT jump straight into the Edit Student form.
    if (n.target_id && typeof openStudentProfile === 'function') await openStudentProfile(n.target_id, 'clinic');
  } else if (n.target_page === 'clinic-visits') {
    // Navigate to Clinic Visit Logs and scroll to the exact visit — do
    // NOT auto-open Edit Clinic Visit. The notification's job is to
    // point the user at the right record; they choose Edit manually
    // from there via the existing Edit button, same pattern as the
    // 'inventory' branch above.
    const navItem = document.querySelector('.nav-item[onclick*="\'clinic-visits\'"]');
    await showPage('clinic-visits', navItem);
    if (n.target_id) highlightRecordRow('visit-row-' + n.target_id);
  } else if (n.target_page === 'users') {
    // Navigate to User Management and highlight the exact account — do
    // NOT auto-open Edit User, same "locate, let the user decide" rule
    // as every other notification type above.
    const navItem = document.querySelector('.nav-item[onclick*="\'users\'"]');
    await showPage('users', navItem);
    if (n.target_id) highlightRecordRow('user-row-' + n.target_id);
  } else if (n.target_page === 'reports') {
    const navItem = document.querySelector('.nav-item[onclick*="\'reports\'"]');
    await showPage('reports', navItem);
  } else if (n.target_page === 'archived' || n.target_page?.startsWith('archived:')) {
    // config/audit.php's generic archive-notification hook (log_audit)
    // encodes which Archived Records tab this record lives in directly
    // into target_page as "archived:{tab}" — derived server-side from
    // the real module/action of the archive call, not from this
    // notification's rendered title. Bare "archived" (no tab) is a
    // defensive fallback for any older/unrecognized notification row.
    const tab = n.target_page.includes(':') ? n.target_page.split(':')[1] : 'students';
    await goToArchivedRecord(tab, n.target_id);
  }
  refreshNotifBadge();
}

// Scrolls the notification dropdown (.notif-panel, the actual
// overflow-y:auto element — #notifPanelBody inside it is not the
// scroll container) back to the top. Called whenever the dropdown
// opens or closes so it never "remembers" a previous scroll position:
// on open, so a reopen always shows the latest/top notifications
// first even before loadNotifications()'s fetch resolves; on close
// (via bell toggle, outside click, or a notification click), so the
// next open doesn't inherit wherever the list happened to be scrolled.
function resetNotifPanelScroll() {
  const panel = document.getElementById("notifPanel");
  if (panel) panel.scrollTop = 0;
}

function toggleNotifPanel(event) {
  event.stopPropagation();
  const wrap = document.querySelector(".notif-bell-wrap");
  wrap.classList.toggle("open");
  resetNotifPanelScroll();
  if (wrap.classList.contains("open")) loadNotifications();
}

document.addEventListener("click", (event) => {
  const wrap = document.querySelector(".notif-bell-wrap");
  if (wrap && wrap.classList.contains("open") && !wrap.contains(event.target)) {
    wrap.classList.remove("open");
    resetNotifPanelScroll();
  }
});

function _buildNotifItemHtml(n, idx) {
  const unread = !n.is_read;
  // created_at is set once when the alert first appears and is never
  // rewritten by notify_upsert's re-detection UPDATE, so this is stable
  // across polls — always shown, unlike the old event_date-only line
  // which stayed blank for event-based types (e.g. New User) that never
  // pass an eventDate.
  const dateLine = `<div class="notif-item-time">${formatRelativeTime(n.created_at)}</div>`;
  return `
    <div class="notif-item ${unread ? 'unread' : ''}" onclick="goToNotification(_notifCache[${idx}])">
      <span class="notif-item-icon">${notifIcon(n)}</span>
      <div class="notif-item-body">
        <div class="notif-item-title">${unread ? '<span class="unread-marker"></span>' : ''}${escapeHtml(n.title)}</div>
        <div class="notif-item-detail">${escapeHtml(n.detail)}</div>
        ${dateLine}
      </div>
    </div>
  `;
}

// Builds the notification panel body HTML from the current _notifCache.
// Pulled out of loadNotifications() so refreshNotifBadge() (which runs on
// page load and every poll, whether or not the panel is open) can render
// the same list without duplicating this logic — see refreshNotifBadge
// below for why that matters.
function renderNotifPanelBody() {
  const body = document.getElementById("notifPanelBody");
  if (!body) return;

  if (!_notifCache.length) {
    body.innerHTML = `<div class="rep-empty">You're all caught up.</div>`;
    return;
  }

  // The API orders unread items first (see api/notifications.php), so a
  // single "Earlier" label right before the first already-read item is
  // enough to separate what's new from what isn't.
  let html = '';
  let dividerShown = false;
  _notifCache.forEach((n, idx) => {
    if (!dividerShown && n.is_read) {
      html += `<div class="notif-section-label">Earlier</div>`;
      dividerShown = true;
    }
    html += _buildNotifItemHtml(n, idx);
  });
  body.innerHTML = html;
}

async function loadNotifications() {
  const body = document.getElementById("notifPanelBody");
  try {
    const res  = await fetch("api/notifications.php", { cache: 'no-store' });
    const data = await res.json();

    if (!data.success) {
      if (body) body.innerHTML = `<div class="rep-empty">${escapeHtml(data.message || 'Could not load notifications.')}</div>`;
      return;
    }

    _notifCache = data.notifications;
    updateNotifBadge(data.counts);
    renderNotifPanelBody();
  } catch (err) {
    if (body) body.innerHTML = `<div class="rep-empty">Could not reach the server.</div>`;
  }
}

// Badge reflects UNREAD critical+warning+info+success items — the
// server already scopes counts to this user's own read state. The
// header bell is the single primary notification indicator.
function updateNotifBadge(counts) {
  const total = (counts.critical || 0) + (counts.warning || 0) + (counts.info || 0) + (counts.success || 0);

  const badge = document.getElementById("notifBellBadge");
  if (badge) {
    if (total > 0) {
      badge.textContent = total > 99 ? '99+' : total;
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
    }
  }
}

// Administrator dashboard's "System Health" card — same heuristic as
// loadAdminDashboard() originally had inline (any active critical
// notification means "Needs Attention"), pulled out into its own
// function so refreshNotifBadge() can drive it too, not just the
// dashboard's own initial load. Takes the SAME notifications array
// refreshNotifBadge() already fetched for the bell — no extra request.
// A no-op on Clinic/Guidance (the card doesn't exist in their DOM) and
// a no-op if the Dashboard page was never rendered yet.
function updateSystemHealthCard(notifications) {
  const healthEl = document.getElementById("ds-admin-health");
  if (!healthEl) return; // Clinic Staff / Guidance Staff have no health card

  const healthCard = document.getElementById("ds-admin-health-card");
  const healthIcon = healthCard?.querySelector(".icon-bg");
  const criticalCount = notifications.filter(n => n.severity === 'critical' && n.is_active).length;
  if (criticalCount > 0) {
    healthEl.textContent = "Needs Attention";
    healthCard.classList.add('red'); healthCard.classList.remove('green');
    if (healthIcon) healthIcon.innerHTML = '<i class="fi fi-rr-triangle-warning"></i>';
  } else {
    healthEl.textContent = "Healthy";
    healthCard.classList.add('green'); healthCard.classList.remove('red');
    if (healthIcon) healthIcon.innerHTML = '<i class="fi fi-rr-check"></i>';
  }
}

// Runs on page load and every poll interval (see DOMContentLoaded below),
// and — since the global fetch wrapper at the top of this file calls it
// after every successful save/delete/restore across the whole app — also
// runs right after any action that could change a critical notification
// (e.g. a medicine going out of stock, a referral going overdue). That
// same trigger now updates the System Health card too, so "Needs
// Attention" appears immediately instead of only after the next 60s poll
// or a manual return to the Dashboard page. Previously this only updated
// the badge count and _notifCache, leaving #notifPanelBody stuck on its
// static "Loading..." placeholder until the user's first click triggered
// loadNotifications() — badge said "1" but the panel had nothing to show
// yet. Rendering the panel body here too (via the same renderNotifPanelBody
// used by loadNotifications) means the list is already populated before
// the bell is ever clicked, and updates live if a poll lands while the
// panel happens to already be open.
async function refreshNotifBadge() {
  try {
    const res  = await fetch("api/notifications.php", { cache: 'no-store' });
    const data = await res.json();
    if (data.success) {
      _notifCache = data.notifications;
      updateNotifBadge(data.counts);
      renderNotifPanelBody();
      updateSystemHealthCard(_notifCache);
    }
  } catch (err) { /* badge/panel just won't refresh this cycle */ }
}

// Marks one notification read for the current user only — every other
// recipient's read state is completely untouched. `silent` skips the
// panel/history re-render for the common case where the caller (e.g.
// goToNotification) is about to navigate away anyway.
async function markNotifRead(id, silent = false) {
  // Optimistic badge update — decrement instantly instead of waiting on
  // the round trip, so the click feels immediate. The next poll/refetch
  // (refreshNotifBadge, called right after this everywhere it's used)
  // reconciles against the server's real counts regardless, so a lost
  // request can't leave the badge permanently wrong.
  const n = _notifCache.find(x => x.id === id);
  if (n && !n.is_read && n.is_active) {
    const badge = document.getElementById("notifBellBadge");
    if (badge && badge.style.display !== 'none') {
      const current = parseInt(badge.textContent, 10);
      if (!isNaN(current) && current > 0) {
        const next = current - 1;
        badge.textContent = next;
        if (next <= 0) badge.style.display = 'none';
      }
    }
    n.is_read = true;
  }

  try {
    await fetch("api/notifications.php", {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', id }),
    });
  } catch (err) { /* best-effort */ }
  if (!silent) {
    loadNotifications();
    if (document.getElementById('page-notif-history')?.classList.contains('active')) loadNotifHistoryPage();
  }
}

async function markAllNotificationsRead(event) {
  if (event) event.stopPropagation();
  try {
    await fetch("api/notifications.php", {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read' }),
    });
  } catch (err) { /* best-effort */ }
  loadNotifications();
  if (document.getElementById('page-notif-history')?.classList.contains('active')) loadNotifHistoryPage();
}

// Fetch notifications on load (badge AND panel list — see refreshNotifBadge
// above) so the list is already rendered before the bell is ever clicked,
// and keep both current with periodic polling thereafter.
document.addEventListener("DOMContentLoaded", () => {
  if (document.getElementById("notifBellBadge")) {
    refreshNotifBadge();
    setInterval(refreshNotifBadge, 60 * 1000); // every minute
  }
});

// ── NOTIFICATION HISTORY ("View All Notifications") ─────────────
// Full-list view of every notification (active AND resolved) with
// search, severity filter, read/unread filter, and a date filter —
// reached only via the bell panel's footer link (deliberately not
// added to the sidebar, to keep the nav itself unchanged).
function openNotifHistory() {
  const wrap = document.querySelector(".notif-bell-wrap");
  if (wrap) wrap.classList.remove("open");
  showPage('notif-history', null);
  document.getElementById("headerTitle").innerText = "Notification History";
}

async function loadNotifHistoryPage() {
  document.getElementById('search-notif-history').value = '';
  document.getElementById('filter-notif-severity').value = '';
  document.getElementById('filter-notif-read').value = '';
  document.getElementById('filter-notif-date').value = '';
  try {
    const res  = await fetch("api/notifications.php?history=1", { cache: 'no-store' });
    const data = await res.json();
    if (data.success) _notifCache = data.notifications;
  } catch (err) { /* keep whatever was already cached */ }
  renderNotifHistory();
}

function renderNotifHistory() {
  const q        = document.getElementById('search-notif-history').value.toLowerCase();
  const severity = document.getElementById('filter-notif-severity').value;
  const readState = document.getElementById('filter-notif-read').value;
  const dateVal  = document.getElementById('filter-notif-date').value;

  const filtered = _notifCache.filter(n => {
    const matchQ = !q || n.title.toLowerCase().includes(q) || (n.detail || '').toLowerCase().includes(q);
    const matchSeverity = !severity || n.severity === severity;
    const matchRead = !readState || (readState === 'unread' ? !n.is_read : n.is_read);
    const matchDate = !dateVal || n.date === dateVal;
    return matchQ && matchSeverity && matchRead && matchDate;
  });

  const list = document.getElementById('notif-history-list');
  list.innerHTML = !filtered.length
    ? `<div class="rep-empty">No notifications match your filters.</div>`
    : filtered.map(n => {
        // Index into the FULL cache (not the filtered view) so the
        // click handler always resolves to the right notification.
        const idx = _notifCache.indexOf(n);
        return _buildNotifItemHtml(n, idx);
      }).join('');
}

function filterNotifHistory() { renderNotifHistory(); }


// ── CHANGE PASSWORD ──
function openChangePasswordModal() {
  document.querySelector(".user-menu-wrap").classList.remove("open");
  document.getElementById("cp-current").value = "";
  document.getElementById("cp-new").value     = "";
  document.getElementById("cp-confirm").value = "";
  document.getElementById("changePasswordModal").style.display = "flex";
}

function closeChangePasswordModal() {
  document.getElementById("changePasswordModal").style.display = "none";
}

async function submitChangePassword() {
  const current = document.getElementById("cp-current").value;
  const next    = document.getElementById("cp-new").value;
  const confirm = document.getElementById("cp-confirm").value;

  const cpValidation = CSFValidate.validateFields([
    { id: 'cp-current', label: 'Current Password', required: true },
    { id: 'cp-new',     label: 'New Password',     required: true },
    { id: 'cp-confirm', label: 'Confirm New Password', required: true },
  ]);
  if (!cpValidation.valid) { showNotif(`⚠️ ${cpValidation.firstError}`, "warn"); return; }
  if (next.length < 8)       { showNotif("⚠️ New password must be at least 8 characters.", "warn"); return; }
  if (next !== confirm)      { showNotif("⚠️ Passwords do not match.", "warn"); return; }
  if (next === current)      { showNotif("⚠️ New password must be different from current password.", "warn"); return; }

  const form = new FormData();
  form.append("current_password", current);
  form.append("new_password", next);

  try {
    const res    = await fetch("api/change_password.php", { method: "POST", body: form });
    const result = await res.json();

    if (!result.success) {
      showNotif(`⚠️ ${result.message}`, "warn");
      return;
    }

    showNotif("✅ Password updated!", "success");
    closeChangePasswordModal();
  } catch (err) {
    showNotif("⚠️ Could not reach the server.", "warn");
  }
}

// ── CLINIC REPORTS ──
const REPORTS_API = "api/reports.php";

// Formats a "YYYY-MM-DD" string as "August 08, 2026" for the print-only
// report header. Parses the parts manually (not `new Date(str)`) for the
// same reason formatDateMDY does — avoids UTC/local timezone shifting
// the date by a day. Reuses the DASH_MONTHS list already defined for
// the Dashboard's long-form date.
function formatLongDateFromYMD(ymd) {
    if (!ymd) return '';
    const parts = String(ymd).split('-');
    if (parts.length !== 3) return ymd;
    const [y, m, d] = parts;
    const monthIdx = parseInt(m, 10) - 1;
    if (!y || !d || monthIdx < 0 || monthIdx > 11 || typeof DASH_MONTHS === 'undefined') return ymd;
    return `${DASH_MONTHS[monthIdx]} ${d.padStart(2, '0')}, ${y}`;
}

// Populates the print-only report header (school name / system name /
// report title / report period) so the on-screen filters and the printed
// output always show the same range. The header itself is hidden on
// screen and only shown via @media print (see admin.css).
function updateReportPrintHeader(title, from, to) {
    const titleEl  = document.getElementById('report-print-title');
    const periodEl = document.getElementById('report-print-period');
    if (titleEl) titleEl.textContent = title;
    if (periodEl) periodEl.textContent = `Report Period: ${formatLongDateFromYMD(from)} – ${formatLongDateFromYMD(to)}`;
    const prepEl = document.getElementById('report-print-prepared');
    const genEl  = document.getElementById('report-print-generated');
    if (prepEl) prepEl.textContent = (typeof CURRENT_USER_NAME !== 'undefined' && CURRENT_USER_NAME) ? CURRENT_USER_NAME : 'Staff';
    if (genEl)  genEl.textContent  = `Generated via CSF Integrated System on ${formatDateLong(getLocalDateStr())}. For internal school use only.`;
}

function getDefaultReportRange() {
    const now = new Date();
    const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
    return { from: getLocalDateStr(firstDay), to: getLocalDateStr(now) };
}

async function loadReports() {
    const fromEl = document.getElementById('report-from');
    const toEl   = document.getElementById('report-to');

    // First load: populate the date inputs with the default range (this month).
    if (!fromEl.value || !toEl.value) {
        const { from, to } = getDefaultReportRange();
        fromEl.value = from;
        toEl.value   = to;
    }

    await fetchAndRenderReports(fromEl.value, toEl.value);
}

// True when the Guidance report view should be used: either this is
// guidance.php itself, or (on admin.php) the Guidance Reports tab is
// the one currently visible.
function isGuidanceReportsActive() {
    if (typeof CSF_ROLE !== 'undefined' && CSF_ROLE === 'guidance') return true;
    const guidancePanel = document.getElementById('guidance-reports-content');
    return !!(guidancePanel && guidancePanel.style.display !== 'none');
}

async function applyReportRange() {
    const from = document.getElementById('report-from').value;
    const to   = document.getElementById('report-to').value;

    if (!from || !to) {
        showNotif('⚠️ Pumili ng valid date range.', 'warn');
        return;
    }
    if (from > to) {
        showNotif('⚠️ "From" date should not be after "To" date.', 'warn');
        return;
    }

    if (isGuidanceReportsActive()) await fetchAndRenderGuidanceReports(from, to);
    else await fetchAndRenderReports(from, to);
}

function resetReportRange() {
    const { from, to } = getDefaultReportRange();
    document.getElementById('report-from').value = from;
    document.getElementById('report-to').value   = to;
    if (isGuidanceReportsActive()) fetchAndRenderGuidanceReports(from, to);
    else fetchAndRenderReports(from, to);
}

async function fetchAndRenderReports(from, to) {
    const wrap = document.getElementById('reports-content');
    wrap.style.opacity = '0.5';

    try {
        const res  = await fetch(`${REPORTS_API}?from=${from}&to=${to}`);
        const data = await res.json();

        if (!data.success) {
            showNotif(`⚠️ ${data.message || 'Failed to load reports.'}`, 'warn');
            return;
        }

        renderReportSummary(data.summary);
        renderReportBreakdown(data.breakdown);
        renderInventoryAlertsReport(data.inventory);
        renderMedicineAnalytics(data.medicine_analytics);
        updateReportPrintHeader('Clinic Report', data.range?.from ?? from, data.range?.to ?? to);
    } catch (err) {
        showNotif('⚠️ Unable to load reports. Please try again.', 'warn');
    } finally {
        wrap.style.opacity = '1';
    }
}

// Toggles the KPI card's active/zero visual state: non-zero values get a
// gold accent + full-weight number, zero values are muted (a zero means
// "no data yet", not an error, so it should never look alarming).
function setKpiState(valueId, hasValue) {
    const valueEl = document.getElementById(valueId);
    if (!valueEl) return;
    valueEl.classList.toggle('is-zero', !hasValue);
    const card = valueEl.closest('.report-stat-card');
    if (card) card.classList.toggle('has-value', hasValue);
}

function renderReportSummary(summary) {
    const totalVisits = summary.total_visits ?? 0;
    document.getElementById('rep-total-visits').textContent = totalVisits;
    setKpiState('rep-total-visits', totalVisits > 0);

    document.getElementById('rep-top-medicine').textContent =
        summary.most_common_medicine ? summary.most_common_medicine : '—';
    document.getElementById('rep-top-medicine-sub').textContent =
        summary.most_common_medicine ? `${summary.most_common_medicine_count} time(s) given` : 'No data for this range.';
    setKpiState('rep-top-medicine', !!summary.most_common_medicine);

    document.getElementById('rep-top-symptom').textContent =
        summary.most_common_symptom ? summary.most_common_symptom : '—';
    document.getElementById('rep-top-symptom-sub').textContent =
        summary.most_common_symptom ? `${summary.most_common_symptom_count} visit(s)` : 'No data for this range.';
    setKpiState('rep-top-symptom', !!summary.most_common_symptom);

    document.getElementById('rep-top-grade').textContent =
        summary.most_common_grade ? summary.most_common_grade : '—';
    document.getElementById('rep-top-grade-sub').textContent =
        summary.most_common_grade ? `${summary.most_common_grade_count} visit(s)` : 'No data for this range.';
    setKpiState('rep-top-grade', !!summary.most_common_grade);
}

function renderReportBreakdown(breakdown) {
    document.getElementById('rep-medicines-list').innerHTML =
        buildBreakdownRows(breakdown.top_medicines, 'medicine', 'uses', 'time(s)');

    document.getElementById('rep-symptoms-list').innerHTML =
        buildBreakdownRows(breakdown.top_symptoms, 'symptoms', 'occurrences', 'visit(s)');

    document.getElementById('rep-grades-list').innerHTML =
        buildBreakdownRows(breakdown.top_grades, 'grade_section', 'visits', 'visit(s)');
}

// ── MEDICINE ANALYTICS (Clinic Improvements) ──────────────────
// Plain CSS bar chart for the 6-month trend (no external chart library
// dependency), plus quantity-based top medicines and a stock depletion
// estimate based on the last 30 days' average daily usage.
function renderMedicineAnalytics(analytics) {
    if (!analytics) return;

    // Monthly usage trend — simple vertical bar chart.
    const chartEl = document.getElementById('rep-medicine-trend-chart');
    if (chartEl) {
        const trend = analytics.monthly_trend || [];
        const max = Math.max(1, ...trend.map(m => m.qty));
        chartEl.innerHTML = !trend.length
            ? `<div class="rep-empty">No dispensing data yet.</div>`
            : trend.map(m => `
                <div class="chart-bar-col">
                    <div class="chart-bar-value">${m.qty}</div>
                    <div class="chart-bar" style="height:${Math.max(2, Math.round((m.qty / max) * 100))}%"></div>
                    <div class="chart-bar-label">${escapeHtml(m.label)}</div>
                </div>
            `).join('');
    }

    // Top medicines by total quantity dispensed (distinct from "most uses").
    const qtyList = document.getElementById('rep-medicine-qty-list');
    if (qtyList) {
        const items = analytics.top_medicines_by_qty || [];
        qtyList.innerHTML = !items.length
            ? `<div class="rep-empty">No data for this range.</div>`
            : buildBreakdownRows(items, 'medicine', 'total_qty', 'unit(s) dispensed');
    }

    // Stock depletion estimate — soonest-to-run-out first.
    const depletionList  = document.getElementById('rep-depletion-list');
    const depletionCount = document.getElementById('rep-depletion-count');
    if (depletionList) {
        const items = analytics.depletion_estimates || [];
        if (depletionCount) depletionCount.textContent = items.length;
        depletionList.innerHTML = !items.length
            ? `<div class="rep-empty">Not enough usage data to estimate depletion.</div>`
            : items.map(d => {
                const urgent = d.days_left !== null && d.days_left <= 14;
                return `
                    <div class="rep-bar-row ${urgent ? 'rep-depletion-urgent' : ''}">
                        <div class="rep-bar-label">${escapeHtml(d.name)}</div>
                        <div class="rep-bar-track">
                            <div class="rep-bar-fill" style="width:${d.days_left !== null ? Math.max(4, Math.min(100, Math.round((d.days_left / 60) * 100))) : 100}%"></div>
                        </div>
                        <div class="rep-bar-count">${d.days_left !== null ? `~${d.days_left}d left` : 'N/A'}</div>
                    </div>`;
            }).join('');
    }
}

function buildBreakdownRows(items, labelKey, countKey, suffix) {
    if (!items || !items.length) {
        return `<div class="rep-empty">No data for this range.</div>`;
    }
    const max = items[0][countKey] || 1;
    return items.map(item => {
        const label = item[labelKey] && item[labelKey].trim() !== '' ? item[labelKey] : '(blank)';
        const count = item[countKey];
        const pct   = Math.round((count / max) * 100);
        return `
            <div class="rep-bar-row">
                <div class="rep-bar-label">${escapeHtml(label)}</div>
                <div class="rep-bar-track">
                    <div class="rep-bar-fill" style="width:${pct}%"></div>
                </div>
                <div class="rep-bar-count">${count} ${suffix}</div>
            </div>
        `;
    }).join('');
}

// Renders the Low Stock / Out of Stock sections in the Reports page.
// (Named "...Report" to avoid clashing with the dashboard's separate
// loadDashboardInventoryAlerts()/buildAlertBanner() inventory logic.)
function renderInventoryAlertsReport(inventory) {
    document.getElementById('rep-low-stock-list').innerHTML =
        buildStockReportRows(inventory.low_stock, 'low');

    document.getElementById('rep-out-stock-list').innerHTML =
        buildStockReportRows(inventory.out_of_stock, 'out');

    document.getElementById('rep-low-stock-count').textContent = inventory.low_stock.length;
    document.getElementById('rep-out-stock-count').textContent = inventory.out_of_stock.length;
}

function buildStockReportRows(items, type) {
    if (!items || !items.length) {
        return `<div class="rep-empty">${type === 'low' ? 'No low stock items.' : 'No out-of-stock items.'}</div>`;
    }
    return items.map(m => `
        <div class="rep-stock-row ${type === 'out' ? 'rep-stock-out' : 'rep-stock-low'}">
            <span class="rep-stock-name">${escapeHtml(m.name)}</span>
            <span class="rep-stock-qty">${m.quantity} ${escapeHtml(capitalizeWords(m.unit ?? ''))} left</span>
        </div>
    `).join('');
}

// ── GUIDANCE REPORTS (Behavioral Analytics, Counselor Analytics, Case
//    History) — used directly on guidance.php, and via the "Guidance
//    Reports" tab on admin.php. ─────────────────────────────────────
async function loadGuidanceReports() {
    const fromEl = document.getElementById('report-from');
    const toEl   = document.getElementById('report-to');

    if (!fromEl.value || !toEl.value) {
        const { from, to } = getDefaultReportRange();
        fromEl.value = from;
        toEl.value   = to;
    }

    await fetchAndRenderGuidanceReports(fromEl.value, toEl.value);
}

async function fetchAndRenderGuidanceReports(from, to) {
    const wrap = document.getElementById('guidance-reports-content') || document.getElementById('reports-content');
    if (wrap) wrap.style.opacity = '0.5';

    try {
        const res  = await fetch(`${REPORTS_API}?module=guidance&from=${from}&to=${to}`);
        const data = await res.json();

        if (!data.success) {
            showNotif(`⚠️ ${data.message || 'Failed to load reports.'}`, 'warn');
            return;
        }

        renderGuidanceReportSummary(data.summary);
        renderGuidanceReportBreakdown(data.breakdown);
        updateReportPrintHeader('Guidance Report', data.range?.from ?? from, data.range?.to ?? to);
    } catch (err) {
        showNotif('⚠️ Unable to load reports. Please try again.', 'warn');
    } finally {
        if (wrap) wrap.style.opacity = '1';
    }
}

function renderGuidanceReportSummary(summary) {
    const sessions   = summary.total_sessions ?? 0;
    const behavioral = summary.total_behavioral ?? 0;
    const offenses   = summary.total_offenses ?? 0;
    const closed     = summary.total_closed_cases ?? 0;

    document.getElementById('gr-total-sessions').textContent   = sessions;
    document.getElementById('gr-total-behavioral').textContent = behavioral;
    document.getElementById('gr-total-offenses').textContent   = offenses;
    document.getElementById('gr-total-closed').textContent     = closed;

    setKpiState('gr-total-sessions', sessions > 0);
    setKpiState('gr-total-behavioral', behavioral > 0);
    setKpiState('gr-total-offenses', offenses > 0);
    setKpiState('gr-total-closed', closed > 0);

    document.getElementById('gr-top-offense').textContent =
        summary.most_common_offense ? summary.most_common_offense : '—';
    document.getElementById('gr-top-offense-sub').textContent =
        summary.most_common_offense ? `${summary.most_common_offense_count} case(s)` : 'No data for this range.';
    setKpiState('gr-top-offense', !!summary.most_common_offense);
}

function renderGuidanceReportBreakdown(breakdown) {
    document.getElementById('gr-offense-list').innerHTML =
        buildBreakdownRows(breakdown.top_offense_types, 'type', 'occurrences', 'case(s)');

    document.getElementById('gr-counselor-list').innerHTML = !breakdown.counselor_analytics.length
        ? `<div class="rep-empty">No data for this range.</div>`
        : breakdown.counselor_analytics.map(c => `
            <div class="rep-bar-row rep-bar-row-plain" title="${escapeHtml(c.counselor_name)}">
                <div class="rep-bar-label">${escapeHtml(c.counselor_name)}</div>
                <div class="rep-bar-count">${c.closed_sessions}/${c.total_sessions} closed</div>
            </div>
        `).join('');

    document.getElementById('gr-cases-list').innerHTML = !breakdown.top_cases.length
        ? `<div class="rep-empty">No data for this range.</div>`
        : breakdown.top_cases.map(c => `
            <div class="rep-bar-row rep-bar-row-plain" title="${escapeHtml(c.full_name)} ${escapeHtml(c.student_code)}">
                <div class="rep-bar-label">${escapeHtml(c.full_name)}<span class="rep-case-code">${escapeHtml(c.student_code)}</span></div>
                <div class="rep-bar-count">${c.case_count} record(s)</div>
            </div>
        `).join('');
}

// Administrator-only: toggles between the Clinic and Guidance report
// panels on the same Reports page (Administrator has both modules).
function switchReportModule(module, el) {
    document.querySelectorAll('.report-tab').forEach(b => b.classList.remove('active'));
    if (el) el.classList.add('active');

    const clinicPanel   = document.getElementById('reports-content');
    const guidancePanel = document.getElementById('guidance-reports-content');
    if (!clinicPanel || !guidancePanel) return; // not on admin.php's dual-tab layout

    if (module === 'guidance') {
        clinicPanel.style.display = 'none';
        guidancePanel.style.display = 'block';
        loadGuidanceReports();
    } else {
        guidancePanel.style.display = 'none';
        clinicPanel.style.display = 'block';
        loadReports();
    }
}

// ── COUNSELING SESSIONS ──
const COUNSELING_API = "api/counseling.php";
let allCounseling = [];
let editingCounselingId = null;
let originalCounselingData = {};

// Shared instance: powers the Student search box inside the Add/Edit
// Counseling Session modal.
const counselingStudentDropdown = document.getElementById('counseling-student-search') ? new SearchDropdown({
    input: '#counseling-student-search',
    hidden: '#counseling-student-id',
    data: [],
    type: 'student',
    valueField: 'id',
    titleField: 'full_name',
    subtitleField: 'student_code',
    extraField: 'grade_section',
    placeholder: 'Type ID or Name...',
    noResultsText: 'No matching students.',
    onSelect: (student) => { loadCounselingLinkOptions(student.id); },
    onClear: () => { loadCounselingLinkOptions(null); }
}) : null;

// Populates the "Linked Behavioral Record" / "Linked Offense / Violation"
// pickers in the Counseling Session modal with that student's own
// records — Behavioral Records and Offenses & Violations may exist
// independently, but can optionally be tied to the session here.
async function loadCounselingLinkOptions(studentId, selectedBehavioralId, selectedOffenseId) {
    const behavioralSel = document.getElementById('counseling-behavioral-id');
    const offenseSel    = document.getElementById('counseling-offense-id');
    if (!behavioralSel || !offenseSel) return;

    if (!studentId) {
        behavioralSel.innerHTML = `<option value="">-- None --</option>`;
        offenseSel.innerHTML    = `<option value="">-- None --</option>`;
        return;
    }

    const [behavioralRes, offenseRes] = await Promise.all([
        fetch(`${BEH_API}?student_id=${studentId}`),
        fetch(`${OFF_API}?student_id=${studentId}`)
    ]);
    const behavioralRecords = await behavioralRes.json();
    const offenseRecords    = await offenseRes.json();

    behavioralSel.innerHTML = `<option value="">-- None --</option>` + behavioralRecords.map(b => `
        <option value="${b.id}">${formatDateLong(b.recorded_at)} — ${escapeHtml(b.behavior)}</option>
    `).join('');
    offenseSel.innerHTML = `<option value="">-- None --</option>` + offenseRecords.map(o => `
        <option value="${o.id}">${formatDateLong(o.recorded_at)} — ${escapeHtml(o.type)}</option>
    `).join('');

    if (selectedBehavioralId) behavioralSel.value = String(selectedBehavioralId);
    if (selectedOffenseId)    offenseSel.value    = String(selectedOffenseId);
}

async function openAddCounseling() {
    editingCounselingId = null;
    originalCounselingData = {};
    clearCounselingForm();
    setCounselingFormReadOnly(false);
    document.getElementById("counselingModalTitle").textContent = "Add Counseling Session";
    // New sessions always start as Scheduled — no status field to show/pick.
    document.getElementById("counseling-status-group").style.display = "none";
    document.getElementById("counseling-timeline-wrap").style.display = "none";
    if (!allStudents.length) await renderStudents();
    counselingStudentDropdown.setData(allStudents);
    document.getElementById("counseling-counselor-display").value =
        typeof CURRENT_USER_NAME !== "undefined" ? CURRENT_USER_NAME : "";
    document.getElementById("counseling-date").value = getLocalDateStr();
    document.getElementById("counseling-date").min   = getLocalDateStr();
    document.getElementById("counselingModal").style.display = "flex";
}

function closeCounselingModal() {
    document.getElementById("counselingModal").style.display = "none";
    editingCounselingId = null;
    originalCounselingData = {};
    clearCounselingForm();
    setCounselingFormReadOnly(false);
}

function clearCounselingForm() {
    counselingStudentDropdown.clear();
    document.getElementById("counseling-counselor-display").value   = "";
    document.getElementById("counseling-referred-by").value         = "";
    document.getElementById("counseling-source").value               = "";
    document.getElementById("counseling-other-source").value         = "";
    document.getElementById("counseling-other-source-wrap").style.display = "none";
    document.getElementById("counseling-concern").value              = "";
    document.getElementById("counseling-status-display").value      = "";
    document.getElementById("counseling-status-group").style.display = "none";
    document.getElementById("counseling-date").value                = "";
    loadCounselingLinkOptions(null);
}

// Shows the required "Other Source" textbox only when Source is set to
// "Other Guidance Concerns"; hidden (and irrelevant) for every other source.
function toggleOtherSourceField() {
    const isOther = document.getElementById("counseling-source").value === "Other Guidance Concerns";
    document.getElementById("counseling-other-source-wrap").style.display = isOther ? "" : "none";
    if (!isOther) document.getElementById("counseling-other-source").value = "";
}

// Closed Counseling Sessions are locked: not editable, not reopenable —
// only viewable. Toggles the Add/Edit modal into a read-only "View" mode.
function setCounselingFormReadOnly(readOnly) {
    const fieldIds = [
        "counseling-behavioral-id", "counseling-offense-id",
        "counseling-referred-by",
        "counseling-date", "counseling-source", "counseling-other-source", "counseling-concern"
    ];
    fieldIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = readOnly;
    });
    // Student search is a SearchDropdown component, not a plain <input> —
    // its clear (x) button is a separate element that el.disabled alone
    // never reaches, so it's toggled through the component's own API.
    // See setDisabled() in search-dropdown.js.
    if (counselingStudentDropdown) counselingStudentDropdown.setDisabled(readOnly);
    const saveBtn = document.getElementById("counseling-save-btn");
    if (saveBtn) saveBtn.style.display = readOnly ? "none" : "";
}

// Shared status → pill class mapping for Counseling Sessions
// (Scheduled -> blue, Ongoing -> yellow, Overdue -> red, Closed -> gray/locked).
function counselingStatusPillClass(status) {
    if (status === 'Closed') return 'pill-gray';
    if (status === 'Ongoing') return 'pill-yellow';
    if (status === 'Overdue') return 'pill-red';
    return 'pill-blue'; // Scheduled
}

// Navigates to the Counseling page and highlights one session row.
// Generic navigation helper (same pattern as goToDashboardCard /
// goToArchivedRecord) — not part of the notification system, just reused
// by it and now by the Upcoming Follow-ups card below.
async function goToCounselingRecord(id) {
    const navItem = document.querySelector('.nav-item[onclick*="\'counseling\'"]');
    await showPage('counseling', navItem);
    if (id) highlightRecordRow('counseling-row-' + id);
}

// Guidance dashboard — "Upcoming Follow-ups" card. Built entirely from
// the `counseling` array loadGuidanceDashboard() already fetches for the
// "Active Counseling Cases" stat and the Recent Activity timeline, so
// this adds zero new API calls. Only real session_date/status values
// already in the database are used — nothing here is invented.
//
// Buckets (most urgent first): Overdue, Due Today, Due Tomorrow, then
// Upcoming (Scheduled, within the next 7 days). Capped at 8 items total
// so the card can't grow to overwhelm the page on a busy day.
function renderUpcomingFollowups(counseling) {
    const listEl  = document.getElementById('upcoming-followups-list');
    const countEl = document.getElementById('upcoming-followups-count');
    if (!listEl) return; // not on this role's dashboard

    const sessions = Array.isArray(counseling) ? counseling : [];
    const todayStr = getLocalDateStr();
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const tomorrowStr = getLocalDateStr(tomorrow);
    const weekOut = new Date();
    weekOut.setDate(weekOut.getDate() + 7);
    const weekOutStr = getLocalDateStr(weekOut);

    const overdue  = sessions.filter(c => c.status === 'Overdue');
    const dueToday = sessions.filter(c => c.status === 'Ongoing' && c.session_date === todayStr);
    const dueTomorrow = sessions.filter(c => c.status === 'Scheduled' && c.session_date === tomorrowStr);
    const upcoming = sessions.filter(c => c.status === 'Scheduled' && c.session_date > tomorrowStr && c.session_date <= weekOutStr)
        .sort((a, b) => (a.session_date || '').localeCompare(b.session_date || ''));

    const totalCount = overdue.length + dueToday.length + dueTomorrow.length + upcoming.length;
    if (countEl) countEl.textContent = totalCount;

    if (!totalCount) {
        listEl.innerHTML = '<div class="tl-empty">No follow-ups due in the next 7 days.</div>';
        return;
    }

    const row = (c, subLabel) => `
        <div class="pending-task-item clickable" id="followup-row-${c.id}" onclick="goToCounselingRecord(${c.id})">
            <div>
                <div class="pt-title">${escapeHtml(c.student_name)} <span style="color:#aaa;font-weight:400;font-size:.72rem">${escapeHtml(c.student_code || '')}</span></div>
                <div class="pt-sub">Counseling Session · ${subLabel}</div>
            </div>
        </div>`;

    const sections = [];
    if (overdue.length)     sections.push(`<div class="pt-section-label">Overdue</div>` + overdue.slice(0, 8).map(c => row(c, 'Overdue')).join(''));
    let remaining = 8 - overdue.length;
    if (remaining > 0 && dueToday.length) {
        sections.push(`<div class="pt-section-label">Today</div>` + dueToday.slice(0, remaining).map(c => row(c, 'Due today')).join(''));
        remaining -= dueToday.length;
    }
    if (remaining > 0 && dueTomorrow.length) {
        sections.push(`<div class="pt-section-label">Tomorrow</div>` + dueTomorrow.slice(0, remaining).map(c => row(c, 'Due tomorrow')).join(''));
        remaining -= dueTomorrow.length;
    }
    if (remaining > 0 && upcoming.length) {
        sections.push(`<div class="pt-section-label">Upcoming</div>` + upcoming.slice(0, remaining).map(c => row(c, formatDateLong(c.session_date))).join(''));
    }

    listEl.innerHTML = sections.join('');
}

function buildCounselingRows(data) {
    if (!data.length) {
        return emptyRow(8, "No counseling sessions found", "Try a different search or status, or schedule a new session.");
    }
    return data.map(c => {
        const links = [];
        if (c.behavioral_record_id) links.push(`<span class="pill pill-gray">📋 Behavioral</span>`);
        if (c.offense_id)           links.push(`<span class="pill pill-gray">🚫 Offense</span>`);
        const linkBadges = links.length ? links.join(' ') : '<span style="color:#888">N/A</span>';

        const isClosed  = c.status === 'Closed';
        const isOngoing = c.status === 'Ongoing';
        const isScheduled = c.status === 'Scheduled';
        const isOverdue = c.status === 'Overdue';

        // Action buttons per status:
        //   Scheduled → Start Session, Edit, Archive
        //   Overdue   → Start Session (late), Edit, Archive
        //   Ongoing   → Done, Edit, Archive
        // Closed sessions never reach this table anymore — renderCounseling()
        // only fetches status=Active — but the isClosed branches below are
        // kept as a defensive no-op in case this function is ever reused
        // against an unfiltered dataset.
        const doneBtn = isOngoing
            ? `<button class="btn-sm icon-only" style="border-color:#2e7d5a;color:#2e7d5a" onclick="openCompleteCounseling(${c.id})" aria-label="Done" title="Done"><i class="fi fi-rr-check btn-ico" aria-hidden="true"></i></button>`
            : '';
        const startBtn = (isScheduled || isOverdue)
            ? `<button class="btn-sm icon-only" style="border-color:#2563eb;color:#2563eb" onclick="startCounseling(${c.id})" aria-label="Start Session" title="Start Session"><i class="fi fi-rr-play btn-ico" aria-hidden="true"></i></button>`
            : '';
        const reopenBtn = isClosed
            ? `<button class="btn-sm icon-only" style="border-color:#c98a1a;color:#c98a1a" onclick="reopenCounseling(${c.id})" aria-label="Reopen" title="Reopen"><i class="fi fi-rr-unlock btn-ico" aria-hidden="true"></i></button>`
            : '';
        // View Details — read-only modal showing the saved Session Notes /
        // Action Taken / Recommendations / Outcome (see openCounselingDetails).
        const eyeBtn = `<button class="btn-sm icon-only" onclick="openCounselingDetails(${c.id})" aria-label="View Details" title="View Details"><i class="fi fi-rr-eye btn-ico" aria-hidden="true"></i></button>`;

        const editOrViewBtn = isClosed
            ? eyeBtn
            : `<button class="btn-sm icon-only" onclick="openEditCounseling(${c.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>`;

        // Status column: Closed shows a locked pill.
        const statusCell = `<span class="pill ${counselingStatusPillClass(c.status)}">${isClosed ? '🔒 ' : ''}${escapeHtml(c.status)}</span>`;

        return `
        <tr id="counseling-row-${c.id}">
            <td>${formatDateLong(c.session_date)}</td>
            <td><strong>${escapeHtml(c.student_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(c.student_code)}</span></td>
            <td>${escapeHtml(c.counselor_name) || 'Unassigned'}</td>
            <td>${linkBadges}</td>
            <td>${c.source ? escapeHtml(c.source === 'Other Guidance Concerns' && c.other_source ? c.other_source : c.source) : 'N/A'}</td>
            <td>${c.concern && c.concern.trim() !== '' ? escapeHtml(c.concern) : (c.notes && c.notes.trim() !== '' ? escapeHtml(c.notes) : 'N/A')}</td>
            <td>${statusCell}</td>
            <td class="actions-cell">
                ${doneBtn}
                ${startBtn}
                ${editOrViewBtn}
                ${reopenBtn}
                <button class="btn-sm danger" onclick="deleteCounseling(${c.id})">Archive</button>
            </td>
        </tr>
    `;
    }).join('');
}

async function renderCounseling() {
    // Counseling Sessions = active/open cases only. Closed cases live in
    // Close Case (see renderCloseCase below) — excluded here at the data
    // source (api/counseling.php's own query, via status=Active), not by
    // hiding rows client-side, so a Closed case can never show up here.
    const res = await fetch(`${COUNSELING_API}?status=Active`);
    allCounseling = await res.json();

    // Reset search box + status filter back to "All" every time this
    // page is (re)loaded, instead of keeping the previous selection.
    document.getElementById("search-counseling").value          = "";
    document.getElementById("filter-counseling-status").value   = "";

    document.getElementById("tbody-counseling").innerHTML = buildCounselingRows(allCounseling);
}

function filterCounseling() {
    const q = document.getElementById("search-counseling").value.toLowerCase();
    const status = document.getElementById("filter-counseling-status").value;
    const filtered = allCounseling.filter(c => {
        const matchSearch = !q ||
            (c.student_name ?? '').toLowerCase().includes(q) ||
            (c.student_code ?? '').toLowerCase().includes(q);
        const matchStatus = !status || c.status === status;
        return matchSearch && matchStatus;
    });
    document.getElementById("tbody-counseling").innerHTML = buildCounselingRows(filtered);
}

async function saveCounseling() {
    if (editingCounselingId && originalCounselingData.status === 'Closed') {
        showNotif("🔒 This counseling session is closed and read-only.", "warn");
        return;
    }

    // counselor_id is NOT sent — the server always uses the logged-in
    // session user as the source of truth (see api/counseling.php).
    const sourceVal      = document.getElementById("counseling-source").value.trim();
    const otherSourceVal = document.getElementById("counseling-other-source").value.trim();
    const isOtherSource  = sourceVal === "Other Guidance Concerns";

    const payload = {
        id:                    editingCounselingId,
        student_id:            document.getElementById("counseling-student-id").value,
        behavioral_record_id:  document.getElementById("counseling-behavioral-id").value,
        offense_id:            document.getElementById("counseling-offense-id").value,
        referred_by:           capitalizeWords(document.getElementById("counseling-referred-by").value.trim()),
        source:                sourceVal,
        other_source:          isOtherSource ? otherSourceVal : "",
        concern:               document.getElementById("counseling-concern").value.trim(),
        session_date:          document.getElementById("counseling-date").value
    };

    const counselingRules = [
        { id: 'counseling-source', label: 'Source',       required: true },
        { id: 'counseling-date',   label: 'Session Date', required: true, type: 'date' },
    ];
    if (isOtherSource) {
        counselingRules.push({ id: 'counseling-other-source', label: 'Other Source', required: true });
    }
    if (!payload.student_id) { showNotif("⚠️ Student not found.", "warn"); return; }
    const counselingValidation = CSFValidate.validateFields(counselingRules);
    if (!counselingValidation.valid) { showNotif(`⚠️ ${counselingValidation.firstError}`, "warn"); return; }

    // Status is never chosen here — it's derived automatically from
    // Session Date on the server (today -> Ongoing, future -> Scheduled,
    // past -> Overdue), the same way on both create and edit. A past
    // date is allowed on purpose: it's how a missed/unlogged session
    // gets flagged Overdue instead of being rejected outright.

    if (editingCounselingId) {
        const noChanges =
            payload.student_id           == originalCounselingData.student_id           &&
            payload.behavioral_record_id == originalCounselingData.behavioral_record_id &&
            payload.offense_id           == originalCounselingData.offense_id           &&
            payload.referred_by          === originalCounselingData.referred_by         &&
            payload.source                === originalCounselingData.source             &&
            payload.other_source          === originalCounselingData.other_source       &&
            payload.concern               === originalCounselingData.concern            &&
            payload.session_date         === originalCounselingData.session_date;
        if (noChanges) { showNotif("⚠️ Walang nabago.", "warn"); return; }
    }

    const method = editingCounselingId ? "PUT" : "POST";
    const res = await fetch(COUNSELING_API, {
        method,
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, "warn"); return; }

    showNotif(editingCounselingId ? "✏️ Session updated!" : "✅ Session added!", editingCounselingId ? "update" : "success");

    closeCounselingModal();

    // Keep the Offenses table's Create/View Counseling button in sync —
    // it's derived from this same link, so it goes stale otherwise.
    if (payload.offense_id && typeof renderOffenses === 'function' && document.getElementById('tbody-offenses')) {
        renderOffenses();
    }

    // Same for the Behavioral Records table — the Create Counseling (+)
    // button is derived from this link, so it must disappear right after
    // a session is created for the record (and come back if it's unlinked).
    const prevBehId = (typeof originalCounselingData !== 'undefined' && originalCounselingData) ? originalCounselingData.behavioral_record_id : null;
    if ((payload.behavioral_record_id || prevBehId) && typeof renderBehavioral === 'function' && document.getElementById('tbody-behavioral')) {
        renderBehavioral();
    }

    renderCounseling();
}

async function openEditCounseling(id) {
    const res = await fetch(`${COUNSELING_API}?id=${id}`);
    const c   = await res.json();

    if (!c || !c.id) { showNotif('⚠️ This counseling session no longer exists.', 'warn'); return; }
    if (Number(c.is_archived) === 1) { showNotif('⚠️ This counseling session has been archived. Open it from Archived Records.', 'warn'); return; }

    const isClosed = c.status === 'Closed';

    editingCounselingId = c.id;
    originalCounselingData = {
        student_id:            c.student_id,
        behavioral_record_id:  c.behavioral_record_id ?? "",
        offense_id:            c.offense_id  ?? "",
        referred_by:           _naToBlank(c.referred_by),
        source:                c.source       ?? "",
        other_source:          c.other_source ?? "",
        concern:               _naToBlank(c.concern),
        status:                c.status       ?? "",
        session_date:          c.session_date ?? ""
    };

    if (!allStudents.length) await renderStudents();
    counselingStudentDropdown.setData(allStudents);

    document.getElementById("counselingModalTitle").textContent     = isClosed ? "View Counseling Session (Closed — Read Only)" : "Edit Counseling Session";
    counselingStudentDropdown.setSelectedById(c.student_id);
    document.getElementById("counseling-counselor-display").value   = c.counselor_name ?? "";
    document.getElementById("counseling-referred-by").value         = _naToBlank(c.referred_by);

    document.getElementById("counseling-source").value              = c.source       ?? "";
    document.getElementById("counseling-other-source").value        = c.other_source ?? "";
    toggleOtherSourceField();

    document.getElementById("counseling-concern").value             = _naToBlank(c.concern);

    // Status is display-only here — it only changes via "Start Session"
    // or the "Complete Session" workflow, never through this form.
    document.getElementById("counseling-status-group").style.display = "";
    document.getElementById("counseling-status-display").value = c.status ?? "";
    document.getElementById("counseling-date").value                = c.session_date ?? "";
    // Only a still-Scheduled session enforces "no past dates" — once
    // Ongoing/Closed its date reflects history, not a future plan.
    document.getElementById("counseling-date").min = c.status === 'Scheduled' ? getLocalDateStr() : "";
    await loadCounselingLinkOptions(c.student_id, c.behavioral_record_id, c.offense_id);

    setCounselingFormReadOnly(isClosed);

    await loadCounselingTimeline(c.id);

    document.getElementById("counselingModal").style.display = "flex";
}

// Renders this session's own case history (Session Created, Started,
// Completed, Case Closed, etc.) pulled from its Audit Trail
// entries — gives counselors a quick, at-a-glance case history without
// needing access to the Administrator-only Audit Trail page.
async function loadCounselingTimeline(id) {
    const wrap = document.getElementById("counseling-timeline-wrap");
    const box  = document.getElementById("counseling-timeline");
    wrap.style.display = "";
    box.innerHTML = `<div class="tl-empty">Loading…</div>`;
    try {
        const res     = await fetch(`${COUNSELING_API}?timeline=${id}`);
        const entries = await res.json();
        if (!Array.isArray(entries) || !entries.length) {
            box.innerHTML = `<div class="tl-empty">No history recorded yet.</div>`;
            return;
        }
        box.innerHTML = entries.map(e => `
            <div class="tl-item">
                <div class="tl-dot"></div>
                <div class="tl-body">
                    <div class="tl-name">${escapeHtml(e.action)}</div>
                    <div class="tl-meta">${e.details ? escapeHtml(e.details) + ' · ' : ''}${escapeHtml(e.user_name || 'System')}</div>
                    <div class="tl-time">${formatDateLong(e.created_at)}</div>
                </div>
            </div>`).join('');
    } catch (err) {
        box.innerHTML = `<div class="tl-empty">Could not load timeline.</div>`;
    }
}

async function deleteCounseling(id) {
    if (!await csfConfirm({ title: 'Archive Counseling Session?', message: 'It will be hidden from active records but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
    const res    = await fetch(`${COUNSELING_API}?id=${id}`, { method: "DELETE" });
    const result = await res.json();
    if (result.success) {
        renderCounseling();
        showNotif("🗄️ Session archived. Find it in Archived Records.", "delete");
    } else {
        showNotif(`⚠️ ${result.message || "Operation failed."}`, "warn");
    }
}

// ── START SESSION workflow ──────────────────────────────────────
// The only way a Counseling Session moves from Scheduled to Ongoing.
// Only shown while the session is Scheduled (see buildCounselingRows).
async function startCounseling(id) {
    if (!await csfConfirm({ title: 'Start Session?', message: 'Its status will change to Ongoing.', confirmText: 'Start Now', variant: 'info' })) return;
    const res = await fetch(COUNSELING_API, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "start", id })
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message || "Failed."}`, "warn"); return; }
    showNotif("▶ Session started!", "success");
    renderCounseling();
    if (typeof loadDashboardStats === "function") loadDashboardStats();
}

// Refreshes Behavioral Records and/or Offenses & Violations if either
// page happens to be open right now — used after any Counseling Session
// action (complete/reopen) that may have auto-updated a linked record's
// status server-side, so the table doesn't keep showing a stale status
// until the user manually switches pages.
function refreshLinkedGuidanceTablesIfVisible() {
    if (typeof renderBehavioral === "function" && document.getElementById("page-behavioral")?.classList.contains("active")) {
        renderBehavioral();
    }
    if (typeof renderOffenses === "function" && document.getElementById("page-offenses")?.classList.contains("active")) {
        renderOffenses();
    }
    if (typeof renderCloseCase === "function" && document.getElementById("page-close-case")?.classList.contains("active")) {
        renderCloseCase();
    }
}

// Reopen Session — the only way a Closed case becomes active again.
// Available to both Administrator and Guidance Staff. Requires a
// mandatory Reason for Reopening; Reopened By / Reopened Date & Time
// are recorded automatically via the Audit Trail entry itself.
async function reopenCounseling(id) {
    const reason = await csfPrompt({
        title: 'Reopen Counseling Session?', message: 'It will move back to Ongoing.',
        label: 'Reason for Reopening', placeholder: 'Why is this case being reopened?',
        required: true, confirmText: 'Reopen', icon: 'reopen'
    });
    if (reason === null) return; // cancelled

    const res = await fetch(COUNSELING_API, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "reopen", id, reason: reason.trim() })
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message || "Failed."}`, "warn"); return; }
    showNotif("🔓 Session reopened!", "success");
    renderCounseling();
    refreshLinkedGuidanceTablesIfVisible();
    if (typeof loadDashboardStats === "function") loadDashboardStats();
}

// Case closure is no longer a separate manual action — it happens
// automatically through the Complete Counseling Session workflow
// (see submitCompleteCounseling below).

// ── COMPLETE COUNSELING SESSION workflow ────────────────────────
// Saves session notes / action plan / outcome onto the SAME session
// and closes the case (status becomes Closed, session locked read-only).
let completingCounselingId = null;

function openCompleteCounseling(id) {
    completingCounselingId = id;
    // Pre-fill with whatever documentation this session already has —
    // relevant after Reopen (e.g. "forgot to enter Session Notes" /
    // "need to correct Outcome"): the counselor is amending existing
    // documentation, not starting over, so Done must not silently wipe
    // out previously saved Notes/Action Taken/Recommendations/Outcome.
    const c = allCounseling.find(x => String(x.id) === String(id)) || {};
    document.getElementById("complete-cs-notes").value = c.notes || "";
    document.getElementById("complete-cs-action-plan").value = c.action_plan || "";
    document.getElementById("complete-cs-recommendations").value = c.recommendations || "";
    document.getElementById("complete-cs-outcome").value = c.outcome || "";
    document.getElementById("completeCounselingModal").style.display = "flex";
}

function closeCompleteCounselingModal() {
    document.getElementById("completeCounselingModal").style.display = "none";
    completingCounselingId = null;
}

async function submitCompleteCounseling() {
    if (!completingCounselingId) return;

    const payload = {
        action: "complete",
        id: completingCounselingId,
        notes: document.getElementById("complete-cs-notes").value.trim(),
        action_plan: document.getElementById("complete-cs-action-plan").value.trim(),
        recommendations: document.getElementById("complete-cs-recommendations").value.trim(),
        outcome: document.getElementById("complete-cs-outcome").value.trim(),
    };

    const res = await fetch(COUNSELING_API, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message || "Failed."}`, "warn"); return; }

    showNotif("🔒 Counseling session completed and closed!", "success");
    closeCompleteCounselingModal();
    renderCounseling();
    refreshLinkedGuidanceTablesIfVisible();
    if (typeof loadDashboardStats === "function") loadDashboardStats();
}

// ── COUNSELING SESSION DETAILS (read-only "View Details") ───────
// Shows what was saved by the Complete Counseling Session workflow —
// counseling_sessions.notes / action_plan / recommendations / outcome —
// plus the session's own info. Always re-fetches the ONE selected
// record by id (same GET api/counseling.php?id= endpoint the Edit modal
// uses), never reads it from the list's cached rows, so it can't show
// stale data or another session's data. Purely read-only: no inputs.
let _csdRequestSeq = 0;

function csdClean(v) {
    return (v === null || v === undefined) ? '' : String(v).trim();
}

// Long free-text block; empty/whitespace-only -> "Not provided".
function csdTextSection(title, value) {
    const v = csdClean(value);
    return `<div class="csd-section">
        <h3 class="csd-heading">${escapeHtml(title)}</h3>
        ${v ? `<div class="csd-text">${escapeHtml(v)}</div>` : `<div class="csd-text csd-empty">Not provided</div>`}
    </div>`;
}

// Short label/value field; empty -> "Not provided".
function csdField(label, valueHtml, full) {
    return `<div class="csd-field${full ? ' full' : ''}">
        <div class="csd-label">${escapeHtml(label)}</div>
        <div class="csd-value">${valueHtml || '<span class="csd-empty">Not provided</span>'}</div>
    </div>`;
}

function csdPlain(v) {
    const t = csdClean(v);
    return t ? escapeHtml(t) : '';
}

function buildCounselingDetailsHtml(c) {
    const sourceText = c.source === 'Other Guidance Concerns' && csdClean(c.other_source) ? c.other_source : c.source;

    // Linked Behavioral Record / Offense (only what actually exists)
    const links = [];
    if (c.behavioral_record_id) {
        const sum = csdClean(c.behavioral_summary);
        links.push(`<div><strong>Behavioral Record:</strong> ${sum ? escapeHtml(sum) : 'Linked record'}</div>`);
    }
    if (c.offense_id) {
        const oType = csdClean(c.offense_type);
        const oDesc = csdClean(c.offense_description);
        const label = [oType, oDesc].filter(Boolean).join(' — ');
        links.push(`<div><strong>Offense / Violation:</strong> ${label ? escapeHtml(label) : 'Linked record'}</div>`);
    }

    const info = [
        csdField('Session Date', c.session_date ? escapeHtml(formatDateLong(c.session_date)) : ''),
        // counseling_sessions has no time column today — shown only if one exists.
        csdClean(c.session_time) ? csdField('Session Time', escapeHtml(c.session_time)) : '',
        csdField('Counselor / Guidance Staff', csdPlain(c.counselor_name)),
        csdField('Status', c.status ? `<span class="pill ${counselingStatusPillClass(c.status)}">${escapeHtml(c.status)}</span>` : ''),
        c.completion_date ? csdField('Date Completed', escapeHtml(formatDateLong(c.completion_date))) : '',
        csdField('Source', csdPlain(sourceText)),
        csdField('Referred By', csdPlain(c.referred_by)),
        csdField('Concern', csdPlain(c.concern), true),
        csdField('Linked Records', links.length ? links.join('') : '<span class="csd-empty">None</span>'),
    ].join('');

    return `
        <div class="csd-section">
            <h3 class="csd-heading">Student</h3>
            <div class="csd-value"><strong>${escapeHtml(csdClean(c.student_name)) || 'Not provided'}</strong>
                <small>${escapeHtml(csdClean(c.student_code))}</small></div>
        </div>
        <div class="csd-section">
            <h3 class="csd-heading">Session Information</h3>
            <div class="csd-grid">${info}</div>
        </div>
        ${csdTextSection('Session Notes', c.notes)}
        ${csdTextSection('Action Taken', c.action_plan)}
        ${csdTextSection('Recommendations', c.recommendations)}
        ${csdTextSection('Outcome', c.outcome)}`;
}

async function openCounselingDetails(id) {
    const modal = document.getElementById('counselingDetailsModal');
    const body  = document.getElementById('csd-body');
    const seq   = ++_csdRequestSeq;            // later clicks invalidate earlier in-flight loads
    body.innerHTML = '<div class="csd-loading">Loading…</div>';   // wipe any previous session's content
    modal.style.display = 'flex';
    try {
        const res = await fetch(`${COUNSELING_API}?id=${encodeURIComponent(id)}`);
        const c   = await res.json();
        if (seq !== _csdRequestSeq) return;    // stale response — a newer open/close happened
        if (!c || !c.id || String(c.id) !== String(id)) {
            body.innerHTML = '<div class="csd-loading">This counseling session could not be loaded.</div>';
            return;
        }
        body.innerHTML = buildCounselingDetailsHtml(c);
        body.scrollTop = 0;
    } catch (err) {
        if (seq !== _csdRequestSeq) return;
        console.error('Failed to load counseling session details:', err);
        body.innerHTML = '<div class="csd-loading">Unable to load this counseling session. Please try again.</div>';
    }
}

function closeCounselingDetails() {
    _csdRequestSeq++;                          // discard any response still on its way
    document.getElementById('counselingDetailsModal').style.display = 'none';
    document.getElementById('csd-body').innerHTML = '';
}

// ── CLOSE CASE (Guidance Module) ────────────────────────────────
// Not a new record type or table — this page reads the SAME
// counseling_sessions rows as Counseling Sessions (same COUNSELING_API),
// filtered here to status === 'Closed'. It exists purely to give
// Guidance Staff a focused place to browse/search finished cases
// separately from Scheduled/Ongoing/Overdue ones. "View" reuses the
// existing openEditCounseling() modal, which already renders read-only
// for a Closed session.
let allCloseCases = [];

function buildCloseCaseRows(data) {
    if (!data.length) {
        return emptyRow(7, "No closed cases found",
            "Cases appear here once completed and closed from Counseling Sessions.");
    }
    return data.map(c => {
        // Outcome is what the Complete Counseling Session workflow calls
        // "why/how the case was resolved" — the closest existing field to
        // a closing reason. Notes/Action Taken are shown as a fallback
        // only if Outcome was left blank.
        const closingNote = c.outcome || c.notes || c.action_plan || c.recommendations || 'N/A';
        const sourceLabel = c.source === 'Other Guidance Concerns' ? (c.other_source || c.source) : c.source;
        return `
        <tr id="close-case-row-${c.id}">
          <td>${c.completion_date ? formatDateLong(c.completion_date) : 'N/A'}</td>
          <td>${escapeHtml(c.student_name)} <span style="color:#9a8a7c;font-size:.78rem">(${escapeHtml(c.student_code || '')})</span></td>
          <td>${escapeHtml(c.counselor_name) || 'N/A'}</td>
          <td>${escapeHtml(sourceLabel)}</td>
          <td>${escapeHtml(c.concern) || 'N/A'}</td>
          <td>${escapeHtml(closingNote)}</td>
          <td>
            <button class="btn-sm icon-only" onclick="openCounselingDetails(${c.id})" aria-label="View Details" title="View Details"><i class="fi fi-rr-eye btn-ico" aria-hidden="true"></i></button>
            <button class="btn-sm icon-only" style="border-color:#c98a1a;color:#c98a1a" onclick="reopenCounseling(${c.id})" aria-label="Reopen" title="Reopen"><i class="fi fi-rr-unlock btn-ico" aria-hidden="true"></i></button>
          </td>
        </tr>`;
    }).join('');
}

async function renderCloseCase() {
    const res  = await fetch(`${COUNSELING_API}?status=Closed`);
    const data = await res.json();
    allCloseCases = Array.isArray(data) ? data : [];
    document.getElementById("search-close-case").value = "";
    document.getElementById("tbody-close-case").innerHTML = buildCloseCaseRows(allCloseCases);
}

function filterCloseCase() {
    const q = document.getElementById("search-close-case").value.toLowerCase();
    const filtered = allCloseCases.filter(c =>
        (c.student_name || '').toLowerCase().includes(q) ||
        (c.student_code || '').toLowerCase().includes(q)
    );
    document.getElementById("tbody-close-case").innerHTML = buildCloseCaseRows(filtered);
}

// ── DASHBOARD STATS ──
// Populates every dashboard card, split into one loader per role
// (loadAdminDashboard / loadClinicDashboard / loadGuidanceDashboard) —
// see loadDashboardStats() below. Each role's cards, Recent Activity
// feed, and Pending Tasks panel are all populated from data already
// fetched for that role, no duplicate requests.
//   Admin:    ds-admin-students, ds-admin-users, ds-admin-active-sessions, ds-admin-audit-today
//   Clinic:   ds-visits, ds-low, ds-expiry, ds-pending-referrals
//   Guidance: ds-active-cases, ds-behavioral-open, ds-notice-required, ds-cases-closed
const DASH_MONTHS = ["January","February","March","April","May","June","July",
                      "August","September","October","November","December"];

function getDashboardGreeting() {
    const hour = new Date().getHours();
    const name = typeof CURRENT_USER_NAME !== "undefined" ? CURRENT_USER_NAME.split(" ")[0] : "Admin";
    if (hour < 12) return `Good Morning, ${name}`;
    if (hour < 17) return `Good Afternoon, ${name}`;
    return `Good Evening, ${name}`;
}

// "August 08, 2026" — the approved user-facing long date format, used
// only for this new dashboard header line (the rest of the system keeps
// its existing MM/DD/YYYY display via formatDateMDY, unchanged).
function getDashboardDateLong(d = new Date()) {
    const day = String(d.getDate()).padStart(2, '0');
    return `${DASH_MONTHS[d.getMonth()]} ${day}, ${d.getFullYear()}`;
}

// Simple in-nav helper for KPI cards that just jump to an existing page
// with no extra filtering (mirrors the .nav-item lookup pattern already
// used by goToInventoryAlert/goToNotification).
async function goToDashboardCard(page) {
    const navItem = document.querySelector(`.nav-item[onclick*="'${page}'"]`);
    await showPage(page, navItem);
}

// Quick Actions panel (dashboard right column) — thin wrappers around
// the existing Add modals. These open right over the Dashboard itself;
// there's no need to navigate to the target page first since every
// Add/Edit modal is a global overlay already present in the DOM
// regardless of which page is currently showing (see admin.php's
// unconditional module includes).
function quickLogVisit() { if (typeof openAddVisit === 'function') openAddVisit(); }
function quickLogOffense() { if (typeof openAddOffense === 'function') openAddOffense(); }
function quickNewCounseling() { if (typeof openAddCounseling === 'function') openAddCounseling(); }

// Clinic Visit Logs already has a status filter (Treated / Referred /
// For Monitoring / Sent Home / Emergency) — reuse it instead of building
// a new filtering mechanism.
async function goToClinicVisitsFiltered(status) {
    const navItem = document.querySelector('.nav-item[onclick*="\'clinic-visits\'"]');
    await showPage('clinic-visits', navItem);
    const filterEl = document.getElementById('filter-status');
    if (filterEl) { filterEl.value = status || ''; filterVisits(); }
}

// Step 3 — jumps to Behavioral Records pre-filtered to Open (needs
// attention), reusing the filter-behavioral-status control already
// added to that table.
async function goToBehavioralAttention() {
    const navItem = document.querySelector('.nav-item[onclick*="\'behavioral\'"]');
    await showPage('behavioral', navItem);
    const filterEl = document.getElementById('filter-behavioral-status');
    if (filterEl) { filterEl.value = 'Open'; filterBehavioral(); }
}

// Cases Closed KPI (Guidance dashboard, 4th card) — jumps to Close Case,
// which is now where every Closed counseling session actually lives
// (Counseling Sessions no longer lists Closed cases at all, so a
// Closed-status filter there would always be empty).
async function goToCasesClosedFiltered() {
    const navItem = document.querySelector('.nav-item[onclick*="\'close-case\'"]');
    await showPage('close-case', navItem);
}

async function loadDashboardStats() {
    // Update time-aware greeting + long-form date
    const greetEl = document.getElementById("dash-greeting");
    if (greetEl) greetEl.textContent = getDashboardGreeting();
    const dateEl = document.getElementById("dash-date");
    if (dateEl) dateEl.textContent = getDashboardDateLong();

    const role = (typeof CSF_ROLE !== 'undefined') ? CSF_ROLE : null;
    if (role === 'admin')         await loadAdminDashboard();
    else if (role === 'clinic')   await loadClinicDashboard();
    else if (role === 'guidance') await loadGuidanceDashboard();
}

// ── Administrator dashboard ──────────────────────────────────────
// Total Users, Active Counseling Sessions, Audit Trail Events Today,
// System Health (derived from active critical notifications — no new
// endpoint needed, reuses the same data already driving the bell badge).
async function loadAdminDashboard() {
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

    try {
        const res  = await fetch("api/students.php");
        const data = await res.json();
        setText("ds-admin-students", Array.isArray(data) ? data.length : 0);
    } catch (err) { console.error("Failed to load student count:", err); }

    try {
        const res  = await fetch("api/users.php?action=list");
        const data = await res.json();
        setText("ds-admin-users", Array.isArray(data) ? data.length : 0);
    } catch (err) { console.error("Failed to load user count:", err); }

    let counseling = [];
    try {
        const res  = await fetch("api/counseling.php");
        counseling = await res.json();
        if (Array.isArray(counseling)) {
            setText("ds-admin-active-sessions", counseling.filter(c => c.status === 'Ongoing').length);
        }
    } catch (err) { console.error("Failed to load counseling summary:", err); }

    let visits = [];
    try {
        const res = await fetch("api/clinic.php");
        visits = await res.json();
    } catch (err) { console.error("Failed to load clinic visits:", err); }

    try {
        const res  = await fetch("api/audit.php?limit=500");
        const data = await res.json();
        const rows = (data && data.success && Array.isArray(data.entries)) ? data.entries : [];
        const todayStr = getLocalDateStr();
        setText("ds-admin-audit-today", rows.filter(a => a.created_at && a.created_at.split(' ')[0] === todayStr).length);
    } catch (err) { console.error("Failed to load audit trail count:", err); }

    renderRecentActivityTimeline(visits, counseling);
}

// ── Clinic Staff dashboard ────────────────────────────────────────
async function loadClinicDashboard() {
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    let visits = [];
    try {
        const res  = await fetch("api/clinic.php");
        visits = await res.json();
        const todayStr = getLocalDateStr();
        const todaysVisits = visits.filter(v => v.visited_at && v.visited_at.split(' ')[0] === todayStr);
        setText("ds-visits", todaysVisits.length);

        const timeline = document.getElementById("dash-timeline");
        if (timeline) {
            // Was capped to 5, permanently hiding older visits. The
            // timeline now scrolls (see .timeline in admin.css), so
            // show a much larger, still-bounded window instead.
            const recent = visits.slice(0, 50);
            timeline.innerHTML = !recent.length
                ? '<div class="tl-empty">No clinic visits recorded yet.</div>'
                : recent.map(v => activityItemHtml({
                    dot: v.status === 'Treated' ? 'green' : (v.status === 'Referred' || v.status === 'Emergency') ? 'red' : 'yellow',
                    name: v.full_name,
                    code: v.student_code,
                    meta: `Clinic Visit · ${escapeHtml(v.symptoms) || 'N/A'}`,
                    status: v.status,
                    pillClass: v.status === 'Treated' ? 'pill-green' : (v.status === 'Referred' || v.status === 'Emergency') ? 'pill-red' : 'pill-yellow',
                    time: v.visited_at,
                })).join('');
        }
    } catch (err) {
        console.error("Failed to load clinic visit widgets:", err);
        const timeline = document.getElementById("dash-timeline");
        if (timeline) timeline.innerHTML = '<div class="tl-empty">Unable to load clinic summary.</div>';
    }

    let referrals = [];
    try {
        const rres = await fetch("api/referrals.php");
        referrals = await rres.json();
        // Referral History no longer tracks a status/outcome — a
        // referral just exists once created (see viewReferral()). This
        // card now counts referrals actually logged this month, so it
        // stays a meaningful, changing number instead of a permanent
        // "still Pending" count that could never move.
        if (Array.isArray(referrals)) {
            const now = new Date();
            const monthStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
            setText("ds-pending-referrals", referrals.filter(r => (r.created_at || '').startsWith(monthStr)).length);
        }
    } catch (err) { console.error("Failed to load referral count:", err); }

    await loadDashboardInventoryAlerts();
}

// ── Guidance Staff dashboard ───────────────────────────────────────
async function loadGuidanceDashboard() {
    const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    let counseling = [];
    try {
        const res  = await fetch("api/counseling.php");
        counseling = await res.json();
        if (Array.isArray(counseling)) {
            setText("ds-active-cases", counseling.filter(c => c.status === 'Ongoing').length);
            // Cases Closed KPI — reuses this same counseling fetch (no
            // extra request, no duplicate DB logic); 'Closed' is the
            // status the backend sets once a session is completed
            // (api/counseling.php's complete action).
            setText("ds-cases-closed", counseling.filter(c => c.status === 'Closed').length);
        }
        const timeline = document.getElementById("dash-timeline");
        if (timeline) {
            // Was capped to 5, permanently hiding older sessions. The
            // timeline now scrolls (see .timeline in admin.css), so
            // show a much larger, still-bounded window instead.
            const recent = [...counseling].sort((a, b) => (b.session_date || '').localeCompare(a.session_date || '')).slice(0, 50);
            timeline.innerHTML = !recent.length
                ? '<div class="tl-empty">No counseling activity yet.</div>'
                : recent.map(c => activityItemHtml({
                    dot: c.status === 'Closed' ? 'green' : c.status === 'Overdue' ? 'red' : 'yellow',
                    name: c.student_name,
                    code: c.student_code,
                    meta: `Counseling Session`,
                    status: c.status,
                    pillClass: counselingStatusPillClass(c.status),
                    time: c.session_date,
                })).join('');
        }
    } catch (err) {
        console.error("Failed to load counseling dashboard data:", err);
        const timeline = document.getElementById("dash-timeline");
        if (timeline) timeline.innerHTML = '<div class="tl-empty">Unable to load guidance summary.</div>';
    }

    let behavioral = [];
    try {
        const res  = await fetch("api/behavioral.php");
        behavioral = await res.json();
        if (Array.isArray(behavioral)) {
            setText("ds-behavioral-open", behavioral.filter(b => (b.status || 'Open') === 'Open').length);
        }
    } catch (err) { console.error("Failed to load behavioral dashboard data:", err); }

    let offenses = [];
    try {
        const res = await fetch("api/offenses.php");
        offenses = await res.json();
        if (Array.isArray(offenses)) {
            setText("ds-notice-required", offenses.filter(o => o.status === 'Notice Required').length);
        }
    } catch (err) { console.error("Failed to load offenses dashboard data:", err); }

    renderUpcomingFollowups(counseling);
}

// One shared "activity item" renderer so every role's timeline looks
// and behaves the same way — icon, colored status badge, and a
// relative-feeling timestamp, instead of three slightly different
// hand-rolled HTML blocks.
function activityItemHtml({ icon, dot, name, code, meta, status, pillClass, time }) {
    return `
        <div class="tl-item">
            <div class="tl-dot ${dot}"></div>
            <div class="tl-body">
                <div class="tl-name">${icon ? `<span style="margin-right:6px">${icon}</span>` : ''}${escapeHtml(name) || 'Unknown'}${code ? ` <span style="color:#aaa;font-weight:400;font-size:.75rem">${escapeHtml(code)}</span>` : ''}</div>
                <div class="tl-meta">${meta} · <span class="pill ${pillClass}">${escapeHtml(status)}</span></div>
                <div class="tl-time">${formatDateLong(time)}</div>
            </div>
        </div>`;
}

// Administrator's merged Recent Activity feed — Clinic Visits +
// Counseling Sessions interleaved by recency. Only surfaces the same
// non-confidential summary fields already shown on the Clinic-only /
// Guidance-only timelines (name, type, status) — no counseling notes
// or behavioral details.
function renderRecentActivityTimeline(visits, counseling) {
    const timeline = document.getElementById("dash-timeline");
    if (!timeline) return;

    const items = [
        ...(Array.isArray(visits) ? visits : []).map(v => ({
            sortKey: v.visited_at || '',
            time: v.visited_at,
            name: v.full_name,
            code: v.student_code,
            meta: 'Clinic Visit',
            status: v.status,
            pillClass: v.status === 'Treated' ? 'pill-green' : (v.status === 'Referred' || v.status === 'Emergency') ? 'pill-red' : 'pill-yellow',
            dot: v.status === 'Treated' ? 'green' : (v.status === 'Referred' || v.status === 'Emergency') ? 'red' : 'yellow',
        })),
        ...(Array.isArray(counseling) ? counseling : []).map(c => ({
            sortKey: `${c.session_date || ''} 00:00:00`,
            time: c.session_date,
            name: c.student_name,
            code: c.student_code,
            meta: 'Counseling Session',
            status: c.status,
            pillClass: counselingStatusPillClass(c.status),
            dot: c.status === 'Closed' ? 'green' : c.status === 'Overdue' ? 'red' : 'yellow',
        })),
    // Was capped to 6, permanently hiding older activity. The
    // timeline now scrolls (see .timeline in admin.css), so show a
    // much larger, still-bounded, newest-first window instead.
    ].sort((a, b) => (b.sortKey || '').localeCompare(a.sortKey || '')).slice(0, 50);

    timeline.innerHTML = !items.length ? '<div class="tl-empty">No recent activity.</div>' : items.map(activityItemHtml).join('');
}

// ── Pending Tasks panel (dashboard right column) ────────────────
// Role-specific "needs your attention" shortlist — reuses data already
// fetched for the stat cards above, no extra requests.
/* ════════════════════════════════════════════════════════════
   ARCHIVED RECORDS
   One unified page showing soft-deleted records from every
   module, grouped by tabs. Restore sends a PATCH to the
   corresponding API endpoint.
   ════════════════════════════════════════════════════════════ */

let _currentArchiveTab = 'students';
let _allArchivedData   = [];

const ARCHIVE_CONFIG = {
  'students': {
    api:      'api/students.php?archived=1',
    restore:  (id) => fetch(`api/students.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/students.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Student ID', 'Full Name', 'Grade / Section', 'Guardian Contact', 'Action'],
    row: (r) => `
      <td>${escapeHtml(r.student_code)}</td>
      <td>${escapeHtml(r.full_name)}</td>
      <td>${escapeHtml(r.grade_section)}</td>
      <td>${r.guardian_contact && r.guardian_contact.trim() ? escapeHtml(r.guardian_contact) : 'N/A'}</td>`,
    searchFields: ['student_code','full_name','grade_section'],
    dateField: 'created_at',
  },
  'clinic-visits': {
    api:      'api/clinic.php?archived=1',
    restore:  (id) => fetch(`api/clinic.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/clinic.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Date', 'Student', 'Symptoms', 'Findings', 'Status', 'Action'],
    row: (r) => `
      <td>${formatDateLong(r.visited_at)}</td>
      <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
      <td>${escapeHtml(r.symptoms) || 'N/A'}</td>
      <td>${escapeHtml(r.findings) || 'N/A'}</td>
      <td><span class="pill ${r.status==='Treated'?'pill-green':r.status==='Referred'?'pill-red':'pill-yellow'}">${escapeHtml(r.status)}</span></td>`,
    searchFields: ['student_code','full_name','symptoms','findings'],
    dateField: 'visited_at',
  },
  // NOTE: Treatment History and Referral History are intentionally NOT
  // listed here. They are read-only history generated automatically from
  // a Clinic Visit and are never archived independently — they follow
  // the archive state of their parent Clinic Visit automatically.
  'inventory': {
    api:      'api/inventory.php?archived=1',
    restore:  (id) => fetch(`api/inventory.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/inventory.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Medicine', 'Quantity', 'Unit', 'Expiry Date', 'Action'],
    row: (r) => `
      <td><strong>${escapeHtml(r.name)}</strong></td>
      <td>${r.quantity ?? 0}</td>
      <td>${escapeHtml(capitalizeWords(r.unit ?? ''))}</td>
      <td>${formatDateLong(r.expiry_date)}</td>`,
    searchFields: ['name','unit'],
    dateField: 'created_at',
  },
  'counseling': {
    api:      'api/counseling.php?archived=1',
    restore:  (id) => fetch(`api/counseling.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/counseling.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Date', 'Student', 'Counselor', 'Status', 'Action'],
    row: (r) => `
      <td>${formatDateLong(r.session_date)}</td>
      <td><strong>${escapeHtml(r.student_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
      <td>${escapeHtml(r.counselor_name) || 'N/A'}</td>
      <td><span class="pill ${counselingStatusPillClass(r.status)}">${escapeHtml(r.status)}</span></td>`,
    searchFields: ['student_name','student_code'],
    dateField: 'session_date',
  },
  'behavioral': {
    api:      'api/behavioral.php?archived=1',
    restore:  (id) => fetch(`api/behavioral.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/behavioral.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Date', 'Student', 'Grade Level', 'Behavior Observed', 'Action'],
    row: (r) => `
      <td>${formatDateLong(r.recorded_at)}</td>
      <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
      <td>${escapeHtml(r.grade_section)}</td>
      <td>${escapeHtml(r.behavior)}</td>`,
    searchFields: ['full_name','student_code','behavior'],
    dateField: 'recorded_at',
  },
  'offenses': {
    api:      'api/offenses.php?archived=1',
    restore:  (id) => fetch(`api/offenses.php`, { method: 'PATCH', headers: {'Content-Type':'application/json'}, body: JSON.stringify({id}) }),
    permanentDelete: (id, confirm) => fetch(`api/offenses.php?id=${id}&permanent=1`, { method: 'DELETE', headers: {'Content-Type':'application/json'}, body: JSON.stringify({confirm}) }),
    headers:  ['Date', 'Student', 'Category', 'Severity', 'Description', 'Status', 'Action'],
    row: (r) => `
      <td>${formatDateLong(r.recorded_at)}</td>
      <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
      <td>${escapeHtml(r.type)}</td>
      <td><span class="pill ${SEVERITY_PILL_CLASS[r.severity_level] || 'pill-gray'}">${escapeHtml(r.severity_level) || 'N/A'}</span></td>
      <td>${escapeHtml(r.description)}</td>
      <td><span class="pill ${OFFENSE_STATUS_CLASS[r.status] || 'pill-yellow'}">${escapeHtml(r.status)}</span></td>`,
    searchFields: ['full_name','student_code','type'],
    dateField: 'recorded_at',
  },
  'users': {
    api:      'api/users.php?action=list&archived=1',
    restore:  (id) => {
      const form = new FormData();
      form.append('action', 'restore');
      form.append('id', id);
      return fetch('api/users.php', { method: 'POST', body: form });
    },
    permanentDelete: (id, confirm) => {
      const form = new FormData();
      form.append('action', 'permanent_delete');
      form.append('id', id);
      form.append('confirm', confirm);
      return fetch('api/users.php', { method: 'POST', body: form });
    },
    headers:  ['Full Name', 'Username', 'Role', 'Action'],
    row: (r) => `
      <td>${escapeHtml(r.full_name)}</td>
      <td>${escapeHtml(r.username)}</td>
      <td>${escapeHtml(r.role)}</td>`,
    searchFields: ['full_name','username','role'],
    dateField: 'created_at',
  },
};

function loadArchivedPage() {
  // Default to first tab on every page visit (fresh state)
  _currentArchiveTab = 'students';
  document.getElementById('search-archived').value = '';
  document.getElementById('filter-archived-date').value = '';
  document.querySelectorAll('.archive-tab').forEach(btn =>
    btn.classList.toggle('active', btn.dataset.archtab === 'students')
  );
  fetchArchivedTab('students');
}

function switchArchiveTab(tab, el) {
  _currentArchiveTab = tab;
  document.getElementById('search-archived').value = '';
  document.getElementById('filter-archived-date').value = '';
  document.querySelectorAll('.archive-tab').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
  fetchArchivedTab(tab);
}

// Deep-links a notification click straight into one Archived Records
// row — navigate to the page (defaults to the 'students' tab), then
// switch to the record's actual tab and highlight it once that tab's
// data has loaded. Reuses the exact same tab/fetch/render machinery a
// manual click on an archive-tab button already uses.
async function goToArchivedRecord(tab, id) {
  const navItem = document.querySelector('.nav-item[onclick*="\'archived\'"]');
  await showPage('archived', navItem);
  const tabBtn = document.querySelector(`.archive-tab[data-archtab="${tab}"]`);
  if (tabBtn) {
    _currentArchiveTab = tab;
    document.getElementById('search-archived').value = '';
    document.getElementById('filter-archived-date').value = '';
    document.querySelectorAll('.archive-tab').forEach(b => b.classList.remove('active'));
    tabBtn.classList.add('active');
    await fetchArchivedTab(tab);
  }
  if (id) highlightRecordRow(`archived-row-${tab}-${id}`);
}

async function fetchArchivedTab(tab) {
  const cfg = ARCHIVE_CONFIG[tab];
  if (!cfg) return;

  // Render header
  document.getElementById('thead-archived').innerHTML =
    `<tr>${cfg.headers.map(h => `<th>${h}</th>`).join('')}</tr>`;
  document.getElementById('tbody-archived').innerHTML =
    emptyRow(cfg.headers.length, "Loading…", "");

  try {
    const res  = await fetch(cfg.api);
    _allArchivedData = await res.json();
    renderArchivedTable(_allArchivedData, tab);
  } catch (err) {
    document.getElementById('tbody-archived').innerHTML =
      emptyRow(cfg.headers.length, "Couldn't load archived records", "Check your connection, then switch tabs to try again.");
  }
}

function renderArchivedTable(data, tab) {
  const cfg = ARCHIVE_CONFIG[tab];
  if (!data.length) {
    document.getElementById('tbody-archived').innerHTML =
      emptyRow(cfg.headers.length, "No archived records found", "Archived records for this category will appear here.");
    return;
  }
  // Restoring a Student record is Administrator-only (also enforced
  // server-side) — Clinic Staff/Guidance Staff can still view this tab
  // to see what's archived, just without the action button. Permanent
  // Delete mirrors the same per-tab permission as Restore, since it's
  // reached from this same authorized view (see spec §13: "ONLY
  // available from Archived Records where the existing permissions
  // allow it") — enforced again server-side regardless.
  const canRestore = !!cfg.restore && (tab !== 'students' || isAdminRole());
  const canDelete  = !!cfg.permanentDelete && (tab !== 'students' || isAdminRole());
  document.getElementById('tbody-archived').innerHTML = data.map(r => `
    <tr id="archived-row-${tab}-${r.id}">
      ${cfg.row(r)}
      <td>
        ${canRestore ? `<button class="btn-sm" style="border-color:#2e7d5a;color:#2e7d5a"
          onclick="restoreArchivedRecord(${r.id}, '${tab}')">Restore</button>` : ''}
        ${canDelete ? `<button class="btn-sm danger"
          onclick="openPermanentDeleteModal(${r.id}, '${tab}')">Delete Permanently</button>` : ''}
      </td>
    </tr>`).join('');
}

function filterArchived() {
  const q    = document.getElementById('search-archived').value.toLowerCase().trim();
  const date = document.getElementById('filter-archived-date').value; // "YYYY-MM-DD" or ""
  const cfg  = ARCHIVE_CONFIG[_currentArchiveTab];
  if (!cfg) return;

  let filtered = _allArchivedData;

  if (q) {
    filtered = filtered.filter(r =>
      cfg.searchFields.some(f => String(r[f] ?? '').toLowerCase().includes(q))
    );
  }

  if (date && cfg.dateField) {
    filtered = filtered.filter(r => {
      const raw = r[cfg.dateField];
      if (!raw) return false;
      return String(raw).slice(0, 10) === date;
    });
  }

  renderArchivedTable(filtered, _currentArchiveTab);
}

// Human-readable labels shared by Restore and Permanent Delete confirm
// dialogs — one place to keep them in sync.
const ARCHIVE_MODULE_NAMES = {
  'students':     'Student',
  'clinic-visits':'Clinic Visit',
  'inventory':    'Medicine',
  'counseling':   'Counseling Session',
  'users':        'User',
  'behavioral':   'Behavioral Record',
  'offenses':     'Offense Record',
};

async function restoreArchivedRecord(id, tab) {
  const cfg = ARCHIVE_CONFIG[tab];
  if (!cfg) return;

  const label = ARCHIVE_MODULE_NAMES[tab] || 'Record';

  if (!await csfConfirm({ title: `Restore ${label}?`, message: 'It will be moved back to its active list.', confirmText: 'Restore', variant: 'restore' })) return;

  try {
    const res    = await cfg.restore(id);
    const result = await res.json();

    if (!result.success) {
      showNotif(`⚠️ ${result.message || 'Restore failed.'}`, 'warn');
      return;
    }

    showNotif(`✅ ${label} restored successfully!`, 'success');

    // Refresh the archived tab and, if the matching active module's data is
    // currently cached, invalidate it so the next visit re-fetches correctly.
    await fetchArchivedTab(tab);

    // Invalidate cached active-module data so it's freshly fetched next visit.
    if (tab === 'students')      allStudents       = [];
    if (tab === 'clinic-visits') allVisits         = [];
    if (tab === 'inventory')     allInventory      = [];
    if (tab === 'counseling')    allCounseling     = [];
    if (tab === 'behavioral')    allBehavioral     = [];
    if (tab === 'offenses')      allOffenses       = [];
    if (tab === 'users')         allUsers          = [];

    // If this was a medicine, the server already re-reconciled its
    // stock/expiry alerts on restore (see api/inventory.php); the global
    // fetch wrapper pulls that into the bell automatically.

  } catch (err) {
    showNotif('⚠️ Could not reach the server. Please try again.', 'warn');
  }
}

// ── Permanent Delete ─────────────────────────────────────────
// Only ever opened from a row in Archived Records (see spec §13).
// Requires typing "DELETE" exactly (case-sensitive, no extra
// characters) before the button enables — no plain Yes/No confirm().
let _permanentDeleteId  = null;
let _permanentDeleteTab = null;

function openPermanentDeleteModal(id, tab) {
  _permanentDeleteId  = id;
  _permanentDeleteTab = tab;

  const label = ARCHIVE_MODULE_NAMES[tab] || 'Record';
  document.getElementById('permanentDeleteLabel').textContent =
    `Permanently delete this ${label}? This action cannot be undone — the record and its history will be removed for good.`;

  const input = document.getElementById('permanentDeleteConfirmInput');
  input.value = '';
  document.getElementById('permanentDeleteConfirmBtn').disabled = true;
  document.getElementById('permanentDeleteError').style.display = 'none';
  document.getElementById('permanentDeleteModal').style.display = 'flex';
  input.focus();
}

function closePermanentDeleteModal() {
  document.getElementById('permanentDeleteModal').style.display = 'none';
  _permanentDeleteId  = null;
  _permanentDeleteTab = null;
}

// Exact match only — "delete", "Delete", "DELET", "DELETE123" all stay
// disabled, per spec §14.
function onPermanentDeleteConfirmInput() {
  const typed = document.getElementById('permanentDeleteConfirmInput').value;
  document.getElementById('permanentDeleteConfirmBtn').disabled = (typed !== 'DELETE');
}

async function confirmPermanentDelete() {
  if (!_permanentDeleteId || !_permanentDeleteTab) return;
  const typed = document.getElementById('permanentDeleteConfirmInput').value;
  if (typed !== 'DELETE') return; // belt-and-suspenders; button should already be disabled

  const id  = _permanentDeleteId;
  const tab = _permanentDeleteTab;
  const cfg = ARCHIVE_CONFIG[tab];
  if (!cfg || !cfg.permanentDelete) return;

  const errEl = document.getElementById('permanentDeleteError');
  errEl.style.display = 'none';

  try {
    const res    = await cfg.permanentDelete(id, typed);
    const result = await res.json();

    if (!result.success) {
      // Show the server's reason (e.g. "still has related records...")
      // inline in the modal instead of closing it, so the user can read
      // why the delete was blocked.
      errEl.textContent = result.message || 'Permanent delete failed.';
      errEl.style.display = 'block';
      return;
    }

    const label = ARCHIVE_MODULE_NAMES[tab] || 'Record';
    closePermanentDeleteModal();
    showNotif(`🗑️ ${label} permanently deleted.`, 'success');
    await fetchArchivedTab(tab);
  } catch (err) {
    errEl.textContent = 'Could not reach the server. Please try again.';
    errEl.style.display = 'block';
  }
}
/* ════════════════════════════════════════════════════════════
   BEHAVIORAL RECORDS
   ════════════════════════════════════════════════════════════ */

const BEH_API = 'api/behavioral.php';
let allBehavioral = [];
let editingBehId = null;
let editingBehOrigStatus = null; // status when the edit form was opened (to detect a Reopen)
let originalBehData = null;      // form values when Edit opened (to detect "no changes")
let _behStudentSD = null;

// Shared lookup of SearchDropdown instances created across modules
// (Behavioral Records, Offenses & Violations, ...), keyed by the
// input element's id. Declared once here since it's used by both
// _ensureBehStudentSD() and _ensureOffStudentSD() below.
const _sdRegistry = {};

function _ensureBehStudentSD() {
    if (!_behStudentSD) {
        _behStudentSD = new SearchDropdown({
            input: '#beh-student-search',
            hidden: '#beh-student-id',
            data: allStudents,
            valueField: 'id',
            titleField: 'full_name',
            subtitleField: 'student_code',
            gradeField: 'grade_section'
        });
        _sdRegistry['beh-student-search'] = _behStudentSD;
    } else {
        _behStudentSD.setData(allStudents);
    }
}

async function openAddBehavioral() {
    editingBehId = null;
    clearBehavioralForm();
    if (!allStudents.length) await renderStudents();
    _ensureBehStudentSD();
    _behStudentSD.clear();
    document.getElementById('behavioralModalTitle').textContent = 'Add Behavioral Record';
    document.getElementById('beh-date').value = new Date().toISOString().split('T')[0];
    document.getElementById('behavioralModal').style.display = 'flex';
}

function closeBehavioralModal() {
    document.getElementById('behavioralModal').style.display = 'none';
    editingBehId = null;
    clearBehavioralForm();
}

function clearBehavioralForm() {
    if (_behStudentSD) _behStudentSD.clear();
    const sid = document.getElementById('beh-student-id'); if (sid) sid.value = '';
    document.getElementById('beh-behavior').value = '';
    document.getElementById('beh-date').value = '';
    document.getElementById('beh-status').value = 'Open';
    document.getElementById('beh-status-wrap').style.display = 'none';
}

const BEHAVIORAL_STATUS_CLASS = { Open: 'pill-yellow', Addressed: 'pill-green' };

// Row actions for a Behavioral Record. There is no "View Counseling" here
// any more — the only counseling action is Create (+), and it is shown only
// while it makes sense:
//   Open      + no active session -> Create Counseling (+)
//   Open      + active session    -> (nothing — a case is already running;
//                                     it auto-marks the record Addressed when
//                                     the session is completed)
//   Addressed                     -> (nothing)
// Editing Addressed -> Open needs a reason (see saveBehavioral) and removes
// the old closed case from Close Case, which brings the + back.
function buildBehavioralActionButtons(r) {
    const buttons = [`<button class="btn-sm icon-only" onclick="openEditBehavioral(${r.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>`];
    const hasActiveSession = !!r.linked_session_id && r.linked_session_status !== 'Closed';
    if ((r.status || 'Open') === 'Open' && !hasActiveSession) {
        buttons.push(`<button class="btn-sm icon-only" onclick="createCounselingFromBehavioral(${r.id})" aria-label="Create Counseling" title="Create Counseling"><i class="fi fi-rr-plus btn-ico" aria-hidden="true"></i></button>`);
    }
    buttons.push(`<button class="btn-sm danger" onclick="deleteBehavioral(${r.id})">Archive</button>`);
    return buttons.join('\n                ');
}

function buildBehavioralRows(data) {
    if (!data.length) return emptyRow(7, "No behavioral records found", "Try a different search or filter, or add a record.");
    return data.map(r => `
        <tr>
            <td>${formatDateLong(r.recorded_at)}</td>
            <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
            <td>${escapeHtml(r.grade_section)}</td>
            <td>${escapeHtml(r.behavior)}</td>
            <td><span class="pill ${BEHAVIORAL_STATUS_CLASS[r.status] || 'pill-yellow'}">${escapeHtml(r.status || 'Open')}</span></td>
            <td>${escapeHtml(r.recorded_by_name) || 'N/A'}</td>
            <td class="actions-cell">
                ${buildBehavioralActionButtons(r)}
            </td>
        </tr>`).join('');
}

async function renderBehavioral() {
    document.getElementById('search-behavioral').value = '';
    const statusEl = document.getElementById('filter-behavioral-status');
    if (statusEl) statusEl.value = '';
    const res = await fetch(BEH_API);
    allBehavioral = await res.json();
    document.getElementById('tbody-behavioral').innerHTML = buildBehavioralRows(allBehavioral);
}

function filterBehavioral() {
    const q        = document.getElementById('search-behavioral').value.toLowerCase();
    const statusEl = document.getElementById('filter-behavioral-status');
    const status   = statusEl ? statusEl.value : '';
    const filtered = allBehavioral.filter(r => {
        const matchSearch = !q || (r.full_name ?? '').toLowerCase().includes(q) || (r.student_code ?? '').toLowerCase().includes(q) || (r.behavior ?? '').toLowerCase().includes(q);
        return matchSearch && (!status || (r.status || 'Open') === status);
    });
    document.getElementById('tbody-behavioral').innerHTML = buildBehavioralRows(filtered);
}

async function saveBehavioral() {
    const payload = {
        id:          editingBehId,
        student_id:  document.getElementById('beh-student-id').value,
        behavior:    document.getElementById('beh-behavior').value.trim(),
        recorded_at: document.getElementById('beh-date').value
    };
    // Only send status when editing — a new record always starts Open
    // (same principle as a new Offense not asking for status up front).
    if (editingBehId) payload.status = document.getElementById('beh-status').value;

    if (!payload.student_id) { showNotif('⚠️ Please select a student.', 'warn'); return; }
    const behValidation = CSFValidate.validateFields([
        { id: 'beh-behavior', label: 'Behavior Observed', required: true },
    ]);
    if (!behValidation.valid) { showNotif(`⚠️ ${behValidation.firstError}`, 'warn'); return; }

    // Edit opened and saved without touching anything.
    if (editingBehId && originalBehData &&
        payload.student_id  == originalBehData.student_id  &&
        payload.behavior    === originalBehData.behavior   &&
        payload.recorded_at === originalBehData.recorded_at &&
        payload.status      === originalBehData.status) {
        showNotif('⚠️ Walang nabago.', 'warn');
        return;
    }

    // Addressed -> Open is a Reopen: same reason prompt as Reopen in Close
    // Case. Nothing is saved unless a reason is given (also enforced in
    // api/behavioral.php).
    if (editingBehId && editingBehOrigStatus === 'Addressed' && payload.status === 'Open') {
        const reason = await csfPrompt({
            title: 'Reopen Behavioral Record?', message: 'It goes back to Open. Its closed counseling case stays in Close Case.',
            label: 'Reason for Reopening', placeholder: 'Why is this record being reopened?',
            required: true, confirmText: 'Reopen', icon: 'reopen'
        });
        if (reason === null) return; // cancelled
        payload.reopen_reason = reason;
    }

    const method = editingBehId ? 'PUT' : 'POST';
    const res = await fetch(BEH_API, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }
    showNotif(editingBehId ? '✏️ Record updated!' : '✅ Behavioral record added!', editingBehId ? 'update' : 'success');
    closeBehavioralModal();
    renderBehavioral();
}

async function openEditBehavioral(id) {
    const res = await fetch(`${BEH_API}?id=${id}`);
    const r   = await res.json();
    editingBehId = r.id;
    editingBehOrigStatus = r.status ?? 'Open';
    if (!allStudents.length) await renderStudents();
    _ensureBehStudentSD();
    document.getElementById('behavioralModalTitle').textContent = 'Edit Behavioral Record';
    _behStudentSD.setSelectedById(r.student_id);
    document.getElementById('beh-behavior').value = r.behavior ?? '';
    document.getElementById('beh-date').value     = r.recorded_at ?? '';
    document.getElementById('beh-status').value   = r.status ?? 'Open';
    document.getElementById('beh-status-wrap').style.display = 'block';
    originalBehData = {
        student_id:  document.getElementById('beh-student-id').value,
        behavior:    document.getElementById('beh-behavior').value.trim(),
        recorded_at: document.getElementById('beh-date').value,
        status:      document.getElementById('beh-status').value
    };
    document.getElementById('behavioralModal').style.display = 'flex';
}

async function deleteBehavioral(id) {
    if (!await csfConfirm({ title: 'Archive Behavioral Record?', message: 'It will be hidden from active records but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
    const res    = await fetch(`${BEH_API}?id=${id}`, { method: 'DELETE' });
    const result = await res.json();
    if (result.success) { renderBehavioral(); showNotif('🗄️ Record archived. Find it in Archived Records.', 'delete'); }
    else { showNotif(`⚠️ ${result.message || 'Archive failed.'}`, 'warn'); }
}

// Step 3 — Behavioral Record → Counseling Session integration. Exact
// mirror of createCounselingFromOffense: reuses the existing Counseling
// Session modal/API (counseling_sessions.behavioral_record_id), no new
// workflow, just carries Student + Behavioral Record context over.
async function createCounselingFromBehavioral(behavioralId) {
    const beh = allBehavioral.find(b => b.id === behavioralId);
    if (!beh) { showNotif('⚠️ Behavioral record not found.', 'warn'); return; }

    await openAddCounseling();
    if (!allStudents.length) await renderStudents();
    counselingStudentDropdown.setData(allStudents);
    counselingStudentDropdown.setSelectedById(beh.student_id);

    document.getElementById('counseling-source').value = 'Behavioral Record';
    toggleOtherSourceField();
    document.getElementById('counseling-concern').value =
        `${beh.behavior} (${formatDateLong(beh.recorded_at)})`;
    await loadCounselingLinkOptions(beh.student_id, beh.id, null);

    document.getElementById('counselingModalTitle').textContent = 'Create Counseling Session (from Behavioral Record)';
}

/* ════════════════════════════════════════════════════════════
   OFFENSES & VIOLATIONS
   ════════════════════════════════════════════════════════════ */

const OFF_API = 'api/offenses.php';
const OFF_CAT_API = 'api/offense_categories.php';
let allOffenses = [];
let allOffenseCategories = [];
let editingOffId = null;
let editingOffOrigStatus = null; // status when the edit form was opened (to detect a Reopen)
let originalOffData = null;      // form values when Edit opened (to detect "no changes")
let originalCategoryData = null; // form values when Edit Category opened
let editingCategoryId = null;
let _offStudentSD = null;

// Severity → pill color, used everywhere a category's severity is shown
// (the Offenses table, the Log Incident preview, Manage Categories).
const SEVERITY_PILL_CLASS = { Minor: 'pill-gray', Moderate: 'pill-blue', Major: 'pill-orange', Severe: 'pill-red' };

function _ensureOffStudentSD() {
    if (!_offStudentSD) {
        _offStudentSD = new SearchDropdown({
            input: '#off-student-search',
            hidden: '#off-student-id',
            data: allStudents,
            valueField: 'id',
            titleField: 'full_name',
            subtitleField: 'student_code',
            gradeField: 'grade_section'
        });
        _sdRegistry['off-student-search'] = _offStudentSD;
    } else {
        _offStudentSD.setData(allStudents);
    }
}

/* ── Offense Categories (masterlist) ──────────────────────────
   Drives the category dropdown on the Log/Edit Offense form AND the
   "All Categories" filter on the Offenses table — both are populated
   from this same fetch so the two lists can never drift apart. */
async function loadOffenseCategories(force = false) {
    if (allOffenseCategories.length && !force) return allOffenseCategories;
    try {
        const res = await fetch(`${OFF_CAT_API}?all=1`);
        allOffenseCategories = await res.json();
    } catch (err) {
        console.error('Failed to load offense categories:', err);
        allOffenseCategories = [];
    }

    const catSelect = document.getElementById('off-category-id');
    if (catSelect) {
        const active = allOffenseCategories.filter(c => Number(c.is_active) === 1);
        catSelect.innerHTML = '<option value="">-- Select a category --</option>' +
            active.map(c => `<option value="${c.id}">${escapeHtml(c.category_name)} (${escapeHtml(c.severity_level)})</option>`).join('');
    }
    const filterSelect = document.getElementById('filter-offense-type');
    if (filterSelect) {
        const active = allOffenseCategories.filter(c => Number(c.is_active) === 1);
        filterSelect.innerHTML = '<option value="">All Categories</option>' +
            active.map(c => `<option value="${c.id}">${escapeHtml(c.category_name)}</option>`).join('');
    }
    return allOffenseCategories;
}

// Live preview under the category dropdown — shows the Severity Level
// and Default Sanction the moment a category is picked, so staff see
// exactly what this category implies without leaving the form.
function onOffenseCategoryChange() {
    const catId  = document.getElementById('off-category-id').value;
    const preview = document.getElementById('off-category-preview');
    const previewText = document.getElementById('off-category-preview-text');
    if (!catId) { preview.style.display = 'none'; return; }
    const cat = allOffenseCategories.find(c => String(c.id) === String(catId));
    if (!cat) { preview.style.display = 'none'; return; }
    preview.style.display = 'block';
    previewText.innerHTML = `<span class="pill ${SEVERITY_PILL_CLASS[cat.severity_level] || 'pill-gray'}">${escapeHtml(cat.severity_level)}</span>
        &nbsp;Default sanction: <strong>${escapeHtml(cat.default_sanction) || 'N/A'}</strong>`;
}

// ── Offense Categories page (System section) ─────────────────
async function renderOffenseCategories() {
    document.getElementById('search-categories').value = '';
    document.getElementById('filter-category-severity').value = '';
    document.getElementById('filter-category-status').value = 'active';
    await loadOffenseCategories(true);
    filterOffenseCategories();
}

function filterOffenseCategories() {
    const q   = document.getElementById('search-categories').value.trim().toLowerCase();
    const sev = document.getElementById('filter-category-severity').value;
    const st  = document.getElementById('filter-category-status').value;
    // Deactivated categories are hidden by default (Active view); switch the
    // status filter to Inactive / All Status to see them or reactivate one.
    const filtered = allOffenseCategories.filter(c =>
        (!q || (c.category_name || '').toLowerCase().includes(q)) &&
        (!sev || c.severity_level === sev) &&
        (!st || (st === 'active') === (Number(c.is_active) === 1)));
    document.getElementById('tbody-categories').innerHTML = buildCategoryRows(filtered);
}

function openAddCategory() {
    clearCategoryForm();
    document.getElementById('categoryModalTitle').textContent = 'Add Offense Category';
    document.getElementById('categoriesModal').style.display = 'flex';
}

function closeCategoryModal() {
    document.getElementById('categoriesModal').style.display = 'none';
    clearCategoryForm();
}

function buildCategoryRows(data) {
    if (!data.length) return emptyRow(5, "No categories found", "Add one to start classifying offenses.");
    return data.map(c => `
        <tr style="${Number(c.is_active) ? '' : 'opacity:.5'}">
            <td>${escapeHtml(c.category_name)}${Number(c.is_active) ? '' : ' <span style="font-size:.7rem;color:#aaa">(inactive)</span>'}</td>
            <td><span class="pill ${SEVERITY_PILL_CLASS[c.severity_level] || 'pill-gray'}">${escapeHtml(c.severity_level)}</span></td>
            <td style="font-size:.8rem;color:#666">${escapeHtml(c.default_sanction) || 'N/A'}</td>
            <td style="font-size:.8rem;color:#666">${escapeHtml(c.description) || 'N/A'}</td>
            <td class="actions-cell" style="white-space:nowrap">
                <button class="btn-sm icon-only" onclick="editOffenseCategory(${c.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>
                ${Number(c.is_active) ? `<button class="btn-sm danger" onclick="deactivateOffenseCategory(${c.id})">Deactivate</button>` : `<button class="btn-sm" onclick="reactivateOffenseCategory(${c.id})">Reactivate</button>`}
            </td>
        </tr>`).join('');
}

function clearCategoryForm() {
    editingCategoryId = null;
    document.getElementById('cat-id').value = '';
    document.getElementById('cat-name').value = '';
    document.getElementById('cat-severity').value = 'Minor';
    document.getElementById('cat-sanction').value = '';
    document.getElementById('cat-description').value = '';
    document.getElementById('catSaveBtn').textContent = '+ Add Category';
}

function editOffenseCategory(id) {
    const cat = allOffenseCategories.find(c => Number(c.id) === Number(id));
    if (!cat) return;
    editingCategoryId = id;
    document.getElementById('cat-id').value = cat.id;
    document.getElementById('cat-name').value = cat.category_name;
    document.getElementById('cat-severity').value = cat.severity_level;
    document.getElementById('cat-sanction').value = _naToBlank(cat.default_sanction);
    document.getElementById('cat-description').value = _naToBlank(cat.description);
    document.getElementById('catSaveBtn').textContent = 'Save Changes';
    document.getElementById('categoryModalTitle').textContent = 'Edit Offense Category';
    originalCategoryData = {
        category_name:    document.getElementById('cat-name').value.trim(),
        severity_level:   document.getElementById('cat-severity').value,
        default_sanction: document.getElementById('cat-sanction').value.trim(),
        description:      document.getElementById('cat-description').value.trim()
    };
    document.getElementById('categoriesModal').style.display = 'flex';
}

async function saveOffenseCategory() {
    const payload = {
        id: editingCategoryId,
        category_name: document.getElementById('cat-name').value.trim(),
        severity_level: document.getElementById('cat-severity').value,
        default_sanction: document.getElementById('cat-sanction').value.trim(),
        description: document.getElementById('cat-description').value.trim(),
    };
    if (!payload.category_name) { showNotif('⚠️ Category Name is required.', 'warn'); return; }
    if (editingCategoryId && originalCategoryData &&
        payload.category_name    === originalCategoryData.category_name &&
        payload.severity_level   === originalCategoryData.severity_level &&
        payload.default_sanction === originalCategoryData.default_sanction &&
        payload.description      === originalCategoryData.description) {
        showNotif('⚠️ Walang nabago.', 'warn');
        return;
    }

    const wasEditing = !!editingCategoryId;
    const method = wasEditing ? 'PUT' : 'POST';
    const res = await fetch(OFF_CAT_API, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }
    showNotif(wasEditing ? '✏️ Category updated!' : '✅ Category added!', wasEditing ? 'update' : 'success');
    closeCategoryModal();
    await loadOffenseCategories(true);
    filterOffenseCategories();
}

async function reactivateOffenseCategory(id) {
    const cat = allOffenseCategories.find(c => Number(c.id) === Number(id));
    if (!cat) return;
    const res = await fetch(OFF_CAT_API, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({
        id: cat.id, category_name: cat.category_name, severity_level: cat.severity_level,
        default_sanction: cat.default_sanction || '', description: cat.description || '', is_active: 1
    }) });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }
    showNotif('✅ Category reactivated.', 'success');
    await loadOffenseCategories(true);
    filterOffenseCategories();
}

async function deactivateOffenseCategory(id) {
    if (!await csfConfirm({ title: 'Deactivate Category?', message: 'It will stop appearing as an option for new incidents, but existing records that use it are unaffected.', confirmText: 'Deactivate', variant: 'danger' })) return;
    const res = await fetch(`${OFF_CAT_API}?id=${id}`, { method: 'DELETE' });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }
    showNotif('✅ Category deactivated.', 'success');
    await loadOffenseCategories(true);
    filterOffenseCategories();
}

async function openAddOffense() {
    editingOffId = null;
    await loadOffenseCategories();
    clearOffenseForm();
    if (!allStudents.length) await renderStudents();
    _ensureOffStudentSD();
    _offStudentSD.clear();
    document.getElementById('offenseModalTitle').textContent = 'Record Offense / Violation';
    document.getElementById('off-date').value = new Date().toISOString().split('T')[0];
    // Status is decided automatically (based on how many times this
    // category has been recorded for the student) when adding a new
    // record, so it's hidden here — only exposed when editing.
    document.getElementById('off-status-wrap').style.display = 'none';
    document.getElementById('offenseSaveBtn').textContent = 'Record';
    document.getElementById('offenseModal').style.display = 'flex';
}

function closeOffenseModal() {
    document.getElementById('offenseModal').style.display = 'none';
    editingOffId = null;
    clearOffenseForm();
}

function clearOffenseForm() {
    if (_offStudentSD) _offStudentSD.clear();
    const sid = document.getElementById('off-student-id'); if (sid) sid.value = '';
    document.getElementById('off-category-id').value = '';
    document.getElementById('off-category-preview').style.display = 'none';
    document.getElementById('off-description').value = '';
    document.getElementById('off-status').value = 'Warned';
    document.getElementById('off-date').value = '';
}

const OFFENSE_STATUS_CLASS = { Warned: 'pill-yellow', 'Notice Required': 'pill-red', Resolved: 'pill-green' };

// Notice Required is where staff are expected to act — surface the
// existing Counseling Session relationship (counseling_sessions.offense_id)
// right on the row instead of making staff hunt for it. Everything else
// (Edit / Archive) stays exactly as it was.
// Row actions for an Offense. There is no "View Counseling" here any more —
// the only counseling action is Launch Case (+), shown only while it makes
// sense:
//   Notice Required + no active session -> Launch Case (+)
//   Notice Required + active session    -> (nothing — a case is already
//                                           running; completing it auto-marks
//                                           the offense Resolved)
//   Warned / Resolved                   -> (nothing)
// Editing Resolved -> Notice Required needs a reason (see saveOffense) and
// removes the old closed case from Close Case, which brings the + back.
function buildOffenseActionButtons(r) {
    const buttons = [`<button class="btn-sm icon-only" onclick="openEditOffense(${r.id})" aria-label="Edit" title="Edit"><i class="fi fi-rr-pencil btn-ico" aria-hidden="true"></i></button>`];
    const hasActiveSession = !!r.linked_session_id && r.linked_session_status !== 'Closed';
    if (r.status === 'Notice Required' && !hasActiveSession) {
        buttons.push(`<button class="btn-sm icon-only" style="border-color:#463a6b;color:#463a6b" onclick="createCounselingFromOffense(${r.id})" aria-label="Launch Case" title="Launch Case"><i class="fi fi-rr-plus btn-ico" aria-hidden="true"></i></button>`);
    }
    buttons.push(`<button class="btn-sm danger" onclick="deleteOffense(${r.id})">Archive</button>`);
    return buttons.join('\n                ');
}

function buildOffenseRows(data) {
    if (!data.length) return emptyRow(8, "No offenses recorded", "Try a different search or filter, or record an offense.");
    return data.map(r => `
        <tr>
            <td>${formatDateLong(r.recorded_at)}</td>
            <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
            <td>${escapeHtml(r.type)}</td>
            <td><span class="pill ${SEVERITY_PILL_CLASS[r.severity_level] || 'pill-gray'}">${escapeHtml(r.severity_level) || 'N/A'}</span></td>
            <td>${escapeHtml(r.description)}</td>
            <td style="text-align:center">${r.count ?? 1}</td>
            <td><span class="pill ${OFFENSE_STATUS_CLASS[r.status] || 'pill-yellow'}">${escapeHtml(r.status)}</span></td>
            <td class="actions-cell">
                ${buildOffenseActionButtons(r)}
            </td>
        </tr>`).join('');
}

async function renderOffenses() {
    document.getElementById('search-offenses').value = '';
    document.getElementById('filter-offense-type').value = '';
    document.getElementById('filter-offense-severity').value = '';
    document.getElementById('filter-offense-status').value = '';
    await loadOffenseCategories();
    const res = await fetch(OFF_API);
    allOffenses = await res.json();
    document.getElementById('tbody-offenses').innerHTML = buildOffenseRows(allOffenses);
}

function filterOffenses() {
    const q        = document.getElementById('search-offenses').value.toLowerCase();
    const catId    = document.getElementById('filter-offense-type').value;
    const severity = document.getElementById('filter-offense-severity').value;
    const status   = document.getElementById('filter-offense-status').value;
    const filtered = allOffenses.filter(r => {
        const matchSearch = !q || (r.full_name ?? '').toLowerCase().includes(q) || (r.student_code ?? '').toLowerCase().includes(q) || (r.type ?? '').toLowerCase().includes(q);
        const matchCat      = !catId    || String(r.category_id) === String(catId);
        const matchSeverity = !severity || r.severity_level === severity;
        const matchStatus   = !status   || r.status === status;
        return matchSearch && matchCat && matchSeverity && matchStatus;
    });
    document.getElementById('tbody-offenses').innerHTML = buildOffenseRows(filtered);
}

async function saveOffense() {
    const payload = {
        id:          editingOffId,
        student_id:  document.getElementById('off-student-id').value,
        category_id: document.getElementById('off-category-id').value,
        description: document.getElementById('off-description').value.trim(),
        recorded_at: document.getElementById('off-date').value
    };
    // Only send status when editing — on a new record the server works
    // out Warned / Notice Required on its own from the category's severity
    // and the running offense count (Severe = Notice right away, Major = 2nd, others = 3rd).
    if (editingOffId) payload.status = document.getElementById('off-status').value;

    if (!payload.student_id)  { showNotif('⚠️ Please select a student.', 'warn'); return; }
    const offValidation = CSFValidate.validateFields([
        { id: 'off-category-id', label: 'Offense Category', required: true },
    ]);
    if (!offValidation.valid) { showNotif(`⚠️ ${offValidation.firstError}`, 'warn'); return; }

    // Edit opened and saved without touching anything.
    if (editingOffId && originalOffData &&
        payload.student_id  == originalOffData.student_id  &&
        payload.category_id == originalOffData.category_id &&
        payload.description === originalOffData.description &&
        payload.recorded_at === originalOffData.recorded_at &&
        payload.status      === originalOffData.status) {
        showNotif('⚠️ Walang nabago.', 'warn');
        return;
    }

    // Leaving Resolved is a Reopen: same reason prompt as Reopen in Close
    // Case. Nothing is saved unless a reason is given (also enforced in
    // api/offenses.php).
    if (editingOffId && editingOffOrigStatus === 'Resolved' && payload.status !== 'Resolved') {
        const reason = await csfPrompt({
            title: 'Reopen Offense Record?', message: `It goes back to ${payload.status}. Its closed counseling case stays in Close Case.`,
            label: 'Reason for Reopening', placeholder: 'Why is this record being reopened?',
            required: true, confirmText: 'Reopen', icon: 'reopen'
        });
        if (reason === null) return; // cancelled
        payload.reopen_reason = reason;
    }

    const method = editingOffId ? 'PUT' : 'POST';
    const res = await fetch(OFF_API, { method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    const result = await res.json();
    if (!result.success) { showNotif(`⚠️ ${result.message}`, 'warn'); return; }
    showNotif(editingOffId ? '✏️ Offense updated!' : '✅ Offense recorded!', editingOffId ? 'update' : 'success');
    closeOffenseModal();
    renderOffenses();
}

async function openEditOffense(id) {
    await loadOffenseCategories();
    const res = await fetch(`${OFF_API}?id=${id}`);
    const r   = await res.json();
    editingOffId = r.id;
    editingOffOrigStatus = r.status ?? 'Warned';
    if (!allStudents.length) await renderStudents();
    _ensureOffStudentSD();
    document.getElementById('offenseModalTitle').textContent = 'Edit Offense / Violation';
    _offStudentSD.setSelectedById(r.student_id);
    document.getElementById('off-category-id').value = r.category_id ?? '';
    onOffenseCategoryChange();
    document.getElementById('off-description').value = _naToBlank(r.description);
    document.getElementById('off-date').value         = r.recorded_at ?? '';
    document.getElementById('off-status').value        = r.status ?? 'Warned';
    document.getElementById('off-status-wrap').style.display = 'block';
    document.getElementById('offenseSaveBtn').textContent = 'Save';
    originalOffData = {
        student_id:  document.getElementById('off-student-id').value,
        category_id: document.getElementById('off-category-id').value,
        description: document.getElementById('off-description').value.trim(),
        recorded_at: document.getElementById('off-date').value,
        status:      document.getElementById('off-status').value
    };
    document.getElementById('offenseModal').style.display = 'flex';
}

async function deleteOffense(id) {
    if (!await csfConfirm({ title: 'Archive Offense Record?', message: 'It will be hidden from active records but can be restored anytime from Archived Records.', confirmText: 'Archive', variant: 'archive' })) return;
    const res    = await fetch(`${OFF_API}?id=${id}`, { method: 'DELETE' });
    const result = await res.json();
    if (result.success) { renderOffenses(); showNotif('🗄️ Record archived. Find it in Archived Records.', 'delete'); }
    else { showNotif(`⚠️ ${result.message || 'Archive failed.'}`, 'warn'); }
}

// ── Notice Required → Counseling Session integration ────────────
// Reuses the existing Counseling Session modal/API (counseling_sessions
// .offense_id) — no new workflow, just carries the Student + Offense
// context over so staff don't have to look the student up again. The
// direct linkage back onto student_offense_records (counseling_session_id
// + handled_by) is written server-side in api/counseling.php's POST
// handler once the session is actually created.
async function createCounselingFromOffense(offenseId) {
    const off = allOffenses.find(o => o.id === offenseId);
    if (!off) { showNotif('⚠️ Offense record not found.', 'warn'); return; }

    await openAddCounseling();
    if (!allStudents.length) await renderStudents();
    counselingStudentDropdown.setData(allStudents);
    counselingStudentDropdown.setSelectedById(off.student_id);

    document.getElementById('counseling-source').value = 'Offense / Violation';
    toggleOtherSourceField();
    document.getElementById('counseling-concern').value =
        `${off.type} (${formatDateLong(off.recorded_at)})${off.description ? ' — ' + off.description : ''}`;
    await loadCounselingLinkOptions(off.student_id, null, off.id);

    document.getElementById('counselingModalTitle').textContent = 'Create Counseling Session (from Offense)';
}

// "View Counseling Session" just opens the same Edit/View modal used
// everywhere else — read-only automatically if the session is Closed.
async function viewCounselingFromOffense(sessionId) {
    await openEditCounseling(sessionId);
}


// Clicking the offense alert row jumps to Offenses & Violations,
// filtered to "Notice Required", same pattern as goToInventoryAlert().
async function goToOffenseAlert() {
    const navItem = document.querySelector('.nav-item[onclick*="offenses"]');
    await showPage('offenses', navItem);
    const filterEl = document.getElementById('filter-offense-status');
    if (filterEl) { filterEl.value = 'Notice Required'; filterOffenses(); }
}

/* ════════════════════════════════════════════════════════════
   REFERRALS (Clinic Module)
   ════════════════════════════════════════════════════════════ */

const REF_API = 'api/referrals.php';
let allReferrals = [];
let _currentReferralDetail = null;

function buildReferralRows(data) {
    if (!data.length) return emptyRow(5, "No referral history found", "Referrals appear here automatically when a clinic visit is marked Referral Required.");
    return data.map(r => `
        <tr>
            <td>${formatDateLong(r.created_at)}</td>
            <td><strong>${escapeHtml(r.full_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(r.student_code)}</span></td>
            <td>${escapeHtml(r.grade_section)}</td>
            <td>${escapeHtml(r.referred_to)}</td>
            <td>${r.reason && r.reason.trim() ? escapeHtml(r.reason) : 'N/A'}</td>
            <td class="actions-cell">
                <button class="btn-sm icon-only" onclick="viewReferral(${r.id})" aria-label="View Details" title="View Details"><i class="fi fi-rr-eye btn-ico" aria-hidden="true"></i></button>
                ${r.visit_id ? `<button class="btn-sm sms icon-only" onclick="sendSmsForVisit(${r.visit_id})" aria-label="Send SMS" title="Send SMS"><i class="fi fi-rr-paper-plane btn-ico" aria-hidden="true"></i></button>` : ''}
            </td>
        </tr>`).join('');
}

async function renderReferrals() {
    document.getElementById('search-referrals').value = '';
    const res = await fetch(REF_API);
    allReferrals = await res.json();
    document.getElementById('tbody-referrals').innerHTML = buildReferralRows(allReferrals);
}

function filterReferrals() {
    const q = document.getElementById('search-referrals').value.toLowerCase();
    const filtered = allReferrals.filter(r =>
        !q || (r.full_name ?? '').toLowerCase().includes(q) || (r.referred_to ?? '').toLowerCase().includes(q)
    );
    document.getElementById('tbody-referrals').innerHTML = buildReferralRows(filtered);
}

async function viewReferral(id) {
    const res = await fetch(`${REF_API}?id=${id}`);
    const r   = await res.json();

    if (!r || !r.id) { showNotif('⚠️ This referral record no longer exists.', 'warn'); return; }
    if (Number(r.is_archived) === 1) { showNotif('⚠️ This referral has been archived. Open it from Archived Records.', 'warn'); return; }

    _currentReferralDetail = r;

    // Referral History is view/print/generate-letter only — there is no
    // outcome/status to record or resolve here. The whole point of a
    // referral is just to produce a Referral Letter to hand to the
    // doctor, parent, or guardian (see "Generate Referral Letter" below).
    document.getElementById('referral-view-fields').innerHTML = `
        <div class="form-group full"><label>Student</label><input type="text" readonly value="${escapeHtml(r.full_name || '')} (${escapeHtml(r.student_code || '')})"></div>
        <div class="form-group"><label>Grade Level</label><input type="text" readonly value="${escapeHtml(r.grade_section || '')}"></div>
        <div class="form-group"><label>Referral Date</label><input type="text" readonly value="${formatDateLong(r.created_at)}"></div>
        <div class="form-group"><label>Referred To</label><input type="text" readonly value="${escapeHtml(r.referred_to || '')}"></div>
        <div class="form-group"><label>Clinic Staff</label><input type="text" readonly value="${escapeHtml(r.clinic_staff_name || '')}"></div>
        <div class="form-group full"><label>Symptoms / Chief Complaint (from visit)</label><textarea readonly>${escapeHtml(r.visit_chief_complaint || '')}</textarea></div>
        <div class="form-group full"><label>Findings / Observation (from visit)</label><textarea readonly>${escapeHtml(r.visit_findings || '')}</textarea></div>
        <div class="form-group full"><label>Reason for Referral</label><textarea readonly>${escapeHtml(r.reason || '')}</textarea></div>
    `;
    document.getElementById('referralModal').style.display = 'flex';
}

function closeReferralModal() {
    document.getElementById('referralModal').style.display = 'none';
    _currentReferralDetail = null;
}

function printReferralDetail() {
    if (!_currentReferralDetail) return;
    const r = _currentReferralDetail;
    csfPrintWindow(`Referral History — ${escapeHtml(r.full_name || '')}`, '', csfDocShell('Referral History Record', `
        <table class="doc-info-table">
          <tr><td>Student Name</td><td>${escapeHtml(r.full_name || 'N/A')}</td><td>Student ID</td><td>${escapeHtml(r.student_code || 'N/A')}</td></tr>
          <tr><td>Grade &amp; Section</td><td>${escapeHtml(r.grade_section || 'N/A')}</td><td>Referral Date</td><td>${formatDateLong(r.created_at)}</td></tr>
          <tr><td>Referred To</td><td>${escapeHtml(r.referred_to || 'N/A')}</td><td>Clinic Staff</td><td>${escapeHtml(r.clinic_staff_name || 'N/A')}</td></tr>
        </table>
        <p><strong>Symptoms / Chief Complaint:</strong><br>${csfMultiline(r.visit_chief_complaint)}</p>
        <p><strong>Findings / Observation:</strong><br>${csfMultiline(r.visit_findings)}</p>
        <p><strong>Reason for Referral:</strong><br>${csfMultiline(r.reason)}</p>
    `));
}

/* ════════════════════════════════════════════════════════════
   STEP 6 — PARENT/GUARDIAN DOCUMENTS
   Internal-staff-only document generation (Conference Notice,
   Referral Letter). No new document table — generated on demand from
   data the entry point already loaded, printed via the same
   window.open()+print() pattern as printReferralDetail() above.
   Only api/documents.php exists server-side, and it does exactly one
   thing: write an audit_trail entry once the staff member actually
   generates a document (role-checked per document type). The document
   CONTENT itself is never persisted or sent to the server — it only
   ever exists in the staff member's own browser/printed page.
   ════════════════════════════════════════════════════════════ */
let _docContext = null; // { type, student:{...}, source_type, source_id, referral:{...}? }

function closeDocumentModal() {
    document.getElementById('documentModal').style.display = 'none';
    _docContext = null;
}

function _resetDocumentForm() {
    document.getElementById('doc-guardian-name').value = '';
    document.getElementById('doc-guardian-relationship').value = '';
    document.getElementById('doc-guardian-contact').value = '';
    document.getElementById('doc-conference-date').value = '';
    document.getElementById('doc-conference-time').value = '';
    document.getElementById('doc-venue').value = 'Guidance Office';
    document.getElementById('doc-purpose').value = 'Parent/Guardian Conference';
    document.getElementById('doc-requested-service').value = '';
    document.getElementById('doc-summary').value = '';
    document.getElementById('doc-action').value = '';
}

// Entry point: Referral view modal ("Generate Referral Letter"). Reuses
// the referral record already loaded by viewReferral() — no new fetch.
//
// FIX (post-Step-6 correction): only pull the REFERRAL's own fields
// (reason, referred_to, created_at, clinic_staff_name) — never the
// source Clinic Visit's symptoms/findings, which viewReferral()'s modal
// also displays for staff's own internal review but which must never
// flow into the parent/receiving-professional-facing printed letter.
// Also stores studentId explicitly (r.student_id) instead of reusing
// the referral's own r.id — those are two different numbers and mixing
// them up was the cause of the "Student not found" bug.
function openReferralLetterModal() {
    const r = _currentReferralDetail;
    if (!r) return;
    _resetDocumentForm();
    _docContext = { type: 'referral', studentId: r.student_id, student: r, source_type: 'referral', source_id: r.id };

    document.getElementById('documentModalTitle').textContent = 'Generate Referral Letter';
    document.getElementById('doc-student-display').value = `${r.full_name || ''} (${r.student_code || ''}) — ${r.grade_section || ''}`;
    document.getElementById('doc-guardian-fields').style.display = 'none';
    document.getElementById('doc-conference-fields').style.display = 'none';
    document.getElementById('doc-referral-fields').style.display = 'block';
    document.getElementById('doc-referral-destination').value = r.referred_to || '';
    document.getElementById('doc-referral-date').value = formatDateLong(r.created_at);
    document.getElementById('doc-referring-staff').value = r.clinic_staff_name || '';
    document.getElementById('doc-summary-label').innerHTML = 'Referral Summary <span style="color:#c0392b">*</span>';
    document.getElementById('doc-summary').value = r.reason || ''; // safe: staff's own referral reason, already reviewed once when the referral was created — never visit symptoms/findings
    document.getElementById('doc-summary').placeholder = 'What the receiving professional needs to know...';

    closeReferralModal();
    document.getElementById('documentModal').style.display = 'flex';
}

// Entry point: Student 360 "Documents" section ("Create Conference
// Notice"). Generic — not tied to one specific behavioral/counseling
// record, matching Step 6's instruction that generating a document is
// always an explicit, separate staff action.
function openConferenceNoticeModal(student) {
    _resetDocumentForm();
    _docContext = { type: 'conference', studentId: student.id, student, source_type: 'student', source_id: student.id };

    document.getElementById('documentModalTitle').textContent = 'Generate Parent/Guardian Conference Notice';
    document.getElementById('doc-student-display').value = `${student.full_name || ''} (${student.student_code || ''}) — ${student.grade_section || ''}`;
    document.getElementById('doc-guardian-fields').style.display = 'contents';
    document.getElementById('doc-guardian-name').value = _naToBlank(student.guardian_name);
    document.getElementById('doc-guardian-contact').value = _naToBlank(student.guardian_contact) || _naToBlank(student.emergency_contact_number);
    document.getElementById('doc-conference-fields').style.display = 'block';
    document.getElementById('doc-referral-fields').style.display = 'none';
    document.getElementById('doc-summary-label').innerHTML = 'Purpose / Concern Summary <span style="color:#c0392b">*</span>';
    document.getElementById('doc-summary').value = ''; // intentionally blank — staff must state the reason themselves
    document.getElementById('doc-summary').placeholder = 'Brief, parent-appropriate summary of why this conference is requested...';

    document.getElementById('documentModal').style.display = 'flex';
}

async function generateDocument() {
    if (!_docContext) return;
    const isConference = _docContext.type === 'conference';

    const rules = [
        { id: 'doc-summary', label: isConference ? 'Purpose/Concern Summary' : 'Referral Summary', required: true },
    ];
    if (isConference) {
        rules.push(
            { id: 'doc-guardian-name',    label: 'Parent/Guardian Name', required: true },
            { id: 'doc-conference-date',  label: 'Conference Date',      required: true },
            { id: 'doc-conference-time',  label: 'Conference Time',      required: true },
        );
    }
    const validation = CSFValidate.validateFields(rules);
    if (!validation.valid) { showNotif(`⚠️ ${validation.firstError}`, 'warn'); return; }

    const form = {
        summary: document.getElementById('doc-summary').value.trim(),
        action:  document.getElementById('doc-action').value.trim(),
    };
    if (isConference) {
        form.guardianName    = document.getElementById('doc-guardian-name').value.trim();
        form.relationship    = document.getElementById('doc-guardian-relationship').value;
        form.guardianContact = document.getElementById('doc-guardian-contact').value.trim();
        form.purpose  = document.getElementById('doc-purpose').value;
        form.confDate = document.getElementById('doc-conference-date').value;
        form.confTime = document.getElementById('doc-conference-time').value;
        form.venue    = document.getElementById('doc-venue').value.trim();
    } else {
        form.destination     = document.getElementById('doc-referral-destination').value;
        form.referralDate    = document.getElementById('doc-referral-date').value;
        form.referringStaff  = document.getElementById('doc-referring-staff').value;
        form.requestedService = document.getElementById('doc-requested-service').value.trim();
    }

    // Audit first (server-side role check happens here — if this fails,
    // the document is not generated, so an unauthorized generation
    // attempt can never slip through even though rendering itself is
    // entirely client-side). Uses the explicit studentId set by the
    // entry point above, not a guess derived from the source object.
    const logRes = await fetch('api/documents.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            document_type: _docContext.type,
            student_id: _docContext.studentId,
            source_type: _docContext.source_type,
            source_id: _docContext.source_id,
        })
    });
    const logResult = await logRes.json();
    if (!logResult.success) { showNotif('❌ ' + (logResult.message || 'You are not authorized to generate this document.'), 'error'); return; }

    printDocument(_docContext, form);
    closeDocumentModal();
}

function printDocument(ctx, form) {
    const s = ctx.student;
    const isConference = ctx.type === 'conference';
    const title = isConference ? 'Parent/Guardian Conference Notice' : 'Referral Letter';
    const today = formatDateLong(new Date().toISOString().slice(0, 10));

    const bodyHtml = isConference ? `
        <table class="doc-info-table">
          <tr><td>Student Name</td><td>${escapeHtml(s.full_name || '')}</td><td>Student ID</td><td>${escapeHtml(s.student_code || '')}</td></tr>
          <tr><td>Grade &amp; Section</td><td>${escapeHtml(s.grade_section || '')}</td><td>Date of Notice</td><td>${today}</td></tr>
          <tr><td>Parent/Guardian</td><td>${escapeHtml(form.guardianName)}</td><td>Relationship</td><td>${escapeHtml(form.relationship || 'N/A')}</td></tr>
          <tr><td>Contact Number</td><td colspan="3">${escapeHtml(form.guardianContact || 'N/A')}</td></tr>
        </table>
        <p>Dear ${escapeHtml(form.guardianName || 'Parent/Guardian')},</p>
        <p>You are respectfully requested to attend a conference regarding your child, <strong>${escapeHtml(s.full_name || '')}</strong>, concerning the following:</p>
        <p><strong>Purpose:</strong> ${escapeHtml(form.purpose)}</p>
        <p><strong>Summary:</strong><br>${escapeHtml(form.summary).replace(/\n/g, '<br>')}</p>
        ${form.action ? `<p><strong>Requested Action / Next Step:</strong><br>${escapeHtml(form.action).replace(/\n/g, '<br>')}</p>` : ''}
        <table class="doc-info-table">
          <tr><td>Conference Date</td><td>${formatDateLong(form.confDate)}</td><td>Time</td><td>${escapeHtml(formatTime12h(form.confTime))}</td></tr>
          <tr><td>Venue</td><td colspan="3">${escapeHtml(form.venue || 'N/A')}</td></tr>
        </table>
        <p>Your presence and cooperation are greatly appreciated in helping us support your child's welfare and development.</p>
    ` : `
        <table class="doc-info-table">
          <tr><td>Student Name</td><td>${escapeHtml(s.full_name || '')}</td><td>Student ID</td><td>${escapeHtml(s.student_code || '')}</td></tr>
          <tr><td>Grade &amp; Section</td><td>${escapeHtml(s.grade_section || '')}</td><td>Referral Date</td><td>${escapeHtml(form.referralDate)}</td></tr>
          <tr><td>Referred To</td><td colspan="3">${escapeHtml(form.destination)}</td></tr>
        </table>
        <p>To Whom It May Concern,</p>
        <p>This is to formally refer the above-named student for the following:</p>
        <p><strong>Reason for Referral:</strong><br>${escapeHtml(form.summary).replace(/\n/g, '<br>')}</p>
        ${form.requestedService ? `<p><strong>Requested Service / Intervention:</strong><br>${escapeHtml(form.requestedService).replace(/\n/g, '<br>')}</p>` : ''}
        ${form.action ? `<p><strong>Additional Notes:</strong><br>${escapeHtml(form.action).replace(/\n/g, '<br>')}</p>` : ''}
        <p>Kindly coordinate with our office for any additional information required to support this referral.</p>
        <table class="doc-info-table">
          <tr><td>Referring Staff / Office</td><td colspan="3">${escapeHtml(form.referringStaff || CURRENT_USER_NAME)}</td></tr>
        </table>
    `;

    const archivedBanner = s.is_archived == 1
        ? `<div style="border:2px solid #c0392b;color:#c0392b;text-align:center;padding:6px;margin-bottom:16px;font-weight:700">STUDENT RECORD IS ARCHIVED</div>`
        : '';

    csfPrintWindow(`${title} — ${escapeHtml(s.full_name || '')}`, '', csfDocShell(title, `
          ${archivedBanner}
          ${bodyHtml}`, `
          <div class="doc-signatures">
            <div class="doc-sig"><div class="doc-sig-line">${escapeHtml(CURRENT_USER_NAME)}<br>Prepared By</div></div>
            <div class="doc-sig"><div class="doc-sig-line">${isConference ? 'Parent/Guardian' : 'Receiving Professional / Authorized Recipient'}</div></div>
          </div>`));
}

// ── SMS CONFIGURATION & MESSAGE HISTORY (Clinic Module only) ────
// Automatic SMS is exclusive to the Clinic Module — this page (and
// api/settings.php behind it) is unreachable for Guidance Staff.
// Gateway credentials + all settings toggles are Administrator-only to
// WRITE; Clinic Staff can still READ them (to see whether automatic
// SMS is on) and always sees the full Message History.
let allSmsLogs = [];
let _currentSmsDetail = null;

async function loadSmsPage() {
    const isAdmin = isAdminRole();

    try {
        const res  = await fetch('api/settings.php');
        const data = await res.json();
        if (data.success) {
            const s = data.settings;
            document.getElementById('sms-enabled').checked = s.sms_enabled === '1';
            document.getElementById('sms-provider').value = s.sms_provider || '';
            document.getElementById('sms-sender-name').value = s.sms_sender_name || '';
            document.getElementById('sms-api-key').value = s.sms_api_key || '';
            document.getElementById('sms-notify-referral').checked  = s.sms_notify_referral  !== '0';
            document.getElementById('sms-notify-sent-home').checked = s.sms_notify_sent_home !== '0';
            document.getElementById('sms-notify-emergency').checked = s.sms_notify_emergency !== '0';
        }
    } catch (err) { console.error('Failed to load SMS settings:', err); }

    // Only an Administrator may change SMS Configuration — Clinic Staff
    // sees the same screen read-only, so they always know whether
    // automatic SMS is currently on without being able to change it.
    ['sms-enabled', 'sms-provider', 'sms-sender-name', 'sms-api-key',
     'sms-notify-referral', 'sms-notify-sent-home', 'sms-notify-emergency'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !isAdmin;
    });
    const gatewayFooter = document.getElementById('sms-gateway-footer');
    const togglesFooter = document.getElementById('sms-toggles-footer');
    if (gatewayFooter) gatewayFooter.style.display = isAdmin ? '' : 'none';
    if (togglesFooter) togglesFooter.style.display = isAdmin ? '' : 'none';

    document.getElementById('search-sms-logs').value = '';
    document.getElementById('filter-sms-status').value = '';

    try {
        const res  = await fetch('api/settings.php?logs=1');
        const data = await res.json();
        if (data.success) {
            allSmsLogs = data.logs;
            document.getElementById('tbody-sms-logs').innerHTML = buildSmsLogRows(allSmsLogs);
        }
    } catch (err) { console.error('Failed to load SMS logs:', err); }
}

function smsStatusPill(status) {
    const cls = status === 'Sent' ? 'pill-green' : status === 'Failed' ? 'pill-red' : 'pill-yellow';
    return `<span class="pill ${cls}">${escapeHtml(status)}</span>`;
}

function buildSmsLogRows(logs) {
    if (!logs.length) {
        return emptyRow(9, "No messages sent yet", "Messages sent to guardians will be listed here.");
    }
    return logs.map(l => {
        const preview = (l.message || '').replace(/\s+/g, ' ').trim();
        const shortPreview = preview.length > 60 ? preview.slice(0, 60) + '…' : preview;
        return `
        <tr>
            <td>${formatDateLong(l.sent_at)}</td>
            <td>${l.student_name ? `<strong>${escapeHtml(l.student_name)}</strong><br><span style="font-size:.75rem;color:#aaa">${escapeHtml(l.student_code)}</span>` : 'N/A'}</td>
            <td>${escapeHtml(l.guardian_name) || 'N/A'}</td>
            <td>${escapeHtml(l.recipient) || 'N/A'}</td>
            <td>${escapeHtml(shortPreview) || 'N/A'}</td>
            <td>${l.visit_id ? `#${l.visit_id}` : 'N/A'}</td>
            <td>${smsStatusPill(l.status)}</td>
            <td>${escapeHtml(l.sent_by) || 'Automatic'}</td>
            <td><button class="btn-sm icon-only" onclick="viewSmsDetails(${l.id})" aria-label="View Details" title="View Details"><i class="fi fi-rr-eye btn-ico" aria-hidden="true"></i></button></td>
        </tr>`;
    }).join('');
}

function filterSmsLogs() {
    const q      = document.getElementById('search-sms-logs').value.toLowerCase();
    const status = document.getElementById('filter-sms-status').value;
    const filtered = allSmsLogs.filter(l => {
        const matchQ = !q ||
            (l.student_name  || '').toLowerCase().includes(q) ||
            (l.guardian_name || '').toLowerCase().includes(q) ||
            (l.recipient     || '').toLowerCase().includes(q);
        const matchStatus = !status || l.status === status;
        return matchQ && matchStatus;
    });
    document.getElementById('tbody-sms-logs').innerHTML = buildSmsLogRows(filtered);
}

// Looks up by the SMS log's real database id — used both by the "View"
// button in the table and by clicking a related Notification Center entry.
// Async + self-healing: if called immediately after navigating to the
// SMS page (Notification Center click), allSmsLogs may not have
// finished loading yet — fetch once more before giving up.
async function viewSmsDetails(id) {
    // api/settings.php returns log ids as strings (mysqli default), but
    // the row's onclick="viewSmsDetails(${l.id})" emits them unquoted as
    // a numeric literal — so a strict === here always missed. Compare as
    // strings so "2" (from the API) matches 2 (from the click).
    let l = allSmsLogs.find(x => String(x.id) === String(id));
    if (!l) {
        try {
            const res  = await fetch('api/settings.php?logs=1');
            const data = await res.json();
            if (data.success) allSmsLogs = data.logs;
        } catch (err) { /* fall through to not-found */ }
        l = allSmsLogs.find(x => String(x.id) === String(id));
    }
    if (!l) { showNotif('⚠️ This SMS record no longer exists.', 'warn'); return; }

    _currentSmsDetail = l;
    document.getElementById('sms-details-fields').innerHTML = `
        <div class="form-group full"><label>Student</label><input type="text" readonly value="${escapeHtml(l.student_name || '')}"></div>
        <div class="form-group"><label>Guardian Name</label><input type="text" readonly value="${escapeHtml(l.guardian_name || '')}"></div>
        <div class="form-group"><label>Contact Number</label><input type="text" readonly value="${escapeHtml(l.recipient || '')}"></div>
        <div class="form-group"><label>Related Clinic Visit</label><input type="text" readonly value="${l.visit_id ? '#' + l.visit_id : 'N/A'}"></div>
        <div class="form-group"><label>Related Referral</label><input type="text" readonly value="${l.referral_id ? '#' + l.referral_id : 'N/A'}"></div>
        <div class="form-group"><label>Date &amp; Time Sent</label><input type="text" readonly value="${formatDateLong(l.sent_at)}"></div>
        <div class="form-group"><label>Delivery Status</label><input type="text" readonly value="${escapeHtml(l.status || '')}"></div>
        <div class="form-group"><label>Sent By</label><input type="text" readonly value="${escapeHtml(l.sent_by || 'Automatic')}"></div>
        <div class="form-group full"><label>Message</label><textarea readonly rows="6">${escapeHtml(l.message || '')}</textarea></div>
    `;
    document.getElementById('smsDetailsModal').style.display = 'flex';
}

function closeSmsDetailsModal() {
    document.getElementById('smsDetailsModal').style.display = 'none';
    _currentSmsDetail = null;
}

async function saveSmsSettings() {
    const payload = {
        sms_enabled: document.getElementById('sms-enabled').checked,
        sms_provider: document.getElementById('sms-provider').value,
        sms_sender_name: document.getElementById('sms-sender-name').value.trim(),
        sms_api_key: document.getElementById('sms-api-key').value,
        sms_notify_referral: document.getElementById('sms-notify-referral').checked,
        sms_notify_sent_home: document.getElementById('sms-notify-sent-home').checked,
        sms_notify_emergency: document.getElementById('sms-notify-emergency').checked,
    };

    const res = await fetch('api/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    const result = await res.json();
    if (result.success) showNotif('✅ SMS settings saved.', 'success');
    else showNotif(`⚠️ ${result.message || 'Failed to save settings.'}`, 'warn');
}

// Manual "Send SMS" — used when automatic SMS is disabled for an event,
// or a staff member wants to (re)notify a guardian for a specific visit.
async function sendSmsForVisit(visitId) {
    if (!await csfConfirm({ title: 'Send Guardian SMS?', message: 'A guardian SMS for this clinic visit will be sent now.', confirmText: 'Send SMS', variant: 'info' })) return;
    try {
        const res = await fetch('api/sms_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ visit_id: visitId })
        });
        const result = await res.json();
        if (!result.success) { showNotif(`⚠️ ${result.message || 'Could not send SMS.'}`, 'warn'); return; }
        showNotif(result.status === 'Failed' ? `❌ SMS delivery failed.` : `✅ SMS ${result.status.toLowerCase()}.`,
            result.status === 'Failed' ? 'warn' : 'success');
    } catch (err) {
        showNotif('⚠️ Could not reach the server.', 'warn');
    }
}

// ── AUDIT TRAIL (Admin Improvements) ─────────────────────────
async function renderAuditTrail() {
    const moduleFilter = document.getElementById('filter-audit-module').value;
    try {
        const res  = await fetch(`api/audit.php${moduleFilter ? `?module=${encodeURIComponent(moduleFilter)}` : ''}`);
        const data = await res.json();

        if (!data.success) {
            showNotif(`⚠️ ${data.message || 'Failed to load audit trail.'}`, 'warn');
            return;
        }

        // Populate the module filter dropdown once, keeping the current selection.
        const sel = document.getElementById('filter-audit-module');
        if (sel.options.length <= 1) {
            data.modules.forEach(m => {
                const o = document.createElement('option');
                o.value = m;
                o.textContent = m;
                sel.appendChild(o);
            });
        }

        const tbody = document.getElementById('tbody-audit-trail');
        tbody.innerHTML = !data.entries.length
            ? emptyRow(5, "No audit entries recorded yet", "System actions are listed here as staff use the system.")
            : data.entries.map(e => `
                <tr>
                    <td>${formatDateLong(e.created_at)} ${e.created_at ? e.created_at.split(' ')[1]?.slice(0,5) || '' : ''}</td>
                    <td>${escapeHtml(e.user_name)}${e.user_role ? ` <span style="color:#aaa;font-size:.75rem">(${escapeHtml(e.user_role)})</span>` : ''}</td>
                    <td><span class="pill pill-yellow">${escapeHtml(e.module)}</span></td>
                    <td>${escapeHtml(e.action)}</td>
                    <td>${escapeHtml(e.details) || 'N/A'}</td>
                </tr>`).join('');
    } catch (err) {
        showNotif('⚠️ Could not reach the server.', 'warn');
    }
}

