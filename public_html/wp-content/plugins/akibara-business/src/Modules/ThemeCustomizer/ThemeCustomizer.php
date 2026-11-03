<?php
/**
 * Theme Customizer — inyector de branding No-Code.
 *
 * Permite inyectar variables CSS dinámicas (--aki-red, etc.) directamente
 * desde wp_options configuradas en el AdminPanel.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\ThemeCustomizer;

use Akibara\Business\Modules\AdminPanel\AdminPanel;

defined( 'ABSPATH' ) || exit;

final class ThemeCustomizer {

	/**
	 * Respaldo del color primario si el theme no expone la paleta.
	 *
	 * La FUENTE ÚNICA del default es la paleta de theme.json (slug `primary`,
	 * hoy #E60012), leída en runtime vía {@see self::theme_palette_color()}.
	 * Este literal solo se usa si `wp_get_global_settings()` no está disponible.
	 */
	private const FALLBACK_PRIMARY = '#E60012';

	/**
	 * Respaldo del color de superficie (slug `surface` de theme.json, hoy #111111).
	 */
	private const FALLBACK_SURFACE = '#111111';

	/**
	 * Tokens de branding No-Code: css_var => [ option_id, palette_slug, fallback ].
	 *
	 * @var array<string, array{0:string,1:string,2:string}>
	 */
	private const BRANDING_TOKENS = array(
		'--aki-red'       => array( 'akb_brand_color_primary', 'primary', self::FALLBACK_PRIMARY ),
		'--aki-surface-1' => array( 'akb_brand_color_surface', 'surface', self::FALLBACK_SURFACE ),
	);

	private AdminPanel $admin_panel;

	public function __construct( AdminPanel $admin_panel ) {
		$this->admin_panel = $admin_panel;
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_admin_tab' ) );
		add_action( 'wp_head', array( $this, 'inject_dynamic_css' ), 100 );
	}

	public function register_admin_tab(): void {
		$this->admin_panel->add_section(
			'branding',
			'Branding y UX',
			function () {
				return array(
					array(
						'title' => 'Branding y UX (No-Code)',
						'type'  => 'title',
						'id'    => 'akb_branding_settings',
						'desc'  => 'Configura los colores del tema y la experiencia de usuario (tokens inyectados vía CSS variables).',
					),
					array(
						'title'   => 'Color Primario (Brand Red)',
						'desc'    => 'Sustituye a --aki-red en todo el sitio.',
						'id'      => 'akb_brand_color_primary',
						'type'    => 'color',
						// Default = color primario del theme (fuente única: theme.json).
						// El picker arranca en el color vigente; mientras coincida,
						// inject_dynamic_css NO pisa el linkage --aki-red del theme.
						'default' => $this->theme_palette_color( 'primary', self::FALLBACK_PRIMARY ),
						'css'     => 'width: 100px;',
					),
					array(
						'title'   => 'Color de Superficie (Surface 1)',
						'desc'    => 'Fondo de componentes como el carrito lateral.',
						'id'      => 'akb_brand_color_surface',
						'type'    => 'color',
						// Default = token surface del theme (#111111): elevación real
						// sobre el bg #0a0a0a. Se lee de theme.json (fuente única).
						'default' => $this->theme_palette_color( 'surface', self::FALLBACK_SURFACE ),
						'css'     => 'width: 100px;',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'akb_branding_settings',
					),
				);
			}
		,
			null,
			false,
			'',
			'sitio'
		);
	}

	public function inject_dynamic_css(): void {
		$declarations = $this->collect_overrides();

		// Sin override del operador no se emite un literal alternativo.
		// El enlace theme.json → presets → tokens del tema conserva la autoridad visual.

		if ( empty( $declarations ) ) {
			return;
		}

		// Inyectar en:root para que sobreescriba los tokens del style.css base.
		// El priority 100 asegura que este bloque quede lo más abajo posible en el head.
		echo "<style id='akb-dynamic-branding'>\n";
		echo ":root {\n";
		foreach ( $declarations as $css_var => $value ) {
			echo "    {$css_var}: {$value};\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $css_var son las claves de la constante BRANDING_TOKENS y $value viene de sanitize_hex_color(); nada de esto es entrada libre.
		}
		echo "}\n";
		echo "</style>\n";
	}

	/**
	 * Junta SOLO los tokens de branding que el operador cambió respecto del theme.
	 *
	 * El default de cada token sale de theme.json (fuente única). Si el color
	 * guardado coincide con el del theme —o no hay nada guardado— el token se
	 * OMITE para no pisar el linkage `--aki-*: var(--wp--preset--color--*)`.
	 *
	 * @return array<string,string> Mapa css_var => color hex saneado a inyectar.
	 */
	private function collect_overrides(): array {
		$declarations = array();

		foreach ( self::BRANDING_TOKENS as $css_var => $spec ) {
			list( $option_id, $slug, $fallback ) = $spec;
			$override = $this->resolve_override( $option_id, $slug, $fallback );
			if ( null !== $override ) {
				$declarations[ $css_var ] = $override;
			}
		}

		return $declarations;
	}

	/**
	 * Devuelve el color a inyectar para un token, o null si no hay override real.
	 *
	 * Conserva la capacidad No-Code: si el operador guardó un color que DIFIERE del
	 * default del theme, se respeta; si coincide (o la option está vacía/corrupta),
	 * devuelve null para dejar vivo el linkage del theme.
	 *
	 * @param string $option_id Clave de wp_option escrita por el AdminPanel.
	 * @param string $slug      Slug del color en la paleta de theme.json.
	 * @param string $fallback  Literal de respaldo si el theme no expone el slug.
	 * @return string|null Hex saneado si difiere del theme; null en caso contrario.
	 */
	private function resolve_override( string $option_id, string $slug, string $fallback ): ?string {
		$theme_default = $this->theme_palette_color( $slug, $fallback );

		// El default de get_option ES el color del theme: si el operador nunca
		// guardó nada, $saved === $theme_default y no se emite ningún override.
		$saved = get_option( $option_id, $theme_default );
		$saved = sanitize_hex_color( is_string( $saved ) ? $saved : '' );
		if ( ! $saved ) {
			return null; // Vacío/corrupto → no arriesgamos un override roto.
		}

		$theme_clean = sanitize_hex_color( $theme_default );
		// Coincide con el theme (case-insensitive) → dejar vivo el linkage.
		if ( $theme_clean && 0 === strcasecmp( $saved, $theme_clean ) ) {
			return null;
		}

		return $saved;
	}

	/**
	 * Lee el color de un slug desde la paleta de theme.json (fuente única del default).
	 *
	 * Usa wp_get_global_settings() para respetar el merge theme/child/usuario. Cae al
	 * $fallback si la API no está disponible o el slug no existe. La paleta puede venir
	 * keyed por origen (custom/theme/default) o como lista plana según versión de WP;
	 * el override del usuario (custom) manda sobre el del theme, y este sobre el default.
	 *
	 * @param string $slug     Slug buscado (p.ej. 'primary', 'surface').
	 * @param string $fallback Color de respaldo.
	 * @return string Color del theme, o el fallback.
	 */
	private function theme_palette_color( string $slug, string $fallback ): string {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $fallback;
		}

		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $palette ) ) {
			return $fallback;
		}

		$origins = isset( $palette[0] )
			? array( $palette )
			: array( $palette['custom'] ?? null, $palette['theme'] ?? null, $palette['default'] ?? null );

		foreach ( $origins as $entries ) {
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'], $entry['color'] ) && $entry['slug'] === $slug ) {
					return (string) $entry['color'];
				}
			}
		}

		return $fallback;
	}
}
