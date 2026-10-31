const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const { readFileSync } = require('node:fs');
const source = readFileSync(require('node:path').join(__dirname,'../payment-confirmation.js'),'utf8');
function setup(type = 'receipt', method = 'GCash') {
    const state = { sends: 0, capture: null, fields: {} };
    const reason = { value: '', setCustomValidity(value) { this.error=value; }, reportValidity() { return !this.error && this.value.length<=200; }, addEventListener(t,f) { state.reasonInput=f; }, focus() {} };
    const cancel = { addEventListener(t,f) { state.cancel=f; }, focus() {} };
    const proceed = { addEventListener(t,f) { state.proceed=f; } };
    const dialog = { open:false, showModal() { this.open=true; }, close() { this.open=false;state.close(); }, addEventListener(t,f) { state.close=f; },
        querySelector(selector) {
            if (selector==='[data-payment-confirm-cancel]') return cancel;
            if (selector==='[data-payment-confirm-proceed]') return proceed;
            return state.fields[selector] ||= {};
        } };
    const nodes = { 'payment-confirmation':dialog,'payment-confirmation-title':{},'payment-confirmation-message':{},'payment-rejection-field':{},'payment-rejection-reason':reason };
    const reference = { value:'1234567890123' };
    const rejection = { value:'' };
    const form = { isConnected:true, dataset:{paymentConfirm:type,paymentStore:'Test Kitchen',paymentAmount:'60.00',paymentOrder:'ORD-TEST',paymentReference:reference.value},
        matches:()=>true, reportValidity:()=>state.valid!==false,
        querySelector(selector) { return ({'[name="payment_method"]:checked':{value:method},'[name="reference_number"]':reference,'[name="receipt"]':{files:[{name:'receipt.png'}]},'[name="reject_reason"]':rejection})[selector]; },
        requestSubmit() { const event=state.submit(); if(!event.defaultPrevented) state.sends++; }
    };
    vm.runInNewContext(source,{document:{getElementById:id=>nodes[id],addEventListener(type,f,capture){state.capture=f;assert.equal(capture,true);}}});
    state.submit=()=>{ const event={target:form,preventDefault(){this.defaultPrevented=true;}};state.capture(event);return event; };
    return Object.assign(state,{form,dialog,reason,rejection,nodes});
}
test('receipt submission waits for confirmation and sends once',()=>{
    const s=setup();assert.equal(s.submit().defaultPrevented,true);assert.equal(s.sends,0);assert.equal(s.dialog.open,true);
    assert.equal(s.form.dataset.confirming,'true');s.proceed();assert.equal(s.sends,1);assert.equal(s.dialog.open,false);assert.equal(s.form.dataset.confirming,undefined);
    s.submit();assert.equal(s.dialog.open,true);assert.equal(s.sends,1);
});
test('cancel and Escape leave payment unsent',()=>{
    const s=setup('checkout');s.submit();s.cancel();assert.equal(s.sends,0);assert.equal(s.form.dataset.confirming,undefined);
    s.submit();s.dialog.close();s.proceed();assert.equal(s.sends,0);
});
test('rejecting an order requires a customer-visible reason',()=>{
    const s=setup('reject');s.submit();s.proceed();assert.equal(s.sends,0);assert.equal(s.dialog.open,true);
    s.reason.value='  Incorrect payment amount.  ';s.reasonInput();s.proceed();assert.equal(s.sends,1);assert.equal(s.rejection.value,'Incorrect payment amount.');
});
test('a detached or invalid form cannot be confirmed',()=>{
    const s=setup();s.submit();s.form.isConnected=false;s.proceed();assert.equal(s.sends,0);
    const invalid=setup();invalid.submit();invalid.valid=false;invalid.proceed();assert.equal(invalid.sends,0);
});
test('checkout confirmation states payment is still pending',()=>{
    const s=setup('checkout');s.submit();assert.match(s.nodes['payment-confirmation-message'].textContent,/pending order/);assert.match(s.nodes['payment-confirmation-message'].textContent,/staff verification/);
});
test('cash checkout explains preparation before payment and payment at pickup',()=>{
    const s=setup('checkout','Cash');s.submit();assert.match(s.nodes['payment-confirmation-message'].textContent,/prepare your food before payment/);
    assert.match(s.nodes['payment-confirmation-message'].textContent,/order number and pay at the cashier/);
});
test('cash confirmation requires checking the order number and actually receiving cash',()=>{
    const s=setup('verify','Cash');s.submit();assert.match(s.nodes['payment-confirmation-message'].textContent,/order number/);
    assert.match(s.nodes['payment-confirmation-message'].textContent,/received the full cash amount/);
    assert.match(s.nodes['payment-confirmation-message'].textContent,/does not mean it is paid/);
});
