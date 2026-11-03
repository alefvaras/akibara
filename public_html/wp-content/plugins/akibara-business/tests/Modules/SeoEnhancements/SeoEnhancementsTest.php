<?php
/**
 * Pruebas de SeoEnhancements.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeoEnhancements;

use Akibara\Business\Modules\SeoEnhancements\SeoEnhancements;
use Akibara\Business\Modules\SeriesCatalog\SeriesCatalogEligibility;
use Akibara\Business\Modules\SeriesDirectory\SeriesIndex;
use Akibara\Business\Modules\SeriesSchema\SeriesSchemaType;
use Akibara\Business\Services\SeriesHubUrl;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeoEnhancementsEligibilityWpdb {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $postmeta            = 'wp_postmeta';
	/** @var list<array<string,mixed>> */
	public array $rows = array();
	public bool $fail = false;

	public function prepare( string $query, ...$args ): string {
		unset( $args );
		return $query;
	}

	/** @return list<array<string,mixed>>|null */
	public function get_results( string $query, string $output = 'OBJECT' ) {
		unset( $query, $output );
		return $this->fail ? null : $this->rows;
	}
}

final class SeoEnhancementsTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_get = $_GET;
		$_GET               = array();

		// ── Defaults deterministas de TODOS los guards de contexto ──────────
		// Brain Monkey define el símbolo en el runtime de PHP la primera vez que
		// un test lo mockea, y esa definición SOBREVIVE al tearDown: lo que se
		// limpia es la expectativa, no la función. A partir de ahí, un test que
		// llame a `is_tax()` sin stubearla recibe el stub vacío (null), no el
		// comportamiento nativo. Verde en orden natural, rojo con
		// `--order-by=random` — misma disciplina que SeriesFactsTest.
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'get_queried_object' )->justReturn( null );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'term_description' )->justReturn( '' );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'strip_shortcodes' )->returnArg();
		Functions\when( 'remove_accents' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		// SeriesHubUrl (R2-8951cc1e): sin páginas que la contengan, cae al
		// fallback `/series/` — igual que el literal que reemplazó.
		Functions\when( 'get_posts' )->justReturn( array() );
		SeriesHubUrl::reset();

		// Implementación fiel de Core, con su segundo parámetro: un alias a
		// `strip_tags` a secas revienta con TypeError cuando el módulo pide
		// `wp_strip_all_tags( $texto, true )`.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $text, bool $remove_breaks = false ): string {
				$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
				$text = strip_tags( $text );

				return $remove_breaks
					? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) )
					: trim( $text );
			}
		);
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $GLOBALS['post'] );
		unset( $GLOBALS['wp_query'] );
		SeriesHubUrl::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should leave one WooCommerce breadcrumb when Rank Math breadcrumbs are disabled.
	 */
	public function test_keeps_woocommerce_breadcrumb_when_rank_math_breadcrumbs_are_disabled(): void {
		$hooks = $this->registered_filter_hooks( new SeoEnhancements( true, null, null, false ) );

		self::assertNotContains(
			'woocommerce_structured_data_breadcrumblist',
			$hooks,
			'WooCommerce must retain the sole BreadcrumbList when Rank Math breadcrumbs are disabled.'
		);
	}

	/**
	 * @testdox Should leave one Rank Math breadcrumb when its breadcrumbs are enabled.
	 */
	public function test_suppresses_woocommerce_breadcrumb_when_rank_math_breadcrumbs_are_enabled(): void {
		$hooks = $this->registered_filter_hooks( new SeoEnhancements( true, null, null, true ) );

		self::assertSame(
			1,
			count(
				array_filter(
					$hooks,
					static fn( string $hook ): bool => 'woocommerce_structured_data_breadcrumblist' === $hook
				)
			),
			'WooCommerce must be suppressed exactly once so Rank Math remains the sole BreadcrumbList owner.'
		);
	}

	/**
	 * @testdox Should preserve the WooCommerce breadcrumb while Rank Math is inactive.
	 */
	public function test_keeps_woocommerce_breadcrumb_when_rank_math_is_inactive(): void {
		$hooks = $this->registered_filter_hooks( new SeoEnhancements( false, null, null, true ) );

		self::assertNotContains(
			'woocommerce_structured_data_breadcrumblist',
			$hooks,
			'WooCommerce must retain its native breadcrumb when Rank Math is inactive.'
		);
	}

	/**
	 * @testdox Should register Rank Math product metadata and sitemap filters only while Rank Math is active.
	 */
	public function test_registers_rank_math_filters_only_with_rank_math(): void {
		$active_hooks   = $this->registered_filter_hooks( new SeoEnhancements( true ) );
		$inactive_hooks = $this->registered_filter_hooks( new SeoEnhancements( false ) );

		foreach (
			array(
				'rank_math/frontend/title',
				'rank_math/frontend/description',
				'rank_math/json_ld',
				'rank_math/sitemap/entry',
				'rank_math/sitemap/posts_to_exclude',
			) as $hook
		) {
			self::assertContains( $hook, $active_hooks, "{$hook} should be registered while Rank Math is active." );
			self::assertNotContains( $hook, $inactive_hooks, "{$hook} should not be registered without Rank Math." );
		}
	}

	/**
	 * @testdox Should always register the directory search robots and canonical policy.
	 */
	public function test_registers_the_series_directory_search_policy(): void {
		foreach ( array( new SeoEnhancements( true ), new SeoEnhancements( false ) ) as $sut ) {
			$hooks = $this->registered_filter_hooks( $sut );

			self::assertContains( 'wp_robots', $hooks );
			self::assertContains( 'rank_math/frontend/robots', $hooks );
			self::assertContains( 'rank_math/frontend/canonical', $hooks );
		}
	}

	/**
	 * @testdox Should use the UI-managed Rank Math product template instead of a long excerpt.
	 */
	public function test_product_without_custom_meta_uses_rank_math_ui_template(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 71 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 71,
				'post_type' => 'product',
				'post_title' => 'Tokyo Revengers 01',
			)
		);
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value ): string => strip_tags( $value )
		);

		$settings_keys = array();
		$sut           = new SeoEnhancements(
			true,
			static function ( string $key ) use ( &$settings_keys ): string {
				$settings_keys[] = $key;
				return 'Compra %title% en Akibara con envío nacional.';
			},
			static fn( string $template, object $post ): string => str_replace( '%title%', $post->post_title, $template )
		);

		self::assertSame(
			'Compra Tokyo Revengers 01 en Akibara con envío nacional.',
			$sut->prefer_product_description_template( str_repeat( 'Extracto comercial ', 30 ) )
		);
		self::assertSame( array( 'titles.pt_product_description' ), $settings_keys );
	}

	/**
	 * @testdox Should preserve an individual Rank Math description.
	 */
	public function test_product_custom_rank_math_description_keeps_current_output(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 71 );
		Functions\when( 'get_post_meta' )->justReturn( 'Meta SEO individual' );
		$settings_calls = 0;
		$sut            = new SeoEnhancements(
			true,
			static function () use ( &$settings_calls ): string {
				++$settings_calls;
				return 'Plantilla';
			},
			static fn(): string => 'Expandida'
		);

		self::assertSame(
			'Meta SEO individual ya expandida',
			$sut->prefer_product_description_template( 'Meta SEO individual ya expandida' )
		);
		self::assertSame( 0, $settings_calls );
	}

	/**
	 * @testdox Should preserve non-products, inactive Rank Math and an empty template.
	 */
	public function test_product_template_override_respects_all_runtime_gates(): void {
		$reader = static fn(): string => '';
		$expand = static fn(): string => 'No debe usarse';

		Functions\when( 'is_singular' )->justReturn( false );
		self::assertSame(
			'Original',
			( new SeoEnhancements( true, $reader, $expand ) )->prefer_product_description_template( 'Original' )
		);

		Functions\when( 'is_singular' )->justReturn( true );
		self::assertSame(
			'Original',
			( new SeoEnhancements( false, $reader, $expand ) )->prefer_product_description_template( 'Original' )
		);

		Functions\when( 'get_queried_object_id' )->justReturn( 71 );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 71,
				'post_type' => 'product',
			)
		);
		self::assertSame(
			'Original',
			( new SeoEnhancements( true, $reader, $expand ) )->prefer_product_description_template( 'Original' )
		);
	}

	/**
	 * @testdox Should sanitize an expanded template and cap only results over 160 characters.
	 */
	public function test_sanitizes_and_caps_only_long_expanded_product_templates(): void {
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( string $value ): string => strip_tags( $value )
		);
		$short = '<strong>Compra manga</strong>  original.';
		$long  = '<p>' . str_repeat( 'Tokyo Revengers disponible en Akibara con despacho nacional seguro. ', 5 ) . '</p>';

		self::assertSame(
			'Compra manga original.',
			SeoEnhancements::sanitize_product_meta_description( $short )
		);

		$sanitized = SeoEnhancements::sanitize_product_meta_description( $long );
		self::assertLessThan( 160, mb_strlen( $sanitized, 'UTF-8' ) );
		self::assertStringNotContainsString( '<', $sanitized );
		self::assertStringEndsWith( '…', $sanitized );
	}

	/**
	 * @testdox Should add one localized page marker to paginated metadata.
	 */
	public function test_adds_localized_page_marker_to_paginated_metadata(): void {
		$GLOBALS['wp_query'] = (object) array( 'max_num_pages' => 5 );
		Functions\when( 'get_query_var' )->alias(
			static fn( string $key ): int => 'paged' === $key ? 2 : 0
		);
		$sut = new SeoEnhancements( true );

		self::assertSame(
			'Catálogo – Página 2 de 5 - Akibara',
			$sut->add_pagination_to_title( 'Catálogo - Akibara' )
		);
		self::assertSame(
			'Compra manga y cómics en Akibara. Página 2 de 5.',
			$sut->add_pagination_to_description( 'Compra manga y cómics en Akibara.' )
		);

		// Desde que las landings de serie publican su descripción curada, el
		// copy base puede llegar recortado con puntos suspensivos. Sin limpiarlos
		// el resultado eran dos terminadores pegados —`…. Página 2 de 5.`—,
		// medido en /serie/one-piece/page/2/.
		self::assertSame(
			'Monkey D. Luffy, un chico de goma con un sueño. Página 2 de 5.',
			$sut->add_pagination_to_description( 'Monkey D. Luffy, un chico de goma con un sueño…' )
		);

		// Y el carácter multibyte anterior al recorte sobrevive intacto: `…` son
		// tres bytes y una lista de `rtrim` los habría soltado sueltos.
		self::assertSame(
			'La epopeya pirata de Eiichiro Oda es única en su versión española. Página 2 de 5.',
			$sut->add_pagination_to_description( 'La epopeya pirata de Eiichiro Oda es única en su versión española…' )
		);
	}

	/**
	 * @testdox Should preserve page one and metadata already expanded by Rank Math.
	 */
	public function test_preserves_page_one_and_already_expanded_metadata(): void {
		$GLOBALS['wp_query'] = (object) array( 'max_num_pages' => 5 );
		$sut = new SeoEnhancements( true );

		Functions\when( 'get_query_var' )->justReturn( 1 );
		self::assertSame(
			'Catálogo - Akibara',
			$sut->add_pagination_to_title( 'Catálogo - Akibara' )
		);

		Functions\when( 'get_query_var' )->justReturn( 2 );
		self::assertSame(
			'Catálogo – Página 2 de 5 - Akibara',
			$sut->add_pagination_to_title( 'Catálogo – Página 2 de 5 - Akibara' )
		);
	}

	/**
	 * @testdox Should add the WooCommerce shop page once to Rank Math sitemap exclusions.
	 */
	public function test_excludes_shop_page_once_from_rank_math_page_sitemap(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( 42 );
		$sut = new SeoEnhancements( true );

		self::assertSame(
			array( 10, 42 ),
			$sut->exclude_shop_page_from_rank_math_sitemap( array( 10 ) ),
			'The shop container should be excluded without removing existing exclusions.'
		);
		self::assertSame(
			array( 10, 42 ),
			$sut->exclude_shop_page_from_rank_math_sitemap( array( 10, 42 ) ),
			'The shop page exclusion should remain idempotent.'
		);
	}

	/**
	 * @testdox Should leave sitemap exclusions unchanged when WooCommerce has no shop page.
	 */
	public function test_keeps_sitemap_exclusions_when_shop_page_is_missing(): void {
		Functions\when( 'wc_get_page_id' )->justReturn( -1 );

		self::assertSame(
			array( 10 ),
			( new SeoEnhancements( true ) )->exclude_shop_page_from_rank_math_sitemap( array( 10 ) ),
			'An invalid shop page ID must not be added to the sitemap exclusions.'
		);
	}

	/**
	 * @testdox Should place the shop and Manga remainder below while keeping compact taxonomy intros above the loop.
	 */
	public function test_registers_description_callbacks_in_the_intended_positions(): void {
		$actions = array();
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$actions ): bool {
				$method    = is_array( $callback ) ? (string) $callback[1] : (string) $callback;
				$actions[] = array( $hook, $method, $priority );
				return true;
			}
		);

		( new SeoEnhancements( false ) )->register();

		self::assertContains(
			array( 'woocommerce_after_main_content', 'woocommerce_product_archive_description', 5 ),
			$actions
		);
		self::assertContains(
			array( 'woocommerce_after_main_content', 'handle_render_manga_archive_description_rest', 5 ),
			$actions
		);
		self::assertContains(
			array( 'woocommerce_before_shop_loop', 'handle_render_taxonomy_intro', 3 ),
			$actions
		);
		self::assertNotContains(
			array( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 5 ),
			$actions
		);
	}

	/**
	 * @testdox Should split Manga only on its clean canonical first page.
	 */
	public function test_limits_manga_split_to_clean_canonical_first_page(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias(
			static fn( $taxonomy ): bool => 'product_cat' === $taxonomy
		);
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 7,
				'taxonomy' => 'product_cat',
				'slug'     => 'manga',
				'name'     => 'Manga',
			)
		);
		$sut = new SeoEnhancements();

		self::assertTrue( $sut->is_clean_manga_archive() );

		$_GET = array( '_disponibilidad' => 'instock' );
		self::assertFalse( $sut->is_clean_manga_archive(), 'Filtered/query archives must not receive the split.' );
	}

	/**
	 * @testdox Should reject pagination and every product category other than the exact Manga slug.
	 */
	public function test_rejects_pagination_and_other_categories_from_manga_split(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 2 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 8,
				'taxonomy' => 'product_cat',
				'slug'     => 'manga-shonen',
				'name'     => 'Manga Shonen',
			)
		);

		self::assertFalse( ( new SeoEnhancements() )->is_clean_manga_archive() );
	}

	/**
	 * @testdox Should keep only the first safe paragraph above and the remaining safe content below.
	 */
	public function test_renders_manga_lead_and_rest_once_with_the_exact_allowlist(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias(
			static fn( $taxonomy ): bool => 'product_cat' === $taxonomy
		);
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 7,
				'taxonomy' => 'product_cat',
				'slug'     => 'manga',
				'name'     => 'Manga',
			)
		);
		Functions\when( 'term_description' )->justReturn(
			'<p>Primer párrafo <strong>editorial</strong>.</p>'
			. '<h2>Sin heading</h2><p>Segundo <a href="/manga/">párrafo</a>.</p>'
			. '<dl><dt>Sin definición</dt></dl><ul><li>Lista segura</li></ul><script>alert(1)</script>'
		);
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<p><a><strong><em><br><ul><ol><li>' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'esc_attr' )->returnArg();

		$sut = new SeoEnhancements();
		ob_start();
		$sut->handle_native_taxonomy_description();
		$lead = (string) ob_get_clean();
		ob_start();
		$sut->handle_render_manga_archive_description_rest();
		$rest = (string) ob_get_clean();

		self::assertStringContainsString( 'term-description akb-archive-desc__lead akb-archive-desc__lead--manga', $lead );
		self::assertStringContainsString( '<strong>editorial</strong>', $lead );
		self::assertStringNotContainsString( 'Segundo', $lead );
		self::assertStringContainsString( 'class="akb-archive-desc akb-archive-desc--manga"', $rest );
		self::assertStringContainsString( 'aria-label="Más información sobre Manga"', $rest );
		self::assertStringContainsString( 'Segundo', $rest );
		self::assertStringContainsString( '<ul><li>Lista segura</li></ul>', $rest );
		self::assertStringNotContainsString( '<h2', $lead . $rest );
		self::assertStringNotContainsString( '<dl', $lead . $rest );
		self::assertStringNotContainsString( '<script', $lead . $rest );
		self::assertStringNotContainsString( 'application/ld+json', $lead . $rest );
	}

	/**
	 * @testdox Should treat blank-line paragraphs from WooCommerce UI as the editable split boundary.
	 */
	public function test_splits_blank_line_paragraphs_from_woocommerce_ui(): void {
		Functions\when( 'wpautop' )->alias(
			static function ( string $text ): string {
				$paragraphs = preg_split( '/\n\s*\n/', trim( $text ) );
				return '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';
			}
		);
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

		$parts = SeoEnhancements::split_manga_archive_description(
			'Presentación editorial.' . "\n\n" . 'Desarrollo completo.' . "\n\n" . 'Cierre editorial.'
		);

		self::assertSame( '<p>Presentación editorial.</p>', $parts['lead'] );
		self::assertSame( '<p>Desarrollo completo.</p><p>Cierre editorial.</p>', $parts['rest'] );
	}

	/**
	 * @testdox Should suppress all Manga copy on paginated and query-string archives without falling back to Woo native output.
	 */
	public function test_does_not_render_custom_manga_copy_on_paginated_or_query_archives(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias(
			static fn( $taxonomy ): bool => 'product_cat' === $taxonomy
		);
		$paged = 1;
		Functions\when( 'get_query_var' )->alias(
			static function () use ( &$paged ): int {
				return $paged;
			}
		);
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 7,
				'taxonomy' => 'product_cat',
				'slug'     => 'manga',
				'name'     => 'Manga',
			)
		);
		$native_calls = 0;
		Functions\when( 'woocommerce_taxonomy_archive_description' )->alias(
			static function () use ( &$native_calls ): void {
				++$native_calls;
			}
		);

		$sut = new SeoEnhancements();
		$_GET = array( 'orderby' => 'price' );
		ob_start();
		$sut->handle_native_taxonomy_description();
		$sut->handle_render_manga_archive_description_rest();
		$query_html = (string) ob_get_clean();

		$_GET  = array();
		$paged = 2;
		ob_start();
		$sut->handle_native_taxonomy_description();
		$sut->handle_render_manga_archive_description_rest();
		$page_two_html = (string) ob_get_clean();

		self::assertSame( '', trim( $query_html ) );
		self::assertSame( '', trim( $page_two_html ) );
		self::assertSame( 0, $native_calls, 'Non-canonical Manga must never fall back to the full native description.' );
		self::assertSame( 0, substr_count( $query_html . $page_two_html, 'akb-archive-desc__lead--manga' ) );
		self::assertSame( 0, substr_count( $query_html . $page_two_html, 'akb-archive-desc--manga' ) );
	}

	/**
	 * @testdox Should expose the native genre or author description only on a clean first page.
	 */
	public function test_limits_taxonomy_intro_to_clean_genre_and_author_archives(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 4,
				'taxonomy' => 'pa_genero',
				'name'     => 'Aventura',
			)
		);
		$sut = new SeoEnhancements();

		self::assertTrue( $sut->is_clean_taxonomy_intro_archive() );

		$_GET = array( '_disponibilidad' => 'instock' );
		self::assertFalse( $sut->is_clean_taxonomy_intro_archive() );
	}

	/**
	 * @testdox Should reject series and paginated archives from the compact native intro.
	 */
	public function test_rejects_series_and_pagination_from_native_taxonomy_intro(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 2 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 8,
				'taxonomy' => 'pa_serie',
				'name'     => 'Serie',
			)
		);

		self::assertFalse( ( new SeoEnhancements() )->is_clean_taxonomy_intro_archive() );
	}

	/**
	 * @testdox Should render one safe mobile-expandable intro without creating a duplicate H1.
	 */
	public function test_renders_safe_taxonomy_intro_without_heading_schema_or_scripts(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'  => 4,
				'taxonomy' => 'pa_genero',
				'name'     => 'Aventura',
			)
		);
		Functions\when( 'term_description' )->justReturn( '<h1>Duplicado</h1><p>Historias <strong>épicas</strong>.</p><script>alert(1)</script>' );
		Functions\when( 'wp_kses' )->alias(
			static fn( string $html ): string => strip_tags( $html, '<p><a><strong><em><br><ul><ol><li>' )
		);
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'esc_attr' )->returnArg();

		ob_start();
		( new SeoEnhancements() )->handle_render_taxonomy_intro();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'class="akb-archive-intro akb-archive-intro--taxonomy"', $html );
		self::assertStringContainsString( 'data-akb-archive-intro', $html );
		self::assertStringContainsString( 'data-akb-archive-intro-toggle', $html );
		self::assertStringContainsString( '<strong>épicas</strong>', $html );
		self::assertStringNotContainsString( '<h1', $html );
		self::assertStringNotContainsString( '<script', $html );
		self::assertStringNotContainsString( 'application/ld+json', $html );
	}

	/**
	 * @testdox Should not expose removed unregistered SEO callbacks.
	 */
	public function test_does_not_expose_removed_residual_callbacks(): void {
		$sut = new SeoEnhancements();

		self::assertFalse( method_exists( $sut, 'add_price_to_offer' ), 'The unused offer callback should stay removed.' );
		self::assertFalse( method_exists( $sut, 'ensure_brand_schema' ), 'The unused brand callback should stay removed.' );
		self::assertFalse( method_exists( $sut, 'add_book_schema_to_product' ), 'Editorial schema should live only in BookSchemaEnricher.' );
		self::assertFalse( method_exists( $sut, 'dynamic_product_seo_title' ), 'Rank Math UI should own product title templates.' );
		self::assertFalse( method_exists( $sut, 'dynamic_product_seo_description' ), 'Rank Math UI should own product description templates.' );
	}

	/**
	 * @testdox Should recognize only XML and XSL sitemap resources for edge-cache bypass.
	 * @dataProvider sitemap_path_data
	 */
	public function test_recognizes_sitemap_resources_for_edge_cache_bypass( string $path, bool $expected ): void {
		self::assertSame( $expected, SeoEnhancements::is_sitemap_path( $path ) );
	}

	/**
	 * @return array<string,array{string,bool}>
	 */
	public static function sitemap_path_data(): array {
		return array(
			'Rank Math index'    => array( '/sitemap_index.xml', true ),
			'Product child'      => array( '/product-sitemap7.xml', true ),
			'Main stylesheet'    => array( '/main-sitemap.xsl', true ),
			'WordPress sitemap'  => array( '/wp-sitemap.xml', true ),
			'Editorial page'     => array( '/blog/como-crear-un-sitemap/', false ),
			'Regular XML asset'  => array( '/feed/product.xml', false ),
		);
	}

	/*
	 * ══ La meta description de las landings de serie ═══════════════════════
	 *
	 * Defecto medido en producción: las 106 landings indexables de `pa_serie`
	 * compartían UNA plantilla genérica, verificada a mano en the-climber,
	 * one-piece, prison-school,
	 * nier-automata y haikyuu. 93 de ellas tienen además una descripción
	 * editorial de 400-800 caracteres que no aparece en ninguna parte del SERP.
	 * En 90 días: 4.044 impresiones, 128 clics, CTR 3,2%.
	 */

	/**
	 * El callback nuevo va en la 20 —igual que el de producto, con el que es
	 * mutuamente excluyente por guard— y NUNCA después del marcador de
	 * paginación de la 99, que sufija el copy ya resuelto.
	 *
	 * @testdox Should register the series description callback before the pagination marker.
	 */
	public function test_registers_the_series_description_callback_before_the_pagination_marker(): void {
		$filters = $this->registered_filters( new SeoEnhancements( true ) );

		self::assertContains(
			array( 'rank_math/frontend/description', 'prefer_series_term_description', 20 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/frontend/description', 'add_pagination_to_description', 99 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/json_ld', 'align_series_breadcrumb_schema', 92 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/json_ld', 'align_product_breadcrumb_schema', 92 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/json_ld', 'add_series_hub_schema', 92 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/json_ld', 'add_shipping_page_schema', 92 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/json_ld', 'cleanup_global_schema', 999 ),
			$filters
		);
		self::assertContains(
			array( 'rank_math/sitemap/entry', 'handle_sitemap_entry', 20 ),
			$filters
		);
	}

	/**
	 * Desde 1.104.0 el TITLE de serie sí se compone acá cuando no existe
	 * `rank_math_title` propio: las queries reales («the climber manga»,
	 * «cuantos tomos tiene the climber») no contienen ninguna palabra de la
	 * plantilla «Completa tu colección». La paginación sigue sufijando al final.
	 *
	 * @testdox Should compose the series title before the pagination marker.
	 */
	public function test_composes_the_series_title_before_pagination(): void {
		$title_callbacks = array_values(
			array_filter(
				$this->registered_filters( new SeoEnhancements( true ) ),
				static fn( array $filter ): bool => 'rank_math/frontend/title' === $filter[0]
			)
		);

		self::assertSame(
			array(
				array( 'rank_math/frontend/title', 'add_pagination_to_title', 99 ),
				array( 'rank_math/frontend/title', 'prefer_series_term_title', 20 ),
				array( 'rank_math/frontend/title', 'prefer_product_title', 20 ),
			),
			$title_callbacks,
			'Composición de serie y de ficha en la 20 (guards mutuamente excluyentes); la paginación sufija en la 99.'
		);
	}

	/**
	 * @testdox Should preserve an explicitly authored Rank Math term title.
	 */
	public function test_preserves_an_explicit_rank_math_term_title(): void {
		$this->on_series_archive( 'The Climber', 'pa_serie', 'completa', '', 'Título SEO propio' );

		self::assertSame(
			'Título SEO propio ya expandido',
			( new SeoEnhancements( true ) )->prefer_series_term_title( 'Título SEO propio ya expandido' )
		);
	}

	/**
	 * Sin producto testigo no hay medio reconocible: el snapshot degrada y la
	 * plantilla original queda intacta.
	 *
	 * @testdox Should leave the series title untouched when the catalog snapshot is unavailable.
	 */
	public function test_leaves_the_series_title_untouched_without_snapshot(): void {
		$this->on_series_archive( 'The Climber', 'pa_serie', 'completa' );

		self::assertSame(
			'The Climber - Completa tu colección en Akibara Chile',
			( new SeoEnhancements( true ) )->prefer_series_term_title( 'The Climber - Completa tu colección en Akibara Chile' )
		);
	}

	/**
	 * @testdox Should compose a complete-series meta when the catalog covers the whole work.
	 */
	public function test_composes_complete_series_meta(): void {
		$meta = SeoEnhancements::compose_series_meta( 'The Climber', 'completa', 17, 17, 'Shinichi Sakamoto', 'Milky Way', 'manga', '', true );

		self::assertSame( 'The Climber manga completo: 17 tomos | Akibara Chile', $meta['title'] );
		self::assertSame(
			'The Climber de Shinichi Sakamoto: serie completa en 17 tomos de la edición Milky Way en español. Cómpralo en Akibara con despacho a todo Chile.',
			$meta['description']
		);
		self::assertStringContainsString( 'todo chile', mb_strtolower( $meta['description'], 'UTF-8' ) );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $meta['description'], 'UTF-8' ) );
	}

	/**
	 * «desde $X» entra SOLO si el caller ya pasó el gate de SeriesFacts (precio
	 * publicable): la composición no decide veracidad, solo formato y largo.
	 *
	 * @testdox Should add "desde $X" to the series description when the price is publishable.
	 */
	public function test_series_meta_publishes_price_from_when_publishable(): void {
		$con = SeoEnhancements::compose_series_meta( 'The Climber', 'completa', 17, 17, 'Shinichi Sakamoto', 'Milky Way', 'manga', '$9.990', true );
		$sin = SeoEnhancements::compose_series_meta( 'The Climber', 'completa', 17, 17, 'Shinichi Sakamoto', 'Milky Way', 'manga', '', true );

		self::assertStringContainsString( 'Desde $9.990', $con['description'] );
		self::assertStringContainsString( 'todo Chile', $con['description'] );
		self::assertLessThanOrEqual( 155, mb_strlen( $con['description'], 'UTF-8' ) );
		self::assertSame( $con['title'], $sin['title'], 'El precio no toca el título.' );
		self::assertStringNotContainsString( 'Desde', $sin['description'], 'Sin precio publicable la descripción es la de siempre.' );

		$largo = SeoEnhancements::compose_series_meta( 'Los diarios de la boticaria', 'en publicación', 0, 14, 'Natsu Hyuuga y Nekokurage', 'Panini Argentina', 'manga', '$12.990' );
		self::assertLessThanOrEqual( 155, mb_strlen( $largo['description'], 'UTF-8' ), 'Con precio o sin él, nunca una frase cortada.' );
		self::assertNotSame( '', $largo['description'] );
	}

	/**
	 * El bug que este candado cierra estaba VIVO en producción el 2026-09-02:
	 * «My Hero Academia manga completo: 42 tomos» con 7 tomos comprables,
	 * «Claymore manga completo: 27 tomos» con 8, «Tokyo Revengers» 31 con 14 y
	 * «Call of the Night» 20 con 8. El título salía de comparar el CATÁLOGO con la
	 * obra, sin mirar el stock: el cliente llegaba desde Google buscando una
	 * colección completa y encontraba el 80 % agotado.
	 *
	 * @testdox Con tomos agotados el título NO dice «completo», aunque el catálogo cubra la obra.
	 */
	public function test_a_sold_out_series_is_never_announced_as_complete(): void {
		$entera = SeoEnhancements::compose_series_meta( 'My Hero Academia', 'completa', 42, 42, 'Kohei Horikoshi', 'Panini Argentina', 'manga', '', true );
		self::assertStringContainsString( 'completo: 42 tomos', $entera['title'], 'Con todo comprable sí se puede afirmar.' );

		$agotada = SeoEnhancements::compose_series_meta( 'My Hero Academia', 'completa', 42, 42, 'Kohei Horikoshi', 'Panini Argentina', 'manga', '', false );
		self::assertStringNotContainsString( 'completo', mb_strtolower( $agotada['title'], 'UTF-8' ), 'Con tomos agotados no se promete la colección entera.' );
		self::assertStringContainsString( '42 tomos en español', $agotada['title'], 'Se pierde la promesa, no la información.' );
	}

	/**
	 * «La serie acaba en Japón y en la editorial que la trae» — el dueño,
	 * 2026-09-02. Son tres ejes distintos y el catálogo lo demuestra: 15 series
	 * tienen la obra CERRADA en Japón y la edición española AÚN SALIENDO (Bleach
	 * Remix: 74 tomos en Japón, la edición va por 26).
	 *
	 * El caso que se perdía mirando sólo la obra es Slam Dunk Deluxe: obra de 31
	 * tomos, EDICIÓN cerrada en 20, que es la que se vende. Nunca llegaba a 31, así
	 * que jamás podía decir que su edición está completa — información verdadera y
	 * útil que se estaba callando.
	 *
	 * @testdox Una edición cerrada puede anunciarse completa aunque la obra tenga más tomos.
	 */
	public function test_a_closed_edition_is_announced_even_when_the_work_is_longer(): void {
		// Slam Dunk Deluxe: obra 31, edición 20, catálogo 20, todo comprable.
		$meta = SeoEnhancements::compose_series_meta( 'Slam Dunk Deluxe', 'completa', 31, 20, 'Takehiko Inoue', 'Ivrea', 'manga', '', true, 'completa', 20 );
		self::assertStringContainsString( 'edición completa en 20 tomos', $meta['title'] );
		self::assertStringNotContainsString( '31', $meta['title'], 'El número de la obra no se mezcla con el de la edición.' );

		// Con tomos agotados la edición tampoco se promete completa.
		$agotada = SeoEnhancements::compose_series_meta( 'Slam Dunk Deluxe', 'completa', 31, 20, 'Takehiko Inoue', 'Ivrea', 'manga', '', false, 'completa', 20 );
		self::assertStringNotContainsString( 'completa', mb_strtolower( $agotada['title'], 'UTF-8' ) );
	}

	/**
	 * @testdox Una edición que sigue saliendo no se anuncia completa aunque la obra haya terminado en Japón.
	 */
	public function test_an_ongoing_edition_is_never_announced_as_complete(): void {
		// Bleach Remix: obra completa en Japón (74), edición en publicación (van 26).
		$meta = SeoEnhancements::compose_series_meta( 'Bleach Remix', 'completa', 74, 16, 'Tite Kubo', 'Panini', 'manga', '', true, 'en publicación', 26 );

		self::assertStringNotContainsString( 'completa', mb_strtolower( $meta['title'], 'UTF-8' ), 'La edición sigue saliendo: prometerla completa deja al cliente esperando tomos que aún no existen en español.' );
		self::assertStringNotContainsString( 'completo', mb_strtolower( $meta['title'], 'UTF-8' ) );
	}

	/**
	 * Naruto (5 fichas de una obra de 72) nunca fue el caso problemático, y el
	 * candado de stock no puede cambiarle el título: sigue cayendo en su rama de
	 * siempre.
	 *
	 * @testdox Una serie con pocas fichas de una obra larga conserva su título de siempre.
	 */
	public function test_a_thinly_covered_work_keeps_its_title(): void {
		$con = SeoEnhancements::compose_series_meta( 'Naruto', 'completa', 72, 5, 'Masashi Kishimoto', 'Panini Argentina', 'manga', '', true );
		$sin = SeoEnhancements::compose_series_meta( 'Naruto', 'completa', 72, 5, 'Masashi Kishimoto', 'Panini Argentina', 'manga', '', false );

		self::assertSame( $con['title'], $sin['title'], 'El stock no decide nada cuando el catálogo ni cubre la obra.' );
		self::assertStringNotContainsString( 'completo:', $con['title'] );
	}

	/** @testdox Should inflect a one-volume complete work in the singular. */
	public function test_inflects_a_single_volume_complete_work(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Look Back', 'completa', 1, 1, 'Tatsuki Fujimoto', 'Norma', 'manga', '', true );

		self::assertStringContainsString( 'completo: 1 tomo', $meta['title'] );
		self::assertStringContainsString( 'serie completa en 1 tomo', $meta['description'] );
		self::assertStringNotContainsString( '1 tomos', $meta['title'] . $meta['description'] );
	}

	/**
	 * Caso Naruto: la obra terminó en 72 tomos pero el catálogo tiene 5. Ni el
	 * título ni la descripción pueden fundir los dos ejes en una sola cifra.
	 *
	 * @testdox Should keep work total and catalog count as separate labeled axes.
	 */
	public function test_separates_work_total_from_catalog_count(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Naruto', 'completa', 72, 5, 'Masashi Kishimoto', 'Panini Argentina', 'manga' );

		self::assertSame( 'Naruto manga en español | Comprar en Akibara Chile', $meta['title'] );
		self::assertStringNotContainsString( '5 tomos |', $meta['title'] );
		self::assertStringContainsString( 'obra completa en 72 tomos', $meta['description'] );
		self::assertStringContainsString( '5 tomos catalogados', $meta['description'] );
		self::assertStringNotContainsString( 'disponible', $meta['description'] );
		self::assertStringNotContainsString( 'serie completa', $meta['description'] );
	}

	/** @testdox Should label a one-volume partial catalogue explicitly. */
	public function test_labels_a_single_volume_partial_catalogue(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Serie parcial', 'completa', 4, 1, 'Autora', 'Editorial', 'manga' );

		self::assertStringContainsString( 'obra completa en 4 tomos', $meta['description'] );
		self::assertStringContainsString( '1 tomo catalogado', $meta['description'] );
		self::assertStringNotContainsString( 'Tomos en el catálogo', $meta['description'] );
	}

	/**
	 * @testdox Should use the catalog count for an ongoing series.
	 */
	public function test_uses_catalog_count_for_an_ongoing_series(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Dan Da Dan', 'en_publicacion', 0, 22, 'Yukinobu Tatsu', 'Ivrea Argentina', 'manga' );

		self::assertSame( 'Dan Da Dan manga: 22 tomos en español | Akibara Chile', $meta['title'] );
		self::assertStringContainsString( '22 tomos catalogados', $meta['description'] );
		self::assertStringNotContainsString( 'disponible', $meta['description'] );
		self::assertStringContainsString( 'Ivrea Argentina', $meta['description'] );
	}

	/**
	 * @testdox Should never turn one catalogued volume into an availability claim.
	 */
	public function test_labels_a_single_catalogued_volume_neutrally(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Berserk Master Edition', 'en_publicacion', 0, 1, 'Kentaro Miura', 'Panini España', 'manga' );

		self::assertStringContainsString( '1 tomo catalogado', $meta['description'] );
		self::assertStringNotContainsString( 'disponible', $meta['description'] );
	}

	/**
	 * @testdox Should keep the zero-count fallback neutral when facts are incomplete.
	 */
	public function test_keeps_the_zero_catalog_count_neutral(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Serie de prueba', 'en_publicacion', 0, 0, '', '', 'manga' );

		self::assertStringContainsString( 'tomos en el catálogo', $meta['description'] );
		self::assertStringNotContainsString( 'disponible', $meta['description'] );
	}

	/**
	 * @testdox Should name comics as cómic, never manga.
	 */
	public function test_names_comics_by_their_real_medium(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Invencible', 'en_publicacion', 0, 3, '', 'Planeta Cómic', 'cómic' );

		self::assertStringContainsString( 'cómic', $meta['title'] );
		self::assertStringNotContainsString( 'manga', $meta['title'] );
	}

	/**
	 * @testdox Should surface a paused publication before the transactional close.
	 */
	public function test_surfaces_a_paused_publication(): void {
		$meta = SeoEnhancements::compose_series_meta( 'Vagabond', 'en_pausa', 0, 37, 'Takehiko Inoue', 'Ivrea Argentina', 'manga' );

		self::assertStringContainsString( 'Publicación en pausa.', $meta['description'] );
		self::assertStringContainsString( '37 tomos catalogados', $meta['description'] );
		self::assertStringNotContainsString( 'disponible', $meta['description'] );
	}

	/**
	 * @testdox Should return empty pieces instead of truncating an extreme name or faking a medium.
	 */
	public function test_returns_empty_pieces_for_untrustworthy_inputs(): void {
		$extreme = SeoEnhancements::compose_series_meta( str_repeat( 'Nombre de serie extraordinariamente largo ', 8 ), 'completa', 10, 10, '', '', 'manga' );
		self::assertSame( '', $extreme['title'] );
		self::assertSame( '', $extreme['description'] );

		$no_medium = SeoEnhancements::compose_series_meta( 'Berserk', 'en_publicacion', 0, 10, '', '', '' );
		self::assertSame( '', $no_medium['title'] );
		self::assertSame( '', $no_medium['description'] );
	}

	/**
	 * @testdox Should compose a rich product meta with author, brand, volume and series.
	 */
	public function test_composes_rich_product_meta(): void {
		$meta = SeoEnhancements::compose_product_meta(
			'Naruto 72',
			'Naruto',
			'Masashi Kishimoto',
			'Panini Argentina',
			'manga',
			72,
			''
		);

		// La editorial entra al title cuando cabe (61 ≤ 62): el comprador busca
		// «naruto 72 panini» y el snippet desambigua Panini/Ivrea/Norma.
		self::assertSame( 'Naruto 72 · Panini Argentina · manga en español | Akibara', $meta['title'] );
		self::assertSame(
			'Naruto 72 de Masashi Kishimoto, edición Panini Argentina en español. Tomo 72 de la serie Naruto. Cómpralo en Akibara con despacho a todo Chile.',
			$meta['description']
		);
		self::assertStringContainsString( 'todo chile', mb_strtolower( $meta['description'], 'UTF-8' ) );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $meta['description'], 'UTF-8' ) );
	}

	/**
	 * @testdox Should announce a preorder without any other volatile state.
	 */
	public function test_announces_preorder_in_the_product_meta(): void {
		$meta = SeoEnhancements::compose_product_meta(
			'Kagurabachi 08',
			'Kagurabachi',
			'Takeru Hokazono',
			'Ivrea Argentina',
			'manga',
			8,
			'preventa'
		);

		self::assertStringContainsString( 'Resérvalo en preventa', $meta['description'] );
		self::assertStringNotContainsString( 'stock', $meta['description'] );
		self::assertStringNotContainsString( '$', $meta['description'] );
	}

	/**
	 * @testdox Should announce a-pedido with its OWN vocabulary, never «preventa».
	 */
	public function test_a_pedido_never_says_preventa(): void {
		// La mentira que vivió un año: backorder titulaba «en preventa» a las
		// 422 fichas a pedido delante de Google (hallazgo 2026-08-31).
		$meta = SeoEnhancements::compose_product_meta(
			'Prison School 02',
			'Prison School',
			'Akira Hiramoto',
			'Ivrea España',
			'manga',
			2,
			'a_pedido'
		);

		self::assertStringContainsString( 'a pedido', mb_strtolower( $meta['title'] . ' ' . $meta['description'], 'UTF-8' ) );
		self::assertStringNotContainsString( 'preventa', mb_strtolower( $meta['title'] . ' ' . $meta['description'], 'UTF-8' ) );
		self::assertStringContainsString( 'Pídelo', $meta['description'] );
	}

	/**
	 * @testdox Should degrade the product meta gracefully when catalog data is partial.
	 */
	public function test_degrades_product_meta_with_partial_data(): void {
		$meta = SeoEnhancements::compose_product_meta( 'Uzumaki', '', '', '', 'manga', null, '' );

		self::assertSame( 'Uzumaki manga en español | Akibara Chile', $meta['title'] );
		self::assertSame(
			'Uzumaki manga en español. Cómpralo en Akibara con despacho a todo Chile.',
			$meta['description']
		);

		$no_medium = SeoEnhancements::compose_product_meta( 'Uzumaki', '', '', '', '', null, '' );
		self::assertSame( '', $no_medium['title'], 'Sin medio real no se adivina «manga».' );
	}

	/**
	 * @testdox Should keep product pieces within SERP limits for long real titles.
	 */
	public function test_keeps_product_pieces_within_limits(): void {
		$meta = SeoEnhancements::compose_product_meta(
			'El chico que me gusta no es un chico 02',
			'El chico que me gusta no es un chico',
			'Sumiko Arai',
			'Panini España',
			'manga',
			2,
			''
		);

		self::assertNotSame( '', $meta['title'] );
		self::assertNotSame( '', $meta['description'] );
		self::assertLessThanOrEqual( 62, mb_strlen( $meta['title'], 'UTF-8' ) );
		self::assertLessThanOrEqual( 155, mb_strlen( $meta['description'], 'UTF-8' ) );
	}

	/**
	 * @testdox Should remove the empty Place node and its dangling location reference.
	 */
	public function test_removes_the_empty_place_node(): void {
		$graph = array(
			'Place'        => array(
				'@type'   => 'Place',
				'@id'     => 'https://akibara.cl/#place',
				'address' => array( '@type' => 'PostalAddress' ),
			),
			'Organization' => array(
				'@type'    => 'Organization',
				'@id'      => 'https://akibara.cl/#organization',
				'name'     => 'Akibara',
				'location' => array( '@id' => 'https://akibara.cl/#place' ),
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		self::assertArrayNotHasKey( 'Place', $result );
		self::assertArrayNotHasKey( 'location', $result['Organization'] );
		self::assertSame( 'Akibara', $result['Organization']['name'] );
	}

	/**
	 * Si el dueño algún día carga la dirección, el guard la deja pasar intacta.
	 *
	 * @testdox Should drop an address that only carries its @type and keep one with real data.
	 */
	public function test_removes_the_empty_postal_address_from_the_organization_node(): void {
		$graph = array(
			'Organization' => array(
				'@type'   => array( 'OnlineStore', 'Organization' ),
				'@id'     => 'https://akibara.cl/#organization',
				'name'    => 'Akibara',
				'address' => array( '@type' => 'PostalAddress' ),
			),
			// Así llega en producción: Rank Math trae los campos del ajuste
			// «dirección local» presentes pero en blanco y los borra recién
			// en validate_schema(), después de este filtro.
			'Blank'        => array(
				'@type'   => array( 'OnlineStore', 'Organization' ),
				'@id'     => 'https://akibara.cl/#organization-blank',
				'address' => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => '',
					'addressLocality' => ' ',
					'addressRegion'   => '',
					'postalCode'      => '',
					'addressCountry'  => '',
				),
			),
			'Real'         => array(
				'@type'   => 'Organization',
				'@id'     => 'https://akibara.cl/#otra',
				'address' => array( '@type' => 'PostalAddress', 'addressCountry' => 'CL' ),
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		self::assertArrayNotHasKey( 'address', $result['Organization'], 'Una dirección sin datos no se emite.' );
		self::assertArrayNotHasKey( 'address', $result['Blank'], 'Una dirección con todos los campos en blanco tampoco se emite.' );
		self::assertSame( array( '@type' => 'PostalAddress', 'addressCountry' => 'CL' ), $result['Real']['address'], 'Una dirección con datos pasa intacta.' );
	}

	/**
	 * @testdox Should preserve a Place that carries real address data.
	 */
	public function test_preserves_a_place_with_real_data(): void {
		$graph = array(
			'Place' => array(
				'@type'   => 'Place',
				'@id'     => 'https://akibara.cl/#place',
				'address' => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => 'Santiago',
				),
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		self::assertSame( $graph, $result );
	}

	/**
	 * Los archives `/author/` están desactivados (404); `/autor/` (pa_autor) no.
	 *
	 * @testdox Should canonicalize the Akibara editor and every exact id reference without touching other people.
	 */
	public function test_canonicalizes_editor_and_exact_references_only(): void {
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );

		$graph = array(
			'ProfilePage' => array(
				'@type'    => 'Person',
				'@id'      => 'https://akibara.cl/author/ale-fvaras/',
				'name'     => 'Alejandro Fernández',
				'url'      => 'https://akibara.cl/author/ale-fvaras/',
				'worksFor' => array( '@id' => 'https://akibara.cl/#organization' ),
			),
			'richSnippet' => array(
				'@type'  => 'BlogPosting',
				'author' => array(
					'@id'  => 'https://akibara.cl/author/ale-fvaras/',
					'name' => 'Alejandro Fernández',
				),
			),
			'Mangaka'     => array(
				'@type' => 'Person',
				'@id'   => 'https://akibara.cl/autor/junji-ito/#person',
				'name'  => 'Junji Ito',
				'url'   => 'https://akibara.cl/autor/junji-ito/',
			),
			'GuestByline' => array(
				'@type'    => 'Person',
				'@id'      => 'https://akibara.cl/author/invitado/',
				'name'     => 'Autor invitado',
				'url'      => 'https://akibara.cl/author/invitado/',
				'worksFor' => array( '@id' => 'https://akibara.cl/#organization' ),
			),
			'UntypedUrl'  => array(
				'url' => 'https://akibara.cl/author/ale-fvaras/',
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		$person_id = 'https://akibara.cl/nosotros/#alejandro-fernandez';
		self::assertSame( $person_id, $result['ProfilePage']['@id'] );
		self::assertSame( 'https://akibara.cl/nosotros/', $result['ProfilePage']['url'] );
		self::assertSame( $person_id, $result['richSnippet']['author']['@id'] );
		self::assertSame( $graph['Mangaka'], $result['Mangaka'] );
		self::assertSame( $graph['GuestByline'], $result['GuestByline'] );
		self::assertSame( $graph['UntypedUrl'], $result['UntypedUrl'] );
	}

	/**
	 * Rank Math usa `<artículo>#author` cuando su opción de archives está apagada.
	 *
	 * @testdox Should canonicalize the per-article Rank Math author shape used in Docker.
	 */
	public function test_rewrites_the_per_article_rank_math_author_id(): void {
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'http://localhost:8080' . $path );

		$old_id = 'http://localhost:8080/guia/#author';
		$graph  = array(
			'ProfilePage' => array(
				'@type'    => array( 'Person', 'Thing' ),
				'@id'      => $old_id,
				'name'     => 'Alejandro Fernández',
				'worksFor' => array( '@id' => 'http://localhost:8080/#organization' ),
			),
			'richSnippet' => array(
				'@type'  => 'Article',
				'author' => array( '@id' => $old_id ),
			),
		);

		$result    = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );
		$person_id = 'http://localhost:8080/nosotros/#alejandro-fernandez';

		self::assertSame( $person_id, $result['ProfilePage']['@id'] );
		self::assertSame( 'http://localhost:8080/nosotros/', $result['ProfilePage']['url'] );
		self::assertSame( $person_id, $result['richSnippet']['author']['@id'] );
	}

	/**
	 * @testdox Should connect the AboutPage to the canonical editor and remain idempotent.
	 */
	public function test_connects_about_page_to_the_editor_idempotently(): void {
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );

		$graph = array(
			'ProfilePage' => array(
				'@type'    => 'Person',
				'@id'      => 'https://akibara.cl/nosotros/#author',
				'name'     => 'Alejandro Fernández',
				'worksFor' => array( '@id' => 'https://akibara.cl/#organization' ),
			),
			'WebPage'     => array(
				'@type' => 'AboutPage',
				'@id'   => 'https://akibara.cl/nosotros/#webpage',
				'url'   => 'https://akibara.cl/nosotros/',
			),
		);
		$sut = new SeoEnhancements( true );

		$result     = $sut->cleanup_global_schema( $graph );
		$idempotent = $sut->cleanup_global_schema( $result );

		self::assertSame(
			array( '@id' => 'https://akibara.cl/nosotros/#alejandro-fernandez' ),
			$result['WebPage']['mainEntity']
		);
		self::assertSame( $result, $idempotent );
	}

	/**
	 * @testdox Should not overwrite an unrelated AboutPage main entity.
	 */
	public function test_preserves_an_existing_unrelated_about_page_main_entity(): void {
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );

		$graph = array(
			'ProfilePage' => array(
				'@type'    => 'Person',
				'@id'      => 'https://akibara.cl/nosotros/#author',
				'name'     => 'Alejandro Fernández',
				'worksFor' => array( '@id' => 'https://akibara.cl/#organization' ),
			),
			'WebPage'     => array(
				'@type'      => 'AboutPage',
				'url'        => 'https://akibara.cl/nosotros/',
				'mainEntity' => array( '@id' => 'https://akibara.cl/#team' ),
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		self::assertSame( $graph['WebPage']['mainEntity'], $result['WebPage']['mainEntity'] );
	}

	/**
	 * Desde el 2026-09-01 el PostalAddress meta-only de Organization se retira
	 * (misma higiene que el Place vacío); el resto del nodo y la
	 * canonicalización del editor no cambian.
	 *
	 * @testdox Should drop the meta-only organization PostalAddress while canonicalizing the editor.
	 */
	public function test_drops_the_empty_organization_postal_address_during_person_cleanup(): void {
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );

		$graph = array(
			'Organization' => array(
				'@type'   => 'Organization',
				'@id'     => 'https://akibara.cl/#organization',
				'address' => array( '@type' => 'PostalAddress' ),
			),
			'ProfilePage' => array(
				'@type'    => 'Person',
				'@id'      => 'https://akibara.cl/author/ale-fvaras/',
				'name'     => 'Alejandro Fernández',
				'worksFor' => array( '@id' => 'https://akibara.cl/#organization' ),
			),
		);

		$result = ( new SeoEnhancements( true ) )->cleanup_global_schema( $graph );

		$expected = $graph['Organization'];
		unset( $expected['address'] );
		self::assertSame( $expected, $result['Organization'] );
	}

	/**
	 * @testdox Should keep every composed piece within SERP limits without ellipsis.
	 */
	public function test_keeps_composed_pieces_within_serp_limits(): void {
		$cases = array(
			array( 'El chico que me gusta no es un chico', 'en_publicacion', 0, 3, 'Sumiko Arai', 'Panini España', 'manga' ),
			array( 'Biblioteca Vertigo. John Constantine: Hellblazer', 'en_publicacion', 0, 24, '', 'Planeta Cómic', 'cómic' ),
			array( 'One Piece', 'en_publicacion', 0, 107, 'Eiichiro Oda', 'Ivrea Argentina', 'manga' ),
		);

		foreach ( $cases as $case ) {
			$meta = SeoEnhancements::compose_series_meta( ...$case );
			self::assertNotSame( '', $meta['title'], $case[0] );
			self::assertNotSame( '', $meta['description'], $case[0] );
			self::assertLessThanOrEqual( 62, mb_strlen( $meta['title'], 'UTF-8' ), $case[0] );
			self::assertLessThanOrEqual( 155, mb_strlen( $meta['description'], 'UTF-8' ), $case[0] );
			self::assertStringNotContainsString( '…', $meta['title'] . $meta['description'], $case[0] );
		}
	}

	/**
	 * @testdox Should distinguish a completed work from Akibara's current catalogue.
	 */
	public function test_describes_the_work_status_without_claiming_catalogue_completeness(): void {
		$this->on_series_archive( 'The Walking Dead', 'pa_serie', 'completa' );

		$result = ( new SeoEnhancements( true ) )->prefer_series_term_description( 'Plantilla compartida' );

		self::assertSame(
			'The Walking Dead en Akibara Chile. La obra original está finalizada. Revisa el catálogo, los tomos a pedido y el envío según destino.',
			$result
		);
		self::assertStringNotContainsString( 'catálogo completo', mb_strtolower( $result, 'UTF-8' ) );
		self::assertStringNotContainsString( 'stock', mb_strtolower( $result, 'UTF-8' ) );
		self::assertStringNotContainsString( 'todo chile', mb_strtolower( $result, 'UTF-8' ) );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $result, 'UTF-8' ) );
	}

	/**
	 * @testdox Should provide a stable Chilean transactional description without publication data.
	 */
	public function test_generates_a_stable_fallback_without_publication_data(): void {
		$this->on_series_archive( 'Berserk' );

		self::assertSame(
			'Berserk en Akibara Chile. Conoce la serie. Revisa el catálogo, los tomos a pedido y el envío según destino.',
			( new SeoEnhancements( true ) )->prefer_series_term_description( 'Plantilla compartida' )
		);
	}

	/**
	 * @testdox Should render every work state with explicit work scope.
	 * @dataProvider work_state_description_data
	 */
	public function test_renders_every_work_state_with_explicit_scope( string $state, string $expected_clause ): void {
		$result = SeoEnhancements::series_meta_description( 'One Piece', $state );

		self::assertStringContainsString( $expected_clause, $result );
		self::assertStringContainsString( 'Revisa el catálogo', $result );
		self::assertLessThanOrEqual( 160, mb_strlen( $result, 'UTF-8' ) );
		self::assertStringNotContainsString( '…', $result );
	}

	/** @return array<string,array{string,string}> */
	public static function work_state_description_data(): array {
		return array(
			'Completed' => array( 'completa', 'La obra original está finalizada.' ),
			'Ongoing'   => array( 'en_publicacion', 'La obra original sigue en publicación.' ),
			'Paused'    => array( 'en_pausa', 'La obra original está en pausa.' ),
			'Cancelled' => array( 'cancelada', 'La obra original fue cancelada.' ),
		);
	}

	/**
	 * @testdox Should fit the longest current series name without truncation or ellipsis.
	 */
	public function test_fits_the_longest_current_series_name_without_truncation(): void {
		$name   = 'Biblioteca Vertigo. John Constantine: Hellblazer';
		$result = SeoEnhancements::series_meta_description( $name, 'en_publicacion' );

		self::assertStringStartsWith( $name . ' en Akibara Chile.', $result );
		self::assertStringContainsString( 'La obra original sigue en publicación.', $result );
		self::assertStringNotContainsString( '…', $result );
		self::assertLessThanOrEqual( 160, mb_strlen( $result, 'UTF-8' ) );
	}

	/**
	 * @testdox Should use a complete generic fallback instead of truncating an extreme future name.
	 */
	public function test_uses_a_complete_fallback_for_an_extreme_future_name(): void {
		$result = SeoEnhancements::series_meta_description( str_repeat( 'Nombre de serie extraordinariamente largo ', 8 ), 'completa' );

		self::assertSame(
			'Series de manga y cómics en Akibara Chile. Revisa el catálogo, los tomos a pedido y el envío según destino.',
			$result
		);
		self::assertStringNotContainsString( '…', $result );
	}

	/**
	 * @testdox Should preserve an explicitly authored Rank Math term description.
	 */
	public function test_preserves_an_explicit_rank_math_term_description(): void {
		$this->on_series_archive( 'One Piece', 'pa_serie', 'en_publicacion', 'Meta SEO individual' );

		self::assertSame(
			'Meta SEO individual ya expandida',
			( new SeoEnhancements( true ) )->prefer_series_term_description( 'Meta SEO individual ya expandida' )
		);
	}

	/**
	 * @testdox Should trust generated ownership only while its exact snapshot hash still matches.
	 */
	public function test_requires_a_matching_hash_for_generated_series_meta_ownership(): void {
		$description = 'Foto generada persistida';
		$title       = 'Título generado persistido';
		$this->on_series_archive(
			'One Piece',
			'pa_serie',
			'en_publicacion',
			$description,
			$title,
			SeoEnhancements::SERIES_META_GENERATED,
			hash( 'sha256', $description ),
			SeoEnhancements::SERIES_META_GENERATED,
			hash( 'sha256', $title )
		);

		$sut    = new SeoEnhancements( true );
		$method = new \ReflectionMethod( $sut, 'is_generated_series_meta_field' );

		self::assertTrue( $method->invoke( $sut, 900, 'description' ) );
		self::assertTrue( $method->invoke( $sut, 900, 'title' ) );
		self::assertFalse( $method->invoke( $sut, 900, 'unsupported' ) );
	}

	/**
	 * TRASPASO DE LA CÉLULA DE BASE (medido en prod el 2026-08-31).
	 *
	 * Las metas de serie se escribieron por primera vez el 2026-08-15 con un
	 * script de una sola pasada, ANTES de que existieran las metas de
	 * propiedad. Esas filas quedaron con valor y sin marca, y el clasificador
	 * no tenía más remedio que leerlas como copy editorial y congelarlas: 82 de
	 * 106 descripciones de `pa_serie` marcadas «manual», 72 de ellas cerrando
	 * con la plantilla retirada de abajo. Congeladas, esas landings —la palanca
	 * SEO de la tienda— dejaron de seguir al catálogo.
	 *
	 * @testdox Should recognise a retired generator template as a snapshot, not editorial copy.
	 */
	public function test_recognises_a_retired_generator_template_as_generated(): void {
		$this->on_series_archive(
			'One Piece',
			'pa_serie',
			'en_publicacion',
			'One Piece: 106 tomos catalogados. Revisa precio y disponibilidad en Akibara.',
			'One Piece manga | Akibara Chile'
		);

		$sut    = new SeoEnhancements( true );
		$method = new \ReflectionMethod( $sut, 'is_generated_series_meta_field' );

		self::assertTrue(
			$method->invoke( $sut, 900, 'description' ),
			'Sin esto, 72 landings siguen sirviendo el conteo de tomos del día en que se escribieron.'
		);
		// El título de esa cosecha NO lleva el cierre retirado: sigue siendo
		// editorial mientras no lo acredite su marca.
		self::assertFalse( $method->invoke( $sut, 900, 'title' ) );
	}

	/**
	 * El criterio es el cierre EXACTO de una plantilla retirada, no un parecido.
	 * Confundir copy del dueño con una foto generada lo descartaría en silencio,
	 * que es un daño peor que el congelamiento que esto viene a deshacer.
	 *
	 * @dataProvider descripciones_editoriales
	 *
	 * @testdox Should leave genuinely hand-written descriptions frozen.
	 */
	public function test_leaves_hand_written_descriptions_frozen( string $descripcion, string $porque ): void {
		$this->on_series_archive( 'One Piece', 'pa_serie', 'en_publicacion', $descripcion, '' );

		$sut    = new SeoEnhancements( true );
		$method = new \ReflectionMethod( $sut, 'is_generated_series_meta_field' );

		self::assertFalse( $method->invoke( $sut, 900, 'description' ), $porque );
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function descripciones_editoriales(): array {
		return array(
			'copy propio del dueño'  => array(
				'La obra que definió el shonen moderno. Edición Ivrea Argentina, tapa blanda.',
				'Nada la relaciona con una plantilla del generador.',
			),
			'menciona la marca'      => array(
				'Revisa precio y disponibilidad en Akibara y en nuestro Instagram.',
				'El cierre retirado tiene que ser el FINAL del texto, no aparecer en medio.',
			),
			'cierre parecido'        => array(
				'One Piece: revisa precio y stock en Akibara.',
				'Parecido no es igual: la coincidencia es exacta o no es.',
			),
			'vacía'                  => array( '', 'Un campo vacío no está congelado; ya es automático.' ),
		);
	}

	/**
	 * @testdox Should fail safe to editorial ownership after a Rank Math value is edited.
	 */
	public function test_rejects_a_stale_generated_snapshot_hash(): void {
		$this->on_series_archive(
			'One Piece',
			'pa_serie',
			'en_publicacion',
			'Descripción editada a mano',
			'Título editado a mano',
			SeoEnhancements::SERIES_META_GENERATED,
			hash( 'sha256', 'Descripción anterior' ),
			SeoEnhancements::SERIES_META_GENERATED,
			'no-es-un-sha256'
		);

		$sut    = new SeoEnhancements( true );
		$method = new \ReflectionMethod( $sut, 'is_generated_series_meta_field' );

		self::assertFalse( $method->invoke( $sut, 900, 'description' ) );
		self::assertFalse( $method->invoke( $sut, 900, 'title' ) );
	}

	/**
	 * @testdox Should strip shortcodes, tags, entities and hard spaces before publishing the snippet.
	 */
	public function test_cleans_shortcodes_tags_entities_and_hard_spaces(): void {
		Functions\when( 'strip_shortcodes' )->alias(
			static fn( string $text ): string => (string) preg_replace( '/\[[^\]]+\]/', '', $text )
		);

		$result = SeoEnhancements::series_meta_description(
			"<strong>Dragon\xc2\xa0Ball</strong> [gallery ids=\"1,2\"] &amp; Dr. Slump",
			'en_publicacion'
		);

		self::assertSame(
			'Dragon Ball & Dr. Slump en Akibara Chile. La obra original sigue en publicación. Revisa el catálogo, los tomos a pedido y el envío según destino.',
			$result,
			'La meta se escapa al imprimirse: sin decodificar acá, `&amp;` llega al SERP como `&amp;amp;`.'
		);
	}

	/**
	 * El guard es `is_tax('pa_serie')` y no «cualquier taxonomía»: las
	 * categorías, `/marca/` y el blog conservan su plantilla, que en su caso no
	 * está midiendo el defecto que se corrige acá.
	 *
	 * @testdox Should not touch any surface other than a pa_serie archive.
	 */
	public function test_does_not_touch_any_other_surface(): void {
		$template = 'Compra manga y cómics de Ivrea en Akibara. Catálogo completo con envío nacional.';
		$sut      = new SeoEnhancements( true );

		// Otra taxonomía de producto.
		$this->on_series_archive( 'Descripción editorial larguísima que jamás debe salir acá porque este no es un archive de serie.', 'product_brand' );
		self::assertSame( $template, $sut->prefer_series_term_description( $template ) );

		// `is_tax()` false (ficha de producto, home, blog).
		Functions\when( 'is_tax' )->justReturn( false );
		self::assertSame( $template, $sut->prefer_series_term_description( $template ) );

		// Ruteo raro: `is_tax('pa_serie')` cierto sin objeto consultado.
		Functions\when( 'is_tax' )->justReturn( true );
		Functions\when( 'get_queried_object' )->justReturn( null );
		self::assertSame( $template, $sut->prefer_series_term_description( $template ) );

		// Y en el admin no se filtra nada.
		$this->on_series_archive( 'Descripción editorial larguísima que jamás debe salir en el admin.' );
		Functions\when( 'is_admin' )->justReturn( true );
		self::assertSame( $template, $sut->prefer_series_term_description( $template ) );
	}

	/**
	 * @testdox Should keep paginated series descriptions complete and below 160 characters.
	 */
	public function test_compacts_paginated_series_description_at_sentence_boundaries(): void {
		$this->on_series_archive( 'Biblioteca Vertigo. John Constantine: Hellblazer', 'pa_serie', 'en_publicacion' );
		$GLOBALS['wp_query'] = (object) array( 'max_num_pages' => 5 );
		Functions\when( 'get_query_var' )->alias(
			static fn( string $key ): int => 'paged' === $key ? 2 : 0
		);
		$sut  = new SeoEnhancements( true );
		$base = $sut->prefer_series_term_description( 'Plantilla compartida' );

		$result = $sut->add_pagination_to_description( $base );

		self::assertSame(
			// La base es la forma corta (la larga con el estado no cabe en 160);
			// la paginación quita la frase del estado para sumar la página.
			'Biblioteca Vertigo. John Constantine: Hellblazer en Akibara Chile. Revisa catálogo, tomos a pedido y envío según destino. Página 2 de 5.',
			$result
		);
		self::assertLessThanOrEqual( 160, mb_strlen( $result, 'UTF-8' ) );
		self::assertStringNotContainsString( '…', $result );
	}

	/**
	 * R8-miga-sin-demografia (2026-09-20): en las 1.643 fichas el BreadcrumbList
	 * de Rank Math traía un nivel menos que la miga visible (faltaba la
	 * demografía Shonen/Seinen). Se reconstruyen los niveles intermedios desde
	 * `WC_Breadcrumb`, la misma fuente que pinta la miga.
	 *
	 * @testdox Should give product BreadcrumbList every level the visible breadcrumb shows.
	 */
	public function test_aligns_product_breadcrumb_schema_with_the_visible_hierarchy(): void {
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		\WC_Breadcrumb::$crumbs = array(
			array( 'Manga', 'https://akibara.cl/manga/' ),
			array( 'Shonen', 'https://akibara.cl/shonen/' ),
			array( 'Dan Da Dan 20', 'https://akibara.cl/dan-da-dan-20-ivrea-argentina/' ),
		);
		$graph = array(
			'BreadcrumbList' => array(
				'@type'           => 'BreadcrumbList',
				'@id'             => 'https://akibara.cl/dan-da-dan-20-ivrea-argentina/#breadcrumb',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'item' => array( '@id' => 'https://akibara.cl/', 'name' => 'Inicio' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'item' => array( '@id' => 'https://akibara.cl/manga/', 'name' => 'Manga' ) ),
					array( '@type' => 'ListItem', 'position' => 3, 'item' => array( '@id' => 'https://akibara.cl/dan-da-dan-20-ivrea-argentina/', 'name' => 'Dan Da Dan 20' ) ),
				),
			),
			'WebPage'        => array( '@type' => 'ItemPage' ),
		);

		$sut    = new SeoEnhancements( true );
		$result = $sut->align_product_breadcrumb_schema( $graph );
		$items  = $result['BreadcrumbList']['itemListElement'];

		self::assertSame( array( 'Inicio', 'Manga', 'Shonen', 'Dan Da Dan 20' ), array_column( array_column( $items, 'item' ), 'name' ), 'Inicio y la ficha se conservan tal cual los emite Rank Math; el medio viene de WooCommerce.' );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $items, 'position' ) );
		self::assertSame( 'https://akibara.cl/shonen/', $items[2]['item']['@id'] );
		self::assertSame( 'https://akibara.cl/dan-da-dan-20-ivrea-argentina/#breadcrumb', $result['BreadcrumbList']['@id'] );
		self::assertSame( $graph['WebPage'], $result['WebPage'] );
		self::assertSame( $result, $sut->align_product_breadcrumb_schema( $result ), 'Idempotente.' );
	}

	/**
	 * @testdox Should leave the product BreadcrumbList alone outside product pages or without intermediate crumbs.
	 */
	public function test_product_breadcrumb_alignment_respects_its_runtime_guards(): void {
		$graph = array(
			'BreadcrumbList' => array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'item' => array( '@id' => 'https://akibara.cl/', 'name' => 'Inicio' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'item' => array( '@id' => 'https://akibara.cl/x/', 'name' => 'X' ) ),
				),
			),
		);
		\WC_Breadcrumb::$crumbs = array( array( 'Manga', 'https://akibara.cl/manga/' ), array( 'X', 'https://akibara.cl/x/' ) );

		self::assertSame( $graph, ( new SeoEnhancements( true ) )->align_product_breadcrumb_schema( $graph ), 'Fuera de una ficha no toca nada.' );

		// La miga se memoiza por petición (una instancia = una petición): cada
		// escenario estrena instancia.
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		\WC_Breadcrumb::$crumbs = array( array( 'X', 'https://akibara.cl/x/' ) );
		self::assertSame( $graph, ( new SeoEnhancements( true ) )->align_product_breadcrumb_schema( $graph ), 'Sin niveles intermedios no hay nada que alinear.' );

		\WC_Breadcrumb::$crumbs = array( array( '', 'https://akibara.cl/manga/' ), array( 'X', 'https://akibara.cl/x/' ) );
		self::assertSame( $graph, ( new SeoEnhancements( true ) )->align_product_breadcrumb_schema( $graph ), 'Una miga sin nombre no se sube al grafo.' );
	}

	/**
	 * Revisión R8 (H6): `WC_Breadcrumb::generate()` puede traer un `WP_Error`
	 * de `get_term_link()` como enlace y, con `?paged=2`, un peldaño «Página 2»
	 * sin enlace DESPUÉS de la ficha. Ni fatal en wp_head ni ficha duplicada.
	 *
	 * @testdox Should skip WP_Error links and unlinked trailing crumbs without duplicating the product.
	 */
	public function test_product_breadcrumb_alignment_survives_hostile_crumbs(): void {
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		\WC_Breadcrumb::$crumbs = array(
			array( 'Manga', 'https://akibara.cl/manga/' ),
			array( 'Roto', new \WP_Error( 'invalid_term', 'Empty Term.' ) ),
			array( 'Seinen', 'https://akibara.cl/seinen/' ),
			array( 'Vagabond 17', 'https://akibara.cl/vagabond-17-ivrea-argentina/' ),
			array( 'Página 2', '' ),
		);
		$graph = array(
			'BreadcrumbList' => array(
				'@type'           => array( 'BreadcrumbList' ),
				'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'item' => array( '@id' => 'https://akibara.cl/', 'name' => 'Inicio' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'item' => array( '@id' => 'https://akibara.cl/manga/', 'name' => 'Manga' ) ),
					array( '@type' => 'ListItem', 'position' => 3, 'item' => array( '@id' => 'https://akibara.cl/vagabond-17-ivrea-argentina/', 'name' => 'Vagabond 17' ) ),
				),
			),
		);

		$items = ( new SeoEnhancements( true ) )->align_product_breadcrumb_schema( $graph )['BreadcrumbList']['itemListElement'];

		self::assertSame( array( 'Inicio', 'Manga', 'Seinen', 'Vagabond 17' ), array_column( array_column( $items, 'item' ), 'name' ) );
		self::assertSame( array( 1, 2, 3, 4 ), array_column( $items, 'position' ) );
	}

	/**
	 * @testdox Should align Rank Math schema with the visible Inicio, Series, term breadcrumb.
	 */
	public function test_aligns_series_breadcrumb_schema_with_the_visible_hierarchy(): void {
		$this->on_series_archive( 'One Piece' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/series/' );
		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $url ): string => rtrim( $url, '/' ) . '/'
		);
		$graph = array(
			'BreadcrumbList' => array(
				'@type'           => 'BreadcrumbList',
				'@id'             => 'https://akibara.cl/serie/one-piece/#breadcrumb',
				'itemListElement' => array(
					array(
						'@type'    => 'ListItem',
						'position' => 1,
						'item'     => array( '@id' => 'https://akibara.cl/', 'name' => 'Inicio' ),
					),
					array(
						'@type'    => 'ListItem',
						'position' => 2,
						'item'     => array( '@id' => 'https://akibara.cl/serie/one-piece/', 'name' => 'One Piece' ),
					),
				),
			),
			'ComicSeries' => array( '@type' => 'ComicSeries' ),
		);

		$result = ( new SeoEnhancements( true ) )->align_series_breadcrumb_schema( $graph );
		$items  = $result['BreadcrumbList']['itemListElement'];

		self::assertSame( array( 'Inicio', 'Series', 'One Piece' ), array_column( array_column( $items, 'item' ), 'name' ) );
		self::assertSame( array( 1, 2, 3 ), array_column( $items, 'position' ) );
		self::assertSame( 'https://akibara.cl/series/', $items[1]['item']['@id'] );
		self::assertSame( $graph['BreadcrumbList']['@id'], $result['BreadcrumbList']['@id'] );
		self::assertSame( $graph['ComicSeries'], $result['ComicSeries'], 'Only the breadcrumb node may change.' );

		$idempotent = ( new SeoEnhancements( true ) )->align_series_breadcrumb_schema( $result );
		self::assertSame( $result, $idempotent, 'The hub must never be inserted twice.' );
	}

	/**
	 * R2-8951cc1e: si el dueño mueve la página del directorio a otro slug, el
	 * `@id` del hub en el BreadcrumbList tiene que seguirla — no quedarse en
	 * `/series/`, que ahora sería un 404 indexado por Google.
	 *
	 * @testdox Should point the hub node at the real page when its slug moved.
	 */
	public function test_breadcrumb_schema_follows_the_hub_when_its_slug_moved(): void {
		$this->on_series_archive( 'One Piece' );
		Functions\when( 'get_posts' )->justReturn( array( 501 ) );
		Functions\when( 'get_post_field' )->justReturn( '[akibara_series_directory]' );
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/todas-las-series/' );
		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $url ): string => rtrim( $url, '/' ) . '/'
		);
		$graph = array(
			'BreadcrumbList' => array(
				'@type'           => 'BreadcrumbList',
				'@id'             => 'https://akibara.cl/serie/one-piece/#breadcrumb',
				'itemListElement' => array(
					array(
						'@type'    => 'ListItem',
						'position' => 1,
						'item'     => array( '@id' => 'https://akibara.cl/', 'name' => 'Inicio' ),
					),
					array(
						'@type'    => 'ListItem',
						'position' => 2,
						'item'     => array( '@id' => 'https://akibara.cl/serie/one-piece/', 'name' => 'One Piece' ),
					),
				),
			),
		);

		$result = ( new SeoEnhancements( true ) )->align_series_breadcrumb_schema( $graph );

		self::assertSame( 'https://akibara.cl/todas-las-series/', $result['BreadcrumbList']['itemListElement'][1]['item']['@id'] );
	}

	/**
	 * @testdox Should leave JSON-LD unchanged outside a series archive or without BreadcrumbList.
	 */
	public function test_breadcrumb_alignment_respects_its_runtime_guards(): void {
		$graph = array( 'WebPage' => array( '@type' => 'CollectionPage' ) );
		$sut   = new SeoEnhancements( true );

		self::assertSame( $graph, $sut->align_series_breadcrumb_schema( $graph ) );

		$this->on_series_archive( 'One Piece' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl/series/' );
		self::assertSame( $graph, $sut->align_series_breadcrumb_schema( $graph ) );
	}

	/**
	 * @testdox Should add one server-rendered CollectionPage and ItemList from the directory index.
	 */
	public function test_adds_series_hub_collection_and_item_list_from_the_directory_index(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'           => 44,
				'post_content' => '[akibara_series_directory]',
			)
		);
		Functions\when( 'has_shortcode' )->alias(
			static fn( string $content, string $tag ): bool => false !== strpos( $content, '[' . $tag . ']' )
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/series/' );
		Functions\when( 'get_the_title' )->justReturn( 'Series' );
		Functions\when( 'user_trailingslashit' )->alias(
			static fn( string $url ): string => rtrim( $url, '/' ) . '/'
		);

		$one_piece           = new \WP_Term( 10, 'one-piece', 'One Piece' );
		$one_piece->taxonomy = 'pa_serie';
		$one_piece->count    = 12;
		$walking_dead           = new \WP_Term( 11, 'the-walking-dead', 'The Walking Dead' );
		$walking_dead->taxonomy = 'pa_serie';
		$walking_dead->count    = 7;
		$hidden           = new \WP_Term( 12, 'pack-oculto', 'Pack oculto' );
		$hidden->taxonomy = 'pa_serie';
		$hidden->count    = 3;
		$empty           = new \WP_Term( 13, 'sin-catalogo', 'Sin catálogo' );
		$empty->taxonomy = 'pa_serie';
		$empty->count    = 0;
		$hidden_only           = new \WP_Term( 14, 'solo-oculta', 'Solo oculta' );
		$hidden_only->taxonomy = 'pa_serie';
		$hidden_only->count    = 3;
		$novel           = new \WP_Term( 15, 'una-novela', 'Una novela' );
		$novel->taxonomy = 'pa_serie';
		$novel->count    = 1;

		Functions\when( 'get_terms' )->justReturn( array( $walking_dead, $hidden, $empty, $hidden_only, $novel, $one_piece ) );
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id, string $key ) => match ( true ) {
				'rank_math_robots' === $key && 12 === $term_id => array( 'noindex' ),
				SeriesSchemaType::META_KEY === $key && 15 === $term_id => SeriesSchemaType::NON_COMIC,
				default => '',
			}
		);
		Functions\when( 'get_term_link' )->alias(
			static fn( \WP_Term $term ): string => 'https://akibara.cl/serie/' . $term->slug . '/'
		);

		$graph = array(
			'BreadcrumbList' => array(
				'@type' => 'BreadcrumbList',
				'@id'   => 'https://akibara.cl/series/#breadcrumb',
			),
		);
		$eligibility_database       = new SeoEnhancementsEligibilityWpdb();
		$eligibility_database->rows = array(
			array( 'term_taxonomy_id' => 10, 'product_count' => 12 ),
			array( 'term_taxonomy_id' => 11, 'product_count' => 7 ),
			array( 'term_taxonomy_id' => 12, 'product_count' => 3 ),
			array( 'term_taxonomy_id' => 15, 'product_count' => 1 ),
		);
		$index  = new SeriesIndex( new SeriesCatalogEligibility( $eligibility_database ) );
		$sut    = new SeoEnhancements( true, null, null, null, null, $index );
		$result = $sut->add_series_hub_schema( $graph );

		$collection_nodes = array_values(
			array_filter(
				$result,
				static fn( $node ): bool => is_array( $node ) && 'CollectionPage' === ( $node['@type'] ?? '' )
			)
		);
		$list_nodes = array_values(
			array_filter(
				$result,
				static fn( $node ): bool => is_array( $node ) && 'ItemList' === ( $node['@type'] ?? '' )
			)
		);

		self::assertCount( 1, $collection_nodes );
		self::assertCount( 1, $list_nodes );
		self::assertSame( 'https://akibara.cl/series/#series-list', $collection_nodes[0]['mainEntity']['@id'] );
		self::assertSame( 3, $list_nodes[0]['numberOfItems'], 'Empty, hidden-only and noindex terms must not enter schema.' );
		self::assertSame( array( 1, 2, 3 ), array_column( $list_nodes[0]['itemListElement'], 'position' ) );
		self::assertSame(
			array(
				'https://akibara.cl/serie/one-piece/',
				'https://akibara.cl/serie/the-walking-dead/',
				'https://akibara.cl/serie/una-novela/',
			),
			array_column( array_column( $list_nodes[0]['itemListElement'], 'item' ), 'url' )
		);
		self::assertSame(
			array( 'ComicSeries', 'ComicSeries', 'Thing' ),
			array_column( array_column( $list_nodes[0]['itemListElement'], 'item' ), '@type' )
		);
		self::assertSame(
			array(
				'https://akibara.cl/serie/one-piece/#serie',
				'https://akibara.cl/serie/the-walking-dead/#serie',
			),
			array_column( array_column( $list_nodes[0]['itemListElement'], 'item' ), '@id' )
		);
		self::assertArrayNotHasKey( '@id', $list_nodes[0]['itemListElement'][2]['item'] );
		self::assertSame( 'CollectionPage', $collection_nodes[0]['@type'] );
		self::assertStringNotContainsString( 'Offer', (string) json_encode( $result ) );
		self::assertStringNotContainsString( 'Product', (string) json_encode( $result ) );
		self::assertSame( $result, $sut->add_series_hub_schema( $result ), 'Schema enrichment must remain idempotent.' );
	}

	/** @testdox Should never cache the series hub when its schema catalogue read fails. */
	public function test_marks_the_hub_uncacheable_when_schema_eligibility_is_unavailable(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'           => 44,
				'post_content' => '[akibara_series_directory]',
			)
		);
		Functions\when( 'has_shortcode' )->justReturn( true );

		$term           = new \WP_Term( 10, 'one-piece', 'One Piece' );
		$term->taxonomy = 'pa_serie';
		$term->count    = 12;
		Functions\when( 'get_terms' )->justReturn( array( $term ) );

		$database       = new SeoEnhancementsEligibilityWpdb();
		$database->fail = true;
		$index          = new SeriesIndex( new SeriesCatalogEligibility( $database ) );
		$sut            = new SeoEnhancements( true, null, null, null, null, $index );
		$graph          = array( 'BreadcrumbList' => array( '@type' => 'BreadcrumbList' ) );

		Monkey\Actions\expectDone( 'litespeed_control_set_nocache' )
			->once()
			->with( 'series catalogue eligibility unavailable' );

		self::assertSame( $graph, $sut->add_series_hub_schema( $graph ) );
		self::assertFalse( $index->is_reliable() );
	}

	/**
	 * @testdox Should not add hub schema to a singular page without the directory shortcode.
	 */
	public function test_does_not_add_hub_schema_to_other_singular_pages(): void {
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'           => 45,
				'post_content' => '<p>Página editorial</p>',
			)
		);
		Functions\when( 'has_shortcode' )->justReturn( false );
		$graph = array( 'BreadcrumbList' => array( '@type' => 'BreadcrumbList' ) );

		self::assertSame( $graph, ( new SeoEnhancements( true ) )->add_series_hub_schema( $graph ) );
	}

	/** @testdox Should add one factual WebPage for the shipping page and preserve the final graph. */
	public function test_adds_the_shipping_webpage_to_the_rank_math_graph(): void {
		Functions\when( 'is_page' )->alias( static fn( string $slug ): bool => 'envios' === $slug );
		$graph = array(
			'BreadcrumbList' => array(
				'@type' => 'BreadcrumbList',
				'@id'   => 'https://akibara.cl/envios/#breadcrumb',
			),
			'Unrelated'      => array(
				'@type' => 'Thing',
				'@id'   => 'https://akibara.cl/#unrelated',
			),
		);
		$sut    = new SeoEnhancements( true );
		$result = $sut->add_shipping_page_schema( $graph );
		$page   = $result['ShippingInformationWebPage'];

		self::assertSame( $graph['BreadcrumbList'], $result['BreadcrumbList'] );
		self::assertSame( $graph['Unrelated'], $result['Unrelated'] );
		self::assertSame( 'WebPage', $page['@type'] );
		self::assertSame( 'https://akibara.cl/envios/#webpage', $page['@id'] );
		self::assertSame( 'https://akibara.cl/envios/', $page['url'] );
		self::assertSame( 'Envíos y retiro de pedidos', $page['name'] );
		self::assertSame(
			'Consulta opciones de despacho a todo Chile y retiro coordinado en Metro San Miguel. El costo se muestra antes de pagar.',
			$page['description']
		);
		self::assertSame( array( '@id' => 'https://akibara.cl/#website' ), $page['isPartOf'] );
		self::assertSame( array( '@id' => 'https://akibara.cl/envios/#breadcrumb' ), $page['breadcrumb'] );
		self::assertStringNotContainsString( 'Article', wp_json_encode( $page ) );
		self::assertStringNotContainsString( 'LocalBusiness', wp_json_encode( $page ) );
		self::assertStringNotContainsString( 'Place', wp_json_encode( $page ) );
		self::assertStringNotContainsString( 'OfferShippingDetails', wp_json_encode( $page ) );
		self::assertSame( $result, $sut->add_shipping_page_schema( $result ), 'Schema enrichment must remain idempotent.' );
	}

	/** @testdox Should not add a shipping WebPage outside envios or without its breadcrumb. */
	public function test_shipping_webpage_fails_closed_without_its_exact_context(): void {
		$graph = array( 'BreadcrumbList' => array( '@type' => 'BreadcrumbList' ) );
		$sut   = new SeoEnhancements( true );

		Functions\when( 'is_page' )->justReturn( false );
		self::assertSame( $graph, $sut->add_shipping_page_schema( $graph ) );

		Functions\when( 'is_page' )->justReturn( true );
		self::assertSame( $graph, $sut->add_shipping_page_schema( $graph ) );
	}

	/**
	 * INVARIANTE: un campo SEO vacío es automático, no «manual congelado».
	 *
	 * Es la rama que el front ya aplicaba —`prefer_series_term_title()` sólo
	 * respeta el termmeta cuando tiene contenido— y la que la columna de la
	 * pantalla de series tiene que contar igual. Fijar la propiedad, no el texto:
	 * lo que no puede cambiar es que un término sin metas se anuncie como
	 * automático.
	 *
	 * @testdox Should report an empty Rank Math field as automatic, never as frozen.
	 */
	public function test_empty_series_meta_counts_as_automatic(): void {
		$sut = new SeoEnhancements( true );
		Functions\when( 'get_term_meta' )->justReturn( '' );

		self::assertSame( 'auto', $sut->render_series_meta_provenance_column( '', 'akb_seo', 900 ) );
	}

	/**
	 * INVARIANTE: valor guardado sin marca de propiedad válida = congelado.
	 *
	 * @testdox Should report a stored field without a valid snapshot marker as manual.
	 */
	public function test_stored_series_meta_without_marker_counts_as_manual(): void {
		$sut = new SeoEnhancements( true );
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id, string $key ): string => in_array( $key, array( 'rank_math_title', 'rank_math_description' ), true )
				? 'Texto editorial'
				: ''
		);

		self::assertSame( 'manual', $sut->render_series_meta_provenance_column( '', 'akb_seo', 900 ) );
	}

	/**
	 * INVARIANTE: propiedad + hash coincidente = automático aunque haya valor.
	 *
	 * @testdox Should report a stored field whose hash still matches as automatic.
	 */
	public function test_stored_series_meta_with_matching_hash_counts_as_automatic(): void {
		$sut   = new SeoEnhancements( true );
		$title = 'Serie X manga en español | Akibara Chile';
		$desc  = 'Serie X en Akibara Chile.';
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key ) use ( $title, $desc ): string {
				unset( $term_id );
				return match ( $key ) {
					'rank_math_title'                                     => $title,
					'rank_math_description'                               => $desc,
					SeoEnhancements::SERIES_TITLE_OWNERSHIP_KEY            => SeoEnhancements::SERIES_META_GENERATED,
					SeoEnhancements::SERIES_DESCRIPTION_OWNERSHIP_KEY      => SeoEnhancements::SERIES_META_GENERATED,
					SeoEnhancements::SERIES_TITLE_SNAPSHOT_HASH_KEY        => hash( 'sha256', $title ),
					SeoEnhancements::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY  => hash( 'sha256', $desc ),
					default                                               => '',
				};
			}
		);

		self::assertSame( 'auto', $sut->render_series_meta_provenance_column( '', 'akb_seo', 900 ) );
	}

	/**
	 * INVARIANTE del botón «Volver a automático»: lo que escribe tiene que
	 * volver a pasar el guard de propiedad.
	 *
	 * Antes de 1.108.22 NADIE escribía las metas de propiedad —las puso un
	 * script de una sola vez el 2026-08-15—, así que salir de un congelamiento
	 * accidental no era una operación posible. Si el hash y el valor se
	 * escribieran desacoplados, el botón dejaría la serie igual de congelada y
	 * sin decirlo.
	 *
	 * @testdox Should write a snapshot the ownership guard accepts when restoring automatic meta.
	 */
	public function test_restore_writes_a_snapshot_its_own_guard_accepts(): void {
		$stored = array();
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id, string $key ): string => (string) ( $stored[ $key ] ?? '' )
		);
		Functions\when( 'update_term_meta' )->alias(
			static function ( int $term_id, string $key, $value ) use ( &$stored ): bool {
				unset( $term_id );
				$stored[ $key ] = (string) $value;
				return true;
			}
		);
		// El generador necesita un medio reconocible desde un producto testigo.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( array( 5 ) );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'slug' => 'manga', 'parent' => 0 ) )
		);

		$term                   = new \WP_Term( 900, 'serie-x', 'Serie X' );
		$term->taxonomy         = 'pa_serie';
		$term->count            = 11;
		$term->term_taxonomy_id = 0; // Sin TTID no se consulta SeriesFacts.

		$sut     = new SeoEnhancements( true );
		$restore = new \ReflectionMethod( $sut, 'restore_generated_series_meta' );
		$restore->setAccessible( true );

		self::assertTrue( $restore->invoke( $sut, $term ), 'El generador sostenía una foto publicable.' );
		self::assertNotSame( '', $stored['rank_math_title'] ?? '' );
		self::assertSame(
			SeoEnhancements::SERIES_META_GENERATED,
			$stored[ SeoEnhancements::SERIES_TITLE_OWNERSHIP_KEY ] ?? ''
		);
		self::assertSame(
			'auto',
			( new SeoEnhancements( true ) )->render_series_meta_provenance_column( '', 'akb_seo', 900 ),
			'El guard tiene que aceptar el snapshot que acaba de escribir el botón.'
		);
	}

	/**
	 * INVARIANTE de rendimiento: con el medio cacheado no se consulta el catálogo.
	 *
	 * Medido en Docker el 2026-08-23 con `SAVEQUERIES` y `wp_cache_flush()` antes
	 * de cada lado, sobre `/serie/20th-century-boys/`: el tramo de meta pasó de
	 * 12 a 6 consultas y de 7,14 a 3,93 ms. Sin este candado, cualquiera puede
	 * volver a resolver el producto testigo por request sin que nada se queje.
	 *
	 * @testdox Should never query the catalog for the series medium when it is cached.
	 */
	public function test_cached_series_medium_skips_the_witness_query(): void {
		$this->on_series_archive( 'Serie X' );
		Functions\when( 'get_transient' )->justReturn( 'manga' );
		Functions\expect( 'get_posts' )->never();
		Functions\expect( 'set_transient' )->never();

		$term                   = new \WP_Term( 900, 'serie-x', 'Serie X' );
		$term->taxonomy         = 'pa_serie';
		$term->count            = 11;
		$term->term_taxonomy_id = 0;
		Functions\when( 'get_queried_object' )->justReturn( $term );

		self::assertStringContainsString( 'manga', ( new SeoEnhancements( true ) )->prefer_series_term_title( 'original' ) );
	}

	/**
	 * INVARIANTE: el '' NO se cachea.
	 *
	 * Un medio sin resolver es el estado de una serie recién creada; congelarlo
	 * 36 h dejaría la landing con la plantilla genérica justo cuando entra su
	 * primer tomo.
	 *
	 * @testdox Should not cache an unresolved series medium.
	 */
	public function test_unresolved_series_medium_is_not_cached(): void {
		$this->on_series_archive( 'Serie X' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array( 5 ) );
		Functions\when( 'get_the_terms' )->justReturn(
			array( (object) array( 'slug' => 'papeleria', 'parent' => 0 ) )
		);
		Functions\expect( 'set_transient' )->never();

		$term                   = new \WP_Term( 900, 'serie-x', 'Serie X' );
		$term->taxonomy         = 'pa_serie';
		$term->count            = 11;
		$term->term_taxonomy_id = 0;
		Functions\when( 'get_queried_object' )->justReturn( $term );

		self::assertSame( 'original', ( new SeoEnhancements( true ) )->prefer_series_term_title( 'original' ) );
	}

	/**
	 * INVARIANTE: sólo `product_cat` puede invalidar el medio.
	 *
	 * Entrar o salir de una serie no cambia el sustantivo del medio; invalidar
	 * ahí convertiría cada `wp_set_object_terms()` de importación en un borrado
	 * de transient por producto.
	 *
	 * @testdox Should forget the cached medium only when a product changes category.
	 */
	public function test_only_category_changes_forget_the_cached_medium(): void {
		$deleted = array();
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);
		Functions\when( 'get_the_terms' )->justReturn( array( (object) array( 'term_id' => 900 ) ) );

		$sut = new SeoEnhancements( true );
		$sut->forget_series_medium( 5, array(), array(), 'pa_serie' );
		self::assertSame( array(), $deleted, 'Un cambio de serie no puede tocar el medio cacheado.' );

		$sut->forget_series_medium( 5, array(), array(), 'product_cat' );
		self::assertSame( array( 'akb_serie_medio_900' ), $deleted );
	}

	/**
	 * Deja el request en un archive limpio de `pa_serie` con datos estables.
	 */
	private function on_series_archive(
		string $name,
		string $taxonomy = 'pa_serie',
		string $work_state = '',
		string $custom_description = '',
		string $custom_title = '',
		string $description_ownership = '',
		string $description_snapshot_hash = '',
		string $title_ownership = '',
		string $title_snapshot_hash = ''
	): void {
		$term           = new \WP_Term( 900, 'serie', $name );
		$term->taxonomy = $taxonomy;

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias(
			static fn( $requested ): bool => 'pa_serie' === $requested && 'pa_serie' === $taxonomy
		);
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key ) use ( $work_state, $custom_description, $custom_title, $description_ownership, $description_snapshot_hash, $title_ownership, $title_snapshot_hash ) {
				unset( $term_id );
				return match ( $key ) {
					'_akb_serie_estado'                                  => $work_state,
					'rank_math_description'                              => $custom_description,
					'rank_math_title'                                    => $custom_title,
					SeoEnhancements::SERIES_DESCRIPTION_OWNERSHIP_KEY     => $description_ownership,
					SeoEnhancements::SERIES_DESCRIPTION_SNAPSHOT_HASH_KEY => $description_snapshot_hash,
					SeoEnhancements::SERIES_TITLE_OWNERSHIP_KEY           => $title_ownership,
					SeoEnhancements::SERIES_TITLE_SNAPSHOT_HASH_KEY       => $title_snapshot_hash,
					default                                               => '',
				};
			}
		);
	}

	/**
	 * Captura hook, método y prioridad de cada filtro registrado.
	 *
	 * @return array<int,array{0:string,1:string,2:int}>
	 */
	private function registered_filters( SeoEnhancements $sut ): array {
		$filters = array();

		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook, $callback, int $priority = 10 ) use ( &$filters ): bool {
				$filters[] = array(
					$hook,
					is_array( $callback ) ? (string) $callback[1] : (string) $callback,
					$priority,
				);
				return true;
			}
		);

		$sut->register();

		return $filters;
	}

	/**
	 * Captura los nombres de filtros registrados por el módulo.
	 *
	 * @return string[]
	 */
	private function registered_filter_hooks( SeoEnhancements $sut ): array {
		$hooks = array();

		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->alias(
			static function ( string $hook ) use ( &$hooks ): bool {
				$hooks[] = $hook;
				return true;
			}
		);

		$sut->register();

		return $hooks;
	}

	/**
	 * @testdox La descripción de las series habla de tomos a pedido, no de preventas: hoy el catálogo no tiene preventas (sesión SEO/GEO).
	 */
	public function test_series_meta_description_says_a_pedido_not_preventas(): void {
		foreach ( array( '', 'completa', 'en_publicacion', 'en_pausa', 'cancelada' ) as $estado ) {
			foreach ( array( 'Berserk', 'Biblioteca Vertigo. John Constantine: Hellblazer', str_repeat( 'Nombre larguísimo ', 10 ) ) as $nombre ) {
				$descripcion = SeoEnhancements::series_meta_description( $nombre, $estado );

				self::assertStringNotContainsString( 'preventa', $descripcion, "{$nombre} / {$estado}" );
				self::assertStringContainsString( 'tomos a pedido', $descripcion, "{$nombre} / {$estado}" );
				self::assertLessThanOrEqual( 160, mb_strlen( $descripcion, 'UTF-8' ) );
			}
		}
	}

	/**
	 * @testdox category_last_updated_date devuelve la fecha del transient si existe.
	 */
	public function test_category_last_updated_date_returns_cached_transient(): void {
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ): ?string {
				return 'akb_cat_updated_28' === $key ? '2026-10-25' : null;
			}
		);

		self::assertSame( '2026-10-25', SeoEnhancements::category_last_updated_date( 28 ) );
		self::assertSame( '', SeoEnhancements::category_last_updated_date( 0 ) );
	}

	/**
	 * @testdox is_clean_product_cat_archive reconoce archivos limpios de product_cat página uno sin filtros.
	 */
	public function test_is_clean_product_cat_archive(): void {
		$sut = new SeoEnhancements();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( 1 );
		Functions\when( 'is_tax' )->alias(
			static function ( $tax = '' ): bool {
				return 'product_cat' === $tax;
			}
		);

		self::assertTrue( $sut->is_clean_product_cat_archive() );

		// En página 2 no es limpio
		Functions\when( 'get_query_var' )->justReturn( 2 );
		self::assertFalse( $sut->is_clean_product_cat_archive() );
	}

	/**
	 * @testdox invalidate_product_category_transients borra los transients akb_cat_updated_* de las categorías del producto.
	 */
	public function test_invalidate_product_category_transients_deletes_transients(): void {
		$deleted = array();
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 101, 102 ) );
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);

		SeoEnhancements::invalidate_product_category_transients( 999 );

		self::assertSame( array( 'akb_cat_updated_101', 'akb_cat_updated_102' ), $deleted );
	}

	/**
	 * @testdox invalidate_category_updated_transient_on_save ignora revisiones y autosaves.
	 */
	public function test_invalidate_category_updated_transient_on_save_guards(): void {
		$sut = new SeoEnhancements();
		$post = new \stdClass();
		$post->post_type = 'product';

		$deleted = false;
		Functions\when( 'delete_transient' )->alias(
			static function () use ( &$deleted ): bool {
				$deleted = true;
				return true;
			}
		);

		// Si es revision, no borra
		Functions\when( 'wp_is_post_revision' )->justReturn( true );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		$sut->invalidate_category_updated_transient_on_save( 123, $post );
		self::assertFalse( $deleted );

		// Si es post ordinario válido
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 555 ) );
		$sut->invalidate_category_updated_transient_on_save( 123, $post );
		self::assertTrue( $deleted );
	}

	/**
	 * @testdox Invalidation runs before deletion and receives old and new category relationships.
	 */
	public function test_registers_category_invalidation_before_relationships_are_deleted(): void {
		$actions = array();
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$actions ): bool {
				if ( is_array( $callback ) && $callback[0] instanceof SeoEnhancements ) {
					$actions[] = array( $hook, $callback[1], $priority, $accepted_args );
				}
				return true;
			}
		);
		( new SeoEnhancements( false ) )->register();
		self::assertContains( array( 'before_delete_post', 'invalidate_category_updated_transient_on_delete', 10, 1 ), $actions );
		self::assertContains( array( 'set_object_terms', 'invalidate_category_updated_transient_on_terms_set', 10, 6 ), $actions );
		self::assertContains( array( 'deleted_term_relationships', 'invalidate_category_updated_transient_on_terms_deleted', 10, 3 ), $actions );
		self::assertNotContains( array( 'delete_post', 'invalidate_category_updated_transient_on_delete', 10, 1 ), $actions );
	}

	/**
	 * @testdox Recategorizing invalidates both categories using term IDs rather than taxonomy relationship IDs.
	 */
	public function test_recategorization_invalidates_old_and_new_category_term_ids(): void {
		$deleted = array();
		$lookups = array();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_term_by' )->alias(
			static function ( string $field, int $tt_id, string $taxonomy ) use ( &$lookups ) {
				$lookups[] = array( $field, $tt_id, $taxonomy );
				return match ( $tt_id ) {
					31 => (object) array( 'term_id' => 701, 'taxonomy' => 'product_cat' ),
					32 => (object) array( 'term_id' => 702, 'taxonomy' => 'product_cat' ),
					33 => false,
					default => (object) array( 'term_id' => 999, 'taxonomy' => 'pa_serie' ),
				};
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);
		$sut = new SeoEnhancements();
		$sut->invalidate_category_updated_transient_on_terms_set( 123, array(), array( 32, 33, 34, 0 ), 'product_cat', false, array( 31, 32 ) );
		self::assertSame( array( 'akb_cat_updated_701', 'akb_cat_updated_702' ), $deleted );
		self::assertSame(
			array(
				array( 'term_taxonomy_id', 31, 'product_cat' ),
				array( 'term_taxonomy_id', 32, 'product_cat' ),
				array( 'term_taxonomy_id', 33, 'product_cat' ),
				array( 'term_taxonomy_id', 34, 'product_cat' ),
			),
			$lookups
		);
	}

	/**
	 * @testdox Removing a category invalidates it while other taxonomies and post types do not trigger work.
	 */
	public function test_removed_category_relationship_invalidates_only_product_category(): void {
		$deleted = array();
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_term_by' )->alias(
			static fn(): object => (object) array( 'term_id' => 701, 'taxonomy' => 'product_cat' )
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$deleted ): bool {
				$deleted[] = $key;
				return true;
			}
		);
		$sut = new SeoEnhancements();
		$sut->invalidate_category_updated_transient_on_terms_deleted( 123, array( 31 ), 'product_cat' );
		self::assertSame( array( 'akb_cat_updated_701' ), $deleted );
		$sut->invalidate_category_updated_transient_on_terms_deleted( 123, array( 31 ), 'pa_serie' );
		$sut->invalidate_category_updated_transient_on_terms_set( 123, array(), array( 31 ), 'pa_serie', false, array() );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		$sut->invalidate_category_updated_transient_on_terms_deleted( 123, array( 31 ), 'product_cat' );
		$sut->invalidate_category_updated_transient_on_terms_set( 123, array(), array( 31 ), 'product_cat', false, array() );
		self::assertSame( array( 'akb_cat_updated_701' ), $deleted );
	}
}
