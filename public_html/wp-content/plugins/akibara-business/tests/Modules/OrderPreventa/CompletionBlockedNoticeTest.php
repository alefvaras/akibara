<?php
/**
 * Contrato del aviso de «no se pudo completar la preventa».
 *
 * `CompletionPolicy` revierte la orden y deja una nota en el pedido, pero en
 * pantalla no ocurría nada: el dueño pulsaba «Completado», la lista se recargaba
 * y el pedido seguía igual, sin distinguir un bloqueo intencional de un fallo.
 *
 * Lo que fija este test:
 *   1. el aviso nombra el pedido Y los productos que faltan;
 *   2. se consume una sola vez (no persigue al dueño por todo el admin);
 *   3. cron y WP-CLI no anotan nada — no hay a quién avisarle en pantalla;
 *   4. una acción en lote no infla la meta del usuario sin tope.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\CompletionBlockedNotice;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.11
 */
final class CompletionBlockedNoticeTest extends TestCase {

	/** @var array<string, mixed> */
	private array $userMeta = array();

	private int $currentUser = 1;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->userMeta    = array();
		$this->currentUser = 1;

		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'get_current_user_id' )->alias( fn(): int => $this->currentUser );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_user_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->userMeta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'update_user_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->userMeta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->userMeta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $n ) => 1 === $n ? $single : $plural
		);
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, string> $itemNames Productos pendientes.
	 */
	private function order( int $id, array $itemNames ): \WC_Order {
		$order             = new \WC_Order();
		$order->akb_id     = $id;
		$order->akb_status = 'processing';
		$order->akb_meta   = array(
			MetaKeys::ORDER_HAS_PREVENTA   => 'yes',
			MetaKeys::ORDER_READY_TO_SHIP  => 'no',
		);

		$item_id = 1;
		foreach ( $itemNames as $name ) {
			$item               = new \WC_Order_Item_Product();
			$item->akb_id       = $item_id;
			$item->akb_name     = $name;
			$item->akb_quantity = 1;
			$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );
			$order->akb_items[] = $item;
			$order->akb_refunded[ $item_id ] = 0;
			++$item_id;
		}

		return $order;
	}

	private function render( CompletionBlockedNotice $notice ): string {
		ob_start();
		$notice->render();

		return (string) ob_get_clean();
	}

	public function test_notice_names_the_order_and_the_missing_products(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 27722, array( 'Blue Lock 37', 'Chainsaw Man 23' ) );

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$notice->record( 27722, 'processing', $order );
		$html = $this->render( $notice );

		self::assertStringContainsString( '27722', $html );
		self::assertStringContainsString( 'Blue Lock 37', $html );
		self::assertStringContainsString( 'Chainsaw Man 23', $html );
		self::assertStringContainsString( 'notice-warning', $html );
	}

	public function test_notice_lists_only_what_is_still_missing(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 27722, array( 'Blue Lock 37', 'Chainsaw Man 23' ) );
		$order->akb_items[0]->akb_product = new \WC_Product( 'instock' ); // Blue Lock ya está en bodega
		$order->akb_items[0]->akb_meta[ MetaKeys::ITEM_ARRIVED ]     = 'yes';
		$order->akb_items[0]->akb_meta[ MetaKeys::ITEM_ARRIVED_QTY ] = '1';

		Functions\when( 'wc_get_order' )->justReturn( $order );

		$notice->record( 27722, 'processing', $order );
		$html = $this->render( $notice );

		self::assertStringContainsString( 'Chainsaw Man 23', $html );
		self::assertStringNotContainsString( 'Blue Lock 37', $html, 'El aviso listaba como «falta por llegar» el tomo que ya estaba en la estantería.' );
	}

	public function test_notice_is_consumed_once(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 27722, array( 'Blue Lock 37' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$notice->record( 27722, 'processing', $order );

		self::assertNotSame( '', trim( $this->render( $notice ) ) );
		self::assertSame( '', trim( $this->render( $notice ) ), 'El aviso no puede repetirse en cada pantalla del admin.' );
	}

	public function test_cron_and_cli_do_not_record_anything(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 27722, array( 'Blue Lock 37' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->currentUser = 0; // cron / WP-CLI
		$notice->record( 27722, 'processing', $order );

		self::assertSame( array(), $this->userMeta, 'Sin usuario no hay a quién avisarle: no se persiste nada.' );
	}

	public function test_bulk_action_does_not_grow_the_meta_without_bound(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 1, array( 'Tomo' ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		for ( $i = 1; $i <= 40; $i++ ) {
			$notice->record( $i, 'processing', $order );
		}

		self::assertCount(
			20,
			$this->userMeta[1]['_akb_preventa_completion_blocked'],
			'Una acción en lote de 40 pedidos no puede dejar 40 entradas en la meta del usuario.'
		);
	}

	public function test_order_without_outstanding_items_still_gets_a_generic_line(): void {
		$notice = new CompletionBlockedNotice();
		$order  = $this->order( 27722, array() );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$notice->record( 27722, 'processing', $order );
		$html = $this->render( $notice );

		self::assertStringContainsString( '27722', $html );
		self::assertStringContainsString( 'sin llegar', $html );
	}
}
