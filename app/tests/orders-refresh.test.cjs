const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../orders-refresh.js'), 'utf8');

function fixture(pending = false) {
    const state = { code:200, dirty:false, replaced:false, restored:false, parsed:0 };
    const events = {}, windowEvents = {}, timers = new Map();
    const status = { textContent: 'Connected', dataset: {} };
    const empty = { hidden: false };
    const summary={matches:()=>false,closest:selector=>selector==='[data-order-details]'?{dataset:{orderDetails:'7'}}:null};
    const details={dataset:{orderDetails:'7'},querySelector:()=>({focus(){state.restored=true;}}),querySelectorAll:()=>[]};
    const list = { innerHTML: '', style:{}, getBoundingClientRect:()=>({height:400}),
        contains:element=>element===summary,
        querySelector:selector=>selector.startsWith('[data-order-details=')?details:(state.dirty?{}:null),
        querySelectorAll:selector=>selector==='details[open]'||selector==='[data-order-details]'?[details]:[],
        replaceChildren(){state.replaced=true;this.innerHTML='Payment confirmed';} };
    const document = { hidden: false, body: { dataset: { ordersUser: '1',ordersVersion:'a'.repeat(64) } },
        getElementById: id => ({'orders-live-list':list,'orders-filter-empty':empty,'orders-sync-status':status,'payment-confirmation':state.dialog})[id] || null,
        querySelectorAll: () => [], addEventListener(name, fn) { events[name] = fn; } };
    let timerId = 0, calls = 0, signal;
    vm.runInNewContext(source, { document, URL, AbortController,
        window: { location: new URL('https://example.invalid/orders.php'),scrollY:200,scrollTo(){}, addEventListener(name, fn) { windowEvents[name] = fn; } },
        requestAnimationFrame:fn=>fn(),
        setTimeout(fn, ms) { timers.set(++timerId, { fn, ms }); return timerId; }, clearTimeout(id) { timers.delete(id); },
        DOMParser: class { parseFromString() { state.parsed++;return { body: { dataset: { ordersUser: '1',ordersVersion:'b'.repeat(64) } }, getElementById: () => state.incoming||list }; } },
        fetch(_url, options) {
            calls++; signal = options.signal;
            if (pending) return new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Stopped', 'AbortError'))));
            return Promise.resolve({ ok:true,status:state.code, redirected:false, text:async () => '' });
        }
    });
    return Object.assign(state,{ events, windowEvents, document, status, timers, summary,details, calls:()=>calls, signal:()=>signal,
        poll() { const entry = [...timers].find(([,value]) => value.ms===1500); assert.ok(entry); timers.delete(entry[0]); return entry[1].fn(); } });
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
test('a payment confirmation updates focused read-only order details and preserves focus',async()=>{
    const page=fixture();page.incoming={innerHTML:'Payment confirmed',childNodes:[]};page.document.activeElement=page.summary;
    await page.poll();assert.equal(page.replaced,true);assert.equal(page.restored,true);assert.equal(page.details.open,true);
    assert.equal(page.document.body.dataset.ordersVersion,'b'.repeat(64));
});
test('receipt edits and payment dialogs are not replaced by payment updates',async()=>{
    const page=fixture();page.dirty=true;await page.poll();assert.equal(page.calls(),0);
    page.dirty=false;page.dialog={open:true};await page.poll();assert.equal(page.calls(),0);
    page.dialog.open=false;page.incoming={innerHTML:'Payment confirmed',childNodes:[]};await page.poll();assert.equal(page.replaced,true);
});
test('an unchanged order response skips parsing and DOM replacement',async()=>{
    const page=fixture();page.code=204;await page.poll();assert.equal(page.parsed,0);assert.equal(page.replaced,false);
});
