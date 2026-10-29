(() => {
    const user = document.body.dataset.ordersUser;
    const notice = document.getElementById('payment-proof-notice');
    if (!user || !notice || !window.fetch) return;
    const previews = new Map();
    let saving = false;
    let noticeTimer;

    function showNotice(message, error = false) {
        clearTimeout(noticeTimer);
        notice.dataset.state = error ? 'error' : 'success';
        notice.textContent = message;
        notice.hidden = false;
        if (!error) noticeTimer = setTimeout(() => { notice.hidden = true; }, 6500);
    }

    function clearPreview(form) {
        if (previews.has(form)) URL.revokeObjectURL(previews.get(form));
        previews.delete(form);
        form.querySelector('.receipt-local-preview').hidden = true;
    }

    document.addEventListener('input', event => {
        const form = event.target.closest('.gcash-receipt-form');
        if (form) form.dataset.dirty = 'true';
    });
    document.addEventListener('change', event => {
        const input = event.target;
        const form = input.closest('.gcash-receipt-form');
        if (!form) return;
        form.dataset.dirty = 'true';
        if (input.name !== 'receipt') return;
        clearPreview(form);
        input.setCustomValidity('');
        const file = input.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size === 0 || file.size > 3 * 1024 * 1024) {
            input.setCustomValidity('Choose a JPG, PNG, or WebP receipt image up to 3 MB.');
            input.reportValidity();
            return;
        }
        const url = URL.createObjectURL(file);
        previews.set(form, url);
        const preview = form.querySelector('.receipt-local-preview');
        preview.src = url;
        preview.hidden = false;
    });

    document.addEventListener('submit', async event => {
        const form = event.target;
        if (!form.matches('.gcash-receipt-form') || event.defaultPrevented) return;
        event.preventDefault();
        if (saving) return;
        saving = true;
        form.dataset.saving = 'true';
        const data = new FormData(form);
        const fields = Array.from(form.elements);
        const disabled = fields.map(field => field.disabled);
        const button = form.querySelector('button[type="submit"]');
        const label = button.textContent;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        fields.forEach(field => { field.disabled = true; });
        form.setAttribute('aria-busy', 'true');
        button.textContent = 'Uploading receipt…';
        notice.hidden = true;
        let submitted = false;
        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-CampusBite-Receipt': '1', 'Accept': 'application/json' }, signal: controller.signal
            });
            if (response.redirected || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Could not verify the upload. Refresh My Orders to check the result before trying again.');
            }
            const result = await response.json();
            if (String(result.userId) !== user || String(result.orderId) !== form.dataset.orderId) {
                throw new Error('Your session changed. Refresh My Orders before submitting a receipt.');
            }
            if (!response.ok || !result.ok) throw new Error(result.message || 'The receipt could not be submitted.');
            const top = window.scrollY;
            const left = window.scrollX;
            const card = form.closest('.order-card[data-order-id]');
            card.style.minHeight = `${card.getBoundingClientRect().height}px`;
            card.querySelectorAll('[data-gcash-reference]').forEach(element => { element.textContent = result.referenceNumber; });
            const section = form.closest('.gcash-payment-proof');
            section.querySelector('.receipt-proof-status').textContent = 'Receipt submitted. Awaiting staff verification.';
            const link = section.querySelector('.receipt-view-link');
            link.href = `payment_receipt.php?order_id=${encodeURIComponent(form.dataset.orderId)}`;
            link.hidden = false;
            card.querySelectorAll('[data-payment-status]').forEach(element => { element.textContent = 'Awaiting verification'; });
            const cancel = card.querySelector('.cancel-form');
            if (cancel) cancel.hidden = true;
            form.querySelector('[name="reference_number"]').value = result.referenceNumber;
            const file = form.querySelector('[name="receipt"]');
            file.value = '';
            file.required = false;
            clearPreview(form);
            delete form.dataset.dirty;
            window.scrollTo({ top, left, behavior: 'instant' });
            submitted = true;
            showNotice(result.message);
        } catch (error) {
            showNotice(error instanceof TypeError || error.name === 'AbortError'
                ? 'Connection interrupted. Check My Orders before resubmitting your payment receipt.' : error.message, true);
        } finally {
            clearTimeout(timeout);
            saving = false;
            delete form.dataset.saving;
            form.removeAttribute('aria-busy');
            fields.forEach((field, index) => { field.disabled = disabled[index]; });
            button.textContent = submitted ? 'Update payment details' : label;
        }
    });
    window.addEventListener('pagehide', event => {
        if (!event.persisted) {
            previews.forEach(url => URL.revokeObjectURL(url));
            previews.clear();
        }
    });
})();
