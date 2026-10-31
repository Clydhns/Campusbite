(() => {
    const list = document.getElementById('staff-orders-list');
    const stats = document.getElementById('staff-order-stats');
    const tracking = document.getElementById('staff-order-tracking');
    const status = document.getElementById('staff-orders-sync-status');
    const button = document.getElementById('staff-orders-sync-refresh');
    const user = document.body.dataset.staffOrdersUser;
    const store = document.body.dataset.staffOrdersStore;
    if (!list || !stats || !tracking || !status || !button || !user || !store) return;
    const url = new URL('staff_dashboard.php', window.location.href);
    url.searchParams.set('sync', '1');
    let timer, controller;
    let stopped = false, leaving = false, failures = 0, revision = 0;
    let latestTracking = [], trackingLoading = false, trackingSource;

    function showStatus(message, state) {
        if (status.textContent !== message) status.textContent = message;
        if (status.dataset.state !== state) status.dataset.state = state;
    }
    function busy() {
        return document.body.dataset.staffOrdersSaving === 'true'
            || document.getElementById('payment-confirmation')?.open
            || list.querySelector('input:not([type="hidden"]):focus, textarea:focus, select:focus');
    }
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !leaving && !document.hidden) {
            timer = setTimeout(refresh, Math.min(30000, 1500 * (failures + 1)));
        }
    }
    function loadTracking() {
        if (trackingLoading || !trackingSource || !tracking.querySelector('[data-cb-tracking] script[type="application/json"]')) return;
        trackingLoading = true;
        const script = document.createElement('script');
        script.src = trackingSource;
        script.onload = () => document.dispatchEvent(new CustomEvent('campusbite:tracking-update', {detail:latestTracking}));
        script.onerror = () => { trackingLoading = false; };
        document.head.append(script);
    }
    function updateTracking(page) {
        const incoming = page.getElementById('staff-order-tracking');
        const payload = incoming?.querySelector('[data-cb-tracking] script[type="application/json"]');
        latestTracking = payload ? JSON.parse(payload.textContent) : [];
        const source = page.querySelector('script[src^="assets/order-tracking/tracking.js"]')?.getAttribute('src');
        if (source && /^assets\/order-tracking\/tracking\.js(?:\?v=\d+)?$/.test(source)) trackingSource = source;
        if (tracking.querySelector('[data-cb-tracking]')) {
            const pendingPayload = tracking.querySelector('script[type="application/json"]');
            if (pendingPayload) pendingPayload.textContent = JSON.stringify(latestTracking);
            document.dispatchEvent(new CustomEvent('campusbite:tracking-update', {detail:latestTracking}));
            loadTracking();
            return;
        }
        if (!payload) return;
        if (!trackingSource) return;
        tracking.replaceChildren(...Array.from(incoming.childNodes));
        loadTracking();
    }
    function sessionChanged() {
        stopped = true;
        list.replaceChildren(); stats.replaceChildren(); tracking.replaceChildren();
        button.hidden = true;
        status.dataset.state = 'error';
        status.textContent = 'Your staff session changed. ';
        const link = document.createElement('a'); link.href = 'login.php'; link.textContent = 'Sign in again'; status.append(link);
    }
    async function refresh(manual = false) {
        if (stopped || leaving || document.hidden || controller) return;
        if (busy()) { schedule(); return; }
        clearTimeout(timer);
        const generation = revision;
        const request = new AbortController();
        controller = request;
        if (manual === true) button.disabled = true;
        const timeout = setTimeout(() => request.abort(), 10000);
        try {
            url.searchParams.set('version', document.body.dataset.staffOrdersVersion || '');
            const response = await fetch(url.href, {credentials:'same-origin', cache:'no-store', signal:request.signal});
            if (!response.ok) throw new Error('Staff order updates unavailable');
            if (leaving || document.hidden || generation !== revision || busy()) return;
            if (response.status === 204) {
                loadTracking();
                failures = 0;
                showStatus('Live order updates connected.', 'connected');
                return;
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (leaving || document.hidden || generation !== revision || busy()) return;
            if (response.redirected || page.body.dataset.staffOrdersUser !== user || page.body.dataset.staffOrdersStore !== store) {
                sessionChanged(); return;
            }
            const incoming = page.getElementById('staff-orders-list');
            const incomingStats = page.getElementById('staff-order-stats');
            const version = page.body.dataset.staffOrdersVersion;
            if (!incoming || !incomingStats || !/^[a-f0-9]{64}$/.test(version || '')) throw new Error('Incomplete staff updates');
            const oldCards = Array.from(list.querySelectorAll('[data-staff-order]'));
            const ids = new Set(oldCards.map(card => card.dataset.staffOrder));
            const added = Array.from(incoming.querySelectorAll('[data-staff-order]')).some(card => !ids.has(card.dataset.staffOrder));
            const anchor = oldCards.find(card => card.getBoundingClientRect().bottom > 0);
            const anchorTop = anchor?.getBoundingClientRect().top;
            const top = window.scrollY;
            const focused = document.activeElement;
            const focusedCard = focused?.closest?.('[data-staff-order]');
            const action = focused?.closest?.('form')?.querySelector('[name="action"]')?.value;
            const href = focused?.closest?.('a[href]')?.href;
            list.replaceChildren(...Array.from(incoming.childNodes));
            stats.replaceChildren(...Array.from(incomingStats.childNodes));
            updateTracking(page);
            if (focusedCard && /^\d+$/.test(focusedCard.dataset.staffOrder)) {
                const replacement = list.querySelector(`[data-staff-order="${focusedCard.dataset.staffOrder}"]`);
                const form = Array.from(replacement?.querySelectorAll('form') || []).find(form => form.querySelector('[name="action"]')?.value === action);
                const link = Array.from(replacement?.querySelectorAll('a[href]') || []).find(link => link.href === href);
                (link || form?.querySelector('button[type="submit"]') || replacement || button).focus({preventScroll:true});
            }
            const newAnchor = anchor && list.querySelector(`[data-staff-order="${anchor.dataset.staffOrder}"]`);
            window.scrollTo(0, Math.max(0, top + (newAnchor && top > 0 ? newAnchor.getBoundingClientRect().top - anchorTop : 0)));
            document.body.dataset.staffOrdersVersion = version;
            failures = 0;
            showStatus(added ? 'New orders received.' : 'Orders updated just now.', 'connected');
        } catch (error) {
            if (!leaving && !document.hidden && generation === revision && !busy()) {
                failures++;
                showStatus('Connection interrupted. Retrying automatically.', 'error');
            }
        } finally {
            clearTimeout(timeout);
            controller = null;
            if (manual === true) button.disabled = false;
            schedule();
        }
    }
    function pause() { leaving = true; clearTimeout(timer); controller?.abort(); }
    document.addEventListener('campusbite:staff-order-save', event => {
        revision++;
        clearTimeout(timer);
        if (event.detail.saving) controller?.abort();
        else return refresh();
    });
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (document.hidden) controller?.abort(); else return refresh();
    });
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const target = new URL(link.href, window.location.href);
        if (target.origin === window.location.origin && (target.pathname !== window.location.pathname || target.search !== window.location.search)) pause();
    });
    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', event => { if (event.persisted) { leaving = false; return refresh(); } });
    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
    button.addEventListener('click', () => refresh(true));
    schedule();
})();
