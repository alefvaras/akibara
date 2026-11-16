// QA Playwright del ESPEJO — calidad AVIF y recompresión de portadas (business 1.116.10).
//
//   C1. La pestaña «Imágenes» existe en el grupo Sitio, con la calidad 60 de fábrica.
//   C2. Una calidad inválida (30) no se guarda y el panel lo dice.
//   C3. Una válida (55) se guarda, no purga la caché y vuelve a 60.
//   C4. Una portada subida AHORA sale con la calidad guardada: su tamaño de 450 px
//       es byte a byte el que da el codificador con esa calidad, y distinto del de 82.
//   C5. El botón encola una pasada; pulsarlo otra vez no la duplica.
//   C6. Sobre portadas reales: un tamaño hecho con 82 se reemplaza por uno más
//       liviano de las mismas medidas; uno que ya pesa poco queda intacto; el
//       original no cambia; el logo y los banners no se tocan.
//   C7. El cliente sigue viendo esas portadas (se decodifican en el navegador).
//   C8. Todo lo sembrado se retira.
//
// Uso: node tools/akibara-releases/business-qa/qa-imagenes-calidad.mjs
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';

const require = createRequire('/Users/alefvaras/Documents/akibara/tools/akibara-releases/rewards-qa/');
const { chromium } = require('playwright');

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const ADMIN = { login: 'qa.imagenes', clave: 'AkbQaImagenes2026!' };

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 700)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();

// El código va por ENTRADA ESTÁNDAR: como argumento se rompe con saltos de línea o comillas.
const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\nuse Akibara\\Business\\Modules\\ImageQuality\\ImageQuality;\nuse Akibara\\Business\\Modules\\ImageQuality\\Recompressor;\n${codigo}` });
  const linea = salida.trim().split('\n').reverse().find((l) => l.startsWith('{'));
  return JSON.parse(linea || '{}');
};

console.log('Preparación');
const S = wp(`
  $u  = get_user_by( 'login', '${ADMIN.login}' );
  $id = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => '${ADMIN.login}', 'user_pass' => '${ADMIN.clave}', 'user_email' => '${ADMIN.login}@akibara.invalid', 'role' => 'administrator' ) );
  wp_set_password( '${ADMIN.clave}', $id );
  global $wpdb;
  // Una pasada anterior (p. ej. la que dejó otra corrida de este QA) se reinicia para medir desde
  // cero; las marcas por portada se conservan y las muestras saltan las ya revisadas.
  $pasada_previa = Recompressor::state();
  as_unschedule_all_actions( Recompressor::HOOK, array(), Recompressor::GROUP );
  delete_option( Recompressor::OPTION_STATE );
  // El candado se escribe con SQL directo (no con add_option), así que se suelta igual: una corrida
  // muerta de otra tanda dejaría el candado fresco y este QA no vería empezar nada.
  $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Recompressor::LOCK_OPTION ) );
  wp_cache_delete( Recompressor::LOCK_OPTION, 'options' );
  // Portadas con original en disco: una «antigua» (sin 560) y una «reciente» (con 560).
  $filas = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' AND m.meta_value LIKE 'portadas/%' WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/avif' ORDER BY p.ID ASC" );
  $antigua = 0; $reciente = 0;
  foreach ( $filas as $f ) {
    $m = wp_get_attachment_metadata( (int) $f ); $o = get_attached_file( (int) $f );
    if ( ! is_array( $m['sizes'] ?? null ) || ! is_file( $o ) ) { continue; }
    // El espejo tiene uploads a medias: sólo se exige lo que se mide (original, 450 y ~700; 560 en la reciente).
    $hay = function ( $n ) use ( $m, $o ) { return ! empty( $m['sizes'][ $n ]['file'] ) && is_file( dirname( $o ) . '/' . $m['sizes'][ $n ]['file'] ); };
    if ( ! $hay( 'large' ) || get_post_meta( (int) $f, Recompressor::META_DONE, true ) ) { continue; }
    // Antigua: original y ~700 px (en el espejo muchas no traen su 450 en disco).
    if ( ! $antigua && empty( $m['sizes']['akibara-logo-footer'] ) ) { $antigua = (int) $f; }
    // Reciente: además 450 y 560, que son los que se hicieron con 82.
    if ( ! $reciente && $hay( 'akibara-logo-footer' ) && $hay( 'akb-card-2x' ) ) { $reciente = (int) $f; }
    if ( $antigua && $reciente ) { break; }
  }
  // Un AVIF que NO es portada, como testigo de que no se toca.
  $ajena = (int) $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' AND m.meta_value NOT LIKE 'portadas/%' WHERE p.post_type = 'attachment' AND p.post_mime_type = 'image/avif' ORDER BY p.ID ASC LIMIT 1" );
  $huella = function ( int $id ) {
    if ( ! $id ) { return array(); }
    $m = wp_get_attachment_metadata( $id ); $o = get_attached_file( $id ); $d = dirname( $o );
    $t = array( '__original' => md5_file( $o ) );
    foreach ( $m['sizes'] as $n => $s ) { if ( is_file( "$d/{$s['file']}" ) ) { $t[ $n ] = array( filesize( "$d/{$s['file']}" ), md5_file( "$d/{$s['file']}" ), $s['width'], $s['height'], $s['mime-type'] ?? '' ); } }
    return $t;
  };
  $prod = function ( int $adj ) use ( $wpdb ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1", $adj ) ); };
  echo wp_json_encode( array(
    'id' => $id,
    'pasada_previa' => $pasada_previa['started'] ? $pasada_previa['revisadas'] . ' de ' . $pasada_previa['total'] : 'ninguna',
    'opcion' => ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", ImageQuality::OPTION_QUALITY ) ) ) ? '(sin fila)' : get_option( ImageQuality::OPTION_QUALITY ),
    'estado' => get_option( Recompressor::OPTION_STATE, '(sin fila)' ),
    'antigua' => $antigua, 'reciente' => $reciente, 'ajena' => $ajena,
    'h_antigua' => $huella( $antigua ), 'h_reciente' => $huella( $reciente ), 'h_ajena' => $huella( $ajena ),
    'ficha_antigua' => $antigua ? get_permalink( $prod( $antigua ) ) : '', 'ficha_reciente' => $reciente ? get_permalink( $prod( $reciente ) ) : '',
  ) );
`);
ok(S.id > 0, 'administrador de QA creado');
ok(S.antigua > 0 && S.reciente > 0 && S.ajena > 0, 'hay portada antigua, reciente y un AVIF ajeno para medir', { antigua: S.antigua, reciente: S.reciente, ajena: S.ajena });
console.log(`     antigua ${S.antigua} · reciente ${S.reciente} · ajena ${S.ajena} · fila de la calidad: ${S.opcion} · pasada previa reiniciada: ${S.pasada_previa}`);
if (!(S.antigua > 0 && S.reciente > 0 && S.ajena > 0)) {
  console.error('\nSin muestras no hay nada que medir: se detiene antes de tocar el panel.');
  process.exit(1);
}

const nav = await chromium.launch();
const ctx = await nav.newContext({ viewport: { width: 1280, height: 900 } });
const page = await ctx.newPage();
const errores = [];
page.on('pageerror', (e) => errores.push(e.message));

try {
  // El espejo comparte CPU con el cron que recomprime: un login puede tardar; se reintenta una vez.
  for (let intento = 1; intento <= 2; intento += 1) {
    try {
      await page.goto(`${BASE}/wp-login.php`, CARGA);
      await page.fill('#user_login', ADMIN.login);
      await page.fill('#user_pass', ADMIN.clave);
      await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 120000 }), page.click('#wp-submit')]);
      break;
    } catch (e) {
      if (intento === 2) { throw e; }
      console.log(`     login lento (${e.message.split('\n')[0]}); reintento`);
    }
  }
  const pestana = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=imagenes`;

  console.log('C1 · la pestaña');
  await page.goto(pestana, CARGA);
  const campo = page.locator('#akb_image_avif_quality');
  ok(await campo.count() === 1, 'la pestaña «Imágenes» muestra el campo de calidad');
  ok(await campo.inputValue() === '60', 'con 60 de fábrica', await campo.inputValue());
  ok(limpio(await page.locator('.wrap').innerText()).includes('Todavía no se ha recomprimido nada'), 'el estado dice que no se ha recomprimido nada');
  const nav_sitio = await page.evaluate(() => Array.from(document.querySelectorAll('a, option')).some((a) => /Imágenes/.test(a.textContent || '') && /tab=imagenes/.test(a.getAttribute('href') || a.getAttribute('value') || '')));
  ok(nav_sitio, 'la pestaña aparece en la navegación del panel');

  console.log('C2 · calidad inválida');
  await campo.evaluate((el) => { el.removeAttribute('min'); el.removeAttribute('max'); el.removeAttribute('required'); });
  await campo.fill('30');
  await Promise.all([page.waitForNavigation(CARGA), page.click('button[name="akibara_panel_save"]')]);
  ok(limpio(await page.locator('.wrap').innerText()).includes('entre 40 y 90'), 'el panel explica que no entró');
  ok(wp('echo wp_json_encode( array( "v" => get_option( ImageQuality::OPTION_QUALITY, "(sin fila)" ) ) );').v === S.opcion, 'la calidad guardada no cambió');

  console.log('C3 · calidad válida');
  await page.goto(pestana, CARGA);
  await page.locator('#akb_image_avif_quality').fill('55');
  await Promise.all([page.waitForNavigation(CARGA), page.click('button[name="akibara_panel_save"]')]);
  ok(/purged=0/.test(page.url()), 'guardar la calidad no purga la caché del sitio', page.url());
  ok(wp('echo wp_json_encode( array( "v" => get_option( ImageQuality::OPTION_QUALITY ) ) );').v === '55', 'quedó guardado 55');
  ok(wp('echo wp_json_encode( array( "v" => apply_filters( "wp_editor_set_quality", 82, "image/avif" ), "j" => apply_filters( "wp_editor_set_quality", 82, "image/jpeg" ) ) );').v === 55, 'y el filtro ya da 55 para AVIF');
  await page.goto(pestana, CARGA);
  await page.locator('#akb_image_avif_quality').fill('60');
  await Promise.all([page.waitForNavigation(CARGA), page.click('button[name="akibara_panel_save"]')]);
  ok(wp('echo wp_json_encode( array( "v" => get_option( ImageQuality::OPTION_QUALITY ) ) );').v === '60', 'vuelve a 60');

  console.log('C4 · una subida nueva usa la calidad guardada');
  const C4 = wp(`
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $o   = get_attached_file( ${S.reciente} );
    $tmp = wp_tempnam( 'qa-calidad.avif' ); copy( $o, $tmp );
    $adj = media_handle_sideload( array( 'name' => 'qa-calidad-' . wp_generate_password( 6, false ) . '.avif', 'tmp_name' => $tmp ), 0 );
    if ( is_wp_error( $adj ) ) { echo wp_json_encode( array( 'error' => $adj->get_error_message() ) ); return; }
    $m = wp_get_attachment_metadata( $adj ); $d = dirname( get_attached_file( $adj ) ); $s = $m['sizes']['akb-card-2x'];
    $ref60 = get_temp_dir() . 'qa-ref60.avif'; $ref82 = get_temp_dir() . 'qa-ref82.avif';
    $caja = wp_get_registered_image_subsizes()['akb-card-2x'];
    $r60 = Recompressor::encode_with_wordpress( get_attached_file( $adj ), $caja['width'], $caja['height'], $caja['crop'], 60, $ref60 );
    $r82 = Recompressor::encode_with_wordpress( get_attached_file( $adj ), $caja['width'], $caja['height'], $caja['crop'], 82, $ref82 );
    $out = array( 'subida' => md5_file( "$d/{$s['file']}" ), 'bytes' => filesize( "$d/{$s['file']}" ), 'q60' => md5_file( $ref60 ), 'b60' => filesize( $ref60 ), 'q82' => md5_file( $ref82 ), 'b82' => filesize( $ref82 ) );
    unlink( $ref60 ); unlink( $ref82 );
    wp_delete_attachment( $adj, true );
    $out['retirada'] = get_post( $adj ) ? 0 : 1;
    echo wp_json_encode( $out );
  `);
  ok(!C4.error, 'la subida de prueba entró', C4);
  ok(C4.subida === C4.q60, 'su 450 px es byte a byte el de calidad 60', C4);
  ok(C4.subida !== C4.q82 && C4.b82 > C4.b60, 'y distinto (más pesado) el de 82', C4);
  ok(C4.retirada === 1, 'la subida de prueba se retiró');

  console.log('C5 · el botón');
  await page.goto(pestana, CARGA);
  const boton = page.getByRole('button', { name: /Recomprimir las portadas ya subidas con calidad 60/ });
  ok(await boton.count() === 1, 'el botón nombra la calidad con que va a recomprimir');
  await Promise.all([page.waitForNavigation(CARGA), boton.click()]);
  ok(limpio(await page.locator('.wrap').innerText()).includes('Recompresión encolada'), 'avisa que quedó encolada');
  await Promise.all([page.waitForNavigation(CARGA), page.getByRole('button', { name: /Recomprimir las portadas/ }).click()]);
  ok(limpio(await page.locator('.wrap').innerText()).includes('Ya hay una recompresión en curso'), 'pulsar otra vez no la duplica');
  const E = wp('echo wp_json_encode( array( "estado" => Recompressor::state(), "pendientes" => count( as_get_scheduled_actions( array( "hook" => Recompressor::HOOK, "status" => "pending" ), "ids" ) ), "en_curso" => count( as_get_scheduled_actions( array( "hook" => Recompressor::HOOK, "status" => "in-progress" ), "ids" ) ) ) );');
  // El cron del espejo corre cada minuto y puede haber tomado ya la corrida.
  ok(E.estado.quality === 60 && E.estado.started > 0 && E.pendientes <= 1 && (E.pendientes + E.en_curso >= 1 || E.estado.revisadas > 0), 'hay una sola corrida con calidad 60 (pendiente, en curso o ya avanzando)', E);
  ok(E.estado.total > 0, `la pasada cuenta ${E.estado.total} portadas`, E.estado.total);

  console.log('C6 · decisiones sobre portadas reales');
  const C6 = wp(`
    $r = new Recompressor(); $estado = Recompressor::state();
    foreach ( array( ${S.antigua}, ${S.reciente} ) as $id ) { $r->process_attachment( $id, 60, $estado ); }
    update_option( Recompressor::OPTION_STATE, $estado, false );
    $huella = function ( int $id ) {
      $m = wp_get_attachment_metadata( $id ); $o = get_attached_file( $id ); $d = dirname( $o );
      $t = array( '__original' => md5_file( $o ) );
      foreach ( $m['sizes'] as $n => $s ) { if ( is_file( "$d/{$s['file']}" ) ) { $t[ $n ] = array( filesize( "$d/{$s['file']}" ), md5_file( "$d/{$s['file']}" ), $s['width'], $s['height'], $s['mime-type'] ?? '', (int) ( $s['filesize'] ?? 0 ) ); } }
      return $t;
    };
    $temporales = count( (array) glob( dirname( get_attached_file( ${S.antigua} ) ) . '/' . Recompressor::TMP_PREFIX . '*' ) );
    echo wp_json_encode( array( 'estado' => $estado, 'antigua' => $huella( ${S.antigua} ), 'reciente' => $huella( ${S.reciente} ), 'marca' => array( get_post_meta( ${S.antigua}, Recompressor::META_DONE, true ), get_post_meta( ${S.reciente}, Recompressor::META_DONE, true ) ), 'temporales' => $temporales ) );
  `);
  const revisar = (antes, despues, etiqueta) => {
    ok(antes.__original === despues.__original, `${etiqueta}: el original no cambió`);
    let reemplazados = 0, intactos = 0;
    for (const [n, a] of Object.entries(antes)) {
      if (n === '__original' || a[4] !== 'image/avif') { continue; }
      const d = despues[n];
      if (a[1] === d[1]) { intactos += 1; continue; }
      reemplazados += 1;
      ok(d[0] <= a[0] * 0.9, `${etiqueta} · ${n}: reemplazado sólo porque baja al 90 % o menos (${a[0]} → ${d[0]} B)`);
      ok(d[2] === a[2] && d[3] === a[3], `${etiqueta} · ${n}: mismas medidas ${a[2]}×${a[3]}`);
      ok(d[5] === d[0], `${etiqueta} · ${n}: la metadata anota el peso nuevo`);
    }
    return { reemplazados, intactos };
  };
  const RA = revisar(S.h_antigua, C6.antigua, `antigua ${S.antigua}`);
  const RR = revisar(S.h_reciente, C6.reciente, `reciente ${S.reciente}`);
  console.log(`     antigua: ${RA.reemplazados} reemplazados, ${RA.intactos} intactos · reciente: ${RR.reemplazados} reemplazados, ${RR.intactos} intactos`);
  ok(RR.reemplazados > 0, 'la portada reciente (hecha con 82) queda más liviana');
  ok(RA.intactos > 0, 'la antigua conserva los tamaños que ya pesaban poco');
  ok(C6.marca[0] === '60' && C6.marca[1] === '60', 'las dos quedan marcadas como revisadas con 60', C6.marca);
  ok(C6.temporales === 0, 'no quedan temporales');

  console.log('C6b · la pasada completa por la cola (primeras corridas) no toca el AVIF ajeno');
  const C6b = wp(`
    for ( $i = 0; $i < 3; $i++ ) { do_action( Recompressor::HOOK ); }
    $m = wp_get_attachment_metadata( ${S.ajena} ); $o = get_attached_file( ${S.ajena} ); $d = dirname( $o ); $t = array( '__original' => md5_file( $o ) );
    foreach ( $m['sizes'] as $n => $s ) { if ( is_file( "$d/{$s['file']}" ) ) { $t[ $n ] = array( filesize( "$d/{$s['file']}" ), md5_file( "$d/{$s['file']}" ) ); } }
    echo wp_json_encode( array( 'estado' => Recompressor::state(), 'ajena' => $t, 'marca_ajena' => get_post_meta( ${S.ajena}, Recompressor::META_DONE, true ) ) );
  `);
  ok(C6b.estado.revisadas > 0, `la cola avanzó: ${C6b.estado.revisadas} de ${C6b.estado.total} portadas`, C6b.estado);
  const ajenaIntacta = Object.entries(S.h_ajena).every(([n, a]) => (n === '__original' ? a === C6b.ajena[n] : a[1] === C6b.ajena[n][1]));
  ok(ajenaIntacta && C6b.marca_ajena === '', 'el AVIF que no es portada quedó intacto y sin marca', { ajena: S.ajena });
  // La pasada no puede quedarse sin nada que la mueva: las corridas por CLI de arriba compiten
  // con las de la cola del espejo (así se encontró la cadena perdida del 2026-09-14).
  const cadena = wp('echo wp_json_encode( array( "estado" => Recompressor::state(), "detenida" => Recompressor::is_stalled( Recompressor::state() ), "pendientes" => count( as_get_scheduled_actions( array( "hook" => Recompressor::HOOK, "status" => "pending" ), "ids" ) ), "en_curso" => count( as_get_scheduled_actions( array( "hook" => Recompressor::HOOK, "status" => "in-progress" ), "ids" ) ) ) );');
  ok(cadena.estado.finished > 0 || (!cadena.detenida && cadena.pendientes + cadena.en_curso >= 1), 'la pasada sigue teniendo una corrida pendiente o en curso', cadena);
  await page.goto(pestana, CARGA);
  ok(/Recompresión en curso con calidad 60: [\d.]+ de [\d.]+ portadas revisadas|Última recompresión, terminada/.test(limpio(await page.locator('.wrap').innerText())), 'el panel muestra el avance', limpio(await page.locator('.wrap p').nth(1).innerText()));

  console.log('C7 · el cliente sigue viendo las portadas');
  for (const ficha of [S.ficha_antigua, S.ficha_reciente].filter(Boolean)) {
    const p = await ctx.newPage();
    await p.goto(`${ficha}${ficha.includes('?') ? '&' : '?'}nc=${Date.now()}`, { waitUntil: 'load', timeout: 90000 });
    const img = await p.evaluate(async () => {
      const i = document.querySelector('.woocommerce-product-gallery img, .product .images img');
      if (!i) { return null; }
      if (!i.complete) { await new Promise((r) => { i.onload = r; i.onerror = r; setTimeout(r, 8000); }); }
      return { natural: i.naturalWidth, src: i.currentSrc.split('/').pop() };
    });
    ok(img && img.natural > 0, `la ficha ${ficha.split('/').filter(Boolean).pop()} pinta su portada`, img);
    await p.close();
  }
  ok(errores.length === 0, 'sin errores de JavaScript en el panel', errores.slice(0, 3));
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e.message);
} finally {
  await nav.close();
  console.log('C8 · limpieza');
  const L = wp(`
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $u = get_user_by( 'login', '${ADMIN.login}' );
    if ( $u ) { wp_delete_user( (int) $u->ID ); }
    ${S.opcion === '(sin fila)' ? 'delete_option( ImageQuality::OPTION_QUALITY );' : `update_option( ImageQuality::OPTION_QUALITY, '${S.opcion}' );`}
    global $wpdb;
    $fila = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", ImageQuality::OPTION_QUALITY ) );
    echo wp_json_encode( array( 'usuario' => get_user_by( 'login', '${ADMIN.login}' ) ? 0 : 1, 'opcion' => null === $fila ? '(sin fila)' : $fila ) );
  `);
  ok(L.usuario === 1, 'administrador de QA retirado');
  ok(L.opcion === S.opcion, 'la calidad queda como estaba', L);
  console.log('     (la pasada de recompresión queda en curso a propósito: es el estado que se quiere en el espejo)');
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
