// QA del ESPEJO — el formulario de registro de Mi cuenta, que desde 1.116.8
// ejecuta ocho `do_action()` que antes vivían dentro de un comentario.
//
//   R1. La pantalla de Mi cuenta pinta el formulario de registro con lo que
//       cuelga de esos ganchos: el campo de cumpleaños y el anuncio del bono.
//   R2. Registrarse funciona y guarda el cumpleaños.
//   R3. El login sigue enviando y el campo `redirect` no acepta un destino ajeno.
//
// Deja el espejo como estaba. Uso: node qa-registro-mi-cuenta.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const CWD = '/Users/alefvaras/Documents/akibara';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const CORREO = 'qa.registro@akibara.invalid';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 500)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();
// Los formularios de Mi cuenta viven dentro de <details> plegados: medirlos
// cerrados da falsos negativos (trampa ya documentada en memoria).
const abrir = async (page) => {
  await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
  await page.waitForTimeout(300);
};
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

console.log('Preparación');
const S = wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $u = get_user_by( 'email', '${CORREO}' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  $registro = (string) get_option( 'woocommerce_enable_myaccount_registration', 'no' );
  if ( 'yes' !== $registro ) { update_option( 'woocommerce_enable_myaccount_registration', 'yes' ); }
  echo wp_json_encode( array( 'registro_original' => $registro, 'gen' => (string) get_option( 'woocommerce_registration_generate_password', 'yes' ), 'bono' => (int) get_option( 'akb_rewards_welcome_bonus', 0 ) ) );
`);
ok(true, `registro activado para la prueba (venía en «${S.registro_original}»)`);

const nav = await chromium.launch();
try {
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();
  const erroresJs = [];
  page.on('pageerror', (e) => erroresJs.push(String(e).slice(0, 160)));

  // ── R1 ────────────────────────────────────────────────────────────────
  console.log('R1 · la pantalla pinta el formulario con sus ganchos');
  const r = await page.goto(`${BASE}/mi-cuenta/`, CARGA);
  ok(r && r.status() === 200, 'Mi cuenta responde 200', r && r.status());
  await abrir(page);
  const registro = page.locator('form.register').first();
  ok(await registro.count() === 1, 'el formulario de registro está');
  const texto = limpio(await registro.innerText().catch(() => ''));
  ok(/cumplea/i.test(texto), 'con el campo de cumpleaños (gancho woocommerce_register_form)', texto.slice(0, 300));
  ok(S.bono <= 0 || /Puntos Akibara/i.test(texto), 'y el anuncio del bono de bienvenida', texto.slice(0, 300));
  const login = page.locator('form.woocommerce-form-login').first();
  ok(await login.count() === 1, 'el formulario de acceso sigue ahí');

  // ── R2 ────────────────────────────────────────────────────────────────
  console.log('R2 · registrarse funciona y guarda el cumpleaños');
  await registro.locator('#reg_email').fill(CORREO);
  const clave = registro.locator('#reg_password');
  if (await clave.count()) await clave.fill('AkbQaRegistro2026!');
  // El cumpleaños son dos selectores (día y mes), del carril de Puntos.
  const dia = registro.locator('select[name="akb_rw_birthday_dia"]');
  const mes = registro.locator('select[name="akb_rw_birthday_mes"]');
  const tieneCumple = await dia.count() > 0 && await mes.count() > 0;
  if (tieneCumple) { await dia.selectOption('17'); await mes.selectOption('4'); }
  await Promise.all([page.waitForLoadState('load').catch(() => {}), registro.locator('button[name="register"]').click()]);
  await page.waitForTimeout(2500);

  const creado = wp(`
    $u = get_user_by( 'email', '${CORREO}' );
    echo wp_json_encode( array(
      'existe' => (bool) $u,
      'cumple' => $u ? wp_json_encode( array_filter( get_user_meta( (int) $u->ID ), static fn( $v, $k ) => false !== stripos( (string) $k, 'birthday' ), ARRAY_FILTER_USE_BOTH ) ) : '',
      'metas'  => $u ? array_keys( array_filter( get_user_meta( (int) $u->ID ), static fn( $v ) => '' !== (string) ( $v[0] ?? '' ) ) ) : array(),
    ) );
  `);
  ok(creado.existe, 'la cuenta se creó desde el formulario', creado);
  if (tieneCumple) {
    ok(/17/.test(creado.cumple) && /\b0?4\b|"4"|-04/.test(creado.cumple), 'y el cumpleaños (17 de abril) quedó guardado', creado);
  } else {
    console.log('  --  el formulario no trae campo de cumpleaños (carril apagado en el espejo)');
  }
  const avisosTras = limpio((await page.locator('.woocommerce-error, .woocommerce-message, .woocommerce-notices-wrapper').allInnerTexts()).join(' | '));
  ok(!/error/i.test(limpio(await page.locator('.woocommerce-error').innerText().catch(() => ''))), 'sin errores en pantalla tras registrarse', avisosTras.slice(0, 200));

  // ── R3 ────────────────────────────────────────────────────────────────
  console.log('R3 · el acceso sigue enviando y el redirect no acepta destinos ajenos');
  // La contraseña la genera WooCommerce y viaja por correo: para probar el acceso
  // se fija una conocida por CLI. Y salir de verdad: la URL de cierre sin nonce
  // válido sólo pide confirmación y dejaba la sesión abierta.
  wp(`$u = get_user_by( 'email', '${CORREO}' ); if ( $u ) { wp_set_password( 'AkbQaRegistro2026!', (int) $u->ID ); } echo wp_json_encode( array( 'ok' => (bool) $u ) );`);
  await ctx.clearCookies();
  await page.goto(`${BASE}/mi-cuenta/?redirect_to=https://example.com/`, CARGA);
  await abrir(page);
  // Hay más de un formulario de acceso en la página (el del encabezado, oculto):
  // se usa el de la pantalla de Mi cuenta.
  // Abre SU desplegable justo antes de escribir: el acordeón de la pantalla deja
  // uno solo abierto y el de registro, abierto antes, cerraba el de acceso.
  await page.evaluate(() => { const d = document.querySelector('#username')?.closest('details'); if (d) d.open = true; });
  await page.waitForTimeout(400);
  const login2 = page.locator('form.woocommerce-form-login').filter({ has: page.locator('#username') }).first();
  if (await login2.count()) {
    await page.locator('#username').fill(CORREO, { timeout: 15000 });
    await page.locator('#password').fill('AkbQaRegistro2026!');
    await Promise.all([page.waitForLoadState('load').catch(() => {}), login2.locator('button[name="login"]').click()]);
    await page.waitForTimeout(2500);
    // Se mide el HOST de la página a la que llegó, no la URL entera: el
    // parámetro `redirect_to` sigue en la barra y contiene «example.com».
    ok('example.com' !== new URL(page.url()).host, 'el acceso no manda a un destino externo', page.url());
    ok((await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in')), 'y la sesión se abre');
  } else {
    ok(false, 'no se pudo volver al formulario de acceso');
  }

  ok(erroresJs.length === 0, 'sin excepciones de JavaScript', erroresJs);
  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e.message);
} finally {
  await nav.close();
  const fin = wp(`
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $u = get_user_by( 'email', '${CORREO}' );
    if ( $u ) { wp_delete_user( (int) $u->ID ); }
    update_option( 'woocommerce_enable_myaccount_registration', '${S.registro_original}' );
    echo wp_json_encode( array( 'cuenta' => (bool) get_user_by( 'email', '${CORREO}' ), 'registro' => (string) get_option( 'woocommerce_enable_myaccount_registration' ) ) );
  `);
  console.log('Limpieza', JSON.stringify(fin));
  ok(!fin.cuenta && fin.registro === S.registro_original, 'el espejo queda como estaba', fin);
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
