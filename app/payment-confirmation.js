(() => {
    const dialog = document.getElementById('payment-confirmation');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    const title = document.getElementById('payment-confirmation-title');
    const message = document.getElementById('payment-confirmation-message');
    const reasonField = document.getElementById('payment-rejection-field');
    const reason = document.getElementById('payment-rejection-reason');
    const cancel = dialog.querySelector('[data-payment-confirm-cancel]');
    const proceed = dialog.querySelector('[data-payment-confirm-proceed]');
    const approved = new WeakSet();
    let pending;

    function review(key, value) {
        dialog.querySelector(`[data-payment-review-row="${key}"]`).hidden = !value;
        dialog.querySelector(`[data-payment-review="${key}"]`).textContent = value || '';
    }
    function clearPending() {
        if (pending) delete pending.form.dataset.confirming;
        pending = null;
    }
    // Capture the submit before the existing checkout/upload/staff action handlers.
    document.addEventListener('submit', event => {
        const form = event.target;
        if (!form.matches('[data-payment-confirm]') || event.defaultPrevented) return;
        if (approved.has(form)) { approved.delete(form); return; }
        event.preventDefault();
        if (dialog.open) return;
        const type = form.dataset.paymentConfirm;
        pending = { form, submitter: event.submitter, type };
        form.dataset.confirming = 'true';
        const method = form.querySelector('[name="payment_method"]:checked')?.value || form.dataset.paymentMethod || 'GCash';
        const reference = form.querySelector('[name="reference_number"]')?.value || form.dataset.paymentReference;
        review('store', form.dataset.paymentStore);
        review('order', form.dataset.paymentOrder);
        review('method', method);
        review('amount', form.dataset.paymentAmount ? `PHP ${form.dataset.paymentAmount}` : '');
        review('number', method === 'GCash' ? form.dataset.paymentNumber : '');
        review('reference', reference);
        review('receipt', type === 'receipt' ? (form.querySelector('[name="receipt"]').files[0]?.name || 'Previously submitted receipt') : '');
        reasonField.hidden = type !== 'reject';
        reason.required = type === 'reject';
        reason.value = '';
        reason.setCustomValidity('');
        if (type === 'checkout') {
            title.textContent = `Continue with ${method}?`;
            message.textContent = method === 'GCash'
                ? 'This saves a pending order. Pay this store using GCash, then submit your actual reference and receipt in My Orders for staff verification.'
                : 'This saves a pending order. Pay at the canteen so staff can confirm your payment and prepare your food.';
            proceed.textContent = 'Confirm order';
        } else if (type === 'receipt') {
            title.textContent = 'Submit payment for verification?';
            message.textContent = 'Check that the reference and receipt match this order and receiving account. Staff will verify the payment before preparing your food.';
            proceed.textContent = 'Submit for verification';
        } else if (type === 'reject') {
            title.textContent = 'Reject payment and cancel order?';
            message.textContent = 'The order will be cancelled. The customer will see your reason. Any refund must be handled separately with the customer.';
            proceed.textContent = 'Reject and cancel order';
        } else {
            title.textContent = `Confirm ${method} payment received?`;
            message.textContent = method === 'GCash'
                ? 'Confirm only after matching the incoming payment in your GCash account with this order, amount, and reference.'
                : 'Confirm that the customer has paid the full amount for this order at the canteen.';
            proceed.textContent = 'Confirm payment received';
        }
        dialog.showModal();
        (type === 'reject' ? reason : cancel).focus({ preventScroll: true });
    }, true);
    cancel.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', clearPending);
    reason.addEventListener('input', () => reason.setCustomValidity(''));
    proceed.addEventListener('click', () => {
        if (!pending) return;
        const { form, submitter, type } = pending;
        if (!form.isConnected || !form.reportValidity()) { dialog.close(); return; }
        if (type === 'reject') {
            if (!reason.value.trim()) reason.setCustomValidity('Enter a rejection reason for the customer.');
            if (!reason.reportValidity()) return;
            form.querySelector('[name="reject_reason"]').value = reason.value.trim();
        }
        clearPending();
        dialog.close();
        approved.add(form);
        try { form.requestSubmit(submitter?.isConnected ? submitter : undefined); }
        finally { approved.delete(form); }
    });
})();
