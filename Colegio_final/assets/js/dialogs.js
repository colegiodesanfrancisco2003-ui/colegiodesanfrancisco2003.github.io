/* ══════════════════════════════════════════════════════════════
   CSF Dialogs — styled replacements for window.confirm()/prompt().
   Usage (inside an async function):
     if (!await csfConfirm({ title, message, confirmText, variant })) return;
     const reason = await csfPrompt({ title, message, label, required: true });
     if (reason === null) return;           // cancelled
   variant / icon: 'archive' | 'danger' | 'restore' | 'info' | 'reopen'
   ══════════════════════════════════════════════════════════════ */
(function () {
  const ICONS = {
    archive: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>',
    danger:  '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
    restore: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>',
    reopen:  '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>',
    info:    '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>'
  };

  let overlay, activeResolve = null, lastFocus = null;

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function build() {
    overlay = document.createElement('div');
    overlay.className = 'csf-dialog-overlay';
    overlay.innerHTML = '<div class="csf-dialog" role="alertdialog" aria-modal="true" aria-labelledby="csfDlgTitle" aria-describedby="csfDlgMsg"></div>';
    document.body.appendChild(overlay);
    overlay.addEventListener('mousedown', e => { if (e.target === overlay) close(null); });
    document.addEventListener('keydown', onKey, true);
  }

  function close(value) {
    if (!overlay || !activeResolve) return;
    const resolve = activeResolve;
    activeResolve = null;
    overlay.classList.remove('open');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    resolve(value);
  }

  function onKey(e) {
    if (!activeResolve || !overlay.classList.contains('open')) return;
    const dlg = overlay.firstElementChild;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(null); return; }
    if (e.key === 'Tab') {
      const f = dlg.querySelectorAll('button, textarea');
      if (!f.length) return;
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function show(opts, withInput) {
    if (!overlay) build();
    if (activeResolve) close(null);           // never stack two dialogs
    lastFocus = document.activeElement;

    const variant = opts.variant || opts.icon || 'info';
    const iconKey = ICONS[opts.icon] ? opts.icon : (ICONS[variant] ? variant : 'info');
    const dlg = overlay.firstElementChild;
    dlg.className = 'csf-dialog csf-' + variant;
    dlg.innerHTML =
      '<div class="csf-dialog-icon">' + ICONS[iconKey] + '</div>' +
      '<h3 class="csf-dialog-title" id="csfDlgTitle">' + esc(opts.title || 'Please confirm') + '</h3>' +
      '<p class="csf-dialog-msg" id="csfDlgMsg">' + esc(opts.message || '') + '</p>' +
      (withInput
        ? '<label class="csf-dialog-label" for="csfDlgInput">' + esc(opts.label || 'Reason') +
          (opts.required ? ' <span style="color:#c0392b">*</span>' : '') + '</label>' +
          '<textarea id="csfDlgInput" class="csf-dialog-input" rows="3" placeholder="' + esc(opts.placeholder || '') + '"></textarea>' +
          '<div class="csf-dialog-error" id="csfDlgErr"></div>'
        : '') +
      '<div class="csf-dialog-actions">' +
        '<button type="button" class="csf-btn csf-btn-cancel" data-act="cancel">' + esc(opts.cancelText || 'Cancel') + '</button>' +
        '<button type="button" class="csf-btn csf-btn-confirm" data-act="ok">' + esc(opts.confirmText || 'Confirm') + '</button>' +
      '</div>';

    return new Promise(resolve => {
      activeResolve = resolve;
      const input = dlg.querySelector('#csfDlgInput');
      const submit = () => {
        if (!withInput) { close(true); return; }
        const v = input.value.trim();
        if (opts.required && !v) {
          dlg.querySelector('#csfDlgErr').textContent = (opts.label || 'Reason') + ' is required.';
          input.classList.add('csf-invalid');
          input.focus();
          return;
        }
        close(v);
      };
      dlg.querySelector('[data-act="cancel"]').onclick = () => close(withInput ? null : false);
      dlg.querySelector('[data-act="ok"]').onclick = submit;
      if (input) input.addEventListener('input', () => {
        input.classList.remove('csf-invalid');
        dlg.querySelector('#csfDlgErr').textContent = '';
      });
      // Enter confirms a plain confirm dialog; in the textarea Ctrl+Enter submits.
      dlg.onkeydown = e => {
        if (e.key === 'Enter' && (!withInput || e.ctrlKey || e.metaKey)) { e.preventDefault(); submit(); }
      };
      overlay.classList.add('open');
      // Destructive actions focus Cancel so a stray Enter can't confirm them.
      const focusEl = withInput ? input : dlg.querySelector(variant === 'danger' || variant === 'archive' ? '[data-act="cancel"]' : '[data-act="ok"]');
      setTimeout(() => focusEl && focusEl.focus(), 30);
    });
  }

  // Resolves true / false.
  window.csfConfirm = function (opts) {
    if (typeof opts === 'string') opts = { message: opts };
    return show(opts, false).then(v => v === true);
  };
  // Resolves the trimmed text, or null when cancelled.
  window.csfPrompt = function (opts) {
    return show(opts || {}, true).then(v => (typeof v === 'string' ? v : null));
  };
})();
