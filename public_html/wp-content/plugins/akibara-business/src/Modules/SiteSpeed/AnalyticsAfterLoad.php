<?php
/**
 * Retrasa Analytics y Clarity cuando el dueño activa el ajuste de Velocidad.
 *
 * GA4 conserva dataLayer y gtag() en línea; su etiqueta pasa a text/plain con
 * URL data-akb-after-load. Clarity conserva su cola inmediatamente y difiere la
 * etiqueta que la consume. El cargador del pie descarga ambas tras load e idle,
 * o al primer toque, tecla o desplazamiento. data-no-optimize conserva el orden.
 *
 * El interruptor está apagado por defecto: una salida anterior a la carga puede
 * quedar sin medición. Los eventos de comercio se encolan hasta que llega la
 * etiqueta. FrontendCompatibility y el guard de Clarity mantienen el aislamiento
 * de los entornos de pruebas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SiteSpeed;

defined( 'ABSPATH' ) || exit;

final class AnalyticsAfterLoad {

	/** `yes` retrasa las dos etiquetas; cualquier otra cosa (o la fila ausente) las deja como siempre. */
	public const OPTION = 'akb_analytics_after_load';

	/** Id con el que Rank Math imprime la etiqueta de GA4. */
	public const GTAG_ID = 'google_gtagjs';

	/** Función del plugin Microsoft Clarity que imprime su etiqueta en `wp_head`. */
	public const CLARITY_PRINTER = 'clarity_add_script_to_header';

	/** Atributo que lleva la URL diferida y que busca el cargador. */
	public const ATTRIBUTE = 'data-akb-after-load';

	/** El cargador del pie, relativo a la raíz del plugin. */
	private const LOADER = 'assets/js/analytics-after-load.js';

	/** ¿Se diferió alguna etiqueta en esta petición? Sin ninguna, no hay cargador. */
	private bool $deferred = false;

	public function register(): void {
		// Cambiar la etiqueta incluida en HTML cacheado requiere purga sólo cuando
		// cambia el valor. Registrar el hook antes del corte is_admin, porque el panel
		// guarda en admin_init y también necesita invalidar esa copia.

		add_action( 'update_option_' . self::OPTION, array( GoogleAdsTag::class, 'purge_after_change' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( GoogleAdsTag::class, 'purge_after_add' ), 10, 2 );

		if ( is_admin() ) {
			return;
		}

		add_filter( 'wp_script_attributes', array( $this, 'defer_gtag' ) );
		// Antes que el `wp_head:10` donde imprime el plugin de Clarity.
		add_action( 'wp_head', array( $this, 'swap_clarity_printer' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_loader' ), 99 );
	}

	/** ¿Están diferidas? */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION, 'no' );
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
	 * Cambia el fragmento de Clarity por su versión en dos tiempos.
	 *
	 * Sólo si el plugin lo tiene enganchado a esta altura: fuera de producción
	 * ya lo quitaron `FrontendCompatibility` y el mu-plugin de Clarity, y entonces
	 * aquí no se imprime nada.
	 *
	 * @internal Callback de `wp_head:1`.
	 */
	public function swap_clarity_printer(): void {
		$prioridad = has_action( 'wp_head', self::CLARITY_PRINTER );
		if ( false === $prioridad || ! self::enabled() ) {
			return;
		}

		remove_action( 'wp_head', self::CLARITY_PRINTER, (int) $prioridad );
		add_action( 'wp_head', array( $this, 'print_clarity' ), (int) $prioridad );
	}

	/**
	 * La cola de Clarity ahora; su etiqueta, cuando el cargador la pida.
	 *
	 * @internal Callback de `wp_head`, en el lugar del plugin de Clarity.
	 */
	public function print_clarity(): void {
		$proyecto = trim( (string) get_option( 'clarity_project_id', '' ) );
		if ( '' === $proyecto ) {
			// Mismo criterio que el plugin: sin proyecto no imprime nada.
			return;
		}

		$this->deferred = true;

		wp_print_inline_script_tag(
			'window.clarity=window.clarity||function(){(window.clarity.q=window.clarity.q||[]).push(arguments)};',
			array(
				'id'               => 'akb-clarity-queue',
				'data-no-optimize' => '1',
			)
		);
		wp_print_script_tag(
			array(
				'id'               => 'akb-clarity-tag',
				'type'             => 'text/plain',
				self::ATTRIBUTE    => 'https://www.clarity.ms/tag/' . rawurlencode( $proyecto ) . '?ref=wordpress',
				'data-no-optimize' => '1',
			)
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
}
