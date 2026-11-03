<?php
/**
 * Semántica y estados de la vista Mi colección.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AccountCollection;

use Akibara\Business\Modules\AccountCollection\Renderer;
use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$escape = static fn( $value ): string => htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.test' . $path );
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		SeriesHubUrl::reset();
		Functions\when( 'wc_get_endpoint_url' )->alias(
			static fn( string $slug, string $value = '', string $permalink = '' ): string =>
				( '' !== $permalink ? rtrim( $permalink, '/' ) . '/' : 'https://akibara.test/mi-cuenta/' ) . $slug . '/'
		);
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.test/mi-cuenta/' );
		Functions\when( 'get_the_post_thumbnail_url' )->alias(
			static fn( int $product_id, string $size ): string => 'https://akibara.test/media/' . $product_id . '-' . $size . '.jpg'
		);
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		// El interruptor de «Sigue esta serie» lee la usermeta del opt-out. Las
		// dos funciones existen SIEMPRE en runtime; declararlas aquí modela eso y
		// deja el default del toggle (seguir) en su valor real. Los casos que
		// miden la baja las sustituyen.
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		// El interruptor GLOBAL «Avisos de mi colección» lleva un nonce atado al
		// id del cliente; existe siempre en runtime.
		Functions\when( 'wp_create_nonce' )->alias( static fn( string $action ): string => 'nonce-' . $action );

		// El estado «no disponible» deja traza por `Utilities\Log`. Se declara un
		// logger mudo por defecto para que el resultado no dependa de si otro
		// test de la suite definió antes `wc_get_logger` (`function_exists()`
		// responde por contagio); el caso que SÍ mide la traza lo sustituye.
		Functions\when( 'wc_get_logger' )->justReturn(
			new class() {
				/**
				 * @param array<string, mixed> $context
				 */
				public function warning( string $message, array $context = array() ): void {
					unset( $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Progreso usa exclusivamente edition.progress_owned y el total de esa edición. */
	public function test_renders_edition_progress_without_using_work_or_catalog_as_denominator(): void {
		$requested_user = 0;
		$renderer       = new Renderer(
			function ( int $user_id ) use ( &$requested_user ): array {
				$requested_user = $user_id;
				return array(
					'status' => 'ok',
					'series' => array(
						$this->series_dto(
							array(
									'name'                  => 'The Walking Dead',
									'owned_count'           => 7,
									'owned_volume_count'    => 7,
								'catalog_count'         => 7,
								'missing_catalog_count' => 0,
								'work_total'            => 32,
								'edition'               => array(
									'state'          => 'en_publicacion',
									'total'          => 8,
									'progress_owned' => 7,
									'missing_count'  => 1,
									'is_complete'    => false,
								),
								'continuity'            => array(
									'kind'          => 'up_to_date',
									'availability'  => 'none',
									'volume_number' => null,
									'product'       => null,
									'options'       => array(),
									'options_count' => 0,
								),
							)
						),
					),
				);
			}
		);

		$html = $renderer->render( 91 );

		self::assertSame( 91, $requested_user );
		self::assertStringContainsString( '7 de 8 tomos', $html );
		self::assertStringContainsString( 'value="7"', $html );
		self::assertStringContainsString( 'max="8"', $html );
		self::assertStringContainsString( 'aria-label="Avance de la edición The Walking Dead: 7 de 8 tomos"', $html );
		self::assertStringContainsString( 'Estás al día con lo publicado en Akibara', $html );
		self::assertMatchesRegularExpression( '/<dt>Tomos<\/dt>\s*<dd>\s*7\s*<\/dd>/', $html );
		self::assertStringContainsString( '<dt>Ediciones completas</dt>', $html );
		self::assertStringNotContainsString( 'akb-account-collection__button--card', $html );
		self::assertStringNotContainsString( '/32', $html );
		self::assertStringNotContainsString( 'Te falta', $html );
	}

	/** @testdox Sin progress_owned no imprime barra ni deduce completitud desde el catálogo. */
	public function test_unknown_progress_has_honest_copy_and_no_progress_element(): void {
		$dto              = $this->series_dto(
			array(
				'name'                  => 'Edición sin total confirmado',
				'owned_count'           => 4,
				'catalog_count'         => 4,
				'missing_catalog_count' => 0,
				'edition'               => array(
					'state'          => 'en_publicacion',
					'total'          => 0,
					'progress_owned' => null,
					'missing_count'  => null,
					'is_complete'    => null,
				),
			)
		);
		$dto['work_total'] = 12;

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $dto ) ) ) )->render( 10 );

		self::assertStringContainsString( 'Total de esta edición por confirmar', $html );
		self::assertStringContainsString( 'No podemos determinar el siguiente tomo con seguridad', $html );
		self::assertStringNotContainsString( '<progress', $html );
		self::assertStringNotContainsString( 'Colección completa', $html );
		self::assertStringNotContainsString( 'akb-account-collection__button--card', $html );
		self::assertStringNotContainsString( '/12', $html );
	}

	/** @testdox Un salto usa lenguaje no presuntivo y las variantes llevan a la edición. */
	public function test_gap_with_multiple_versions_links_to_series_without_choosing_a_product(): void {
		$missing = $this->series_dto(
			array(
				'name'                     => 'Berserk Deluxe',
				'url'                      => 'https://akibara.test/series/berserk/',
				'completion_url'           => 'https://akibara.test/series/berserk/#completar-coleccion',
				'owned_count'              => 3,
				'catalog_count'            => 5,
				'missing_catalog_count'    => 2,
				'missing_catalog_products' => array(
					array( 'id' => 8, 'title' => 'Berserk 4', 'url' => 'https://akibara.test/p/8/' ),
					array( 'id' => 9, 'title' => 'Berserk 5', 'url' => 'https://akibara.test/p/9/' ),
				),
				'continuity'               => array(
					'kind'          => 'gap',
					'availability'  => 'available',
					'volume_number' => 4,
					'product'       => array(
						'id'             => 8,
						'title'          => 'Berserk Deluxe 4 A',
						'url'            => 'https://akibara.test/p/8/',
						'is_purchasable' => true,
					),
					'options'       => array(
						array( 'id' => 8, 'title' => 'Berserk Deluxe 4 A', 'url' => 'https://akibara.test/p/8/' ),
						array( 'id' => 18, 'title' => 'Berserk Deluxe 4 B', 'url' => 'https://akibara.test/p/18/' ),
					),
					'options_count' => 2,
				),
			)
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $missing ) ) ) )->render( 10 );

		self::assertStringContainsString( 'No vemos el tomo 4 entre tus compras de Akibara', $html );
		self::assertStringContainsString( 'Hay 2 versiones del tomo 4', $html );
		self::assertStringContainsString( '>Disponible<', preg_replace( '/\s+/', '', $html ) ?: '' );
		self::assertStringContainsString( 'Ver versiones del tomo 4', $html );
		self::assertStringContainsString( 'aria-label="Ver versiones del tomo 4 de Berserk Deluxe"', $html );
		self::assertStringContainsString( 'href="https://akibara.test/series/berserk/#completar-coleccion"', $html );
		self::assertStringNotContainsString( 'href="https://akibara.test/p/8/" aria-label="Ver versiones', $html );
		self::assertStringNotContainsString( 'Te falta', $html );
		self::assertSame( 1, substr_count( $html, 'akb-account-collection__button--card' ) );
	}

	/** @testdox Stock y preventa determinan texto y destino de una única CTA por tarjeta. */
	public function test_available_preorder_and_out_of_stock_actions_are_explicit(): void {
		$available = $this->series_dto(
			array(
				'name'       => 'Disponible',
				'continuity' => array(
					'kind'          => 'next',
					'availability'  => 'available',
					'volume_number' => 8,
					'product'       => array( 'id' => 8, 'title' => 'Tomo 8', 'url' => 'https://akibara.test/p/8/' ),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$preorder  = $this->series_dto(
			array(
				'name'       => 'Preventa',
				'continuity' => array(
					'kind'          => 'start',
					'availability'  => 'preorder',
					'volume_number' => 1,
					'product'       => array(
						'id'                 => 21,
						'title'              => 'Tomo 1',
						'url'                => 'https://akibara.test/p/21/',
						'preorder_label'     => 'Preventa · entrega estimada 12 de septiembre',
						// S-6d21e49e: el verbo del CTA depende de ESTO, no del texto
						// de arriba (que el dueño puede renombrar en Akibara → Textos).
						'availability_state' => Availability::PREVENTA,
						'is_purchasable'     => true,
					),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$waitlist  = $this->series_dto(
			array(
				'name'       => 'Lista de espera',
				'continuity' => array(
					'kind'          => 'gap',
					'availability'  => 'out_of_stock',
					'volume_number' => 3,
					'product'       => array(
						'id'           => 31,
						'title'        => 'Tomo 3',
						'url'          => 'https://akibara.test/p/31/',
						'waitlist_url' => 'https://akibara.test/p/31/#akb-bis-form',
					),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$sold_out  = $this->series_dto(
			array(
				'name'       => 'Agotado sin lista',
				'continuity' => array(
					'kind'          => 'next',
					'availability'  => 'out_of_stock',
					'volume_number' => 6,
					'product'       => array( 'id' => 61, 'title' => 'Tomo 6', 'url' => 'https://akibara.test/p/61/' ),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$unknown_stock = $this->series_dto(
			array(
				'name'       => 'Stock desconocido',
				'continuity' => array(
					'kind'          => 'start',
					'availability'  => 'unknown',
					'volume_number' => 1,
					'product'       => array( 'id' => 71, 'title' => 'Tomo 1', 'url' => 'https://akibara.test/p/71/' ),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$absent_product = $this->series_dto(
			array(
				'name'       => 'Tomo ausente del catálogo',
				'continuity' => array(
					'kind'          => 'next',
					'availability'  => 'unknown',
					'volume_number' => 5,
					'product'       => null,
					'options'       => array(),
					'options_count' => 0,
				),
			)
		);

		$html = ( new Renderer(
			static fn( int $user_id ): array => array(
				'status' => 'ok',
				'series' => array( $available, $preorder, $waitlist, $sold_out, $unknown_stock, $absent_product ),
			)
		) )->render( 10 );

		self::assertStringContainsString( 'Siguiente en tu edición: tomo 8', $html );
		self::assertStringContainsString( 'Ver tomo 8', $html );
		self::assertStringContainsString( 'href="https://akibara.test/p/8/"', $html );
		self::assertStringContainsString( 'Empieza esta edición con el tomo 1', $html );
		self::assertStringContainsString( 'Preventa · entrega estimada 12 de septiembre', $html );
		self::assertStringContainsString( 'Reservar tomo 1', $html );
		self::assertStringContainsString( 'No vemos el tomo 3 entre tus compras de Akibara', $html );
		self::assertStringContainsString( 'Avísame cuando vuelva', $html );
		self::assertStringContainsString( 'href="https://akibara.test/p/31/#akb-bis-form"', $html );
		self::assertStringContainsString( 'Siguiente en tu edición: tomo 6', $html );
		self::assertStringContainsString( 'href="https://akibara.test/p/61/"', $html );
		self::assertStringContainsString( 'No disponible por ahora', $html );
		self::assertStringNotContainsString( 'href="https://akibara.test/p/71/" aria-label=', $html );
		self::assertStringContainsString( 'Siguiente en tu edición: tomo 5', $html );
		self::assertStringNotContainsString( 'Ver tomo 5', $html );
		$compact_html = preg_replace( '/\s+/', '', $html );
		self::assertIsString( $compact_html );
		self::assertSame( 2, substr_count( $compact_html, '>Agotado<' ) );
		self::assertSame( 4, substr_count( $html, 'akb-account-collection__button--card' ) );
		self::assertSame( 6, preg_match_all( '/<article\b.*?<\/article>/s', $html, $cards ) );
		foreach ( $cards[0] as $card ) {
			self::assertLessThanOrEqual( 1, substr_count( $card, 'akb-account-collection__button--card' ) );
		}
	}

	/**
	 * Hallazgo S-6d21e49e: antes, el verbo se decidía comparando el TEXTO de
	 * `preorder_label` contra la palabra de fábrica «Preventa». Si el dueño
	 * renombra esa palabra en Akibara → Textos (p.ej. «Reserva anticipada»),
	 * una preventa real dejaba de empezar por «Preventa» y el botón pasaba de
	 * «Reservar» a «Pedir» — el verbo equivocado para un tomo que aún no sale.
	 * El fix lee `availability_state`, que no depende del copy.
	 *
	 * @testdox El verbo del CTA sigue al ESTADO aunque el dueño renombre la palabra de fábrica.
	 */
	public function test_the_cta_verb_survives_a_renamed_word(): void {
		$preventa_renombrada = $this->series_dto(
			array(
				'name'       => 'Preventa con palabra renombrada',
				'continuity' => array(
					'kind'          => 'start',
					'availability'  => 'preorder',
					'volume_number' => 1,
					'product'       => array(
						'id'                 => 21,
						'title'              => 'Tomo 1',
						'url'                => 'https://akibara.test/p/21/',
						// El dueño cambió la palabra de fábrica: el texto YA NO
						// empieza por «Preventa».
						'preorder_label'     => 'Reserva anticipada · entrega estimada 12 de septiembre',
						'availability_state' => Availability::PREVENTA,
						'is_purchasable'     => true,
					),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);
		$a_pedido = $this->series_dto(
			array(
				'name'       => 'A pedido',
				'continuity' => array(
					'kind'          => 'start',
					'availability'  => 'preorder',
					'volume_number' => 2,
					'product'       => array(
						'id'                 => 22,
						'title'              => 'Tomo 2',
						'url'                => 'https://akibara.test/p/22/',
						'preorder_label'     => 'A pedido · disponible en 10 días',
						'availability_state' => Availability::A_PEDIDO,
						'is_purchasable'     => true,
					),
					'options'       => array(),
					'options_count' => 1,
				),
			)
		);

		$html = ( new Renderer(
			static fn( int $user_id ): array => array(
				'status' => 'ok',
				'series' => array( $preventa_renombrada, $a_pedido ),
			)
		) )->render( 10 );

		self::assertStringContainsString(
			'Reservar tomo 1',
			$html,
			'La preventa real sigue siendo «Reservar» aunque la palabra de fábrica ya no aparezca en el texto.'
		);
		self::assertStringNotContainsString( 'Pedir tomo 1', $html );
		self::assertStringContainsString( 'Pedir tomo 2', $html );
		self::assertStringNotContainsString( 'Reservar tomo 2', $html );
	}

	/** @testdox Completa, al día y desconocida informan sin inventar una compra siguiente. */
	public function test_terminal_and_unknown_states_have_no_primary_cta(): void {
		$complete = $this->series_dto(
			array(
				'name'       => 'Completa',
				'continuity' => array( 'kind' => 'complete', 'availability' => 'none' ),
			)
		);
		$up_to_date = $this->series_dto(
			array(
				'name'       => 'Al día',
				'continuity' => array( 'kind' => 'up_to_date', 'availability' => 'none' ),
			)
		);
		$unknown = $this->series_dto(
			array(
				'name'       => 'Sin número',
				'continuity' => array( 'kind' => 'next', 'availability' => 'available', 'volume_number' => '99', 'product' => array( 'url' => 'https://akibara.test/p/99/' ) ),
			)
		);

		$html = ( new Renderer(
			static fn( int $user_id ): array => array(
				'status' => 'ok',
				'series' => array( $complete, $up_to_date, $unknown ),
			)
		) )->render( 10 );

		self::assertStringContainsString( 'Edición completa', $html );
		self::assertStringContainsString( 'Estás al día con lo publicado en Akibara', $html );
		self::assertStringContainsString( 'No podemos determinar el siguiente tomo con seguridad', $html );
		self::assertMatchesRegularExpression( '/<dt>Ediciones completas<\/dt>\s*<dd>1<\/dd>/', $html );
		self::assertStringNotContainsString( 'akb-account-collection__button--card', $html );
		self::assertStringNotContainsString( 'href="https://akibara.test/p/99/"', $html );
	}

	/** @testdox Variantes sin term URL no usan el fallback de producto como serie. */
	public function test_multiple_versions_without_series_url_have_no_fallback_cta(): void {
		$dto = $this->series_dto(
			array(
				'url'                      => '',
				'completion_url'           => 'https://akibara.test/p/8/',
				'missing_catalog_products' => array(
					array( 'id' => 8, 'title' => 'Tomo 8', 'url' => 'https://akibara.test/p/8/' ),
				),
				'continuity'               => array(
					'kind'          => 'gap',
					'availability'  => 'available',
					'volume_number' => 8,
					'product'       => array( 'id' => 8, 'title' => 'Tomo 8 A', 'url' => 'https://akibara.test/p/8/' ),
					'options'       => array(
						array( 'id' => 8, 'title' => 'Tomo 8 A', 'url' => 'https://akibara.test/p/8/' ),
						array( 'id' => 18, 'title' => 'Tomo 8 B', 'url' => 'https://akibara.test/p/18/' ),
					),
					'options_count' => 2,
				),
			)
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $dto ) ) ) )->render( 10 );

		self::assertStringContainsString( 'Hay 2 versiones del tomo 8', $html );
		self::assertStringNotContainsString( 'akb-account-collection__button--card', $html );
		self::assertStringNotContainsString( 'href="https://akibara.test/p/8/" aria-label="Ver versiones', $html );
		self::assertMatchesRegularExpression( '/<h3[^>]*>\s*Serie de prueba\s*<\/h3>/', $html );
	}

	/** @testdox Productos propios se escapan, son navegables y los históricos se explican. */
	public function test_owned_products_are_escaped_and_historical_items_are_explained(): void {
		$dto = $this->series_dto(
			array(
				'owned_count'          => 2,
				'owned_volume_count'   => 1,
				'historical_only_count' => 1,
				'owned_products'       => array(
					array( 'id' => 1, 'title' => 'Tomo <script>alert(1)</script>', 'url' => 'https://akibara.test/p/1/' ),
					array( 'id' => 2, 'title' => 'Tomo 2', 'url' => '' ),
				),
			)
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $dto ) ) ) )->render( 10 );

		self::assertStringContainsString( 'Ver compras de esta edición', $html );
		self::assertStringContainsString( 'Tomo &lt;script&gt;alert(1)&lt;/script&gt;', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '1 tomo comprado ya no está en el catálogo actual', $html );
		self::assertMatchesRegularExpression( '/<dt>Tomos<\/dt>\s*<dd>\s*1\s*<\/dd>/', $html );
	}

	/** @testdox Un especial sin número no fabrica un total de tomos. */
	public function test_unknown_logical_volume_count_has_no_invented_summary_number(): void {
		$dto = $this->series_dto(
			array(
				'owned_count'        => 2,
				'owned_volume_count' => null,
				'owned_products'     => array(
					array( 'id' => 1, 'title' => 'Integral especial', 'url' => 'https://akibara.test/p/1/' ),
					array( 'id' => 2, 'title' => 'Pack aniversario', 'url' => 'https://akibara.test/p/2/' ),
				),
			)
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $dto ) ) ) )->render( 10 );

		self::assertMatchesRegularExpression( '/<dt>Compras registradas<\/dt>\s*<dd>\s*<span[^>]*>Sin cifra segura<\/span>\s*<\/dd>/', $html );
		self::assertStringContainsString( 'Ver compras de esta edición', $html );
		self::assertStringNotContainsString( '<dt>Tomos</dt>', $html );
	}

	/** @testdox Cover products and their attachments are primed in two batches. */
	public function test_cover_images_are_primed_without_queries_per_series(): void {
		$prime_calls = array();
		Functions\when( '_prime_post_caches' )->alias(
			static function ( array $ids, bool $term_cache, bool $meta_cache ) use ( &$prime_calls ): void {
				$prime_calls[] = array( $ids, $term_cache, $meta_cache );
			}
		);
		Functions\when( 'get_post_thumbnail_id' )->alias(
			static fn( int $product_id ): int => 1000 + $product_id
		);

		$first  = $this->series_dto( array( 'name' => 'Serie A', 'cover_product_id' => 11 ) );
		$second = $this->series_dto( array( 'name' => 'Serie B', 'cover_product_id' => 22 ) );
		( new Renderer(
			static fn( int $user_id ): array => array(
				'status' => 'ok',
				'series' => array( $first, $second ),
			)
		) )->render( 10 );

		self::assertSame(
			array(
				array( array( 11, 22 ), false, true ),
				array( array( 1011, 1022 ), false, true ),
			),
			$prime_calls
		);
	}

	/**
	 * El interruptor GLOBAL de avisos se expone en 'ok' y 'empty' con el estado
	 * real de la usermeta; con la baja global puesta, cada tarjeta queda
	 * bloqueada (sin botón por serie, con la línea que lo explica) porque
	 * `opt_in()` de una serie levantaría el global desde una tarjeta.
	 *
	 * @testdox follow_all refleja la baja global y bloquea el botón de cada serie.
	 */
	public function test_follow_all_reflects_the_global_opt_out_and_locks_each_series(): void {
		$dos_series = static fn( int $user_id ): array => array(
			'status' => 'ok',
			'series' => array(
				array( 'term_id' => 4, 'name' => 'Serie A', 'owned_products' => array(), 'edition' => array(), 'continuity' => array() ),
				array( 'term_id' => 5, 'name' => 'Serie B', 'owned_products' => array(), 'edition' => array(), 'continuity' => array() ),
			),
		);

		// Sin baja: sección encendida, dos botones por serie, ninguna tarjeta bloqueada.
		$encendido = ( new Renderer( $dos_series ) )->render( 10 );
		self::assertStringContainsString( 'data-enabled="true"', $encendido );
		self::assertStringContainsString( 'value="off"', $encendido );
		self::assertStringContainsString( 'nonce-akb_sf_global_10', $encendido, 'El nonce va atado al id del cliente.' );
		self::assertSame( 2, substr_count( $encendido, 'data-akb-series-follow' ) );
		self::assertStringNotContainsString( 'akb-series-follow__locked', $encendido );

		// Con la baja global: sección apagada, cero botones, dos líneas de bloqueo.
		Functions\when( 'get_user_meta' )->alias(
			static fn( int $user_id, string $key ) => '_akb_sf_optout_all' === $key ? '1' : ''
		);
		$apagado = ( new Renderer( $dos_series ) )->render( 10 );
		self::assertStringContainsString( 'data-enabled="false"', $apagado );
		self::assertStringContainsString( 'value="on"', $apagado );
		self::assertStringNotContainsString( 'data-akb-series-follow', $apagado );
		self::assertSame( 2, substr_count( $apagado, 'akb-series-follow__locked' ) );

		// En 'empty' la sección existe (quien se dio de baja desde un correo
		// tiene que poder reencender); en 'unavailable' TAMBIÉN (contrato
		// cambiado el 2026-09-11: el consentimiento no depende de que el
		// resolver de compras funcione); sin sesión no.
		$vacio = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 10 );
		self::assertStringContainsString( 'akb-account-collection__follow-all', $vacio );

		// R2-8951cc1e: «Explorar series» sale de SeriesHubUrl, no del literal
		// `/series/` — si el dueño movió esa página, este enlace la sigue.
		Functions\when( 'get_posts' )->justReturn( array( 501 ) );
		Functions\when( 'get_post_field' )->justReturn( '[akibara_series_directory]' );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/todas-las-series/' );
		SeriesHubUrl::reset();
		$vacio_url_movida = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 10 );
		self::assertStringContainsString( 'href="https://akibara.test/todas-las-series/">Explorar series', $vacio_url_movida );

		$no_disponible = ( new Renderer(
			static function ( int $user_id ): array {
				throw new \RuntimeException( 'caída' );
			}
		) )->render( 10 );
		self::assertStringContainsString( 'akb-account-collection__follow-all', $no_disponible );
		self::assertStringContainsString( 'data-enabled="false"', $no_disponible, 'Refleja el estado real aunque la colección no cargue.' );

		// Con el global encendido y UNA serie apagada una a una, la sección
		// cuenta esa serie; con las dos, cuenta dos.
		Functions\when( 'get_user_meta' )->alias(
			static fn( int $user_id, string $key ) => '_akb_sf_optout' === $key ? array( 4 ) : ''
		);
		$parcial = ( new Renderer( $dos_series ) )->render( 10 );
		self::assertStringContainsString( 'Salvo una serie que apagaste una a una: la reactivas desde su tarjeta.', $parcial );
		Functions\when( 'get_user_meta' )->alias(
			static fn( int $user_id, string $key ) => '_akb_sf_optout' === $key ? array( 4, 5 ) : ''
		);
		$parcial = ( new Renderer( $dos_series ) )->render( 10 );
		self::assertStringContainsString( 'Salvo 2 series que apagaste una a una: las reactivas desde su tarjeta.', $parcial );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$sin_sesion = ( new Renderer( $dos_series ) )->render( 0 );
		self::assertStringNotContainsString( 'akb-account-collection__follow-all', $sin_sesion );
	}

	/**
	 * ── El aviso de compras recientes ───────────────────────────────────────
	 * La colección se arma con las compras ya registradas, no en el instante de
	 * pagar. Quien acaba de comprar abre esta pantalla, no ve su tomo y concluye
	 * que la compra se perdió. Se dice en las dos pantallas donde el cliente
	 * cuenta tomos: la vacía y la de las tarjetas.
	 *
	 * @testdox El aviso de demora sale de fábrica en la colección vacía y encima de las tarjetas.
	 */
	public function test_avisa_que_las_compras_recientes_tardan_en_aparecer(): void {
		unset( $GLOBALS['akibara_test_options'] );

		$vacio = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 3 );
		self::assertStringContainsString( 'akb-account-collection__delay-note', $vacio );
		self::assertStringContainsString( Renderer::DEFAULT_DELAY_NOTICE, $vacio );

		$con_tomos = ( new Renderer( fn( int $user_id ): array => $this->coleccion_con_una_serie() ) )->render( 3 );
		self::assertStringContainsString( 'akb-account-collection__delay-note', $con_tomos );
	}

	/**
	 * El aviso se pinta encendido de fábrica y en la pantalla donde aterriza el
	 * comprador por transferencia, que es el método más usado de la tienda (36
	 * de 120 pedidos en 180 días, medido en producción) y el que entra en «En
	 * espera» hasta que el dueño confirma el depósito a mano: 17 de esos 36
	 * terminaron cancelados, o sea que el tomo no aparece nunca. Un literal de
	 * fábrica que prometa minutos —«unos minutos», «en breve», «enseguida»— le
	 * promete al cliente lo que la tienda no cumple, y por eso esta prueba
	 * prohíbe el reloj en vez de fijar la redacción.
	 *
	 * @testdox [NEG] El aviso de fábrica no promete un plazo que la tienda no controla.
	 */
	public function test_el_aviso_de_fabrica_no_promete_un_plazo(): void {
		$literal = mb_strtolower( Renderer::DEFAULT_DELAY_NOTICE, 'UTF-8' );

		foreach ( array( 'unos minutos', 'pocos minutos', 'en breve', 'enseguida', 'de inmediato', 'unas horas' ) as $promesa ) {
			self::assertStringNotContainsString(
				$promesa,
				$literal,
				'El aviso de fábrica no puede prometer un plazo: una transferencia sin confirmar tarda horas o no llega nunca.'
			);
		}

		// Y sí tiene que decir la condición REAL, que es lo único verdadero para
		// los cuatro métodos de pago vivos.
		self::assertStringContainsString( 'pago confirmado', $literal );
	}

	/** @testdox El texto del aviso sale de la option del panel, no de la plantilla. */
	public function test_el_aviso_de_demora_es_editable_desde_el_panel(): void {
		$GLOBALS['akibara_test_options'] = array(
			Renderer::OPTION_DELAY_NOTICE => 'Tu compra aparece acá apenas la registramos.',
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 3 );

		self::assertStringContainsString( 'Tu compra aparece acá apenas la registramos.', $html );
		self::assertStringNotContainsString( Renderer::DEFAULT_DELAY_NOTICE, $html );

		unset( $GLOBALS['akibara_test_options'] );
	}

	/**
	 * @testdox Vaciar la option retira la línea, y no la resucita con el literal de fábrica.
	 */
	public function test_vaciar_la_option_retira_el_aviso(): void {
		$GLOBALS['akibara_test_options'] = array( Renderer::OPTION_DELAY_NOTICE => '   ' );

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 3 );

		self::assertStringNotContainsString( 'akb-account-collection__delay-note', $html );

		unset( $GLOBALS['akibara_test_options'] );
	}

	/**
	 * El estado «no disponible» es un fallo, no una espera: ofrecerle al cliente
	 * «espera unos minutos» ahí lo manda a esperar por algo que no se arregla
	 * solo.
	 *
	 * @testdox [NEG] El aviso de demora no aparece cuando la colección no cargó.
	 */
	public function test_el_aviso_de_demora_no_aparece_en_el_estado_de_error(): void {
		unset( $GLOBALS['akibara_test_options'] );

		$html = ( new Renderer(
			static function ( int $user_id ): array {
				throw new \RuntimeException( 'caída' );
			}
		) )->render( 3 );

		self::assertStringNotContainsString( 'akb-account-collection__delay-note', $html );
	}

	/** @return array<string,mixed> DTO mínimo con una serie pintable. */
	private function coleccion_con_una_serie(): array {
		return array(
			'status' => 'ok',
			'series' => array(
				array(
					'name'                  => 'Berserk',
					'url'                   => 'https://akibara.test/serie/berserk/',
					'owned_products'        => array(),
					'owned_count'           => 1,
					'owned_volume_count'    => 1,
					'catalog_count'         => 3,
					'missing_catalog_count' => 2,
					'historical_only_count' => 0,
					'cover_product_id'      => 0,
					'edition'               => array(
						'state'          => 'completa',
						'total'          => 3,
						'progress_owned' => 1,
						'is_complete'    => false,
					),
					'continuity'            => array(
						'kind'          => 'next',
						'volume_number' => 2,
						'product'       => array(),
					),
				),
			),
		);
	}

	/** @testdox Vacío y error son honestos, accionables y no revelan detalles técnicos. */
	public function test_renders_honest_empty_and_unavailable_states(): void {
		$empty = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'empty', 'series' => array() ) ) )->render( 3 );

		// Candado por INVARIANTE, no por literal: lo que este caso protege es que
		// el estado vacío sea HONESTO y ACCIONABLE, no una frase concreta. En
		// 1.108.14 el copy cambió porque la frase anterior —«Todavía no vemos
		// compras asociadas a esta cuenta»— le negaba la compra a quien sí había
		// comprado como invitado, y la salida real iba en letra chica con el
		// botón principal apuntando a otra parte.
		self::assertStringNotContainsString(
			'Todavía no vemos compras asociadas a esta cuenta',
			$empty,
			'No afirmar que no hay compras: puede haberlas, sin vincular.'
		);
		// Accionable: nombra el camino de recuperación y lo pone como acción.
		self::assertStringContainsString( 'Pedidos', $empty );
		self::assertMatchesRegularExpression(
			'/sin iniciar sesión|como invitado/u',
			$empty,
			'Tiene que reconocer la compra sin sesión, que es el caso real.'
		);
		self::assertMatchesRegularExpression(
			'/Confirma tu correo|verificar/u',
			$empty,
			'Tiene que decir QUÉ hacer para recuperar el historial.'
		);
		self::assertStringContainsString( 'no te suscribe a marketing', $empty );
		// El botón primario lleva a la acción que resuelve; el secundario a explorar.
		self::assertMatchesRegularExpression(
			'/akb-account-collection__button"[^>]*href="[^"]*orders[^"]*"/u',
			$empty,
			'El botón primario del estado vacío debe llevar a Pedidos.'
		);
		self::assertStringContainsString( 'Explorar series', $empty );
		self::assertStringNotContainsString( 'Explorar series y ediciones', $empty );
		self::assertStringNotContainsString( 'Tienes 0', $empty );

		$unavailable = ( new Renderer(
			static function ( int $user_id ): array {
				throw new \RuntimeException( 'SQLSTATE private detail' );
			}
		) )->render( 3 );

		self::assertStringContainsString( 'No pudimos cargar tu colección ahora', $unavailable );
		self::assertStringContainsString( 'Tus compras siguen seguras', $unavailable );
		self::assertStringContainsString( 'Reintentar', $unavailable );
		self::assertStringNotContainsString( 'SQLSTATE', $unavailable );
	}

	/**
	 * @testdox El estado «no disponible» deja traza en el log de WooCommerce.
	 *
	 * Es la ÚNICA salida de error de la pantalla y el catch se tragaba el
	 * Throwable entero: al cliente le aparecía algo indistinguible de una
	 * colección vacía y en el servidor no quedaba nada que mirar.
	 */
	public function test_the_unavailable_state_leaves_a_trace(): void {
		$registrado = array();

		Functions\when( 'wc_get_logger' )->justReturn(
			new class( $registrado ) {
				/** @var array<string, mixed> */
				private array $sink;

				/**
				 * @param array<string, mixed> $sink
				 */
				public function __construct( array &$sink ) {
					$this->sink = &$sink;
				}

				/**
				 * @param array<string, mixed> $context
				 */
				public function warning( string $message, array $context = array() ): void {
					$this->sink[] = array( $message, $context );
				}
			}
		);

		( new Renderer(
			static function ( int $user_id ): array {
				throw new \RuntimeException( 'SQLSTATE private detail' );
			}
		) )->render( 77 );

		self::assertCount( 1, $registrado, 'Sin traza, el fallo es indiagnosticable en producción.' );
		self::assertStringContainsString( '77', $registrado[0][0], 'La traza tiene que nombrar al cliente afectado.' );
		self::assertStringContainsString( 'SQLSTATE private detail', $registrado[0][0] );
		self::assertSame( 'akibara-coleccion', $registrado[0][1]['source'] );
		self::assertSame( \RuntimeException::class, $registrado[0][1]['exception'] );
	}

	/** @testdox Unclassified purchases remain visible and never become a false first-purchase empty state. */
	public function test_unclassified_purchases_are_counted_and_explained(): void {
		$html = ( new Renderer(
			static fn( int $user_id ): array => array(
				'status'             => 'ok',
				'series'             => array(),
				'unclassified_count' => 2,
			)
		) )->render( 10 );

		self::assertStringNotContainsString( 'Todavía no vemos compras asociadas a esta cuenta', $html );
		self::assertStringContainsString( 'Aún no pudimos agrupar tus compras por edición', $html );
		self::assertStringContainsString( '2 productos comprados', $html );
		self::assertStringContainsString( 'una sola edición', $html );
		self::assertStringContainsString( 'Explorar series', $html );
		self::assertStringNotContainsString( 'Explorar series y ediciones', $html );
		self::assertStringContainsString( 'No los contamos como tomos', $html );
		self::assertStringNotContainsString( '<dt>Tomos</dt>', $html );
	}

	/**
	 * Un tomo único sin «01» en el título («Uzumaki») no puede quedar como
	 * «No podemos determinar el siguiente tomo», ni prometer avisos de un
	 * siguiente tomo que no existe, ni decir «1 tomos».
	 *
	 * @testdox Un tomo único comprado se muestra completo, en singular y sin botón de avisos.
	 */
	public function test_one_shot_purchased_is_complete_singular_and_without_follow(): void {
		$dto = $this->series_dto(
			array(
				'name'                  => 'Uzumaki',
				'owned_count'           => 1,
				'owned_volume_count'    => null,
				'catalog_count'         => 1,
				'missing_catalog_count' => 0,
				'edition'               => array(
					'state'          => 'completa',
					'total'          => 1,
					'progress_owned' => null,
					'missing_count'  => null,
					'is_complete'    => null,
				),
			)
		);

		$html = ( new Renderer( static fn( int $user_id ): array => array( 'status' => 'ok', 'series' => array( $dto ) ) ) )->render( 10 );

		self::assertStringContainsString( 'Edición de tomo único', $html );
		self::assertStringContainsString( 'Edición completa · Obra autoconclusiva', $html );
		self::assertStringContainsString( '1 de 1 tomo<', $html );
		self::assertStringNotContainsString( '1 tomos', $html );
		self::assertStringNotContainsString( 'No podemos determinar el siguiente tomo', $html );
		self::assertStringNotContainsString( 'data-akb-series-follow', $html );
		self::assertMatchesRegularExpression( '/<dt>Ediciones completas<\/dt>\s*<dd>1<\/dd>/', $html );
	}

	/**
	 * @param array<string,mixed> $overrides Campos a reemplazar.
	 * @return array<string,mixed>
	 */
	private function series_dto( array $overrides = array() ): array {
		$base = array(
			'term_id'                     => 4,
			'term_taxonomy_id'            => 40,
			'name'                        => 'Serie de prueba',
			'slug'                        => 'serie-de-prueba',
			'url'                         => 'https://akibara.test/series/prueba/',
			'completion_url'              => 'https://akibara.test/series/prueba/#completar-coleccion',
			'owned_product_ids'           => array( 1 ),
			'owned_count'                 => 1,
			'owned_volume_count'          => 1,
			'owned_products'              => array(
				array( 'id' => 1, 'title' => 'Tomo 1', 'url' => 'https://akibara.test/p/1/' ),
			),
			'catalog_product_ids'         => array( 1 ),
			'catalog_count'               => 1,
			'owned_in_catalog_product_ids' => array( 1 ),
			'owned_in_catalog_count'       => 1,
			'missing_catalog_product_ids'  => array(),
			'missing_catalog_count'        => 0,
			'missing_catalog_products'     => array(),
			'historical_only_product_ids'  => array(),
			'historical_only_count'        => 0,
			'cover_product_id'             => 1,
			'edition'                     => array(
				'state'          => 'en_publicacion',
				'total'          => 0,
				'progress_owned' => null,
				'missing_count'  => null,
				'is_complete'    => null,
			),
			'continuity'                  => array(
				'kind'          => 'unknown',
				'availability'  => 'none',
				'volume_number' => null,
				'product'       => null,
				'options'       => array(),
				'options_count' => 0,
			),
		);

		return array_replace( $base, $overrides );
	}
}
