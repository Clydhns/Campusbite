<?php if ($order['payment_status'] === 'Unpaid'
    && (($order['payment_method'] === 'Cash' && in_array((int) $order['status_id'], [1,2,3], true))
        || ($order['payment_method'] === 'GCash' && (int) $order['status_id'] === 1))): ?>
    <form method="POST" data-staff-order-action data-payment-confirm="verify"
        data-payment-method="<?= cbEscape($order['payment_method']) ?>"
        data-payment-store="<?= cbEscape($store['store_name']) ?>"
        data-payment-order="<?= cbEscape($order['order_number']) ?>"
        data-payment-reference="<?= cbEscape(cbRealGcashReference($order['reference_number']) ? $order['reference_number'] : '') ?>"
        data-payment-amount="<?= number_format((float) $order['total_amount'], 2, '.', '') ?>">
        <input type="hidden" name="csrf_token" value="<?= cbEscape($staffToken) ?>">
        <input type="hidden" name="review_token" value="<?= cbReceiptReviewToken($order) ?>">
        <input type="hidden" name="action" value="confirm_payment">
        <input type="hidden" name="order_id" value="<?= (int) $order_id ?>">
        <button type="submit" class="action-button pay-button"><?= $order['payment_method'] === 'GCash' ? 'Confirm GCash Payment' : 'Confirm Cash Payment' ?></button>
    </form>
<?php endif; ?>
