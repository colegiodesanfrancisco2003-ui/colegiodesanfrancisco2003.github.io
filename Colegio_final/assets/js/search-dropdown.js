/* ============================================================
   SearchDropdown — single reusable searchable dropdown component
   Pure Vanilla JS (ES6). Replaces every <datalist> in the project.

   Usage:
     new SearchDropdown({
       input: "#visit-student-search",
       hidden: "#visit-student-id",
       data: allStudents,
       valueField: "id",
       titleField: "full_name",
       subtitleField: "student_code",
       extraField: "grade_section",     // optional 3rd line (e.g. grade level)
       type: "student",                  // "student" | "medicine" | "generic"
       placeholder: "Type to search...",
       onSelect: (item) => {}            // optional callback
     });

   Public API (returned instance):
     .setData(newArray)   refresh the dataset (e.g. after a fetch)
     .getSelected()        returns the currently selected raw item or null
     .setSelectedById(val) programmatically select by valueField match
     .clear()               clears input + hidden value + selection
     .destroy()              removes all listeners / DOM additions
   ============================================================ */

(function (global) {
  "use strict";

  let zCounter = 9000;
  let activeInstance = null; // only one panel open at a time

  function el(tag, cls, html) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (html !== undefined) e.innerHTML = html;
    return e;
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function normalize(str) {
    return (str === null || str === undefined ? "" : String(str)).toLowerCase();
  }

  // Inject component CSS once, regardless of how many instances exist.
  function ensureStyles() {
    if (document.getElementById("search-dropdown-styles")) return;
    const style = el("style");
    style.id = "search-dropdown-styles";
    style.textContent = `
.sd-wrap{position:relative;width:100%;}
.sd-input{width:100%;box-sizing:border-box;}
.sd-input.sd-input-active{border-color:#2e7d5a !important;box-shadow:0 0 0 3px rgba(46,125,90,.12);}
.sd-clear-btn{
  position:absolute;right:8px;top:50%;transform:translateY(-50%);
  width:20px;height:20px;border:none;background:#e3e6ea;color:#5b6573;
  border-radius:50%;cursor:pointer;font-size:13px;line-height:1;
  display:none;align-items:center;justify-content:center;padding:0;
  transition:background .15s ease,color .15s ease;
}
.sd-clear-btn:hover{background:#d3373d;color:#fff;}
.sd-wrap.sd-has-value .sd-clear-btn{display:flex;}

/* Read-only / disabled state — used when a form is opened in "View"
   mode (e.g. a Closed counseling session). The clear (x) button is a
   separate sibling element from the <input>, so setting input.disabled
   alone does NOT hide or disable it — this class is required too. */
.sd-wrap.sd-readonly{pointer-events:none;}
.sd-wrap.sd-readonly .sd-clear-btn{display:none !important;}

.sd-panel{
  position:absolute;left:0;right:0;
  background:#fff;border:1px solid #e1e4e9;border-radius:12px;
  box-shadow:0 12px 28px rgba(20,25,35,.14), 0 2px 6px rgba(20,25,35,.06);
  max-height:280px;overflow-y:auto;overflow-x:hidden;
  z-index:9999;padding:6px;
  opacity:0;transform:translateY(-6px) scale(.98);
  pointer-events:none;
  transition:opacity .14s ease, transform .14s ease;
}
.sd-panel.sd-open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto;}
.sd-panel::-webkit-scrollbar{width:8px;}
.sd-panel::-webkit-scrollbar-thumb{background:#d6dadf;border-radius:8px;}
.sd-panel::-webkit-scrollbar-track{background:transparent;}

.sd-option{
  display:flex;flex-direction:column;gap:2px;
  padding:9px 12px;border-radius:8px;cursor:pointer;
  transition:background .12s ease;
}
.sd-option + .sd-option{margin-top:1px;}
.sd-option:hover,.sd-option.sd-highlight{background:#f0f6f3;}
.sd-option.sd-selected{background:#e7f3ec;}
.sd-option.sd-selected .sd-opt-title{color:#1f5c40;}

.sd-opt-row{display:flex;align-items:center;justify-content:space-between;gap:10px;}
.sd-opt-title{font-weight:600;font-size:.92rem;color:#23292f;}
.sd-opt-badge{
  font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:999px;
  white-space:nowrap;flex-shrink:0;
}
.sd-opt-badge-id{background:#eef1f4;color:#5b6573;}
.sd-opt-badge-stock-ok{background:#e3f6ea;color:#1f7a45;}
.sd-opt-badge-stock-low{background:#fff3da;color:#9a6400;}
.sd-opt-badge-stock-out{background:#fde6e6;color:#b3261e;}
.sd-opt-badge-expired{background:#fde6e6;color:#b3261e;}
.sd-opt-badge-expiring{background:#fff3da;color:#9a6400;}
.sd-opt-badge-valid{background:#e3f6ea;color:#1f7a45;}

.sd-opt-sub{font-size:.78rem;color:#7a8290;display:flex;gap:8px;flex-wrap:wrap;}
.sd-opt-sub span:not(:last-child)::after{content:"•";margin-left:8px;color:#c4c9d0;}

.sd-empty,.sd-loading{
  padding:18px 12px;text-align:center;color:#9aa1ab;font-size:.85rem;
}
.sd-group-label{
  padding:6px 12px 2px;font-size:.68rem;font-weight:700;letter-spacing:.04em;
  text-transform:uppercase;color:#a6acb6;
}

@media (max-width:560px){
  .sd-panel{max-height:240px;}
  .sd-opt-title{font-size:.88rem;}
}
`;
    document.head.appendChild(style);
  }

  class SearchDropdown {
    constructor(opts) {
      this.input = typeof opts.input === "string" ? document.querySelector(opts.input) : opts.input;
      this.hidden = typeof opts.hidden === "string" ? document.querySelector(opts.hidden) : opts.hidden;

      if (!this.input) {
        console.error("SearchDropdown: input element not found for", opts.input);
        return;
      }

      this.data = Array.isArray(opts.data) ? opts.data : [];
      this.valueField = opts.valueField || "id";
      this.titleField = opts.titleField || "name";
      this.subtitleField = opts.subtitleField || null;
      this.extraField = opts.extraField || null;
      this.type = opts.type || "generic"; // "student" | "medicine" | "generic"
      this.placeholder = opts.placeholder || this.input.getAttribute("placeholder") || "Type to search...";
      this.onSelect = typeof opts.onSelect === "function" ? opts.onSelect : null;
      this.onClear = typeof opts.onClear === "function" ? opts.onClear : null;
      this.minChars = typeof opts.minChars === "number" ? opts.minChars : 0;
      this.noResultsText = opts.noResultsText || "No matches found.";

      this.filtered = [];
      this.highlightIndex = -1;
      this.selectedItem = null;
      this.isOpen = false;

      this._build();
      this._bindEvents();
      this.setData(this.data);
    }

    /* ---------- DOM setup ---------- */

    _build() {
      ensureStyles();

      // Wrap the existing input in a positioning container, in-place,
      // preserving the input's id/name/classes/attributes exactly.
      const parent = this.input.parentNode;
      this.wrap = el("div", "sd-wrap");
      parent.insertBefore(this.wrap, this.input);
      this.wrap.appendChild(this.input);

      this.input.classList.add("sd-input");
      this.input.setAttribute("autocomplete", "off");
      this.input.setAttribute("role", "combobox");
      this.input.setAttribute("aria-expanded", "false");
      this.input.setAttribute("aria-autocomplete", "list");
      this.input.setAttribute("placeholder", this.placeholder);

      // Clear (x) button — lets the user reopen/replace an already
      // selected value without retyping from scratch.
      this.clearBtn = el("button", "sd-clear-btn", "✕");
      this.clearBtn.type = "button";
      this.clearBtn.setAttribute("aria-label", "Clear selection");
      this.clearBtn.tabIndex = -1;
      this.wrap.appendChild(this.clearBtn);

      // Dropdown panel — appended to <body> so it is never clipped by
      // a modal's overflow:hidden, and z-indexed above modal overlays.
      this.panel = el("div", "sd-panel");
      this.panel.setAttribute("role", "listbox");
      document.body.appendChild(this.panel);

      this.listboxId = "sd-listbox-" + Math.random().toString(36).slice(2, 9);
      this.panel.id = this.listboxId;
      this.input.setAttribute("aria-controls", this.listboxId);
    }

    _bindEvents() {
      this._onFocus = () => { if (this.input.disabled) return; this._openAndFilter(); };
      this._onClick = () => { if (this.input.disabled) return; this._openAndFilter(); }; // re-open even with a value already selected
      this._onInput = () => {
        if (this.input.disabled) return;
        // Typing implicitly clears any previously committed selection
        // until the user picks again, so the hidden ID never points
        // at stale data.
        if (this.selectedItem) {
          this.selectedItem = null;
          if (this.hidden) this.hidden.value = "";
          this.wrap.classList.remove("sd-has-value");
        }
        this._openAndFilter();
      };
      this._onKeydown = (e) => { if (this.input.disabled) return; this._handleKeydown(e); };
      this._onDocClick = (e) => {
        if (!this.wrap.contains(e.target) && !this.panel.contains(e.target)) {
          this._close();
        }
      };
      this._onWheel = (e) => {
        // Allow the mouse wheel to scroll the panel itself; only
        // intercept page scroll when the cursor is over the panel.
        if (this.isOpen && this.panel.contains(e.target)) {
          e.stopPropagation();
        }
      };
      this._onReposition = () => { if (this.isOpen) this._positionPanel(); };
      this._onClearClick = (e) => {
        if (this.input.disabled) return;
        e.preventDefault();
        e.stopPropagation();
        this.clear();
        this.input.focus();
        this._openAndFilter();
      };

      this.input.addEventListener("focus", this._onFocus);
      this.input.addEventListener("click", this._onClick);
      this.input.addEventListener("input", this._onInput);
      this.input.addEventListener("keydown", this._onKeydown);
      this.clearBtn.addEventListener("click", this._onClearClick);
      this.panel.addEventListener("wheel", this._onWheel, { passive: true });
      document.addEventListener("click", this._onDocClick, true);
      window.addEventListener("resize", this._onReposition);
      window.addEventListener("scroll", this._onReposition, true);
    }

    /* ---------- data / filtering ---------- */

    setData(newData) {
      this.data = Array.isArray(newData) ? newData : [];
      if (this.isOpen) this._filter(this.input.value);
    }

    _matchScore(item, q) {
      const title = normalize(item[this.titleField]);
      const sub = this.subtitleField ? normalize(item[this.subtitleField]) : "";
      if (!q) return 0;
      if (sub === q || title === q) return 3;
      if (sub.startsWith(q) || title.startsWith(q)) return 2;
      if (title.includes(q) || sub.includes(q)) return 1;
      return -1;
    }

    _filter(rawQuery) {
      const q = normalize((rawQuery || "").trim());

      if (q.length < this.minChars) {
        this.filtered = this.minChars === 0 ? this.data.slice() : [];
      } else if (!q) {
        this.filtered = this.data.slice();
      } else {
        this.filtered = this.data
          .map((item) => ({ item, score: this._matchScore(item, q) }))
          .filter((x) => x.score >= 0)
          .sort((a, b) => b.score - a.score)
          .map((x) => x.item);
      }

      this.highlightIndex = this.filtered.length ? 0 : -1;
      this._render();
    }

    /* ---------- rendering ---------- */

    _badgeForMedicine(item) {
      const qty = Number(item.quantity);
      let stockBadge = "";
      if (!isNaN(qty)) {
        const cls = qty <= 0 ? "sd-opt-badge-stock-out" : qty < 10 ? "sd-opt-badge-stock-low" : "sd-opt-badge-stock-ok";
        const label = qty <= 0 ? "Out of stock" : `${qty} left`;
        stockBadge = `<span class="sd-opt-badge ${cls}">${escapeHtml(label)}</span>`;
      }

      let expiryBadge = "";
      if (item.expiry_date) {
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const [y, m, d] = String(item.expiry_date).split("-").map(Number);
        if (y && m && d) {
          const exp = new Date(y, m - 1, d); exp.setHours(0, 0, 0, 0);
          if (exp <= today) {
            expiryBadge = `<span class="sd-opt-badge sd-opt-badge-expired">Expired</span>`;
          } else {
            const daysLeft = Math.ceil((exp - today) / 86400000);
            if (daysLeft <= 15) {
              expiryBadge = `<span class="sd-opt-badge sd-opt-badge-expiring">${daysLeft}d left</span>`;
            }
          }
        }
      }
      return stockBadge + expiryBadge;
    }

    _renderOption(item, index) {
      const title = escapeHtml(item[this.titleField]);
      const isSelected = this.selectedItem && this.selectedItem[this.valueField] === item[this.valueField];
      const cls = ["sd-option"];
      if (index === this.highlightIndex) cls.push("sd-highlight");
      if (isSelected) cls.push("sd-selected");

      let rightBadge = "";
      let subParts = [];

      if (this.type === "medicine") {
        rightBadge = this._badgeForMedicine(item);
        if (item.unit) subParts.push(escapeHtml(item.unit));
      } else if (this.type === "student") {
        if (this.subtitleField && item[this.subtitleField]) {
          rightBadge = `<span class="sd-opt-badge sd-opt-badge-id">${escapeHtml(item[this.subtitleField])}</span>`;
        }
        if (this.extraField && item[this.extraField]) subParts.push(escapeHtml(item[this.extraField]));
      } else {
        if (this.subtitleField && item[this.subtitleField]) {
          subParts.push(escapeHtml(item[this.subtitleField]));
        }
        if (this.extraField && item[this.extraField]) subParts.push(escapeHtml(item[this.extraField]));
      }

      const subHtml = subParts.length
        ? `<div class="sd-opt-sub">${subParts.map((p) => `<span>${p}</span>`).join("")}</div>`
        : "";

      const optEl = el(
        "div",
        cls.join(" "),
        `<div class="sd-opt-row"><span class="sd-opt-title">${title}</span>${rightBadge}</div>${subHtml}`
      );
      optEl.setAttribute("role", "option");
      optEl.dataset.index = String(index);
      optEl.addEventListener("mousedown", (e) => {
        // mousedown (not click) so it fires before the input's blur/close logic
        e.preventDefault();
        this._select(item);
      });
      optEl.addEventListener("mouseenter", () => {
        this.highlightIndex = index;
        this._updateHighlightClasses();
      });
      return optEl;
    }

    _render() {
      this.panel.innerHTML = "";

      if (!this.filtered.length) {
        this.panel.appendChild(el("div", "sd-empty", escapeHtml(this.noResultsText)));
        return;
      }

      const frag = document.createDocumentFragment();
      this.filtered.forEach((item, idx) => frag.appendChild(this._renderOption(item, idx)));
      this.panel.appendChild(frag);
      this._scrollHighlightIntoView();
    }

    _updateHighlightClasses() {
      const opts = this.panel.querySelectorAll(".sd-option");
      opts.forEach((o, i) => o.classList.toggle("sd-highlight", i === this.highlightIndex));
      this._scrollHighlightIntoView();
    }

    _scrollHighlightIntoView() {
      const opt = this.panel.querySelector(`.sd-option[data-index="${this.highlightIndex}"]`);
      if (!opt) return;
      const panelRect = this.panel.getBoundingClientRect();
      const optRect = opt.getBoundingClientRect();
      if (optRect.bottom > panelRect.bottom) {
        this.panel.scrollTop += optRect.bottom - panelRect.bottom;
      } else if (optRect.top < panelRect.top) {
        this.panel.scrollTop -= panelRect.top - optRect.top;
      }
    }

    /* ---------- positioning ---------- */

    _positionPanel() {
      const rect = this.input.getBoundingClientRect();
      const viewportH = window.innerHeight;
      const spaceBelow = viewportH - rect.bottom;
      const spaceAbove = rect.top;
      const panelMaxHeight = 280;

      const openUpward = spaceBelow < panelMaxHeight + 12 && spaceAbove > spaceBelow;

      this.panel.style.left = rect.left + window.scrollX + "px";
      this.panel.style.width = rect.width + "px";

      if (openUpward) {
        this.panel.style.top = "auto";
        this.panel.style.bottom = window.innerHeight - rect.top - window.scrollY + 6 + "px";
        this.panel.style.maxHeight = Math.max(140, spaceAbove - 16) + "px";
      } else {
        this.panel.style.bottom = "auto";
        this.panel.style.top = rect.bottom + window.scrollY + 6 + "px";
        this.panel.style.maxHeight = Math.max(140, Math.min(panelMaxHeight, spaceBelow - 16)) + "px";
      }
    }

    /* ---------- open / close ---------- */

    _openAndFilter() {
      if (activeInstance && activeInstance !== this) activeInstance._close();
      activeInstance = this;
      this.isOpen = true;
      this.input.classList.add("sd-input-active");
      this.input.setAttribute("aria-expanded", "true");
      this._positionPanel();
      this._filter(this.input.value);
      requestAnimationFrame(() => this.panel.classList.add("sd-open"));
    }

    _close() {
      if (!this.isOpen) return;
      this.isOpen = false;
      this.input.classList.remove("sd-input-active");
      this.input.setAttribute("aria-expanded", "false");
      this.panel.classList.remove("sd-open");
      if (activeInstance === this) activeInstance = null;
    }

    /* ---------- selection ---------- */

    _select(item) {
      this.selectedItem = item;
      this.input.value = item[this.titleField] != null ? item[this.titleField] : "";
      if (this.hidden) this.hidden.value = item[this.valueField];
      this.wrap.classList.add("sd-has-value");
      this._close();
      if (this.onSelect) this.onSelect(item);
      // Fire a native 'change' event so any existing non-component
      // listeners on the input keep working unmodified.
      this.input.dispatchEvent(new Event("change", { bubbles: true }));
    }

    /* ---------- keyboard ---------- */

    _handleKeydown(e) {
      if (e.key === "ArrowDown") {
        e.preventDefault();
        if (!this.isOpen) { this._openAndFilter(); return; }
        if (this.filtered.length) {
          this.highlightIndex = (this.highlightIndex + 1) % this.filtered.length;
          this._updateHighlightClasses();
        }
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (!this.isOpen) { this._openAndFilter(); return; }
        if (this.filtered.length) {
          this.highlightIndex = (this.highlightIndex - 1 + this.filtered.length) % this.filtered.length;
          this._updateHighlightClasses();
        }
      } else if (e.key === "Enter") {
        if (this.isOpen && this.highlightIndex >= 0 && this.filtered[this.highlightIndex]) {
          e.preventDefault();
          this._select(this.filtered[this.highlightIndex]);
        }
      } else if (e.key === "Escape") {
        if (this.isOpen) {
          e.preventDefault();
          this._close();
        }
      } else if (e.key === "Tab") {
        // Let Tab move focus naturally; just close the panel first.
        this._close();
      }
    }

    /* ---------- public helpers ---------- */

    getSelected() {
      return this.selectedItem;
    }

    setSelectedById(value) {
      if (value === null || value === undefined || value === "") {
        this.clear();
        return;
      }
      const match = this.data.find((d) => String(d[this.valueField]) === String(value));
      if (match) {
        this.selectedItem = match;
        this.input.value = match[this.titleField] != null ? match[this.titleField] : "";
        if (this.hidden) this.hidden.value = match[this.valueField];
        this.wrap.classList.add("sd-has-value");
      } else {
        this.clear();
      }
    }

    clear() {
      this.selectedItem = null;
      this.input.value = "";
      if (this.hidden) this.hidden.value = "";
      this.wrap.classList.remove("sd-has-value");
      this._close();
      if (this.onClear) this.onClear();
    }

    // Toggles read-only/view mode: disables the underlying <input>
    // (blocks typing, focus, and click-to-open), hides the clear (x)
    // button regardless of whether a value is selected, and closes the
    // panel if it happened to be open. Call with (false) to restore
    // normal Add/Edit behavior — the input and clear button work exactly
    // as before.
    setDisabled(disabled) {
      this.input.disabled = !!disabled;
      this.wrap.classList.toggle("sd-readonly", !!disabled);
      if (disabled) this._close();
    }

    destroy() {
      this.input.removeEventListener("focus", this._onFocus);
      this.input.removeEventListener("click", this._onClick);
      this.input.removeEventListener("input", this._onInput);
      this.input.removeEventListener("keydown", this._onKeydown);
      this.clearBtn.removeEventListener("click", this._onClearClick);
      document.removeEventListener("click", this._onDocClick, true);
      window.removeEventListener("resize", this._onReposition);
      window.removeEventListener("scroll", this._onReposition, true);
      if (this.panel.parentNode) this.panel.parentNode.removeChild(this.panel);
      if (this.wrap && this.wrap.parentNode) {
        this.wrap.parentNode.insertBefore(this.input, this.wrap);
        this.wrap.parentNode.removeChild(this.wrap);
      }
      if (activeInstance === this) activeInstance = null;
    }
  }

  global.SearchDropdown = SearchDropdown;
})(window);