<?php
/**
 * Pruebas del contrato unificado DiscountCopy <-> AnnouncementBar.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\DiscountCopy;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Akibara\Business\Modules\DiscountCopy\DiscountCopy;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class DiscountCopyUnifiedTest extends TestCase {

	/** @var array<int, string> */
	private array $deleted_transients = array();

	/** @var array<string, mixed> */
	private array $written_transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->deleted_transients = array();
		$this->written_transients = array();

		$GLOBALS['akibara_test_options'] = array(
			Settings::OPTION_ENABLED  => 'yes',
			Settings::OPTION_FROM     => '2026-11-01',
			Settings::OPTION_TO       => '2026-11-30',
			Settings::OPTION_APPLY_TO => Settings::APPLY_INSTOCK_ONLY,
		);

		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'current_time' )->justReturn( '2026-11-10' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl/wp-admin/' . ltrim( $path, '/' ) );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ): string => strip_tags( (string) $text ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( string $url, int $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/tienda/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( ...$args ): string {
				if ( is_array( $args[0] ) ) {
					$url = $args[1] ?? '';
					return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args[0] );
				}
				$key = (string) ( $args[0] ?? '' );
				$val = (string) ( $args[1] ?? '' );
				$url = (string) ( $args[2] ?? '' );
				return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( $val );
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $v ): bool => $v instanceof \WP_Error );
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				$this->deleted_transients[] = $key;
				return true;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->written_transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );

		DiscountCopy::forget();
		$this->deleted_transients = array();
		$this->written_transients = array();
	}

	protected function tearDown(): void {
		DiscountCopy::forget();
		unset( $GLOBALS['akibara_test_options'], $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, object>       $terms_by_tax
	 * @param array<int, string|int>   $pcts
	 * @param array<int, int>          $eligible_counts
	 */
	private function setup_taxonomy_world( array $terms_by_tax, array $pcts, array $eligible_counts ): void {
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( string $tax ): bool => array_key_exists( $tax, $terms_by_tax )
		);
		Functions\when( 'get_terms' )->alias(
			static function ( array $args = array() ) use ( $terms_by_tax ): array {
				$taxes = (array) ( $args['taxonomy'] ?? array() );
				$out   = array();
				foreach ( $taxes as $tax ) {
					foreach ( $terms_by_tax[ $tax ] ?? array() as $term ) {
						$out[] = $term;
					}
				}
				return $out;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static fn( int $term_id, string $key ): string => (string) ( $pcts[ $term_id ] ?? '' )
		);
		Functions\when( 'get_term_link' )->alias(
			static function ( $term, string $tax = '' ) {
				$id       = is_object( $term ) ? (int) $term->term_id : (int) $term;
				$slug     = is_object( $term ) && isset( $term->slug ) ? $term->slug : ( 'term-' . $id );
				$taxonomy = '' !== $tax ? $tax : ( is_object( $term ) && isset( $term->taxonomy ) ? (string) $term->taxonomy : '' );
				$prefix   = 'pa_serie' === $taxonomy ? 'serie' : ( 'product_brand' === $taxonomy ? 'marca' : 'tax' );
				return 'https://akibara.cl/' . $prefix . '/' . $slug . '/';
			}
		);

		$GLOBALS['wpdb'] = new class( $eligible_counts ) {
			public string $term_taxonomy      = 'wp_term_taxonomy';
			public string $term_relationships = 'wp_term_relationships';
			public string $posts              = 'wp_posts';
			public string $prefix             = 'wp_';
			public string $last_error         = '';
			/** @var array<int, int> */
			private array $counts;

			/** @param array<int, int> $counts */
			public function __construct( array $counts ) {
				$this->counts = $counts;
			}

			public function prepare( string $sql, ...$args ): string {
				if ( false !== strpos( $sql, 'SHOW TABLES LIKE' ) && isset( $args[0] ) ) {
					return 'SHOW_TABLES:' . (string) $args[0];
				}
				return $sql;
			}

			public function get_var( string $sql ) {
				if ( 0 === strpos( $sql, 'SHOW_TABLES:' ) ) {
					return substr( $sql, strlen( 'SHOW_TABLES:' ) );
				}
				return null;
			}

			public function get_results( string $sql, string $output = ARRAY_A ): array {
				unset( $sql, $output );
				$rows = array();
				foreach ( $this->counts as $tt_id => $cnt ) {
					$rows[] = array(
						'term_taxonomy_id' => $tt_id,
						'cnt'              => $cnt,
					);
				}
				return $rows;
			}
		};
	}

	/**
	 * @testdox [akb_descuento_url] respeta stock=auto/si/no, alcance=tienda y cae a tienda si get_term_link falla.
	 */
	public function test_descuento_url_and_vigencia_shortcodes(): void {
		$prop = new ReflectionProperty( DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$prop->setValue(
			null,
			array(
				'v'           => 2,
				'pct'         => 15,
				'term_name'   => 'Ivrea Argentina',
				'term_id'     => 230,
				'taxonomy'    => 'product_brand',
				'term_path'   => '/marca/ivrea-argentina/',
				'rules_count' => 1,
			)
		);

		Functions\when( 'get_term_link' )->justReturn( new \WP_Error( 'invalid_term', 'broken' ) );

		// Con apply_to = instock_only y stock="auto", usa term_path relativo + _disponibilidad=instock.
		self::assertSame(
			'https://akibara.cl/marca/ivrea-argentina/?_disponibilidad=instock',
			DiscountCopy::resolve_in( '[akb_descuento_url]' )
		);
		self::assertSame(
			'https://akibara.cl/marca/ivrea-argentina/',
			DiscountCopy::resolve_in( '[akb_descuento_url stock="no"]' )
		);
		self::assertSame(
			'https://akibara.cl/tienda/?_disponibilidad=instock',
			DiscountCopy::resolve_in( '[akb_descuento_url alcance="tienda"]' )
		);
		self::assertSame( 'del 01/11/2026 al 30/11/2026', DiscountCopy::resolve_in( '[akb_descuento_vigencia]' ) );

		// Si falta term_path y get_term_link devuelve WP_Error, cae limpio a /tienda/.
		$prop->setValue(
			null,
			array(
				'v'           => 2,
				'pct'         => 15,
				'term_name'   => 'Ivrea',
				'term_id'     => 230,
				'taxonomy'    => 'product_brand',
				'term_path'   => '',
				'rules_count' => 1,
			)
		);
		self::assertSame(
			'https://akibara.cl/tienda/?_disponibilidad=instock',
			DiscountCopy::target_url( 'term', 'si' )
		);
	}

	/**
	 * @testdox Excluye taxonomías 100% agotadas (Dorohedoro) y aplica tie-break determinista (pct -> stock -> prioridad -> id) guardando solo path relativo v2.
	 */
	public function test_excludes_out_of_stock_terms_and_breaks_ties_deterministically(): void {
		$dorohedoro = (object) array(
			'term_id'          => 10,
			'name'             => 'Dorohedoro',
			'slug'             => 'dorohedoro',
			'taxonomy'         => 'pa_serie',
			'term_taxonomy_id' => 1010,
		);
		$ivrea = (object) array(
			'term_id'          => 230,
			'name'             => 'Ivrea Argentina',
			'slug'             => 'ivrea-argentina',
			'taxonomy'         => 'product_brand',
			'term_taxonomy_id' => 2230,
		);
		$panini = (object) array(
			'term_id'          => 150,
			'name'             => 'Panini España',
			'slug'             => 'panini-espana',
			'taxonomy'         => 'product_brand',
			'term_taxonomy_id' => 2150,
		);

		$this->setup_taxonomy_world(
			array(
				'product_brand' => array( $ivrea, $panini ),
				'pa_serie'      => array( $dorohedoro ),
			),
			array(
				10  => 25, // Mayor % pero 0 elegibles con stock -> excluido bajo instock_only.
				230 => 15, // 45 elegibles -> gana tie-break sobre Panini (10 elegibles).
				150 => 15,
			),
			array(
				2230 => 45,
				2150 => 10,
			)
		);

		$rule = DiscountCopy::top_rule();
		self::assertIsArray( $rule );
		self::assertSame( 'Ivrea Argentina', $rule['term_name'] );
		self::assertSame( 15, $rule['pct'] );
		self::assertSame( '/marca/ivrea-argentina/', $rule['term_path'] );
		self::assertSame( 2, $rule['rules_count'] );

		// El transient guarda versión 2 y path relativo (jamás URL absoluta con host congelado).
		$cached = $this->written_transients['akb_discount_top_rule'] ?? null;
		self::assertIsArray( $cached );
		self::assertSame( 2, $cached['v'] );
		self::assertSame( '/marca/ivrea-argentina/', $cached['term_path'] );
		self::assertStringStartsNotWith( 'http', $cached['term_path'] );
	}

	/**
	 * @testdox Un transient legado v1 sin term_path se recalcula automáticamente en WordPress a v2.
	 */
	public function test_upgrades_v1_transient_to_v2(): void {
		Functions\when( 'get_transient' )->justReturn(
			array(
				'pct'       => 10,
				'term_name' => 'Ivrea Argentina',
			)
		);
		$ivrea = (object) array(
			'term_id'          => 230,
			'name'             => 'Ivrea Argentina',
			'slug'             => 'ivrea-argentina',
			'taxonomy'         => 'product_brand',
			'term_taxonomy_id' => 2230,
		);
		$this->setup_taxonomy_world(
			array( 'product_brand' => array( $ivrea ) ),
			array( 230 => 15 ),
			array( 2230 => 12 )
		);

		$rule = DiscountCopy::top_rule();
		self::assertSame( 15, $rule['pct'] );
		self::assertSame( '/marca/ivrea-argentina/', $rule['term_path'] );
		self::assertSame( 2, $this->written_transients['akb_discount_top_rule']['v'] );
	}

	/**
	 * @testdox register() escucha edited_term, delete_term y cambios de atributos/permalinks para invalidar caché.
	 */
	public function test_register_hooks_term_and_permalink_invalidation(): void {
		$actions = array();
		Functions\when( 'add_shortcode' )->justReturn( true );
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$actions ): bool {
				$actions[] = $hook;
				return true;
			}
		);

		( new DiscountCopy() )->register();

		self::assertContains( 'edited_term', $actions );
		self::assertContains( 'delete_term', $actions );
		self::assertContains( 'woocommerce_attribute_updated', $actions );
		self::assertContains( 'update_option_woocommerce_permalinks', $actions );

		// forget_on_term_change invalida si la taxonomía pertenece a Descuentos, no si es nav_menu.
		Functions\when( 'taxonomy_exists' )->alias( static fn( string $tax ): bool => 'product_brand' === $tax );
		DiscountCopy::forget_on_term_change( 1, 1, 'nav_menu' );
		self::assertSame( array(), $this->deleted_transients );

		DiscountCopy::forget_on_term_change( 230, 2230, 'product_brand' );
		self::assertContains( 'akb_discount_top_rule', $this->deleted_transients );
	}

	/**
	 * @testdox AnnouncementBar acepta | [akb_descuento_url], limpia | huérfana si resuelve vacío y advierte tokens [akb_descuento_*] en mensajes fijos.
	 */
	public function test_announcement_bar_link_token_cleanup_and_fixed_warning(): void {
		$GLOBALS['akibara_test_options']['akibara_announcement_messages']   = 'Fijo mal puesto: [akb_descuento_pct]';
		$GLOBALS['akibara_test_options']['akibara_announcement_promo_text'] = 'Semana Ivrea | [akb_descuento_url]';

		self::assertSame( array(), AnnouncementBar::unrecognized_links( 'Semana Ivrea | [akb_descuento_url]' ) );
		self::assertSame( array(), AnnouncementBar::unrecognized_links( 'Semana Ivrea | [akb_descuento_url stock="si"]' ) );

		// Si [akb_descuento_url] resuelve vacío (campaña apagada), split_lines debe limpiar la barra final «|».
		$GLOBALS['akibara_test_options'][ Settings::OPTION_ENABLED ] = 'no';
		$split = new ReflectionMethod( AnnouncementBar::class, 'split_lines' );
		$split->setAccessible( true );
		$lines = $split->invoke( null, 'Semana Ivrea | [akb_descuento_url]' );
		self::assertSame( array( 'Semana Ivrea' ), $lines );

		$avisos = ( new AnnouncementBar() )->report_saved_with_warning( array(), 'announcement' );
		self::assertArrayHasKey( 'akibara_announcement_messages', $avisos );
		self::assertArrayHasKey( 'akibara_announcement_promo_text', $avisos );

		// Vista previa compartida resuelve shortcodes + link para ambos tabs.
		$prop = new ReflectionProperty( DiscountCopy::class, 'rule_memo' );
		$prop->setAccessible( true );
		$GLOBALS['akibara_test_options'][ Settings::OPTION_ENABLED ] = 'yes';
		$prop->setValue(
			null,
			array(
				'v'           => 2,
				'pct'         => 15,
				'term_name'   => 'Ivrea Argentina',
				'term_id'     => 230,
				'taxonomy'    => 'product_brand',
				'term_path'   => '/marca/ivrea-argentina/',
				'rules_count' => 1,
			)
		);
		$preview = AnnouncementBar::preview_promo_line( '<b>Hasta [akb_descuento_pct]</b> en [akb_descuento_editorial] | [akb_descuento_url]' );
		self::assertSame( 'Hasta 15% en Ivrea Argentina', $preview['text'] );
		self::assertSame( 'https://akibara.cl/marca/ivrea-argentina/?_disponibilidad=instock', $preview['url'] );
	}
}
