<?php
/**
 * Cart and Checkout Blocks runtime assets.
 *
 * @package Akibara\CartCheckoutBlocks
 */

declare( strict_types = 1 );

namespace Akibara\CartCheckoutBlocks;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the single Blocks presentation bundle and checkout helpers.
 *
 * @since 2.0.0
 */
final class Assets {

	/**
	 * Nombre COMPARTIDO de la option y del filtro del aviso de despacho.
	 *
	 * El campo se edita en el panel de `akibara-business` (Akibara → Carrito,
	 * junto a los demás copys del carrito): este plugin no tiene pantalla propia
	 * y montar una para un solo texto le habría dado al dueño dos lugares donde
	 * buscar el mismo carrito. Se lee por `get_option()` y no por una clase del
	 * otro plugin porque el carrito no puede quedarse mudo si aquél no está
	 * activo; el nombre es el contrato.
	 *
	 * @since 2.2.12
	 */
	private const SHIPPING_HINT_OPTION = 'akibara_blocks_shipping_hint_text';

	/**
	 * El literal que este aviso tenía escrito antes de ser configurable. Sigue
	 * siendo el default final: sin option guardada, el carrito dice lo mismo de
	 * siempre.
	 *
	 * @since 2.2.12
	 */
	private const SHIPPING_HINT_DEFAULT = '¿Prefieres despacho a domicilio? Se calcula en el siguiente paso al ingresar tu comuna.';

	/**
	 * Nombre COMPARTIDO de la option y del filtro del aviso de colección del
	 * checkout: la frase que le dice al cliente, encima del botón de pagar, que
	 * comprar activa los avisos «salió el siguiente tomo» y dónde apagarlos.
	 *
	 * Mismo contrato que `SHIPPING_HINT_OPTION`, y por las mismas razones: el
	 * campo vive en Akibara → Carrito (akibara-business), este plugin sólo lo
	 * lee por `get_option()` y el nombre es el contrato entre los dos.
	 *
	 * Sale en la columna de campos (bloque `akibara/checkout-trust-signals`) y
	 * no en el resumen del pedido, porque ahí es donde el cliente ya está leyendo
	 * los términos y la casilla de ofertas: el aviso explica que son dos cosas
	 * distintas justo donde se decide la segunda.
	 *
	 * @since 2.2.17
	 */
	private const COLLECTION_NOTICE_OPTION = 'akibara_blocks_collection_notice_text';

	/**
	 * Copy del dueño, literal (nunca sprintf): es el default cuando la option no
	 * existe todavía, para que el aviso salga desde el primer despliegue sin
	 * pasar por el panel.
	 *
	 * @since 2.2.17
	 */
	private const COLLECTION_NOTICE_DEFAULT = 'Con tu cuenta Akibara te avisamos por correo cuando salga el siguiente tomo de las series que compras. No es publicidad: lo apagas cuando quieras en Mi cuenta, pestaña Colección.';

	/**
	 * Interruptor maestro de los avisos de tomo nuevo (Akibara → Venta →
	 * «Sigue esta serie» → «Enviar avisos de tomo nuevo»). Es
	 * `SeriesFollow::OPTION_ENABLED` de akibara-business, copiado por NOMBRE y
	 * no importado: `src/` de este plugin no puede depender de los módulos de
	 * Business (lo exige el gate `docker-checkout.sh`), y el nombre de la
	 * option es el contrato, igual que con los dos copys de arriba.
	 *
	 * Sin esto el aviso prometía correos con el maestro apagado —que es el
	 * estado de fábrica del módulo—: la única condición para pintarlo era que
	 * el texto no estuviera vacío, y el dueño tenía que acordarse de vaciar a
	 * mano un segundo campo en otra sección del panel.
	 *
	 * @since 2.2.17
	 */
	private const SERIES_FOLLOW_ENABLED_OPTION = 'akb_sf_enabled';

	/**
	 * Usermeta de la baja global («Apagar los avisos» en Mi cuenta → Colección
	 * y el List-Unsubscribe del correo escriben '1'). Es
	 * `FollowRegistry::META_OPTOUT_ALL` de akibara-business, copiada por nombre
	 * por la misma razón que la option de arriba.
	 *
	 * Para quien la tiene, el aviso es falso: el digest lo descarta antes de
	 * enviar nada, y Mi cuenta le acaba de decir «Apagados». Una superficie de
	 * consentimiento no puede afirmar lo contrario de lo que el sitio tiene
	 * registrado sobre ese cliente.
	 *
	 * @since 2.2.17
	 */
	private const SERIES_FOLLOW_OPTOUT_ALL_META = '_akb_sf_optout_all';

	private BlocksPage $blocks_page;

	private AddressIntegration $address_integration;

	private ?ComunaDirectory $comunas;

	private bool $enqueued = false;

	/**
	 * Initializes the asset adapter.
	 *
	 * @param BlocksPage           $blocks_page         Blocks page resolver.
	 * @param AddressIntegration   $address_integration Google Places adapter.
	 * @param ComunaDirectory|null $comunas             Commune catalog, null disables the combobox.
	 *
	 * @since 2.0.0
	 */
	public function __construct(
		BlocksPage $blocks_page,
		AddressIntegration $address_integration,
		?ComunaDirectory $comunas = null
	) {
		$this->blocks_page         = $blocks_page;
		$this->address_integration = $address_integration;
		$this->comunas             = $comunas;
	}

	/**
	 * Registers Cart and Checkout Block enqueue hooks.
	 *
	 * @since 2.0.0
	 */
	public function register(): void {
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues all first-party Blocks assets once.
	 *
	 * @internal
	 */
	public function enqueue(): void {
		if ( $this->enqueued ) {
			return;
		}

		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/cart-checkout-blocks.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/cart-checkout-blocks.js',
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data', 'wp-element', 'wp-plugins' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);

			wp_add_inline_script(
				'akibara-cart-checkout-blocks',
				'window.akbCartCheckoutBlocks = ' . wp_json_encode(
					array(
						'namespace'    => StoreApiIntegration::NAMESPACE,
						/**
						 * Microcopy del carrito cuando la dirección actual solo alcanza
						 * retiro: sin dirección, WooCommerce ofrece únicamente los
						 * local_pickup y un cliente de regiones puede leer «no hay
						 * despacho» y abandonar. La condición vive en el JS sobre
						 * cart.shippingRates (dato nativo del Store API): al ingresar
						 * una dirección con cobertura, las tarifas cambian y el aviso
						 * desaparece en el mismo render. Cadena vacía = apagado.
						 */
						// El nombre del filtro va LITERAL a propósito, aunque coincida
						// con `SHIPPING_HINT_OPTION`: un hook escrito como constante
						// deja de aparecer al hacer grep del nombre, que es como se
						// busca un punto de extensión.
						'shippingHint' => (string) apply_filters(
							'akibara_blocks_shipping_hint_text',
							self::shipping_hint_copy()
						),
					)
				) . ';',
			'before'
		);

		$style_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/css/cart-checkout-blocks.css';
		wp_enqueue_style(
			'akibara-cart-checkout-blocks',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/css/cart-checkout-blocks.css',
			array(),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $style_path ) ? '.' . filemtime( $style_path ) : '' )
		);

		if ( $this->blocks_page->is_checkout() ) {
			$this->enqueue_checkout_helpers();
			$this->address_integration->enqueue();
		}

		$this->enqueued = true;
	}

	/**
	 * Texto del aviso configurado, o el literal de siempre si nunca se guardó.
	 *
	 * Una cadena vacía GUARDADA es una decisión: apaga el aviso. Por eso el
	 * default sólo entra cuando la fila no existe, y no cuando existe vacía —
	 * reponerlo ahí le habría quitado al dueño el único interruptor que tiene.
	 *
	 * @since 2.2.12
	 */
	public static function shipping_hint_copy(): string {
		$copy = get_option( self::SHIPPING_HINT_OPTION, self::SHIPPING_HINT_DEFAULT );

		return is_string( $copy ) ? trim( $copy ) : self::SHIPPING_HINT_DEFAULT;
	}

	/**
	 * Texto del aviso de colección configurado, o el copy del dueño si nunca se
	 * guardó.
	 *
	 * Misma regla que `shipping_hint_copy()`: una cadena vacía GUARDADA apaga el
	 * aviso; el default sólo entra cuando la fila no existe. El campo del panel
	 * lo dice en su descripción («Déjalo vacío para apagarlo»), así que reponer
	 * el default sobre un vacío haría mentir al panel.
	 *
	 * @since 2.2.17
	 */
	public static function collection_notice_copy(): string {
		$copy = get_option( self::COLLECTION_NOTICE_OPTION, self::COLLECTION_NOTICE_DEFAULT );

		return is_string( $copy ) ? trim( $copy ) : self::COLLECTION_NOTICE_DEFAULT;
	}

	/**
	 * ¿Es verdad el aviso de colección para quien está en el checkout?
	 *
	 * El texto dice «te avisamos por correo cuando salga el siguiente tomo».
	 * Eso sólo ocurre si (1) el interruptor maestro de los avisos está
	 * encendido y (2) el cliente no apagó los avisos de su colección. Con
	 * cualquiera de las dos en contra, el aviso se calla: mejor no decir nada
	 * que prometer un correo que no va a salir, encima del botón de pagar.
	 *
	 * El maestro se lee con el MISMO conjunto de valores que acepta
	 * `SeriesFollow::option_is_enabled()` (formato histórico 1/on y formato
	 * WC yes/no), y su default es apagado, como allá: sin la fila, no hay
	 * avisos y no hay aviso.
	 *
	 * El invitado (`$user_id` 0) sí lo lee: el copy empieza por «Con tu cuenta
	 * Akibara», es decir, describe lo que pasa si compra con cuenta, que es la
	 * decisión que está tomando en ese formulario. No tiene usermeta que
	 * consultar, así que sólo lo gobierna el maestro.
	 *
	 * @param int $user_id Cliente de la sesión (0 = invitado).
	 * @return bool
	 *
	 * @since 2.2.17
	 */
	public static function collection_notice_applies( int $user_id ): bool {
		$enabled = get_option( self::SERIES_FOLLOW_ENABLED_OPTION, false );
		if ( ! in_array( $enabled, array( true, 1, '1', 'yes', 'on' ), true ) ) {
			return false;
		}

		if ( $user_id < 1 ) {
			return true;
		}

		// Misma comparación que `FollowRegistry::opted_out_of_everything()`
		// ('1' === (string) meta), con guarda para una meta deserializada como
		// array: castearla a string es un warning, y el checkout no puede
		// avisar por un dato corrupto.
		$stored = get_user_meta( $user_id, self::SERIES_FOLLOW_OPTOUT_ALL_META, true );

		return ! ( is_scalar( $stored ) && '1' === (string) $stored );
	}

	/**
	 * Enqueues validation and checkout-copy helpers.
	 */
	private function enqueue_checkout_helpers(): void {
		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-blocks.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks-checkout',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-blocks.js',
			array( 'wc-blocks-checkout', 'wc-blocks-checkout-events', 'wc-blocks-data-store', 'wp-data' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);

		$this->enqueue_rut_validation();
		$this->enqueue_phone_validation();
		$this->enqueue_comuna_combobox();
		$this->enqueue_field_error_labels();
	}

	/**
	 * Enqueues the helper that keeps Blocks' generic «El campo %s no es válido.»
	 * naming the label the customer sees.
	 *
	 * Blocks validates the address form once before the cart hydrates, with the
	 * default labels («Ciudad», «Teléfono»), and never revalidates when the
	 * Chilean locale swaps them for «Comuna» and «Celular»; it also lowercases
	 * «RUT». Its own file, like the RUT and the phone: checkout-blocks.js stays
	 * free of field logic.
	 */
	private function enqueue_field_error_labels(): void {
		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-field-errors.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks-field-errors',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-field-errors.js',
			array( 'wc-blocks-data-store', 'wp-data', 'wp-i18n' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);
	}

	/**
	 * Enqueues the client-side RUT check (blur), twin of CheckoutFields::validate_rut.
	 *
	 * Lives in its own file: checkout-blocks.js is kept free of contact-field
	 * logic by a test invariant, and the RUT feedback must not depend on it.
	 */
	private function enqueue_rut_validation(): void {
		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-rut.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks-rut',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-rut.js',
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);
	}

	/**
	 * Enqueues client-side inline phone validation on blur, providing early feedback
	 * before form submission. Follows the dedicated per-field script modularization pattern.
	 */
	private function enqueue_phone_validation(): void {
		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/checkout-phone.js';
		wp_enqueue_script(
			'akibara-cart-checkout-blocks-phone',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/checkout-phone.js',
			array( 'wc-blocks-checkout', 'wc-blocks-data-store', 'wp-data' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);
	}

	/**
	 * Enqueues the commune combobox with its region catalog.
	 *
	 * Skipped entirely when the Blue Express catalog is unavailable so the
	 * checkout keeps its plain text field (fail open, no broken UI).
	 */
	private function enqueue_comuna_combobox(): void {
		if ( null === $this->comunas || ! $this->comunas->is_available() ) {
			return;
		}

		$script_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/js/comuna-combobox.js';
		wp_enqueue_script(
			'akibara-comuna-combobox',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/js/comuna-combobox.js',
			// `wc-blocks-data-store` es quien registra `wc/store/validation`,
			// el store donde el combobox marca una comuna inexistente (igual
			// que checkout-rut.js). Antes la dependencia era real pero no
			// declarada: funcionaba por el orden en que Blocks encola, no por
			// contrato.
			array( 'wp-data', 'wc-blocks-data-store' ),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $script_path ) ? '.' . filemtime( $script_path ) : '' ),
			true
		);

		$region_labels = array();
		if ( function_exists( 'WC' ) && is_object( WC()->countries ?? null ) ) {
			$states = WC()->countries->get_states( 'CL' );
			if ( is_array( $states ) ) {
				$region_labels = array_map( 'html_entity_decode', $states );
			}
		}

		wp_add_inline_script(
			'akibara-comuna-combobox',
			'window.akbComunaData = ' . wp_json_encode(
				array(
					'regions'      => $this->comunas->all_by_region(),
					'regionLabels' => $region_labels,
					'strings'      => array(
						'noMatches'   => 'Sin coincidencias en esta región',
						'listLabel'   => 'Comunas sugeridas',
						'resultCount' => '%d comunas disponibles',
					),
				)
			) . ';',
			'before'
		);

		$style_path = AKIBARA_CART_CHECKOUT_BLOCKS_DIR . 'assets/css/comuna-combobox.css';
		wp_enqueue_style(
			'akibara-comuna-combobox',
			AKIBARA_CART_CHECKOUT_BLOCKS_URL . 'assets/css/comuna-combobox.css',
			array(),
			AKIBARA_CART_CHECKOUT_BLOCKS_VERSION . ( file_exists( $style_path ) ? '.' . filemtime( $style_path ) : '' )
		);
	}
}
