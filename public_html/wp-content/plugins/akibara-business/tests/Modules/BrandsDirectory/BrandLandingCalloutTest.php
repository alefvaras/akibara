<?php
/**
 * El conmutador de ediciones en /marca/<edición>/.
 *
 * Desde Panini Argentina la acción principal es Panini España (y viceversa),
 * con conteo; la landing de la familia queda como enlace «Comparar». El
 * aviso anterior mandaba a la landing, cuyo primer botón devolvía al mismo
 * archivo: dos clics para lo que es uno.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\BrandsDirectory;

use Akibara\Business\Modules\BrandsDirectory\BrandLandingCallout;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;

final class BrandLandingCalloutTest extends TestCase {

	/** @var array<string, WP_Term> */
	private array $terms = array();

	private bool $landing_published = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_GET = array();
		$GLOBALS['akibara_test_options'] = array();

		$ar        = new WP_Term( 263, 'panini-argentina', 'Panini Argentina' );
		$ar->count = 372;
		$es        = new WP_Term( 208, 'panini-espana', 'Panini España' );
		$es->count = 49;
		$this->terms = array( 'panini-argentina' => $ar, 'panini-espana' => $es );
		$this->landing_published = true;

		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value, ...$args ) => $value );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->alias( fn() => $this->terms['panini-argentina'] );
		Functions\when( 'get_term_by' )->alias( fn( string $by, string $slug ) => $this->terms[ $slug ] ?? false );
		Functions\when( 'get_term_link' )->alias( static fn( WP_Term $t ): string => 'https://akibara.test/marca/' . $t->slug . '/' );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'get_page_by_path' )->alias(
			function () {
				$page              = new WP_Post();
				$page->ID          = 27450;
				$page->post_status = $this->landing_published ? 'publish' : 'draft';
				return $page;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.test/panini/' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $t ): string => strip_tags( $t ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n, 0, ',', '.' ) );
	}

	protected function tearDown(): void {
		$_GET = array();
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function html(): string {
		ob_start();
		( new BrandLandingCallout() )->handle_render();
		return (string) ob_get_clean();
	}

	/** @testdox Desde Panini Argentina, el conmutador ofrece España con su conteo y marca la actual. */
	public function test_switch_offers_the_sibling_edition_with_counts(): void {
		$html = $this->html();

		self::assertStringContainsString( '<nav class="akb-edition-switch"', $html );
		self::assertStringContainsString( 'Ediciones de <strong>Panini</strong>', $html );
		self::assertMatchesRegularExpression( '#href="https://akibara.test/marca/panini-argentina/" aria-current="page"#', $html, 'La edición actual va marcada.' );
		self::assertMatchesRegularExpression( '#href="https://akibara.test/marca/panini-espana/">#', $html );
		self::assertStringContainsString( '>AR<', $html );
		self::assertStringContainsString( '>ES<', $html );
		self::assertStringContainsString( '>49<span class="screen-reader-text"> productos</span>', $html, 'El conteo deja decidir sin navegar.' );
		self::assertStringContainsString( '>372<span', $html );
		self::assertStringContainsString( 'href="https://akibara.test/panini/">Comparar las dos ediciones', $html, 'La landing sigue enlazada (SEO), pero como acción terciaria.' );
		self::assertStringNotContainsString( 'Estás viendo', $html, 'El hero ya dice dónde estás.' );
	}

	/** @testdox Sin landing publicada, los chips siguen y desaparece sólo «Comparar». */
	public function test_without_a_published_landing_only_the_compare_link_goes(): void {
		$this->landing_published = false;
		$html                    = $this->html();

		self::assertStringContainsString( 'panini-espana', $html );
		self::assertStringNotContainsString( 'Comparar', $html );
	}

	/** @testdox Con una sola edición resoluble no hay a dónde cambiar: nada. */
	public function test_a_single_edition_prints_nothing(): void {
		unset( $this->terms['panini-espana'] );
		self::assertSame( '', $this->html() );
	}

	/** @testdox Con filtros en la URL no se imprime (una variante de caché por filtro sería un despilfarro). */
	public function test_filters_hide_the_switch(): void {
		$_GET = array( 'filter_stock' => 'instock' );
		self::assertSame( '', $this->html() );
	}

	/** @testdox Los parámetros de campaña (utm, fbclid) NO lo esconden: quien llega de un anuncio también cambia de edición. */
	public function test_campaign_params_do_not_hide_the_switch(): void {
		$_GET = array( 'utm_source' => 'meta', 'utm_campaign' => 'panini', 'fbclid' => 'abc' );
		self::assertStringContainsString( 'akb-edition-switch', $this->html() );
	}

	/** @testdox Con un solo producto el lector de pantalla oye «1 producto». */
	public function test_singular_for_one_product(): void {
		$this->terms['panini-espana']->count = 1;
		self::assertStringContainsString( '>1<span class="screen-reader-text"> producto</span>', $this->html() );
	}

	/** @testdox Los textos del conmutador se editan en Textos. */
	public function test_wording_is_editable(): void {
		$GLOBALS['akibara_test_options']['akb_copy_editions_label']   = 'Elige la edición de %s';
		$GLOBALS['akibara_test_options']['akb_copy_editions_compare'] = 'Ver la comparación';
		$html = $this->html();

		self::assertStringContainsString( 'Elige la edición de <strong>Panini</strong>', $html );
		self::assertStringContainsString( 'Ver la comparación', $html );
	}

	/** @testdox Fuera del archivo de una edición con familia no se encola ni pinta nada. */
	public function test_other_brands_are_left_alone(): void {
		$otra = new WP_Term( 999, 'norma-editorial', 'Norma' );
		Functions\when( 'get_queried_object' )->justReturn( $otra );
		$encolado = false;
		Functions\when( 'wp_enqueue_style' )->alias( static function () use ( &$encolado ): void { $encolado = true; } );

		( new BrandLandingCallout() )->handle_enqueue_assets();

		self::assertSame( '', $this->html() );
		self::assertFalse( $encolado );
	}

	/** @testdox La flecha marca la edición a la que se va, nunca la actual, y su clase entra en la allowlist de UCSS. */
	public function test_only_the_sister_edition_gets_the_arrow(): void {
		$html = $this->html();

		self::assertSame( 1, substr_count( $html, 'akb-edition-switch__go' ), 'Una sola flecha: la de la hermana.' );
		$actual = (string) preg_replace( '#.*aria-current="page">#s', '', substr( $html, 0, (int) strpos( $html, '</li>' ) + 5 ) );
		self::assertStringNotContainsString( 'akb-edition-switch__go', $actual, 'Donde ya estás no hay flecha.' );

		$callout   = new BrandLandingCallout();
		$selectors = $callout->allowlist_ucss_selectors( array( '.ajeno' ) );
		self::assertContains( '.ajeno', $selectors );
		foreach ( array( '.akb-edition-switch__item', '.akb-edition-switch__go', '.akb-edition-switch__badge' ) as $sel ) {
			self::assertContains( $sel, $selectors, $sel );
		}
	}
}