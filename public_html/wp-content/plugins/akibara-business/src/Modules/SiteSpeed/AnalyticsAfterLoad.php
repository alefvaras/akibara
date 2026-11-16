<?php
/**
 * Retrasa Google Analytics (GA4) cuando el dueño activa el ajuste de Velocidad.
 *
 * GA4 conserva dataLayer y gtag() en línea; su etiqueta pasa a text/plain con
 * URL data-akb-after-load. El cargador del pie la descarga tras load e idle,
 * o al primer toque, tecla o desplazamiento. data-no-optimize conserva el orden.
 *
 * El interruptor está apagado por defecto: una salida anterior a la carga puede
 * quedar sin medición.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress UnusedParam
 * @psalm-suppress UnusedParam
 */
final class AnalyticsAfterLoad {

	/** `yes` retrasa la etiqueta de GA4; cualquier otra cosa (o la fila ausente) la deja como siempre. */
	public const OPTION = 'akb_analytics_after_load';

	/** Id con el que Rank Math imprime la etiqueta de GA4. */
	public const GTAG_ID = 'google_gtagjs';

	/** Atributo que lleva la URL diferida y que busca el cargador. */
	public const ATTRIBUTE = 'data-akb-after-load';

	/** El cargador del pie, relativo a la raíz del plugin. */
	private const LOADER = 'assets/js/analytics-after-load.js';

	/** ¿Se diferió alguna etiqueta en esta petición? Sin ninguna, no hay cargador. */
	private bool $deferred = false;

	public function register(): void {
		if ( is_admin() ) {
			return;
		}

		add_filter( 'wp_script_attributes', array( $this, 'defer_gtag' ) );
		add_action( 'wp_footer', array( $this, 'print_loader' ), 99 );
	}

	/** ¿Están diferidas? Opera permanentemente por código. */
	public static function enabled(): bool {
		return true;
	}

	/**
	 * La etiqueta de GA4 de Rank Math sale inerte, con su URL a la espera.
	 *
	 * @internal Callback de `wp_script_attributes`.
	 *
	 * @param mixed $attributes Atributos del `<script>` que se va a imprimir.
	 * @return mixed
	 */
	public function defer_gtag( $attributes ) {
		if ( ! is_array( $attributes ) || self::GTAG_ID !== ( $attributes['id'] ?? '' ) ) {
			return $attributes;
		}

		$src = is_string( $attributes['src'] ?? null ) ? trim( $attributes['src'] ) : '';
		if ( '' === $src || ! self::enabled() ) {
			return $attributes;
		}

		$this->deferred = true;

		return array(
			'id'               => self::GTAG_ID,
			'type'             => 'text/plain',
			self::ATTRIBUTE    => $src,
			'data-no-optimize' => '1',
		);
	}

	/**
	 * El cargador, sólo si algo quedó a la espera en esta petición.
	 *
	 * @internal Callback de `wp_footer:99`.
	 */
	public function print_loader(): void {
		if ( ! $this->deferred ) {
			return;
		}

		$ruta   = dirname( __DIR__, 3 ) . '/' . self::LOADER;
		$codigo = is_readable( $ruta ) ? (string) file_get_contents( $ruta ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero propio del plugin, leído del disco.
		if ( '' === trim( $codigo ) ) {
			return;
		}

		wp_print_inline_script_tag(
			$codigo,
			array(
				'id'               => 'akb-analytics-after-load',
				'data-no-optimize' => '1',
			)
		);
	}

	/**
	 * La etiqueta vive en el HTML guardado en caché: cambiarla exige purgar.
	 *
	 * @internal Callback de `update_option_akb_analytics_after_load`.
	 *
	 * @param mixed $old_value Valor anterior.
	 * @param mixed $value     Valor nuevo.
	 */
	public static function purge_after_change( $old_value, $value ): void {
		if ( ( 'yes' === $old_value ) !== ( 'yes' === $value ) ) {
			self::purge_on_shutdown();
		}
	}

	/**
	 * La primera escritura sólo cambia algo si enciende: la fila ausente ya apagaba.
	 *
	 * @internal Callback de `add_option_akb_analytics_after_load`.
	 *
	 * @param string $option Nombre de la option.
	 * @param mixed  $value  Valor escrito.
	 * @psalm-suppress UnusedParam
	 */
	public static function purge_after_add( $option, $value ): void {
		if ( 'yes' === $value ) {
			self::purge_on_shutdown();
		}
	}

	private static function purge_on_shutdown(): void {
		add_action(
			'shutdown',
			static function (): void {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- hook de LiteSpeed: es SU purga.
				do_action( 'litespeed_purge_all' );
			},
			10,
			0
		);
	}
}
