<?php
/**
 * Selector múltiple de tomos y barra de compra en landings de serie (`pa_serie`).
 *
 * Permite seleccionar varios tomos simultáneamente, calcula el subtotal de toda la edición y agrega en bloque con confirmación de servidor
 * (`akb_batch_add_to_cart`), abriendo el cajón de carrito nativo una única vez.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBatchAtc;

use Akibara\Business\Modules\AccountCollection\CollectionOwnership;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Services\VolumeTitle;
use Akibara\Business\Services\PurchaseIncentives;
use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress HookNotFound
 */
final class SeriesBatchAtc {

	public const NONCE_ACTION = 'akb_series_batch_nonce';

	/** Máximo de productos por petición de Batch ATC. */
	public const MAX_BATCH_ITEMS = 150;

	/** Umbral de tomos para activar el selector de tramo por rango (Propuesta C). */
	public const LARGE_SERIES_THRESHOLD = 20;

	public function register(): void {
		add_action( 'template_redirect', array( PurchaseIncentives::class, 'private_series_headers' ), 0, 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );

		// Endpoints AJAX para adición atómica de lote al carrito nativo
		add_action( 'wp_ajax_akb_batch_add_to_cart', array( $this, 'ajax_batch_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_akb_batch_add_to_cart', array( $this, 'ajax_batch_add_to_cart' ) );

		// Checkbox en cada card de producto en el loop de serie (prio 7: antes del badge y título)
		add_action( 'woocommerce_before_shop_loop_item_title', array( $this, 'render_card_checkbox' ), 7 );

		// Barra de selección rápida arriba de la rejilla (prio 12: tras el resumen de SeriesFacts)
		add_action( 'woocommerce_before_shop_loop', array( $this, 'render_quick_select_toolbar' ), 12 );

		// Barra flotante inferior de acción y FSP (prio 15: antes de la ficha completa)
		add_action( 'woocommerce_after_shop_loop', array( $this, 'render_action_bar' ), 15 );

		// En series multitomo, suprime el botón individual 'Añadir al carrito' para unificar la compra en la barra flotante
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'filter_loop_add_to_cart_link' ), 30, 2 );
	}

	public function is_active_series_landing(): bool {
		return $this->is_multi_volume_series();
	}

	public function is_multi_volume_series(): bool {
		if ( ! function_exists( 'is_tax' ) || ! is_tax( 'pa_serie' ) ) {
			return false;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}

		// Tomos únicos (one-shot): exactamente 1 tomo o término con 1 producto o menos.
		if ( (int) $term->count <= 1 ) {
			return false;
		}

		$tomos_edicion = get_term_meta( $term->term_id, PublicationStatus::META_EDITION_TOTAL, true );
		if ( '' !== $tomos_edicion && 1 === (int) $tomos_edicion ) {
			return false;
		}

		return true;
	}

	/**
	 * Determina si la serie actual es extensa (más de 20 tomos, ej. One Piece, Haikyuu, Berserk),
	 * habilitando la arquitectura del selector de tramo por rango numérico (Propuesta C).
	 *
	 * @param int $threshold Umbral de tomos para calificar como serie extensa.
	 * @return bool True si la serie supera el umbral.
	 */
	public function is_large_series( int $threshold = self::LARGE_SERIES_THRESHOLD ): bool {
		if ( ! $this->is_multi_volume_series() ) {
			return false;
		}

		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}

		if ( (int) $term->count > $threshold ) {
			return true;
		}

		$tomos_edicion = get_term_meta( $term->term_id, PublicationStatus::META_EDITION_TOTAL, true );
		if ( '' !== $tomos_edicion && (int) $tomos_edicion > $threshold ) {
			return true;
		}

		$tomos_obra = get_term_meta( $term->term_id, PublicationStatus::META_WORK_TOTAL, true );
		if ( '' !== $tomos_obra && (int) $tomos_obra > $threshold ) {
			return true;
		}

		return false;
	}

	public function add_body_class( array $classes ): array {
		if ( $this->is_multi_volume_series() ) {
			$classes[] = 'akb-has-batch-series';
		}
		if ( $this->is_large_series() ) {
			$classes[] = 'akb-has-large-series';
		}
		return $classes;
	}

	/**
	 * Suprime el botón individual 'Añadir al carrito' en series de 2 o más tomos para
	 * enfocar la compra en la barra flotante (Batch ATC / FSP). Conserva el botón
	 * de 'Avísame cuando vuelva' (.akb-notify-link) en tomos agotados y el botón
	 * individual en tomos únicos.
	 *
	 * @param string      $html    Markup del botón generado por WooCommerce / LoopEnhancer.
	 * @param \WC_Product $product Instancia del producto en el loop.
	 * @return string Markup filtrado.
	 */
	public function filter_loop_add_to_cart_link( string $html, \WC_Product $product ): string {
		if ( ! $this->is_multi_volume_series() ) {
			return $html;
		}

		return self::can_select_product( $product ) ? '' : $html;
	}

	public function enqueue_assets(): void {
		if ( ! $this->is_active_series_landing() ) {
			return;
		}

		$css_rel = 'assets/css/series-multiselect.css';
		$js_rel  = 'assets/js/series-multiselect.js';
		self::enqueue_client();

		wp_enqueue_style(
			'akb-series-multiselect',
			AKIBARA_BUSINESS_URL . $css_rel,
			array(),
			PluginAssetVersion::for_path( $css_rel )
		);

		wp_enqueue_script(
			'akb-series-multiselect',
			AKIBARA_BUSINESS_URL . $js_rel,
			array( 'akb-batch-cart', 'akb-edition-products', 'akb-purchase-incentives' ),
			PluginAssetVersion::for_path( $js_rel ),
			true
		);


		wp_localize_script(
			'akb-series-multiselect',
			'akbSeriesBatchConfig',
			array(
				'ajaxUrl'          => '/wp-admin/admin-ajax.php',
				'nonce'            => wp_create_nonce( self::NONCE_ACTION ),
				'editionProducts'  => $this->edition_products(),
				'purchase_incentives' => PurchaseIncentives::configuration( (int) get_current_user_id() ),
				'cart_state' => get_current_user_id() > 0 ? PurchaseIncentives::current_cart_state() : null,
				'i18n'             => array(
					'selected_singular'   => '1 tomo seleccionado',
					'selected_plural'     => '%s tomos seleccionados',
					'add_selection'       => 'Añadir selección (%s)',
					'adding'              => 'Añadiendo...',
				),
			)
		);
	}

	/** One purchase client shared by the series and personal collection interfaces. */
	public static function enqueue_client(): void {
		PurchaseIncentives::enqueue_client();
		$path = 'assets/js/batch-cart.js';
		wp_enqueue_script( 'akb-batch-cart', AKIBARA_BUSINESS_URL . $path, array(), PluginAssetVersion::for_path( $path ), true );
		$edition_path = 'assets/js/edition-products.js';
		wp_enqueue_script( 'akb-edition-products', AKIBARA_BUSINESS_URL . $edition_path, array(), PluginAssetVersion::for_path( $edition_path ), true );
	}

	/** Full edition, independent of the current WooCommerce loop page. */
	public function edition_products(): ?array {
		$term = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		return ( new EditionCatalog() )->for_term( (int) $term->term_id );
	}

	public function render_card_checkbox(): void {
		if ( ! $this->is_active_series_landing() ) {
			return;
		}

		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		if ( ! self::can_select_product( $product ) ) {
			return;
		}

		$price      = (float) wc_get_price_to_display( $product );
		$name       = $product->get_name();
		$product_id = $product->get_id();
		$vol        = VolumeTitle::extract_number( $name );
		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$is_owned   = $user_id > 0 && CollectionOwnership::owns_product( $user_id, $product_id );
		?>
		<div class="akb-series-card-select" data-akb-volume-card-select>
			<?php if ( $is_owned ) : ?>
				<span class="akb-series-card-select__owned" data-akb-owned-badge="1">✓ Comprado</span>
			<?php endif; ?>
			<label class="akb-series-card-select__label" title="<?php echo esc_attr( sprintf( 'Añadir %s al lote', $name ) ); ?>">
				<input
					type="checkbox"
					class="akb-series-card-select__input"
					data-akb-volume-checkbox
					data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
					data-price="<?php echo esc_attr( (string) $price ); ?>"
					data-volume-number="<?php echo null !== $vol ? esc_attr( (string) $vol ) : ''; ?>"
					data-owned="<?php echo $is_owned ? '1' : '0'; ?>"
					aria-label="<?php echo esc_attr( sprintf( 'Añadir %s al lote', $name ) ); ?>"
				>
				<span class="akb-series-card-select__custom" aria-hidden="true"></span>
			</label>
		</div>
		<?php
	}

	public function render_quick_select_toolbar(): void {
		if ( ! $this->is_active_series_landing() ) {
			return;
		}

		$is_large = $this->is_large_series();
		?>
		<div class="akb-series-select-toolbar" data-akb-series-select-toolbar>
			<div class="akb-series-select-toolbar__actions">
				<button type="button" class="akb-series-select-toolbar__btn" data-akb-select-all aria-pressed="false">
					<span class="akb-btn-check-icon" aria-hidden="true">✓</span> Seleccionar disponibles
				</button>
				<button type="button" class="akb-series-select-toolbar__btn akb-series-select-toolbar__btn--clear" data-akb-clear-selection hidden>
					Deseleccionar
				</button>
				<span class="akb-series-select-toolbar__status" data-akb-toolbar-status role="status" hidden></span>
			</div>
			<?php if ( $is_large ) : ?>
			<div class="akb-series-range-selector" data-akb-range-selector>
				<span class="akb-range-selector__label">Tramo:</span>
				<label class="akb-range-selector__field" for="akb-range-from">
					<span class="screen-reader-text">Desde el tomo</span>
					<input type="number" id="akb-range-from" class="akb-range-selector__input" data-akb-range-from min="1" step="1" placeholder="1" aria-label="Tomo inicial">
				</label>
				<span class="akb-range-selector__sep" aria-hidden="true">al</span>
				<label class="akb-range-selector__field" for="akb-range-to">
					<span class="screen-reader-text">Hasta el tomo</span>
					<input type="number" id="akb-range-to" class="akb-range-selector__input" data-akb-range-to min="1" step="1" placeholder="12" aria-label="Tomo final">
				</label>
				<button type="button" class="akb-series-select-toolbar__btn akb-series-select-toolbar__btn--range" data-akb-range-apply>
					Seleccionar tramo
				</button>
			</div>
			<?php endif; ?>
		</div>
		<div data-akb-series-purchase-preview aria-live="polite" hidden></div>
		<?php
	}

	public function render_action_bar(): void {
		if ( ! $this->is_active_series_landing() ) {
			return;
		}

		?>
		<div class="akb-series-action-bar" id="akb-series-action-bar" data-akb-series-action-bar hidden>
			<div class="akb-series-action-bar__inner">
				<div class="akb-series-action-bar__summary">
					<div class="akb-series-action-bar__info">
						<span class="akb-series-action-bar__count" data-akb-action-bar-count>0 tomos seleccionados</span>
						<span class="akb-series-action-bar__total" data-akb-action-bar-total>$0</span>

					</div>
					<button type="button" class="akb-series-action-bar__submit" data-akb-batch-submit>
						<span class="akb-submit-icon" aria-hidden="true">⚡</span>
						<span class="akb-submit-label" data-akb-submit-label>Añadir al carrito</span>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Endpoint AJAX para agregar múltiples productos al carrito en un lote con resultado individual por producto.
	 */
	public function ajax_batch_add_to_cart(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Sesión caducada.' ), 403 );
		}

		$raw_ids = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitizado en absint/array_map abajo.
		if ( is_string( $raw_ids ) ) {
			$raw_ids = explode( ',', $raw_ids );
		}

		$product_ids = is_array( $raw_ids ) ? array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) ) : array();
		if ( empty( $product_ids ) ) {
			wp_send_json_error( array( 'message' => 'No se indicaron productos válidos.' ), 400 );
		}

		if ( count( $product_ids ) > self::MAX_BATCH_ITEMS ) {
			wp_send_json_error( array( 'message' => 'Selecciona hasta 150 tomos por compra en lote.' ), 413 );
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => 'El carrito no está disponible.' ), 500 );
		}

		$result = $this->add_batch_products( $product_ids, WC()->cart );
		if ( array() === $result['added_ids'] && array() === $result['already_ids'] ) {
			wp_send_json_error( array( 'message' => 'Esos tomos ya no están disponibles. Conservamos tu selección.', 'failed_ids' => $result['failed_ids'] ), 409 );
		}

		WC()->cart->calculate_totals();

		$fragments = array();
		if ( function_exists( 'woocommerce_mini_cart' ) ) {
			ob_start();
			woocommerce_mini_cart();
			$mini_cart = ob_get_clean();
			$fragments['div.widget_shopping_cart_content'] = '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>';
		}
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', $fragments );

		$cart_hash = method_exists( WC()->cart, 'get_cart_hash' ) ? WC()->cart->get_cart_hash() : '';

		wp_send_json_success(
			array(
				'added_count' => count( $result['added_ids'] ),
				'added_ids' => $result['added_ids'],
				'already_ids' => $result['already_ids'],
				'failed_ids' => $result['failed_ids'],
				'cart_hash'   => $cart_hash,
				'fragments'   => $fragments,
			)
		);
	}

	/** Añade una copia por tomo conservando las cantidades presentes y la validación nativa. */
	private function add_batch_products( array $ids, \WC_Cart $cart ): array {
		$result = array( 'added_ids' => array(), 'already_ids' => array(), 'failed_ids' => array() );
		$present = array_column( $cart->get_cart(), 'product_id' );
		foreach ( $ids as $id ) {
			if ( in_array( $id, $present, true ) ) {
				$result['already_ids'][] = $id;
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product || ! self::can_select_product( $product ) || ! apply_filters( 'woocommerce_add_to_cart_validation', true, $id, 1 ) ) {
				$result['failed_ids'][] = $id;
				continue;
			}
			$key = $cart->add_to_cart( $id, 1 );
			$result[ $key ? 'added_ids' : 'failed_ids' ][] = $id;
		}
		return $result;
	}

	/** The same products use the selector, replace their CTA and pass batch validation. */
	public static function can_select_product( \WC_Product $product ): bool {
		return 'publish' === $product->get_status() && $product->is_type( 'simple' ) && $product->is_purchasable() && StockStatus::is_instock( $product );
	}

}
