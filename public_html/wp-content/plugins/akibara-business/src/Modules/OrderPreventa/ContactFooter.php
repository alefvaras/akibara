<?php
/**
 * Genera contacto directo para los correos transaccionales de reserva.
 *
 * El bloque Necesitas ayuda ofrece WhatsApp mediante wa.me y correo mediante
 * mailto, accesibles desde el propio mensaje. El teléfono procede de la opción
 * joinchat del plugin Creame Joinchat; si no está disponible usa el respaldo
 * configurado en esta clase.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Footer contacto compartido entre ReminderEmail y ReadyToShipEmail.
 *
 * @since 1.23.1
 */
final class ContactFooter {

	/**
	 * Teléfono de respaldo cuando Joinchat no tiene una opción telephone utilizable.
	 */
	private const FALLBACK_PHONE = '+56944242844';

	private const SUPPORT_EMAIL = 'contacto@akibara.cl';

	/**
	 * Renderiza HTML del bloque de contacto. Incluye order_number en el
	 * mensaje pre-poblado de WhatsApp y subject pre-poblado de mailto —
	 * reduce 1 round-trip con soporte que típicamente pide "¿cuál es tu
	 * número de pedido?".
	 *
	 * @param WC_Order $order Orden cliente.
	 * @return string HTML.
	 */
	public static function render_html( WC_Order $order ): string {
		$order_number = '#' . $order->get_order_number();
		$wa_url       = self::whatsapp_url( $order_number );
		$mailto_url   = self::mailto_url( $order_number );
		$display_phone = self::format_display_phone( self::get_phone() );

		$lines = array();
		// Links de contacto: negro de marca en light (no azul); en dark se vuelven
		// blancos (override Apple/iOS en el wrapper + inversión de Gmail). Clase
		// `akb-contact-box` para targetearlos sin pisar otros callouts.
		// El `-webkit-text-fill-color` acompaña SIEMPRE al `color`: la hoja del
		// wrapper rellena de rojo todo `<a>` del cuerpo y el `color` solo no lo
		// desactiva en WebKit (Apple Mail / app de Gmail / Outlook móvil).
		$lines[] = '<div class="akb-email-callout akb-contact-box" style="margin:24px 0 12px 0;padding:14px 18px;background:#f7f7f7;border-radius:4px;font-size:0.92em;line-height:1.5;">';
		$lines[] = '	<p style="margin:0 0 8px 0;font-weight:600;color:#1d2327;">¿Necesitas ayuda con tu pedido?</p>';
		$lines[] = '	<p style="margin:0 0 4px 0;">';
		$lines[] = '		<span style="display:inline-block;width:80px;color:#50575e;">WhatsApp:</span>';
		$lines[] = '		<a href="' . esc_url( $wa_url ) . '" style="color:#0d0d0d;-webkit-text-fill-color:#0d0d0d;text-decoration:underline;font-weight:600;">' . esc_html( $display_phone ) . '</a>';
		$lines[] = '	</p>';
		$lines[] = '	<p style="margin:0;">';
		$lines[] = '		<span style="display:inline-block;width:80px;color:#50575e;">Correo:</span>';
		$lines[] = '		<a href="' . esc_url( $mailto_url ) . '" style="color:#0d0d0d;-webkit-text-fill-color:#0d0d0d;text-decoration:underline;font-weight:600;">' . esc_html( self::SUPPORT_EMAIL ) . '</a>';
		$lines[] = '	</p>';
		$lines[] = '</div>';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Renderiza versión plain text del bloque de contacto.
	 *
	 * @param WC_Order $order Orden cliente.
	 * @return string Plain text con saltos de línea.
	 */
	public static function render_plain( WC_Order $order ): string {
		$order_number = '#' . $order->get_order_number();
		$wa_url       = self::whatsapp_url( $order_number );

		$out  = "----------\n";
		$out .= "¿Necesitas ayuda con tu pedido?\n";
		$out .= 'WhatsApp: ' . $wa_url . "\n";
		$out .= 'Correo: ' . self::SUPPORT_EMAIL . "\n";

		return $out;
	}

	/**
	 * URL wa.me con mensaje pre-poblado.
	 *
	 * @param string $order_number Display order number con prefijo `#`.
	 * @return string
	 */
	private static function whatsapp_url( string $order_number ): string {
		$phone = self::get_phone_e164_clean();
		$msg   = sprintf( 'Hola, consulta por mi pedido %s', $order_number );
		return 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . rawurlencode( $msg );
	}

	/**
	 * URL mailto con subject pre-poblado.
	 *
	 * @param string $order_number Display order number con prefijo `#`.
	 * @return string
	 */
	private static function mailto_url( string $order_number ): string {
		$subject = sprintf( 'Consulta pedido %s', $order_number );
		return 'mailto:' . self::SUPPORT_EMAIL . '?subject=' . rawurlencode( $subject );
	}

	/**
	 * Número WhatsApp en formato E164 (`+56944242844`) leyendo de la option
	 * `joinchat` (plugin Creame Joinchat) o fallback hardcoded.
	 *
	 * @return string
	 */
	private static function get_phone(): string {
		$joinchat = get_option( 'joinchat' );
		if ( is_array( $joinchat ) && ! empty( $joinchat['telephone'] ) ) {
			return (string) $joinchat['telephone'];
		}
		return self::FALLBACK_PHONE;
	}

	/**
	 * Número limpio para wa.me — sin el `+` inicial (wa.me los rechaza),
	 * sin espacios, sin guiones.
	 *
	 * @return string
	 */
	private static function get_phone_e164_clean(): string {
		$phone = self::get_phone();
		return preg_replace( '/[^0-9]/', '', $phone );
	}

	/**
	 * Formato amigable para mostrar: `+56 9 4424 2844` desde `+56944242844`.
	 *
	 * @param string $e164
	 * @return string
	 */
	private static function format_display_phone( string $e164 ): string {
		// CL E164: +56 9XXXXXXXX. Insert spaces: +56 9 XXXX XXXX.
		if ( preg_match( '/^\+56(9)(\d{4})(\d{4})$/', $e164, $m ) ) {
			return '+56 ' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
		}
		return $e164;
	}
}
