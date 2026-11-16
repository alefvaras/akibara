// QA vivo del ajuste manual de puntos: login real, formulario real, submit
// real por admin-post, verificación del ledger y REVERSIÓN completa.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const ADMIN = { user: 'ale.fvaras', pass: 'QaAdmin!2026' };
const CLIENTE = { id: 241, email: 'alii.ulloa143@gmail.com' };

// Comillas SIMPLES de shell: sin interpolación — $wpdb sobrevive al sh.
const shq = ( str ) => `'` + str.replace( /'/g, `'\\''` ) + `'`;
const cli = ( code ) => execSync(
  `docker compose run --rm -T cli wp eval ${shq( code )} --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8' }
).trim();

let fallas = 0;
const check = ( nombre, ok, extra = '' ) => {
  console.log( `${nombre}: ${ok ? 'OK' : 'FALLA'}${extra ? ' [' + extra + ']' : ''}` );
  if ( ! ok ) fallas++;
};

const browser = await chromium.launch();
const p = await ( await browser.newContext( { viewport: { width: 1440, height: 900 } } ) ).newPage();

// Login sin carrera (patrón sellado del gate).
await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await p.fill( '#user_login', ADMIN.user );
await p.fill( '#user_pass', ADMIN.pass );
await p.click( '#wp-submit' );
try {
  await p.waitForURL( '**/wp-admin/**', { timeout: 30000, waitUntil: 'commit' } );
} catch ( e ) {
  await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
  await p.fill( '#user_login', ADMIN.user );
  await p.fill( '#user_pass', ADMIN.pass );
  await p.click( '#wp-submit' );
  await p.waitForURL( '**/wp-admin/**', { timeout: 45000, waitUntil: 'commit' } );
}

const saldo0 = parseInt( cli( `echo (int) get_user_meta(${CLIENTE.id},"_akb_rewards_points",true);` ), 10 );

try {
  // 1. La sección existe en la pantalla de ajustes.
  await p.goto( `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`, { waitUntil: 'networkidle' } );
  check( 'seccion-visible', ( await p.locator( 'h2:has-text("Otorgar o quitar puntos a mano")' ).count() ) === 1 );
  check( 'campos-presentes', ( await p.locator( '#akb_adj_email' ).count() ) === 1 && ( await p.locator( '#akb_adj_motivo' ).count() ) === 1 );

  // 2. Otorgar 250 con motivo (flujo story de IG) — submit REAL.
  await p.fill( '#akb_adj_email', CLIENTE.email );
  await p.selectOption( '#akb_adj_signo', 'mas' );
  await p.fill( '#akb_adj_points', '250' );
  await p.fill( '#akb_adj_motivo', 'QA ajuste manual — story IG simulada, se revierte' );
  await p.click( 'button:has-text("Aplicar movimiento")' );
  await p.waitForURL( '**page=akb-rewards-settings**', { timeout: 30000 } );
  await p.waitForLoadState( 'networkidle' );
  const aviso1 = await p.locator( '.notice-success' ).innerText().catch( () => '' );
  check( 'aviso-otorgados', aviso1.includes( 'Otorgados' ) && aviso1.includes( '250' ), aviso1.slice( 0, 80 ) );
  const saldo1 = parseInt( cli( `echo (int) get_user_meta(${CLIENTE.id},"_akb_rewards_points",true);` ), 10 );
  check( 'saldo-sube-250', saldo1 === saldo0 + 250, `${saldo0}→${saldo1}` );
  const fila = cli( `global $wpdb; $r=$wpdb->get_row("SELECT action,delta,note FROM {$wpdb->prefix}akb_rewards_ledger WHERE user_id=${CLIENTE.id} ORDER BY id DESC LIMIT 1",ARRAY_A); echo $r["action"]."|".$r["delta"]."|".$r["note"];` );
  check( 'ledger-auditado', fila.startsWith( 'admin_adjust|250|' ) && fila.includes( 'por ale.fvaras' ) && fila.includes( 'story IG' ), fila.slice( 0, 110 ) );

  // 3. Motivo vacío rechazado por el servidor (required es solo del navegador).
  //    Se envía el form sin motivo saltando la validación HTML.
  await p.evaluate( () => { document.getElementById( 'akb_adj_motivo' ).removeAttribute( 'required' ); } );
  await p.fill( '#akb_adj_email', 'alii.ulloa143@gmail.com' );
  await p.fill( '#akb_adj_points', '100' );
  await p.fill( '#akb_adj_motivo', '' );
  await p.click( 'button:has-text("Aplicar movimiento")' );
  await p.waitForURL( '**page=akb-rewards-settings**', { timeout: 30000 } );
  await p.waitForLoadState( 'networkidle' );
  const avisoErr = await p.locator( '.notice-error' ).innerText().catch( () => '' );
  check( 'motivo-obligatorio-servidor', avisoErr.includes( 'motivo' ), avisoErr.slice( 0, 80 ) );
  const saldoTrasErr = parseInt( cli( `echo (int) get_user_meta(${CLIENTE.id},"_akb_rewards_points",true);` ), 10 );
  check( 'sin-movimiento-en-error', saldoTrasErr === saldo0 + 250 );

  // 4. Quitar 9999: clampa al saldo, jamás negativo.
  await p.fill( '#akb_adj_email', CLIENTE.email );
  await p.selectOption( '#akb_adj_signo', 'menos' );
  await p.fill( '#akb_adj_points', '9999' );
  await p.fill( '#akb_adj_motivo', 'QA reversión del ajuste manual' );
  await p.click( 'button:has-text("Aplicar movimiento")' );
  await p.waitForURL( '**page=akb-rewards-settings**', { timeout: 30000 } );
  await p.waitForLoadState( 'networkidle' );
  const aviso2 = await p.locator( '.notice-success' ).innerText().catch( () => '' );
  check( 'aviso-quitados-clampado', aviso2.includes( 'Quitados' ), aviso2.slice( 0, 80 ) );
  const saldo2 = parseInt( cli( `echo (int) get_user_meta(${CLIENTE.id},"_akb_rewards_points",true);` ), 10 );
  check( 'clamp-a-cero', saldo2 === 0, `saldo=${saldo2}` );
} finally {
  await browser.close();
  // Red de seguridad del espejo: saldo original y CERO residuo de QA en el ledger.
  cli( `global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}akb_rewards_ledger WHERE user_id=${CLIENTE.id} AND action='admin_adjust' AND note LIKE '%QA %'"); update_user_meta(${CLIENTE.id},"_akb_rewards_points",${saldo0}); echo "restaurado";` );
  const residuo = cli( `global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}akb_rewards_ledger WHERE action='admin_adjust'");` );
  const saldoFin = cli( `echo (int) get_user_meta(${CLIENTE.id},"_akb_rewards_points",true);` );
  check( 'espejo-restaurado', residuo === '0' && parseInt( saldoFin, 10 ) === saldo0, `residuo=${residuo} saldo=${saldoFin}` );
}

// 5. Los correos del ajuste quedaron en Mailpit (no salieron al mundo).
try {
  const mp = JSON.parse( execSync( `curl -s ${'http://localhost:8025'}/api/v1/search?query=to:%22${CLIENTE.email}%22`, { encoding: 'utf8' } ) );
  const asuntos = ( mp.messages || [] ).map( ( m ) => m.Subject ).slice( 0, 4 );
  check( 'correo-en-mailpit', asuntos.some( ( s ) => /Puntos Akibara/.test( s ) ), asuntos.join( ' // ' ).slice( 0, 120 ) );
} catch ( e ) {
  console.log( 'correo-en-mailpit: SKIP (' + String( e ).slice( 0, 60 ) + ')' );
}

console.log( `AJUSTE-FALLAS=${fallas}` );
process.exit( fallas > 0 ? 1 : 0 );
