'use strict';

const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const test = require( 'node:test' );

const raiz = path.resolve( __dirname, '..' );
const leer = ( f ) => fs.readFileSync( path.join( raiz, f ), 'utf8' );
// Aserciones NEGATIVAS contra el código SIN comentarios: el comentario que
// documenta la historia del context-aware menciona justo los literales que
// aquí se prohíben (lección aprendida tres veces el 2026-08-29).
const sinComentarios = ( s ) => s.replace( /\/\*[\s\S]*?\*\/|\/\/[^\n]*/g, '' );

const shell = leer( 'template-parts/header/shell.php' );
const drawer = leer( 'template-parts/header/mobile-drawer.php' );

test( 'el buscador del header busca SIEMPRE en toda la tienda', () => {
	// Decisión del dueño (2026-08-29): «el buscador no debería buscar en los
	// productos de una página en particular; para eso están los filtros».
	// Antes, un action «context-aware» retenía la búsqueda en el archive:
	// /comics/?_buscar=jjk daba 0 resultados en silencio con 24 tomos a un
	// paso en /tienda/.
	assert.match( shell, /\$akb_search_action\s*=\s*home_url\( '\/tienda\/' \);/ );
	assert.match( drawer, /\$akb_mobile_action\s*=\s*home_url\( '\/tienda\/' \);/ );

	// Sin reasignación condicional: ningún archive cambia el destino.
	assert.doesNotMatch( sinComentarios( shell ), /is_product_taxonomy|is_shop\(\)/ );
	assert.doesNotMatch( sinComentarios( drawer ), /is_product_taxonomy|is_shop\(\)/ );
} );

test( 'el header no adjunta contexto de archive a la búsqueda', () => {
	// Los data-attrs de contexto hacían que el desplegable contara resultados
	// del archive mientras el usuario esperaba los de la tienda entera.
	assert.doesNotMatch( sinComentarios( shell ), /akibara_search_facets_context/ );
	assert.doesNotMatch( sinComentarios( drawer ), /akibara_search_facets_context/ );
	// Antes esto se fijaba mirando una variable que valía siempre '' y se
	// imprimía igual: código muerto con su `phpcs:ignore` intacto, que es lo
	// que el hallazgo S-75bf9f17 mandó retirar. La invariante de verdad es que
	// la etiqueta <form> del buscador no lleva NINGÚN atributo interpolado.
	assert.doesNotMatch( sinComentarios( shell ), /_ctx_attr/ );
	assert.doesNotMatch( sinComentarios( drawer ), /_ctx_attr/ );
	assert.match( shell, /<form class="akibara-search" role="search" method="get" action="<\?php echo esc_url\( \$akb_search_action \); \?>" aria-label="Buscar productos">/ );
	assert.match( drawer, /<form class="mobile-drawer__search" role="search" method="get" action="<\?php echo esc_url\( \$akb_mobile_action \); \?>" aria-label="Buscar productos">/ );
} );

test( 'el cajón de facetas CONSERVA su contexto: eso son los filtros', () => {
	// El alcance por página es trabajo del drawer de filtros, no del buscador.
	// Este test protege la otra mitad de la decisión: que nadie «limpie» el
	// contexto también donde sí corresponde.
	const facets = fs.readFileSync(
		path.join( raiz, '../../plugins/akibara-search/frontend/facets-render.php' ),
		'utf8'
	);
	assert.match( facets, /akibara_search_facets_context/ );
} );

test( 'el buscador del 404 va al MISMO motor que el del header', () => {
	// Hallazgo S-1e205397: apuntaba a la búsqueda nativa de WordPress (`?s=`
	// sobre la portada) mientras el comentario de al lado prometía el motor
	// propio. Quien cae en un 404 y busca «naruto» merece los mismos
	// resultados que desde el header.
	const cuatrocuatro = fs.readFileSync( path.join( raiz, '404.php' ), 'utf8' );

	assert.match( cuatrocuatro, /name="_buscar"/ );
	assert.doesNotMatch( sinComentarios( cuatrocuatro ), /name="s"/ );
	assert.doesNotMatch( sinComentarios( cuatrocuatro ), /name="post_type"/ );
	assert.match( cuatrocuatro, /action="<\?php echo esc_url\( \$akb_shop_url \); \?>"/ );
} );
