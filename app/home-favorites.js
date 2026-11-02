(() => {
    'use strict';

    // Current-order card on Home: × hides it until that order's status changes.
    // The wrapper is hidden rather than the card, so live updates still see unchanged markup.
    const orderRegion = document.querySelector('[data-dashboard-live="order-card"]');
    const hiddenOrderKey = 'campusbite-hidden-order';
    function applyOrderCard() {
        const card = orderRegion.querySelector('.cb-order-card');
        let hidden = null;
        try { hidden = localStorage.getItem(hiddenOrderKey); } catch (error) { /* storage unavailable: always show */ }
        orderRegion.hidden = Boolean(card && hidden === card.dataset.orderKey);
    }
    if (orderRegion) {
        orderRegion.addEventListener('click', event => {
            const card = event.target.closest('.cb-order-card-close')?.closest('.cb-order-card');
            if (!card) return;
            try { localStorage.setItem(hiddenOrderKey, card.dataset.orderKey); } catch (error) { /* hide for this visit only */ }
            orderRegion.hidden = true;
        });
        new MutationObserver(applyOrderCard).observe(orderRegion, { childList: true });
        applyOrderCard();
    }

    // Heart buttons: save or remove a favourite kitchen without leaving the page.
    // The forms still work as normal POSTs if this script does not run.
    const status = document.getElementById('favorites-status');
    document.addEventListener('submit', async event => {
        const form = event.target.closest?.('.cb-favorite-form');
        if (!form) return;
        event.preventDefault();
        const button = form.querySelector('.cb-heart-button');
        if (button.disabled) return;
        button.disabled = true;
        try {
            const response = await fetch('favorites.php', {
                method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to update favourites.');
            const name = button.dataset.storeName;
            button.setAttribute('aria-pressed', String(result.favorite));
            button.setAttribute('aria-label', (result.favorite ? 'Remove ' + name + ' from' : 'Add ' + name + ' to') + ' favourites');
            form.elements.favorite.value = result.favorite ? '0' : '1';
            // On the Favourites page, an unsaved kitchen leaves the list.
            const item = form.closest('.cb-favorite-item');
            if (item && !result.favorite) {
                item.remove();
                if (status) status.textContent = name + ' removed from favourites.';
                if (!document.querySelector('.cb-favorite-item')) document.querySelector('.cb-favorites-empty')?.removeAttribute('hidden');
            }
        } catch (error) {
            window.alert(error.message || 'Unable to update favourites. Please try again.');
        } finally {
            button.disabled = false;
        }
    });

    // Home search: suggests kitchens and foods from what is on the page, so results stay in step with live updates.
    const input = document.getElementById('home-search');
    const results = document.getElementById('home-search-results');
    if (!input || !results) return;

    function link(href, title, detail) {
        const item = document.createElement('a');
        item.className = 'cb-search-result';
        item.href = href;
        const strong = document.createElement('strong');
        strong.textContent = title;
        const small = document.createElement('span');
        small.textContent = detail;
        item.append(strong, small);
        return item;
    }

    function group(title, items) {
        if (!items.length) return null;
        const section = document.createElement('div');
        section.className = 'cb-search-group';
        const heading = document.createElement('p');
        heading.className = 'cb-search-group-title';
        heading.textContent = title;
        section.append(heading, ...items);
        return section;
    }

    function close() {
        results.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    }

    function search() {
        const query = input.value.trim().toLowerCase();
        if (!query) { close(); return; }
        const kitchens = Array.from(document.querySelectorAll('.cb-kitchen-card[data-kitchen-name]'))
            .filter(card => card.dataset.kitchenName.toLowerCase().includes(query))
            .slice(0, 5)
            .map(card => link(card.dataset.kitchenHref, card.dataset.kitchenName, 'Kitchen'));
        const foods = Array.from(document.querySelectorAll('.food-card[data-food-name]'))
            .filter(card => [card.dataset.foodName, card.dataset.category, card.dataset.storeName].some(value => value.toLowerCase().includes(query)))
            .slice(0, 6)
            .map(card => link('menu.php?store=' + encodeURIComponent(card.dataset.store) + '&q=' + encodeURIComponent(card.dataset.foodName),
                card.dataset.foodName, card.dataset.storeName + ' · ' + card.dataset.category));
        const groups = [group('Kitchens', kitchens), group('Food', foods)].filter(Boolean);
        if (!groups.length) {
            const empty = document.createElement('p');
            empty.className = 'cb-search-empty';
            empty.textContent = 'No kitchens or food match "' + input.value.trim() + '".';
            groups.push(empty);
        }
        results.replaceChildren(...groups);
        results.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    input.addEventListener('input', search);
    input.addEventListener('focus', search);
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { close(); input.blur(); }
        if (event.key === 'ArrowDown') { event.preventDefault(); results.querySelector('a')?.focus(); }
    });
    // Enter opens the first suggestion when there is one; otherwise the form searches the full menu.
    input.form.addEventListener('submit', event => {
        const first = results.hidden ? null : results.querySelector('a');
        if (first) { event.preventDefault(); location.assign(first.href); }
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.cb-home-search-bar')) close();
    });
})();
