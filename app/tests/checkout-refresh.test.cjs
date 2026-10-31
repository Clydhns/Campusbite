const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../checkout-refresh.js'), 'utf8');

function fixture(options = {}) {
    const events = {}, windowEvents = {}, timers = new Map();
    const state = { calls:0, restores:0, scrolls:[], url:null, request:null, responseUser:'1', redirected:false, fail:false };
    const action = {dataset:{checkoutAction:'orders'}, focus(options) {state.restores++; assert.equal(options.preventScroll,true);}};
    const card = {innerHTML:'pending', dataset:{orderId:'12',orderStatus:'1',paymentState:'unpaid'},
        contains:node=>node===action, querySelector:()=>action,
        replaceChildren(...nodes) {this.innerHTML=nodes.map(node=>node.html).join('');} };
    state.incoming = {innerHTML:'pending', dataset:{...card.dataset}, childNodes:[{html:'pending'}]};
    const status = {dataset:{}, textContent:'Waiting', append(node) {this.link=node;}};
    const button = {disabled:false, hidden:false, addEventListener(name,fn) {state.click=fn;}};
    const document = {hidden:false, body:{dataset:{checkoutUser:'1'}}, activeElement:null,
        getElementById:id=>({'checkout-live-card':card,'checkout-sync-status':status,'checkout-sync-refresh':button})[id],
        createElement:()=>({}), addEventListener(name,fn) {events[name]=fn;} };
    let id=0;
    vm.runInNewContext(source, {document, URL, AbortController,
        window:{location:new URL('https://example.invalid/checkout.php?success=1'),scrollY:360,
            scrollTo(x,y) {state.scrolls.push([x,y]);}, addEventListener(name,fn) {windowEvents[name]=fn;}},
        setTimeout(fn,ms) {timers.set(++id,{fn,ms});return id;}, clearTimeout(id) {timers.delete(id);},
        DOMParser:class {parseFromString() {return {body:{dataset:{checkoutUser:state.responseUser}},getElementById:()=>state.incoming};}},
        fetch(url,request) {
            state.calls++; state.url=new URL(url); state.request=request;
            if (options.pending) return new Promise((_resolve,reject)=>request.signal.addEventListener('abort',()=>reject(new DOMException('Stopped','AbortError'))));
            if (state.fail) return Promise.reject(new Error('Offline'));
            return Promise.resolve({ok:true,redirected:state.redirected,text:async()=>''});
        }
    });
    return Object.assign(state,{card,status,button,document,events,windowEvents,timers,action,
        poll(ms=3000) {const entry=[...timers].find(([,timer])=>timer.ms===ms);assert.ok(entry,'Poll scheduled for '+ms);timers.delete(entry[0]);return entry[1].fn();},
        ready() {this.incoming={innerHTML:'ready',dataset:{orderId:'12',orderStatus:'3',paymentState:'paid'},childNodes:[{html:'ready'}]};}
    });
}

test('confirmation uses initial HTML, then polls the pinned order without caching',async()=>{
    const page=fixture();assert.equal(page.calls,0);await page.poll();
    assert.equal(page.calls,1);assert.equal(page.url.searchParams.get('order'),'12');
    assert.equal(page.request.credentials,'same-origin');assert.equal(page.request.cache,'no-store');
    assert.equal(page.status.textContent,'Live order updates connected.');
});
test('staff changes update status and payment without reload or losing scroll and button focus',async()=>{
    const page=fixture();page.ready();page.document.activeElement={closest:()=>page.action};await page.poll();
    assert.equal(page.card.innerHTML,'ready');assert.equal(page.card.dataset.orderStatus,'3');
    assert.equal(page.card.dataset.paymentState,'paid');assert.equal(page.restores,1);
    assert.deepEqual(page.scrolls,[[0,360]]);assert.equal(page.status.textContent,'Order updated just now.');
});
test('manual checks and focus refresh immediately without overlapping requests',async()=>{
    const page=fixture({pending:true});const pending=page.poll();page.click();page.windowEvents.focus();
    assert.equal(page.calls,1);assert.equal(page.button.disabled,true);
    page.windowEvents.pagehide();await pending;assert.equal(page.button.disabled,false);assert.equal(page.timers.size,0);
});
test('changed sessions and different orders clear stale confirmation data and stop polling',async()=>{
    for(const change of [page=>{page.responseUser='2';},page=>{page.incoming.dataset.orderId='99';},page=>{page.redirected=true;}]) {
        const page=fixture();change(page);await page.poll();assert.equal(page.card.innerHTML,'');
        assert.equal(page.button.hidden,true);assert.equal(page.timers.size,0);assert.equal(page.status.link.href,'orders.php');
    }
});
test('missing markup and network failures retain the current order and retry automatically',async()=>{
    const page=fixture();page.fail=true;await page.poll();assert.equal(page.card.innerHTML,'pending');
    assert.equal(page.status.dataset.state,'error');page.fail=false;page.ready();await page.poll(6000);
    assert.equal(page.card.innerHTML,'ready');assert.equal(page.status.dataset.state,'connected');
    const missing=fixture();missing.incoming=null;await missing.poll();assert.equal(missing.card.innerHTML,'pending');
    assert.ok([...missing.timers.values()].some(timer=>timer.ms===6000));
});
test('hidden pages abort work and returning to the page resumes checks',async()=>{
    const page=fixture({pending:true});const pending=page.poll();page.document.hidden=true;page.events.visibilitychange();await pending;
    assert.equal(page.request.signal.aborted,true);assert.equal(page.timers.size,0);assert.equal(page.status.textContent,'Waiting');
    page.document.hidden=false;page.events.visibilitychange();assert.equal(page.calls,2);
    page.windowEvents.pagehide();
});
test('same-tab navigation pauses checks, but modifier clicks do not',()=>{
    const page=fixture();const link={href:'https://example.invalid/orders.php',target:'',hasAttribute:()=>false};
    page.events.click({button:0,ctrlKey:true,target:{closest:()=>link}});assert.equal(page.timers.size,1);
    page.events.click({button:0,target:{closest:()=>link}});assert.equal(page.timers.size,0);
    page.windowEvents.focus();assert.equal(page.calls,0);
});
test('back-forward cache and online events trigger a fresh check',async()=>{
    const page=fixture();page.windowEvents.pagehide();page.ready();await page.windowEvents.pageshow({persisted:true});
    assert.equal(page.card.innerHTML,'ready');await page.windowEvents.online();assert.equal(page.calls,2);
});
test('completed orders use a slower interval but still accept later payment updates',async()=>{
    const page=fixture();page.incoming.dataset.orderStatus='4';await page.poll();
    page.incoming.innerHTML='refunded';page.incoming.childNodes=[{html:'refunded'}];page.incoming.dataset.paymentState='refunded';
    await page.poll(15000);assert.equal(page.card.dataset.paymentState,'refunded');
});
