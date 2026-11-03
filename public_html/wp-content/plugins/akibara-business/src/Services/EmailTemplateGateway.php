<?php
/**
 * EmailTemplateGateway — vía única para los correos AD-HOC de Akibara Business.
 *
 * Mapa de vías de correo de la casa (no hay una cuarta):
 *
 *   1. Correos con plantilla y ajustes en WooCommerce → Correos: subclases de
 *      {@see AkibaraEmail} (stock, series, preventas). Es la vía preferida.
 *   2. Correos ad-hoc sin pantalla de ajustes (alta en lista de espera,
 *      recordatorio de transferencia): ESTA clase.
 *   3. Correos del programa de puntos: `Akibara\Rewards\Points\Emails`, que vive
 *      en otro plugin y no puede depender de éste.
 *
 * Las tres terminan en `WC_Email::send()`, que inlinea el CSS y aplica
 * `woocommerce_mail_content` (blindaje Gmail modo oscuro de EmailBrand) UNA vez,
 * y las tres usan el mismo `email-header.php` / `email-footer.php` del tema.
 *
 * Los avisos operativos internos al dueño (formulario de contacto, solicitudes
 * de tomos, alarmas de reindexado y del vigía de Blue Express) salen en texto
 * plano con `wp_mail()` a propósito: no son correo de marca.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.0
 */
final class EmailTemplateGateway {

	/**
	 * Ensambla header + cuerpo + footer canónicos del tema, SIN inline ni filtros.
	 *
	 * El inline de CSS y el blindaje de modo oscuro los hace `WC_Email::send()`
	 * al entregar; hacerlos aquí también los duplicaría.
	 *
	 * @param string $heading   Encabezado del banner rojo.
	 * @param string $body_html Cuerpo del correo (HTML ya escapado).
	 * @return string
	 */
	public static function render( string $heading, string $body_html ): string {
		if ( ! function_exists( 'wc_get_template' ) ) {
			return self::fallback_html( $heading, $body_html );
		}

		ob_start();
		wc_get_template( 'emails/email-header.php', array( 'email_heading' => $heading ) );
		echo $body_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- el emisor entrega el cuerpo ya escapado.
		wc_get_template( 'emails/email-footer.php' );

		return (string) ob_get_clean();
	}

	/**
	 * Entrega un correo de marca con parte plana derivada del HTML.
	 *
	 * @param string               $to        Destinatario.
	 * @param string               $subject   Asunto.
	 * @param string               $heading   Encabezado del banner.
	 * @param string               $body_html Cuerpo (HTML ya escapado).
	 * @param array<string, mixed> $options   - 'key'     => string Identidad en el registro de WC (`akb_<key>`).
	 *                                        - 'headers' => string[] Cabeceras extra (p. ej. List-Unsubscribe).
	 * @return bool Si el transporte confirmó.
	 */
	public static function send( string $to, string $subject, string $heading, string $body_html, array $options = array() ): bool {
		$to = trim( $to );
		if ( '' === $to ) {
			return false;
		}

		$html    = self::render( $heading, $body_html );
		$plain   = self::html_to_plain( $html );
		$headers = '';
		foreach ( (array) ( $options['headers'] ?? array() ) as $linea ) {
			$linea = trim( (string) $linea );
			if ( '' !== $linea ) {
				$headers .= $linea . "\r\n";
			}
		}

		if ( MailerBoot::ready() ) {
			return GatewayEmail::for( (string) ( $options['key'] ?? 'generico' ) )
				->deliver( $to, $subject, $html, $plain, $headers );
		}

		// Sin WooCommerce no hay inline ni blindaje: marcado de respaldo con estilos en línea.
		return (bool) wp_mail( $to, $subject, $html, "Content-Type: text/html; charset=UTF-8\r\n" . $headers );
	}

	/**
	 * Texto plano legible que conserva el destino de cada enlace.
	 *
	 * @param string $html HTML del correo.
	 * @return string
	 */
	public static function html_to_plain( string $html ): string {
		$text = (string) preg_replace( '#<(style|script|title)\b[^>]*>.*?</\1>#is', '', $html );

		$text = (string) preg_replace_callback(
			'#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			static function ( array $m ): string {
				$label = trim( (string) wp_strip_all_tags( $m[2] ) );
				$url   = trim( (string) $m[1] );
				if ( '' === $label || 0 === strpos( $url, 'mailto:' ) ) {
					return $url;
				}
				return $label . ': ' . $url;
			},
			$text
		);

		$text = (string) preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|div|tr|h[1-6]|li)>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// El preheader de email-header.php rellena su línea con caracteres
		// invisibles (combining grapheme joiner + zero-width non-joiner).
		$text = (string) preg_replace( '/[\x{034F}\x{200B}-\x{200D}\x{FEFF}\x{00AD}]/u', '', $text );
		$text = str_replace( "\xC2\xA0", ' ', $text );

		// Espacios de indentación de las plantillas + líneas en blanco de más.
		$text = (string) preg_replace( '/[ \t]+/', ' ', $text );
		$text = (string) preg_replace( '/ ?\n ?/', "\n", $text );
		$text = (string) preg_replace( '/\n{3,}/', "\n\n", $text );

		return trim( $text );
	}

	/**
	 * Marcado de respaldo cuando WooCommerce no está cargado.
	 *
	 * @param string $heading   Encabezado.
	 * @param string $body_html Contenido.
	 * @return string
	 */
	private static function fallback_html( string $heading, string $body_html ): string {
		return '<div style="background:#0d0d0d;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
			. '<div style="max-width:600px;margin:0 auto;background:#fffdf7;">'
			. '<div style="background:#E60012;padding:16px 24px;">'
			. '<span style="color:#ffffff;font-size:18px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">'
			. esc_html( $heading ) . '</span></div>'
			. '<div style="padding:24px;color:#2b2b2b;font-size:15px;line-height:1.65;">' . $body_html . '</div>'
			. '<div style="background:#0d0d0d;border-top:3px solid #E60012;padding:16px 24px;color:#8a8378;font-size:12px;text-align:center;">'
			. '&copy; ' . esc_html( (string) wp_date( 'Y' ) ) . ' Akibara &mdash; Tu rincón de Akihabara en Chile.</div>'
			. '</div></div>';
	}
}
