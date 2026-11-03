<?php
/**
 * EmailButton — el ÚNICO botón de correo de la casa, en HTML y en plano.
 *
 * Existe para que la lección de contraste no haya que re-aprenderla en cada
 * plantilla: el wrapper de marca rellena de rojo todo `<a>` del cuerpo
 * (`-webkit-text-fill-color`), y un botón que sólo declara `color:#ffffff`
 * sale rojo-sobre-rojo —1,23:1— en Apple Mail, la app de Gmail y Outlook
 * móvil. Acá el relleno blanco viaja SIEMPRE, junto con la clase
 * `.akb-email-btn` que lo repite desde la hoja (ver `Modules\EmailBrand`).
 *
 * Geometría: 15px de padding vertical + 15px de línea = 45px de alto real, por
 * encima del mínimo táctil de 44px.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.112.1
 */
final class EmailButton {

	/**
	 * Estilo inline del botón. Constante y no una interpolación: es el
	 * contrato que las pruebas de conducta miran.
	 */
	private const STYLE = 'display:inline-block;background-color:#E60012;background-image:linear-gradient(#E60012,#E60012);color:#ffffff;'
		. '-webkit-text-fill-color:#ffffff;padding:15px 28px;font-weight:bold;font-size:15px;'
		. 'line-height:1;text-decoration:none;border-radius:0;text-transform:uppercase;letter-spacing:0.5px;';

	/**
	 * Botón listo para incrustar.
	 *
	 * @param string $label      Texto del botón (se escapa).
	 * @param string $url        Destino ABSOLUTO (se escapa).
	 * @param bool   $plain_text Devolver la variante de texto plano.
	 * @return string Markup (o texto) del botón; '' si falta el destino.
	 */
	public static function render( string $label, string $url, bool $plain_text = false ): string {
		if ( '' === trim( $url ) ) {
			return '';
		}

		if ( $plain_text ) {
			// En plano el botón es su etiqueta y su URL: el mismo destino que
			// el HTML, para que las dos partes del multipart no diverjan.
			return "\n" . $label . ":\n" . esc_url_raw( $url ) . "\n\n";
		}

		return '<p style="margin:24px 0;"><a class="akb-email-btn" href="' . esc_url( $url )
			. '" style="' . self::STYLE . '"><span class="akb-blend-screen"><span class="akb-blend-diff">' . esc_html( $label ) . '</span></span></a></p>' . "\n";
	}
}
