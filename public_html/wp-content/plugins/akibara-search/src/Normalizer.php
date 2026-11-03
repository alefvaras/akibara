<?php
/**
 * Normalizer — normalización y tokenización determinista del texto de búsqueda.
 *
 * Clase pura orientada a pruebas unitarias sin dependencias externas de WordPress.
 *
 * Reglas de normalización:
 *   - Lowercase + fold de caracteres diacríticos en memoria PHP para almacenar
 *     tokens normalizados en columnas de base de datos con collation estricta `utf8mb4_bin`.
 *   - Normalización de «ñ» a «n» para coincidir con patrones habituales de teclado y catálogo.
 *   - Sin eliminación de términos comunes ni números en indexación: patrones de compra
 *     tales como "{serie} {tomo}" o "3 en 1" preservan todos sus componentes.
 *   - Normalización dual de tokens numéricos: compatibiliza variantes con/sin relleno de ceros
 *     (ej. "1" indexa y empareja con "01").
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.55.0
 */
final class Normalizer {

	/** Largo máximo de un token — cap del VARCHAR(40) del schema. */
	public const MAX_TOKEN_LENGTH = 40;

	/**
	 * Mapa de fold de diacríticos. Cubre español + los macrons japoneses
	 * habituales en títulos romanizados (Tōkyō, Jūbei). Se aplica DESPUÉS
	 * de mb_strtolower, por eso solo minúsculas.
	 *
	 * @var array<string, string>
	 */
	private const FOLD_MAP = array(
		'á' => 'a',
		'à' => 'a',
		'ä' => 'a',
		'â' => 'a',
		'ã' => 'a',
		'å' => 'a',
		'ā' => 'a',
		'é' => 'e',
		'è' => 'e',
		'ë' => 'e',
		'ê' => 'e',
		'ē' => 'e',
		'í' => 'i',
		'ì' => 'i',
		'ï' => 'i',
		'î' => 'i',
		'ī' => 'i',
		'ó' => 'o',
		'ò' => 'o',
		'ö' => 'o',
		'ô' => 'o',
		'õ' => 'o',
		'ō' => 'o',
		'ú' => 'u',
		'ù' => 'u',
		'ü' => 'u',
		'û' => 'u',
		'ū' => 'u',
		'ñ' => 'n',
		'ç' => 'c',
	);

	/**
	 * Minúsculas y sin diacríticos, CARÁCTER A CARÁCTER.
	 *
	 * A diferencia de {@see self::normalize()}, no quita ni colapsa nada: cada
	 * carácter del resultado ocupa la posición del original (el mapa es 1:1),
	 * así que sirve para BUSCAR dentro de un texto y volver a sus posiciones.
	 * Es lo que necesita el resaltado: «pokemon» tiene que poder marcar
	 * «Pokémon» en el título tal como se escribe.
	 *
	 * @param string $text Texto crudo.
	 * @return string Mismo largo en caracteres que `$text`.
	 */
	public static function fold( string $text ): string {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			return strtr( strtolower( $text ), self::FOLD_MAP );
		}

		$lower = mb_strtolower( $text, 'UTF-8' );

		// Manejo de caracteres Unicode especiales (ej. 'İ' turca) que pueden expandirse
		// a secuencias combinadas: asegura correspondencia 1:1 en longitud de caracteres.
		if ( mb_strlen( $lower, 'UTF-8' ) !== mb_strlen( $text, 'UTF-8' ) ) {
			$lower = '';
			foreach ( mb_str_split( $text, 1, 'UTF-8' ) as $caracter ) {
				$lower .= mb_substr( mb_strtolower( $caracter, 'UTF-8' ), 0, 1, 'UTF-8' );
			}
		}

		return strtr( $lower, self::FOLD_MAP );
	}

	/**
	 * Normaliza texto: lowercase, fold de diacríticos, todo lo no-alfanumérico
	 * a espacio, whitespace colapsado.
	 *
	 * "Haikyu!! Nº 01 – Ivrea" → "haikyu n 01 ivrea"
	 * "Nausicaä del Valle del Viento" → "nausicaa del valle del viento"
	 *
	 * @param string $text Texto crudo.
	 * @return string
	 */
	public static function normalize( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$text = self::fold( $text );

		// Todo lo que no sea [a-z0-9] pasa a espacio: puntuación, símbolos
		// (nº → "n" + espacio), kanji/kana (fuera del alcance v1) y restos
		// multibyte no mapeados.
		$text = (string) preg_replace( '/[^a-z0-9]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Tokeniza texto YA normalizado (o crudo — normaliza primero) en tokens
	 * únicos. Cada token numérico agrega su forma canónica doble:
	 * "1" → {"1","01"}, "01" → {"01","1"}, "107" → {"107"} (sin cambio).
	 *
	 * @param string $text Texto a tokenizar.
	 * @return array<int, string> Tokens únicos, orden de aparición.
	 */
	public static function tokenize( string $text ): array {
		$normalized = self::normalize( $text );
		if ( '' === $normalized ) {
			return array();
		}

		$tokens = array();
		foreach ( explode( ' ', $normalized ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
				$token = substr( $token, 0, self::MAX_TOKEN_LENGTH );
			}
			$tokens[ $token ] = true;

			foreach ( self::numeric_variants( $token ) as $variant ) {
				$tokens[ $variant ] = true;
			}
		}

		// strval: PHP castea keys numéricas canónicas a int ("1" → 1, "107" →
		// 107 — "01" queda string); los tokens son SIEMPRE strings.
		return array_map( 'strval', array_keys( $tokens ) );
	}

	/**
	 * Genera variantes canónicas adicionales para un token numérico (ej. "1" ↔ "01").
	 *
	 * @param string $token Token normalizado.
	 * @return array<int, string> Variantes adicionales (sin incluir el original).
	 */
	public static function numeric_variants( string $token ): array {
		if ( ! ctype_digit( $token ) ) {
			return array();
		}

		$variants = array();
		$trimmed  = ltrim( $token, '0' );
		if ( '' === $trimmed ) {
			$trimmed = '0';
		}

		if ( $trimmed !== $token ) {
			// "01" → "1", "007" → "7".
			$variants[] = $trimmed;
		} elseif ( 1 === strlen( $token ) ) {
			// "1" → "01" (el padding del catálogo es a 2 dígitos).
			$variants[] = '0' . $token;
		}

		return $variants;
	}

	/**
	 * Extrae el número de tomo del título según el patrón del catálogo
	 * "{Serie} (Colección) {NN}" — el número SIEMPRE al final (regla dura de
	 * naming del proyecto; el auto-padding del bootstrap la refuerza al
	 * guardar). Tolera el sufijo editorial legacy "NN – Editorial" (mismo
	 * caso que cubre el regex de auto-padding del bootstrap), siempre que el
	 * sufijo no contenga dígitos.
	 *
	 * "One Piece nº 01 (3 en 1)" NO extrae tomo (el paréntesis final tiene
	 * dígitos): los ómnibus caen al grupo fallback, misma semántica que
	 * HitsComparator.
	 *
	 * @param string $title Título crudo del producto.
	 * @return int|null Tomo, o null si el título no termina en número.
	 */
	public static function tomo_from_title( string $title ): ?int {
		if ( ! preg_match( '/(\d{1,4})\s*(?:[–\-—][^0-9]*)?$/u', trim( $title ), $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}

	/**
	 * Normaliza una query de usuario para el qlog agregado: normalize() + cap
	 * de largo (191 = PK del schema) — el scrub de PII (emails, teléfonos) es
	 * responsabilidad del endpoint, no de esta clase pura.
	 *
	 * @param string $query Query cruda.
	 * @return string
	 */
	public static function normalize_query_for_log( string $query ): string {
		$normalized = self::normalize( $query );
		if ( strlen( $normalized ) > 191 ) {
			$normalized = substr( $normalized, 0, 191 );
		}
		return $normalized;
	}
}
