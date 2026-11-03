<?php
/**
 * Contrato del plazo PERSONALIZADO por ficha (pedido del dueño, 2026-08-30):
 * «cada preventa va a ser diferente — dos meses en algunos casos, tres en
 * otros». El plazo vive en la ficha, solo aplica a preventas, y su precedencia
 * es ficha > tanda de la editorial > país.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.109.0
 */
final class DateCalculatorManualLeadTest extends TestCase {

	private const TZ = 'America/Santiago';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( self::TZ )
		);
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => $default_value
		);
		Functions\when( 'get_term_by' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function producto(): \WC_Product {
		return new class( StockStatus::ON_BACKORDER ) extends \WC_Product {
			public function get_id(): int {
				return 5151;
			}
		};
	}

	/**
	 * @param array<string,string> $metas   Postmeta simulada de la ficha.
	 * @param array<string,mixed>  $marca   Term-meta de la editorial.
	 */
	private function stub_datos( array $metas, array $marca = array() ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) use ( $metas ) {
				unset( $post_id, $single );
				return $metas[ $key ] ?? '';
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) {
				unset( $post_id );
				if ( PedidoPolicy::TAXONOMY === $taxonomy ) {
					return array( (object) array( 'term_id' => 99, 'name' => 'Ivrea España' ) );
				}
				return false; // Sin pa_pais a propósito: el manual no lo necesita.
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = true ) use ( $marca ) {
				unset( $term_id, $single );
				return $marca[ $key ] ?? '';
			}
		);
	}

	private function calculador( string $hoy ): DateCalculator {
		$tz = new \DateTimeZone( self::TZ );

		return new DateCalculator(
			new Settings(),
			static fn(): int => ( new \DateTimeImmutable( $hoy, $tz ) )->getTimestamp(),
			$tz,
			new PedidoPolicy()
		);
	}

	private function fecha( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( self::TZ ) )->format( 'Y-m-d' );
	}

	/**
	 * El caso que motivó la feature: preventa con fecha y «unos 2 meses» de
	 * plazo escrito a mano — sin `pa_pais` configurado, que antes dejaba el
	 * banner MUDO.
	 */
	public function test_manual_lead_runs_from_the_release_date_in_calendar_days(): void {
		$this->stub_datos(
			array(
				DateCalculator::META_TYPE            => 'release_date',
				DateCalculator::META_RELEASE_DATE    => '2026-10-01',
				DateCalculator::META_MANUAL_MIN_DIAS => '60',
				DateCalculator::META_MANUAL_MAX_DIAS => '75',
			)
		);

		$eta = $this->calculador( '2026-08-30 12:00:00' )->calculate( $this->producto() );

		self::assertNotNull( $eta );
		self::assertFalse( $eta->is_tba() );
		self::assertSame( '2026-11-30', $this->fecha( (int) $eta->min_ts() ) );
		self::assertSame( '2026-12-15', $this->fecha( (int) $eta->max_ts() ) );
	}

	/**
	 * Precedencia ficha > tanda: si el dueño escribió un plazo en ESTA ficha,
	 * esa es la promesa — no el corte general de la editorial.
	 */
	public function test_manual_lead_beats_the_publisher_batch(): void {
		$this->stub_datos(
			array(
				DateCalculator::META_TYPE            => 'release_date',
				DateCalculator::META_RELEASE_DATE    => '2026-10-01',
				DateCalculator::META_MANUAL_MIN_DIAS => '30',
				DateCalculator::META_MANUAL_MAX_DIAS => '40',
			),
			array(
				PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
				PedidoPolicy::META_DIA  => 5,
				PedidoPolicy::META_MIN  => 25,
				PedidoPolicy::META_MAX  => 35,
			)
		);

		$eta = $this->calculador( '2026-08-30 12:00:00' )->calculate( $this->producto() );

		self::assertNotNull( $eta );
		// Desde el lanzamiento (01-oct) + 30/40 corridos — NO desde el cierre
		// de la tanda del 5 (que daría 30-oct/09-nov).
		self::assertSame( '2026-10-31', $this->fecha( (int) $eta->min_ts() ) );
		self::assertSame( '2026-11-10', $this->fecha( (int) $eta->max_ts() ) );
	}

	public function test_manual_lead_applies_to_tba_without_a_country(): void {
		$this->stub_datos(
			array(
				DateCalculator::META_TYPE            => 'tba',
				DateCalculator::META_MANUAL_MIN_DIAS => '90',
				DateCalculator::META_MANUAL_MAX_DIAS => '105',
			)
		);

		$eta = $this->calculador( '2026-08-30 12:00:00' )->calculate( $this->producto() );

		self::assertNotNull( $eta );
		self::assertTrue( $eta->is_tba() );
		self::assertTrue( $eta->has_tba_lead() );
		self::assertSame( 90, $eta->tba_min_days() );
		self::assertSame( 105, $eta->tba_max_days() );
		self::assertNull( $eta->tba_country_name(), 'El plazo es de la ficha, no de un origen.' );
	}

	/**
	 * Un «a pedido» (auto) NUNCA usa el plazo manual: se rige por su editorial
	 * o país. Sin país ni tanda, el banner queda mudo — como siempre.
	 */
	public function test_auto_ignores_the_manual_lead(): void {
		$this->stub_datos(
			array(
				DateCalculator::META_TYPE            => 'auto',
				DateCalculator::META_MANUAL_MIN_DIAS => '60',
				DateCalculator::META_MANUAL_MAX_DIAS => '75',
			)
		);

		self::assertNull( $this->calculador( '2026-08-30 12:00:00' )->calculate( $this->producto() ) );
	}

	/**
	 * @dataProvider provide_invalid_pairs
	 */
	public function test_an_unusable_pair_falls_back_to_the_general_rule( string $min, string $max ): void {
		$this->stub_datos(
			array(
				DateCalculator::META_TYPE            => 'release_date',
				DateCalculator::META_RELEASE_DATE    => '2026-10-01',
				DateCalculator::META_MANUAL_MIN_DIAS => $min,
				DateCalculator::META_MANUAL_MAX_DIAS => $max,
			)
		);

		// Sin país ni tanda, la regla general no tiene datos: banner mudo. Si
		// el par inválido se colara, habría un EtaResult con rango imposible.
		self::assertNull( $this->calculador( '2026-08-30 12:00:00' )->calculate( $this->producto() ) );
	}

	/** @return array<string, array{string, string}> */
	public static function provide_invalid_pairs(): array {
		return array(
			'solo min'      => array( '60', '' ),
			'solo max'      => array( '', '75' ),
			'max < min'     => array( '75', '60' ),
			'cero'          => array( '0', '10' ),
			'fuera de cota' => array( '10', '400' ),
		);
	}
}
