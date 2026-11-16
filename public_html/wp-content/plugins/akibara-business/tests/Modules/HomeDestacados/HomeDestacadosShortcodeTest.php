<?php
/**
 * Pruebas unitarias de registro y sanitización de comillas en los shortcodes de HomeDestacados.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeDestacados;

use Akibara\Business\Modules\HomeDestacados\HomeDestacados;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.122.1
 */
final class HomeDestacadosShortcodeTest extends TestCase {

	/** @var array<string, callable> */
	private array $shortcodes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->shortcodes = array();
		Functions\when( 'add_shortcode' )->alias(
			function ( string $tag, $callback ): void {
				$this->shortcodes[ $tag ] = $callback;
			}
		);
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'shortcode_atts' )->alias( static fn( array $defaults, array $atts ): array => array_merge( $defaults, $atts ) );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '0' );
		Functions\when( 'get_posts' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Registra tanto akb_destacados como akb_preventas_destacadas. */
	public function test_registers_both_shortcodes(): void {
		$module = new HomeDestacados();
		$module->register();

		$this->assertArrayHasKey( 'akb_destacados', $this->shortcodes );
		$this->assertArrayHasKey( 'akb_preventas_destacadas', $this->shortcodes );
	}

	/** @testdox Product events invalidate once without passing product IDs to the boolean force argument. */
	public function test_product_hook_callbacks_preserve_the_generation_write_guard(): void {
		$hooks = array();
		$writes = 0;
		Functions\when( 'add_action' )->alias( static function ( $name, $callback, $priority = 10, $accepted = 1 ) use ( &$hooks ): void {
			$hooks[ $name ] = array( $callback, $accepted );
		} );
		Functions\when( 'update_option' )->alias( static function () use ( &$writes ): bool {
			++$writes;
			return true;
		} );
		$module = new HomeDestacados();
		$module->register();
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'woocommerce_delete_product', 'woocommerce_trash_product', 'woocommerce_updated_product_sales', 'woocommerce_recorded_sales' ) as $name ) {
			[ $callback, $accepted ] = $hooks[ $name ];
			self::assertSame( 0, $accepted );
			call_user_func_array( $callback, array_slice( array( 123, 'instock' ), 0, $accepted ) );
		}
		self::assertSame( 1, $writes, 'Ocho eventos del mismo request no fuerzan ocho escrituras.' );
	}

	/** @testdox render_a_pedido_destacados limpia comillas tipográficas en atributos. */
	public function test_render_a_pedido_destacados_strips_typographic_quotes(): void {
		$module = new HomeDestacados();

		// Si no hay candidatos, devuelve cadena vacía limpiamente sin romper en PHP
		$out = $module->render_a_pedido_destacados(
			array(
				'count'         => '»8″',
				'columns'       => '»4″',
				'max_per_serie' => '»1″',
			)
		);

		$this->assertSame( '', $out );
	}

	/** @testdox clean_shortcode_atts rescata tokens posicionales, primas, entidades HTML, cachea vacío y autoload=false. */
	public function test_clean_shortcode_atts_rescues_positional_prime_entities_caches_empty_and_autoloads_false(): void {
		$captured_atts       = array();
		$transient_writes    = array();
		$updated_options     = array();
		Functions\when( 'sanitize_key' )->alias(
			static fn( string $k ): string => strtolower( (string) preg_replace( '/[^a-zA-Z0-9_-]/', '', $k ) )
		);
		Functions\when( 'shortcode_atts' )->alias(
			static function ( array $defaults, array $atts ) use ( &$captured_atts ): array {
				$merged          = array_merge( $defaults, $atts );
				$captured_atts[] = $merged;
				return $merged;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( string $key, $val, int $ttl ) use ( &$transient_writes ): bool {
				$transient_writes[ $key ] = array( 'val' => $val, 'ttl' => $ttl );
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( string $opt, $val, $autoload = null ) use ( &$updated_options ): bool {
				$updated_options[ $opt ] = array( 'val' => $val, 'autoload' => $autoload );
				return true;
			}
		);

		$module = new HomeDestacados();
		$out    = $module->render_a_pedido_destacados(
			array(
				0               => 'count=»8&Prime;',
				1               => 'columns="4',
				'max_per_serie' => '′1&#8221;',
			)
		);

		$this->assertSame( '', $out );
		$this->assertSame( '8', $captured_atts[0]['count'] ?? null );
		$this->assertSame( '4', $captured_atts[0]['columns'] ?? null );
		$this->assertSame( '1', $captured_atts[0]['max_per_serie'] ?? null );
		$this->assertContains( '__EMPTY__', array_column( $transient_writes, 'val' ) );

		$module->bump_cache_generation();
		$this->assertFalse( $updated_options['akb_home_destacados_generation']['autoload'] );
	}
}
