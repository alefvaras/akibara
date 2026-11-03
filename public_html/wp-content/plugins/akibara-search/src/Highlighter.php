<?php
/**
 * Highlighter — resalta con <mark> los tokens del término en un texto (título
 * de la tarjeta del autocomplete). Paridad con excerpts-highlights de Relevanssi,
 * reimplementado nativo: server-side, escaping por segmento (seguro), matching
 * case-insensitive. Sin excerpts de cuerpo largo (nuestras tarjetas son
 * título+precio, no contenido).
 *
 * Diseño de seguridad: se escapa CADA segmento con esc_html y solo se inyecta el
 * `<mark>` propio — el título del producto NUNCA llega crudo al DOM. El estilo
 * lo da el selector de elemento `.akb-suggest-title mark` (rojo de marca, no el
 * amarillo del navegador): el <mark> viaja SIN clase — `akb-hl` no existía en
 * ninguna hoja y sólo sumaba bytes por coincidencia resaltada.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Highlighter {

	/**
	 * Devuelve el texto con los tokens que matchean envueltos en
	 * `<mark>…</mark>`. Escapado y seguro. Si no hay match,
	 * devuelve el texto escapado sin marcas.
	 *
	 * @param string             $text   Texto crudo (título del producto).
	 * @param array<int, string> $tokens Tokens del término (≥2 chars).
	 * @return string HTML escapado con marcas.
	 */
	public static function mark( string $text, array $tokens ): string {
		if ( '' === $text ) {
			return '';
		}

		// Tokens de longitud >= 2 caracteres o dígitos numéricos individuales (para resaltar tomos).
		$tokens = array_values(
			array_unique(
				array_filter(
					array_map( static fn( string $t ): string => trim( $t ), $tokens ),
					static fn( string $t ): bool => mb_strlen( $t ) >= 2 || 1 === preg_match( '/^\d$/', $t )
				)
			)
		);
		if ( empty( $tokens ) ) {
			return esc_html( $text );
		}

		// Token más largo primero: gana en solapes (evita marcar "one" dentro de
		// "one piece" cuando ambos matchean).
		usort( $tokens, static fn( string $a, string $b ): int => mb_strlen( $b ) <=> mb_strlen( $a ) );

		// Búsqueda insensible a mayúsculas y diacríticos mediante `Normalizer::fold()`,
		// permitiendo resaltar correctamente coincidencias sobre caracteres acentuados.
		$lower  = Normalizer::fold( $text );
		$ranges = array();
		foreach ( $tokens as $tok ) {
			$needle = Normalizer::fold( $tok );

			// Un número se marca como NÚMERO ENTERO y por su valor: «1» marca el
			// «001» de «One Piece 001» y no el 1 de «Tomo 10» ni el de «2019».
			if ( 1 === preg_match( '/^\d+$/', $needle ) ) {
				$valor = ltrim( $needle, '0' );
				if ( preg_match_all( '/(?<!\d)0*' . preg_quote( '' === $valor ? '0' : $valor, '/' ) . '(?!\d)/u', $lower, $m, PREG_OFFSET_CAPTURE ) ) {
					foreach ( $m[0] as $hit ) {
						// PREG_OFFSET_CAPTURE da BYTES; los rangos van en caracteres.
						$pos      = mb_strlen( substr( $lower, 0, (int) $hit[1] ) );
						$ranges[] = array( $pos, $pos + mb_strlen( (string) $hit[0] ) );
					}
				}
				continue;
			}

			$len = mb_strlen( $needle );
			$off = 0;
			while ( false !== ( $pos = mb_strpos( $lower, $needle, $off ) ) ) {
				$ranges[] = array( $pos, $pos + $len );
				$off      = $pos + $len;
			}
		}
		if ( empty( $ranges ) ) {
			return esc_html( $text );
		}

		// Ordenar y fusionar solapes.
		sort( $ranges );
		$merged = array();
		foreach ( $ranges as $r ) {
			$n = count( $merged );
			if ( $n > 0 && $r[0] <= $merged[ $n - 1 ][1] ) {
				$merged[ $n - 1 ][1] = max( $merged[ $n - 1 ][1], $r[1] );
			} else {
				$merged[] = $r;
			}
		}

		// Construir la salida escapando por segmento.
		$out    = '';
		$cursor = 0;
		$total  = mb_strlen( $text );
		foreach ( $merged as $range ) {
			list( $start, $end ) = $range;
			if ( $start > $cursor ) {
				$out .= esc_html( mb_substr( $text, $cursor, $start - $cursor ) );
			}
			$out   .= '<mark>' . esc_html( mb_substr( $text, $start, $end - $start ) ) . '</mark>';
			$cursor = $end;
		}
		if ( $cursor < $total ) {
			$out .= esc_html( mb_substr( $text, $cursor ) );
		}

		return $out;
	}
}
