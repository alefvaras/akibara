import { chromium } from 'playwright';
const BASE='http://localhost:8080';
const nb=await chromium.launch(); const p=await (await nb.newContext({viewport:{width:1440,height:1000}})).newPage();
const faltantes=[];
p.on('response',(r)=>{ if(r.status()>=400) faltantes.push(r.status()+' '+r.url().replace(BASE,'').slice(0,110)); });
await p.goto(`${BASE}/wp-login.php`,{waitUntil:'load'});
await p.fill('#user_login','qa.owner'); await p.fill('#user_pass','AkbQaOwner2026!');
await Promise.all([p.waitForNavigation({waitUntil:'load'}),p.click('#wp-submit')]);
await p.goto(`${BASE}/wp-admin/admin.php?page=akibara-panel&tab=dashboard`,{waitUntil:'load'});
console.log('== submenú real de Akibara');
const items = await p.$$eval('#adminmenu a', (as)=>as
  .filter(a=>/akibara/i.test(a.href))
  .map(a=>({t:a.textContent.trim().replace(/\s+/g,' ').slice(0,30), h:a.getAttribute('href')})));
const vistos=new Set();
for(const it of items){ if(vistos.has(it.h))continue; vistos.add(it.h); console.log('  ·',it.t.padEnd(24),it.h); }
console.log('\n== recursos con error en esta pantalla');
[...new Set(faltantes)].forEach(f=>console.log('  ',f));
await nb.close();
