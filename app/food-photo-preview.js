(() => {
    const input = document.getElementById('food-photo');
    const preview = document.getElementById('food-photo-preview');
    const message = document.getElementById('food-photo-message');
    if (!input || !preview || !message) return;
    const original = preview.getAttribute('src');
    const originalHidden = preview.hidden;
    let url;
    input.addEventListener('change', () => {
        if (url) URL.revokeObjectURL(url);
        input.setCustomValidity('');
        message.textContent = '';
        const file = input.files[0];
        preview.src = original;
        preview.hidden = originalHidden;
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 3 * 1024 * 1024) {
            message.textContent = 'Choose a JPEG, PNG, or WebP photo up to 3 MB.';
            input.setCustomValidity(message.textContent);
            input.reportValidity();
            return;
        }
        url = URL.createObjectURL(file);
        preview.src = url;
        preview.hidden = false;
        const action = input.form.querySelector('button[type="submit"]').textContent.trim();
        message.textContent = 'Preview only. Click ' + action + ' to publish this photo.';
    });
    window.addEventListener('pagehide', () => { if (url) URL.revokeObjectURL(url); });
})();
