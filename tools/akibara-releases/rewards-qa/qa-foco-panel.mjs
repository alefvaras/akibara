// qa-foco-panel.mjs — barrido exhaustivo del panel: por cada tab y CADA
// control enfocable, enfoca (lo que hace el UA al tabular) y mide si alguna
// barra sticky pegada lo tapa. Nació en la sesión panel-sticky (2026-09-11,
// asunto 2026-09-11-doble-barra-sticky-arma-tu-pack) y se guarda aquí para que
// QA pueda re-medir: debe imprimir «tabs con fallo=0» en 1440x1000, 1920x1080,
// 768x1024, 375x812 y 360x640.
//
// Uso: node qa-foco-panel.mjs [ancho] [alto]   (AKB_BASE, AKB_ADMIN_USER, AKB_ADMIN_PASS)
import { chromium } from 'playwright';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const ADMIN = { user: process.env.AKB_ADMIN_USER || 'ale.fvaras', pass: process.env.AKB_ADMIN_PASS || 'QaAdmin!2026' }; // credenciales del ESPEJO Docker, nunca de producción
const W = Number( process.argv[2] ?? 1440 );
const H = Number( process.argv[3] ?? 1000 );
const PAD = 84; // scroll-padding-bottom declarado en admin-panel.css

const browser = await chromium.launch();
const p = await ( await browser.newContext( { viewport: { width: W, height: H } } ) ).newPage();

for ( let i = 0; i < 2; i++ ) {
  await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
  await p.fill( '#user_login', ADMIN.user );
  await p.fill( '#user_pass', ADMIN.pass );
  await p.click( '#wp-submit' );
  const ok = await p.waitForURL( /wp-admin|confirm_admin_email/, { timeout: 15000 } ).then( () => true, () => false );
  if ( ok ) break;
  if ( i === 1 ) throw new Error( 'login QA falló dos veces' );
}
if ( p.url().includes( 'confirm_admin_email' ) ) {
  await p.click( '#correct-admin-email' );
  await p.waitForURL( /wp-admin/, { timeout: 15000 } );
}

await p.goto( `${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'domcontentloaded' } );
const tabs = await p.$$eval( '.akb-panel-nav__link', ( els ) =>
  els.map( ( a ) => ( { label: a.textContent.trim(), href: a.href } ) )
);
console.log( `Viewport ${W}x${H} · scroll-padding declarado ${PAD}px · tabs ${tabs.length}` );

let tabsConFallo = 0, peorGlobal = 0, barraMasAlta = 0, tabBarraAlta = '';
for ( const tab of tabs ) {
  await p.goto( tab.href, { waitUntil: 'domcontentloaded' } );
  await p.evaluate( () => document.querySelectorAll( 'details:not([open])' ).forEach( ( d ) => { d.open = true; } ) );

  const r = await p.evaluate( () => {
    const visible = ( el ) => {
      if ( el.offsetParent === null ) return false;
      for ( let a = el; a; a = a.parentElement ) if ( a.tagName === 'DETAILS' && ! a.open ) return false;
      return true;
    };
    const alturas = [ ...document.querySelectorAll( '.akb-panel .submit' ) ]
      .filter( ( s ) => getComputedStyle( s ).position === 'sticky' )
      .map( ( s ) => Math.round( s.getBoundingClientRect().height ) );

    const controles = [ ...document.querySelectorAll(
      '.akb-panel-content input:not([type=hidden]), .akb-panel-content textarea, .akb-panel-content select, .akb-panel-content a[href], .akb-panel-content button'
    ) ].filter( ( c ) => visible( c ) && ! c.closest( '.submit' )
      // Un control deshabilitado no se tabula ni recibe foco: medir su caja
      // tras un focus() que no hizo nada da falsos positivos (el botón
      // «Todo al día — sin pendientes» de Recomendaciones lleva disabled()).
      && ! c.disabled && c.getAttribute( 'aria-disabled' ) !== 'true'
      && c.tabIndex >= 0 );

    const fallos = [];
    let peor = 0;
    for ( const c of controles ) {
      c.focus();
      const rc = c.getBoundingClientRect();
      if ( rc.height === 0 ) continue;
      const barras = [ ...document.querySelectorAll( '.akb-panel .submit' ) ]
        .map( ( s ) => s.getBoundingClientRect() )
        .filter( ( rs ) => rs.height > 0 && rs.bottom <= window.innerHeight + 1 && rs.top < window.innerHeight );
      let solape = 0;
      for ( const rs of barras ) solape = Math.max( solape, Math.min( rc.bottom, rs.bottom ) - Math.max( rc.top, rs.top ) );
      solape = Math.round( Math.max( 0, solape ) );
      // Además: ¿queda el control fuera del viewport por abajo?
      const fueraAbajo = Math.round( Math.max( 0, rc.bottom - window.innerHeight ) );
      if ( solape > 0 || fueraAbajo > 0 ) {
        fallos.push( {
          control: ( c.id || c.name || c.getAttribute( 'aria-label' ) || c.textContent || c.tagName ).toString().trim().replace( /\s+/g, ' ' ).slice( 0, 40 ),
          solape, fueraAbajo,
        } );
      }
      peor = Math.max( peor, solape, fueraAbajo );
    }
    const padReal = Math.round( parseFloat( getComputedStyle( document.documentElement ).scrollPaddingBottom ) || 0 );
    return { alturas, padReal, total: controles.length, fallos: fallos.slice( 0, 6 ), nFallos: fallos.length, peor };
  } );

  const alto = r.alturas.length ? Math.max( ...r.alturas ) : 0;
  if ( alto > barraMasAlta ) { barraMasAlta = alto; tabBarraAlta = tab.label; }
  peorGlobal = Math.max( peorGlobal, r.peor );
  const mal = r.nFallos > 0;
  if ( mal ) tabsConFallo++;
  const alertaAltura = alto > r.padReal ? ` ← BARRA(${alto}) > PADDING-REAL(${r.padReal})` : '';
  console.log( `${tab.label} · ${r.total} controles · barras=[${r.alturas.join( ',' ) || '—'}] pad=${r.padReal}${alertaAltura} · fallos=${r.nFallos}${mal ? ' ← ' + r.fallos.map( ( f ) => `«${f.control}» solape=${f.solape} fuera=${f.fueraAbajo}` ).join( ' · ' ) : ''}` );
}
console.log( `\nRESUMEN ${W}x${H}: tabs con fallo=${tabsConFallo} · peor medida=${peorGlobal}px · barra más alta=${barraMasAlta}px (${tabBarraAlta}) vs padding ${PAD}px` );
await browser.close();
