<dialog id="payment-confirmation" class="payment-confirmation" aria-labelledby="payment-confirmation-title" aria-describedby="payment-confirmation-message">
    <h2 id="payment-confirmation-title">Confirm payment details</h2>
    <p id="payment-confirmation-message"></p>
    <dl class="payment-confirmation-details">
        <?php foreach (['store' => 'Store', 'order' => 'Order', 'method' => 'Payment method', 'amount' => 'Amount', 'number' => 'Receiving number', 'reference' => 'Reference', 'receipt' => 'Receipt'] as $key => $label): ?>
            <div data-payment-review-row="<?= $key ?>"><dt><?= $label ?></dt><dd data-payment-review="<?= $key ?>"></dd></div>
        <?php endforeach; ?>
    </dl>
    <div id="payment-rejection-field" hidden>
        <label for="payment-rejection-reason">Why is this payment being rejected?</label>
        <textarea id="payment-rejection-reason" maxlength="200" rows="3" placeholder="Explain the verification issue for the customer."></textarea>
    </div>
    <div class="payment-confirmation-actions">
        <button type="button" data-payment-confirm-cancel>Go back</button>
        <button type="button" data-payment-confirm-proceed>Confirm</button>
    </div>
</dialog>
