(() => {
    const scrollStorageKey = 'admin-users-scroll-position';
    try {
        const saved = sessionStorage.getItem(scrollStorageKey);
        sessionStorage.removeItem(scrollStorageKey);
        if (saved !== null && Number.isFinite(Number(saved))) {
            window.addEventListener('load', () => window.scrollTo(0, Number(saved)), { once: true });
        }
    } catch (_) { /* Native forms also work when browser storage is unavailable. */ }

    document.querySelectorAll('.message-notice').forEach(message => {
        setTimeout(() => message.remove(), 5400);
    });
    document.querySelectorAll('[data-password-toggle]').forEach(button => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} ${input.id === 'staff-password' ? 'password' : 'confirm password'}`);
        });
    });

    const section = document.getElementById('registered-accounts');
    const notice = document.getElementById('account-action-notice');
    const dialog = document.getElementById('delete-confirmation');
    const adminId = document.body.dataset.adminUsersId;
    let pendingDeleteForm = null;
    let saving = false;
    let noticeTimer;

    function showNotice(message, error = false) {
        clearTimeout(noticeTimer);
        notice.textContent = message;
        notice.classList.toggle('success', !error);
        notice.classList.toggle('error', error);
        notice.hidden = false;
        if (!error) noticeTimer = setTimeout(() => { notice.hidden = true; }, 6000);
    }

    async function saveAccount(form) {
        if (saving) return;
        saving = true;
        const row = form.closest('[data-account-id]');
        const data = new FormData(form);
        const button = form.querySelector('button[type="submit"]');
        const originalLabel = button.textContent;
        const buttons = Array.from(section.querySelectorAll('button[type="submit"]'));
        const disabled = buttons.map(item => item.disabled);
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        const hadFocus = row.contains(document.activeElement);
        let nextFocus;
        row.classList.add('account-pending');
        row.setAttribute('aria-busy', 'true');
        buttons.forEach(item => { item.disabled = true; });
        button.textContent = form.matches('[data-delete-form]') ? 'Deleting…' : 'Saving…';
        notice.hidden = true;

        try {
            const response = await fetch(form.getAttribute('action') || location.href, {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-CampusBite-Accounts': '1', 'Accept': 'application/json' },
                signal: controller.signal
            });
            if (response.redirected) throw new Error('Your session changed. Refresh the page and sign in again.');
            if (!(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Could not verify the update. Refresh the account list before trying again.');
            }
            const result = await response.json();
            if (result.sessionChanged) throw new Error(result.message);
            if (!response.ok || String(result.adminId) !== adminId) {
                throw new Error('Could not verify the update. Refresh the account list before trying again.');
            }
            if (!result.ok) throw new Error(result.message || 'This account could not be changed.');
            if (String(result.userId) !== row.dataset.accountId ||
                !['updated', 'deleted'].includes(result.action)) {
                throw new Error('The account response was incomplete. Refresh the account list.');
            }
            let incoming;
            if (result.action === 'updated') {
                const page = new DOMParser().parseFromString(`<table><tbody>${result.rowHtml}</tbody></table>`, 'text/html');
                incoming = page.querySelector('[data-account-id]');
                if (!incoming || incoming.dataset.accountId !== row.dataset.accountId) {
                    throw new Error('The account response was incomplete. Refresh the account list.');
                }
            } else if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches && row.animate) {
                // Only fade after the server has confirmed deletion.
                await row.animate([{ opacity: .55 }, { opacity: 0 }], { duration: 140, fill: 'forwards' }).finished.catch(() => {});
            }

            const top = window.scrollY;
            const left = window.scrollX;
            const sectionTop = section.getBoundingClientRect().top + top;
            section.style.minHeight = `${section.getBoundingClientRect().height}px`;
            if (incoming) {
                row.replaceWith(incoming);
                incoming.classList.add('account-updated');
                nextFocus = incoming.querySelector('button[type="submit"]');
            } else {
                nextFocus = (row.nextElementSibling || row.previousElementSibling)?.querySelector('button[type="submit"]');
                row.remove();
            }
            document.querySelectorAll('[data-account-count]').forEach(count => {
                const value = result.counts?.[count.dataset.accountCount];
                if (Number.isInteger(value) && value >= 0) count.textContent = String(value);
            });
            // Reserve only enough height to keep the current viewport after a row disappears.
            section.style.minHeight = `${Math.max(0, top + window.innerHeight - sectionTop)}px`;
            window.scrollTo({ top, left, behavior: 'instant' });
            showNotice(result.message);
        } catch (error) {
            showNotice(error.name === 'AbortError' || error instanceof TypeError
                ? 'Connection interrupted. Refresh the account list to check the result before trying again.'
                : error.message, true);
        } finally {
            clearTimeout(timeout);
            saving = false;
            row.classList.remove('account-pending');
            row.removeAttribute('aria-busy');
            button.textContent = originalLabel;
            buttons.forEach((item, index) => { item.disabled = disabled[index]; });
            if (hadFocus && (document.activeElement === document.body || row.contains(document.activeElement))) {
                (nextFocus || (row.isConnected ? button : section.querySelector('.table-wrapper')))?.focus({ preventScroll: true });
            }
        }
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (event.defaultPrevented) return;
        if (form.matches('[data-toggle-form], [data-delete-form]') && window.fetch) {
            event.preventDefault();
            if (saving) return;
            if (form.matches('[data-delete-form]')) {
                pendingDeleteForm = form;
                document.getElementById('delete-user-name').textContent = form.dataset.userName;
                dialog.showModal();
            } else saveAccount(form);
            return;
        }
        // Create-staff and administrator settings retain their existing native form flow.
        if (form.method.toLowerCase() === 'post') {
            try { sessionStorage.setItem(scrollStorageKey, String(window.scrollY)); } catch (_) {}
        }
    });
    document.querySelector('[data-cancel-delete]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pendingDeleteForm = null; });
    document.querySelector('[data-confirm-delete]').addEventListener('click', () => {
        if (!pendingDeleteForm || saving) return;
        const form = pendingDeleteForm;
        pendingDeleteForm = null;
        dialog.close();
        saveAccount(form);
    });
})();
