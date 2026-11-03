<?php
/**
 * Google Login Button — coordina la UI de Mi Cuenta.
 *
 *   - Override del template `woocommerce/myaccount/form-login.php`
 *     servido desde el plugin (`templates/myaccount/form-login.php`).
 *     Layout unificado con Google primario + dos disclosure
 *     (`<details>`) para los flujos de correo/contraseña.
 *
 * Checkout pertenece al bloque oficial `google-login/login-button`, insertado
 * como inner block por Akibara Cart and Checkout Blocks.
 *
 * Si el theme decide overridear `myaccount/form-login.php` por su
 * cuenta, gana el theme — el filtro sólo entra cuando WC iba a servir
 * el template por defecto.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\GoogleLoginButton;

defined( 'ABSPATH' ) || exit;

final class GoogleLoginButton {

	public function register(): void {
		add_filter( 'woocommerce_locate_template', array( $this, 'override_form_login' ), 10, 3 );
		add_action( 'woocommerce_login_form_end', array( $this, 'drop_unvalidated_redirect_field' ), 1 );
		add_filter( 'rtcamp.google_default_redirect', array( $this, 'google_returns_where_the_customer_came_from' ) );
		add_action( 'rtcamp.google_user_created', array( $this, 'on_google_user_created' ), 10, 2 );
		add_action( 'rtcamp.google_user_authenticated', array( $this, 'on_google_user_authenticated' ), 10, 1 );
	}

	/**
	 * El botón Google comparte el destino saneado de los formularios del muro
	 * y preserva checkout cuando corresponde. El filtro oficial de login-with-google
	 * evita que su state OAuth use la URL del muro como destino por defecto.
	 *
	 * @param mixed $default Destino que traía login-with-google.
	 * @return mixed
	 */
	public function google_returns_where_the_customer_came_from( $default ) {
		$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '';
		if ( '' !== $checkout_url ) {
			// Si el cliente inicia el flujo desde /finalizar-compra/ (o el destino
			// apunta al checkout), no desviarlo hacia /mi-cuenta/ en medio del pago.
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				return $checkout_url;
			}
			if ( is_string( $default ) && 0 === strpos( $default, $checkout_url ) ) {
				return $default;
			}
			$destino_crudo = AccessWall::redirect_target();
			if ( '' !== $destino_crudo && 0 === strpos( $destino_crudo, $checkout_url ) ) {
				return $destino_crudo;
			}
		}

		$destino = AccessWall::redirect_field_value( AccessWall::current_endpoint() );

		return '' !== $destino ? $destino : $default;
	}

	/**
	 * Sincroniza datos de facturación en WC_Customer cuando un nuevo usuario se crea vía Google.
	 *
	 * @param int       $uid  ID de usuario recién insertado.
	 * @param \stdClass $user Datos de usuario entregados por la API de Google.
	 */
	public function on_google_user_created( int $uid, $user ): void {
		if ( $uid <= 0 || ! class_exists( '\WC_Customer' ) ) {
			return;
		}

		try {
			$customer = new \WC_Customer( $uid );
			$changed  = false;

			$first_name = is_object( $user ) && ! empty( $user->given_name ) ? (string) $user->given_name : '';
			$last_name  = is_object( $user ) && ! empty( $user->family_name ) ? (string) $user->family_name : '';
			$email      = is_object( $user ) && ! empty( $user->email ) ? (string) $user->email : '';

			if ( '' !== $first_name && empty( $customer->get_billing_first_name() ) ) {
				$customer->set_billing_first_name( sanitize_text_field( $first_name ) );
				$changed = true;
			}
			if ( '' !== $last_name && empty( $customer->get_billing_last_name() ) ) {
				$customer->set_billing_last_name( sanitize_text_field( $last_name ) );
				$changed = true;
			}
			if ( '' !== $email && empty( $customer->get_billing_email() ) ) {
				$customer->set_billing_email( sanitize_email( $email ) );
				$changed = true;
			}

			if ( $changed ) {
				$customer->save();
			}
		} catch ( \Throwable $e ) {
			// Fail-safe: si WC_Customer falla, no interrumpir la creación de la cuenta.
		}

		$this->auto_verify_google_user( $uid );
	}

	/**
	 * Auto-verifica el correo y vincula pedidos pasados para usuarios que inician sesión con Google OAuth.
	 *
	 * Google garantiza email_verified === true antes de autenticar. Activar _wc_email_verified
	 * y disparar woocommerce_customer_email_verified vincula sus pedidos de invitado legítimos
	 * sin exponer al sistema a IDOR.
	 *
	 * @param mixed $user Usuario autenticado (WP_User).
	 */
	public function on_google_user_authenticated( $user ): void {
		if ( ! $user instanceof \WP_User || $user->ID <= 0 ) {
			return;
		}

		$this->auto_verify_google_user( (int) $user->ID );
	}

	/**
	 * Marca el correo verificado en WooCommerce y dispara la vinculación oficial de pedidos.
	 *
	 * @param int $user_id ID del usuario.
	 */
	public function auto_verify_google_user( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User || empty( $user->user_email ) ) {
			return;
		}

		$account_email = strtolower( trim( (string) $user->user_email ) );

		// En WooCommerce 11.x, el estado verificado se almacena en `_wc_email_verified`
		// conteniendo el correo en minúsculas. Si ya está verificado para este email, no-op.
		$current_verified = (string) get_user_meta( $user_id, '_wc_email_verified', true );
		if ( '' !== $current_verified && $current_verified === $account_email ) {
			return;
		}

		// Delegar en EmailVerificationService si el contenedor de WooCommerce está disponible.
		$handled = false;
		if ( function_exists( 'wc_get_container' ) ) {
			try {
				$container     = wc_get_container();
				$service_class = 'Automattic\\WooCommerce\\Internal\\CustomerEmailVerification\\EmailVerificationService';
				if ( is_object( $container ) && method_exists( $container, 'has' ) && method_exists( $container, 'get' ) && $container->has( $service_class ) ) {
					$service = $container->get( $service_class );
					if ( is_object( $service ) && method_exists( $service, 'mark_verified' ) ) {
						$service->mark_verified( $user_id );
						$handled = true;
					}
				}
			} catch ( \Throwable $e ) {
				$handled = false;
			}
		}

		if ( ! $handled ) {
			update_user_meta( $user_id, '_wc_email_verified', $account_email );
			delete_user_meta( $user_id, '_wc_email_verification_key' );
			do_action( 'woocommerce_customer_email_verified', $user_id );
		}
	}

	/**
	 * Retira el campo de redirección que WooCommerce Blocks añade al final del
	 * formulario de acceso.
	 *
	 * `BlockTypesController::redirect_to_field()` echa `<input name="redirect">`
	 * con el `redirect_to` de la URL **tal cual**, sin comprobar a dónde apunta,
	 * y lo hace SÓLO en el formulario de iniciar sesión: el de crear cuenta se
	 * quedaba sin destino y caía al `Referer`, que en un envío desde el muro es
	 * el muro mismo. Así que el mismo cliente volvía o no volvía a su tomo según
	 * el botón que pulsara.
	 *
	 * La plantilla del muro imprime ese campo en los DOS formularios, con el
	 * destino ya filtrado por {@see AccessWall::redirect_field_value()}. Si se
	 * dejara también el de Blocks, en el formulario de acceso habría dos campos
	 * con el mismo nombre y ganaría el suyo —el que no valida nada—, así que el
	 * filtro no serviría de nada.
	 *
	 * Se quita por hook y por identidad del objeto, nunca editando su código.
	 */
	public function drop_unvalidated_redirect_field(): void {
		global $wp_filter;

		if ( ! isset( $wp_filter['woocommerce_login_form_end'] ) ) {
			return;
		}

		foreach ( $wp_filter['woocommerce_login_form_end']->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'] ?? null;
				if ( ! is_array( $fn ) || ! is_object( $fn[0] ) || 'redirect_to_field' !== ( $fn[1] ?? '' ) ) {
					continue;
				}
				if ( false === strpos( get_class( $fn[0] ), 'Automattic\\WooCommerce\\Blocks\\' ) ) {
					continue;
				}
				remove_action( 'woocommerce_login_form_end', $fn, (int) $priority );
			}
		}
	}

	/**
	 * Filter `woocommerce_locate_template`: si WC iba a servir su
	 * template por defecto de `myaccount/form-login.php`, lo
	 * reemplazamos por el del plugin.
	 *
	 * @param string $template      Path absoluto al template resuelto por WC.
	 * @param string $template_name Nombre relativo (`myaccount/form-login.php`).
	 * @param string $template_path Path base donde WC busca templates.
	 * @return string
	 */
	public function override_form_login( string $template, string $template_name, string $template_path ): string {
		if ( 'myaccount/form-login.php' !== $template_name ) {
			return $template;
		}

		// Si el theme ya hizo override, lo dejamos pasar — el theme manda.
		$wc_default_dir = defined( 'WC_ABSPATH' ) ? WC_ABSPATH . 'templates/' : '';
		if ( '' === $wc_default_dir || 0 !== strpos( $template, $wc_default_dir ) ) {
			return $template;
		}

		$plugin_template = AKIBARA_BUSINESS_DIR . 'templates/myaccount/form-login.php';
		return file_exists( $plugin_template ) ? $plugin_template : $template;
	}
}
