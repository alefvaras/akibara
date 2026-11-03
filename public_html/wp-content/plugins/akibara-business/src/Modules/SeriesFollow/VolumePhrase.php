<?php
/**
 * VolumePhrase — cómo se nombran los tomos que cubre un bloque del aviso.
 *
 * Puro y estático, mismo carácter que `CutoffPhrase`: el runner resuelve la
 * frase, la plantilla sólo la imprime.
 *
 * QUÉ NUMERA, con precisión, porque esto se le enseña al cliente: los tomos de
 * ESA serie que viajan en ESTE correo para ESE cliente. Los publicados dentro de
 * la ventana del digest, que él NO tiene (`without_owned`), que siguen
 * publicados y de cuya serie no se dio de baja. NO es «los tomos que te faltan
 * de la serie» —uno publicado hace tres meses que nunca compró no está en la
 * cola—, y NO es «los que publicó la tienda» —dos clientes de la misma tanda
 * reciben números distintos y eso es correcto—.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.115.21
 */
final class VolumePhrase {

	/**
	 * Hasta cuántos tomos se nombran uno a uno antes de pasar a los extremos.
	 *
	 * Seis NO es un número inventado: es `BuilderWidget::COMPLETE_MAX_MISSING`,
	 * el corte que la casa ya eligió para cuántos tomos caben en una frase antes
	 * de desmotivar. Fijo en código y no en el panel a propósito: no es una
	 * preferencia editorial, es el ancho de una línea, y el dueño ya tiene
	 * cuatro palancas en ese tab.
	 */
	private const ENUMERAR_HASTA = 6;

	/**
	 * A partir de tres, un tramo contiguo se lee mejor como rango que como lista.
	 */
	private const RANGO_DESDE = 3;

	/**
	 * Números limpios: enteros, positivos, únicos y de menor a mayor.
	 *
	 * UNA sola normalización para todo el módulo. `volumes_count` y
	 * `volumes_phrase` salen de este mismo array, y por eso el número que dice
	 * el asunto no puede contradecir a la lista que dice el bloque. Los únicos
	 * importan: dos productos con el mismo número (una reedición) son UN tomo.
	 *
	 * @param array<int, int> $volumes Tomos crudos.
	 * @return array<int, int>
	 */
	public static function normalize( array $volumes ): array {
		$limpios = array_filter(
			array_map( 'intval', $volumes ),
			static fn ( int $tomo ): bool => $tomo > 0
		);
		$limpios = array_values( array_unique( $limpios ) );
		sort( $limpios, SORT_NUMERIC );

		return $limpios;
	}

	/**
	 * La frase, SIN la palabra «tomo»: la pone la plantilla, que es la que sabe
	 * si va en singular o en plural.
	 *
	 * `71 al 74` PROMETE el 72 y el 73, así que sólo se escribe cuando el tramo
	 * es contiguo de verdad. Fire Force salió 1,5..33 y Demon Slayer
	 * 2,3,4,5,21,22 (tandas reales del catálogo): ahí el rango sería falso y la
	 * frase honesta más corta es nombrar los extremos SIN decir «todos».
	 *
	 * Sin `number_format_i18n()`: un tomo 1023 no son mil veintitrés pesos, y el
	 * separador de miles lo convertiría en «1.023».
	 *
	 * @param array<int, int> $volumes Tomos del bloque, incluido el que se muestra.
	 * @return string
	 */
	public static function of( array $volumes ): string {
		$tomos = self::normalize( $volumes );
		$total = count( $tomos );

		if ( 0 === $total ) {
			return '';
		}
		if ( 1 === $total ) {
			return (string) $tomos[0];
		}

		$min      = (int) $tomos[0];
		$max      = (int) $tomos[ $total - 1 ];
		$contiguo = ( $max - $min + 1 ) === $total;

		if ( $contiguo && $total >= self::RANGO_DESDE ) {
			return $min . ' al ' . $max;
		}
		if ( $total > self::ENUMERAR_HASTA ) {
			return 'entre el ' . $min . ' y el ' . $max;
		}

		$ultimo = array_pop( $tomos );

		return implode( ', ', array_map( 'strval', $tomos ) ) . ' y ' . $ultimo;
	}
}
