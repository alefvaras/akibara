<?php
/**
 * BrandedEmail — un `WC_Email` con identidad para los correos del programa.
 *
 * Por qué existe
 * --------------
 * `Points\Emails::send_branded()` utiliza `BrandedEmail` para dotar a los correos del programa
 * de un identificador `akb_rewards_<correo>` en los registros de WooCommerce (`WC_Email::send()`),
 * garantizando trazabilidad y observabilidad homologada con los correos nativos de la tienda.
 *
 * Con esta clase el registro reporta `akb_rewards_<correo>` y el envío pasa por `WC_Email::send()`,
 * que es la misma vía que usan los correos estándar del sistema.
 *
 * No tiene pantalla de ajustes (los del programa viven en Akibara → Puntos) ni
 * plantilla: el cuerpo ya viene envuelto y la parte plana ya viene derivada.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.4.15
 */
final class BrandedEmail extends \WC_Email {

	private string $plain = '';

	/** @var array<string, self> Una instancia por correo y petición. */
	private static array $instances = array();

	/**
	 * Una instancia por identificador evita duplicar los hooks que registra el
	 * constructor de WC_Email y que send() no retira.
	 *
	 * @param string $key   Identificador del correo ('order', 'expiry', 'birthday', 'review', 'review_invite', 'referral', 'admin_adjust', 'admin_alert').
	 * @param string $plain Parte plana ya derivada del HTML de ESTE envío.
	 * @return self
	 */
	public static function for( string $key, string $plain ): self {
		$key = sanitize_key( $key );
		if ( ! isset( self::$instances[ $key ] ) ) {
			self::$instances[ $key ] = new self( $key );
		}
		self::$instances[ $key ]->plain = $plain;

		return self::$instances[ $key ];
	}

	/**
	 * @param string $key Identificador ya saneado.
	 */
	private function __construct( string $key ) {
		$this->id    = 'akb_rewards_' . $key;
		$this->title = 'Puntos Akibara: ' . $key;

		parent::__construct();

		// Después del padre: el constructor lee `email_type` de una option que
		// no existe y sin esto el tipo cae a «plain» y el HTML saldría como texto.
		$this->email_type = 'multipart';
	}

	/**
	 * Sin ajustes propios: nada que guardar en WooCommerce → Correos.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	/**
	 * La parte plana. OJO: `WC_Email::handle_multipart()` NO la usa desde
	 * `send()` (sale porque `$this->sending` sólo se enciende en
	 * `get_content()`); la escribe el hook propio de `Emails::send_branded()`.
	 * Se expone igual por el contrato de `WC_Email`.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return $this->plain;
	}

	/**
	 * El cuerpo se pasa ya envuelto a `send()`; esta clase no renderiza plantillas.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return '';
	}
}
