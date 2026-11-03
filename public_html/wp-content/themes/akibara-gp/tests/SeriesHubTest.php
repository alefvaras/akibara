<?php
/**
 * Contrato ejecutable de la resolución del hub de series.
 *
 * El peldaño «Series» de las 106 landings depende de esta función. Pedía UNA
 * página a la búsqueda de WordPress y después la filtraba con `has_shortcode()`,
 * de modo que si la mejor coincidencia era una página que sólo NOMBRA el
 * shortcode en prosa, el hub real ni siquiera entraba en la lista: la defensa
 * sólo podía producir falsos negativos. Y ese falso negativo se cacheaba 12 h.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class SeriesHubTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_transient' )->justReturn( false );

		require_once dirname( __DIR__ ) . '/inc/series-breadcrumb.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Devuelve las páginas candidatas y modela `has_shortcode` sobre el contenido.
	 *
	 * @param array<int, string> $contenido_por_id Id de página => contenido.
	 */
	private function candidatas( array $contenido_por_id ): void {
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( $contenido_por_id ) {
				return array_slice( array_keys( $contenido_por_id ), 0, (int) $args['posts_per_page'] );
			}
		);
		Functions\when( 'get_post_field' )->alias(
			static function ( $field, $id ) use ( $contenido_por_id ) {
				return $contenido_por_id[ $id ] ?? '';
			}
		);
		Functions\when( 'has_shortcode' )->alias(
			static function ( $content, $tag ): bool {
				return false !== strpos( (string) $content, '[' . $tag );
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ): string {
				return 'https://akibara.test/pagina-' . $id . '/';
			}
		);
	}

	/**
	 * EL DEFECTO QUE CIERRA. Con `posts_per_page = 1` la página que sólo menciona
	 * el shortcode en prosa se comía el único cupo y el hub quedaba sin enlace.
	 *
	 * @testdox El hub se encuentra aunque la búsqueda devuelva antes una mención en prosa.
	 */
	public function test_hub_survives_a_prose_mention_ranking_first(): void {
		$this->candidatas(
			array(
				11 => 'En esta guía explicamos qué hace akibara_series_directory por dentro.',
				12 => 'Directorio: [akibara_series_directory]',
			)
		);
		Functions\when( 'set_transient' )->justReturn( true );

		self::assertSame( 'https://akibara.test/pagina-12/', akibara_gp_series_hub_url() );
	}

	/**
	 * @testdox Se piden varias candidatas, no una sola.
	 */
	public function test_more_than_one_candidate_is_requested(): void {
		$pedido = 0;
		Functions\when( 'get_posts' )->alias(
			static function ( array $args ) use ( &$pedido ) {
				$pedido = (int) $args['posts_per_page'];
				return array();
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );

		akibara_gp_series_hub_url();

		self::assertGreaterThan( 1, $pedido, 'Con una sola candidata la defensa sólo puede fallar hacia el falso negativo.' );
	}

	/**
	 * @testdox El negativo caduca en una hora; el acierto dura doce.
	 */
	public function test_negative_result_is_cached_for_less_time(): void {
		$ttl = array();
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration ) use ( &$ttl ): bool {
				$ttl[ '' === $value ? 'vacio' : 'encontrado' ] = (int) $expiration;
				return true;
			}
		);

		$this->candidatas( array( 11 => 'Sin shortcode.' ) );
		self::assertSame( '', akibara_gp_series_hub_url() );

		$this->candidatas( array( 12 => '[akibara_series_directory]' ) );
		self::assertSame( 'https://akibara.test/pagina-12/', akibara_gp_series_hub_url() );

		self::assertSame( HOUR_IN_SECONDS, $ttl['vacio'] );
		self::assertSame( 12 * HOUR_IN_SECONDS, $ttl['encontrado'] );
		self::assertLessThan(
			$ttl['encontrado'],
			$ttl['vacio'],
			'Un «no hay hub» equivocado no puede costar lo mismo que un acierto.'
		);
	}

	/**
	 * @testdox Un valor cacheado se devuelve sin volver a consultar.
	 */
	public function test_cached_value_short_circuits_the_query(): void {
		Functions\when( 'get_transient' )->justReturn( 'https://akibara.test/series/' );
		Functions\expect( 'get_posts' )->never();

		self::assertSame( 'https://akibara.test/series/', akibara_gp_series_hub_url() );
	}

	/**
	 * R12-miga-autor-genero (2026-09-20): la etiqueta cruda de la taxonomía
	 * («Autor del producto») se cambia por la palabra humana en las tres
	 * taxonomías de atributo; sólo series lleva enlace al hub. Y el peldaño se
	 * busca por su firma (sin URL, no el último), no por posición: desde la
	 * página 2 WooCommerce añade «Página N» al final (revisión R12, 2).
	 *
	 * @testdox La miga cambia la etiqueta de serie/autor/género por su palabra, también en páginas 2+, y sólo series enlaza.
	 */
	public function test_la_miga_reemplaza_la_etiqueta_de_las_tres_taxonomias(): void {
		Functions\when( 'get_transient' )->justReturn( 'https://akibara.test/series/' );
		$tax_activa = 'pa_serie';
		Functions\when( 'is_tax' )->alias( static function ( $tax = '' ) use ( &$tax_activa ): bool { return $tax === $tax_activa; } );

		$serie_pagina_1 = array( array( 'Inicio', 'https://akibara.test' ), array( 'Serie del producto', '' ), array( 'One Piece', '' ) );
		self::assertSame(
			array( array( 'Inicio', 'https://akibara.test' ), array( 'Series', 'https://akibara.test/series/' ), array( 'One Piece', '' ) ),
			akibara_gp_series_breadcrumb( $serie_pagina_1 )
		);

		$serie_pagina_2 = array( array( 'Inicio', 'https://akibara.test' ), array( 'Serie del producto', '' ), array( 'One Piece', 'https://akibara.test/serie/one-piece/' ), array( 'Página 2', '' ) );
		self::assertSame( 'Series', akibara_gp_series_breadcrumb( $serie_pagina_2 )[1][0], 'Con «Página 2» al final, la etiqueta sigue siendo el peldaño sin URL que no es el último.' );
		self::assertSame( 'Página 2', akibara_gp_series_breadcrumb( $serie_pagina_2 )[3][0] );

		$tax_activa = 'pa_autor';
		$autor      = array( array( 'Inicio', 'https://akibara.test' ), array( 'Autor del producto', '' ), array( 'Kentaro Miura', '' ) );
		$resultado  = akibara_gp_series_breadcrumb( $autor );
		self::assertSame( 'Autores', $resultado[1][0] );
		self::assertSame( '', $resultado[1][1], 'Autor no tiene hub: texto sin enlace.' );

		$tax_activa = 'pa_genero';
		self::assertSame( 'Géneros', akibara_gp_series_breadcrumb( array( array( 'Inicio', 'x' ), array( 'Género del producto', '' ), array( 'Acción', '' ) ) )[1][0] );

		$tax_activa = 'product_cat';
		$categoria  = array( array( 'Inicio', 'x' ), array( 'Manga', '' ) );
		self::assertSame( $categoria, akibara_gp_series_breadcrumb( $categoria ), 'Fuera de las tres taxonomías no se toca nada.' );
	}
}
