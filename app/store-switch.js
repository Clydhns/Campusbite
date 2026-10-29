(() => {
    const results = document.getElementById('store-menu-results');
    const links = Array.from(document.querySelectorAll('.cb-store-section .cb-store-tile'));
    const status = document.getElementById('store-switch-status');
    const login = document.getElementById('store-switch-login');
    const user = document.body.dataset.menuUser;
    if (!results || !links.length || !status || !user) return;
    let currentStore = Number(results.dataset.store);
    let controller;
    let sequence = 0;
    let animationTimer;

    function showSelection(store) {
        links.forEach(link => {
            const id = Number(new URL(link.href, location.href).searchParams.get('store'));
            link.classList.toggle('is-selected', id === store);
            if (id === store) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
    }

    function storeName(store) {
        const link = links.find(link => Number(new URL(link.href, location.href).searchParams.get('store')) === store);
        return link ? link.dataset.storeName : 'this kitchen';
    }

    async function switchStore(store, mode = 'push') {
        const request = ++sequence;
        if (controller) controller.abort();
        controller = new AbortController();
        const requestController = controller;
        const timeout = setTimeout(() => requestController.abort(), 12000);
        const url = new URL('menu.php', location.href);
        if (store) url.searchParams.set('store', String(store));
        clearTimeout(animationTimer);
        results.style.minHeight = results.getBoundingClientRect().height + 'px';
        results.setAttribute('aria-busy', 'true');
        status.hidden = false;
        status.classList.remove('is-error');
        status.textContent = 'Loading this kitchen...';
        try {
            const response = await fetch(url, { credentials:'same-origin', cache:'no-store', signal:requestController.signal });
            if (!response.ok) throw new Error('Unable to load this store.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (request !== sequence) return;
            if (response.redirected || page.body.dataset.menuUser !== user) {
                if (login) login.hidden = false;
                throw new Error('Your login session changed. Please sign in again.');
            }
            if (page.body.dataset.menuError) throw new Error('This kitchen could not be loaded. Please try again.');
            const incoming = page.getElementById('store-menu-results');
            if (!incoming || Number(incoming.dataset.store) !== store) throw new Error('Incomplete store response. Please try again.');
            const top = window.scrollY;
            results.replaceChildren(...Array.from(incoming.childNodes));
            results.dataset.store = String(store);
            currentStore = store;
            if (login) login.hidden = true;
            showSelection(store);
            // Leave search text and category controls in place and reapply their filters.
            document.dispatchEvent(new Event('campusbite:store-menu-updated'));
            if (mode === 'push' && location.href !== url.href) history.pushState({}, '', url);
            window.scrollTo(0, top);
            results.classList.remove('is-switching-in');
            void results.offsetWidth;
            results.classList.add('is-switching-in');
            status.textContent = store ? 'Showing ' + storeName(store) + '.' : 'Showing all kitchens.';
            animationTimer = setTimeout(() => {
                results.style.minHeight = '';
                results.classList.remove('is-switching-in');
            }, 240);
        } catch (error) {
            if (request !== sequence) return;
            showSelection(currentStore);
            status.classList.add('is-error');
            status.textContent = error.name === 'AbortError' ? 'The store took too long to respond. Please try again.' : error.message;
            results.style.minHeight = '';
        } finally {
            clearTimeout(timeout);
            if (request === sequence) results.setAttribute('aria-busy', 'false');
        }
    }

    links.forEach(link => {
        link.addEventListener('click', event => {
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href, location.href);
            if (url.origin !== location.origin || !url.pathname.endsWith('/menu.php')) return;
            event.preventDefault();
            switchStore(Number(url.searchParams.get('store') || 0));
        });
    });
    window.addEventListener('popstate', () => switchStore(Number(new URL(location.href).searchParams.get('store') || 0), 'pop'));
    showSelection(currentStore);
})();
