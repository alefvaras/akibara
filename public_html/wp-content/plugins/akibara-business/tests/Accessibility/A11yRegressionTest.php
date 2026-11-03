<?php
/**
 * Regresiones de accesibilidad de los arreglos de la auditoría 2026-07-28 (D3).
 *
 * Mismo estilo file-based que `LabelInNameTest`: afirma sobre el código fuente
 * que emite el HTML, sin bootstrap de WordPress. Cubre las piezas que un
 * refactor puede romper en silencio — un atributo ARIA borrado no rompe ningún
 * test funcional ni se ve en pantalla, pero deja el componente mudo o
 * inutilizable por teclado.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Accessibility;

use PHPUnit\Framework\TestCase;

final class A11yRegressionTest extends TestCase {

	private function source( string $relative ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative );
	}

	/**
	 * @testdox Should expose the PDP star rating as an image with a real name.
	 */
	public function test_pdp_star_rating_is_an_image_with_hidden_glyphs(): void {
		$pdp = $this->source( 'src/Modules/PdpEnhancer/PdpEnhancer.php' );

		// Sin role, el aria-label sobre un div genérico no se expone.
		self::assertStringContainsString( 'class="akb-pdp-rating__stars" role="img" aria-label=', $pdp );
		// Los glifos ★/☆ deben quedar ocultos: si no, se leen literalmente.
		self::assertStringContainsString( 'class="akb-pdp-rating__filled" aria-hidden="true"', $pdp );
		self::assertStringContainsString( 'class="akb-pdp-rating__empty" aria-hidden="true"', $pdp );
	}

	/**
	 * @testdox Should keep the stock badge still: its clipped pulse was never visible.
	 */
	public function test_stock_badge_does_not_animate(): void {
		// El CSS vive en el asset desde D11 (antes era <style> inline en el PHP).
		// Sin comentarios: la nota que explica la retirada nombra el pulso.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $this->source( 'assets/css/pdp-enhancer.css' ) );

		// El badge lleva clip-path, que recorta cualquier sombra hacia fuera: un
		// pulso de box-shadow no se ve y sólo cuesta fotogramas (revisión B5-R5).
		self::assertMatchesRegularExpression( '/\.akb-badge-panel--preventa \{[^}]*clip-path:/', $css );
		self::assertDoesNotMatchRegularExpression( '/\.akb-badge-panel(--preventa)? \{[^}]*animation/', $css );
		// DS-9: el panel verde del tomo disponible se retiró con su CSS.
		self::assertDoesNotMatchRegularExpression( '/\.akb-badge-panel[\s{:.]/', $css );
		self::assertStringNotContainsString( 'akb-pulse', $css );
	}

	/**
	 * @testdox Should name quick-view and wishlist controls per product.
	 */
	public function test_card_controls_carry_the_product_name(): void {
		$quick_view = $this->source( 'src/Modules/QuickView/QuickView.php' );
		$wishlist   = $this->source( 'src/Modules/Wishlist/Button.php' );

		// El rótulo es del panel (D5b): se concatena con el título, nunca sprintf.
		self::assertStringContainsString( "esc_attr( \$label . ': ' . \$payload['title'] )", $quick_view );
		self::assertStringContainsString( 'data-name="<?php echo esc_attr( $name ); ?>"', $wishlist );
		self::assertStringContainsString( "'Guardar «%s» en favoritos'", $wishlist );
	}

	/**
	 * @testdox Should trap quick-view focus, lock scroll and preserve a broken cover ratio.
	 */
	public function test_quick_view_contains_modal_and_broken_image_guards(): void {
		$js  = $this->source( 'assets/js/quick-view.js' );
		$css = $this->source( 'assets/css/quick-view.css' );

		self::assertStringContainsString( "dialog.setAttribute( 'aria-modal', 'true' )", $js );
		self::assertStringContainsString( "dialog.addEventListener( 'keydown', trapFocus )", $js );
		self::assertStringContainsString( 'lockPageScroll()', $js );
		self::assertStringContainsString( 'unlockPageScroll()', $js );
		self::assertStringContainsString( "cover.classList.add( 'akb-cover--error' )", $js );
		self::assertStringContainsString( "cover.classList.toggle( 'akb-cover--error', 0 === image.naturalWidth )", $js );
		self::assertStringContainsString( "'.akb-product-cover-wrap, .akb-qv__media'", $js );
		self::assertStringContainsString( "closest( '.akb-product-cover-wrap, .akb-qv__media' )", $js );
		self::assertStringContainsString( 'protectCovers( dialog )', $js );
		self::assertStringContainsString( 'aspect-ratio: 2 / 3;', $css );
		// La jaula de `.akb-product-cover-wrap` ya NO se afirma acá: la card del
		// catálogo es del TEMA, y su regla se mudó a `loop.css` cuando una portada
		// rota infló la portada a 1500px. Este archivo sólo puede responder por lo
		// que es suyo — el media del quick-view.
		self::assertStringContainsString( '.akb-qv__media.akb-cover--error::after', $css );
		self::assertStringContainsString( '.akb-qv-trigger[hidden]', $css );
	}

	/**
	 * @testdox Should not drop keyboard focus when toggling a wishlist button.
	 */
	public function test_wishlist_toggle_keeps_focus_and_announces(): void {
		$js = $this->source( 'assets/js/wishlist.js' );

		// `disabled` sobre el botón enfocado manda el foco a <body>.
		self::assertStringNotContainsString( 'btn.disabled = locked', $js );
		self::assertStringContainsString( "btn.setAttribute('aria-disabled', 'true')", $js );
		// El resultado del toggle se anuncia (aria-pressed solo no basta).
		self::assertStringContainsString( "liveRegion.setAttribute('aria-live', 'polite')", $js );
		// En favoritos el foco se reubica ANTES de sacar la card del DOM.
		self::assertStringContainsString( 'var hadFocus = card.contains(document.activeElement)', $js );
	}

	/**
	 * @testdox Should keep the cart drawer a proper modal dialog.
	 */
	public function test_cart_drawer_is_a_modal_dialog_with_focus_trap(): void {
		$cart = $this->source( 'src/Modules/NativeCart/NativeCart.php' );
		$js   = $this->source( 'assets/js/native-cart.js' );

		self::assertStringContainsString( 'role="dialog"', $cart );
		self::assertStringContainsString( 'aria-modal="true"', $cart );
		// Escape cierra y el foco vuelve al disparador; si WooCommerce lo cambió
		// por un fragment con el cajón abierto, al nodo que ocupa su puesto
		// (revisión REV-X1-2-D1). El comportamiento lo ejerce
		// tests/Modules/NativeCart/native-cart-accessible-triggers.test.js.
		self::assertStringContainsString( "if (event.key === 'Escape')", $js );
		self::assertStringContainsString( 'var returnTarget = focusReturnTarget();', $js );
		self::assertStringContainsString( 'return lastFocused;', $js );
		self::assertStringContainsString( 'trapFocus(event)', $js );
	}

	/**
	 * @testdox Should honour the system reduced-motion setting in the drawer.
	 */
	public function test_cart_drawer_animation_respects_system_reduced_motion(): void {
		$css = $this->source( 'assets/css/native-cart-v2.css' );

		self::assertMatchesRegularExpression(
			'/@media \(prefers-reduced-motion: reduce\)[^}]*\{[\s\S]*?\.akb-cart-drawer,\s*\n\s*\.akb-cart-overlay \{\s*\n\s*transition-duration: 0\.01ms;/',
			$css
		);
	}

	/**
	 * @testdox Should give a role to the containers that carry an ARIA name.
	 */
	public function test_named_containers_have_a_role(): void {
		$loop    = $this->source( 'src/Modules/LoopEnhancer/LoopEnhancer.php' );
		$builder = $this->source( 'templates/series-bundles/builder-widget.php' );

		self::assertStringContainsString( 'class="akb-badge-single" role="group" aria-label=', $loop );
		self::assertStringContainsString( 'role="region"', $builder );
	}

	/**
	 * @testdox Should land keyboard focus on the back-in-stock confirmation.
	 */
	public function test_back_in_stock_moves_focus_to_the_confirmation(): void {
		$js = $this->source( 'assets/js/bis-form.js' );

		self::assertMatchesRegularExpression(
			'/form\.hidden = true;\s*\n\s*msg\.setAttribute\( ?\'tabindex\', ?\'-1\' ?\);\s*\n\s*msg\.focus\(\);/',
			$js
		);
	}

	/**
	 * Cinco estrellas grises en cada card sin reseñas se leen como «valoración
	 * mala» (prueba social negativa): la decisión de la revisión de identidad
	 * 2026-06-27 es que el listado NO pinte ese placeholder. Hasta 1.116.33 eso
	 * se sostenía con un método enganchado a prioridad 6 cuyo único `echo`
	 * estaba detrás de un filtro con default `false` que nadie escuchaba —
	 * código que corría en cada tarjeta para no emitir nunca nada. Se retiró
	 * (regla del dueño: cero código muerto) y el candado pasa a afirmar la
	 * ausencia, que es lo que el cliente ve.
	 *
	 * @testdox Should not render an empty star rating placeholder in the loop.
	 */
	public function test_the_loop_never_renders_an_empty_star_rating_placeholder(): void {
		$loop = $this->source( 'src/Modules/LoopEnhancer/LoopEnhancer.php' );

		self::assertStringNotContainsString( 'Sin valoraciones aún', $loop );
		self::assertStringNotContainsString( 'akibara_loop_show_empty_rating', $loop );
		self::assertStringNotContainsString( 'render_empty_star_rating_loop', $loop );
	}
}
