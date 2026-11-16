// QA del carrusel: carátula de respaldo + GSAP + arreglos del escéptico,
// medido en escritorio 1440 y móvil 375 con capturas.
// REQUIERE SIEMBRA PREVIA (≥8 reseñas elegibles): usar seed.php del
// scratchpad o equivalente; sobre el espejo limpio (1 reseña) el carrusel
// no aparece por umbral y TODO fallaría. NO es paso del gate qa-rewards.sh.
import { chromium } from 'playwright';

const BASE = 'http://localhost:8080';
const OUT = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/carrusel-final';
import { mkdirSync } from 'node:fs';
mkdirSync( OUT, { recursive: true } );

let fallas = 0;
const check = ( n, ok, extra = '' ) => {
  console.log( `${n}: ${ok ? 'OK' : 'FALLA'}${extra ? ' [' + extra + ']' : ''}` );
  if ( ! ok ) fallas++;
};

const browser = await chromium.launch();

for ( const [ modo, vw, vh ] of [ [ 'desktop', 1440, 900 ], [ 'movil', 375, 812 ] ] ) {
  const ctx = await browser.newContext( { viewport: { width: vw, height: vh }, ...( 'movil' === modo ? { isMobile: true, hasTouch: true } : {} ) } );
  const p = await ctx.newPage();
  await p.goto( `${BASE}/?nocache=${Date.now()}`, { waitUntil: 'networkidle' } );

  // 1. GSAP presente y nuestro script encolado.
  const gsapOk = await p.evaluate( () => typeof window.gsap !== 'undefined' && typeof window.ScrollTrigger !== 'undefined' );
  const scriptOk = await p.evaluate( () => !! document.querySelector( 'script[src*="akibara-rewards/assets/js/carousel.js"]' ) );
  check( `${modo}-gsap-cargado`, gsapOk );
  check( `${modo}-reveal-encolado`, scriptOk );

  // 2. Las cards existen y TODAS llevan imagen (foto o carátula).
  const cards = await p.locator( '.akb-rw-card' ).count();
  const imgs = await p.locator( '.akb-rw-card img.akb-rw-photo' ).count();
  check( `${modo}-8-cards`, 8 === cards, `cards=${cards}` );
  check( `${modo}-toda-card-con-imagen`, imgs === cards, `imgs=${imgs}` );

  // 3. Reveal GSAP de verdad: antes de entrar, ocultas; tras scroll, opacity 1.
  const preOpacity = await p.evaluate( () => {
    const c = document.querySelector( '.akb-rw-card' );
    return c ? window.getComputedStyle( c ).opacity : 'sin-card';
  } );
  await p.locator( '.akb-rw-carousel' ).scrollIntoViewIfNeeded();
  await p.waitForTimeout( 900 );
  const postOpacity = await p.evaluate( () => {
    const cs = [ ...document.querySelectorAll( '.akb-rw-card' ) ];
    return cs.map( ( c ) => window.getComputedStyle( c ).opacity ).join( ',' );
  } );
  const revealed = await p.evaluate( () => document.querySelectorAll( '.akb-rw-card.akb-rw-revealed' ).length );
  check( `${modo}-reveal-anima`, '0' === preOpacity && postOpacity.split( ',' ).every( ( o ) => '1' === o ), `pre=${preOpacity} post=${postOpacity.slice( 0, 20 )}…` );
  check( `${modo}-cards-marcadas`, 8 === revealed );

  // 4. CERO scroll horizontal de página (el 1px del h2 incluido).
  const scroll = await p.evaluate( () => {
    window.scrollTo( 99999, window.scrollY );
    return { w: document.documentElement.scrollWidth, c: document.documentElement.clientWidth, x: window.scrollX };
  } );
  check( `${modo}-sin-scroll-x`, scroll.w === scroll.c && 0 === scroll.x, `scrollW=${scroll.w} clientW=${scroll.c} scrollX=${scroll.x}` );

  // 5. Anchos uniformes + alturas contenidas (line-clamp + imagen en todas).
  const geo = await p.evaluate( () => {
    const cs = [ ...document.querySelectorAll( '.akb-rw-card' ) ].map( ( c ) => c.getBoundingClientRect() );
    const ws = cs.map( ( r ) => r.width.toFixed( 1 ) );
    const hs = cs.map( ( r ) => r.height );
    return { ws: [ ...new Set( ws ) ], hmin: Math.min( ...hs ).toFixed( 0 ), hmax: Math.max( ...hs ).toFixed( 0 ) };
  } );
  check( `${modo}-anchos-uniformes`, 1 === geo.ws.length, `anchos=${geo.ws.join( '/' )}` );
  check( `${modo}-alturas-contenidas`, geo.hmax - geo.hmin <= 120, `h=${geo.hmin}-${geo.hmax}` );

  // 6. La JAULA de la imagen aguanta incluso rota (140px pase lo que pase);
  // cargadas es informativo: el espejo local carece de varias variantes
  // redimensionadas (artefacto del import; en prod existen).
  const imgStats = await p.evaluate( () => {
    const list = [ ...document.querySelectorAll( '.akb-rw-photo' ) ];
    return {
      total: list.length,
      ok: list.filter( ( i ) => i.complete && i.naturalWidth > 0 ).length,
      enjauladas: list.filter( ( i ) => Math.abs( i.getBoundingClientRect().height - 140 ) < 2 ).length,
    };
  } );
  check( `${modo}-jaula-140px`, imgStats.enjauladas === imgStats.total, `enjauladas=${imgStats.enjauladas}/${imgStats.total} cargadas=${imgStats.ok}` );

  if ( 'movil' === modo ) {
    // 7. La card siguiente ASOMA (señal de que hay más).
    const peek = await p.evaluate( () => {
      const track = document.querySelector( '.akb-rw-track' );
      const second = document.querySelectorAll( '.akb-rw-card' )[1].getBoundingClientRect();
      return { visible: Math.max( 0, window.innerWidth - second.left ), trackScrollable: track.scrollWidth > track.clientWidth };
    } );
    check( 'movil-card-siguiente-asoma', peek.visible >= 20 && peek.trackScrollable, `asoman=${peek.visible.toFixed( 0 )}px` );
  }

  await p.locator( '.akb-rw-carousel' ).screenshot( { path: `${OUT}/final-${modo}-seccion.png` } );
  await p.screenshot( { path: `${OUT}/final-${modo}-viewport.png` } );

  // 8. reduced-motion: cero animación, cards visibles al tiro.
  await p.emulateMedia( { reducedMotion: 'reduce' } );
  await p.goto( `${BASE}/?nocache=rm${Date.now()}`, { waitUntil: 'networkidle' } );
  const rmOpacity = await p.evaluate( () => {
    const c = document.querySelector( '.akb-rw-card' );
    return c ? window.getComputedStyle( c ).opacity : 'sin-card';
  } );
  check( `${modo}-reduced-motion-visible`, '1' === rmOpacity, `opacity=${rmOpacity}` );

  await ctx.close();
}

await browser.close();
console.log( `CARRUSEL-FINAL-FALLAS=${fallas}` );
process.exit( fallas > 0 ? 1 : 0 );
