<?php
/**
 * Button — corazón toggle en loop + single product.
 *
 * Loop: `woocommerce_after_shop_loop_item` prio 6 — FUERA del
 * <a class="woocommerce-LoopProduct-link"> (WC lo cierra en prio 5) y antes del
 * add-to-cart (prio 10). Antes vivía en before_shop_loop_item_title prio 11,
 * DENTRO del link: <button> anidado en <a> es HTML inválido y sin JS (o antes
 * de cargar el script defer) el click del corazón NAVEGABA al producto. El
 * anclaje visual sobre el cover lo da el shim.akb-wl-anchor (aspect-ratio 2:3
 * en sync con la caja del cover de loop.css — decisión de mesa).
 * Single: `woocommerce_single_product_summary` prio 35 (después de add-to-cart).
 * Clase `.akb-wl-toggle` para no colisionar con `.add_to_wishlist` del plugin
 * viejo durante sesión 1 de coexistencia.
 *
 * Estado inicial server-side: `aria-pressed="true|false"` según Storage::has().
 * El JS solo cambia el estado on-click, no hace fetch inicial.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class Button {

	public function register(): void {
		// Prioridad 8: Antes de los badges (prio 9) y la imagen (prio 10)
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'wrap_loop_start' ), 8 );
		// Prioridad 12: Cerramos el wrapper
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'wrap_loop_end' ), 12 );
		// Prio 6: después del cierre del <a> (prio 5) y antes del add-to-cart
		// (prio 10) — el botón queda hijo directo del li.product, nunca dentro
		// del link (ver docblock del archivo).
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_loop' ), 6 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_single' ), 35 );
	}

	public function render_loop(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// Shim de anclaje: caja absoluta que replica la geometría del cover
		// (inset 0 + aspect-ratio 2:3) con pointer-events:none; el botón dentro
		// re-habilita los suyos. Así el corazón queda visualmente sobre la
		// portada sin vivir dentro del <a>.
		echo '<div class="akb-wl-anchor">' . $this->html( $product->get_id(), 'loop' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() devuelve el botón con cada dato pasado por esc_attr() y esc_html(); escapar el marcado completo lo imprimiría como texto.
	}

	public function wrap_loop_start(): void {
		echo '<div class="akb-product-cover-wrap">';
	}

	public function wrap_loop_end(): void {
		echo '</div>';
	}

	public function render_single(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		echo $this->html( $product->get_id(), 'single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() devuelve el botón con cada dato pasado por esc_attr() y esc_html(); escapar el marcado completo lo imprimiría como texto.
	}

	private function html( int $product_id, string $context ): string {
		// Red de seguridad del encolado perezoso: si el corazón sale en una
		// superficie que `Wishlist::surface_renders_hearts()` no previó, la hoja
		// y el script se encolan aquí (WordPress los imprime en el footer). Sin
		// esto, un cover sin CSS deja `.akb-wl-anchor` sin `pointer-events:none`
		// y el shim se traga el click de TODA la portada.
		Wishlist::ensure_assets();

		$saved   = Storage::has( $product_id );
		$pressed = $saved ? 'true' : 'false';
		// Nombre en el label: N corazones idénticos por página no se distinguen
		// en el rotor del lector de pantalla. data-name alimenta al JS para
		// mantener el nombre al togglear (wishlist.js::ariaFor).
		$name  = wp_strip_all_tags( get_the_title( $product_id ) );
		$label = $saved
			? sprintf( 'Quitar «%s» de favoritos', $name )
			: sprintf( 'Guardar «%s» en favoritos', $name );

		/*
             * Para invitados, el corazón inicia un cambio de contexto, no un toggle.
             * wishlist.js conduce al acceso o abre GuestPrompt; no persiste el estado
             * de favoritos. El nombre accesible comunica esa acción sin aria-pressed.
             * El control se conserva como acceso al registro y redirect_to devuelve
             * a la página desde la que se inició la acción.
             */
		$logged = is_user_logged_in();
		// Con el aviso de GuestPrompt el toque abre un diálogo en la página:
		// `aria-haspopup` lo anuncia antes de pulsar ().
		$popup = '';
		if ( ! $logged ) {
			$label = sprintf( 'Inicia sesión para guardar «%s» en favoritos', $name );
			$popup = GuestPrompt::enabled() ? ' aria-haspopup="dialog"' : '';
		}

		$classes = sprintf( 'akb-wl-toggle akb-wl-toggle--%s', $context );
		if ( $saved ) {
			$classes .= ' is-saved';
		}

		ob_start();
		?>
		<button type="button" class="<?php echo esc_attr( $classes ); ?>" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>" data-name="<?php echo esc_attr( $name ); ?>"<?php echo $logged ? ' aria-pressed="' . esc_attr( $pressed ) . '"' : $popup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $popup es un literal fijo o vacío. ?> aria-label="<?php echo esc_attr( $label ); ?>">
			<svg class="akb-wl-toggle__icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true" focusable="false">
				<path stroke-linecap="round" stroke-linejoin="round" d="m12 21-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.18L12 21z"/>
			</svg>
			<?php if ( 'single' === $context ) : ?>
				<span class="akb-wl-toggle__label"><?php echo esc_html( $saved ? 'Guardado' : 'Guardar' ); ?></span>
			<?php endif; ?>
		</button>
		<?php
		return (string) ob_get_clean();
	}
}
