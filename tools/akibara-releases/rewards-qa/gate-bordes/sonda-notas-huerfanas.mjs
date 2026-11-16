// SONDA — notas de pedido HUÉRFANAS (residuo real que el gate no ve).
//
// Hallazgo del gate del 2026-09-01: `agente-cliente.mjs` borra su pedido QA
// con `$order->delete( true )`, y su propio `limpieza-verificada` da OK —
// pero las notas del pedido viven en `wp_comments` (comment_type=order_note,
// comment_post_ID = id del pedido) y ese borrado NO las toca. Cada vuelta
// completa del gate deja 8 filas huérfanas apuntando a un pedido que ya no
// existe. Medido: 251 acumuladas antes de la limpieza manual de esta ronda,
// en bloques de 8 que calzan con cada corrida de agente-cliente.
//
// Por qué importa: `limpieza-verificada: OK` es la promesa de «el espejo
// quedó como estaba». Mientras esta sonda encuentre huérfanas nuevas, esa
// promesa es falsa y la basura se acumula corrida a corrida.
//
// Uso:
//   node sonda-notas-huerfanas.mjs            → falla si hay CUALQUIER huérfana
//   node sonda-notas-huerfanas.mjs --tolerar N → falla si hay más de N
//                                               (N = deuda histórica aceptada)
import { execSync } from 'node:child_process';

const CWD = '/Users/alefvaras/Documents/akibara';
const i = process.argv.indexOf( '--tolerar' );
const TOLERADAS = i > -1 ? parseInt( process.argv[ i + 1 ] || '0', 10 ) : 0;

const q = ( sql ) => execSync(
  `docker compose run --rm -T cli wp db query "${sql}" --path=/var/www/html`,
  { cwd: CWD, encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'pipe' ] }
).split( '\n' ).filter( ( l ) => l.trim() && ! / Container /.test( l ) );

// HPOS: los pedidos viven en wp_wc_orders, no en wp_posts. Una nota es
// huérfana cuando su comment_post_ID no existe en NINGUNA de las dos tablas
// (wp_posts cubre instalaciones sin HPOS y los pedidos legacy).
const SQL_TOTAL = `SELECT COUNT(*) FROM wp_comments c WHERE c.comment_type='order_note' AND NOT EXISTS (SELECT 1 FROM wp_wc_orders o WHERE o.id=c.comment_post_ID) AND NOT EXISTS (SELECT 1 FROM wp_posts p WHERE p.ID=c.comment_post_ID)`;
const SQL_DETALLE = `SELECT c.comment_post_ID, COUNT(*) n, MIN(c.comment_date) desde FROM wp_comments c WHERE c.comment_type='order_note' AND NOT EXISTS (SELECT 1 FROM wp_wc_orders o WHERE o.id=c.comment_post_ID) AND NOT EXISTS (SELECT 1 FROM wp_posts p WHERE p.ID=c.comment_post_ID) GROUP BY c.comment_post_ID ORDER BY c.comment_post_ID DESC LIMIT 10`;

const total = parseInt( q( SQL_TOTAL )[ 1 ] || '0', 10 );
const detalle = q( SQL_DETALLE ).slice( 1 );

console.log( `notas-huerfanas: ${total} (toleradas: ${TOLERADAS})` );
for ( const l of detalle ) {
  const [ pedido, n, desde ] = l.split( '\t' );
  console.log( `  pedido ${pedido} inexistente → ${n} notas vivas (desde ${desde})` );
}

const ok = total <= TOLERADAS;
console.log( `espejo-sin-notas-huerfanas: ${ok ? 'OK' : 'FALLA'}` );
console.log( `NOTAS-HUERFANAS=${total}` );
process.exit( ok ? 0 : 1 );
