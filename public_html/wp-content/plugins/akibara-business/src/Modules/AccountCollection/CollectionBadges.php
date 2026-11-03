<?php
/**
 * CollectionBadges — hidratación global de posesión de tomos ("✓ En tu colección").
 *
 * Libera el reconocimiento de posesión de coleccionista fuera del silo de
 * SeriesBundles: muestra la insignia esmeralda en el loop del catálogo general,
 * resultados de búsqueda, páginas de editorial/categoría y en la ficha de
 * producto (PDP) sin importar si la serie tiene o no descuento por volumen.
 *
 * Resiliente a caché pública (LiteSpeed / Cloudflare): las páginas públicas
 * permanecen 100% cache-safe y sin datos de usuario en HTML; la posesión se
 * hidrata en el navegador del cliente autenticado con sessionStorage (0 ms)
 * y reconciliación reactiva ante cambios y búsquedas (`akb:search:swapped`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\AccountCollection;

use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Utilities\PluginAssetVersion;
use WC_Product;

defined( 'ABSPATH' ) || exit;

final class CollectionBadges {

	public const NONCE_ACTION = 'akb_collection_badges_nonce';
	public const AJAX_ACTION  = 'akb_get_my_collection_ids';

	public function __construct(
		private readonly CustomerCollection $customer_collection
	) {
	}

	/**
	 * Conecta los hooks del módulo.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_get_owned_ids' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_nopriv_get_owned_ids' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_pdp_placeholder' ), 6 );
		add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'render_pdp_shelve_button' ), 25 );
	}

	/**
	 * Encola estilos y script de hidratación universal para la colección.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'akb-collection-badges',
			AKIBARA_BUSINESS_URL . 'assets/css/collection-badges.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/collection-badges.css' )
		);

		wp_enqueue_script(
			'akb-collection-badges',
			AKIBARA_BUSINESS_URL . 'assets/js/collection-badges.js',
			array(),
			PluginAssetVersion::for_path( 'assets/js/collection-badges.js' ),
			true
		);
		wp_script_add_data( 'akb-collection-badges', 'strategy', 'defer' );

		$user_id           = (int) get_current_user_id();
		$is_logged         = $user_id > 0;
		$is_order_received = function_exists( 'is_order_received_page' ) && is_order_received_page();

		wp_localize_script(
			'akb-collection-badges',
			'akbCollectionBadges',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'action'          => self::AJAX_ACTION,
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'markAction'      => 'akb_collection_mark_owned',
				'markNonce'       => wp_create_nonce( 'akb_collection_manual' ),
				'isLogged'        => $is_logged,
				'userId'          => $user_id,
				'storageKey'      => 'akb_collection_owned_v1_' . $user_id,
				'isOrderReceived' => $is_order_received,
				'labels'          => array(
					'badge'        => '✓ En tu colección',
					'pdpNotice'    => 'Ya tienes este tomo en tu estantería.',
					'shelvePrompt' => '📚 ¿Ya tienes este tomo en casa?',
					'shelveAction' => 'Márcalo en tu estantería',
					'unmarkAction' => '✕ Quitar de mi estantería',
				),
			)
		);
	}

	/**
	 * Placeholder accesible en ficha de producto (PDP).
	 *
	 * Se inyecta tras el título de producto en WC core (prio 5) y antes del precio (prio 10).
	 * Oculto por defecto (`hidden`) para invitados; JS lo activa al detectar que el ID pertenece
	 * a la colección del cliente.
	 */
	public function render_pdp_placeholder(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo '<div class="akb-pdp-collection-banner" data-akb-pdp-collection-product="' . esc_attr( (string) $product->get_id() ) . '" hidden></div>';
	}

	/**
	 * Placeholder discreto debajo del formulario de compra para marcar en estantería personal.
	 *
	 * Se ubica tras el formulario de añadir al carrito (woocommerce_after_add_to_cart_form)
	 * para jamás competir visualmente con el botón principal de compra. Oculto por defecto
	 * (`hidden`) para invitados; JS lo revela solo si el cliente está autenticado y aún NO posee el tomo.
	 */
	public function render_pdp_shelve_button(): void {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		echo '<div class="akb-pdp-shelve-action" data-akb-pdp-shelve-product="' . esc_attr( (string) $product->get_id() ) . '" hidden></div>';
	}

	/**
	 * Endpoint AJAX privado que devuelve la lista consolidada de productos en colección
	 * (compras netas verificadas + estantería manual).
	 */
	public function ajax_get_owned_ids(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'message' => 'Tu sesión caducó. Reintentando…',
					'code'    => 'stale_nonce',
					'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				),
				403
			);
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_success( array( 'owned_ids' => array() ) );
			return;
		}

		$purchased  = $this->customer_collection->owned_product_ids_result( $user_id )['product_ids'] ?? array();
		$manual     = CollectionResolver::manual_owned_product_ids( $user_id );
		$all_owned  = array_values( array_unique( array_merge( $purchased, $manual ) ) );
		sort( $all_owned, SORT_NUMERIC );

		wp_send_json_success(
			array(
				'owned_ids' => $all_owned,
				'count'     => count( $all_owned ),
			)
		);
	}

	/**
	 * Endpoint público para visitantes no autenticados: devuelve lista vacía inmediata.
	 */
	public function ajax_nopriv_get_owned_ids(): void {
		wp_send_json_success( array( 'owned_ids' => array() ) );
	}
}
