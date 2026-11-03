<?php
/**
 * Fuzzy — recuperación de typos por edit-distance acotado. Corre SOLO como
 * fallback cuando la búsqueda de catálogo devuelve cero resultados (no en cada
 * query → no toca el hot path ni degrada búsquedas con resultados). Corrige cada
 * token que no exista en el vocabulario de títulos por el token más cercano
 * (distancia OSA —Levenshtein donde cambiar dos letras adyacentes cuesta 1—
 * ≤1 para tokens ≤5 chars, ≤2 para más largos) y el motor reintenta la
 * búsqueda UNA vez. "jujustu" → "jujutsu", "beserk" → "berserk", "balck" → "black".
 *
 * Clase PURA: correct()/nearest() no tocan la DB (el caller pasa el vocabulario),
 * así que son testeables sin WordPress. El flag `akb_search_fuzzy` (default OFF
 * hasta el cutover) la habilita. La corrección aplicada se expone por un
 * side-channel estático por-request para que la UI muestre "¿quisiste decir X?".
 *
 * Los tokens ya vienen normalizados (Normalizer translitera acentos → ASCII
 * a-z0-9), por eso `strlen` y la distancia byte a byte de `osa()` son correctos aquí.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Fuzzy {

	public const OPTION = 'akb_search_fuzzy';

	/** @var array<string, string> Última corrección aplicada (original→corregido), por request. */
	private static array $last = array();

	/**
	 * ¿Está habilitado el fuzzy? Flag `akb_search_fuzzy`, default OFF.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return 'yes' === SemanticPublication::authoritative_value( self::OPTION, 'no' );
	}

	/**
	 * Corrige tokens contra un vocabulario por edit-distance acotado. Los dígitos
	 * (tomos) y los tokens que YA están en el vocabulario no se tocan.
	 *
	 * Dos políticas de corrección:
	 *   - Modo laxo (por defecto): utilizado en búsqueda de catálogo; ante empates
	 *     selecciona el primer candidato más cercano.
	 *   - Modo estricto: utilizado en autocompletado (`/suggest`), donde ante cualquier
	 *     ambigüedad de distancia mínima descarta la sugerencia para no desviar la consulta (`nearest_strict()`).
	 *
	 * @param array<int, string> $tokens     Tokens de query normalizados.
	 * @param array<int, string> $vocabulary Tokens del índice de títulos.
	 * @param bool               $strict     Política estricta (suggest). @since 0.2.0
	 * @return array{tokens: array<int, string>, changed: bool, map: array<string, string>}
	 */
	public static function correct( array $tokens, array $vocabulary, bool $strict = false ): array {
		$vocab_set = array();
		foreach ( $vocabulary as $v ) {
			$vocab_set[ (string) $v ] = true;
		}

		$map = array();
		$out = array();
		foreach ( $tokens as $token ) {
			$token = (string) $token;
			if ( ctype_digit( $token ) || isset( $vocab_set[ $token ] ) ) {
				$out[] = $token;
				continue;
			}
			$best = $strict
				? self::nearest_strict( $token, $vocabulary )
				: self::nearest( $token, $vocabulary );
			if ( null !== $best && $best !== $token ) {
				$map[ $token ] = $best;
				$out[]         = $best;
			} else {
				$out[] = $token;
			}
		}

		return array(
			'tokens'  => $out,
			'changed' => ! empty( $map ),
			'map'     => $map,
		);
	}

	/**
	 * Token del vocabulario más cercano dentro del umbral, o null si ninguno
	 * calza. Umbral por longitud: ≤5 chars → distancia 1; más largo → 2. Poda por
	 * diferencia de longitud (distancia ≥ |Δlen|) para no comparar de más.
	 *
	 * Utiliza la métrica Damerau-Levenshtein / Optimal String Alignment (`osa()`),
	 * asignando costo 1 a transposiciones de letras adyacentes para asegurar consistencia
	 * entre el autocompletado y los resultados de catálogo.
	 *
	 * @param string             $token      Token a corregir.
	 * @param array<int, string> $vocabulary Vocabulario.
	 * @return string|null
	 */
	private static function nearest( string $token, array $vocabulary ): ?string {
		$len = strlen( $token );
		$max = $len <= 5 ? 1 : 2;

		$best   = null;
		$best_d = $max + 1;
		foreach ( $vocabulary as $cand ) {
			$cand = (string) $cand;
			if ( abs( strlen( $cand ) - $len ) > $max ) {
				continue;
			}
			$dist = self::osa( $token, $cand );
			if ( $dist < $best_d ) {
				$best_d = $dist;
				$best   = $cand;
				if ( 0 === $dist ) {
					break;
				}
			}
		}

		return $best_d <= $max ? $best : null;
	}

	/**
	 * Política ESTRICTA (suggest, spec v5): el token del vocabulario más cercano
	 * SOLO si la corrección es inequívoca. Pasos, en orden:
	 *
	 *   1. len >= 4 y sin dígitos (un token mixto tipo "hero2" jamás se corrige).
	 *   2. Distancia OSA (transposición adyacente = 1) sobre el pool GLOBAL,
	 *      umbral por largo del typo (<=5 → 1, >5 → 2). El escaneo usa ventana
	 *      Δlen/d <= max+1: las guardias deben VER competidores que la poda
	 *      estricta ocultaría (la fuente de "flre" es "flores", Δlen 2 con max 1).
	 *   3. Guardia de EMPATE global: >=2 candidatos a la distancia mínima → null.
	 *   4. Guardia de ALCANCE: si OTRO candidato tiene al typo dentro de SU
	 *      presupuesto de error (len<=5 → 1, >5 → 2), el typo también se explica
	 *      como error de esa palabra → ambigüedad → null. En un typo a distancia
	 *      2 de una fuente larga, la fuente siempre es alcanzable: 0 correcciones
	 *      equivocadas por construcción en esa banda (medido en
	 *      .design-qa/verify-fuzzy-v5.php, 134.095/134.095/0).
	 *   5. Ancla sobre el GANADOR: comparte la 1ª letra con el typo, o la
	 *      diferencia es la transposición del primer par ("asma"→"sama").
	 *
	 * @since 0.2.0
	 *
	 * @param string             $token      Token a corregir.
	 * @param array<int, string> $vocabulary Vocabulario.
	 * @return string|null
	 */
	private static function nearest_strict( string $token, array $vocabulary ): ?string {
		$len = strlen( $token );
		if ( $len < 4 || 1 === preg_match( '/[0-9]/', $token ) ) {
			return null;
		}
		$max    = $len <= 5 ? 1 : 2;
		$window = $max + 1;

		$best          = null;
		$best_d        = $window + 1;
		$ties          = 0;
		$reach_total   = 0;
		$best_in_reach = false;

		foreach ( $vocabulary as $cand ) {
			$cand = (string) $cand;
			if ( '' === $cand || abs( strlen( $cand ) - $len ) > $window ) {
				continue;
			}
			$dist = self::osa( $token, $cand );
			if ( $dist > $window ) {
				continue;
			}
			$in_reach = $dist <= ( strlen( $cand ) <= 5 ? 1 : 2 );
			if ( $in_reach ) {
				++$reach_total;
			}
			if ( $dist < $best_d ) {
				$best_d        = $dist;
				$best          = $cand;
				$ties          = 1;
				$best_in_reach = $in_reach;
			} elseif ( $dist === $best_d ) {
				++$ties;
			}
		}

		if ( null === $best || $best_d > $max || $ties > 1 ) {
			return null;
		}
		if ( $reach_total - ( $best_in_reach ? 1 : 0 ) > 0 ) {
			return null;
		}
		$transposed_pair = strlen( $best ) > 1 && $best[0] === $token[1] && $best[1] === $token[0];
		if ( $best[0] !== $token[0] && ! $transposed_pair ) {
			return null;
		}

		return $best;
	}

	/**
	 * Distancia OSA (optimal string alignment: Levenshtein + transposición
	 * adyacente a costo 1). DP clásica; los tokens ya vienen transliterados a
	 * ASCII por Normalizer, por eso el acceso por byte es correcto.
	 *
	 * @since 0.2.0
	 *
	 * @param string $a Cadena A.
	 * @param string $b Cadena B.
	 * @return int
	 */
	private static function osa( string $a, string $b ): int {
		$la = strlen( $a );
		$lb = strlen( $b );
		$d  = array();
		for ( $i = 0; $i <= $la; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $lb; $j++ ) {
			$d[0][ $j ] = $j;
		}
		for ( $i = 1; $i <= $la; $i++ ) {
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost         = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
			}
		}
		return $d[ $la ][ $lb ];
	}

	/**
	 * Registra la corrección aplicada en esta request (para la etiqueta de la UI).
	 *
	 * @param array<string, string> $map original→corregido.
	 * @return void
	 */
	public static function note_correction( array $map ): void {
		self::$last = $map;
	}

	/**
	 * Última corrección aplicada en la request (original→corregido), o vacío.
	 *
	 * @return array<string, string>
	 */
	public static function last_correction(): array {
		return self::$last;
	}

	/**
	 * Reset del side-channel (tests, o inicio de una búsqueda de catálogo).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$last = array();
	}
}
