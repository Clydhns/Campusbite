(() => {
    const list = document.getElementById('orders-live-list');
    const empty = document.getElementById('orders-filter-empty');
    const status = document.getElementById('orders-sync-status');
    const buttons = Array.from(document.querySelectorAll('[data-order-filter]'));
    const user = document.body.dataset.ordersUser;
    if (!list || !empty || !status || !user) return;
    let selected = 'all';
    let snapshot = list.innerHTML;
    let timer;
    let controller;
    let stopped = false;
    let leaving = false;
    let failures = 0;

    function filter() {
        const cards = Array.from(list.querySelectorAll('[data-order-group]'));
        let count = 0;
        cards.forEach(card => {
            card.hidden = selected !== 'all' && card.dataset.orderGroup !== selected;
            if (!card.hidden) count++;
        });
        empty.hidden = selected === 'all' || count > 0;
        buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.orderFilter === selected)));
    }
    buttons.forEach(button => button.addEventListener('click', () => { selected = button.dataset.orderFilter; filter(); }));

    function schedule() {
        clearTimeout(timer);
        if (!stopped && !leaving && !document.hidden) timer = setTimeout(refresh, Math.min(30000, 4000 * (failures + 1)));
    }
    async function refresh() {
        if (stopped || leaving || document.hidden || controller) return;
        const modal = document.getElementById('cancelConfirmation');
        const editingReceipt = () => list.querySelector('.gcash-receipt-form[data-dirty="true"], .gcash-receipt-form[data-saving="true"], .gcash-receipt-form[data-confirming="true"]');
        if (list.contains(document.activeElement) || editingReceipt() || (modal && !modal.hidden)) { schedule(); return; }
        controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch('orders.php', {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (!response.ok) throw new Error('Order refresh failed');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (response.redirected || page.body.dataset.ordersUser !== user) {
                stopped = true;
                list.replaceChildren();
                empty.hidden = true;
                status.dataset.state = 'error';
                status.textContent = 'Your session changed. ';
                const link = document.createElement('a'); link.href='login.php'; link.textContent='Sign in again'; status.append(link);
                return;
            }
            const incoming = page.getElementById('orders-live-list');
            if (!incoming) throw new Error('Incomplete order response');
            // Never replace a cancellation form or keyboard-focused details mid-interaction.
            if (list.contains(document.activeElement) || editingReceipt() || (modal && !modal.hidden)) return;
            if (incoming.innerHTML !== snapshot) {
                const opened = new Set(Array.from(list.querySelectorAll('details[open]')).map(details => details.dataset.orderDetails));
                const top = window.scrollY;
                list.style.minHeight = list.getBoundingClientRect().height + 'px';
                snapshot = incoming.innerHTML;
                list.replaceChildren(...Array.from(incoming.childNodes));
                list.querySelectorAll('[data-order-details]').forEach(details => { details.open = opened.has(details.dataset.orderDetails); });
                filter();
                window.scrollTo(0, top);
                requestAnimationFrame(() => { list.style.minHeight=''; });
                status.textContent = 'Orders updated just now';
            } else status.textContent = 'Live order updates connected';
            status.dataset.state = 'connected';
            failures = 0;
        } catch (error) {
            if (!leaving && !document.hidden) {
                failures++;
                status.dataset.state = 'error';
                status.textContent = 'Updates paused. Retrying automatically.';
            }
        } finally {
            clearTimeout(timeout);
            controller = null;
            schedule();
        }
    }
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (document.hidden) controller?.abort();
        else { if (!controller) refresh(); }
    });
    window.addEventListener('focus', () => { clearTimeout(timer); refresh(); });
    function pauseForNavigation() {
        leaving = true;
        clearTimeout(timer);
        controller?.abort();
    }
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('.cb-site-navbar a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const target = new URL(link.href, window.location.href);
        if (target.origin === window.location.origin && (target.pathname !== window.location.pathname || target.search !== window.location.search)) pauseForNavigation();
    });
    window.addEventListener('pagehide', pauseForNavigation);
    window.addEventListener('pageshow', event => {
        if (event.persisted) { leaving = false; stopped = false; schedule(); }
    });
    document.addEventListener('submit', event => { if (!event.defaultPrevented && event.target.matches('.cancel-form')) { stopped=true; clearTimeout(timer); controller?.abort(); } });
    filter();
    schedule();
})();
