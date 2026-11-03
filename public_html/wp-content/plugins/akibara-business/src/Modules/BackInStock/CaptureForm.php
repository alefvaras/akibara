<?php
/**
 * CaptureForm — formulario "Avísame cuando vuelva" en el PDP agotado.
 *
 * Render server-side SIN estado por-visitante (LiteSpeed cachea el PDP para
 * invitados): nada de nonces ni timestamps del server en el markup. El
 * endpoint se protege con honeypot y rate limit; un nonce embebido para
 * invitados nacería muerto en una página cacheada.
 *
 * Anchor público: #akb-bis-form — destino del CTA "Avísame cuando vuelva" de
 * las cards del loop, del builder de packs y de Mi colección. Los tres lo piden
 * por `CaptureForm::anchor()`, que aplica el filtro `akibara_bis_form_anchor`
 * UNA vez sobre el id real: dos de ellos escribían el literal '#akb-bis-form' a
 * mano, así que renombrar el form dejaba dos CTA apuntando a la nada.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BackInStock;

use Akibara\Business\Modules\Forms\Honeypot;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.53.0
 */
final class CaptureForm {

	/** Anchor/id público del form en el PDP. */
	public const FORM_ID = 'akb-bis-form';

	/**
	 * El ancla que deben usar todos los CTA «Avísame cuando vuelva».
	 *
	 * Dueño único del filtro: el id sale de `FORM_ID` (el mismo que imprime
	 * `render()`), así que renombrar el form mueve los tres CTA a la vez.
	 */
	public static function anchor(): string {
		/**
		 * Filtra el ancla del formulario de aviso de reposición.
		 *
		 * @since 1.53.0
		 * @param string $anchor Ancla con `#`, derivada de `CaptureForm::FORM_ID`.
		 */
		return (string) apply_filters( 'akibara_bis_form_anchor', '#' . self::FORM_ID );
	}

	/**
	 * @return void
	 */
	public function register(): void {
		// Prio 35: después del bloque de compra/meta del summary (~30) y antes
		// de sharing; visualmente donde vivía el form de YITH re-brandeado.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 35 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * ¿Corresponde mostrar el form en este request?
	 *
	 * @return bool
	 */
	private function should_render(): bool {
		if ( ! BackInStock::capture_enabled() || ! is_product() ) {
			return false;
		}

		$product = wc_get_product( get_the_ID() );

		// Estricto outofstock: la preventa (onbackorder) se COMPRA, no se espera.
		return $product && StockStatus::is_out_of_stock( $product );
	}

	/**
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$product_id = (int) get_the_ID();
		?>
		<section id="<?php echo esc_attr( self::FORM_ID ); ?>" class="akb-bis" data-akb-bis>
			<h2 class="akb-bis__title">Avísame cuando vuelva</h2>
			<p class="akb-bis__lede">Este tomo está agotado. Deja tu correo y te avisamos apenas vuelva a stock.</p>
			<form class="akb-bis__form" method="post" novalidate>
				<?php echo Honeypot::field_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup propio ya escapado. ?>
				<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product_id ); ?>">
				<label class="akb-bis__label" for="akb-bis-email">Tu correo</label>
				<div class="akb-bis__row">
					<input
						id="akb-bis-email"
						class="akb-bis__email"
						type="email"
						name="email"
						placeholder="tu@correo.cl"
						autocomplete="email"
						inputmode="email"
						maxlength="100"
						aria-describedby="akb-bis-message"
						required
					>
					<button type="submit" class="button akb-bis__submit" data-idle-label="Avisarme">Avisarme</button>
				</div>
			</form>
			<p id="akb-bis-message" class="akb-bis__msg" role="status" aria-live="polite" aria-atomic="true" hidden></p>
		</section>
		<?php
	}

	/**
	 * Assets solo donde el form existe (PDP agotado con captura activa).
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_render() ) {
			return;
		}

		$base = trailingslashit( AKIBARA_BUSINESS_URL );

		wp_enqueue_style(
			'akb-bis-form',
			$base . 'assets/css/bis-form.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/bis-form.css' )
		);

		wp_enqueue_script(
			'akb-bis-form',
			$base . 'assets/js/bis-form.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/bis-form.js' ),
			true
		);

		wp_localize_script(
			'akb-bis-form',
			'akbBis',
			array(
				'endpoint' => esc_url_raw( rest_url( 'akibara/v1/back-in-stock' ) ),
			)
		);
	}
}
