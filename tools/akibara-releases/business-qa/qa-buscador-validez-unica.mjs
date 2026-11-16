// QA del ESPEJO — el guardado y el lector del buscador usan UNA sola noción de
// validez, y lo roto se repara desde la pantalla (search 0.1.63).
//
//   V1. Con todo sano, un valor fuera de dominio NO entra (ni por WP-CLI ni por
//       una pantalla de WooCommerce) y el buscador no se cae.
//   V2. Un valor imposible sembrado por SQL tumba la foto de precios, y
//       corregirlo desde el panel se acepta y la repara (S-4d0e7c11).
//   V3. Con una reconstrucción en curso, un cambio de precios se rechaza por
//       «ocupado» y no se cuela sin cerco.
//   V4. Guardar Ajustes → Generales sin cambiar nada de precios no mueve la
//       revisión editorial ni pide reconstrucción.
//   V5. Borrar una option que el lector exige (el contrato del feed) se veta y
//       la fila sigue ahí, también para la petición siguiente (M-3).
//   V6. Un marco de escritura que Core no llega a cerrar no deja el servicio
//       cerrado: vuelve solo, sin reconstrucción (M-1).
//
// Deja el espejo como estaba. Uso: node qa-buscador-validez-unica.mjs
import { execSync, spawn } from 'node:child_process';

const CWD = '/Users/alefvaras/Documents/akibara';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const VIGILADAS = [
  'timezone_string', 'gmt_offset', 'woocommerce_price_num_decimals',
  'akibara_taxonomy_discount_enabled', 'akibara_taxonomy_discount_apply_to',
  'akibara_taxonomy_discount_from', 'akibara_taxonomy_discount_to',
  'akibara_taxonomy_discount_sale_policy', 'akb_discount_max_pct',
  'akibara_business_search_feed_contract_version',
];

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};

// LiteSpeed imprime «Success: Purgar…» pegado al JSON: se recorta el primer objeto equilibrado.
const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const i = salida.indexOf('{');
  if (i < 0) throw new Error(`sin JSON: ${salida.slice(0, 300)}`);
  let nivel = 0, texto = false, escape = false;
  for (let j = i; j < salida.length; j += 1) {
    const c = salida[j];
    if (escape) { escape = false; continue; }
    if (c === '\\') { escape = true; continue; }
    if (c === '"') { texto = !texto; continue; }
    if (texto) continue;
    if (c === '{') nivel += 1;
    if (c === '}') { nivel -= 1; if (nivel === 0) return JSON.parse(salida.slice(i, j + 1)); }
  }
  throw new Error('JSON incompleto');
};
const cli = (args) => {
  try { return { rc: 0, salida: execSync(`docker compose exec -T cron wp ${args} 2>&1`, { cwd: CWD, encoding: 'utf8' }) }; }
  catch (e) { return { rc: e.status ?? 1, salida: String(e.stdout || '') + String(e.stderr || '') }; }
};
// El suggest limita a 60/min por IP ANTES de nada salvo el gate: un 429 no es
// «caído». Se guarda el último código para que el rojo diga cuál fue.
let ultimoSuggest = 0;
const suggest = () => (ultimoSuggest = Number(execSync(`curl -s -o /dev/null -w '%{http_code}' -A AkibaraQA '${BASE}/wp-json/akibara/v1/search/suggest?q=naruto'`, { encoding: 'utf8' })));
const filas = () => wp(`
  global $wpdb; $out = array();
  foreach ( ${JSON.stringify(VIGILADAS)} as $o ) {
    $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o ) );
    $out[ $o ] = null === $v ? null : (string) $v;
  }
  echo wp_json_encode( array( 'filas' => $out ) );
`).filas;
const estado = () => wp(`
  wp_cache_flush();
  $s = (array) get_option( 'akb_search_rebuild_state', array() );
  $e = \\Akibara\\Search\\EditorialDiscountPublication::status();
  $h = \\Akibara\\Search\\HealthReport::collect();
  echo wp_json_encode( array(
    'seq'       => (int) ( $e['wanted_seq'] ?? 0 ),
    'sirve'     => (bool) \\Akibara\\Search\\EditorialDiscountPublication::serving_ready(),
    'fase'      => (string) ( $s['phase'] ?? '' ),
    'flags'     => $h['flags'] ?? null,
    'pedido'    => (string) get_option( 'akb_search_rebuild_request', '' ),
    'vivas'     => (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}akb_search_work WHERE scope = 'live' AND status IN ('pending','leased')" ),
  ) );
`);
const comoAdmin = (codigo) => wp(`
  $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
  wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
  ${codigo}
`);
const avisos = () => wp(`
  $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
  $uid = (int) ( $admins[0] ?? 1 );
  $t = get_transient( 'akb_search_rechazo_' . $uid );
  echo wp_json_encode( array( 'avisos' => is_array( $t ) ? array_values( $t ) : array() ) );
`).avisos;
const auditoria = (n = 5) => wp(`
  wp_cache_flush();
  $filas = array_slice( \\Akibara\\Search\\AuditLog::entries(), 0, ${n} );
  echo wp_json_encode( array( 'log' => array_values( (array) $filas ) ) );
`).log;

// Publicar un cambio de precios es asíncrono: la reconstrucción corre en la
// cola y hasta que sella su recibo el gate de servido sigue cerrado. Medir
// antes de eso da falsos rojos (y deja el espejo «caído» a ojos del QA).
// «Sirve» se mide como lo ve el cliente: el gate de la foto Y el autocompletado
// respondiendo 200. La ruta se registra por petición, así que durante un
// re-sellado una petición puede ver el gate abierto y la siguiente todavía no.
// Y un cambio de precios marca todo el catálogo como deuda viva: mientras esa
// cola drena, cada producto publica su generación y el suggest alterna 200/503
// (deuda previa, medida 2026-09-14). Se espera a que la cola viva quede vacía.
const esperarServicio = async (segundos = 180, { drenaje = true } = {}) => {
  const hasta = Date.now() + segundos * 1000;
  while (Date.now() < hasta) {
    const e = estado();
    if (e.sirve && (!drenaje || 0 === e.vivas) && 200 === suggest()) return true;
    await new Promise((r) => setTimeout(r, 500));
  }
  return false;
};

console.log('Estado inicial');
// Una corrida anterior (o su restauración) puede dejar una publicación en curso:
// se parte de un buscador que YA sirve, para que un rojo sea de esta corrida.
await esperarServicio();
const ORIGINAL = filas();
const INICIO = estado();
ok(suggest() === 200, 'el autocompletado responde 200 antes de tocar nada', { codigo: ultimoSuggest, t: Math.floor(Date.now() / 1000) });
ok(INICIO.sirve, 'la foto de precios sirve', INICIO);

try {
  // ── V1 ────────────────────────────────────────────────────────────────
  console.log('V1 · un valor fuera de dominio no entra, con todo sano');
  const cliRes = cli('option update akibara_taxonomy_discount_apply_to stock');
  ok(cliRes.rc !== 0 || /Could not update|no se pudo/i.test(cliRes.salida), 'WP-CLI rechaza el valor fuera de dominio', cliRes.salida.slice(0, 200));
  ok(filas().akibara_taxonomy_discount_apply_to === ORIGINAL.akibara_taxonomy_discount_apply_to, 'la option no cambió');

  const decimales = comoAdmin(`
    $antes = get_option( 'woocommerce_price_num_decimals' );
    $r = update_option( 'woocommerce_price_num_decimals', 10 );
    wp_cache_flush();
    echo wp_json_encode( array( 'devuelto' => $r, 'ahora' => (string) get_option( 'woocommerce_price_num_decimals' ), 'antes' => (string) $antes ) );
  `);
  ok(false === decimales.devuelto && decimales.ahora === decimales.antes, 'WooCommerce «Número de decimales» = 10 tampoco entra', decimales);
  ok(suggest() === 200, 'y el buscador sigue en 200 (en 0.1.62 esto lo dejaba en 503)', { codigo: ultimoSuggest, t: Math.floor(Date.now() / 1000) });
  const avisoV1 = avisos();
  ok(avisoV1.some((a) => a.cause === 'invalido'), 'queda un aviso para el dueño con la causa «valor inválido»', avisoV1);

  // ── V2 ────────────────────────────────────────────────────────────────
  console.log('V2 · lo roto por SQL se repara desde la pantalla');
  wp(`
    global $wpdb;
    $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", '2026-02-30', 'akibara_taxonomy_discount_to' ) );
    wp_cache_flush();
    echo wp_json_encode( array( 'valor' => (string) get_option( 'akibara_taxonomy_discount_to' ) ) );
  `);
  const roto = estado();
  ok(!roto.sirve, 'con una fecha imposible, la foto de precios deja de servir', roto);
  const rotas = wp(`wp_cache_flush(); $e = \\Akibara\\Search\\EditorialDiscountPublication::status(); echo wp_json_encode( array( 'rotas' => $e['broken_options'] ?? null ) );`).rotas;
  ok(JSON.stringify(rotas || []).includes('akibara_taxonomy_discount_to') || /fecha/i.test(JSON.stringify(rotas || [])), 'y el estado nombra el dato roto y dónde corregirlo', rotas);
  ok([404, 503].includes(suggest()), 'y el autocompletado deja de atender');

  const reparado = comoAdmin(`
    $r = update_option( 'akibara_taxonomy_discount_to', '${ORIGINAL.akibara_taxonomy_discount_to}' );
    wp_cache_flush();
    echo wp_json_encode( array( 'devuelto' => $r, 'ahora' => (string) get_option( 'akibara_taxonomy_discount_to' ) ) );
  `);
  ok(true === reparado.devuelto && reparado.ahora === ORIGINAL.akibara_taxonomy_discount_to, 'corregir la fecha desde el panel SÍ entra (antes sólo salía con SQL)', reparado);
  const log = auditoria(6);
  ok(log.some((l) => JSON.stringify(l).includes('option_editorial_reparada')), 'queda registrado como reparación', log);
  cli('akb-search rebuild');
  await esperarServicio();
  const tras = estado();
  ok(tras.sirve && suggest() === 200, 'tras la reconstrucción, el buscador vuelve a atender', tras);

  // ── V3 ────────────────────────────────────────────────────────────────
  console.log('V3 · con el cerco tomado por otra conexión, el cambio se rechaza por ocupado');
  const durante = comoAdmin(`
    // El tope tiene que entrar DISTINTO: escribir el mismo valor no es una
    // escritura y no habría nada que rechazar.
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'akb_discount_max_pct'" );
    wp_cache_flush();
    global $wpdb;
    // El cerco del buscador es un GET_LOCK de MariaDB, por conexión: se toma
    // desde OTRA conexión, que es justo lo que hace una reconstrucción en curso.
    $nombre = ( new \\Akibara\\Search\\WriteFence( $wpdb ) )->name();
    $otra   = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
    $otra->query( "SELECT GET_LOCK('" . $otra->real_escape_string( $nombre ) . "', 1)" );

    $r = update_option( 'akb_discount_max_pct', 20 );
    wp_cache_flush();
    $ahora = get_option( 'akb_discount_max_pct', '(ausente)' );

    $otra->query( "SELECT RELEASE_LOCK('" . $otra->real_escape_string( $nombre ) . "')" );
    $otra->close();
    echo wp_json_encode( array( 'devuelto' => $r, 'ahora' => (string) $ahora, 'cerco' => $nombre ) );
  `);
  const avisoV3 = avisos();
  ok(false === durante.devuelto, 'el cambio no se guarda mientras el buscador publica', durante);
  ok(avisoV3.some((a) => a.cause === 'ocupado'), 'y el aviso dice «ocupado», no «valor inválido»', avisoV3);
  await esperarServicio();
  ok(suggest() === 200, 'el buscador sigue atendiendo');

  // ── V4 ────────────────────────────────────────────────────────────────
  console.log('V4 · guardar Generales sin tocar precios no mueve nada');
  // Con la fila `gmt_offset` presente y vacía, como en producción (medido el
  // 2026-09-14). Hasta hoy V4 se omitía «porque el espejo no tiene la fila»:
  // quien la borraba era la restauración de este mismo QA, que partía de un
  // espejo sin ella. Ahora cada escenario monta su condición por SQL crudo (sin
  // hooks) y espera a que el servicio la asiente antes de medir.
  wp(`global $wpdb; $wpdb->query( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('gmt_offset', '', 'on') ON DUPLICATE KEY UPDATE option_value = option_value" ); wp_cache_flush(); echo wp_json_encode( array( 'ok' => 1 ) );`);
  await esperarServicio();
  {
  const antesV4 = estado();
  const generales = comoAdmin(`
    $r = update_option( 'gmt_offset', '' );
    wp_cache_flush();
    echo wp_json_encode( array( 'devuelto' => $r, 'gmt' => (string) get_option( 'gmt_offset' ), 'tz' => (string) get_option( 'timezone_string' ) ) );
  `);
  const despuesV4 = estado();
  ok(antesV4.seq === despuesV4.seq, 'la revisión editorial no sube', { antes: antesV4.seq, despues: despuesV4.seq, generales });
  ok(despuesV4.sirve && suggest() === 200, 'y el buscador no se entera', despuesV4);
  }
  // ── V5 ────────────────────────────────────────────────────────────────
  console.log('V5 · borrar lo que el lector exige se veta');
  const borrado = cli('option delete akibara_business_search_feed_contract_version');
  ok(borrado.rc !== 0 || /Could not delete|no se pudo/i.test(borrado.salida), 'WP-CLI no puede borrar el contrato del feed', borrado.salida.slice(0, 160));
  const fila = wp(`global $wpdb; echo wp_json_encode( array( 'v' => $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'akibara_business_search_feed_contract_version'" ) ) );`).v;
  ok(fila === ORIGINAL.akibara_business_search_feed_contract_version, 'la fila sigue en la base', { fila });
  const leido = cli('option get akibara_business_search_feed_contract_version').salida.trim().split('\n').pop();
  ok(leido === ORIGINAL.akibara_business_search_feed_contract_version, 'y una petición nueva la lee (la caché no quedó marcándola como borrada)', leido);
  ok(suggest() === 200, 'el buscador no se enteró');

  // ── V6 ────────────────────────────────────────────────────────────────
  console.log('V6 · un marco sin cerrar no deja el servicio cerrado');
  // La condición contraria a V4: sin fila, `add_option()` se niega porque
  // `get_option()` devuelve un valor calculado, y eso es el marco sin cerrar.
  wp(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name = 'gmt_offset'" ); wp_cache_flush(); echo wp_json_encode( array( 'ok' => 1 ) );`);
  {
    // Con una publicación todavía asentándose el cerco está tomado y el intento
    // se rechaza por «ocupado» sin montar marco: se espera a que sirva y se
    // reintenta hasta tres veces para medir de verdad el marco sin cerrar.
    await esperarServicio();
    const antesV6 = estado();
    let intento = {};
    let marco = false;
    for (let i = 0; i < 3 && !marco; i += 1) {
      intento = comoAdmin(`$r = update_option( 'gmt_offset', '' ); echo wp_json_encode( array( 'devuelto' => $r ) );`);
      marco = /marco_editorial_sin_cerrar/.test(auditoria(3).map((l) => JSON.stringify(l)).join(' '));
      if (!marco) { await esperarServicio(); }
    }
    ok(false === intento.devuelto, 'Core no escribe (add_option se niega con un valor calculado)', intento);
    // Se mide la vuelta del SERVICIO, no el drenaje de la deuda viva que el
    // re-sellado deja detrás (con 0.1.64 ese drenaje ya no cierra nada).
    const inicio = Date.now();
    const volvio = await esperarServicio(60, { drenaje: false });
    const segundos = Math.round((Date.now() - inicio) / 1000);
    ok(volvio && suggest() === 200, `el servicio vuelve solo (${segundos} s)`, estado());
    await esperarServicio();
    const log = auditoria(8).map((l) => JSON.stringify(l)).join(' ');
    ok(/marco_editorial_sin_cerrar/.test(log), 'y queda rastro del marco sin cerrar', log.slice(0, 300));
    ok(estado().seq >= antesV6.seq, 'sin perder la revisión', { antes: antesV6.seq, ahora: estado().seq });
  }
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e.message);
} finally {
  console.log('Restauración');
  const fin = wp(`
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    $uid = (int) ( $admins[0] ?? 1 );
    delete_transient( 'akb_search_rechazo_' . $uid );
    global $wpdb;
    ${Object.entries(ORIGINAL).map(([o, v]) => (
      null === v
        ? `$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", '${o}' ) );`
        : `$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", '${o}', '${String(v).replace(/'/g, "\\'")}' ) );`
    )).join('\n')}
    wp_cache_flush();
    echo wp_json_encode( array( 'ok' => 1 ) );
  `);
  cli('akb-search rebuild');
  await esperarServicio();
  const final = estado();
  const filasFinales = filas();
  const iguales = JSON.stringify(filasFinales) === JSON.stringify(ORIGINAL);
  ok(iguales, 'las options vuelven exactamente a como estaban', { esperado: ORIGINAL, ahora: filasFinales });
  ok(final.sirve && suggest() === 200, 'y el espejo queda con el buscador atendiendo', { codigo: ultimoSuggest, t: Math.floor(Date.now() / 1000), final });
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
