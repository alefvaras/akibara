// SONDA DE REPRODUCCIÓN — «home @caliente: ttfb > 800ms» del agente 3.
//
// El gate midió home con TTFB 1220 ms del lado CALIENTE (presupuesto 800 ms)
// mientras el lado FRÍO de la MISMA superficie dio 386 ms. Un caliente más
// lento que su frío no es una regresión de rendimiento: es el síntoma de que
// la primera navegación de un contexto nuevo se está midiendo como si fuera
// régimen. Esta sonda separa las dos cosas:
//
//   - N navegaciones seguidas en el MISMO contexto (régimen real);
//   - la PRIMERA navegación de un contexto recién creado, repetida N veces
//     (lo que el agente 3 mide para «home @caliente»).
//
// Si la primera navegación es sistemáticamente la lenta y el régimen cabe en
// presupuesto, el rojo del gate es de la MEDICIÓN, no del sitio. Si el
// régimen también excede, el rojo es real y hay que optimizar la home.
//
// Uso: node sonda-ttfb-home.mjs [ruta] [vueltas]      (por defecto: / y 6)
// Exit != 0 si la MEDIANA en régimen excede el presupuesto de 800 ms.
import { chromium } from 'playwright';

const BASE  = 'http://localhost:8080';
const RUTA  = process.argv[ 2 ] || '/';
const VUELTAS = parseInt( process.argv[ 3 ] || '6', 10 );
const PRESUPUESTO_TTFB = 800;

const ttfbDe = ( page ) => page.evaluate( () => {
  const nav = performance.getEntriesByType( 'navigation' )[ 0 ];
  return Math.round( nav ? nav.responseStart : -1 );
} );

const mediana = ( xs ) => {
  const s = [ ...xs ].sort( ( a, b ) => a - b );
  return s.length % 2 ? s[ ( s.length - 1 ) / 2 ] : Math.round( ( s[ s.length / 2 - 1 ] + s[ s.length / 2 ] ) / 2 );
};

const movil = { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 };

const browser = await chromium.launch();

// A) Régimen: un solo contexto, N navegaciones seguidas.
const ctx = await browser.newContext( movil );
const page = await ctx.newPage();
const regimen = [];
for ( let i = 0; i < VUELTAS; i++ ) {
  await page.goto( BASE + RUTA, { waitUntil: 'networkidle' } );
  regimen.push( await ttfbDe( page ) );
}
await ctx.close();

// B) Primera navegación de un contexto NUEVO, N veces (lo del agente 3).
const primeras = [];
for ( let i = 0; i < VUELTAS; i++ ) {
  const c = await browser.newContext( movil );
  const p = await c.newPage();
  await p.goto( BASE + RUTA, { waitUntil: 'networkidle' } );
  primeras.push( await ttfbDe( p ) );
  await c.close();
}

await browser.close();

console.log( `ruta: ${RUTA}  vueltas: ${VUELTAS}` );
console.log( `regimen-mismo-contexto: [${regimen.join( ', ' )}]  mediana=${mediana( regimen )}ms  max=${Math.max( ...regimen )}ms` );
console.log( `primera-de-contexto-nuevo: [${primeras.join( ', ' )}]  mediana=${mediana( primeras )}ms  max=${Math.max( ...primeras )}ms` );

const medReg = mediana( regimen );
const medPri = mediana( primeras );
const ok = medReg <= PRESUPUESTO_TTFB;
console.log( `regimen-dentro-de-presupuesto(${PRESUPUESTO_TTFB}ms): ${ok ? 'OK' : 'FALLA'} [mediana=${medReg}ms]` );
console.log( `sobrecosto-de-contexto-nuevo: ${medPri - medReg}ms` );
console.log( `TTFB-HOME-FALLAS=${ok ? 0 : 1}` );
process.exit( ok ? 0 : 1 );
