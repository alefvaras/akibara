// QA Playwright del ESPEJO — una reseña premiada se lee sabiendo que lo fue
// (hallazgo S-66cc662a, rewards 0.4.1).
//
//   D0. De fábrica el aviso NO se pinta: el dueño lo dejó apagado el
//       2026-09-13 (las reseñas premiadas siguen yendo a Google, sabiendo que
//       la guía de Google pide divulgar el incentivo).
//   D1. Encendido el interruptor, la reseña que cobró puntos lleva el aviso
//       «ganó Puntos Akibara por publicarla con foto»; la que no cobró, nada.
//   D2. El aviso sale UNA vez y después del texto de la reseña.
//   D3. Los puntos en espera (`pending_account`) no son un premio: sin aviso.
//   D4. El interruptor «Sacar de Google el texto de las reseñas premiadas»
//       está en el panel, en Reseñas, y guarda en los dos sentidos.
//   D5. Con «premiar cada N reseñas», la que sumó para el premio sin cobrar lo
//       dice con sus palabras («sumó para un premio»), no «ganó».
//   D6. El carrusel del home lleva el mismo aviso en la tarjeta premiada y en
//       la que sumó, y ninguno en la que no (revisión independiente, MEDIA).
//
// El JSON-LD NO se mide acá a propósito: Rank Math está inactivo en local y es
// quien entrega la entidad Product que el plugin enriquece. Esa mitad se mide
// en producción tras el despliegue.
//
// Deja el espejo como estaba. Uso: node qa-divulgacion-resena.mjs
import { chromium } from 'playwright';
import { execSync } from 'node:child_process';

const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CWD = '/Users/alefvaras/Documents/akibara';
const UA = 'Mozilla/5.0 (AkibaraQA) Playwright';
const CARGA = { waitUntil: 'domcontentloaded', timeout: 90000 };
const ADMIN = { login: 'qa.divulgacion', clave: 'AkbQaDivulga2026!' };
const OPCION = 'akb_rewards_schema_exclude_rewarded';
const CARRUSEL_MIN = 'akb_rewards_carousel_min';
const AVISO = 'akb_rewards_disclosure_enabled';
const AVISO_TEXTO = 'ganó Puntos Akibara por publicarla con foto';

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 600)}`}`);
};
const limpio = (t) => String(t || '').replace(/\s+/g, ' ').trim();

// Borrar un comentario o guardar una option dispara la purga de LiteSpeed, que
// escribe «Success: Purgar la URL …» SIN salto de línea y pegado a nuestro
// JSON. Por eso no se busca una línea: se recorta el primer objeto equilibrado.
const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const inicio = salida.indexOf('{');
  if (inicio < 0) { throw new Error(`WP-CLI no devolvió JSON: ${salida.slice(0, 400)}`); }
  let nivel = 0;
  let dentroDeTexto = false;
  let escapado = false;
  for (let i = inicio; i < salida.length; i += 1) {
    const c = salida[i];
    if (escapado) { escapado = false; continue; }
    if ('\\' === c) { escapado = true; continue; }
    if ('"' === c) { dentroDeTexto = !dentroDeTexto; continue; }
    if (dentroDeTexto) { continue; }
    if ('{' === c) { nivel += 1; }
    if ('}' === c) {
      nivel -= 1;
      if (0 === nivel) { return JSON.parse(salida.slice(inicio, i + 1)); }
    }
  }
  throw new Error(`JSON incompleto de WP-CLI: ${salida.slice(inicio, inicio + 400)}`);
};

// Tres reseñas sobre un producto publicado de verdad: premiada, sin premio y
// con los puntos en espera (quien reseñó sin cuenta).
const sembrar = () => wp(`
  $u  = get_user_by( 'login', '${ADMIN.login}' );
  $id = $u ? (int) $u->ID : (int) wp_insert_user( array( 'user_login' => '${ADMIN.login}', 'user_pass' => '${ADMIN.clave}', 'user_email' => '${ADMIN.login}@akibara.invalid', 'role' => 'administrator' ) );
  wp_set_password( '${ADMIN.clave}', $id );

  $productos = wc_get_products( array( 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
  $producto  = isset( $productos[0] ) ? (int) $productos[0] : 0;

  $crear = function ( $marca, $puntos ) use ( $producto ) {
    $cid = (int) wp_insert_comment( array(
      'comment_post_ID'      => $producto,
      'comment_author'       => 'QA Divulgación',
      'comment_author_email' => 'qa.divulgacion@akibara.invalid',
      'comment_content'      => $marca . ' — llegó impecable y el tomo es precioso.',
      'comment_type'         => 'review',
      'comment_approved'     => 1,
    ) );
    update_comment_meta( $cid, 'rating', 5 );
    if ( null !== $puntos ) { update_comment_meta( $cid, '_akb_rewards_review_points', $puntos ); }
    return $cid;
  };

  echo wp_json_encode( array(
    'admin'     => $id,
    'producto'  => $producto,
    'url'       => $producto ? (string) get_permalink( $producto ) : '',
    'premiada'  => $producto ? $crear( 'QA-PREMIADA', 500 ) : 0,
    'sin_premio'=> $producto ? $crear( 'QA-SIN-PREMIO', null ) : 0,
    'en_espera' => $producto ? $crear( 'QA-EN-ESPERA', 'pending_account' ) : 0,
    'suma'      => $producto ? (function () use ( $crear ) { $id = $crear( 'QA-SUMA', 0 ); update_comment_meta( $id, '_akb_rewards_review_counted', 2 ); return $id; })() : 0,
    'opcion'    => (string) get_option( '${OPCION}', '' ),
    'carrusel_min'     => (string) get_option( '${CARRUSEL_MIN}', '' ),
    'carrusel_enabled' => (string) get_option( 'akb_rewards_carousel_enabled', '' ),
    'aviso'            => (string) get_option( '${AVISO}', '' ),
  ) );
`);

const opcion = () => wp(`wp_cache_flush(); echo wp_json_encode( array( 'v' => (string) get_option( '${OPCION}', '' ) ) );`).v;

const limpiar = (S) => wp(`
  require_once ABSPATH . 'wp-admin/includes/user.php';
  foreach ( array( ${S.premiada}, ${S.sin_premio}, ${S.en_espera}, ${S.suma} ) as $cid ) {
    if ( $cid ) { wp_delete_comment( (int) $cid, true ); }
  }
  $u = get_user_by( 'login', '${ADMIN.login}' );
  if ( $u ) { wp_delete_user( (int) $u->ID ); }
  ${S.opcion === '' ? `delete_option( '${OPCION}' );` : `update_option( '${OPCION}', '${S.opcion}' );`}
  ${S.carrusel_min === '' ? `delete_option( '${CARRUSEL_MIN}' );` : `update_option( '${CARRUSEL_MIN}', '${S.carrusel_min}' );`}
  ${S.carrusel_enabled === '' ? `delete_option( 'akb_rewards_carousel_enabled' );` : `update_option( 'akb_rewards_carousel_enabled', '${S.carrusel_enabled}' );`}
  ${S.aviso === '' ? `delete_option( '${AVISO}' );` : `update_option( '${AVISO}', '${S.aviso}' );`}
  delete_transient( 'akb_rewards_carousel_v2' );
  do_action( 'litespeed_purge_all' );
  wp_cache_flush();
  echo wp_json_encode( array(
    'comentarios' => (int) get_comments( array( 'post_id' => ${S.producto}, 'author_email' => 'qa.divulgacion@akibara.invalid', 'count' => true, 'status' => 'all' ) ),
    'opcion'      => (string) get_option( '${OPCION}', '(borrada)' ),
  ) );
`);

console.log('Siembra');
const S = sembrar();
ok(S.producto > 0 && S.premiada > 0 && S.sin_premio > 0 && S.en_espera > 0 && S.suma > 0, `producto ${S.producto} con cuatro reseñas`, S);

const nav = await chromium.launch();
try {
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 950 }, userAgent: UA });
  const page = await ctx.newPage();

  // ── D0 · apagado de fábrica ───────────────────────────────────────────
  console.log('D0 · de fábrica el aviso no se pinta');
  const ruta = new URL(S.url).pathname;
  const primera = await page.goto(`${BASE}${ruta}`, CARGA);
  ok(primera && primera.status() === 200, `la ficha responde 200 (${ruta})`, primera && primera.status());
  const tabApagado = page.locator('a[href="#tab-reviews"], #tab-title-reviews a').first();
  if (await tabApagado.count()) { await tabApagado.click().catch(() => {}); }
  await page.waitForTimeout(600);
  ok(!(await page.evaluate(() => document.body.innerText)).includes(AVISO_TEXTO), 'con el interruptor apagado, la ficha no dice nada del incentivo');

  // ── D1-D3 · la ficha, como cliente invitado ───────────────────────────
  console.log('D1-D3 · con el aviso encendido, la ficha dice quién ganó puntos');
  wp(`update_option( '${AVISO}', 'yes' ); do_action( 'litespeed_purge_all' ); echo wp_json_encode( array( 'ok' => 1 ) );`);
  const resp = await page.goto(`${BASE}${ruta}`, CARGA);
  ok(resp && resp.status() === 200, `la ficha responde 200 (${ruta})`, resp && resp.status());

  const tab = page.locator('a[href="#tab-reviews"], #tab-title-reviews a').first();
  if (await tab.count()) { await tab.click().catch(() => {}); }
  await page.waitForTimeout(600);

  const datos = await page.evaluate(() => {
    const caso = (marca) => {
      const nodo = [...document.querySelectorAll('li.review, li.comment, .comment_container')]
        .find((n) => n.textContent.includes(marca));
      const li = nodo ? (nodo.closest('li') || nodo) : null;
      if (!li) return { existe: false };
      const avisos = [...li.querySelectorAll('p.akb-rw-disclosure')];
      const texto = li.querySelector('.description, .comment-text p, p');
      return {
        existe: true,
        cuantos: avisos.length,
        texto: avisos[0] ? avisos[0].textContent.trim() : null,
        despuesDelTexto: avisos[0] && texto
          ? !!(texto.compareDocumentPosition(avisos[0]) & Node.DOCUMENT_POSITION_FOLLOWING)
          : null,
      };
    };
    return {
      premiada: caso('QA-PREMIADA'),
      sinPremio: caso('QA-SIN-PREMIO'),
      enEspera: caso('QA-EN-ESPERA'),
      suma: caso('QA-SUMA'),
      enLaPagina: (document.body.innerText.match(/ganó Puntos Akibara por publicarla con foto/g) || []).length,
      desborde: document.documentElement.scrollWidth - window.innerWidth,
    };
  });

  ok(datos.premiada.existe && datos.premiada.cuantos === 1, 'la reseña premiada lleva el aviso, una sola vez', datos.premiada);
  ok((datos.premiada.texto || '').includes(AVISO_TEXTO), 'con el texto acordado', datos.premiada.texto);
  ok(datos.premiada.despuesDelTexto !== false, 'el aviso va después del texto de la reseña', datos.premiada);
  ok(datos.sinPremio.existe && datos.sinPremio.cuantos === 0, 'la reseña sin premio no dice nada', datos.sinPremio);
  ok(datos.enEspera.existe && datos.enEspera.cuantos === 0, 'los puntos en espera todavía no son un premio', datos.enEspera);
  ok(datos.enLaPagina === 1, 'el aviso de «ganó» aparece exactamente una vez en toda la ficha', datos.enLaPagina);
  ok(datos.suma.existe && datos.suma.cuantos === 1 && /sumó para un premio de Puntos Akibara/.test(datos.suma.texto || ''), 'D5 · la que sumó para el premio lo dice con sus palabras', datos.suma);
  ok(datos.desborde <= 0, 'la ficha no desborda en horizontal', datos.desborde);

  // ── D6 · el carrusel del home ─────────────────────────────────────────
  console.log('D6 · el carrusel del home lleva el mismo aviso');
  wp(`update_option( '${CARRUSEL_MIN}', 1 ); update_option( 'akb_rewards_carousel_enabled', 'yes' ); delete_transient( 'akb_rewards_carousel_v2' ); do_action( 'litespeed_purge_all' ); echo wp_json_encode( array( 'ok' => 1 ) );`);
  const home = await page.goto(`${BASE}/`, CARGA);
  ok(home && home.status() === 200, 'el home responde 200', home && home.status());
  await page.waitForTimeout(1500);
  const tarjetas = await page.evaluate(() => [...document.querySelectorAll('.akb-rw-carousel .akb-rw-card')].map((li) => ({
    texto: li.querySelector('.akb-rw-text')?.textContent || '',
    aviso: li.querySelector('.akb-rw-disclosure')?.textContent?.trim() || '',
  })));
  const tarjeta = (marca) => tarjetas.find((t) => t.texto.includes(marca));
  ok(tarjetas.length > 0, `el carrusel se pinta (${tarjetas.length} tarjetas)`, tarjetas.length);
  ok(/ganó Puntos Akibara/.test(tarjeta('QA-PREMIADA')?.aviso || ''), 'la tarjeta premiada lleva el aviso', tarjeta('QA-PREMIADA'));
  ok(/sumó para un premio/.test(tarjeta('QA-SUMA')?.aviso || ''), 'la que sumó, el suyo', tarjeta('QA-SUMA'));
  ok(tarjeta('QA-SIN-PREMIO') && tarjeta('QA-SIN-PREMIO').aviso === '', 'la que no cobró, ninguno', tarjeta('QA-SIN-PREMIO'));
  const desbordeHome = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  ok(desbordeHome <= 0, 'el home no desborda en horizontal', desbordeHome);

  // ── D4 · el interruptor del panel ─────────────────────────────────────
  console.log('D4 · el dueño decide si van a Google');
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

  await page.goto(`${BASE}/wp-admin/admin.php?page=akb-rewards-settings`, CARGA);
  const campo = page.locator(`[name="${OPCION}"]`).first();
  ok(await campo.count() > 0, 'el interruptor está en el panel');

  const fila = limpio(await campo.locator('xpath=ancestor::tr[1]').innerText().catch(() => ''));
  ok(/Reseñas|Google/.test(fila), 'con una explicación en la fila', fila.slice(0, 260));

  const guardar = async (valor) => {
    await page.goto(`${BASE}/wp-admin/admin.php?page=akb-rewards-settings`, CARGA);
    const c = page.locator(`[name="${OPCION}"]`).first();
    const tipo = await c.evaluate((el) => (el.type || el.tagName).toLowerCase());
    if ('checkbox' === tipo) { await c.setChecked('yes' === valor); } else { await c.selectOption(valor); }
    await Promise.all([
      page.waitForLoadState('load').catch(() => {}),
      page.locator('form button[type="submit"], form input[type="submit"]').first().click(),
    ]);
    await page.waitForTimeout(1500);
  };

  await guardar('yes');
  ok(opcion() === 'yes', 'encenderlo se guarda', { leido: opcion() });
  await guardar('no');
  ok(opcion() === 'no', 'y apagarlo también', { leido: opcion() });

  await ctx.close();
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e);
} finally {
  await nav.close();
  const fin = limpiar(S);
  console.log('Limpieza', JSON.stringify(fin));
  if (fin.comentarios !== 0) { fallos += 1; console.error('FAIL: quedaron reseñas de QA en el espejo', fin); }
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
