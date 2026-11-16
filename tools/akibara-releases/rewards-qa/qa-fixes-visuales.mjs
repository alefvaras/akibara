// Re-verificación de los 3 fixes del QA visual 2026-09-01:
// (1) order-again muerto también en el DETALLE del pedido,
// (2) código de tracking Blue visible en móvil,
// (3) tira de pestañas de mi-cuenta aterriza al INICIO en móvil.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const OUT = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/qa-visual-tandas/refix';
mkdirSync( OUT, { recursive: true } );

const shq = ( x ) => `'` + x.replace( /'/g, `'\\''` ) + `'`;
const cli = ( code ) => execSync(
  `docker compose run --rm -T cli wp eval ${shq( code )} --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8' }
).trim();

let fallas = 0;
const check = ( n, ok, extra = '' ) => {
  console.log( `${n}: ${ok ? 'OK' : 'FALLA'}${extra ? ' [' + extra + ']' : ''}` );
  if ( ! ok ) fallas++;
};

// Login del cliente 229 con swap de hash REVERSIBLE (patrón del QA previo).
// El hash phpass lleva $: viaja por FICHERO montado, jamás por el shell.
const HASH = cli( `global $wpdb; echo $wpdb->get_var("SELECT user_pass FROM {$wpdb->users} WHERE ID=229");` );
const HASH_FILE = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/hash-229.txt';
const RESTORE_PHP = '/private/tmp/claude-501/-Users-alefvaras-Documents-akibara/d5a6502d-96ec-4b2f-bfd8-ffb8f9cd378c/scratchpad/restaura-229.php';
writeFileSync( HASH_FILE, HASH );
writeFileSync( RESTORE_PHP, `<?php
global $wpdb;
$hash = trim( (string) file_get_contents( '/tmp/hash-229.txt' ) );
$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => 229 ) );
delete_user_meta( 229, 'session_tokens' );
wp_cache_delete( 229, 'users' );
wp_cache_delete( 229, 'user_meta' );
echo $wpdb->get_var( "SELECT user_pass FROM {$wpdb->users} WHERE ID=229" ) === $hash ? 'IDENTICO' : 'DIFIERE';
` );
const restaurar = () => execSync(
  `docker compose run --rm -T -v ${HASH_FILE}:/tmp/hash-229.txt -v ${RESTORE_PHP}:/tmp/restaura-229.php cli wp eval-file /tmp/restaura-229.php --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8' }
).trim();
cli( `wp_set_password( "QaReFix!2026", 229 );` );

const browser = await chromium.launch();
try {
  const ctx = await browser.newContext( { viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true } );
  const p = await ctx.newPage();

  await p.goto( `${BASE}/mi-cuenta/`, { waitUntil: 'domcontentloaded' } );
  // El tema pliega acceso/registro en móvil: destapar el panel de acceso si
  // hay conmutador; si el campo sigue oculto, envío nativo del form (el
  // login es PRE-requisito, no la superficie bajo prueba).
  const tab = p.locator( 'text=/Iniciar sesión|Acceder|Ya tengo cuenta/i' ).first();
  if ( await tab.count() ) { await tab.click().catch( () => {} ); await p.waitForTimeout( 400 ); }
  const user = p.locator( '#username' );
  try {
    await user.scrollIntoViewIfNeeded();
    await user.fill( 'miaucraaft@gmail.com', { timeout: 5000 } );
    await p.fill( '#password', 'QaReFix!2026' );
    await p.click( 'button[name="login"]' );
  } catch ( e ) {
    await p.evaluate( () => {
      document.querySelector( '#username' ).value = 'miaucraaft@gmail.com';
      document.querySelector( '#password' ).value = 'QaReFix!2026';
      document.querySelector( '#username' ).closest( 'form' ).submit();
    } );
  }
  await p.waitForTimeout( 3500 );

  // (3) La tira de pestañas aterriza al inicio.
  await p.goto( `${BASE}/mi-cuenta/?n=${Date.now()}`, { waitUntil: 'networkidle' } );
  const tabs = await p.evaluate( () => {
    const ul = document.querySelector( '.woocommerce-MyAccount-navigation ul' );
    const links = [ ...ul.querySelectorAll( 'a' ) ].map( ( a ) => ( { t: a.textContent.trim(), x: a.getBoundingClientRect().left } ) );
    return { scrollLeft: ul.scrollLeft, visibles: links.filter( ( l ) => l.x >= 0 && l.x < 375 ).map( ( l ) => l.t ) };
  } );
  check( 'tabs-aterrizan-al-inicio', tabs.scrollLeft < 40, `scrollLeft=${tabs.scrollLeft} visibles=${tabs.visibles.join( '/' )}` );
  check( 'pedidos-visible-al-aterrizar', tabs.visibles.some( ( t ) => /Pedidos|Resumen/i.test( t ) ), tabs.visibles.join( '/' ) );
  await p.screenshot( { path: `${OUT}/fix3-tabs-movil.png` } );

  // (1)+(2) El detalle del pedido 27448.
  await p.goto( `${BASE}/mi-cuenta/view-order/27448/?n=${Date.now()}`, { waitUntil: 'networkidle' } );
  const detalle = await p.evaluate( () => {
    const again = document.querySelector( '.order-again, a[href*="order_again"]' );
    const th = [ ...document.querySelectorAll( '.wc-correios-tracking__table th' ) ]
      .map( ( el ) => ( { txt: el.textContent.trim(), vis: getComputedStyle( el ).display !== 'none' && el.getBoundingClientRect().height > 0 } ) );
    return { again: again ? getComputedStyle( again ).display : 'ausente', th };
  } );
  check( 'order-again-muerto-en-detalle', 'ausente' === detalle.again, `estado=${detalle.again}` );
  const codigo = detalle.th.find( ( t ) => /^\d{6,}$/.test( t.txt ) );
  check( 'tracking-visible-movil', !! codigo && codigo.vis, codigo ? `${codigo.txt} vis=${codigo.vis}` : 'sin th numérico' );
  await p.screenshot( { path: `${OUT}/fix1-2-detalle-movil.png`, fullPage: false } );
  const tabla = p.locator( '.wc-correios-tracking__table' ).first();
  if ( await tabla.count() ) {
    await tabla.scrollIntoViewIfNeeded();
    await p.screenshot( { path: `${OUT}/fix2-tracking-movil.png` } );
  }
  await ctx.close();
} finally {
  await browser.close();
  const resultado = restaurar();
  check( 'hash-restaurado-identico', resultado.endsWith( 'IDENTICO' ), resultado.slice( -60 ) );
}

console.log( `REFIX-FALLAS=${fallas}` );
process.exit( fallas > 0 ? 1 : 0 );
