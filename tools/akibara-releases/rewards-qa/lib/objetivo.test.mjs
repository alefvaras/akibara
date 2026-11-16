// node --test tools/akibara-releases/rewards-qa/lib/objetivo.test.mjs
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { ErrorDeUso, cargar, leerFraccion, leerObjetivo } from './objetivo.mjs';

const argv = ( ...args ) => [ 'node', 'sonda.mjs', ...args ];

test( 'una URL válida con viewport por defecto', () => {
	const o = leerObjetivo( argv( 'https://akibara.cl/tienda/' ) );
	assert.deepEqual( o, { url: 'https://akibara.cl/tienda/', ancho: 375, alto: 812, resto: [] } );
} );

test( 'ancho, alto y resto se leen como enteros y cola', () => {
	const o = leerObjetivo( argv( 'http://localhost:8080/', '1280', '800', '--scroll=0.4' ) );
	assert.equal( o.ancho, 1280 );
	assert.equal( o.alto, 800 );
	assert.deepEqual( o.resto, [ '--scroll=0.4' ] );
	assert.equal( leerFraccion( o.resto, '--scroll', 0.6 ), 0.4 );
	assert.equal( leerFraccion( [], '--scroll', 0.6 ), 0.6 );
} );

test( 'la URL con espacios de una variable sin dividir se rechaza', () => {
	assert.throws( () => leerObjetivo( argv( 'https://akibara.cl/tienda/ 375 812' ) ), ErrorDeUso );
} );

test( 'sin URL, URL rota o protocolo raro: error de uso', () => {
	assert.throws( () => leerObjetivo( argv() ), ErrorDeUso );
	assert.throws( () => leerObjetivo( argv( 'no-es-una-url' ) ), ErrorDeUso );
	assert.throws( () => leerObjetivo( argv( 'file:///etc/passwd' ) ), ErrorDeUso );
} );

test( 'viewport no entero o fuera de rango: error de uso', () => {
	assert.throws( () => leerObjetivo( argv( 'https://akibara.cl/', '375.5' ) ), ErrorDeUso );
	assert.throws( () => leerObjetivo( argv( 'https://akibara.cl/', '375', 'alto' ) ), ErrorDeUso );
	assert.throws( () => leerObjetivo( argv( 'https://akibara.cl/', '10' ) ), ErrorDeUso );
	assert.throws( () => leerFraccion( [ '--scroll=2' ], '--scroll', 0.6 ), ErrorDeUso );
} );

test( 'cargar exige un 2xx y traduce los fallos de red a error de uso', async () => {
	const pagina = ( respuesta ) => ( { goto: async () => respuesta } );
	await assert.rejects( cargar( pagina( null ), 'https://x/' ), ErrorDeUso );
	await assert.rejects( cargar( pagina( { ok: () => false, status: () => 404 } ), 'https://x/' ), /404/ );
	await assert.rejects( cargar( { goto: async () => { throw new Error( 'net::ERR_NAME_NOT_RESOLVED' ); } }, 'https://x/' ), /ERR_NAME_NOT_RESOLVED/ );
	const ok = { ok: () => true, status: () => 200 };
	assert.equal( await cargar( pagina( ok ), 'https://x/' ), ok );
} );
