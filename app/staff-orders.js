(() => {
    const user = document.body.dataset.staffOrdersUser;
    const store = document.body.dataset.staffOrdersStore;
    const notice = document.getElementById('staff-order-notice');
    if (!user || !store || !notice || !window.fetch || !window.FormData) return;

    let saving = false;
    let noticeTimer;

    function showNotice(message, error = false) {
        clearTimeout(noticeTimer);
        notice.dataset.state = error ? 'error' : 'success';
        notice.textContent = message;
        notice.hidden = false;
        if (!error) noticeTimer = setTimeout(() => { notice.hidden = true; }, 6000);
    }

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-staff-order-action]') || event.defaultPrevented) return;
        event.preventDefault();
        if (saving) return;

        const card = form.closest('[data-staff-order]');
        const selector = `[data-staff-order="${card.dataset.staffOrder}"]`;
        const button = event.submitter || form.querySelector('button[type="submit"]');
        const label = button.textContent;
        const data = new FormData(form);
        const buttons = Array.from(document.querySelectorAll('[data-staff-order-action] button'));
        const disabled = buttons.map(item => item.disabled);
        const hadFocus = card.contains(document.activeElement);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        saving = true;
        document.body.dataset.staffOrdersSaving = 'true';
        document.dispatchEvent(new CustomEvent('campusbite:staff-order-save', {detail:{saving:true}}));
        card.setAttribute('aria-busy', 'true');
        buttons.forEach(item => { item.disabled = true; });
        button.textContent = 'Saving…';
        notice.hidden = true;

        try {
            // Reuse the server's validation, CSRF protection and rendered order state.
            // A hidden input named "action" shadows the form.action property.
            const response = await fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.ok) {
                throw new Error('Could not verify the update. Refresh the page to check the order before trying again.');
            }
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (response.redirected || page.body.dataset.staffOrdersUser !== user ||
                page.body.dataset.staffOrdersStore !== store) {
                throw new Error('Your session changed. Refresh the page and sign in again before updating orders.');
            }
            const result = page.querySelector('[data-staff-result]');
            const incoming = page.querySelector(selector);
            const stats = page.getElementById('staff-order-stats');
            if (!result || !incoming || !stats) {
                throw new Error('Could not verify the update. Refresh the page to check the order before trying again.');
            }

            // Capture position after the request, so scrolling while saving is respected.
            const top = window.scrollY;
            const left = window.scrollX;
            incoming.style.minHeight = `${card.getBoundingClientRect().height}px`;
            card.replaceWith(incoming);
            document.getElementById('staff-order-stats').replaceChildren(...stats.childNodes);
            const payload = page.querySelector('[data-cb-tracking] script[type="application/json"]');
            if (payload) {
                document.dispatchEvent(new CustomEvent('campusbite:tracking-update', {
                    detail: JSON.parse(payload.textContent)
                }));
            }
            if (hadFocus && (document.activeElement === document.body || incoming.contains(document.activeElement))) {
                (incoming.querySelector('button[type="submit"]') || incoming).focus({ preventScroll: true });
            }
            window.scrollTo({ top, left, behavior: 'instant' });
            showNotice(result.textContent.trim(), result.dataset.staffResult === 'error');
        } catch (error) {
            showNotice(error instanceof TypeError || error.name === 'AbortError'
                ? 'Connection interrupted. Refresh the page to check the order before trying again.'
                : error.message, true);
        } finally {
            clearTimeout(timeout);
            saving = false;
            delete document.body.dataset.staffOrdersSaving;
            card.removeAttribute('aria-busy');
            button.textContent = label;
            buttons.forEach((item, index) => { item.disabled = disabled[index]; });
            document.dispatchEvent(new CustomEvent('campusbite:staff-order-save', {detail:{saving:false}}));
        }
    });
})();
