import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {runInNewContext} from 'node:vm';
const source=await readFile(new URL('../app/recovery.js',import.meta.url),'utf8');
test('Recovery eye buttons toggle each field independently without changing password values',()=>{
  let click;
  const inputs={new:{type:'password',value:'fixture only'},confirm:{type:'password',value:'fixture only'}};
  const buttons=Object.fromEntries(['new','confirm'].map(id=>[id,{
    dataset:{passwordLabel:id==='new'?'new password':'confirm new password'},
    attributes:{'aria-controls':id,'aria-pressed':'false'},
    getAttribute(name){return this.attributes[name];},
    setAttribute(name,value){this.attributes[name]=value;},
  }]));
  runInNewContext(source,{document:{
    addEventListener(name,fn){assert.equal(name,'click');click=fn;},
    getElementById(id){return inputs[id];},
  }});
  const press=id=>click({target:{closest:()=>buttons[id]}});
  press('new');
  assert.equal(inputs.new.type,'text');assert.equal(inputs.confirm.type,'password');
  assert.equal(buttons.new.attributes['aria-label'],'Hide new password');
  assert.equal(buttons.new.attributes['aria-pressed'],'true');
  press('new');press('confirm');
  assert.equal(inputs.new.type,'password');assert.equal(inputs.confirm.type,'text');
  assert.equal(buttons.new.attributes['aria-label'],'Show new password');
  assert.equal(buttons.confirm.attributes['aria-label'],'Hide confirm new password');
  assert.equal(inputs.new.value,'fixture only');assert.equal(inputs.confirm.value,'fixture only');
  click({target:{closest:()=>null}});
});
