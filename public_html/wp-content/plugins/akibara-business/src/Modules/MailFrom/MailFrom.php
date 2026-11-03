<?php
/**
 * MailFrom — sender por defecto unificado para todo `wp_mail()` del sitio.
 *
 * WordPress core construye `From:` por defecto como `WordPress <wordpress@<dominio>>`
 * cuando ningún caller setea el header `From:` explícitamente. Ese remitente NO
 * tiene DKIM/SPF firmando, así que Gmail lo manda a spam o lo rechaza
 * silenciosamente.
 *
 * `contacto@akibara.cl` es el buzón autenticado del dominio en Hostinger,
 * proveedor único de entrega del sitio.
 * Este módulo aplica filters globales para que TODO email saliente del sitio
 * (WP core, WooCommerce, plugins, akibara-business) use ese sender autenticado:
 *
 *   - `wp_mail_from`      → contacto@akibara.cl
 *   - `wp_mail_from_name` → Akibara
 *
 * Override per-caller NO es posible por diseño: `wp_mail()` aplica el filter
 * `wp_mail_from` DESPUÉS de parsear el header `From:` del caller, y este módulo
 * descarta ese input INCONDICIONALMENTE (ignora el `$email`/`$name` entrante y
 * fuerza siempre el sender verificado). Es intencional y correcto: el servidor
 * SMTP saliente rechaza cualquier remitente no autenticado, así que un
 * `From:` per-caller distinto rompería el envío. Un solo sender firmado =
 * deliverability predecible.
 *
 * El valor no se toca por caller, pero SÍ es override-able por código vía los filters
 * `akibara_mail_from_email` / `akibara_mail_from_name`. Esa perilla existe SOLO para el
 * día en que el sender verificado cambie (p. ej. se verifica otra casilla): se ajusta el
 * default por código sin reintroducir el riesgo de que un `From:` divergente tumbe todo
 * el correo. NO usar esos filters para identidad per-email — un sender no verificado se
 * rechaza igual.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\MailFrom;

defined( 'ABSPATH' ) || exit;

final class MailFrom {

	public const FROM_EMAIL = 'contacto@akibara.cl';
	public const FROM_NAME  = 'Akibara';

	public function register(): void {
		// Prioridad alta (15) para correr DESPUÉS del default de WP core (10),
		// pero ANTES de plugins que quieran override puntual (20+).
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 15 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 15 );
	}

	/**
	 * Fuerza el remitente verificado, ignorando el `$email` entrante (override
	 * incondicional; ver docblock del módulo). El valor sigue siendo override-able
	 * por código vía el filter `akibara_mail_from_email`.
	 *
	 * @param string $email Email actual (default WP: `wordpress@<dominio>`); ignorado.
	 */
	public function filter_from_email( string $email ): string {
		/**
		 * Filtra el remitente (From) forzado para TODO wp_mail() del sitio.
		 *
		 * Perilla para cuando el sender verificado cambie; NO para identidad
		 * per-email (un remitente no verificado hace que el ESP rechace el envío).
		 *
		 * @since 1.71.7
		 *
		 * @param string $from_email Email remitente forzado (default: verificado).
		 */
		return (string) apply_filters( 'akibara_mail_from_email', self::FROM_EMAIL );
	}

	/**
	 * Fuerza el nombre del remitente, ignorando el `$name` entrante (override
	 * incondicional; ver docblock del módulo). Override-able vía el filter
	 * `akibara_mail_from_name`.
	 *
	 * @param string $name Nombre actual (default WP: "WordPress"); ignorado.
	 */
	public function filter_from_name( string $name ): string {
		/**
		 * Filtra el nombre del remitente (From name) forzado para TODO wp_mail().
		 *
		 * @since 1.71.7
		 *
		 * @param string $from_name Nombre remitente forzado (default: "Akibara").
		 */
		return (string) apply_filters( 'akibara_mail_from_name', self::FROM_NAME );
	}
}
