(() => {
    const results = document.getElementById('admin-store-results');
    const filter = document.querySelector('.cb-store-filter');
    const select = document.getElementById('store-filter');
    const status = document.getElementById('admin-store-status');
    const user = document.body.dataset.adminStoresUser;
    if (!results || !filter || !select || !status || !user || !window.fetch) return;

    const links = Array.from(document.querySelectorAll('[data-admin-store]'));
    let currentStore = Number(results.dataset.store);
    let controller;
    let sequence = 0;
    let animationTimer;
    const previousRestoration = history.scrollRestoration;
    history.scrollRestoration = 'manual';

    function showSelection(store) {
        select.value = String(store);
        links.forEach(link => {
            if (Number(link.dataset.adminStore) === store) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
    }

    function showStatus(message, error = false) {
        status.dataset.state = error ? 'error' : 'ready';
        status.textContent = message;
    }

    async function switchStore(store, mode = 'push') {
        const request = ++sequence;
        controller?.abort();
        const pending = new AbortController();
        controller = pending;
        const timeout = setTimeout(() => pending.abort(), 15000);
        const url = new URL(location.href);
        url.searchParams.set('store', String(store));
        clearTimeout(animationTimer);
        results.classList.remove('is-updated');
        results.style.minHeight = `${results.getBoundingClientRect().height}px`;
        results.setAttribute('aria-busy', 'true');
        showStatus('Loading store…');

        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: pending.signal });
            if (!response.ok) throw new Error('Unable to load this store. Please try again.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (request !== sequence) return;
            if (response.redirected || page.body.dataset.adminStoresUser !== user) {
                throw new Error('Your session changed. Refresh the page and sign in again.');
            }
            const incoming = page.getElementById('admin-store-results');
            if (!incoming || Number(incoming.dataset.store) !== store) {
                throw new Error('The store response was incomplete. Please try again.');
            }
            // Keep the position reached while waiting, including on shorter store menus.
            const top = window.scrollY;
            const left = window.scrollX;
            const resultsTop = results.getBoundingClientRect().top + top;
            results.replaceChildren(...incoming.childNodes);
            results.dataset.store = String(store);
            results.style.minHeight = `${Math.max(0, top + window.innerHeight - resultsTop)}px`;
            currentStore = store;
            showSelection(store);
            if (mode === 'push' && location.href !== url.href) history.pushState({}, '', url);
            else if (mode === 'pop') history.replaceState({}, '', url);
            window.scrollTo({ top, left, behavior: 'instant' });
            showStatus(store ? `Showing ${select.selectedOptions[0].textContent.trim()}.` : 'Showing all stores.');
            results.classList.add('is-updated');
            animationTimer = setTimeout(() => results.classList.remove('is-updated'), 200);
        } catch (error) {
            if (request !== sequence) return;
            showSelection(currentStore);
            // A failed Back/Forward request should leave the URL matching the visible store.
            if (mode === 'pop') {
                const current = new URL(location.href);
                current.searchParams.set('store', String(currentStore));
                history.replaceState({}, '', current);
            }
            showStatus(error.name === 'AbortError' ? 'The store took too long to respond. Please try again.' : error.message, true);
        } finally {
            clearTimeout(timeout);
            if (request === sequence) {
                controller = null;
                results.setAttribute('aria-busy', 'false');
            }
        }
    }

    links.forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        switchStore(Number(link.dataset.adminStore));
    }));
    filter.addEventListener('submit', event => {
        event.preventDefault();
        switchStore(Number(select.value));
    });
    select.addEventListener('change', () => switchStore(Number(select.value)));
    window.addEventListener('popstate', () => switchStore(Number(new URL(location.href).searchParams.get('store') || 0), 'pop'));
    window.addEventListener('pagehide', () => {
        sequence++;
        controller?.abort();
        clearTimeout(animationTimer);
        history.scrollRestoration = previousRestoration;
    });
    window.addEventListener('pageshow', () => { history.scrollRestoration = 'manual'; });
    showSelection(currentStore);
})();
