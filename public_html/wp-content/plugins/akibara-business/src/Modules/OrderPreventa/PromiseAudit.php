<?php
/**
 * Contrasta la fecha prometida de llegada con la disponibilidad efectiva.
 *
 * Reúne el snapshot de compra y la señal de pedido despachable para fundamentar
 * la revisión de plazos sin modificarlos automáticamente. ORDER_READY_DATE
 * registra cuándo el cron detectó todos los reservables disponibles. Cuando
 * existe además una transición operacional a listo para enviar, toma la señal
 * más temprana y presenta la divergencia para su revisión.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.108.22
 */
final class PromiseAudit {

	/**
	 * Zona en la que se leen los DÍAS.
	 *
	 * No es un detalle de formato: al cliente se le prometió «llega entre el 28 y
	 * el 31 de julio», no un instante. Comparar marcas de tiempo crudas convierte
	 * una promesa CUMPLIDA en incumplida por unas horas — el pedido 25538 de
	 * producción llegó el 31 de julio a las 18:47 y el informe lo acusaba de
	 * «tarde», porque el máximo guardado apunta al comienzo de ese día.
	 */
	private \DateTimeZone $tz;

	public function __construct( ?\DateTimeZone $tz = null ) {
		$this->tz = $tz ?? StoreTime::zone();
	}

	/** Llegó dentro de la ventana prometida. */
	public const DENTRO = 'dentro';

	/** Llegó después del máximo prometido. */
	public const TARDE = 'tarde';

	/** Llegó antes del mínimo prometido. */
	public const ANTES = 'antes';

	/** Todavía no llega y el plazo no ha vencido. */
	public const ABIERTO = 'abierto';

	/** Todavía no llega y el máximo prometido ya pasó. */
	public const VENCIDO = 'vencido';

	/** El pedido no guardó promesa: no se puede contrastar nada. */
	public const SIN_PROMESA = 'sin_promesa';

	/**
	 * Contrasta una promesa con lo ocurrido.
	 *
	 * Función pura a propósito: es la única parte con reglas, y así se puede
	 * probar sin base de datos ni pedidos.
	 *
	 * El desvío se mide SIEMPRE contra el borde que se cruzó (el máximo si llegó
	 * tarde, el mínimo si llegó antes), nunca contra el centro de la ventana: al
	 * cliente se le prometió un rango, y un rango se incumple por sus bordes.
	 *
	 * @param int      $comprado Marca de tiempo de la compra.
	 * @param int|null $min      Mínimo prometido, o null si no se guardó.
	 * @param int|null $max      Máximo prometido, o null si no se guardó.
	 * @param int|null $listo    Momento en que estuvo listo, o null si sigue abierto.
	 * @param int      $ahora    Reloj.
	 * @return array{veredicto:string, dias_reales:int|null, dias_prometidos:int|null, desvio_dias:int|null}
	 */
	public function veredicto( int $comprado, ?int $min, ?int $max, ?int $listo, int $ahora ): array {
		$sin_datos = array(
			'veredicto'       => self::SIN_PROMESA,
			'dias_reales'     => null !== $listo ? $this->dias_entre( $comprado, $listo ) : null,
			'dias_prometidos' => null,
			'desvio_dias'     => null,
		);

		// Una ventana invertida o incompleta no es una promesa: no se contrasta
		// contra ella, porque cualquier veredicto que saliera sería inventado.
		if ( null === $min || null === $max || $min > $max ) {
			return $sin_datos;
		}

		$prometidos = $this->dias_entre( $comprado, $max );

		// Todo se compara por DÍA en la zona de la tienda. La promesa que vio el
		// cliente era «entre el 28 y el 31», así que cualquier hora del 31 cumple.
		$dia_min = $this->dia( $min );
		$dia_max = $this->dia( $max );

		if ( null === $listo ) {
			$vencido = $this->dia( $ahora ) > $dia_max;

			return array(
				'veredicto'       => $vencido ? self::VENCIDO : self::ABIERTO,
				'dias_reales'     => null,
				'dias_prometidos' => $prometidos,
				'desvio_dias'     => $vencido ? $this->dias_entre( $max, $ahora ) : null,
			);
		}

		$reales   = $this->dias_entre( $comprado, $listo );
		$dia_real = $this->dia( $listo );

		if ( $dia_real > $dia_max ) {
			return array(
				'veredicto'       => self::TARDE,
				'dias_reales'     => $reales,
				'dias_prometidos' => $prometidos,
				'desvio_dias'     => $this->dias_entre( $max, $listo ),
			);
		}

		if ( $dia_real < $dia_min ) {
			return array(
				'veredicto'       => self::ANTES,
				'dias_reales'     => $reales,
				'dias_prometidos' => $prometidos,
				'desvio_dias'     => -$this->dias_entre( $listo, $min ),
			);
		}

		return array(
			'veredicto'       => self::DENTRO,
			'dias_reales'     => $reales,
			'dias_prometidos' => $prometidos,
			'desvio_dias'     => 0,
		);
	}

	/**
	 * Resume una lista de veredictos.
	 *
	 * El resumen NO promedia el desvío sobre todos los pedidos: mezclar un «llegó
	 * 3 días antes» con un «llegó 3 días tarde» daría cero y diría que todo va
	 * bien. Se cuentan los incumplimientos y se reporta el PEOR, que es la queja
	 * que llega a soporte.
	 *
	 * @param array<int, array{veredicto:string, dias_reales:int|null, dias_prometidos:int|null, desvio_dias:int|null}> $veredictos
	 * @return array{cerrados:int, dentro:int, tarde:int, antes:int, abiertos:int, vencidos:int, sin_promesa:int, peor_atraso:int|null, dias_reales_max:int|null}
	 */
	public function resumen( array $veredictos ): array {
		$r = array(
			'cerrados'        => 0,
			'dentro'          => 0,
			'tarde'           => 0,
			'antes'           => 0,
			'abiertos'        => 0,
			'vencidos'        => 0,
			'sin_promesa'     => 0,
			'peor_atraso'     => null,
			'dias_reales_max' => null,
		);

		foreach ( $veredictos as $v ) {
			switch ( $v['veredicto'] ?? '' ) {
				case self::DENTRO:
					++$r['dentro'];
					++$r['cerrados'];
					break;
				case self::TARDE:
					++$r['tarde'];
					++$r['cerrados'];
					$atraso = (int) ( $v['desvio_dias'] ?? 0 );
					if ( null === $r['peor_atraso'] || $atraso > $r['peor_atraso'] ) {
						$r['peor_atraso'] = $atraso;
					}
					break;
				case self::ANTES:
					++$r['antes'];
					++$r['cerrados'];
					break;
				case self::ABIERTO:
					++$r['abiertos'];
					break;
				case self::VENCIDO:
					++$r['vencidos'];
					$atraso = (int) ( $v['desvio_dias'] ?? 0 );
					if ( null === $r['peor_atraso'] || $atraso > $r['peor_atraso'] ) {
						$r['peor_atraso'] = $atraso;
					}
					break;
				default:
					++$r['sin_promesa'];
			}

			$reales = $v['dias_reales'] ?? null;
			if ( is_int( $reales ) && ( null === $r['dias_reales_max'] || $reales > $r['dias_reales_max'] ) ) {
				$r['dias_reales_max'] = $reales;
			}
		}

		return $r;
	}

	/**
	 * Días corridos entre dos marcas de tiempo, redondeando hacia abajo.
	 *
	 * Corridos y no hábiles: mide la espera del CLIENTE, que no distingue fines
	 * de semana. Los días hábiles son la unidad en que se configura el plazo, no
	 * en la que se sufre.
	 */
	private function dias_entre( int $desde, int $hasta ): int {
		return (int) $this->fecha( $desde )->diff( $this->fecha( $hasta ) )->format( '%r%a' );
	}

	/** Día local en formato ordenable, para comparar fechas y no instantes. */
	private function dia( int $ts ): string {
		return $this->fecha( $ts )->format( 'Y-m-d' );
	}

	private function fecha( int $ts ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $this->tz )->setTime( 0, 0, 0 );
	}
}
