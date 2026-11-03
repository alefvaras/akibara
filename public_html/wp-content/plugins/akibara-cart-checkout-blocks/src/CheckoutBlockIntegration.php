<?php
/**
 * Checkout inner-block registration.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Akibara's semantic Checkout Fields inner blocks.
 *
 * @since 2.1.0
 */
final class CheckoutBlockIntegration implements IntegrationInterface {

	private const NAME = 'akibara_checkout_inner_blocks';

	/**
	 * Bloques internos que aporta esta integración.
	 *
	 * @var list<string>
	 */
	private const BLOCKS = array(
		'akibara/checkout-preventa-summary',
		'akibara/checkout-trust-signals',
	);

	/**
	 * Hooks the integration into WooCommerce's official Checkout registry.
	 */
	public function register(): void {
		add_action( 'woocommerce_blocks_checkout_block_registration', array( $this, 'register_integration' ) );
		add_action( 'init', array( $this, 'register_block_types' ) );
	}

	/**
	 * Registra los bloques en el servidor.
	 *
	 * El registro PHP asegura que render_block() preserve el contenedor HTML con
	 * data-block-name necesario para la hidratación en runtime del cliente.
	 *
	 * @internal
	 * @return void
	 */
	public function register_block_types(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$registry = class_exists( '\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance() : null;

		foreach ( self::BLOCKS as $name ) {
			// `register_block_type()` avisa por `_doing_it_wrong` si el nombre ya
			// está tomado, y `init` puede correr más de una vez en pruebas y CLI.
			if ( null !== $registry && $registry->is_registered( $name ) ) {
				continue;
			}

			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'parent'          => array( 'woocommerce/checkout-fields-block' ),
					'render_callback' => static function () use ( $name ): string {
						return sprintf(
							'<div data-block-name="%1$s" class="wp-block-%2$s"></div>',
							esc_attr( $name ),
							esc_attr( str_replace( '/', '-', $name ) )
						);
					},
					'supports'        => array(
						'html'     => false,
						'multiple' => false,
						'reusable' => false,
					),
				)
			);
		}
	}

	/**
	 * Registers this integration once with the Checkout Block.
	 *
	 * @internal
	 *
	 * @param IntegrationRegistry $registry WooCommerce integration registry.
	 */
	public function register_integration( IntegrationRegistry $registry ): void {
		if ( ! $registry->is_registered( self::NAME ) ) {
			$registry->register( $this );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name(): string {
		return self::NAME;
	}

	/**
	 * {@inheritDoc}
	 */
	public function initialize(): void {
		$frontend_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-inner-blocks-frontend.js';
		wp_register_script(
			'akibara-checkout-inner-blocks-frontend',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-inner-blocks-frontend.js',
			array( 'wc-blocks-checkout', 'wp-element' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $frontend_path ) ? '.' . filemtime( $frontend_path ) : '' ),
			true
		);

		/*
		 * El aviso de colección viaja inlinado ANTES del bundle, como el
		 * `shippingHint` en `Assets::enqueue()`, y no por `get_script_data()`:
		 * ese canal lo imprime WooCommerce como settings de la integración y
		 * ningún JS de este plugin lo lee (ver docblock de `get_script_data()`).
		 * Tampoco va en el payload del Store API: el texto es una option del
		 * panel, no depende del carrito, y el bloque tiene que pintarlo aunque
		 * la extensión falte o cambie de esquema. `TrustSignals` lo lee en
		 * tiempo de render desde `window.akbCheckoutInnerBlocks`.
		 *
		 * La decisión de si el aviso es VERDAD para esta sesión se toma aquí,
		 * en el servidor, y no en el bundle: el maestro es una option y la
		 * baja es una usermeta, y ninguna de las dos viaja al navegador. Cuando
		 * no aplica se inlina la cadena vacía, que el bundle ya trata como «no
		 * pintar» (mismo contrato que el vacío guardado en el panel). El
		 * checkout nunca se sirve desde caché de página, así que el valor es
		 * el de la petición de ese cliente.
		 *
		 * Este método corre en `init` de cada petición (WooCommerce inicializa
		 * las integraciones al registrar el bloque), no sólo en el checkout:
		 * por eso sólo consulta option autocargada y la meta del usuario de la
		 * sesión, que WordPress ya tiene en caché al resolverlo.
		 */
		$applies = Assets::collection_notice_applies( (int) get_current_user_id() );

		wp_add_inline_script(
			'akibara-checkout-inner-blocks-frontend',
			'window.akbCheckoutInnerBlocks = ' . wp_json_encode(
				array(
					// El nombre del filtro va LITERAL a propósito, igual que
					// `akibara_blocks_shipping_hint_text`: un hook escrito como
					// constante deja de aparecer al hacer grep del nombre. El
					// filtro decide el TEXTO; no puede reencender un aviso que
					// el maestro o el cliente apagaron.
					'collectionNotice' => $applies
						? (string) apply_filters(
							'akibara_blocks_collection_notice_text',
							Assets::collection_notice_copy()
						)
						: '',
				)
			) . ';',
			'before'
		);

		$editor_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-inner-blocks-editor.js';
		wp_register_script(
			'akibara-checkout-inner-blocks-editor',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-inner-blocks-editor.js',
			array( 'wc-blocks-checkout', 'wp-block-editor', 'wp-blocks', 'wp-element', 'wp-i18n' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $editor_path ) ? '.' . filemtime( $editor_path ) : '' ),
			true
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 */
	public function get_script_handles(): array {
		return array( 'akibara-checkout-inner-blocks-frontend' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int,string>
	 */
	public function get_editor_script_handles(): array {
		return array( 'akibara-checkout-inner-blocks-editor' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Esta integración no publica datos de script, y el array vacío es
	 * deliberado: WooCommerce imprime lo que devuelva aquí como settings del
	 * bundle en CADA checkout de bloques, y emitía `namespace` + `schemaVersion`
	 * que ningún JS leía —los dos bundles del cliente comparan contra sus
	 * propios literales—. El contrato lo publica una sola vez el Store API
	 * (`StoreApiIntegration::NAMESPACE` y `::SCHEMA_VERSION`) dentro del propio
	 * payload del carrito, que es donde el cliente ya lo lee; que los literales
	 * del JS no se separen de esas constantes lo exige `PluginContractTest`.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data(): array {
		return array();
	}
}
