import { chromium } from 'playwright';
import fs from 'node:fs';
const inv = JSON.parse(fs.readFileSync(process.env.GATE3_INV,'utf8'));
const hex = (c)=>{const m=/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?/.exec(c||'');if(!m)return c;if(m[4]!==undefined&&+m[4]===0)return 'transparente';return '#'+[1,2,3].map(i=>(+m[i]).toString(16).padStart(2,'0')).join('').toUpperCase();};
const b = await chromium.launch(); const p = await (await b.newContext()).newPage();
const acumBg={}, acumFg={}, fuentes={};
for (const it of inv){
  const r = await (await fetch('http://localhost:8025/api/v1/message/'+it.id)).json();
  await p.setViewportSize({width:600,height:900});
  await p.setContent(r.HTML,{waitUntil:'load'}); await p.waitForTimeout(250);
  const d = await p.evaluate(()=>{
    const bg={},fg={},ff={};
    for(const el of document.querySelectorAll('*')){
      const rect=el.getBoundingClientRect(); if(rect.width*rect.height<40) continue;
      const c=getComputedStyle(el);
      bg[c.backgroundColor]=(bg[c.backgroundColor]||0)+1;
      if((el.textContent||'').trim().length>0 && el.children.length===0){ fg[c.color]=(fg[c.color]||0)+1; ff[c.fontFamily]=(ff[c.fontFamily]||0)+1; }
    }
    return {bg,fg,ff};
  });
  for(const [k,v] of Object.entries(d.bg)){const h=hex(k); if(h==='transparente')continue; acumBg[h]=(acumBg[h]||0)+v;}
  for(const [k,v] of Object.entries(d.fg)){const h=hex(k); acumFg[h]=(acumFg[h]||0)+v;}
  for(const [k,v] of Object.entries(d.ff)){fuentes[k]=(fuentes[k]||0)+v;}
}
await b.close();
const ord=(o)=>Object.entries(o).sort((a,c)=>c[1]-a[1]);
console.log('FONDOS:'); for(const [k,v] of ord(acumBg)) console.log('  ',k,v);
console.log('TEXTOS:'); for(const [k,v] of ord(acumFg)) console.log('  ',k,v);
console.log('FUENTES:'); for(const [k,v] of ord(fuentes)) console.log('  ',k,v);
