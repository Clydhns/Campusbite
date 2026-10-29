(() => {
    const toggle = document.getElementById('accept-gcash');
    const details = document.getElementById('store-gcash-details');
    const number = document.getElementById('gcash-number');
    const summary = document.getElementById('store-payment-summary');
    const upload = document.getElementById('gcash-qr');
    const preview = document.getElementById('gcash-qr-preview');
    const message = document.getElementById('gcash-qr-message');
    const remove = document.getElementById('remove-gcash-qr');
    if (!toggle || !details || !number || !summary) return;

    function update() {
        details.hidden = !toggle.checked;
        number.disabled = !toggle.checked;
        number.required = toggle.checked;
        if (upload) upload.disabled = !toggle.checked;
        if (remove) remove.disabled = !toggle.checked;
        summary.textContent = toggle.checked
            ? 'Save your store to offer both Cash and GCash at checkout.'
            : 'Save your store to accept Cash only.';
    }

    toggle.addEventListener('change', update);
    // Preserve the saved-state message on initial load.
    details.hidden = !toggle.checked;
    number.disabled = !toggle.checked;
    number.required = toggle.checked;
    if (upload) upload.disabled = !toggle.checked;
    if (remove) remove.disabled = !toggle.checked;

    if (!upload || !preview || !message) return;
    const original = preview.getAttribute('src');
    const originalHidden = preview.hidden;
    let previewUrl;
    function updatePreview() {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        upload.setCustomValidity('');
        preview.src = original;
        preview.hidden = originalHidden || Boolean(remove?.checked);
        message.textContent = remove?.checked ? 'The current QR image will be removed when you save your store.' : '';
        const file = upload.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size === 0 || file.size > 3 * 1024 * 1024) {
            message.textContent = 'Choose a JPEG, PNG, or WebP QR image up to 3 MB.';
            upload.setCustomValidity(message.textContent);
            upload.reportValidity();
            return;
        }
        previewUrl = URL.createObjectURL(file);
        preview.src = previewUrl;
        preview.hidden = false;
        message.textContent = 'Preview only. Click Save store to publish this QR image.';
    }
    upload.addEventListener('change', () => {
        if (upload.files[0] && remove) remove.checked = false;
        updatePreview();
    });
    remove?.addEventListener('change', () => {
        if (remove.checked) upload.value = '';
        updatePreview();
    });
    window.addEventListener('pagehide', event => {
        if (!event.persisted && previewUrl) URL.revokeObjectURL(previewUrl);
    });
})();
