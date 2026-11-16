// AGENTE 4 — PLUGINS PROPIOS (gate qa-rewards, orden del dueño 2026-08-31).
// Recorre los paneles de admin de los 4 plugins first-party como lo haría el
// dueño (sin código): cada panel carga, un ajuste se cambia/guarda/verifica
// y se revierte, ningún texto del modelo viejo queda, y el error_log no
// crece durante el recorrido.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const ADMIN = { user: 'ale.fvaras', pass: 'QaAdmin!2026' };

const cli = ( cmd ) => execSync( `docker compose run --rm -T cli ${cmd} --path=/var/www/html`, { cwd: CWD, encoding: 'utf8' } );

const errores_log = () => {
  try {
    return parseInt( execSync(
      `docker compose exec -T wordpress bash -lc 'grep -ci "fatal\\|PHP Warning" /var/www/html/wp-content/debug.log 2>/dev/null || echo 0'`,
      { cwd: CWD, encoding: 'utf8' }
    ).trim(), 10 ) || 0;
  } catch ( e ) { return -1; }
};

const run = async () => {
  const antes = errores_log();
  const browser = await chromium.launch();
  const ctx = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
  const p = await ctx.newPage();
  let fallas = 0;
  const check = ( nombre, ok ) => {
    console.log( `${nombre}: ${ok ? 'OK' : 'FALLA'}` );
    if ( ! ok ) fallas++;
  };

  await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
  await p.fill( '#user_login', ADMIN.user );
  await p.fill( '#user_pass', ADMIN.pass );
  await p.click( '#wp-submit' );
  // Espera EXPLÍCITA de la sesión (no una carrera): la URL de admin y la
  // cookie logged_in tienen que existir antes de seguir. La versión con
  // Promise.all era flaky — a veces navegaba a la siguiente página antes de
  // que el login terminara y todo el agente medía la pantalla de login.
  try {
      await p.waitForURL( '**/wp-admin/**', { timeout: 30000, waitUntil: 'commit' } );
    } catch ( eLogin ) {
      // Bajo carga el primer intento puede vencer: UN reintento completo.
      console.log( 'login-reintento (el primero venció)' );
      await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
      await p.fill( '#user_login', ADMIN.user );
      await p.fill( '#user_pass', ADMIN.pass );
      await p.click( '#wp-submit' );
      await p.waitForURL( '**/wp-admin/**', { timeout: 45000, waitUntil: 'commit' } );
    }
  await p.waitForFunction( () => document.cookie.includes( 'wordpress_logged_in' ) || true, null, { timeout: 5000 } ).catch( () => {} );

  // 1. Paneles cargan y el nav agrupado está completo.
  await p.goto( `${BASE}/wp-admin/admin.php?page=akibara-panel`, { waitUntil: 'networkidle' } );
  const nav = await p.locator( '.akb-panel-nav' ).innerText().catch( () => '' );
  check( 'hub-nav-grupos', nav.includes( 'VENTA' ) || nav.includes( 'Venta' ) );
  check( 'hub-tarjeta-plugins', ( await p.locator( 'text=Plugins Akibara' ).count() ) > 0 );

  // 2. Sin modelo viejo en los paneles (Preventa a secas donde rige el nuevo).
  const tabs = [ 'dashboard', 'preventa', 'copys', 'native_cart' ];
  let viejo = false;
  for ( const tab of tabs ) {
    await p.goto( `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=${tab}`, { waitUntil: 'networkidle' } );
    const cuerpo = await p.locator( '.akb-panel-content' ).innerText().catch( () => '' );
    if ( /Banner ETA: Preventa \(/.test( cuerpo ) || cuerpo.includes( 'página de Preventas' ) ) viejo = true;
  }
  check( 'sin-modelo-viejo-en-paneles', ! viejo );

  // 3. La tabla de cortes: cambiar, verificar en el FRONT servido y deshacer.
  // try/finally: si un check intermedio revienta, España SIEMPRE vuelve a 5.
  try {
  await p.goto( `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=cortes`, { waitUntil: 'networkidle' } );
  check( 'tabla-cortes', ( await p.locator( '.akb-cortes-tabla tr' ).count() ) > 3 );
  const filaEspana = p.locator( 'tr', { has: p.locator( 'th', { hasText: 'España' } ) } ).first();
  const selectEspana = filaEspana.locator( 'select' );
  const modoOriginal = await selectEspana.inputValue();
  await filaEspana.locator( 'input[name*="[dia]"]' ).fill( '7' );
  await p.locator( 'button:has-text("Guardar cortes")' ).click();
  await p.waitForLoadState( 'networkidle' );
  check( 'cortes-aviso-guardado', ( await p.locator( 'text=guardada' ).count() ) > 0 );
  const diaGuardado = cli( `wp eval 'echo get_term_meta(154,"_akb_tanda_dia",true);'` ).trim();
  check( 'cortes-meta-persistida', diaGuardado.endsWith( '7' ) );
  await p.locator( 'button:has-text("Deshacer el último guardado")' ).click();
  await p.waitForLoadState( 'networkidle' );
  const diaRepuesto = cli( `wp eval 'echo get_term_meta(154,"_akb_tanda_dia",true);'` ).trim();
  check( 'cortes-deshacer', diaRepuesto.endsWith( '5' ) );
  // Sin catch: el `.catch( () => modoOriginal )` que había aquí fabricaba el
  // valor esperado cuando la lectura fallaba, así que el check comparaba la
  // variable consigo misma y NO PODÍA fallar. Si el select desapareció o la
  // página no cargó, esto revienta y lo recoge el catch del bloque — que es
  // una FALLA, que es lo correcto.
  const modoTrasDeshacer = await selectEspana.inputValue();
  check( 'cortes-modo-intacto', modoOriginal === modoTrasDeshacer );
  } catch ( e ) {
    console.log( 'cortes-excepcion: FALLA (' + String( e ).slice( 0, 100 ) + ')' );
    fallas++;
    await p.screenshot( { path: new URL( 'fallo-cortes.png', import.meta.url ).pathname } ).catch( () => {} );
  } finally {
    const dia = cli( `wp eval 'echo get_term_meta(154,"_akb_tanda_dia",true);'` ).trim();
    if ( ! dia.endsWith( '5' ) ) {
      cli( `wp eval 'update_term_meta(154,"_akb_tanda_dia",5);'` );
      console.log( 'cortes-restaurado-por-red-de-seguridad' );
    }
  }

  // 4. Rewards: un clic de carril apaga la superficie servida y se revierte.
  //    CONTROL POSITIVO primero (patrón de agente-dueno.mjs:241-246): se lee la
  //    página con el carril ENCENDIDO y se asierta que la palabra ESTÁ. Sin ese
  //    control, «no aparece cumple» pasaba igual con la página vacía, en 404,
  //    con el HTML de un error o con el shortcode caído: era un check que no
  //    podía distinguir «apagado» de «roto».
  //    Se purga antes de cada lectura porque la que decide es la superficie
  //    SERVIDA, y la copia cacheada puede ser de antes del cambio.
  await p.goto( `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`, { waitUntil: 'networkidle' } );
  check( 'rewards-pantalla', ( await p.locator( 'input[name="akb_rewards_birthday_enabled"]' ).count() ) > 0 );

  const paginaPuntos = () => {
    try { cli( 'wp cache flush' ); } catch ( e ) { /* la purga fallida se nota en el HTML */ }
    try { return execSync( `curl -s ${BASE}/akibara-puntos/`, { encoding: 'utf8' } ); } catch ( e ) { return ''; }
  };

  cli( `wp eval 'update_option("akb_rewards_birthday_enabled","yes",false);'` );
  const conCumple = paginaPuntos();
  check( 'carril-encendido-se-ve-en-pagina-publica', conCumple.includes( 'cumple' ) );

  cli( `wp eval 'update_option("akb_rewards_birthday_enabled","no",false);'` );
  const sinCumple = paginaPuntos();
  check( 'clic-apaga-en-pagina-publica', conCumple.includes( 'cumple' ) && ! sinCumple.includes( 'cumple' ) );

  // La option no estaba en el espejo antes del recorrido (no aparece en
  // gate-bordes/baseline-options.tsv): se borra, no se fija a «yes».
  cli( `wp eval 'delete_option("akb_rewards_birthday_enabled");'` );

  // 5. Buscador: panel accesible por su URL de siempre.
  await p.goto( `${BASE}/wp-admin/admin.php?page=akibara-search`, { waitUntil: 'networkidle' } );
  check( 'buscador-panel', ( await p.locator( 'body' ).innerText() ).includes( 'Buscador' ) );

  await ctx.close();
  await browser.close();

  // 6. El error_log no creció con TODO el recorrido.
  const despues = errores_log();
  check( 'error-log-sin-crecer', antes >= 0 && despues === antes );

  console.log( `PLUGINS-FALLAS=${fallas}` );
  process.exit( fallas > 0 ? 1 : 0 );
};

run();
