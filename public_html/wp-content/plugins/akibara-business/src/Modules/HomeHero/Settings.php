<?php
/**
 * HomeHero\Settings — storage + defaults del hero/carrusel.
 *
 * Schema centralizado. AdminUI lee/escribe vía estas helpers. Renderer las
 * consume. Migration siembra defaults al activar el plugin si no hay slides.
 *
 * Storage: option array versionado `akibara_hero_settings`. Versionar permite
 * migrar schema sin riesgo de leer estructuras viejas como nuevas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\HomeHero;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION_KEY     = 'akibara_hero_settings';
	public const SCHEMA_VERSION = 1;

	public const MAX_SLIDES     = 8;
	public const MAX_CTAS       = 3;

	/** Dimensiones recomendadas (px). Validador JS las compara y advierte. */
	public const REC_DESKTOP_WIDTH  = 1920;
	public const REC_DESKTOP_HEIGHT = 811;
	public const REC_MOBILE_WIDTH   = 768;
	public const REC_MOBILE_HEIGHT  = 1377;

	/** Tolerancia % sobre el aspect-ratio antes de advertir. */
	public const ASPECT_TOLERANCE_PCT = 8;

	/**
	 * Limita a 50% el focal Y tablet derivado de móvil para mantener el sujeto
	 * en el recorte vertical más bajo. Los valores iguales o menores pasan sin
	 * cambios. El JavaScript administrativo recibe esta misma constante mediante
	 * wp_localize_script, sin duplicar el límite.
	 */
	public const TABLET_FOCAL_Y_CAP = 50;

	/** 9 posiciones del bloque de texto sobre la imagen (desktop). Grid 3×3. */
	public const TEXT_POSITIONS = array(
		'top-left',      'top-center',      'top-right',
		'middle-left',   'middle-center',   'middle-right',
		'bottom-left',   'bottom-center',   'bottom-right',
	);

	/** Alineaciones de texto dentro del bloque. */
	public const TEXT_ALIGNS = array( 'left', 'center', 'right' );

	/** Layouts del bloque de texto en mobile. */
	public const MOBILE_TEXT_LAYOUTS = array(
		'below'          => 'Debajo de la imagen',
		'overlay-bottom' => 'Sobre la imagen (abajo)',
		'overlay-center' => 'Sobre la imagen (centrado)',
	);

	/** Estilos válidos de CTA (id => clase modifier + color). */
	public const CTA_STYLES = array(
		'manga'  => array( 'label' => 'Rojo (manga)',    'modifier' => 'manga' ),
		'comics' => array( 'label' => 'Amarillo (cómics)', 'modifier' => 'comics' ),
		'shop'   => array( 'label' => 'Rojo (catálogo)',   'modifier' => 'shop' ),
		'ghost'  => array( 'label' => 'Outline neutro',    'modifier' => 'ghost' ),
	);

	/** Estilos válidos para el lede (subtítulo). */
	public const LEDE_STYLES = array(
		'plain'  => 'Texto Plano (Actual)',
		'glass'  => 'Fondo Oscuro (Pastilla Blur)',
		'shadow' => 'Texto con Sombra',
	);

	/**
	 * Defaults del slide vacío. AdminUI los usa para nuevos slides creados desde
	 * el botón "Agregar slide".
	 *
	 * @return array<string, mixed>
	 */
	public static function default_slide(): array {
		return array(
			'desktop_id'              => 0,
			'mobile_id'               => 0,
			'alt'                     => '',
			'eyebrow'                 => '',          // texto pequeño encima del H1, ej. "NOVEDAD"
			'title'                   => '',
			'lede'                    => '',
			'title_color'              => '',         // override por slide; vacío = --aki-white
			'title_font'               => '',         // override: Bebas Neue, Inter, System
			'title_weight'             => '',         // override: 400, 600, 700, 900
			'lede_color'               => '',         // override por slide
			'lede_font'                => '',         // override
			'lede_weight'              => '',         // override
			'lede_style'               => 'plain',    // plain, glass, shadow
			// OJO: Renderer trata 'center center' literal como sentinel "pin nunca
			// tocado" para la banda 769-900 (no emite --akb-hero-objpos-band). Si
			// este default cambia, actualizar render_slide() y su test.
			'object_position_desktop' => 'center center',
			'object_position_mobile'  => 'center 80%',
			// Encuadre tablet (601-768). '' = auto: derivado del mobile con la Y
			// capada a TABLET_FOCAL_Y_CAP (ver derive_tablet_object_position).
			'object_position_tablet'  => '',
			'vignette_opacity'        => 50,
			'is_active'               => true,        // toggle visibilidad sin borrar
			'link_url'                => '',
			'ctas'                    => array(),
			// Posicionamiento del bloque de texto sobre la imagen (desktop).
			// 9 opciones — grid 3×3. Default "bottom-left" replica el hero histórico.
			'text_position'           => 'bottom-left',
			// Alineación del texto dentro del bloque.
			'text_align'              => 'left',
			// Layout mobile del bloque de texto:
			//   - 'below'           : debajo de la imagen, en caja con bg gradient (default actual)
			//   - 'overlay-bottom'  : sobre la imagen, bottom, fondo blur
			//   - 'overlay-center'  : sobre la imagen, centrado vertical
			'mobile_text_layout'      => 'below',
		);
	}

	/**
	 * Defaults del bloque carousel. Usados si no hay settings o si se agrega
	 * el bloque por primera vez.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_carousel(): array {
		return array(
			// Autoplay default OFF — patrón moderno 2026 (Apple, Vercel, Stripe).
			// Owner lo activa opt-in si quiere rotación automática. Cuando activo,
			// el frontend muestra progress bar Instagram-style que también funciona
			// como toggle pause/play (WCAG 2.2.2 cumplido sin botón extra).
			'autoplay'             => false,
			'interval_seconds'     => 6,
			'pause_on_hover'       => true,
			'pause_on_blur'        => true,
			'loop'                 => true,
			'show_arrows_desktop'  => true,
			'show_dots_desktop'    => true,
			'show_dots_mobile'     => true,
			// Altura del hero. Aplica al .akb-hero__viewport vía CSS variable.
			//   - 'compact'     → ~480px desktop / 45vh mobile
			//   - 'standard'    → ~700px desktop / 55vh mobile (default actual)
			//   - 'tall'        → ~900px desktop / 75vh mobile
			//   - 'fullscreen'  → 100vh ambos
			//   - 'custom'      → usa hero_height_custom_desktop/_mobile
			'hero_height'                  => 'standard',
			'hero_height_custom_desktop'   => 700,  // px
			'hero_height_custom_mobile'    => 50,   // vh — techo duro del CSS phone (fold guard)
		);
	}

	/** Preset → reglas CSS aplicadas al .akb-hero__viewport. */
	public const HEIGHT_PRESETS = array(
		'compact'    => array( 'label' => 'Compacto',          'desktop' => '480px', 'mobile' => '45vh' ),
		'standard'   => array( 'label' => 'Estándar',          'desktop' => '700px', 'mobile' => '55vh' ),
		'tall'       => array( 'label' => 'Alto',              'desktop' => '900px', 'mobile' => '75vh' ),
		'fullscreen' => array( 'label' => 'Pantalla completa', 'desktop' => '100vh', 'mobile' => '100vh' ),
		'custom'     => array( 'label' => 'Personalizado',     'desktop' => null,    'mobile' => null ),
	);

	/**
	 * Defaults globales (slides vacíos). Migration::seed_if_empty() los puebla
	 * con un slide replicando el hero actual.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'  => self::SCHEMA_VERSION,
			'slides'   => array(),
			'carousel' => self::default_carousel(),
		);
	}

	/**
	 * Get settings con migración silenciosa de defaults faltantes. Si el option
	 * no existe o no es array, devuelve defaults().
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$raw = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}

		$defaults = self::defaults();

		$out = array(
			'version'  => isset( $raw['version'] ) && is_int( $raw['version'] ) ? $raw['version'] : self::SCHEMA_VERSION,
			'slides'   => array(),
			'carousel' => array_merge( $defaults['carousel'], is_array( $raw['carousel'] ?? null ) ? $raw['carousel'] : array() ),
		);

		if ( isset( $raw['slides'] ) && is_array( $raw['slides'] ) ) {
			foreach ( $raw['slides'] as $slide ) {
				if ( is_array( $slide ) ) {
					$out['slides'][] = array_merge( self::default_slide(), $slide );
				}
			}
		}

		return $out;
	}

	/**
	 * Sanitize + persist. Devuelve true si guardó (option cambió).
	 *
	 * @param array<string, mixed> $raw Datos crudos del POST (no sanitizados).
	 * @return bool
	 */
	public static function save( array $raw ): bool {
		$clean = self::sanitize( $raw );
		return update_option( self::OPTION_KEY, $clean, false );
	}

	/**
	 * Sanitización completa. Aplicada al POST antes de update_option.
	 *
	 * @param array<string, mixed> $raw
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $raw ): array {
		$out = self::defaults();

		// ---- Slides ----
		if ( isset( $raw['slides'] ) && is_array( $raw['slides'] ) ) {
			$slides_in = array_values( $raw['slides'] );
			$slides_in = array_slice( $slides_in, 0, self::MAX_SLIDES );

			foreach ( $slides_in as $slide_raw ) {
				if ( ! is_array( $slide_raw ) ) {
					continue;
				}
				$slide = self::default_slide();

				$slide['desktop_id'] = isset( $slide_raw['desktop_id'] ) ? absint( $slide_raw['desktop_id'] ) : 0;
				$slide['mobile_id']  = isset( $slide_raw['mobile_id'] )  ? absint( $slide_raw['mobile_id'] )  : 0;

				// sanitize() conserva los datos de las diapositivas sin imagen para poder
				// completarlas después. active_slides() exige imagen e is_active antes de
				// mostrarlas, y Renderer comparte esa selección.

				$slide['alt']        = sanitize_text_field( (string) ( $slide_raw['alt'] ?? '' ) );
				$slide['eyebrow']    = sanitize_text_field( (string) ( $slide_raw['eyebrow'] ?? '' ) );
				$slide['title']      = sanitize_text_field( (string) ( $slide_raw['title'] ?? '' ) );
				$slide['lede']       = sanitize_text_field( (string) ( $slide_raw['lede'] ?? '' ) );
				$slide['link_url']   = esc_url_raw( (string) ( $slide_raw['link_url'] ?? '' ) );

				$slide['title_color']  = self::sanitize_color( (string) ( $slide_raw['title_color']  ?? '' ) );
				$slide['lede_color']   = self::sanitize_color( (string) ( $slide_raw['lede_color']   ?? '' ) );

				// Fuentes/pesos override por slide. sanitize() no los copiaba de $raw, así
				// que la seleccion del admin (AdminUI) se perdia siempre contra el default.
				$allowed_title_fonts = array( '', 'Saira', 'Inter', 'system-ui' );
				$allowed_lede_fonts  = array( '', 'Bebas Neue', 'Saira', 'system-ui' );
				$allowed_weights     = array( '', '400', '600', '700', '900' );
				$tf = sanitize_text_field( (string) ( $slide_raw['title_font'] ?? '' ) );
				$slide['title_font']   = in_array( $tf, $allowed_title_fonts, true ) ? $tf : '';
				$tw = sanitize_text_field( (string) ( $slide_raw['title_weight'] ?? '' ) );
				$slide['title_weight'] = in_array( $tw, $allowed_weights, true ) ? $tw : '';
				$lf = sanitize_text_field( (string) ( $slide_raw['lede_font'] ?? '' ) );
				$slide['lede_font']    = in_array( $lf, $allowed_lede_fonts, true ) ? $lf : '';
				$lw = sanitize_text_field( (string) ( $slide_raw['lede_weight'] ?? '' ) );
				$slide['lede_weight']  = in_array( $lw, $allowed_weights, true ) ? $lw : '';
				$slide['is_active']  = isset( $slide_raw['is_active'] ) ? ! empty( $slide_raw['is_active'] ) : true;

				$lstyle = sanitize_key( (string) ( $slide_raw['lede_style'] ?? 'plain' ) );
				$lstyle = str_replace( '_', '-', $lstyle );
				$slide['lede_style']     = array_key_exists( $lstyle, self::LEDE_STYLES ) ? $lstyle : 'plain';

				$pos = sanitize_key( (string) ( $slide_raw['text_position'] ?? 'bottom-left' ) );
				$pos = str_replace( '_', '-', $pos );
				$slide['text_position']  = in_array( $pos, self::TEXT_POSITIONS, true ) ? $pos : 'bottom-left';

				$align = sanitize_key( (string) ( $slide_raw['text_align'] ?? 'left' ) );
				$slide['text_align']     = in_array( $align, self::TEXT_ALIGNS, true ) ? $align : 'left';

				$mlayout = sanitize_key( (string) ( $slide_raw['mobile_text_layout'] ?? 'below' ) );
				$mlayout = str_replace( '_', '-', $mlayout );
				$slide['mobile_text_layout'] = array_key_exists( $mlayout, self::MOBILE_TEXT_LAYOUTS ) ? $mlayout : 'below';

				$slide['object_position_desktop'] = self::sanitize_object_position( (string) ( $slide_raw['object_position_desktop'] ?? 'center center' ) );
				$slide['object_position_mobile']  = self::sanitize_object_position( (string) ( $slide_raw['object_position_mobile'] ?? 'center 80%' ) );
				$slide['object_position_tablet']  = self::sanitize_object_position_optional( (string) ( $slide_raw['object_position_tablet'] ?? '' ) );

				$opacity = isset( $slide_raw['vignette_opacity'] ) ? (int) $slide_raw['vignette_opacity'] : 50;
				$slide['vignette_opacity'] = max( 0, min( 100, $opacity ) );

				// ---- CTAs ----
				$slide['ctas'] = array();
				if ( isset( $slide_raw['ctas'] ) && is_array( $slide_raw['ctas'] ) ) {
					$ctas_in = array_values( $slide_raw['ctas'] );
					$ctas_in = array_slice( $ctas_in, 0, self::MAX_CTAS );
					foreach ( $ctas_in as $cta_raw ) {
						if ( ! is_array( $cta_raw ) ) {
							continue;
						}
						$label = sanitize_text_field( (string) ( $cta_raw['label'] ?? '' ) );
						$url   = esc_url_raw( (string) ( $cta_raw['url'] ?? '' ) );
						$style = sanitize_key( (string) ( $cta_raw['style'] ?? 'shop' ) );
						if ( ! isset( self::CTA_STYLES[ $style ] ) ) {
							$style = 'shop';
						}
						if ( '' === $label || '' === $url ) {
							continue;
						}
						$slide['ctas'][] = array(
							'label' => $label,
							'url'   => $url,
							'style' => $style,
						);
					}
				}

				$out['slides'][] = $slide;
			}
		}

		// ---- Carousel ----
		$car = self::default_carousel();
		if ( isset( $raw['carousel'] ) && is_array( $raw['carousel'] ) ) {
			$c = $raw['carousel'];
			$car['autoplay']            = ! empty( $c['autoplay'] );
			$car['pause_on_hover']      = ! empty( $c['pause_on_hover'] );
			$car['pause_on_blur']       = ! empty( $c['pause_on_blur'] );
			$car['loop']                = ! empty( $c['loop'] );
			$car['show_arrows_desktop'] = ! empty( $c['show_arrows_desktop'] );
			$car['show_dots_desktop']   = ! empty( $c['show_dots_desktop'] );
			$car['show_dots_mobile']    = ! empty( $c['show_dots_mobile'] );

			$interval = isset( $c['interval_seconds'] ) ? (int) $c['interval_seconds'] : 6;
			$car['interval_seconds'] = max( 2, min( 30, $interval ) );

			$height = isset( $c['hero_height'] ) ? sanitize_key( (string) $c['hero_height'] ) : 'standard';
			$car['hero_height'] = isset( self::HEIGHT_PRESETS[ $height ] ) ? $height : 'standard';

			$car['hero_height_custom_desktop'] = max( 200, min( 2000, (int) ( $c['hero_height_custom_desktop'] ?? 700 ) ) );
			// Techo 50: el CSS phone capea la imagen a min(50vh, …) para que el CTA
			// quede sobre el fold (bottom-nav fija). Aceptar >50 acá era mentirle a
			// la UI — el owner guardaba 65 y el front rendía 50 en silencio.
			$car['hero_height_custom_mobile']  = max( 30, min( 50, (int) ( $c['hero_height_custom_mobile'] ?? 50 ) ) );
		}
		$out['carousel'] = $car;

		return $out;
	}

	/**
	 * Sanitize CSS color. Acepta hex (#abc, #aabbcc, #aabbccdd), var(--foo),
	 * named keyword, o functional notation (rgb/rgba/hsl/hsla/oklch/oklab/lab/
	 * lch/color). Si falla, devuelve ''.
	 */
	private static function sanitize_color( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		// Hex
		if ( preg_match( '/^#(?:[0-9a-f]{3,8})$/i', $raw ) ) {
			return strtolower( $raw );
		}
		// CSS variable
		if ( preg_match( '/^var\(--[a-z0-9_-]+\)$/i', $raw ) ) {
			return $raw;
		}
		// Functional notation: rgb()/rgba()/hsl()/hsla()/oklch()/oklab()/lab()/
		// lch()/color(). Charset interno restringido a [a-z0-9 .,%/-] para
		// evitar inyección (`(`, `;`, `:`, `url`, expresiones).
		if ( preg_match( '/^(?:rgb|rgba|hsl|hsla|oklch|oklab|lab|lch|color)\(\s*[a-z0-9\s.,%\/\-]+\s*\)$/i', $raw ) ) {
			return strtolower( $raw );
		}
		// Keyword muy básica (white, black, transparent, etc.)
		if ( preg_match( '/^[a-z]{3,20}$/i', $raw ) ) {
			return strtolower( $raw );
		}
		return '';
	}

	/**
	 * Sanitize CSS object-position. Acepta keywords ("center", "left top") +
	 * % (0-100). Si falla, devuelve "center center".
	 */
	private static function sanitize_object_position( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 'center center';
		}
		// Permitir keywords + porcentajes + px (lite). Rechaza url(), expressions, etc.
		if ( ! preg_match( '/^[a-z0-9%\s.\-]{1,40}$/i', $raw ) ) {
			return 'center center';
		}
		return $raw;
	}

	/**
	 * Variante opcional de sanitize_object_position: '' significa "auto"
	 * (Renderer deriva el valor) y DEBE sobrevivir al save. Input inválido
	 * también cae a '' — nunca a 'center center', que mataría el auto en
	 * silencio al primer typo.
	 */
	private static function sanitize_object_position_optional( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^[a-z0-9%\s.\-]{1,40}$/i', $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Deriva el object-position tablet desde el mobile: misma X, componente Y
	 * capado a TABLET_FOCAL_Y_CAP. Ver el comentario de la constante para el
	 * porqué (la caja tablet ve una banda mucho más corta del arte vertical).
	 *
	 * Parsing con la semántica CSS real de object-position (a diferencia del
	 * parse_focal naive del AdminUI): keywords clasificados por eje —
	 * left/right → X, top/bottom → Y, center comodín; con un valor numérico en
	 * juego el orden es estricto (1º=X, 2º=Y). Casos no capeables pasan intactos:
	 *   - Y en px (no comparable con %), >2 tokens o combinaciones inválidas en
	 *     CSS ('80% left'): passthrough — sanitize ya los dejó pasar, no empeorar.
	 *   - Y ≤ cap: devuelve el string ORIGINAL byte a byte (sin re-serializar
	 *     'center'→'50%'), para que update_option no vea diffs espurios.
	 */
	public static function derive_tablet_object_position( string $mobile ): string {
		$trimmed = trim( $mobile );
		if ( '' === $trimmed ) {
			return '';
		}

		$tokens = preg_split( '/\s+/', $trimmed );
		if ( ! is_array( $tokens ) || count( $tokens ) > 2 ) {
			return $mobile;
		}

		$x_kw = array( 'left', 'right' );
		$y_kw = array( 'top', 'bottom' );

		// 1 token: keyword de eje Y va a Y; todo lo demás (X keyword, center,
		// numérico) deja Y implícita en center (50) → nunca se capa.
		if ( 1 === count( $tokens ) ) {
			$t = strtolower( $tokens[0] );
			if ( 'bottom' === $t ) {
				return 'center ' . self::TABLET_FOCAL_Y_CAP . '%';
			}
			return $mobile;
		}

		$t0 = strtolower( $tokens[0] );
		$t1 = strtolower( $tokens[1] );

		// Ambos keywords → orden libre ('top left' válido): clasificar por eje.
		$kw_set     = array_merge( $x_kw, $y_kw, array( 'center' ) );
		$both_kw    = in_array( $t0, $kw_set, true ) && in_array( $t1, $kw_set, true );
		$x_token    = $tokens[0];
		$y_token    = $tokens[1];
		if ( $both_kw ) {
			if ( in_array( $t0, $y_kw, true ) || in_array( $t1, $x_kw, true ) ) {
				// Reordenado ('top left', 'bottom center'): swap para X/Y reales.
				// Doble keyword del mismo eje ('left right') es inválido → igual
				// entra al swap y cae en passthrough por Y no-capeable abajo.
				$x_token = $tokens[1];
				$y_token = $tokens[0];
			}
		} elseif ( in_array( $t0, $y_kw, true ) || in_array( $t1, $x_kw, true ) ) {
			// Numérico + keyword fuera de su eje ('80% left', 'top 20%'):
			// inválido en CSS 2-value → passthrough.
			return $mobile;
		}

		// Resolver Y a % si se puede; si no (px u otra unidad), passthrough.
		$y_lower = strtolower( trim( $y_token ) );
		if ( 'top' === $y_lower ) {
			$y_pct = 0.0;
		} elseif ( 'bottom' === $y_lower ) {
			$y_pct = 100.0;
		} elseif ( 'center' === $y_lower ) {
			$y_pct = 50.0;
		} elseif ( preg_match( '/^(\d+(?:\.\d+)?)%$/', $y_lower, $m ) ) {
			$y_pct = (float) $m[1];
		} else {
			return $mobile;
		}

		if ( $y_pct <= (float) self::TABLET_FOCAL_Y_CAP ) {
			return $mobile;
		}

		return $x_token . ' ' . self::TABLET_FOCAL_Y_CAP . '%';
	}

	/**
	 * Filtra los slides activos con al menos una imagen válida.
	 *
	 * Se usa para decidir si el hero necesita JS de carrusel y para mantener
	 * la misma semántica que el renderer al contar slides visibles.
	 *
	 * @param array<string, mixed> $settings Settings ya sanitizados o cargados desde BD.
	 * @return array<int, array<string, mixed>>
	 */
	public static function active_slides( array $settings ): array {
		if ( empty( $settings['slides'] ) || ! is_array( $settings['slides'] ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$settings['slides'],
				static fn( array $slide ): bool => ! empty( $slide['is_active'] ?? true ) && ( ! empty( $slide['desktop_id'] ) || ! empty( $slide['mobile_id'] ) )
			)
		);
	}
}
