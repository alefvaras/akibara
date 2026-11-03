<?php
/**
 * La línea del corte dentro del correo.
 *
 * Los negativos son lo importante: el correo NO puede prometer una fecha que la
 * política no sostiene, y `EtaProvider::calculate()` devuelve null para todo
 * producto que no sea `onbackorder` — o sea, para la mayoría del catálogo. Ese
 * null llegando a algo que tipa `EtaResult` sería un TypeError en mitad de la
 * corrida del digest.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use Akibara\Business\Tests\Support\SeriesFollowFixedEta;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

final class CutoffPhraseTest extends TestCase {

	private const TZ = 'America/Santiago';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_timezone' )->alias(
			static fn (): \DateTimeZone => new \DateTimeZone( self::TZ )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function producto(): WC_Product {
		return new class() extends WC_Product {
			public function get_id(): int {
				return 4242;
			}
		};
	}

	/**
	 * @param array<string, mixed> $marca  Metas de la editorial ('' → sin política).
	 * @param array<string, mixed> $pais   Metas del país.
	 */
	private function stub_terminos( array $marca, array $pais = array() ): void {
		Functions\when( 'get_the_terms' )->alias(
			static function ( int $post_id, string $taxonomy ) {
				unset( $post_id );

				if ( PedidoPolicy::TAXONOMY === $taxonomy ) {
					return array( (object) array( 'term_id' => 99, 'name' => 'Ivrea Argentina' ) );
				}

				return 'pa_pais' === $taxonomy
					? array( (object) array( 'term_id' => 7, 'name' => 'Argentina' ) )
					: false;
			}
		);
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key, bool $single = true ) use ( $marca, $pais ) {
				unset( $single );

				return 7 === $term_id ? ( $pais[ $key ] ?? '' ) : ( $marca[ $key ] ?? '' );
			}
		);
	}

	/** @param array<string, mixed> $extra Metas adicionales. */
	private function tanda( int $dia = 5, int $min = 25, int $max = 35, array $extra = array() ): array {
		return array_merge(
			array(
				PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
				PedidoPolicy::META_DIA  => $dia,
				PedidoPolicy::META_MIN  => $min,
				PedidoPolicy::META_MAX  => $max,
			),
			$extra
		);
	}

	private function frase( ?EtaResult $eta ): string {
		return ( new CutoffPhrase( new SeriesFollowFixedEta( $eta ), new PedidoPolicy() ) )->for_product( $this->producto() );
	}

	private function timestamp( string $fecha ): int {
		return ( new \DateTimeImmutable( $fecha, new \DateTimeZone( self::TZ ) ) )->getTimestamp();
	}

	/** @testdox Una editorial por tanda produce la frase sellada, con sus DOS mitades. */
	public function test_editorial_por_tanda_produce_la_frase_sellada_con_las_dos_mitades(): void {
		$this->stub_terminos( $this->tanda( 5, 25, 35 ) );

		$cierre = $this->timestamp( '2026-10-05 00:00:00' );
		$frase  = $this->frase( EtaResult::range( $cierre + ( 25 * DAY_IN_SECONDS ), $cierre + ( 35 * DAY_IN_SECONDS ) ) );

		self::assertSame( 'Sale en el pedido que cierra el 5 de octubre; desde ahí viaja 4–5 semanas.', $frase );
		self::assertStringContainsString(
			'desde ahí viaja',
			$frase,
			'Nombrar el corte sin decir qué pasa después hace que el cliente lea las semanas desde su compra.'
		);
	}

	/**
	 * Gemelo del candado de la ficha: el correo tiene que recuperar el MISMO
	 * cierre del que salió la fecha, restándole a `min_ts()` los días mínimos.
	 * Si divergen, el correo y la ficha nombran cortes distintos y las dos se
	 * leen correctas por separado.
	 *
	 * @testdox El correo nombra EL MISMO corte que la ficha.
	 */
	public function test_el_correo_nombra_el_mismo_corte_que_la_ficha(): void {
		$this->stub_terminos( $this->tanda( 5, 25, 35 ) );

		$cierre_octubre = $this->timestamp( '2026-10-05 00:00:00' );
		$eta            = EtaResult::range( $cierre_octubre + ( 25 * DAY_IN_SECONDS ), $cierre_octubre + ( 35 * DAY_IN_SECONDS ) );

		$politica = new PedidoPolicy();
		$ancla    = PedidoPolicy::ancla_de_tanda( $eta, array( 'min' => 25 ) );

		self::assertSame( $cierre_octubre, $ancla );
		self::assertSame(
			$politica->frase_de_tanda(
				array(
					'modo'  => PedidoPolicy::MODO_TANDA,
					'dia'   => 5,
					'min'   => 25,
					'max'   => 35,
					'marca' => '',
				),
				$ancla,
				new \DateTimeZone( self::TZ )
			),
			$this->frase( $eta )
		);
	}

	/**
	 * EL CANDADO QUE FALTABA. `DateCalculator::calculate()` devuelve null para
	 * todo lo que no sea `onbackorder`: un tomo Disponible llega aquí con null.
	 *
	 * @testdox [NEG] Un producto EN STOCK no revienta y no imprime fecha.
	 */
	public function test_producto_en_stock_no_revienta_y_no_imprime_fecha(): void {
		$this->stub_terminos( $this->tanda() );

		self::assertSame( '', $this->frase( null ) );
	}

	/** @testdox [NEG] Un producto AGOTADO no revienta y no imprime fecha. */
	public function test_producto_agotado_no_revienta_y_no_imprime_fecha(): void {
		$this->stub_terminos( $this->tanda() );

		// Mismo null: `is_reservable()` sólo es cierto para `onbackorder`.
		self::assertSame( '', $this->frase( null ) );
	}

	/**
	 * @testdox [NEG] Sin política de corte NO se imprime ninguna fecha.
	 * @dataProvider politicasSinCorte
	 * @param array<string, mixed> $marca Metas de la editorial.
	 */
	public function test_producto_sin_politica_de_corte_no_imprime_ninguna_fecha( array $marca ): void {
		$this->stub_terminos( $marca );

		$cierre = $this->timestamp( '2026-10-05 00:00:00' );
		$frase  = $this->frase( EtaResult::range( $cierre + ( 25 * DAY_IN_SECONDS ), $cierre + ( 35 * DAY_IN_SECONDS ) ) );

		self::assertSame( '', $frase );
		self::assertDoesNotMatchRegularExpression(
			'/\b(enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b/u',
			$frase
		);
		self::assertDoesNotMatchRegularExpression( '/\d/u', $frase );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function politicasSinCorte(): array {
		return array(
			'editorial continua'   => array( array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_CONTINUO ) ),
			'editorial pausada'    => array( array( PedidoPolicy::META_MODO => PedidoPolicy::MODO_PAUSADA ) ),
			'sin política alguna'  => array( array() ),
			'día sin configurar'   => array(
				array(
					PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
					PedidoPolicy::META_DIA  => 0,
					PedidoPolicy::META_MIN  => 25,
					PedidoPolicy::META_MAX  => 35,
				),
			),
			'plazo sin configurar' => array(
				array(
					PedidoPolicy::META_MODO => PedidoPolicy::MODO_TANDA,
					PedidoPolicy::META_DIA  => 5,
					PedidoPolicy::META_MIN  => 0,
					PedidoPolicy::META_MAX  => 0,
				),
			),
		);
	}

	/**
	 * `EtaResult::tba()` es la rama que decide NO prometer. Añadirle «sale en el
	 * pedido que cierra el 5» produce el mensaje más contradictorio posible:
	 * «te avisamos en cuanto tengamos fecha» seguido de una fecha.
	 *
	 * @testdox [NEG] Un producto TBA no imprime fecha.
	 * @dataProvider resultadosTba
	 */
	public function test_producto_tba_no_imprime_fecha( EtaResult $eta ): void {
		$this->stub_terminos( $this->tanda() );

		self::assertTrue( $eta->is_tba() );
		self::assertNull( $eta->min_ts() );
		self::assertSame( '', $this->frase( $eta ) );
	}

	/** @return array<string, array{0: EtaResult}> */
	public static function resultadosTba(): array {
		return array(
			'sin lead time' => array( EtaResult::tba() ),
			'con lead time' => array( EtaResult::tba_with_lead( 12, 15, 'Argentina' ) ),
		);
	}

	/**
	 * La cadena real es ficha > editorial > PAÍS > continuo. Un tomo importado
	 * sin marca tiene que seguir tomando el corte de su origen.
	 *
	 * @testdox El fallback por PAÍS produce corte cuando la editorial no tiene política.
	 */
	public function test_el_fallback_por_pais_produce_corte_cuando_la_editorial_no_tiene_politica(): void {
		$this->stub_terminos( array(), $this->tanda( 20, 25, 35 ) );

		$cierre = $this->timestamp( '2026-10-20 00:00:00' );
		$frase  = $this->frase( EtaResult::range( $cierre + ( 25 * DAY_IN_SECONDS ), $cierre + ( 35 * DAY_IN_SECONDS ) ) );

		self::assertSame( 'Sale en el pedido que cierra el 20 de octubre; desde ahí viaja 4–5 semanas.', $frase );
	}

	/**
	 * El módulo no formatea NI UNA fecha: sin `dia_y_mes()`, sin tabla de meses
	 * y sin cuenta de semanas propia, no hay dónde inventarla. Es una prueba de
	 * conducta, no de texto: se comprueba que la salida es EXACTAMENTE la que
	 * produce la única autoridad de cortes de la casa.
	 *
	 * @testdox La frase sale íntegra de PedidoPolicy, sin aritmética propia.
	 */
	public function test_la_frase_sale_integra_de_pedido_policy(): void {
		$this->stub_terminos( $this->tanda( 5, 7, 21 ) );

		$cierre = $this->timestamp( '2026-11-05 00:00:00' );
		$eta    = EtaResult::range( $cierre + ( 7 * DAY_IN_SECONDS ), $cierre + ( 21 * DAY_IN_SECONDS ) );

		self::assertSame(
			( new PedidoPolicy() )->frase_de_tanda(
				array(
					'modo'  => PedidoPolicy::MODO_TANDA,
					'dia'   => 5,
					'min'   => 7,
					'max'   => 21,
					'marca' => '',
				),
				$cierre,
				new \DateTimeZone( self::TZ )
			),
			$this->frase( $eta )
		);
	}
}
