<?php
/**
 * HomeHero\Renderer — markup frontend del hero / carrusel.
 *
 * Casos:
 *   - 0 slides activos    → render vacío (string ''). Sin error visible, sin
 *                            asset enqueue. El shortcode no rompe la página.
 *   - 1 slide             → hero estático. Sin JS, sin dots, sin arrows.
 *   - 2+ slides           → carrusel. Enqueue JS + dots/arrows según settings.
 *
 * Markup principles:
 *   - `<picture>` con `<source media="(max-width:768px)">` para mobile y
 *     desktop. Las URLs se resuelven desde WordPress uploads para que Cloudinary
 *     no reinterprete el hero ni cambie el recorte en producción. WebP-first
 *     si existe un archivo `.webp` adyacente, fallback al original.
 *   - `aria-roledescription="carousel"` solo cuando hay 2+ slides.
 *   - `aria-live="polite"` en track para anunciar cambios a screen readers.
 *   - Vignette opacity inline via CSS custom property `--akb-hero-vignette`.
 *   - object-position inline via CSS custom property `--akb-hero-objpos`.
 *
 * Performance:
 *   - Slide #1: `<picture>` SSR directo, img loading="eager" +
 *     fetchpriority="high".
 *   - Slide #2+: `<picture>` diferido dentro de `<template>`; el JS lo hidrata
 *     por intención/navegación o antes del autoplay. `<noscript>` conserva las
 *     imágenes, alt y dimensiones para navegación sin JavaScript.
 *   - Preload solo del slide #1 (filter `wp_preload_resources`).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeHero;

use Akibara\Business\Utilities\Text;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Nombre accesible del hero para lectores de pantalla, editable en
	 * Akibara → Textos.
	 */
	public const OPTION_LABEL = 'akb_copy_hero_label';

	public const DEFAULT_LABEL = 'Destacados';

	/**
	 * El nombre tal como se imprime: una línea, nunca vacío.
	 */
	public static function label(): string {
		return Text::one_line_option( self::OPTION_LABEL, self::DEFAULT_LABEL );
	}

	/**
	 * Secuencia de render dentro de la request para evitar IDs DOM duplicados
	 * cuando una página contiene más de un shortcode del hero.
	 */
	private static int $render_sequence = 0;

	/**
	 * Memoize de URLs locales por instancia. Evita N+1 cuando
	 * `get_preload_urls()` y `render_slide()` resuelven el mismo attachment.
	 *
	 * @var array<int, string>
	 */
	private array $url_cache = array();

	/**
	 * Memoize de `srcset`/`sizes` generados por WordPress por attachment.
	 *
	 * @var array<int, array{srcset:string,sizes:string}>
	 */
	private array $responsive_cache = array();

	/**
	 * Renderiza el hero como string HTML. Usado por shortcode `[akb_hero]`.
	 */
	public function render(): string {
		return $this->render_from_settings( Settings::get() );
	}

	/**
	 * Devuelve la URL local del attachment (size `full`), memoizando por instancia.
	 *
	 * El hero usa la Media Library como source of truth pero evita delivery vía
	 * Cloudinary para mantener el encuadre idéntico entre Docker y producción.
	 */
	private function get_image_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		if ( ! isset( $this->url_cache[ $attachment_id ] ) ) {
			$this->url_cache[ $attachment_id ] = self::get_local_attachment_url( $attachment_id );
		}
		return $this->url_cache[ $attachment_id ];
	}

	/**
	 * Obtiene los derivados responsive ya registrados por WordPress.
	 *
	 * `full` conserva la relación de aspecto del arte elegido y deja que core
	 * descarte tamaños intermedios incompatibles. Si WordPress no entrega
	 * `sizes`, `100vw` describe el ancho real del hero full-bleed.
	 *
	 * @return array{srcset:string,sizes:string}
	 */
	private function get_responsive_attributes( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array(
				'srcset' => '',
				'sizes'  => '',
			);
		}

		if ( ! isset( $this->responsive_cache[ $attachment_id ] ) ) {
			$srcset = wp_get_attachment_image_srcset( $attachment_id, 'full' );
			$sizes  = false !== $srcset
				? wp_get_attachment_image_sizes( $attachment_id, 'full' )
				: false;

			$srcset = is_string( $srcset ) ? trim( $srcset ) : '';
			$sizes  = is_string( $sizes ) ? trim( $sizes ) : '';

			$this->responsive_cache[ $attachment_id ] = array(
				'srcset' => $srcset,
				'sizes'  => '' !== $srcset ? ( '' !== $sizes ? $sizes : '100vw' ) : '',
			);
		}

		return $this->responsive_cache[ $attachment_id ];
	}

	/**
	 * Prima juntos post y metadatos de todos los adjuntos del hero para que
	 * preload, srcset y render reutilicen esa caché.
	 *
	 * @param array<int, array<string, mixed>> $slides Diapositivas activas.
	 */
	private static function prime_slide_attachments( array $slides ): void {
		if ( ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		$ids = array();
		foreach ( $slides as $slide ) {
			foreach ( array( 'desktop_id', 'mobile_id' ) as $key ) {
				$id = (int) ( $slide[ $key ] ?? 0 );
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}
		if ( array() !== $ids ) {
			_prime_post_caches( array_values( $ids ), false, true );
		}
	}

	/**
	 * Resuelve la URL local del attachment desde `uploads/`, sin pasar por
	 * filtros de delivery de Cloudinary.
	 */
	private static function get_local_attachment_url( int $attachment_id ): string {
		if ( $attachment_id <= 0 ) {
			return '';
		}

		$attached_file = (string) get_attached_file( $attachment_id );
		if ( '' === $attached_file ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$basedir = rtrim( str_replace( '\\', '/', (string) $uploads['basedir'] ), '/' );
		$baseurl = rtrim( (string) $uploads['baseurl'], '/' );
		$file    = str_replace( '\\', '/', $attached_file );

		if ( ! str_starts_with( $file, $basedir ) ) {
			$file = $basedir . '/' . ltrim( $file, '/' );
		}

		$relative = ltrim( substr( $file, strlen( $basedir ) ), '/' );
		if ( '' === $relative ) {
			return '';
		}

		return $baseurl . '/' . $relative;
	}

	/**
	 * Renderiza el hero desde un settings array inyectado (sin leer BD).
	 *
	 * Usado por el endpoint AJAX de preview (capa 2) para mostrar el hero con
	 * cambios no guardados todavía. El array debe haber pasado por
	 * `Settings::sanitize()` antes para garantizar fidelidad con el save real.
	 *
	 * @param array<string, mixed> $settings Settings ya sanitizados.
	 */
	public function render_from_settings( array $settings ): string {

		// Settings::active_slides() decide la visibilidad tanto en vista previa
		// como en portada. Guardar datos incompletos no cambia ese criterio.
		$slides = Settings::active_slides( $settings );

		if ( empty( $slides ) ) {
			return '';
		}

		self::prime_slide_attachments( $slides );

		$count       = count( $slides );
		$is_carousel = $count > 1;
		$carousel    = $settings['carousel'];
		$instance_id = sprintf(
			'akb-hero-%d-%d',
			max( 0, (int) get_the_ID() ),
			++self::$render_sequence
		);

		$primary_heading_index = null;
		foreach ( $slides as $idx => $slide ) {
			if ( '' !== trim( (string) ( $slide['title'] ?? '' ) ) ) {
				$primary_heading_index = $idx;
				break;
			}
		}

		// Resolver altura del hero según preset o custom.
		$preset = (string) ( $carousel['hero_height'] ?? 'standard' );
		if ( 'custom' === $preset ) {
			$h_desktop = (int) ( $carousel['hero_height_custom_desktop'] ?? 700 ) . 'px';
			// min(50): settings guardados antes del techo (ej. 65) rendían un var
			// que el CSS igual capea — emitir el valor efectivo evita el gaslight
			// en DevTools y en el ancla de los dots.
			$h_mobile  = min( 50, (int) ( $carousel['hero_height_custom_mobile'] ?? 50 ) ) . 'vh';
		} else {
			$p = Settings::HEIGHT_PRESETS[ $preset ] ?? Settings::HEIGHT_PRESETS['standard'];
			$h_desktop = (string) $p['desktop'];
			$h_mobile  = (string) $p['mobile'];
		}
		$root_style = sprintf(
			'--akb-hero-height-desktop:%s;--akb-hero-height-mobile:%s;',
			esc_attr( $h_desktop ),
			esc_attr( $h_mobile )
		);

		ob_start();

		$root_classes = array( 'akb-hero', 'alignfull' );
		if ( $is_carousel ) {
			$root_classes[] = 'akb-hero--carousel';
		}

		$data_attrs = '';
		if ( $is_carousel ) {
			// data-attrs comunican config del JS al frontend. Sin `transition` —
			// el patrón moderno usa scroll-snap nativo (slide horizontal) siempre.
			$data_attrs = sprintf(
				' data-akb-hero data-autoplay="%s" data-interval="%d" data-pause-hover="%s" data-pause-blur="%s" data-loop="%s"',
				$carousel['autoplay'] ? '1' : '0',
				(int) $carousel['interval_seconds'] * 1000,
				$carousel['pause_on_hover'] ? '1' : '0',
				$carousel['pause_on_blur'] ? '1' : '0',
				$carousel['loop'] ? '1' : '0'
			);
		}
		?>
		<?php
		// En el hero estático, la referencia accesible usa el ID único del primer
		// título real. Si no existe título, el nombre del dueño (Akibara → Textos).
		$single_aria = null !== $primary_heading_index
			? sprintf( 'aria-labelledby="%s-title-%d"', $instance_id, $primary_heading_index )
			: 'aria-label="' . esc_attr( self::label() ) . '"';
		?>
		<section
			class="<?php echo esc_attr( implode( ' ', $root_classes ) ); ?>"
			style="<?php echo esc_attr( $root_style ); ?>"
			<?php
			if ( ! $is_carousel ) {
				echo $single_aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			} else {
				echo 'aria-roledescription="carrusel" aria-label="' . esc_attr( self::label() ) . '"';
			}
			echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf-built string with esc_attr() values.
			?>
		>
			<div class="akb-hero__viewport">
				<?php
				// ARIA basic carousel pattern (APG):
				//   - track sin aria-live constante; anuncios solo en cambios manuales
				//     via .akb-hero__sr-status (region polite). Evita screen reader spam.
				//   - los segmentos del indicador son <button> normales con
				//     aria-current="true" para el activo (en lugar de role=tab +
				//     aria-selected, que requiere focus management completo no
				//     implementado). Más simple y válido WAI ARIA.
				//   - arrows sin aria-controls (apuntaba a id inexistente).
				?>
				<?php if ( $is_carousel ) : ?>
					<?php
					// El indicador precede al track en el DOM para que el foco siga el orden
					// visual. Usa un botón por diapositiva con aria-current; autoplay anima
					// el segmento activo y tiene un control separado de pausa/reanudación.
					// Elegir una diapositiva no cambia la pausa. Sin JavaScript conserva el
					// estado activo renderizado por servidor.
					// Los flags show_dots controlan el indicador; con autoplay se ignora su
					// ocultación para mantener disponible el control exigido por WCAG 2.2.2.
					$autoplay_on  = ! empty( $carousel['autoplay'] );
					$hide_desktop = ! $autoplay_on && empty( $carousel['show_dots_desktop'] );
					$hide_mobile  = ! $autoplay_on && empty( $carousel['show_dots_mobile'] );
					?>
					<?php if ( ! $hide_desktop || ! $hide_mobile ) : ?>
						<div
							class="akb-hero__progress
								<?php echo $hide_desktop ? 'akb-hero__progress--hide-desktop ' : ''; ?>
								<?php echo $hide_mobile  ? 'akb-hero__progress--hide-mobile '  : ''; ?>"
							role="group"
							aria-label="Progreso del carrusel"
							hidden
						>
								<?php foreach ( $slides as $idx => $slide ) : ?>
									<?php $slide_label = $this->slide_accessible_label( $slide, $idx, $count ); ?>
									<button
										type="button"
										class="akb-hero__seg <?php echo 0 === $idx ? 'is-active' : ''; ?>"
										<?php echo 0 === $idx ? 'aria-current="true"' : ''; ?>
										aria-label="<?php echo esc_attr( 'Ir al ' . lcfirst( $slide_label ) ); ?>"
										data-slide="<?php echo (int) $idx; ?>"
									><span class="akb-hero__seg-fill" data-akb-seg-fill aria-hidden="true"></span></button>
								<?php endforeach; ?>

							<?php if ( $autoplay_on ) : ?>
								<button
									type="button"
									class="akb-hero__progress-toggle"
									aria-pressed="false"
									aria-label="Pausar rotación automática"
									data-akb-progress
								>
									<span class="akb-hero__progress-toggle-icon" aria-hidden="true"></span>
								</button>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php /* Contenido INICIAL de la live region, sembrado server-side: los
						SR no anuncian el contenido inicial, solo los cambios. Da el contexto
						"Slide 1 de N" sin spam de carga; el JS solo la actualiza al navegar. */ ?>
						<span class="akb-hero__sr-status" role="status" aria-live="polite" aria-atomic="true"><?php echo esc_html( $this->slide_accessible_label( $slides[0], 0, $count ) ); ?></span>
					<?php endif; ?>

					<div class="akb-hero__track" id="<?php echo esc_attr( $instance_id . '-track' ); ?>">
						<?php foreach ( $slides as $idx => $slide ) : ?>
							<?php
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_slide() escapa dentro; el sniff marca además cada argumento de la llamada multilínea.
							echo $this->render_slide(
								$slide,
								$idx,
								$is_carousel,
								$count,
								$instance_id,
								$idx === $primary_heading_index
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						<?php endforeach; ?>
					</div>

				<?php if ( $is_carousel && ! empty( $carousel['show_arrows_desktop'] ) ) : ?>
					<button type="button" class="akb-hero__arrow akb-hero__arrow--prev" aria-label="Slide anterior" hidden>
						<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
					<button type="button" class="akb-hero__arrow akb-hero__arrow--next" aria-label="Slide siguiente" hidden>
						<svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
					</button>
				<?php endif; ?>

			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renderiza un slide individual.
	 *
	 * @param array<string, mixed> $slide
	 * @param int                  $idx
	 * @param bool                 $is_carousel
	 * @param int                  $slide_count
	 * @param string               $instance_id
	 * @param bool                 $is_primary_heading
	 */
	private function render_slide(
		array $slide,
		int $idx,
		bool $is_carousel,
		int $slide_count,
		string $instance_id,
		bool $is_primary_heading
	): string {
		$desktop_id = (int) ( $slide['desktop_id'] ?? 0 );
		$mobile_id  = (int) ( $slide['mobile_id'] ?? 0 );

		if ( 0 === $desktop_id && 0 === $mobile_id ) {
			return '';
		}

		$alt        = (string) ( $slide['alt'] ?? '' );
		$eyebrow    = (string) ( $slide['eyebrow'] ?? '' );
		$title      = (string) ( $slide['title'] ?? '' );
		$lede       = (string) ( $slide['lede'] ?? '' );

		$title_color  = (string) ( $slide['title_color'] ?? '' );
		$title_font   = (string) ( $slide['title_font'] ?? '' );
		$title_weight = (string) ( $slide['title_weight'] ?? '' );

		$lede_color   = (string) ( $slide['lede_color']  ?? '' );
		$lede_font    = (string) ( $slide['lede_font'] ?? '' );
		$lede_weight  = (string) ( $slide['lede_weight'] ?? '' );

		$objpos_d = (string) ( $slide['object_position_desktop'] ?? 'center center' );
		$objpos_m = (string) ( $slide['object_position_mobile']  ?? 'center 80%' );

		// Encuadre tablet (601-768): explícito del slide, o derivado del mobile
		// con la Y capada (la caja tablet ve una banda más corta del arte — un
		// Y de 80% afinado para phone decapita al sujeto). ÚNICA fuente de la
		// derivación; el CSS solo consume la var.
		$objpos_t = (string) ( $slide['object_position_tablet'] ?? '' );
		if ( '' === $objpos_t ) {
			$objpos_t = Settings::derive_tablet_object_position( $objpos_m );
		}

		$opacity = (int) ( $slide['vignette_opacity'] ?? 50 );
		$opacity = max( 0, min( 100, $opacity ) );

		$link_url = (string) ( $slide['link_url'] ?? '' );

		$desktop_src = $this->get_image_url( $desktop_id );
		$mobile_src  = $this->get_image_url( $mobile_id );
		$desktop_responsive_id = $desktop_id;
		$mobile_responsive_id  = $mobile_id;

		// Si solo hay 1 imagen, usar la misma para ambos breakpoints.
		if ( '' === $desktop_src && '' !== $mobile_src ) {
			$desktop_src           = $mobile_src;
			$desktop_responsive_id = $mobile_id;
		}
		if ( '' === $mobile_src && '' !== $desktop_src ) {
			$mobile_src           = $desktop_src;
			$mobile_responsive_id = $desktop_responsive_id;
		}

		$desktop_responsive = $this->get_responsive_attributes( $desktop_responsive_id );
		$mobile_responsive  = $this->get_responsive_attributes( $mobile_responsive_id );

		// WebP-adjacent detection: si existe un archivo .webp con el mismo
		// basename que el attachment cargado (ej. owner subió .png pero también
		// optimizó manualmente a .webp con cwebp), emitimos un <source webp>
		// adicional para que el browser elija WebP cuando lo soporta. Memoria
		// `project_lcp_optimization_2026_05_07`: WebP del hero pesa ~6 % del
		// PNG (105 KB vs 1.81 MB). Sin esta detección, el plugin degradaría
		// LCP respecto al markup `<!-- wp:html -->` legacy.
		$desktop_webp = self::find_adjacent_webp( $desktop_src );
		$mobile_webp  = self::find_adjacent_webp( $mobile_src );

		// Intrinsic dims del attachment principal (desktop) para evitar CLS.
		$dims = $desktop_id ? self::get_local_attachment_dimensions( $desktop_id ) : array( 1920, 811 );
		$w    = (int) ( $dims[0] ?? 1920 );
		$h    = (int) ( $dims[1] ?? 811 );

		$slide_classes = array( 'akb-hero__slide' );
		if ( 0 === $idx ) {
			$slide_classes[] = 'is-active';
		}

		$style_parts = array(
			sprintf( '--akb-hero-vignette:%d%%', $opacity ),
			sprintf( '--akb-hero-objpos-desktop:%s', $objpos_d ),
			sprintf( '--akb-hero-objpos-mobile:%s', $objpos_m ),
			sprintf( '--akb-hero-objpos-tablet:%s', $objpos_t ),
		);
		// Banda 769-900: el crop 1200/750 tiene su propio default afinado (58%
		// center en CSS). 'center center' LITERAL = sentinel "pin nunca tocado"
		// (default de Settings::default_slide()): solo si el owner movió el pin
		// desktop emitimos la var y el pin manda también en la banda. El picker
		// escribe 'N% N%', así que centrar explícito ('50% 50%') NO es sentinel.
		if ( 'center center' !== $objpos_d ) {
			$style_parts[] = sprintf( '--akb-hero-objpos-band:%s', $objpos_d );
		}
		if ( '' !== $title_color ) {
			$style_parts[] = sprintf( '--akb-hero-title-color:%s', $title_color );
		}
		if ( '' !== $title_font ) {
			$style_parts[] = sprintf( '--akb-hero-title-font:%s, sans-serif', self::css_font_family( $title_font ) );
		}
		if ( '' !== $title_weight ) {
			$style_parts[] = sprintf( '--akb-hero-title-weight:%s', $title_weight );
		}
		if ( '' !== $lede_color ) {
			$style_parts[] = sprintf( '--akb-hero-lede-color:%s', $lede_color );
		}
		if ( '' !== $lede_font ) {
			$style_parts[] = sprintf( '--akb-hero-lede-font:%s, sans-serif', self::css_font_family( $lede_font ) );
		}
		if ( '' !== $lede_weight ) {
			$style_parts[] = sprintf( '--akb-hero-lede-weight:%s', $lede_weight );
		}
		$style = implode( ';', $style_parts ) . ';';

		// Posicionamiento del bloque de texto.
		$text_position      = (string) ( $slide['text_position'] ?? 'bottom-left' );
		$text_align         = (string) ( $slide['text_align']    ?? 'left' );
		// El modo below usa una caja obi a caballo entre el arte y su banda inferior
		// en anchos de hasta 768 px, incluido tablet 601–768. Conserva el valor guardado
		// y los bloques correspondientes de home-hero.css; no lo migra a overlay.
			$mobile_text_layout = (string) ( $slide['mobile_text_layout'] ?? 'below' );
			$slide_label       = $this->slide_accessible_label( $slide, $idx, $slide_count );

		$content_classes = array(
			'akb-hero__content',
			'akb-hero__content--' . sanitize_html_class( $text_position ),
			'akb-hero__content--align-' . sanitize_html_class( $text_align ),
		);

		$content_has_anything = ( '' !== $eyebrow || '' !== $title || '' !== $lede || ! empty( $slide['ctas'] ) );

		ob_start();
		?>
		<div
			class="<?php echo esc_attr( implode( ' ', $slide_classes ) ); ?>"
			style="<?php echo esc_attr( $style ); ?>"
			data-mobile-layout="<?php echo esc_attr( $mobile_text_layout ); ?>"
				<?php if ( $is_carousel ) : ?>
					role="group"
					aria-roledescription="slide"
					aria-label="<?php echo esc_attr( $slide_label ); ?>"
				<?php endif; ?>
		>
			<?php
			$picture_markup = $this->render_picture(
				$desktop_src,
				$mobile_src,
				$desktop_webp,
				$mobile_webp,
				$desktop_responsive['srcset'],
				$desktop_responsive['sizes'],
				$mobile_responsive['srcset'],
				$mobile_responsive['sizes'],
				$alt,
				$w,
				$h,
				0 === $idx
			);
			?>
			<?php if ( 0 === $idx ) : ?>
				<?php echo $picture_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
			<?php else : ?>
				<template data-akb-hero-media>
					<?php echo $picture_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
				</template>
				<noscript>
					<?php echo $picture_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped within helper. ?>
				</noscript>
			<?php endif; ?>
			<div class="akb-hero__vignette" aria-hidden="true"></div>

			<?php if ( $content_has_anything ) : ?>
				<div class="<?php echo esc_attr( implode( ' ', $content_classes ) ); ?>">
					<?php if ( '' !== $eyebrow ) : ?>
						<p class="akb-hero__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
					<?php endif; ?>

					<?php if ( '' !== $title ) : ?>
						<?php
						// El primer título no vacío emite <h1>. Los títulos restantes
						// usan <h2>, aunque el primer slide editorial no tenga título.
						$title_tag = $is_primary_heading ? 'h1' : 'h2';
						?>
						<<?php echo esc_html( $title_tag ); ?>
							id="<?php echo esc_attr( $instance_id . '-title-' . $idx ); ?>"
							class="akb-hero__title"
						><?php echo esc_html( $title ); ?></<?php echo esc_html( $title_tag ); ?>>
					<?php endif; ?>

					<?php if ( '' !== $lede ) :
						$lede_style_class = '';
						$ls = (string) ( $slide['lede_style'] ?? 'plain' );
						if ( 'plain' !== $ls ) {
							$lede_style_class = ' akb-hero__lede--' . sanitize_html_class( $ls );
						}
						?>
						<p class="akb-hero__lede<?php echo esc_attr( $lede_style_class ); ?>"><?php echo esc_html( $lede ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $slide['ctas'] ) && is_array( $slide['ctas'] ) ) : ?>
						<?php
						// Un grupo de botones, no un landmark de navegación: con un
						// <nav> por diapositiva el lector listaba «Acciones del slide»
						// entre las regiones de la página (AI-13).
						?>
						<div class="akb-hero__actions">
							<?php foreach ( $slide['ctas'] as $cta ) :
								$cta_label = (string) ( $cta['label'] ?? '' );
								$cta_url   = (string) ( $cta['url']   ?? '' );
								$cta_style = (string) ( $cta['style'] ?? 'shop' );
								if ( '' === $cta_label || '' === $cta_url ) {
									continue;
								}
								$cta_class = 'akb-hero__action akb-hero__action--' . sanitize_html_class( $cta_style );
								?>
								<a class="<?php echo esc_attr( $cta_class ); ?>" href="<?php echo esc_url( $cta_url ); ?>"><?php echo esc_html( $cta_label ); ?></a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $link_url && empty( $slide['ctas'] ) ) : ?>
				<a class="akb-hero__link" href="<?php echo esc_url( $link_url ); ?>" aria-label="<?php echo esc_attr( '' !== $alt ? $alt : ( '' !== $title ? $title : 'Ir al destino' ) ); ?>"></a>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Construye el `<picture>` completo de un slide.
	 *
	 * El mismo markup se usa en el `<template>` hidratable y en `<noscript>` para
	 * que ambos caminos conserven URLs, alt, dimensiones intrínsecas y selección
	 * responsive. Solo el primer slide es candidato LCP.
	 */
	private function render_picture(
		string $desktop_src,
		string $mobile_src,
		string $desktop_webp,
		string $mobile_webp,
		string $desktop_srcset,
		string $desktop_sizes,
		string $mobile_srcset,
		string $mobile_sizes,
		string $alt,
		int $width,
		int $height,
		bool $is_lcp
	): string {
		$loading       = $is_lcp ? 'eager' : 'lazy';
		$fetchpriority = $is_lcp ? ' fetchpriority="high"' : '';

		ob_start();
		?>
		<picture class="akb-hero__picture" data-no-lazy="1">
			<?php if ( '' !== $mobile_webp ) : ?>
				<source media="(max-width: 768px)" srcset="<?php echo esc_url( $mobile_webp ); ?>" type="image/webp">
			<?php endif; ?>
			<?php if ( '' !== $mobile_srcset ) : ?>
				<source
					media="(max-width: 768px)"
					srcset="<?php echo esc_attr( $mobile_srcset ); ?>"
					sizes="<?php echo esc_attr( $mobile_sizes ); ?>"
				>
			<?php else : ?>
				<source media="(max-width: 768px)" srcset="<?php echo esc_url( $mobile_src ); ?>">
			<?php endif; ?>
			<?php if ( '' !== $desktop_webp ) : ?>
				<source srcset="<?php echo esc_url( $desktop_webp ); ?>" type="image/webp">
			<?php endif; ?>
				<img
					src="<?php echo esc_url( $desktop_src ); ?>"
					alt="<?php echo esc_attr( $alt ); ?>"
					<?php if ( '' === trim( $alt ) ) : ?>
						aria-hidden="true"
					<?php endif; ?>
					class="akb-hero__img skip-lazy"
				width="<?php echo (int) $width; ?>"
				height="<?php echo (int) $height; ?>"
				data-no-lazy="1"
				loading="<?php echo esc_attr( $loading ); ?>"<?php echo $fetchpriority; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?>
				decoding="async"
				<?php if ( '' !== $desktop_srcset ) : ?>
					srcset="<?php echo esc_attr( $desktop_srcset ); ?>"
					sizes="<?php echo esc_attr( $desktop_sizes ); ?>"
				<?php endif; ?>
			>
		</picture>
		<?php
			return (string) ob_get_clean();
	}

	/**
	 * Nombre accesible con posición y título editorial. Si no hay título, la
	 * posición por sí sola sigue dando contexto suficiente dentro del carrusel.
	 *
	 * @param array<string, mixed> $slide
	 */
	private function slide_accessible_label( array $slide, int $idx, int $count ): string {
		$label = sprintf( 'Slide %d de %d', $idx + 1, $count );
		$title = trim( (string) ( $slide['title'] ?? '' ) );
		return '' !== $title ? $label . ': ' . $title : $label;
	}

	/**
	 * Devuelve los URLs del primer slide (desktop + mobile) para preload. Vacío
	 * si no hay slides. Prefiere `.webp` adyacente si existe.
	 *
	 * `imagesrcset`/`imagesizes` solo se exponen si el preload usa el formato
	 * original. Un WebP adyacente no comparte los candidatos del attachment y
	 * mezclarlos haría que el preload y el `<picture>` eligieran recursos
	 * distintos.
	 *
	 * @return array{
	 *   desktop:string,
	 *   mobile:string,
	 *   desktop_srcset:string,
	 *   desktop_sizes:string,
	 *   mobile_srcset:string,
	 *   mobile_sizes:string
	 * }
	 */
	public function get_preload_urls(): array {
		$settings = Settings::get();
		$slides = Settings::active_slides( $settings );
		if ( empty( $slides ) ) {
			return array(
				'desktop'         => '',
				'mobile'          => '',
				'desktop_srcset'  => '',
				'desktop_sizes'   => '',
				'mobile_srcset'   => '',
				'mobile_sizes'    => '',
			);
		}
		$first                 = $slides[0];
		self::prime_slide_attachments( $slides );
		$desktop_id            = (int) ( $first['desktop_id'] ?? 0 );
		$mobile_id             = (int) ( $first['mobile_id'] ?? 0 );
		$desktop_responsive_id = $desktop_id;
		$mobile_responsive_id  = $mobile_id;
		$desktop_url           = $this->get_image_url( $desktop_id );
		$mobile_url            = $this->get_image_url( $mobile_id );
		if ( '' === $desktop_url && '' !== $mobile_url ) {
			$desktop_url           = $mobile_url;
			$desktop_responsive_id = $mobile_id;
		}
		if ( '' === $mobile_url && '' !== $desktop_url ) {
			$mobile_url           = $desktop_url;
			$mobile_responsive_id = $desktop_responsive_id;
		}

		// Prefiere WebP adyacente para preload (LCP critical).
		$desktop_webp = self::find_adjacent_webp( $desktop_url );
		$mobile_webp  = self::find_adjacent_webp( $mobile_url );
		$desktop_responsive = '' === $desktop_webp
			? $this->get_responsive_attributes( $desktop_responsive_id )
			: array( 'srcset' => '', 'sizes' => '' );
		$mobile_responsive = '' === $mobile_webp
			? $this->get_responsive_attributes( $mobile_responsive_id )
			: array( 'srcset' => '', 'sizes' => '' );

		return array(
			'desktop'        => '' !== $desktop_webp ? $desktop_webp : $desktop_url,
			'mobile'         => '' !== $mobile_webp  ? $mobile_webp  : $mobile_url,
			'desktop_srcset' => $desktop_responsive['srcset'],
			'desktop_sizes'  => $desktop_responsive['sizes'],
			'mobile_srcset'  => $mobile_responsive['srcset'],
			'mobile_sizes'   => $mobile_responsive['sizes'],
		);
	}

	/**
	 * Si el URL apunta a una imagen no-WebP, intenta encontrar el archivo
	 * `.webp` adyacente en filesystem (mismo basename, mismo dir). Devuelve
	 * el URL del WebP si existe, '' si no.
	 *
	 * Soporte para owners que optimizan manualmente con cwebp pero no suben
	 * el WebP como attachment WP (caso real Akibara, ver memory
	 * `project_lcp_optimization_2026_05_07`).
	 *
	 * Cache de dos niveles:
	 *   1. Static array per-request — evita re-resolución dentro del mismo render.
	 *   2. Transient 12h cross-request — evita N `file_exists()` syscalls en cache
	 *      miss del HTML. Se invalida en `attachment_updated`, `delete_attachment`
	 *      y `update_option_akibara_hero_settings` (ver HomeHero::register).
	 */
	private static function find_adjacent_webp( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		// Nivel 1 — request-scoped.
		static $cache = array();
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}

		// Nivel 2 — cross-request transient.
		$transient_key = 'akb_hero_webp_' . md5( $url );
		$cached        = get_transient( $transient_key );
		if ( false !== $cached ) {
			// `'0'` literal codifica "no existe webp adyacente" (lo distingue del
			// cache miss `false`). Evita revalidación file_exists() en cada render.
			$value         = ( '0' === $cached ) ? '' : (string) $cached;
			$cache[ $url ] = $value;
			return $value;
		}

		// Resolución real (única ruta con syscalls).
		$found = self::resolve_adjacent_webp( $url );

		$cache[ $url ] = $found;
		set_transient( $transient_key, '' !== $found ? $found : '0', 12 * HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Resolución filesystem del `.webp` adyacente. Sin cache — lo añade el caller
	 * (`find_adjacent_webp`). Devuelve URL del webp si existe, '' si no.
	 */
	private static function resolve_adjacent_webp( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return '';
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'png', 'jpg', 'jpeg', 'gif' ), true ) ) {
			return '';
		}

		// Build filesystem path. Solo manejamos URLs que cuelguen de uploads/
		// para evitar leer paths arbitrarios.
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}

		$baseurl = (string) $uploads['baseurl'];
		if ( 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}

		$rel = substr( $url, strlen( $baseurl ) );
		$rel = ltrim( $rel, '/' );
		$rel = preg_replace( '/\.(png|jpe?g|gif)$/i', '.webp', $rel );
		if ( ! is_string( $rel ) || '' === $rel ) {
			return '';
		}

		$candidate_path = rtrim( (string) $uploads['basedir'], '/' ) . '/' . $rel;
		if ( file_exists( $candidate_path ) ) {
			return rtrim( $baseurl, '/' ) . '/' . $rel;
		}

		return '';
	}

	/**
	 * Familia CSS para el inline style: nombres propios van entre comillas;
	 * 'system-ui' es keyword genérica y va SIN comillas — entre comillas el
	 * browser la trata como nombre de familia inexistente y cae a sans-serif
	 * (el select "Sistema" del admin parecía no hacer nada).
	 */
	private static function css_font_family( string $family ): string {
		return 'system-ui' === $family ? 'system-ui' : '"' . $family . '"';
	}

	/**
	 * Resuelve las dimensiones del attachment desde metadata local.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function get_local_attachment_dimensions( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return array( 1920, 811 );
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && isset( $meta['width'], $meta['height'] ) ) {
			return array(
				max( 1, (int) $meta['width'] ),
				max( 1, (int) $meta['height'] ),
			);
		}

		return array( 1920, 811 );
	}
}
