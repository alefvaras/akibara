<?php
/**
 * Utilidades puras para interpretar y ordenar números de tomo.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Fuente única para el orden de lectura de productos seriados.
 *
 * Los títulos del catálogo suelen terminar en un número con padding
 * (`One Piece 099`), pero también existen sufijos editoriales
 * (`One Piece nº 01 (3 en 1)`) y productos especiales que no representan un
 * tomo lineal (`Shangri La Frontier 1 Expansion Pass`). La extracción es
 * deliberadamente estricta: cuando no hay un número de tomo inequívoco al
 * final, devuelve null y evita recomendaciones comerciales incorrectas.
 */
final class VolumeTitle {

	/**
	 * Extrae el número de tomo inequívoco de un título.
	 *
	 * Se eliminan únicamente sufijos finales entre paréntesis o corchetes. Esto
	 * permite interpretar `Maximum Berserk 21 (Portada alternativa)` sin
	 * confundir números descriptivos del sufijo con el tomo. Los decimales
	 * (`1.5`) se consideran ediciones especiales y no una secuencia entera.
	 */
	public static function extract_number( string $title ): ?int {
		$title = trim( strip_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) );
		if ( '' === $title ) {
			return null;
		}

		$previous = null;
		while ( $previous !== $title ) {
			$previous = $title;
			$title    = trim(
				(string) preg_replace(
					'/\s*(?:\([^()]*\)|\[[^\[\]]*\])\s*$/u',
					'',
					$title
				)
			);
		}

		if ( ! preg_match( '/(?:^|[\s#º°-])0*(\d{1,4})\s*$/u', $title, $matches ) ) {
			return null;
		}

		// Evita interpretar el último componente de un decimal como tomo.
		$match_offset = strrpos( $title, $matches[0] );
		if ( false !== $match_offset ) {
			$prefix = substr( $title, 0, $match_offset );
			if ( preg_match( '/\d\.\s*$/u', $prefix ) ) {
				return null;
			}
		}

		$number = (int) $matches[1];
		return $number > 0 ? $number : null;
	}

	/**
	 * Paddea a dos dígitos el número de tomo de un dígito de un título.
	 *
	 * El catálogo ordena por `post_title`, así que sin padding la serie salta de
	 * «Naruto Gaiden 1» a «Naruto Gaiden 10» y deja el 2 detrás. La regla se
	 * aplica al guardar cada producto (filtro `wp_insert_post_data`) y vive acá
	 * para poder probarse: dentro del cierre del bootstrap no era alcanzable.
	 *
	 * El dígito tiene que ir precedido de un espacio —lo que descarta `1.5`,
	 * `1+2+3` y `Ranma 1/2`— y seguido de un guion editorial, de un sufijo entre
	 * paréntesis que cierra el título («X 5 (Edición Especial)») o del final. Sin
	 * la rama del paréntesis, la primera variante de un tomo de un dígito se
	 * quedaba sin paddear y el orden de la landing la mandaba detrás del 40.
	 *
	 * @param string $title Título tal como llega del formulario.
	 * @return string Título con el número paddeado, o el mismo si no aplica.
	 */
	public static function pad_single_digit( string $title ): string {
		$padded = preg_replace_callback(
			'/(?<=\s)([1-9])(\s+[–\-—]|\s+\([^()]*\)\s*$|(?:\s*$))/u',
			static fn( array $matches ): string => '0' . $matches[1] . $matches[2],
			$title
		);

		return is_string( $padded ) ? $padded : $title;
	}

	/**
	 * Ordena DTOs por tomo, dejando los especiales al final.
	 *
	 * Si el caller ya calculó `volume_number`, se respeta. En caso contrario se
	 * deriva desde `title`. Los empates se resuelven de forma natural y estable
	 * por título e ID.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @return array<int, array<string, mixed>>
	 */
	public static function sort_records( array $records ): array {
		foreach ( $records as $index => &$record ) {
			if ( ! array_key_exists( 'volume_number', $record ) ) {
				$record['volume_number'] = self::extract_number( (string) ( $record['title'] ?? '' ) );
			}
			$record['_akb_original_index'] = $index;
		}
		unset( $record );

		usort(
			$records,
			static function ( array $a, array $b ): int {
				$number_a = isset( $a['volume_number'] ) ? (int) $a['volume_number'] : null;
				$number_b = isset( $b['volume_number'] ) ? (int) $b['volume_number'] : null;

				if ( null === $number_a && null !== $number_b ) {
					return 1;
				}
				if ( null !== $number_a && null === $number_b ) {
					return -1;
				}
				if ( null !== $number_a && null !== $number_b && $number_a !== $number_b ) {
					return $number_a <=> $number_b;
				}

				$title_compare = strnatcasecmp(
					(string) ( $a['title'] ?? '' ),
					(string) ( $b['title'] ?? '' )
				);
				if ( 0 !== $title_compare ) {
					return $title_compare;
				}

				$id_compare = (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
				if ( 0 !== $id_compare ) {
					return $id_compare;
				}

				return (int) $a['_akb_original_index'] <=> (int) $b['_akb_original_index'];
			}
		);

		foreach ( $records as &$record ) {
			unset( $record['_akb_original_index'] );
		}
		unset( $record );

		return $records;
	}

	/**
	 * Selecciona los tomos posteriores más cercanos sin duplicar volumen.
	 *
	 * @param array<int, array<string, mixed>> $records
	 * @param array<int, int>                  $excluded_ids
	 * @return array<int, array<string, mixed>>
	 */
	public static function next_records(
		array $records,
		int $current_number,
		int $limit,
		array $excluded_ids = array()
	): array {
		if ( $limit < 1 ) {
			return array();
		}

		$excluded_lookup = array_fill_keys( array_map( 'intval', $excluded_ids ), true );
		$seen_ids        = array();
		$seen_numbers    = array();
		$next            = array();

		foreach ( self::sort_records( $records ) as $record ) {
			$id     = (int) ( $record['id'] ?? 0 );
			$number = isset( $record['volume_number'] ) ? (int) $record['volume_number'] : null;
			if ( $id < 1
				|| null === $number
				|| $number <= $current_number
				|| isset( $excluded_lookup[ $id ] )
				|| isset( $seen_ids[ $id ] )
				|| isset( $seen_numbers[ $number ] ) ) {
				continue;
			}

			$seen_ids[ $id ]         = true;
			$seen_numbers[ $number ] = true;
			$next[]                  = $record;

			if ( count( $next ) >= $limit ) {
				break;
			}
		}

		return $next;
	}
}
