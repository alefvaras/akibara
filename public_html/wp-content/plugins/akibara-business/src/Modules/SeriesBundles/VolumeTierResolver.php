<?php
/**
 * Series Bundles — VolumeTierResolver.
 *
 * LÓGICA PURA: selección del tramo de volumen y normalización/validación de
 * tramos para Build_Your_Pack. No depende del runtime de WooCommerce ni de
 * WordPress; recibe datos ya resueltos por la capa de hooks (el conteo de
 * tomos DISTINTOS ya viene deduplicado por el caller, Decisión 8 del design).
 *
 * Es la base de las Correctness Properties 6 (monotonía del descuento por
 * volumen) y 8 (selección correcta del tramo).
 *
 * Reglas (Req 2.2–2.7):
 *   - Cada Volume_Tier es un par `{min:int, pct:int}` con `min >= 2` y
 *     `pct` entero en el rango 0..15 inclusive (Req 2.2).
 *   - Los tramos se ordenan por `min` ascendente (Req 2.3).
 *   - Dos tramos no pueden compartir la misma `min` (Req 2.4).
 *   - La selección es inclusiva: se aplica el tramo de mayor `min` tal que
 *     `min <= distinct_qty` (Req 2.5/2.6). Si la cantidad es menor a la `min`
 *     del primer tramo, no aplica descuento (0) (Req 2.7).
 *
 * Sin funciones i18n (Req 11.4): los mensajes se expresan en español literal.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Support\Result;
use Akibara\Business\Utilities\DiscountCeiling;

defined( 'ABSPATH' ) || exit;

final class VolumeTierResolver {

	public const MIN_QUANTITY = 2;

	/**
	 * Mínimo de tomos para que un tramo pueda llevar descuento (> 0%).
	 *
	 * Guard anti-canibalización (mesa de estrategia): un 2-pack es una
	 * compra que el cliente hace igual, así que NUNCA debe descontarse. Los tramos
	 * con descuento arrancan en 3 tomos; un tramo {min:2} solo se admite con pct 0.
	 */
	public const DISCOUNT_MIN_QUANTITY = 3;

	public const PCT_MIN = 0;

	/**
	 * Tope del tramo. Ya no es una constante: es el MISMO número que acota el
	 * descuento editorial, y el dueño lo edita en Akibara → Descuentos
	 * ({@see DiscountCeiling}). Cuando estaban clavados por separado, subir uno
	 * dejaba al otro contradiciéndolo en la misma tienda.
	 *
	 * Sin option guardada devuelve 15, el literal de siempre.
	 */
	public static function pct_max(): int {
		return DiscountCeiling::pct();
	}

	/**
	 * Resuelve el porcentaje de descuento por volumen para una cantidad de
	 * tomos distintos.
	 *
	 * Selecciona el tramo de mayor `min` que no supera `$distinct_qty`
	 * (condición inclusiva `>=`, Req 2.5/2.6). Si `$distinct_qty` es menor a la
	 * `min` del primer tramo, devuelve 0 (Req 2.7). Tolera tramos desordenados
	 * y entradas malformadas (se ignoran de forma robusta).
	 *
	 * @param int                                  $distinct_qty Conteo de tomos DISTINTOS ya deduplicado.
	 * @param array<int, array{min:int,pct:int}>   $tiers        Tramos candidatos (posiblemente desordenados).
	 * @return int Porcentaje del tramo seleccionado, o 0 si ninguno aplica.
	 */
	public function resolve_pct( int $distinct_qty, array $tiers ): int {
		$best_min = null;
		$best_pct = 0;

		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) || ! isset( $tier['min'], $tier['pct'] ) ) {
				continue;
			}

			$min = (int) $tier['min'];
			$pct = (int) $tier['pct'];

			// El tramo solo aplica si su mínimo no supera la cantidad presente.
			if ( $min > $distinct_qty ) {
				continue;
			}

			// Nos quedamos con el tramo de mayor `min` alcanzado.
			if ( null === $best_min || $min > $best_min ) {
				$best_min = $min;
				$best_pct = $pct;
			}
		}

		return $best_pct;
	}

	/**
	 * Valida y comprueba la consistencia de un conjunto de tramos.
	 *
	 * Ordena por `min` ascendente (Req 2.3), valida `min >= 2` y `pct` entero
	 * entre `PCT_MIN` y el tope configurado de la tienda (Req 2.2 — el 15 fijo
	 * pasó a ser editable) y rechaza `min` duplicados (Req 2.4). En éxito devuelve
	 * `Result::ok()`; el caller que necesite los tramos ya ordenados puede
	 * obtenerlos con `sort_tiers()`.
	 *
	 * @param array<int, array{min:int,pct:int}> $tiers
	 */
	public function normalize_tiers( array $tiers ): Result {
		if ( empty( $tiers ) ) {
			return Result::error( 'Una serie habilitada debe tener al menos un tramo de descuento' );
		}

		$sorted   = $this->sort_tiers( $tiers );
		$seen     = array();
		$prev_pct = null;

		foreach ( $sorted as $tier ) {
			if ( ! is_array( $tier ) || ! isset( $tier['min'], $tier['pct'] ) ) {
				return Result::error( 'Cada tramo debe definir una cantidad mínima y un porcentaje' );
			}

			$min = (int) $tier['min'];
			$pct = (int) $tier['pct'];

			if ( $min < self::MIN_QUANTITY ) {
				return Result::error( 'La cantidad mínima de cada tramo debe ser al menos 2' );
			}

			if ( $pct < self::PCT_MIN || $pct > self::pct_max() ) {
				return Result::error(
					sprintf(
						'El porcentaje de cada tramo debe ser un entero entre %d y %d',
						self::PCT_MIN,
						self::pct_max()
					)
				);
			}

			// Guard anti-canibalización: un tramo con descuento (> 0%) debe aplicar
			// desde 3 tomos. Un 2-pack es compra que el cliente hace igual; no se regala.
			if ( $pct > 0 && $min < self::DISCOUNT_MIN_QUANTITY ) {
				return Result::error( 'Un tramo con descuento debe aplicar desde 3 tomos o más (un 2-pack no debe llevar descuento)' );
			}

			if ( isset( $seen[ $min ] ) ) {
				return Result::error( 'Cada tramo debe tener una cantidad mínima distinta' );
			}

			// Un tramo mayor con igual o menor descuento contradice la promesa
			// "más tomos, más baja el precio" (y con pct repetido el builder
			// renderizaba pills duplicadas, ej. "5+ · -10%" y "7+ · -10%").
			if ( null !== $prev_pct && $pct <= $prev_pct ) {
				return Result::error( 'Los porcentajes deben ser crecientes entre tramos (un tramo mayor no puede repetir ni bajar el descuento)' );
			}

			$seen[ $min ] = true;
			$prev_pct     = $pct;
		}

		return Result::ok();
	}

	/**
	 * Colapsa tramos consecutivos (ya ordenados por `min`) que repiten el mismo
	 * `pct`, conservando el de menor `min`. Pricing-neutral: un tramo de igual
	 * pct a mayor cantidad no cambia el descuento de ninguna cantidad — pero
	 * mostrado ("5+ · -10%" y "7+ · -10%") contradice la promesa de progresión.
	 * Sanea configs persistidos ANTES del guard de pct creciente de
	 * `normalize_tiers()`; espera input ordenado (usar `sort_tiers()` antes).
	 *
	 * @param array<int, array{min:int,pct:int}> $tiers Tramos ordenados por min.
	 * @return array<int, array{min:int,pct:int}>
	 */
	public function coalesce_equal_pct_tiers( array $tiers ): array {
		$coalesced = array();
		$prev_pct  = null;

		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) || ! isset( $tier['min'], $tier['pct'] ) ) {
				continue;
			}
			if ( null !== $prev_pct && (int) $tier['pct'] === $prev_pct ) {
				continue;
			}
			$coalesced[] = $tier;
			$prev_pct    = (int) $tier['pct'];
		}

		return $coalesced;
	}

	/**
	 * La FRASE con que se anuncia un tramo — fuente única del vocabulario.
	 *
	 * Vive acá y no en cada superficie por la misma razón que
	 * `Stock\StockStatus::label()` centraliza Disponible/Preventa/Agotado: desde
	 *  el mismo tramo se anuncia en DOS sitios —las pills del builder de
	 * la ficha y el bloque de la landing de serie— y un cliente puede leer los dos
	 * en el mismo minuto. Con el `sprintf` copiado, el día que alguien ajuste la
	 * redacción en uno la landing y la ficha describirían el mismo descuento con
	 * palabras distintas, que es la clase de incoherencia que nadie reporta como
	 * bug pero que resta confianza en el número.
	 *
	 * «Desde» es literal y no adorno: la selección del tramo es INCLUSIVA hacia
	 * arriba (`resolve_pct` toma el mayor `min` que no supera la cantidad), así
	 * que el tramo se gana desde esa cantidad y sigue valiendo con más tomos.
	 */
	public function tier_label( int $min, int $pct ): string {
		return sprintf( 'Desde %d tomos · %d%% dto.', $min, $pct );
	}

	/**
	 * Devuelve los tramos ordenados por `min` ascendente, de forma estable.
	 *
	 * Auxiliar público reutilizado internamente por `normalize_tiers()` y
	 * consumible por el caller que necesite persistir los tramos ya ordenados
	 * (Req 2.3). No valida: solo ordena de forma robusta.
	 *
	 * @param array<int, array{min:int,pct:int}> $tiers
	 * @return array<int, array{min:int,pct:int}>
	 */
	public function sort_tiers( array $tiers ): array {
		$sorted = array_values( $tiers );

		usort(
			$sorted,
			static function ( $a, $b ): int {
				$min_a = ( is_array( $a ) && isset( $a['min'] ) ) ? (int) $a['min'] : 0;
				$min_b = ( is_array( $b ) && isset( $b['min'] ) ) ? (int) $b['min'] : 0;
				return $min_a <=> $min_b;
			}
		);

		return $sorted;
	}
}
