<?php
/**
 * Settings for Akibara Native Cart.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\NativeCart;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/**
	 * Acción de nonce de las mutaciones del carrito.
	 *
	 * El prefijo literal woocommerce permite que WC_Session_Handler vincule el
	 * nonce de invitado a su sesión mediante nonce_user_logged_out. ajax_get_cart
	 * es un canal no cacheable que devuelve un nonce fresco sin exigir uno previo.
	 * El cliente lo adopta y, ante un 403 de mutación, refresca y reintenta una vez.
	 * La primera lectura del drawer establece el nonce antes de modificar el carrito.
	 */
	public const NONCE_ACTION = 'woocommerce_akibara_native_cart';

	public const OPTION_ENABLED                    = 'akibara_native_cart_enabled';
	public const OPTION_AUTO_OPEN                  = 'akibara_native_cart_auto_open';
	public const OPTION_TRIGGER_SELECTOR           = 'akibara_native_cart_trigger_selector';
	public const OPTION_MOBILE_FULLSCREEN          = 'akibara_native_cart_mobile_fullscreen';
	public const OPTION_DRAWER_SIDE                = 'akibara_native_cart_drawer_side';
	public const OPTION_DRAWER_WIDTH               = 'akibara_native_cart_drawer_width';
	public const OPTION_SHOW_THUMBNAILS            = 'akibara_native_cart_show_thumbnails';
	public const OPTION_SHOW_SUBTOTAL              = 'akibara_native_cart_show_subtotal';
	public const OPTION_SHOW_FREE_SHIPPING         = 'akibara_native_cart_show_free_shipping';
	public const OPTION_SHOW_RECOMMENDATIONS       = 'akibara_native_cart_show_recommendations';
	public const OPTION_RECOMMENDATION_LIMIT       = 'akibara_native_cart_recommendation_limit';
	public const OPTION_RECOMMENDATION_SOURCE      = 'akibara_native_cart_recommendation_source';
	public const OPTION_SHOW_BYP_POLICY            = 'akibara_native_cart_show_byp_policy';
	public const OPTION_SHOW_PREVENTA_POLICY       = 'akibara_native_cart_show_preventa_policy';
	public const OPTION_EMPTY_COPY                 = 'akibara_native_cart_empty_copy';
	public const OPTION_CHECKOUT_COPY              = 'akibara_native_cart_checkout_copy';
	public const OPTION_CONTINUE_COPY              = 'akibara_native_cart_continue_copy';
	public const OPTION_CONTINUE_URL               = 'akibara_native_cart_continue_url';
	public const OPTION_BYP_POLICY_COPY            = 'akibara_native_cart_byp_policy_copy';
	public const OPTION_PREVENTA_POLICY_COPY       = 'akibara_native_cart_preventa_policy_copy';
	public const OPTION_REDUCED_MOTION             = 'akibara_native_cart_reduced_motion';
	public const OPTION_RECOMMENDATIONS_COPY       = 'akibara_native_cart_recommendations_copy';
	/**
	 * Aviso del carrito de BLOQUES para quien aún no ingresa dirección.
	 *
	 * El texto lo pinta `akibara-cart-checkout-blocks`, que no tiene pantalla
	 * propia; se edita acá porque es un copy del carrito, junto a los otros. El
	 * plugin de bloques lo lee por `get_option()` con este MISMO nombre y deja el
	 * filtro homónimo como override programático.
	 */
	public const OPTION_BLOCKS_SHIPPING_HINT      = 'akibara_blocks_shipping_hint_text';
	/**
	 * Aviso de colección en el CHECKOUT de bloques, encima del botón de pagar.
	 *
	 * Mismo mecanismo que el aviso de Santiago: el texto lo pinta
	 * `akibara-cart-checkout-blocks`, que lo lee por `get_option()` con este
	 * MISMO nombre y deja un filtro homónimo como override programático. Se
	 * edita aquí porque es un copy del checkout, junto a los otros. Vacío =
	 * apagado.
	 */
	public const OPTION_BLOCKS_COLLECTION_NOTICE  = 'akibara_blocks_collection_notice_text';
	public const OPTION_RECOMMENDATION_ADD_COPY    = 'akibara_native_cart_recommendation_add_copy';

	public const RECOMMENDATION_SOURCE_CROSS_SELLS = 'cross_sells';
	public const RECOMMENDATION_SOURCE_NONE        = 'none';

	/**
	 * Selector por defecto de los elementos que abren el carrito. Dejar el
	 * campo vacío restaura este valor (es la afordancia de "restaurar default").
	 */
	public const DEFAULT_TRIGGER_SELECTOR = '.akb-cart-trigger';

	/**
	 * Tab del AdminPanel donde vive este módulo (para validación por tab).
	 */
	public const PANEL_TAB_ID = 'native_cart';

	private const DEFAULT_EMPTY_COPY           = 'Aún no agregas títulos. Explora nuestro catálogo de manga y cómics y encuentra tu próxima lectura.';
	private const DEFAULT_CHECKOUT_COPY        = 'Pagar pedido';
	private const DEFAULT_CONTINUE_COPY        = 'Seguir comprando';
	private const DEFAULT_BYP_POLICY_COPY      = 'Los descuentos de Arma tu Pack se calculan al actualizar el carrito.';
	private const DEFAULT_PREVENTA_POLICY_COPY = 'Si mezclas {tipo} con stock listo, se enviará en un despacho único al reunirse todos los tomos de tu pedido.';
	private const DEFAULT_RECOMMENDATIONS_COPY = 'También te puede gustar';
	private const DEFAULT_RECOMMENDATION_ADD_COPY = 'Agregar';

	/** El literal que el carrito de bloques tenía clavado. */
	public const DEFAULT_BLOCKS_SHIPPING_HINT = '¿Prefieres despacho a domicilio? Se calcula en el siguiente paso al ingresar tu comuna.';

	/**
	 * Aviso de colección del checkout. Cuenta el MISMO hecho que la nota de
	 * Mi cuenta → Colección (`templates/myaccount/collection.php`) y que la
	 * política de privacidad —la compra activa los avisos de colección, no son
	 * publicidad y se apagan en Mi cuenta → Colección—, pero con un texto
	 * propio para su superficie: aquí sólo el aviso de colección; en Mi cuenta,
	 * además, las ofertas de campañas y su enlace de baja. NO son idénticos y
	 * ninguno se mueve solo: si cambia el hecho, hay que barrer las tres
	 * superficies a mano (la política vive en WordPress, no en este repo).
	 */
	public const DEFAULT_BLOCKS_COLLECTION_NOTICE = 'Con tu cuenta Akibara te avisamos por correo cuando salga el siguiente tomo de las series que compras. No es publicidad: lo apagas cuando quieras en Mi cuenta, pestaña Colección.';

	/**
	 * Registers WooCommerce setting sanitizers used by AdminPanel.
	 */
	public function register(): void {
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( $this, 'sanitize_admin_option' ), 10, 3 );
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_trigger_selector' ), 10, 2 );
	}

	/**
	 * Valida que el selector de activadores sea interpretable por el navegador.
	 *
	 * Un selector CSS inválido haría fallar element.closest(). Se rechaza su guardado
	 * con un error visible y se conserva el valor previamente válido.
	 *
	 * @since 1.98.0
	 *
	 * @param array<int, string> $errors Errores previos de otros módulos.
	 * @param string             $tab    Tab activo del panel.
	 * @return array<int, string>
	 */
	public function validate_trigger_selector( array $errors, string $tab ): array {
		if ( self::PANEL_TAB_ID !== $tab ) {
			return $errors;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel verifica el nonce antes de este filter.
		if ( ! isset( $_POST[ self::OPTION_TRIGGER_SELECTOR ] ) ) {
			return $errors;
		}
		// El sniff no reconoce `sanitize_copy()` como sanitizador, pero es
		// `sanitize_text_field` sobre `wp_strip_all_tags`: la entrada SÍ se sanea.
		$raw = self::sanitize_copy( wp_unslash( (string) $_POST[ self::OPTION_TRIGGER_SELECTOR ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_copy() = trim( sanitize_text_field( wp_strip_all_tags())).
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Vacío es intencional: el sanitizer repone el default.
		if ( '' === $raw ) {
			return $errors;
		}

		foreach ( preg_split( '/\s*,\s*/', $raw ) ?: array() as $selector ) {
			if ( '' === $selector || self::is_valid_trigger_selector( $selector ) ) {
				continue;
			}

			$errors[] = sprintf(
				'Carrito: «%s» no es un selector CSS válido para abrir el carrito. Usa una clase (<code>.mi-boton</code>) o un id (<code>#mi-boton</code>), separando varios por coma, o deja el campo vacío para volver al valor por defecto (<code>%s</code>).',
				esc_html( $selector ),
				esc_html( self::DEFAULT_TRIGGER_SELECTOR )
			);
		}

		return $errors;
	}

	/**
	 * ¿Es un selector usable como activador del carrito?
	 *
	 * Deliberadamente conservador: exige que apunte a una clase, un id o un
	 * atributo (`.x`, `#x`, `[data-x]`), que es lo que un activador siempre es.
	 * Una cadena de nombres de etiqueta ("boton rojo del header") es CSS
	 * sintácticamente válido pero jamás es lo que el operador quiso escribir, y
	 * aceptarla dejaría el carrito sin activadores igual de silenciosamente.
	 *
	 * @since 1.98.0
	 *
	 * @param string $selector Un selector individual, ya recortado.
	 * @return bool
	 */
	public static function is_valid_trigger_selector( string $selector ): bool {
		if ( 1 !== preg_match( '/[.#\[]/', $selector ) ) {
			return false;
		}

		// Caracteres imposibles en un selector de activador. `>` (combinador
		// hijo) y los paréntesis de `:not(...)` SÍ son válidos y no van acá.
		if ( 1 === preg_match( '/[{};<"\']/', $selector ) ) {
			return false;
		}

		// Estructura: secuencias tipo `tag.clase#id[attr]` unidas por
		// combinadores (espacio, >, +, ~), con pseudo-clases simples.
		$unit    = '(?:[A-Za-z][\w-]*|\*)?(?:[.#][\w-]+|\[[\w-]+(?:[~|^$*]?=[^\]]*)?\]|:[\w-]+(?:\([^()]*\))?)+';
		$pattern = '/^' . $unit . '(?:\s*[>+~]\s*|\s+)?(?:' . $unit . '(?:\s*[>+~]\s*|\s+)?)*$/';

		return 1 === preg_match( $pattern, $selector );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Carrito',
				'type'  => 'title',
				'id'    => 'akibara_native_cart_section',
				'desc'  => 'Configuración operativa del carrito lateral nativo.',
			),
			array(
				'title'   => 'Activar carrito nativo',
				'id'      => self::OPTION_ENABLED,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Mostrar el drawer nativo fuera de carrito y checkout.',
			),
			array(
				'title'   => 'Abrir al agregar',
				'id'      => self::OPTION_AUTO_OPEN,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Abrir automáticamente después de agregar un producto.',
			),
			array(
				'title'    => 'Selector de activadores (avanzado)',
				'id'       => self::OPTION_TRIGGER_SELECTOR,
				'type'     => 'text',
				'default'  => self::DEFAULT_TRIGGER_SELECTOR,
				'desc'     => 'Campo avanzado. Elementos que abren el carrito, escritos como selectores CSS (empiezan con <code>.</code> para una clase o <code>#</code> para un id) y separados por coma. <strong>Déjalo vacío para volver al valor por defecto</strong> (<code>' . self::DEFAULT_TRIGGER_SELECTOR . '</code>). Un valor inválido impide abrir el carrito, así que se rechaza al guardar.',
				'css'      => 'min-width: 360px;',
			),
			array(
				'title'   => 'Pantalla completa en mobile',
				'id'      => self::OPTION_MOBILE_FULLSCREEN,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'En celulares el carrito ocupa toda la pantalla en lugar de abrirse como panel lateral.',
			),
			array(
				'title'   => 'Lado del drawer',
				'id'      => self::OPTION_DRAWER_SIDE,
				'type'    => 'select',
				'default' => 'right',
				'options' => array(
					'right' => 'Derecha',
					'left'  => 'Izquierda',
				),
			),
			array(
				'title'             => 'Ancho desktop',
				'id'                => self::OPTION_DRAWER_WIDTH,
				'type'              => 'number',
				'default'           => 404,
				'custom_attributes' => array(
					'min'  => 320,
					'max'  => 640,
					'step' => 1,
				),
				'desc'              => 'Pixeles. Se limita entre 320 y 640.',
			),
			array(
				'title'   => 'Mostrar miniaturas',
				'id'      => self::OPTION_SHOW_THUMBNAILS,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra la portada de cada producto en la lista del carrito.',
			),
			array(
				'title'   => 'Mostrar subtotal',
				'id'      => self::OPTION_SHOW_SUBTOTAL,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra el total antes de descuentos y envío en el pie del carrito.',
			),
			array(
				'title'   => 'Mostrar avance envío gratis',
				'id'      => self::OPTION_SHOW_FREE_SHIPPING,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra cuánto falta para alcanzar el envío gratis (se configura en la pestaña Envío gratis).',
			),
			array(
				'title'   => 'Mostrar recomendados',
				'id'      => self::OPTION_SHOW_RECOMMENDATIONS,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Sugiere productos relacionados dentro del carrito.',
			),
			array(
				'title'             => 'Límite de recomendados',
				'desc'              => 'Cuántos productos sugeridos se muestran como máximo (0 los oculta).',
				'id'                => self::OPTION_RECOMMENDATION_LIMIT,
				'type'              => 'number',
				'default'           => 3,
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 6,
					'step' => 1,
				),
			),
			array(
				'title'   => 'Fuente de recomendados',
				'id'      => self::OPTION_RECOMMENDATION_SOURCE,
				'type'    => 'select',
				'default' => self::RECOMMENDATION_SOURCE_CROSS_SELLS,
				'options' => array(
					self::RECOMMENDATION_SOURCE_CROSS_SELLS => 'Cross-sells de WooCommerce',
					self::RECOMMENDATION_SOURCE_NONE        => 'Sin recomendados',
				),
			),
			// El interruptor y el texto que enciende van pegados. Estaban a ~55
			// líneas de distancia, y con eso la descripción del interruptor se
			// convirtió en un resumen de la política («se despacha cuando llegan
			// todos los productos») que dejó de coincidir con el copy realmente
			// publicado («revisa las opciones de despacho antes de pagar»): el
			// dueño leía una promesa en el admin y el cliente veía otra frase en el
			// carrito. Una descripción que dice qué hace el interruptor, y no qué
			// dice el texto, no puede volver a desincronizarse cuando el copy cambie.
			array(
				'title'   => 'Aviso Arma tu Pack',
				'id'      => self::OPTION_SHOW_BYP_POLICY,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra en el carrito el «Copy Arma tu Pack» de aquí abajo cuando el carrito arma un pack de una serie.',
			),
			array(
				'title'   => 'Copy Arma tu Pack',
				'id'      => self::OPTION_BYP_POLICY_COPY,
				'type'    => 'textarea',
				'default' => self::DEFAULT_BYP_POLICY_COPY,
				'desc'    => 'Sólo se ve si «Aviso Arma tu Pack» está activo.',
			),
			array(
				'title'   => 'Aviso preventa',
				'id'      => self::OPTION_SHOW_PREVENTA_POLICY,
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => 'Muestra en el carrito el «Aviso de pedidos/preventa en el carrito» de aquí abajo cuando el carrito lleva algún producto reservable.',
			),
			array(
				'title'   => 'Aviso de pedidos/preventa en el carrito',
				'id'      => self::OPTION_PREVENTA_POLICY_COPY,
				'type'    => 'textarea',
				'default' => self::DEFAULT_PREVENTA_POLICY_COPY,
				'desc'    => 'Escribe {tipo} donde va la palabra: el carrito la elige solo («pedidos», «preventas» o «preventas y pedidos» según lo que el cliente lleve). Si escribes un texto sin {tipo}, se muestra tal cual. Solo se ve si «Aviso preventa» está activo.',
			),
			array(
				'title'   => 'Aviso «¿No estás en Santiago?» (carrito de bloques)',
				'id'      => self::OPTION_BLOCKS_SHIPPING_HINT,
				'type'    => 'textarea',
				'default' => self::DEFAULT_BLOCKS_SHIPPING_HINT,
				'desc'    => 'Sin dirección, WooCommerce sólo ofrece retiro y un cliente de regiones puede leer «no hay despacho» y abandonar. El aviso desaparece solo en cuanto la dirección tiene cobertura. <strong>Déjalo vacío para apagarlo.</strong>',
			),
			array(
				'title'   => 'Aviso de colección (checkout de bloques)',
				'id'      => self::OPTION_BLOCKS_COLLECTION_NOTICE,
				'type'    => 'textarea',
				'default' => self::DEFAULT_BLOCKS_COLLECTION_NOTICE,
				'desc'    => 'Se ve en el checkout, encima del botón de pagar, a todos los clientes. Déjalo vacío para apagarlo.',
			),
			array(
				'title'   => 'Movimiento reducido',
				'id'      => self::OPTION_REDUCED_MOTION,
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => 'Fuerza una experiencia sin transiciones largas.',
			),
			array(
				'title'   => 'Texto carrito vacío',
				'id'      => self::OPTION_EMPTY_COPY,
				'type'    => 'textarea',
				'default' => self::DEFAULT_EMPTY_COPY,
			),
			array(
				'title'   => 'Texto botón checkout',
				'id'      => self::OPTION_CHECKOUT_COPY,
				'type'    => 'text',
				'default' => self::DEFAULT_CHECKOUT_COPY,
			),
			array(
				'title'   => 'Texto seguir comprando',
				'id'      => self::OPTION_CONTINUE_COPY,
				'type'    => 'text',
				'default' => self::DEFAULT_CONTINUE_COPY,
			),
			array(
				'title'   => 'Título de recomendados',
				'id'      => self::OPTION_RECOMMENDATIONS_COPY,
				'type'    => 'text',
				'default' => self::DEFAULT_RECOMMENDATIONS_COPY,
				'desc'    => 'Encabezado que aparece sobre los productos sugeridos.',
			),
			array(
				'title'   => 'Botón de recomendados',
				'id'      => self::OPTION_RECOMMENDATION_ADD_COPY,
				'type'    => 'text',
				'default' => self::DEFAULT_RECOMMENDATION_ADD_COPY,
				'desc'    => 'Texto breve del botón para agregar una recomendación.',
			),
			array(
				'title'   => 'URL seguir comprando',
				'id'      => self::OPTION_CONTINUE_URL,
				'type'    => 'text',
				'default' => '',
				'desc'    => 'Vacío usa la URL de la tienda.',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akibara_native_cart_section',
			),
		);
	}

	/**
	 * @param mixed                $value Raw/sanitized value from WooCommerce.
	 * @param array<string, mixed> $option Setting definition.
	 * @param mixed                $raw_value Raw value.
	 * @return mixed
	 */
	public function sanitize_admin_option( $value, array $option, $raw_value = null ) {
		$id    = (string) ( $option['id'] ?? '' );
		$input = null !== $raw_value ? $raw_value : $value;

		switch ( $id ) {
			case self::OPTION_DRAWER_WIDTH:
				return self::sanitize_drawer_width( $input );
			case self::OPTION_RECOMMENDATION_LIMIT:
				return self::sanitize_recommendation_limit( $input );
			case self::OPTION_DRAWER_SIDE:
				return self::sanitize_drawer_side( (string) $input );
			case self::OPTION_RECOMMENDATION_SOURCE:
				return self::sanitize_recommendation_source( (string) $input );
			case self::OPTION_TRIGGER_SELECTOR:
				return self::sanitize_trigger_selector( (string) $input );
			case self::OPTION_CONTINUE_URL:
				return esc_url_raw( (string) $input );
			case self::OPTION_EMPTY_COPY:
			case self::OPTION_CHECKOUT_COPY:
			case self::OPTION_CONTINUE_COPY:
			case self::OPTION_BYP_POLICY_COPY:
			case self::OPTION_PREVENTA_POLICY_COPY:
			case self::OPTION_RECOMMENDATIONS_COPY:
			// El vacío se GUARDA, no se repone: en el aviso de Santiago y en el
			// de colección vaciar el campo es apagarlo, y reponer el default
			// dejaría al dueño sin forma de quitarlos desde la pantalla.
			case self::OPTION_BLOCKS_SHIPPING_HINT:
			case self::OPTION_BLOCKS_COLLECTION_NOTICE:
			case self::OPTION_RECOMMENDATION_ADD_COPY:
				return self::sanitize_copy( (string) $input );
			default:
				return $value;
		}
	}

	public static function sanitize_drawer_width( $value ): int {
		return max( 320, min( 640, absint( $value ) ) );
	}

	public static function sanitize_recommendation_limit( $value ): int {
		return max( 0, min( 6, intval( $value ) ) );
	}

	public static function sanitize_drawer_side( string $value ): string {
		return in_array( $value, array( 'left', 'right' ), true ) ? $value : 'right';
	}

	public static function sanitize_recommendation_source( string $value ): string {
		return in_array( $value, array( self::RECOMMENDATION_SOURCE_CROSS_SELLS, self::RECOMMENDATION_SOURCE_NONE ), true )
			? $value
			: self::RECOMMENDATION_SOURCE_CROSS_SELLS;
	}

	public static function sanitize_trigger_selector( string $value ): string {
		$value = self::sanitize_copy( $value );
		$selectors = preg_split( '/\s*,\s*/', $value ) ?: array();
		$selectors = array_values(
			array_filter(
				$selectors,
				// Los inválidos ya bloquearon el save en validate_trigger_selector;
				// se filtran igual por si el valor llega por otra vía (WP-CLI,
				// migración) — el carrito nunca debe quedarse sin activador.
				static fn( string $selector ): bool => '' !== $selector
					&& '.xoo-wsc-cart-trigger' !== $selector
					&& self::is_valid_trigger_selector( $selector )
			)
		);

		return empty( $selectors ) ? self::DEFAULT_TRIGGER_SELECTOR : implode( ', ', $selectors );
	}

	public static function sanitize_copy( string $value ): string {
		return trim( sanitize_text_field( wp_strip_all_tags( $value ) ) );
	}

	public function is_enabled(): bool {
		return $this->is_yes( self::OPTION_ENABLED, 'yes' );
	}

	public function should_auto_open(): bool {
		return $this->is_yes( self::OPTION_AUTO_OPEN, 'yes' );
	}

	public function get_trigger_selector(): string {
		return self::sanitize_trigger_selector( (string) get_option( self::OPTION_TRIGGER_SELECTOR, '.akb-cart-trigger' ) );
	}

	public function mobile_fullscreen(): bool {
		return $this->is_yes( self::OPTION_MOBILE_FULLSCREEN, 'yes' );
	}

	public function get_drawer_side(): string {
		return self::sanitize_drawer_side( (string) get_option( self::OPTION_DRAWER_SIDE, 'right' ) );
	}

	public function get_drawer_width(): int {
		return self::sanitize_drawer_width( get_option( self::OPTION_DRAWER_WIDTH, 404 ) );
	}

	public function show_thumbnails(): bool {
		return $this->is_yes( self::OPTION_SHOW_THUMBNAILS, 'yes' );
	}

	public function show_subtotal(): bool {
		return $this->is_yes( self::OPTION_SHOW_SUBTOTAL, 'yes' );
	}

	public function show_free_shipping_progress(): bool {
		return $this->is_yes( self::OPTION_SHOW_FREE_SHIPPING, 'yes' );
	}

	public function show_recommendations(): bool {
		return $this->is_yes( self::OPTION_SHOW_RECOMMENDATIONS, 'yes' ) && $this->get_recommendation_limit() > 0;
	}

	public function get_recommendation_limit(): int {
		return self::sanitize_recommendation_limit( get_option( self::OPTION_RECOMMENDATION_LIMIT, 3 ) );
	}

	public function get_recommendation_source(): string {
		return self::sanitize_recommendation_source( (string) get_option( self::OPTION_RECOMMENDATION_SOURCE, self::RECOMMENDATION_SOURCE_CROSS_SELLS ) );
	}

	public function show_byp_policy(): bool {
		return $this->is_yes( self::OPTION_SHOW_BYP_POLICY, 'yes' );
	}

	public function show_preventa_policy(): bool {
		return $this->is_yes( self::OPTION_SHOW_PREVENTA_POLICY, 'yes' );
	}

	public function prefers_reduced_motion(): bool {
		return $this->is_yes( self::OPTION_REDUCED_MOTION, 'no' );
	}

	public function get_empty_copy(): string {
		return $this->copy_option( self::OPTION_EMPTY_COPY, self::DEFAULT_EMPTY_COPY );
	}

	public function get_checkout_copy(): string {
		return $this->copy_option( self::OPTION_CHECKOUT_COPY, self::DEFAULT_CHECKOUT_COPY );
	}

	public function get_continue_copy(): string {
		return $this->copy_option( self::OPTION_CONTINUE_COPY, self::DEFAULT_CONTINUE_COPY );
	}

	public function get_continue_url(): string {
		$url = esc_url_raw( (string) get_option( self::OPTION_CONTINUE_URL, '' ) );
		return '' !== $url ? $url : wc_get_page_permalink( 'shop' );
	}

	public function get_byp_policy_copy(): string {
		return $this->copy_option( self::OPTION_BYP_POLICY_COPY, self::DEFAULT_BYP_POLICY_COPY );
	}

	public function get_preventa_policy_copy(): string {
		return $this->copy_option( self::OPTION_PREVENTA_POLICY_COPY, self::DEFAULT_PREVENTA_POLICY_COPY );
	}

	/**
	 * La frase del aviso con el {tipo} resuelto para ESTE carrito
	 * («pedidos» / «preventas» / «preventas y pedidos»). Si el dueño editó
	 * la copy y no dejó {tipo}, SU texto manda tal cual.
	 *
	 * @param string $tipo Palabra detectada por el carrito.
	 * @return string
	 */
	public function build_preventa_policy_copy( string $tipo ): string {
		return str_replace( '{tipo}', $tipo, $this->get_preventa_policy_copy() );
	}

	public function get_recommendations_copy(): string {
		return $this->copy_option( self::OPTION_RECOMMENDATIONS_COPY, self::DEFAULT_RECOMMENDATIONS_COPY );
	}

	public function get_recommendation_add_copy(): string {
		return $this->copy_option( self::OPTION_RECOMMENDATION_ADD_COPY, self::DEFAULT_RECOMMENDATION_ADD_COPY );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_script_data(): array {
		return array(
			'enabled'                  => $this->is_enabled(),
			'autoOpenOnAdd'            => $this->should_auto_open(),
			'triggerSelector'          => $this->get_trigger_selector(),
			'mobileFullscreen'         => $this->mobile_fullscreen(),
			'drawerSide'               => $this->get_drawer_side(),
			'drawerWidth'              => $this->get_drawer_width(),
			'showThumbnails'           => $this->show_thumbnails(),
			'showSubtotal'             => $this->show_subtotal(),
			'showFreeShippingProgress' => $this->show_free_shipping_progress(),
			'showRecommendations'      => $this->show_recommendations(),
			'recommendationLimit'      => $this->get_recommendation_limit(),
			'recommendationSource'     => $this->get_recommendation_source(),
			'showBypPolicy'            => $this->show_byp_policy(),
			// La clave la lee `renderStatus()` en assets/js/native-cart.js. Viajó
			// como `showPedidoPolicy` desde que el aviso se renombró a «pedido»,
			// así que el interruptor llegaba al cliente bajo un nombre que el JS
			// no consulta y el aviso de preventa NUNCA se pintó.
			'showPreventaPolicy'       => $this->show_preventa_policy(),
			'reducedMotion'            => $this->prefers_reduced_motion(),
			'continueUrl'              => $this->get_continue_url(),
			'copy'                     => array(
				'empty'             => $this->get_empty_copy(),
				'checkout'          => $this->get_checkout_copy(),
				'continue'          => $this->get_continue_copy(),
				'bypPolicy'         => $this->get_byp_policy_copy(),
				'preventaPolicy'    => $this->get_preventa_policy_copy(),
				'recommendations'   => $this->get_recommendations_copy(),
				'recommendationAdd' => $this->get_recommendation_add_copy(),
				'loading'           => 'Cargando...',
				'checkingCart'      => 'Comprobando…',
				'checkoutWait'      => 'Espera a que termine de actualizarse tu carrito.',
				'cartEmptied'       => $this->get_empty_copy() . ' Agrega nuevamente tus productos para continuar.',
				'error'             => 'Error al cargar el carrito.',
				'connection'        => 'Error de conexión.',
				'added'             => 'Agregado al carrito.',
				'adding'            => 'Agregando…',
				'addError'          => 'No se pudo agregar. Intenta de nuevo.',
				'mutationError'     => 'No pudimos actualizar tu carrito. Intenta de nuevo.',
			),
		);
	}

	private function is_yes( string $option, string $default ): bool {
		return 'yes' === get_option( $option, $default );
	}

	private function copy_option( string $option, string $default ): string {
		$value = self::sanitize_copy( (string) get_option( $option, $default ) );
		return '' !== $value ? $value : $default;
	}
}
