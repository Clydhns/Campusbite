const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../food-availability.js'), 'utf8');

function fixture({ result, ok = true, pending = false, redirected = false } = {}) {
    const state = { calls: 0, attributes: { 'aria-checked': 'true' }, timers: new Map(), events: {} };
    const input = { value: '0' }, label = { textContent: 'Available' }, text = { textContent: '' };
    const button = { disabled: false, setAttribute(name, value) { state.attributes[name] = value; }, removeAttribute(name) { delete state.attributes[name]; } };
    const notice = { hidden: true, classList: { toggle() {} }, querySelector(selector) {
        return selector === 'span' ? text : { addEventListener(_name, fn) { state.dismiss = fn; } };
    } };
    const form = { dataset: { itemId: '10' }, matches: () => true, getAttribute: () => 'staff_menu.php', querySelector(selector) {
        return selector === 'button' ? button : selector === '[name="available"]' ? input : label;
    } };
    const document = { body: { dataset: { staffWorkspace: '2-1' } }, getElementById: () => notice,
        querySelector: () => null, addEventListener(name, fn) { state.events[name] = fn; } };
    let timerId = 0;
    const response = { ok, redirected, headers: { get: () => 'application/json' }, json: async () => result || {
        ok: true, workspace: '2-1', item_id: 10, available: false, message: 'Meal is now unavailable.'
    } };
    vm.runInNewContext(source, { document, AbortController, FormData: class {},
        setTimeout(fn, ms) { state.timers.set(++timerId, { fn, ms }); return timerId; }, clearTimeout(id) { state.timers.delete(id); },
        fetch(_url, options) {
            state.calls++; state.options = options;
            return pending ? new Promise(resolve => { state.resolve = () => resolve(response); }) : Promise.resolve(response);
        }
    });
    state.submit = () => state.events.submit({ target: form, preventDefault() {} });
    return Object.assign(state, { button, input, label, notice, text });
}

test('quick availability saves in place and reflects only a confirmed server state', async () => {
    const page = fixture(); await page.submit();
    assert.equal(page.calls, 1);
    assert.equal(page.options.method, 'POST');
    assert.equal(page.attributes['aria-checked'], 'false');
    assert.equal(page.input.value, '1');
    assert.equal(page.label.textContent, 'Unavailable');
    assert.equal(page.button.disabled, false);
    assert.equal(page.notice.hidden, false);
    assert.match(page.text.textContent, /now unavailable/);
});

test('repeated clicks send one request and never optimistically flip the switch', async () => {
    const page = fixture({ pending: true });
    const first = page.submit(); await page.submit();
    assert.equal(page.calls, 1);
    assert.equal(page.attributes['aria-checked'], 'true');
    page.resolve(); await first;
    assert.equal(page.attributes['aria-checked'], 'false');
});

test('server failures retain the old state and display the error', async () => {
    const page = fixture({ ok: false, result: { ok: false, message: 'Food belongs to another store.' } });
    await page.submit();
    assert.equal(page.attributes['aria-checked'], 'true');
    assert.equal(page.input.value, '0');
    assert.equal(page.text.textContent, 'Food belongs to another store.');
    assert.equal(page.button.disabled, false);
});

test('a changed session or mismatched item cannot update the current switch', async () => {
    for (const changed of [{ workspace: '8-2' }, { item_id: 11 }, { available: true }]) {
        const page = fixture({ result: { ok: true, workspace: '2-1', item_id: 10, available: false, ...changed } });
        await page.submit();
        assert.equal(page.attributes['aria-checked'], 'true');
        assert.match(page.text.textContent, /Refresh the page/);
    }
});

test('expired sessions are not mistaken for a successful availability change', async () => {
    const page = fixture({ redirected: true }); await page.submit();
    assert.equal(page.attributes['aria-checked'], 'true');
    assert.match(page.text.textContent, /session may have changed/);
});

test('confirmation disappears after five seconds and supports manual dismissal', async () => {
    const page = fixture(); await page.submit();
    const timer = [...page.timers.values()].find(entry => entry.ms === 5000);
    assert.ok(timer); timer.fn(); assert.equal(page.notice.hidden, true);
    await page.submit(); page.dismiss(); assert.equal(page.notice.hidden, true);
    assert.equal(page.timers.size, 0);
});
