(() => {
    const shell = document.getElementById("auth-shell");
    if (!shell) return;
    const show = (mode, focus = false) => {
        const signup = mode === "register";
        shell.classList.toggle("sign-up-mode", signup);
        document.getElementById("login-view").hidden = signup;
        document.getElementById("register-view").hidden = !signup;
        shell.querySelectorAll("[data-panel]").forEach(panel => {
            panel.hidden = panel.dataset.panel !== mode;
        });
        document.title = "CampusBite - " + (signup ? "Register" : "Login");
        if (focus) document.getElementById(mode + "-title").focus({ preventScroll: true });
    };
    shell.querySelectorAll("[data-switch]").forEach(link => {
        link.addEventListener("click", event => {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            event.preventDefault();
            history.pushState({}, "", link.getAttribute("href"));
            show(link.dataset.switch, true);
        });
    });
    const password = document.getElementById("login-password");
    const passwordToggle = document.getElementById("toggle-login-password");
    if (password && passwordToggle) {
        passwordToggle.addEventListener("click", () => {
            const reveal = password.type === "password";
            password.type = reveal ? "text" : "password";
            passwordToggle.setAttribute("aria-pressed", String(reveal));
            passwordToggle.setAttribute(
                "aria-label",
                reveal ? "Hide password" : "Show password"
            );
        });
    }
    window.addEventListener("popstate", () => {
        show(location.pathname.endsWith("/register.php") ? "register" : "login", true);
    });
})();
