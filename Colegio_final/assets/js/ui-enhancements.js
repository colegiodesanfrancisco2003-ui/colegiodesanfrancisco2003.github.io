/* ── UI enhancements (keyboard support only) ───────────────────
   Sidebar links, stat cards, and the user menu are <div role="button">
   so they can carry their existing onclick handlers. This makes Enter
   and Space activate them, and Escape close open dropdowns. It adds no
   new behaviour for mouse users and touches no data or API code. */
(function () {
  document.addEventListener("keydown", function (e) {
    var t = e.target;
    if ((e.key === "Enter" || e.key === " ") && t && t.getAttribute && t.getAttribute("role") === "button" && t.tagName !== "BUTTON") {
      e.preventDefault();
      t.click();
      return;
    }
    if (e.key === "Escape") {
      document.querySelectorAll(".user-menu-wrap.open, .notif-bell-wrap.open").forEach(function (el) {
        el.classList.remove("open");
      });
      document.body.classList.remove("sidebar-open");
    }
  });
})();
