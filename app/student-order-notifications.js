(() => {
    const nav = document.querySelector('[data-order-notifications-user]');
    const badge = document.getElementById('student-order-notifications-count');
    const status = document.getElementById('student-order-notifications-status');
    const user = nav?.dataset.orderNotificationsUser;
    if (!badge || !status || !/^[1-9]\d*$/.test(user || '')) return;
    let count = Number(badge.textContent.trim()) || 0;
    let timer, controller;
    let failures = 0, stopped = false, leaving = false;
    function display(value) {
        if (value === count) return;
        const increased = value > count;
        count = value;
        badge.textContent = String(value);
        badge.hidden = value === 0;
        badge.setAttribute('aria-label', `${value} active orders with confirmed payment`);
        status.textContent = increased ? 'Payment confirmed. Open My Orders to see your order status.' : '';
    }
    function schedule() {
        clearTimeout(timer);
        if (!stopped && !leaving && !document.hidden) timer = setTimeout(refresh, Math.min(30000, 3000 * (failures + 1)));
    }
    async function refresh() {
        if (stopped || leaving || document.hidden || controller) return;
        clearTimeout(timer);
        const request = new AbortController(); controller = request;
        const timeout = setTimeout(() => request.abort(), 10000);
        try {
            const response = await fetch('order_notifications.php', {credentials:'same-origin',cache:'no-store',signal:request.signal});
            if (leaving || document.hidden) return;
            if (response.redirected || response.status === 401 || response.status === 403) { stopped = true; display(0); return; }
            if (!response.ok) throw new Error('Notification check unavailable');
            const data = await response.json();
            if (leaving || document.hidden) return;
            if (data.user_id !== user) { stopped = true; display(0); return; }
            if (!Number.isSafeInteger(data.confirmed_count) || data.confirmed_count < 0) throw new Error('Invalid notification response');
            display(data.confirmed_count);
            failures = 0;
        } catch (error) {
            if (!leaving && !document.hidden) failures++;
        } finally {
            clearTimeout(timeout); controller = null; schedule();
        }
    }
    function pause() { leaving = true; clearTimeout(timer); controller?.abort(); }
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
    schedule();
})();
