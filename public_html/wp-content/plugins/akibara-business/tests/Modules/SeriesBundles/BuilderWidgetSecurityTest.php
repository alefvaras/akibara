<?php
/**
 * Frontera pública y universo canónico del builder de ficha.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\SelectionCart;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class BuilderWidgetJsonResponse extends \RuntimeException {
	/** @param array<string,mixed> $payload Respuesta interceptada. */
	public function __construct( public array $payload ) {
		parent::__construct( 'wp_send_json' );
	}
}

final class BuilderWidgetSecurityProduct extends \WC_Product {
	public bool $visible = true;
	public string $status = 'publish';
	public string $current_price = '10000';

	/** Unidades con stock gestionado: las preguntas de `RejectionReason`, con la regla de WooCommerce. */
	public int $unidades = 1;

	public function is_sold_individually(): bool {
		return false;
	}

	public function is_in_stock(): bool {
		return 'outofstock' !== $this->get_stock_status();
	}

	public function managing_stock(): bool {
		return 'onbackorder' !== $this->get_stock_status();
	}

	/**
	 * @param int $cantidad Unidades.
	 */
	public function has_enough_stock( $cantidad ): bool {
		return ! $this->managing_stock() || $this->unidades >= $cantidad;
	}

	public function get_stock_managed_by_id(): int {
		return (int) $this->get_id();
	}

	public function is_visible(): bool {
		return $this->visible;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_price(): string {
		return $this->current_price;
	}
}

final class BuilderWidgetRateWpdb {
	public string $options = 'wp_options';

	public function prepare( string $sql, ...$args ): string {
		unset( $args );
		return $sql;
	}

	public function query( string $sql ): int {
		unset( $sql );
		return 1;
	}

	public function get_var( string $sql ): string {
		unset( $sql );
		return '1';
	}
}

/**
 * Aislamiento necesario para declarar el doble global WC_Cart/WC().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class BuilderWidgetSecurityTest extends TestCase {

	private const TERM_ID = 378;

	/** @var array<int,BuilderWidgetSecurityProduct> */
	private array $products = array();

	/** @var array<string,string> Options del dueño. */
	private array $options = array();

	/** @var mixed */
	private $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );

		// Un alta rechazada deja su aviso en la cola, como `WC_Cart::add_to_cart()`.
		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					public array $attempted = array();
					public array $results = array();
					public array $quantities = array();
					public function add_to_cart( int $id, int $qty = 1 ) {
						unset( $qty ); $this->attempted[] = $id;
						$ok = $this->results[ $id ] ?? true;
						if ( ! $ok ) { $GLOBALS["akibara_builder_notices"]["error"][] = array( "notice" => "Ya tienes en tu carrito todas las unidades que quedan de este tomo." ); }
						return $ok;
					}
					public function get_cart_item_quantities() { return $this->quantities; }
				} }'
			);
		}
		$GLOBALS['akibara_builder_notices'] = array();
		Functions\when( 'wc_get_notices' )->alias( static fn(): array => $GLOBALS['akibara_builder_notices'] );
		Functions\when( 'wc_set_notices' )->alias(
			static function ( $avisos ): void {
				$GLOBALS['akibara_builder_notices'] = $avisos;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v ) );
		if ( ! function_exists( 'WC' ) ) {
			eval( 'namespace { function WC() { return $GLOBALS["akibara_builder_wc"]; } }' );
		}

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new BuilderWidgetRateWpdb();
		$_POST               = array();
		$_SERVER             = array(
			'HTTP_ORIGIN' => 'https://akibara.cl',
			'REMOTE_ADDR' => '127.0.0.1',
		);

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_send_json' )->alias(
			static function ( array $payload ): void {
				throw new BuilderWidgetJsonResponse( $payload );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ): string => (string) $value );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				$options = array(
					Settings::OPTION_ENABLED         => 'yes',
					Settings::OPTION_BYP             => '{"378":{"enabled":true,"tiers":[{"min":2,"pct":10}]}}',
					Settings::OPTION_EXCLUDE_CATS    => array(),
					Settings::OPTION_ALLOW_BACKORDER => 'yes',
				) + $this->options;
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->products[ $id ] ?? null );
		Functions\when( 'get_the_terms' )->alias(
			function ( int $product_id, string $taxonomy ): array {
				if ( 'pa_serie' !== $taxonomy || ! isset( $this->products[ $product_id ] ) ) {
					return array();
				}
				$term           = new \WP_Term( self::TERM_ID, 'serie', 'Serie' );
				$term->taxonomy = 'pa_serie';
				return array( $term );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_builder_wc'], $GLOBALS['akibara_builder_notices'] );
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		$_POST   = array();
		$_SERVER = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox handle_add_to_cart accepts only publish visible simple purchasable stock or preorder products. */
	public function test_handle_add_to_cart_uses_the_same_canonical_catalog_universe(): void {
		$specs = array(
			1 => array( 'stock' => 'instock', 'visible' => true, 'status' => 'publish', 'type' => 'simple', 'purchasable' => true ),
			2 => array( 'stock' => 'instock', 'visible' => false, 'status' => 'publish', 'type' => 'simple', 'purchasable' => true ),
			3 => array( 'stock' => 'instock', 'visible' => true, 'status' => 'publish', 'type' => 'variable', 'purchasable' => true ),
			4 => array( 'stock' => 'instock', 'visible' => true, 'status' => 'publish', 'type' => 'simple', 'purchasable' => false ),
			5 => array( 'stock' => 'outofstock', 'visible' => true, 'status' => 'publish', 'type' => 'simple', 'purchasable' => true ),
			6 => array( 'stock' => 'onbackorder', 'visible' => true, 'status' => 'publish', 'type' => 'simple', 'purchasable' => true ),
			7 => array( 'stock' => 'instock', 'visible' => true, 'status' => 'draft', 'type' => 'simple', 'purchasable' => true ),
		);
		foreach ( $specs as $id => $spec ) {
			$product           = new BuilderWidgetSecurityProduct( $spec['stock'], array(), $spec['purchasable'] );
			$product->akb_id   = $id;
			$product->akb_type = $spec['type'];
			$product->visible  = $spec['visible'];
			$product->status   = $spec['status'];
			$this->products[ $id ] = $product;
		}
		$_POST = array(
			'series_id'   => self::TERM_ID,
			'product_ids' => array_keys( $specs ),
		);
		$cart = new \WC_Cart();
		$GLOBALS['akibara_builder_wc'] = (object) array( 'cart' => $cart );

		$response = $this->capture( fn() => $this->builder()->handle_add_to_cart() );

		self::assertTrue( $response['success'] );
		// `added_ids` es exacta: lo NO autorizado es su complemento sobre el POST,
		// que es como lo calcula el cliente. La respuesta no lo enumera aparte.
		self::assertSame( array( 1, 6 ), $response['added_ids'] );
		self::assertSame( array( 2, 3, 4, 5, 7 ), array_values( array_diff( array_keys( $specs ), $response['added_ids'] ) ) );
		self::assertArrayNotHasKey( 'skipped_ids', $response );
		self::assertSame( array( 1, 6 ), $cart->attempted );
		// El agotado no llega a intentarse, pero tiene motivo: el cliente lo
		// desmarca y lee por qué. Lo demás no tiene un motivo que el estado
		// explique y queda marcado.
		self::assertSame( array( 5 ), $response['rejected_ids'] );
		self::assertSame( SelectionCart::DEFAULT_ERROR, $response['message'] );
	}

	/**
	 * Deuda previa (verificación del 2026-09-25): volver a elegir tomos de una
	 * unidad que ya estaban en el carrito respondía «No se pudo agregar.
	 * Inténtalo de nuevo.», fijo en el código, y los dejaba marcados: el
	 * reintento daba lo mismo. Y cada intento dejaba un aviso por tomo en la
	 * cola, que la página siguiente pintaba de golpe.
	 *
	 * @testdox Volver a elegir tomos que ya están en el carrito dice el motivo, con el texto del dueño, los desmarca y no deja avisos para la página siguiente.
	 */
	public function test_tomos_que_ya_estan_en_el_carrito(): void {
		foreach ( array( 11, 12 ) as $id ) {
			$product         = new BuilderWidgetSecurityProduct( 'instock', array(), true );
			$product->akb_id = $id;
			$this->products[ $id ] = $product;
		}
		$_POST = array(
			'series_id'   => self::TERM_ID,
			'product_ids' => array( 11, 12 ),
		);
		$cart             = new \WC_Cart();
		$cart->results    = array( 11 => false, 12 => false );
		$cart->quantities = array( 11 => 1, 12 => 1 );
		$GLOBALS['akibara_builder_wc']      = (object) array( 'cart' => $cart );
		$antes                              = array( 'success' => array( array( 'notice' => 'Un aviso que ya estaba.' ) ) );
		$GLOBALS['akibara_builder_notices'] = $antes;

		$response = $this->capture( fn() => $this->builder()->handle_add_to_cart() );

		self::assertFalse( $response['success'] );
		self::assertSame( SelectionCart::DEFAULT_YA_EN_CARRITO, $response['message'], 'El motivo real, no «No se pudo agregar. Inténtalo de nuevo.».' );
		self::assertSame( array( 11, 12 ), $response['rejected_ids'], 'Reintentar no cambiaría nada: el cliente los desmarca.' );
		self::assertSame( $antes, $GLOBALS['akibara_builder_notices'], 'La cola de avisos queda como estaba: la respuesta ya explica.' );

		// El texto es del dueño.
		$this->options[ SelectionCart::OPTION_YA_EN_CARRITO ] = "Esos tomos\nya están en tu carrito.";
		$response = $this->capture( fn() => $this->builder()->handle_add_to_cart() );
		self::assertSame( 'Esos tomos ya están en tu carrito.', $response['message'] );
	}

	/** @testdox Con un tomo ya en el carrito y otro que entra, el pack completo está en el carrito: no hay motivo que explicar. */
	public function test_uno_ya_en_el_carrito_y_otro_que_entra(): void {
		foreach ( array( 11, 12 ) as $id ) {
			$product         = new BuilderWidgetSecurityProduct( 'instock', array(), true );
			$product->akb_id = $id;
			$this->products[ $id ] = $product;
		}
		$_POST = array(
			'series_id'   => self::TERM_ID,
			'product_ids' => array( 11, 12 ),
		);
		$cart             = new \WC_Cart();
		$cart->results    = array( 11 => false );
		$cart->quantities = array( 11 => 1, 12 => 1 );
		$GLOBALS['akibara_builder_wc'] = (object) array( 'cart' => $cart );

		$response = $this->capture( fn() => $this->builder()->handle_add_to_cart() );

		self::assertTrue( $response['success'] );
		self::assertSame( array( 12 ), $response['added_ids'] );
		self::assertSame( array( 11 ), $response['rejected_ids'] );
		self::assertSame( '', $response['message'] );
	}

	/** @testdox Builder batch overflow fails closed before the WooCommerce cart is opened. */
	public function test_handle_add_to_cart_rejects_more_than_the_ceiling(): void {
		$_POST['product_ids'] = range( 1, BuilderWidget::MAX_ADD_PER_REQUEST + 1 );

		$response = $this->capture( fn() => $this->builder()->handle_add_to_cart() );

		self::assertFalse( $response['success'] );
		self::assertStringContainsString( 'hasta 300 tomos', (string) $response['message'] );
		self::assertArrayNotHasKey( 'akibara_builder_wc', $GLOBALS );
	}

	/** @testdox series_volumes explicitly removes draft and hidden products and delegates selectable state to resolve_catalog_series. */
	public function test_series_volumes_source_uses_visibility_and_the_canonical_resolver(): void {
		$method = new \ReflectionMethod( BuilderWidget::class, 'series_volumes' );
		$lines  = file( (string) $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );

		self::assertStringContainsString( "method_exists( \$vol_product, 'get_status' )", $source );
		self::assertStringContainsString( "method_exists( \$vol_product, 'is_visible' )", $source );
		self::assertStringContainsString( 'resolve_catalog_series( $vol_product, $term_id )', $source );

		// La consulta y la decisión viven en `builder_view_model()`; `build_html()`
		// sólo pinta lo que ese modelo ya resolvió.
		$model  = new \ReflectionMethod( BuilderWidget::class, 'builder_view_model' );
		$lines  = file( (string) $model->getFileName() );
		$model_source = implode( '', array_slice( $lines, $model->getStartLine() - 1, $model->getEndLine() - $model->getStartLine() + 1 ) );
		self::assertStringContainsString( 'resolve_catalog_product_ids( $term_id, $candidates, 999 )', $model_source );
		self::assertStringContainsString( 'owned_among( $user_id, $catalog_ids )', $model_source );
		self::assertStringNotContainsString( 'owned_among( $user_id, $candidates )', $model_source );
		self::assertStringContainsString( 'self::reachable_tiers_for_volumes( $tiers, $volumes )', $model_source );
		self::assertStringNotContainsString( '$purchasable_count', $model_source );

		$build = new \ReflectionMethod( BuilderWidget::class, 'build_html' );
		$lines = file( (string) $build->getFileName() );
		$html_source = implode( '', array_slice( $lines, $build->getStartLine() - 1, $build->getEndLine() - $build->getStartLine() + 1 ) );
		self::assertStringNotContainsString( 'owned_among(', $html_source );
		self::assertStringNotContainsString( 'resolve_catalog_product_ids(', $html_source );
	}

	/** @testdox A purchasable ordinary volume cannot make the seven-volume discount tier appear on the PDP. */
	public function test_pdp_tiers_count_only_discount_eligible_volumes(): void {
		$tiers = array(
			array( 'min' => 5, 'pct' => 8 ),
			array( 'min' => 7, 'pct' => 10 ),
		);
		$volumes = array();
		for ( $id = 1; $id <= 6; ++$id ) {
			$volumes[] = array(
				'id'                => $id,
				'is_purchasable'    => true,
				'discount_eligible' => true,
			);
		}
		$volumes[] = array(
			'id'                => 7,
			'is_purchasable'    => true,
			'discount_eligible' => false,
		);

		$method = new \ReflectionMethod( BuilderWidget::class, 'reachable_tiers_for_volumes' );
		$actual = $method->invoke( null, $tiers, $volumes );

		self::assertSame(
			array( array( 'min' => 5, 'pct' => 8 ) ),
			$actual,
			'El tomo ordinario sigue siendo comprable, pero no vuelve alcanzable el tramo 7→10.'
		);
	}

	/** @return array<string,mixed> */
	private function capture( callable $callback ): array {
		try {
			$callback();
			self::fail( 'El handler no llamó wp_send_json().' );
		} catch ( BuilderWidgetJsonResponse $response ) {
			return $response->payload;
		}
	}

	private function builder(): BuilderWidget {
		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );

		return new BuilderWidget( $settings, $guard, new VolumeTierResolver(), $collector );
	}
}
