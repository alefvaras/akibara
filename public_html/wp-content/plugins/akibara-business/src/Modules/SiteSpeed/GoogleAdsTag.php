<?php
/**
 * Controla la etiqueta de conversiones Google Ads de Google for WooCommerce.
 *
 * El feed de productos Merchant es independiente de esta etiqueta. La clase se
 * engancha en plugins_loaded:0 desde el archivo principal para aplicar el ajuste
 * antes de registrar servicios en20. GlobalSiteTag consulta
 * woocommerce_gla_disable_gtag_tracking al imprimir; no se depende del orden
 * de plugins que comparten prioridad.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

defined( 'ABSPATH' ) || exit;

final class GoogleAdsTag {

	/** `yes` carga la etiqueta; cualquier otra cosa (o la fila ausente) la apaga. */
	public const OPTION = 'akb_google_ads_tag';

	/** Filtro público de Google for WooCommerce. */
	public const FILTER = 'woocommerce_gla_disable_gtag_tracking';

	/**
	 * Engancha el filtro sólo si Google for WooCommerce está cargado.
	 *
	 * @internal Callback de `plugins_loaded:0`, desde `akibara-business.php`.
	 */
	public static function register_early(): void {
		if ( ! self::glue_active() ) {
			return;
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de Google for WooCommerce: el nombre lo fija ese plugin.
		add_filter( self::FILTER, array( self::class, 'filter_disable' ) );
		add_action( 'update_option_' . self::OPTION, array( self::class, 'purge_after_change' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'purge_after_add' ), 10, 2 );
	}

	/** ¿Está Google for WooCommerce? Sin él no hay etiqueta ni interruptor que mostrar. */
	public static function glue_active(): bool {
		return defined( 'WC_GLA_VERSION' );
	}

	/** ¿La etiqueta se carga? */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
	}

	/**
	 * @internal Callback de `woocommerce_gla_disable_gtag_tracking`.
	 *
	 * @param mixed $disable Lo que decidió quien filtró antes.
	 */
	public static function filter_disable( $disable ): bool {
		return (bool) $disable || ! self::enabled();
	}

	/**
	 * La etiqueta vive en el HTML guardado en caché: cambiarla exige purgar.
	 *
	 * La pestaña del panel no purga al guardar (el recalentado de las fichas vive
	 * en la misma pestaña y no cambia ningún HTML), así que la purga es de esta
	 * option y sólo cuando su valor cambia de verdad.
	 *
	 * @internal Callback de `update_option_akb_google_ads_tag`.
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
	 * @internal Callback de `add_option_akb_google_ads_tag`.
	 *
	 * @param string $option Nombre de la option.
	 * @param mixed  $value  Valor escrito.
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
			99
		);
	}
}
