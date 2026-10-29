<?php if ($order['payment_method'] === 'GCash'):
    $proofOrderId = (int) $order['order_id'];
    $hasReceipt = (bool) cbReceiptPath($order['receipt_path'] ?? null);
    $actualReference = cbRealGcashReference($order['reference_number']) ? $order['reference_number'] : '';
    $canSubmitProof = (int) $order['status_id'] === 1 && $order['payment_status'] === 'Unpaid';
?>
<section class="gcash-payment-proof" id="payment-proof-<?= $proofOrderId ?>" aria-labelledby="payment-proof-title-<?= $proofOrderId ?>">
    <h3 id="payment-proof-title-<?= $proofOrderId ?>">GCash payment</h3>
    <p class="receipt-proof-status" role="status"><?= !empty($order['payment_rejected_at']) ? 'Payment rejected. This order has been cancelled.' : ((int) $order['status_id'] === 5 ? 'This order has been cancelled.' : ($order['payment_status'] === 'Paid' ? 'Payment confirmed by staff.' : ($hasReceipt ? 'Receipt submitted. Awaiting staff verification.' : 'Submit your actual payment reference and receipt after paying this store.'))) ?></p>
    <?php if (!empty($order['payment_rejected_at'])): ?><p><strong>Rejection reason:</strong> <?= cbEscape($order['payment_rejection_reason']) ?></p><?php endif; ?>
    <p>Reference: <strong data-gcash-reference><?= cbEscape($actualReference ?: 'Not submitted') ?></strong></p>
    <a class="receipt-view-link" href="payment_receipt.php?order_id=<?= $proofOrderId ?>" target="_blank" rel="noopener" <?= $hasReceipt ? '' : 'hidden' ?>>View submitted receipt</a>
    <?php if ($canSubmitProof): ?>
        <details class="gcash-pay-details" data-order-details="pay-<?= $proofOrderId ?>">
            <summary>Store's GCash number and QR</summary>
            <p>Pay <strong>PHP <?= number_format((float) $order['total_amount'], 2) ?></strong> to <?= cbEscape($order['store_name']) ?>.</p>
            <p>Receiving number: <strong><?= cbEscape($order['gcash_number'] ?: 'Contact the store for payment details.') ?></strong></p>
            <?php if (!empty($order['gcash_number']) && ($qr = cbPaymentQrUrl($order['gcash_qr_path'] ?? null))): ?>
                <a href="<?= cbEscape($qr) ?>" target="_blank" rel="noopener"><img class="receipt-store-qr" src="<?= cbEscape($qr) ?>" alt="<?= cbEscape($order['store_name']) ?> GCash QR" width="240" height="240" loading="lazy"></a>
                <a href="<?= cbEscape($qr) ?>" download>Save QR image</a>
            <?php endif; ?>
            <p>Check the receiving account before paying. Staff confirms payments manually.</p>
        </details>
        <form class="gcash-receipt-form" action="submit_payment.php" method="POST" enctype="multipart/form-data" data-order-id="<?= $proofOrderId ?>" data-payment-confirm="receipt" data-payment-store="<?= cbEscape($order['store_name']) ?>" data-payment-order="<?= cbEscape($order['order_number']) ?>" data-payment-amount="<?= number_format((float) $order['total_amount'], 2, '.', '') ?>" data-payment-number="<?= cbEscape($order['gcash_number'] ?? '') ?>">
            <input type="hidden" name="order_id" value="<?= $proofOrderId ?>">
            <input type="hidden" name="csrf_token" value="<?= cbEscape($ordersCsrf) ?>">
            <label for="payment-reference-<?= $proofOrderId ?>">Reference number from your GCash receipt</label>
            <input id="payment-reference-<?= $proofOrderId ?>" name="reference_number" type="text" inputmode="numeric" pattern="[0-9]{6,30}" minlength="6" maxlength="30" required value="<?= cbEscape($actualReference) ?>" autocomplete="off">
            <label for="payment-receipt-<?= $proofOrderId ?>">GCash receipt image</label>
            <input id="payment-receipt-<?= $proofOrderId ?>" name="receipt" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="receipt-help-<?= $proofOrderId ?>" <?= $hasReceipt ? '' : 'required' ?>>
            <p id="receipt-help-<?= $proofOrderId ?>">Upload a clear JPG, PNG, or WebP image up to 3 MB showing the reference, receiving account, and amount. <?= $hasReceipt ? 'Leave the image blank to keep your submitted receipt.' : '' ?></p>
            <img class="receipt-local-preview" alt="Selected receipt preview" width="240" height="300" hidden>
            <button type="submit"><?= $hasReceipt ? 'Update payment details' : 'Submit reference and receipt' ?></button>
        </form>
    <?php endif; ?>
</section>
<?php endif; ?>
