/*
 * Las copias `.min` que el tema SIRVE (PC-1 = PM-4 + PC-7 y woocommerce.css partida
 * por contexto, DS-10 = PC-5 = PM-13; auditoría 2026-09-22).
 *
 * El riesgo es el mismo que ya se pagó con core.css: alguien edita una fuente, no
 * regenera, y el cliente sigue recibiendo la versión vieja mientras la fuente se lee
 * perfecta. Cada copia lleva en su cabecera el sha256 de su fuente y el del
 * generador; aquí se recalculan. También se fija que el reparto por contexto no
 * pierda ni reordene reglas y que PHP y el build hablen de los mismos ficheros.
 */
import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import {
	CSS_MIN,
	CSS_MIN_BUSINESS,
	JS_DESTINO,
	JS_MIN,
	JS_MIN_BUSINESS,
	MARCAS,
	WC_CONTEXTOS,
	contextosDeRegla,
	huella,
	huellaGenerador,
	partirCss,
	partirPorContexto,
} from '../../../../../tools/akibara-releases/build-theme-min-assets.mjs';

const TEMA = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const CSS = join( TEMA, 'assets', 'css' );
const CARPETA_JS = join( TEMA, 'assets', 'js' );
const REGENERAR = 'Ejecuta: bash tools/akibara-releases/build-theme-core-css.sh';
const leer = ( ruta ) => readFileSync( ruta, 'utf8' );
const primeraLinea = ( ruta ) => leer( ruta ).split( '\n', 1 )[ 0 ];

function comprobarCabecera( copia, fuente, contexto = '' ) {
	assert.ok( existsSync( copia ), `falta ${ copia.replace( TEMA, '' ) }. ${ REGENERAR }` );
	const cabecera = primeraLinea( copia );
	const nombre = fuente.split( '/' ).pop();
	assert.match( cabecera, /^\/\*! akibara-gp GENERADO, no editar: /, `${ copia.replace( TEMA, '' ) } no es una copia generada` );
	assert.ok(
		cabecera.includes( `${ nombre } sha256:${ huella( readFileSync( fuente ) ) }` ),
		`${ copia.replace( TEMA, '' ) } está DESACTUALIZADA respecto de ${ nombre }. ${ REGENERAR }`
	);
	assert.ok(
		cabecera.includes( `gen:${ huellaGenerador() }` ),
		`${ copia.replace( TEMA, '' ) } se generó con otra versión de build-theme-min-assets.mjs. ${ REGENERAR }`
	);
	if ( contexto ) {
		assert.ok( cabecera.includes( ` contexto:${ contexto } ` ), `${ copia.replace( TEMA, '' ) } no es la del contexto ${ contexto }` );
	}
}

test( 'cada hoja servida está al día con su fuente y con el generador', () => {
	for ( const nombre of [ ...CSS_MIN, ...CSS_MIN_BUSINESS ] ) {
		comprobarCabecera( join( CSS, `${ nombre }.min.css` ), join( CSS, `${ nombre }.css` ) );
	}
} );

test( 'cada guion servido está al día con su fuente y con el generador', () => {
	for ( const nombre of [ ...JS_MIN, ...JS_MIN_BUSINESS ] ) {
		comprobarCabecera( join( CARPETA_JS, `${ nombre }.min.js` ), join( CARPETA_JS, `${ nombre }.js` ) );
	}
} );

test( 'woocommerce.css se sirve partida en sus cuatro contextos, todos al día', () => {
	for ( const ctx of WC_CONTEXTOS ) {
		comprobarCabecera( join( CSS, `woocommerce-${ ctx }.min.css` ), join( CSS, 'woocommerce.css' ), ctx );
	}
} );

test( 'no queda ninguna copia .min sin fuente ni sin encolar (cero código muerto)', () => {
	const esperadas = new Set( [
		...[ ...CSS_MIN, ...CSS_MIN_BUSINESS ].map( ( n ) => `${ n }.min.css` ),
		...WC_CONTEXTOS.map( ( c ) => `woocommerce-${ c }.min.css` ),
	] );
	for ( const f of readdirSync( CSS ).filter( ( f ) => f.endsWith( '.min.css' ) ) ) {
		assert.ok( esperadas.has( f ), `assets/css/${ f } no la genera el build: sobra` );
	}
	const guiones = new Set( [ ...JS_MIN, ...JS_MIN_BUSINESS ].map( ( n ) => `${ n }.min.js` ) );
	for ( const f of readdirSync( CARPETA_JS ).filter( ( f ) => f.endsWith( '.min.js' ) ) ) {
		assert.ok( guiones.has( f ), `assets/js/${ f } no la genera el build: sobra` );
	}
} );

test( 'PHP encola exactamente lo que el build minifica, por akibara_gp_asset()', () => {
	const php = [ 'inc/enqueue.php', 'functions.php', 'inc/rank-math.php' ].map( ( f ) => leer( join( TEMA, f ) ) ).join( '\n' );
	// `akibara_gp_asset_servido()` cuenta también: es la copia servida que se
	// imprime EN LÍNEA (toque-temprano.js, QAM-2), sin URL que encolar.
	const pedidas = new Set( [ ...php.matchAll( /akibara_gp_asset(?:_servido)?\(\s*'assets\/(css|js)\/([a-z0-9-]+)\.(css|js)'/g ) ].map( ( m ) => `${ m[ 2 ] }.${ m[ 3 ] }` ) );
	const generadas = new Set( [ ...CSS_MIN.map( ( n ) => `${ n }.css` ), 'woocommerce.css', ...JS_MIN.map( ( n ) => `${ n }.js` ) ] );
	for ( const f of pedidas ) {
		assert.ok( generadas.has( f ), `${ f } se encola por akibara_gp_asset() pero el build no genera su .min (se serviría la fuente)` );
	}
	for ( const f of generadas ) {
		assert.ok( pedidas.has( f ), `el build minifica ${ f } pero nadie la encola por akibara_gp_asset()` );
	}
	// Ninguna hoja o guion propio vuelve a encolarse por la ruta cruda.
	assert.doesNotMatch( php, /\$theme_uri \. '\/assets\/(css|js)\/(?!vendor\/)[a-z0-9-]+\.(css|js)'/, 'un asset del tema se encola sin pasar por akibara_gp_asset()' );
	// El contexto de woocommerce.css que elige PHP es uno de los que genera el build.
	const enqueue = leer( join( TEMA, 'inc/enqueue.php' ) );
	const cuerpo = enqueue.split( 'function akibara_gp_woocommerce_css_contexto()' )[ 1 ].split( '\n}\n' )[ 0 ];
	const devueltos = [ ...cuerpo.matchAll( /return '([a-z]+)';/g ) ].map( ( m ) => m[ 1 ] ).sort();
	assert.deepEqual( devueltos, [ ...WC_CONTEXTOS ].sort() );
	assert.match( enqueue, /'assets\/css\/woocommerce-' \. akibara_gp_woocommerce_css_contexto\(\) \. '\.min\.css'/ );
} );

/*
 * T4 (auditoría 2026-09-22): forms.css, akibara-forms.js, brands.css y
 * akibara-brands-filter.js viven en el tema pero los encola akibara-business
 * (Forms y BrandsDirectory). Hasta el lote de cruce (X1) se servían siempre las
 * fuentes comentadas: el build no las minificaba y business no pedía la copia.
 * Aquí se fija que las dos mitades hablen de los mismos ficheros: lo que business
 * encola por ThemeAssetVersion::served_path() es exactamente lo que el build genera
 * para él, y ninguno de los dos se queda con un fichero que el otro no conoce.
 */
test( 'akibara-business pide por served_path() exactamente las copias que el build genera para él', () => {
	const business = join( TEMA, '..', '..', 'plugins', 'akibara-business', 'src', 'Modules' );
	const php = [ 'Forms/Forms.php', 'BrandsDirectory/BrandsDirectory.php' ]
		.map( ( f ) => leer( join( business, f ) ) ).join( '\n' );
	const rutas = [ ...php.matchAll( /\$(?:css|js)_path\s*=\s*'assets\/(css|js)\/([a-z0-9-]+)\.(css|js)';/g ) ].map( ( m ) => `${ m[ 2 ] }.${ m[ 3 ] }` );
	assert.deepEqual(
		[ ...rutas ].sort(),
		[ ...CSS_MIN_BUSINESS.map( ( n ) => `${ n }.css` ), ...JS_MIN_BUSINESS.map( ( n ) => `${ n }.js` ) ].sort(),
		'CSS_MIN_BUSINESS / JS_MIN_BUSINESS no coinciden con lo que encola akibara-business'
	);
	// Cada encolado pide la copia servida y la versiona por ESE fichero, no por la fuente.
	const servidas = [ ...php.matchAll( /\$(css|js)_served = ThemeAssetVersion::served_path\( \$\1_path \);/g ) ];
	assert.equal( servidas.length, rutas.length, 'cada fuente del tema que encola business pasa por served_path()' );
	assert.equal( ( php.match( /ThemeAssetVersion::for_path\( \$(css|js)_served \)/g ) || [] ).length, rutas.length, 'la versión se calcula sobre el fichero servido' );
	assert.doesNotMatch( php, /\$theme_(uri|url) \. \$(css|js)_path/, 'un asset del tema se encola por la ruta cruda (siempre la fuente)' );
	// Y el tema no las encola por su cuenta: serían dos dueños para el mismo fichero.
	for ( const f of [ 'inc/enqueue.php', 'functions.php' ] ) {
		for ( const n of [ ...CSS_MIN_BUSINESS, ...JS_MIN_BUSINESS ] ) {
			assert.doesNotMatch( leer( join( TEMA, f ) ), new RegExp( `akibara_gp_asset\\(\\s*'assets/(css|js)/${ n }\\.` ), `${ n } lo encola business, no el tema` );
		}
	}
} );

test( 'JS_DESTINO sólo nombra guiones que el build genera, y su copia conserva la sintaxis del fuente', () => {
	for ( const [ nombre, destino ] of Object.entries( JS_DESTINO ) ) {
		assert.ok( [ ...JS_MIN, ...JS_MIN_BUSINESS ].includes( nombre ), `JS_DESTINO nombra ${ nombre }, que el build no genera` );
		assert.match( destino, /^es20\d\d$/ );
	}
	// akibara-forms.js usa async/await: con destino es5/es2015 esbuild lo bajaría a
	// generadores con un ayudante (`__async`), otro código que el que se probó.
	const forms = leer( join( CARPETA_JS, 'akibara-forms.min.js' ) );
	assert.match( forms, /async function/ );
	assert.doesNotMatch( forms, /__async|function\*/ );
} );

test( 'el reparto conserva el orden y deja cada regla donde puede casar', () => {
	const css = [
		'/* comentario con { llave */',
		'.a{color:red}',
		'.single-product .b{color:red}',
		'.woocommerce-account .c,.d{color:red}',
		'@media (max-width:10px){.woocommerce-MyAccount-navigation a{x:1}.e{x:2}}',
		'body:not(.woocommerce-cart) .f{color:red}',
		'.woocommerce div.product .g{color:red}',
		'.woocommerce ul.products li.product .h{color:red}',
		'.i::before{content:"}{"}',
		'@keyframes k{from{opacity:0}to{opacity:1}}',
		'.woocommerce-cart .j,.woocommerce-checkout .j{color:red}',
		'.woocommerce-account .woocommerce-order-details{color:red}',
	].join( '\n' );
	const { hojas, conflictos } = partirPorContexto( css );
	assert.deepEqual( conflictos, [] );
	const orden = ( hoja ) => [ ...hoja.matchAll( /\.([a-k])(?![\w-])/g ) ].map( ( m ) => m[ 1 ] ).join( '' );
	// `.c,.d` va a todos (basta un selector sin marca); `:not(.woocommerce-cart)` no es
	// una marca; `div.product` es la ficha y `li.product` no.
	assert.equal( orden( hojas.listado ), 'acdefhi' );
	assert.equal( orden( hojas.ficha ), 'abcdefghi' );
	assert.equal( orden( hojas.carrito ), 'acdefhijj' ); // `.j` sale dos veces: son dos selectores de la misma regla.
	assert.equal( orden( hojas.cuenta ), 'acdefhi' );
	assert.match( hojas.cuenta, /@media \(max-width:10px\)\{\.woocommerce-MyAccount-navigation a\{x:1\}\n\.e\{x:2\}\}/ );
	assert.match( hojas.listado, /@media \(max-width:10px\)\{\.e\{x:2\}\}/ );
	assert.ok( hojas.listado.includes( '.i::before{content:"}{"}' ), 'una llave dentro de una cadena no corta la regla' );
	for ( const ctx of WC_CONTEXTOS ) {
		assert.ok( hojas[ ctx ].includes( '@keyframes k' ), `@keyframes va a ${ ctx }` );
	}
	// El detalle de un pedido sale en «pedido recibido» y en Mi cuenta → Ver pedido.
	assert.ok( hojas.cuenta.includes( '.woocommerce-account .woocommerce-order-details' ) );
	assert.ok( ! hojas.carrito.includes( '.woocommerce-account .woocommerce-order-details' ), 'la marca de <body> de cuenta manda sobre el componente compartido' );
} );

test( 'en la hoja real ninguna regla se pierde y lo compartido va a los cuatro contextos', () => {
	const fuente = leer( join( CSS, 'woocommerce.css' ) );
	const { reglas, total, conflictos } = partirPorContexto( fuente );
	assert.deepEqual( conflictos, [], 'hay selectores con marcas de contextos incompatibles: revisa MARCAS' );

	let sinContexto = 0;
	const recorrer = ( items ) => items.forEach( ( it ) => {
		if ( 'grupo' === it.tipo ) {
			return recorrer( it.hijos );
		}
		if ( 'regla' === it.tipo && 0 === contextosDeRegla( it.preludio ).contextos.length ) {
			sinContexto++;
		}
	} );
	recorrer( partirCss( fuente ) );
	assert.equal( sinContexto, 0, 'toda regla tiene que caer en al menos un contexto' );
	for ( const ctx of WC_CONTEXTOS ) {
		assert.ok( reglas[ ctx ] > 0 && reglas[ ctx ] <= total );
	}

	// Lo que se ve en todas partes (avisos, estrellas, botón de las tarjetas) va en cada copia.
	for ( const ctx of WC_CONTEXTOS ) {
		const hoja = leer( join( CSS, `woocommerce-${ ctx }.min.css` ) );
		for ( const selector of [ '.woocommerce-info,.woocommerce-message,.woocommerce-error', '.star-rating', '.woocommerce ul.products li.product' ] ) {
			assert.ok( hoja.includes( selector ), `${ selector } falta en woocommerce-${ ctx }.min.css` );
		}
	}
	// Y lo atado a otra plantilla no viaja al listado.
	const listado = leer( join( CSS, 'woocommerce-listado.min.css' ) );
	for ( const ajeno of [ '.woocommerce-MyAccount-navigation', '.woocommerce-tabs', '.woocommerce-cart-form', '.akb-sticky-atc__btn' ] ) {
		assert.ok( ! listado.includes( ajeno ), `${ ajeno } no debería viajar al listado` );
	}
} );

/*
 * Revisión T6 (marca `akb-sticky-atc__btn--preorder`): una marca de un componente
 * PROPIO que ya no sale en ninguna regla es código muerto del build (la clase se
 * retiró con DS-2 y la marca siguió). Las clases de <body> y los componentes de
 * WooCommerce son vocabulario del tercero y pueden no tener regla hoy; las
 * `akb-*` son nuestras: o tienen consumidor en woocommerce.css o sobran.
 */
test( 'cada marca de un componente propio (akb-*) tiene una regla que la use', () => {
	const hoja = leer( join( CSS, 'woocommerce.css' ) ).replace( /\/\*[\s\S]*?\*\//g, '' );
	const escapar = ( t ) => t.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	const sinUso = Object.keys( MARCAS )
		.filter( ( m ) => /^#?akb-/.test( m ) )
		.filter( ( m ) => ! new RegExp( ( m.startsWith( '#' ) ? '' : '\\.' ) + escapar( m ) + '(?![\\w-])' ).test( hoja ) );
	assert.deepEqual( sinUso, [], 'marcas sin consumidor en woocommerce.css: bórralas de MARCAS' );
} );
