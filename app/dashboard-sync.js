(() => {
    'use strict';

    const userId = document.body.dataset.dashboardUser;
    const status = document.getElementById('dashboard-sync-status');
    const checkedAt = document.getElementById('dashboard-sync-time');
    const refreshButton = document.getElementById('dashboard-sync-refresh');
    const loginLink = document.getElementById('dashboard-sync-login');
    if (!userId || !status) return;
    const panel = document.getElementById('dashboard-sync-panel');
    const label = document.getElementById('dashboard-sync-label');
    function showSyncState(state) {
        if (panel) {
            panel.dataset.syncState = state;
            if (state === 'error') panel.open = true;
        }
        if (label) label.textContent = state === 'connected' ? 'Live menu' : 'Updates paused';
    }

    let timer;
    let busy = false;
    let failures = 0;
    const pageUrl = new URL('dashboard.php', window.location.href);

    function schedule() {
        clearTimeout(timer);
        if (!document.hidden) {
            timer = setTimeout(refresh, Math.min(3000 * (failures + 1), 30000));
        }
    }

    async function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        if (refreshButton) refreshButton.disabled = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(pageUrl, {
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) throw new Error('Unable to load the dashboard.');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (response.redirected || next.body.dataset.dashboardUser !== userId) {
                failures = 9;
                showSyncState('error');
                if (loginLink) loginLink.hidden = false;
                status.textContent = 'Live updates paused: this browser is no longer signed in to this student account. Use a separate browser profile or Incognito window for staff.';
                return;
            }

            const regions = Array.from(document.querySelectorAll('[data-dashboard-live]'));
            const updates = regions.map(current => ({
                current,
                incoming: next.querySelector(`[data-dashboard-live="${current.dataset.dashboardLive}"]`)
            }));
            if (updates.some(update => !update.incoming)) {
                throw new Error('Incomplete dashboard response.');
            }
            let changed = false;
            updates.forEach(({ current, incoming }) => {
                // Keep keyboard focus intact; update this region on the next poll instead.
                if (current.contains(document.activeElement)) return;
                if (current.innerHTML !== incoming.innerHTML) {
                    current.replaceChildren(...Array.from(incoming.childNodes));
                    changed = true;
                }
            });
            if (loginLink) loginLink.hidden = true;
            showSyncState('connected');
            if (changed) status.textContent = 'Store menus updated just now.';
            else status.textContent = 'Live updates connected. No new store changes.';
            if (checkedAt) checkedAt.textContent = 'Last checked: ' + new Date().toLocaleTimeString();
            failures = 0;
        } catch (error) {
            failures += 1;
            showSyncState('error');
            status.textContent = 'Live updates are temporarily unavailable. Retrying automatically.';
        } finally {
            clearTimeout(timeout);
            busy = false;
            if (refreshButton) refreshButton.disabled = false;
            schedule();
        }
    }

    document.addEventListener('visibilitychange', () => {
        clearTimeout(timer);
        if (!document.hidden) refresh();
    });
    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
    if (refreshButton) refreshButton.addEventListener('click', refresh);
    refresh();
})();
