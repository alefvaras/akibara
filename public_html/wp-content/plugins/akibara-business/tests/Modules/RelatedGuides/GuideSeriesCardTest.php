<?php
/**
 * La serie de una guía del blog y los datos de su tarjeta (D6, lote de
 * integración 2026-09-23). Hasta ese lote todo esto vivía en el tema
 * (themes/akibara-gp/inc/guide-series.php): la búsqueda inversa del vínculo, el
 * desempate entre ediciones, la portada cacheada, los conteos con su propia
 * regla y la etiqueta de caché de la página. El tema ahora sólo pinta lo que
 * devuelve el filtro `akb_guide_series_card`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\RelatedGuides;

use Akibara\Business\Modules\RelatedGuides\GuideSeriesCard;
use Akibara\Business\Modules\RelatedGuides\SeriesRelatedGuide;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class GuideSeriesCardTest extends TestCase {

	/**
	 * Hechos por term_taxonomy_id que devuelve el doble de SeriesFacts.
	 *
	 * @var array<int, array<string, int>>
	 */
	private array $hechos = array();

	/**
	 * The System Under Test.
	 *
	 * @var GuideSeriesCard
	 */
	private GuideSeriesCard $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$memo = new \ReflectionProperty( GuideSeriesCard::class, 'memo' );
		$memo->setAccessible( true );
		$memo->setValue( null, array() );

		Functions\when( 'get_option' )->alias( static fn( $option, $default_value = false ) => $default_value );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'get_term_link' )->alias( static fn( $term ): string => 'https://akibara.test/serie/' . $term->slug . '/' );
		// La portada ya cacheada: SeriesCover no se consulta.
		Functions\when( 'get_transient' )->justReturn( 'https://akibara.test/wp-content/uploads/portada.avif' );

		$hechos    = &$this->hechos;
		$this->sut = new GuideSeriesCard(
			static function () use ( &$hechos ): object {
				return new class( $hechos ) {
					/** @var array<int, array<string, int>> */
					private array $hechos;

					/** @param array<int, array<string, int>> $hechos Hechos. */
					public function __construct( array &$hechos ) {
						$this->hechos = &$hechos;
					}

					/** @return array<string, int>|null */
					public function catalog_facts( int $term_taxonomy_id ): ?array {
						return $this->hechos[ $term_taxonomy_id ] ?? null;
					}
				};
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Término de serie (su term_taxonomy_id es $id + 1000: en una base real no coinciden).
	 */
	private function serie( int $id, string $slug, string $name, int $count ): \WP_Term {
		$term                   = new \WP_Term( $id, $slug, $name );
		$term->term_taxonomy_id = $id + 1000;
		$term->taxonomy         = 'pa_serie';
		$term->count            = $count;

		return $term;
	}

	/**
	 * @testdox La serie se busca por el meta que escribe la «Guía relacionada», con el ID de la entrada como texto.
	 */
	public function test_busca_la_serie_por_el_meta_del_vinculo(): void {
		$pedido = null;
		Functions\when( 'get_terms' )->alias(
			function ( array $args ) use ( &$pedido ) {
				$pedido = $args;
				return array( $this->serie( 477, 'the-climber', 'The Climber', 17 ) );
			}
		);

		$term = GuideSeriesCard::series_for_guide( 900001 );

		self::assertSame( 477, $term->term_id ?? null );
		self::assertSame( 'pa_serie', $pedido['taxonomy'] );
		self::assertSame( SeriesRelatedGuide::META_KEY, $pedido['meta_query'][0]['key'] );
		self::assertSame( '900001', $pedido['meta_query'][0]['value'], 'El meta guarda el ID como texto.' );
	}

	/**
	 * @testdox Con varias ediciones ligadas a la misma guía gana la que tiene más productos; sin productos no cuenta; empate por nombre.
	 */
	public function test_gana_la_edicion_con_mas_productos(): void {
		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->serie( 598, 'berserk-master', 'Berserk Master Edition', 1 ),
				$this->serie( 525, 'maximum-berserk', 'Maximum Berserk', 21 ),
				$this->serie( 700, 'berserk-vacia', 'Berserk vacía', 0 ),
			)
		);
		self::assertSame( 525, GuideSeriesCard::series_for_guide( 900002 )->term_id );

		Functions\when( 'get_terms' )->justReturn(
			array(
				$this->serie( 11, 'z', 'Zeta', 5 ),
				$this->serie( 12, 'a', 'Alfa', 5 ),
			)
		);
		self::assertSame( 12, GuideSeriesCard::series_for_guide( 900003 )->term_id, 'El empate no depende del orden de la base.' );
	}

	/**
	 * @testdox Una entrada sin serie ligada, o una consulta que falla, no tiene serie ni tarjeta.
	 */
	public function test_sin_vinculo_no_hay_serie(): void {
		Functions\when( 'get_terms' )->justReturn( array() );
		self::assertNull( GuideSeriesCard::series_for_guide( 900004 ) );
		self::assertNull( $this->sut->card( null, 900004 ) );

		Functions\when( 'get_terms' )->justReturn( new \stdClass() );
		self::assertNull( GuideSeriesCard::series_for_guide( 900005 ) );
		self::assertNull( GuideSeriesCard::series_for_guide( 0 ) );
	}

	/**
	 * El caso de la revisión T7-2 (D3): la guía de «El chico que me gusta…» y su
	 * landing contaban con reglas distintas. Ahora las dos salen de
	 * `SeriesFacts::stock_rows()` —mismas palabras y mismos números—, y la guía
	 * omite las filas en cero (QA-MOV-7, ver `estados()`).
	 *
	 * @testdox La tarjeta trae nombre, URL, portada y los tomos por estado con la regla y las palabras de la ficha de la landing.
	 */
	public function test_tarjeta_con_la_regla_de_la_ficha(): void {
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 414, 'el-chico', 'El chico que me gusta no es un chico', 3 ) ) );
		$this->hechos[1414] = array( 'tomos' => 3, 'instock' => 0, 'preventa' => 0, 'agotados' => 3 );

		self::assertSame(
			array(
				'name'    => 'El chico que me gusta no es un chico',
				'url'     => 'https://akibara.test/serie/el-chico/',
				'cover'   => 'https://akibara.test/wp-content/uploads/portada.avif',
				'estados' => array(
					array( 'label' => 'Agotado', 'texto' => '3 tomos' ),
				),
			),
			$this->sut->card( null, 900006 )
		);
	}

	/**
	 * QA integral 2026-09-24 (QA-MOV-7): la guía de Berserk decía «Disponible 1
	 * tomo · A pedido 0 tomos».
	 *
	 * @testdox La guía no pinta filas en cero: sólo los estados que tienen tomos, en el orden de la ficha.
	 */
	public function test_la_guia_omite_las_filas_en_cero(): void {
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 481, 'berserk-master-edition', 'Berserk Master Edition', 1 ) ) );
		$this->hechos[1481] = array( 'tomos' => 1, 'instock' => 1, 'preventa' => 0, 'agotados' => 0 );
		self::assertSame(
			array( array( 'label' => 'Disponible', 'texto' => '1 tomo' ) ),
			$this->sut->card( null, 900030 )['estados'] ?? null
		);

		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 482, 'jujutsu-kaisen', 'Jujutsu Kaisen', 30 ) ) );
		$this->hechos[1482] = array( 'tomos' => 30, 'instock' => 13, 'preventa' => 0, 'agotados' => 17 );
		self::assertSame(
			array(
				array( 'label' => 'Disponible', 'texto' => '13 tomos' ),
				array( 'label' => 'Agotado', 'texto' => '17 tomos' ),
			),
			$this->sut->card( null, 900031 )['estados'] ?? null,
			'Índices seguidos: el tema recorre la lista tal cual.'
		);
	}

	/**
	 * @testdox Sin agotados no sale Agotado; «1 tomo» en singular; sin agregado, sin conteos (no se inventa un cero).
	 */
	public function test_conteos_sin_agotados_y_sin_agregado(): void {
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 477, 'the-climber', 'The Climber', 13 ) ) );
		$this->hechos[1477] = array( 'tomos' => 9, 'instock' => 8, 'preventa' => 1, 'agotados' => 0 );
		self::assertSame(
			array(
				array( 'label' => 'Disponible', 'texto' => '8 tomos' ),
				array( 'label' => 'A pedido', 'texto' => '1 tomo' ),
			),
			$this->sut->card( null, 900007 )['estados'] ?? null
		);

		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 999, 'sin-datos', 'Sin datos', 1 ) ) );
		self::assertSame( array(), $this->sut->card( null, 900008 )['estados'] ?? null );
	}

	/**
	 * REV-T7-3-D2: el `count` del término (WordPress) deja pasar la tarjeta con
	 * productos que SeriesFacts no cuenta como tomo (variantes, fuera del
	 * catálogo). La landing de esa serie no pinta su ficha
	 * (`SeriesFacts::renderable_facts()`: tomos < 1); la guía tampoco puede
	 * afirmar «Disponible 0 tomos · A pedido 0 tomos».
	 *
	 * @testdox Sin ningún tomo contado la tarjeta sale sin conteos, con la misma regla con que la landing calla su ficha.
	 */
	public function test_sin_tomos_contados_no_afirma_ceros(): void {
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 610, 'solo-variantes', 'Serie con sólo variantes', 2 ) ) );
		$this->hechos[1610] = array( 'tomos' => 0, 'instock' => 0, 'preventa' => 0, 'agotados' => 0 );

		$card = $this->sut->card( null, 900020 );

		self::assertSame( 'Serie con sólo variantes', $card['name'] ?? null, 'La tarjeta sigue enlazando a la serie.' );
		self::assertSame( array(), $card['estados'] ?? null );

		// Un agregado sin la clave (forma vieja) tampoco inventa ceros.
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 611, 'sin-clave', 'Sin clave', 2 ) ) );
		$this->hechos[1611] = array( 'instock' => 0, 'preventa' => 0 );
		self::assertSame( array(), $this->sut->card( null, 900021 )['estados'] ?? null );
	}

	/**
	 * @testdox La página de una guía con serie se etiqueta con la caché del término; sin serie, o fuera de una entrada, nada.
	 */
	public function test_etiqueta_de_cache_de_la_serie(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 900010 );
		Functions\when( 'get_terms' )->justReturn( array( $this->serie( 477, 'the-climber', 'The Climber', 17 ) ) );
		Actions\expectDone( 'litespeed_tag_add' )->once()->with( 'T.477' );

		$this->sut->tag_guide_with_series();

		Functions\when( 'is_singular' )->justReturn( false );
		$this->sut->tag_guide_with_series();

		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 900011 );
		Functions\when( 'get_terms' )->justReturn( array() );
		$this->sut->tag_guide_with_series();

		$this->addToAssertionCount( 1 ); // Mockery verifica las expectativas al cerrar.
	}

	/**
	 * @testdox El filtro y la etiqueta quedan enganchados.
	 */
	public function test_se_engancha(): void {
		$this->sut->register();

		self::assertNotFalse( has_filter( GuideSeriesCard::FILTER, array( $this->sut, 'card' ) ) );
		self::assertNotFalse( has_action( 'wp', array( $this->sut, 'tag_guide_with_series' ) ) );
	}
}
