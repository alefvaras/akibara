<?php
/**
 * HomeHero\AdminUI — UI custom del tab "Home Hero" en panel Akibara.
 *
 * Custom porque WC `form-table` no encaja con un repeater de slides anidados.
 * Renderea HTML propio + maneja su POST handler (`admin_post_akibara_hero_save`)
 * en lugar del `woocommerce_update_options` que usan los otros tabs. AdminPanel
 * registra esta sección como `is_action=true` para que no intente guardar nada
 * con el flujo WC estándar.
 *
 * Repeater: cada slide es un fieldset con `name="slides[N][field]"`. PHP
 * recibe el POST como array y delega sanitize+save a Settings::save().
 *
 * Validación de dimensiones: JS lado cliente (home-hero-admin.js) lee
 * `wp.media` attachment metadata al seleccionar imagen y muestra advertencia
 * inline si difiere de Settings::REC_*_WIDTH/HEIGHT.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeHero;

use Akibara\Business\Utilities\PluginAssetVersion;
use Akibara\Business\Utilities\ThemeAssetVersion;

defined( 'ABSPATH' ) || exit;

final class AdminUI {

	public function register(): void {
		add_action( 'admin_post_akibara_hero_save',  array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts',         array( $this, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_akb_hero_preview',      array( $this, 'ajax_preview' ) );
	}

	/**
	 * Carga JS/CSS solo en el tab Home Hero del panel Akibara.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'toplevel_page_akibara-panel' !== $hook ) {
			return;
		}
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : ''; // Los assets de medios se encolan sólo en la pestaña explícita del slider.
		// La pestaña vacía corresponde al Dashboard por defecto y no necesita
		// wp_enqueue_media ni los assets del editor del hero.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Sólo decide si esta pantalla del panel encola sus assets; no guarda nada.
		if ( 'home_hero' !== $current_tab ) {
			return;
		}

		// WP media uploader + color picker.
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );

		// Fuentes reales del theme para que la mini-preview y los selects de
		// tipografía muestren lo que el visitante verá — sin esto el admin cae a
		// sans genérica y "cambiar la fuente" parece no hacer nada. fonts.css trae
		// Saira; Bebas e Inter vienen de theme.json, que WordPress ya imprime en
		// todo el admin (`admin_print_styles` → wp_print_font_faces).
		$fonts_css = self::theme_fonts_css_url();
		if ( '' !== $fonts_css ) {
			wp_enqueue_style( 'akb-theme-fonts', $fonts_css, array(), ThemeAssetVersion::for_path( 'assets/css/fonts.css' ) );
		}

		wp_enqueue_style(
			'akb-home-hero-admin',
			AKIBARA_BUSINESS_URL . 'assets/css/home-hero-admin.css',
			array( 'wp-color-picker' ),
			PluginAssetVersion::for_path( 'assets/css/home-hero-admin.css' )
		);

		wp_enqueue_script(
			'akb-home-hero-admin',
			AKIBARA_BUSINESS_URL . 'assets/js/home-hero-admin.js',
			array( 'jquery', 'wp-i18n', 'media-views', 'wp-color-picker' ),
			PluginAssetVersion::for_path( 'assets/js/home-hero-admin.js' ),
			true
		);

		wp_localize_script(
			'akb-home-hero-admin',
			'akbHeroAdmin',
			array(
				'rec'           => array(
					'desktop_w'        => Settings::REC_DESKTOP_WIDTH,
					'desktop_h'        => Settings::REC_DESKTOP_HEIGHT,
					'mobile_w'         => Settings::REC_MOBILE_WIDTH,
					'mobile_h'         => Settings::REC_MOBILE_HEIGHT,
					'tolerance_pct'    => Settings::ASPECT_TOLERANCE_PCT,
				),
				'maxSlides'     => Settings::MAX_SLIDES,
				'maxCtas'       => Settings::MAX_CTAS,
				// Única fuente del cap (Settings) — el JS solo COMPARA contra él
				// para mostrar/ocultar el hint; la derivación vive en PHP.
				'tabletFocalYCap' => Settings::TABLET_FOCAL_Y_CAP,
				'slideTemplate' => $this->render_slide_template(),
				'ctaTemplate'   => $this->render_cta_template(),
				'editAttachmentUrl' => admin_url( 'post.php' ),
				'previewNonce'    => wp_create_nonce( 'akb_hero_preview' ),
				'previewAjaxUrl'  => admin_url( 'admin-ajax.php' ),
				'i18n'          => array(
					'pickImage'        => 'Seleccionar imagen',
					'pickerTitleDesk'  => 'Selecciona imagen desktop',
					'pickerTitleMob'   => 'Selecciona imagen mobile',
					'warnDimsAspect'   => 'Sugerido aspect-ratio %ratio (ej. %wx%h). Tu imagen %tw×%th puede verse recortada.',
					'okDims'           => 'Dimensiones óptimas (%tw×%th).',
					'confirmRemove'    => '¿Eliminar este slide? Esta acción no se puede deshacer hasta guardar.',
					'maxSlidesReached' => 'Máximo %max slides.',
					'maxCtasReached'   => 'Máximo %max CTAs por slide.',
				),
			)
		);
	}

	/**
	 * Manejador del form POST. URL: /wp-admin/admin-post.php?action=akibara_hero_save
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}
		check_admin_referer( 'akibara_hero_save' );

		$raw_slides = isset( $_POST['slides'] ) && is_array( $_POST['slides'] ) ? wp_unslash( $_POST['slides'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize is the canonical sanitizer.
		$raw_car    = isset( $_POST['carousel'] ) && is_array( $_POST['carousel'] ) ? wp_unslash( $_POST['carousel'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize is the canonical sanitizer.

		$payload = array(
			'slides'   => $raw_slides,
			'carousel' => $raw_car,
		);

		Settings::save( $payload );

		// La home vive cacheada en LiteSpeed (y Cloudflare vía la integración CDN
		// del plugin LSC). Sin purge, el hero guardado no aparece en el sitio hasta
		// que el cache expire — mismo patrón que AnnouncementBar/CatalogOrder.
		do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache (tercero): el nombre lo fija ese plugin.

		$redirect_args = array(
			'page'  => 'akibara-panel',
			'tab'   => 'home_hero',
			'saved' => '1',
		);

		// Capa 4: si el user clickeó "Guardar y abrir en el sitio", el JS detecta
		// este query param tras el redirect y abre `/` en tab nuevo.
		if ( isset( $_POST['open_after_save'] ) ) {
			$redirect_args['open_site'] = '1';
		}

		$redirect = add_query_arg( $redirect_args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Endpoint AJAX para render preview con settings inyectados (no commit BD).
	 * URL: /wp-admin/admin-ajax.php?action=akb_hero_preview
	 *
	 * Pattern inspirado en Smart Slider 3 ControllerPreview::actionFull —
	 * acepta data via POST y devuelve HTML del hero rendered con esos settings.
	 * Pasa por Settings::sanitize() (mismo path que el save real) para garantizar
	 * fidelidad 1:1 con lo que se vería tras guardar.
	 *
	 * @since 1.38.0
	 */
	public function ajax_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
		}
		check_ajax_referer( 'akb_hero_preview', '_ajax_nonce' );

		$raw_slides   = isset( $_POST['slides'] ) && is_array( $_POST['slides'] ) ? wp_unslash( $_POST['slides'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array crudo que se entrega entero a Settings::sanitize() —absint/sanitize_text_field/esc_url_raw campo a campo y listas blancas—; no existe sanitize_* que acepte un array anidado y el sniff no sigue un saneador propio.
		$raw_carousel = isset( $_POST['carousel'] ) && is_array( $_POST['carousel'] ) ? wp_unslash( $_POST['carousel'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array crudo que se entrega entero a Settings::sanitize() —absint/sanitize_text_field/esc_url_raw campo a campo y listas blancas—; no existe sanitize_* que acepte un array anidado y el sniff no sigue un saneador propio.

		$clean = Settings::sanitize( array(
			'slides'   => $raw_slides,
			'carousel' => $raw_carousel,
		) );

		$renderer  = new Renderer();
		$hero_html = $renderer->render_from_settings( $clean );

		if ( '' === $hero_html ) {
			wp_send_json_success( array(
				'html'  => $this->preview_empty_html(),
				'empty' => true,
			) );
		}

		wp_send_json_success( array(
			'html'  => $this->preview_wrap_html( $hero_html ),
			'empty' => false,
		) );
	}

	/**
	 * URL del fonts.css del theme activo (Saira; Bebas e Inter están en su
	 * theme.json), '' si el theme no lo trae. El plugin es theme-agnostic: el
	 * guard evita 404 con otro theme.
	 */
	private static function theme_fonts_css_url(): string {
		if ( ! file_exists( get_stylesheet_directory() . '/assets/css/fonts.css' ) ) {
			return '';
		}
		return get_stylesheet_directory_uri() . '/assets/css/fonts.css';
	}

	/**
	 * Wrap del hero HTML en una página completa para servir en iframe srcdoc.
	 * Incluye CSS del frontend del plugin + body styles minimal.
	 */
	private function preview_wrap_html( string $hero_html ): string {
		$css_url = AKIBARA_BUSINESS_URL . 'assets/css/home-hero.css?ver=' . PluginAssetVersion::for_path( 'assets/css/home-hero.css' );
		$js_url  = AKIBARA_BUSINESS_URL . 'assets/js/home-hero.js?ver=' . PluginAssetVersion::for_path( 'assets/js/home-hero.js' );

		$js = '<script src="' . esc_url( $js_url ) . '"></script>';

		// La vista previa recibe los tokens reales del tema: variables de
		// wp_get_global_stylesheet, shim tokens.css, fonts.css y las font-face de
		// wp_print_font_faces. El srcdoc no pasa por wp_head y debe cargar esas
		// fuentes expresamente para coincidir con la portada.
		$links = '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">';
		foreach ( array( 'tokens.css', 'fonts.css' ) as $themed ) {
			if ( file_exists( get_stylesheet_directory() . '/assets/css/' . $themed ) ) {
				$links = '<link rel="stylesheet" href="' . esc_url( get_stylesheet_directory_uri() . '/assets/css/' . $themed . '?ver=' . ThemeAssetVersion::for_path( 'assets/css/' . $themed ) ) . '">' . $links;
			}
		}

		$global = function_exists( 'wp_get_global_stylesheet' ) ? wp_get_global_stylesheet( array( 'variables' ) ) : '';
		$globals_style = '' !== $global ? '<style>' . $global . '</style>' : '';

		$font_faces = '';
		if ( function_exists( 'wp_print_font_faces' ) ) {
			ob_start();
			wp_print_font_faces();
			$font_faces = (string) ob_get_clean();
		}
		$base_style    = '<style>html,body{margin:0;background:#fff;font-family:var(--font-body,system-ui);}</style>';

		return '<!DOCTYPE html><html lang="es"><head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Vista previa hero</title>'
			. $globals_style // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS de core (wp_get_global_stylesheet).
			. $font_faces // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- <style> de core (wp_print_font_faces), ya saneado por WP_Font_Face.
			. $links
			. $base_style
			. '</head><body>'
			. $hero_html
			. $js
			. '</body></html>';
	}

	/**
	 * HTML mostrado cuando los settings inyectados no producen ningún slide
	 * activo (todos is_active=false o sin imágenes).
	 */
	private function preview_empty_html(): string {
		return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Sin slides</title>'
			. '<style>body{margin:0;display:flex;align-items:center;justify-content:center;height:100vh;background:#f6f7f7;font-family:system-ui,sans-serif;}'
			. '.msg{text-align:center;color:#50575e;padding:24px;}'
			. '.msg strong{display:block;font-size:18px;margin-bottom:8px;color:#1d2327;}'
			. '.msg span{font-size:14px;}</style>'
			. '</head><body><div class="msg"><strong>No hay slides para mostrar</strong>'
			. '<span>Activa al menos una diapositiva con imagen y guarda para ver el hero.</span></div></body></html>';
	}

	/**
	 * Renderiza la sección completa del tab. Invocado por AdminPanel via
	 * `header_fn` callback (sección registrada como is_action=true).
	 */
	public function render_section(): void {
		$settings = Settings::get();
		$slides   = $settings['slides'];
		$carousel = $settings['carousel'];
		$admin_ui = $this;

		$template = dirname( __DIR__, 3 ) . '/templates/admin/home-hero/admin-page.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Obtiene URL y dimensiones de un adjunto.
	 *
	 * @param int $attachment_id
	 * @return array{url: string, w: int, h: int}
	 */
	private function get_attachment_dimensions( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array(
				'url' => '',
				'w'   => 0,
				'h'   => 0,
			);
		}
		$url  = (string) wp_get_attachment_image_url( $attachment_id, 'medium' );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$w    = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h    = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		return array(
			'url' => $url,
			'w'   => $w,
			'h'   => $h,
		);
	}

	/**
	 * Renderea un fieldset de slide.
	 *
	 * @param int                  $idx
	 * @param array<string, mixed> $slide
	 */
	public function render_slide_fieldset( int $idx, array $slide ): void {
		$desktop_id  = (int) ( $slide['desktop_id'] ?? 0 );
		$mobile_id   = (int) ( $slide['mobile_id'] ?? 0 );
		$desktop_img = $this->get_attachment_dimensions( $desktop_id );
		$mobile_img  = $this->get_attachment_dimensions( $mobile_id );

		$desktop_url = $desktop_img['url'];
		$desktop_w   = $desktop_img['w'];
		$desktop_h   = $desktop_img['h'];

		$mobile_url  = $mobile_img['url'];
		$mobile_w    = $mobile_img['w'];
		$mobile_h    = $mobile_img['h'];

		$is_active   = isset( $slide['is_active'] ) ? (bool) $slide['is_active'] : true;
		$slide_state = $is_active ? 'is-on' : 'is-off';

		// Parse object-position to focal % for picker.
		$focal_d  = $this->parse_focal( (string) ( $slide['object_position_desktop'] ?? 'center center' ) );
		$focal_m  = $this->parse_focal( (string) ( $slide['object_position_mobile'] ?? 'center 80%' ) );
		$admin_ui = $this;

		// Consume $idx to satisfy static analysis while passing into template scope.
		$akb_idx = $idx;

		$template = dirname( __DIR__, 3 ) . '/templates/admin/home-hero/slide-fieldset.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
		unset( $akb_idx );
	}

	/**
	 * Etiqueta legible para una clave de posición del grid 3×3.
	 */
	public function position_label( string $pos ): string {
		$labels = array(
			'top-left'     => 'Arriba · izquierda',
			'top-center'   => 'Arriba · centro',
			'top-right'    => 'Arriba · derecha',
			'middle-left'  => 'Centro · izquierda',
			'middle-center'=> 'Centro · centro',
			'middle-right' => 'Centro · derecha',
			'bottom-left'  => 'Abajo · izquierda',
			'bottom-center'=> 'Abajo · centro',
			'bottom-right' => 'Abajo · derecha',
		);
		return $labels[ $pos ] ?? $pos;
	}

	/**
	 * Parse object-position string ("center 80%", "50% 30%", "left top") to
	 * [x, y] percentages (0-100). Defaults [50, 50].
	 *
	 * @return array{0:int, 1:int}
	 */
	private function parse_focal( string $objpos ): array {
		$objpos = trim( $objpos );
		$tokens = preg_split( '/\s+/', $objpos );
		if ( ! is_array( $tokens ) ) {
			return array( 50, 50 );
		}
		$x_token = $tokens[0] ?? 'center';
		$y_token = $tokens[1] ?? 'center';

		$kw = array(
			'left'   => 0,
			'center' => 50,
			'right'  => 100,
			'top'    => 0,
			'middle' => 50,
			'bottom' => 100,
		);

		$resolve = static function ( string $token, int $default ) use ( $kw ): int {
			$token = strtolower( trim( $token ) );
			if ( isset( $kw[ $token ] ) ) {
				return $kw[ $token ];
			}
			if ( preg_match( '/^(\d+(?:\.\d+)?)%?$/', $token, $m ) ) {
				return max( 0, min( 100, (int) round( (float) $m[1] ) ) );
			}
			return $default;
		};

		return array( $resolve( $x_token, 50 ), $resolve( $y_token, 50 ) );
	}

	/**
	 * Renderea fieldset de un CTA.
	 *
	 * @param int                   $slide_idx
	 * @param int                   $cta_idx
	 * @param array<string, mixed>  $cta
	 */
	public function render_cta_fieldset( int $slide_idx, int $cta_idx, array $cta ): void {
		$style_current = (string) ( $cta['style'] ?? 'shop' );
		?>
		<div class="akb-hero-cta" data-cta-idx="<?php echo (int) $cta_idx; ?>">
			<input type="text"
				name="slides[<?php echo (int) $slide_idx; ?>][ctas][<?php echo (int) $cta_idx; ?>][label]"
				value="<?php echo esc_attr( (string) ( $cta['label'] ?? '' ) ); ?>"
				placeholder="Texto del botón (ej. Manga)"
				maxlength="40"
				class="akb-hero-cta__label"
				aria-label="Texto del botón CTA <?php echo (int) ( $cta_idx + 1 ); ?>">
			<input type="text"
				inputmode="url"
				autocomplete="url"
				name="slides[<?php echo (int) $slide_idx; ?>][ctas][<?php echo (int) $cta_idx; ?>][url]"
				value="<?php echo esc_url( (string) ( $cta['url'] ?? '' ) ); ?>"
				placeholder="URL (ej. /manga/)"
				class="akb-hero-cta__url"
				aria-label="URL del botón CTA <?php echo (int) ( $cta_idx + 1 ); ?>">
			<select name="slides[<?php echo (int) $slide_idx; ?>][ctas][<?php echo (int) $cta_idx; ?>][style]" class="akb-hero-cta__style" aria-label="Estilo del botón CTA <?php echo (int) ( $cta_idx + 1 ); ?>">
				<?php foreach ( Settings::CTA_STYLES as $style_key => $style_def ) : ?>
					<option value="<?php echo esc_attr( $style_key ); ?>" <?php selected( $style_current, $style_key ); ?>><?php echo esc_html( $style_def['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button-link akb-hero-cta__remove" data-action="remove-cta" aria-label="Eliminar CTA">×</button>
		</div>
		<?php
	}

	/**
	 * Renderea bloque de settings del carrusel.
	 *
	 * @param array<string, mixed> $carousel
	 */
	public function render_carousel_fieldset( array $carousel ): void {
		$akb_carousel = $carousel;
		$template     = dirname( __DIR__, 3 ) . '/templates/admin/home-hero/carousel-fieldset.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
		unset( $akb_carousel );
	}

	/**
	 * Template HTML inyectado al DOM cuando JS hace "+ Agregar slide".
	 * Placeholder `__INDEX__` será reemplazado en cliente.
	 */
	private function render_slide_template(): string {
		ob_start();
		$placeholder_slide = Settings::default_slide();
		$this->render_slide_fieldset( -1, $placeholder_slide );
		$html = (string) ob_get_clean();
		// Reemplazo del index por placeholder string. Buscamos todas las
		// ocurrencias de `[-1]` y `data-slide-idx="-1"` para `__INDEX__`,
		// y `Slide -...` no debería existir; mejor literal:
		$html = str_replace(
			array( 'data-slide-idx="-1"', 'slides[-1]' ),
			array( 'data-slide-idx="__INDEX__"', 'slides[__INDEX__]' ),
			$html
		);
		// El número visible "Slide 0" → JS lo sobreescribe vía data-slide-number.
		return $html;
	}

	/**
	 * Template para nuevos CTAs.
	 */
	private function render_cta_template(): string {
		ob_start();
		$this->render_cta_fieldset( -1, -1, array( 'style' => 'shop' ) );
		$html = (string) ob_get_clean();
		$html = str_replace(
			array( 'slides[-1]', '[ctas][-1]', 'data-cta-idx="-1"' ),
			array( 'slides[__SLIDE__]', '[ctas][__CTA__]', 'data-cta-idx="__CTA__"' ),
			$html
		);
		return $html;
	}
}
