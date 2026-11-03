<?php
/**
 * Doble de `$wpdb` con la SEMÁNTICA REAL de `{prefix}akb_series_follow_queue`.
 *
 * No devuelve filas preparadas: mantiene la tabla en memoria y aplica cada
 * sentencia con sus argumentos ligados, incluidos los dos comportamientos de los
 * que depende todo el módulo:
 *
 *   - `UNIQUE (event_key)` con `INSERT IGNORE`: la segunda alta del mismo par
 *     cliente↔tomo afecta CERO filas, esté la primera `pending`, `sent` o
 *     `dropped`. Un doble que aceptara el duplicado dejaría verde el día que
 *     alguien quite el índice y los clientes reciban el aviso dos veces.
 *   - Los `WHERE status = …` de los UPDATE: el claim sólo puede ganarlo un
 *     worker, y liberar o descartar filas ya reclamadas no puede resucitarlas.
 *
 * La discriminación de sentencias mira el SQL porque ES la frontera entre el
 * repositorio y la base; los VALORES salen siempre de los argumentos ligados,
 * nunca de parsear el texto.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

/**
 * @since 1.110.2
 */
final class SeriesFollowQueueWpdb {

	public string $prefix = 'wp_';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/** @var array<int, array<string, mixed>> Filas vivas, por id. */
	public array $table = array();

	/** Sentencias emitidas, para contar consultas. */
	public int $query_count = 0;

	private int $next_id = 1;

	private string $pending_sql = '';

	/** @var array<int, mixed> */
	private array $pending_args = array();

	/** Reloj congelado del doble (los timestamps reales harían inestable el test). */
	public string $now = '2026-08-31 12:00:00';

	/**
	 * @param string $sql     Sentencia con placeholders.
	 * @param mixed  ...$args Argumentos ligados.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		$this->pending_sql  = $sql;
		$this->pending_args = $args;

		return $sql;
	}

	/**
	 * @param string $sql Sentencia ya «preparada».
	 * @return int Filas afectadas.
	 */
	public function query( string $sql ): int {
		$this->last_query = $sql;
		++$this->query_count;
		$args = $this->pending_args;

		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			return $this->apply_insert( $args );
		}
		if ( false !== strpos( $sql, 'attempts = attempts + 1' ) ) {
			return $this->apply_release( $args );
		}
		if ( false !== strpos( $sql, 'sent_at = %s WHERE user_id' ) ) {
			return $this->apply_claim( $args );
		}
		if ( false !== strpos( $sql, 'created_at < %s' ) ) {
			return $this->apply_expire( $args );
		}
		if ( 0 === strpos( $sql, 'DELETE FROM' ) ) {
			return $this->apply_delete( $args );
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			return $this->apply_mark( $args );
		}

		return 0;
	}

	/**
	 * @param string $sql Sentencia ya «preparada».
	 * @return array<int, int|string>
	 */
	public function get_col( string $sql ): array {
		$this->last_query = $sql;
		++$this->query_count;

		// pending_user_ids(): status, limit.
		$status = (string) ( $this->pending_args[0] ?? 'pending' );
		$limit  = (int) ( $this->pending_args[1] ?? 50 );

		$seen = array();
		foreach ( $this->sorted_rows() as $row ) {
			if ( $status !== $row['status'] ) {
				continue;
			}
			$seen[ (int) $row['user_id'] ] = (int) $row['user_id'];
		}

		return array_slice( array_values( $seen ), 0, $limit );
	}

	/**
	 * @param string $sql    Sentencia ya «preparada».
	 * @param string $output Formato pedido (siempre ARRAY_A aquí).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $sql, string $output = 'ARRAY_A' ): array {
		unset( $output );
		$this->last_query = $sql;
		++$this->query_count;

		if ( false !== strpos( $sql, 'GROUP BY product_id' ) ) {
			return $this->summary_rows();
		}

		$user_id = (int) ( $this->pending_args[0] ?? 0 );
		$status  = (string) ( $this->pending_args[1] ?? 'pending' );

		$rows = array();
		foreach ( $this->sorted_rows() as $row ) {
			if ( $user_id !== (int) $row['user_id'] || $status !== $row['status'] ) {
				continue;
			}
			$rows[] = array(
				'id'         => (int) $row['id'],
				'user_id'    => (int) $row['user_id'],
				'product_id' => (int) $row['product_id'],
				'term_id'    => (int) $row['term_id'],
				'volume'     => (int) $row['volume'],
			);
		}

		usort(
			$rows,
			static fn ( array $a, array $b ): int => $b['volume'] <=> $a['volume'] ?: $a['id'] <=> $b['id']
		);

		return $rows;
	}

	/**
	 * @param string $sql Sentencia ya «preparada».
	 * @return int
	 */
	public function get_var( string $sql ): int {
		$this->last_query = $sql;
		++$this->query_count;

		$status = (string) ( $this->pending_args[0] ?? 'pending' );
		$total  = 0;
		foreach ( $this->table as $row ) {
			if ( $status === $row['status'] ) {
				++$total;
			}
		}

		return $total;
	}

	/** Filas con un estado dado, para las aserciones del test. */
	public function rows_with_status( string $status ): array {
		return array_values(
			array_filter(
				$this->table,
				static fn ( array $row ): bool => $status === $row['status']
			)
		);
	}

	/** @param array<int, mixed> $args Siete argumentos por fila. */
	private function apply_insert( array $args ): int {
		$inserted = 0;

		foreach ( array_chunk( $args, 7 ) as $tuple ) {
			if ( 7 !== count( $tuple ) ) {
				continue;
			}
			$event_key = (string) $tuple[4];

			// UNIQUE (event_key): el duplicado NO entra, sea cual sea el estado
			// de la fila que ya existe. Ésta es la garantía del módulo.
			foreach ( $this->table as $row ) {
				if ( $event_key === $row['event_key'] ) {
					continue 2;
				}
			}

			$id                 = $this->next_id++;
			$this->table[ $id ] = array(
				'id'         => $id,
				'user_id'    => (int) $tuple[0],
				'product_id' => (int) $tuple[1],
				'term_id'    => (int) $tuple[2],
				'volume'     => (int) $tuple[3],
				'event_key'  => $event_key,
				'status'     => (string) $tuple[5],
				'attempts'   => 0,
				'created_at' => (string) $tuple[6],
				'sent_at'    => null,
			);
			++$inserted;
		}

		return $inserted;
	}

	/** @param array<int, mixed> $args status_nuevo, sent_at, user_id, status_previo, ...ids. */
	private function apply_claim( array $args ): int {
		$nuevo   = (string) array_shift( $args );
		$sent_at = (string) array_shift( $args );
		$user_id = (int) array_shift( $args );
		$previo  = (string) array_shift( $args );
		$ids     = array_map( 'intval', $args );

		$affected = 0;
		foreach ( $this->table as $id => $row ) {
			if ( ! in_array( $id, $ids, true ) || $user_id !== (int) $row['user_id'] || $previo !== $row['status'] ) {
				continue;
			}
			$this->table[ $id ]['status']  = $nuevo;
			$this->table[ $id ]['sent_at'] = $sent_at;
			++$affected;
		}

		return $affected;
	}

	/** @param array<int, mixed> $args max, failed, pending, status_previo, ...ids. */
	private function apply_release( array $args ): int {
		$max     = (int) array_shift( $args );
		$failed  = (string) array_shift( $args );
		$pending = (string) array_shift( $args );
		$previo  = (string) array_shift( $args );
		$ids     = array_map( 'intval', $args );

		$affected = 0;
		foreach ( $this->table as $id => $row ) {
			if ( ! in_array( $id, $ids, true ) || $previo !== $row['status'] ) {
				continue;
			}
			// MySQL evalúa el SET izquierda→derecha: el CASE ve `attempts` YA
			// incrementado. El doble reproduce ese orden a propósito.
			$attempts                       = (int) $row['attempts'] + 1;
			$this->table[ $id ]['attempts'] = $attempts;
			$this->table[ $id ]['sent_at']  = null;
			$this->table[ $id ]['status']   = $attempts >= $max ? $failed : $pending;
			++$affected;
		}

		return $affected;
	}

	/** @param array<int, mixed> $args status_nuevo, status_previo, ...ids. */
	private function apply_mark( array $args ): int {
		$nuevo  = (string) array_shift( $args );
		$previo = (string) array_shift( $args );
		$ids    = array_map( 'intval', $args );

		$affected = 0;
		foreach ( $this->table as $id => $row ) {
			if ( ! in_array( $id, $ids, true ) || $previo !== $row['status'] ) {
				continue;
			}
			$this->table[ $id ]['status'] = $nuevo;
			++$affected;
		}

		return $affected;
	}

	/** @param array<int, mixed> $args status_previo, ...ids. */
	private function apply_delete( array $args ): int {
		$previo = (string) array_shift( $args );
		$ids    = array_map( 'intval', $args );

		$affected = 0;
		foreach ( $this->table as $id => $row ) {
			if ( ! in_array( $id, $ids, true ) || $previo !== $row['status'] ) {
				continue;
			}
			unset( $this->table[ $id ] );
			++$affected;
		}

		return $affected;
	}

	/** @param array<int, mixed> $args status_nuevo, status_previo, corte. */
	private function apply_expire( array $args ): int {
		$nuevo  = (string) ( $args[0] ?? '' );
		$previo = (string) ( $args[1] ?? '' );
		$corte  = (string) ( $args[2] ?? '' );

		$affected = 0;
		foreach ( $this->table as $id => $row ) {
			if ( $previo !== $row['status'] || (string) $row['created_at'] >= $corte ) {
				continue;
			}
			$this->table[ $id ]['status'] = $nuevo;
			++$affected;
		}

		return $affected;
	}

	/** @return array<int, array<string, mixed>> */
	private function summary_rows(): array {
		$pending  = (string) ( $this->pending_args[0] ?? 'pending' );
		$sent     = (string) ( $this->pending_args[1] ?? 'sent' );
		$agrupado = array();

		foreach ( $this->table as $row ) {
			$clave = $row['product_id'] . ':' . $row['term_id'] . ':' . $row['volume'];
			if ( ! isset( $agrupado[ $clave ] ) ) {
				$agrupado[ $clave ] = array(
					'product_id' => (int) $row['product_id'],
					'term_id'    => (int) $row['term_id'],
					'volume'     => (int) $row['volume'],
					'waiting'    => 0,
					'notified'   => 0,
				);
			}
			if ( $pending === $row['status'] ) {
				++$agrupado[ $clave ]['waiting'];
			}
			if ( $sent === $row['status'] ) {
				++$agrupado[ $clave ]['notified'];
			}
		}

		return array_values(
			array_filter(
				$agrupado,
				static fn ( array $fila ): bool => $fila['waiting'] > 0 || $fila['notified'] > 0
			)
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function sorted_rows(): array {
		$rows = array_values( $this->table );
		usort(
			$rows,
			static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id']
		);

		return $rows;
	}
}
