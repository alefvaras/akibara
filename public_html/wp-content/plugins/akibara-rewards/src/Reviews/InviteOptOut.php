<?php
/**
 * InviteOptOut — la salida del cliente que no quiere que le pidan reseñas.
 *
 * La preferencia del cliente se aplica a las invitaciones para reseñar. Los
 * avisos transaccionales del pedido y del saldo conservan su propio flujo.
 *
 * Decisiones que explican la forma de este fichero:
 *
 *   - **La baja es por CORREO, no por cuenta.** La mitad de los pedidos son de
 *     invitado; una preferencia guardada en `user_meta` no existiría para
 *     ellos. La lista guarda el SHA-256 del correo normalizado: basta para
 *     reconocer a quien pidió la baja y no deja una lista de direcciones
 *     legible en la tabla de options.
 *   - **El enlace no da de baja por sí solo.** Un GET que cambia estado lo
 *     dispara cualquier prefetch de cliente de correo o antivirus de enlaces:
 *     el enlace abre una página con un botón, y la baja viaja por POST. La
 *     excepción es el «un clic» del RFC 8058, que Gmail manda como POST con
 *     su propio campo y por eso sí se atiende de inmediato.
 *   - **Nunca es un callejón.** La misma página deja volver a activarlas: una
 *     baja por error sin vuelta atrás obliga a escribir, que es justo lo que
 *     este fichero vino a evitar.
 *
 * El enlace lleva el número del pedido y una firma HMAC de ese número con la
 * sal del sitio. La dirección se resuelve desde el pedido al validar, por lo
 * que corregir su correo no invalida un enlace ya enviado. No reutiliza el
 * token del formulario de reseña:
 *
 *   - El token de reseña expira y se purga con el tiempo; la firma HMAC es permanente
 *     y garantiza que la desuscripción funcione indefinidamente.
 *   - Principio de mínimo privilegio: la cabecera `List-Unsubscribe` es leída por
 *     filtros antispam automáticos y no debe otorgar permisos para enviar reseñas ni acreditar puntos.
 *
 * La firma no vive en ninguna tabla (no caduca ni se purga), no abre nada más
 * que esta página y no expone el correo. Deja de valer en dos casos, y en los
 * dos la página responde 404 con el contacto a mano: si el sitio rota sus
 * sales, o si el pedido ya no existe.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Support\PrivatePage;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.9
 */
final class InviteOptOut {

	public const QUERY_VAR = 'akb_rw_baja';

	/**
	 * Lista de bajas: array<string hash, int timestamp>.
	 */
	private const OPTION = 'akb_rewards_invite_optout';

	/**
	 * Enlace de baja para el pedido que recibe la invitación.
	 *
	 * Query string y no una ruta bonita a propósito: una `rewrite rule` nueva
	 * necesita un flush que un despliegue por ZIP no dispara, y la baja daría
	 * 404 justo en el correo que la ofrece.
	 *
	 * @param \WC_Order $order Pedido invitado.
	 * @return string '' si el pedido no tiene correo.
	 */
	public static function url_for( \WC_Order $order ): string {
		$ref = self::ref_for( (int) $order->get_id(), (string) $order->get_billing_email() );

		// Bajo la URL de Mi cuenta y no en la portada: el borde de la tienda
		// cachea diez minutos cualquier HTML de invitado aunque diga
		// `no-store`, y esa ruta es de las que deja pasar. Ver PrivatePage.
		return '' === $ref ? '' : add_query_arg( self::QUERY_VAR, $ref, PrivatePage::base_url() );
	}

	/**
	 * «<pedido>-<firma>»: lo que viaja en el enlace.
	 *
	 * @param int    $order_id Pedido.
	 * @param string $email    Correo de facturación del pedido.
	 * @return string '' si falta alguno de los dos.
	 */
	private static function ref_for( int $order_id, string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( $order_id <= 0 || '' === $email ) {
			return '';
		}

		// La firma permanece válida al corregir el correo de facturación;
		// la validación obtiene la dirección actual desde el pedido firmado.
		return $order_id . '-' . substr( hash_hmac( 'sha256', 'akb_rw_baja|' . $order_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * ¿Este correo pidió no recibir más invitaciones?
	 *
	 * @param string $email Dirección del pedido.
	 * @return bool
	 */
	public static function opted_out( string $email ): bool {
		$clave = self::clave( $email );

		return '' !== $clave && isset( self::lista()[ $clave ] );
	}

	/**
	 * Cuántos clientes pidieron la baja (para el panel del dueño).
	 *
	 * @return int
	 */
	public static function total(): int {
		return count( self::lista() );
	}

	/**
	 * Cuelga la página pública.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Prioridad 1, como las demás bajas de la tienda: antes de que la
		// página de Mi cuenta decida pintar su formulario de acceso.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
	}

	/**
	 * @param array<int,string> $vars Query vars públicas.
	 * @return array<int,string>
	 */
	public function register_query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Pinta la página de baja y termina la petición.
	 *
	 * Sólo la cáscara con efectos: el `exit` no deja probar nada, así que la
	 * decisión entera vive en `responder()`.
	 *
	 * @return void
	 */
	public function maybe_render(): void {
		$token = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}

		PrivatePage::no_cache( 'Akibara Rewards: preferencias de correo' );

		$metodo = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- el token del enlace es el secreto y viaja en el propio POST; ver responder().
		$post = (array) wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$respuesta = self::responder( $token, $metodo, $post );

		// Un enlace que no se reconoce NO puede responder 200: el «Cancelar
		// suscripción» de Gmail es un POST sin pantalla, lee cualquier 2xx como
		// «hecho» y se lo dice al cliente — que seguiría recibiendo correos
		// creyendo que ya se dio de baja.
		status_header( $respuesta['status'] );

		echo $respuesta['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML propio, escapado por partes.
		exit;
	}

	/**
	 * La página entera para un token, un método y un POST dados.
	 *
	 * El token ES el secreto del enlace y viaja en el cuerpo del POST; un nonce
	 * de invitado no añadiría nada (depende de una sesión que un comprador sin
	 * cuenta no tiene) y sí rompería la baja de quien abre el correo semanas
	 * después.
	 *
	 * @param string              $token  Token del enlace.
	 * @param string              $metodo Método HTTP.
	 * @param array<string,mixed> $post   Cuerpo del POST.
	 * @return array{status:int,html:string} Código HTTP y página completa.
	 */
	public static function responder( string $token, string $metodo, array $post = array() ): array {
		$titulo = 'Preferencias de correo — Akibara';
		$email  = self::email_of_ref( $token );

		if ( '' === $email ) {
			// Enlace inválido: se dice sin filtrar si el pedido existe.
			return array(
				'status' => 404,
				'html'   => ReviewForm::shell( self::cuerpo_invalido(), $titulo ),
			);
		}

		if ( 'POST' !== strtoupper( $metodo ) ) {
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_confirmar( $token, self::opted_out( $email ) ), $titulo ),
			);
		}

		$accion = isset( $post['akb_rw_pref'] ) ? sanitize_key( (string) $post['akb_rw_pref'] ) : '';

		// «Un clic» del RFC 8058: Gmail y Outlook mandan este POST sin abrir la
		// página, y el correo lo anuncia en su cabecera List-Unsubscribe-Post.
		if ( isset( $post['List-Unsubscribe'] ) ) {
			$accion = 'baja';
		}

		if ( 'alta' === $accion ) {
			self::alta( $email );
			return array(
				'status' => 200,
				'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, false ), $titulo ),
			);
		}

		self::baja( $email );

		return array(
			'status' => 200,
			'html'   => ReviewForm::shell( self::cuerpo_hecho( $token, true ), $titulo ),
		);
	}

	/**
	 * Correo del pedido al que pertenece un enlace, si la firma es la suya.
	 *
	 * @param string $ref «<pedido>-<firma>» del enlace.
	 * @return string '' si el enlace no es válido o el pedido ya no está.
	 */
	private static function email_of_ref( string $ref ): string {
		if ( 1 !== preg_match( '/^(\d{1,12})-([a-f0-9]{32})$/', $ref, $m ) || ! function_exists( 'wc_get_order' ) ) {
			return '';
		}

		$order = wc_get_order( (int) $m[1] );
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		$email = (string) $order->get_billing_email();

		// Comparación en tiempo constante contra la firma recalculada: quien
		// conoce un número de pedido no puede dar de baja a su dueño.
		return hash_equals( self::ref_for( (int) $m[1], $email ), $ref ) ? $email : '';
	}

	/**
	 * Huella estable de una dirección.
	 *
	 * @param string $email Dirección.
	 * @return string '' si no hay dirección.
	 */
	private static function clave( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : hash( 'sha256', $email );
	}

	/**
	 * @return array<string,int>
	 */
	private static function lista(): array {
		$lista = get_option( self::OPTION, array() );

		return is_array( $lista ) ? $lista : array();
	}

	/**
	 * @param string $email Dirección que pide la baja.
	 * @return void
	 */
	private static function baja( string $email ): void {
		$clave = self::clave( $email );
		if ( '' === $clave ) {
			return;
		}

		$lista           = self::lista();
		$lista[ $clave ] = time();

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $email Dirección que vuelve a aceptarlas.
	 * @return void
	 */
	private static function alta( string $email ): void {
		$clave = self::clave( $email );
		$lista = self::lista();

		if ( '' === $clave || ! isset( $lista[ $clave ] ) ) {
			return;
		}

		unset( $lista[ $clave ] );

		update_option( self::OPTION, $lista, false );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si el botón da de baja (o vuelve a activar).
	 * @return string
	 */
	private static function formulario( string $token, bool $baja ): string {
		return sprintf(
			'<form method="post" style="margin:16px 0 0">
				<input type="hidden" name="akb_rw_pref" value="%s">
				<input type="hidden" name="%s" value="%s">
				<button type="submit">%s</button>
			</form>',
			$baja ? 'baja' : 'alta',
			esc_attr( self::QUERY_VAR ),
			esc_attr( $token ),
			$baja ? 'No quiero recibirlas' : 'Volver a recibirlas'
		);
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $ya    Si ya estaba dado de baja.
	 * @return string
	 */
	private static function cuerpo_confirmar( string $token, bool $ya ): string {
		if ( $ya ) {
			return '<h1>Ya no te pedimos reseñas</h1>'
				. '<p>No vamos a enviarte más invitaciones a reseñar. Los correos de tus pedidos y de tus Puntos Akibara siguen llegando: son parte de la compra.</p>'
				. self::formulario( $token, false );
		}

		return '<h1>¿Dejamos de pedirte reseñas?</h1>'
			. '<p>Puedes decirnos que no te enviemos más invitaciones a reseñar tus compras. Los correos de tus pedidos y de tus Puntos Akibara seguirán llegando: son parte de la compra.</p>'
			. self::formulario( $token, true );
	}

	/**
	 * @param string $token Token del enlace.
	 * @param bool   $baja  Si acaba de darse de baja.
	 * @return string
	 */
	private static function cuerpo_hecho( string $token, bool $baja ): string {
		if ( $baja ) {
			return '<h1>Listo</h1>'
				. '<p>No volveremos a pedirte reseñas. Si fue sin querer, puedes revertirlo aquí mismo.</p>'
				. self::formulario( $token, false )
				. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
		}

		return '<h1>Volvimos a activarlas</h1>'
			. '<p>Te escribiremos de nuevo después de tus próximos pedidos para pedirte tu opinión.</p>'
			. self::formulario( $token, true )
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}

	/**
	 * @return string
	 */
	private static function cuerpo_invalido(): string {
		return '<h1>Ese enlace ya no sirve</h1>'
			. '<p class="akb-rf-aviso">No pudimos reconocer el enlace. Escríbenos a <a href="mailto:contacto@akibara.cl" style="color:#f5f5f5">contacto@akibara.cl</a> y lo hacemos nosotros.</p>'
			. sprintf( '<p class="akb-rf-nota" style="margin-top:24px"><a href="%s" style="color:#f5f5f5">Volver a la tienda</a></p>', esc_url( home_url( '/' ) ) );
	}
}
