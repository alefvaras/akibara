<?php
/**
 * Pruebas del tamaño de página de las landings de serie (UC-5).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesCatalog;

use Akibara\Business\Modules\SeriesCatalog\LandingPageSize;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SiteSpeed\EdgePurge;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Doble de `$wpdb` para el conteo agregado de la autoridad de catálogo. */
final class LandingPageSizeWpdb {

	public string $posts              = 'wp_posts';
	public string $terms              = 'wp_terms';
	public string $term_taxonomy      = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta           = 'wp_postmeta';
	public string $last_error         = '';
	public string $last_query         = '';
	public bool $ready                = true;

	/** Productos visibles de la serie; null simula una lectura fallida. */
	public ?int $products = 44;

	/** @param mixed ...$args Valores. */
	public function prepare( string $query, ...$args ): string {
		unset( $args );

		return $query;
	}

	/** @return list<array<string,string>>|null */
	public function get_results( string $query, string $output = 'OBJECT' ): ?array {
		unset( $output );
		$this->last_query = $query;
		if ( null === $this->products ) {
			$this->last_error = 'Simulated SQL error';
			return array();
		}
		$this->last_error = '';

		return array(
			array(
				'term_taxonomy_id' => '374',
				'product_count'    => (string) $this->products,
				'volume_count'     => (string) $this->products,
			),
		);
	}
}

/** Consulta principal de una landing, con lo que Core expone en `pre_handle_404`. */
final class LandingPageSizeQuery extends \WP_Query {

	public bool $series = true;

	public function is_tax( $taxonomy = '', $term = '' ): bool {
		unset( $term );

		return $this->series && 'pa_serie' === $taxonomy;
	}

	/** @return object|null */
	public function get_queried_object() {
		if ( ! $this->series ) {
			return null;
		}
		$term                   = new \WP_Term( 900, 'haikyuu', 'Haikyuu!!' );
		$term->taxonomy         = 'pa_serie';
		$term->term_taxonomy_id = 374;

		return $term;
	}
}

final class LandingPageSizeTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<string,mixed> */
	private array $original_get = array();

	/** @var array<string,mixed> */
	private array $original_post = array();

	private LandingPageSizeWpdb $wpdb;

	/** @var list<array<int,mixed>> `do_action()` que salieron en el test. */
	private array $acciones = array();

	/**
	 * The System Under Test.
	 */
	private LandingPageSize $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_get  = $_GET;
		$this->original_post = $_POST;
		$_GET                = array();
		$_POST               = array();
		$this->options       = array();
		$this->wpdb          = new LandingPageSizeWpdb();
		$this->sut           = new LandingPageSize( new SeriesCatalogEligibility( $this->wpdb ) );

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias( static fn( $taxonomy = '' ): bool => 'pa_serie' === $taxonomy );
		$this->acciones = array();
		Functions\when( 'do_action' )->alias(
			function ( ...$args ): void {
				$this->acciones[] = $args;
			}
		);
		// Dos series con catálogo y una vacía: la purga es por término, tenga o
		// no tomos hoy (su landing puede estar en caché de cuando los tenía).
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ): array {
				if ( 'pa_serie' !== ( $args['taxonomy'] ?? '' ) ) {
					return array();
				}
				$terms = array();
				foreach ( array( 900 => 'haikyuu', 901 => 'one-piece', 902 => 'serie-vacia' ) as $id => $slug ) {
					$term           = new \WP_Term( $id, $slug, $slug );
					$term->taxonomy = 'pa_serie';
					$terms[]        = $term;
				}
				return $terms;
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/'
		);
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		Functions\when( 'get_pagenum_link' )->alias(
			static fn( int $page = 1 ): string => 1 === $page
				? 'https://akibara.cl/serie/haikyuu/'
				: 'https://akibara.cl/serie/haikyuu/page/' . $page . '/'
		);
	}

	protected function tearDown(): void {
		// `EdgePurge` junta las URLs en un estático que sólo vacía el `shutdown`
		// real, que no corre bajo PHPUnit.
		$pendientes = new ReflectionProperty( EdgePurge::class, 'pendientes' );
		$pendientes->setAccessible( true );
		$pendientes->setValue( null, array() );

		$_GET  = $this->original_get;
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Consulta de `/serie/haikyuu/page/{n}/` que salió sin tomos. */
	private function empty_page( int $paged ): LandingPageSizeQuery {
		return new LandingPageSizeQuery( array( 'paged' => $paged ) );
	}

	/**
	 * @testdox Should register the page size, the redirect and the panel validation.
	 */
	public function test_registers_its_hooks(): void {
		$this->sut->register();

		self::assertSame( 20, Monkey\Filters\has( 'loop_shop_per_page', array( $this->sut, 'handle_loop_shop_per_page' ) ) );
		self::assertSame( 10, Monkey\Filters\has( 'pre_handle_404', array( $this->sut, 'handle_pre_handle_404' ) ) );
		self::assertSame( 1, Monkey\Actions\has( 'template_redirect', array( $this->sut, 'handle_template_redirect' ) ) );
	}

	/** Etiquetas que se mandaron a purgar a LiteSpeed. @return list<string> */
	private function purged_tags(): array {
		$tags = array();
		foreach ( $this->acciones as $accion ) {
			if ( 'litespeed_purge' === ( $accion[0] ?? '' ) ) {
				$tags[] = (string) $accion[1];
			}
		}
		return $tags;
	}

	/** URLs anotadas para el borde. @return list<string> */
	private function edge_urls(): array {
		$pendientes = new ReflectionProperty( EdgePurge::class, 'pendientes' );
		$pendientes->setAccessible( true );

		return array_keys( (array) $pendientes->getValue() );
	}

	/**
	 * @testdox Should purge every series landing in LiteSpeed and at the edge when purge_landings is called.
	 */
	public function test_purge_landings_annotates_litespeed_and_edge(): void {
		$this->sut->purge_landings();

		self::assertSame( array( 'T.900', 'T.901', 'T.902' ), $this->purged_tags() );
		self::assertSame(
			array(
				'https://akibara.cl/serie/haikyuu/',
				'https://akibara.cl/serie/one-piece/',
				'https://akibara.cl/serie/serie-vacia/',
			),
			$this->edge_urls()
		);
		self::assertNotContains( 'litespeed_purge_all', array_column( $this->acciones, 0 ), 'Nunca el sitio entero.' );
	}

	/**
	 * @testdox Should show 48 volumes per page on a series landing by default.
	 */
	public function test_series_landing_defaults_to_48(): void {
		self::assertSame( 48, $this->sut->handle_loop_shop_per_page( 24 ) );
		self::assertSame( 48, LandingPageSize::per_page() );
	}

	/**
	 * @testdox Should leave every other archive with the store page size.
	 */
	public function test_other_archives_keep_the_store_page_size(): void {
		Functions\when( 'is_tax' )->justReturn( false );
		self::assertSame( 24, $this->sut->handle_loop_shop_per_page( 24 ) );

		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		self::assertSame( 24, $this->sut->handle_loop_shop_per_page( 24 ), 'El admin no pagina landings.' );
	}

	/**
	 * @testdox If AKB_SERIES_LANDING_PER_PAGE is defined, it takes precedence over the code default.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_override_takes_precedence(): void {
		define( 'AKB_SERIES_LANDING_PER_PAGE', 60 );
		self::assertSame( 60, LandingPageSize::per_page() );
	}

	/**
	 * @testdox If AKB_SERIES_LANDING_PER_PAGE is out of bounds, it falls back safely to 48.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_invalid_constant_falls_back_safely_to_default(): void {
		define( 'AKB_SERIES_LANDING_PER_PAGE', 999 );
		self::assertSame( 48, LandingPageSize::per_page() );
	}

	/**
	 * @testdox parse() accepts integers and numeric strings between MIN (12) and MAX (120).
	 */
	public function test_parse_accepts_valid_range_and_rejects_invalid(): void {
		self::assertSame( 12, LandingPageSize::parse( 12 ) );
		self::assertSame( 48, LandingPageSize::parse( '48' ) );
		self::assertSame( 120, LandingPageSize::parse( '120' ) );

		self::assertNull( LandingPageSize::parse( 11 ) );
		self::assertNull( LandingPageSize::parse( 121 ) );
		self::assertNull( LandingPageSize::parse( 'invalido' ) );
		self::assertNull( LandingPageSize::parse( null ) );
	}

	/**
	 * Haikyuu tiene 44 tomos: con 48 por página su antigua página 2 ya no existe
	 * y estaba indexada. Se redirige a la única página que queda.
	 *
	 * @testdox Should send a series page that no longer exists to the last one that does.
	 */
	public function test_redirects_a_page_that_no_longer_exists(): void {
		self::assertSame( 'https://akibara.cl/serie/haikyuu/', $this->sut->out_of_range_target( $this->empty_page( 2 ) ) );

		$this->wpdb->products = 109;
		self::assertSame(
			'https://akibara.cl/serie/haikyuu/page/3/',
			( new LandingPageSize( new SeriesCatalogEligibility( $this->wpdb ) ) )->out_of_range_target( $this->empty_page( 5 ) ),
			'One Piece: 109 tomos son 3 páginas de 48; la 5 va a la 3.'
		);
	}

	/**
	 * @testdox Should leave alone pages in range, filtered variants, other queries and unreadable counts.
	 */
	public function test_does_not_redirect_outside_its_case(): void {
		$this->wpdb->products = 109;
		self::assertNull( $this->sut->out_of_range_target( $this->empty_page( 2 ) ), 'La página 2 de One Piece existe.' );

		$this->wpdb->products = 44;
		$_GET                 = array( 'orderby' => 'date' );
		self::assertNull( $this->sut->out_of_range_target( $this->empty_page( 2 ) ), 'Las variantes con parámetros son noindex: se quedan en 404.' );
		$_GET = array();

		$with_posts        = $this->empty_page( 2 );
		$with_posts->posts = array( 1 );
		self::assertNull( $this->sut->out_of_range_target( $with_posts ), 'Con tomos no hay nada que redirigir.' );

		$secondary                       = $this->empty_page( 2 );
		$secondary->is_main_query_result = false;
		self::assertNull( $this->sut->out_of_range_target( $secondary ) );

		$other         = $this->empty_page( 2 );
		$other->series = false;
		self::assertNull( $this->sut->out_of_range_target( $other ) );

		self::assertNull( $this->sut->out_of_range_target( $this->empty_page( 1 ) ) );
		self::assertNull( $this->sut->out_of_range_target( 'no es una consulta' ) );

		$this->wpdb->products = null;
		self::assertNull(
			( new LandingPageSize( new SeriesCatalogEligibility( $this->wpdb ) ) )->out_of_range_target( $this->empty_page( 2 ) ),
			'Una lectura fallida no es evidencia de que la página sobre.'
		);

		$this->wpdb->products = 0;
		self::assertNull(
			( new LandingPageSize( new SeriesCatalogEligibility( $this->wpdb ) ) )->out_of_range_target( $this->empty_page( 2 ) ),
			'Una serie sin catálogo no tiene página a la que volver.'
		);
	}

	/**
	 * QA integral 2026-09-24 (QA-MOV-1 = BIZ-QA-1): la página 2 de una serie que
	 * ya cabe en una llegaba desde Instagram (`fbclid`), una campaña (`utm_*`) o
	 * un resultado orgánico de Google con Merchant Center (`srsltid`) y caía en
	 * el 404, mientras producción la servía con 200.
	 *
	 * @testdox Should redirect a page that no longer exists when it only carries tracking parameters, keeping them in the target.
	 */
	public function test_tracking_parameters_do_not_block_the_redirect(): void {
		$pedido = array();
		Functions\when( 'get_pagenum_link' )->alias(
			static function ( int $page = 1, bool $escape = true ) use ( &$pedido ): string {
				$pedido[] = $escape;
				$base     = 1 === $page ? 'https://akibara.cl/serie/haikyuu/' : 'https://akibara.cl/serie/haikyuu/page/' . $page . '/';
				// Como el núcleo: arrastra la consulta de la petición y, escapado para
				// HTML, convierte el «&» en «&#038;».
				$query = http_build_query( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$url   = '' === $query ? $base : $base . '?' . $query;
				return $escape ? str_replace( '&', '&#038;', $url ) : $url;
			}
		);

		foreach ( array(
			array( 'fbclid' => 'IwAR123' ),
			array( 'srsltid' => 'AfmBOoqTest' ),
			array( 'gclid' => 'abc' ),
			array( 'utm_source' => 'instagram', 'utm_medium' => 'social' ),
			array( 'UTM_Campaign' => 'cyber' ),
		) as $get ) {
			$_GET   = $get;
			$target = $this->sut->out_of_range_target( $this->empty_page( 2 ) );
			self::assertSame(
				'https://akibara.cl/serie/haikyuu/?' . http_build_query( $get ),
				$target,
				'Con ' . implode( ', ', array_keys( $get ) ) . ' la página sigue siendo limpia y la atribución viaja.'
			);
			self::assertStringNotContainsString( '&#038;', (string) $target, 'Es una cabecera Location, no HTML.' );
		}
		self::assertNotContains( true, $pedido, 'El destino se pide sin escapar para HTML.' );
	}

	/**
	 * QA integral 2026-09-24 (QA-BIZ-DIFF-4): `?paged=2` de una serie que ahora
	 * cabe en una página daba 404, mientras `/page/2/` daba 301 y producción la
	 * servía con 200. Es la misma página: se redirige igual, y el destino sale
	 * sin `paged` (lo quita `get_pagenum_link()`, como el núcleo).
	 *
	 * @testdox Should redirect ?paged=N out of range like /page/N/, and keep the 404 when it comes with an order or a facet.
	 */
	public function test_the_page_number_in_the_query_is_not_a_filter(): void {
		Functions\when( 'get_pagenum_link' )->alias(
			static function ( int $page = 1, bool $escape = true ): string {
				unset( $escape );
				$base  = 1 === $page ? 'https://akibara.cl/serie/haikyuu/' : 'https://akibara.cl/serie/haikyuu/page/' . $page . '/';
				$get   = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				unset( $get['paged'] );
				$query = http_build_query( $get );
				return '' === $query ? $base : $base . '?' . $query;
			}
		);

		$_GET = array( 'paged' => '2' );
		self::assertSame( 'https://akibara.cl/serie/haikyuu/', $this->sut->out_of_range_target( $this->empty_page( 2 ) ) );

		$_GET = array( 'paged' => '2', 'utm_source' => 'x' );
		self::assertSame( 'https://akibara.cl/serie/haikyuu/?utm_source=x', $this->sut->out_of_range_target( $this->empty_page( 2 ) ), 'La atribución viaja; el número de página no.' );

		foreach ( array(
			array( 'paged' => '2', 'orderby' => 'price' ),
			array( 'paged' => '2', '_disponibilidad' => 'instock' ),
			array( 'PAGED' => '2' ),
		) as $get ) {
			$_GET = $get;
			self::assertNull( $this->sut->out_of_range_target( $this->empty_page( 2 ) ), implode( ', ', array_keys( $get ) ) );
		}
	}

	/**
	 * @testdox Should keep the 404 when a tracking parameter comes along with a facet or an order.
	 */
	public function test_tracking_parameters_do_not_rescue_filtered_variants(): void {
		foreach ( array(
			array( '_disponibilidad' => 'instock' ),
			array( 'orderby' => 'date', 'utm_source' => 'x' ),
			array( 'fbclid' => 'IwAR', '_disponibilidad' => 'instock' ),
			array( 'utmsource' => 'x' ),
		) as $get ) {
			$_GET = $get;
			self::assertNull( $this->sut->out_of_range_target( $this->empty_page( 2 ) ), implode( ', ', array_keys( $get ) ) );
		}
	}

	/**
	 * Revisión BIZ-QA-2: la 301 sale en una petición que WordPress ya marcó 404
	 * y LiteSpeed la guardaba con el TTL de un 404 (1 h) y sin la etiqueta del
	 * término, así que la purga al cambiar el tamaño no la alcanzaba.
	 *
	 * @testdox Should mark the redirect as not cacheable before sending it.
	 */
	public function test_the_redirect_is_never_cached(): void {
		$orden = array();
		Functions\when( 'do_action' )->alias(
			static function ( ...$args ) use ( &$orden ): void {
				$orden[] = (string) $args[0];
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( string $location, int $status ) use ( &$orden ): void {
				$orden[] = 'redirect ' . $status . ' ' . $location;
				// `handle_template_redirect()` termina con `exit`: se corta aquí.
				throw new \RuntimeException( 'redirigido' );
			}
		);

		$this->sut->handle_pre_handle_404( false, $this->empty_page( 2 ) );
		try {
			$this->sut->handle_template_redirect();
			self::fail( 'Tenía que redirigir.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirigido', $e->getMessage() );
		}

		self::assertSame(
			array( 'litespeed_control_set_nocache', 'redirect 301 https://akibara.cl/serie/haikyuu/' ),
			$orden
		);
	}

	/**
	 * @testdox Should keep the 404 verdict untouched while it decides the redirect.
	 */
	public function test_pre_handle_404_never_changes_the_verdict(): void {
		self::assertFalse( $this->sut->handle_pre_handle_404( false, $this->empty_page( 2 ) ) );
		self::assertTrue( $this->sut->handle_pre_handle_404( true, $this->empty_page( 2 ) ) );
	}
}
