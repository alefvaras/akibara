<?php
/**
 * Bootstrap de las pruebas PHP de Akibara Search.
 *
 * Suite focal del contrato durable del buscador. El runner `qa-search.sh`
 * descubre dinámicamente estos tests y los JavaScript presentes, de modo que
 * no conserva rutas históricas a archivos retirados.
 *
 * Reusa el vendor ya instalado de akibara-business (mismo PHPUnit, mismo Brain
 * Monkey) en vez de abrir un segundo árbol de dependencias, igual que
 * `tools/akibara-gp/phpunit.xml.dist`.
 *
 * Carga por `require_once` explícito y no por el autoloader del plugin: el
 * bootstrap de `akibara-search.php` exige WordPress cargado (`plugin_dir_path`,
 * `add_action`) y acá se prueban clases sueltas contra dobles.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

$repo_root = dirname( __DIR__, 5 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $repo_root . '/public_html/' );
defined( 'AKIBARA_SEARCH_TESTING' ) || define( 'AKIBARA_SEARCH_TESTING', true );

// Las constantes de tiempo de WordPress (wp-includes/default-constants.php). Las
// definían, cada una por su cuenta, cuatro clases de prueba; las demás que las
// usan pasaban sólo porque PHPUnit carga todos los ficheros de prueba antes de
// correr ninguno, y caían con «Undefined constant» al correrlas solas (2026-09-19).
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );

// Y los formatos de salida de wpdb (wp-includes/class-wpdb.php). Mismo caso: sin
// `ARRAY_A` el motor cae en su rama «indisponible» y una prueba del motor corrida
// sola medía una lista vacía en vez de lo que dice medir.
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
defined( 'OBJECT_K' ) || define( 'OBJECT_K', 'OBJECT_K' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );

$autoload = $repo_root . '/tools/akibara-business/vendor/autoload.php';
if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException(
		'Falta el tooling local. Ejecuta ./tools/akibara-business/install.sh desde la raíz del proyecto.'
	);
}
require_once $autoload;

/*
 * Patchwork ANTES de declarar nada redefinible. Brain Monkey lo carga
 * perezosamente en su primer `Monkey\setUp()`; cualquier función declarada
 * antes de ese momento queda fuera de su instrumentación y ya no se puede
 * mockear (`DefinedTooEarly`).
 */
require_once $repo_root . '/tools/akibara-business/vendor/antecedent/patchwork/Patchwork.php';

require_once __DIR__ . '/Support/wp-function-stubs.php';
require_once __DIR__ . '/Support/FakeWpdb.php';

require_once dirname( __DIR__ ) . '/src/Install.php';
require_once dirname( __DIR__ ) . '/src/OptionLock.php';
require_once dirname( __DIR__ ) . '/src/GapDismissals.php';
require_once dirname( __DIR__ ) . '/src/DemandGaps.php';
require_once dirname( __DIR__ ) . '/src/Support/FailsClosed.php';
require_once dirname( __DIR__ ) . '/src/Support/OptionCodec.php';
require_once dirname( __DIR__ ) . '/src/Support/RestCache.php';
// RebuildJob y Reconciler le preguntan cuánto dura su memo de «ya agendado» (S-3e1f3f03).
require_once dirname( __DIR__ ) . '/src/Support/RecurringMemo.php';
require_once dirname( __DIR__ ) . '/src/Synonyms.php';
require_once dirname( __DIR__ ) . '/src/Stopwords.php';
require_once dirname( __DIR__ ) . '/src/Fuzzy.php';
require_once dirname( __DIR__ ) . '/src/FacetRegistry.php';
require_once dirname( __DIR__ ) . '/src/Novedad.php';
require_once dirname( __DIR__ ) . '/src/PriceBuckets.php';
require_once dirname( __DIR__ ) . '/src/SemanticPublication.php';
// El panel de Ajustes lee y sanea los mensajes de «sin resultados» al pintar
// y al guardar; sin autoloader, cualquier test del panel fatalea sin esto.
require_once dirname( __DIR__ ) . '/src/PopularQueries.php';
require_once dirname( __DIR__ ) . '/src/EmptyStateCopy.php';
require_once dirname( __DIR__ ) . '/src/BrandTokens.php';
require_once dirname( __DIR__ ) . '/src/BrandRelax.php';
require_once dirname( __DIR__ ) . '/src/ProductSourceAuthority.php';
require_once dirname( __DIR__ ) . '/src/DocumentBuilder.php';
require_once dirname( __DIR__ ) . '/src/WorkRepository.php';
require_once dirname( __DIR__ ) . '/src/WriteFence.php';
require_once dirname( __DIR__ ) . '/src/Indexer.php';
require_once dirname( __DIR__ ) . '/src/RebuildJob.php';
require_once dirname( __DIR__ ) . '/src/EditorialDiscountPublication.php';
// Guarda de la suite: ninguna prueba termina con un marco editorial abierto (0.1.64).
require_once __DIR__ . '/Support/MarcosEditorialesVigilados.php';
require_once dirname( __DIR__ ) . '/src/HealthReport.php';
// SuggestController lo construye por default; sin autoloader hay que cargarlo
// acá o cualquier test del endpoint fatalea según el orden de ejecución.
require_once dirname( __DIR__ ) . '/src/SeriesSuggestions.php';

// El MISMO cargador PSR-4 que el plugin registra en producción cuando no hay
// vendor (`akibara-search.php`). Los `require_once` de arriba fijan el orden de
// lo que tiene efectos al cargarse; esto cubre todo lo demás. Sin él, 19 de las
// 67 clases de prueba sólo pasaban dentro de la suite completa —PHPUnit carga
// TODOS los ficheros de prueba antes de correr ninguno, y los `require` de unas
// le servían las clases a otras— y caían con «Class not found» al correrlas
// solas (2026-09-19). No necesita WordPress: sólo resuelve rutas bajo src/.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Akibara\\Search\\';
		if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
			return;
		}

		$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
