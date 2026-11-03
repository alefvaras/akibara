<?php
/**
 * FakeWpdb — doble de $wpdb para la suite de Rewards.
 *
 * Modela lo que el código ejercita de verdad:
 *   - GET_LOCK/RELEASE_LOCK (configurable para simular candado ocupado).
 *   - Lectura directa de usermeta (la re-lectura bajo candado del Ledger),
 *     respaldada por el MISMO store que usan los stubs de
 *     get_user_meta/update_user_meta del RewardsTestCase — así una escritura
 *     bajo candado es visible en la re-lectura siguiente, como en MySQL.
 *   - insert() con registro de filas y fallo scripteable.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

/**
 * @since 0.1.0
 */
class FakeWpdb {

	public string $prefix     = 'wp_';
	public string $usermeta   = 'wp_usermeta';
	public string $options    = 'wp_options';
	public string $comments   = 'wp_comments';
	public string $commentmeta = 'wp_commentmeta';
	public string $posts      = 'wp_posts';
	public string $last_error = '';

	/**
	 * Store de usermeta compartido con los stubs de funciones:
	 * [user_id => [meta_key => value]].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $user_meta = array();

	/**
	 * Cuentas de la tienda, para `get_user_by()`:
	 * [user_id => ['user_email' => ..., 'user_login' => ...]].
	 *
	 * Vacío = comprador invitado, que es el caso por defecto de la suite.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $users = array();

	/**
	 * Store de commentmeta compartido con los stubs:
	 * [comment_id => [meta_key => value]].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $comment_meta = array();

	/**
	 * Filas insertadas: [ [tabla, datos] ].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	public array $inserts = array();

	/**
	 * Queries crudas ejecutadas (RELEASE_LOCK vive acá).
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/** Resultado de GET_LOCK ('1' = concedido). */
	public string $lock_result = '1';

	/**
	 * Candados concretos ocupados aunque `lock_result` conceda el resto: el
	 * del usuario (libro) y el del pedido son distintos, y un caso real ocupa
	 * sólo uno.
	 *
	 * @var array<int,string>
	 */
	public array $busy_locks = array();

	/**
	 * Candados tomados por esta «conexión», con conteo de reentradas:
	 * [nombre => n]. Modela la reentrancia real de GET_LOCK (MySQL 5.7+/
	 * MariaDB 10.0.2+): el candado anidado pedido→usuario del código depende
	 * de ella y la suite debe poder detectarla rota.
	 *
	 * @var array<string,int>
	 */
	public array $held_locks = array();

	/** Forzar fallo del próximo insert. */
	public bool $fail_insert = false;

	/**
	 * Respuestas scripteadas para get_var por fragmento de SQL:
	 * [needle => valor]. Se evalúan antes que los comportamientos por defecto.
	 *
	 * @var array<string,mixed>
	 */
	public array $var_answers = array();

	/**
	 * Respuestas scripteadas para get_results por fragmento de SQL.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $results_answers = array();

	/**
	 * Interpolación fiel a lo que el código necesita: %s citado, %d entero.
	 *
	 * @param string $query Query con placeholders.
	 * @param mixed  ...$args Valores.
	 * @return string
	 */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$query = str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query );

		return vsprintf( $query, array_map( static fn( $a ) => is_int( $a ) ? $a : (string) $a, $args ) );
	}

	/**
	 * @param string $sql Query preparada.
	 * @return mixed
	 */
	/**
	 * SQL crudas que pasaron por get_var (candados de forma REALES en la
	 * suite, no greps del fuente).
	 *
	 * @var array<int,string>
	 */
	public array $var_log = array();

	public function get_var( string $sql ) {
		$this->var_log[] = $sql;
		foreach ( $this->var_answers as $needle => $answer ) {
			if ( false !== strpos( $sql, $needle ) ) {
				return $answer;
			}
		}

		if ( preg_match( "/GET_LOCK\('([^']+)'/", $sql, $m ) ) {
			if ( '1' !== $this->lock_result ) {
				return $this->lock_result;
			}
			if ( in_array( $m[1], $this->busy_locks, true ) ) {
				return '0';
			}
			$this->held_locks[ $m[1] ] = ( $this->held_locks[ $m[1] ] ?? 0 ) + 1;
			return '1';
		}

		// Idempotencia por event_key (índice UNIQUE simulado).
		if ( preg_match( "/SELECT id FROM wp_akb_rewards_ledger WHERE event_key = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->inserts as $i => $ins ) {
				if ( ( $ins[1]['event_key'] ?? null ) === $m[1] ) {
					return (string) ( $i + 1 );
				}
			}
			return null;
		}

		if ( preg_match( "/^SELECT effective_at FROM .*CASE WHEN action = 'migration' THEN '([^']*)'.*WHERE user_id = (\d+).*cumulative_points > (\d+)/", $sql, $m ) ) {
			$credits = array();
			foreach ( $this->inserts as $ins ) {
				$row = $ins[1];
				if ( (int) $row['user_id'] !== (int) $m[2] || (int) $row['delta'] <= 0 || ( 'migration' === $row['action'] && '' === $m[1] ) ) {
					continue;
				}
				$date = 'migration' === $row['action'] ? $m[1] : $row['created_at'];
				$credits[ $date ] = ( $credits[ $date ] ?? 0 ) + (int) $row['delta'];
			}
			ksort( $credits );
			$total = 0;
			foreach ( $credits as $date => $points ) {
				$total += $points;
				if ( $total > (int) $m[3] ) {
					return $date;
				}
			}
			return null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+)$/', $sql, $m ) ) {
			return (string) count( array_filter( $this->inserts, static fn( array $ins ): bool => (int) $ins[1]['user_id'] === (int) $m[1] ) );
		}

		// Ganado «viejo» del vencimiento, consciente de la época de migración
		// (evaluación REAL sobre las filas insertadas).
		if ( preg_match( "/SELECT COALESCE\(SUM\(delta\),0\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+) AND delta > 0 AND \( created_at < '([^']+)' AND action <> 'migration' OR action = 'migration' AND '([^']+)' < '([^']+)' \)/", $sql, $m ) ) {
			$sum = 0;
			foreach ( $this->inserts as $ins ) {
				$d = $ins[1];
				if ( (int) $d['user_id'] !== (int) $m[1] || (int) $d['delta'] <= 0 ) {
					continue;
				}
				$es_migracion = 'migration' === (string) $d['action'];
				if ( ( ! $es_migracion && (string) $d['created_at'] < $m[2] ) || ( $es_migracion && $m[3] < $m[4] ) ) {
					$sum += (int) $d['delta'];
				}
			}
			return (string) $sum;
		}

		// Débitos históricos del vencimiento (evaluación real).
		if ( preg_match( "/SELECT COALESCE\(SUM\(-delta\),0\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+) AND delta < 0/", $sql, $m ) ) {
			$sum = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] && (int) $ins[1]['delta'] < 0 ) {
					$sum += -(int) $ins[1]['delta'];
				}
			}
			return (string) $sum;
		}

		// Suma TOTAL de deltas de un usuario (saldo derivado del ledger para
		// la auditoría). Anclada al final: las variantes con más condiciones
		// tienen sus patrones arriba.
		if ( preg_match( "/SELECT COALESCE\(SUM\(delta\),0\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+)$/", $sql, $m ) ) {
			$sum = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] ) {
					$sum += (int) $ins[1]['delta'];
				}
			}
			return (string) $sum;
		}

		// Suma de deltas por referencia y conjunto de acciones (conciliación).
		if ( preg_match( "/SELECT COALESCE\(SUM\(delta\),0\) FROM wp_akb_rewards_ledger WHERE ref_type = '([^']+)' AND ref_id = (\d+) AND action IN \(([^)]+)\)/", $sql, $m ) ) {
			$actions = array_map( static fn( string $a ): string => trim( $a, " '" ), explode( ',', $m[3] ) );
			$sum     = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (string) $ins[1]['ref_type'] === $m[1] && (int) $ins[1]['ref_id'] === (int) $m[2] && in_array( (string) $ins[1]['action'], $actions, true ) ) {
					$sum += (int) $ins[1]['delta'];
				}
			}
			return (string) $sum;
		}

		// Créditos VIGENTES agrupados por referencia (el GROUP BY … HAVING de
		// MySQL): fuente única de los premios de referido vigentes Y del tope
		// histórico de reseñas premiadas (Ledger::count_live_credits()).
		if ( preg_match( "/SELECT COUNT\(\*\) FROM \( SELECT ref_id FROM wp_akb_rewards_ledger WHERE user_id = (\d+) AND ref_type = '([^']+)' AND action IN \('([^']+)','([^']+)'\) GROUP BY ref_id HAVING SUM\(delta\) > 0 \) AS vigentes/", $sql, $m ) ) {
			$netos = array();
			foreach ( $this->inserts as $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] && (string) $ins[1]['ref_type'] === $m[2] && in_array( (string) $ins[1]['action'], array( $m[3], $m[4] ), true ) ) {
					$netos[ (int) $ins[1]['ref_id'] ] = ( $netos[ (int) $ins[1]['ref_id'] ] ?? 0 ) + (int) $ins[1]['delta'];
				}
			}
			return (string) count( array_filter( $netos, static fn( int $neto ): bool => $neto > 0 ) );
		}

		// Conteo de movimientos por usuario+referencia/acción (reversas por
		// reseña: el mismo producto puede compartir ref_id entre clientes
		// distintos, y esta cuenta no debe mezclarlos).
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+) AND ref_type = '([^']+)' AND ref_id = (\d+) AND action = '([^']+)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] && (string) $ins[1]['ref_type'] === $m[2] && (int) $ins[1]['ref_id'] === (int) $m[3] && (string) $ins[1]['action'] === $m[4] ) {
					++$n;
				}
			}
			return (string) $n;
		}

		// Conteo de créditos por usuario/acción (política de frecuencia).
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_akb_rewards_ledger WHERE user_id = (\d+) AND action = '([^']+)' AND delta > 0/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] && (string) $ins[1]['action'] === $m[2] && (int) $ins[1]['delta'] > 0 ) {
					++$n;
				}
			}
			return (string) $n;
		}

		// Conteo de movimientos por referencia/acción (conciliación Accrual).
		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_akb_rewards_ledger WHERE ref_type = '([^']+)' AND ref_id = (\d+) AND action = '([^']+)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (string) $ins[1]['ref_type'] === $m[1] && (int) $ins[1]['ref_id'] === (int) $m[2] && (string) $ins[1]['action'] === $m[3] ) {
					++$n;
				}
			}
			return (string) $n;
		}

		if ( preg_match( "/SELECT COUNT\(\*\) FROM wp_akb_rewards_ledger WHERE action = '([^']+)'/", $sql, $m ) ) {
			$n = 0;
			foreach ( $this->inserts as $ins ) {
				if ( (string) $ins[1]['action'] === $m[1] ) {
					++$n;
				}
			}
			return (string) $n;
		}

		if ( false !== strpos( $sql, 'SELECT COUNT(*) FROM wp_akb_rewards_ledger' ) ) {
			return (string) count( $this->inserts );
		}

		// Última acreditación de un pedido (recuperación de ventana de caída).
		if ( preg_match( "/SELECT delta FROM wp_akb_rewards_ledger WHERE ref_type = 'order' AND ref_id = (\d+) AND action = 'order' ORDER BY id DESC/", $sql, $m ) ) {
			for ( $i = count( $this->inserts ) - 1; $i >= 0; $i-- ) {
				$ins = $this->inserts[ $i ];
				if ( 'order' === (string) $ins[1]['ref_type'] && (int) $ins[1]['ref_id'] === (int) $m[1] && 'order' === (string) $ins[1]['action'] ) {
					return (string) $ins[1]['delta'];
				}
			}
			return null;
		}

		if ( preg_match( "/SELECT meta_value FROM {$this->usermeta} WHERE user_id = (\\d+) AND meta_key = '([^']+)'/", $sql, $m ) ) {
			return $this->user_meta[ (int) $m[1] ][ $m[2] ] ?? null;
		}

		return null;
	}

	/**
	 * @param string $sql  Query.
	 * @param string $mode Formato.
	 * @return array<int,array<string,mixed>>
	 */
	/**
	 * SQL crudas que pasaron por get_results (candados de forma REALES en la
	 * suite, no greps del fuente).
	 *
	 * @var array<int,string>
	 */
	public array $results_log = array();

	public function get_results( string $sql, string $mode = 'OBJECT' ): array {
		unset( $mode );

		$this->results_log[] = $sql;

		foreach ( $this->results_answers as $needle => $answer ) {
			if ( false !== strpos( $sql, $needle ) ) {
				return $answer;
			}
		}

		if ( preg_match( '/^SELECT delta, note, created_at FROM wp_akb_rewards_ledger WHERE user_id = (\d+) ORDER BY created_at DESC, id DESC LIMIT 10 OFFSET (\d+)$/', $sql, $m ) ) {
			$rows = array();
			foreach ( $this->inserts as $id => $ins ) {
				if ( (int) $ins[1]['user_id'] === (int) $m[1] ) {
					$rows[] = (object) array_merge( $ins[1], array( 'id' => $id + 1 ) );
				}
			}
			usort( $rows, static fn( object $a, object $b ): int => strcmp( $b->created_at, $a->created_at ) ?: $b->id <=> $a->id );
			return array_slice( $rows, (int) $m[2], 10 );
		}

		return array();
	}

	/**
	 * @param string                    $table  Tabla.
	 * @param array<string,mixed>       $data   Datos.
	 * @param array<int,string>|null    $format Formatos.
	 * @return int|false
	 */
	public function insert( string $table, array $data, ?array $format = null ) {
		unset( $format );

		if ( $this->fail_insert ) {
			$this->last_error = 'insert scripted to fail';
			return false;
		}

		// Índice UNIQUE de event_key, como en la tabla real.
		if ( isset( $data['event_key'] ) ) {
			foreach ( $this->inserts as $ins ) {
				if ( ( $ins[1]['event_key'] ?? null ) === $data['event_key'] ) {
					$this->last_error = "Duplicate entry '" . $data['event_key'] . "' for key 'event_key'";
					return false;
				}
			}
		}

		$this->inserts[] = array( $table, $data );
		return 1;
	}

	/**
	 * UPDATEs registrados: [ [tabla, datos, where] ]. El de tokens además
	 * muta la fila insertada (used_at) para que validate_token la vea.
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public array $updates = array();

	/**
	 * @param string              $table  Tabla.
	 * @param array<string,mixed> $data   Datos.
	 * @param array<string,mixed> $where  Condición.
	 * @param mixed               $format Formatos (no usados).
	 * @param mixed               $where_format Formatos del where.
	 * @return int
	 */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int {
		unset( $format, $where_format );
		$this->updates[] = array( $table, $data, $where );

		$n = 0;
		foreach ( $this->inserts as $i => $ins ) {
			if ( $ins[0] !== $table ) {
				continue;
			}
			$match = true;
			foreach ( $where as $k => $v ) {
				if ( (string) ( $ins[1][ $k ] ?? '' ) !== (string) $v ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				$this->inserts[ $i ][1] = array_merge( $ins[1], $data );
				++$n;
			}
		}
		return $n;
	}

	/**
	 * @param string $sql  Query preparada.
	 * @param string $mode Formato.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $mode = 'OBJECT' ) {
		unset( $mode );

		// Ledger::last_credit(): el crédito más reciente de una acción sobre una referencia.
		if ( preg_match( "/SELECT delta, note, created_at FROM wp_akb_rewards_ledger WHERE ref_type = '([^']+)' AND ref_id = (\\d+) AND action = '([^']+)' AND delta > 0 ORDER BY id DESC LIMIT 1/", $sql, $m ) ) {
			for ( $i = count( $this->inserts ) - 1; $i >= 0; $i-- ) {
				$fila = $this->inserts[ $i ][1];
				if ( 'wp_akb_rewards_ledger' === $this->inserts[ $i ][0]
					&& (string) ( $fila['ref_type'] ?? '' ) === $m[1]
					&& (int) ( $fila['ref_id'] ?? 0 ) === (int) $m[2]
					&& (string) ( $fila['action'] ?? '' ) === $m[3]
					&& (int) ( $fila['delta'] ?? 0 ) > 0 ) {
					return array(
						'delta'      => $fila['delta'],
						'note'       => $fila['note'] ?? '',
						'created_at' => $fila['created_at'] ?? '2026-08-30 12:00:00',
					);
				}
			}
			return null;
		}

		if ( preg_match( "/FROM wp_akb_rewards_review_forms WHERE token = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->inserts as $ins ) {
				if ( 'wp_akb_rewards_review_forms' === $ins[0] && (string) $ins[1]['token'] === $m[1] ) {
					return array(
						'order_id'   => $ins[1]['order_id'],
						'expires_at' => $ins[1]['expires_at'],
						'used_at'    => $ins[1]['used_at'] ?? null,
					);
				}
			}
		}

		return null;
	}

	/**
	 * @param string $sql Query preparada.
	 * @return array<int,string>
	 */
	public function get_col( string $sql ): array {
		if ( preg_match( "/SELECT id FROM wp_akb_rewards_ledger WHERE user_id = (\\d+)/", $sql, $m ) ) {
			$ids = array();
			foreach ( $this->inserts as $i => $ins ) {
				if ( 'wp_akb_rewards_ledger' === $ins[0] && (int) $ins[1]['user_id'] === (int) $m[1] ) {
					$ids[] = (string) ( $i + 1 );
				}
			}
			return $ids;
		}
		return array();
	}

	/**
	 * @param string $sql Query cruda.
	 * @return int
	 */
	public function query( string $sql ): int {
		$this->queries[] = $sql;

		// Toma CONDICIONAL del token de reseña: modela el UPDATE ... WHERE
		// used_at IS NULL de MySQL (0 filas si otro envío llegó primero). Un
		// doble que siempre devolviera 1 escondería justo la carrera que el
		// candado existe para cerrar.
		if ( preg_match( "/UPDATE wp_akb_rewards_review_forms SET used_at = '([^']+)' WHERE token = '([^']+)' AND used_at IS NULL/", $sql, $m ) ) {
			foreach ( $this->inserts as $i => $ins ) {
				if ( 'wp_akb_rewards_review_forms' !== $ins[0] || (string) $ins[1]['token'] !== $m[2] ) {
					continue;
				}
				if ( '' !== (string) ( $ins[1]['used_at'] ?? '' ) ) {
					return 0; // Ya tomado.
				}
				$this->inserts[ $i ][1]['used_at'] = $m[1];
				return 1;
			}
			return 0;
		}

		if ( preg_match( "/UPDATE wp_akb_rewards_review_forms SET used_at = NULL WHERE token = '([^']+)'/", $sql, $m ) ) {
			foreach ( $this->inserts as $i => $ins ) {
				if ( 'wp_akb_rewards_review_forms' === $ins[0] && (string) $ins[1]['token'] === $m[1] ) {
					$this->inserts[ $i ][1]['used_at'] = null;
					return 1;
				}
			}
			return 0;
		}

		if ( preg_match( "/RELEASE_LOCK\('([^']+)'\)/", $sql, $m ) && isset( $this->held_locks[ $m[1] ] ) ) {
			if ( --$this->held_locks[ $m[1] ] <= 0 ) {
				unset( $this->held_locks[ $m[1] ] );
			}
		}

		if ( preg_match( "/DELETE FROM wp_akb_rewards_ledger WHERE action = '([^']+)'/", $sql, $m ) ) {
			$action = $m[1];
			$this->inserts = array_values(
				array_filter(
					$this->inserts,
					static fn( array $ins ): bool => (string) $ins[1]['action'] !== $action
				)
			);
		}

		return 1;
	}
}
