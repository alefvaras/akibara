/**
 * Corre TODAS las sondas del gate de bordes EN SERIE (nunca en paralelo: hay un
 * solo espejo). Sale con código != 0 si alguna falla.
 *
 * Uso (cwd = tools/akibara-releases/rewards-qa/):
 *   node gate-bordes/run-all.mjs
 */

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const AQUI = dirname( fileURLToPath( import.meta.url ) );

const SONDAS = [
	'01-vigia-bluex.mjs',
	'02-token-resena-reusado.mjs',
	'03-baja-serie-reusada.mjs',
	'04-action-scheduler-caido.mjs',
	'05-aislamiento-credenciales.mjs',
];

const resultados = [];

for ( const s of SONDAS ) {
	console.log( `\n${ '='.repeat( 72 ) }\n${ s }\n${ '='.repeat( 72 ) }` );
	try {
		execFileSync( process.execPath, [ join( AQUI, s ) ], { stdio: 'inherit' } );
		resultados.push( [ s, 0 ] );
	} catch ( e ) {
		resultados.push( [ s, e.status ?? 1 ] );
	}
}

console.log( `\n${ '='.repeat( 72 ) }\nRESUMEN\n${ '='.repeat( 72 ) }` );
for ( const [ s, code ] of resultados ) {
	console.log( `${ code === 0 ? 'OK   ' : 'FALLA' } · ${ s } (exit=${ code })` );
}

process.exit( resultados.some( ( [ , c ] ) => c !== 0 ) ? 1 : 0 );
