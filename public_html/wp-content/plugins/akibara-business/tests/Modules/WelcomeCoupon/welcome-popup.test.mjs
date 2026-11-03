import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';

const source = fs.readFileSync(new URL('../../../assets/js/welcome-popup.js', import.meta.url), 'utf8');

function start({mode='promo',cookie='',storage={},search='',preview=false,delay=10,audience='all',logged=false}={}) {
  const timers=[]; const events=new Map();
  const classes = new Set(logged?['logged-in']:[]);
  const classList={contains:k=>classes.has(k),add:k=>classes.add(k),remove:k=>classes.delete(k)};
  const launcher={hidden:true,classList,addEventListener(){},querySelector(){return null;}};
  const modal={hidden:true,classList,addEventListener(){},querySelector(){return null;},querySelectorAll(){return [];}};
  const doc={cookie,body:{classList,scrollHeight:3000},documentElement:{classList,scrollHeight:3000,clientHeight:900},activeElement:null,
    querySelector(selector){return selector==='#akb-welcome-modal'?modal:selector==='#akb-welcome-launcher'?launcher:null;},
    getElementById(id){return id==='akb-welcome-modal'?modal:id==='akb-welcome-launcher'?launcher:null;},
    addEventListener(name,fn){events.set(name,fn);},removeEventListener(){}};
  const config={mode,audience,preview,delaySeconds:delay,cookieName:mode==='promo'?'promo-test':'welcome-test',doneCookieName:'akb_welcome_done',launcher:true,exitIntent:false};
  const win={akbWelcomePopup:config,location:{search},localStorage:{getItem:k=>storage[k]??null,setItem:(k,v)=>storage[k]=v},
    setTimeout(fn,ms){timers.push({fn,ms});return timers.length;},clearTimeout(){},addEventListener(){},removeEventListener(){},fetch(){throw new Error('Informative popup must not send a request');}};
  vm.runInNewContext(source,{window:win,document:doc,navigator:{},URLSearchParams,Date,console});
  return {timers,modal,launcher,events,config};
}

test('Informative campaigns still reach visitors who completed welcome',()=>{
  assert.equal(start({cookie:'akb_welcome_done=1'}).timers.length,1);
  assert.equal(start({storage:{akb_welcome_done:'1'}}).timers.length,1);
});
test('Welcome remains suppressed after its own completion',()=>{
  assert.equal(start({mode:'welcome',cookie:'akb_welcome_done=1'}).timers.length,0);
});
test('An authorized welcome preview remains visible after the administrator completed welcome',()=>{
  assert.equal(start({mode:'welcome',cookie:'akb_welcome_done=1',preview:true}).modal.hidden,false);
});
test('Zero seconds means immediate scheduling',()=>{
  assert.equal(start({delay:0}).timers[0].ms,0);
});
test('An untrusted preview URL cannot bypass an existing campaign dismissal',()=>{
  const state=start({search:'?akb_preview_welcome=1',cookie:'promo-test=1',preview:false});
  assert.equal(state.timers.length,0);
  assert.equal(state.modal.hidden,true);
});
test('Server-authorized preview can bypass dismissal for the administrator',()=>{
  assert.equal(start({cookie:'promo-test=1',preview:true}).modal.hidden,false);
});
test('Guest campaigns are not scheduled for a logged in visitor',()=>{
  assert.equal(start({audience:'guests',logged:true}).timers.length,0);
});
test('A dismissed guest campaign cannot leak its launcher to a logged in visitor',()=>{
  assert.equal(start({audience:'guests',logged:true,cookie:'promo-test=1'}).launcher.hidden,true);
});
