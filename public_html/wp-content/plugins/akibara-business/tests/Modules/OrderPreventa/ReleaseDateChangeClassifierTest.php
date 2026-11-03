<?php
/**
 * Tests de la matriz de clasificación de cambios de fecha de lanzamiento.
 *
 * D2 de la auditoría 2026-07-28. Esta matriz decide si un cambio de fecha le
 * escribe o no al cliente: clasificar de más manda correos indebidos, y
 * clasificar de menos incumple el aviso de atraso (Ley 21.719). Las reglas
 * están documentadas en el docblock del listener con fecha y candado
 * ("LOCKED"), así que merecen regresión explícita.
 *
 * `classify()` es privado por diseño (detalle interno del listener); se accede
 * por reflexión a propósito: es una función pura y probarla a través de
 * `on_release_date_changed()` exigiría simular el paginador de pedidos entero,
 * lo que testearía menos y con más ruido.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Tests\Support\NullEtaProvider;

use Akibara\Business\Modules\OrderPreventa\ReleaseDateChangeListener;
use Akibara\Business\Modules\OrderPreventa\Settings;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReleaseDateChangeClassifierTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

		$this->options = array(
			Settings::OPT_DATE_CHANGE_THRESHOLD  => 7,
			Settings::OPT_DATE_CHANGE_NOTIFY_ADV => 'no',
		);

		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => $this->options[ $key ] ?? $default
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
	}

	/** «Hoy» fijo (2026-06-01 12:00 UTC): las fechas de esta matriz son todas posteriores, y así no caducan. */
	private const HOY = 1780315200;

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $old Fecha previa (Y-m-d o '').
	 * @param string $new Fecha nueva (Y-m-d o '').
	 */
	private function classify( string $old, string $new ): string {
		$listener = new ReleaseDateChangeListener( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::HOY );
		$method   = new ReflectionMethod( ReleaseDateChangeListener::class, 'classify' );
		$method->setAccessible( true );

		return (string) $method->invoke( $listener, $old, $new );
	}

	/**
	 * @testdox La nota de traza distingue un adelanto de una postergación.
	 */
	public function test_an_advance_is_not_noted_as_a_postponement(): void {
		$listener = new ReleaseDateChangeListener( new SummaryRecalculator( new NullEtaProvider() ), static fn(): int => self::HOY );
		$note     = new ReflectionMethod( ReleaseDateChangeListener::class, 'event_note' );
		$note->setAccessible( true );
		self::assertSame( 'Adelanto registrado · producto #9: 2026-11-04 → 2026-10-20.', $note->invoke( $listener, 'advance', 9, '2026-11-04', '2026-10-20' ) );
		self::assertSame( 'Postergación registrada · producto #9: 2026-10-20 → 2026-11-04.', $note->invoke( $listener, 'postponement', 9, '2026-10-20', '2026-11-04' ) );
	}

	/**
	 * @testdox Should announce a date for a product bought without one.
	 */
	public function test_tba_to_date_is_announced(): void {
		self::assertSame( 'announced', $this->classify( '', '2026-09-01' ) );
	}

	/**
	 * @testdox Should announce regardless of how near the new date is.
	 */
	public function test_announcement_ignores_the_threshold(): void {
		self::assertSame( 'announced', $this->classify( '', '2026-07-29' ) );
	}

	/**
	 * @testdox Should mark a withdrawn date as retracted (no customer email).
	 */
	public function test_date_to_tba_is_retracted(): void {
		self::assertSame( 'retracted', $this->classify( '2026-09-01', '' ) );
	}

	/**
	 * @testdox Should treat a delay beyond the threshold as a postponement.
	 */
	public function test_delay_over_threshold_is_a_postponement(): void {
		self::assertSame( 'postponement', $this->classify( '2026-09-01', '2026-09-20' ) );
	}

	/**
	 * @testdox Should stay silent on a delay under the threshold.
	 */
	public function test_delay_under_threshold_is_silent(): void {
		self::assertSame( 'none', $this->classify( '2026-09-01', '2026-09-04' ) );
	}

	/**
	 * @testdox Should stay silent on an early date while the toggle is off.
	 */
	public function test_advance_is_silent_by_default(): void {
		self::assertSame( 'none', $this->classify( '2026-09-20', '2026-09-01' ) );
	}

	/**
	 * @testdox Should report an early date once the operator enables it.
	 */
	public function test_advance_is_reported_when_enabled(): void {
		$this->options[ Settings::OPT_DATE_CHANGE_NOTIFY_ADV ] = 'yes';

		self::assertSame( 'advance', $this->classify( '2026-09-20', '2026-09-01' ) );
	}

	/**
	 * @testdox Should stay silent on a small advance even when enabled.
	 */
	public function test_small_advance_is_silent_even_when_enabled(): void {
		$this->options[ Settings::OPT_DATE_CHANGE_NOTIFY_ADV ] = 'yes';

		self::assertSame( 'none', $this->classify( '2026-09-05', '2026-09-01' ) );
	}

	/**
	 * @testdox Should ignore a no-op change.
	 */
	public function test_identical_dates_are_ignored(): void {
		self::assertSame( 'none', $this->classify( '2026-09-01', '2026-09-01' ) );
	}

	/**
	 * @testdox Should ignore a malformed date instead of guessing.
	 */
	public function test_invalid_dates_are_ignored(): void {
		self::assertSame( 'none', $this->classify( '2026-09-01', '2026-13-45' ) );
		self::assertSame( 'none', $this->classify( 'mañana', '2026-09-01' ) );
	}

	/**
	 * @testdox Should honour a custom threshold.
	 */
	public function test_threshold_is_configurable(): void {
		$this->options[ Settings::OPT_DATE_CHANGE_THRESHOLD ] = 30;

		self::assertSame( 'none', $this->classify( '2026-09-01', '2026-09-20' ) );
		self::assertSame( 'postponement', $this->classify( '2026-09-01', '2026-10-15' ) );
	}

	/**
	 * @testdox A date already in the past announces nothing, whatever it replaces.
	 */
	public function test_a_date_in_the_past_is_never_news(): void {
		self::assertSame( 'none', $this->classify( '', '2026-05-20' ), 'La editorial ya publicó: no hay salida que anunciar.' );
		self::assertSame( 'none', $this->classify( '2026-04-01', '2026-05-20' ), 'Corregir una fecha vieja por otra vieja mandaba «nueva fecha» con un arribo vencido.' );
		self::assertSame( 'none', $this->classify( '2026-07-01', '2026-05-20' ) );
	}

	/**
	 * @testdox The launch day itself still counts as a future date.
	 */
	public function test_today_is_not_the_past(): void {
		self::assertSame( 'announced', $this->classify( '', '2026-06-01' ) );
		self::assertSame( 'postponement', $this->classify( '2026-05-20', '2026-06-01' ) );
	}

	/**
	 * @testdox Should classify a delay that crosses a DST change by real days.
	 */
	public function test_dst_crossing_delay_is_measured_in_whole_days(): void {
		// Chile cambia de hora a comienzos de septiembre: el cálculo usa
		// mediodía UTC justamente para no perder/ganar un día acá.
		self::assertSame( 'postponement', $this->classify( '2026-09-01', '2026-09-08' ) );
	}
}
