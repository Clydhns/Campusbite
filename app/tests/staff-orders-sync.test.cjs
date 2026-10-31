const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../staff-orders-sync.js'), 'utf8');

function fixture(options = {}) {
    const state = {calls:0,code:200,user:'7',store:'3',version:'b'.repeat(64),fail:false,scroll:[],trackingEvents:[],scripts:[]};
    const events = {}, windowEvents = {}, timers = new Map();
    const card = id => ({dataset:{staffOrder:id},getBoundingClientRect:()=>({top:100,bottom:400}),focus(){},querySelectorAll:()=>[]});
    const list = {cards:[],querySelectorAll:()=>list.cards,
        querySelector:selector=>selector.startsWith('[data-staff-order=')?list.cards.find(card=>selector.includes('"'+card.dataset.staffOrder+'"')):state.editing,
        replaceChildren(...nodes) {this.cards=nodes;} };
    const stats = {replaceChildren(...nodes){state.stats=nodes;}};
    const tracking = {querySelector:selector=>state.tracking && state.payload
        ? (selector.includes('script')?{textContent:state.payload}:{}) : null,
        replaceChildren(...nodes){state.tracking=nodes;}};
    const status = {dataset:{},textContent:'Waiting',append(link){this.link=link;}};
    let statusText = status.textContent;
    state.statusWrites = 0;
    Object.defineProperty(status, 'textContent', {get:()=>statusText,set:value=>{statusText=value;state.statusWrites++;}});
    const button = {disabled:false,hidden:false,addEventListener(_name,fn){state.manual=fn;},focus(){}};
    const dialog = {open:false};
    const nodes = {'staff-orders-list':list,'staff-order-stats':stats,'staff-order-tracking':tracking,
        'staff-orders-sync-status':status,'staff-orders-sync-refresh':button,'payment-confirmation':dialog};
    const document = {hidden:false,body:{dataset:{staffOrdersUser:'7',staffOrdersStore:'3',staffOrdersVersion:'a'.repeat(64)}},
        head:{append(script){state.scripts.push(script);}},
        getElementById:id=>nodes[id],createElement:()=>({}),addEventListener(name,fn){events[name]=fn;},
        dispatchEvent(event){state.trackingEvents.push(event.detail);} };
    const incoming = {cards:[card('42')],get childNodes(){return this.cards;},querySelectorAll(){return this.cards;}};
    const incomingTracking = {childNodes:[{tracking:true}],querySelector:()=>state.payload?{textContent:state.payload}:null};
    let id=0;
    vm.runInNewContext(source,{document,URL,AbortController,
        CustomEvent:class {constructor(type,options){this.type=type;this.detail=options.detail;}},
        window:{location:new URL('https://example.invalid/staff_dashboard.php'),scrollY:500,
            scrollTo(x,y){state.scroll.push([x,y]);},addEventListener(name,fn){windowEvents[name]=fn;}},
        setTimeout(fn,ms){timers.set(++id,{fn,ms});return id;},clearTimeout(id){timers.delete(id);},
        DOMParser:class {parseFromString(){state.parsed=true;return {body:{dataset:{staffOrdersUser:state.user,staffOrdersStore:state.store,staffOrdersVersion:state.version}},
            getElementById:id=>({'staff-orders-list':state.incomplete?null:incoming,'staff-order-stats':{childNodes:['counts']},'staff-order-tracking':incomingTracking})[id],
            querySelector:()=>({getAttribute:()=>state.scriptSource||'assets/order-tracking/tracking.js?v=1'})};}},
        fetch(url,request){state.calls++;state.url=new URL(url);state.request=request;
            if(options.pending)return new Promise((_resolve,reject)=>request.signal.addEventListener('abort',()=>reject(new DOMException('Stopped','AbortError'))));
            if(state.fail)return Promise.reject(new Error('Offline'));
            return Promise.resolve({ok:true,status:state.code,redirected:state.redirected,text:async()=>''});}
    });
    return Object.assign(state,{events,windowEvents,timers,document,list,stats,tracking,status,button,dialog,incoming,
        poll(ms=1500){const entry=[...timers].find(([,timer])=>timer.ms===ms);assert.ok(entry,'Scheduled interval '+ms);timers.delete(entry[0]);return entry[1].fn();}});
}

test('an initially empty staff workspace receives new orders and counts automatically',async()=>{
    const page=fixture();assert.equal(page.calls,0);await page.poll();
    assert.equal(page.list.cards[0].dataset.staffOrder,'42');assert.deepEqual(page.stats,['counts']);
    assert.equal(page.status.textContent,'New orders received.');assert.equal(page.document.body.dataset.staffOrdersVersion,'b'.repeat(64));
    assert.equal(page.request.credentials,'same-origin');assert.equal(page.request.cache,'no-store');assert.deepEqual(page.scroll,[[0,500]]);
});
test('unchanged staff checks return without parsing or replacing the order list',async()=>{
    const page=fixture();page.code=204;await page.poll();assert.equal(page.parsed,undefined);
    assert.equal(page.url.searchParams.get('sync'),'1');assert.equal(page.url.searchParams.get('version'),'a'.repeat(64));
    assert.equal(page.status.textContent,'Live order updates connected.');
});
test('payment dialogs, edited fields and saves defer updates without duplicate requests',async()=>{
    const page=fixture();page.dialog.open=true;await page.poll();assert.equal(page.calls,0);
    page.dialog.open=false;page.editing=true;await page.poll();assert.equal(page.calls,0);
    page.editing=false;page.document.body.dataset.staffOrdersSaving='true';await page.poll();assert.equal(page.calls,0);
    delete page.document.body.dataset.staffOrdersSaving;await page.manual();assert.equal(page.calls,1);
});
test('starting a staff action aborts a pending poll and prevents an old response overwriting the save',async()=>{
    const page=fixture({pending:true});const pending=page.poll();
    page.document.body.dataset.staffOrdersSaving='true';page.events['campusbite:staff-order-save']({detail:{saving:true}});await pending;
    assert.equal(page.request.signal.aborted,true);assert.equal(page.list.cards.length,0);assert.equal(page.status.textContent,'Waiting');
    delete page.document.body.dataset.staffOrdersSaving;page.events['campusbite:staff-order-save']({detail:{saving:false}});
    assert.equal(page.calls,2);page.windowEvents.pagehide();
});
test('changed staff identity or store clears private data and stops updates',async()=>{
    for(const change of [page=>{page.user='8';},page=>{page.store='4';},page=>{page.redirected=true;}]){
        const page=fixture();change(page);await page.poll();assert.equal(page.list.cards.length,0);
        assert.equal(page.button.hidden,true);assert.equal(page.timers.size,0);assert.equal(page.status.link.href,'login.php');
    }
});
test('network or incomplete responses keep existing orders and retry with backoff',async()=>{
    const page=fixture();page.fail=true;await page.poll();assert.equal(page.status.dataset.state,'error');
    page.fail=false;await page.poll(3000);assert.equal(page.list.cards.length,1);
    const incomplete=fixture();incomplete.incomplete=true;await incomplete.poll();assert.equal(incomplete.list.cards.length,0);
    assert.equal(incomplete.status.dataset.state,'error');
});
test('background tabs pause and back-forward cache restores automatic checks',async()=>{
    const page=fixture({pending:true});const pending=page.poll();page.document.hidden=true;page.events.visibilitychange();await pending;
    assert.equal(page.request.signal.aborted,true);assert.equal(page.timers.size,0);
    page.document.hidden=false;page.windowEvents.pagehide();page.windowEvents.pageshow({persisted:true});assert.equal(page.calls,2);page.windowEvents.pagehide();
});
test('navigation aborts polling while new-tab links keep it active',()=>{
    const page=fixture();const link={href:'https://example.invalid/staff_menu.php',target:'',hasAttribute:()=>false};
    page.events.click({button:0,ctrlKey:true,target:{closest:()=>link}});assert.equal(page.timers.size,1);
    page.events.click({button:0,target:{closest:()=>link}});assert.equal(page.timers.size,0);
    page.windowEvents.focus();assert.equal(page.calls,0);
});
test('first incoming order lazily loads tracking without requiring an initial order',async()=>{
    const page=fixture();page.payload='[{"number":"ORD-FIXTURE","status":"Pending"}]';await page.poll();
    assert.equal(page.scripts.length,1);assert.equal(page.scripts[0].src,'assets/order-tracking/tracking.js?v=1');
    page.scripts[0].onload();assert.equal(page.trackingEvents[0][0].number,'ORD-FIXTURE');
    const unsafe=fixture();unsafe.payload='[]';unsafe.scriptSource='https://untrusted.invalid/tracking.js';await unsafe.poll();assert.equal(unsafe.scripts.length,0);
});
test('a failed first tracking download retries on a lightweight unchanged check',async()=>{
    const page=fixture();page.payload='[]';await page.poll();assert.equal(page.scripts.length,1);
    page.scripts[0].onerror();page.code=204;await page.poll();assert.equal(page.scripts.length,2);
});
test('automatic staff checks leave controls steady and unchanged labels untouched',async()=>{
    const pendingPage=fixture({pending:true});const pending=pendingPage.poll();assert.equal(pendingPage.button.disabled,false);
    pendingPage.windowEvents.pagehide();await pending;
    const page=fixture();page.code=204;await page.poll();const writes=page.statusWrites;
    await page.poll();await page.poll();assert.equal(page.statusWrites,writes);assert.equal(page.button.disabled,false);
});
test('a manual staff check still provides busy feedback until it finishes',async()=>{
    const page=fixture({pending:true});const pending=page.manual();assert.equal(page.button.disabled,true);
    page.windowEvents.pagehide();await pending;assert.equal(page.button.disabled,false);
});
