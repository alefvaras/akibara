<?php
/**
 * Elegibilidad unificada de reseñas y resolución sin N+1 de tomos pendientes.
 *
 * Centraliza en consultas indexadas por lote qué productos de un pedido o de la
 * colección comprada de un cliente ya tienen reseña registrada y cuáles siguen
 * pendientes de reseñar.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Reviews;

use Akibara\Rewards\Points\Ledger;
use Akibara\Rewards\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Servicio sin estado persistente propio para consultar elegibilidad de reseñas.
 * @api
 * @psalm-suppress RedundantCondition
 */
// @phpstan-ignore complexity.classLike
final class ReviewEligibility {

	/**
	 * Caché en memoria durante la petición actual para reflejar al instante
	 * una reseña recién insertada (y facilitar pruebas sin reconsultar SQL).
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $request_reviewed = array();

	/**
	 * Limpia la caché en memoria de la petición actual.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$request_reviewed = array();
	}

	/**
	 * Registra en la memoria de la petición que un cliente acaba de reseñar un producto.
	 *
	 * @param int    $user_id    ID del cliente (0 si es invitado).
	 * @param string $email      Correo del cliente.
	 * @param int    $product_id ID del producto reseñado.
	 * @return void
	 */
	public static function mark_reviewed_in_request( int $user_id, string $email, int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		foreach ( self::identity_keys( $user_id, $email ) as $key ) {
			self::$request_reviewed[ $key ][ $product_id ] = $product_id;
		}
	}

	/**
	 * Devuelve los IDs de productos que el cliente ya reseñó (excluyendo spam y papelera)
	 * en una única consulta SQL indexada sobre `wp_comments`.
	 *
	 * @param int             $user_id               ID del usuario (0 si es invitado).
	 * @param string          $email                 Correo del comprador.
	 * @param array<int, int> $candidate_product_ids Lista opcional de productos a filtrar.
	 * @return array<int, int> Mapa `product_id => product_id`.
	 */
	public static function reviewed_product_ids_for_customer(
		int $user_id,
		string $email,
		array $candidate_product_ids = array()
	): array {
		$email = strtolower( trim( $email ) );
		if ( $user_id <= 0 && '' === $email ) {
			return array();
		}

		$candidates = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $candidate_product_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		$found = array();
		foreach ( self::identity_keys( $user_id, $email ) as $key ) {
			if ( ! empty( self::$request_reviewed[ $key ] ) ) {
				foreach ( self::$request_reviewed[ $key ] as $pid ) {
					if ( array() === $candidates || in_array( $pid, $candidates, true ) ) {
						$found[ $pid ] = $pid;
					}
				}
			}
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->comments ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return $found;
		}

		$where_identity = array();
		$params         = array();

		if ( $user_id > 0 ) {
			$where_identity[] = 'user_id = %d';
			$params[]         = $user_id;
		}
		if ( '' !== $email ) {
			$where_identity[] = 'LOWER(comment_author_email) = %s';
			$params[]         = $email;
		}

		$identity_sql = implode( ' OR ', $where_identity );
		$product_sql  = '';

		if ( array() !== $candidates ) {
			$placeholders = implode( ', ', array_fill( 0, count( $candidates ), '%d' ) );
			$product_sql  = " AND comment_post_ID IN ({$placeholders})";
			$params       = array_merge( $params, $candidates );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$query = $wpdb->prepare(
			"SELECT DISTINCT comment_post_ID
			 FROM {$wpdb->comments}
			 WHERE comment_type = 'review'
			   AND comment_approved NOT IN ('trash', 'spam')
			   AND ({$identity_sql}){$product_sql}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( is_string( $query ) && '' !== $query ) {
			$rows = (array) $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $raw_id ) {
				$pid = (int) $raw_id;
				if ( $pid > 0 ) {
					$found[ $pid ] = $pid;
				}
			}
		}

		return $found;
	}

	/**
	 * ¿Este cliente ya envió una reseña para este producto?
	 *
	 * @param int    $product_id ID del producto.
	 * @param int    $user_id    ID del cliente.
	 * @param string $email      Correo del cliente.
	 * @return bool
	 */
	public static function has_customer_reviewed_product( int $product_id, int $user_id, string $email ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		$reviewed = self::reviewed_product_ids_for_customer( $user_id, $email, array( $product_id ) );
		return isset( $reviewed[ $product_id ] );
	}

	/**
	 * Separa las líneas elegibles de un pedido en pendientes de reseñar y ya reseñadas.
	 *
	 * @param \WC_Order                                                    $order     Pedido.
	 * @param array<int, array{id:int, name:string, thumb:string, url:string}>|null $all_items Líneas ya resueltas (opcional).
	 * @return array{
	 *   pending: array<int, array{id:int, name:string, thumb:string, url:string}>,
	 *   reviewed: array<int, array{id:int, name:string, thumb:string, url:string}>,
	 *   total_count: int,
	 *   reviewed_count: int,
	 *   pending_count: int
	 * }
	 */
	public static function unreviewed_order_items( \WC_Order $order, ?array $all_items = null ): array {
		$items = null !== $all_items ? $all_items : self::extract_order_items( $order );
		if ( array() === $items ) {
			return array(
				'pending'        => array(),
				'reviewed'       => array(),
				'total_count'    => 0,
				'reviewed_count' => 0,
				'pending_count'  => 0,
			);
		}

		$candidate_ids = array_values(
			array_map(
				static fn( array $item ): int => (int) $item['id'],
				$items
			)
		);

		$reviewed_map = self::reviewed_product_ids_for_customer(
			(int) $order->get_customer_id(),
			(string) $order->get_billing_email(),
			$candidate_ids
		);

		$pending  = array();
		$reviewed = array();

		foreach ( $items as $item ) {
			$pid = (int) $item['id'];
			if ( isset( $reviewed_map[ $pid ] ) ) {
				$reviewed[] = $item;
			} else {
				$pending[] = $item;
			}
		}

		return array(
			'pending'        => $pending,
			'reviewed'       => $reviewed,
			'total_count'    => count( $items ),
			'reviewed_count' => count( $reviewed ),
			'pending_count'  => count( $pending ),
		);
	}

	/**
	 * Calcula el estado de reseñas pendientes sobre una lista de productos ya resueltos
	 * en `/mi-cuenta/coleccion/` (1 sola consulta SQL sobre `wp_comments`).
	 *
	 * @param int             $user_id            ID del cliente autenticado.
	 * @param array<int, int> $owned_product_ids  IDs de productos en la colección.
	 * @param array<int, int> $manual_product_ids IDs agregados manualmente a la estantería (no elegibles).
	 * @return array{
	 *   pending_ids: array<int, int>,
	 *   reviewed_ids: array<int, int>,
	 *   pending_count: int,
	 *   remaining_quota: int,
	 *   points_per_review: int,
	 *   points_label: string
	 * }
	 */
	public static function pending_for_collection(
		int $user_id,
		array $owned_product_ids,
		array $manual_product_ids = array()
	): array {
		$empty = array(
			'pending_ids'       => array(),
			'reviewed_ids'      => array(),
			'pending_count'     => 0,
			'remaining_quota'   => 0,
			'points_per_review' => 0,
			'points_label'      => '',
		);

		if ( $user_id <= 0 || ! Options::reviews_engine_on() ) {
			return $empty;
		}

		$manual_lookup = array_fill_keys( array_map( 'intval', $manual_product_ids ), true );
		$verified_ids  = array();
		foreach ( $owned_product_ids as $raw_id ) {
			$pid = (int) $raw_id;
			if ( $pid > 0 && ! isset( $manual_lookup[ $pid ] ) ) {
				$verified_ids[ $pid ] = $pid;
			}
		}

		if ( array() === $verified_ids ) {
			return $empty;
		}

		$user  = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : ( function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : false );
		$email = ( is_object( $user ) && isset( $user->user_email ) ) ? (string) $user->user_email : '';

		$reviewed_ids = self::reviewed_product_ids_for_customer( $user_id, $email, array_values( $verified_ids ) );
		$pending_ids  = array_diff_key( $verified_ids, $reviewed_ids );

		$quota_info = self::user_points_quota( $user_id );

		return array(
			'pending_ids'       => $pending_ids,
			'reviewed_ids'      => $reviewed_ids,
			'pending_count'     => count( $pending_ids ),
			'remaining_quota'   => $quota_info['remaining_quota'],
			'points_per_review' => $quota_info['points_per_review'],
			'points_label'      => $quota_info['points_label'],
		);
	}

	/**
	 * Resumen rápido de tomos comprados pendientes de reseñar para `/mi-cuenta/rewards/`.
	 *
	 * Reutiliza el lookup cacheado por generación de `CustomerCollection` si está activo,
	 * o los pedidos verificados recientes del usuario.
	 *
	 * @param int $user_id ID del usuario autenticado.
	 * @return array{
	 *   pending_count: int,
	 *   rewardable_count: int,
	 *   points_per_review: int,
	 *   total_potential_points: int,
	 *   collection_url: string
	 * }
	 */
	public static function summary_for_user( int $user_id ): array {
		$collection_url = function_exists( 'wc_get_account_endpoint_url' )
			? (string) wc_get_account_endpoint_url( 'orders' )
			: home_url( '/mi-cuenta/pedidos/' );

		$empty = array(
			'pending_count'          => 0,
			'rewardable_count'       => 0,
			'points_per_review'      => 0,
			'total_potential_points' => 0,
			'collection_url'         => $collection_url,
		);

		if ( $user_id <= 0 || ! Options::reviews_engine_on() ) {
			return $empty;
		}

		$purchased_ids = self::purchased_product_ids_for_user( $user_id );
		if ( array() === $purchased_ids ) {
			return $empty;
		}

		$state            = self::pending_for_collection( $user_id, $purchased_ids );
		$pending_count    = $state['pending_count'];
		$rewardable_count = min( $pending_count, $state['remaining_quota'] );
		$every_n          = max( 1, Options::review_every_n() );
		$potential_points = $state['points_per_review'] > 0 && $rewardable_count > 0
			? (int) floor( $rewardable_count / $every_n ) * $state['points_per_review']
			: 0;

		return array(
			'pending_count'          => $pending_count,
			'rewardable_count'       => $rewardable_count,
			'points_per_review'      => $state['points_per_review'],
			'total_potential_points' => $potential_points,
			'collection_url'         => $collection_url,
		);
	}

	/**
	 * Devuelve el ID del pedido elegible más reciente en que el usuario compró un producto
	 * (con cantidad neta > 0 tras reembolsos).
	 *
	 * @param int $user_id    ID del cliente.
	 * @param int $product_id ID del producto.
	 * @return int ID del pedido o 0 si no existe compra verificada elegible.
	 */
	// phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh
	public static function find_verified_order_for_product( int $user_id, int $product_id ): int { // @phpstan-ignore complexity.functionLike
		if ( $user_id <= 0 || $product_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$user   = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : false;
		$email  = ( is_object( $user ) && isset( $user->user_email ) ) ? strtolower( trim( (string) $user->user_email ) ) : '';
		$seen   = array();
		$orders = array();

		$by_id = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => self::verified_purchase_statuses(),
				'limit'       => 25,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		foreach ( (array) $by_id as $order ) {
			if ( $order instanceof \WC_Order ) {
				$seen[ $order->get_id() ] = true;
				$orders[]                 = $order;
			}
		}

		if ( '' !== $email ) {
			$by_email = wc_get_orders(
				array(
					'billing_email' => $email,
					'status'        => self::verified_purchase_statuses(),
					'limit'         => 25,
					'orderby'       => 'date',
					'order'         => 'DESC',
				)
			);
			foreach ( (array) $by_email as $order ) {
				if ( ! ( $order instanceof \WC_Order ) || isset( $seen[ $order->get_id() ] ) ) {
					continue;
				}
				$order_customer = (int) $order->get_customer_id();
				if ( $order_customer > 0 && $order_customer !== $user_id ) {
					continue;
				}
				if ( strtolower( trim( (string) $order->get_billing_email() ) ) !== $email ) {
					continue;
				}
				$orders[] = $order;
			}
		}

		foreach ( $orders as $order ) {
			foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
					continue;
				}
				if ( (int) $item->get_product_id() !== $product_id ) {
					continue;
				}
				$qty      = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
				$refunded = method_exists( $order, 'get_qty_refunded_for_item' ) // @phpstan-ignore function.alreadyNarrowedType
					? abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) )
					: 0;
				if ( $qty - $refunded > 0 ) {
					return (int) $order->get_id();
				}
			}
		}

		return 0;
	}

	/**
	 * Calcula cuántos premios de reseña con foto le quedan disponibles al cliente.
	 *
	 * @param int $user_id ID del cliente.
	 * @return array{remaining_quota: int, points_per_review: int, points_label: string}
	 */
	public static function user_points_quota( int $user_id ): array {
		if ( $user_id <= 0 || ! Options::reviews_lane_on() ) {
			return array(
				'remaining_quota'   => 0,
				'points_per_review' => 0,
				'points_label'      => '',
			);
		}

		$points = Options::review_points();
		if ( $points <= 0 ) {
			return array(
				'remaining_quota'   => 0,
				'points_per_review' => 0,
				'points_label'      => '',
			);
		}

		$max_per_user    = Options::review_max_per_user();
		$remaining_quota = PHP_INT_MAX;
		if ( $max_per_user > 0 ) {
			$paid            = Ledger::count_live_credits( $user_id, 'review', 'review', 'review_reversal', 30 );
			$remaining_quota = max( 0, $max_per_user - $paid );
		}

		$every = max( 1, Options::review_every_n() );
		$label = '+' . number_format_i18n( $points ) . ' Puntos Akibara';
		if ( $every > 1 ) {
			$label .= ' cada ' . number_format_i18n( $every ) . ' reseñas';
		}

		return array(
			'remaining_quota'   => $remaining_quota,
			'points_per_review' => $points,
			'points_label'      => $label,
		);
	}

	/**
	 * Extrae las líneas de producto elegibles (no reembolsadas y publicadas) de un pedido.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<int, array{id:int, name:string, thumb:string, url:string}>
	 */
	public static function extract_order_items( \WC_Order $order ): array {
		$seen = array();
		$out  = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			$qty      = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;
			$refunded = method_exists( $order, 'get_qty_refunded_for_item' ) // @phpstan-ignore function.alreadyNarrowedType
				? abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) )
				: 0;
			if ( $qty - $refunded <= 0 ) {
				continue;
			}
			$pid = (int) $item->get_product_id();
			if ( $pid <= 0 || isset( $seen[ $pid ] ) ) {
				continue;
			}
			if ( function_exists( 'get_post_status' ) ) {
				$status = get_post_status( $pid );
				if ( false === $status || 'publish' !== $status ) {
					continue;
				}
			}
			$seen[ $pid ] = true;
			$thumb        = function_exists( 'get_the_post_thumbnail_url' )
				? (string) get_the_post_thumbnail_url( $pid, 'woocommerce_gallery_thumbnail' )
				: '';
			$url          = function_exists( 'get_permalink' ) ? (string) get_permalink( $pid ) : '';
			$out[]        = array(
				'id'    => $pid,
				'name'  => method_exists( $item, 'get_name' ) ? (string) $item->get_name() : ( '#' . $pid ),
				'thumb' => $thumb,
				'url'   => $url,
			);
		}

		return $out;
	}

	/**
	 * Obtiene los IDs de productos comprados por el usuario.
	 *
	 * @param int $user_id ID del cliente.
	 * @return list<int>
	 */
	private static function purchased_product_ids_for_user( int $user_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => self::verified_purchase_statuses(),
				'limit'       => 25,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		$ids = array();
		foreach ( (array) $orders as $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				continue;
			}
			foreach ( self::extract_order_items( $order ) as $item ) {
				$pid         = (int) $item['id'];
				$ids[ $pid ] = $pid;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Estados de pedido que califican como compra verificada en Akibara.
	 *
	 * @return list<string>
	 */
	public static function verified_purchase_statuses(): array {
		return array( 'processing', 'completed' );
	}

	/**
	 * Claves de identidad para la caché por petición.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $email   Correo.
	 * @return list<string>
	 */
	private static function identity_keys( int $user_id, string $email ): array {
		$keys  = array();
		$email = strtolower( trim( $email ) );
		if ( $user_id > 0 ) {
			$keys[] = 'u:' . $user_id;
		}
		if ( '' !== $email ) {
			$keys[] = 'e:' . $email;
		}
		return $keys;
	}
}
