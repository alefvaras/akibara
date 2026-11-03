<?php
/**
 * Los dos navegadores del panel se derivan de UNA sola lista.
 *
 * El panel pinta la navegación dos veces: un `<select>` para viewports
 * angostos y una lista de enlaces para escritorio. Cada uno derivaba de un
 * array distinto —el de escritorio recorría sólo los grupos declarados—, así
 * que registrar una sección con el grupo mal escrito la borraba del nav de
 * escritorio y la dejaba viva en el selector móvil: media pantalla
 * inalcanzable con el ratón, sin ningún error.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AdminPanel;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PanelNavigationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://akibara.cl/wp-admin/admin.php' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'woocommerce_admin_fields' )->justReturn( null );
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( AdminPanel $panel ): string {
		ob_start();
		$panel->render_page();

		return (string) ob_get_clean();
	}

	/**
	 * @return array{select: string, nav: string}
	 */
	private function split_navs( string $html ): array {
		$select = '';
		$nav    = '';

		if ( 1 === preg_match( '/<select[^>]*akb-panel-section-select.*?<\/select>/s', $html, $m ) ) {
			$select = $m[0];
		}
		if ( 1 === preg_match( '/<nav class="akb-panel-nav".*?<\/nav>/s', $html, $m ) ) {
			$nav = $m[0];
		}

		return array(
			'select' => $select,
			'nav'    => $nav,
		);
	}

	public function test_a_section_with_an_unknown_group_still_reaches_both_navs(): void {
		$panel = new AdminPanel();
		$panel->add_section( 'anuncios', 'Anuncios', static fn(): array => array(), null, false, '', 'sitio' );
		// El tipeo del defecto original: «sistemas» en vez de «sistema».
		$panel->add_section( 'diagnostico', 'Diagnóstico', static fn(): array => array(), null, false, '', 'sistemas' );

		$navs = $this->split_navs( $this->render( $panel ) );

		self::assertStringContainsString( 'tab=diagnostico', $navs['select'] );
		self::assertStringContainsString(
			'tab=diagnostico',
			$navs['nav'],
			'Un grupo mal escrito no puede hacer desaparecer la sección del nav de escritorio.'
		);
	}

	public function test_both_navs_list_exactly_the_same_sections_in_the_same_order(): void {
		$panel = new AdminPanel();
		$panel->add_section( 'sistema-uno', 'Sistema uno', static fn(): array => array(), null, false, '', 'sistema' );
		$panel->add_section( 'venta-uno', 'Venta uno', static fn(): array => array(), null, false, '', 'venta' );
		$panel->add_section( 'suelta', 'Suelta', static fn(): array => array() );
		$panel->add_section( 'sitio-uno', 'Sitio uno', static fn(): array => array(), null, false, '', 'sitio' );

		$navs = $this->split_navs( $this->render( $panel ) );

		preg_match_all( '/tab=([a-z-]+)/', $navs['select'], $en_select );
		preg_match_all( '/tab=([a-z-]+)/', $navs['nav'], $en_nav );

		self::assertSame(
			array( 'suelta', 'venta-uno', 'sitio-uno', 'sistema-uno' ),
			$en_select[1],
			'El orden declarado de grupos manda: sin grupo, Venta, Sitio, Sistema.'
		);
		self::assertSame( $en_select[1], $en_nav[1], 'Los dos navegadores salen de la misma lista.' );
	}

	public function test_the_first_visible_section_is_the_first_in_nav_order(): void {
		$panel = new AdminPanel();
		$panel->add_section( 'sistema-uno', 'Sistema uno', static fn(): array => array(), null, false, '', 'sistema' );
		$panel->add_section( 'venta-uno', 'Venta uno', static fn(): array => array(), null, false, '', 'venta' );

		$html = $this->render( $panel );

		self::assertStringContainsString(
			'name="akibara_current_tab" value="venta-uno"',
			$html,
			'Sin `?tab=`, el fallback es la primera del nav, no la primera registrada.'
		);
	}

	public function test_a_failed_validation_says_that_nothing_was_saved(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'El plazo mínimo no puede superar al máximo.' ) );

		$_GET  = array( 'saved' => '1' );
		$panel = new AdminPanel();
		$panel->add_section( 'preventa', 'A pedido', static fn(): array => array() );

		$html = $this->render( $panel );

		// `handle_save()` redirige ANTES de tocar `woocommerce_update_options()`
		// en cuanto hay errores: no se guardó nada de la pestaña.
		self::assertStringContainsString( 'No se guardó ningún ajuste de esta pestaña', $html );
		self::assertStringNotContainsString(
			'Algunos ajustes no se guardaron',
			$html,
			'«Algunos» invita a creer que el resto sí quedó escrito.'
		);
	}
}
