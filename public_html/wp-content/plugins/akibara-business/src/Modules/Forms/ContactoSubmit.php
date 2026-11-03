<?php
/**
 * Endpoint REST y respaldo admin-post del formulario de contacto. Ambos
 * delegan en process() para validar, aplicar honeypot, límites y origen,
 * guardar ContactoLog antes del correo y señalar mensajes sin entregar.
 * No incrusta nonce por visitante en HTML cacheado. REST comprueba Origin;
 * el POST nativo usa Referer. Las redirecciones sólo transportan estado,
 * nunca nombre, dirección o mensaje por query string.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Forms;

use Akibara\Business\Services\NavigationLinks;
use Akibara\Business\Utilities\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class ContactoSubmit {

	private const REST_NAMESPACE = 'akibara/v1';
	private const REST_ROUTE     = '/contacto';

	/** Valor de `action` del `<form>` del tema → nombre de los hooks admin-post. */
	public const ADMIN_POST_ACTION = 'akb_contacto';

	/** Intentos por minuto y por IP, válidos o no (ver process()). */
	private const MAX_ATTEMPTS_PER_MINUTE = 20;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
				// El esquema REST y validate_callback explícito limitan tamaño antes del
				// handler. Los límites siguen el maxlength del formulario con holgura.
				// No usa required: los campos vacíos llegan como strings y se responden con
				// el error propio en español, sin delegarlo al genérico de WordPress.
				'args'                => array(
					'nombre'  => array(
						'type'              => 'string',
						'maxLength'         => 120,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'email'   => array(
						'type'              => 'string',
						'maxLength'         => 120,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'mensaje' => array(
						'type'              => 'string',
						'maxLength'         => 4000,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin ) {
			$origin = (string) $request->get_header( 'referer' );
		}

		$resultado = $this->process( $request->get_params(), $origin );

		if ( ! $resultado['ok'] ) {
			return new WP_Error( $resultado['code'], $resultado['message'], array( 'status' => $resultado['status'] ) );
		}

		$payload = array( 'ok' => true );
		if ( '' !== $resultado['message'] ) {
			$payload['message'] = $resultado['message'];
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Respaldo sin JavaScript: el `<form>` del tema hace un submit NATIVO a
	 * `admin-post.php` (`action=akb_contacto`) cuando el JS no llegó a
	 * registrar su `preventDefault()`. Registrado desde `Forms::register()`
	 * en `admin_post_akb_contacto` y `admin_post_nopriv_akb_contacto` — el
	 * mismo handler para invitado y logueado, porque el formulario de contacto
	 * no distingue sesión.
	 *
	 * Termina SIEMPRE en `wp_safe_redirect()` + `exit`: nunca hay datos del
	 * cliente en la URL de vuelta, sólo `?akb_contacto=ok|error`, que
	 * `ContactoForm::render()` traduce a un mensaje ANTES del include de la
	 * plantilla (contrato documentado en `template-parts/forms/contacto.php`
	 * del tema).
	 *
	 * @return void
	 */
	public function handle_admin_post(): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_exit, Squiz.PHP.DiscouragedFunctions.Discouraged -- salida obligatoria de un handler admin-post: sin ella WordPress sigue ejecutando el resto de la petición sobre una respuesta ya redirigida.
		wp_safe_redirect( $this->admin_post_redirect_url() );
		exit;
	}

	/**
	 * La URL de vuelta tras el submit nativo, YA con `?akb_contacto=ok|error`.
	 * Aparte de `handle_admin_post()` para poder fijarla con una prueba sin
	 * arrastrar el `exit` del método público.
	 *
	 * @return string
	 */
	private function admin_post_redirect_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- sin nonce a propósito (ver docblock de clase): el HTML se cachea 10 min para todo invitado y un nonce por usuario no sirve. Las defensas son honeypot + rate limit + referer, igual que en el REST.
		$params = wp_unslash( $_POST );
		$params = is_array( $params ) ? $params : array();

		$referer   = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$resultado = $this->process( $params, $referer );

		$estado      = $resultado['ok'] ? 'ok' : 'error';
		$redirect_to = $this->safe_redirect_target();

		return (string) add_query_arg( 'akb_contacto', $estado, $redirect_to );
	}

	/**
	 * `wp_get_referer()` de vuelta si es del propio sitio; si no (falta,
	 * cross-site, o el visitante llegó sin referer), la página de Contacto.
	 * Nunca se redirige a una URL ajena.
	 *
	 * @return string
	 */
	private function safe_redirect_target(): string {
		$referer = (string) wp_get_referer();

		return $this->same_site_host( $referer ) ? $referer : NavigationLinks::contact_url();
	}

	/**
	 * Núcleo del envío — ÚNICA implementación para el REST (`handle()`) y el
	 * respaldo sin JS (`handle_admin_post()`). Cada llamador sólo aporta sus
	 * parámetros ya en bruto y la señal de origen que tenga disponible
	 * (cabecera Origin en REST, Referer del navegador en el respaldo).
	 *
	 * @param array<string, mixed> $params        Parámetros crudos: nombre, email, mensaje, honeypot.
	 * @param string               $origin_source Origin o Referer disponible para el chequeo de origen.
	 * @return array{ok:bool,code:string,message:string,status:int}
	 */
	private function process( array $params, string $origin_source ): array {
		// Limita intentos antes de validar para acotar CPU frente a payloads
		// malformados. La cuota de envíos válidos usa un bucket independiente.
		if ( RateLimit::per_minute( 'contacto_attempt', self::MAX_ATTEMPTS_PER_MINUTE ) ) {
			return $this->fallo( 'rate_limited', 'Demasiados intentos. Espera un momento e intenta de nuevo.', 429 );
		}

		if ( Honeypot::tripped( $params ) ) {
			// Silencioso a propósito: un bot que dispara el honeypot no debe
			// aprender que falló. La misma respuesta "éxito" que ve un humano.
			return $this->exito( '' );
		}

		if ( ! $this->origin_allowed( $origin_source ) ) {
			return $this->fallo( 'bad_origin', 'Origen no permitido.', 403 );
		}

		$nombre = sanitize_text_field( (string) ( $params['nombre'] ?? '' ) );
		// El crudo se guarda SÓLO para saber si el campo venía vacío. Nunca se
		// escribe, se envía ni se muestra: para eso está `$email` saneado.
		//
		// POR QUÉ HACE FALTA: `sanitize_email()` VACÍA un correo mal formado, así
		// que «juan@correo» llegaba como '' y se lo comía la comprobación de
		// «faltan campos» — con los tres campos llenos. El `invalid_email` de
		// abajo era código inalcanzable, y quien se equivocaba tecleando su
		// correo leía el aviso genérico de campos faltantes, no encontraba qué
		// faltaba y se iba. Del otro lado se ve como «no llegó ningún mensaje».
		$email_crudo = trim( (string) ( $params['email'] ?? '' ) );
		$email       = sanitize_email( $email_crudo );
		$mensaje     = sanitize_textarea_field( (string) ( $params['mensaje'] ?? '' ) );

		if ( '' === $nombre || '' === $email_crudo || '' === $mensaje ) {
			return $this->fallo( 'missing_fields', 'Nombre, correo y mensaje son requeridos.', 400 );
		}

		// La tercera condición es la que cierra el agujero: `sanitize_email()` no
		// sólo VACÍA lo que está mal formado, también REESCRIBE en silencio lo que
		// cree poder arreglar —le quita los espacios de en medio, las tildes y la ñ—
		// y devuelve una dirección DISTINTA pero válida. Así «juan perez@gmail.com»
		// se guardaba como «juanperez@gmail.com»: el cliente leía «Mensaje recibido»
		// y la respuesta se iba al buzón de otra persona, que además pasaba a leer
		// una consulta que no es suya. El formulario lleva `novalidate`, así que el
		// navegador tampoco lo avisa: esta es la única puerta. Si sanear cambió la
		// dirección, se rechaza en vez de suplantarla.
		if ( '' === $email || ! is_email( $email ) || $email !== $email_crudo ) {
			return $this->fallo( 'invalid_email', 'Email inválido.', 400 );
		}

		// Rate-limit recién acá: solo los envíos válidos (los que se van a notificar)
		// consumen el cupo. Antes corría antes de validar, así que un payload
		// malformado incrementaba el contador y gastaba la cuota igual.
		if ( RateLimit::exceeded( 'contacto' ) ) {
			return $this->fallo( 'rate_limited', 'Demasiados envíos. Intenta de nuevo en una hora.', 429 );
		}

		// Se guarda ANTES de intentar el correo. `wp_mail()` es la parte frágil
		// de esta ruta —en agosto de 2026 el sitio estuvo tres días sin poder
		// enviar ninguno— y el orden inverso significaba perder el mensaje del
		// cliente sin que quedara rastro para nadie.
		$entry_id = ContactoLog::record( $nombre, $email, $mensaje );
		$mailed   = $this->notify( $nombre, $email, $mensaje );

		if ( $mailed ) {
			ContactoLog::mark_mailed( $entry_id );
		}

		if ( ! $mailed && '' === $entry_id ) {
			// Ni correo ni bitácora: es el único caso en que el mensaje de
			// verdad se perdió, y el único en que corresponde decírselo.
			return $this->fallo( 'notification_failed', 'No pudimos enviar tu mensaje. Escríbenos a contacto@akibara.cl.', 503 );
		}

		return $this->exito( 'Mensaje recibido. Te respondemos a la brevedad.' );
	}

	/**
	 * @return array{ok:bool,code:string,message:string,status:int}
	 */
	private function exito( string $mensaje ): array {
		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => $mensaje,
			'status'  => 200,
		);
	}

	/**
	 * @return array{ok:bool,code:string,message:string,status:int}
	 */
	private function fallo( string $code, string $mensaje, int $status ): array {
		return array(
			'ok'      => false,
			'code'    => $code,
			'message' => $mensaje,
			'status'  => $status,
		);
	}

	/**
	 * Chequeo "suave" de origen para un endpoint público: si hay una señal de
	 * origen (Origin en REST, Referer en el respaldo sin JS) y su host NO
	 * coincide con el del sitio, se rechaza. Sin ninguna señal (clientes
	 * legítimos que no la mandan), se permite — el endpoint sigue siendo
	 * público por diseño. Reduce el abuso cross-origin sin exigir nonce (que
	 * rompería los envíos anónimos válidos, y con el HTML cacheado 10 min por
	 * Cloudflare, un nonce por usuario tampoco serviría).
	 */
	private function origin_allowed( string $origin ): bool {
		if ( '' === $origin ) {
			return true; // Sin señal de origen → no bloquear (público).
		}

		return $this->same_site_host( $origin );
	}

	/**
	 * ¿El host de esta URL es el del propio sitio? Fuente única para las dos
	 * preguntas que necesitan la misma comparación: si un origen/referer es de
	 * confianza (`origin_allowed()`) y si un referer es seguro para redirigir
	 * de vuelta (`safe_redirect_target()`).
	 */
	private function same_site_host( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		$host      = wp_parse_url( $url, PHP_URL_HOST );
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $site_host )
			&& strtolower( $host ) === strtolower( $site_host );
	}

	private function notify( string $nombre, string $email, string $mensaje ): bool {
		// Notificación operativa al owner vía wp_mail(); el proveedor activo del
		// sitio administra la entrega fuera de Akibara Business.
		// `From: Akibara <contacto@akibara.cl>` lo aplica el módulo MailFrom
		// global (filters wp_mail_from / wp_mail_from_name) — no hardcodear acá.
		$body  = '<h2>Nuevo mensaje de contacto</h2>';
		$body .= sprintf( '<p><strong>De:</strong> %s &lt;%s&gt;</p>', esc_html( $nombre ), esc_html( $email ) );
		$body .= sprintf( '<p><strong>Mensaje:</strong></p><blockquote>%s</blockquote>', nl2br( esc_html( $mensaje ) ) );

		// Sanea coma y punto y coma del display name: wp_mail separa Reply-To por
		// coma y esos caracteres pueden introducir destinatarios. El saneo de texto
		// por sí solo sólo cubre otros separadores como CRLF y signos de etiqueta.
		$reply_name = trim( str_replace( array( ',', ';', ':', '<', '>', '"' ), ' ', $nombre ) );
		$reply_to   = '' === $reply_name
			? sprintf( 'Reply-To: %s', $email )
			: sprintf( 'Reply-To: %s <%s>', $reply_name, $email );

		return wp_mail(
			Settings::get_notify_email(),
			'Contacto: ' . wp_trim_words( $mensaje, 8, '...' ),
			$body,
			array(
				'Content-Type: text/html; charset=UTF-8',
				$reply_to,
			)
		);
	}
}
