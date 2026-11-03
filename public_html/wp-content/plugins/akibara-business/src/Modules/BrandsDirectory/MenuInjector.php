<?php
/**
 * MenuInjector — reemplaza dinámicamente los children del menu item
 * "Editoriales" (o cualquier item marcado con CSS class `akb-brands-mega`)
 * por items auto-generados desde el taxonomy `product_brand`, agrupados por
 * región con section labels.
 *
 * Cómo funciona:
 *   1. Owner crea un menu item top-level (Apariencia → Menús) con CSS class
 *      `akb-brands-mega` (Pantalla → Opciones → CSS classes).
 *   2. Cualquier child que tenga ese padre se descarta del render.
 *   3. Inyectamos items auto-generados:
 *      - Section header por región (no-clickeable)
 *      - Brand items con link al archive /brand/<slug>/
 *
 * Beneficio: nuevas brands aparecen automáticamente sin tocar el menú admin.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\BrandsDirectory;

use Akibara\Business\Services\BrandsHubUrl;
use WP_Term;

defined( 'ABSPATH' ) || exit;

final class MenuInjector {

	public const TRIGGER_CLASS = 'akb-brands-mega';

	public function register(): void {
		add_filter( 'wp_nav_menu_objects', array( $this, 'inject_brands' ), 10, 2 );
	}

	/**
	 * @param array<int, \stdClass> $items
	 * @return array<int, \stdClass>
	 */
	public function inject_brands( array $items, $args ): array {
		// Find trigger item by CSS class.
		$trigger = null;
		foreach ( $items as $item ) {
			$classes = is_array( $item->classes ?? null ) ? $item->classes : array();
			if ( in_array( self::TRIGGER_CLASS, $classes, true ) ) {
				$trigger = $item;
				break;
			}
		}

		if ( null === $trigger ) {
			return $items;
		}

		// Resolver la fuente dinámica antes de tocar el menú original. Si la
		// taxonomía falla o queda vacía, los children manuales siguen siendo el
		// fallback navegable y el trigger no se convierte en un toggle vacío.
		$brands = $this->fetch_brands();
		if ( empty( $brands ) ) {
			return $items;
		}

		// Force `menu-item-has-children` and `mainnav__sub--mega` classes on trigger
		$trigger->classes = array_unique(
			array_merge(
				is_array( $trigger->classes ) ? $trigger->classes : array(),
				array( 'menu-item-has-children', 'mainnav__sub--mega' )
			)
		);

		// Strip the complete existing subtree of the trigger. Quitar solo los
		// children directos deja nietos huérfanos apuntando a padres ausentes.
		$trigger_id = (int) $trigger->ID;
		$items      = $this->without_descendants( $items, $trigger_id );

		$sorted_brands = $this->sort_brands_by_family( $brands );
		$grouped       = $this->group_by_region( $brands );
		$next_id       = $this->max_id( $items ) + 1;

		/**
		 * Modo de renderizado del submenu de Editoriales (Mega Menú por País).
		 *
		 * Agrupado por país con las mejoras modernas de mega menú:
		 *   - Columnas dedicadas por región en desktop.
		 *   - Accordions accesibles en móvil.
		 *   - Zero-CLS y WAI-ARIA disclosure pattern.
		 *
		 * Override manual para forzar lista unificada sin división regional:
		 *   add_filter( 'akibara_brands_menu_by_region', '__return_false' );
		 */
		$by_region    = (bool) apply_filters( 'akibara_brands_menu_by_region', true );
		$total_brands = count( $brands );
		$threshold    = (int) apply_filters( 'akibara_brands_menu_nested_threshold', 24 );
		$auto_nested  = $threshold > 0 && $total_brands >= $threshold;
		$nested       = (bool) apply_filters( 'akibara_brands_menu_nested', $auto_nested, $grouped );

		// El menú omite por defecto el bloque adicional de landings de familia.
		// akibara_brands_menu_include_landings permite incluirlo.
		$include_landings = (bool) apply_filters( 'akibara_brands_menu_include_landings', false );
		$landing_items    = $include_landings ? $this->build_landing_items( $trigger_id, $next_id ) : array();
		if ( ! empty( $landing_items ) ) {
			$next_id = $this->max_id( $landing_items ) + 1;
		}

		if ( $by_region || $nested ) {
			$injected = array_merge(
				$landing_items,
				$nested
					? $this->build_nested_items( $grouped, $trigger_id, $next_id )
					: $this->build_flat_regional_items( $grouped, $trigger_id, $next_id )
			);
		} else {
			$injected = array_merge(
				$landing_items,
				$this->build_unified_items( $sorted_brands, $trigger_id, $next_id, ! empty( $landing_items ) )
			);
		}

		// El enlace Ver todas es hermano del disparador en ambos modos del menú.
		// BrandsHubUrl comparte su resolución de página con carrusel y grilla.
		$next_id    = $this->max_id( $injected ) + 1;
		$injected[] = $this->build_view_all_item( $next_id, $trigger_id, BrandsHubUrl::url() );

		// wp_nav_menu calcula las clases current-* ANTES de este filtro. Los items
		// runtime no pasan por ese cálculo, por lo que un archivo /marca/<slug>/
		// quedaba sin orientación visual. Aplicamos el contexto actual al brand,
		// su grupo de región (modo nested) y el trigger Editoriales.
		$injected = $this->apply_current_brand_context( $trigger, $injected );

		// Splice injected items right after the trigger in the items array
		// to preserve correct render order.
		return $this->splice_after( $items, $trigger_id, $injected );
	}

	/**
	 * Elimina todos los descendientes de un item, no solo el primer nivel.
	 *
	 * @param array<int, \stdClass> $items
	 * @return array<int, \stdClass>
	 */
	private function without_descendants( array $items, int $parent_id ): array {
		$remove = array();
		$added  = true;

		while ( $added ) {
			$added = false;
			foreach ( $items as $item ) {
				$item_id        = (int) ( $item->ID ?? 0 );
				$item_parent_id = (int) ( $item->menu_item_parent ?? 0 );
				if ( $item_id <= 0 || isset( $remove[ $item_id ] ) ) {
					continue;
				}
				if ( $parent_id === $item_parent_id || isset( $remove[ $item_parent_id ] ) ) {
					$remove[ $item_id ] = true;
					$added              = true;
				}
			}
		}

		return array_values(
			array_filter(
				$items,
				static fn( $item ): bool => ! isset( $remove[ (int) ( $item->ID ?? 0 ) ] )
			)
		);
	}

	/**
	 * Ordena brands por familia editorial (alfabético) y dentro de la misma
	 * familia por país (Argentina primero, luego España u otros).
	 *
	 * Garantiza que:
	 *   - "Ivrea Argentina" quede inmediatamente seguida de "Ivrea España".
	 *   - "Panini Argentina" quede inmediatamente seguida de "Panini España".
	 *   - Las demás editoriales ("Arechi Manga", "Milky Way", "Ovni Press", etc.)
	 *     se ordenen alfabéticamente de forma natural.
	 *
	 * @param list<WP_Term> $brands
	 * @return list<WP_Term>
	 */
	public function sort_brands_by_family( array $brands ): array {
		$prio = array(
			BrandRegion::REGION_ARGENTINA => 1,
			BrandRegion::REGION_ESPANA    => 2,
			BrandRegion::REGION_OTROS     => 3,
		);

		usort(
			$brands,
			static function ( WP_Term $a, WP_Term $b ) use ( $prio ): int {
				$family_a = mb_strtolower( BrandRegion::display_name( $a ), 'UTF-8' );
				$family_b = mb_strtolower( BrandRegion::display_name( $b ), 'UTF-8' );

				if ( $family_a !== $family_b ) {
					return $family_a <=> $family_b;
				}

				$region_a = BrandRegion::for_term( $a );
				$region_b = BrandRegion::for_term( $b );

				$pa = $prio[ $region_a ] ?? 99;
				$pb = $prio[ $region_b ] ?? 99;

				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}

				return strcmp( (string) $a->name, (string) $b->name );
			}
		);

		return array_values( $brands );
	}

	/**
	 * Estructura por defecto: listado continuo de ediciones ordenado por familia
	 * editorial y con nombres completos.
	 *
	 * @param list<WP_Term> $brands
	 * @return list<\stdClass>
	 */
	private function build_unified_items( array $brands, int $trigger_id, int $next_id, bool $has_landings_above = false ): array {
		$out = array();

		if ( $has_landings_above ) {
			$out[] = $this->build_label_item(
				$next_id++,
				$trigger_id,
				'Todas las editoriales'
			);
		}

		foreach ( $brands as $brand ) {
			$out[] = $this->build_brand_item(
				$next_id++,
				$trigger_id,
				$brand,
				false
			);
		}

		return $out;
	}

	/**
	 * Estructura plana regional (legacy / filter): label + brands como siblings depth=1.
	 *
	 * @param array<string, list<\WP_Term>> $grouped
	 * @return list<\stdClass>
	 */
	private function build_flat_regional_items( array $grouped, int $trigger_id, int $next_id ): array {
		$out = array();
		foreach ( $grouped as $region => $region_brands ) {
			if ( empty( $region_brands ) ) {
				continue;
			}

			$out[] = $this->build_label_item(
				$next_id++,
				$trigger_id,
				BrandRegion::label( $region )
			);

			foreach ( $region_brands as $brand ) {
				$out[] = $this->build_brand_item(
					$next_id++,
					$trigger_id,
					$brand,
					false
				);
			}
		}
		return $out;
	}

	/**
	 * Estructura anidada con región navegable y marcas hijas. La clase
	 * menu-item-has-children activa los walkers de escritorio y móvil.
	 * La base del filtro regional se resuelve mediante BrandsHubUrl.
	 *
	 * @param array<string, list<\WP_Term>> $grouped
	 * @return list<\stdClass>
	 */
	private function build_nested_items( array $grouped, int $trigger_id, int $next_id ): array {
		$out      = array();
		$base_url = BrandsHubUrl::url();

		foreach ( $grouped as $region => $region_brands ) {
			if ( empty( $region_brands ) ) {
				continue;
			}

			$parent_id  = $next_id++;
			$region_url = add_query_arg( 'region', $region, $base_url );

			// Region item — parent navegable con children.
			$out[] = $this->build_item(
				array(
					'ID'      => $parent_id,
					'parent'  => $trigger_id,
					'title'   => BrandRegion::label( $region ),
					'url'     => $region_url,
					'classes' => array(
						'akb-brands-mega-region',
						'menu-item-has-children',
					),
				)
			);

			foreach ( $region_brands as $brand ) {
				$out[] = $this->build_brand_item(
					$next_id++,
					$parent_id,
					$brand,
					true
				);
			}
		}

		return $out;
	}

	/**
	 * @return list<WP_Term>
	 */
	private function fetch_brands(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Shortcode::TAXONOMY,
				'hide_empty' => true,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		return is_array( $terms ) ? array_values( array_filter( $terms, static fn( $t ) => $t instanceof WP_Term ) ) : array();
	}

	/**
	 * @param list<WP_Term> $brands
	 * @return array<string, list<WP_Term>>
	 */
	private function group_by_region( array $brands ): array {
		$grouped = array();
		foreach ( BrandRegion::ordered_regions() as $region ) {
			$grouped[ $region ] = array();
		}
		foreach ( $brands as $brand ) {
			$grouped[ BrandRegion::for_term( $brand ) ][] = $brand;
		}
		return array_filter( $grouped, static fn( $v ) => ! empty( $v ) );
	}

	/**
	 * @param array<int, \stdClass> $items
	 */
	private function max_id( array $items ): int {
		$max = 0;
		foreach ( $items as $item ) {
			$max = max( $max, (int) ( $item->ID ?? 0 ) );
		}
		return $max < 100000 ? 100000 : $max;
	}

	/**
	 * Items de las landings paraguas (`/ivrea/`, `/panini/`).
	 *
	 * Devuelve array() cuando ninguna landing resuelve a una página publicada,
	 * de modo que el menú degrada al de siempre en vez de emitir enlaces rotos.
	 * Los items salen con `object` 'custom' —el default de build_item()— para
	 * que `apply_current_brand_context()` los ignore: esa rutina sólo marca
	 * items cuyo `object` es la taxonomía.
	 *
	 * @return list<\stdClass>
	 */
	private function build_landing_items( int $parent_id, int $next_id ): array {
		$items = array();

		foreach ( BrandLandings::all() as $key => $landing ) {
			$url = BrandLandings::url( (string) $key );
			if ( '' === $url ) {
				continue;
			}

			$items[] = $this->build_item(
				array(
					'ID'      => $next_id + count( $items ) + 1,
					'parent'  => $parent_id,
					'title'   => (string) ( $landing['menu_label'] ?? '' ),
					'url'     => $url,
					'classes' => array( 'akb-brands-mega-landing' ),
				)
			);
		}

		if ( empty( $items ) ) {
			return array();
		}

		array_unshift( $items, $this->build_label_item( $next_id, $parent_id, 'Todas las ediciones' ) );

		return $items;
	}

	private function build_label_item( int $id, int $parent_id, string $label ): \stdClass {
		return $this->build_item(
			array(
				'ID'      => $id,
				'parent'  => $parent_id,
				'title'   => $label,
				'url'     => '#',
				'classes' => array( 'mainnav__sub-label', 'akb-brands-mega-label' ),
			)
		);
	}

	private function build_brand_item( int $id, int $parent_id, WP_Term $brand, bool $strip_country = false ): \stdClass {
		$link   = get_term_link( $brand );
		$region = BrandRegion::for_term( $brand );
		$title  = $strip_country ? BrandRegion::display_name( $brand, $region ) : (string) $brand->name;

		$classes = array( 'akb-brands-mega-brand' );
		if ( '' !== $region ) {
			$safe_region = preg_replace( '/[^a-zA-Z0-9_-]/', '', $region );
			if ( '' !== $safe_region ) {
				$classes[] = 'akb-brand--' . $safe_region;
			}
		}

		return $this->build_item(
			array(
				'ID'        => $id,
				'parent'    => $parent_id,
				'title'     => $title,
				'url'       => is_wp_error( $link ) ? '#' : (string) $link,
				'classes'   => $classes,
				'object'    => Shortcode::TAXONOMY,
				'object_id' => (string) $brand->term_id,
			)
		);
	}

	/**
	 * Replica las clases `current-*` que WordPress no puede calcular para items
	 * inyectados después de resolver el menú.
	 *
	 * @param object             $trigger  Item top-level "Editoriales" (WP_Post o item sintético).
	 * @param list<\stdClass>    $injected Items generados en runtime.
	 * @return list<\stdClass>
	 */
	private function apply_current_brand_context( object $trigger, array $injected ): array {
		$queried = get_queried_object();
		if ( ! $queried instanceof WP_Term || Shortcode::TAXONOMY !== (string) $queried->taxonomy ) {
			return $injected;
		}

		$current_parent_id = 0;
		foreach ( $injected as $item ) {
			if (
				Shortcode::TAXONOMY === (string) ( $item->object ?? '' )
				&& (int) ( $item->object_id ?? 0 ) === (int) $queried->term_id
			) {
				$item->current = true;
				$item->classes = $this->add_classes( $item->classes ?? array(), array( 'current-menu-item' ) );
				$current_parent_id = (int) ( $item->menu_item_parent ?? 0 );
				break;
			}
		}

		if ( 0 === $current_parent_id ) {
			return $injected;
		}

		$trigger_id = (int) ( $trigger->ID ?? 0 );
		if ( $current_parent_id !== $trigger_id ) {
			foreach ( $injected as $item ) {
				if ( (int) ( $item->ID ?? 0 ) !== $current_parent_id ) {
					continue;
				}
				$item->current_item_parent   = true;
				$item->current_item_ancestor = true;
				$item->classes = $this->add_classes(
					$item->classes ?? array(),
					array( 'current-menu-parent', 'current-menu-ancestor' )
				);
				break;
			}
		} else {
			$trigger->current_item_parent = true;
			$trigger->classes = $this->add_classes( $trigger->classes ?? array(), array( 'current-menu-parent' ) );
		}

		$trigger->current_item_ancestor = true;
		$trigger->classes = $this->add_classes( $trigger->classes ?? array(), array( 'current-menu-ancestor' ) );

		return $injected;
	}

	/**
	 * @param mixed        $current Clases existentes.
	 * @param list<string> $extra   Clases a sumar.
	 * @return list<string>
	 */
	private function add_classes( $current, array $extra ): array {
		$classes = is_array( $current ) ? $current : array();
		return array_values( array_unique( array_merge( $classes, $extra ) ) );
	}

	private function build_view_all_item( int $id, int $parent_id, string $url ): \stdClass {
		return $this->build_item(
			array(
				'ID'      => $id,
				'parent'  => $parent_id,
				'title'   => 'Ver todas →',
				'url'     => $url,
				'classes' => array( 'mainnav__sublink--all', 'akb-brands-mega-all' ),
			)
		);
	}

	/**
	 * Construye un menu item compatible con el walker WP estándar.
	 *
	 * @param array{ID: int, parent: int, title: string, url: string, classes: list<string>, object?: string, object_id?: string} $a
	 */
	private function build_item( array $a ): \stdClass {
		$item                    = new \stdClass();
		$item->ID                = $a['ID'];
		$item->db_id             = $a['ID'];
		$item->menu_item_parent  = (string) $a['parent'];
		$item->object_id         = $a['object_id'] ?? (string) $a['ID'];
		$item->object            = $a['object'] ?? 'custom';
		$item->type              = 'custom';
		$item->type_label        = 'Custom Link';
		$item->title             = $a['title'];
		$item->url               = $a['url'];
		$item->target            = '';
		$item->attr_title        = '';
		$item->description       = '';
		$item->classes           = $a['classes'];
		$item->xfn               = '';
		$item->current           = false;
		$item->current_item_ancestor = false;
		$item->current_item_parent   = false;
		return $item;
	}

	/**
	 * Inserta items inyectados después del trigger en el array, manteniendo
	 * el orden global de los siblings.
	 *
	 * @param array<int, \stdClass> $items
	 * @param int                   $trigger_id
	 * @param array<int, \stdClass> $injected
	 * @return array<int, \stdClass>
	 */
	private function splice_after( array $items, int $trigger_id, array $injected ): array {
		$out = array();
		foreach ( $items as $item ) {
			$out[] = $item;
			if ( (int) $item->ID === $trigger_id ) {
				foreach ( $injected as $i ) {
					$out[] = $i;
				}
			}
		}
		return $out;
	}
}
