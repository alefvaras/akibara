<?php
/**
 * MyAccountUx — Refina /mi-cuenta/ para uso mobile real.
 *
 * - Renombra labels de tabs para que entren en mobile (Detalles vs
 *   "Detalles de la cuenta", Direcciones vs "Dirección", Resumen vs
 *   "Escritorio").
 * - Reordena el menú priorizando acciones (Pedidos primero, Resumen
 *   penúltimo, Salir al final).
 * - Reemplaza el template `myaccount/dashboard.php` por uno propio
 *   que: saluda por first_name (no user_login), elimina el copy
 *   "¿no eres X? Cerrar sesión" que no tiene sentido logueado, y
 *   expone Pedidos/Favoritos/Direcciones/Detalles como cards con
 *   hit target ≥56px. "Cerrar sesión" pasa a botón ≥44px al cierre.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\MyAccountUx;

defined( 'ABSPATH' ) || exit;

final class MyAccountUx {

	/**
	 * Templates default WC que este módulo overridea desde el plugin.
	 *
	 * @var array<string, string>
	 */
	private const OVERRIDES = array(
		'myaccount/dashboard.php'         => 'templates/myaccount/dashboard.php',
		'myaccount/orders.php'            => 'templates/myaccount/orders.php',
		'myaccount/my-address.php'        => 'templates/myaccount/my-address.php',
		'myaccount/form-edit-account.php' => 'templates/myaccount/form-edit-account.php',
	);

	/**
	 * Registra hooks WC.
	 */
	public function register(): void {
		// Prioridad 20: después de Wishlist (10), que inserta 'favoritos'
		// luego de 'dashboard'. Reordenamos sobre el array final.
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ), 20 );

		// La recompra del mismo tomo no forma parte del flujo de la colección.
		// La lista de estados vacía impide el botón y el endpoint order_again, además
		// de retirar la acción en las filas del listado de pedidos.

		add_filter( 'woocommerce_valid_order_statuses_for_order_again', '__return_empty_array' );
		add_filter( 'woocommerce_locate_template', array( $this, 'override_template' ), 10, 3 );

		// Quitar display_name del listado de required en submit de edit-account
		// (template propio ya no lo renderea pero WC core valida server-side).
		add_filter( 'woocommerce_save_account_details_required_fields', array( $this, 'required_fields' ) );

		// El template propio NO renderea el field account_display_name → llega
		// vacío al submit handler de WC. wp_update_user($user) con
		// display_name='' falla silenciosamente y se pierden first_name,
		// last_name, email también. Auto-componer display_name desde
		// first_name+last_name resuelve el silent failure sin volver a meter
		// el field en el form.
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'auto_display_name' ), 5, 2 );

		// Pre-validar RUT en edit-account antes de wp_update_user (UX-25).
		// Evita que un RUT vacío/inválido persista nombre y fecha de cumpleaños parcialmente.
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_account_rut' ), 10, 2 );

		// WC limpia `default_password_nag` SOLO en el reset_password flow
		// (vía /lost-password/). Cuando el usuario cambia su password via
		// /edit-account/ form, el meta queda en 1 y el banner amarillo
		// "contraseña temporal" persiste — UX broken. Limpiamos acá.
		add_action( 'woocommerce_save_account_details', array( $this, 'clear_password_nag_on_change' ) );

		// Retirar "Volver a comprar" de /mi-cuenta/orders/ (ver el método).
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'orders_actions' ), 20, 2 );

		// Enqueue del password-strength-meter de WP core en /edit-account/.
		// zxcvbn ya viene bundled en core → cero costo de bundle propio.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_password_strength' ) );
	}

	/**
	 * Enqueue del strength meter nativo de WP core (zxcvbn wrapped). Solo
	 * en /mi-cuenta/edit-account/. El template `form-edit-account.php`
	 * tiene el container `#akb-pwd-strength` donde el JS inline pinta el
	 * resultado.
	 */
	public function enqueue_password_strength(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'edit-account' ) ) {
			return;
		}

		// WooCommerce encola wc-password-strength-meter en la edición de cuenta y
		// sus dependencias incluyen password-strength-meter y zxcvbn. La plantilla
		// consume wp.passwordStrength y su validación gobierna el submit. No retirar
		// este enlace sin revisar el grafo de scripts y la validación completa.

		wp_enqueue_script( 'password-strength-meter' );

		// Strings localizados para el meter (i18n de WP usa estos por default
		// pero los pisamos para que el copy quede en es-CL aunque WP esté en
		// otro locale).
		wp_localize_script(
			'password-strength-meter',
			'pwsL10n',
			array(
				'unknown'  => 'Fortaleza de la contraseña',
				'short'    => 'Muy débil',
				'bad'      => 'Débil',
				'good'     => 'Media',
				'strong'   => 'Fuerte',
				'mismatch' => 'No coincide',
			)
		);
	}

	/**
	 * Renombra labels para mobile + reordena por prioridad de acción.
	 *
	 * @param array<string, string> $items Items WC del menú.
	 * @return array<string, string>
	 */
	public function menu_items( array $items ): array {
		// Labels cortos para los chips horizontales del menú mobile.
		if ( isset( $items['dashboard'] ) ) {
			$items['dashboard'] = 'Resumen';
		}
		if ( isset( $items['edit-account'] ) ) {
			$items['edit-account'] = 'Detalles';
		}
		if ( isset( $items['edit-address'] ) ) {
			$items['edit-address'] = 'Direcciones';
		}

		// Orden mobile-first: lo más usado primero.
		// Pedidos > Colección > Recompensas > Favoritos > Direcciones > Detalles > Resumen > Salir.
		//
		// `rewards` va tercero a propósito: en 375 px sólo entran tres pestañas
		// sin deslizar, y al quedar fuera de la lista de prioridad caía al sexto
		// puesto, o sea fuera de la vista inicial. Es la pantalla donde el
		// cliente ve su saldo de Akibara Puntos, así que tiene que verse sin
		// descubrir primero que la barra se desliza.
		//
		// `coleccion` NO está en la lista y no es un olvido: este filtro corre en
		// prioridad 20 y `AccountCollection\Endpoint::menu_item()` inserta la
		// pestaña en la 30, o sea DESPUÉS. Nombrarla acá era ordenar una clave que
		// todavía no existe. Quien la inserta la pone inmediatamente detrás de
		// `orders`, que es justo el puesto que este orden le reserva.
		$priority = array( 'orders', 'rewards', 'favoritos', 'edit-address', 'edit-account' );

		$reordered = array();
		foreach ( $priority as $key ) {
			if ( isset( $items[ $key ] ) ) {
				$reordered[ $key ] = $items[ $key ];
			}
		}

		// Items extras que algún plugin agregue se conservan entre las acciones
		// principales y Resumen. Logout queda siempre al final: una acción
		// destructiva no debe mezclarse con destinos normales de la cuenta.
		foreach ( $items as $key => $label ) {
			if ( ! isset( $reordered[ $key ] ) && 'dashboard' !== $key && 'customer-logout' !== $key ) {
				$reordered[ $key ] = $label;
			}
		}
		if ( isset( $items['dashboard'] ) ) {
			$reordered['dashboard'] = $items['dashboard'];
		}
		if ( isset( $items['customer-logout'] ) ) {
			$reordered['customer-logout'] = $items['customer-logout'];
		}

		return $reordered;
	}

	/**
	 * Override de templates `myaccount/*.php` listados en OVERRIDES. Sigue el
	 * patrón de GoogleLoginButton::override_form_login. Si el theme decide
	 * overridear el mismo template, ese gana (WC busca theme primero).
	 *
	 * @param string $template      Path absoluto resuelto por WC.
	 * @param string $template_name Nombre relativo (e.g. `myaccount/orders.php`).
	 * @param string $template_path Path base WC.
	 * @return string
	 */
	public function override_template( string $template, string $template_name, string $template_path ): string {
		if ( ! isset( self::OVERRIDES[ $template_name ] ) ) {
			return $template;
		}

		// Solo overridear si WC iba a servir su default (sin theme override).
		$wc_default_dir = defined( 'WC_ABSPATH' ) ? \WC_ABSPATH . 'templates/' : '';
		if ( '' === $wc_default_dir || 0 !== strpos( $template, $wc_default_dir ) ) {
			return $template;
		}

		$plugin_template = AKIBARA_BUSINESS_DIR . self::OVERRIDES[ $template_name ];
		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}

	/**
	 * Saca `account_display_name` de los required server-side. El template
	 * propio (`form-edit-account.php`) ya no renderea el field — sin esto WC
	 * rechaza el submit por field missing.
	 *
	 * @param array<string, string> $fields Required fields map id→label.
	 * @return array<string, string>
	 */
	public function required_fields( array $fields ): array {
		unset( $fields['account_display_name'] );
		return $fields;
	}

	/**
	 * Limpia el meta `default_password_nag` si el user cambió password vía
	 * el form de edit-account. Solo borra si las 3 password fields llegaron
	 * completas en el POST (indicador de que WC efectivamente persistió la
	 * nueva password). Sin esto, el banner "contraseña temporal" persiste
	 * indefinidamente aunque el user ya cambió su pass.
	 *
	 * @param int $user_id
	 */
	public function clear_password_nag_on_change( int $user_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC core verifica el nonce «save_account_details» en WC_Form_Handler ANTES de disparar este hook; aquí sólo se lee la forma del POST.
		$changed_password = ! empty( $_POST['password_current'] )
			&& ! empty( $_POST['password_1'] )
			&& ! empty( $_POST['password_2'] )
			&& $_POST['password_1'] === $_POST['password_2'];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $changed_password ) {
			update_user_meta( $user_id, 'default_password_nag', false );
		}
	}

	/**
	 * Compone display_name antes de que WooCommerce llame a wp_update_user().
	 *
	 * La plantilla no expone account_display_name, pero el objeto de usuario debe
	 * llevar un display_name válido para guardar los demás datos de la cuenta.
	 * woocommerce_save_account_details_errors recibe errors y user por referencia.
	 *
	 * @param \WP_Error  $errors
	 * @param \stdClass  $user
	 */
	public function auto_display_name( $errors, $user ): void {
		if ( ! empty( $user->display_name ) ) {
			return;
		}
		$parts = array_filter(
			array(
				isset( $user->first_name ) ? trim( (string) $user->first_name ) : '',
				isset( $user->last_name ) ? trim( (string) $user->last_name ) : '',
			)
		);
		if ( ! empty( $parts ) ) {
			$user->display_name = implode( ' ', $parts );
			return;
		}
		// Fallback: usar email local-part o el current display_name si existe.
		$current = wp_get_current_user();
		if ( $current && $current->display_name ) {
			$user->display_name = $current->display_name;
			return;
		}
		$email = isset( $user->user_email ) ? (string) $user->user_email : '';
		if ( $email ) {
			$parts = explode( '@', $email );
			$user->display_name = $parts[0];
		}
	}

	/**
	 * Retira la acción Volver a comprar de las filas de pedidos.
	 *
	 * El flujo de una colección seriada conduce al tomo siguiente mediante colección,
	 * upsells y avisos de continuidad. La recompra del mismo tomo no forma parte de
	 * ese flujo. El filtro elimina order-again aunque WooCommerce lo vuelva a añadir
	 * entre sus acciones predeterminadas.
	 *
	 * @param array<string, array{url:string,name:string,aria-label?:string}> $actions Existing actions.
	 * @param \WC_Order $order  Order de la fila.
	 * @return array<string, array{url:string,name:string,aria-label?:string}>
	 */
	public function orders_actions( array $actions, $order ): array {
		unset( $actions['order-again'] );

		return $actions;
	}

	/**
	 * Pre-valida el RUT en edit-account antes de que wp_update_user persista cambios parciales (UX-25).
	 *
	 * WooCommerce Blocks valida contact fields recién en save_account_form_fields
	 * (hook woocommerce_save_account_details), que corre DESPUÉS de wp_update_user.
	 * Si el RUT es inválido o vacío, wp_update_user ya guardó first_name/last_name
	 * produciendo un guardado parcial silencioso.
	 *
	 * @param \WP_Error $errors  Errores acumulados de WC.
	 * @param \stdClass $user    Objeto usuario que se actualizaría.
	 */
	public function validate_account_rut( $errors, $user ): void {
		// Detectar submit del form de edit-account
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce ya verificado por WC_Form_Handler::save_account_details.
		if ( ! isset( $_POST['save_account_details'] ) && ! ( isset( $_POST['action'] ) && 'save_account_details' === $_POST['action'] ) ) {
			return;
		}

		$raw_rut = null;
		$keys = array( '_wc_other/akibara/rut', 'akibara/rut', 'billing_rut', 'rut' );
		foreach ( $keys as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ $k ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$raw_rut = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
				break;
			}
		}

		if ( null === $raw_rut ) {
			return; // No se envió campo de RUT en este submit
		}

		if ( '' === trim( $raw_rut ) ) {
			$errors->add( 'billing_rut_error', '<strong>RUT</strong> es un campo obligatorio.' );
			return;
		}

		if ( class_exists( '\Akibara\Business\Utilities\RutValidator' ) && ! \Akibara\Business\Utilities\RutValidator::is_valid( $raw_rut ) ) {
			$errors->add( 'billing_rut_error', 'El <strong>RUT</strong> ingresado no es válido. Revisa los dígitos y el dígito verificador.' );
		}
	}
}
