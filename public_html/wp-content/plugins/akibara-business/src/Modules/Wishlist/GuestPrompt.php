<?php
/**
 * GuestPrompt — lo que ve quien toca el corazón SIN haber iniciado sesión.
 *
 *. Hasta aquí el corazón de un invitado
 * navegaba directo a /mi-cuenta/: el 100 % del tráfico orgánico llega sin sesión,
 * y las grabaciones de Clarity muestran el rebote de vuelta a la ficha en 2-4 s,
 * sin cuentas creadas. Con el aviso encendido, abre uno en la misma página
 * —«Inicia sesión para guardar tus favoritos»— con el acceso a un toque y
 * «Seguir mirando».
 *
 * La decisión escrita en `Button.php` se mantiene: el corazón sigue siendo el
 * punto de captación de cuenta. Lo que cambia es que el cliente elige ir, en vez
 * de encontrarse fuera de la ficha. Si elige entrar, el tomo queda anotado y se
 * guarda solo al volver (la intención pendiente de wishlist.js); si elige seguir
 * mirando, no se anota nada: completar después un guardado que no pidió sería
 * una sorpresa.
 *
 * APAGADO DE FÁBRICA: es una decisión del dueño (captación de cuenta frente a
 * fricción), no de quien escribe el código. Sin la fila, o con cualquier valor
 * que no sea `yes`, el corazón navega al acceso como siempre; el dueño lo
 * enciende en Akibara → Textos, donde viven también los cuatro textos
 *.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class GuestPrompt {

	/** `yes` muestra el aviso; cualquier otra cosa (o la fila ausente) lleva directo al acceso, como siempre. */
	public const OPTION_ENABLED = 'akb_wishlist_guest_prompt';

	public const OPTION_TITLE   = 'akb_copy_wl_guest_title';
	public const OPTION_BODY    = 'akb_copy_wl_guest_body';
	public const OPTION_CTA     = 'akb_copy_wl_guest_cta';
	public const OPTION_DISMISS = 'akb_copy_wl_guest_dismiss';

	public const DEFAULT_TITLE   = 'Inicia sesión para guardar tus favoritos';
	public const DEFAULT_BODY    = 'Al entrar, {producto} queda guardado en tus favoritos y lo encuentras desde cualquier dispositivo.';
	public const DEFAULT_CTA     = 'Iniciar sesión o crear cuenta';
	public const DEFAULT_DISMISS = 'Seguir mirando';

	/**
	 * Donde va el nombre del tomo en la bajada. Lo reemplaza wishlist.js con el
	 * nombre entre comillas («…»); sin marcador, la bajada sale tal cual.
	 */
	public const PRODUCT_MARKER = '{producto}';

	/**
	 * option → literal, para que CopyManager los exponga y los sanee como copys
	 * de una línea obligatorios (vaciar uno lo devuelve a su literal).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			self::OPTION_TITLE   => self::DEFAULT_TITLE,
			self::OPTION_BODY    => self::DEFAULT_BODY,
			self::OPTION_CTA     => self::DEFAULT_CTA,
			self::OPTION_DISMISS => self::DEFAULT_DISMISS,
		);
	}

	/**
	 * ¿Se muestra el aviso? Sólo con un `yes` guardado, que es lo que escribe la
	 * casilla encendida: el lector no acepta nada que el guardado no escriba.
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Los textos del aviso para wishlist.js, saneados y con su literal como red.
	 *
	 * @return array{guestTitle:string,guestBody:string,guestCta:string,guestDismiss:string,guestMarker:string}
	 */
	public static function labels(): array {
		return array(
			'guestTitle'   => Text::one_line_option( self::OPTION_TITLE, self::DEFAULT_TITLE ),
			'guestBody'    => Text::one_line_option( self::OPTION_BODY, self::DEFAULT_BODY ),
			'guestCta'     => Text::one_line_option( self::OPTION_CTA, self::DEFAULT_CTA ),
			'guestDismiss' => Text::one_line_option( self::OPTION_DISMISS, self::DEFAULT_DISMISS ),
			'guestMarker'  => self::PRODUCT_MARKER,
		);
	}
}
