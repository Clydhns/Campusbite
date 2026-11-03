(() => {
    'use strict';

    // Tap a customer's photo on an order to see it large, to check who is collecting the food.
    // Uses delegation because live order updates replace the order cards.
    const dialog = document.getElementById('customer-photo-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const image = document.getElementById('customer-photo-large');
    const name = document.getElementById('customer-photo-name');

    document.addEventListener('click', event => {
        const opener = event.target.closest('[data-customer-photo]');
        if (opener) {
            image.src = opener.dataset.customerPhoto;
            image.alt = 'Photo of ' + opener.dataset.customerName;
            name.textContent = opener.dataset.customerName;
            dialog.showModal();
            return;
        }
        // Close with the × button or by tapping outside the photo.
        if (event.target.closest('[data-customer-photo-close]') || event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => { image.removeAttribute('src'); });
})();
