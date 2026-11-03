<?php
/**
 * Tests for Native Cart client-side safeguards.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NativeCartAssetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value ): string => strip_tags( $value )
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => trim( $value )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should remove the retired Xoo trigger without discarding valid selectors.
	 */
	public function test_sanitizes_retired_xoo_trigger(): void {
		self::assertSame(
			'.akb-cart-trigger, [data-open-cart]',
			Settings::sanitize_trigger_selector( '.akb-cart-trigger, .xoo-wsc-cart-trigger, [data-open-cart]' )
		);
		self::assertSame( '.akb-cart-trigger', Settings::sanitize_trigger_selector( '.xoo-wsc-cart-trigger' ) );
	}

	/**
	 * @testdox Should keep cart data in memory and protect live reads from stale responses.
	 */
	public function test_javascript_has_session_and_race_safeguards(): void {
		$javascript = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/native-cart.js' );

		self::assertStringNotContainsString( 'localStorage', $javascript );
		self::assertStringNotContainsString( 'sessionStorage', $javascript );
		self::assertStringNotContainsString( 'xoo-wsc', $javascript );
		self::assertStringContainsString( 'AbortController', $javascript );
		self::assertStringContainsString( 'requestId !== cartReadSequence', $javascript );
		self::assertStringContainsString( "endpoint('add_to_cart')", $javascript );
		self::assertStringContainsString( 'btn.disabled = true', $javascript );
		self::assertStringContainsString( 'btn.disabled = false', $javascript );
		self::assertStringContainsString( 'restoreFocusAfterRecommendation(recommendationIndex)', $javascript );
		self::assertStringContainsString( 'Math.min(previousIndex, nextButtons.length - 1)', $javascript );
		self::assertStringContainsString( 'renderEmptyState(settings)', $javascript );
		self::assertStringContainsString( "continueLink.className = 'akb-cart-empty__cta'", $javascript );
		self::assertStringContainsString( "retryButton.textContent = 'Reintentar'", $javascript );
		self::assertStringContainsString( 'renderCart(json.data, false)', $javascript );
		self::assertStringContainsString( 'renderCart(json.data, true)', $javascript );
		self::assertStringContainsString( 'if (announceMutation)', $javascript );
		self::assertStringContainsString( 'data.has_discount && data.discount_html && data.total_html', $javascript );
		self::assertStringContainsString( "discountRow.toggleAttribute('hidden', !showDiscountBreakdown)", $javascript );
		self::assertStringContainsString( "totalRow.toggleAttribute('hidden', !showDiscountBreakdown)", $javascript );
	}

	/**
	 * @testdox Should use a dynamic mobile viewport and honor reduced motion.
	 */
	public function test_styles_support_mobile_viewport_and_reduced_motion(): void {
		$stylesheet = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/native-cart-v2.css' );

		self::assertStringContainsString( 'height: 100dvh', $stylesheet );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $stylesheet );
		self::assertStringContainsString( 'env(safe-area-inset-bottom)', $stylesheet );
		self::assertStringContainsString( '.akb-cart-empty__cta', $stylesheet );
		self::assertStringContainsString( 'min-height: var(--touch-target-min, 44px)', $stylesheet );
		self::assertStringContainsString( '.akb-cart-totals__row', $stylesheet );
		self::assertStringContainsString( '.akb-cart-discount[hidden]', $stylesheet );
		self::assertStringContainsString( '.akb-cart-total[hidden]', $stylesheet );
	}

	/**
	 * @testdox Should pulse «Cargando…» only while the drawer is open.
	 *
	 * AI-2 (auditoría 2026-09-22). El nodo se imprime dentro del cajón cerrado en
	 * todas las páginas y una animación infinita corre aunque su caja esté oculta:
	 * ~60 recálculos de estilo por segundo sin que el cliente vea nada. La clase
	 * de abierto es `.akb-open` (native-cart.js): con `.is-open` el pulso no se
	 * vería nunca.
	 */
	public function test_loading_pulse_runs_only_with_the_drawer_open(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/native-cart-v2.css' );
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$js  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/native-cart.js' );

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $reglas, PREG_SET_ORDER );
		$con_pulso = array();
		foreach ( $reglas as $regla ) {
			if ( str_contains( $regla[2], 'akb-cart-loading-pulse' ) && ! str_contains( $regla[1], '@keyframes' ) ) {
				$con_pulso[] = trim( $regla[1] );
			}
		}

		self::assertSame( array( '.akb-cart-drawer.akb-open .akb-cart-loading' ), $con_pulso, 'El pulso sólo puede declararse con el cajón abierto.' );
		self::assertStringContainsString( "drawer.classList.add('akb-open')", $js, 'La clase de abierto que el CSS espera la pone native-cart.js.' );
	}

	/**
	 * @testdox Should expose WooCommerce-calculated discount and total without reimplementing bundle stacking.
	 */
	public function test_native_cart_payload_uses_calculated_woocommerce_totals(): void {
		$php = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );

		self::assertStringContainsString( 'WC()->cart->calculate_totals();', $php );
		self::assertStringContainsString( "WC()->cart->get_discount_total()", $php );
		self::assertStringContainsString( "WC()->cart->get_discount_tax()", $php );
		self::assertStringContainsString( "'discount_html'", $php );
		self::assertStringContainsString( "'total_html'", $php );
		self::assertStringContainsString( "WC()->cart->get_total()", $php );
		self::assertStringNotContainsString( "0.10", $php );
		self::assertStringNotContainsString( "10 / 100", $php );
	}
}
