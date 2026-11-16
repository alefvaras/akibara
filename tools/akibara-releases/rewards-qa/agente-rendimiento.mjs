// AGENTE 3 — RENDIMIENTO (gate qa-rewards, orden del dueño 2026-08-31).
// Mide TTFB/LCP/CLS en móvil emulado con caché FRÍA y caliente (flush entre
// lados: la caché caliente miente) sobre las superficies clave, y corre la
// sonda de textos corridos como parte del veredicto.
//
// POR QUÉ MIDE 3 VECES Y DECIDE POR LA MEDIANA (diagnóstico del gate, pasada 2
// del 2026-09-01):
//   La versión anterior hacía UNA navegación por superficie y lado, y el lado
//   «caliente» —el único con presupuesto— arrancaba con la PRIMERA navegación
//   de un contexto recién creado. Esa primera navegación paga el arranque del
//   proceso de render, la conexión y la compilación de los bundles: dio
//   `home @caliente` en 1220 ms y 967 ms en las dos pasadas (rojo las dos), con
//   la MEDIANA real de la misma superficie en ~298 ms. Un n=1 sobre el peor
//   momento posible del navegador no mide el sitio: mide el arranque de
//   Chromium, y hace fallar el gate por algo que el cliente nunca ve.
//   Ahora: se DESCARTA una navegación de calentamiento por cada contexto nuevo
//   y cada superficie se mide REPETICIONES veces; el veredicto es la MEDIANA
//   (no la media: un pico aislado no puede arrastrar el número).
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const PRESUPUESTO = { lcp: 2500, cls: 0.1, ttfb: 800 };
const REPETICIONES = 3;
const SUPERFICIES = [
  [ 'home', '/' ],
  [ 'tienda', '/tienda/' ],
  [ 'ficha', process.env.AKB_PDP || '/product/frieren-10-ivrea-argentina/' ],
  [ 'a-pedido', '/a-pedido/' ],
  [ 'puntos-pagina', '/akibara-puntos/' ],
];
// La superficie que se navega para CALENTAR el contexto y cuyo número se tira.
const CALENTAMIENTO = SUPERFICIES[ 0 ][ 1 ];

// Mediana de verdad (ordena y toma el centro; con n par promedia los dos del
// medio). Con REPETICIONES=3 siempre es la muestra central.
const mediana = ( xs ) => {
  const s = [ ...xs ].sort( ( a, b ) => a - b );
  return s.length % 2 ? s[ ( s.length - 1 ) / 2 ] : ( s[ s.length / 2 - 1 ] + s[ s.length / 2 ] ) / 2;
};

const purga = () => {
  try {
    execSync( 'docker compose run --rm -T cli wp litespeed-purge all --path=/var/www/html', { cwd: '/Users/alefvaras/Documents/akibara', stdio: 'ignore' } );
  } catch ( e ) { /* la purga fallida se nota en los números */ }
};

const medir = async ( page, url ) => {
  await page.goto( BASE + url, { waitUntil: 'networkidle' } );
  await page.waitForTimeout( 1200 );
  return page.evaluate( () => new Promise( ( resolve ) => {
    const nav = performance.getEntriesByType( 'navigation' )[ 0 ];
    let lcp = 0;
    let cls = 0;
    new PerformanceObserver( ( l ) => { for ( const e of l.getEntries() ) lcp = e.startTime; } )
      .observe( { type: 'largest-contentful-paint', buffered: true } );
    new PerformanceObserver( ( l ) => { for ( const e of l.getEntries() ) if ( ! e.hadRecentInput ) cls += e.value; } )
      .observe( { type: 'layout-shift', buffered: true } );
    setTimeout( () => resolve( {
      ttfb: Math.round( nav ? nav.responseStart : -1 ),
      lcp: Math.round( lcp ),
      cls: Math.round( cls * 1000 ) / 1000,
    } ), 800 );
  } ) );
};

const run = async () => {
  const browser = await chromium.launch();
  let fallas = 0;

  for ( const lado of [ 'fria', 'caliente' ] ) {
    if ( 'fria' === lado ) purga();
    const ctx = await browser.newContext( { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 } );
    const page = await ctx.newPage();

    // CALENTAMIENTO: la primera navegación de un contexto nuevo no se mide.
    // Es la que pagaba el arranque del render y ensuciaba la primera
    // superficie de la lista (ver cabecera).
    if ( 'fria' === lado ) purga();
    const descartada = await medir( page, CALENTAMIENTO );
    console.log( `calentamiento @${lado}: ttfb=${descartada.ttfb}ms lcp=${descartada.lcp}ms cls=${descartada.cls}  ← DESCARTADA` );

    for ( const [ nombre, url ] of SUPERFICIES ) {
      const muestras = [];
      for ( let i = 0; i < REPETICIONES; i++ ) {
        // El lado frío purga ANTES DE CADA navegación: si no, la segunda
        // repetición ya sería caliente y el «lado frío» dejaría de serlo.
        if ( 'fria' === lado ) purga();
        muestras.push( await medir( page, url ) );
      }
      const m = {
        ttfb: Math.round( mediana( muestras.map( ( x ) => x.ttfb ) ) ),
        lcp: Math.round( mediana( muestras.map( ( x ) => x.lcp ) ) ),
        cls: Math.round( mediana( muestras.map( ( x ) => x.cls ) ) * 1000 ) / 1000,
      };
      // Presupuestos solo del lado CALIENTE en local (la fría documenta).
      const excede = 'caliente' === lado && ( m.lcp > PRESUPUESTO.lcp || m.cls > PRESUPUESTO.cls || m.ttfb > PRESUPUESTO.ttfb );
      if ( excede ) fallas++;
      // Se imprimen también las muestras: una mediana sin sus datos es una
      // cifra que nadie puede discutir.
      console.log(
        `${nombre} @${lado}: ttfb=${m.ttfb}ms lcp=${m.lcp}ms cls=${m.cls} (mediana de ${REPETICIONES}` +
        ` · ttfb=[${muestras.map( ( x ) => x.ttfb ).join( ', ' )}]` +
        ` lcp=[${muestras.map( ( x ) => x.lcp ).join( ', ' )}]` +
        ` cls=[${muestras.map( ( x ) => x.cls ).join( ', ' )}])${excede ? '  ← EXCEDE PRESUPUESTO' : ''}`
      );
    }
    await ctx.close();
  }

  await browser.close();

  // La sonda visual es parte del veredicto de rendimiento/UI.
  try {
    const out = execSync( 'node ' + new URL( 'qa-visual-sonda.mjs', import.meta.url ).pathname, {
      env: { ...process.env },
      encoding: 'utf8',
    } );
    const total = /TOTAL-PROBLEMAS=(\d+)/.exec( out );
    console.log( 'sonda-visual TOTAL-PROBLEMAS=' + ( total ? total[ 1 ] : '?' ) );
    if ( ! total || '0' !== total[ 1 ] ) fallas++;
  } catch ( e ) {
    console.log( 'sonda-visual ERROR' );
    fallas++;
  }

  console.log( `RENDIMIENTO-FALLAS=${fallas}` );
  process.exit( fallas > 0 ? 1 : 0 );
};

run();
