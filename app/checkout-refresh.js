(() => {
    const card = document.getElementById('checkout-live-card');
    const status = document.getElementById('checkout-sync-status');
    const button = document.getElementById('checkout-sync-refresh');
    const user = document.body.dataset.checkoutUser;
    if (!card || !status || !button || !user || !/^\d+$/.test(card.dataset.orderId)) return;
    const order = card.dataset.orderId;
    const url = new URL('checkout.php', window.location.href);
    url.searchParams.set('success', '1');
    url.searchParams.set('order', order);
    url.searchParams.set('sync', '1');
    let snapshot = card.innerHTML;
    let timer, controller;
    let failures = 0, stopped = false, leaving = false;

    function showStatus(message, state) {
        if (status.textContent !== message) status.textContent = message;
        if (status.dataset.state !== state) status.dataset.state = state;
    }
    function schedule() {
        clearTimeout(timer);
        if (stopped || leaving || document.hidden) return;
        const interval = ['4', '5'].includes(card.dataset.orderStatus) ? 15000 : 1000;
        timer = setTimeout(refresh, Math.min(30000, interval * (failures + 1)));
    }
    function stopForSession() {
        stopped = true;
        card.replaceChildren();
        button.hidden = true;
        status.dataset.state = 'error';
        status.textContent = 'This order is no longer available in this session. ';
        const link = document.createElement('a');
        link.href = 'orders.php';
        link.textContent = 'Open My Orders';
        status.append(link);
    }
    async function refresh(manual = false) {
        if (stopped || leaving || document.hidden || controller) return;
        clearTimeout(timer);
        const request = new AbortController();
        controller = request;
        if (manual === true) button.disabled = true;
        const timeout = setTimeout(() => request.abort(), 10000);
        try {
            url.searchParams.set('version', card.dataset.orderVersion || '');
            const response = await fetch(url.href, {credentials:'same-origin', cache:'no-store', signal:request.signal});
            if (!response.ok) throw new Error('Order update unavailable');
            if (leaving || document.hidden) return;
            if (response.status === 204) {
                showStatus('Live order updates connected.', 'connected');
                failures = 0;
                return;
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (leaving || document.hidden) return;
            if (response.redirected || page.body.dataset.checkoutUser !== user) { stopForSession(); return; }
            const incoming = page.getElementById('checkout-live-card');
            if (!incoming) throw new Error('Incomplete order update');
            if (incoming.dataset.orderId !== order) { stopForSession(); return; }
            if (!/^[1-5]$/.test(incoming.dataset.orderStatus)) throw new Error('Invalid order status');
            if (incoming.innerHTML !== snapshot) {
                const focused = document.activeElement?.closest?.('[data-checkout-action]');
                const action = focused && card.contains(focused) ? focused.dataset.checkoutAction : null;
                const top = window.scrollY;
                snapshot = incoming.innerHTML;
                card.replaceChildren(...Array.from(incoming.childNodes));
                card.dataset.paymentState = incoming.dataset.paymentState;
                if (action && ['cart', 'orders', 'menu'].includes(action)) {
                    card.querySelector(`[data-checkout-action="${action}"]`)?.focus({preventScroll:true});
                }
                window.scrollTo(0, top);
                showStatus('Order updated just now.', 'connected');
            } else showStatus('Live order updates connected.', 'connected');
            card.dataset.orderStatus = incoming.dataset.orderStatus;
            card.dataset.orderVersion = incoming.dataset.orderVersion || '';
            failures = 0;
        } catch (error) {
            if (!leaving && !document.hidden) {
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
    function pause() {
        leaving = true;
        clearTimeout(timer);
        controller?.abort();
    }
    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const target = new URL(link.href, window.location.href);
        if (target.origin === window.location.origin && (target.pathname !== window.location.pathname || target.search !== window.location.search)) pause();
    });
    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (document.hidden) controller?.abort();
        else refresh();
    });
    window.addEventListener('pagehide', pause);
    window.addEventListener('pageshow', event => {
        if (event.persisted) { leaving = false; return refresh(); }
    });
    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
    button.addEventListener('click', () => refresh(true));
    schedule();
})();
