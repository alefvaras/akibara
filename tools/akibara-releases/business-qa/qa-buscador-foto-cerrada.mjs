// QA Playwright del ESPEJO — qué ve el dueño cuando la foto de precios del
// buscador se cierra (hallazgos S-09b30833, S-abb1b5fe y S-4d0e7c11).
//
//   F1. Con un valor fuera de dominio en una option de precios, el panel del
//       buscador dice «Encendido, pero no disponible ahora» en vez de culpar al
//       interruptor del dueño.
//   F2. Guardar la zona horaria desde Ajustes → Generales queda vetado, y ahora
//       el dueño ve por qué en vez de sólo «Ajustes guardados».
//   F3. Devolverle a la option un valor de su dominio se puede hacer desde
//       WP-CLI: antes el propio veto lo impedía y sólo se salía con SQL.
//
// Deja el espejo como estaba. Uso: node qa-buscador-foto-cerrada.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const ADMIN = { login: 'qa.buscador', clave: 'AkbQaBuscador2026!' };
const OPCION = 'akibara_taxonomy_discount_apply_to';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();

const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const linea = salida.trim().split('\n').reverse().find((l) => l.startsWith('{'));
  return JSON.parse(linea || '{}');
};
const cli = (args) => execSync(`docker compose exec -T cron wp ${args}`, { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24 }).trim();

const preparar = () => wp(`
  $u = get_user_by( 'login', '${ADMIN.login}' );
  $id = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => '${ADMIN.login}', 'user_pass' => '${ADMIN.clave}', 'user_email' => '${ADMIN.login}@akibara.invalid', 'role' => 'administrator' ) );
  wp_set_password( '${ADMIN.clave}', $id );
  echo wp_json_encode( array( 'id' => $id, 'apply_to' => (string) get_option( '${OPCION}', '' ), 'tz' => (string) get_option( 'timezone_string', '' ) ) );
`);

// El valor inválido se pone por SQL a propósito: es como llega en la vida real
// (una restauración, un `wp db query`, un plugin viejo), y el veto no lo deja
// entrar por la puerta normal.
const romper = () => wp(`
  global $wpdb;
  $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", 'stock', '${OPCION}' ) );
  wp_cache_flush();
  echo wp_json_encode( array( 'valor' => (string) get_option( '${OPCION}', '' ) ) );
`);

const valor = () => wp(`wp_cache_flush(); echo wp_json_encode( array( 'v' => (string) get_option( '${OPCION}', '' ) ) );`).v;
const limpiar = (original) => wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $u = get_user_by( 'login', '${ADMIN.login}' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  global $wpdb;
  $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", '${original}', '${OPCION}' ) );
  wp_cache_flush();
  echo wp_json_encode( array( 'valor' => (string) get_option( '${OPCION}', '' ) ) );
`);

console.log('Preparación');
const S = preparar();
ok(S.id > 0 && '' !== S.apply_to, `administrador de QA · apply_to = ${S.apply_to}`, S);

const nav = await chromium.launch();
try {
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();

  let dentro = false;
  for (let intento = 1; intento <= 3 && !dentro; intento += 1) {
    await page.goto(`${BASE}/wp-login.php`, { waitUntil: 'load', timeout: 90000 });
    await page.waitForSelector('#wp-submit', { timeout: 30000 });
    await page.fill('#user_login', ADMIN.login);
    await page.fill('#user_pass', ADMIN.clave);
    await page.press('#user_pass', 'Enter');
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1500);
    dentro = (await ctx.cookies()).some((c) => c.name.startsWith('wordpress_logged_in'));
  }
  ok(dentro, 'sesión del administrador de QA');

  ok(romper().valor === 'stock', 'la option queda con un valor fuera de dominio');

  // ── F1 ────────────────────────────────────────────────────────────────
  console.log('F1 · el panel no culpa al interruptor del dueño');
  await page.goto(`${BASE}/wp-admin/admin.php?page=akibara-search&sub=indice`, CARGA);
  const servicios = limpio(await page.locator('h2:has-text("Servicios")').locator('xpath=following-sibling::div[1]').innerText().catch(() => ''));
  ok(/Encendido, pero no disponible ahora/.test(servicios), 'dice que está encendido y hoy no disponible', servicios.slice(0, 320));
  ok(!/Autocompletado Apagado \(flag OFF\)/.test(servicios), 'y ya no dice «Apagado (flag OFF)»', servicios.slice(0, 320));

  // ── F2 ────────────────────────────────────────────────────────────────
  console.log('F2 · el veto de un ajuste de WordPress se explica');
  await page.goto(`${BASE}/wp-admin/options-general.php`, CARGA);
  const zona = page.locator('#timezone_string');
  const antes = await zona.inputValue();
  await zona.selectOption(antes === 'America/Santiago' ? 'America/Lima' : 'America/Santiago');
  await Promise.all([page.waitForLoadState('load').catch(() => {}), page.locator('#submit').click()]);
  await page.waitForTimeout(1500);
  const avisos = limpio((await page.locator('.notice, #setting-error-settings_updated').allInnerTexts().catch(() => [])).join(' | '));
  ok(/no se guardó la zona horaria del sitio/i.test(avisos), 'el dueño ve por qué no se guardó', avisos.slice(0, 300));
  ok(wp(`wp_cache_flush(); echo wp_json_encode( array( 'v' => (string) get_option( 'timezone_string', '' ) ) );`).v === antes, 'y la zona horaria sigue como estaba');

  // ── F3 ────────────────────────────────────────────────────────────────
  console.log('F3 · la option se puede reparar sin SQL');
  const salida = cli(`option update ${OPCION} instock_only`);
  ok(/Success/.test(salida), 'WP-CLI acepta el valor válido', salida.slice(0, 160));
  ok(valor() === 'instock_only', 'y la option queda reparada', { valor: valor() });

  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e);
} finally {
  await nav.close();
  console.log('Limpieza', JSON.stringify(limpiar(S.apply_to)));
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
