(() => {
    const content = document.getElementById('cart-content');
    const user = document.body.dataset.cartUser;
    if (!content || !user) return;
    let pending = false;
    let noticeTimer;

    function showNotice() {
        clearTimeout(noticeTimer);
        const notice = content.querySelector('[data-cart-notice]');
        if (!notice) return;
        notice.focus({preventScroll:true});
        // Errors remain visible until the next action; only confirmations fade.
        if (notice.dataset.noticeType === 'success') {
            noticeTimer = setTimeout(() => {
                notice.classList.add('is-leaving');
                setTimeout(() => notice.remove(), 220);
            }, 5000);
        }
    }

    content.addEventListener('click', event => {
        const button = event.target.closest('[data-quantity-step]');
        if (!button || pending) return;
        const input = button.closest('form').querySelector('[name=quantity]');
        const value = Number(input.value) || 1;
        input.value = String(Math.max(Number(input.min), Math.min(Number(input.max), value + Number(button.dataset.quantityStep))));
        input.dispatchEvent(new Event('input', {bubbles:true}));
    });

    content.addEventListener('submit', async event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        event.preventDefault();
        if (pending) return;
        pending = true;
        const data = new FormData(form);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        content.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch('cart.php', {method:'POST', body:data, credentials:'same-origin', cache:'no-store', signal:controller.signal});
            if (!response.ok) throw new Error('Unable to load your cart.');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (response.redirected || page.body.dataset.cartUser !== user) {
                location.assign('login.php');
                return;
            }
            const incoming = page.getElementById('cart-content');
            if (!incoming) throw new Error('Incomplete cart response.');
            const top = window.scrollY;
            const height = content.getBoundingClientRect().height;
            content.style.minHeight = height + 'px';
            content.replaceChildren(...Array.from(incoming.childNodes));
            document.body.dataset.cartCount = page.body.dataset.cartCount;
            const oldCartLink = document.querySelector('.cb-site-nav-pill a[href="cart.php"]');
            const newCartLink = page.querySelector('.cb-site-nav-pill a[href="cart.php"]');
            if (oldCartLink && newCartLink) oldCartLink.replaceChildren(...Array.from(newCartLink.childNodes));
            window.scrollTo(0, top);
            showNotice();
            requestAnimationFrame(() => { content.style.minHeight = ''; });
        } catch (error) {
            content.querySelector('[data-cart-notice]')?.remove();
            const notice = document.createElement('div');
            notice.className = 'error';
            notice.dataset.cartNotice = '';
            notice.dataset.noticeType = 'error';
            notice.tabIndex = -1;
            notice.setAttribute('role', 'alert');
            notice.textContent = 'We could not confirm the update. Reload your cart to check its latest state before trying again.';
            const reload = document.createElement('a');
            reload.href = 'cart.php';
            reload.textContent = ' Reload cart';
            notice.append(reload);
            content.prepend(notice);
            showNotice();
        } finally {
            clearTimeout(timeout);
            pending = false;
            content.setAttribute('aria-busy', 'false');
        }
    });
    showNotice();
})();
