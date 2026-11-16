<?php
/**
 * Pruebas unitarias para SeriesBatchAtc.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBatchAtc;

use Akibara\Business\Modules\SeriesBatchAtc\SeriesBatchAtc;
use Akibara\Business\Modules\SeriesFacts\PublicationStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

final class SeriesBatchAtcTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.cl' . ( $path ? '/' . ltrim( $path, '/' ) : '' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mock_series_term( int $count = 11, ?int $tomos_edicion = null ): WP_Term {
		$term           = new WP_Term( 99, 'chainsaw-man', 'Chainsaw Man' );
		$term->taxonomy = 'pa_serie';
		$term->count    = $count;

		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_term_meta' )->alias(
			static function( int $term_id, string $key, bool $single = false ) use ( $tomos_edicion ) {
				if ( PublicationStatus::META_EDITION_TOTAL === $key && null !== $tomos_edicion ) {
					return $tomos_edicion;
				}
				return '';
			}
		);

		return $term;
	}

	private function make_product( int $id, string $name, string $price ): \WC_Product {
		return new class( $id, $name, $price ) extends \WC_Product {
			private int $p_id;
			private string $p_name;
			private string $p_price;

			public function __construct( int $id, string $name, string $price ) {
				parent::__construct( 'instock' );
				$this->p_id    = $id;
				$this->p_name  = $name;
				$this->p_price = $price;
			}

			public function get_id(): int { return $this->p_id; }
			public function get_name(): string { return $this->p_name; }
			public function get_price(): string { return $this->p_price; }
			public function get_type(): string { return 'simple'; }
		};
	}

	/** @testdox The edition map queries the series directly, orders volumes numerically and marks verified purchases including variants. */
	public function test_edition_products_queries_series_and_marks_verified_purchases(): void {
		$this->mock_series_term( 109, 110 );
		$ids      = range( 101, 209 );
		$ids[]    = 999; // Variant of volume 2.
		$products = array();
		for ( $volume = 1; $volume <= 109; ++$volume ) {
			$id              = 100 + $volume;
			$products[ $id ] = $this->make_product( $id, sprintf( 'One Piece %03d', $volume ), '1000' );
		}
		$products[999] = $this->make_product( 999, 'One Piece 002 (Portada alternativa)', '1500' );

		Functions\when( 'get_objects_in_term' )->alias( static fn ( int $term_id, string $tax ): array => 99 === $term_id && 'pa_serie' === $tax ? array_map( 'strval', $ids ) : array() );
		Functions\when( 'get_posts' )->justReturn( $ids );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'wc_get_product' )->alias( static fn ( int $id ) => $products[ $id ] ?? null );
		Functions\when( 'wc_get_price_to_display' )->alias( static fn ( \WC_Product $p ): string => (string) $p->get_price() );
		Functions\when( 'get_post_meta' )->alias( static fn ( int $id, string $key ): string => 999 === $id && \Akibara\Business\Services\VolumeVariant::META_KEY === $key ? \Akibara\Business\Services\VolumeVariant::KIND_PORTADA_ALTERNATIVA : '' );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_user_meta' )->justReturn( 'gen-1' );
		Functions\when( 'get_transient' )->justReturn( array( 'version' => 3, 'status' => 'ok', 'product_ids' => array( 102 ) ) );

		$result = ( new SeriesBatchAtc() )->edition_products();

		self::assertCount( 110, $result );
		self::assertSame( 101, $result[0]['id'] );
		self::assertSame( 102, $result[1]['id'] );
		self::assertTrue( $result[1]['owned'], 'Bought volume 2 is marked owned.' );
		self::assertSame( 999, $result[2]['id'] );
		self::assertTrue( $result[2]['variant'] );
		self::assertTrue( $result[2]['owned'], 'Variant of owned volume in same edition is also owned.' );
		self::assertFalse( $result[0]['owned'] );
		self::assertSame( 209, $result[109]['id'] );
		self::assertSame( array( 'id', 'volume', 'variant', 'price', 'stock_status', 'purchasable', 'type', 'owned' ), array_keys( $result[0] ) );
	}

	/** @testdox Card checkbox renders owned attribute and badge for verified customer purchases. */
	public function test_render_card_checkbox_marks_owned_purchases(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		$this->mock_series_term();
		$GLOBALS['product'] = $this->make_product( 501, 'Chainsaw Man 01', '9990' );

		Functions\when( 'wc_get_price_to_display' )->justReturn( 9990 );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_user_meta' )->justReturn( 'gen-1' );
		Functions\when( 'get_transient' )->justReturn( array( 'version' => 3, 'status' => 'ok', 'product_ids' => array( 501 ) ) );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_the_title' )->justReturn( 'Chainsaw Man 01' );

		try {
			ob_start();
			( new SeriesBatchAtc() )->render_card_checkbox();
			$html = (string) ob_get_clean();
			self::assertStringContainsString( 'data-owned="1"', $html );
			self::assertStringContainsString( 'data-akb-owned-badge="1"', $html );
			self::assertStringContainsString( '✓ Comprado', $html );
		} finally {
			unset( $GLOBALS['product'] );
		}
	}

	/** @testdox Public card selection uses the same strict availability as the batch endpoint. */
	public function test_backorders_do_not_expose_a_checkbox_the_server_would_reject(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		$this->mock_series_term();
		$GLOBALS['product'] = new class() extends \WC_Product {
			public function is_purchasable(): bool { return true; }
			public function is_in_stock(): bool { return true; }
			public function get_stock_status(): string { return 'onbackorder'; }
		};
		try {
			ob_start();
			( new SeriesBatchAtc() )->render_card_checkbox();
			self::assertSame( '', ob_get_clean() );
		} finally {
			unset( $GLOBALS['product'] );
		}
	}

	/** @testdox Products outside the batch contract retain their native purchase link. */
	public function test_native_cta_survives_for_backorders_variable_and_nonpurchasable_products(): void {
		Functions\when( 'is_tax' )->justReturn( true );
		$this->mock_series_term();
		$variable = new \WC_Product( 'instock' );
		$variable->akb_type = 'variable';
		$draft = new \WC_Product( 'instock' );
		$draft->akb_status = 'draft';
		$products = array( new \WC_Product( 'onbackorder' ), new \WC_Product( 'instock', array(), false ), $variable, $draft );
		$module = new SeriesBatchAtc();
		try {
			foreach ( $products as $product ) {
				$GLOBALS['product'] = $product;
				self::assertSame( '<a>Compra nativa</a>', $module->filter_loop_add_to_cart_link( '<a>Compra nativa</a>', $product ) );
				ob_start();
				$module->render_card_checkbox();
				self::assertSame( '', ob_get_clean() );
			}
		} finally {
			unset( $GLOBALS['product'] );
		}
	}

	/**
	 * @testdox is_active_series_landing solo devuelve true cuando es serie multitomo.
	 */
	public function test_is_active_series_landing_gates_to_pa_serie_taxonomy(): void {
		$module = new SeriesBatchAtc();

		Functions\when( 'is_tax' )->alias(
			static fn( $tax = '' ): bool => 'pa_serie' === $tax
		);

		$this->mock_series_term( 11 );
		self::assertTrue( $module->is_active_series_landing() );

		// Tomo único (count 1) no activa batch
		$this->mock_series_term( 1 );
		self::assertFalse( $module->is_active_series_landing() );
	}

	/**
	 * @testdox render_action_bar keeps a compact purchase summary without a duplicated benefit promise.
	 */
	public function test_render_action_bar_outputs_a_compact_hidden_purchase_summary(): void {
		$module = new SeriesBatchAtc();

		Functions\when( 'is_tax' )->alias(
			static fn( $tax = '' ): bool => 'pa_serie' === $tax
		);
		$this->mock_series_term( 11 );

		Functions\when( 'get_option' )->alias(
			static fn( string $opt, $default = 60000 ) => 60000
		);

		ob_start();
		$module->render_action_bar();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'id="akb-series-action-bar"', $html );
		self::assertStringContainsString( 'data-akb-series-action-bar', $html );
		self::assertStringContainsString( 'hidden', $html );
		self::assertStringContainsString( 'data-akb-action-bar-total', $html );
		self::assertStringNotContainsString( 'desbloqueado', $html );
		self::assertStringContainsString( 'data-akb-batch-submit', $html );
		self::assertStringNotContainsString( 'data-akb-action-bar-rewards', $html );
	}

	/**
	 * @testdox render_quick_select_toolbar imprime el botón de seleccionar disponibles.
	 */
	public function test_render_quick_select_toolbar_outputs_select_buttons(): void {
		$module = new SeriesBatchAtc();

		Functions\when( 'is_tax' )->alias(
			static fn( $tax = '' ): bool => 'pa_serie' === $tax
		);
		$this->mock_series_term( 11 );

		ob_start();
		$module->render_quick_select_toolbar();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-akb-series-select-toolbar', $html );
		self::assertStringContainsString( 'data-akb-select-all', $html );
		self::assertStringContainsString( 'data-akb-clear-selection', $html );
		self::assertStringNotContainsString( 'data-akb-range-selector', $html, 'En serie <= 20 tomos, no se renderiza selector de tramo.' );

		// Serie extensa (> 20 tomos, ej. One Piece con 109)
		$this->mock_series_term( 25 );
		ob_start();
		$module->render_quick_select_toolbar();
		$large_html = (string) ob_get_clean();

		self::assertStringContainsString( 'data-akb-range-selector', $large_html );
		self::assertStringContainsString( 'data-akb-range-from', $large_html );
		self::assertStringContainsString( 'data-akb-range-to', $large_html );
		self::assertStringContainsString( 'data-akb-range-apply', $large_html );
	}

	/**
	 * @testdox is_large_series detecta series con más de 20 tomos por conteo o metadatos de obra/edición.
	 */
	public function test_is_large_series_detects_extensive_series(): void {
		$module = new SeriesBatchAtc();

		Functions\when( 'is_tax' )->alias(
			static fn( $tax = '' ): bool => 'pa_serie' === $tax
		);

		// Serie regular (11 tomos) -> False
		$this->mock_series_term( 11 );
		self::assertFalse( $module->is_large_series() );

		// Serie extensa por conteo de catálogo (109 tomos como One Piece) -> True
		$this->mock_series_term( 109 );
		self::assertTrue( $module->is_large_series() );

		// Serie con pocos tomos en catálogo (10) pero edición supera 20 -> True
		$this->mock_series_term( 10, 24 );
		self::assertTrue( $module->is_large_series() );
	}

	/**
	 * @testdox filter_loop_add_to_cart_link suprime botón individual en series multitomo disponibles pero preserva en tomos únicos o agotados.
	 */
	public function test_filter_loop_add_to_cart_link_suppresses_individual_button_on_multi_volume(): void {
		$module = new SeriesBatchAtc();

		Functions\when( 'is_tax' )->alias(
			static fn( $tax = '' ): bool => 'pa_serie' === $tax
		);

		$product_instock = new \WC_Product( 'instock' );

		$product_outofstock = new \WC_Product( 'outofstock' );

		$dummy_html = '<a href="?add-to-cart=1" class="button">Añadir al carrito</a>';
		$notify_html = '<a href="#bis" class="button akb-notify-link">Avísame cuando vuelva</a>';

		// Caso A: Serie multitomo (11 tomos)
		$this->mock_series_term( 11 );
		self::assertSame( '', $module->filter_loop_add_to_cart_link( $dummy_html, $product_instock ), 'En tomo disponible multitomo, se suprime el botón individual.' );
		self::assertSame( $notify_html, $module->filter_loop_add_to_cart_link( $notify_html, $product_outofstock ), 'En tomo agotado multitomo, se conserva el botón de aviso.' );

		// Caso B: Tomo único (1 tomo)
		$this->mock_series_term( 1 );
		self::assertSame( $dummy_html, $module->filter_loop_add_to_cart_link( $dummy_html, $product_instock ), 'En tomo único, se conserva el botón individual.' );
	}
}
