(() => {
    const workspace = document.body.dataset.staffWorkspace;
    const notice = document.getElementById('food-availability-notice');
    if (!workspace || !notice) return;
    let noticeTimer;
    function showNotice(message, failed = false) {
        clearTimeout(noticeTimer);
        notice.classList.toggle('error-message', failed);
        notice.classList.toggle('success-message', !failed);
        notice.querySelector('span').textContent = message;
        notice.hidden = false;
        noticeTimer = setTimeout(() => { notice.hidden = true; }, 5000);
    }
    notice.querySelector('button').addEventListener('click', () => {
        clearTimeout(noticeTimer);
        notice.hidden = true;
    });
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('[data-food-availability]') || event.defaultPrevented) return;
        event.preventDefault();
        const button = form.querySelector('button');
        if (button.disabled) return;
        const target = form.querySelector('[name="available"]');
        const requested = target.value === '1';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', credentials: 'same-origin', signal: controller.signal,
                headers: { Accept: 'application/json' }, body: new FormData(form)
            });
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Your session may have changed. Refresh the page before trying again.');
            }
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to change availability.');
            if (result.workspace !== workspace || result.item_id !== Number(form.dataset.itemId)
                || typeof result.available !== 'boolean' || result.available !== requested) {
                throw new Error('The menu changed. Refresh the page before trying again.');
            }
            button.setAttribute('aria-checked', String(result.available));
            form.querySelector('[data-availability-label]').textContent = result.available ? 'Available' : 'Unavailable';
            target.value = result.available ? '0' : '1';
            const editor = document.querySelector('[name="action"][value="save_food"]')?.form;
            if (editor?.querySelector('[name="item_id"]').value === String(result.item_id)) {
                editor.querySelector('[name="is_available"]').checked = result.available;
            }
            showNotice(result.message);
        } catch (error) {
            showNotice(error.name === 'AbortError'
                ? 'The request timed out. Refresh to check the saved availability before trying again.'
                : error.message, true);
        } finally {
            clearTimeout(timeout);
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }
    });
})();
