<?php
/**
 * Publica el umbral vivo de envío gratis mediante un shortcode compartido.
 *
 * akb_envio_gratis toma Umbral del transportista (CLP) de los ajustes de Envío
 * gratis. Páginas, entradas y mensajes consumen esa fuente única en lugar de
 * mantener importes que puedan desincronizarse de la condición del checkout.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ShippingCopy;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.113.0
 */
final class ShippingCopy {

	/** Shortcode público. */
	public const SHORTCODE = 'akb_envio_gratis';

	/**
	 * La MISMA opción que alimenta la barra de progreso
	 * (`FreeShippingProgress::OPTION_CARRIER_THRESHOLD`). No se duplica el
	 * número: si algún día cambia de nombre, este módulo se queda sin dato y lo
	 * dice, en vez de inventar uno propio que se desincronice en silencio.
	 */
	private const OPTION_CARRIER_THRESHOLD = 'akibara_fsp_carrier_threshold';

	/**
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * El umbral vivo en pesos, o 0 si no hay ninguno configurado.
	 *
	 * @return int
	 */
	public static function threshold(): int {
		return max( 0, (int) get_option( self::OPTION_CARRIER_THRESHOLD, 0 ) );
	}

	/**
	 * El umbral ya formateado para leerse («$60.000»), o '' si no hay.
	 *
	 * Devolver cadena vacía —y no un «$0»— es deliberado: sin umbral no hay
	 * promesa que hacer, y una frase que diga «envío gratis sobre $0» es peor
	 * que una frase incompleta.
	 *
	 * @param bool $con_signo Anteponer el signo peso.
	 * @return string
	 */
	public static function formatted( bool $con_signo = true ): string {
		$monto = self::threshold();
		if ( $monto <= 0 ) {
			return '';
		}

		return ( $con_signo ? '$' : '' ) . number_format_i18n( $monto );
	}

	/**
	 * Resuelve el shortcode dentro de un texto que NO pasa por `do_shortcode()`.
	 *
	 * La barra de anuncios imprime sus mensajes con `wp_kses()` y sin procesar
	 * shortcodes, a propósito: es un texto que se pinta en todas las páginas del
	 * sitio y abrirlo a shortcodes arbitrarios sería una superficie de ejecución
	 * de más. Este método resuelve SÓLO el nuestro y deja el resto intacto, así
	 * que el dueño usa la misma sintaxis en todas partes sin ampliar el riesgo.
	 *
	 * @param string $texto Texto con el shortcode escrito.
	 * @return string
	 */
	public static function resolve_in( string $texto ): string {
		if ( false === strpos( $texto, '[' . self::SHORTCODE ) ) {
			return $texto;
		}

		return (string) preg_replace_callback(
			'/\[' . preg_quote( self::SHORTCODE, '/' ) . '(\s[^\]]*)?\]/',
			static function ( array $m ): string {
				$sin_signo = isset( $m[1] ) && false !== strpos( $m[1], 'signo="no"' );
				return self::formatted( ! $sin_signo );
			},
			$texto
		);
	}

	/**
	 * `[akb_envio_gratis]` → «$60.000».
	 * `[akb_envio_gratis signo="no"]` → «60.000», para cuando el signo ya está
	 * escrito en la frase.
	 *
	 * @param array<string,string>|string $atts Atributos.
	 * @return string
	 */
	public function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'signo' => 'si' ), is_array( $atts ) ? $atts : array() );

		return esc_html( self::formatted( 'no' !== $atts['signo'] ) );
	}
}
