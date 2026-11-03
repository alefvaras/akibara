<?php
/**
 * Contrato del modo TANDA dentro del cálculo de fecha estimada.
 *
 * `PedidoPolicyTest` fija la aritmética del cierre en aislamiento. Acá se fija
 * lo que el cliente ve: qué rango sale del calculador cuando la editorial va por
 * tanda, cuándo NO cambia nada respecto de antes, y qué pasa con una editorial
 * pausada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.22
 */
final class DateCalculatorTandaTest extends TestCase {

	private const TZ = 'America/Santiago';

	/** Lead time del país en la fixture: Argentina 12–15 días hábiles. */
	private const PAIS_MIN = 12;
	private const PAIS_MAX = 15;

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
		Functions\when( 'get_term_by' )->alias(
			static fn( string $field, $value, string $taxonomy ) => 'pa_pais' === $taxonomy
				? (object) array( 'term_id' => 7, 'name' => 'Argentina' )
				: false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function producto(): \WC_Product {
		return new class( StockStatus::ON_BACKORDER ) extends \WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
	}

	/**
	 * @param array<string,mixed> $marca Metas de la editorial ('' → sin política).
	 * @param string              $release Fecha de lanzamiento Y-m-d, o '' si no aplica.
	 */
	private function stub_datos( array $marca, string $eta_type = '', string $release = '' ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) use ( $eta_type, $release ) {
				unset( $post_id, $single );

				if ( DateCalculator::META_TYPE === $key ) {
					return $eta_type;
				}

				return DateCalculator::META_RELEASE_DATE === $key ? $release : '';
			}
		);
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) {
				unset( $post_id );

				if ( 'pa_pais' === $taxonomy ) {
					return array( (object) array( 'term_id' => 7, 'slug' => 'argentina' ) );
				}

				if ( PedidoPolicy::TAXONOMY === $taxonomy ) {
					return array( (object) array( 'term_id' => 99, 'name' => 'Ivrea Argentina' ) );
				}

				return false;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = true ) use ( $marca ) {
				unset( $single );

				if ( 7 === $term_id ) {
					if ( Settings::TERM_META_MIN_DAYS === $key ) {
						return self::PAIS_MIN;
					}

					return Settings::TERM_META_MAX_DAYS === $key ? self::PAIS_MAX : '';
				}

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

	/** @param array<string,mixed> $extra */
	private function tanda( int $dia = 5, int $min = 25, int $max = 35, array $extra = array() ): array {
		return array(
			PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
			PedidoPolicy::META_DIA  => $dia,
			PedidoPolicy::META_MIN  => $min,
			PedidoPolicy::META_MAX  => $max,
		) + $extra;
	}

	private function fecha( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( self::TZ ) )->format( 'Y-m-d' );
	}

	/**
	 * @testdox Toda la tanda recibe la MISMA fecha, compre el día 6 o el día 4 siguiente.
	 *
	 * Es el punto entero del modelo: la mercadería viaja junta, así que la promesa
	 * es una sola. Si el plazo corriera desde la compra, dos clientes de la misma
	 * caja verían fechas distintas.
	 */
	public function test_the_whole_batch_gets_one_single_date(): void {
		$this->stub_datos( $this->tanda() );

		$rangos = array();
		foreach ( array( '2026-08-06 09:00:00', '2026-08-20 23:30:00', '2026-09-05 08:00:00' ) as $momento ) {
			$eta = $this->calculador( $momento )->calculate( $this->producto() );

			self::assertInstanceOf( EtaResult::class, $eta );
			self::assertFalse( $eta->is_tba(), "«{$momento}» tiene que producir un rango con fechas, no un TBA." );
			self::assertNotNull( $eta->min_ts() );
			self::assertNotNull( $eta->max_ts() );

			$rangos[] = $this->fecha( $eta->min_ts() ) . '→' . $this->fecha( $eta->max_ts() );
		}

		// Cierre 5 sep + 25/35 días corridos.
		self::assertSame( array( '2026-09-30→2026-10-10' ), array_unique( $rangos ) );
	}

	/**
	 * @testdox El plazo se cuenta desde el cierre, y son días CORRIDOS.
	 *
	 * Días hábiles tienen sentido contando trabajo de bodega; una espera de barco
	 * se cuenta en semanas de calendario, que es como lo lee el cliente.
	 */
	public function test_the_lead_time_runs_from_the_cutoff_in_calendar_days(): void {
		$this->stub_datos( $this->tanda( 5, 30, 30 ) );

		$eta = $this->calculador( '2026-08-01 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		// Cierre 5 ago + 30 corridos = 4 sep. Con días hábiles caería en 16 sep.
		self::assertSame( '2026-09-04', $this->fecha( $eta->min_ts() ) );
	}

	/**
	 * @testdox Un tomo que sale DESPUÉS del cierre entra en la tanda siguiente.
	 *
	 * El día del corte esa mercadería todavía no existe: meterla en esa tanda
	 * sería la versión elegante de mentir.
	 */
	public function test_a_title_released_after_the_cutoff_falls_to_the_next_batch(): void {
		$this->stub_datos( $this->tanda( 5, 25, 35 ), 'release_date', '2026-08-20' );

		$eta = $this->calculador( '2026-08-01 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		// Sale el 20 ago → el primer cierre posible es el 5 SEP, no el 5 ago.
		self::assertSame( '2026-09-30', $this->fecha( $eta->min_ts() ) );
	}

	/**
	 * @testdox Bajo una tanda, un tomo sin fecha no hereda el plazo del país.
	 *
	 * «Llega ~2 semanas tras su salida» describe el modelo continuo. Con un corte
	 * de por medio la espera real es hasta el próximo cierre MÁS el plazo de la
	 * tanda, y sin fecha de publicación ese cierre no se puede calcular: dar el
	 * número del país sería prometer algo que la política ya no sostiene.
	 */
	public function test_under_a_batch_a_dateless_title_inherits_no_country_lead(): void {
		$this->stub_datos( $this->tanda(), 'tba' );

		$eta = $this->calculador( '2026-08-01 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertTrue( $eta->is_tba() );
		self::assertFalse( $eta->has_tba_lead(), 'No puede colarse el plazo del país por la puerta del TBA.' );

		// Sin política, ese mismo producto SÍ da el plazo del país: la diferencia
		// es la política, no una regresión del TBA.
		$this->stub_datos( array(), 'tba' );
		$sin_politica = $this->calculador( '2026-08-01 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $sin_politica );
		self::assertTrue( $sin_politica->has_tba_lead() );
		self::assertSame( self::PAIS_MIN, $sin_politica->tba_min_days() );
	}

	/**
	 * @testdox La nota nombra el MISMO cierre del que salió la fecha impresa.
	 *
	 * Es la invariante de la que depende `BannerRenderer::nota_de_tanda()`: puede
	 * recuperar el cierre restándole a `min_ts()` los días mínimos. Si se rompe,
	 * la ficha dice una fecha y nombra otro cierre — y las dos mitades se leen
	 * correctas por separado, que es lo que hace el defecto tan difícil de ver.
	 *
	 * Caso concreto: un tomo que sale el 20 de septiembre comprado el 24 de
	 * agosto. La fecha sale del cierre de OCTUBRE; una nota anclada en «hoy»
	 * nombraría el de septiembre, anterior a la fecha impresa encima.
	 */
	public function test_the_note_names_the_same_cutoff_the_printed_date_came_from(): void {
		$this->stub_datos( $this->tanda( 5, 25, 35 ), 'release_date', '2026-09-20' );

		$eta = $this->calculador( '2026-08-24 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertNotNull( $eta->min_ts() );

		$politica = new PedidoPolicy();
		$cierre   = $eta->min_ts() - ( 25 * DAY_IN_SECONDS );

		self::assertSame( '2026-10-05', $this->fecha( $cierre ), 'El cierre recuperado tiene que ser el de octubre.' );
		self::assertSame(
			'Sale en el pedido que cierra el 5 de octubre; desde ahí viaja 4–5 semanas.',
			$politica->frase_de_tanda(
				array( 'modo' => PedidoPolicy::MODO_TANDA, 'dia' => 5, 'min' => 25, 'max' => 35, 'marca' => '' ),
				$cierre,
				new \DateTimeZone( self::TZ )
			)
		);

		// Y la trampa que motivó todo: anclar en «hoy» nombra el cierre EQUIVOCADO.
		self::assertSame(
			'Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 4–5 semanas.',
			$politica->frase_de_tanda(
				array( 'modo' => PedidoPolicy::MODO_TANDA, 'dia' => 5, 'min' => 25, 'max' => 35, 'marca' => '' ),
				( new \DateTimeImmutable( '2026-08-24 10:00:00', new \DateTimeZone( self::TZ ) ) )->getTimestamp(),
				new \DateTimeZone( self::TZ )
			)
		);
	}

	/**
	 * @testdox Una editorial pausada no promete fecha, aunque el país tenga plazo.
	 */
	public function test_a_paused_publisher_promises_no_date(): void {
		$this->stub_datos( array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_PAUSADA ) );

		$eta = $this->calculador( '2026-08-01 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertTrue( $eta->is_tba(), 'Pausada tiene que salir sin fecha, no heredar la del país.' );
	}

	/**
	 * @testdox Sin política configurada NADA cambia: sigue el plazo del país en días hábiles.
	 *
	 * Es el candado de regresión que importa. Este módulo toca la ficha de cada
	 * preventa del catálogo; el default tiene que ser exactamente lo de antes.
	 */
	public function test_without_a_policy_nothing_changes(): void {
		foreach ( array( array(), array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_CONTINUO ) ) as $marca ) {
			$this->stub_datos( $marca );

			$eta = $this->calculador( '2026-06-01 15:40:00' )->calculate( $this->producto() );

			self::assertInstanceOf( EtaResult::class, $eta );
			// Lunes 1 jun + 12/15 días HÁBILES.
			self::assertSame( '2026-06-17', $this->fecha( $eta->min_ts() ) );
			self::assertSame( '2026-06-22', $this->fecha( $eta->max_ts() ) );
		}
	}

	/**
	 * @testdox Una tanda a medio configurar cae al comportamiento de siempre, no a una fecha inventada.
	 */
	public function test_a_half_configured_batch_falls_back_instead_of_inventing(): void {
		$incompletas = array(
			'sin día'   => $this->tanda( 0, 25, 35 ),
			'día fuera de rango' => $this->tanda( 31, 25, 35 ),
			'sin máximo' => $this->tanda( 5, 0, 0 ),
		);

		foreach ( $incompletas as $caso => $marca ) {
			$this->stub_datos( $marca );

			$eta = $this->calculador( '2026-06-01 15:40:00' )->calculate( $this->producto() );

			self::assertInstanceOf( EtaResult::class, $eta );
			self::assertSame( '2026-06-17', $this->fecha( $eta->min_ts() ), "Caso «{$caso}»: tiene que caer al plazo del país." );
		}
	}

	/**
	 * @testdox DateCalculator respeta la frecuencia bimestral al calcular el rango ETA.
	 */
	public function test_date_calculator_respects_bimestral_frequency(): void {
		// Tanda día 5, tránsito 25-35 días, bimestral impar (Ene, Mar, May, Jul, Sep, Nov)
		$this->stub_datos(
			$this->tanda( 5, 25, 35, array( PedidoPolicy::META_FRECUENCIA => PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR ) )
		);

		// Comprando el 6 de noviembre (tras el cierre de noviembre):
		// El siguiente cierre es el 5 de enero de 2027.
		// 5 ene + 25 días = 30 de enero de 2027.
		// 5 ene + 35 días = 9 de febrero de 2027.
		$eta = $this->calculador( '2026-11-06 10:00:00' )->calculate( $this->producto() );

		self::assertInstanceOf( EtaResult::class, $eta );
		self::assertSame( '2027-01-30', $this->fecha( $eta->min_ts() ) );
		self::assertSame( '2027-02-09', $this->fecha( $eta->max_ts() ) );
	}
}
