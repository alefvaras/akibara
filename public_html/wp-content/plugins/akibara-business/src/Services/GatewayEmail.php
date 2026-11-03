<?php
/**
 * GatewayEmail — el `WC_Email` con identidad por el que sale todo correo ad-hoc
 * de {@see EmailTemplateGateway}.
 *
 * Por qué no `wc_mail()`: `WC_Emails::send()` instancia un `WC_Email` genérico
 * sin `id`. El registro de WooCommerce lo anota como «Email "" sent» (mismo
 * defecto que Rewards cerró con su BrandedEmail) y su
 * `email_type` cae a «plain», con lo que `style_inline()` no inlinea nada.
 *
 * Esta clase arregla las dos cosas y deja que `WC_Email::send()` haga, UNA sola
 * vez, el inline de CSS (Emogrifier), el filtro `woocommerce_mail_content`
 * (blindaje Gmail modo oscuro de EmailBrand) y la inyección de `AltBody` a
 * través del propio `WC_Email::handle_multipart()` de WooCommerce core, sin
 * agregar ningún hook `phpmailer_init` en Akibara Business.
 *
 * SÓLO se instancia después de `MailerBoot::ready()`: el `extends WC_Email`
 * revienta si se autocarga antes de que WooCommerce incluya la clase padre.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use WC_Email;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.0
 */
final class GatewayEmail extends WC_Email {

	/** @var array<string, self> Una instancia por identificador y petición. */
	private static array $instances = array();

	/** @var string Parte plana en vuelo para `WC_Email::handle_multipart()`. */
	private string $plain = '';

	/**
	 * Una instancia por identificador: el constructor de `WC_Email` cuelga hooks
	 * que `send()` no retira, así que instanciar uno por envío acumularía
	 * callbacks en un lote.
	 *
	 * @param string $key Identificador del correo (p. ej. 'bacs_reminder').
	 * @return self
	 */
	public static function for( string $key ): self {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			$key = 'generico';
		}
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $key );
		}

		return self::$instances[ $key ];
	}

	/**
	 * @param string $key Identificador ya saneado.
	 */
	private function __construct( string $key ) {
		$this->id    = 'akb_' . $key;
		$this->title = 'Akibara: ' . $key;

		parent::__construct();

		// Después del padre: el constructor lee `email_type` de una option que no
		// existe y el tipo caería a «plain» (HTML enviado como texto, sin inline).
		// Con `multipart`, `WC_Email::handle_multipart()` (registrado por el padre)
		// rellena `$mailer->AltBody` desde `get_content_plain()` cuando `$this->sending`
		// está encendido.
		$this->email_type = 'multipart';
	}

	/**
	 * Entrega el correo encendiendo `$this->sending` para que `WC_Email::handle_multipart()`
	 * adjunte la parte plana y limpiando estado en `finally`.
	 *
	 * @param string $to      Destinatario.
	 * @param string $subject Asunto.
	 * @param string $html    Cuerpo HTML ensamblado (sin inlinar).
	 * @param string $plain   Parte en texto plano derivada del HTML.
	 * @param string $headers Cabeceras adicionales separadas por CRLF.
	 * @return bool
	 */
	public function deliver( string $to, string $subject, string $html, string $plain, string $headers = '' ): bool {
		$this->plain   = $plain;
		$this->sending = true;

		try {
			return (bool) $this->send( $to, $subject, $html, $headers, array() );
		} finally {
			$this->sending = false;
			$this->plain   = '';
		}
	}

	/**
	 * Sin pantalla de ajustes: estos correos no se configuran desde WooCommerce.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = array();
	}

	/**
	 * El cuerpo llega ya ensamblado a `send()`; esta clase no renderiza plantillas.
	 *
	 * @return string
	 */
	public function get_content_html(): string {
		return '';
	}

	/**
	 * Parte en texto plano consumida por `WC_Email::handle_multipart()`.
	 *
	 * @return string
	 */
	public function get_content_plain(): string {
		return $this->plain;
	}
}
