<?php
/**
 * Contrato de la política de preventa por editorial.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.22
 */
final class PedidoPolicyTest extends TestCase {

	private \DateTimeZone $tz;

	protected function setUp(): void {
		parent::setUp();
		$this->tz = new \DateTimeZone( 'America/Santiago' );
	}

	private function ts( string $fecha ): int {
		return ( new \DateTimeImmutable( $fecha . ' 12:00:00', $this->tz ) )->getTimestamp();
	}

	private function fecha( int $ts ): string {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->tz )->format( 'Y-m-d' );
	}

	/**
	 * @testdox Comprar EL día del cierre entra en esa tanda, no en la siguiente.
	 *
	 * Es la regla que decide si el cliente espera un mes más. Un «cerramos el 5»
	 * que en realidad excluye al que compra el 5 es una promesa que se incumple
	 * el primer día.
	 */
	public function test_buying_on_the_cutoff_day_joins_that_batch(): void {
		$p = new PedidoPolicy();

		self::assertSame( '2026-08-05', $this->fecha( $p->proximo_cierre( $this->ts( '2026-08-05' ), 5, $this->tz ) ) );
		self::assertSame( '2026-08-05', $this->fecha( $p->proximo_cierre( $this->ts( '2026-08-01' ), 5, $this->tz ) ) );
		self::assertSame( '2026-09-05', $this->fecha( $p->proximo_cierre( $this->ts( '2026-08-06' ), 5, $this->tz ) ) );
	}

	/**
	 * @testdox El cierre existe en TODOS los meses, febrero incluido.
	 *
	 * Por eso el día se topa en 28: un corte «el 30» no existe en febrero, y
	 * recortarlo en silencio convierte la promesa en una excepción anual que
	 * nadie recuerda al configurarla.
	 */
	public function test_the_cutoff_exists_in_every_month(): void {
		$p = new PedidoPolicy();

		self::assertSame( '2026-02-28', $this->fecha( $p->proximo_cierre( $this->ts( '2026-01-31' ), 28, $this->tz ) ) );
		self::assertSame( '2026-02-28', $this->fecha( $p->proximo_cierre( $this->ts( '2026-02-01' ), 28, $this->tz ) ) );
		self::assertSame( '2026-03-28', $this->fecha( $p->proximo_cierre( $this->ts( '2026-03-01' ), 28, $this->tz ) ) );

		// Y un día imposible no puede colarse por la puerta de atrás.
		self::assertSame( '2026-09-01', $this->fecha( $p->proximo_cierre( $this->ts( '2026-08-15' ), 31, $this->tz ) ) );
		self::assertSame( '2026-09-01', $this->fecha( $p->proximo_cierre( $this->ts( '2026-08-15' ), 0, $this->tz ) ) );
	}

	/**
	 * @testdox Doce meses seguidos caen todos en el día configurado.
	 */
	public function test_a_whole_year_lands_on_the_configured_day(): void {
		$p = new PedidoPolicy();

		for ( $mes = 1; $mes <= 12; $mes++ ) {
			$desde  = $this->ts( sprintf( '2026-%02d-15', $mes ) );
			$cierre = $this->fecha( $p->proximo_cierre( $desde, 10, $this->tz ) );

			self::assertSame( '10', ( new \DateTimeImmutable( $cierre ) )->format( 'j' ), "Mes {$mes}: el cierre tiene que caer el día 10." );
		}
	}

	/**
	 * @testdox La frase de tanda sólo aparece cuando la editorial va por tanda.
	 *
	 * Quien la imprime no tiene que saber de políticas: pregunta y recibe '' si
	 * no aplica.
	 */
	public function test_the_batch_sentence_only_shows_for_batch_publishers(): void {
		$p = new PedidoPolicy();

		$tanda = array( 'modo' => PedidoPolicy::MODO_TANDA, 'dia' => 5, 'min' => 25, 'max' => 35, 'marca' => 'Planeta' );
		// Copy sincronizado 2026-08-31: la frase cierra el eslabón lógico. «Entra
		// en el pedido que cierra el 5» nombraba el corte y callaba lo que pasa
		// después, así que el cliente completaba la frase con lo único que tenía
		// —el rango de arriba— y volvía a contar las semanas desde su compra. Las
		// semanas son DERIVADAS (25/35 días → 4–5), no un literal: ese es el
		// contrato que fija el test de abajo.
		self::assertSame(
			'Sale en el pedido que cierra el 5 de septiembre; desde ahí viaja 4–5 semanas.',
			$p->frase_de_tanda( $tanda, $this->ts( '2026-08-24' ), $this->tz )
		);

		foreach ( array( PedidoPolicy::MODO_HEREDADO, PedidoPolicy::MODO_CONTINUO, PedidoPolicy::MODO_PAUSADA ) as $modo ) {
			self::assertSame( '', $p->frase_de_tanda( array( 'modo' => $modo, 'dia' => 5, 'min' => 1, 'max' => 2, 'marca' => '' ), $this->ts( '2026-08-24' ), $this->tz ) );
		}

		// Tanda a medio configurar no es una tanda: nada que prometer. La condición
		// tiene que ser la MISMA que aplica el cálculo, o la ficha promete un
		// corte mientras la fecha viene del plazo del país.
		foreach ( array(
			array( 'dia' => 0, 'min' => 1, 'max' => 2 ),
			array( 'dia' => 5, 'min' => 0, 'max' => 0 ),
			array( 'dia' => 0, 'min' => 0, 'max' => 0 ),
		) as $incompleta ) {
			self::assertSame(
				'',
				$p->frase_de_tanda( $incompleta + array( 'modo' => PedidoPolicy::MODO_TANDA, 'marca' => '' ), $this->ts( '2026-08-24' ), $this->tz )
			);
		}
	}

	/**
	 * @testdox El día máximo configurable es 28, y eso es una decisión, no un límite técnico.
	 */
	public function test_the_maximum_configurable_day_is_28(): void {
		self::assertSame( 28, PedidoPolicy::DIA_MAXIMO );
	}

	/**
	 * @testdox Cadencia bimestral en meses impares salta correctamente los meses pares.
	 */
	public function test_bimestral_impares_lands_on_odd_months(): void {
		$p = new PedidoPolicy();

		// En octubre (mes 10, par), el siguiente corte impar es noviembre (mes 11)
		self::assertSame(
			'2026-11-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-10-24' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR ) )
		);

		// Comprar el propio día del corte entra en esa tanda
		self::assertSame(
			'2026-11-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-05' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR ) )
		);

		// Pasado el 5 de noviembre, salta diciembre (mes 12, par) y cae en enero 2027 (mes 1, impar)
		self::assertSame(
			'2027-01-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-06' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR ) )
		);

		// Comprando en diciembre (mes 12, par), el siguiente corte sigue siendo enero 2027
		self::assertSame(
			'2027-01-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-12-15' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR ) )
		);
	}

	/**
	 * @testdox Cadencia bimestral en meses pares salta correctamente los meses impares.
	 */
	public function test_bimestral_pares_lands_on_even_months(): void {
		$p = new PedidoPolicy();

		// En octubre (mes 10, par) antes del día 5
		self::assertSame(
			'2026-10-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-10-01' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_PAR ) )
		);

		// Pasado el 5 de octubre, salta noviembre (mes 11, impar) y cae en diciembre (mes 12, par)
		self::assertSame(
			'2026-12-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-10-06' ), 5, $this->tz, PedidoPolicy::FRECUENCIA_BIMESTRAL_PAR ) )
		);
	}

	/**
	 * @testdox La frase de tanda nombra el mes bimestral correcto.
	 */
	public function test_bimestral_batch_sentence_names_the_right_month(): void {
		$p = new PedidoPolicy();

		$tanda_bimestral = array(
			'modo'       => PedidoPolicy::MODO_TANDA,
			'dia'        => 5,
			'min'        => 25,
			'max'        => 35,
			'frecuencia' => PedidoPolicy::FRECUENCIA_BIMESTRAL_IMPAR,
			'marca'      => 'Pujol España',
		);

		// El 24 de octubre, el próximo cierre es el 5 de noviembre
		self::assertSame(
			'Sale en el pedido que cierra el 5 de noviembre; desde ahí viaja 4–5 semanas.',
			$p->frase_de_tanda( $tanda_bimestral, $this->ts( '2026-10-24' ), $this->tz )
		);

		// El 6 de noviembre, el próximo cierre salta a enero
		self::assertSame(
			'Sale en el pedido que cierra el 5 de enero; desde ahí viaja 4–5 semanas.',
			$p->frase_de_tanda( $tanda_bimestral, $this->ts( '2026-11-06' ), $this->tz )
		);
	}

	/**
	 * @testdox Cadencia quincenal opera 2 cortes por mes (ej: días 5 y 20).
	 */
	public function test_quincenal_frequency_handles_two_cutoffs_per_month(): void {
		$p = new PedidoPolicy();

		// El 24 de octubre, con cortes 5 y 20, el siguiente cierre es 5 de noviembre
		self::assertSame(
			'2026-11-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-10-24' ), '5, 20', $this->tz, PedidoPolicy::FRECUENCIA_QUINCENAL ) )
		);

		// Comprando el propio 5 de noviembre entra en ese corte
		self::assertSame(
			'2026-11-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-05' ), '5, 20', $this->tz, PedidoPolicy::FRECUENCIA_QUINCENAL ) )
		);

		// El 6 de noviembre, el siguiente cierre es el 20 de noviembre
		self::assertSame(
			'2026-11-20',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-06' ), '5, 20', $this->tz, PedidoPolicy::FRECUENCIA_QUINCENAL ) )
		);

		// Comprando el propio 20 de noviembre entra en el segundo corte
		self::assertSame(
			'2026-11-20',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-20' ), '5, 20', $this->tz, PedidoPolicy::FRECUENCIA_QUINCENAL ) )
		);

		// Pasado el 20 de noviembre (el 21), el siguiente cierre es el 5 de diciembre
		self::assertSame(
			'2026-12-05',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-21' ), '5, 20', $this->tz, PedidoPolicy::FRECUENCIA_QUINCENAL ) )
		);
	}

	/**
	 * @testdox Cadencia personalizada respeta la lista exacta de meses activos.
	 */
	public function test_personalizada_frequency_respects_custom_months(): void {
		$p = new PedidoPolicy();

		// Meses activos: marzo (3), junio (6), octubre (10), noviembre (11). Día 10.
		$meses = '3, 6, 10, 11';

		// El 1 de abril, el siguiente mes activo es junio
		self::assertSame(
			'2026-06-10',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-04-01' ), 10, $this->tz, PedidoPolicy::FRECUENCIA_PERSONALIZADA, $meses ) )
		);

		// Pasado el 10 de junio, el siguiente mes activo es octubre
		self::assertSame(
			'2026-10-10',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-06-11' ), 10, $this->tz, PedidoPolicy::FRECUENCIA_PERSONALIZADA, $meses ) )
		);

		// Pasado el 10 de noviembre, salta a marzo del año siguiente (2027)
		self::assertSame(
			'2027-03-10',
			$this->fecha( $p->proximo_cierre( $this->ts( '2026-11-11' ), 10, $this->tz, PedidoPolicy::FRECUENCIA_PERSONALIZADA, $meses ) )
		);
	}

	/**
	 * @testdox sanitize_row valida y persiste días quincenales y meses personalizados.
	 */
	public function test_sanitize_row_handles_quincenal_and_personalizada(): void {
		// Quincenal con string "5, 20"
		$res_quincenal = PedidoPolicy::sanitize_row( array(
			'modo'       => PedidoPolicy::MODO_TANDA,
			'dia'        => '5, 20',
			'min'        => 15,
			'max'        => 25,
			'frecuencia' => PedidoPolicy::FRECUENCIA_QUINCENAL,
		) );
		self::assertTrue( $res_quincenal['ok'] );
		self::assertSame( '5, 20', $res_quincenal['set'][ PedidoPolicy::META_DIA ] );
		self::assertSame( PedidoPolicy::FRECUENCIA_QUINCENAL, $res_quincenal['set'][ PedidoPolicy::META_FRECUENCIA ] );

		// Personalizada con meses
		$res_pers = PedidoPolicy::sanitize_row( array(
			'modo'       => PedidoPolicy::MODO_TANDA,
			'dia'        => 10,
			'min'        => 20,
			'max'        => 30,
			'frecuencia' => PedidoPolicy::FRECUENCIA_PERSONALIZADA,
			'meses'      => '3, 6, 10, 11',
		) );
		self::assertTrue( $res_pers['ok'] );
		self::assertSame( '3,6,10,11', $res_pers['set'][ PedidoPolicy::META_MESES ] );

		// Personalizada sin meses falla
		$res_invalida = PedidoPolicy::sanitize_row( array(
			'modo'       => PedidoPolicy::MODO_TANDA,
			'dia'        => 10,
			'min'        => 20,
			'max'        => 30,
			'frecuencia' => PedidoPolicy::FRECUENCIA_PERSONALIZADA,
			'meses'      => '',
		) );
		self::assertFalse( $res_invalida['ok'] );
	}
}
