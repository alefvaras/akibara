// QA del ESPEJO — lo corregido en business 1.116.9 / tema 2.1.122 / mu 1.2.2.
//
//   B1. «Tipo de obra»: marcar «Cómic o manga» sobre «Sin definir» NO purga el
//       sitio entero (no cambia nada publicado); pasar a «No es cómic» sí.
//   B2. Los correos en texto plano conservan los apóstrofes («D'Angelo»).
//   B3. El inventario de claves no pinta rojos falsos con Mailpit fuera de
//       producción, y los patrones aceptan mayúsculas.
//   B4. El aviso «tu pedido ya llegó» suprimido por estado de salida deja UNA
//       nota en el pedido.
//
// Deja el espejo como estaba. Uso: node qa-business-1-116-9.mjs
import { execSync } from 'node:child_process';

const CWD = '/Users/alefvaras/Documents/akibara';
let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};
const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const i = salida.indexOf('{');
  if (i < 0) throw new Error(`sin JSON: ${salida.slice(0, 400)}`);
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
const cli = (args) => { try { return execSync(`docker compose exec -T cron wp ${args} 2>&1`, { cwd: CWD, encoding: 'utf8' }); } catch (e) { return String(e.stdout || '') + String(e.stderr || ''); } };
const mailpit = (ruta) => JSON.parse(execSync(`curl -s 'http://localhost:8025/api/v1/${ruta}'`, { encoding: 'utf8' }));

// ── B1 ──────────────────────────────────────────────────────────────────
console.log('B1 · «Tipo de obra» purga sólo cuando cambia lo publicado');
const b1 = wp(`
  $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
  wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
  $terms = get_terms( array( 'taxonomy' => 'pa_serie', 'hide_empty' => false, 'number' => 1 ) );
  $term  = is_array( $terms ) && isset( $terms[0] ) ? (int) $terms[0]->term_id : 0;
  $antes = get_term_meta( $term, '_akb_serie_schema_type', true );

  $purgas = 0;
  add_action( 'litespeed_purge_all', static function () use ( &$purgas ) { ++$purgas; } );

  $campos = new \\Akibara\\Business\\Modules\\SeriesSchema\\SchemaTypeFields();
  $guardar = function ( string $valor ) use ( $campos, $term ) {
    $_POST['akb_serie_tipo_schema_nonce'] = wp_create_nonce( 'akb_save_serie_tipo_schema' );
    $_POST['akb_serie_tipo_schema']       = $valor;
    $campos->handle_save_term( $term );
    $campos->flush_pending_purges();
  };

  delete_term_meta( $term, '_akb_serie_schema_type' );
  $guardar( 'comic_series' );
  $tras_comic = array( 'meta' => (string) get_term_meta( $term, '_akb_serie_schema_type', true ), 'purgas' => $purgas );

  $guardar( 'non_comic' );
  $tras_non = array( 'meta' => (string) get_term_meta( $term, '_akb_serie_schema_type', true ), 'purgas' => $purgas );

  $guardar( '' );
  $tras_vacio = array( 'meta' => (string) get_term_meta( $term, '_akb_serie_schema_type', true ), 'purgas' => $purgas );

  if ( '' === (string) $antes ) { delete_term_meta( $term, '_akb_serie_schema_type' ); } else { update_term_meta( $term, '_akb_serie_schema_type', $antes ); }
  echo wp_json_encode( array( 'term' => $term, 'comic' => $tras_comic, 'non' => $tras_non, 'vacio' => $tras_vacio, 'original' => (string) $antes ) );
`);
ok(b1.comic.meta === 'comic_series' && b1.comic.purgas === 0, 'marcar «Cómic o manga» se guarda y NO purga el sitio', b1.comic);
ok(b1.non.meta === 'non_comic' && b1.non.purgas === 1, 'pasar a «No es cómic» sí purga, una vez', b1.non);
ok(b1.vacio.meta === '' && b1.vacio.purgas === 2, 'y volver a «Sin definir» purga otra vez (vuelve a publicar cómic)', b1.vacio);

// ── B2 ──────────────────────────────────────────────────────────────────
console.log('B2 · los correos en texto plano conservan los apóstrofes');
const antesCorreos = mailpit('messages?limit=1').total;
const b2 = wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $correo = 'qa.apostrofe@akibara.invalid';
  $u = get_user_by( 'email', $correo );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  $uid = wp_insert_user( array( 'user_login' => 'qa.apostrofe', 'user_pass' => wp_generate_password(), 'user_email' => $correo, 'first_name' => "D'Angelo", 'role' => 'customer' ) );
  if ( is_wp_error( $uid ) ) { echo wp_json_encode( array( 'error' => $uid->get_error_message() ) ); return; }
  WC()->mailer();
  // Firma real del hook: (customer_id, new_customer_data, password_generated).
  do_action( 'woocommerce_created_customer_notification', (int) $uid, array( 'user_login' => 'qa.apostrofe', 'user_email' => $correo ), true );
  echo wp_json_encode( array( 'uid' => (int) $uid ) );
`);
const lista = mailpit('messages?limit=5');
const mensaje = (lista.messages || []).find((m) => (m.To || []).some((t) => t.Address === 'qa.apostrofe@akibara.invalid'));
ok(!!mensaje, 'el correo de cuenta nueva llegó a la bandeja de pruebas', { total: lista.total, antes: antesCorreos });
if (mensaje) {
  const cuerpo = execSync(`curl -s 'http://localhost:8025/api/v1/message/${mensaje.ID}' `, { encoding: 'utf8' });
  const json = JSON.parse(cuerpo);
  const plano = String(json.Text || '');
  ok(/D'Angelo/.test(plano), 'la parte de texto plano conserva el apóstrofe (antes salía «DAngelo»)', plano.slice(0, 200));
  ok(!/&#0?39;|&amp;/.test(plano), 'y no lleva entidades HTML sueltas', plano.slice(0, 200));
}
wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $u = get_user_by( 'email', 'qa.apostrofe@akibara.invalid' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  echo wp_json_encode( array( 'borrado' => ! get_user_by( 'email', 'qa.apostrofe@akibara.invalid' ) ) );
`);

// ── B3 ──────────────────────────────────────────────────────────────────
console.log('B3 · el inventario de claves no pinta rojos falsos en el espejo');
const listado = cli('akibara access-list');
ok(!/FORMATO/.test(listado) || /PRUEBAS/.test(listado), 'las filas del correo de pruebas salen como PRUEBAS, no como formato inválido', listado.split('\n').filter((l) => /smtp|mail|correo/i.test(l)).slice(0, 6).join(' | '));
const patrones = wp(`
  $r = new ReflectionClass( '\\\\Akibara\\\\ProviderAccess\\\\Registry' );
  $cat = $r->getConstant( 'CATALOG' );
  $host = '';
  foreach ( (array) $cat as $fila ) { if ( 'AKIBARA_HOSTINGER_SMTP_HOST' === ( $fila['const'] ?? '' ) ) { $host = (string) ( $fila['pattern'] ?? '' ); } }
  echo wp_json_encode( array( 'patron_host' => $host, 'acepta_mayusculas' => (bool) preg_match( $host, 'SMTP.hostinger.com' ) ) );
`);
ok(patrones.acepta_mayusculas, 'el patrón del servidor SMTP acepta mayúsculas (el transporte hace strtolower)', patrones);

// ── B4 ──────────────────────────────────────────────────────────────────
console.log('B4 · el aviso «ya llegó» suprimido deja una nota');
// Necesita un pedido con preventa PENDIENTE: lo monta el sembrador de la casa,
// en su propia parcela, y se barre al terminar.
execSync("docker compose exec -T cron wp eval-file - qa-nota < tools/qa-seed-preventa.php", { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24 });
const b4 = wp(`
  $pedidos = wc_get_orders( array( 'limit' => 10, 'status' => 'any', 'return' => 'ids', 'billing_email' => 'qa-seed-qa-nota@akibara.cl' ) );
  $elegido = 0;
  foreach ( (array) $pedidos as $id ) {
    $o = wc_get_order( (int) $id );
    if ( $o && 'yes' === (string) $o->get_meta( '_akb_eta_has_preventa' ) ) { $elegido = (int) $id; break; }
  }
  if ( $elegido <= 0 ) { echo wp_json_encode( array( 'sin_pedidos' => true ) ); return; }

  $order = wc_get_order( $elegido );
  $order->update_meta_data( '_akb_eta_ready_to_ship', 'yes' );
  $order->delete_meta_data( '_akb_eta_ready_email_sent' );
  $order->delete_meta_data( '_akb_eta_ready_email_departed_noted' );
  $order->set_status( 'completed' ); // Estado de salida: el aviso no debe salir.
  $order->save();

  do_action( 'akb_order_preventa_dispatch_ready_email', $elegido );
  do_action( 'akb_order_preventa_dispatch_ready_email', $elegido ); // Dos veces: una sola nota.

  $order  = wc_get_order( $elegido );
  $textos = array_map( static fn( $n ) => $n->content, wc_get_order_notes( array( 'order_id' => $elegido ) ) );
  $suprimidas = array_values( array_filter( $textos, static fn( $t ) => false !== strpos( $t, 'NO enviado' ) ) );

  // Con el pedido en «procesando» (a bodega, sin salir) el aviso sí puede salir.
  $order->delete_meta_data( '_akb_eta_ready_email_departed_noted' );
  $order->set_status( 'processing' );
  $order->save();
  $puede = \\Akibara\\Business\\Modules\\OrderPreventa\\FulfillmentCapabilities::can_send_ready_email( wc_get_order( $elegido ) );

  echo wp_json_encode( array(
    'pedido'     => $elegido,
    'suprimidas' => $suprimidas,
    'marca'      => (string) wc_get_order( $elegido )->get_meta( '_akb_eta_ready_email_departed_noted' ),
    'puede_en_procesando' => (bool) $puede,
  ) );
`);
if (b4.sin_pedidos) {
  ok(false, 'el sembrador no dejó un pedido con preventa', b4);
} else {
  ok(b4.suprimidas.length === 1, 'queda UNA nota, aunque el aviso se intente dos veces', b4.suprimidas);
  ok(/cuenta como salida|ya estaba en/.test(b4.suprimidas[0] || ''), 'y dice por qué no salió', (b4.suprimidas[0] || '').slice(0, 200));
  ok(b4.puede_en_procesando, 'en «procesando» el aviso sí puede salir: no se rompió el camino normal', b4);
}
execSync("docker compose exec -T cron wp eval-file - qa-nota limpiar < tools/qa-seed-preventa.php", { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24 });
const restos = wp(`
  $q = wc_get_orders( array( 'limit' => 5, 'status' => 'any', 'return' => 'ids', 'billing_email' => 'qa-seed-qa-nota@akibara.cl' ) );
  echo wp_json_encode( array( 'pedidos' => count( (array) $q ) ) );
`);
ok(restos.pedidos === 0, 'la parcela de QA queda barrida', restos);

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
