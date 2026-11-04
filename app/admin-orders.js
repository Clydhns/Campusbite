(() => {
    let pending = null;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const status = () => document.getElementById('admin-orders-status');

    async function loadOrders(url, push, fromPanel) {
        if (pending) pending.abort();
        const controller = new AbortController();
        pending = controller;
        const panel = document.getElementById('admin-orders');
        if (!panel) return;
        panel.setAttribute('aria-busy', 'true');
        status().textContent = 'Loading orders...';
        const timeout = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(url.href, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (!response.ok || response.redirected) throw new Error('Order history unavailable');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = page.getElementById('admin-orders');
            if (!next) throw new Error('Order history missing');
            if (pending !== controller) return;
            // Measure immediately before swapping so scrolling during the request is preserved.
            const previousTop = panel.getBoundingClientRect().top;
            panel.replaceWith(next);
            next.classList.add('is-updated');
            if (push) history.pushState(null, '', url.href);
            if (fromPanel) {
                window.scrollBy({ top: next.getBoundingClientRect().top - previousTop, behavior: 'instant' });
            } else {
                next.scrollIntoView({ block: 'start', behavior: reducedMotion.matches ? 'instant' : 'smooth' });
            }
            next.querySelector('h2').focus({ preventScroll: true });
            status().textContent = 'Orders updated.';
        } catch (error) {
            if (pending !== controller) return;
            panel.removeAttribute('aria-busy');
            status().textContent = 'Unable to update orders. Please try again.';
        } finally {
            clearTimeout(timeout);
            if (pending === controller) pending = null;
        }
    }

    document.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.hash !== '#admin-orders') return;
        event.preventDefault();
        loadOrders(url, true, Boolean(link.closest('#admin-orders')));
    });
    window.addEventListener('popstate', () => {
        loadOrders(new URL(window.location.href), false, true);
    });
})();
