<?php
/**
 * Doble mínimo de `\RankMath\Redirections\DB` para RedireccionesSeLeenEnterasTest.
 *
 * S-403094bb (REAPERTURA, 2026-09-19): el candado anterior era un
 * `file_get_contents()` del propio fuente con `assertStringContainsString()` —
 * nunca ejecutaba la paginación ni el corte. Este doble modela lo único de la
 * API oficial de Rank Math que el mu-plugin usa: `get_redirections()` pagina
 * con `limit`/`paged` (1-based, `page($paged-1,$limit)` en la clase real) y
 * devuelve `count` = el total real (`get_found_rows()`), no el tamaño de la
 * página. Con esto la prueba EJECUTA la lectura, el tope duro
 * (AKIBARA_RM_REDIRECTIONS_MAX_ROWS) y el flag de truncado, en vez de creer lo
 * que dice un comentario.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace RankMath\Redirections;

if ( ! class_exists( __NAMESPACE__ . '\\DB', false ) ) {

	/**
	 * @phpstan-type Fila array<string,mixed>
	 */
	class DB {

		/**
		 * Dataset simulado por estado.
		 *
		 * @var array<string,array<int,array<string,mixed>>>
		 */
		public static array $dataset = array();

		/**
		 * Cada llamada a get_redirections(), en el orden en que se pidió.
		 *
		 * @var array<int,array<string,mixed>>
		 */
		public static array $paginas_pedidas = array();

		public static function reset(): void {
			self::$dataset         = array();
			self::$paginas_pedidas = array();
		}

		/**
		 * @param array<string,mixed> $args Igual forma que la clase real: limit/paged/status.
		 * @return array{redirections:array<int,array<string,mixed>>, count:int}
		 */
		public static function get_redirections( $args = array() ): array {
			self::$paginas_pedidas[] = $args;

			$status = (string) ( $args['status'] ?? 'any' );
			$filas  = self::$dataset[ $status ] ?? array();
			$total  = count( $filas );

			$limit  = max( 1, (int) ( $args['limit'] ?? 10 ) );
			$paged  = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$offset = ( $paged - 1 ) * $limit;

			return array(
				'redirections' => array_slice( $filas, $offset, $limit ),
				'count'        => $total,
			);
		}
	}
}
