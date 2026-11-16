/**
 * Utilidades comunes de las sondas del gate de BORDES (resiliencia).
 *
 * Todas las sondas son RE-EJECUTABLES y RESTAURAN lo que siembran.
 * Salen con código != 0 ante cualquier falla.
 *
 * Nota de entorno: el ÚNICO monte visible dentro del contenedor es
 * `public_html/`. Por eso los helpers PHP se escriben en
 * `public_html/qa-gate-bordes/` y se borran al terminar — `/private/tmp` no
 * llega a Docker Desktop.
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync, existsSync } from 'node:fs';
import { join } from 'node:path';

export const REPO = '/Users/alefvaras/Documents/akibara';
export const PHP_DIR = join( REPO, 'public_html', 'qa-gate-bordes' );
export const BASE = 'http://localhost:8080';
export const MAILPIT = 'http://localhost:8025';

/** Ejecuta un comando y devuelve stdout. */
export function sh( cmd, args, opts = {} ) {
	return execFileSync( cmd, args, {
		cwd: REPO,
		encoding: 'utf8',
		maxBuffer: 64 * 1024 * 1024,
		...opts,
	} );
}

/** Corre PHP dentro de WordPress (wp eval-file). Devuelve stdout limpio. */
export function wpEval( name, php ) {
	mkdirSync( PHP_DIR, { recursive: true } );
	const file = join( PHP_DIR, name );
	writeFileSync( file, php, 'utf8' );
	try {
		const out = sh( 'docker', [
			'compose', 'run', '--rm', '-T', 'cli',
			'wp', 'eval-file', `/var/www/html/qa-gate-bordes/${ name }`,
			'--path=/var/www/html',
		], { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
		return out;
	} finally {
		rmSync( file, { force: true } );
	}
}

/** Corre PHP PELADO (sin WordPress cargado) dentro del contenedor. */
export function phpBare( name, php ) {
	mkdirSync( PHP_DIR, { recursive: true } );
	const file = join( PHP_DIR, name );
	writeFileSync( file, php, 'utf8' );
	try {
		return sh( 'docker', [
			'compose', 'run', '--rm', '-T', 'cli',
			'php', `/var/www/html/qa-gate-bordes/${ name }`,
		], { stdio: [ 'ignore', 'pipe', 'pipe' ] } );
	} finally {
		rmSync( file, { force: true } );
	}
}

/** Extrae `CLAVE=valor` de la salida de un helper PHP. */
export function field( out, key ) {
	const m = out.match( new RegExp( `^${ key }=(.*)$`, 'm' ) );
	return m ? m[ 1 ] : null;
}

/** Limpia el directorio de helpers PHP si quedó vacío. */
export function cleanupPhpDir() {
	try {
		if ( existsSync( PHP_DIR ) ) {
			rmSync( PHP_DIR, { recursive: true, force: true } );
		}
	} catch ( e ) { /* ruido de limpieza: no falla la sonda */ }
}

/** GET/POST crudo con curl: mide la superficie SERVIDA, no el código. */
export function http( url, { method = 'GET', form = null } = {} ) {
	const args = [ '-s', '-o', '/dev/stdout', '-w', '\n__HTTP__%{http_code}\n', '-X', method, url ];
	if ( form ) {
		for ( const [ k, v ] of Object.entries( form ) ) {
			args.push( '-F', `${ k }=${ v }` );
		}
	}
	const out = sh( 'curl', args );
	const m = out.match( /__HTTP__(\d+)/ );
	return { status: m ? Number( m[ 1 ] ) : 0, body: out.replace( /\n__HTTP__\d+\n?/, '' ) };
}

/** Lee JSON de la API de Mailpit. */
export function mailpit( path ) {
	return JSON.parse( sh( 'curl', [ '-s', `${ MAILPIT }${ path }` ] ) );
}

/** Acumulador de fallas con salida final. */
export class Gate {
	constructor( nombre ) {
		this.nombre = nombre;
		this.fallas = [];
		this.notas = [];
	}

	check( ok, descripcion, evidencia ) {
		const linea = `${ ok ? 'OK   ' : 'FALLA' } · ${ descripcion } · ${ evidencia }`;
		console.log( linea );
		if ( ! ok ) {
			this.fallas.push( `${ descripcion } — ${ evidencia }` );
		}
		return ok;
	}

	nota( texto ) {
		this.notas.push( texto );
		console.log( `NOTA  · ${ texto }` );
	}

	fin() {
		console.log( `\n=== ${ this.nombre }: ${ this.fallas.length } falla(s) ===` );
		for ( const f of this.fallas ) {
			console.log( `  - ${ f }` );
		}
		cleanupPhpDir();
		process.exit( this.fallas.length === 0 ? 0 : 1 );
	}
}
