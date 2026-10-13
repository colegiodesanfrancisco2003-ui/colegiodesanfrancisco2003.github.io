/**
 * CSFValidate — shared client-side form validation.
 *
 * One reusable library instead of the ad-hoc `if (!payload.x) { ... }`
 * checks that used to live separately in every submit handler in
 * admin.js. Every form still decides ITS OWN required fields and
 * business rules by calling validateFields() with its own rule list —
 * this file only standardizes HOW a field gets checked, highlighted,
 * and reported, not WHICH fields are required for a given form.
 *
 * Usage from admin.js:
 *
 *   const result = CSFValidate.validateFields([
 *     { id: 'student-first-name', label: 'First Name', required: true },
 *     { id: 'student-guardian-contact', label: 'Guardian Contact Number', type: 'phone' },
 *     { id: 'student-birth-date', label: 'Birth Date', required: true, type: 'date', notFuture: true },
 *   ]);
 *   if (!result.valid) { showNotif('⚠️ ' + result.firstError, 'warn'); return; }
 *
 * Every checked field gets a `.field-invalid` class (red border, see
 * admin.css) plus an inline `.field-error-msg` under it when invalid,
 * and both are cleared the moment the field passes again (on blur/input).
 */
const CSFValidate = (() => {

  // Philippine mobile number: 09XXXXXXXXX (11 digits) or +639XXXXXXXXX.
  // Spaces/dashes are stripped before testing, so "0917 123 4567" passes.
  const PH_MOBILE_RE = /^(\+63|0)9\d{9}$/;
  const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  function normalizePhone(raw) {
    return String(raw ?? '').replace(/[\s-]/g, '');
  }

  function isPlaceholderOption(el) {
    if (!el || el.tagName !== 'SELECT') return false;
    const v = (el.value ?? '').trim();
    if (v === '') return true;
    const label = (el.options[el.selectedIndex]?.text ?? '').trim().toLowerCase();
    return label === '-- select --' || label === '--select--' || label === 'select' ||
           label === '-- none --' || label === '--none--' || label === 'none' ||
           label.startsWith('--');
  }

  function fieldEl(id) {
    return typeof id === 'string' ? document.getElementById(id) : id;
  }

  function clearError(el) {
    if (!el) return;
    el.classList.remove('field-invalid');
    const msg = el.parentElement?.querySelector(':scope > .field-error-msg');
    if (msg) msg.remove();
  }

  function showError(el, message) {
    if (!el) return;
    el.classList.add('field-invalid');
    let msg = el.parentElement?.querySelector(':scope > .field-error-msg');
    if (!msg) {
      msg = document.createElement('span');
      msg.className = 'field-error-msg';
      el.insertAdjacentElement('afterend', msg);
    }
    msg.textContent = message;
    // Self-heals: the moment the user edits/re-selects, re-check just
    // this field so the red border/message clear without a full re-submit.
    const revalidate = () => {
      const v = (el.value ?? '').trim();
      const stillPlaceholder = isPlaceholderOption(el);
      if (v !== '' && !stillPlaceholder) clearError(el);
    };
    el.removeEventListener('input', el._csfRevalidate || (() => {}));
    el.removeEventListener('change', el._csfRevalidate || (() => {}));
    el._csfRevalidate = revalidate;
    el.addEventListener('input', revalidate);
    el.addEventListener('change', revalidate);
  }

  function clearErrorsIn(container) {
    const scope = typeof container === 'string' ? document.getElementById(container) : (container || document);
    if (!scope) return;
    scope.querySelectorAll('.field-invalid').forEach(el => el.classList.remove('field-invalid'));
    scope.querySelectorAll('.field-error-msg').forEach(el => el.remove());
  }

  // Trims every text/textarea input inside a container and writes the
  // trimmed value back — call before reading payload values into an
  // object, so "  Juan  " never gets sent as-is.
  function trimAllIn(container) {
    const scope = typeof container === 'string' ? document.getElementById(container) : (container || document);
    if (!scope) return;
    scope.querySelectorAll('input[type="text"], input[type="tel"], input[type="email"], input:not([type]), textarea')
      .forEach(el => { el.value = (el.value ?? '').trim(); });
  }

  /**
   * rules: [{ id, label, required, type, notFuture, notPast, minDateId,
   *            minDateLabel, min, max }]
   *   type: 'text' (default) | 'phone' | 'email' | 'date' | 'numeric' | 'dropdown'
   *   required: field must not be empty / not a placeholder option
   *   notFuture / notPast: for type:'date', date can't be after/before today
   *   minDateId: for type:'date', this date must not be earlier than the
   *              date currently in the field with this id (e.g. Follow-up
   *              Date can't be earlier than Session Date)
   */
  function validateFields(rules) {
    let firstError = null;
    let firstInvalidEl = null;

    for (const rule of rules) {
      const el = fieldEl(rule.id);
      if (!el) continue; // field not present in this form/modal — skip
      clearError(el);

      const type = rule.type || 'text';
      const rawValue = el.value ?? '';
      const value = type === 'phone' ? normalizePhone(rawValue) : rawValue.trim();
      const isDropdown = el.tagName === 'SELECT';
      const empty = isDropdown ? isPlaceholderOption(el) : value === '';

      let err = null;

      if (rule.required && empty) {
        err = `${rule.label} is required.`;
      } else if (!empty && type === 'phone' && !PH_MOBILE_RE.test(value)) {
        err = `${rule.label} must be a valid Philippine mobile number (e.g. 09171234567).`;
      } else if (!empty && type === 'email' && !EMAIL_RE.test(value)) {
        err = `${rule.label} must be a valid email address.`;
      } else if (!empty && type === 'numeric') {
        const n = Number(value);
        if (Number.isNaN(n)) err = `${rule.label} must be a valid number.`;
        else if (rule.min !== undefined && n < rule.min) err = `${rule.label} must be at least ${rule.min}.`;
        else if (rule.max !== undefined && n > rule.max) err = `${rule.label} must be at most ${rule.max}.`;
      } else if (!empty && type === 'date') {
        const d = new Date(value + 'T00:00:00');
        const today = new Date(); today.setHours(0, 0, 0, 0);
        if (Number.isNaN(d.getTime())) {
          err = `${rule.label} is not a valid date.`;
        } else if (rule.notFuture && d.getTime() > today.getTime()) {
          err = `${rule.label} cannot be in the future.`;
        } else if (rule.notPast && d.getTime() < today.getTime()) {
          err = `${rule.label} cannot be in the past.`;
        } else if (rule.minDateId) {
          const minEl = fieldEl(rule.minDateId);
          const minRaw = minEl ? (minEl.value ?? '').trim() : '';
          if (minRaw !== '') {
            const minD = new Date(minRaw + 'T00:00:00');
            if (!Number.isNaN(minD.getTime()) && d.getTime() < minD.getTime()) {
              err = `${rule.label} cannot be earlier than ${rule.minDateLabel || 'the related date'}.`;
            }
          }
        }
      }

      if (err) {
        showError(el, err);
        if (!firstError) { firstError = err; firstInvalidEl = el; }
      }
    }

    if (firstInvalidEl && typeof firstInvalidEl.focus === 'function') {
      firstInvalidEl.focus({ preventScroll: false });
    }

    return { valid: !firstError, firstError };
  }

  return {
    validateFields,
    clearErrorsIn,
    trimAllIn,
    isPlaceholderOption,
    normalizePhone,
    PH_MOBILE_RE,
    EMAIL_RE,
  };
})();
