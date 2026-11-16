// SONDA DE RESTAURACIÓN del gate qa-rewards (operador de la compuerta).
//
// Compara el espejo Docker contra el sello de baseline capturado ANTES de
// correr `qa-rewards.sh todos`, y falla (exit != 0) ante cualquier residuo.
// Es re-ejecutable: `node sonda-restauracion.mjs` compara contra el baseline
// guardado; `node sonda-restauracion.mjs --sellar` re-graba el baseline
// (hacerlo SOLO con el espejo limpio, antes de una corrida).
//
// Por qué existe: los agentes del gate declaran su propia limpieza, pero un
// agente que revienta a medias limpia lo que anotó, no lo que hizo. Esta
// sonda mide el espejo COMPLETO — pedidos, ledger, usuarios, comentarios,
// options de rewards, hash del cliente 229 y stock del tomo que se compra —
// sin creerle a nadie.
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const CWD  = '/Users/alefvaras/Documents/akibara';
const DIR  = new URL( '.', import.meta.url ).pathname;
const SELLAR = process.argv.includes( '--sellar' );

const PRODUCTO = 27347;  // el tomo que compra el agente CLIENTE (consume stock).
const USUARIO_HASH = 229; // cliente cuyo hash el dueño exige intacto.

// wp-cli mezcla avisos de docker compose con el dato: se filtran las líneas
// de « Container …» y las vacías.
const cli = ( cmd ) => execSync(
  `docker compose run --rm -T cli wp ${cmd} --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'pipe' ] }
).split( '\n' ).filter( ( l ) => l.trim() && ! / Container /.test( l ) ).join( '\n' );

const CONSULTA = `
SELECT 'orders' k, COUNT(*) v FROM wp_wc_orders
UNION ALL SELECT 'comments', COUNT(*) FROM wp_comments
UNION ALL SELECT 'users', COUNT(*) FROM wp_users
UNION ALL SELECT 'ledger', COUNT(*) FROM wp_akb_rewards_ledger
UNION ALL SELECT 'review_forms', COUNT(*) FROM wp_akb_rewards_review_forms
UNION ALL SELECT 'qa_orders', COUNT(*) FROM wp_wc_orders WHERE billing_email LIKE '%qa%' OR billing_email LIKE '%test%'
UNION ALL SELECT 'checkout_drafts', COUNT(*) FROM wp_wc_orders WHERE status='wc-checkout-draft'
UNION ALL SELECT 'ledger_admin_adjust', COUNT(*) FROM wp_akb_rewards_ledger WHERE action='admin_adjust'
UNION ALL SELECT 'usermeta_akb', COUNT(*) FROM wp_usermeta WHERE meta_key LIKE '%akb%'
UNION ALL SELECT 'hash229', LEFT(user_pass,60) FROM wp_users WHERE ID=${USUARIO_HASH}
`.replace( /\n/g, ' ' );

const tabla = ( sql ) => {
  const lineas = cli( `db query "${sql}"` ).split( '\n' ).filter( Boolean );
  const filas = {};
  for ( const l of lineas.slice( 1 ) ) {
    const [ k, ...resto ] = l.split( '\t' );
    filas[ k ] = resto.join( '\t' );
  }
  return filas;
};

const opciones = () => {
  const lineas = cli( `db query "SELECT option_name, option_value FROM wp_options WHERE option_name LIKE 'akb_rewards%' AND option_name NOT LIKE '%transient%' ORDER BY option_name"` )
    .split( '\n' ).filter( Boolean ).slice( 1 );
  const o = {};
  for ( const l of lineas ) {
    const [ k, ...resto ] = l.split( '\t' );
    o[ k ] = resto.join( '\t' );
  }
  return o;
};

const stock = () => cli( `eval '$p = wc_get_product(${PRODUCTO}); echo (int) $p->get_stock_quantity() . "|" . $p->get_stock_status();'` ).trim();

// El gap de la pasada 3: 117 correos de QA acumulados en Mailpit sin que
// NINGÚN check del gate midiera el buzón. El conteo entra al sello — un
// gate que termina con más correos de los que encontró no restauró.
const mailpit = () => {
  try {
    const res = JSON.parse(
      execSync( `curl -s 'http://localhost:8025/api/v1/messages?limit=1'`, { encoding: 'utf8' } )
    );
    return String( res.total ?? res.messages_count ?? '?' );
  } catch ( e ) {
    return 'mailpit-inaccesible';
  }
};

const estado = {
  contadores: { ...tabla( CONSULTA ), mailpit: mailpit() },
  opciones: opciones(),
  stock: stock(),
};

const RUTA = `${DIR}baseline-espejo.json`;

if ( SELLAR ) {
  writeFileSync( RUTA, JSON.stringify( estado, null, 2 ) );
  console.log( `baseline sellado en ${RUTA}` );
  process.exit( 0 );
}

if ( ! existsSync( RUTA ) ) {
  console.log( `FALLA: no hay baseline. Corre \`node sonda-restauracion.mjs --sellar\` con el espejo limpio.` );
  process.exit( 2 );
}

const base = JSON.parse( readFileSync( RUTA, 'utf8' ) );
let fallas = 0;
const check = ( nombre, ok, detalle ) => {
  console.log( `${nombre}: ${ok ? 'OK' : 'FALLA'}${detalle ? ' [' + detalle + ']' : ''}` );
  if ( ! ok ) fallas++;
};

// Se recorre la UNIÓN de claves: una métrica que el baseline no trae se
// informa como «no sellada» (no puede fallar, y se dice que no puede) en vez
// de desaparecer del informe sin que nadie lo note.
for ( const k of new Set( [ ...Object.keys( base.contadores ), ...Object.keys( estado.contadores ) ] ) ) {
  const antes = base.contadores[ k ];
  const ahora = estado.contadores[ k ];
  if ( undefined === antes ) {
    console.log( `contador-${k}: NO-SELLADO [ahora=${ahora}] (informativo: re-sella con --sellar)` );
    continue;
  }
  check( `contador-${k}`, String( antes ) === String( ahora ), `antes=${antes} ahora=${ahora}` );
}

check( 'stock-producto-repuesto', base.stock === estado.stock, `antes=${base.stock} ahora=${estado.stock}` );

// Options del CATÁLOGO de SettingsPage que el guardado del panel materializa
// legítimamente con su valor por defecto: el baseline se selló antes de que
// existieran en la base, y tras una vuelta del gate existen. Que APAREZCAN con
// su valor de catálogo es esperado (informativo); que aparezcan con OTRO valor,
// o que cambien una vez ya selladas, sigue siendo falla.
// `akb_rewards_invite_status` = 'completed' → Invitations.php:60 y
// SettingsPage.php:135 (también en gate-bordes/baseline-options.tsv).
const MATERIALIZABLES = { akb_rewards_invite_status: 'completed' };

const nombres = new Set( [ ...Object.keys( base.opciones ), ...Object.keys( estado.opciones ) ] );
let opsDistintas = [];
for ( const n of nombres ) {
  if ( base.opciones[ n ] !== estado.opciones[ n ] ) {
    if ( undefined === base.opciones[ n ] && MATERIALIZABLES[ n ] === estado.opciones[ n ] ) {
      console.log( `option-${n}: MATERIALIZADA [(ausente) → ${estado.opciones[ n ]}] (catálogo de SettingsPage; informativo)` );
      continue;
    }
    opsDistintas.push( `${n}: ${base.opciones[ n ] ?? '(ausente)'} → ${estado.opciones[ n ] ?? '(ausente)'}` );
  }
}
check( 'options-rewards-identicas', 0 === opsDistintas.length, opsDistintas.join( ' ; ' ).slice( 0, 300 ) );

console.log( `RESTAURACION-FALLAS=${fallas}` );
process.exit( fallas > 0 ? 1 : 0 );
