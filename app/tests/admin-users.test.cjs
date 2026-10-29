const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').join(__dirname, '../admin-users.js'), 'utf8');

function setup({ kind = 'toggle', result, deferred } = {}) {
    const state = { calls: [], events: {}, stored: [], scroll: null };
    const classes = () => ({ add() {}, remove() {}, toggle() {} });
    const button = { textContent: kind === 'delete' ? 'Delete' : 'Deactivate', disabled: false, focus() {} };
    const row = {
        dataset: { accountId: '3' }, classList: classes(), isConnected: true,
        contains: () => false, setAttribute() {}, removeAttribute() {},
        replaceWith(value) { state.incoming = value; this.isConnected = false; },
        remove() { state.removed = true; this.isConnected = false; }
    };
    const incoming = { dataset: { accountId: '3' }, classList: classes(), querySelector: () => button };
    const form = {
        action: { toString: () => '[object HTMLInputElement]' },
        dataset: { userName: 'Test Student' },
        fields: { action: kind === 'delete' ? 'delete_user' : 'toggle_user', csrf_token: 'test-token', user_id: '3', is_active: '0' },
        matches: selector => selector.includes(`[data-${kind}-form]`),
        getAttribute: () => null, closest: () => row, querySelector: () => button
    };
    const notice = { classList: classes(), hidden: true };
    const section = { style: {}, querySelectorAll: () => [button], getBoundingClientRect: () => ({ height: 800, top: 100 }) };
    const dialog = {
        addEventListener(type, handler) { state.events[type] = handler; },
        showModal() { state.modal = true; },
        close() { state.modal = false; state.events.close(); }
    };
    const cancel = { addEventListener(type, handler) { state.cancel = handler; } };
    const confirm = { addEventListener(type, handler) { state.confirm = handler; } };
    const count = { dataset: { accountCount: 'Students' }, textContent: '1' };
    const name = {};
    const document = {
        body: { dataset: { adminUsersId: '1' } },
        getElementById(id) { return ({ 'registered-accounts': section, 'account-action-notice': notice, 'delete-confirmation': dialog, 'delete-user-name': name })[id]; },
        querySelector(selector) { return selector === '[data-cancel-delete]' ? cancel : confirm; },
        querySelectorAll(selector) { return selector === '[data-account-count]' ? [count] : []; },
        addEventListener(type, handler) { state.submitHandler = handler; }
    };
    state.result = result || { ok: true, adminId: 1, action: kind === 'delete' ? 'deleted' : 'updated', userId: 3, rowHtml: '<tr></tr>', counts: { Students: kind === 'delete' ? 0 : 1 }, message: 'Saved.' };
    const location = { href: 'http://localhost/campusbite/admin_users.php' };
    vm.runInNewContext(source, {
        document, location, AbortController,
        window: { fetch: true, addEventListener() {}, scrollY: 1800, scrollX: 60, innerHeight: 700,
            matchMedia: () => ({ matches: true }), scrollTo(value) { state.scroll = value; } },
        sessionStorage: { getItem: () => null, removeItem() {}, setItem(...args) { state.stored.push(args); } },
        setTimeout: () => 1, clearTimeout() {},
        FormData: class { constructor(value) { this.fields = value.fields; } },
        DOMParser: class { parseFromString() { return { querySelector: () => incoming }; } },
        fetch: async (url, options) => {
            state.calls.push({ url, options });
            if (deferred) await deferred;
            return { ok: true, redirected: false, headers: { get: () => 'application/json' }, json: async () => state.result };
        }
    });
    state.submit = () => {
        const event = { target: form, preventDefault() { this.defaultPrevented = true; } };
        state.submitHandler(event);
        return event;
    };
    return Object.assign(state, { button, row, notice, count, name });
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('status update posts to the page URL and updates only the row without storing a reload position', async () => {
    const state = setup();
    assert.equal(state.submit().defaultPrevented, true);
    assert.equal(state.button.disabled, true);
    await settle();
    assert.equal(state.calls[0].url, 'http://localhost/campusbite/admin_users.php');
    assert.equal(state.calls[0].options.headers['X-CampusBite-Accounts'], '1');
    assert.equal(state.calls[0].options.body.fields.csrf_token, 'test-token');
    assert.equal(state.calls[0].options.body.fields.is_active, '0');
    assert.ok(state.incoming);
    assert.equal(state.removed, undefined);
    assert.equal(state.scroll.top, 1800);
    assert.equal(state.scroll.left, 60);
    assert.equal(state.stored.length, 0);
    assert.equal(state.notice.textContent, 'Saved.');
    assert.equal(state.button.disabled, false);
});

test('duplicate clicks while a request is pending send one update', async () => {
    let release;
    const state = setup({ deferred: new Promise(resolve => { release = resolve; }) });
    state.submit(); state.submit();
    assert.equal(state.calls.length, 1);
    release();
    await settle();
    assert.equal(state.button.disabled, false);
});

test('canceling deletion sends no request and leaves the account visible', async () => {
    const state = setup({ kind: 'delete' });
    state.submit();
    assert.equal(state.modal, true);
    assert.equal(state.name.textContent, 'Test Student');
    assert.equal(state.calls.length, 0);
    state.cancel(); state.confirm();
    await settle();
    assert.equal(state.calls.length, 0);
    assert.equal(state.removed, undefined);
});

test('confirmed deletion removes the row and updates counts after server success', async () => {
    const state = setup({ kind: 'delete' });
    state.submit(); state.confirm(); state.confirm();
    assert.equal(state.calls.length, 1);
    assert.equal(state.removed, undefined);
    await settle();
    assert.equal(state.removed, true);
    assert.equal(state.count.textContent, '0');
    assert.equal(state.scroll.top, 1800);
    assert.equal(state.modal, false);
});

test('server refusal keeps the row and restores its controls', async () => {
    const state = setup({ kind: 'delete', result: { ok: false, adminId: 1, message: 'This student has order history.' } });
    state.submit(); state.confirm();
    await settle();
    assert.equal(state.removed, undefined);
    assert.equal(state.notice.textContent, 'This student has order history.');
    assert.equal(state.button.disabled, false);
    assert.equal(state.button.textContent, 'Delete');
});

test('a response from a different admin session cannot update the displayed account', async () => {
    const state = setup({ result: { ok: true, adminId: 2 } });
    state.submit(); await settle();
    assert.equal(state.incoming, undefined);
    assert.match(state.notice.textContent, /Could not verify/);
    assert.equal(state.button.disabled, false);
});
