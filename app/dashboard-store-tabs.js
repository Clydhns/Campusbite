(() => {
    const stores = document.querySelector('[data-dashboard-live="stores"]');
    const menu = document.querySelector('[data-dashboard-live="menu"]');
    const all = document.getElementById('dashboard-all-stores');
    const status = document.getElementById('dashboard-store-status');
    if (!stores || !menu || !all || !status) return;
    let selected = 0;
    let timer;
    const empty = document.createElement('p');
    empty.className = 'empty-box';
    empty.textContent = 'This kitchen has not added any food to display yet.';
    empty.hidden = true;
    menu.after(empty);
    function apply() {
        const links = Array.from(stores.querySelectorAll('.cb-kitchen-tile'));
        const active = links.find(link => Number(new URL(link.href, location.href).searchParams.get('store')) === selected);
        if (selected && !active) selected = 0;
        links.forEach(link => {
            const isActive = Number(new URL(link.href, location.href).searchParams.get('store')) === selected;
            link.classList.toggle('is-selected', isActive);
            if (isActive) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
        let visible = 0;
        menu.querySelectorAll('.food-card').forEach(card => {
            card.hidden = selected !== 0 && Number(card.dataset.store) !== selected;
            if (!card.hidden) visible++;
        });
        all.setAttribute('aria-pressed', String(selected === 0));
        const name = active?.querySelector('h3')?.textContent.trim();
        status.textContent = name ? 'Showing today\'s picks from ' + name + '.' : 'Showing today\'s picks from all kitchens.';
        empty.hidden = selected === 0 || visible > 0;
        const fullMenu = menu.querySelector('.section-heading a');
        if (fullMenu) fullMenu.href = selected ? 'menu.php?store=' + selected : 'menu.php';
    }
    function choose(store) {
        const top = window.scrollY;
        selected = store;
        clearTimeout(timer);
        menu.style.minHeight = menu.getBoundingClientRect().height + 'px';
        apply();
        menu.classList.remove('is-switching-in');
        void menu.offsetWidth;
        menu.classList.add('is-switching-in');
        window.scrollTo(0, top);
        timer = setTimeout(() => { menu.style.minHeight = ''; menu.classList.remove('is-switching-in'); }, 240);
    }
    stores.addEventListener('click', event => {
        const link = event.target.closest('.cb-kitchen-tile');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        choose(Number(new URL(link.href, location.href).searchParams.get('store')));
    });
    all.addEventListener('click', () => choose(0));
    const observer = new MutationObserver(apply);
    observer.observe(stores, { childList:true });
    observer.observe(menu, { childList:true });
    apply();
})();
