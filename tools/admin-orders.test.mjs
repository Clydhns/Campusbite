import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import vm from 'node:vm';

const script = await readFile(new URL('../app/admin-orders.js', import.meta.url), 'utf8');
function setup({ fail = false, reduced = false } = {}) {
    const listeners = {}, announcements = { textContent: '' }, calls = [];
    let panel;
    const makePanel = () => ({
        attributes: {}, classList: { add: name => calls.push(name) },
        setAttribute(key, value) { this.attributes[key] = value; },
        removeAttribute(key) { delete this.attributes[key]; },
        getBoundingClientRect: () => ({ top: 120 }),
        replaceWith(next) { panel = next; },
        scrollIntoView: options => calls.push(['scroll', options]),
        querySelector: () => ({ focus: options => calls.push(['focus', options]) }),
    });
    panel = makePanel();
    const document = {
        getElementById: id => id === 'admin-orders' ? panel : announcements,
        addEventListener: (name, handler) => { listeners[name] = handler; },
    };
    const location = new URL('https://example.test/admin_dashboard.php');
    const context = {
        document, URL, AbortController, setTimeout, clearTimeout,
        window: { location, matchMedia: () => ({ matches: reduced }),
            scrollBy: options => calls.push(['preserve', options]),
            addEventListener: (name, handler) => { listeners[name] = handler; } },
        history: { pushState: (_, __, href) => { calls.push(['history', href]); location.href = href; } },
        fetch: async () => { if (fail) throw new Error('offline'); return { ok: true, redirected: false, text: async () => '<panel>' }; },
        DOMParser: class { parseFromString() { return { getElementById: () => makePanel() }; } },
    };
    vm.runInNewContext(script, context);
    const click = async (inside = true, extra = {}) => {
        let prevented = false;
        const link = { href: 'https://example.test/admin_dashboard.php?orders=all#admin-orders',
            hasAttribute: () => false, closest: () => inside ? panel : null };
        listeners.click({ button: 0, target: { closest: () => link },
            preventDefault: () => { prevented = true; }, ...extra });
        await new Promise(resolve => setImmediate(resolve));
        return prevented;
    };
    return { click, calls, announcements, listeners, panel: () => panel };
}

test('Order links swap only the panel, update history and preserve scroll and keyboard focus', async () => {
    const app = setup();
    const original = app.panel();
    assert.equal(await app.click(), true);
    assert.notEqual(app.panel(), original);
    assert.equal(app.announcements.textContent, 'Orders updated.');
    assert.ok(app.calls.some(call => call[0] === 'history'));
    assert.ok(app.calls.some(call => call[0] === 'preserve'));
    assert.ok(app.calls.some(call => call[0] === 'focus' && call[1].preventScroll));
    app.listeners.popstate();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(app.calls.filter(call => call[0] === 'history').length, 1);
});

test('Quick actions scroll smoothly, respecting reduced motion', async () => {
    for (const reduced of [false, true]) {
        const app = setup({ reduced });
        await app.click(false);
        assert.equal(app.calls.find(call => call[0] === 'scroll')[1].behavior, reduced ? 'instant' : 'smooth');
    }
});

test('Network failures retain the existing orders and clear the busy state', async () => {
    const app = setup({ fail: true });
    const original = app.panel();
    await app.click();
    assert.equal(app.panel(), original);
    assert.equal(original.attributes['aria-busy'], undefined);
    assert.match(app.announcements.textContent, /try again/);
    assert.equal(app.calls.length, 0);
});

test('Modified clicks retain normal open-in-new-tab behavior', async () => {
    const app = setup();
    assert.equal(await app.click(true, { ctrlKey: true }), false);
    assert.equal(app.calls.length, 0);
});
