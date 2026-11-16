<?php
/**
 * Controla la calidad AVIF de las imágenes nuevas al subirlas desde el panel.
 * La política inicial elegida para el catálogo es 60. La calidad se aplica
 * mediante wp_editor_set_quality: el editor puede reemplazar set_quality
 * al guardar y el codificador AVIF usa setCompressionQuality, no
 * setImageCompressionQuality.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ImageQuality;

use Akibara\Business\Modules\AdminPanel\AdminPanel;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 */
final class ImageQuality {

	/** Pestaña propia del panel. */
	public const PANEL_TAB_ID = 'imagenes';

	/** Calidad AVIF guardada (entero como texto, igual que el resto del panel). */
	public const OPTION_QUALITY = 'akb_image_avif_quality';

	/**
 * Calidad AVIF inicial elegida para el catálogo.
 */
	public const DEFAULT_QUALITY = 60;

	/** Por debajo de 40 la trama y los trazos finos se pierden a simple vista. */
	public const MIN_QUALITY = 40;

	/** Por encima de 90 el archivo crece mucho sin que se vea distinto. */
	public const MAX_QUALITY = 90;

	private AdminPanel $panel;

	public function __construct( AdminPanel $panel ) {
		$this->panel = $panel;
	}

	public function register(): void {
		add_filter( 'wp_editor_set_quality', array( $this, 'filter_quality' ), 20, 2 );
	}

	/**
	 * La única noción de calidad válida: la usan el guardado y el lector.
	 *
	 * Un entero de 40 a 90, como número o como texto de dígitos. Cualquier otra
	 * cosa —vacío, decimales, «60%», fuera de rango— no es una calidad.
	 *
	 * @param mixed $value Valor a interpretar.
	 */
	public static function parse_quality( $value ): ?int {
		if ( is_int( $value ) ) {
			$calidad = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*\d{1,3}\s*$/', $value ) ) {
			$calidad = (int) trim( $value );
		} else {
			return null;
		}

		return ( $calidad >= self::MIN_QUALITY && $calidad <= self::MAX_QUALITY ) ? $calidad : null;
	}

	/**
	 * La calidad vigente. Una constante Twelve-Factor, una fila ausente o inválida vale el valor por defecto (60).
	 */
	public static function current_quality(): int {
		if ( defined( 'AKB_IMAGE_QUALITY_AVIF' ) ) {
			$from_const = self::parse_quality( AKB_IMAGE_QUALITY_AVIF );
			if ( null !== $from_const ) {
				return $from_const;
			}
		}

		return self::parse_quality( get_option( self::OPTION_QUALITY, self::DEFAULT_QUALITY ) ) ?? self::DEFAULT_QUALITY;
	}

	/**
	 * Calidad con que WordPress guarda cada tamaño nuevo. Sólo AVIF: JPEG y WebP
	 * siguen con el valor de core.
	 *
	 * @internal Callback de `wp_editor_set_quality`.
	 *
	 * @param mixed $quality   Calidad propuesta.
	 * @param mixed $mime_type Tipo MIME de la imagen de salida.
	 * @return mixed
	 */
	public function filter_quality( $quality, $mime_type = '' ) {
		return 'image/avif' === $mime_type ? self::current_quality() : $quality;
	}

	/**
	 * Rechaza la pestaña entera si la calidad escrita no es válida.
	 *
	 * Mejor que guardar otra cosa en silencio: el dueño escribió un número y
	 * tiene que saber que no entró.
	 *
	 * @internal Callback de `akibara_panel_save_errors`.
	 *
	 * @param mixed  $errors Mensajes acumulados.
	 * @param string $tab    Pestaña que se guarda.
	 * @return mixed
	 */
	public function validate_save( $errors, $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- `AdminPanel::handle_save()` verifica el nonce antes de aplicar este filtro.
		if ( self::PANEL_TAB_ID !== $tab || ! is_array( $errors ) || ! isset( $_POST[ self::OPTION_QUALITY ] ) ) {
			return $errors;
		}

		$enviado = wp_unslash( $_POST[ self::OPTION_QUALITY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con parse_quality() y no se guarda.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( null === self::parse_quality( is_string( $enviado ) ? $enviado : null ) ) {
			$errors[] = sprintf( 'La calidad tiene que ser un número entero entre %d y %d. No se guardó nada.', self::MIN_QUALITY, self::MAX_QUALITY );
		}

		return $errors;
	}

	/**
	 * Lo que WooCommerce escribe: la calidad normalizada, o la vigente si lo
	 * enviado no es válido (la validación previa ya lo habría detenido).
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_akb_image_avif_quality`.
	 *
	 * @param mixed $value Valor enviado.
	 */
	public static function sanitize_for_save( $value ): string {
		return (string) ( self::parse_quality( $value ) ?? self::current_quality() );
	}

	/**
	 * @internal Callback de `init`.
	 */
	public function register_admin_tab(): void {
		$this->panel->add_section(
			self::PANEL_TAB_ID,
			'Imágenes',
			array( $this, 'get_settings' ),
			null, // Retirada la UI manual: la optimización vive en Akibara Image Studio.
			false,
			'',
			'sitio'
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => 'Calidad de las imágenes nuevas',
				'type'  => 'title',
				'id'    => 'akb_image_quality_settings',
				'desc'  => 'WordPress genera varios tamaños de tus imágenes nuevas al subirlas; esta calidad gobierna cuánto pesan esos derivados en AVIF (valor menor = menor peso). El recorte automático de márgenes, mejora x4 con IA/GPU y compresión inteligente se realizan antes en Akibara Image Studio.',
			),
			array(
				'title'             => 'Calidad AVIF (de 40 a 90)',
				'id'                => self::OPTION_QUALITY,
				'type'              => 'number',
				'default'           => (string) self::DEFAULT_QUALITY,
				'custom_attributes' => array(
					'min'      => self::MIN_QUALITY,
					'max'      => self::MAX_QUALITY,
					'step'     => 1,
					'required' => 'required',
				),
				'desc'              => 'Medido en la tienda el 14 de septiembre de 2026 con las portadas que se cargan por el servidor: con 60 pesan un 37-39 % menos que con 82 (lo que usa WordPress de fábrica) y a tamaño real no se distinguen. Con 50 pesan la mitad y, con lupa, se suavizan los trazos finos.',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_image_quality_settings',
			),
		);
	}
}
