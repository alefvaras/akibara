<?php
/**
 * FollowerFinder — la consulta INVERSA: de una serie a sus compradores.
 *
 * `SeriesBundles\CustomerCollection` responde «qué tiene este cliente». Sus
 * docblocks citaban una `SeriesAvailability\SeriesCustomerFinder` para la
 * dirección contraria, pero esa clase NUNCA existió en el árbol. Esta es.
 *
 * Se calca de `CustomerCollection::query_owned_product_ids()`: las mismas
 * tablas de WC Analytics, el mismo colapso de refunds por `parent_id`, los
 * mismos joins autoritativos contra el almacén canónico de pedidos (HPOS o
 * CPT), el mismo `HAVING SUM(product_qty) > 0` y la misma paranoia de lectura
 * que devuelve **null en vez de vacío** ante cualquier duda. Los tres métodos
 * que definen «lo tenés» se le PIDEN a esa clase en vez de reescribirse: una
 * segunda cuenta equivalente es el defecto más difícil de ver.
 *
 * Paginación en DOS consultas, no en una
 * --------------------------------------
 * El `LIMIT` del molde cuenta filas AGRUPADAS por producto, no clientes: con un
 * límite de 200, un coleccionista con 250 tomos de la serie se cortaría a la
 * mitad y el resto de sus tomos jamás llegaría al cálculo del máximo comprado.
 * Por eso (A) pagina CLIENTES y (B) trae todas las filas de esos clientes sin
 * límite. El cursor de la vuelta siguiente se deriva del RESULTADO de (B)
 * (la mayor clave `user_id` del mapa devuelto), no del listado crudo de (A).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

use Akibara\Business\Modules\SeriesBundles\CustomerCollection;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class FollowerFinder {

	/** @var CustomerCollection */
	private CustomerCollection $collection;

	/** Base de datos inyectable para pruebas; null usa el `$wpdb` global. */
	private ?object $database;

	/**
	 * @param CustomerCollection $collection Autoridad de «lo tenés».
	 * @param object|null        $database   Dependencia compatible con wpdb.
	 */
	public function __construct( CustomerCollection $collection, ?object $database = null ) {
		$this->collection = $collection;
		$this->database   = $database;
	}

	/**
	 * Compradores de una serie, con los productos de ESA serie que conservan.
	 *
	 * @param int $term_taxonomy_id `term_taxonomy_id` del término `pa_serie`.
	 * @param int $after_user_id    Cursor: sólo clientes con id mayor. 0 = desde el principio.
	 * @param int $limit            Máximo de CLIENTES por vuelta.
	 * @return array<int, array<int, int>>|null user_id ⇒ product_ids, o null si la lectura no fue fiable.
	 */
	public function followers_of_series( int $term_taxonomy_id, int $after_user_id, int $limit ): ?array {
		if ( $term_taxonomy_id < 1 ) {
			return array();
		}

		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_col' )
			|| ! method_exists( $database, 'get_results' )
			|| ! isset( $database->prefix )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
		) {
			return null;
		}

		$user_ids = $this->candidate_user_ids( $database, $term_taxonomy_id, $after_user_id, max( 1, $limit ) );
		if ( null === $user_ids ) {
			return null;
		}
		if ( array() === $user_ids ) {
			return array();
		}

		return $this->owned_rows( $database, $term_taxonomy_id, $user_ids );
	}

	/**
	 * (A) Clientes con al menos una línea de la serie en un pedido pagado.
	 *
	 * @param object $database         wpdb o doble.
	 * @param int    $term_taxonomy_id Término de la serie.
	 * @param int    $after_user_id    Cursor.
	 * @param int    $limit            Clientes por vuelta.
	 * @return array<int, int>|null
	 */
	private function candidate_user_ids( object $database, int $term_taxonomy_id, int $after_user_id, int $limit ): ?array {
		$prefix              = (string) $database->prefix;
		$statuses            = $this->collection->purchase_statuses();
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$joins               = $this->series_joins( $prefix );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from wpdb prefix; placeholders are generated tokens only.
		$query = $database->prepare(
			"SELECT customers.user_id
				{$joins}
				AND series.term_taxonomy_id = %d
				WHERE parent_orders.status IN ({$status_placeholders})
					AND products.product_id > 0
					AND customers.user_id > %d
				GROUP BY customers.user_id
				ORDER BY customers.user_id ASC
				LIMIT %d",
			...array_merge( array( $term_taxonomy_id ), $statuses, array( max( 0, $after_user_id ), $limit ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$rows = $database->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; se corre en un worker de Action Scheduler, no en una petición de visitante.
		if ( ! $this->read_is_trustworthy( $database, $query, $rows ) ) {
			return null;
		}

		$user_ids = array();
		foreach ( $rows as $row ) {
			$user_id = (int) $row;
			if ( $user_id > 0 ) {
				$user_ids[ $user_id ] = $user_id;
			}
		}

		return array_values( $user_ids );
	}

	/**
	 * (B) Productos de la serie que conservan esos clientes. SIN LIMIT: el
	 * recorte ya lo hizo (A) por cliente entero.
	 *
	 * @param object          $database         wpdb o doble.
	 * @param int             $term_taxonomy_id Término de la serie.
	 * @param array<int, int> $user_ids         Lista exacta devuelta por (A).
	 * @return array<int, array<int, int>>|null
	 */
	private function owned_rows( object $database, int $term_taxonomy_id, array $user_ids ): ?array {
		$prefix              = (string) $database->prefix;
		$statuses            = $this->collection->purchase_statuses();
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$user_placeholders   = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );
		$joins               = $this->series_joins( $prefix );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from wpdb prefix; placeholders are generated tokens only.
		$query = $database->prepare(
			"SELECT customers.user_id, products.product_id
				{$joins}
				AND series.term_taxonomy_id = %d
				WHERE parent_orders.status IN ({$status_placeholders})
					AND products.product_id > 0
					AND customers.user_id IN ({$user_placeholders})
				GROUP BY customers.user_id, products.product_id
				HAVING SUM(products.product_qty) > 0
				ORDER BY customers.user_id ASC, products.product_id ASC",
			...array_merge( array( $term_taxonomy_id ), $statuses, $user_ids )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$rows = $database->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; se corre en un worker de Action Scheduler, no en una petición de visitante.
		if ( ! $this->read_is_trustworthy( $database, $query, $rows ) ) {
			return null;
		}

		$followers = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$user_id    = (int) ( $row['user_id'] ?? 0 );
			$product_id = (int) ( $row['product_id'] ?? 0 );
			if ( $user_id < 1 || $product_id < 1 ) {
				continue;
			}
			$followers[ $user_id ][ $product_id ] = $product_id;
		}

		foreach ( $followers as $user_id => $product_ids ) {
			$followers[ $user_id ] = array_values( $product_ids );
		}

		return $followers;
	}

	/**
	 * Los joins compartidos por (A) y (B). El `INNER JOIN` de la serie queda
	 * ABIERTO a propósito: cada consulta le añade su `AND series.term_taxonomy_id
	 * = %d` para que el placeholder viaje en el mismo `prepare()`.
	 *
	 * `customers.user_id > 0` es implícito en el WHERE de cada consumidor: los
	 * invitados no tienen colección (la de la casa es user-based) y los recupera
	 * GuestOrderLinking al vincular el pedido a una cuenta.
	 *
	 * @param string $prefix Prefijo de tablas validado por wpdb.
	 * @return string
	 */
	private function series_joins( string $prefix ): string {
		$authoritative = $this->collection->authoritative_order_joins( $prefix );

		return "FROM {$prefix}wc_order_product_lookup products
				INNER JOIN {$prefix}wc_order_stats activity
					ON activity.order_id = products.order_id
				INNER JOIN {$prefix}wc_order_stats parent_orders
					ON parent_orders.order_id = CASE
						WHEN activity.parent_id > 0 THEN activity.parent_id
						ELSE activity.order_id
					END
				INNER JOIN {$prefix}wc_customer_lookup customers
					ON customers.customer_id = parent_orders.customer_id
				{$authoritative}
				INNER JOIN {$prefix}term_relationships series
					ON series.object_id = products.product_id";
	}

	/**
	 * La misma paranoia de lectura de `CustomerCollection`: ante cualquier duda
	 * se devuelve null, nunca «nadie sigue esta serie». Convertir una caída en un
	 * conjunto vacío marcaría el tomo como avisado sin haber avisado a nadie.
	 *
	 * @param object $database wpdb o doble.
	 * @param string $query    Consulta emitida.
	 * @param mixed  $rows     Resultado crudo.
	 * @return bool
	 */
	private function read_is_trustworthy( object $database, string $query, $rows ): bool {
		return is_array( $rows )
			&& ! ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			&& ! ( isset( $database->ready ) && ! (bool) $database->ready )
			&& ! ( isset( $database->last_query ) && (string) $database->last_query !== $query );
	}

	/** Devuelve la base inyectada o el global de WordPress. */
	private function database(): ?object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;

		return is_object( $wpdb ) ? $wpdb : null;
	}
}
