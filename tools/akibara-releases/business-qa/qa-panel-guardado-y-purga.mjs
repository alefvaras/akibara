// QA Playwright del ESPEJO — el panel dice la verdad de lo que hizo al guardar
// (hallazgos S-45c17f64 y S-7022f6e1, 2026-09-13).
//
//   P1. Cambiar el interruptor «Activar el aviso previo» cuenta como cambio: el
//       panel purga y el aviso lo dice.
//   P2. Guardar sin tocar nada no purga, y el aviso dice que no había cambios en
//       vez de prometer que limpió la caché.
//   P3. Con el aviso previo apagado, la ficha de una preventa deja de prometerlo.
//   P4. Todo vuelve a como estaba.
//
// El porcentaje por editorial (term-meta, S-33ca8e15) no se puede medir aquí:
// el espejo tiene la publicación editorial del buscador atascada y rechaza
// cualquier cambio de precio. Queda cubierto por la suite.
//
// Uso: node qa-panel-purga-porcentaje.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const ADMIN = { login: 'qa.panel', clave: 'AkbQaPanel2026!' };

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();

// El código va por ENTRADA ESTÁNDAR: pasarlo como argumento lo rompe en cuanto
// lleva saltos de línea o comillas.
const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const linea = salida.trim().split('\n').reverse().find((l) => l.startsWith('{'));
  return JSON.parse(linea || '{}');
};

// Administrador de QA propio: no se toca ninguna cuenta del dueño.
const preparar = () => wp(`
  $u = get_user_by( 'login', '${ADMIN.login}' );
  $id = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => '${ADMIN.login}', 'user_pass' => '${ADMIN.clave}', 'user_email' => '${ADMIN.login}@akibara.invalid', 'role' => 'administrator' ) );
  wp_set_password( '${ADMIN.clave}', $id );

  // Una ficha «a pedido» o en preventa: ahí se pinta el bloque de promesas.
  global $wpdb;
  $ficha = '';
  $fila  = $wpdb->get_var( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_stock_status' WHERE p.post_type = 'product' AND p.post_status = 'publish' AND m.meta_value IN ( 'onbackorder', 'preventa' ) LIMIT 1" );
  if ( $fila ) { $ficha = (string) get_permalink( (int) $fila ); }

  echo wp_json_encode( array( 'id' => $id, 't7' => (string) get_option( 'akb_preventa_t7_enabled', '(sin fila)' ), 'ficha' => $ficha ) );
`);

const t7 = () => wp(`echo wp_json_encode( array( 'v' => (string) get_option( 'akb_preventa_t7_enabled', '(sin fila)' ) ) );`).v;
const limpiar = () => wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $u = get_user_by( 'login', '${ADMIN.login}' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  echo wp_json_encode( array( 'borrado' => $u ? 1 : 0 ) );
`);

console.log('Preparación');
const S = preparar();
ok(S.id > 0, 'administrador de QA creado', S);
ok(true, `interruptor del aviso previo: ${S.t7}${S.ficha ? ' · hay ficha con fecha' : ' · sin ficha con fecha'}`);
const original = S.t7;

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

  const panel = `${BASE}/wp-admin/admin.php?page=akibara-panel&tab=preventa&subtab=recordatorio`;
  const campo = 'input[name="akb_preventa_t7_enabled"]';

  // Las filas viven dentro de acordeones plegados: sin abrirlos el input existe
  // pero no es visible y Playwright no lo puede rellenar.
  const abrirFila = async () => {
    await page.waitForSelector(campo, { state: 'attached', timeout: 60000 });
    await page.evaluate(() => document.querySelectorAll('details').forEach((d) => { d.open = true; }));
    await page.waitForSelector(campo, { timeout: 30000 });
  };

  const guardar = async () => {
    // Con cambios sin guardar, la barra fija se pone encima del botón del
    // formulario y se come el clic: se pulsa el de la barra, que es el que ve
    // el dueño.
    const barra = page.locator('#akb-sticky-save-bar button[name="akibara_panel_save"]');
    const boton = await barra.isVisible().catch(() => false) ? barra : page.locator('form button[name="akibara_panel_save"]').first();
    await Promise.all([page.waitForLoadState('load').catch(() => {}), boton.click()]);
    await page.waitForTimeout(1500);
    return {
      url: page.url(),
      // Todos los avisos de la pantalla: el panel no es el único que pinta uno.
      aviso: limpio((await page.locator('.wrap .notice, .akb-panel .notice').allInnerTexts().catch(() => [])).join(' | ')),
    };
  };

  // ── P1 ────────────────────────────────────────────────────────────────
  console.log('P1 · apagar el aviso previo purga y lo dice');
  await page.goto(panel, CARGA);
  await abrirFila();
  await page.locator(campo).uncheck();
  const r1 = await guardar();
  ok(t7() === 'no', 'el interruptor quedó apagado', { leido: t7() });
  ok(/cambios=1/.test(r1.url) && /purged=1/.test(r1.url), 'el guardado se cuenta como cambio y purga', r1.url);
  ok(/Se limpió la caché/.test(r1.aviso), 'y el aviso dice que se limpió la caché', r1.aviso.slice(0, 300));

  // ── P2 ────────────────────────────────────────────────────────────────
  console.log('P2 · guardar sin tocar nada');
  await page.goto(panel, CARGA);
  await abrirFila();
  const r2 = await guardar();
  ok(/cambios=0/.test(r2.url) && /purged=0/.test(r2.url), 'sin cambios no se purga', r2.url);
  ok(/No había cambios/.test(r2.aviso), 'y el aviso lo dice en vez de prometer una limpieza', r2.aviso.slice(0, 300));
  ok(!/Se limpió la caché/.test(r2.aviso), 'nunca promete lo que no hizo');

  // ── P3 ────────────────────────────────────────────────────────────────
  console.log('P3 · la ficha deja de prometer el aviso previo');
  if ('' === S.ficha) {
    console.log('  --  omitido: no hay ficha con fecha estimada en el espejo');
  } else {
    await page.goto(S.ficha, CARGA);
    const apagado = limpio(await page.evaluate(() => document.body.innerText));
    ok(!/una semana antes/.test(apagado), 'apagado: la ficha ya no promete avisar una semana antes', apagado.match(/Cómo funciona.{0,260}/)?.[0]);
    ok(/cuando llegue a bodega/.test(apagado), 'pero sigue prometiendo el aviso de llegada', apagado.match(/Cómo funciona.{0,260}/)?.[0]);
  }

  // ── P4 ────────────────────────────────────────────────────────────────
  console.log('P4 · devolver el interruptor a como estaba');
  await page.goto(panel, CARGA);
  await abrirFila();
  if ('no' !== original) {
    await page.locator(campo).check();
  }
  await guardar();
  ok(t7() === ( '(sin fila)' === original ? 'yes' : original ), 'el interruptor volvió a su valor', { leido: t7(), original });

  if ('' !== S.ficha) {
    await page.goto(S.ficha, CARGA);
    const encendido = limpio(await page.evaluate(() => document.body.innerText));
    ok(/una semana antes/.test(encendido), 'y la ficha vuelve a prometer el aviso previo', encendido.match(/Cómo funciona.{0,260}/)?.[0]);
  }

  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e);
} finally {
  await nav.close();
  if ('(sin fila)' === original) {
    wp(`delete_option( 'akb_preventa_t7_enabled' ); echo wp_json_encode( array( 'restaurado' => 1 ) );`);
  } else if (t7() !== original) {
    wp(`update_option( 'akb_preventa_t7_enabled', '${original}' ); echo wp_json_encode( array( 'restaurado' => 1 ) );`);
  }
  console.log('Limpieza', JSON.stringify(limpiar()), 'aviso previo =', t7());
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
