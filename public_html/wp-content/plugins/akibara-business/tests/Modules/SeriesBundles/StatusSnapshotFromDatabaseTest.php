<?php
/**
 * `status_snapshot_from_database()` — la tarjeta «Estado» de «Arma tu Pack»
 * tiene que decir lo que hay ESCRITO, no lo que dice una lectura por caché
 * que puede ir por detrás justo después de guardar (R4-198b3376, 2026-09-19:
 * `AdminSection::render_header()` no tenía ningún equivalente al bypass que
 * `TaxonomyDiscount` ya usa para su propia tarjeta de estado).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Tests\Support\OptionsFromDatabaseWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class StatusSnapshotFromDatabaseTest extends TestCase {

	/** @var mixed */
	private $wpdb_previo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_previo = $GLOBALS['wpdb'] ?? null;

		// `get_option()` simula el blob rancio: SIEMPRE el valor VIEJO (todo
		// apagado, cero series, cero excluidas), para que cualquier lectura que
		// caiga en `get_option()` se note en la prueba.
		Functions\when( 'get_option' )->alias(
			static fn( string $o, $d = false ) => array(
				Settings::OPTION_ENABLED         => 'no',
				Settings::OPTION_BYP             => '{}',
				Settings::OPTION_EXCLUDE_CATS    => array(),
				Settings::OPTION_ALLOW_BACKORDER => 'no',
			)[ $o ] ?? $d
		);
		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $valor ) {
				if ( is_string( $valor ) && 1 === preg_match( '/^a:\d+:\{/', $valor ) ) {
					return unserialize( $valor ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- doble de prueba.
				}
				return $valor;
			}
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

	/** @testdox Las cuatro piezas salen de la base recién guardada, no del blob rancio de get_option(). */
	public function test_las_cuatro_piezas_salen_de_la_base(): void {
		$doble        = new OptionsFromDatabaseWpdb();
		$doble->filas = array(
			Settings::OPTION_ENABLED         => 'yes',
			Settings::OPTION_BYP             => wp_json_encode(
				array(
					'12' => array( 'enabled' => true, 'tiers' => array() ),
					'45' => array( 'enabled' => false ),
					'99' => array( 'enabled' => true ),
				)
			),
			Settings::OPTION_EXCLUDE_CATS    => serialize( array( 7, 9 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- modela lo que WordPress escribe de verdad.
			Settings::OPTION_ALLOW_BACKORDER => 'yes',
		);
		$GLOBALS['wpdb'] = $doble;

		$estado = ( new Settings() )->status_snapshot_from_database();

		self::assertSame(
			array(
				'enabled'          => true,
				'enabled_count'    => 2,
				'excluded_count'   => 2,
				'allows_backorder' => true,
			),
			$estado
		);
	}

	/** @testdox Categorías excluidas en formato CSV legacy también se leen crudas. */
	public function test_lee_el_csv_legacy_de_categorias_excluidas(): void {
		$doble           = new OptionsFromDatabaseWpdb();
		$doble->filas    = array( Settings::OPTION_EXCLUDE_CATS => '12,45,99' );
		$GLOBALS['wpdb'] = $doble;

		$estado = ( new Settings() )->status_snapshot_from_database();

		self::assertSame( 3, $estado['excluded_count'] );
	}

	/** @testdox Sin $wpdb disponible, cae a los getters tolerantes (mejor eso que romper la pantalla). */
	public function test_sin_wpdb_cae_a_los_getters(): void {
		unset( $GLOBALS['wpdb'] );

		$estado = ( new Settings() )->status_snapshot_from_database();

		self::assertFalse( $estado['enabled'] );
		self::assertSame( 0, $estado['enabled_count'] );
		self::assertSame( 0, $estado['excluded_count'] );
		self::assertFalse( $estado['allows_backorder'] );
	}
}
