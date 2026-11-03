<?php
/**
 * Tests for the runtime brands menu state.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WP_Term' ) ) {
		class WP_Term {
			public int $term_id;
			public string $taxonomy;
			public string $slug;
			public string $name;
			public int $count;

			public function __construct( int $term_id, string $slug, string $name ) {
				$this->term_id  = $term_id;
				$this->taxonomy = 'product_brand';
				$this->slug     = $slug;
				$this->name     = $name;
				$this->count    = 1;
			}
		}
	}

	// WP_Post: stub canónico en tests/wp-stubs.php (fuente única).
}

namespace Akibara\Business\Tests\Modules\BrandsDirectory {

	use Akibara\Business\Modules\BrandsDirectory\MenuInjector;
	use Akibara\Business\Services\BrandsHubUrl;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use WP_Post;
	use WP_Term;

	final class MenuInjectorTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			BrandsHubUrl::reset();

			// 2026-09-19 (R3-editoriales-hub): el link «Ver todas» y la región del
			// modo nested ya no resuelven con `get_page_by_path('editoriales')`,
			// sino con `BrandsHubUrl` (busca por shortcode vía `get_posts`). Sin
			// páginas candidatas, cae al literal `/editoriales/` — home_url() lo
			// necesita como fallback.
			Functions\when( 'get_posts' )->justReturn( array() );
			Functions\when( 'home_url' )->alias(
				static fn( string $path = '' ): string => 'https://example.test' . $path
			);
			Functions\when( 'add_query_arg' )->alias(
				static fn( string $key, string $value, string $url ): string => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value
			);
		}

		protected function tearDown(): void {
			BrandsHubUrl::reset();
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * @testdox Should mark the current brand and its Editoriales parent.
		 */
		public function test_current_brand_marks_brand_and_wp_post_editoriales_parent(): void {
			$current = new WP_Term( 22, 'milky-way-espana', 'Milky Way España' );
			$other   = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );
			$current->taxonomy = 'product_brand';
			$other->taxonomy   = 'product_brand';

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $other, $current ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $term ): string => 'https://example.test/marca/' . $term->slug . '/'
			);
			Functions\when( 'get_page_by_path' )->justReturn( null );
			Functions\when( 'get_queried_object' )->justReturn( $current );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items  = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );
			$brands = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => 'product_brand' === (string) ( $item->object ?? '' )
				)
			);
			$active = array_values(
				array_filter(
					$brands,
					static fn( \stdClass $item ): bool => ! empty( $item->current )
				)
			);

			self::assertCount( 1, $active );
			self::assertSame( '22', $active[0]->object_id );
			self::assertContains( 'current-menu-item', $active[0]->classes );
			self::assertContains( 'current-menu-ancestor', $trigger->classes );
			self::assertContains( 'current-menu-parent', $trigger->classes );
			self::assertTrue( $trigger->current_item_ancestor );
			self::assertTrue( $trigger->current_item_parent );
		}

		/**
		 * @testdox Should mark the nested region parent and trigger ancestor.
		 */
		public function test_nested_menu_marks_region_parent_and_trigger_ancestor(): void {
			$current = new WP_Term( 22, 'milky-way', 'Milky Way' );
			$other   = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );
			$current->taxonomy = 'product_brand';
			$other->taxonomy   = 'product_brand';

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					return 'akibara_brands_menu_nested' === $hook ? true : $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $other, $current ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $term ): string => 'https://example.test/marca/' . $term->slug . '/'
			);
			Functions\when( 'get_page_by_path' )->justReturn( null );
			Functions\when( 'get_queried_object' )->justReturn( $current );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );
			$brand = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => 'product_brand' === (string) ( $item->object ?? '' )
						&& 22 === (int) ( $item->object_id ?? 0 )
				)
			)[0];
			$region = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => (int) ( $item->ID ?? 0 ) === (int) $brand->menu_item_parent
				)
			)[0];

			self::assertTrue( $brand->current );
			self::assertContains( 'current-menu-item', $brand->classes );
			self::assertTrue( $region->current_item_parent );
			self::assertTrue( $region->current_item_ancestor );
			self::assertContains( 'current-menu-parent', $region->classes );
			self::assertContains( 'current-menu-ancestor', $region->classes );
			self::assertTrue( $trigger->current_item_ancestor );
			self::assertFalse( $trigger->current_item_parent );
		}

		/**
		 * R3-editoriales-hub: el link «Ver todas» y la región del modo nested
		 * comparten fuente única con `HomeCarousel` y `Shortcode::purge_hub_page_cache()`
		 * vía `BrandsHubUrl` — resuelven por la página que CONTIENE el shortcode,
		 * no por el slug `editoriales`. Antes cada uno repetía su propio
		 * `get_page_by_path('editoriales')`; ahora ambos usan la URL real que
		 * `BrandsHubUrl` encuentra buscando el shortcode `akibara_brands_grid`.
		 *
		 * @testdox Should build the «Ver todas» link and the nested region URL from BrandsHubUrl, not from a slug lookup.
		 */
		public function test_view_all_link_and_nested_region_url_come_from_brands_hub_url(): void {
			$brand = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					return 'akibara_brands_menu_nested' === $hook ? true : $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $brand ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/marca/ivrea-argentina/' );
			Functions\when( 'get_queried_object' )->justReturn( null );
			// Landings paraguas (`BrandLandings::url()`), feature aparte: sin
			// página publicada, no aportan items al menú en este caso.
			Functions\when( 'get_page_by_path' )->justReturn( null );

			// El dueño renombró la página del directorio: ya no vive en el slug
			// `editoriales`, sino en `todas-las-editoriales`. `get_page_by_path`
			// no encontraría nada; `BrandsHubUrl` la ubica por el shortcode.
			Functions\when( 'get_posts' )->justReturn( array( 909 ) );
			Functions\when( 'get_post_field' )->justReturn( '[akibara_brands_grid]' );
			Functions\when( 'has_shortcode' )->justReturn( true );
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/todas-las-editoriales/' );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );

			$ver_todas = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => in_array( 'akb-brands-mega-all', (array) ( $item->classes ?? array() ), true )
				)
			);
			self::assertNotEmpty( $ver_todas, 'El link «Ver todas» debe existir aunque la página no viva en el slug editoriales.' );
			self::assertSame( 'https://example.test/todas-las-editoriales/', $ver_todas[0]->url );

			$region = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => in_array( 'akb-brands-mega-region', (array) ( $item->classes ?? array() ), true )
				)
			)[0];
			self::assertStringStartsWith( 'https://example.test/todas-las-editoriales/', $region->url );
		}

		public function test_empty_taxonomy_preserves_manual_fallback_unchanged(): void {
			Functions\when( 'get_terms' )->justReturn( array() );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$manual                   = new WP_Post();
			$manual->ID               = 11;
			$manual->classes          = array( 'menu-item' );
			$manual->menu_item_parent = '10';

			$input  = array( $trigger, $manual );
			$result = ( new MenuInjector() )->inject_brands( $input, (object) array() );

			self::assertSame( $input, $result );
			self::assertNotContains( 'menu-item-has-children', $trigger->classes );
		}

		public function test_dynamic_menu_removes_the_complete_manual_subtree(): void {
			$brand = new WP_Term( 22, 'milky-way', 'Milky Way' );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $brand ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/marca/milky-way/' );
			Functions\when( 'get_page_by_path' )->justReturn( null );
			Functions\when( 'get_queried_object' )->justReturn( null );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$child                   = new WP_Post();
			$child->ID               = 11;
			$child->classes          = array( 'menu-item' );
			$child->menu_item_parent = '10';

			$grandchild                   = new WP_Post();
			$grandchild->ID               = 12;
			$grandchild->classes          = array( 'menu-item' );
			$grandchild->menu_item_parent = '11';

			$sibling                   = new WP_Post();
			$sibling->ID               = 20;
			$sibling->classes          = array( 'menu-item' );
			$sibling->menu_item_parent = '0';

			$result = ( new MenuInjector() )->inject_brands(
				array( $trigger, $child, $grandchild, $sibling ),
				(object) array()
			);
			$ids    = array_map( static fn( object $item ): int => (int) $item->ID, $result );

			self::assertNotContains( 11, $ids );
			self::assertNotContains( 12, $ids );
			self::assertContains( 20, $ids );
			self::assertContains( 'menu-item-has-children', $trigger->classes );
		}

		/**
		 * @testdox Should inject the umbrella landing links above the region list.
		 */
		public function test_landings_paraguas_se_inyectan_con_su_url(): void {
			$brand = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_include_landings' === $hook ) {
						return true;
					}
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $brand ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $term ): string => 'https://example.test/marca/' . $term->slug . '/'
			);
			Functions\when( 'get_queried_object' )->justReturn( null );
			Functions\when( 'get_page_by_path' )->alias(
				static function ( string $slug ): ?WP_Post {
					$page              = new WP_Post();
					$page->ID          = 27449;
					$page->post_status = 'publish';
					return $page;
				}
			);
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/ivrea/' );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items    = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );
			$landings = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => in_array(
						'akb-brands-mega-landing',
						(array) ( $item->classes ?? array() ),
						true
					)
				)
			);

			self::assertNotEmpty( $landings );
			self::assertSame( 'https://example.test/ivrea/', $landings[0]->url );
			// `object` debe seguir siendo 'custom': apply_current_brand_context()
			// sólo marca items de la taxonomía, y un item paraguas marcado como
			// brand contaminaría el estado current del mega.
			self::assertSame( 'custom', (string) $landings[0]->object );
		}

		/**
		 * @testdox Should degrade to the previous menu when the landing page is missing.
		 */
		public function test_sin_pagina_publicada_el_menu_queda_igual_que_antes(): void {
			$brand = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $brand ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $term ): string => 'https://example.test/marca/' . $term->slug . '/'
			);
			Functions\when( 'get_queried_object' )->justReturn( null );
			Functions\when( 'get_page_by_path' )->justReturn( null );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items    = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );
			$landings = array_filter(
				$items,
				static fn( object $item ): bool => in_array(
					'akb-brands-mega-landing',
					(array) ( $item->classes ?? array() ),
					true
				)
			);

			self::assertSame( array(), $landings );
		}

		/**
		 * @testdox Ordena marcas por familia editorial: Ivrea AR seguida de Ivrea ES, Panini AR seguida de Panini ES.
		 */
		public function test_sort_brands_by_family_unifies_and_orders_ivrea_and_panini_consecutively(): void {
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);

			$arechi     = new WP_Term( 1, 'arechi-manga', 'Arechi Manga' );
			$ivrea_es   = new WP_Term( 2, 'ivrea-espana', 'Ivrea España' );
			$panini_es  = new WP_Term( 3, 'panini-espana', 'Panini España' );
			$milky      = new WP_Term( 4, 'milky-way', 'Milky Way' );
			$panini_ar  = new WP_Term( 5, 'panini-argentina', 'Panini Argentina' );
			$ivrea_ar   = new WP_Term( 6, 'ivrea-argentina', 'Ivrea Argentina' );
			$ovni       = new WP_Term( 7, 'ovni-press', 'Ovni Press' );
			$planeta    = new WP_Term( 8, 'planeta-espana', 'Planeta Cómic' );

			$injector = new MenuInjector();
			$sorted   = $injector->sort_brands_by_family(
				array( $planeta, $ivrea_es, $panini_es, $arechi, $ovni, $panini_ar, $milky, $ivrea_ar )
			);

			$slugs = array_map( static fn( WP_Term $t ): string => $t->slug, $sorted );

			self::assertSame(
				array(
					'arechi-manga',
					'ivrea-argentina',
					'ivrea-espana',
					'milky-way',
					'ovni-press',
					'panini-argentina',
					'panini-espana',
					'planeta-espana',
				),
				$slugs
			);
		}

		/**
		 * @testdox En el menú unificado se muestran todas las editoriales con título completo sin dividir por región.
		 */
		public function test_unified_menu_presents_all_editoriales_without_regional_split_and_full_titles(): void {
			$ivrea_ar  = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );
			$ivrea_es  = new WP_Term( 12, 'ivrea-espana', 'Ivrea España' );
			$panini_ar = new WP_Term( 21, 'panini-argentina', 'Panini Argentina' );
			$panini_es = new WP_Term( 22, 'panini-espana', 'Panini España' );

			Functions\when( 'apply_filters' )->alias(
				static function ( string $hook, $value, ...$args ) {
					if ( 'akibara_brands_menu_by_region' === $hook ) {
						return false;
					}
					return $value;
				}
			);
			Functions\when( 'get_terms' )->justReturn( array( $ivrea_es, $panini_ar, $ivrea_ar, $panini_es ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->alias(
				static fn( WP_Term $term ): string => 'https://example.test/marca/' . $term->slug . '/'
			);
			Functions\when( 'get_queried_object' )->justReturn( null );
			Functions\when( 'get_page_by_path' )->justReturn( null );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );

			// No debe haber labels regionales tipo "Editoriales argentinas" ni "Editoriales españolas"
			$labels = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => in_array( 'akb-brands-mega-label', (array) ( $item->classes ?? array() ), true )
				)
			);
			self::assertEmpty( $labels, 'En modo unificado no debe haber headers regionales dividiendo la lista.' );

			// Marcas inyectadas
			$brands = array_values(
				array_filter(
					$items,
					static fn( object $item ): bool => in_array( 'akb-brands-mega-brand', (array) ( $item->classes ?? array() ), true )
				)
			);

			self::assertCount( 4, $brands );
			self::assertSame( 'Ivrea Argentina', $brands[0]->title );
			self::assertSame( 'Ivrea España', $brands[1]->title );
			self::assertSame( 'Panini Argentina', $brands[2]->title );
			self::assertSame( 'Panini España', $brands[3]->title );

			// Ver todas al final
			$last = end( $items );
			self::assertStringContainsString( 'Ver todas', $last->title );
		}

		/**
		 * @testdox Por orden del dueño (2026-10-21), las landings paraguas 'Todas las ediciones' se omiten por defecto en el menú.
		 */
		public function test_landings_paraguas_se_omiten_por_defecto_en_el_menu(): void {
			$brand = new WP_Term( 11, 'ivrea-argentina', 'Ivrea Argentina' );

			Functions\when( 'apply_filters' )->alias(
				static fn( string $hook, $value, ...$args ) => $value
			);
			Functions\when( 'get_terms' )->justReturn( array( $brand ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/marca/ivrea-argentina/' );
			Functions\when( 'get_queried_object' )->justReturn( null );
			Functions\when( 'get_page_by_path' )->alias(
				static function ( string $slug ): ?WP_Post {
					$page              = new WP_Post();
					$page->ID          = 27449;
					$page->post_status = 'publish';
					return $page;
				}
			);
			Functions\when( 'get_permalink' )->justReturn( 'https://example.test/ivrea/' );

			$trigger                   = new WP_Post();
			$trigger->ID               = 10;
			$trigger->classes          = array( MenuInjector::TRIGGER_CLASS );
			$trigger->menu_item_parent = '0';

			$items    = ( new MenuInjector() )->inject_brands( array( $trigger ), (object) array() );
			$landings = array_filter(
				$items,
				static fn( object $item ): bool => in_array(
					'akb-brands-mega-landing',
					(array) ( $item->classes ?? array() ),
					true
				)
			);

			self::assertSame( array(), $landings, 'Por defecto, el bloque redundante "Todas las ediciones" no debe aparecer en el menú.' );
		}
	}
}
