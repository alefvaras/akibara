<?php
/**
 * Contrato de la bitácora: los eventos críticos de DINERO van al registro de
 * WooCommerce (pantalla propia, retención, filtro por origen), no a un
 * error_log que hay que abrir por SSH.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Support;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Log;

/**
 * @since 0.3.1
 */
final class LogTest extends RewardsTestCase {

	public function test_a_rejected_movement_reaches_the_woocommerce_log_with_our_source(): void {
		$this->wpdb->lock_result = '0'; // Candado ocupado: el movimiento se rechaza.

		$this->assertNull( Ledger::credit( 7, 100, 'order', 'order', 1 ), 'Sin candado, infraestructura (null).' );

		$this->assertCount( 1, $this->logger->entries, 'Un movimiento rechazado no puede quedar mudo.' );
		$this->assertSame( 'error', $this->logger->entries[0]['level'] );
		$this->assertSame( Log::SOURCE, $this->logger->entries[0]['context']['source'] ?? '', 'El origen es lo que filtra la pantalla de Woo.' );
		$this->assertStringContainsString( 'Candado akb_rewards_user_7', $this->logger->entries[0]['message'] );
	}

	public function test_a_failed_ledger_insert_is_logged_as_lost_audit(): void {
		$this->wpdb->fail_insert = true;

		$this->assertNull( Ledger::credit( 7, 100, 'order', 'order', 1 ) );

		$this->assertCount( 1, $this->logger->entries );
		$this->assertStringContainsString( 'INSERT del ledger falló para user 7', $this->logger->entries[0]['message'] );
		$this->assertSame( Log::SOURCE, $this->logger->entries[0]['context']['source'] ?? '' );
	}

	public function test_the_message_carries_no_prefix_of_its_own(): void {
		// El origen ya lo pone el contexto: repetir «[akibara-rewards]» en el
		// texto ensuciaba cada línea de la pantalla de registros.
		Log::error( 'Mensaje suelto.' );

		$this->assertSame( 'Mensaje suelto.', $this->logger->entries[0]['message'] );
	}
}
