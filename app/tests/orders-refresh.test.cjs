const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../orders-refresh.js'), 'utf8');

function fixture(pending = false) {
    const events = {}, windowEvents = {}, timers = new Map();
    const status = { textContent: 'Connected', dataset: {} };
    const empty = { hidden: false };
    const list = { innerHTML: '', contains: () => false, querySelector: () => null, querySelectorAll: () => [] };
    const document = { hidden: false, body: { dataset: { ordersUser: '1' } },
        getElementById: id => ({'orders-live-list':list,'orders-filter-empty':empty,'orders-sync-status':status})[id] || null,
        querySelectorAll: () => [], addEventListener(name, fn) { events[name] = fn; } };
    let timerId = 0, calls = 0, signal;
    vm.runInNewContext(source, { document, URL, AbortController,
        window: { location: new URL('https://example.invalid/orders.php'), addEventListener(name, fn) { windowEvents[name] = fn; } },
        setTimeout(fn, ms) { timers.set(++timerId, { fn, ms }); return timerId; }, clearTimeout(id) { timers.delete(id); },
        DOMParser: class { parseFromString() { return { body: { dataset: { ordersUser: '1' } }, getElementById: () => list }; } },
        fetch(_url, options) {
            calls++; signal = options.signal;
            if (pending) return new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Stopped', 'AbortError'))));
            return Promise.resolve({ ok:true, redirected:false, text:async () => '' });
        }
    });
    return { events, windowEvents, document, status, timers, calls:()=>calls, signal:()=>signal,
        poll() { const entry = [...timers].find(([,value]) => value.ms===4000); assert.ok(entry); timers.delete(entry[0]); return entry[1].fn(); } };
}

test('orders use their initial HTML without an immediate duplicate request', async () => {
    const page = fixture(); assert.equal(page.calls(),0);
    await page.poll(); assert.equal(page.calls(),1);
    assert.equal(page.status.textContent,'Live order updates connected');
});

test('navbar navigation aborts order polling and prevents another refresh', async () => {
    const page = fixture(true); const pending = page.poll();
    const link = {href:'https://example.invalid/menu.php',target:'',hasAttribute:()=>false};
    page.events.click({button:0,target:{closest:()=>link}}); await pending;
    assert.equal(page.signal().aborted,true);
    assert.equal(page.timers.size,0);
    assert.equal(page.status.textContent,'Connected');
    page.windowEvents.focus(); assert.equal(page.calls(),1);
});

test('opening a navbar link in another tab does not pause the current orders page', () => {
    const page = fixture(); page.events.click({button:0,ctrlKey:true});
    assert.equal(page.timers.size,1);
});

test('orders resume polling after back-forward cache restoration', () => {
    const page = fixture(); page.windowEvents.pagehide(); assert.equal(page.timers.size,0);
    page.windowEvents.pageshow({persisted:true}); assert.equal(page.timers.size,1);
});

test('hiding orders aborts background work without showing an error', async () => {
    const page = fixture(true); const pending = page.poll();
    page.document.hidden = true; page.events.visibilitychange(); await pending;
    assert.equal(page.signal().aborted,true); assert.equal(page.timers.size,0);
    assert.equal(page.status.textContent,'Connected');
});

test('a cancelled form confirmation does not stop live order updates', () => {
    const page = fixture(); page.events.submit({defaultPrevented:true,target:{matches:()=>true}});
    assert.equal(page.timers.size,1);
});
