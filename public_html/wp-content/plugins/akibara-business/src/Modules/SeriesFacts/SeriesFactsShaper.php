<?php
/**
 * Normaliza y estructura los resultados agregados SQL de la ficha de serie.
 *
 * Transforma la fila única devuelta por SeriesFacts::query_row() en un diccionario
 * tipado y validado, sin suposiciones ni datos inferidos.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFacts;

use Akibara\Business\Services\VolumeTitle;

defined( 'ABSPATH' ) || exit;

/**
 * Conformador de hechos de catálogo para una serie.
 *
 * @since 1.120.4
 */
final class SeriesFactsShaper {

	/**
	 * Separador ENTRE elementos de una lista del GROUP_CONCAT (Record Separator, 0x1E).
	 */
	public const LIST_SEPARATOR = "\x1e";

	/**
	 * Separador DENTRO de cada par nombre/term_id (Unit Separator, 0x1F).
	 */
	public const PAIR_SEPARATOR = "\x1f";

	/**
	 * Patrón para extraer el número del tomo del título.
	 */
	public const VOLUME_PATTERN = '/^(.*\S)\s+(\d+)\s*$/u';

	/**
	 * Umbral de sospecha de truncamiento del GROUP_CONCAT de títulos, en bytes.
	 */
	public const CONCAT_SAFE_BYTES = 1000;

	/**
	 * Normaliza la fila única de la consulta en la estructura de hechos.
	 *
	 * @param array<string, mixed> $row Fila de query_row().
	 * @return array{tomos:int,instock:int,preventa:int,agotados:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int,agotados_tomos:array<int,string>,price_min:?float,price_max:?float,updated:string,autores:array<int,array{name:string,term_id:int}>,editoriales:array<int,array{name:string,term_id:int}>,ultimo_tomo:array{id:int,numero:int}|null}
	 */
	public static function shape( array $row ): array {
		$min = isset( $row['price_min'] ) ? (float) $row['price_min'] : 0.0;
		$max = isset( $row['price_max'] ) ? (float) $row['price_max'] : 0.0;

		$agotados  = max( 0, (int) ( $row['agotados'] ?? 0 ) );
		$prices_ok = $min > 0 && $max > 0 && $max >= $min;

		return array_merge(
			self::extract_counts( $row ),
			array(
				'agotados'       => $agotados,
				'agotados_tomos' => self::out_of_stock_volumes( (string) ( $row['agotados_titulos'] ?? '' ), $agotados ),
				'price_min'      => $prices_ok ? $min : null,
				'price_max'      => $prices_ok ? $max : null,
				'updated'        => self::iso_date( (string) ( $row['updated'] ?? '' ) ),
				'autores'        => self::term_pairs( (string) ( $row['autores'] ?? '' ) ),
				'editoriales'    => self::term_pairs( (string) ( $row['editoriales'] ?? '' ) ),
				'ultimo_tomo'    => self::latest_volume( (string) ( $row['ultimo_tomo'] ?? '' ) ),
			)
		);
	}

	/**
	 * Extrae los conteos enteros de tomos y productos.
	 *
	 * @param array<string, mixed> $row Fila cruda.
	 * @return array{tomos:int,instock:int,preventa:int,productos:int,productos_instock:int,productos_preventa:int,productos_agotados:int}
	 */
	private static function extract_counts( array $row ): array {
		return array(
			'tomos'              => max( 0, (int) ( $row['tomos'] ?? 0 ) ),
			'instock'            => max( 0, (int) ( $row['instock'] ?? 0 ) ),
			'preventa'           => max( 0, (int) ( $row['preventa'] ?? 0 ) ),
			'productos'          => max( 0, (int) ( $row['productos'] ?? 0 ) ),
			'productos_instock'  => max( 0, (int) ( $row['productos_instock'] ?? 0 ) ),
			'productos_preventa' => max( 0, (int) ( $row['productos_preventa'] ?? 0 ) ),
			'productos_agotados' => max( 0, (int) ( $row['productos_agotados'] ?? 0 ) ),
		);
	}

	/**
	 * ID y número del tomo más nuevo, o null si no se puede afirmar.
	 *
	 * @param string $pair título<US>ID de query_row().
	 * @return array{id:int,numero:int}|null
	 */
	public static function latest_volume( string $pair ): ?array {
		$cut = strrpos( $pair, self::PAIR_SEPARATOR );
		if ( false === $cut ) {
			return null;
		}

		$id     = (int) substr( $pair, $cut + strlen( self::PAIR_SEPARATOR ) );
		$number = VolumeTitle::extract_number( substr( $pair, 0, $cut ) );
		if ( $id < 1 || null === $number ) {
			return null;
		}

		return array(
			'id'     => $id,
			'numero' => $number,
		);
	}

	/**
	 * Números de los tomos agotados, en orden numérico, o lista vacía si no se
	 * pueden afirmar TODOS.
	 *
	 * @param string $concat   Títulos separados por LIST_SEPARATOR.
	 * @param int    $agotados Tomos agotados del agregado, que es la verdad a igualar.
	 * @return array<int, string> Números tal como los escribe el título ("03").
	 */
	public static function out_of_stock_volumes( string $concat, int $agotados ): array {
		if ( $agotados < 1 || '' === $concat ) {
			return array();
		}

		if ( strlen( $concat ) >= self::CONCAT_SAFE_BYTES ) {
			return array();
		}

		$volumes = array();
		foreach ( explode( self::LIST_SEPARATOR, $concat ) as $title ) {
			if ( 1 !== preg_match( self::VOLUME_PATTERN, $title, $matches ) ) {
				return array();
			}

			$volumes[ (int) $matches[2] ] = $matches[2];
		}

		if ( count( $volumes ) !== $agotados ) {
			return array();
		}

		ksort( $volumes, SORT_NUMERIC );

		return array_values( $volumes );
	}

	/**
	 * Los 10 primeros caracteres de post_modified (YYYY-MM-DD), o cadena vacía si no cumple el formato.
	 *
	 * @param string $modified Fecha post_modified.
	 * @return string Formato YYYY-MM-DD o cadena vacía.
	 */
	public static function iso_date( string $modified ): string {
		$iso = substr( $modified, 0, 10 );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $iso ) ? $iso : '';
	}

	/**
	 * Parsea los pares nombre/term_id de un GROUP_CONCAT.
	 *
	 * @param string $concat Concatenación de pares.
	 * @return array<int, array{name:string,term_id:int}>
	 */
	public static function term_pairs( string $concat ): array {
		if ( '' === $concat ) {
			return array();
		}

		$terms = array();
		foreach ( explode( self::LIST_SEPARATOR, $concat ) as $entry ) {
			$parts = explode( self::PAIR_SEPARATOR, $entry, 2 );
			$name  = trim( (string) ( $parts[0] ?? '' ) );
			if ( '' === $name || isset( $terms[ $name ] ) ) {
				continue;
			}

			$terms[ $name ] = array(
				'name'    => $name,
				'term_id' => max( 0, (int) ( $parts[1] ?? 0 ) ),
			);
		}

		return array_values( $terms );
	}
}
