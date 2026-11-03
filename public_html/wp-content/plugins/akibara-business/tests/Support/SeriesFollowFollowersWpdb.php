<?php
/**
 * Doble de `$wpdb` con las LÍNEAS DE PEDIDO en memoria, para la consulta
 * inversa serie → compradores.
 *
 * Cada línea declara su propio estado de pedido, así que el filtro por estados
 * de compra lo aplica el código bajo prueba y no el doble. El neteo de
 * reembolsos reproduce el `HAVING SUM(product_qty) > 0` del molde.
 *
 * Vive en `Support` y no dentro de un fichero de test porque lo consumen tres
 * suites: declararlo en una de ellas haría que las otras dos pasaran sólo por
 * contagio de orden.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Support;

/**
 * Estado en memoria de las líneas de pedido. Cada línea declara su estado de
 * pedido, así que el filtro por estados es el del código bajo prueba y no el del
 * doble.
 */
final class SeriesFollowFollowersWpdb {

	public string $prefix = 'wp_';

	public bool $ready = true;

	public string $last_error = '';

	public string $last_query = '';

	/** Rompe la lectura como lo haría MySQL: filas vacías + rastro en last_error. */
	public bool $falla = false;

	/** @var array<int, array{user_id:int, product_id:int, status:string, qty:int}> */
	public array $lineas = array();

	/** @var array<int, mixed> */
	private array $pending_args = array();

	private string $pending_sql = '';

	/** @param mixed ...$args Argumentos ligados. */
	public function prepare( string $sql, ...$args ): string {
		$this->pending_sql  = $sql;
		$this->pending_args = $args;

		return $sql;
	}

	/**
	 * Las MISMAS tablas sirven a las dos direcciones de la consulta, así que hay
	 * dos consumidores con formas distintas:
	 *   - `FollowerFinder` pide `customers.user_id` (serie → compradores).
	 *   - `CustomerCollection` pide `products.product_id` (cliente → colección),
	 *     y el digest lo llama para revalidar la propiedad antes de enviar.
	 *
	 * @return array<int, int>
	 */
	public function get_col( string $sql ): array {
		$this->last_query = $sql;
		if ( $this->falla ) {
			$this->last_error = 'Lost connection to MySQL server during query';
			return array();
		}

		return false !== strpos( $sql, 'SELECT products.product_id' )
			? $this->productos_del_cliente()
			: $this->clientes_de_la_serie();
	}

	/**
	 * Forma de `CustomerCollection::query_owned_product_ids`: prepare(user_id,
	 * ...estados).
	 *
	 * @return array<int, int>
	 */
	private function productos_del_cliente(): array {
		$args    = $this->pending_args;
		$user_id = (int) array_shift( $args );
		$estados = array_map( 'strval', $args );

		return array_keys( $this->neto( $estados )[ $user_id ] ?? array() );
	}

	/**
	 * Forma de `FollowerFinder::candidate_user_ids`: prepare(ttid, ...estados,
	 * cursor, limit).
	 *
	 * @return array<int, int>
	 */
	private function clientes_de_la_serie(): array {
		$args   = $this->pending_args;
		$limit  = (int) array_pop( $args );
		$cursor = (int) array_pop( $args );
		array_shift( $args );
		$estados = array_map( 'strval', $args );

		$user_ids = array();
		foreach ( $this->neto( $estados ) as $user_id => $productos ) {
			if ( $user_id > $cursor ) {
				$user_ids[ $user_id ] = $user_id;
			}
			unset( $productos );
		}

		ksort( $user_ids, SORT_NUMERIC );

		return array_slice( array_values( $user_ids ), 0, $limit );
	}

	/** @return array<int, array<string, mixed>> */
	public function get_results( string $sql, string $output = 'ARRAY_A' ): array {
		unset( $output );
		$this->last_query = $sql;
		if ( $this->falla ) {
			$this->last_error = 'Lost connection to MySQL server during query';
			return array();
		}

		$args = $this->pending_args;
		array_shift( $args );
		$estados     = array();
		$solicitados = array();
		foreach ( $args as $arg ) {
			if ( is_string( $arg ) ) {
				$estados[] = $arg;
				continue;
			}
			$solicitados[] = (int) $arg;
		}

		$rows = array();
		foreach ( $this->neto( $estados ) as $user_id => $productos ) {
			if ( ! in_array( $user_id, $solicitados, true ) ) {
				continue;
			}
			foreach ( $productos as $product_id => $qty ) {
				if ( $qty > 0 ) {
					$rows[] = array(
						'user_id'    => $user_id,
						'product_id' => $product_id,
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * `HAVING SUM(product_qty) > 0` del molde: reembolsos incluidos.
	 *
	 * @param array<int, string> $estados Estados aceptados.
	 * @return array<int, array<int, int>>
	 */
	private function neto( array $estados ): array {
		$neto = array();
		foreach ( $this->lineas as $linea ) {
			if ( ! in_array( $linea['status'], $estados, true ) || $linea['user_id'] < 1 ) {
				continue;
			}
			$neto[ $linea['user_id'] ][ $linea['product_id'] ] =
				( $neto[ $linea['user_id'] ][ $linea['product_id'] ] ?? 0 ) + $linea['qty'];
		}

		ksort( $neto, SORT_NUMERIC );

		return $neto;
	}
}
