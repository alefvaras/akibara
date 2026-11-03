<?php
/**
 * La vitrina de series no puede servir el recorte de otro `count`.
 *
 * Hallazgo S-8bd07130: lo cacheado es el resultado YA recortado por `count`,
 * pero la clave sólo dependía de la lista guardada. Dos usos del shortcode con
 * distinto `count` compartían foto: el segundo se servía el recorte del primero.
 *
 * La primera corrección borraba, en cada guardado de producto, todas las
 * variantes posibles de la clave: hasta 48 `delete_transient()` por guardado,
 * que en una importación de 400 tomos son ~10.000 consultas de más (revisión
 * independiente previa al despliegue, 2026-09-18). Ahora la clave LLEVA lo que
 * puede cambiar la foto y no se borra nada: la copia vieja queda inalcanzable.
 *
 * REAPERTURA 2026-09-19 (prueba_debil): las cuatro pruebas de este fichero
 * medían la fuente con `file_get_contents()` + `assertStringContainsString()`
 * y nunca llamaban a `render()`. Mover el recorte DESPUÉS de leer la caché —
 * `$items = array_slice( $this->items( $slugs ), 0, $count )` en vez de
 * `$this->items( array_slice( $slugs, 0, $count ) )`— deja esas cadenas
 * intactas (`items()` sigue recibiendo `$slugs`, `cache_suffix()` no cambia)
 * y el bug de vuelta: dos `count` distintos comparten la MISMA clave.
 * `test_render_cachea_por_separado_...` y `test_el_mismo_count_reusa_...`
 * llaman a `render()` de punta a punta —el nivel donde vive el recorte real—
 * con una caché en memoria de verdad, con el mismo arnés
 * (`HomeSeriesEligibilityWpdb`, `\WP_Query::$akb_resolver`) que ya usa
 * `HomeSeriesDestacadasTest`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeSeriesDestacadas;

require_once __DIR__ . '/HomeSeriesEligibilityWpdb.php';

use Akibara\Business\Modules\HomeSeriesDestacadas\HomeSeriesDestacadas;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CacheDistinguePorCountTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
		defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.cl/wp-content/plugins/akibara-business/' );

		$this->options = array(
			HomeSeriesDestacadas::OPTION_ENABLED => 'yes',
			HomeSeriesDestacadas::OPTION_SLUGS   => implode( "\n", array( 'blue-lock', 'naruto', 'one-piece', 'jujutsu-kaisen' ) ),
		);
		Functions\when( 'get_option' )->alias( fn ( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'sanitize_title' )->alias(
			static fn ( string $t ): string => trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $t ) ), '-' )
		);
		Functions\when( 'shortcode_atts' )->alias( static fn ( array $defaults, array $atts ): array => array_merge( $defaults, $atts ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		SeriesHubUrl::reset();
		// Sin tomos resolubles ⇒ sin portada (SeriesCover) y sin queries extra de
		// título/stock: lo que se mide acá es la CLAVE de caché, no la portada.
		\WP_Query::$akb_resolver = static fn (): array => array();
	}

	protected function tearDown(): void {
		\WP_Query::$akb_resolver = null;
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Corre `render()` de punta a punta sobre una caché en memoria de verdad
	 * —no un `justReturn()`— para que un `get_transient`/`set_transient` que
	 * no se llamen, o que se llamen con la clave equivocada, se noten.
	 *
	 * @param int                                           $count   Atributo `count` del shortcode.
	 * @param array<string,array<int,array<string,mixed>>>   $store   Caché compartida entre llamadas del mismo test (por referencia).
	 * @param int                                            $lookups Contador de `get_term_by`, para medir cache HIT vs MISS (por referencia).
	 */
	private function render_con_cache_real( int $count, array &$store, int &$lookups = 0 ): string {
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $store[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ) use ( &$store ): bool {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_term_by' )->alias(
			function ( string $field, string $slug, string $taxonomy ) use ( &$lookups ) {
				unset( $field, $taxonomy );
				++$lookups;
				$term           = new \WP_Term( 1000 + crc32( $slug ) % 1000, $slug, ucfirst( str_replace( '-', ' ', $slug ) ) );
				$term->taxonomy = 'pa_serie';
				$term->count    = 5;
				return $term;
			}
		);
		Functions\when( 'get_term_link' )->alias( static fn ( \WP_Term $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/' );

		$wpdb            = new HomeSeriesEligibilityWpdb();
		$wpdb->fail      = true; // Agregado ilegible ⇒ cae a $term->count (comportamiento ya cubierto en HomeSeriesDestacadasTest).
		$original        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		try {
			return ( new HomeSeriesDestacadas() )->render( array( 'count' => $count ) );
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original;
			}
		}
	}

	/**
	 * @testdox Dos usos del shortcode con distinto `count` no comparten foto: cada recorte cachea aparte y pinta su propio número de cards.
	 */
	public function test_render_cachea_por_separado_segun_el_count(): void {
		$store = array();

		$html_2 = $this->render_con_cache_real( 2, $store );
		$html_4 = $this->render_con_cache_real( 4, $store );

		self::assertSame( 2, substr_count( $html_2, 'akb-home-series__item' ), 'count=2 debe pintar exactamente 2 cards.' );
		self::assertSame(
			4,
			substr_count( $html_4, 'akb-home-series__item' ),
			'count=4 no puede heredar las 2 cards de la llamada anterior con count=2 (S-8bd07130).'
		);
		self::assertCount(
			2,
			$store,
			'Dos count distintos deben ocupar DOS claves de transient: si comparten una, el segundo sirve el recorte del primero.'
		);
	}

	/**
	 * Lado positivo del mismo candado: si la caché nunca acertara, la prueba de
	 * arriba pasaría igual sin que `render()` cachee nada de verdad.
	 *
	 * @testdox El mismo count reusa la foto: no repite la consulta de términos en la segunda pasada.
	 */
	public function test_el_mismo_count_reusa_la_foto_sin_repetir_la_consulta(): void {
		$store   = array();
		$lookups = 0;

		$this->render_con_cache_real( 2, $store, $lookups );
		$this->render_con_cache_real( 2, $store, $lookups );

		self::assertCount( 1, $store, 'El mismo count pedido dos veces debe usar UNA sola clave.' );
		self::assertSame(
			2,
			$lookups,
			'La segunda pasada no debió consultar get_term_by: si lo hizo, no fue una caché HIT.'
		);
	}

	private function source(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php' );
	}

	/**
	 * Desde 2026-09-26 la vitrina recorre la lista ENTERA hasta juntar `count`
	 * (una serie sin tomos deja entrar a la reserva), así que lo que distingue
	 * un recorte de otro ya no son los slugs pedidos sino `count`.
	 *
	 * @testdox La clave de caché depende de cuántas se piden, no sólo de la lista guardada.
	 */
	public function test_la_clave_lleva_el_count_pedido(): void {
		$src = $this->source();

		self::assertStringContainsString( '$this->cache_suffix( $count )', $src );
		self::assertMatchesRegularExpression( '/cache_suffix\(\s*int \$count\s*\)/', $src );
		self::assertStringContainsString( "\$base . '|' . \$count", $src );
	}

	/** @testdox La clave lleva también la versión de catálogo de WooCommerce. */
	public function test_la_clave_lleva_la_version_del_catalogo(): void {
		$src = $this->source();

		self::assertStringContainsString( 'self::catalog_version()', $src );
		self::assertStringContainsString( "WC_Cache_Helper::get_transient_version( 'product' )", $src );
		self::assertStringContainsString( "class_exists( '\\WC_Cache_Helper' )", $src, 'Sin WooCommerce no puede tirar un fatal.' );
	}

	/** @testdox Guardar un producto no dispara un barrido de transients. */
	public function test_no_hay_barrido_por_guardado(): void {
		$src = $this->source();

		self::assertStringNotContainsString( 'delete_transient', $src, 'Volvió el borrado en masa: son hasta 48 consultas por guardado de producto.' );
		self::assertStringNotContainsString( "add_action( 'woocommerce_update_product'", $src );
		self::assertStringNotContainsString( 'flush_cache', $src, 'Sin hooks de invalidación, el método sobraba: sería código muerto.' );
	}

	/** @testdox El tope de slugs sigue acotando la lista que el dueño puede pegar. */
	public function test_el_tope_existe(): void {
		self::assertGreaterThan( 0, HomeSeriesDestacadas::MAX_SLUGS );
		self::assertLessThanOrEqual( 50, HomeSeriesDestacadas::MAX_SLUGS );
	}
}
