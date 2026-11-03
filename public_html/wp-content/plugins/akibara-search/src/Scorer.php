<?php
/**
 * Scorer — ranking de un documento contra la query (clase PURA, testeable).
 *
 * Evoluciona HitsComparator de "comparar 2 títulos" a "puntuar 1 doc contra
 * la query", operando sobre los campos YA normalizados del índice
 * (title_norm, serie_norm, autores_norm, tomo) — cero acceso a WP, cero SQL.
 *
 * Modelo de puntaje determinista:
 *   TIER base según DÓNDE calza la query (de mejor a peor):
 *     - serie coincide y tomo coincide exactamente .... 1200
 *     - título empieza con la frase exacta ............ 1000
 *     - título contiene la frase exacta ................ 800
 *     - todos los tokens en el título (no como frase) .. 600
 *     - todos los tokens en la serie ................... 400
 *     - calza vía autor ................................ 250
 *     - solo vía descripción/otros (tier residual) ..... 100
 *   BONUS:
 *     - un token numérico == tomo del doc ............. +300
 *       (prioriza el tomo exacto en consultas combinadas).
 *   PENALTY por disponibilidad (stock_rank): agotado castiga fuerte,
 *     preventa un poco — un tomo comprable le gana a uno agotado a igualdad
 *     de relevancia, sin sacarlo del set.
 *   DESEMPATE (mismo score): tomo asc (orden natural de la serie) →
 *     menu_order → strnatcmp del título (los tomos recopilatorios sin número
 *     se ordenan tras los tomos regulares numerados).
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Scorer {

	public const TIER_TITLE_PREFIX = 1000;
	public const TIER_TITLE_PHRASE = 800;
	public const TIER_TITLE_TOKENS = 600;
	public const TIER_SERIE_TOKENS = 400;
	public const TIER_AUTHOR       = 250;
	public const TIER_RESIDUAL     = 100;

	public const BONUS_TOMO_EXACT = 300;

	/**
	 * El documento que el cliente NOMBRÓ: su serie coincide y su tomo es
	 * exactamente el número que escribió.
	 *
	 * Va por encima de {@see self::TIER_TITLE_PREFIX} porque el prefijo de
	 * título, cuando lo que se prolonga es un NÚMERO, es una coincidencia
	 * accidental de dígitos: «one piece 100» empieza con «one piece 1» y se
	 * llevaba los 1000 puntos, mientras «One Piece 001» o «One Piece 1» —el tomo
	 * pedido por el usuario— recibía menor puntuación por prefijo de texto.
	 *
	 * El tomo 1 es la puerta de entrada habitual a una serie y debe liderar
	 * la respuesta ante una consulta exacta de volumen.
	 *
	 * La precedencia no bloquea consultas numéricas parciales durante el tipeo:
	 * los candidatos extendidos (como tomos 10 a 19 o 100) continúan presentes
	 * en el conjunto ordenados por relevancia.
	 */
	public const TIER_TOMO_NOMBRADO = 1200;

	/** Penalización por disponibilidad, indexada por stock_rank. */
	private const STOCK_PENALTY = array(
		0 => 0,    // instock.
		1 => 40,   // onbackorder (preventa).
		2 => 250,  // outofstock (agotado): fuerte, pero no lo saca del set.
	);

	/**
	 * Puntúa un doc del índice contra la query normalizada.
	 *
	 * @param array{title_norm: string, serie_norm: string, autores_norm: string, tomo: ?int, stock_rank: int} $doc  Campos del doc.
	 * @param string                                                                                            $query_norm Query normalizada (Normalizer::normalize).
	 * @param array<int, string>                                                                                $tokens     Tokens de la query (Normalizer::tokenize).
	 * @return int Puntaje; mayor = más relevante.
	 */
	public static function score( array $doc, string $query_norm, array $tokens ): int {
		$title   = (string) ( $doc['title_norm'] ?? '' );
		$serie   = (string) ( $doc['serie_norm'] ?? '' );
		$autores = (string) ( $doc['autores_norm'] ?? '' );

		$score = self::tier( $title, $serie, $autores, $query_norm, $tokens );
		if ( self::tomo_nombrado( $doc, $title, $serie, $tokens ) ) {
			$score = max( $score, self::TIER_TOMO_NOMBRADO );
		}
		$score += self::tomo_bonus( $doc, $tokens );
		$score -= self::STOCK_PENALTY[ (int) ( $doc['stock_rank'] ?? 2 ) ] ?? self::STOCK_PENALTY[2];

		return $score;
	}

	/**
	 * Comparador para usort de docs ya puntuados. Espera filas con
	 * `_score` (int), `tomo` (?int), `menu_order` (int), `title_norm` (string).
	 *
	 * @param array<string, mixed> $a Doc A.
	 * @param array<string, mixed> $b Doc B.
	 * @return int
	 */
	public static function compare_scored( array $a, array $b ): int {
		// Puntaje desc.
		$cmp = (int) $b['_score'] <=> (int) $a['_score'];
		if ( 0 !== $cmp ) {
			return $cmp;
		}

		// Tomo asc — pero un doc CON tomo va antes que uno sin tomo (ómnibus).
		$a_tomo = isset( $a['tomo'] ) && null !== $a['tomo'] ? (int) $a['tomo'] : null;
		$b_tomo = isset( $b['tomo'] ) && null !== $b['tomo'] ? (int) $b['tomo'] : null;
		if ( null !== $a_tomo && null !== $b_tomo && $a_tomo !== $b_tomo ) {
			return $a_tomo <=> $b_tomo;
		}
		if ( ( null !== $a_tomo ) !== ( null !== $b_tomo ) ) {
			return null !== $a_tomo ? -1 : 1;
		}

		// menu_order asc.
		$cmp = (int) ( $a['menu_order'] ?? 0 ) <=> (int) ( $b['menu_order'] ?? 0 );
		if ( 0 !== $cmp ) {
			return $cmp;
		}

		// Orden natural del título (01, 02, ... 10, 11 — no 1, 10, 11, 2).
		return strnatcmp( (string) ( $a['title_norm'] ?? '' ), (string) ( $b['title_norm'] ?? '' ) );
	}

	/**
	 * Determina el tier base de match.
	 *
	 * @param string             $title   title_norm.
	 * @param string             $serie   serie_norm.
	 * @param string             $autores autores_norm.
	 * @param string             $query   Query normalizada.
	 * @param array<int, string> $tokens  Tokens de la query.
	 * @return int
	 */
	private static function tier( string $title, string $serie, string $autores, string $query, array $tokens ): int {
		if ( '' !== $query ) {
			if ( 0 === strpos( $title, $query ) ) {
				return self::TIER_TITLE_PREFIX;
			}
			if ( false !== strpos( $title, $query ) ) {
				return self::TIER_TITLE_PHRASE;
			}
		}

		if ( self::all_tokens_in( $title, $tokens ) ) {
			return self::TIER_TITLE_TOKENS;
		}
		if ( self::all_tokens_in( $serie, $tokens ) ) {
			return self::TIER_SERIE_TOKENS;
		}
		if ( self::all_tokens_in( $autores, $tokens ) ) {
			return self::TIER_AUTHOR;
		}

		return self::TIER_RESIDUAL;
	}

	/**
	 * Bonus si algún token numérico de la query coincide EXACTO con el tomo.
	 * Compara por valor entero (26 == "26" == "026").
	 *
	 * @param array{tomo?: ?int} $doc    Doc.
	 * @param array<int, string> $tokens Tokens de la query.
	 * @return int
	 */
	private static function tomo_bonus( array $doc, array $tokens ): int {
		$tomo = isset( $doc['tomo'] ) && null !== $doc['tomo'] ? (int) $doc['tomo'] : null;
		if ( null === $tomo ) {
			return 0;
		}

		foreach ( $tokens as $token ) {
			if ( ctype_digit( $token ) && (int) $token === $tomo ) {
				return self::BONUS_TOMO_EXACT;
			}
		}

		return 0;
	}

	/**
	 * ¿Es este el documento que el cliente NOMBRÓ?
	 *
	 * Dos condiciones a la vez, y las dos hacen falta:
	 *   1. todas las PALABRAS de la consulta están en el título o en la serie, y
	 *   2. algún número de la consulta es exactamente su tomo.
	 *
	 * La primera es la que hace seguro subir el tier: sin ella, «one piece 1»
	 * pondría «Naruto 001» por delante de todo por tener el tomo 1. La segunda es
	 * la que distingue el acierto del roce: «one piece 100» comparte el prefijo
	 * de dígitos pero su tomo no es el que se pidió.
	 *
	 * Una consulta de sólo números no nombra ninguna serie y no gana el tier:
	 * escribir «1» no es pedir el tomo 1 de nada en concreto.
	 *
	 * @param array{tomo?: ?int}  $doc    Doc.
	 * @param string              $title  title_norm.
	 * @param string              $serie  serie_norm.
	 * @param array<int, string>  $tokens Tokens de la query.
	 * @return bool
	 */
	private static function tomo_nombrado( array $doc, string $title, string $serie, array $tokens ): bool {
		if ( 0 === self::tomo_bonus( $doc, $tokens ) ) {
			return false;
		}

		$palabras = array_values(
			array_filter(
				$tokens,
				static function ( string $token ): bool {
					return ! ctype_digit( $token );
				}
			)
		);
		if ( array() === $palabras ) {
			return false;
		}

		return self::all_tokens_in( $title, $palabras ) || self::all_tokens_in( $serie, $palabras );
	}

	/**
	 * True si TODOS los tokens alfabéticos aparecen (como prefijo de palabra o
	 * substring) en el texto. Los tokens numéricos se comparan por igualdad de
	 * palabra para no dar falsos positivos ("1" dentro de "100").
	 *
	 * @param string             $haystack Texto normalizado.
	 * @param array<int, string> $tokens   Tokens.
	 * @return bool
	 */
	private static function all_tokens_in( string $haystack, array $tokens ): bool {
		if ( '' === $haystack || empty( $tokens ) ) {
			return false;
		}

		$words = array_flip( explode( ' ', $haystack ) );

		foreach ( $tokens as $token ) {
			if ( ctype_digit( $token ) ) {
				// Un tomo calza si CUALQUIER forma canónica es palabra del
				// texto: la query "haikyu 1" (tokens {1, 01}) matchea el título
				// paddeado "haikyu 01". Evita el falso positivo de "1" en "100"
				// (compara palabra completa, no substring).
				$forms   = array_merge( array( $token ), Normalizer::numeric_variants( $token ) );
				$present = false;
				foreach ( $forms as $form ) {
					if ( isset( $words[ $form ] ) ) {
						$present = true;
						break;
					}
				}
				if ( ! $present ) {
					return false;
				}
				continue;
			}
			// Alfabético: substring cubre prefijo Y contenido ("berser" en
			// "maximum berserk"); barato para textos cortos de catálogo.
			if ( false === strpos( $haystack, $token ) ) {
				return false;
			}
		}

		return true;
	}
}
