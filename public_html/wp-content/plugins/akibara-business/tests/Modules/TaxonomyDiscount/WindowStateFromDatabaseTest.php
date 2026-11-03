<?php
/**
 * `window_state_from_database()` tiene que devolver lo que hay ESCRITO, no lo
 * que dice una lectura por caché que puede ir por detrás justo después de
 * guardar (R4-83d4b7c3, 2026-09-19: antes esto sólo cubría from/to/enabled y
 * dejaba sale_policy/apply_to/stacks_coupons cayendo en `get_option()`).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Tests\Support\OptionsFromDatabaseWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class WindowStateFromDatabaseTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;

		// `get_option()` simula el blob rancio: SIEMPRE el valor VIEJO, para
		// que cualquier lectura que caiga en `get_option()` se note en la prueba.
		Functions\when( 'get_option' )->alias(
			static fn( string $o, $d = false ) => array(
				Settings::OPTION_ENABLED        => 'no',
				Settings::OPTION_FROM           => '',
				Settings::OPTION_TO             => '',
				Settings::OPTION_SALE_POLICY    => Settings::POLICY_SKIP_IF_ON_SALE,
				Settings::OPTION_APPLY_TO       => Settings::APPLY_INSTOCK_ONLY,
				Settings::OPTION_STACKS_COUPONS => 'no',
			)[ $o ] ?? $d
		);
	}

	protected function tearDown(): void {
		if ( null === $this->wpdb_previo ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->wpdb_previo;
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Las SEIS piezas salen de la base recién guardada, no del blob rancio de get_option(). */
	public function test_las_seis_piezas_salen_de_la_base(): void {
		$doble        = new OptionsFromDatabaseWpdb();
		$doble->filas = array(
			Settings::OPTION_ENABLED        => 'yes',
			Settings::OPTION_FROM           => '2026-09-01',
			Settings::OPTION_TO             => '2026-12-31',
			Settings::OPTION_SALE_POLICY    => Settings::POLICY_GREATEST_WINS,
			Settings::OPTION_APPLY_TO       => Settings::APPLY_ALL,
			Settings::OPTION_STACKS_COUPONS => 'yes',
		);
		$GLOBALS['wpdb'] = $doble;

		$estado = ( new Settings() )->window_state_from_database();

		self::assertSame(
			array(
				'from'           => '2026-09-01',
				'to'             => '2026-12-31',
				'enabled'        => 'yes',
				'sale_policy'    => Settings::POLICY_GREATEST_WINS,
				'apply_to'       => Settings::APPLY_ALL,
				'stacks_coupons' => true,
			),
			$estado
		);
	}

	/** @testdox Una fila con un valor fuera del dominio cerrado no se pinta tal cual: cae al default seguro. */
	public function test_un_valor_corrupto_cae_al_default(): void {
		$doble        = new OptionsFromDatabaseWpdb();
		$doble->filas = array(
			Settings::OPTION_SALE_POLICY => 'algo-que-nadie-guardo-desde-el-panel',
			Settings::OPTION_APPLY_TO    => 'tampoco-esto',
		);
		$GLOBALS['wpdb'] = $doble;

		$estado = ( new Settings() )->window_state_from_database();

		self::assertSame( Settings::POLICY_SKIP_IF_ON_SALE, $estado['sale_policy'] );
		self::assertSame( Settings::APPLY_INSTOCK_ONLY, $estado['apply_to'] );
	}

	/** @testdox Sin $wpdb disponible, cae a los getters tolerantes (mejor eso que romper la pantalla). */
	public function test_sin_wpdb_cae_a_los_getters(): void {
		unset( $GLOBALS['wpdb'] );

		$estado = ( new Settings() )->window_state_from_database();

		self::assertSame( 'no', $estado['enabled'] );
		self::assertSame( Settings::POLICY_SKIP_IF_ON_SALE, $estado['sale_policy'] );
		self::assertSame( Settings::APPLY_INSTOCK_ONLY, $estado['apply_to'] );
		self::assertFalse( $estado['stacks_coupons'] );
	}
}
