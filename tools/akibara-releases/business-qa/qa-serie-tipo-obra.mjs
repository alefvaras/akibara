// QA Playwright del ESPEJO — clasificar una serie como cómic o no, en pantalla
// (hallazgo S-ccf4dde8, 2026-09-13).
//
//   T1. El campo «Tipo de obra» sale en la pantalla de edición de la serie.
//   T2. Elegir «No es cómic» se guarda y la lista de series lo muestra.
//   T3. Volver a «Sin definir» borra la clasificación.
//
// OJO: el guardado se dispara con `form.requestSubmit()`. Con Rank Math activo,
// el clic en «Actualizar» no envía el formulario en NINGUNA pantalla de término
// —tampoco las nativas— y eso es un hallazgo aparte (S-6b21f0ac).
//
// Uso: node qa-serie-tipo-obra.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const ADMIN = { login: 'qa.series', clave: 'AkbQaSeries2026!' };

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

const preparar = () => wp(`
  $u = get_user_by( 'login', '${ADMIN.login}' );
  $id = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => '${ADMIN.login}', 'user_pass' => '${ADMIN.clave}', 'user_email' => '${ADMIN.login}@akibara.invalid', 'role' => 'administrator' ) );
  wp_set_password( '${ADMIN.clave}', $id );
  $terminos = get_terms( array( 'taxonomy' => 'pa_serie', 'hide_empty' => false, 'number' => 1 ) );
  $t = is_array( $terminos ) && isset( $terminos[0] ) ? $terminos[0] : null;
  echo wp_json_encode( array(
    'id'     => $id,
    'term'   => $t ? (int) $t->term_id : 0,
    'nombre' => $t ? (string) $t->name : '',
    'tipo'   => $t ? (string) get_term_meta( (int) $t->term_id, '_akb_serie_schema_type', true ) : '',
  ) );
`);

const tipo = (term) => wp(`echo wp_json_encode( array( 'v' => (string) get_term_meta( ${term}, '_akb_serie_schema_type', true ) ) );`).v;
const limpiar = (term, original) => wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  $u = get_user_by( 'login', '${ADMIN.login}' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  ${original === '' ? `delete_term_meta( ${term}, '_akb_serie_schema_type' );` : `update_term_meta( ${term}, '_akb_serie_schema_type', '${original}' );`}
  echo wp_json_encode( array( 'tipo' => (string) get_term_meta( ${term}, '_akb_serie_schema_type', true ) ) );
`);

console.log('Preparación');
const S = preparar();
ok(S.id > 0 && S.term > 0, `administrador de QA y serie «${S.nombre}»`, S);
const original = S.tipo;

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

  const edicion = `${BASE}/wp-admin/term.php?taxonomy=pa_serie&tag_ID=${S.term}&post_type=product`;
  const listado = `${BASE}/wp-admin/edit-tags.php?taxonomy=pa_serie&post_type=product`;

  const guardar = async (valor) => {
    await page.goto(edicion, CARGA);
    await page.waitForSelector('select[name="akb_serie_tipo_schema"]', { timeout: 60000 });
    await page.selectOption('select[name="akb_serie_tipo_schema"]', valor);
    // El formulario se envía por código y no con un clic en «Actualizar»: en
    // este sitio Rank Math engancha `#edittag` y se come el clic en TODAS las
    // pantallas de término, también las nativas de WordPress y con nuestros
    // plugins desactivados (hallazgo S-6b21f0ac, medido el 2026-09-13). Lo que
    // se mide aquí es el campo nuevo, no ese bloqueo ajeno.
    await Promise.all([
      page.waitForLoadState('load').catch(() => {}),
      page.evaluate(() => document.getElementById('edittag').requestSubmit()),
    ]);
    await page.waitForTimeout(2500);
  };

  // ── T1 ────────────────────────────────────────────────────────────────
  console.log('T1 · el campo está en la pantalla de la serie');
  await page.goto(edicion, CARGA);
  await page.waitForSelector('select[name="akb_serie_tipo_schema"]', { timeout: 60000 });
  const opciones = await page.locator('select[name="akb_serie_tipo_schema"] option').allInnerTexts();
  ok(opciones.some((o) => /No es cómic/.test(o)) && opciones.some((o) => /Cómic o manga/.test(o)), 'con sus tres opciones', opciones);
  const ayuda = limpio(await page.locator('select[name="akb_serie_tipo_schema"]').locator('xpath=../p').first().innerText().catch(() => ''));
  ok(/datos estructurados|Google/.test(ayuda), 'y una explicación de para qué sirve', ayuda);

  // ── T2 ────────────────────────────────────────────────────────────────
  console.log('T2 · elegir «No es cómic» se guarda');
  await guardar('non_comic');
  ok(tipo(S.term) === 'non_comic', 'la clasificación quedó guardada', { leido: tipo(S.term) });

  await page.goto(listado, CARGA);
  const fila = limpio(await page.locator(`tr#tag-${S.term}`).innerText().catch(() => ''));
  ok(/No es cómic/.test(fila), 'y la lista de series lo dice de un vistazo', fila.slice(0, 200));

  // ── T3 ────────────────────────────────────────────────────────────────
  console.log('T3 · volver a «Sin definir» borra la clasificación');
  await guardar('');
  ok(tipo(S.term) === '', 'la serie vuelve a tratarse como cómic', { leido: tipo(S.term) });

  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e);
} finally {
  await nav.close();
  console.log('Limpieza', JSON.stringify(limpiar(S.term, original)));
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
