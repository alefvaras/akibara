#!/usr/bin/env node
/**
 * Drenaje de la deuda viva del buscador, medido como lo ve el cliente.
 *
 * Un cambio editorial ensucia el catálogo entero (`stage_locked()` →
 * `mark_live_dirty_many()`). Hasta 0.1.63 el bombeo publicaba UNA generación de
 * índice por producto: 2.304 escrituras de options en 27 s y el `suggest`
 * alternando 200/503 entre el 30 % y el 60 % de las peticiones. Esta sonda mide
 * las tres cosas que importan mientras drena: cuántas generaciones distintas se
 * publican, cuántos 503 ve un cliente que busca, y si el índice queda idéntico.
 *
 * Uso:  node tools/akibara-releases/business-qa/qa-buscador-drenaje-vivo.mjs
 *
 * Deja el espejo como lo encontró (options restauradas y verificadas).
 */
import { execSync, spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';

const CWD = '/Users/alefvaras/Documents/akibara';
const BASE = process.env.AKB_BASE || 'http://localhost:8080';
const CLAVE = (readFileSync(`${CWD}/compose.yaml`, 'utf8').match(/WORDPRESS_DB_PASSWORD:\s*(\S+)/) || [])[1];
const USUARIO = (readFileSync(`${CWD}/compose.yaml`, 'utf8').match(/WORDPRESS_DB_USER:\s*(\S+)/) || [])[1];
const BASE_DATOS = (readFileSync(`${CWD}/compose.yaml`, 'utf8').match(/WORDPRESS_DB_NAME:\s*(\S+)/) || [])[1];
if (!CLAVE || !USUARIO || !BASE_DATOS) { throw new Error('no se pudo leer el acceso local del compose.yaml'); }

let fallos = 0;
const ok = (cond, msg, dato) => {
  if (cond) { console.log(`  ok  ${msg}`); return; }
  fallos += 1;
  console.error(`FAIL: ${msg}${dato === undefined ? '' : ` → ${JSON.stringify(dato).slice(0, 500)}`}`);
};

/**
 * Consulta directa a la base: barata (~50 ms) para muestrear a 4 Hz, donde un
 * `wp eval` (1,5 s por llamada) se perdería justo las publicaciones que cuenta.
 * El cliente del contenedor es `mariadb`; `mysql` no existe en esa imagen.
 */
const sql = (consulta) => execSync(
  `docker compose exec -T -e MYSQL_PWD=${CLAVE} db mariadb -N -B -u${USUARIO} ${BASE_DATOS} -e ${JSON.stringify(consulta)}`,
  { cwd: CWD, encoding: 'utf8' }
).trim();

const wp = (codigo) => {
  const salida = execSync('docker compose exec -T cron wp eval-file -', { cwd: CWD, encoding: 'utf8', maxBuffer: 1 << 24, input: `<?php\n${codigo}` });
  const i = salida.indexOf('{');
  if (i < 0) { throw new Error(`sin JSON: ${salida.slice(0, 300)}`); }
  let nivel = 0, texto = false, escape = false;
  for (let j = i; j < salida.length; j += 1) {
    const c = salida[j];
    if (escape) { escape = false; continue; }
    if (c === '\\') { escape = true; continue; }
    if (c === '"') { texto = !texto; continue; }
    if (texto) { continue; }
    if (c === '{') { nivel += 1; }
    if (c === '}') { nivel -= 1; if (nivel === 0) { return JSON.parse(salida.slice(i, j + 1)); } }
  }
  throw new Error('JSON incompleto');
};

const generacion = () => sql("SELECT option_value FROM wp_options WHERE option_name='akb_search_index_gen'");
const vivas = () => Number(sql("SELECT COUNT(*) FROM wp_akb_search_work WHERE scope='live' AND status IN ('pending','leased')"));
const docs = () => Number(sql('SELECT COUNT(*) FROM wp_akb_search_docs'));
const suggest = () => Number(execSync(`curl -s -o /dev/null -w '%{http_code}' -A AkibaraQA '${BASE}/wp-json/akibara/v1/search/suggest?q=naruto'`, { encoding: 'utf8' }));
const estado = () => wp(`
  wp_cache_flush();
  echo wp_json_encode( array(
    'sirve' => (bool) \\Akibara\\Search\\EditorialDiscountPublication::serving_ready(),
    'seq'   => (int) ( \\Akibara\\Search\\EditorialDiscountPublication::status()['wanted_seq'] ?? 0 ),
  ) );
`);

/**
 * Los clientes que buscan mientras drena. Son DOS, con IP distinta (el
 * contenedor cron y el propio contenedor web contra 127.0.0.1), y cada uno va a
 * ~57 búsquedas/min: el suggest limita a 60/min por IP, y una sonda única a 4 Hz
 * medía su propio 429 en vez del buscador (2026-09-14: 144 de 149 «caídas»
 * eran eso). Un 429 aquí es un defecto de la sonda y se informa aparte. Su
 * tráfico es además lo que despierta `wp-cron` en el espejo, que es quien bombea.
 */
// `kill()` sobre el `docker compose exec` NO mata el bucle de dentro del
// contenedor: las sondas de corridas anteriores seguían vivas gastando el cupo
// de su IP y esta corrida medía su 429. Se barren al empezar y al terminar.
const barrerSondas = () => ['cron', 'wordpress'].forEach((servicio) => {
  // La marca es un literal sin metacaracteres: `pkill -f` usa regex, y con
  // «suggest?q=» el `?` volvía opcional la «t» y no mataba nada.
  try { execSync(`docker compose exec -T ${servicio} sh -c 'pkill -f akb-sonda-drenaje || true'`, { cwd: CWD, stdio: 'ignore' }); } catch { /* sin sondas vivas */ }
});
// Cada búsqueda imita al cliente real desde 0.1.64 (`search-suggest.js`): ante un
// 503 `index_unavailable` reintenta a 1 s, 2 s y 4 s —cuatro intentos, el último
// a +7 s del primer 503— antes de rendirse; un 429 no se reintenta. La línea deja el
// código FINAL (lo que ve el cliente), los intentos y cuántos 503 crudos pagó.
const abrirSonda = (servicio, url, desfase) => spawn('docker', ['compose', 'exec', '-T', servicio, 'sh', '-c',
  `: akb-sonda-drenaje; sleep ${desfase}; i=0; while [ $i -lt 700 ]; do t=$(date +%s); n=0; crudos=0; for espera in 1 2 4 fin; do n=$((n+1)); c=$(curl -s -o /dev/null -w "%{http_code}" -H "Host: localhost:8080" "${url}/wp-json/akibara/v1/search/suggest?q=naruto"); [ "$c" = 503 ] || break; crudos=$((crudos+1)); [ "$espera" = fin ] && break; sleep $espera; done; echo "$t $c $n $crudos"; i=$((i+1)); sleep 1.05; done`,
], { cwd: CWD, stdio: ['ignore', 'pipe', 'ignore'] });

const OPCIONES = ['akibara_taxonomy_discount_enabled', 'akibara_taxonomy_discount_from', 'akibara_taxonomy_discount_to'];
const filas = () => wp(`
  global $wpdb; $out = array();
  foreach ( ${JSON.stringify(OPCIONES)} as $o ) {
    $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $o ) );
    $out[ $o ] = null === $v ? null : (string) $v;
  }
  echo wp_json_encode( array( 'filas' => $out ) );
`).filas;

const esperar = (ms) => new Promise((r) => setTimeout(r, ms));

console.log('D0 · línea base');
for (let i = 0; i < 360 && (0 !== vivas() || 200 !== suggest()); i += 1) { await esperar(500); }
const ORIGINAL = filas();
const DOCS_ANTES = generacion() && docs();
ok(0 === vivas(), 'no queda deuda viva de una corrida anterior', { vivas: vivas() });
ok(200 === suggest(), 'el autocompletado responde antes de tocar nada');

barrerSondas();
const respuestas = [];
const sondas = [abrirSonda('cron', 'http://wordpress', 0), abrirSonda('wordpress', 'http://127.0.0.1', 0.5)];
sondas.forEach((sonda) => sonda.stdout.on('data', (b) => {
  String(b).trim().split('\n').filter(Boolean).forEach((linea) => {
    const [t, c, n, crudos] = linea.trim().split(' ');
    respuestas.push({ t: Number(t), c: Number(c), n: Number(n), crudos: Number(crudos) });
  });
}));
const ahoraBase = () => Number(sql('SELECT UNIX_TIMESTAMP()'));

// Todo lo que el gate público lee en su foto doble. Un 503 sin generación nueva
// sólo puede venir de que alguna de éstas cambió (o de que la foto no cuadra):
// se registra CADA cambio con la hora de la base para ponerlo al lado de los 503.
const VIGILADAS_GATE = [
  'akb_search_index_gen', 'akb_search_editorial_discount_state', 'akb_search_editorial_discount_receipt',
  'akb_search_semantic_fingerprint', 'akb_search_rest_purge_receipt', 'akb_search_rebuild_state',
  'akb_search_generation_outbox', 'akb_search_pending_rebuild', 'akb_search_db_version', 'akb_search_schema_seal',
];
const huellas = () => {
  const filas = sql(`SELECT UNIX_TIMESTAMP(), option_name, MD5(option_value) FROM wp_options WHERE option_name IN ('${VIGILADAS_GATE.join("','")}')`);
  const mapa = {}; let t = 0;
  filas.split('\n').filter(Boolean).forEach((l) => { const [ts, n, h] = l.split('\t'); t = Number(ts); mapa[n] = h; });
  return { t, mapa };
};
const generaciones = new Set([generacion()]);
const cambios = [];
let previa = huellas().mapa;
const reloj = setInterval(() => {
  try {
    generaciones.add(generacion());
    const { t, mapa } = huellas();
    for (const n of VIGILADAS_GATE) { if (mapa[n] !== previa[n]) { cambios.push({ t, n }); } }
    previa = mapa;
  } catch { /* la muestra que falla no miente: se descarta */ }
}, 250);

/** Tramos de búsquedas caídas, con lo que cambió en el gate en ese segundo o el anterior. */
const informeTramos = (lista, origen) => {
  const tramos = [];
  for (const r of lista) {
    if (200 === r.c) { continue; }
    const u = tramos[tramos.length - 1];
    if (u && r.t - u.hasta <= 1) { u.hasta = r.t; u.n += 1; u.codigos.add(r.c); } else { tramos.push({ desde: r.t, hasta: r.t, n: 1, codigos: new Set([r.c]) }); }
  }
  const t0 = origen;
  for (const tr of tramos) {
    const cerca = cambios.filter((c) => c.t >= tr.desde - 1 && c.t <= tr.hasta).map((c) => `${c.t - t0}s ${c.n.replace('akb_search_', '')}`);
    console.log(`     caída +${tr.desde - t0}s..+${tr.hasta - t0}s · ${tr.n} búsquedas · códigos ${[...tr.codigos].join('/')} · cambios: ${cerca.length ? [...new Set(cerca)].join(', ') : 'ninguno'}`);
  }
  const porOption = {};
  cambios.forEach((c) => { porOption[c.n] = (porOption[c.n] || 0) + 1; });
  console.log(`     escrituras vistas en el gate durante la corrida: ${JSON.stringify(porOption)}`);
};

try {
  console.log('D1 · un cambio editorial que NO mueve ningún precio');
  // La ventana sigue apagada, así que ningún producto cambia de precio; lo que
  // cambia es la revisión editorial, y eso ensucia el catálogo entero.
  const nuevaFecha = '2020-01-02';
  await esperar(4000); // unos segundos de búsquedas sanas antes del guardado
  const guardadoEn = ahoraBase();
  const aplicado = wp(`
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
    $r = update_option( 'akibara_taxonomy_discount_from', '${nuevaFecha}' );
    echo wp_json_encode( array( 'devuelto' => $r, 'ahora' => (string) get_option( 'akibara_taxonomy_discount_from' ) ) );
  `);
  ok(nuevaFecha === aplicado.ahora, 'el cambio entró', aplicado);

  const sucias = await (async () => {
    for (let i = 0; i < 60; i += 1) { const n = vivas(); if (0 < n) { return n; } await esperar(500); }
    return 0;
  })();
  ok(0 < sucias, 'el cambio deja deuda viva que drenar', { sucias });

  const t0 = Date.now();
  const genAntes = generaciones.size;
  let drenado = false;
  for (let i = 0; i < 600 && !drenado; i += 1) { drenado = 0 === vivas(); if (!drenado) { await esperar(500); } }
  const segundos = Math.round((Date.now() - t0) / 1000);
  ok(drenado, `la cola viva drena (${segundos} s, ${sucias} productos)`, { vivas: vivas() });

  respuestas.sort((a, b) => a.t - b.t);
  const durante = respuestas.filter((r) => r.t >= guardadoEn - 2);
  const caidas = durante.filter((r) => 200 !== r.c).length;
  const limitadas = durante.filter((r) => 429 === r.c).length;
  const crudos = durante.reduce((a, r) => a + r.crudos, 0);
  const rescatadas = durante.filter((r) => 200 === r.c && r.crudos > 0).length;
  const publicadas = generaciones.size - genAntes;
  console.log(`     medido: ${durante.length} búsquedas de cliente, ${caidas} sin respuesta tras reintentar; ${crudos} respuestas 503 crudas (${rescatadas} búsquedas rescatadas por el reintento); ${publicadas} generaciones publicadas`);
  console.log(`     línea de tiempo desde el guardado (+0s): ${cambios.filter((c) => c.t >= guardadoEn - 1).map((c) => `+${c.t - guardadoEn}s ${c.n.replace('akb_search_', '')}`).join(', ') || 'sin escrituras en el gate'}`);
  if (caidas) { informeTramos(durante, guardadoEn); }
  ok(0 === limitadas, 'la sonda no se limita a sí misma (ningún 429)', { limitadas });
  ok(0 === caidas, 'el cliente no ve ni una búsqueda caída mientras drena', { caidas, de: durante.length });
  ok(0 === publicadas, 'y no se publica ninguna generación, porque ningún precio cambió', { publicadas });
  ok(DOCS_ANTES === docs(), 'el índice conserva exactamente los mismos documentos', { antes: DOCS_ANTES, ahora: docs() });

  const fin = estado();
  ok(fin.sirve && 200 === suggest(), 'el buscador queda atendiendo', fin);

  console.log('D2 · el reconciliador no encuentra nada rancio');
  execSync('docker compose exec -T cron wp akb-search reconcile', { cwd: CWD, encoding: 'utf8' });
  await esperar(2000);
  ok(0 === vivas(), 'tras reconciliar no reaparece deuda viva', { vivas: vivas() });
} catch (e) {
  fallos += 1;
  console.error('FAIL: excepción', e.message);
} finally {
  clearInterval(reloj);
  sondas.forEach((sonda) => sonda.kill());
  barrerSondas();
  console.log('Restauración');
  wp(`
    $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
    wp_set_current_user( (int) ( $admins[0] ?? 1 ) );
    ${Object.entries(ORIGINAL).map(([o, v]) => (
      null === v ? `delete_option( '${o}' );` : `update_option( '${o}', '${String(v).replace(/'/g, "\\'")}' );`
    )).join('\n')}
    echo wp_json_encode( array( 'ok' => 1 ) );
  `);
  for (let i = 0; i < 360 && (0 !== vivas() || 200 !== suggest()); i += 1) { await esperar(500); }
  ok(JSON.stringify(filas()) === JSON.stringify(ORIGINAL), 'las options vuelven a como estaban', { esperado: ORIGINAL, ahora: filas() });
  ok(0 === vivas() && 200 === suggest(), 'y el espejo queda sin deuda y atendiendo');
}

console.log(fallos ? `\n${fallos} FALLO(S)` : '\nPASS');
process.exit(fallos ? 1 : 0);
