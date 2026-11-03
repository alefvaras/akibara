<?php
/**
 * Las tarjetas de edición de la landing de una familia (/panini/).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\BrandFamilyLanding;
use Akibara\Business\Modules\BrandsDirectory\EditionFacts;
use Akibara\Business\Tests\Support\EditionsWpdb;
use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class BrandFamilyLandingTest extends TestCase {

	/** @var array<int, array<string, string>> */
	private array $meta = array();

	private EditionsWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();
		$this->meta      = array();
		$this->wpdb      = new EditionsWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		$ar                   = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		$ar->count            = 372;
		$ar->term_taxonomy_id = 2630;
		$es                   = new WP_Term( 208, 'panini-espana', 'Panini España' );
		$es->count            = 49;
		$es->term_taxonomy_id = 2080;
		$brands               = array( 'panini-argentina' => $ar, 'panini-espana' => $es );

		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => $value );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'shortcode_atts' )->alias( static fn( array $d, array $a ): array => array_merge( $d, $a ) );
		Functions\when( 'sanitize_key' )->alias( static fn( string $v ): string => strtolower( $v ) );
		Functions\when( 'get_term_by' )->alias( static fn( string $by, string $slug ) => $brands[ $slug ] ?? false );
		Functions\when( 'get_term_link' )->alias( static fn( WP_Term $t ): string => ( 'product_brand' === $t->taxonomy || isset( $brands[ $t->slug ] ) ? 'https://akibara.test/marca/' : 'https://akibara.test/serie/' ) . $t->slug . '/' );
		Functions\when( 'get_term' )->alias(
			static function ( int $id ) {
				$series = array( 11 => 'Fire Force', 12 => 'Golden Kamuy' );
				return isset( $series[ $id ] ) ? new WP_Term( $id, 'serie-' . $id, $series[ $id ] ) : null;
			}
		);
		Functions\when( 'get_term_meta' )->alias( fn( int $id, string $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_get_attachment_image_src' )->alias( static fn( int $id ): array => array( 'https://akibara.test/logo-' . $id . '.png', 200, 100 ) );
		// El helper del tema existe en la suite por contagio de otros tests: se
		// dobla con la misma respuesta que el fallback para que el resultado no
		// dependa del orden de carga.
		Functions\when( 'akibara_gp_brand_logo' )->alias( static fn( string $slug, int $thumb = 0 ): array => array( 'url' => 'https://akibara.test/logo-' . $thumb . '.png', 'w' => 200, 'h' => 100 ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		Functions\when( 'wc_price' )->alias( static fn( float $p ): string => '<span>$' . number_format( $p, 0, ',', '.' ) . '</span>' );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n, 0, ',', '.' ) );
		Functions\when( 'get_the_post_thumbnail_url' )->alias( static fn( int $id ): string => 'https://akibara.test/p/' . $id . '.jpg' );
		\WP_Query::$akb_resolver = static fn( array $args ): array => array( 700 + (int) $args['tax_query'][0]['terms'][0] );
		defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://akibara.test/wp-content/plugins/akibara-business/' );
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		foreach ( array( 'esc_html', 'esc_attr', 'esc_url' ) as $f ) {
			Functions\when( $f )->returnArg();
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'], $GLOBALS['wpdb'] );
		\WP_Query::$akb_resolver = null;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Una tarjeta por edición: logo, país, conteo, precio «desde», series con portada y botón. */
	public function test_cards_show_what_a_reader_needs_at_a_glance(): void {
		$this->meta[263]['thumbnail_id'] = '27079';
		$this->meta[208]['thumbnail_id'] = '27077';
		$this->wpdb->series[2630]        = array( 11 => 33, 12 => 31 );
		$this->wpdb->price[2630]         = 10990.0;
		$this->wpdb->price[2080]         = 14000.0;

		$html = ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertSame( 2, substr_count( $html, '<article class="akb-edition-card' ) );
		self::assertStringContainsString( 'akb-edition-card--argentina', $html );
		self::assertStringContainsString( 'akb-edition-card--espana', $html );
		self::assertStringContainsString( '<h2 class="akb-edition-card__title" id="akb-edition-panini-argentina">Panini Argentina</h2>', $html );
		self::assertStringContainsString( 'Edición Argentina', $html );
		self::assertStringContainsString( '372 productos en catálogo · Desde $10.990', $html );
		self::assertStringContainsString( '49 productos en catálogo · Desde $14.000', $html );
		self::assertStringContainsString( 'src="https://akibara.test/logo-27079.png" alt=""', $html, 'El H2 ya nombra la editorial: el logo es decorativo.' );
		self::assertStringContainsString( '<img src="https://akibara.test/p/711.jpg" alt="Fire Force"', $html, 'La portada del tomo más bajo de la serie DENTRO de la edición.' );
		self::assertStringContainsString( 'href="https://akibara.test/marca/panini-argentina/">Ver Panini Argentina', $html );
		self::assertStringContainsString( '<h2 id="akb-family-compare-title">¿Cuál edición elegir?</h2>', $html );
		self::assertStringNotContainsString( '<table', $html, 'Sin hechos escritos no hay tabla que comparar.' );
	}

	/** @testdox Los hechos escritos por el dueño salen en la tarjeta y en la tabla comparativa. */
	public function test_facts_feed_the_card_and_the_comparison(): void {
		$this->meta[263][ EditionFacts::META_IDIOMA ]  = 'Español rioplatense';
		$this->meta[208][ EditionFacts::META_IDIOMA ]  = 'Español de España';
		$this->meta[208][ EditionFacts::META_FORMATO ] = 'Tankōbon con sobrecubierta';

		$html = ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertStringContainsString( '<dt>Idioma</dt><dd>Español rioplatense</dd>', $html );
		self::assertStringContainsString( '<table class="akb-family__table">', $html );
		self::assertStringContainsString( '<th scope="row">Formato</th><td>—</td><td>Tankōbon con sobrecubierta</td>', $html, 'Lo que una edición no dice se marca, no se inventa.' );
	}

	/** @testdox Sin el helper del tema (o si no encuentra logo), la tarjeta cae a la imagen del término. */
	public function test_logo_falls_back_to_the_term_image(): void {
		Functions\when( 'akibara_gp_brand_logo' )->justReturn( array( 'url' => '', 'w' => 0, 'h' => 0 ) );
		$this->meta[263]['thumbnail_id'] = '27079';

		$html = ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertStringContainsString( 'src="https://akibara.test/logo-27079.png"', $html );
		self::assertSame( 1, substr_count( $html, 'akb-edition-card__logo' ), 'La edición sin imagen alguna no pinta placa vacía.' );
	}

	/** @testdox El shortcode con `key` en otra página también trae su hoja de estilo. */
	public function test_render_enqueues_its_style_anywhere(): void {
		$encolado = array();
		Functions\when( 'wp_enqueue_style' )->alias( static function ( string $handle ) use ( &$encolado ): void { $encolado[] = $handle; } );
		Functions\when( 'is_singular' )->justReturn( false );

		( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertContains( 'akibara-brand-family', $encolado );
	}

	/** @testdox Una familia desconocida o sin ediciones no imprime nada. */
	public function test_unknown_family_prints_nothing(): void {
		self::assertSame( '', ( new BrandFamilyLanding() )->render( array( 'key' => 'marvel' ) ) );
	}

	/** @testdox Sin `key`, el shortcode resuelve la landing de la página que se está viendo. */
	public function test_key_defaults_to_the_current_landing(): void {
		$page = new class() extends \WP_Post {
			public string $post_name = 'panini';
		};
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( $page );

		// Observamos la familia por las tarjetas que produce, no por un atributo:
		// `data-akb-family` se retiró en 1.116.34 (nadie lo leía, ni JS ni CSS ni
		// sondas) por la regla del dueño «cero código muerto».
		self::assertStringContainsString( 'id="akb-edition-panini-argentina"', ( new BrandFamilyLanding() )->render( array() ) );
	}

	/** @testdox El singular también se edita en Textos. */
	public function test_singular_count_is_editable(): void {
		$GLOBALS['akibara_test_options']['akb_copy_edition_count_one'] = 'Un único título';
		$es                   = new WP_Term( 208, 'panini-espana', 'Panini España' );
		$es->count            = 1;
		$es->term_taxonomy_id = 2080;
		Functions\when( 'get_term_by' )->alias( static fn( string $by, string $slug ) => 'panini-espana' === $slug ? $es : false );

		self::assertStringContainsString( 'Un único título', ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) ) );
	}

	/** @testdox Con un solo producto la tarjeta dice «1 producto en catálogo». */
	public function test_singular_count(): void {
		$es = new WP_Term( 208, 'panini-espana', 'Panini España' );
		$es->count = 1;
		$es->term_taxonomy_id = 2080;
		Functions\when( 'get_term_by' )->alias( static fn( string $by, string $slug ) => 'panini-espana' === $slug ? $es : false );
		self::assertStringContainsString( '1 producto en catálogo', ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) ) );
	}

	/** @testdox Los textos de la tarjeta se editan en Textos. */
	public function test_wording_is_editable(): void {
		$GLOBALS['akibara_test_options']['akb_copy_edition_cta']   = 'Entrar a %s';
		$GLOBALS['akibara_test_options']['akb_copy_edition_count'] = '%s títulos';

		$html = ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertStringContainsString( 'Entrar a Panini España', $html );
		self::assertStringContainsString( '49 títulos', $html );
	}

	/** @testdox Una serie sin portada se enlaza por su nombre, con la clase que la estila. */
	public function test_series_without_cover_falls_back_to_its_name(): void {
		$this->wpdb->series[2630] = array( 11 => 33 );
		\WP_Query::$akb_resolver  = static fn(): array => array();

		$html = ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) );

		self::assertStringContainsString( 'class="akb-edition-card__cover--text"', $html );
		self::assertStringContainsString( '>Fire Force</a>', $html, 'El nombre de la serie es el enlace.' );
		self::assertStringNotContainsString( '<img src="" ', $html, 'Nunca una imagen vacía.' );
	}

	/** @testdox Un texto sin marcador no pierde el dato: se le añade detrás. */
	public function test_wording_without_placeholder_appends_the_value(): void {
		$GLOBALS['akibara_test_options']['akb_copy_edition_cta'] = 'Entrar';

		self::assertStringContainsString( 'Entrar Panini España', ( new BrandFamilyLanding() )->render( array( 'key' => 'panini' ) ) );
	}

	/** @testdox Las clases de las tarjetas (también la variante sin portada) están en la allowlist de UCSS y colgadas del filtro de LiteSpeed. */
	public function test_ucss_allowlist_covers_the_cards(): void {
		Functions\when( 'add_shortcode' )->justReturn( null );
		$landing = new BrandFamilyLanding();
		$landing->register();
		$selectors = $landing->allowlist_ucss_selectors( array( '.ajeno' ) );

		self::assertContains( '.ajeno', $selectors );
		foreach ( array( '.akb-edition-card', '.akb-edition-card__covers', '.akb-edition-card__cover--text', '.akb-edition-card__cta', '.akb-family__table' ) as $sel ) {
			self::assertContains( $sel, $selectors, $sel );
		}
		self::assertSame( 20, Filters\has( 'litespeed_ucss_whitelist', array( $landing, 'allowlist_ucss_selectors' ) ) );
	}
}
