<?php
/**
 * Vitrina «Series más buscadas» de la portada: lista operable sin código,
 * nunca un bloque vacío, HTML con la jaula de portada del sitio.
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


final class HomeSeriesDestacadasTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			fn ( string $option, $default_value = false ) => $this->options[ $option ] ?? $default_value
		);
		Functions\when( 'sanitize_title' )->alias(
			static fn ( string $t ): string => trim( (string) preg_replace( '/[^a-z0-9-]+/', '-', strtolower( $t ) ), '-' )
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'shortcode_atts' )->alias( static fn ( array $defaults, array $atts ): array => array_merge( $defaults, $atts ) );
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );
		SeriesHubUrl::reset();
	}

	protected function tearDown(): void {
		\WP_Query::$akb_resolver = null;
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Portada por reflexión, con la consulta de tomos resuelta por el test.
	 *
	 * @param callable $resolver Recibe los args de WP_Query y devuelve ids.
	 */
	private function cover( callable $resolver ): string {
		\WP_Query::$akb_resolver = $resolver;
		Functions\when( 'get_the_post_thumbnail_url' )->alias( static fn( int $id ): string => 'https://akibara.test/p/frieren-' . ( $id - 500 ) . '.jpg' );

		$original        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \Akibara\Business\Tests\Support\TitulosDeTomosWpdb( static fn( int $id ): string => 'Frieren ' . ( $id - 500 ) );

		try {
			$method = new \ReflectionMethod( HomeSeriesDestacadas::class, 'cover_url' );
			$method->setAccessible( true );

			return (string) $method->invoke( new HomeSeriesDestacadas(), 77 );
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original;
			}
		}
	}

	/**
	 * La tarjeta dice «N tomos», así que cuenta TOMOS y no productos.
	 *
	 * Medido en vivo el 2026-09-11: /series/ publica «Demon Slayer … 23 tomos en
	 * catálogo». Con la edición especial dentro de la serie, contar `$term->count`
	 * dejaba a la portada diciendo 24 mientras la landing de esa misma serie dice
	 * «Completa en 23 tomos», a un clic de distancia.
	 *
	 * @testdox La vitrina publica TOMOS, no el conteo de productos del término.
	 */
	public function test_la_vitrina_publica_tomos_y_no_productos(): void {
		$term                   = new \WP_Term( 435, 'demon-slayer', 'Demon Slayer' );
		$term->taxonomy         = 'pa_serie';
		$term->count            = 24;
		$term->term_taxonomy_id = 435;

		$wpdb                  = new HomeSeriesEligibilityWpdb();
		$wpdb->rows            = array(
			array( 'term_taxonomy_id' => '435', 'product_count' => '24', 'volume_count' => '23' ),
		);
		$original              = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']       = $wpdb;

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_term_by' )->justReturn( $term );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/demon-slayer/' );
		\WP_Query::$akb_resolver = static fn(): array => array();

		$method = new \ReflectionMethod( HomeSeriesDestacadas::class, 'items' );
		$method->setAccessible( true );

		try {
			$items = $method->invoke( new HomeSeriesDestacadas(), array( 'demon-slayer' ), 1 );
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original;
			}
		}

		self::assertSame( 23, $items[0]['tomos'], 'La portada cuenta tomos, no las 24 cards de la rejilla.' );
	}

	/**
	 * Un hipo de la base no puede borrar la línea: la tarjeta la oculta cuando el
	 * número es 0, así que un agregado ilegible cae a la cifra de hoy.
	 *
	 * @testdox [NEG] Con el agregado ilegible se conserva el conteo del término.
	 */
	public function test_con_el_agregado_ilegible_conserva_el_conteo_del_termino(): void {
		$term                   = new \WP_Term( 435, 'demon-slayer', 'Demon Slayer' );
		$term->taxonomy         = 'pa_serie';
		$term->count            = 24;
		$term->term_taxonomy_id = 435;

		$wpdb            = new HomeSeriesEligibilityWpdb();
		$wpdb->fail      = true;
		$original        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $wpdb;

		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_term_by' )->justReturn( $term );
		Functions\when( 'get_term_link' )->justReturn( 'https://akibara.cl/serie/demon-slayer/' );
		\WP_Query::$akb_resolver = static fn(): array => array();

		$method = new \ReflectionMethod( HomeSeriesDestacadas::class, 'items' );
		$method->setAccessible( true );

		try {
			$items = $method->invoke( new HomeSeriesDestacadas(), array( 'demon-slayer' ), 1 );
		} finally {
			if ( null === $original ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original;
			}
		}

		self::assertSame( 24, $items[0]['tomos'] );
	}

	/** @testdox La portada es la del tomo más bajo CON stock. */
	public function test_cover_prefers_the_lowest_tomo_in_stock(): void {
		$url = $this->cover( static fn( array $args ): array => isset( $args['meta_query'] ) ? array( 503, 502 ) : array( 503, 502, 501 ) );

		self::assertSame( 'https://akibara.test/p/frieren-2.jpg', $url );
	}

	/** @testdox Con la serie entera agotada, la portada cae al tomo más bajo a secas (Frieren, 2026-09-04). */
	public function test_cover_falls_back_when_nothing_is_in_stock(): void {
		$url = $this->cover( static fn( array $args ): array => isset( $args['meta_query'] ) ? array() : array( 503, 501, 502 ) );

		self::assertSame( 'https://akibara.test/p/frieren-1.jpg', $url, 'La serie salía en la vitrina como un rectángulo vacío.' );
	}

	/** @testdox Sin tomos publicados no hay portada. */
	public function test_cover_is_empty_without_tomos(): void {
		self::assertSame( '', $this->cover( static fn(): array => array() ) );
	}

	/** @testdox Parsea slugs sueltos, URLs pegadas y duplicados en orden y con tope. */
	public function test_parse_slugs_tolera_urls_y_duplicados(): void {
		$raw = "Blue Lock\n/serie/naruto/\nhttps://akibara.cl/serie/one-piece/\n\nblue-lock\n, jujutsu-kaisen ,";

		self::assertSame( array( 'blue-lock', 'naruto', 'one-piece', 'jujutsu-kaisen' ), HomeSeriesDestacadas::parse_slugs( $raw ) );

		$muchos = implode( "\n", array_map( static fn ( int $i ): string => "serie-$i", range( 1, 40 ) ) );
		self::assertCount( HomeSeriesDestacadas::MAX_SLUGS, HomeSeriesDestacadas::parse_slugs( $muchos ) );
	}

	/** @testdox Apagado o sin lista, el shortcode no pinta nada. */
	public function test_sin_lista_o_apagado_no_pinta(): void {
		$modulo = new HomeSeriesDestacadas();

		self::assertSame( '', $modulo->render(), 'Sin lista no hay bloque.' );

		$this->options[ HomeSeriesDestacadas::OPTION_SLUGS ]   = 'blue-lock';
		$this->options[ HomeSeriesDestacadas::OPTION_ENABLED ] = 'no';
		self::assertSame( '', $modulo->render(), 'Apagado no hay bloque aunque haya lista.' );
	}

	/**
	 * R2-8951cc1e: el enlace «Ver todas» del bloque no puede quedarse en
	 * `/series/` si el dueño movió esa página a otro slug — se resuelve vía
	 * `SeriesHubUrl`, no con el literal.
	 *
	 * @testdox Should link "Ver todas" to the real hub page when its slug moved.
	 */
	public function test_render_links_ver_todas_to_the_real_hub_when_the_slug_moved(): void {
		$this->options[ HomeSeriesDestacadas::OPTION_SLUGS ] = 'blue-lock';

		Functions\when( 'get_transient' )->alias(
			static fn( string $key ) => str_starts_with( $key, 'akb_home_series_v1_' )
				? array( array( 'name' => 'Blue Lock', 'url' => 'https://akibara.cl/serie/blue-lock/', 'cover' => '', 'tomos' => 37 ) )
				: false
		);
		Functions\when( 'get_posts' )->justReturn( array( 501 ) );
		Functions\when( 'get_post_field' )->justReturn( '[akibara_series_directory]' );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/todas-las-series/' );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );

		$html = ( new HomeSeriesDestacadas() )->render();

		self::assertStringContainsString( 'href="https://akibara.cl/todas-las-series/">Ver todas', $html );
		self::assertStringNotContainsString( '/series/', $html );
	}

	/** @testdox El HTML lleva título, enlace a la landing, jaula de portada y conteo en singular/plural. */
	public function test_render_items(): void {
		$html = HomeSeriesDestacadas::render_items(
			array(
				array( 'name' => 'Blue Lock', 'url' => 'https://akibara.cl/serie/blue-lock/', 'cover' => 'https://akibara.cl/p/bl1.jpg', 'tomos' => 37 ),
				array( 'name' => 'Look Back', 'url' => 'https://akibara.cl/serie/look-back/', 'cover' => '', 'tomos' => 1 ),
				array( 'name' => '', 'url' => 'https://akibara.cl/serie/x/', 'cover' => '', 'tomos' => 3 ),
			),
			'Series más buscadas',
			4,
			'https://akibara.cl/series/'
		);

		self::assertStringContainsString( '<header class="akb-section__header"><h2 id="akb-home-series-title">Series más buscadas</h2><a class="akb-section__link" href="https://akibara.cl/series/">Ver todas →</a></header>', $html );
		self::assertStringContainsString( 'akb-home-series__grid--cols-4', $html );
		self::assertStringContainsString( 'href="https://akibara.cl/serie/blue-lock/"', $html );
		self::assertStringContainsString( 'class="akb-home-series__cover akb-cover-cage"', $html );
		// UM-14: el alt lleva el nombre (Rank Math rellena los alt vacíos del
		// contenido con «Portada Inicio - Comprar…») y el texto visible se oculta
		// al lector para no decirlo dos veces. Sin portada, el texto es el nombre.
		self::assertStringContainsString( 'src="https://akibara.cl/p/bl1.jpg" alt="Blue Lock" width="300" height="450" loading="lazy"', $html );
		self::assertStringContainsString( '<span class="akb-home-series__name" aria-hidden="true">Blue Lock</span>', $html );
		self::assertStringContainsString( '<span class="akb-home-series__name">Look Back</span>', $html );
		self::assertStringNotContainsString( 'alt=""', $html );
		self::assertStringContainsString( '37 tomos', $html );
		self::assertStringContainsString( '1 tomo<', $html );
		self::assertSame( 2, substr_count( $html, '<li class="akb-home-series__item">' ), 'Una serie sin nombre se salta.' );
		self::assertSame( '', HomeSeriesDestacadas::render_items( array(), 'x', 4 ) );
	}

	/** @testdox Los campos del hub cubren interruptor, título y lista con sus ids. */
	public function test_get_settings(): void {
		$ids = array_values( array_filter( array_map( static fn ( array $f ) => $f['id'] ?? '', ( new HomeSeriesDestacadas() )->get_settings() ) ) );

		self::assertContains( HomeSeriesDestacadas::OPTION_ENABLED, $ids );
		self::assertContains( HomeSeriesDestacadas::OPTION_TITLE, $ids );
		self::assertContains( HomeSeriesDestacadas::OPTION_SLUGS, $ids );
	}
}
