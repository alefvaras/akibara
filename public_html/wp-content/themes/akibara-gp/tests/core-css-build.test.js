'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const cssDir = path.join( __dirname, '..', 'assets', 'css' );

/*
 * `core.css` es la concatenación servida de tokens+fonts+base+header+footer.
 * Los cinco siguen siendo la fuente editable, así que el riesgo evidente es que
 * alguien edite `header.css`, no regenere, y su cambio NO llegue al cliente —
 * un fallo mudo: el fichero de origen se lee perfecto y el sitio sirve lo viejo.
 */
const FUENTES = [ 'tokens', 'fonts', 'base', 'header', 'footer' ];

test( 'core.css está al día con sus cinco ficheros de origen', () => {
	const core = fs.readFileSync( path.join( cssDir, 'core.css' ), 'utf8' );

	FUENTES.forEach( ( nombre ) => {
		const fuente = fs.readFileSync( path.join( cssDir, `${ nombre }.css` ), 'utf8' );

		assert.ok(
			core.includes( `/* --- ${ nombre }.css --- */` ),
			`core.css tiene que incluir el bloque de ${ nombre }.css`
		);
		assert.ok(
			core.includes( fuente.trim() ),
			`core.css está DESACTUALIZADO respecto de ${ nombre }.css. ` +
				'Ejecuta tools/akibara-releases/build-theme-core-css.sh'
		);
	} );
} );

test( 'el orden dentro de core.css es el de dependencias, no alfabético', () => {
	// `tokens` define las variables que consume todo lo demás; si se cuela
	// después, el resto resuelve contra valores que todavía no existen y el sitio
	// pierde su paleta sin que nada falle a gritos.
	const core = fs.readFileSync( path.join( cssDir, 'core.css' ), 'utf8' );
	const posiciones = FUENTES.map( ( n ) => core.indexOf( `/* --- ${ n }.css --- */` ) );

	posiciones.forEach( ( pos, i ) => {
		assert.ok( pos >= 0, `falta el bloque ${ FUENTES[ i ] }` );
		if ( i > 0 ) {
			assert.ok(
				pos > posiciones[ i - 1 ],
				`${ FUENTES[ i ] } tiene que ir después de ${ FUENTES[ i - 1 ] }`
			);
		}
	} );
} );

test( 'los cinco handles siguen registrados como alias para no romper dependencias', () => {
	// Trece sitios del tema y de los plugins declaran `array( 'akibara-base' )` y
	// compañía como dependencia. Si el alias desaparece, WordPress deja de
	// garantizar el orden de impresión de esas hojas y la cascada cambia en
	// silencio.
	const enqueue = fs.readFileSync( path.join( __dirname, '..', 'inc', 'enqueue.php' ), 'utf8' );

	assert.match( enqueue, /wp_enqueue_style\(\s*\n?\s*'akibara-core'/, 'core se encola' );
	FUENTES.forEach( ( nombre ) => {
		assert.ok(
			enqueue.includes( `'akibara-${ nombre }'` ),
			`el handle akibara-${ nombre } tiene que seguir existiendo`
		);
	} );
	assert.match(
		enqueue,
		/wp_register_style\(\s*\$akibara_gp_core_alias,\s*false,\s*array\(\s*'akibara-core'\s*\)/,
		'los alias se registran SIN src: si llevaran fichero volverían las cinco peticiones'
	);
} );
