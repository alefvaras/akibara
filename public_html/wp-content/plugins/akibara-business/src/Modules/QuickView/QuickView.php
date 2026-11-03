<?php
/**
 * Presenta una vista rápida desde las tarjetas del catálogo.
 *
 * El trigger se imprime después de que WooCommerce cierra el enlace de tarjeta,
 * entre favoritos y añadir al carrito, para mantener HTML válido. La sinopsis
 * viaja en JSON escapado data-akb-qv; portada, precio, estado y botón se clonan
 * del DOM de la tarjeta sin otro endpoint AJAX. El botón mantiene las clases
 * y atributos del original y usa el handler delegado de WooCommerce.
 *
 * Un dialog único proporciona foco contenido y cierre por Escape. Sin soporte
 * el trigger se omite y la tarjeta conserva la navegación a ficha. El CSS propio
 * usa tokens de Akibara y presenta bottom-sheet móvil y modal de escritorio.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\QuickView;

defined( 'ABSPATH' ) || exit;

use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\Text;
use WC_Product;

/**
 * Módulo autocontenido: trigger por card + assets. El sheet lo arma el JS.
 *
 * @since 1.97.3
 */
final class QuickView {

	/**
	 * Largo máximo de la sinopsis embebida en la card (caracteres).
	 */
	public const SYNOPSIS_MAX_CHARS = 280;

	/** Rótulo del botón; se edita en Akibara → Textos (D5b). */
	public const OPTION_LABEL = 'akb_copy_quick_view_label';

	public const DEFAULT_LABEL = 'Vista rápida';

	/**
	 * Desde qué ancho existe la vista rápida. En el teléfono el tema no pinta el
	 * botón (tapaba el número del tomo en portadas de ~158 px) y toda la tarjeta
	 * ya abre la ficha: la hoja y el JS sólo trabajan desde aquí.
	 */
	public const MEDIA = '(min-width: 769px)';

	/**
	 * El rótulo tal como se pinta: una línea, nunca vacío.
	 */
	public static function label(): string {
		return Text::one_line_option( self::OPTION_LABEL, self::DEFAULT_LABEL );
	}

	/**
	 * Registra hooks del módulo.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		// Prio 7: después del corazón wishlist (6), antes del add-to-cart (10).
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_trigger' ), 7 );
	}

	/**
	 * ¿Estamos en un contexto de listado donde el quick-view aporta?
	 *
	 * Solo archives de catálogo (/tienda/, taxonomías de producto y búsqueda de
	 * productos). La home queda fuera a propósito: sus secciones curadas son
	 * vitrina, no exploración, y el sheet compite con el hero.
	 */
	public function is_catalog_context(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}

		return is_shop()
			|| is_product_taxonomy()
			|| ( is_search() && 'product' === get_query_var( 'post_type' ) );
	}

	/**
	 * Encola CSS + JS solo en contextos de catálogo.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->is_catalog_context() ) {
			return;
		}

		// Con `media`: en el teléfono la hoja no bloquea el render (el navegador
		// la baja con prioridad baja y no la aplica) — D5b.
		wp_enqueue_style(
			'akb-quick-view',
			AKIBARA_BUSINESS_URL . 'assets/css/quick-view.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/quick-view.css' ),
			self::MEDIA
		);
		wp_enqueue_script(
			'akb-quick-view',
			AKIBARA_BUSINESS_URL . 'assets/js/quick-view.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/quick-view.js' ),
			true
		);
	}

	/**
	 * Trigger por card. Hijo directo del li.product (nunca dentro del <a>).
	 */
	public function render_trigger(): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! $this->is_catalog_context() ) {
			return;
		}

		$synopsis = $this->synopsis( $product );
		if ( '' === $synopsis ) {
			// Sin sinopsis el sheet no aporta nada sobre la card: no pintamos
			// el trigger (mejor ausencia honesta que un modal vacío).
			return;
		}

		$payload = array(
			'title'    => $product->get_name(),
			'synopsis' => $synopsis,
			'url'      => (string) get_permalink( $product->get_id() ),
		);

		// aria-label con el producto: 24 triggers "Vista rápida" idénticos por
		// página no se distinguen en el rotor. Empieza con el texto visible
		// (label-in-name, WCAG 2.5.3). El rótulo es del dueño: se concatena,
		// nunca pasa por sprintf. `data-akb-qv-label` lo lleva al rótulo que el
		// tema pinta al pasar el ratón (loop.css, `content: attr(…)`).
		$label = self::label();
		printf(
			'<button type="button" class="akb-qv-trigger" data-akb-qv="%s" data-akb-qv-label="%s" aria-haspopup="dialog" aria-label="%s" hidden>%s</button>',
			esc_attr( (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			esc_attr( $label ),
			esc_attr( $label . ': ' . $payload['title'] ),
			esc_html( $label )
		);
	}

	/**
	 * Sinopsis corta del producto: short_description, o excerpt del contenido.
	 *
	 * @param WC_Product $product Producto del loop.
	 */
	public function synopsis( WC_Product $product ): string {
		$raw = self::presentation_synopsis( wp_strip_all_tags( $product->get_short_description() ) );
		if ( '' === $raw ) {
			$raw = self::presentation_synopsis( wp_strip_all_tags( $product->get_description() ) );
		}
		if ( '' === $raw ) {
			return '';
		}

		if ( mb_strlen( $raw ) <= self::SYNOPSIS_MAX_CHARS ) {
			return $raw;
		}

		$cut   = mb_substr( $raw, 0, self::SYNOPSIS_MAX_CHARS );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t\n\r.,;:" ) . '…';
	}

	/**
	 * Conserva la información editorial y retira frases comerciales repetidas.
	 *
	 * La vista rápida ya muestra precio, disponibilidad y ETA en componentes
	 * propios. Repetir en la sinopsis frases como "Compra X en Akibara" o
	 * "Envío a todo Chile" hace que el texto parezca una plantilla SEO y desplaza
	 * el dato narrativo que ayuda a decidir. El contenido persistido no se modifica.
	 *
	 * @param string $raw Descripción sin HTML.
	 */
	private static function presentation_synopsis( string $raw ): string {
		$normalized = preg_replace( '/\s+/u', ' ', trim( $raw ) );
		if ( ! is_string( $normalized ) || '' === $normalized ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/u', $normalized );
		if ( ! is_array( $sentences ) ) {
			return $normalized;
		}

		$meaningful = array_filter(
			$sentences,
			static function ( string $sentence ): bool {
				$commercial_brand_or_logistics = preg_match( '/\bakibara\b/iu', $sentence );
				$commercial_logistics_context  = preg_match(
					'/\b(?:env[ií]os?|despacho|retiro|entrega)\b[^.!?]*\b(?:chile|tienda|pedido|domicilio|transportista|compra|gratis|regi[oó]n|punto\s+blue)\b|\bcobertura\s+del\s+transportista\b/iu',
					$sentence
				);
				$commercial_installments       = preg_match(
					'/\b(?:paga(?:r)?|pago|precio)\b[^.!?]{0,60}\bcuotas?\b|\bcuotas?\b[^.!?]{0,60}\b(?:sin\s+inter[eé]s|tarjeta|pago|precio)\b/iu',
					$sentence
				);
				$catalog_purchase_lead         = preg_match(
					'/^\s*(?:compra(?:r)?|adquiere|adquirir)\b[^.!?]*\b(?:tomos?|vol(?:umen)?\.?|mangas?|c[oó]mics?|edici[oó]n)\b/iu',
					$sentence
				);

				return 1 !== $commercial_brand_or_logistics
					&& 1 !== $commercial_logistics_context
					&& 1 !== $commercial_installments
					&& 1 !== $catalog_purchase_lead;
			}
		);

		return trim( implode( ' ', $meaningful ) );
	}
}
