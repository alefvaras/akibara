<?php
/**
 * Contrato del VALIDADOR ÚNICO de filas de política (blueprint de paneles
 * 2026-08-31): la pantalla del término y la tabla de cortes del panel llaman
 * a la misma función — dos pantallas, un validador. Estas pruebas fijan las
 * reglas y los MENSAJES históricos, incluida la asimetría deliberada
 * (HEREDADO borra las 4 metas; CONTINUO/PAUSADA solo escriben el modo).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.110.0
 */
final class PedidoPolicySanitizeRowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) )
		);
		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_heredado_deletes_the_six_metas(): void {
		$res = PedidoPolicy::sanitize_row( array( 'modo' => 'heredado' ) );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array(), $res['set'] );
		$this->assertSame(
			array( PedidoPolicy::META_MODO, PedidoPolicy::META_DIA, PedidoPolicy::META_MIN, PedidoPolicy::META_MAX, PedidoPolicy::META_FRECUENCIA, PedidoPolicy::META_MESES ),
			$res['delete']
		);
	}

	public function test_an_unknown_mode_falls_back_to_heredado_semantics(): void {
		$res = PedidoPolicy::sanitize_row( array( 'modo' => 'invento<script>' ) );

		$this->assertTrue( $res['ok'] );
		$this->assertNotSame( array(), $res['delete'], 'Modo basura = heredar, jamás persistir basura.' );
	}

	public function test_a_valid_tanda_sets_all_five_metas(): void {
		$res = PedidoPolicy::sanitize_row( array( 'modo' => 'tanda', 'dia' => '5', 'min' => '25', 'max' => '35' ) );

		$this->assertTrue( $res['ok'] );
		$this->assertSame( array(), $res['delete'] );
		$this->assertSame( 5, $res['set'][ PedidoPolicy::META_DIA ] );
		$this->assertSame( 25, $res['set'][ PedidoPolicy::META_MIN ] );
		$this->assertSame( 35, $res['set'][ PedidoPolicy::META_MAX ] );
		$this->assertSame( PedidoPolicy::FRECUENCIA_MENSUAL, $res['set'][ PedidoPolicy::META_FRECUENCIA ] );
		$this->assertSame( 'tanda', $res['set'][ PedidoPolicy::META_MODO ] );
	}

	public function test_a_half_tanda_is_rejected_whole_with_the_historic_message(): void {
		// Día 31 no existe en febrero: mensaje EXACTO del term-edit histórico.
		$res = PedidoPolicy::sanitize_row( array( 'modo' => 'tanda', 'dia' => '31', 'min' => '25', 'max' => '35' ) );

		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'exista también en febrero', $res['error'] );
		$this->assertSame( array(), $res['set'], 'Una tanda a medias no escribe NADA.' );
		$this->assertSame( array(), $res['delete'], 'Y tampoco borra lo guardado.' );
	}

	public function test_min_above_max_is_rejected_with_the_plazos_message(): void {
		$res = PedidoPolicy::sanitize_row( array( 'modo' => 'tanda', 'dia' => '5', 'min' => '40', 'max' => '35' ) );

		$this->assertFalse( $res['ok'] );
		$this->assertStringContainsString( 'no puede superar al máximo', $res['error'] );
	}

	public function test_continuo_and_pausada_write_only_the_mode(): void {
		foreach ( array( 'continuo', 'pausada' ) as $modo ) {
			$res = PedidoPolicy::sanitize_row( array( 'modo' => $modo, 'dia' => '9', 'min' => '1', 'max' => '2' ) );

			$this->assertTrue( $res['ok'] );
			$this->assertSame( array( PedidoPolicy::META_MODO => $modo ), $res['set'], "Asimetría histórica intacta para {$modo}." );
			$this->assertSame( array(), $res['delete'] );
		}
	}
}
