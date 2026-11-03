<?php
/**
 * BrandsHubUrl — la URL real de la página que aloja `[akibara_brands_grid]`.
 *
 * POR QUÉ EXISTE. Mismo defecto de fuente
 * única que `Services\SeriesHubUrl` cerró para `/series/` (), pero
 * para el directorio de editoriales: `HomeCarousel::render()`,
 * `MenuInjector` (dos veces: el link «Ver todas» y la región del modo nested)
 * y `BrandsDirectory\Shortcode::hub_url()` repetían cada uno su propio
 * `get_page_by_path('editoriales')`. Si el dueño mueve o renombra la página
 * desde Páginas → Editar, unas piezas la seguían y otras no.
 *
 * Esta clase es la ÚNICA fuente de esa URL dentro de `akibara-business`: los
 * cuatro consumidores la llaman a ella, no repiten la búsqueda ni el literal.
 * El ALGORITMO es el mismo que `SeriesHubUrl` a propósito: buscar por
 * `has_shortcode()`, no por slug — así una editorial nueva de página no deja
 * a nadie apuntando a una URL vieja o rota.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

use Akibara\Business\Modules\BrandsDirectory\Shortcode;

defined( 'ABSPATH' ) || exit;

final class BrandsHubUrl {

	/**
	 * Memoria de PROCESO (no transient): la página no cambia de shortcode a
	 * media petición, y esto se llama a lo sumo un puñado de veces por render
	 * (carrusel del home, menú, purga). `null` = todavía no resuelto en esta
	 * petición; `''` no es un valor válido de caché porque siempre hay un
	 * fallback no vacío.
	 */
	private static ?string $resolved = null;

	/**
	 * URL absoluta de la página real, o el literal `/editoriales/` como
	 * fallback si ninguna página publicada contiene el shortcode (todavía no
	 * se creó, o se despublicó).
	 */
	public static function url(): string {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$found           = self::find_by_shortcode();
		self::$resolved = '' !== $found ? $found : home_url( '/editoriales/' );

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
