const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../payment-receipts.js'), 'utf8');

function setup({ rejected = false, otherUser = false, deferred } = {}) {
    const state = { handlers: {}, calls: [], revoked: [] };
    const field = value => ({ value, disabled: false });
    const reference = field('1234567890123');
    const file = { ...field('receipt.png'), required: true, files: [{ type: 'image/png', size: 200 }], name: 'receipt',
        setCustomValidity(value) { this.error = value; }, reportValidity() {} };
    const button = { textContent: 'Submit reference and receipt', disabled: false };
    const preview = { hidden: true };
    const displayedReference = { textContent: 'Not submitted' };
    const paymentStatus = { textContent: 'Unpaid' };
    const proofStatus = {};
    const link = { hidden: true };
    const cancel = { hidden: false };
    const notice = { dataset: {}, hidden: true };
    const section = { querySelector: selector => selector === '.receipt-proof-status' ? proofStatus : link };
    const card = {
        style: {}, getBoundingClientRect: () => ({ height: 1200 }),
        querySelectorAll: selector => selector === '[data-gcash-reference]' ? [displayedReference] : [paymentStatus],
        querySelector: () => cancel
    };
    const form = {
        dataset: { orderId: '101', dirty: 'true' }, elements: [reference, file, button],
        matches: selector => selector === '.gcash-receipt-form',
        getAttribute: () => 'submit_payment.php', setAttribute() {}, removeAttribute() {},
        // Both the form and the card have data-order-id. Select the card explicitly.
        closest: selector => selector === '.order-card[data-order-id]' ? card : selector === '.gcash-payment-proof' ? section : form,
        querySelector(selector) {
            return ({ 'button[type="submit"]': button, '[name="reference_number"]': reference,
                '[name="receipt"]': file, '.receipt-local-preview': preview })[selector];
        }
    };
    file.closest = () => form;
    const document = {
        body: { dataset: { ordersUser: '3' } }, getElementById: () => notice,
        addEventListener(type, handler) { state.handlers[type] = handler; }
    };
    vm.runInNewContext(source, {
        document, AbortController, location: { href: 'http://localhost/campusbite/orders.php' },
        window: { fetch: true, scrollY: 1700, scrollX: 0, addEventListener() {}, scrollTo(value) { state.scroll = value; } },
        URL: { createObjectURL: () => 'blob:receipt', revokeObjectURL: url => state.revoked.push(url) },
        setTimeout: () => 1, clearTimeout() {},
        FormData: class { constructor(value) { this.form = value; } },
        fetch: async (url, options) => {
            state.calls.push({ url, options });
            if (deferred) await deferred;
            return { ok: true, redirected: false, headers: { get: () => 'application/json' },
                json: async () => ({ ok: !rejected, userId: otherUser ? 4 : 3, orderId: 101,
                    referenceNumber: '1234567890123', message: rejected ? 'This reference is already attached to another order.' : 'Receipt submitted.' }) };
        }
    });
    state.submit = () => state.handlers.submit({ target: form, preventDefault() {} });
    return Object.assign(state, { form, file, button, notice, displayedReference, paymentStatus, link, cancel, preview });
}

test('receipt upload updates the whole order card without navigating or marking it paid', async () => {
    const state = setup();
    await state.submit();
    assert.equal(state.calls[0].url, 'submit_payment.php');
    assert.equal(state.calls[0].options.headers['X-CampusBite-Receipt'], '1');
    assert.equal(state.calls[0].options.body.form, state.form);
    assert.equal(state.displayedReference.textContent, '1234567890123');
    assert.equal(state.paymentStatus.textContent, 'Awaiting verification');
    assert.equal(state.link.hidden, false);
    assert.equal(state.cancel.hidden, true);
    assert.equal(state.file.required, false);
    assert.equal(state.file.value, '');
    assert.equal(state.form.dataset.dirty, undefined);
    assert.equal(state.form.dataset.saving, undefined);
    assert.equal(state.scroll.top, 1700);
    assert.equal(state.button.disabled, false);
});

test('rejected references preserve the selected receipt and allow correction', async () => {
    const state = setup({ rejected: true });
    await state.submit();
    assert.equal(state.file.value, 'receipt.png');
    assert.equal(state.form.dataset.dirty, 'true');
    assert.equal(state.link.hidden, true);
    assert.equal(state.cancel.hidden, false);
    assert.match(state.notice.textContent, /another order/);
    assert.equal(state.button.disabled, false);
});

test('rapid repeat submissions send a single upload', async () => {
    let release;
    const state = setup({ deferred: new Promise(resolve => { release = resolve; }) });
    const first = state.submit();
    await state.submit();
    assert.equal(state.calls.length, 1);
    assert.equal(state.form.dataset.saving, 'true');
    release(); await first;
    assert.equal(state.form.dataset.saving, undefined);
});

test('a changed session cannot alter the displayed payment', async () => {
    const state = setup({ otherUser: true });
    await state.submit();
    assert.match(state.notice.textContent, /session changed/);
    assert.equal(state.paymentStatus.textContent, 'Unpaid');
    assert.equal(state.link.hidden, true);
});

test('receipt selection stays dirty for background-refresh protection and rejects oversized images', () => {
    const state = setup();
    state.handlers.change({ target: state.file });
    assert.equal(state.preview.hidden, false);
    assert.equal(state.form.dataset.dirty, 'true');
    state.file.files = [{ type: 'image/png', size: 4 * 1024 * 1024 }];
    state.handlers.change({ target: state.file });
    assert.match(state.file.error, /3 MB/);
    assert.equal(state.preview.hidden, true);
});
