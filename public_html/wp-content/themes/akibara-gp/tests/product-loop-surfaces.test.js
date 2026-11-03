import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

/**
 * La lista de «superficies con rejilla de producto» vivía copiada en tres
 * sitios y las copias derivaron: el dequeue de `wc-add-to-cart` y el encolado
 * del guard se quedaron sin `pa_genero` ni `pa_autor`, así que /genero/ y
 * /autor/ servían el botón «Añadir al carrito» sin el script que lo hace
 * funcionar. La decisión ejecutable se prueba en PageTargetsTest.php; acá se
 * guarda que no vuelva a haber una segunda copia.
 */
const read = ( rel ) => readFileSync( new URL( `../${ rel }`, import.meta.url ), 'utf8' );

const functions = read( 'functions.php' );
const assetDequeue = read( 'inc/asset-dequeue.php' );
const enqueue = read( 'inc/enqueue.php' );
const pageTargets = read( 'inc/page-targets.php' );
const bridge = read( 'assets/js/wc-event-bridge.js' );
const main = read( 'assets/js/main.js' );

test( 'la lista de superficies con rejilla tiene un solo dueño', () => {
	assert.match(
		pageTargets,
		/function akibara_gp_surface_has_product_loop\(\): bool/,
		'El helper es la fuente única.'
	);
	for ( const taxonomy of [ 'product_brand', 'pa_serie', 'pa_genero', 'pa_autor' ] ) {
		assert.match(
			pageTargets,
			new RegExp( `'${ taxonomy }'` ),
			`La lista dejó fuera ${ taxonomy }.`
		);
	}

	// El dequeue vive en inc/asset-dequeue.php desde R3-2c3c4720 (2026-09-19):
	// se extrajo de functions.php para poder probarlo de comportamiento sin
	// cargar los otros ~19 módulos que encadena el bootstrap.
	assert.match( assetDequeue, /akibara_gp_surface_has_product_loop\(\)/ );
	assert.match( enqueue, /\$has_loop = akibara_gp_surface_has_product_loop\(\);/ );

	// Ninguna de las tres copias puede volver a escribir su propia lista.
	for ( const [ name, source ] of [ [ 'functions.php', functions ], [ 'inc/asset-dequeue.php', assetDequeue ], [ 'inc/enqueue.php', enqueue ] ] ) {
		assert.doesNotMatch(
			source,
			/is_tax\(\s*array\(\s*'product_brand',\s*'pa_serie'\s*\)\s*\)/,
			`${ name } volvió a clavar una lista de taxonomías propia.`
		);
	}
} );

test( 'el guard del add-to-cart cubre la misma superficie que el dequeue', () => {
	// atc-guard.js bloquea el segundo request en vuelo: donde hay botón de
	// compra tiene que estar, y su copia drifteada lo dejaba fuera de /genero/.
	assert.match(
		enqueue,
		/if \( \$has_loop \) \{[\s\S]{0,400}akibara-atc-guard/,
		'El guard tiene que colgar del mismo predicado que el dequeue.'
	);
} );

test( 'no se descargan handles de pasarelas que nadie registra', () => {
	// Cuatro `wp_dequeue_script()` apuntaban a handles inexistentes en todo
	// /wp-content: dos plugins que no están instalados y dos que registran
	// otros nombres.
	for ( const handle of [ 'mercadopago-checkout', 'flow-checkout', 'transbank-webpay', 'khipu-checkout' ] ) {
		assert.doesNotMatch(
			functions,
			new RegExp( handle ),
			`El handle ${ handle } no existe en ningún plugin del sitio.`
		);
	}
} );

test( 'el shim jQuery solo re-emite eventos que WooCommerce dispara hoy', () => {
	for ( const evento of [ 'added_to_cart', 'removed_from_cart', 'wc_fragments_refreshed', 'wc_fragments_loaded' ] ) {
		assert.match( bridge, new RegExp( `'${ evento }'` ) );
	}

	// Los seis restantes salen de cart.js/checkout.js. WooCommerce sí los
	// encola en /carrito/ y /checkout/ (medido el 2026-09-19), pero sus
	// manejadores cuelgan de los formularios clásicos y los bloques no pintan
	// ninguno: no se disparan nunca. Cupón y error de pago se leen de `wp.data`
	// (S-b9250562, clarity-commerce-events.js de akibara-business).
	for ( const muerto of [
		'updated_wc_div',
		'updated_cart_totals',
		'item_removed_from_classic_cart',
		'applied_coupon',
		'applied_coupon_in_checkout',
		'checkout_error',
	] ) {
		assert.doesNotMatch(
			bridge,
			new RegExp( `'${ muerto }'` ),
			`${ muerto } no lo dispara ningún fichero de WooCommerce en este sitio.`
		);
		assert.doesNotMatch(
			main,
			new RegExp( `'akb:${ muerto }'` ),
			`main.js escucha ${ muerto }, que el shim ya no re-emite.`
		);
	}
} );

test( 'el carrito de bloques no deja atrás guiones del carrito clásico', () => {
	assert.equal(
		existsSync( new URL( '../assets/js/cart-empty-reload.js', import.meta.url ) ),
		false,
		'cart-empty-reload.js contaba `tr.cart_item`: con el Cart Block leía cero siempre y recargaba en bucle.'
	);
	// Por el HANDLE y no por el nombre del fichero: el comentario que explica
	// por qué se retiró sí lo nombra, y un negativo sobre la palabra suelta
	// chocaría con su propia explicación.
	assert.doesNotMatch( enqueue, /'akibara-cart-empty-reload'/ );
	assert.doesNotMatch(
		main,
		/\.woocommerce-cart-form'\)\) return;/,
		'El auto-update del carrito clásico se retiró con su formulario.'
	);
} );

test( 'el badge de filtros no cuenta el texto de búsqueda ni pierde valores', () => {
	// `_buscar` es param reservado (FilterSet::PARAM_SEARCH), no una faceta:
	// contarlo pintaba «Filtrar (1)» en rojo en 272 de 414 vistas de /tienda/.
	assert.match( main, /var FACET_RESERVED_PARAMS = \['_paged', '_buscar'\];/ );
	assert.match( main, /FACET_RESERVED_PARAMS\.indexOf\(k\) !== -1/ );
	// La gramática de la URL es multi-valor por coma: `?_categoria=shonen,seinen`
	// son DOS filtros.
	assert.match( main, /v\.split\(','\)\.forEach\(/ );
	assert.doesNotMatch(
		main,
		/k\.charAt\(0\) === '_' && k !== '_paged'/,
		'Volvió la cuenta por parámetro con `_paged` como única exclusión.'
	);
} );
