// AGENTE 2 — DUEÑO CONFIGURANDO (gate qa-rewards, orden del dueño 2026-08-31).
// Emula la operación de la tienda SIN código: el dueño entra a wp-admin,
// cambia montos en WooCommerce → Puntos y Reseñas, guarda con el botón, y
// exige que el cambio se vea en la superficie SERVIDA (/akibara-puntos/ y la
// ficha). Después modera una reseña real desde Comentarios y comprueba que el
// premio se paga UNA vez (y que des-aprobar/re-aprobar no re-paga).
//
// Regla de la casa: se restaura SIEMPRE (finally), aunque un check falle —
// «espejo restaurado tras cada QA». Los valores canónicos se LEEN antes de
// tocar nada y se reponen al final; los literales del checklist solo se usan
// para la verificación final.
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE    = 'http://localhost:8080';
const MAILPIT = 'http://localhost:8025';
const CWD     = '/Users/alefvaras/Documents/akibara';
const OUT     = new URL( '.', import.meta.url ).pathname;
const ADMIN   = { user: 'ale.fvaras', pass: 'QaAdmin!2026' };
const AJUSTES = `${BASE}/wp-admin/admin.php?page=akb-rewards-settings`;
const FICHA   = `${BASE}/product/frieren-10-ivrea-argentina/`;

const CLIENTE  = 244;   // qa-visual@akibara.cl — cliente real con saldo vivo.
const PRODUCTO = 27347; // Atelier of Witch Hat 15 (product/publish).
const PEDIDO   = 27729; // Su pedido acreditado: da el vínculo reseña↔pedido.
const PREMIO   = 500;   // Puntos por reseña con foto aprobada (canónico).

// Options que este agente mueve. Se leen ANTES y se reponen en el finally.
const TOCADAS = [
  'akb_rewards_review_points',
  'akb_rewards_expiry_months',
  'akb_rewards_redeem_min_points',
  'akb_rewards_birthday_enabled',
];

const cli = ( cmd ) => execSync( `docker compose run --rm -T cli ${cmd} --path=/var/www/html`, { cwd: CWD, encoding: 'utf8' } );

const errores_log = () => {
  try {
    return parseInt( execSync(
      `docker compose exec -T wordpress bash -lc 'grep -ci "fatal\\|PHP Warning" /var/www/html/wp-content/debug.log 2>/dev/null || echo 0'`,
      { cwd: CWD, encoding: 'utf8' }
    ).trim(), 10 ) || 0;
  } catch ( e ) { return -1; }
};

const leerOpcion = ( o ) => {
  try { return cli( `wp option get ${o}` ).trim(); } catch ( e ) { return ''; }
};

const reponerOpcion = ( o, original ) => {
  try {
    // Si la option NO existía, se borra: el default del código vuelve a mandar
    // y el espejo queda como estaba (agente-plugins hace lo mismo).
    if ( '' === original ) { cli( `wp option delete ${o}` ); return; }
    cli( `wp option update ${o} ${original}` );
  } catch ( e ) { /* el finally nunca puede tumbar la corrida */ }
};

// Purga del lado WordPress antes de leer el front.
//
// TRAMPA MEDIDA (2026-08-31): NO usar `do_action("litespeed_purge_all")` como
// alternativa — en este local SÍ llega hasta Cloudflare y purga la zona REAL
// de akibara.cl («Avisado correctamente a Cloudflare de la purga completa»).
// Un gate de QA local jamás puede tocar el CDN de producción.
const purgar = () => {
  try { cli( 'wp cache flush' ); } catch ( e ) {}
  try { cli( 'wp litespeed-purge all' ); } catch ( e ) {} // no-op local (admin-ajax no es alcanzable desde el contenedor cli).
};

const servido = ( url ) => {
  try { return execSync( `curl -s ${url}`, { encoding: 'utf8' } ); } catch ( e ) { return ''; }
};

// La página pública SERVIDA tras purgar: la única verdad del reflejo.
const paginaPuntos = () => { purgar(); return servido( `${BASE}/akibara-puntos/` ); };

const saldo = () => {
  try { return parseInt( cli( `wp eval 'echo \\Akibara\\Rewards\\Points\\Ledger::balance(${CLIENTE});'` ).trim(), 10 ); } catch ( e ) { return -1; }
};

const metaUsuario = ( clave ) => {
  try { return cli( `wp user meta get ${CLIENTE} ${clave}` ).trim(); } catch ( e ) { return ''; }
};

// El premio ESCRIBE dos usermeta (saldo y total histórico). Hoy el cliente de
// pruebas tiene saldo pero NO tiene `_akb_rewards_total_earned`: reponerlo con
// un valor dejaría una fila que antes no existía. Ausente ⇒ se borra.
const reponerMeta = ( clave, original ) => {
  try {
    if ( '' === original ) { cli( `wp user meta delete ${CLIENTE} ${clave}` ); return; }
    cli( `wp user meta update ${CLIENTE} ${clave} ${original}` );
  } catch ( e ) {}
};

const correos = async () => {
  try {
    const r = await fetch( `${MAILPIT}/api/v1/messages?limit=200` );
    const j = await r.json();
    return ( j.messages || [] ).map( ( m ) => ( { id: String( m.ID ), asunto: String( m.Subject || '' ) } ) );
  } catch ( e ) { return null; }
};

const run = async () => {
  const antesLog = errores_log();
  const browser  = await chromium.launch();
  const ctx      = await browser.newContext( { viewport: { width: 1440, height: 900 } } );
  const p        = await ctx.newPage();
  let fallas     = 0;

  const check = ( nombre, ok ) => {
    console.log( `${nombre}: ${ok ? 'OK' : 'FALLA'}` );
    if ( ! ok ) fallas++;
    return !! ok;
  };

  const captura = async ( nombre ) => {
    try { await p.screenshot( { path: `${OUT}dueno-${nombre}.png`, fullPage: false } ); } catch ( e ) {}
  };

  // Cada flujo aislado: una excepción cuenta como falla y NO detiene el resto.
  const flujo = async ( nombre, fn ) => {
    const antes = fallas;
    try {
      await fn();
    } catch ( e ) {
      check( `${nombre}-sin-excepcion`, false );
      console.log( `  ↳ ${e && e.message ? e.message : e}` );
    }
    if ( fallas > antes ) await captura( nombre );
  };

  // Estado original de TODO lo que este agente mueve (para el finally).
  const original = {};
  for ( const o of TOCADAS ) original[ o ] = leerOpcion( o );
  const saldoOriginal = metaUsuario( '_akb_rewards_points' );
  const ganadoOriginal = metaUsuario( '_akb_rewards_total_earned' );

  // Rastro del flujo 3 (se limpia en el finally aunque reviente a medias).
  const rastro = { comentario: 0, adjunto: 0, padreAdjunto: 0 };

  // ── Guardado por la PANTALLA, como lo haría el dueño ───────────────────────
  // El form es admin-post y reescribe TODAS las options del catálogo: se envía
  // entero con el botón «Guardar ajustes» (nada de update_option por debajo).
  const abrirAjustes = async () => {
    await p.goto( AJUSTES, { waitUntil: 'networkidle' } );
  };

  const guardarForm = async () => {
    await p.locator( '#submit' ).click();
    await p.waitForLoadState( 'networkidle' );
  };

  const guardarNumero = async ( opcion, valor ) => {
    await abrirAjustes();
    await p.fill( `input[name="${opcion}"]`, String( valor ) );
    await guardarForm();
    return {
      aviso: ( await p.locator( '.notice-success' ).count() ) > 0,
      enPantalla: await p.inputValue( `input[name="${opcion}"]` ).catch( () => '' ),
      enBase: leerOpcion( opcion ),
    };
  };

  const guardarInterruptor = async ( opcion, encendido ) => {
    await abrirAjustes();
    const caja = p.locator( `input[name="${opcion}"]` );
    if ( encendido ) { await caja.check(); } else { await caja.uncheck(); }
    await guardarForm();
    return {
      aviso: ( await p.locator( '.notice-success' ).count() ) > 0,
      enPantalla: await p.locator( `input[name="${opcion}"]` ).isChecked().catch( () => null ),
      enBase: leerOpcion( opcion ),
    };
  };

  try {
    // ── Login de admin ──────────────────────────────────────────────────────
    await p.goto( `${BASE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
    await p.fill( '#user_login', ADMIN.user );
    await p.fill( '#user_pass', ADMIN.pass );
    await p.click( '#wp-submit' );
    // Espera EXPLÍCITA de la sesión, no una carrera (flaky medido: a veces
    // el goto siguiente ganaba y TODO el agente medía la pantalla de login).
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

    await abrirAjustes();
    check( 'admin-pantalla-ajustes', ( await p.locator( 'input[name="akb_rewards_review_points"]' ).count() ) > 0 );

    // ── FLUJO 1 — Configura y verifica el REFLEJO en la superficie servida ───
    await flujo( 'config-reflejo', async () => {
      // 1a. Puntos por reseña 500 → 750 → 500.
      let r = await guardarNumero( 'akb_rewards_review_points', 750 );
      check( 'resena-aviso-guardado', r.aviso );
      check( 'resena-option-750', '750' === r.enBase );
      check( 'resena-front-750', paginaPuntos().includes( '750 puntos por reseña' ) );

      r = await guardarNumero( 'akb_rewards_review_points', PREMIO );
      check( 'resena-restaurada-option', String( PREMIO ) === r.enBase );
      check( 'resena-restaurada-front', paginaPuntos().includes( `${PREMIO} puntos por reseña` ) );

      // 1b. Meses de vida de los puntos 12 → 6 → 12. La frase vive en DOS
      // sitios de la página (tabla + bullet de reglas): las dos salen del
      // shortcode, así que el valor viejo debe desaparecer por completo.
      r = await guardarNumero( 'akb_rewards_expiry_months', 6 );
      check( 'vencimiento-option-6', '6' === r.enBase );
      const conSeis = paginaPuntos();
      check( 'vencimiento-front-6', conSeis.includes( 'duran 6 meses' ) );
      check( 'vencimiento-sin-valor-viejo', ! conSeis.includes( 'duran 12 meses' ) );

      r = await guardarNumero( 'akb_rewards_expiry_months', 12 );
      check( 'vencimiento-restaurado-option', '12' === r.enBase );
      check( 'vencimiento-restaurado-front', paginaPuntos().includes( 'duran 12 meses' ) );

      // 1c. Mínimo de canje 100 → 250 → 100.
      r = await guardarNumero( 'akb_rewards_redeem_min_points', 250 );
      check( 'canje-option-250', '250' === r.enBase );
      check( 'canje-front-250', paginaPuntos().includes( 'parte desde 250' ) );

      r = await guardarNumero( 'akb_rewards_redeem_min_points', 100 );
      check( 'canje-restaurado-option', '100' === r.enBase );
      check( 'canje-restaurado-front', paginaPuntos().includes( 'parte desde 100' ) );
    } );

    // ── FLUJO 2 — Interruptor de UN CLIC (cumpleaños) ────────────────────────
    await flujo( 'interruptor-cumple', async () => {
      let r = await guardarInterruptor( 'akb_rewards_birthday_enabled', false );
      check( 'cumple-apagado-aviso', r.aviso );
      check( 'cumple-apagado-option', 'no' === r.enBase );
      check( 'cumple-apagado-checkbox', false === r.enPantalla );
      check( 'cumple-desaparece-del-front', ! paginaPuntos().includes( 'cumpleaños' ) );

      r = await guardarInterruptor( 'akb_rewards_birthday_enabled', true );
      check( 'cumple-encendido-option', 'yes' === r.enBase );
      const vuelto = paginaPuntos();
      check( 'cumple-vuelve-al-front', vuelto.includes( 'cumpleaños' ) );
      // El monto guardado se conserva al re-encender (no se resetea).
      check( 'cumple-monto-conservado', vuelto.includes( '1.000 puntos cada año' ) );
    } );

    // ── FLUJO 3 — Modera y paga ─────────────────────────────────────────────
    await flujo( 'modera-y-paga', async () => {
      const marca = `QA-DUENO-${Date.now()}`;

      // Adjunto imagen existente: la foto REAL es la compuerta del premio.
      rastro.adjunto = parseInt(
        cli( 'wp post list --post_type=attachment --post_mime_type=image/jpeg --posts_per_page=1 --field=ID' ).trim(),
        10
      ) || 0;
      if ( ! check( 'reseña-adjunto-disponible', rastro.adjunto > 0 ) ) return;

      // Reseña PENDIENTE con el molde EXACTO de ReviewForm::process (mismo
      // commentdata, mismo vínculo al pedido, misma Media::attach_photo).
      // Dos preparaciones explícitas, no atajos del motor:
      //  - se borra cualquier asiento `review` previo del cliente: su
      //    event_key `review_u{user}_p{producto}` es UNIQUE y una corrida
      //    anterior dejaría el crédito como duplicado (saldo intacto);
      //  - se fija `verified=1`, que es lo que Woo escribe en el flujo real
      //    por comment_post; sin él la compuerta de compra verificada corta y
      //    lo que se estaría midiendo no sería la moderación.
      const salida = cli( `wp eval '
$pid = ${PRODUCTO};
$uid = ${CLIENTE};
$adj = ${rastro.adjunto};
$w = $GLOBALS["wpdb"];
$w->query( $w->prepare( "DELETE FROM " . $w->prefix . "akb_rewards_ledger WHERE user_id = %d AND action = %s", $uid, "review" ) );
delete_user_meta( $uid, "_akb_rewards_review_qualifying" );
$u = get_user_by( "id", $uid );
$padre = (int) get_post_field( "post_parent", $adj );
$datos = array(
  "comment_post_ID"      => $pid,
  "comment_author"       => $u->display_name,
  "comment_author_email" => $u->user_email,
  "comment_author_url"   => "",
  "comment_content"      => "${marca} Edicion impecable, papel grueso y traduccion muy cuidada; llego en dos dias.",
  "comment_type"         => "review",
  "comment_parent"       => 0,
  // wp_filter_comment lee comment_author_IP: sin esta clave emite un PHP
  // Warning que ensuciaría el debug.log y tumbaría el propio check de abajo.
  "comment_author_IP"    => "127.0.0.1",
  "user_id"              => $uid,
  "comment_approved"     => 0,
  "comment_meta"         => array( "rating" => 5 ),
);
$cid = (int) wp_insert_comment( wp_filter_comment( $datos ) );
update_comment_meta( $cid, "_akb_rewards_review_order", ${PEDIDO} );
\\Akibara\\Rewards\\Reviews\\Media::attach_photo( $cid, $adj, $pid );
update_comment_meta( $cid, "verified", 1 );
echo "CID=" . $cid . "|PADRE=" . $padre;
'` ).trim();

      const m = /CID=(\d+)\|PADRE=(\d+)/.exec( salida );
      if ( ! check( 'reseña-creada', !! m ) ) { console.log( `  ↳ ${salida}` ); return; }
      rastro.comentario   = parseInt( m[ 1 ], 10 );
      rastro.padreAdjunto = parseInt( m[ 2 ], 10 );

      const estadoInicial = cli( `wp eval 'echo get_comment(${rastro.comentario})->comment_approved;'` ).trim();
      check( 'reseña-nace-pendiente', '0' === estadoInicial );
      check( 'reseña-con-foto', '1' === cli( `wp eval 'echo \\Akibara\\Rewards\\Reviews\\Media::has_real_photo(${rastro.comentario}) ? 1 : 0;'` ).trim() );

      const saldoAntes = saldo();
      check( 'saldo-legible', saldoAntes >= 0 );

      const correosAntes = await correos();
      const idsAntes = new Set( ( correosAntes || [] ).map( ( c ) => c.id ) );

      // El dueño la aprueba DESDE LA UI (fila → enlace Aprobar). Los
      // selectores son estructurales (.approve / .unapprove), no textos:
      // el idioma del admin no puede tumbar el gate.
      // Las reseñas de producto NO viven en Comentarios: WooCommerce las
      // separa en Productos → Valoraciones (product-reviews). Es la pantalla
      // que un dueño real usa para moderar.
      await p.goto( `${BASE}/wp-admin/edit.php?post_type=product&page=product-reviews&comment_status=all&s=${marca}`, { waitUntil: 'networkidle' } );
      const fila = p.locator( `#comment-${rastro.comentario}` );
      if ( ! check( 'reseña-en-comentarios', ( await fila.count() ) > 0 ) ) return;
      check( 'reseña-fila-pendiente', ( await fila.evaluate( ( el ) => el.className ) ).includes( 'unapproved' ) );

      // Las acciones de fila de WP solo se ven con el mouse encima.
      await fila.hover();
      await fila.locator( '.approve a' ).click();
      await p.waitForSelector( `#comment-${rastro.comentario}.approved`, { timeout: 15000 } ).catch( () => {} );

      // El pago viaja por transition_comment_status; se espera al SALDO, que
      // es la verdad (la clase de la fila solo dice que el AJAX volvió).
      let saldoDespues = saldoAntes;
      for ( let i = 0; i < 10 && saldoDespues === saldoAntes; i++ ) {
        await p.waitForTimeout( 1000 );
        saldoDespues = saldo();
      }

      check( 'reseña-aprobada-en-base', '1' === cli( `wp eval 'echo get_comment(${rastro.comentario})->comment_approved;'` ).trim() );
      check( 'premio-paga-500-exactos', saldoDespues === saldoAntes + PREMIO );
      check( 'premio-sellado-en-la-reseña', String( PREMIO ) === cli( `wp eval 'echo get_comment_meta(${rastro.comentario},"_akb_rewards_review_points",true);'` ).trim() );

      // Correo del premio en Mailpit (asunto «[Akibara] Tu reseña sumó N…»).
      let nuevos = [];
      for ( let i = 0; i < 10 && 0 === nuevos.length; i++ ) {
        const ahora = await correos();
        if ( null === ahora ) break;
        nuevos = ahora.filter( ( c ) => ! idsAntes.has( c.id ) && c.asunto.includes( 'reseña' ) );
        if ( 0 === nuevos.length ) await p.waitForTimeout( 1000 );
      }
      if ( null === correosAntes ) {
        check( 'mailpit-alcanzable', false );
      } else {
        check( 'correo-del-premio-en-mailpit', nuevos.length > 0 );
        if ( nuevos.length > 0 ) console.log( `  ↳ asunto: ${nuevos[ 0 ].asunto}` );
      }

      // Des-aprobar y re-aprobar NO puede volver a pagar (sello + event_key).
      await fila.hover();
      await fila.locator( '.unapprove a' ).click();
      await p.waitForSelector( `#comment-${rastro.comentario}.unapproved`, { timeout: 15000 } ).catch( () => {} );
      await p.waitForTimeout( 1000 );
      check( 'desaprobar-no-mueve-saldo', saldo() === saldoDespues );

      // Las acciones de fila de WP solo se ven con el mouse encima.
      await fila.hover();
      await fila.locator( '.approve a' ).click();
      await p.waitForSelector( `#comment-${rastro.comentario}.approved`, { timeout: 15000 } ).catch( () => {} );
      await p.waitForTimeout( 3000 );
      check( 'reaprobar-no-re-paga', saldo() === saldoDespues );
      check( 'reaprobar-sin-segundo-asiento', '1' === cli( `wp eval '$w = $GLOBALS["wpdb"]; echo (int) $w->get_var( $w->prepare( "SELECT COUNT(*) FROM " . $w->prefix . "akb_rewards_ledger WHERE user_id = %d AND action = %s", ${CLIENTE}, "review" ) );'` ).trim() );
    } );

    // ── FLUJO 5 — La página pública, con los cuatro valores canónicos ────────
    await flujo( 'pagina-publica', async () => {
      const html = paginaPuntos();
      check( 'canonico-resena-500', html.includes( `${PREMIO} puntos por reseña` ) );
      check( 'canonico-cumple-1000', html.includes( '1.000 puntos cada año' ) );
      check( 'canonico-vencimiento-12', html.includes( 'duran 12 meses' ) );
      check( 'canonico-canje-100', html.includes( 'parte desde 100' ) );
      check( 'ficha-aviso-puntos', servido( FICHA ).includes( 'Puntos Akibara' ) );
    } );
  } finally {
    // ── Restauración: SIEMPRE, aunque algún check haya fallado ──────────────
    if ( rastro.comentario > 0 ) {
      try { cli( `wp comment delete ${rastro.comentario} --force` ); } catch ( e ) {}
    }
    try {
      cli( `wp eval '$w = $GLOBALS["wpdb"]; $w->query( $w->prepare( "DELETE FROM " . $w->prefix . "akb_rewards_ledger WHERE user_id = %d AND action = %s", ${CLIENTE}, "review" ) );'` );
    } catch ( e ) {}
    try { cli( `wp user meta delete ${CLIENTE} _akb_rewards_review_qualifying` ); } catch ( e ) {}
    reponerMeta( '_akb_rewards_points', saldoOriginal );
    reponerMeta( '_akb_rewards_total_earned', ganadoOriginal );
    // attach_photo reparenta el adjunto al producto: se devuelve a su padre.
    if ( rastro.adjunto > 0 ) {
      try { cli( `wp post update ${rastro.adjunto} --post_parent=${rastro.padreAdjunto}` ); } catch ( e ) {}
    }
    for ( const o of TOCADAS ) reponerOpcion( o, original[ o ] );
    purgar();

    await ctx.close().catch( () => {} );
    await browser.close().catch( () => {} );
  }

  // El espejo quedó como estaba (se comprueba, no se supone).
  check( 'espejo-saldo-repuesto', String( saldoOriginal ) === metaUsuario( '_akb_rewards_points' ) );
  const finalHtml = servido( `${BASE}/akibara-puntos/` );
  check( 'espejo-pagina-canonica', finalHtml.includes( `${PREMIO} puntos por reseña` ) && finalHtml.includes( 'duran 12 meses' ) && finalHtml.includes( 'parte desde 100' ) && finalHtml.includes( '1.000 puntos cada año' ) );

  const despuesLog = errores_log();
  check( 'error-log-sin-crecer', antesLog >= 0 && despuesLog === antesLog );

  console.log( `DUENO-FALLAS=${fallas}` );
  process.exit( fallas > 0 ? 1 : 0 );
};

run();
