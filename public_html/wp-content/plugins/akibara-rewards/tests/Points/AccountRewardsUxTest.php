<?php
/** Resultados del HTML y consultas privadas de la cuenta de puntos. */
declare( strict_types = 1 );
namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\AccountRewards;
use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class AccountRewardsUxTest extends RewardsTestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '$' . number_format( $n, 0, ',', '.' ) );
		Functions\when( 'wp_nonce_field' )->justReturn( '<input type="hidden" name="_akbnonce" value="nonce">' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wc_get_price_decimal_separator' )->justReturn( ',' );
		Functions\when( 'wc_get_price_thousand_separator' )->justReturn( '.' );
		Functions\when( 'get_woocommerce_price_format' )->justReturn( '%1$s%2$s' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_date' )->alias( static fn( $f, $t, $tz ) => ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( $f ) );
		Functions\when( 'date_i18n' )->alias( static fn( $f, $t ) => gmdate( $f, $t ) );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();
		$this->install_fake_wc();
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://akibara.test/carrito/' );
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new AccountRewards() )->render();
		return (string) ob_get_clean();
	}

	/** @testdox Preview del servidor, mínimos y máximo no mutan saldo ni sesión. */
	public function test_rendered_redemption_and_dated_expiry(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'akb_rewards_redeem_value' === $key ? 2 : $default );
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 300;
		$this->wpdb->insert( 'wp_akb_rewards_ledger', array( 'user_id' => 7, 'delta' => 300, 'created_at' => '2024-01-31 12:00:00', 'action' => 'order', 'note' => 'Compra' ) );
		$GLOBALS['akb_rewards_test_wc']->cart = new class() extends \WC_Cart {
			public ?float $recalculated_net = null;
			public function calculate_totals(): void {
				if ( null !== $this->recalculated_net ) {
					$this->contents[0]['line_total'] = $this->recalculated_net;
				}
			}
		};
		$GLOBALS['akb_rewards_test_wc']->cart->contents = array( array( 'line_total' => 50000.0, 'line_tax' => 0.0 ) );
		$before = $this->wpdb->inserts;
		$html = $this->render();
		self::assertStringContainsString( '100 puntos: $200 de descuento aplicable', $html );
		self::assertStringContainsString( 'min="100" max="300"', $html );
		self::assertStringContainsString( 'type="button" class="button akb-rw-max" hidden', $html );
		self::assertStringContainsString( 'Tope por compra:', $html );
		self::assertStringContainsString( 'aria-describedby="akb-rw-preview akb-rw-rules"', $html );
		self::assertStringContainsString( 'datetime="2025-01-31"', $html );
		self::assertStringContainsString( '300 puntos pueden vencer desde el', $html );
		self::assertStringContainsString( 'pendiente de procesarse', $html );
		self::assertSame( $before, $this->wpdb->inserts );
		self::assertSame( 300, Ledger::balance( 7 ) );
	}

	/** @testdox El HTML muestra el máximo del carrito y bloquea vacío o compra bajo el mínimo sin mutar intención. */
	public function test_current_cart_preview_and_disabled_controls(): void {
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		\Akibara\Rewards\Points\Redemption::set_intent( 1000 );
		$session = WC()->session->data;
		$html = $this->render();
		self::assertStringContainsString( 'Equivalencia potencial: $20.000', $html );
		self::assertStringContainsString( 'Descuento aplicable en tu carrito actual: $0.', $html );
		self::assertStringContainsString( 'Añade productos al carrito', $html );
		self::assertStringContainsString( 'hidden disabled>Usar el máximo disponible', $html );
		self::assertStringContainsString( 'akb-rw-redeem-submit" disabled', $html );
		self::assertStringContainsString( 'Quitar canje', $html );
		self::assertSame( $session, WC()->session->data );

		WC()->cart = new class() extends \WC_Cart {
			public ?float $recalculated_net = null;
			public function calculate_totals(): void {
				if ( null !== $this->recalculated_net ) {
					$this->contents[0]['line_total'] = $this->recalculated_net;
				}
			}
		};
		WC()->cart->contents = array( array( 'line_total' => 49999.0, 'line_tax' => 0.0 ) );
		$html = $this->render();
		self::assertStringContainsString( 'Te faltan $1 en productos', $html );
		self::assertStringContainsString( 'akb-rw-redeem-submit" disabled', $html );
		self::assertStringContainsString( 'Descuento aplicable en tu carrito actual: $0.', $html );
		self::assertSame( $session, WC()->session->data );

		WC()->cart->contents[0]['line_total'] = 50000.0;
		$html = $this->render();
		self::assertStringContainsString( 'min="100" max="5000"', $html );
		self::assertStringContainsString( '1.000 puntos: $1.000 de descuento aplicable', $html );
		self::assertStringContainsString( 'Máximo aplicable en este carrito: 5.000 puntos.', $html );
		self::assertStringNotContainsString( 'akb-rw-redeem-submit" disabled', $html );
		self::assertSame( $session, WC()->session->data );
	}

	/** @testdox Abrir la cuenta recalcula un snapshot50k que ha caído a49999 y no crea ni cambia el canje. */
	public function test_get_recalculates_stale_cart_before_preview(): void {
		$this->wpdb->user_meta[7][ Ledger::META_BALANCE ] = 20000;
		WC()->cart = new class() extends \WC_Cart {
			public function calculate_totals(): void {
				$this->contents[0]['line_total'] = 49999.0;
			}
		};
		WC()->cart->contents = array( array( 'line_total' => 50000.0, 'line_tax' => 0.0 ) );
		\Akibara\Rewards\Points\Redemption::set_intent( 1000 );
		$session = WC()->session->data;
		$html = $this->render();
		self::assertStringContainsString( 'Descuento aplicable en tu carrito actual: $0.', $html );
		self::assertStringContainsString( 'Te faltan $1 en productos', $html );
		self::assertStringContainsString( 'akb-rw-redeem-submit" disabled', $html );
		self::assertSame( $session, WC()->session->data );
		self::assertSame( 20000, Ledger::balance( 7 ) );
		self::assertCount( 0, $this->wpdb->inserts );
	}

	/** @testdox Historial privado y estable recorre las páginas sin saltar fechas iguales. */
	public function test_history_pages_and_private_queries(): void {
		for ( $i = 1; $i <= 21; ++$i ) {
			$this->wpdb->insert( 'wp_akb_rewards_ledger', array( 'user_id' => 7, 'delta' => $i % 2 ? 1 : -1, 'created_at' => '2025-01-01 12:00:00', 'action' => 'order', 'note' => 'Movimiento ' . $i ) );
		}
		$this->wpdb->insert( 'wp_akb_rewards_ledger', array( 'user_id' => 8, 'delta' => 1, 'created_at' => '2025-01-01 12:00:00', 'action' => 'order', 'note' => 'PRIVADO AJENO' ) );
		$seen = array();
		foreach ( array( 1, 2, 3 ) as $page ) {
			$_GET['akb_rw_page'] = (string) $page;
			$html = $this->render();
			preg_match_all( '/Movimiento (\d+)</', $html, $matches );
			self::assertCount( 3 === $page ? 1 : 10, $matches[1] );
			$seen = array_merge( $seen, array_map( 'intval', $matches[1] ) );
			self::assertStringNotContainsString( 'PRIVADO AJENO', $html );
			self::assertStringContainsString( 'Página ' . $page . ' de 3', $html );
			self::assertSame( $page < 3, str_contains( $html, 'rel="next"' ) );
			self::assertSame( $page > 1, str_contains( $html, 'rel="prev"' ) );
		}
		self::assertSame( range( 21, 1 ), $seen );
		foreach ( array_merge( $this->wpdb->var_log, $this->wpdb->results_log ) as $sql ) {
			self::assertStringContainsString( 'user_id = 7', $sql );
		}
	}

	/** @testdox Parámetros inválidos y enormes se acotan antes del OFFSET. */
	public function test_untrusted_page_and_empty_history(): void {
		self::assertStringContainsString( 'Todavía no tienes movimientos de puntos.', $this->render() );
		$this->wpdb->insert( 'wp_akb_rewards_ledger', array( 'user_id' => 7, 'delta' => 1, 'created_at' => '2025-01-01 12:00:00', 'action' => 'order', 'note' => 'Único' ) );
		foreach ( array( '0', '-1', 'abc', '1.5', '999999999999999999999999999999', array( '2' ) ) as $value ) {
			$_GET['akb_rw_page'] = $value;
			self::assertStringContainsString( 'Único', $this->render() );
			self::assertStringContainsString( 'LIMIT 10 OFFSET 0', end( $this->wpdb->results_log ) );
		}
	}

	/** @testdox Invitados no consultan historial ni cargan ayuda privada. */
	public function test_guest_and_asset_scope(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		self::assertSame( '', $this->render() );
		self::assertCount( 0, $this->wpdb->var_log );
		$account = new AccountRewards();
		$account->enqueue_assets();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		$account->enqueue_assets();
		Functions\when( 'is_wc_endpoint_url' )->justReturn( true );
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();
		$account->enqueue_assets();
	}
}
