<?php
/**
 * BrandRegion — clasifica brands del taxonomy `product_brand` en regiones
 * (Argentina / España / Otros) y expone metadata visual (badge text, color,
 * label) que el theme consume sin duplicar lógica.
 *
 * Single source of truth: la estructura `regions()` describe TODA la metadata
 * de cada región (label, group_label, badge_text, badge_color, slug_suffix,
 * name_hints). Filtrable via `akibara_brand_regions` para que un site
 * agregue un nuevo país sin tocar el plugin core.
 *
 * Estrategia de resolución (en orden):
 *   1. Term meta `akibara_region` editable desde wp-admin → Marcas → Editar.
 *   2. Slug heurístico — sufijo configurable por región (`-argentina`, `-espana`).
 *   3. Slug map exact match (legacy slugs sin sufijo: `ovni-press`, etc.).
 *   4. Heurística por substring del nombre (`name_hints` por región).
 *   5. Default `otros`.
 *
 * Filter `akibara_brand_region` queda expuesto para overrides programáticos
 * de la región final ya resuelta.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use WP_Term;

defined( 'ABSPATH' ) || exit;

final class BrandRegion {

	public const REGION_ARGENTINA = 'argentina';
	public const REGION_ESPANA    = 'espana';
	public const REGION_OTROS     = 'otros';

	public const META_KEY = 'akibara_region';

	/**
	 * Slug map para legacy slugs que no siguen el sufijo predecible. Brands
	 * nuevas con slug `<editorial>-argentina` / `<editorial>-espana` se
	 * detectan vía sufijo automáticamente y NO necesitan estar acá.
	 *
	 * @var array<string, string>
	 */
	private const SLUG_MAP = array(
		'ovni-press'      => self::REGION_ARGENTINA,
		'distrito-manga'  => self::REGION_ESPANA,
		'devir'           => self::REGION_ESPANA,
		'milky-way'       => self::REGION_ESPANA,
		'arechi-manga'    => self::REGION_ESPANA,
		'norma-editorial' => self::REGION_ESPANA,
		'tomodomo'        => self::REGION_ESPANA,
		'kodai'           => self::REGION_ESPANA,
		'letrablanka'     => self::REGION_ESPANA,
	);

	/**
	 * Single source of truth de regiones. Cada entrada incluye TODA la
	 * metadata necesaria para renderizar (badge en home + agrupar en grid +
	 * auto-detect por slug/nombre).
	 *
	 * Para añadir una región nueva (ej. México) sin tocar este archivo:
	 *
	 *   add_filter( 'akibara_brand_regions', function( $regions ) {
	 *       $regions['mexico'] = array(
	 *           'label'       => 'México',
	 *           'group_label' => 'Editoriales mexicanas',
	 *           'badge_text'  => 'MX',
	 *           'badge_color' => '#10B981',
	 *           'slug_suffix' => '-mexico',
	 *           'name_hints'  => array( 'mexico' ),
	 *       );
	 *       return $regions;
	 *   });
	 *
	 * Importante: si añades región nueva, también define una constante de
	 * código (ej. `REGION_MEXICO = 'mexico'`) si planeas referenciarla
	 * desde otro código — para uso solo via filter no es necesario.
	 *
	 * @return array<string, array{label:string,group_label:string,badge_text:string,badge_color:string,slug_suffix:string,name_hints:list<string>}>
	 */
	public static function regions(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}

		$defaults = array(
			self::REGION_ARGENTINA => array(
				'label'       => 'Argentina',
				'group_label' => 'Editoriales argentinas',
				'badge_text'  => 'AR',
				'badge_color' => '#0084C7',
				'slug_suffix' => '-argentina',
				'name_hints'  => array( 'argentina', 'ovni' ),
			),
			self::REGION_ESPANA    => array(
				'label'       => 'España',
				'group_label' => 'Editoriales españolas',
				'badge_text'  => 'ES',
				'badge_color' => 'var(--aki-red)',
				'slug_suffix' => '-espana',
				// Los nombres genéricos Ivrea y Panini no determinan país: ambas familias
				// tienen ediciones nacionales distintas. El slug con sufijo resuelve antes;
				// sin esa pista se conserva Otros. Planeta sí mantiene su pista de nombre
				// porque el mapa del catálogo no contiene una edición argentina gemela.
				'name_hints'  => array( 'espa', 'milky', 'arechi', 'norma', 'planeta', 'distrito' ),
			),
			self::REGION_OTROS     => array(
				'label'       => 'Otros',
				'group_label' => 'Otras editoriales',
				'badge_text'  => '',
				'badge_color' => 'var(--aki-text-muted, #6b7280)',
				'slug_suffix' => '',
				'name_hints'  => array(),
			),
		);

		$cache = (array) apply_filters( 'akibara_brand_regions', $defaults );
		return $cache;
	}

	/**
	 * Acceso a metadata de una región. Default fallback a OTROS si no existe.
	 *
	 * @return array{label:string,group_label:string,badge_text:string,badge_color:string,slug_suffix:string,name_hints:list<string>}
	 */
	public static function region( string $code ): array {
		$regions = self::regions();
		return $regions[ $code ] ?? $regions[ self::REGION_OTROS ];
	}

	/**
	 * Resuelve la región de un brand. Cascada:
	 *   1. Term meta (admin override)
	 *   2. Slug heurístico (sufijo configurable)
	 *   3. SLUG_MAP exact (BC para slugs legacy sin sufijo)
	 *   4. Heurística por substring del nombre
	 *   5. Default `otros`
	 *
	 * Filtrable vía `akibara_brand_region` (final resolved code).
	 */
	public static function for_term( WP_Term $term ): string {
		$meta = get_term_meta( $term->term_id, self::META_KEY, true );
		if ( is_string( $meta ) && in_array( $meta, self::valid_regions(), true ) ) {
			return (string) apply_filters( 'akibara_brand_region', $meta, $term );
		}

		$by_slug = self::for_slug( $term->slug );
		if ( self::REGION_OTROS !== $by_slug ) {
			return (string) apply_filters( 'akibara_brand_region', $by_slug, $term );
		}

		$by_name = self::for_name( (string) $term->name );
		return (string) apply_filters( 'akibara_brand_region', $by_name, $term );
	}

	/**
	 * Resuelve región por slug solo. Sufijo configurable por región (e.g.
	 * `-argentina` / `-espana` / `-mexico` si se añade) cubre brands nuevas
	 * que sigan la convención sin tocar código.
	 */
	public static function for_slug( string $slug ): string {
		foreach ( self::regions() as $code => $cfg ) {
			$suffix = (string) ( $cfg['slug_suffix'] ?? '' );
			if ( '' === $suffix ) {
				continue;
			}
			if ( str_ends_with( $slug, $suffix ) ) {
				return $code;
			}
			// Variant con ñ para `-espana` legacy.
			if ( '-espana' === $suffix && str_ends_with( $slug, '-españa' ) ) {
				return $code;
			}
		}

		// BC: legacy slug map para brands sin sufijo predecible.
		if ( isset( self::SLUG_MAP[ $slug ] ) ) {
			return self::SLUG_MAP[ $slug ];
		}

		return self::REGION_OTROS;
	}

	/**
	 * Resuelve región por substring del nombre. Cubre brands cuyo slug no
	 * coincide con sufijo ni map (ej. names tipo "Editorial X de México"
	 * con slug `editorial-x`).
	 *
	 * Reemplaza la antigua `HomeCarousel::detect_country()` para unificar
	 * la lógica en un solo lugar.
	 */
	public static function for_name( string $name ): string {
		$lower = mb_strtolower( $name, 'UTF-8' );
		foreach ( self::regions() as $code => $cfg ) {
			foreach ( (array) ( $cfg['name_hints'] ?? array() ) as $hint ) {
				$hint_lower = mb_strtolower( (string) $hint, 'UTF-8' );
				if ( '' !== $hint_lower && false !== strpos( $lower, $hint_lower ) ) {
					return $code;
				}
			}
		}
		return self::REGION_OTROS;
	}

	public static function label( string $region ): string {
		return self::region( $region )['group_label'];
	}

	/**
	 * Display name del brand sin el sufijo de país (ej. "Ivrea Argentina" →
	 * "Ivrea"). Pensado para contextos UI donde el badge AR/ES o el header de
	 * grupo ("EDITORIALES ARGENTINAS") ya comunican la región — el sufijo en
	 * el nombre sería redundancia.
	 *
	 * Strip-en-presentación: NO muta `$term->name` en BD. Otros consumers
	 * (`single brand page`, breadcrumbs, REST) siguen viendo el name
	 * original. Si no hay sufijo detectable, devuelve el name tal cual.
	 *
	 * Stripea el label de la región (`Argentina`, `España`) al final del
	 * nombre, case-insensitive, soporta variante sin tilde (`Espana`) por BC
	 * con names legacy.
	 *
	 * @param WP_Term     $term   Term del brand.
	 * @param string|null $region Region pre-resuelta (evita doble lookup). Si
	 *                            null, se resuelve aquí.
	 */
	public static function display_name( WP_Term $term, ?string $region = null ): string {
		$name = (string) $term->name;
		if ( '' === $name ) {
			return $name;
		}

		$region      = $region ?? self::for_term( $term );
		$region_data = self::region( $region );
		$label       = (string) ( $region_data['label'] ?? '' );
		if ( '' === $label ) {
			return $name;
		}

		// Strip trailing " Label" (e.g. " Argentina", " España"), case-insensitive.
		$stripped = preg_replace(
			'/\s+' . preg_quote( $label, '/' ) . '$/iu',
			'',
			$name
		);

		// BC: variante sin tilde para names legacy "Ivrea Espana".
		if ( self::REGION_ESPANA === $region ) {
			$stripped = preg_replace( '/\s+Espana$/iu', '', (string) $stripped );
		}

		$result = is_string( $stripped ) ? trim( $stripped ) : $name;
		return '' !== $result ? $result : $name;
	}

	/**
	 * Orden canónico para grid agrupado: regiones definidas en `regions()`
	 * en su orden, con OTROS siempre al final aunque haya sido movido por
	 * filter.
	 *
	 * @return list<string>
	 */
	public static function ordered_regions(): array {
		$codes = array_keys( self::regions() );
		$codes = array_values( array_filter( $codes, static fn( $c ) => self::REGION_OTROS !== $c ) );
		$codes[] = self::REGION_OTROS;
		return $codes;
	}

	/**
	 * @return list<string>
	 */
	public static function valid_regions(): array {
		return array_keys( self::regions() );
	}

	/**
	 * Choices para el `<select>` admin del field "Región (Akibara)".
	 *
	 * @return array<string, string>
	 */
	public static function admin_choices(): array {
		$out = array(
			'' => '— Auto (por slug) —',
		);
		foreach ( self::regions() as $code => $cfg ) {
			$out[ $code ] = (string) ( $cfg['group_label'] ?? $code );
		}
		return $out;
	}
}
