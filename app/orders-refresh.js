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

    function showStatus(message, state) {
        if (status.textContent !== message) status.textContent = message;
        if (status.dataset.state !== state) status.dataset.state = state;
    }
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
        if (!stopped && !leaving && !document.hidden) timer = setTimeout(refresh, Math.min(30000, 1500 * (failures + 1)));
    }
    async function refresh() {
        if (stopped || leaving || document.hidden || controller) return;
        const modal = document.getElementById('cancelConfirmation');
        const editingReceipt = () => list.querySelector('.gcash-receipt-form[data-dirty="true"], .gcash-receipt-form[data-saving="true"], .gcash-receipt-form[data-confirming="true"]');
        const interacting = () => editingReceipt() || (modal && !modal.hidden)
            || document.getElementById('payment-confirmation')?.open
            || (list.contains(document.activeElement) && document.activeElement?.matches('input,textarea,select'));
        if (interacting()) { schedule(); return; }
        controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const url = 'orders.php?sync=1&version=' + encodeURIComponent(document.body.dataset.ordersVersion || '');
            const response = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (!response.ok) throw new Error('Order refresh failed');
            if (leaving || document.hidden) return;
            if (response.status === 204) {
                failures = 0; showStatus('Live order updates connected', 'connected'); return;
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (leaving || document.hidden) return;
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
            // Preserve receipt edits and payment/cancellation dialogs, but allow a focused read-only summary to update.
            if (interacting()) return;
            if (incoming.innerHTML !== snapshot) {
                const opened = new Set(Array.from(list.querySelectorAll('details[open]')).map(details => details.dataset.orderDetails));
                const focused = document.activeElement;
                const detailsId = list.contains(focused) ? focused?.closest?.('[data-order-details]')?.dataset.orderDetails : null;
                const focusedHref = detailsId ? focused?.closest?.('a[href]')?.href : null;
                const top = window.scrollY;
                list.style.minHeight = list.getBoundingClientRect().height + 'px';
                snapshot = incoming.innerHTML;
                list.replaceChildren(...Array.from(incoming.childNodes));
                list.querySelectorAll('[data-order-details]').forEach(details => { details.open = opened.has(details.dataset.orderDetails); });
                if (detailsId && /^\d+$/.test(detailsId)) {
                    const replacement = list.querySelector(`[data-order-details="${detailsId}"]`);
                    const link = Array.from(replacement?.querySelectorAll('a[href]') || []).find(link => link.href === focusedHref);
                    (link || replacement?.querySelector('summary'))?.focus({preventScroll:true});
                }
                filter();
                window.scrollTo(0, top);
                requestAnimationFrame(() => { list.style.minHeight=''; });
                showStatus('Orders updated just now', 'connected');
            } else showStatus('Live order updates connected', 'connected');
            document.body.dataset.ordersVersion = page.body.dataset.ordersVersion || '';
            failures = 0;
        } catch (error) {
            if (!leaving && !document.hidden) {
                failures++;
                showStatus('Updates paused. Retrying automatically.', 'error');
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
