(() => {
    const workspace = document.body.dataset.staffWorkspace;
    if (!workspace) return;
    const storageKey = 'campusbite-staff-scroll-' + workspace;
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(storageKey));
        sessionStorage.removeItem(storageKey);
    } catch (_) {
        // Forms still work when browser storage is unavailable.
    }

    if (saved && Number.isFinite(saved.top) && saved.top >= 0 &&
        saved.path === location.pathname && Date.now() - saved.at < 120000) {
        const previousRestoration = history.scrollRestoration;
        history.scrollRestoration = 'manual';
        let interrupted = false;
        const interrupt = () => { interrupted = true; };
        const events = ['wheel', 'touchstart', 'pointerdown', 'keydown'];
        events.forEach(event => window.addEventListener(event, interrupt, { passive: true }));
        const restore = () => {
            if (!interrupted) window.scrollTo(0, saved.top);
        };
        requestAnimationFrame(restore);
        const finish = () => {
            restore();
            history.scrollRestoration = previousRestoration;
            events.forEach(event => window.removeEventListener(event, interrupt));
        };
        if (document.readyState === 'complete') finish();
        else window.addEventListener('load', finish, { once: true });
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        // A cancelled confirmation must not create a pending scroll restoration.
        if (event.defaultPrevented || form.method.toLowerCase() !== 'post') return;
        const top = form.dataset.returnScroll !== undefined
            ? Number(form.dataset.returnScroll) : window.scrollY;
        try {
            sessionStorage.setItem(storageKey, JSON.stringify({ top, at: Date.now(), path: location.pathname }));
        } catch (_) {}
    });

    document.querySelectorAll('[data-staff-notice]').forEach(notice => {
        const timers = [];
        let started = false;
        let dismissed = false;
        const dismiss = () => {
            dismissed = true;
            timers.forEach(timer => clearTimeout(timer));
            document.removeEventListener('visibilitychange', showWhenVisible);
            notice.remove();
        };
        const begin = () => {
            if (started || dismissed || notice.isConnected === false) return;
            if (document.hidden) {
                document.addEventListener('visibilitychange', showWhenVisible);
                return;
            }
            started = true;
            document.removeEventListener('visibilitychange', showWhenVisible);
            notice.classList.add('is-notice-ready');
            timers.push(setTimeout(() => notice.classList.add('is-leaving'), 4800));
            timers.push(setTimeout(dismiss, 5000));
        };
        const showWhenVisible = () => {
            if (document.hidden) {
                document.addEventListener('visibilitychange', showWhenVisible);
                return;
            }
            // Start after load-time scroll restoration has completed and the notice can paint.
            requestAnimationFrame(begin);
        };
        notice.querySelector('[data-dismiss-notice]').addEventListener('click', dismiss);
        if (notice.hasAttribute('data-auto-dismiss')) {
            if (document.readyState === 'complete') showWhenVisible();
            else window.addEventListener('load', showWhenVisible, { once: true });
        }
    });
})();
