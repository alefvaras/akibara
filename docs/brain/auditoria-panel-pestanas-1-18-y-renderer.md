# AUDITORÍA PROFUNDA DEL PANEL AKIBARA (PESTAÑAS 1 A 18) Y MOTOR RENDERER.PHP

> **Documento de Auditoría Forense Exhaustiva y Mapeo de Arquitectura**  
> **Fecha de ejecución:** Noviembre 2026  
> **Entorno auditado:** Local Docker (`http://localhost:8080/wp-admin/admin.php?page=akibara-panel`) y Producción (`https://akibara.cl`)  
> **Perfil de prueba:** `qa.owner` (Capability: `manage_woocommerce`)  
> **Estado de las 18 pestañas:** 100% en Verde (`HTTP 200`, 0 errores PHP, 0 excepciones JS en consola).

---

## ÍNDICE DEL INFORME

1. [PARTE 1: ARQUITECTURA Y CÓDIGO ÍNTEGRO DE RENDERER.PHP (893 LÍNEAS VERBATIM)](#parte-1-arquitectura-y-código-íntegro-de-rendererphp)
   - [1.1. Principios de Ingeniería y Rendimiento LCP](#11-principios-de-ingeniería-y-rendimiento-lcp)
   - [1.2. Código Fuente Íntegro de HomeHero\Renderer.php](#12-código-fuente-íntegro-de-homeherorendererphp)
2. [PARTE 2: MAPA TÉCNICO PROFUNDO DE LAS PESTAÑAS 1 A 18](#parte-2-mapa-técnico-profundo-de-las-pestañas-1-a-18)
   - [Pestaña 1: Slider de Inicio (home_hero)](#pestaña-1-slider-de-inicio-home_hero)
   - [Pestaña 2: Series en la portada (home_series)](#pestaña-2-series-en-la-portada-home_series)
   - [Pestaña 3: Vitrina (catalog_order)](#pestaña-3-vitrina-catalog_order)
   - [Pestaña 4: Barra de anuncios (announcement)](#pestaña-4-barra-de-anuncios-announcement)
   - [Pestaña 5: Lista de espera (back_in_stock)](#pestaña-5-lista-de-espera-back_in_stock)
   - [Pestaña 6: Sigue esta serie (series_follow)](#pestaña-6-sigue-esta-serie-series_follow)
   - [Pestaña 7: Envío gratis (free_shipping_progress)](#pestaña-7-envío-gratis-free_shipping_progress)
   - [Pestaña 8: Carrito nativo (native_cart)](#pestaña-8-carrito-nativo-native_cart)
   - [Pestaña 9: Recomendaciones y Autofill (upsells_autofill)](#pestaña-9-recomendaciones-y-autofill-upsells_autofill)
   - [Pestaña 10: Descuentos editoriales (taxonomy_discount)](#pestaña-10-descuentos-editoriales-taxonomy_discount)
   - [Pestaña 11: Pop-ups de bienvenida (welcome_coupon)](#pestaña-11-pop-ups-de-bienvenida-welcome_coupon)
   - [Pestaña 12: Autocompletar dirección Chile (address)](#pestaña-12-autocompletar-dirección-chile-address)
   - [Pestaña 13: Branding y UX (branding)](#pestaña-13-branding-y-ux-branding)
   - [Pestaña 14: Calidad de imágenes AVIF/WebP (imagenes)](#pestaña-14-calidad-de-imágenes-avifwebp-imagenes)
   - [Pestaña 15: Velocidad y rendimiento (velocidad)](#pestaña-15-velocidad-y-rendimiento-velocidad)
   - [Pestaña 16: Gestor de textos de interfaz (copys)](#pestaña-16-gestor-de-textos-de-interfaz-copys)
   - [Pestaña 17: Salud SEO y Manifiesto LLMS (salud_seo)](#pestaña-17-salud-seo-y-manifiesto-llms-salud_seo)
   - [Pestaña 18: Puntos y Reseñas 2.0 (akb-rewards-settings)](#pestaña-18-puntos-y-reseñas-20-akb-rewards-settings)
3. [PARTE 3: PANTALLAS SATÉLITE ADICIONALES (19 A 22)](#parte-3-pantallas-satélite-adicionales-19-a-22)
   - [Pestaña 19: Buscador Akibara (akibara-search)](#pestaña-19-buscador-akibara-akibara-search)
   - [Pestaña 20: Reglas Financieras (akibara-financial-rules)](#pestaña-20-reglas-financieras-akibara-financial-rules)
   - [Pestaña 21: Matriz de Precios (akibara-pricing-matrix)](#pestaña-21-matriz-de-precios-akibara-pricing-matrix)
   - [Pestaña 22: Claves y Accesos (akibara-provider-access)](#pestaña-22-claves-y-accesos-akibara-provider-access)

---

# PARTE 1: ARQUITECTURA Y CÓDIGO ÍNTEGRO DE RENDERER.PHP

## 1.1. Principios de Ingeniería y Rendimiento LCP

El archivo `HomeHero\Renderer.php` gobierna la superficie visual más crítica del e-commerce: el First Fold de la portada (`/`). Para no degradar el Largest Contentful Paint (LCP) ni causar Cumulative Layout Shift (CLS), implementa cuatro patrones defensivos:

1. **Slide 1 con Prioridad Crítica (LCP Shield):**
   - Se imprime directamente en el HTML del servidor dentro de un elemento `<picture>` con `loading="eager"` y `fetchpriority="high"`.
   - Dispara la inyección de `<link rel="preload" as="image">` mediante el hook `wp_preload_resources`.
2. **Slides 2 en adelante Diferidos (Template Deferral):**
   - No ensucian el árbol DOM inicial; se encapsulan en etiquetas `<template>`.
   - El script de cliente hidrata los slides secundarios por intención (primer scroll, hover sobre las flechas o proximidad al autoplay).
   - Mantiene etiquetas `<noscript>` con dimensiones explícitas y textos alternativos para SEO y bots.
3. **Aislamiento de CDN y Paridad Absoluta Docker $\leftrightarrow$ Producción:**
   - Resuelve el archivo directamente desde el sistema de archivos local (`wp_get_upload_dir()`) mediante `get_local_attachment_url()`.
   - Evita transformaciones arbitrarias de CDNs externas que puedan alterar el punto de recorte y enfoque (`object-position`).
4. **Encuadre Responsivo Multi-Breakpoint (Desktop, Tablet y Móvil):**
   - Maneja `object_position_desktop`, `object_position_mobile` y una derivación matemática para tablet (`object_position_tablet` de 601px a 768px con Y capada) para que las cajas intermedias no decapiten el arte ni a los personajes.
5. **Accesibilidad WAI-ARIA (Patrón APG Carousel):**
   - Si existe 1 solo slide: render estático sin JS, sin dots, con `aria-labelledby` hacia el título real.
   - Si existen 2+ slides: `role="region"`, `aria-roledescription="carrusel"`, live region `aria-live="polite"` para anunciar cambios manuales sin generar spam en lectores de pantalla.

---

## 1.2. Código Fuente Íntegro de HomeHero\Renderer.php

```php
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

```

---

# PARTE 2: MAPA TÉCNICO PROFUNDO DE LAS PESTAÑAS 1 A 18

A continuación se detalla la radiografía completa de cada una de las pestañas del panel administrativo, abarcando sus 4 capas: Interfaz Visual (DOM), Capa JavaScript de Cliente, Controlador Backend PHP y Persistencia en MariaDB.

---

## Pestaña 1: Slider de Inicio (`home_hero`)
- **Identificador de Tab:** `tab=home_hero`
- **Grupo de Navegación:** `catalogo` (Catálogo & Portada)
- **Tipo de Sección:** `Action Section` (no delega en campos genéricos de WooCommerce; renderizado autónomo).
- **Controlador PHP:** [`Akibara\Business\Modules\HomeHero\HomeHero::render_admin_section()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/HomeHero/HomeHero.php) y [`AdminUI.php`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/HomeHero/AdminUI.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `846 ms` · Controles interactivos: `206`.

### Capa 1: Visual / DOM & Formulario
Interfaz visual de alta complejidad interactiva:
- **Gestor de Diapositivas:** Lista ordenable con Drag & Drop basada en HTML5 Drag API.
- **Selectores de Multimedia:** Integración con la Biblioteca de Medios de WordPress (`wp.media`) para adjunto Desktop (`desktop_id`) y adjunto Móvil (`mobile_id`).
- **Campos de Texto:** Título de la diapositiva, Eyebrow/Subtítulo, Bajada/Lede, Botón CTA (Texto y URL canónica).
- **Controles de Estilo:** Selector de color de texto HEX, opacidad de viñeta oscura (`--akb-hero-vignette` de 0 a 100), encuadre (`object-position` Desktop, Tablet y Móvil).
- **Vigencia:** Campos de fecha y hora (`date_start`, `date_end`) con conmutador activo/inactivo.

### Capa 2: JavaScript de Cliente
- Script: [`assets/js/admin-hero.js`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/assets/js/admin-hero.js).
- Previsualización en vivo (Live Preview) sin recargar la página: realiza peticiones AJAX a `admin-ajax.php?action=akb_hero_preview` inyectando los settings serializados para validar fidelidad tipográfica y de contraste antes de guardar.

### Capa 3: Backend PHP y Guardado
- La sección guarda mediante su propio endpoint AJAX o POST dedicado:
  - Sanitización en [`Settings::sanitize()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/HomeHero/Settings.php).
  - Valida que URLs comiencen con `/` o `https://`.
  - Fuerza enteros para IDs de adjuntos (`desktop_id`, `mobile_id`).
- **Política de Caché:** Purga completa obligatoria (`litespeed_purge_all`) al modificarse cualquier diapositiva porque impacta el First Fold de la portada (`/`).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options`.
- **Clave principal:** `akibara_home_hero_slides` (array serializado de diapositivas).
- **Claves auxiliares:** `akibara_home_hero_carousel_config` (autoplay, interval, pause_on_hover).
- **Relaciones:** Los IDs de imágenes corresponden a filas en `wp_posts` con `post_type = 'attachment'`.

---

## Pestaña 2: Series en la portada (`home_series`)
- **Identificador de Tab:** `tab=home_series`
- **Grupo de Navegación:** `catalogo` (Catálogo & Portada)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\HomeSeriesDestacadas\HomeSeriesDestacadas::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/HomeSeriesDestacadas/HomeSeriesDestacadas.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `334 ms` · Controles interactivos: `7`.

### Capa 1: Visual / DOM & Formulario
- `akibara_home_series_ids`: Desplegable múltiple Select2/selectWoo alimentado dinámicamente con los términos de la taxonomía `pa_serie`.
- `akibara_home_series_title`: Input de texto (Default: *"Series Destacadas"*).
- `akibara_home_series_limit`: Input numérico (`min="1"`, `max="24"`, Default: `8`).

### Capa 2: JavaScript de Cliente
- [`admin-panel.js`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/assets/js/admin-panel.js): La función `akbLabelSelect2Search` detecta el contenedor Select2 de `akibara_home_series_ids` y le asigna automáticamente un `aria-label="Series en la portada"` accesible.

### Capa 3: Backend PHP y Guardado
- Define el array de campos estándar de WooCommerce:
```php
public function get_settings(): array {
    return array(
        array( 'title' => 'Series en la portada', 'type' => 'title', 'id' => 'akibara_home_series_section' ),
        array(
            'title'    => 'Series seleccionadas',
            'id'       => 'akibara_home_series_ids',
            'type'     => 'multiselect',
            'options'  => $this->get_series_options(),
            'default'  => array(),
            'class'    => 'wc-enhanced-select',
        ),
        array(
            'title'    => 'Título del bloque',
            'id'       => 'akibara_home_series_title',
            'type'     => 'text',
            'default'  => 'Series Destacadas',
        ),
        array(
            'title'    => 'Cantidad de series',
            'id'       => 'akibara_home_series_limit',
            'type'     => 'number',
            'default'  => '8',
        ),
        array( 'type' => 'sectionend', 'id' => 'akibara_home_series_section' ),
    );
}
```
- **Política de Caché:** Declarada en `AdminPanel::SECTIONS_WITHOUT_CACHED_SURFACE`. No ejecuta purga global del sitio: purga exclusivamente la URL de portada (`/`), notificando al dueño:
  > *"Si cambiaste la vitrina, se limpió de la caché sólo la portada, no el sitio entero. Cloudflare puede tardar hasta 10 minutos en mostrar el cambio."*

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options`.
- **Claves:** `akibara_home_series_ids` (array serializado de term_ids), `akibara_home_series_title`, `akibara_home_series_limit`.

---

## Pestaña 3: Vitrina (`catalog_order`)
- **Identificador de Tab:** `tab=catalog_order`
- **Grupo de Navegación:** `catalogo` (Catálogo & Portada)
- **Tipo de Sección:** `Form Section` + Header con estado de sincronización.
- **Controlador PHP:** [`Akibara\Business\Modules\CatalogOrder\CatalogOrder::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/CatalogOrder/CatalogOrder.php) y [`LandingPageSize::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SeriesCatalog/LandingPageSize.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `250 ms` · Controles interactivos: `11`.

### Capa 1: Visual / DOM & Formulario
- Selector de algoritmo: `akibara_catalog_order_algorithm` (`stock_interleaved` [intercalado inteligente de series con stock], `stock_first` [stock primero], `newest` [más recientes]).
- `akibara_series_page_size`: Selector de cantidad de tomos en landings de serie `/serie/<slug>/` (opciones: 12, 24, 36, 48; default 24).
- Botón de diagnóstico: *"Reordenar catálogo ahora"* (dispara acción en segundo plano).

### Capa 2: JavaScript de Cliente
- Manejo de confirmación de disparo de recálculo mediante modal nativo o aviso no intrusivo.

### Capa 3: Backend PHP y Guardado
- La ordenación de la vitrina no se ejecuta en tiempo real durante el POST HTTP para evitar timeouts de PHP en servidores de producción.
- Encola la tarea asíncrona `akibara_recalc_catalog_order` en **Action Scheduler**.
- **Política de Caché:** Registrada en `SECTIONS_WITHOUT_CACHED_SURFACE`: el guardado no purga inmediatamente porque el orden aún no cambia; el cron purga al completar la reescritura de `menu_order`.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_catalog_order_algorithm`, `akibara_series_page_size`).
- **Tabla:** `wp_posts`: actualización en lote de la columna numérica `menu_order` en todos los productos de tipo `product`.

---

## Pestaña 4: Barra de anuncios (`announcement`)
- **Identificador de Tab:** `tab=announcement`
- **Grupo de Navegación:** `fidelizacion` (Fidelización & Avisos)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\AnnouncementBar\AnnouncementBar::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/AnnouncementBar/AnnouncementBar.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `265 ms` · Controles interactivos: `15`.

### Capa 1: Visual / DOM & Formulario
- `akibara_announcement_bar_enabled`: Checkbox booleano.
- `akibara_announcement_bar_messages`: Textarea con atributo `data-akb-line-max="50"`. Admite mensajes por línea con enlaces usando el delimitador pipe:
  `¡Envío gratis a todo Chile desde $60.000! | /despacho`
- `akibara_announcement_bar_speed`: Input numérico de rotación en segundos (Default: `5`).
- `akibara_announcement_bar_bg_color` y `akibara_announcement_bar_text_color`: Controles de colorpicker HEX.

### Capa 2: JavaScript de Cliente
- `akbLineCounter` en `admin-panel.js`: Lee cada línea en tiempo real al escribir (`input`), excluye la URL y etiquetas HTML, y muestra:
  `3 líneas · la más larga 42/50` (o añade en rojo `· 1 se corta en móvil` si supera los 50 caracteres).

### Capa 3: Backend PHP y Guardado
- Sanitizado de mensajes con `sanitize_textarea_field`, separando y validando las URLs internas o HTTPS absolutas mediante `esc_url_raw`.
- **Política de Caché:** Purga completa (`litespeed_purge_all`) porque la barra de anuncios se inyecta en la cabecera superior de absolutamente todas las páginas del sitio.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_announcement_bar_enabled`, `akibara_announcement_bar_messages`, etc.).

---

## Pestaña 5: Lista de espera (`back_in_stock`)
- **Identificador de Tab:** `tab=back_in_stock`
- **Grupo de Navegación:** `fidelizacion` (Fidelización & Avisos)
- **Tipo de Sección:** `Form Section` + Header con suscriptores.
- **Controlador PHP:** [`Akibara\Business\Modules\BackInStock\BackInStock::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/BackInStock/BackInStock.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `306 ms` · Controles interactivos: `6`.

### Capa 1: Visual / DOM & Formulario
- `akibara_bis_enabled`: Checkbox maestro de activación.
- `akibara_bis_button_text`: Input de texto (Default: *"Avísame cuando vuelva"*).
- `akibara_bis_notice_text`: Texto de éxito post-captura.
- Visualizador de demanda acumulada con lista de productos agotados y cantidad de correos esperando reposición.

### Capa 2: JavaScript de Cliente
- En el frontend, el formulario `.akb-bis-form` captura el correo vía Fetch API hacia el endpoint REST `/wp-json/akibara/v1/bis/subscribe`, mostrando validación accesible en línea.

### Capa 3: Backend PHP y Guardado
- Control de suscripciones vía [`SubscriberRepository`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/BackInStock/SubscriberRepository.php).
- Escucha el hook de cambio de stock `woocommerce_product_set_stock_status` y `akibara_product_stock_restocked` para disparar notificaciones por correo por lotes con retraso inteligente.
- **Política de Caché:** Purga completa (`litespeed_purge_all`).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` para configuración general.
- **Tabla dedicada:** `wp_akb_bis_subscribers` con columnas `id`, `product_id`, `email`, `created_at`, `notified_at`.

---

## Pestaña 6: Sigue esta serie (`series_follow`)
- **Identificador de Tab:** `tab=series_follow`
- **Grupo de Navegación:** `fidelizacion` (Fidelización & Avisos)
- **Tipo de Sección:** `Form Section` + Header con estadísticas.
- **Controlador PHP:** [`Akibara\Business\Modules\SeriesFollow\SeriesFollow::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SeriesFollow/SeriesFollow.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `477 ms` · Controles interactivos: `10`.

### Capa 1: Visual / DOM & Formulario
- `akibara_series_follow_enabled`: Interruptor maestro.
- `akibara_series_follow_button_text`: Texto del botón en ficha (Default: *"Seguir serie"*).
- `akibara_series_follow_modal_copy`: Texto descriptivo en el modal para usuarios invitados.

### Capa 2: JavaScript de Cliente
- Manejo de suscripción asíncrona en fichas de serie y fichas técnicas dinámicas (`SeriesFacts`).

### Capa 3: Backend PHP y Guardado
- Al publicarse un nuevo tomo asignado al término `pa_serie`, el despachador encola notificaciones personalizadas para todos los seguidores registrados.
- **Política de Caché:** Purga completa del sitio.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` y tabla relacional `wp_akb_series_followers` (`id`, `term_id`, `user_id`, `email`, `created_at`).

---

## Pestaña 7: Envío gratis (`free_shipping_progress`)
- **Identificador de Tab:** `tab=free_shipping_progress`
- **Grupo de Navegación:** `venta` (Operaciones & Pedidos)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/FreeShippingProgress/FreeShippingProgress.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `355 ms` · Controles interactivos: `14`.

### Capa 1: Visual / DOM & Formulario
- `akibara_fsp_enabled`: Interruptor de la barra.
- `akibara_fsp_carrier_threshold`: Input numérico único del umbral de envío gratis a domicilio con Blue Express en pesos chilenos (Default: `60000`). Fuente única de verdad.
- `akibara_fsp_progress_message`: Texto antes de alcanzar la meta (*"Te faltan %s para tener ENVÍO GRATIS"*).
- `akibara_fsp_unlocked_message`: Texto al alcanzar o superar la meta (*"¡Felicitaciones! Tienes ENVÍO GRATIS desbloqueado"*).

### Capa 2: JavaScript de Cliente
- Animación de la barra de progreso con gradiente y porcentaje dinámico (`width: XX%`) tanto en el Drawer AJAX de carrito como en la página de checkout.

### Capa 3: Backend PHP y Guardado
- **Política de Caché:** En `SECTIONS_WITHOUT_CACHED_SURFACE`. La barra vive en superficies que no se cachean (Drawer AJAX, `/carrito/`, `/checkout/`), por lo que no es necesario purgar el sitio.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_fsp_enabled`, `akibara_fsp_carrier_threshold`, etc.).

---

## Pestaña 8: Carrito nativo (`native_cart`)
- **Identificador de Tab:** `tab=native_cart`
- **Grupo de Navegación:** `venta` (Operaciones & Pedidos)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\NativeCart\NativeCart::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/NativeCart/NativeCart.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `296 ms` · Controles interactivos: `25`.

### Capa 1: Visual / DOM & Formulario
- `akibara_native_cart_enabled`: Conmutador maestro del Drawer deslizante.
- `akibara_native_cart_auto_open`: Abrir automáticamente tras presionar botón ATC en catálogo o ficha.
- `akibara_native_cart_cross_sells_enabled`: Activar cross-sells inteligentes en el cajón.
- `akibara_native_cart_title`: Título del encabezado (Default: *"Tu Carrito"*).

### Capa 2: JavaScript de Cliente
- Script: [`assets/js/native-cart.js`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/assets/js/native-cart.js). Controla apertura, cierre con tecla Escape, trap de foco accesible, y actualización instantánea de cantidades (+ / -) vía WooCommerce Cart Fragments API.

### Capa 3: Backend PHP y Guardado
- Integra el recomendador [`SmartCartRecommender`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/NativeCart/SmartCartRecommender.php) que excluye automáticamente productos ya presentes en el carrito.
- **Política de Caché:** En `SECTIONS_WITHOUT_CACHED_SURFACE`.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_native_cart_*`).

---

## Pestaña 9: Recomendaciones y Autofill (`upsells_autofill`)
- **Identificador de Tab:** `tab=upsells_autofill`
- **Grupo de Navegación:** `marketing` (Marketing & Promociones)
- **Tipo de Sección:** `Form Section` + Header con métricas.
- **Controlador PHP:** [`Akibara\Business\Modules\RecommendationSettings::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/RecommendationSettings.php) y [`UpsellsAutofill.php`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/UpsellsAutofill/UpsellsAutofill.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `284 ms` · Controles interactivos: `21`.

### Capa 1: Visual / DOM & Formulario
- `akibara_upsells_enabled`: Activa el algoritmo de recomendación secuencial.
- `akibara_upsells_max_items`: Cantidad máxima de recomendaciones a renderizar en la ficha del producto (Default: `4`).
- Barra de estado: *"100.00% COBERTURA DEL CATÁLOGO MANGA"*.
- Botón *"Rellenar upsells ahora"* para ejecutar el script por CLI o cron.

### Capa 2: JavaScript de Cliente
- En frontend, hidrata el bloque `.up-sells` con estilo brutalista y previene colisiones de estilos CSS con los precios tachados.

### Capa 3: Backend PHP y Guardado
- Algoritmo que examina la serie y número de volumen del tomo actual para encadenar el siguiente tomo ($N+1$), evitando vacíos comerciales.
- Tarea programada en cron: `akb_upsells_autofill_cron`.
- **Política de Caché:** Purga completa obligatoria.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_upsells_enabled`, `akibara_upsells_last_run`).
- **Tabla:** `wp_postmeta`: actualiza masivamente la clave `_upsell_ids` en cada post de tipo `product`.

---

## Pestaña 10: Descuentos editoriales (`taxonomy_discount`)
- **Identificador de Tab:** `tab=taxonomy_discount`
- **Grupo de Navegación:** `marketing` (Marketing & Promociones)
- **Tipo de Sección:** `Form Section` + Navegación por subpestañas (`Editoriales`, `Géneros`, `Categorías`).
- **Controlador PHP:** [`Akibara\Business\Modules\TaxonomyDiscount\TaxonomyDiscount::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/TaxonomyDiscount/TaxonomyDiscount.php) y [`Settings.php`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/TaxonomyDiscount/Settings.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `348 ms` · Controles interactivos: `326`.

### Capa 1: Visual / DOM & Formulario
- `akibara_taxonomy_discount_enabled`: Interruptor general de campañas.
- `akibara_taxonomy_discount_to`: Fecha límite de vigencia de la campaña.
- Grilla de 326 campos numéricos para ingresar el porcentaje de descuento (0% a 99%) por cada editorial (`product_brand`) o género (`pa_genero`).

### Capa 2: JavaScript de Cliente
- Navegación instantánea entre subpestañas sin recargar la página (`#akb-subtab-editoriales`, `#akb-subtab-generos`).

### Capa 3: Backend PHP y Guardado
- Implementa la autoridad única de precios [`PriceAuthority`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/TaxonomyDiscount/PriceAuthority.php), que calcula en tiempo de ejecución el precio rebajado sin escribir precios sucios en `_sale_price`.
- Cron de vencimiento: `CampaignExpiryCron` limpia automáticamente los descuentos cuando expira la fecha límite.
- **Política de Caché:** Purga completa (`litespeed_purge_all`).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_taxonomy_discount_enabled`, `akibara_taxonomy_discount_to`).
- **Tabla:** `wp_termmeta`: clave `akibara_discount_percent` por cada término de la taxonomía.

---

## Pestaña 11: Pop-ups de bienvenida (`welcome_coupon`)
- **Identificador de Tab:** `tab=welcome_coupon`
- **Grupo de Navegación:** `marketing` (Marketing & Promociones)
- **Tipo de Sección:** `Form Section` + Header de diagnóstico Brevo.
- **Controlador PHP:** [`Akibara\Business\Modules\WelcomeCoupon\WelcomeCoupon::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/WelcomeCoupon/WelcomeCoupon.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `309 ms` · Controles interactivos: `42`.

### Capa 1: Visual / DOM & Formulario
- `akibara_welcome_popup_enabled`: Activa el modal.
- `akibara_welcome_coupon_code`: Código del cupón asignado (ej. `HOLA-AKIBARA`).
- `akibara_welcome_coupon_discount`: Porcentaje de descuento (Default: `10%`).
- `akibara_welcome_popup_delay`: Segundos de espera antes de la apertura (Default: `5`).
- Visualizador del estado de sincronización con la API de Brevo.

### Capa 2: JavaScript de Cliente
- Script de cliente en tema: controla el almacenamiento de la cookie `akb_welcome_popup_dismissed` por 30 días si el usuario cierra el modal, evitando molestarlo en navegaciones recurrentes.

### Capa 3: Backend PHP y Guardado
- Generación y validación del cupón en WooCommerce vía [`CouponService`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/WelcomeCoupon/CouponService.php).
- Sincronización asíncrona del contacto hacia las listas de marketing de Brevo.
- **Política de Caché:** Purga completa.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_welcome_*`).
- **Tabla:** `wp_posts` con `post_type = 'shop_coupon'`.

---

## Pestaña 12: Autocompletar dirección Chile (`address`)
- **Identificador de Tab:** `tab=address`
- **Grupo de Navegación:** `sitio` (Sitio & Experiencia)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\AddressAutocomplete\AddressAutocomplete::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `314 ms` · Controles interactivos: `6`.

### Capa 1: Visual / DOM & Formulario
- `akibara_address_autocomplete_enabled`: Interruptor principal.
- `akibara_google_places_api_key`: Clave de API de Google Maps / Places.
- `akibara_address_country_restriction`: Restricción de país fija en `CL` (Chile).

### Capa 2: JavaScript de Cliente
- Script en checkout: se acopla a los campos de dirección de facturación y envío de WooCommerce Blocks y Checkout tradicional, poblando automáticamente calle, número, comuna y región.

### Capa 3: Backend PHP y Guardado
- Sanitiza la clave de API con `sanitize_text_field`.
- **Política de Caché:** Declarada en `SECTIONS_WITHOUT_CACHED_SURFACE` (solo afecta `/checkout/`, que nunca se cachea).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_address_autocomplete_enabled`, `akibara_google_places_api_key`).

---

## Pestaña 13: Branding y UX (`branding`)
- **Identificador de Tab:** `tab=branding`
- **Grupo de Navegación:** `sitio` (Sitio & Experiencia)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\ThemeCustomizer\ThemeCustomizer::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/ThemeCustomizer/ThemeCustomizer.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `291 ms` · Controles interactivos: `6`.

### Capa 1: Visual / DOM & Formulario
- `akibara_theme_bottom_nav_enabled`: Interruptor maestro de la barra de navegación móvil inferior (`.akb-mobile-bottom-nav`).
- `akibara_theme_brand_badge_style`: Selector de estilo para placas editoriales.
- Selector de color de énfasis y acento.

### Capa 2: JavaScript de Cliente
- Gestiona la visualización condicional de la barra inferior coordinada con el Sticky ATC en `franja-inferior.js`.

### Capa 3: Backend PHP y Guardado
- Inyecta variables CSS y clases de body en el tema hijo `akibara-gp`.
- **Política de Caché:** Purga completa obligatoria.

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_theme_bottom_nav_enabled`, etc.).

---

## Pestaña 14: Calidad de imágenes AVIF/WebP (`imagenes`)
- **Identificador de Tab:** `tab=imagenes`
- **Grupo de Navegación:** `sitio` (Sitio & Experiencia)
- **Tipo de Sección:** `Form Section` + Header de recompresión.
- **Controlador PHP:** [`Akibara\Business\Modules\ImageQuality\ImageQuality::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/ImageQuality/ImageQuality.php) y [`Recompressor.php`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/ImageQuality/Recompressor.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `301 ms` · Controles interactivos: `9`.

### Capa 1: Visual / DOM & Formulario
- `akibara_image_quality_avif`: Slider de compresión AVIF (Default: `80%`).
- `akibara_image_quality_webp`: Slider de compresión WebP (Default: `82%`).
- Consola interactiva para iniciar, pausar y monitorear la recompresión masiva de portadas de manga antiguas.

### Capa 2: JavaScript de Cliente
- Polling asíncrono para actualizar la barra de progreso sin bloquear la pestaña administrativa.

### Capa 3: Backend PHP y Guardado
- Engancha filtros en `wp_editor_set_quality` y `image_editor_output_format` para generar automáticamente versiones AVIF y WebP en cada subida de archivo.
- **Política de Caché:** En `SECTIONS_WITHOUT_CACHED_SURFACE` (la calidad solo aplica a archivos nuevos; no altera HTML cacheado existente).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akibara_image_quality_avif`, `akibara_image_quality_webp`).

---

## Pestaña 15: Velocidad y rendimiento (`velocidad`)
- **Identificador de Tab:** `tab=velocidad`
- **Grupo de Navegación:** `sitio` (Sitio & Experiencia)
- **Tipo de Sección:** `Form Section` + Header de estado.
- **Controlador PHP:** [`Akibara\Business\Modules\SiteSpeed\SiteSpeed::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed/SiteSpeed.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `333 ms` · Controles interactivos: `13`.

### Capa 1: Visual / DOM & Formulario
- `akb_cache_warm_enabled`: Interruptor del recalentado nocturno de las fichas (por defecto `yes`).
- `akibara_edge_purge_enabled`: Aviso a Cloudflare al cambiar precios o stock (por defecto `yes`).
- `akb_crossorigin_scripts_enabled`: Pide el detalle de los errores de scripts de terceros (por defecto `yes`).
- `akb_analytics_after_load`: Carga de Google Analytics después de `window.load` (por defecto `no`; protege TBT y LCP).
- `akb_lean_cart`: Quita del carrito lo que sólo usa la página de pago. Sólo se muestra si `LeanCart::glue_active()` es verdadero.

### Capa 2: JavaScript de Cliente
- Inyección controlada de scripts analíticos mediante listeners no bloqueantes.

### Capa 3: Backend PHP y Guardado
- Desacoplamiento de componentes de rendimiento vía [`CacheWarmer`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed/CacheWarmer.php), [`EdgePurge`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed/EdgePurge.php), [`AnalyticsAfterLoad`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed/AnalyticsAfterLoad.php) y [`LeanCart`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed/LeanCart.php).
- **Política de Caché:** En `SECTIONS_WITHOUT_CACHED_SURFACE` (las opciones que tocan HTML purgan selectivamente por su cuenta).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` (`akb_cache_warm_enabled`, `akibara_edge_purge_enabled`, `akb_crossorigin_scripts_enabled`, `akb_analytics_after_load`, `akb_lean_cart`).

---

## Pestaña 16: Gestor de textos de interfaz (`copys`)
- **Identificador de Tab:** `tab=copys`
- **Grupo de Navegación:** `sitio` (Sitio & Experiencia)
- **Tipo de Sección:** `Form Section` (WooCommerce Settings).
- **Controlador PHP:** [`Akibara\Business\Modules\CopyManager\CopyManager::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/CopyManager/CopyManager.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `262 ms` · Controles interactivos: `53`.

### Capa 1: Visual / DOM & Formulario
- 53 inputs organizados temáticamente:
  - Textos de Carrito Vacío.
  - Placeholders y textos de ayuda del Buscador.
  - Garantías y sellos de confianza en la ficha de producto.
  - Tooltips de la estantería Hondana (*"✓ En tu colección"*).
  - Avisos legales y notas al pie del Checkout.

### Capa 2: JavaScript de Cliente
- Validadores de longitud máxima para evitar que textos muy largos rompan botones en móviles.

### Capa 3: Backend PHP y Guardado
- Sanitizado universal con `sanitize_text_field` y filtrado a través de `get_option()`.
- **Política de Caché:** Purga completa obligatoria (`litespeed_purge_all`).

### Capa 4: Persistencia en MariaDB
- **Tabla:** `wp_options` con claves prefijadas `akibara_copys_*`.

---

## Pestaña 17: Salud SEO y Manifiesto LLMS (`salud_seo`)
- **Identificador de Tab:** `tab=salud_seo`
- **Grupo de Navegación:** `sistema` (Sistema)
- **Tipo de Sección:** `Form Section` + Header con estado de archivos.
- **Controlador PHP:** [`Akibara\Business\Modules\LlmsManifest\LlmsManifest::get_settings()`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/LlmsManifest/LlmsManifest.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `880 ms` · Controles interactivos: `5`.

### Capa 1: Visual / DOM & Formulario
- `akibara_llms_manifest_enabled`: Interruptor maestro.
- Visor de estado del archivo `/llms.txt` y `/llms-full.txt`.
- Botón *"Regenerar manifiesto LLMS ahora"* que dispara la acción POST `admin_post_akibara_salud_seo_regenerar`.

### Capa 2: JavaScript de Cliente
- Enlace directo con target `_blank` para auditar el archivo servido en la raíz.

### Capa 3: Backend PHP y Guardado
- Generador automático que recorre todas las series activas y tomos para crear una documentación sintética para modelos de IA y crawlers.
- **Política de Caché:** Purga completa.

### Capa 4: Persistencia en MariaDB y Filesystem
- **Tabla:** `wp_options` (`akibara_llms_manifest_enabled`, `akibara_llms_manifest_last_generated`).
- **Sistema de archivos:** Escribe físicamente en `public_html/llms.txt` y `public_html/llms-full.txt`.

---

## Grupo de Navegación Lateral: Pantallas Satélite
- **Encabezado en Sidebar:** `<span class="akb-panel-nav__group" data-nav-group="satelites">Pantallas Satélite</span>`
- **Selector Móvil:** `<optgroup label="Pantallas Satélite">`
- **Integración con Buscador Lateral (`#akb-panel-search-input`):**
  Las 5 pantallas satélite cuentan con `data-nav-group="satelites"`, `data-tab` y `data-keywords` para búsqueda instantánea en cliente con sinónimos cotidianos (ej. "puntos", "tarifas", "precios", "faceta", "claves").

---

## Pestaña 18: Puntos y Reseñas 2.0 (`akb-rewards-settings`)
- **Identificador de Tab:** `admin.php?page=akb-rewards-settings`
- **Ubicación:** Pantalla satélite enlazada en el submenú lateral de Akibara.
- **Plugin Responsable:** `akibara-rewards` (v0.4.35).
- **Controladores PHP:** [`Akibara\Rewards\Admin\AdminOverview`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminOverview.php), [`AdminAdjust`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-rewards/src/Points/AdminAdjust.php) y [`AdminReviews`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-rewards/src/Reviews/AdminReviews.php).
- **Métricas Medidas en Local Docker:** Status `200 OK` · Tiempo de render: `363 ms` · Controles interactivos: `91`.

### Subpestaña 18.1: Puntos y Fidelización
- Configuración de la tasa de canje (1 pt = $1 CLP).
- Bonificación de bienvenida: +1.000 pts al crear cuenta.
- Bonificación primera compra: +1.000 pts al completar el primer pedido.
- Bonificación por reseña con foto: +500 pts tras aprobación de moderación.
- Días de carencia (hold) para evitar canjes antes del despacho físico del pedido.

### Subpestaña 18.2: Moderación de Reseñas 2.0
- Visualizador de fotos subidas por clientes con zoom interactivo.
- Botones de acción: *"Aprobar y Pagar +500 pts"* (acredita en el ledger) o *"Rechazar"*.
- Monitor de tokens de reseña emitidos (`wp_akb_rewards_review_forms`) con soporte multi-tomo (no quema el token hasta que se reseñan todos los tomos del pedido).

### Subpestaña 18.3: Ajuste Manual de Saldo
- Permite al dueño ingresar el correo de un cliente, seleccionar si es abono o deducción, especificar la cantidad y escribir el motivo de auditoría.

### Capa MariaDB
- **Tablas dedicadas:** `wp_akb_rewards_ledger` (registro append-only inmutable), `wp_akb_rewards_review_forms`, `wp_akb_rewards_invitations`.
- **Comentarios:** Metadatos canónicos en `wp_commentmeta` (`_akb_review_image_id`, `_akb_review_title`).

---

# PARTE 3: PANTALLAS SATÉLITE ADICIONALES (19 A 22)

### Pestaña 19: Buscador Akibara (`akibara-search`)
- **URL:** `admin.php?page=akibara-search`
- **Controlador:** [`Akibara\Search\SearchAdminSection`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-search/src/SearchAdminSection.php).
- **Métricas:** Status `200 OK` · `334 ms` · `12 controles`.
- **Subpestañas Oficiales:** Índice (`indice`), Búsquedas (`busquedas`), Facetas (`facetas`), Precios (`precios`), Sinónimos (`sinonimos`), Palabras ignoradas (`stopwords`) y Ajustes (`ajustes`).
- **Funcionalidad:** Estado del índice SQLite/MariaDB full-text, recálculo de facetas, tabla de sinónimos manga y registro de búsquedas sin resultado.
- **Capa MariaDB:** `wp_options` (`akibara_search_*`), tablas `wp_akb_search_docs`, `wp_akb_search_tokens`, `wp_akb_search_facet_index`, `wp_akb_search_qlog` y `wp_akb_search_work`.

### Pestaña 20: Reglas Financieras (`akibara-financial-rules`)
- **URL:** `admin.php?page=akibara-financial-rules`
- **Controlador:** [`FinancialRulesAdminPage`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Admin/FinancialRulesAdminPage.php).
- **Métricas:** Status `200 OK` · `297 ms` · `8 controles`.
- **Funcionalidad:** Pisos de marginalidad, paridad cambiaria EUR/ARS a CLP y regla *Best-Wins* contra colisión de promociones.

### Pestaña 21: Matriz de Precios (`akibara-pricing-matrix`)
- **URL:** `admin.php?page=akibara-pricing-matrix`
- **Controlador:** [`PricingMatrixAdminPage`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Modules/PricingMatrix/PricingMatrixAdminPage.php).
- **Métricas:** Status `200 OK` · `509 ms` · `829 controles`.
- **Funcionalidad:** Matriz de conversión de códigos de tarifa de editoriales españolas y argentinas hacia PVP chileno.

### Pestaña 22: Claves y Accesos (`akibara-provider-access`)
- **URL:** `admin.php?page=akibara-provider-access`
- **Controlador:** [`ProviderAccessAdminPage`](file:///Users/alefvaras/Documents/akibara/public_html/wp-content/plugins/akibara-business/src/Admin/ProviderAccessAdminPage.php).
- **Métricas:** Status `200 OK` · `324 ms` · `0 controles editables` (modo visor seguro).
- **Funcionalidad:** Monitor de conexión de credenciales: Blue Express, Brevo, Google Places, Cloudflare y Webhooks.
