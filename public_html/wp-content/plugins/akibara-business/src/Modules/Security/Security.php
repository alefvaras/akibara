<?php
/**
 * Protege los formularios públicos frente a enumeración de usuarios.
 *
 * En la recuperación de contraseña, ejecuta retrieve_password() para conservar
 * el envío legítimo y fuerza después la misma URL de confirmación para correos
 * existentes e inexistentes. Se engancha en wp_loaded antes del handler de
 * WooCommerce y conserva un mensaje de respuesta genérico.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Security;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.18.1
 */
final class Security {

	/**
	 * Acciones administrativas BlueX que el vendor protege solo con nonce.
	 *
	 * Los dos endpoints de integración también están registrados como nopriv
	 * en BlueX 3.2.2–3.2.3. Los handlers de tracking carecen igualmente de una
	 * comprobación de capacidad. Un nonce evita CSRF, pero no autoriza al actor.
	 *
	 * `clear_shipping_cache` no pertenece a esta lista: es un flujo público
	 * intencional del checkout/PUDO y opera sobre la sesión del comprador.
	 *
	 * @var string[]
	 */
	private const BLUEX_ADMIN_AJAX_ACTIONS = array(
		'test_correios_integration',
		'correios_autofill_addresses_empty_database',
		'validate_integration_is_active',
		'update_integration_credentials',
		'woocommerce_correios_add_tracking_code',
		'woocommerce_correios_remove_tracking_code',
	);

	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'intercept_lostpassword_post' ), 15 );
		add_filter( 'authenticate', array( $this, 'harden_login_errors' ), 99, 3 );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'harden_registration_errors' ), 99, 4 );

		// Anti user-enumeration: el REST endpoint `/wp/v2/users` y el query
		// `?author=N` exponen los usernames reales (slugs) a cualquier visitante,
		// facilitando fuerza bruta contra wp-login. Los cerramos para quien no
		// puede listar usuarios; los admins logueados conservan acceso.
		add_filter( 'rest_endpoints', array( $this, 'maybe_remove_user_endpoints' ) );
		add_filter( 'rest_endpoints', array( $this, 'protect_bluex_management_endpoints' ), 1000 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_bluex_no_store_headers' ), 1000, 3 );
		add_action( 'parse_request', array( $this, 'block_author_enumeration' ), 1 );

		foreach ( self::BLUEX_ADMIN_AJAX_ACTIONS as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'enforce_bluex_ajax_capability' ), -1000 );
			add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'enforce_bluex_ajax_capability' ), -1000 );
		}

		// BlueX 3.2.2–3.2.3 localiza un nonce REST correcto, pero su cliente React no lo
		// adjunta a las llamadas `/wc-bluex/v1/*`. El bridge corre antes del bundle
		// del plugin para que la UI oficial siga funcionando después de cerrar esas
		// rutas a visitantes anónimos.
		add_action( 'admin_print_footer_scripts', array( $this, 'add_bluex_rest_nonce_bridge' ), 1 );
	}

	/**
	 * Unifica los errores de autenticación que revelarían si existe una cuenta.
	 *
	 * Los cuatro códigos que distinguen usuario inexistente y contraseña incorrecta
	 * producen un mensaje genérico con acceso a restablecer la contraseña. Los campos
	 * vacíos conservan sus errores específicos porque no revelan cuentas. La prioridad
	 * 99 aplica esta proyección después de los autenticadores del resto del sistema.
	 *
	 * @param \WP_User|\WP_Error|null $user     Resultado de la autenticación hasta ahora.
	 * @param string                 $username Usuario o correo enviado.
	 * @param string                 $password Contraseña enviada.
	 * @return \WP_User|\WP_Error|null
	 */
	public function harden_login_errors( $user, $username, $password ) {
		unset( $username, $password );
		if ( ! is_wp_error( $user ) ) {
			return $user;
		}
		$leaky = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo' );
		if ( ! in_array( (string) $user->get_error_code(), $leaky, true ) ) {
			return $user;
		}
		$lost    = function_exists( 'wp_lostpassword_url' ) ? (string) wp_lostpassword_url() : '';
		$message = '<strong>Error:</strong> Los datos de acceso no coinciden. Revisa tu correo o usuario y tu contraseña';
		if ( '' !== $lost ) {
			$message .= ', o <a href="' . esc_url( $lost ) . '">restablece tu contraseña</a>';
		}
		$message .= '.';

		return new \WP_Error( 'akibara_login_failed', $message );
	}

	/**
	 * Anti user-enumeration en el ALTA de /mi-cuenta/ (CWE-203), el tercer lado
	 * del mismo triángulo que login y lost-password.
	 *
	 * El formulario de registro respondía «Ya existe una cuenta registrada con
	 * alejandro@ejemplo.cl. Inicia sesión o usa otra dirección» — es decir,
	 * confirmaba con nombre y apellido qué correos tienen cuenta, deshaciendo
	 * los dos arreglos ya desplegados: basta probar el alta para saber lo que el
	 * login y el recupero ya no cuentan. Lo mismo con el nombre de usuario, que
	 * este sitio pide en el formulario.
	 *
	 * DÓNDE se engancha, y por qué NO en `woocommerce_registration_errors`:
	 * `wc_create_new_customer()` (WooCommerce 11.0.1, wc-user-functions.php:61)
	 * hace `return new WP_Error( 'registration-error-email-exists',...)` ANTES
	 * de disparar ese filtro, así que ahí el error no pasa jamás. El único punto
	 * que corre antes del alta y sí es filtrable es
	 * `woocommerce_process_registration_errors` (class-wc-form-handler.php:1259):
	 * si devuelve un error, WC lanza con ese mensaje y nunca llega a
	 * `wc_create_new_customer()`. Por eso la comprobación se ADELANTA aquí.
	 *
	 * El alta legítima no se toca: sin correo ni usuario tomados, el WP_Error
	 * vuelve intacto y el flujo sigue igual.
	 *
	 * @param mixed  $errors   WP_Error acumulado por WooCommerce y otros plugins.
	 * @param string $username Nombre de usuario enviado (vacío si WC lo genera).
	 * @param string $password Contraseña enviada (no se usa).
	 * @param string $email    Correo enviado.
	 * @return mixed
	 */
	public function harden_registration_errors( $errors, $username, $password, $email ) {
		unset( $password );

		// Con un error de validación previo (correo inválido, contraseña vacía)
		// el alta ya se detiene y el mensaje no dice nada de la base: no se pisa.
		if ( ! is_wp_error( $errors ) || '' !== (string) $errors->get_error_code() ) {
			return $errors;
		}

		$email = sanitize_email( (string) $email );
		$taken = '' !== $email && is_email( $email ) && (bool) email_exists( $email );

		if ( ! $taken ) {
			$username = sanitize_user( (string) $username );
			$taken    = '' !== $username && (bool) username_exists( $username );
		}

		if ( ! $taken ) {
			return $errors;
		}

		$lost    = function_exists( 'wp_lostpassword_url' ) ? (string) wp_lostpassword_url() : '';
		$message = 'No pudimos crear la cuenta con esos datos. Si ya tienes cuenta, inicia sesión';
		if ( '' !== $lost ) {
			$message .= ' o <a href="' . esc_url( $lost ) . '">restablece tu contraseña</a>';
		}
		$message .= '.';

		$errors->add( 'akibara_registration_failed', $message );

		return $errors;
	}

	/**
	 * Sustituye los permission callbacks inseguros del namespace administrativo
	 * de BlueX sin editar el plugin de terceros.
	 *
	 * BlueX 3.2.2–3.2.3 registra lectura de settings/logs, escritura de credenciales y
	 * truncado de tablas con callbacks que retornan `true`. La protección se
	 * aplica a cada handler del namespace, incluidas rutas añadidas en upgrades,
	 * y deja intactos Store API, pricing, webhook y tracking.
	 *
	 * @param array<string, mixed> $endpoints Rutas registradas en REST.
	 * @return array<string, mixed>
	 */
	public function protect_bluex_management_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		foreach ( $endpoints as $route => $handlers ) {
			if ( ! is_string( $route ) || ! $this->is_bluex_management_route( $route ) || ! is_array( $handlers ) ) {
				continue;
			}

			foreach ( $handlers as $index => $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
					continue;
				}

				$endpoints[ $route ][ $index ]['permission_callback'] = array( $this, 'can_manage_bluex' );
			}
		}

		return $endpoints;
	}

	/**
	 * Impide que navegador, proxy o CDN almacenen respuestas administrativas.
	 *
	 * Cloudflare llegó a cachear `get-settings` públicamente porque el vendor no
	 * emitía directivas de caché. Se fijan políticas tanto para el navegador
	 * como para CDN en cada respuesta del namespace, incluidos 401/403 y el 200
	 * autenticado de la UI oficial.
	 *
	 * @param mixed $response Respuesta REST.
	 * @param mixed $server   Servidor REST (no usado).
	 * @param mixed $request  Request REST.
	 * @return mixed
	 */
	public function add_bluex_no_store_headers( $response, $server, $request ) {
		unset( $server );

		$route = is_object( $request ) && method_exists( $request, 'get_route' )
			? (string) $request->get_route()
			: '';
		if (
			! $this->is_bluex_management_route( $route )
			|| ! is_object( $response )
			|| ! method_exists( $response, 'header' )
		) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
		$response->header( 'Cloudflare-CDN-Cache-Control', 'no-store' );
		$response->header( 'CDN-Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * Exige autorización real antes de los callbacks AJAX administrativos.
	 *
	 * Corre en prioridad negativa para que un request anónimo o una cuenta de
	 * bajo privilegio termine en 403 antes del handler del vendor. Para un shop
	 * manager autorizado retorna y BlueX conserva sus validaciones de nonce.
	 */
	public function enforce_bluex_ajax_capability(): void {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'code'    => 'bluex_forbidden',
				'message' => 'No tienes permisos para administrar Blue Express.',
			),
			403
		);
	}

	/**
	 * Capacidad única para todas las operaciones administrativas BlueX.
	 */
	public function can_manage_bluex(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Comprueba el namespace administrativo exacto de BlueX.
	 */
	private function is_bluex_management_route( string $route ): bool {
		return '/wc-bluex/v1' === $route
			|| str_starts_with( $route, '/wc-bluex/v1/' );
	}

	/**
	 * Añade el nonce REST ya generado por BlueX a su cliente fetch.
	 *
	 * Se adjunta como inline `before` al handle oficial y solo interviene
	 * requests same-origin del namespace BlueX. No persiste ni expone la API key.
	 */
	public function add_bluex_rest_nonce_bridge(): void {
		$handle = 'correios-integration-admin';
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$script = <<<'JS'
(function () {
	if (window.__akibaraBluexRestAuth || typeof window.fetch !== 'function') {
		return;
	}
	var params = window.WCCorreiosIntegrationAdminParams || {};
	if (!params.rest_nonce) {
		return;
	}
	var nativeFetch = window.fetch.bind(window);
	window.fetch = function (input, init) {
		var rawUrl = typeof input === 'string' ? input : (input && input.url ? input.url : '');
		var url;
		try {
			url = new URL(rawUrl, window.location.href);
		} catch (error) {
			return nativeFetch(input, init);
		}
		if (url.origin !== window.location.origin || url.pathname.indexOf('/wp-json/wc-bluex/v1/') !== 0) {
			return nativeFetch(input, init);
		}
		var options = Object.assign({}, init || {});
		var sourceHeaders = options.headers || (typeof Request !== 'undefined' && input instanceof Request ? input.headers : undefined);
		var headers = new Headers(sourceHeaders || {});
		if (!headers.has('X-WP-Nonce')) {
			headers.set('X-WP-Nonce', params.rest_nonce);
		}
		options.headers = headers;
		return nativeFetch(input, options);
	};
	window.__akibaraBluexRestAuth = true;
}());
JS;

		wp_add_inline_script( $handle, $script, 'before' );
	}

	/**
	 * Quita las rutas REST de usuarios cuando el request no puede `list_users`.
	 *
	 * @param array<string, mixed> $endpoints Rutas registradas en la REST API.
	 * @return array<string, mixed>
	 */
	public function maybe_remove_user_endpoints( $endpoints ) {
		if ( ! is_array( $endpoints ) || current_user_can( 'list_users' ) ) {
			return $endpoints;
		}

		return $this->filter_user_endpoints( $endpoints );
	}

	/**
	 * Remueve las rutas `/wp/v2/users` y `/wp/v2/users/{id}` del set de
	 * endpoints. Método puro (sin WP) para poder testearlo en aislamiento.
	 *
	 * @param array<string, mixed> $endpoints
	 * @return array<string, mixed>
	 */
	public function filter_user_endpoints( array $endpoints ): array {
		unset(
			$endpoints['/wp/v2/users'],
			$endpoints['/wp/v2/users/(?P<id>[\d]+)']
		);

		return $endpoints;
	}

	/**
	 * Bloquea la enumeración de autores vía `?author=N` y `/author/{slug}/`.
	 * Para usuarios sin la capacidad `list_users` convierte la petición en un
	 * 404 real, sin crear una redirección SEO artesanal ni un soft-404 hacia la
	 * portada. Una cuenta WooCommerce no elude el control por iniciar sesión.
	 *
	 * @return void
	 */
	public function block_author_enumeration( $wp ): void {
		if ( is_admin() || current_user_can( 'list_users' ) ) {
			return;
		}
		if ( ! $wp instanceof \WP ) {
			return;
		}

		// `author_name` viene de la regla de reescritura de `/author/{slug}/`;
		// `author` cubre tanto el query var resuelto como `?author=N`.
		$has_author_archive = isset( $wp->query_vars['author_name'] )
			|| isset( $wp->query_vars['author'] );
		if ( ! $has_author_archive ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo se detecta la ruta, no se consume el valor.
			$author_query = isset( $_GET['author'] ) ? sanitize_text_field( wp_unslash( $_GET['author'] ) ) : null;
			$has_author_archive = null !== $author_query && is_numeric( $author_query );
		}
		if ( ! $has_author_archive ) {
			return;
		}

		unset( $wp->query_vars['author'], $wp->query_vars['author_name'] );
		$wp->query_vars['error'] = '404';
	}

	/**
	 * Intercepta el POST a /mi-cuenta/lost-password/ antes del handler de WC
	 * (que corre en wp_loaded priority 20). Ejecuta el handler real para que
	 * envíe email si el user existe, pero fuerza redirect al confirmation
	 * URL siempre. El cliente nunca distingue entre email registrado y no
	 * registrado.
	 *
	 * @return void
	 */
	public function intercept_lostpassword_post(): void {
		if ( ! isset( $_POST['wc_reset_password'], $_POST['user_login'] ) ) {
			return;
		}

		if ( ! class_exists( '\\WC_Shortcode_My_Account' ) ) {
			return;
		}

		// Nonce read: WC core usa `wc_get_var( $_REQUEST['x'],...)` que pasa
		// por referencia — pasar una expresión (`?? ''`) genera fatal en PHP 8
		// ("Argument #1 could not be passed by reference"). Usamos null
		// coalescing directo sobre $_REQUEST para evitarlo.
		$nonce_value = isset( $_REQUEST['woocommerce-lost-password-nonce'] )
			? sanitize_text_field( wp_unslash( $_REQUEST['woocommerce-lost-password-nonce'] ) )
			: ( isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '' );

		if ( ! wp_verify_nonce( $nonce_value, 'lost_password' ) ) {
			return;
		}

		// Validación obvia: si el campo está vacío, dejar que WC muestre el
		// "Enter a username or email address." (no es un leak de info).
		$login = sanitize_user( wp_unslash( (string) $_POST['user_login'] ) );
		if ( '' === $login ) {
			return;
		}

		// Ejecutar el handler real de WC — envía email si user existe, agrega
		// notice "Invalid username or email." (que ignoramos) si no existe.
		// Backslash prefix obligatorio: estamos en namespace local, sin él
		// PHP busca \Akibara\Business\Modules\Security\WC_Shortcode_My_Account.
		\WC_Shortcode_My_Account::retrieve_password();

		// Limpiar notices que WC pudo agregar — el confirmation page tiene su
		// propio mensaje genérico, no queremos el "Invalid username or email"
		// filtrándose por session entre redirects.
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

		// Redirect siempre al confirmation URL — mismo flow que WC usa para
		// success. El cliente ve la página de confirmation genérica sin
		// importar si el email estaba registrado.
		wp_safe_redirect(
			add_query_arg(
				'reset-link-sent',
				'true',
				wc_get_account_endpoint_url( 'lost-password' )
			)
		);
		exit;
	}
}
