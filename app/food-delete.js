(() => {
    const dialog = document.getElementById('food-delete-dialog');
    const name = document.getElementById('food-delete-name');
    const confirm = document.getElementById('food-delete-confirm');
    const cancel = document.getElementById('food-delete-cancel');
    if (!dialog || !name || !confirm || !cancel) return;
    let pendingForm = null;
    document.querySelectorAll('[data-delete-food]').forEach(form => {
        form.querySelector('[data-delete-food-button]').disabled = false;
        form.addEventListener('submit', event => {
            if (form.dataset.confirmed === 'true') {
                form.querySelector('[data-delete-food-button]').disabled = true;
                return;
            }
            event.preventDefault();
            form.dataset.returnScroll = String(window.scrollY);
            pendingForm = form;
            name.textContent = form.dataset.foodName;
            dialog.showModal();
            cancel.focus();
        });
    });
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { pendingForm = null; });
    confirm.addEventListener('click', () => {
        if (!pendingForm) return;
        const form = pendingForm;
        pendingForm = null;
        confirm.disabled = true;
        form.dataset.confirmed = 'true';
        form.requestSubmit();
    });
})();
