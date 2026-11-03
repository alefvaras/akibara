<?php
/**
 * Módulo configurable del hero. Registra akb_hero, encolado condicional,
 * preload del primer slide, editor en el panel y migración de configuración.
 * El tema delega en este módulo el CSS y preload del hero y no debe duplicar
 * los assets legacy. El marcado propio usa el prefijo akb-hero.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeHero;

use Akibara\Business\Utilities\PluginAssetVersion;

defined( 'ABSPATH' ) || exit;

final class HomeHero {

	private Renderer $renderer;
	private AdminUI  $admin_ui;

	public function __construct() {
		$this->renderer = new Renderer();
		$this->admin_ui = new AdminUI();
	}

	public function register(): void {
		add_shortcode( 'akb_hero', array( $this, 'render_shortcode' ) );

		// Preload dinámico del primer slide (LCP).
		add_filter( 'wp_preload_resources', array( $this, 'inject_preload' ) );

		// Admin UI hooks.
		if ( is_admin() ) {
			$this->admin_ui->register();
		}

		// Seed migration: corre 1 vez tras upgrade del plugin (idempotente).
		add_action( 'akibara_business_upgraded', array( __NAMESPACE__ . '\\Migration', 'maybe_seed' ), 10, 0 );

		// Lazy seed: si el hook `upgraded` ya pasó (ej. version_installed ya
		// avanzada antes de que HomeHero existiera), corremos `maybe_seed`
		// también en `admin_init` baja prioridad. Idempotente — devuelve early
		// si ya hay slides persistidos. No impacta frontend (solo admin).
		if ( is_admin() ) {
			add_action( 'admin_init', array( __NAMESPACE__ . '\\Migration', 'maybe_seed' ), 5 );
		}

		// Invalidación del transient cache de `find_adjacent_webp`. Sin estos
		// hooks el transient (12h TTL) puede retornar un URL `.webp` stale tras
		// regenerate-thumbs / delete-attachment / cambio de slide.
		add_action( 'attachment_updated', array( $this, 'invalidate_webp_cache_for_attachment' ), 10, 1 );
		add_action( 'delete_attachment',  array( $this, 'invalidate_webp_cache_for_attachment' ), 10, 1 );
		// 3 argumentos: el hook nativo entrega ($old_value, $new_value, $option) y
		// hacen falta los DOS valores — los slides que SALEN de los settings ya no
		// se pueden deducir leyendo la opción, que a esta altura ya es la nueva.
		add_action( 'update_option_akibara_hero_settings', array( $this, 'invalidate_webp_cache_all' ), 10, 3 );

		// Forzar a LiteSpeed Cache a ignorar las imágenes del Hero para evitar LCP penalty.
		add_filter( 'litespeed_media_lazy_img_excludes', function( $excludes ) {
			if ( ! is_array( $excludes ) ) {
				$excludes = array();
			}
			$excludes[] = 'akb-hero__img';
			return $excludes;
		} );
	}

	/**
	 * Invalida el transient `find_adjacent_webp` para el URL del attachment dado.
	 *
	 * @param int $attachment_id
	 */
	public function invalidate_webp_cache_for_attachment( $attachment_id ): void {
		$url = wp_get_attachment_image_url( (int) $attachment_id, 'full' );
		if ( is_string( $url ) && '' !== $url ) {
			delete_transient( 'akb_hero_webp_' . md5( $url ) );
		}
	}

	/**
	 * Invalida con delete_transient las claves akb_hero_webp por URL de los
	 * adjuntos anteriores y nuevos. Los transients pueden vivir en object cache
	 * y no deben borrarse por SQL. Incluye imágenes retiradas y nuevas para
	 * revisar también respuestas cacheadas de ausencia de WebP.
	 *
	 * @param mixed  $old_value Settings antes del guardado.
	 * @param mixed  $new_value Settings después del guardado.
	 * @param string $option    Nombre de la opción, ignorado.
	 */
	public function invalidate_webp_cache_all( $old_value = null, $new_value = null, string $option = '' ): void {
		unset( $option );

		$ids = array_merge(
			self::slide_attachment_ids( $old_value ),
			self::slide_attachment_ids( $new_value )
		);

		// Llamada sin argumentos (WP-CLI, un `do_action` manual, una prueba): no
		// hay diff que recorrer, pero dejarlo sin hacer nada sería peor que
		// invalidar de más. Se cae a los settings vigentes.
		if ( array() === $ids ) {
			$ids = self::slide_attachment_ids( get_option( 'akibara_hero_settings' ) );
		}

		foreach ( array_unique( $ids ) as $attachment_id ) {
			$this->invalidate_webp_cache_for_attachment( $attachment_id );
		}
	}

	/**
	 * IDs de attachment de un árbol de settings del hero, sin filtrar por
	 * `is_active`: un slide desactivado sigue teniendo su entrada cacheada.
	 *
	 * @param mixed $settings Settings crudos tal como llegan del hook.
	 * @return list<int>
	 */
	private static function slide_attachment_ids( $settings ): array {
		if ( ! is_array( $settings ) || empty( $settings['slides'] ) || ! is_array( $settings['slides'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( $settings['slides'] as $slide ) {
			if ( ! is_array( $slide ) ) {
				continue;
			}
			foreach ( array( 'desktop_id', 'mobile_id' ) as $field ) {
				$id = (int) ( $slide[ $field ] ?? 0 );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Atributos del shortcode (todos opcionales, default sensato):
	 *   - `class`   → clases CSS extra para el <section> root.
	 *
	 * @param array<string, mixed>|string $atts
	 * @return string
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'akb_hero'
		);

		$html = $this->renderer->render();
		if ( '' === $html ) {
			return '';
		}

		// Enqueue assets (CSS + JS condicional según slide count).
		$this->enqueue_assets();

		// Inyectar clases extra.
		$extra = sanitize_text_field( (string) ( $atts['class'] ?? '' ) );
		if ( '' !== $extra ) {
			$html = preg_replace(
				'/<section class="akb-hero/',
				'<section class="akb-hero ' . esc_attr( $extra ),
				$html,
				1
			);
		}

		return (string) $html;
	}

	/**
	 * Enqueue CSS siempre, JS solo si hay 2+ slides (carrusel).
	 */
	private function enqueue_assets(): void {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;
		wp_enqueue_style(
			'akb-home-hero',
			AKIBARA_BUSINESS_URL . 'assets/css/home-hero.css',
			array(),
			PluginAssetVersion::for_path( 'assets/css/home-hero.css' )
		);

		$settings = Settings::get();
		if ( count( Settings::active_slides( $settings ) ) > 1 ) {
			wp_enqueue_script(
				'akb-home-hero',
				AKIBARA_BUSINESS_URL . 'assets/js/home-hero.js',
				array(),
				PluginAssetVersion::for_path( 'assets/js/home-hero.js' ),
				true
			);
		}
	}

	/**
	 * Añade el preload del primer slide cuando el contenido incluye akb_hero,
	 * mediante wp_preload_resources y la configuración vigente del componente.
	 *
	 * @param array<int, array<string, string>> $preload_resources
	 * @return array<int, array<string, string>>
	 */
	public function inject_preload( array $preload_resources ): array {
		if ( ! $this->current_content_has_hero_shortcode() ) {
			return $preload_resources;
		}

		$urls = $this->renderer->get_preload_urls();
		if ( '' === $urls['desktop'] && '' === $urls['mobile'] ) {
			return $preload_resources;
		}

		if ( '' !== $urls['desktop'] ) {
			$desktop_preload = array(
				'href'          => $urls['desktop'],
				'as'            => 'image',
				'type'          => $this->mime_from_url( $urls['desktop'] ),
				'media'         => '(min-width: 769px)',
				'fetchpriority' => 'high',
			);
			if ( '' !== $urls['desktop_srcset'] ) {
				$desktop_preload['imagesrcset'] = $urls['desktop_srcset'];
				$desktop_preload['imagesizes']  = $urls['desktop_sizes'];
			}
			$preload_resources[] = $desktop_preload;
		}
		if ( '' !== $urls['mobile'] ) {
			$mobile_preload = array(
				'href'          => $urls['mobile'],
				'as'            => 'image',
				'type'          => $this->mime_from_url( $urls['mobile'] ),
				'media'         => '(max-width: 768px)',
				'fetchpriority' => 'high',
			);
			if ( '' !== $urls['mobile_srcset'] ) {
				$mobile_preload['imagesrcset'] = $urls['mobile_srcset'];
				$mobile_preload['imagesizes']  = $urls['mobile_sizes'];
			}
			$preload_resources[] = $mobile_preload;
		}

		return $preload_resources;
	}

	/**
	 * Comprueba el contenido del objeto consultado, no su rol como portada.
	 */
	private function current_content_has_hero_shortcode(): bool {
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return false;
		}

		$content = get_post_field( 'post_content', $post_id );
		return is_string( $content ) && has_shortcode( $content, 'akb_hero' );
	}

	/**
	 * Devuelve un valor para el atributo `admin_panel->add_section header_fn`.
	 * AdminPanel lo invoca cuando el tab está activo.
	 */
	public function render_admin_section(): void {
		$this->admin_ui->render_section();
	}

	/**
	 * Calcula `type="image/..."` a partir de la extensión del URL.
	 */
	private function mime_from_url( string $url ): string {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'webp':
				return 'image/webp';
			case 'avif':
				return 'image/avif';
			case 'jpg':
			case 'jpeg':
				return 'image/jpeg';
			case 'gif':
				return 'image/gif';
			case 'svg':
				return 'image/svg+xml';
			case 'png':
			default:
				return 'image/png';
		}
	}
}
