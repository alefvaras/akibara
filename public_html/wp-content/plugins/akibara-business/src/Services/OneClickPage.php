<?php
/**
 * Presenta las confirmaciones de baja por correo con una cáscara compartida.
 *
 * El flujo GET confirma y POST muta con el contrato de baja en un clic. La página
 * usa identidad de Akibara sin assets externos. Deshabilita page cache de WordPress
 * y LiteSpeed mediante sus mecanismos explícitos; nocache_headers() no sustituye
 * esas señales. Los enlaces nuevos se sitúan bajo Mi cuenta, cuya regla de borde
 * no cachea HTML individual de invitado.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class OneClickPage {

	/**
	 * Base privada de los enlaces individuales de baja bajo Mi cuenta.
	 *
	 * Una cabecera no-store de origen no reemplaza la política de caché del borde.
	 * Los handlers continúan interpretando sus query vars en template_redirect con
	 * prioridad1, por lo que un enlace previamente enviado desde otra ruta mantiene
	 * su validez. El resultado de cada baja no debe almacenarse como HTML público.
	 *
	 * @return string Con barra final.
	 */
	public static function private_base_url(): string {
		$cuenta = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';

		return '' !== $cuenta ? $cuenta : home_url( '/mi-cuenta/' );
	}

	/**
	 * Emite la página completa y termina la petición.
	 *
	 * @param string $title       Título del documento (sin « — Akibara», se añade).
	 * @param string $body_html   Markup interno YA escapado en origen.
	 * @param string $exit_filter Filtro booleano que permite a las pruebas
	 *                            cortocircuitar el `exit` (cada flujo conserva
	 *                            el suyo: la conducta pública no cambia).
	 * @return void
	 */
	public static function render( string $title, string $body_html, string $exit_filter ): void {
		// Desactivar page cache mediante DONOTCACHEPAGE y el hook de LiteSpeed.
		// Las cabeceras HTTP de origen por sí solas no aíslan resultados individuales
		// de una página de baja.

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constante estándar de facto de los plugins de caché: prefijarla la dejaría inerte.
		}
		do_action( 'litespeed_control_set_nocache', 'Akibara: página de baja de un clic (' . $title . ')' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook público de LiteSpeed Cache: el nombre lo fija ese plugin.

		if ( ! headers_sent() ) {
			status_header( 200 );
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
		}

		echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<meta name="robots" content="noindex">'
			. '<title>' . esc_html( $title ) . ' — Akibara</title>'
			. '<style>body{margin:0;display:grid;place-items:center;min-height:100vh;background:#0a0a0a;color:#fff;font-family:Inter,system-ui,sans-serif;text-align:center;padding:24px}'
			. '.akb-wordmark{font-family:"Bebas Neue",Impact,"Arial Narrow",system-ui,sans-serif;font-size:2.4rem;line-height:1;letter-spacing:.14em;font-weight:700;color:#E60012;margin:0 0 20px;text-transform:uppercase}'
			. 'h1{font-size:1.6rem;margin:0 0 12px}p{color:#c9c9c9;line-height:1.5;max-width:34rem}button{background:#E60012;color:#fff;border:0;padding:14px 28px;font-size:1rem;font-weight:600;cursor:pointer;box-shadow:4px 4px 0 #000}a{color:#FF4756}</style>'
			// Wordmark TIPOGRÁFICO, no imagen: esta página se sirve desde un
			// handler propio en mitad de un flujo de baja, y una petición extra a
			// un asset (que puede llegar tarde, fallar o venir del dominio
			// equivocado) dejaría al cliente ante una pantalla anónima justo
			// cuando tiene que reconocer de quién se está dando de baja.
			// `aria-hidden` + el `<h1>` de cada flujo: para un lector de pantalla
			// la marca ya viaja en el `<title>`.
			. '</head><body><div class="akb-wordmark" aria-hidden="true">AKIBARA</div>'
			. '<main>' . $body_html . '</main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup interno construido con esc_* en origen.

		/**
		 * Cortocircuito para pruebas (evita el `exit`). El nombre del filtro
		 * lo aporta cada flujo llamador y se conserva estable.
		 *
		 * @since 1.110.2
		 *
		 * @param bool $should_exit Default true.
		 */
		if ( apply_filters( $exit_filter, true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- cada llamador aporta su filtro histórico akb_* (conducta pública estable).
			exit;
		}
	}
}
