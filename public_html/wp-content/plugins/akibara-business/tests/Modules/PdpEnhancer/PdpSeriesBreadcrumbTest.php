<?php
/**
 * La miga de la ficha sube a su serie.
 *
 * ── El defecto medido ────────────────────────────────────────────────────────
 * Rastreo de producción del 2026-09-26: ninguna de las 1.643 fichas declaraba
 * su landing de serie en la miga («Inicio / Manga / Seinen / The Climber 03»).
 * La landing `/serie/<slug>/` es la URL que debe ganar «<serie> manga», y la
 * jerarquía que Google lee es la de la miga y su BreadcrumbList.
 *
 * ── Por qué setUp() declara TODOS los guards ────────────────────────────────
 * Brain Monkey deja el símbolo DEFINIDO tras `tearDown()`: un guard que otro
 * archivo stubee de otra forma cambiaría este resultado según el orden.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\PdpEnhancer;

use Akibara\Business\Modules\PdpEnhancer\PdpSeriesEnhancer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class PdpSeriesBreadcrumbTest extends TestCase {

	private const FICHA = 'https://akibara.cl/the-climber-3-milky-way/';
	private const SERIE = 'https://akibara.cl/serie/the-climber/';

	private bool $is_product = true;

	/** @var array<int, WP_Term> */
	private array $terms = array();

	/** @var mixed */
	private $robots = '';

	/** @var mixed */
	private $term_link = self::SERIE;

	private int $get_the_terms_calls = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$serie           = new WP_Term( 435, 'the-climber', 'The Climber' );
		$serie->taxonomy = 'pa_serie';
		$this->terms     = array( $serie );

		Functions\when( 'is_singular' )->alias( fn(): bool => $this->is_product );
		Functions\when( 'get_queried_object_id' )->justReturn( 17 );
		Functions\when( 'get_permalink' )->justReturn( self::FICHA );
		Functions\when( 'get_the_terms' )->alias(
			function (): array {
				++$this->get_the_terms_calls;
				return $this->terms;
			}
		);
		Functions\when( 'get_term_meta' )->alias( fn() => $this->robots );
		Functions\when( 'get_term_link' )->alias( fn() => $this->term_link );
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'untrailingslashit' )->alias( static fn( $value ): string => rtrim( (string) $value, '/\\' ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ): string => trim( strip_tags( (string) $text ) ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array{0:string,1:string}> $crumbs Miga de entrada.
	 * @return mixed
	 */
	private function filter( array $crumbs ) {
		return ( new PdpSeriesEnhancer() )->add_series_crumb( $crumbs );
	}

	/** @return array<int, array{0:string,1:string}> */
	private static function miga_de_hoy(): array {
		return array(
			array( 'Inicio', 'https://akibara.cl' ),
			array( 'Manga', 'https://akibara.cl/manga/' ),
			array( 'Seinen', 'https://akibara.cl/seinen/' ),
			array( 'The Climber 03', self::FICHA ),
		);
	}

	public function test_inserta_la_serie_antes_del_peldano_de_la_ficha(): void {
		self::assertSame(
			array(
				array( 'Inicio', 'https://akibara.cl' ),
				array( 'Manga', 'https://akibara.cl/manga/' ),
				array( 'Seinen', 'https://akibara.cl/seinen/' ),
				array( 'The Climber', self::SERIE ),
				array( 'The Climber 03', self::FICHA ),
			),
			$this->filter( self::miga_de_hoy() )
		);
	}

	public function test_inserta_igual_sin_inicio_como_devuelve_generate(): void {
		// `( new WC_Breadcrumb() )->generate()` —la generación que usa
		// SeoEnhancements para alinear el BreadcrumbList— no trae «Inicio».
		$sin_inicio = array_slice( self::miga_de_hoy(), 1 );

		self::assertSame(
			array( 'Manga', 'Seinen', 'The Climber', 'The Climber 03' ),
			array_column( $this->filter( $sin_inicio ), 0 )
		);
	}

	public function test_con_un_peldano_final_extra_inserta_antes_de_la_ficha_y_no_antes_del_ultimo(): void {
		$crumbs   = self::miga_de_hoy();
		$crumbs[] = array( 'Página 2', '' );

		self::assertSame(
			array( 'Inicio', 'Manga', 'Seinen', 'The Climber', 'The Climber 03', 'Página 2' ),
			array_column( $this->filter( $crumbs ), 0 )
		);
	}

	public function test_la_ficha_se_reconoce_aunque_difiera_la_barra_final(): void {
		$crumbs       = self::miga_de_hoy();
		$crumbs[3][1] = rtrim( self::FICHA, '/' );

		self::assertSame( 'The Climber', $this->filter( $crumbs )[3][0] );
	}

	public function test_el_nombre_pasa_por_strip_tags_como_los_de_woocommerce(): void {
		$this->terms[0]->name = '<b>The Climber</b>';

		self::assertSame( 'The Climber', $this->filter( self::miga_de_hoy() )[3][0] );
	}

	public function test_es_idempotente_si_la_serie_ya_esta(): void {
		$una_vez = $this->filter( self::miga_de_hoy() );

		self::assertSame( $una_vez, $this->filter( $una_vez ) );
	}

	public function test_sin_serie_no_toca_la_miga(): void {
		$this->terms = array();

		self::assertSame( self::miga_de_hoy(), $this->filter( self::miga_de_hoy() ) );
	}

	public function test_serie_noindex_no_toca_la_miga(): void {
		// Forma real de Rank Math: lista ya deserializada.
		$this->robots = array( 'noindex', 'follow' );

		self::assertSame( self::miga_de_hoy(), $this->filter( self::miga_de_hoy() ) );
	}

	public function test_robots_ilegible_falla_cerrado_y_no_toca_la_miga(): void {
		$this->robots = 42;

		self::assertSame( self::miga_de_hoy(), $this->filter( self::miga_de_hoy() ) );
	}

	public function test_wp_error_de_get_term_link_no_toca_la_miga(): void {
		$this->term_link = new \WP_Error( 'invalid_term', 'x' );

		self::assertSame( self::miga_de_hoy(), $this->filter( self::miga_de_hoy() ) );
	}

	public function test_fuera_de_una_ficha_no_toca_la_miga(): void {
		// En la landing de la serie (`is_tax('pa_serie')`) la miga la arma el
		// tema; aquí no se debe tocar.
		$this->is_product = false;

		self::assertSame( self::miga_de_hoy(), $this->filter( self::miga_de_hoy() ) );
	}

	public function test_sin_el_peldano_de_la_ficha_no_adivina_la_posicion(): void {
		$crumbs = array_slice( self::miga_de_hoy(), 0, 3 );

		self::assertSame( $crumbs, $this->filter( $crumbs ) );
	}

	public function test_una_miga_que_no_es_array_se_devuelve_tal_cual(): void {
		self::assertSame( 'x', ( new PdpSeriesEnhancer() )->add_series_crumb( 'x' ) );
	}

	public function test_usa_el_primer_termino_como_el_nav(): void {
		$otra           = new WP_Term( 900, 'otra-serie', 'Otra serie' );
		$otra->taxonomy = 'pa_serie';
		$this->terms[]  = $otra;

		self::assertSame( 'The Climber', $this->filter( self::miga_de_hoy() )[3][0] );
	}

	public function test_registra_la_serie_en_la_miga_con_prioridad_30(): void {
		$module = new PdpSeriesEnhancer();

		// 30: después del filtro del tema (20), que sólo actúa en `is_tax`.
		Monkey\Filters\expectAdded( 'woocommerce_get_breadcrumb' )
			->once()
			->with( array( $module, 'add_series_crumb' ), 30 );

		$module->register();
		self::addToAssertionCount( 1 );
	}
}
