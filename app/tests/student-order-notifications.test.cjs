const test=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const source=require('node:fs').readFileSync(require('node:path').join(__dirname,'../student-order-notifications.js'),'utf8');
function fixture(options={}) {
    const state={calls:0,count:0,user:'4',code:200,fail:false,writes:0};
    const events={},windowEvents={},timers=new Map();
    let text=String(options.initial || 0);
    const badge={hidden:!options.initial,setAttribute(name,value){this[name]=value;}};
    Object.defineProperty(badge,'textContent',{get:()=>text,set:value=>{text=value;state.writes++;}});
    const status={textContent:''};
    const document={hidden:false,querySelector:()=>({dataset:{orderNotificationsUser:'4'}}),
        getElementById:id=>({'student-order-notifications-count':badge,'student-order-notifications-status':status})[id],
        addEventListener(name,fn){events[name]=fn;}};
    let id=0;
    vm.runInNewContext(source,{document,URL,AbortController,
        window:{location:new URL('https://example.invalid/cart.php'),addEventListener(name,fn){windowEvents[name]=fn;}},
        setTimeout(fn,ms){timers.set(++id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);},
        fetch(url,request){state.calls++;state.url=url;state.request=request;
            if(options.pending)return new Promise((_resolve,reject)=>request.signal.addEventListener('abort',()=>reject(new DOMException('Stopped','AbortError'))));
            if(state.fail)return Promise.reject(new Error('Offline'));
            return Promise.resolve({ok:state.code===200,status:state.code,redirected:state.redirected,json:async()=>({user_id:state.user,confirmed_count:state.count})});}
    });
    return Object.assign(state,{events,windowEvents,timers,document,badge,status,
        poll(ms=3000){const entry=[...timers].find(([,timer])=>timer.ms===ms);assert.ok(entry);timers.delete(entry[0]);return entry[1].fn();}});
}
test('payment confirmation updates the navbar badge without a page reload',async()=>{
    const page=fixture();assert.equal(page.calls,0);page.count=1;await page.poll();
    assert.equal(page.badge.textContent,'1');assert.equal(page.badge.hidden,false);
    assert.match(page.status.textContent,/Payment confirmed/);assert.match(page.badge['aria-label'],/confirmed payment/);
    assert.equal(page.request.credentials,'same-origin');assert.equal(page.request.cache,'no-store');
});
test('unchanged counts do not redraw or blink and completed orders hide the badge',async()=>{
    const page=fixture({initial:1});page.count=1;await page.poll();await page.poll();assert.equal(page.writes,0);
    page.count=0;await page.poll();assert.equal(page.badge.hidden,true);assert.equal(page.badge.textContent,'0');
});
test('a changed account or expired session clears the count and stops polling',async()=>{
    for(const change of [page=>{page.user='5';},page=>{page.code=401;},page=>{page.code=403;},page=>{page.redirected=true;}]){
        const page=fixture({initial:2});change(page);await page.poll();assert.equal(page.badge.hidden,true);assert.equal(page.timers.size,0);
    }
});
test('invalid data or network errors keep the last verified count and retry',async()=>{
    const page=fixture({initial:1});page.count=-1;await page.poll();assert.equal(page.badge.textContent,'1');
    page.count=2;await page.poll(6000);assert.equal(page.badge.textContent,'2');
    page.fail=true;await page.poll();assert.equal(page.badge.textContent,'2');assert.ok([...page.timers.values()].some(timer=>timer.ms===6000));
});
test('hidden pages abort badge checks and foreground restores them without overlap',async()=>{
    const page=fixture({pending:true});const pending=page.poll();page.windowEvents.focus();assert.equal(page.calls,1);
    page.document.hidden=true;page.events.visibilitychange();await pending;assert.equal(page.request.signal.aborted,true);assert.equal(page.timers.size,0);
    page.document.hidden=false;page.events.visibilitychange();assert.equal(page.calls,2);page.windowEvents.pagehide();
});
test('same-tab navigation pauses checks and browser back resumes them',async()=>{
    const page=fixture();const link={href:'https://example.invalid/orders.php',target:'',hasAttribute:()=>false};
    page.events.click({button:0,ctrlKey:true,target:{closest:()=>link}});assert.equal(page.timers.size,1);
    page.events.click({button:0,target:{closest:()=>link}});assert.equal(page.timers.size,0);
    await page.windowEvents.pageshow({persisted:true});assert.equal(page.calls,1);
});
