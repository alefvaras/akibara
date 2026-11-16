<?php
/**
 * Candado de las barandas de LiteSpeed; no carga WordPress ni abre red.
 *
 * INVARIANTES QUE FIJA:
 *
 * 1. Ningún camino de LiteSpeed puede encender el combinado de CSS. Combinar
 *    hojas funde el `media='print'` + `onload` con que el tema difiere las no
 *    críticas y se queda con el `media` del primer `<link>`: el carrusel de
 *    editoriales se convirtió en una lista cruda de 1.805 px y fue una caída
 *    real de la portada [2026-08-15].
 *
 *    Las dos barandas históricas (`optm-css_comb`, `optm-js_comb`) actúan dentro
 *    de `Root::conf()`. No bastaban: los consumidores reales cortocircuitan antes
 *    con `defined('LITESPEED_GUEST_OPTM') || $this->conf(...)`, y esa constante la
 *    define Guest Mode para toda visita nueva cuando `guest_optm` está activo. Por
 *    eso la tercera baranda.
 *
 * 2. LiteSpeed no puede apagar la carga diferida nativa de WordPress
 *    (`media-lazy`, 2026-09-14). Con la opción encendida en producción,
 *    `wp_lazy_loading_enabled` daba false y el lazy de LiteSpeed tampoco llegaba
 *    al HTML: /tienda/ bajaba 22 portadas fuera de pantalla, 3.472 KB de imagen
 *    en móvil. Además de la baranda, se comprueba en el fuente del tercero que
 *    el apagado del nativo sigue colgando de ESA opción: si LiteSpeed lo mueve a
 *    otra, la baranda se queda muda y esto se pone rojo.
 *
 * 3. La Store API y la REST de pedidos nunca se cachean (`cache-exc`). Su
 *    comportamiento fino lo cubre
 *    plugins/akibara-business/tests/MuPlugins/LitespeedCartExclusionTest.php;
 *    aquí sólo que está registrada, tarde, y que devuelve las dos rutas.
 *
 * DEUDA QUE CIERRA (2026-09-14). Hasta hoy este guion exigía «las tres barandas,
 * y sólo ellas» y que TODAS devolvieran false. La cuarta (`cache-exc`, 1.1.0)
 * devuelve una lista, así que el guion llevaba rojo desde que se desplegó y
 * nadie lo corría. Ahora cada baranda declara qué debe devolver.
 *
 * Los ids se comparan contra los que declara el propio LiteSpeed: si el tercero
 * renombra una opción, el nombre del filtro deja de coincidir y la baranda se
 * queda muda sin avisar.
 *
 * Uso: php tools/test-litespeed-guardrails.php
 */

declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ . '/' );

$akibara_test_filters = array();

/** @param callable|string $callback */
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $akibara_test_filters;

	akibara_assert(
		! isset( $akibara_test_filters[ $hook ] ),
		sprintf( '«%s» se registra dos veces: la segunda pisaría a la primera en este doble', $hook )
	);

	$akibara_test_filters[ $hook ] = array( $callback, $priority, $accepted_args );
}

/** @param mixed $actual */
function akibara_assert( bool $condition, string $message, $actual = null ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ': ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

require dirname( __DIR__ ) . '/public_html/wp-content/mu-plugins/akibara-litespeed-guardrails.php';

// Constante de LiteSpeed → qué debe devolver la baranda de esa opción, con la
// casilla ACTIVADA como valor guardado (el caso que hay que neutralizar).
$barandas = array(
	'O_OPTM_CSS_COMB' => array( 'optm-css_comb', 'apagada' ),
	'O_OPTM_JS_COMB'  => array( 'optm-js_comb', 'apagada' ),
	'O_GUEST_OPTM'    => array( 'guest_optm', 'apagada' ),
	'O_MEDIA_LAZY'    => array( 'media-lazy', 'apagada' ),
	'O_CACHE_EXC'     => array( 'cache-exc', 'rutas' ),
	'O_CACHE_MOBILE'  => array( 'cache-mobile', 'apagada' ),
);

$esperados = array();
foreach ( $barandas as $baranda ) {
	$esperados[] = 'litespeed_conf_load_option_' . $baranda[0];
}

akibara_assert(
	$esperados === array_keys( $akibara_test_filters ),
	'las seis barandas deben registrarse, en este orden, y sólo ellas',
	array_keys( $akibara_test_filters )
);

foreach ( $barandas as $baranda ) {
	list( $id, $contrato ) = $baranda;
	$hook                  = 'litespeed_conf_load_option_' . $id;
	$registro              = $akibara_test_filters[ $hook ];

	akibara_assert(
		99 === $registro[1],
		sprintf( '«%s» debe correr tarde para ganar a otros filtros', $hook ),
		$registro[1]
	);

	if ( 'apagada' === $contrato ) {
		foreach ( array( true, 1, '1' ) as $guardado ) {
			akibara_assert(
				false === call_user_func( $registro[0], $guardado ),
				sprintf( '«%s» debe devolver false aunque la casilla esté activada (%s)', $hook, var_export( $guardado, true ) ),
				call_user_func( $registro[0], $guardado )
			);
		}
		continue;
	}

	// LiteSpeed guarda varias de sus listas como TEXTO, un elemento por línea —lo
	// que se teclea literalmente en su caja de ajustes—. Con un `is_array()` a
	// secas, ese caso se leía como «no hay nada» y la lista del sitio se PERDÍA:
	// nuestras dos rutas la reemplazaban entera, justo al revés de lo que promete
	// el docblock de la baranda («se añade a lo que hubiera, sin pisarlo»).
	// Cerrado en 1.4.0.
	$desde_texto = call_user_func( $registro[0], "/otra-ruta/\n  /tercera/  \n\n" );
	akibara_assert(
		is_array( $desde_texto )
			&& in_array( '/otra-ruta/', $desde_texto, true )
			&& in_array( '/tercera/', $desde_texto, true )
			&& in_array( '/wp-json/wc/store/', $desde_texto, true ),
		sprintf( '«%s» debe conservar la lista del sitio cuando LiteSpeed la guarda como texto', $hook ),
		$desde_texto
	);

	akibara_assert(
		array( '/wp-json/wc/store/', '/wp-json/wc/v3/' ) === call_user_func( $registro[0], null ),
		sprintf( '«%s» con un valor que no es una lista debe dejar sólo las dos obligatorias', $hook ),
		call_user_func( $registro[0], null )
	);

	$repetida = call_user_func( $registro[0], array( '/wp-json/wc/store/', ' /wp-json/wc/store/ ' ) );
	akibara_assert(
		2 === count( $repetida ),
		sprintf( '«%s» no puede repetir una ruta que ya estaba, con espacios o sin ellos', $hook ),
		$repetida
	);

	$rutas = call_user_func( $registro[0], array( '/otra-ruta/' ) );
	akibara_assert(
		is_array( $rutas )
			&& in_array( '/wp-json/wc/store/', $rutas, true )
			&& in_array( '/wp-json/wc/v3/', $rutas, true )
			&& in_array( '/otra-ruta/', $rutas, true ),
		sprintf( '«%s» debe añadir la Store API y la REST de pedidos sin pisar lo configurado', $hook ),
		$rutas
	);
}

// Contraste con el tercero. Se lee de su fuente, no de una copia nuestra.
$litespeed = dirname( __DIR__ ) . '/public_html/wp-content/plugins/litespeed-cache/src';
if ( is_readable( $litespeed . '/base.cls.php' ) ) {
	$fuente = (string) file_get_contents( $litespeed . '/base.cls.php' );

	foreach ( $barandas as $constante => $baranda ) {
		akibara_assert(
			1 === preg_match( '/const\s+' . $constante . '\s*=\s*\'([^\']+)\'/', $fuente, $coincidencia ),
			sprintf( 'LiteSpeed ya no declara %s: revisar las barandas', $constante )
		);
		akibara_assert(
			$baranda[0] === $coincidencia[1],
			sprintf( '%s vale «%s» y la baranda escucha «%s»', $constante, $coincidencia[1], $baranda[0] )
		);
	}

	// El apagado del lazy nativo tiene que seguir colgando de O_MEDIA_LAZY, y
	// tiene que ser el único: si aparece otro, la baranda ya no basta. Se recorre
	// el plugin ENTERO (src, cli, lib, thirdparty, tpl…), no sólo `src/`: una
	// integración de `thirdparty/` podría apagarlo por su cuenta.
	$apagados = 0;
	$iterador = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( $litespeed ), FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterador as $fichero ) {
		if ( 'php' !== $fichero->getExtension() ) {
			continue;
		}
		$apagados += substr_count( (string) file_get_contents( $fichero->getPathname() ), "'wp_lazy_loading_enabled'" );
	}
	akibara_assert(
		1 === $apagados,
		'LiteSpeed debe tocar `wp_lazy_loading_enabled` en un único sitio; si cambió, revisar la baranda de media-lazy',
		$apagados
	);

	$media = (string) file_get_contents( $litespeed . '/media.cls.php' );
	akibara_assert(
		1 === preg_match(
			'/if\s*\(\s*\$this->conf\(\s*Base::O_MEDIA_LAZY\s*\)[^{]*\{[^}]*add_filter\(\s*\'wp_lazy_loading_enabled\'\s*,\s*\'__return_false\'\s*\)/s',
			$media
		),
		'el apagado del lazy nativo ya no depende de O_MEDIA_LAZY: la baranda de media-lazy no lo impide'
	);

	// Todas las barandas cuelgan del mismo mecanismo: `Root::conf()` dispara
	// `litespeed_conf_load_option_{id}`. Si LiteSpeed lo renombra, las seis se quedan
	// mudas y lo de arriba seguiría en verde.
	akibara_assert(
		1 === preg_match( "/'litespeed_conf_load_option_'\\s*\\.\\s*\\\$id/", (string) file_get_contents( $litespeed . '/root.cls.php' ) ),
		'Root::conf() ya no dispara litespeed_conf_load_option_{id}: todas las barandas quedarían mudas'
	);

	// La trampa del celular: con la opción encendida y sin vary móvil, toda visita
	// móvil cae en `set_nocache( 'is mobile' )`. La baranda sirve mientras la
	// opción apagada siga saliendo de `finalize()` sin marcar nada (salvo que un
	// tercero filtre `litespeed_is_mobile`, cosa que nadie hace en producción).
	$control = (string) file_get_contents( $litespeed . '/control.cls.php' );
	akibara_assert(
		1 === preg_match(
			'/if\s*\(\s*!\s*\$this->conf\(\s*Base::O_CACHE_MOBILE\s*\)\s*\)\s*\{\s*if\s*\(\s*\$is_mobile_conf\s*\)\s*\{\s*self::set_nocache\(\s*\'mobile\'\s*\);\s*\}\s*return;\s*\}/',
			$control
		),
		'con cache-mobile apagado, finalize() ya no sale limpio: revisar la baranda de cache-mobile'
	);
	akibara_assert(
		1 === preg_match( '/\$is_mobile_conf\s*=\s*apply_filters\(\s*\'litespeed_is_mobile\'\s*,\s*false\s*\)/', $control ),
		'el móvil de finalize() ya no se decide con litespeed_is_mobile en false por defecto'
	);
} else {
	fwrite( STDOUT, 'AVISO: litespeed-cache no está en el árbol; no se contrastaron los ids con el tercero' . PHP_EOL );
}

fwrite( STDOUT, 'PASS: barandas de LiteSpeed — combinado apagado también por Guest Optimization, lazy nativo de WordPress intacto, carrito sin caché, celular con caché de página' . PHP_EOL );
