<?php
/**
 * EmailBrand — las dos cosas que la casa le hace a TODO correo de WooCommerce.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\EmailBrand;

use Akibara\Business\Modules\SeoSocial\SocialImageDerivatives;
use Akibara\Business\Services\EmailButton;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.112.1
 */
final class EmailBrand {

	/**
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_styles', array( $this, 'add_button_exception' ), 20, 1 );

		// Prioridad 15: después de la tabla de pedido de core (10) y antes del
		// empujón de reseña de rewards (25). El botón cierra el detalle.
		add_action( 'woocommerce_email_order_details', array( $this, 'render_order_cta' ), 15, 4 );

		// Reemplaza el recorte AVIF 100x100 por la hermana JPG en correos.
		add_filter( 'woocommerce_order_item_thumbnail', array( $this, 'jpeg_cover_for_email' ), 20, 1 );

		// Post-Emogrifier: inyecta las reglas `u + .body` y envuelve automáticamente
		// encabezados, botones y chapitas de tomo `.akb-vol` en capas mix-blend-mode.
		add_filter( 'woocommerce_mail_content', array( $this, 'protect_gmail_dark_mode' ), 99, 1 );
	}

	/**
	 * Cambia la portada AVIF de un artículo del correo por su hermana JPG.
	 *
	 * @param mixed $image Etiqueta `<img>` que WooCommerce va a imprimir.
	 * @return mixed
	 */
	public function jpeg_cover_for_email( $image ) {
		try {
			if ( ! is_string( $image ) || '' === $image
				|| ! function_exists( 'did_action' ) || did_action( 'woocommerce_email_header' ) <= did_action( 'woocommerce_email_footer' )
				|| 1 !== preg_match( '/\ssrc="([^"]+)"/', $image, $src ) ) {
				return $image;
			}
			$jpeg = SocialImageDerivatives::email_url( $src[1] );
			if ( $jpeg === $src[1] ) {
				return $image;
			}

			$out = str_replace( $src[0], ' src="' . esc_url( $jpeg ) . '"', $image );
			$out = (string) preg_replace( '/\s(?:srcset|sizes)="[^"]*"/', '', $out );

			$medidas = self::cover_dimensions( $jpeg );
			if ( null !== $medidas && 1 === preg_match( '/\swidth="(\d+)"/', $out, $w ) ) {
				$alto = max( 1, (int) round( (int) $w[1] * $medidas[1] / $medidas[0] ) );
				$out  = (string) preg_replace( '/\sheight="\d+"/', ' height="' . $alto . '"', $out );
			} else {
				$out = (string) preg_replace( '/\sheight="\d+"/', '', $out );
			}

			return $out;
		} catch ( \Throwable $throwable ) {
			return $image;
		}
	}

	/**
	 * Ancho y alto de una hermana JPG a partir de su URL, o null.
	 *
	 * @param string $url URL de `/uploads/portadas/<slug>-og.jpg`.
	 * @return array{0:int,1:int}|null
	 */
	private static function cover_dimensions( string $url ): ?array {
		if ( 1 !== preg_match( '#/uploads/portadas/([a-z0-9-]+' . preg_quote( SocialImageDerivatives::SUFFIX, '#' ) . ')$#', $url, $m ) ) {
			return null;
		}
		$dir = SocialImageDerivatives::portadas_dir();
		if ( '' === $dir ) {
			return null;
		}
		$path = $dir . '/' . $m[1];
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return is_array( $size ) && (int) ( $size[0] ?? 0 ) > 0 && (int) ( $size[1] ?? 0 ) > 0 ? array( (int) $size[0], (int) $size[1] ) : null;
	}

	/**
	 * Reinstala el relleno blanco y el gradiente anti-inversión del botón.
	 *
	 * @param string $css Hoja de estilos que Emogrifier va a inlinar.
	 * @return string
	 */
	public function add_button_exception( $css ): string {
		return (string) $css . "\n"
			. ".akb-email-btn, a.akb-email-btn {\n"
			. "\tcolor: #ffffff !important;\n"
			. "\t-webkit-text-fill-color: #ffffff !important;\n"
			. "\tbackground-color: #E60012 !important;\n"
			. "\tbackground-image: linear-gradient(#E60012, #E60012) !important;\n"
			. "\ttext-decoration: none !important;\n"
			. "\tfont-weight: bold;\n"
			. "\tborder-radius: 0 !important;\n"
			. "\ttext-transform: uppercase !important;\n"
			. "\tletter-spacing: 0.5px !important;\n"
			. "}\n";
	}

	/**
	 * Protege texto blanco sobre fondos rojos en Gmail móvil Dark Mode.
	 *
	 * @param mixed $html HTML final del correo tras Emogrifier.
	 * @return mixed
	 */
	public function protect_gmail_dark_mode( $html ) {
		try {
			if ( ! is_string( $html ) || '' === $html || false !== strpos( $html, 'u + .body .akb-blend-screen' ) ) {
				return $html;
			}

			// 1. Garantiza que <body> tenga la clase "body".
			$html = (string) preg_replace_callback(
				'/<body\b([^>]*)>/i',
				static function ( array $m ): string {
					$attrs = $m[1];
					if ( 1 === preg_match( '/\bclass=(["\'])(.*?)\1/i', $attrs, $cm ) ) {
						if ( false === strpos( ' ' . $cm[2] . ' ', ' body ' ) ) {
							$new_class = trim( $cm[2] . ' body' );
							$attrs     = str_replace( $cm[0], 'class=' . $cm[1] . $new_class . $cm[1], $attrs );
						}
						return '<body' . $attrs . '>';
					}
					return '<body class="body"' . $attrs . '>';
				},
				$html,
				1
			);

			// 2. Inyecta el CSS exclusivo para Gmail antes de </head>.
			$gmail_css = "<style type=\"text/css\">\n"
				. "u + .body .akb-blend-screen { background: #000000 !important; mix-blend-mode: screen; display: inline-block; }\n"
				. "u + .body .akb-blend-diff { background: #000000 !important; mix-blend-mode: difference; display: inline-block; color: #ffffff !important; -webkit-text-fill-color: #ffffff !important; }\n"
				. "</style>\n</head>";

			$html = (string) preg_replace( '#</head>#i', $gmail_css, $html, 1 );

			$wrap = static function ( array $m ): string {
				if ( false !== strpos( $m[2], 'akb-blend-screen' ) ) {
					return $m[0];
				}
				return $m[1] . '<span class="akb-blend-screen"><span class="akb-blend-diff">' . $m[2] . '</span></span>' . $m[3];
			};

			// 3. Envuelve el contenido del <h1> del banner.
			$html = (string) preg_replace_callback(
				'/(<td[^>]*\bid=["\']header_wrapper["\'][^>]*>\s*<h1[^>]*>)(.*?)(<\/h1>)/is',
				$wrap,
				$html
			);

			// 4. Envuelve el texto interior de los botones .akb-email-btn.
			$html = (string) preg_replace_callback(
				'/(<a\b[^>]*\bclass=["\'][^"\']*\bakb-email-btn\b[^"\']*["\'][^>]*>)(.*?)(<\/a>)/is',
				$wrap,
				$html
			);

			// 5. Envuelve automáticamente el número interior de cualquier chapita .akb-vol.
			$html = (string) preg_replace_callback(
				'/(<span\b[^>]*\bclass=["\'][^"\']*\bakb-vol\b[^"\']*["\'][^>]*>)(.*?)(<\/span>)/is',
				$wrap,
				$html
			);

			// 6. Envuelve automáticamente los enlaces blancos del footer (#template_footer a).
			$html = (string) preg_replace_callback(
				'/(<table[^>]*\bid=["\']template_footer["\'][^>]*>.*?<\/table>)/is',
				static function ( array $footer_match ) use ( $wrap ): string {
					return (string) preg_replace_callback(
						'/(<a\b[^>]*>)(.*?)(<\/a>)/is',
						$wrap,
						$footer_match[1]
					);
				},
				$html
			);

			return $html;
		} catch ( \Throwable $throwable ) {
			return $html;
		}
	}

	/**
	 * Botón «Ver mi pedido» al pie del detalle, sólo para el cliente.
	 *
	 * @param mixed $order         Pedido del correo.
	 * @param mixed $sent_to_admin Si el correo va al admin.
	 * @param mixed $plain_text    Si se está armando la parte de texto plano.
	 * @param mixed $email         Instancia del correo.
	 * @return void
	 */
	public function render_order_cta( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );

		if ( ! $order instanceof WC_Order || ! empty( $sent_to_admin ) ) {
			return;
		}

		$url = self::order_url( $order );
		if ( '' === $url ) {
			return;
		}

		echo EmailButton::render( 'Ver mi pedido', $url, (bool) $plain_text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Destino absoluto del botón.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string URL absoluta o ''.
	 */
	public static function order_url( WC_Order $order ): string {
		$url = $order->get_customer_id() > 0
			? (string) $order->get_view_order_url()
			: (string) $order->get_checkout_order_received_url();

		return 0 === strpos( $url, 'http' ) ? $url : '';
	}

	public static function saludo( WC_Order $order ): string {
		$nombre = trim( (string) $order->get_billing_first_name() );
		return '' !== $nombre ? sprintf( 'Hola %s,', $nombre ) : 'Hola,';
	}

	public static function plano( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}
}
