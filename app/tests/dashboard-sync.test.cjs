const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const code = fs.readFileSync(path.join(__dirname, '../dashboard-sync.js'), 'utf8');

function fixture(pending = false) {
    const events = {}, windowEvents = {}, timers = new Map();
    const status = {textContent:'Connected'};
    const document = {
        hidden:false, body:{dataset:{dashboardUser:'1'}},
        getElementById:id => id === 'dashboard-sync-status' ? status : null,
        querySelectorAll:() => [], addEventListener:(name,fn) => { events[name] = fn; }
    };
    let timerId = 0, calls = 0, signal;
    const window = {location:new URL('https://example.invalid/dashboard.php'), addEventListener:(name,fn) => { windowEvents[name] = fn; }};
    vm.runInNewContext(code, {document,window,URL,AbortController,
        setTimeout:(fn,ms) => {timers.set(++timerId,{fn,ms});return timerId;},
        clearTimeout:id => timers.delete(id),
        DOMParser:class {parseFromString() {return {body:{dataset:{dashboardUser:'1'}}};}},
        fetch:(_url, options) => {
            calls++; signal = options.signal;
            if (pending) return new Promise((_resolve,reject) => signal.addEventListener('abort', () => reject(new DOMException('Stopped','AbortError'))));
            return Promise.resolve({ok:true,redirected:false,text:async () => '<html></html>'});
        }
    });
    return {document,events,windowEvents,timers,status,
        calls:() => calls, signal:() => signal,
        poll:() => {const entry = [...timers].find(([,t]) => t.ms === 3000); assert.ok(entry); timers.delete(entry[0]); return entry[1].fn();}
    };
}
test('initial data schedules a poll without immediately duplicating the dashboard request', () => {
    const page = fixture();
    assert.equal(page.calls(),0);
    assert.equal(page.timers.size,1);
});
test('scheduled polling still updates live status and schedules the next poll', async () => {
    const page = fixture(); await page.poll();
    assert.equal(page.calls(),1);
    assert.match(page.status.textContent,/Live updates connected/);
    assert.equal(page.timers.size,1);
});
test('leaving aborts background work without displaying a false error or scheduling another poll', async () => {
    const page = fixture(true); const request = page.poll();
    page.windowEvents.pagehide(); await request;
    assert.equal(page.signal().aborted,true);
    assert.equal(page.status.textContent,'Connected');
    assert.equal(page.timers.size,0);
    page.windowEvents.focus(); assert.equal(page.calls(),1);
});
test('navbar navigation pauses polling, while a new-tab click does not', () => {
    const page = fixture();
    const link = {href:'https://example.invalid/menu.php', target:'', hasAttribute:() => false};
    const event = {button:0,target:{closest:() => link}};
    page.events.click({...event,ctrlKey:true}); assert.equal(page.timers.size,1);
    page.events.click(event); assert.equal(page.timers.size,0);
});
test('back-forward cache restoration resumes polling', () => {
    const page = fixture(); page.windowEvents.pagehide();
    assert.equal(page.timers.size,0);
    page.windowEvents.pageshow({persisted:true}); assert.equal(page.timers.size,1);
});
test('hiding the page aborts in-flight work and avoids an error notice', async () => {
    const page = fixture(true); const request = page.poll();
    page.document.hidden = true; page.events.visibilitychange(); await request;
    assert.equal(page.signal().aborted,true);
    assert.equal(page.status.textContent,'Connected');
    assert.equal(page.timers.size,0);
});
