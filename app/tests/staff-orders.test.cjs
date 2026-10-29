const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');

const source = readFileSync(require('node:path').join(__dirname, '../staff-orders.js'), 'utf8');

function setup({ action = null, status = 200, sessionUser = '7', redirected = false } = {}) {
    const notice = { dataset: {}, hidden: true };
    const button = { textContent: 'Mark Completed', disabled: false };
    const card = {
        dataset: { staffOrder: '42' },
        contains: () => false,
        setAttribute() {}, removeAttribute() {},
        getBoundingClientRect: () => ({ height: 450 }),
        replaceWith(incoming) { state.incoming = incoming; }
    };
    const form = {
        // Reproduce the browser's named form property: this is an input, not a URL.
        action: { toString: () => '[object HTMLInputElement]' },
        getAttribute: name => name === 'action' ? action : null,
        matches: selector => selector === '[data-staff-order-action]',
        closest: () => card,
        querySelector: () => button
    };
    const stats = { replaceChildren(...nodes) { state.stats = nodes; } };
    const incoming = { style: {}, contains: () => false };
    const document = {
        body: { dataset: { staffOrdersUser: '7', staffOrdersStore: '3' } },
        getElementById: id => id === 'staff-order-notice' ? notice : stats,
        addEventListener(type, handler) { state.submit = handler; },
        querySelectorAll: () => [button],
        dispatchEvent(event) { state.tracking = event.detail; }
    };
    const page = {
        body: { dataset: { staffOrdersUser: sessionUser, staffOrdersStore: '3' } },
        querySelector(selector) {
            if (selector === '[data-staff-result]') return { textContent: 'Order is now Completed.', dataset: { staffResult: 'success' } };
            if (selector.startsWith('[data-staff-order=')) return incoming;
            if (selector.startsWith('[data-cb-tracking]')) return { textContent: '[{"status":"Completed"}]' };
        },
        getElementById: () => ({ childNodes: ['updated-counts'] })
    };
    const state = { notice, button, form };
    const window = {
        location: { href: 'http://localhost/campusbite/staff_dashboard.php' },
        fetch: true, FormData: true, scrollY: 1300, scrollX: 0,
        scrollTo(position) { state.scroll = position; }
    };
    vm.runInNewContext(source, {
        document, window, AbortController, setTimeout, clearTimeout,
        FormData: class { constructor(value) { this.form = value; } },
        CustomEvent: class { constructor(type, options) { this.detail = options.detail; } },
        DOMParser: class { parseFromString() { state.parsed = true; return page; } },
        fetch: async (url, options) => {
            state.request = { url, options };
            return { ok: status === 200, redirected, text: async () => '<html></html>' };
        }
    });
    state.run = () => state.submit({ target: form, submitter: button, preventDefault() {} });
    return state;
}

test('hidden action input does not replace the request URL; completion updates in place', async () => {
    const state = setup();
    await state.run();
    assert.equal(state.request.url, 'http://localhost/campusbite/staff_dashboard.php');
    assert.equal(state.request.options.method, 'POST');
    assert.equal(state.request.options.body.form, state.form);
    assert.equal(state.request.options.credentials, 'same-origin');
    assert.ok(state.incoming);
    assert.equal(state.stats[0], 'updated-counts');
    assert.equal(state.tracking[0].status, 'Completed');
    assert.equal(state.scroll.top, 1300);
    assert.equal(state.notice.textContent, 'Order is now Completed.');
    assert.equal(state.notice.dataset.state, 'success');
    assert.equal(state.button.disabled, false);
});

test('explicit form action attribute is respected', async () => {
    const state = setup({ action: 'staff_dashboard.php' });
    await state.run();
    assert.equal(state.request.url, 'staff_dashboard.php');
});

test('HTTP failures do not report a session change', async () => {
    const state = setup({ status: 404 });
    await state.run();
    assert.match(state.notice.textContent, /Could not verify the update/);
    assert.equal(state.parsed, undefined);
    assert.equal(state.incoming, undefined);
    assert.equal(state.notice.dataset.state, 'error');
    assert.equal(state.button.disabled, false);
    assert.equal(state.button.textContent, 'Mark Completed');
});

test('a successful response for a different session still blocks UI updates', async () => {
    const state = setup({ sessionUser: '8' });
    await state.run();
    assert.match(state.notice.textContent, /Your session changed/);
    assert.equal(state.incoming, undefined);
    assert.equal(state.notice.dataset.state, 'error');
});
