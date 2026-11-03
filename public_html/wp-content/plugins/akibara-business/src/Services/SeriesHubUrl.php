<?php
/**
 * SeriesHubUrl — la URL real de la página que aloja `[akibara_series_directory]`.
 *
 * POR QUÉ EXISTE. `/series/` es hoy una página de
 * WordPress que el dueño puede mover o renombrar desde Páginas → Editar —y ya
 * lo hizo una vez: el propio docblock de `SeriesDirectory\Shortcode` deja
 * constancia de que esa URL fue antes una redirección 301 a `/tienda/`. El tema
 * (`akibara_gp_series_hub_url()`, `inc/series-breadcrumb.php`) ya resuelve esto
 * bien: busca la página que CONTIENE el shortcode en vez de asumir el slug.
 * Pero tres piezas de negocio (el schema de `SeoEnhancements`, la purga de
 * `SeriesDirectory\Shortcode` y el enlace «Ver todas» de
 * `HomeSeriesDestacadas`) y una cuarta (`AccountCollection\Renderer`)
 * repetían el literal `home_url('/series/')` cada una por su cuenta: cambiar
 * el slug las dejaba a las cuatro apuntando a una URL rota (schema JSON-LD que
 * Google indexa igual, purgas de LiteSpeed que no purgan nada, enlaces 404),
 * mientras el breadcrumb visible del tema se autocorregía solo.
 *
 * Esta clase es la ÚNICA fuente de esa URL dentro de `akibara-business`: los
 * cuatro consumidores la llaman a ella, no repiten la búsqueda ni el literal.
 * No se reutiliza la función del tema porque el plugin no puede depender de
 * que el tema activo la defina (theme-agnostic, ver docblock de
 * `BrandsDirectory\HomeCarousel`), pero el ALGORITMO es el mismo a propósito:
 * buscar por `has_shortcode()`, no por slug.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Modules\SeriesDirectory\Shortcode;

defined( 'ABSPATH' ) || exit;

final class SeriesHubUrl {

	/**
	 * Memoria de PROCESO (no transient): la página no cambia de shortcode a
	 * media petición, y esto se llama a lo sumo un puñado de veces por render
	 * (schema, purga, enlace «Ver todas»). `null` = todavía no resuelto en esta
	 * petición; `''` no es un valor válido de caché porque siempre hay un
	 * fallback no vacío.
	 */
	private static ?string $resolved = null;

	/**
	 * URL absoluta de la página real, o el literal `/series/` como fallback si
	 * ninguna página publicada contiene el shortcode (todavía no se creó, o se
	 * despublicó).
	 */
	public static function url(): string {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$found           = self::find_by_shortcode();
		self::$resolved = '' !== $found ? $found : home_url( '/series/' );

		return self::$resolved;
	}

	/**
	 * Sólo para pruebas: fuerza a volver a resolver en la siguiente llamada.
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$resolved = null;
	}

	/**
	 * Busca entre las páginas publicadas la que contiene el shortcode del
	 * directorio. Pide varias (no una) porque la búsqueda de WordPress (`s`)
	 * empareja por texto y una página que sólo MENCIONE el nombre del
	 * shortcode en prosa podría ganarle a la que realmente lo usa.
	 */
	private static function find_by_shortcode(): string {
		$pages = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'posts_per_page'         => 5,
				's'                      => Shortcode::TAG,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( (array) $pages as $page_id ) {
			$content = (string) get_post_field( 'post_content', (int) $page_id );
			if ( has_shortcode( $content, Shortcode::TAG ) ) {
				return (string) get_permalink( (int) $page_id );
			}
		}

		return '';
	}
}
