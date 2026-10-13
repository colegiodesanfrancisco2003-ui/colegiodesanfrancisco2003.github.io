/* ── Login page script ────────────────────────────────────── */

const password    = document.getElementById("password");
const togglePw    = document.getElementById("togglePassword");
const loginForm   = document.getElementById("loginForm");
const loginBtn    = document.querySelector(".login-btn");

// Show/hide the password-visibility toggle icon.
password.addEventListener("input", function () {
    if (togglePw) {
        togglePw.style.display = this.value.length > 0 ? "block" : "none";
        if (this.value.length === 0) {
            this.type = "password";
            togglePw.classList.replace("fa-eye", "fa-eye-slash");
        }
    }
});

// Toggle password visibility on icon click.
if (togglePw) {
    togglePw.addEventListener("click", () => {
        const isHidden = password.type === "password";
        password.type  = isHidden ? "text" : "password";
        togglePw.classList.toggle("fa-eye-slash", !isHidden);
        togglePw.classList.toggle("fa-eye",        isHidden);
    });
}

// Client-side validation before form submission.
loginForm.addEventListener("submit", function (e) {
    const username = document.getElementById("username");

    if (!username.value.trim() || !password.value.trim()) {
        e.preventDefault();
        showToast("Please fill in all fields.");
        return;
    }

    loginBtn.disabled   = true;
    loginBtn.innerHTML  = `<i class="fa-solid fa-spinner fa-spin"></i> Logging in...`;
});

function showToast(message) {
    const toast = document.getElementById("toast");
    if (!toast) return;
    toast.textContent = message;
    toast.classList.add("show");
    setTimeout(() => toast.classList.remove("show"), 3000);
}

// Auto-dismiss the server-side error banner after 4 seconds.
// Guard with a null check so this never throws when the element
// is absent (e.g. on a successful login redirect).
(function dismissErrorBanner() {
    const errorBox = document.getElementById("errorBox");
    if (!errorBox) return;

    setTimeout(() => {
        errorBox.style.transition = "opacity 0.4s";
        errorBox.style.opacity    = "0";
        setTimeout(() => {
            errorBox.style.display = "none";
            // Clean up the URL so a page refresh doesn't re-show the error.
            const url = new URL(window.location.href);
            url.searchParams.delete("error");
            window.history.replaceState({}, document.title, url.toString());
        }, 400);
    }, 4000);
})();