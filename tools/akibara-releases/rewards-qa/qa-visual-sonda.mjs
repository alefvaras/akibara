// Sonda visual de textos corridos — buscador, puntos y reseñas (orden del
// dueño 2026-08-31). Por página × viewport mide:
//   - desborde horizontal de la página (scrollWidth > innerWidth)
//   - elementos con texto que se salen del viewport
//   - texto RECORTADO sin elipsis (scrollWidth > clientWidth con overflow
//     oculto y sin text-overflow) → «texto corrido»
// y guarda screenshot de evidencia.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://localhost:8080';
const OUT = new URL('.', import.meta.url).pathname;
const TOKEN = process.env.AKB_TOKEN || '';
const VIEWPORTS = [
  { name: 'movil', width: 375, height: 812, mobile: true },
  { name: 'escritorio', width: 1440, height: 900, mobile: false },
];

const medir = async ( page ) => page.evaluate( () => {
  const vw = window.innerWidth;
  const desbordePagina = Math.max( 0, document.documentElement.scrollWidth - vw );
  const fueraDeViewport = [];
  const recortados = [];
  const vistos = new Set();
  const enScroller = ( el ) => {
    for ( let a = el.parentElement; a; a = a.parentElement ) {
      const acs = getComputedStyle( a );
      if ( /auto|scroll/.test( acs.overflowX ) ) return true;
      const rol = ( a.getAttribute( 'aria-roledescription' ) || '' ).toLowerCase();
      if ( rol.includes( 'carrusel' ) || rol.includes( 'carousel' ) ) return true;
    }
    return false;
  };
  for ( const el of document.querySelectorAll( 'body *' ) ) {
    const cs = getComputedStyle( el );
    if ( cs.display === 'none' || cs.visibility === 'hidden' ) continue;
    // Exclusiones POR DISEÑO (documentadas): skip-links de accesibilidad
    // (posicionados a -9999), sr-only (cajas de ≤2px) y todo lo que viva
    // dentro de un scroller horizontal o carrusel (slides fuera de vista y
    // tiras scrolleables con ítems parciales como affordance).
    if ( el.classList.contains( 'skip-link' ) ) continue;
    if ( el.clientWidth <= 2 || el.clientHeight <= 2 ) continue;
    if ( enScroller( el ) ) continue;
    // Marquesinas animadas (transform bajo overflow hidden): la tira de
    // editoriales del home mueve sus ítems a través del viewport a propósito.
    if ( el.closest( '.akb-brands-home, [class*="marquee"]' ) ) continue;
    const texto = ( el.childNodes.length && [ ...el.childNodes ].some( n => n.nodeType === 3 && n.textContent.trim() ) )
      ? el.textContent.trim().slice( 0, 60 ) : '';
    const r = el.getBoundingClientRect();
    if ( ! r.width || ! r.height ) continue;
    // 1) Se sale del viewport por la derecha o la izquierda.
    if ( texto && ( r.right > vw + 1 || r.left < -1 ) ) {
      const firma = ( el.className || el.tagName ) + '|' + texto;
      if ( ! vistos.has( firma ) ) {
        vistos.add( firma );
        fueraDeViewport.push( { sel: el.tagName.toLowerCase() + ( el.className && typeof el.className === 'string' ? '.' + el.className.split( ' ' )[0] : '' ), texto, right: Math.round( r.right ), left: Math.round( r.left ) } );
      }
    }
    // 2) Texto recortado sin elipsis.
    if ( texto && el.scrollWidth > el.clientWidth + 2
      && /hidden|clip/.test( cs.overflowX )
      && cs.textOverflow !== 'ellipsis'
      && cs.whiteSpace === 'nowrap' ) {
      recortados.push( { sel: el.tagName.toLowerCase() + ( typeof el.className === 'string' && el.className ? '.' + el.className.split( ' ' )[0] : '' ), texto } );
    }
  }
  return { desbordePagina, fueraDeViewport: fueraDeViewport.slice( 0, 8 ), recortados: recortados.slice( 0, 8 ) };
} );

const run = async () => {
  const browser = await chromium.launch();
  const resultados = [];

  for ( const vp of VIEWPORTS ) {
    const ctx = await browser.newContext( {
      viewport: { width: vp.width, height: vp.height },
      isMobile: vp.mobile,
      hasTouch: vp.mobile,
      deviceScaleFactor: vp.mobile ? 2 : 1,
    } );
    const page = await ctx.newPage();

    const sondear = async ( nombre, prep ) => {
      try {
        await prep( page );
        await page.waitForTimeout( 700 );
        const m = await medir( page );
        await page.screenshot( { path: `${OUT}${nombre}-${vp.name}.png`, fullPage: false } );
        resultados.push( { superficie: nombre, viewport: vp.name, ...m } );
      } catch ( e ) {
        resultados.push( { superficie: nombre, viewport: vp.name, error: String( e ).slice( 0, 160 ) } );
      }
    };

    // ---- PUNTOS: página pública.
    await sondear( 'puntos-pagina', async p => { await p.goto( `${BASE}/akibara-puntos/`, { waitUntil: 'networkidle' } ); } );

    // ---- LOGIN del cliente QA vía wp-login (robusto a overlays del theme).
    try {
      await page.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
      await page.fill( '#user_login', 'qa-visual@akibara.cl', { timeout: 8000 } );
      await page.fill( '#user_pass', 'QaVisual!2026', { timeout: 8000 } );
      await Promise.all( [
        page.waitForNavigation( { waitUntil: 'networkidle' } ).catch( () => {} ),
        page.click( '#wp-submit' ),
      ] );
    } catch ( e ) {
      resultados.push( { superficie: 'login', viewport: vp.name, error: String( e ).slice( 0, 120 ) } );
    }

    // ---- PUNTOS: Mi cuenta → rewards (saldo, didáctico, historial).
    await sondear( 'puntos-micuenta', async p => { await p.goto( `${BASE}/mi-cuenta/rewards/`, { waitUntil: 'networkidle' } ); } );

    // ---- RESEÑAS: formulario por token.
    if ( TOKEN ) {
      await sondear( 'resena-form', async p => { await p.goto( `${BASE}/resena/${TOKEN}/`, { waitUntil: 'networkidle' } ); } );
    }

    // ---- BUSCADOR: abrir la superficie REAL y escribir (suggest vivo).
    await sondear( 'buscador-abierto', async p => {
      await p.goto( `${BASE}/`, { waitUntil: 'networkidle' } );
      if ( vp.mobile ) {
        // La pestaña «Buscar» de la barra inferior es un BUTTON que abre el
        // drawer de búsqueda (bottom-nav.php).
        await p.locator( 'button:has-text("Buscar")' ).last().click( { timeout: 8000 } );
        await p.waitForTimeout( 500 );
      }
      const input = p.locator( vp.mobile ? '#akb-mobile-search-input' : '#akb-search-input' ).first();
      await input.waitFor( { state: 'visible', timeout: 8000 } );
      await input.fill( 'naruto' );
      await p.waitForTimeout( 1500 ); // suggest REST
    } );

    // ---- RESEÑAS: ficha con la reseña aprobada renderizada.
    if ( process.env.AKB_PDP ) {
      await sondear( 'pdp-con-resena', async p => {
        await p.goto( process.env.AKB_PDP, { waitUntil: 'networkidle' } );
        await p.locator( '#reviews, .woocommerce-Reviews, #tab-reviews' ).first().scrollIntoViewIfNeeded().catch( () => {} );
      } );
    }

    // ---- BUSCADOR: página de resultados con y sin resultados.
    await sondear( 'buscador-resultados', async p => { await p.goto( `${BASE}/tienda/?_buscar=naruto`, { waitUntil: 'networkidle' } ); } );
    await sondear( 'buscador-sin-resultados', async p => { await p.goto( `${BASE}/tienda/?_buscar=zzzqueryinexistente`, { waitUntil: 'networkidle' } ); } );

    await ctx.close();
  }

  await browser.close();
  fs.writeFileSync( `${OUT}metricas.json`, JSON.stringify( resultados, null, 2 ) );

  // Resumen legible.
  let fallas = 0;
  for ( const r of resultados ) {
    const problemas = ( r.desbordePagina > 0 ? 1 : 0 ) + ( r.fueraDeViewport?.length || 0 ) + ( r.recortados?.length || 0 ) + ( r.error ? 1 : 0 );
    if ( problemas ) fallas += problemas;
    console.log( `${r.superficie} @${r.viewport}: desborde=${r.desbordePagina ?? '-'}px fuera=${r.fueraDeViewport?.length ?? '-'} recortados=${r.recortados?.length ?? '-'}${r.error ? ' ERROR=' + r.error : ''}` );
  }
  console.log( `TOTAL-PROBLEMAS=${fallas}` );
};

run();
