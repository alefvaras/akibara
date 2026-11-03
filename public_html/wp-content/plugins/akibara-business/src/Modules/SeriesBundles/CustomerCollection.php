<?php
/**
 * Series Bundles — Progreso de colección del cliente.
 *
 * Devuelve los `product_id` distintos que el usuario LOGUEADO conserva después
 * de netear compras y reembolsos. El builder "Arma tu pack" intersecta ese
 * histórico con los tomos de una serie; Mi cuenta lo consume completo. Reusa el
 * patrón de query sobre las lookup tables de WC
 * Analytics (`wc_order_product_lookup` + `wc_order_stats` + `wc_customer_lookup`).
 * `SeriesFollow\FollowerFinder` recorre esa misma consulta en sentido inverso
 * (serie → compradores) reusando los tres métodos públicos de aquí. Cada fila se cruza
 * además con el almacén canónico de pedidos (`wc_orders` en HPOS, `posts` en
 * CPT): así una fila negativa huérfana de un refund borrado no puede ocultar
 * una compra viva. Sigue siendo 1 query indexada, sin hidratar `WC_Order`.
 *
 * Caché por `user_id` + generación en transient (TTL 1 día), invalidado cuando
 * cambia el estado o una línea de producto del
 * pedido, y ante un reembolso de productos aunque el estado no cambie. La
 * generación vive en user meta: `update_user_meta()` invalida también el object
 * cache persistente, a diferencia de borrar filas `_transient_*` por SQL directo.
 * Degradación explícita: `empty` significa que la lectura fiable no encontró
 * productos; `unavailable`, que la infraestructura no pudo probarlo. Sólo los
 * resultados fiables se cachean. El builder conserva su API tolerante y degrada
 * `unavailable` a `array()` para no romper la compra.
 *
 * Sin funciones i18n (Req 11.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Utilities\OrderStorage;

defined( 'ABSPATH' ) || exit;

final class CustomerCollection {

	/** Lookup fiable con al menos un producto. */
	public const RESULT_OK = 'ok';

	/** Lookup fiable sin productos. */
	public const RESULT_EMPTY = 'empty';

	/** No fue posible distinguir una colección vacía de una falla. */
	public const RESULT_UNAVAILABLE = 'unavailable';

	private const CACHE_VERSION      = 3;
	private const TRANSIENT_PREFIX   = 'akb_byp_owned_all_v3_';
	private const OWNER_FENCE_PREFIX = 'akb_byp_owner_fence_';
	private const OWNER_FENCE_CLEANUP_HOOK = 'akibara_business_cleanup_collection_owner_fence';
	private const OWNER_FENCE_TTL     = 2 * DAY_IN_SECONDS;
	private const OWNER_FENCE_PURGE_TTL = 7 * DAY_IN_SECONDS;
	private const OWNER_FENCE_MAX_BATCH = 64;
	private const GENERATION_META    = '_akb_byp_owned_generation';
	private const TTL                = DAY_IN_SECONDS;

	/** Base de datos inyectable para pruebas; null usa el `$wpdb` global. */
	private ?object $database;

	/** @var array<int,bool> Pedidos cuyo bulk-delete `null` contenía productos. */
	private array $pending_bulk_product_removals = array();

	/** @var array<int,int> Dueño persistido antes de guardar cada pedido. */
	private array $pre_save_customer_ids = array();

	/** @var array<int,int> Pedido persistido antes de guardar cada line item. */
	private array $pre_save_item_order_ids = array();

	/** @var array<int,int> Refund ID a parent order ID hasta el post-delete o shutdown. */
	private array $pending_refund_parent_ids = array();

	/** @var array<int,int> Dueño disponible hasta el post-delete del mismo request. */
	private array $pending_deleted_order_user_ids = array();

	/**
	 * @param object|null $database Dependencia compatible con wpdb para pruebas.
	 */
	public function __construct( ?object $database = null ) {
		$this->database = $database;
	}

	/** Registra las fronteras CRUD que pueden cambiar la colección comprada. */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'flush_for_order' ), 10, 1 );
		add_action( 'woocommerce_before_order_object_save', array( $this, 'capture_order_customer_before_save' ), 999, 1 );
		add_action( 'woocommerce_update_order', array( $this, 'handle_order_customer_updated' ), 20, 2 );
		add_action( 'woocommerce_before_delete_order', array( $this, 'handle_order_deleted' ), 5, 2 );
		add_action( 'woocommerce_delete_order', array( $this, 'handle_order_delete_completed' ), 10, 1 );
		add_filter( 'woocommerce_pre_delete_order_refund', array( $this, 'handle_refund_before_delete' ), 10, 3 );
		add_action( 'woocommerce_delete_order_refund', array( $this, 'handle_refund_delete_completed' ), 10, 1 );
		add_action( 'woocommerce_refund_deleted', array( $this, 'handle_refund_deleted' ), 10, 2 );
		add_action( 'woocommerce_before_order_item_object_save', array( $this, 'capture_order_item_before_save' ), 10, 2 );
		add_action( 'woocommerce_new_order_item', array( $this, 'handle_order_item_change' ), 10, 3 );
		add_action( 'woocommerce_update_order_item', array( $this, 'handle_order_item_change' ), 10, 3 );
		add_action( 'woocommerce_before_delete_order_item', array( $this, 'handle_order_item_change' ), 10, 1 );
		add_action( 'woocommerce_remove_order_items', array( $this, 'capture_bulk_product_removal' ), 10, 2 );
		add_action( 'woocommerce_removed_order_items', array( $this, 'handle_bulk_product_removal' ), 10, 2 );
		add_action( 'woocommerce_order_refunded', array( $this, 'handle_order_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_scheduler_after_import_order', array( $this, 'handle_analytics_import' ), 10, 1 );
		add_action( self::OWNER_FENCE_CLEANUP_HOOK, array( $this, 'handle_owner_fence_cleanup' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'handle_shutdown' ), PHP_INT_MAX, 0 );
	}

	/**
	 * IDs de `$candidate_ids` que el usuario ya compró en pedidos pagados.
	 * Invitado, colección vacía o lookup no disponible ⇒ `array()` para
	 * mantener el contrato tolerante del builder.
	 *
	 * @param int             $user_id
	 * @param array<int, int> $candidate_ids product_ids de los tomos de la serie.
	 * @return array<int, int>
	 */
	public function owned_among( int $user_id, array $candidate_ids ): array {
		$candidate_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $candidate_ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);
		if ( array() === $candidate_ids ) {
			return array();
		}

		$result = $this->owned_product_ids_result( $user_id );
		if ( self::RESULT_OK !== $result['status'] ) {
			return array();
		}

		$owned_lookup = array_fill_keys( $result['product_ids'], true );

		return array_values(
			array_filter(
				$candidate_ids,
				static fn ( int $product_id ): bool => isset( $owned_lookup[ $product_id ] )
			)
		);
	}

	/**
	 * Devuelve el histórico neto de productos distintos del cliente.
	 *
	 * `empty` es una respuesta positiva y fiable. `unavailable` nunca se escribe
	 * en caché: una caída transitoria no puede convertirse en un día de falso
	 * “no tienes tomos”.
	 *
	 * @return array{status:'ok'|'empty'|'unavailable',product_ids:list<int>}
	 */
	public function owned_product_ids_result( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return $this->result( self::RESULT_EMPTY, array() );
		}

		$generation = (string) get_user_meta( $user_id, self::GENERATION_META, true );
		$key        = self::TRANSIENT_PREFIX . $user_id . '_g' . md5( $generation );
		$cached     = $this->normalize_cached_result( get_transient( $key ) );
		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$product_ids = $this->query_owned_product_ids( $user_id );
		} catch ( \Throwable $exception ) {
			unset( $exception );
			return $this->result( self::RESULT_UNAVAILABLE, array() );
		}

		if ( null === $product_ids ) {
			return $this->result( self::RESULT_UNAVAILABLE, array() );
		}

		$status = array() === $product_ids ? self::RESULT_EMPTY : self::RESULT_OK;
		$result = $this->result( $status, $product_ids );
		set_transient(
			$key,
			array(
				'version'     => self::CACHE_VERSION,
				'status'      => $result['status'],
				'product_ids' => $result['product_ids'],
			),
			self::TTL
		);

		return $result;
	}

	/**
	 * Estados de pedido que cuentan como "lo tenés": pagados, incluyendo
	 * preventas pagadas en tránsito.
	 * `cancelled`/`refunded`/`failed`/`pending`/`on-hold` NO.
	 *
	 * `public` porque la consulta INVERSA (serie → compradores) vive en
	 * `SeriesFollow\FollowerFinder` y tiene que preguntar por esta definición en
	 * vez de reescribirla: dos listas «equivalentes» de estados de compra es el
	 * defecto más difícil de ver, y el filtro de abajo dejaría de ser el único
	 * mando.
	 *
	 * @return array<int, string>
	 */
	public function purchase_statuses(): array {
		$statuses = array( 'wc-processing', 'wc-completed', 'wc-shipping-progress' );
		$filtered = apply_filters( 'akibara_business_series_purchase_statuses', $statuses );

		if ( ! is_array( $filtered ) ) {
			return $statuses;
		}

		$filtered = array_values(
			array_unique(
				array_filter(
					$filtered,
					static fn ( $status ): bool => is_string( $status ) && '' !== trim( $status )
				)
			)
		);

		return array() !== $filtered ? $filtered : $statuses;
	}

	/**
	 * Query única sobre las lookup tables de WC Analytics.
	 *
	 * @return list<int>|null IDs netos, o null si la lectura no fue fiable.
	 */
	private function query_owned_product_ids( int $user_id ): ?array {
		$database = $this->database();
		if (
			null === $database
			|| ! method_exists( $database, 'prepare' )
			|| ! method_exists( $database, 'get_col' )
			|| ! isset( $database->prefix )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
		) {
			return null;
		}

		$prefix              = (string) $database->prefix;
		$statuses            = $this->purchase_statuses();
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$authoritative_joins = $this->authoritative_order_joins( $prefix );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names derive from wpdb prefix; placeholders are generated tokens only.
		$query = $database->prepare(
			"SELECT products.product_id
				FROM {$prefix}wc_order_product_lookup products
				INNER JOIN {$prefix}wc_order_stats activity
					ON activity.order_id = products.order_id
				INNER JOIN {$prefix}wc_order_stats parent_orders
					ON parent_orders.order_id = CASE
						WHEN activity.parent_id > 0 THEN activity.parent_id
						ELSE activity.order_id
					END
				INNER JOIN {$prefix}wc_customer_lookup customers
					ON customers.customer_id = parent_orders.customer_id
					AND customers.user_id = %d
				{$authoritative_joins}
				WHERE parent_orders.status IN ({$status_placeholders})
					AND products.product_id > 0
				GROUP BY products.product_id
				HAVING SUM(products.product_qty) > 0
				ORDER BY products.product_id ASC",
			...array_merge( array( $user_id ), $statuses )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}

		$rows = $database->get_col( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; result is cached per customer generation.
		if (
			! is_array( $rows )
			|| ( isset( $database->last_error ) && '' !== trim( (string) $database->last_error ) )
			|| ( isset( $database->ready ) && ! (bool) $database->ready )
			|| ( isset( $database->last_query ) && (string) $database->last_query !== $query )
		) {
			return null;
		}

		$product_ids = array();
		foreach ( $rows as $row ) {
			$product_id = self::normalize_id( $row );
			if ( $product_id < 1 ) {
				continue;
			}
			$product_ids[ $product_id ] = $product_id;
		}

		$product_ids = array_values( $product_ids );
		sort( $product_ids, SORT_NUMERIC );

		return $product_ids;
	}

	/**
	 * Exige que las filas Analytics correspondan a un pedido/refund todavía vivo.
	 *
	 * `public` por la misma razón que `purchase_statuses()`: la consulta inversa
	 * de `SeriesFollow\FollowerFinder` reusa estos joins en vez de mantener una
	 * segunda copia de la rama HPOS/CPT.
	 *
	 * @param string $prefix Prefijo de tablas validado por wpdb.
	 */
	public function authoritative_order_joins( string $prefix ): string {
		if ( $this->uses_hpos_order_storage() ) {
			return "INNER JOIN {$prefix}wc_orders live_activity
					ON live_activity.id = activity.order_id
					AND live_activity.type IN ('shop_order', 'shop_order_refund')
				INNER JOIN {$prefix}wc_orders live_parent
					ON live_parent.id = parent_orders.order_id
					AND live_parent.id = CASE
						WHEN live_activity.parent_order_id > 0 THEN live_activity.parent_order_id
						ELSE live_activity.id
					END
					AND live_parent.type = 'shop_order'";
		}

		return "INNER JOIN {$prefix}posts live_activity
				ON live_activity.ID = activity.order_id
				AND live_activity.post_type IN ('shop_order', 'shop_order_refund')
			INNER JOIN {$prefix}posts live_parent
				ON live_parent.ID = parent_orders.order_id
				AND live_parent.ID = CASE
					WHEN live_activity.post_parent > 0 THEN live_activity.post_parent
					ELSE live_activity.ID
				END
				AND live_parent.post_type = 'shop_order'";
	}

	/**
	 * Consulta si WooCommerce usa sus tablas propias de pedidos.
	 *
	 * Utilities\OrderStorage centraliza la detección de HPOS para el árbol.
	 * Este adaptador sólo mantiene el consumo privado del componente.
	 */
	private function uses_hpos_order_storage(): bool {
		return OrderStorage::uses_hpos();
	}

	/** Returns the injected database or the normal WordPress global. */
	private function database(): ?object {
		if ( null !== $this->database ) {
			return $this->database;
		}

		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) ? $wpdb : null;
	}

	/**
	 * @param mixed $cached Valor crudo del transient.
	 * @return array{status:'ok'|'empty'|'unavailable',product_ids:list<int>}|null
	 */
	private function normalize_cached_result( $cached ): ?array {
		if (
			! is_array( $cached )
			|| self::CACHE_VERSION !== (int) ( $cached['version'] ?? 0 )
			|| ! isset( $cached['status'], $cached['product_ids'] )
			|| ! is_string( $cached['status'] )
			|| ! is_array( $cached['product_ids'] )
			|| ! in_array( $cached['status'], array( self::RESULT_OK, self::RESULT_EMPTY ), true )
		) {
			return null;
		}

		$product_ids = array();
		foreach ( $cached['product_ids'] as $value ) {
			$product_id = self::normalize_id( $value );
			if ( $product_id < 1 ) {
				return null;
			}
			$product_ids[ $product_id ] = $product_id;
		}

		$product_ids = array_values( $product_ids );
		sort( $product_ids, SORT_NUMERIC );
		if (
			( self::RESULT_OK === $cached['status'] && array() === $product_ids )
			|| ( self::RESULT_EMPTY === $cached['status'] && array() !== $product_ids )
		) {
			return null;
		}

		return $this->result( $cached['status'], $product_ids );
	}

	/**
	 * @param 'ok'|'empty'|'unavailable' $status Estado del lookup.
	 * @param list<int>                  $product_ids IDs normalizados.
	 * @return array{status:'ok'|'empty'|'unavailable',product_ids:list<int>}
	 */
	private function result( string $status, array $product_ids ): array {
		return array(
			'status'      => $status,
			'product_ids' => $product_ids,
		);
	}

	/** Normalizes one positive integer ID without accepting lossy values. */
	private static function normalize_id( $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
			return 0;
		}

		$value = (int) $value;

		return $value > 0 ? $value : 0;
	}

	/**
	 * Invalida la colección al crear, editar o borrar una línea de PRODUCTO.
	 *
	 * WooCommerce moderno entrega el objeto y el order ID en los hooks de alta y
	 * edición, pero el helper legacy de edición entrega un array y el hook previo
	 * al borrado entrega sólo el item ID. En todos los casos se vuelve al objeto
	 * canónico por `WC_Order_Factory` cuando hace falta y el pedido se deriva de la
	 * propia línea: el tercer argumento del hook nunca puede hacer que se invalide
	 * la caché de un pedido ajeno. Cupones, envíos, tasas y fees no cambian los
	 * tomos que el cliente posee y se ignoran.
	 *
	 * @param int|mixed                      $item_id         ID de la línea.
	 * @param \WC_Order_Item_Product|mixed $item            Objeto moderno o payload legacy.
	 * @param int|mixed                      $reported_order_id Order ID informativo del hook moderno.
	 * @internal Callback de hooks WooCommerce.
	 */
	public function handle_order_item_change( $item_id, $item = null, $reported_order_id = 0 ): void {
		unset( $reported_order_id );

		$item_id      = (int) $item_id;
		$product_item = $this->resolve_product_item( $item_id, $item );
		if ( null === $product_item || ! method_exists( $product_item, 'get_order_id' ) ) {
			unset( $this->pre_save_item_order_ids[ $item_id ] );
			return;
		}

		$new_order_id = (int) $product_item->get_order_id();
		$old_order_id = $this->pre_save_item_order_ids[ $item_id ] ?? 0;
		unset( $this->pre_save_item_order_ids[ $item_id ] );
		if ( $old_order_id > 0 && $old_order_id !== $new_order_id ) {
			$this->flush_for_order( $old_order_id );
		}
		$this->flush_for_order( $new_order_id );
	}

	/**
	 * Captura el order ID persistido antes de un posible reparent del line item.
	 *
	 * @param \WC_Order_Item_Product|mixed $item       Línea antes de guardarse.
	 * @param mixed                        $data_store Data store informativo.
	 * @internal Callback de `woocommerce_before_order_item_object_save`.
	 */
	public function capture_order_item_before_save( $item, $data_store = null ): void {
		unset( $data_store );
		if ( ! $item instanceof \WC_Order_Item_Product || ! method_exists( $item, 'get_data' ) ) {
			return;
		}

		$item_id = (int) $item->get_id();
		$data    = $item->get_data();
		if ( $item_id > 0 && is_array( $data ) ) {
			$this->pre_save_item_order_ids[ $item_id ] = isset( $data['order_id'] ) ? (int) $data['order_id'] : 0;
		}
	}

	/**
	 * Captura el dueño persistido: `WC_Data::$changes` aún no fue aplicado.
	 *
	 * @param \WC_Order|mixed $order Pedido antes de guardarse.
	 * @internal Callback de `woocommerce_before_order_object_save`.
	 */
	public function capture_order_customer_before_save( $order ): void {
		if ( ! $order instanceof \WC_Order
			|| ! method_exists( $order, 'get_type' )
			|| 'shop_order' !== $order->get_type()
			|| ! method_exists( $order, 'get_data' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$data     = $order->get_data();
		if ( $order_id > 0 && is_array( $data ) ) {
			$old_user_id = isset( $data['customer_id'] ) ? (int) $data['customer_id'] : 0;
			$new_user_id = (int) $order->get_user_id( 'edit' );
			$this->pre_save_customer_ids[ $order_id ] = $old_user_id;
			if ( $old_user_id !== $new_user_id ) {
				$this->append_owner_fence( $order_id, $old_user_id );
			}
		}
	}

	/**
	 * Al reasignar un pedido invalida tanto al dueño anterior como al nuevo.
	 *
	 * @param int|mixed       $order_id Pedido actualizado.
	 * @param \WC_Order|mixed $order    Objeto después de guardarse.
	 * @internal Callback de `woocommerce_update_order`.
	 */
	public function handle_order_customer_updated( $order_id, $order ): void {
		$order_id = (int) $order_id;
		$old_user_id = $this->pre_save_customer_ids[ $order_id ] ?? null;
		unset( $this->pre_save_customer_ids[ $order_id ] );
		if ( null === $old_user_id || ! $order instanceof \WC_Order ) {
			return;
		}

		$new_user_id = (int) $order->get_user_id();
		if ( $old_user_id === $new_user_id ) {
			return;
		}

		$this->flush_user( $old_user_id );
		$this->flush_user( $new_user_id );
	}

	/**
	 * Una eliminación directa no necesita transición de estado: invalida al dueño.
	 *
	 * @param int|mixed       $order_id Pedido eliminado (informativo).
	 * @param \WC_Order|mixed $order    Objeto canónico previo a borrar.
	 * @internal Callback de `woocommerce_before_delete_order`.
	 */
	public function handle_order_deleted( $order_id, $order ): void {
		unset( $order_id );
		if ( $order instanceof \WC_Order ) {
			$canonical_order_id = (int) $order->get_id();
			$user_id            = (int) $order->get_user_id();
			if ( $canonical_order_id > 0 && $user_id > 0 ) {
				$this->pending_deleted_order_user_ids[ $canonical_order_id ] = $user_id;
			}
			$this->append_owner_fence( $canonical_order_id, $user_id );
			$this->flush_order_object( $order );
			return;
		}

		if ( is_object( $order )
			&& method_exists( $order, 'get_parent_id' )
			&& $this->refund_affects_products( $order ) ) {
			$this->flush_for_order( (int) $order->get_parent_id() );
		}
	}

	/**
	 * Consume el ledger después de que WooCommerce terminó el borrado y sus lookups.
	 *
	 * @param int|mixed $order_id Pedido que WooCommerce acaba de eliminar.
	 * @internal Callback de `woocommerce_delete_order`.
	 */
	public function handle_order_delete_completed( $order_id ): void {
		$order_id = (int) $order_id;
		$user_id  = $this->pending_deleted_order_user_ids[ $order_id ] ?? 0;
		unset( $this->pending_deleted_order_user_ids[ $order_id ] );
		$this->consume_owner_fences( $order_id, array( $user_id ) );
	}

	/**
	 * El AJAX de Woo expone el parent después de borrar el refund.
	 *
	 * @param int|mixed $refund_id Refund eliminado (informativo).
	 * @param int|mixed $order_id  Pedido padre.
	 * @internal Callback de `woocommerce_refund_deleted`.
	 */
	public function handle_refund_deleted( $refund_id, $order_id ): void {
		unset( $this->pending_refund_parent_ids[ (int) $refund_id ] );
		$this->flush_for_order( (int) $order_id );
	}

	/**
	 * Segunda invalidación después del borrado real del refund en el data store.
	 *
	 * @param int|mixed $refund_id Refund que WooCommerce acaba de eliminar.
	 * @internal Callback de `woocommerce_delete_order_refund`.
	 */
	public function handle_refund_delete_completed( $refund_id ): void {
		$refund_id = (int) $refund_id;
		$order_id  = $this->pending_refund_parent_ids[ $refund_id ] ?? 0;
		unset( $this->pending_refund_parent_ids[ $refund_id ] );

		if ( $order_id > 0 ) {
			$this->flush_for_order( $order_id );
		}
	}

	/**
	 * Cierra deletes de refund sin hook post-delete una vez terminada la request.
	 *
	 * La cola se consume antes de invalidar: llamadas repetidas son idempotentes y
	 * varios refunds del mismo pedido producen un único bump final.
	 *
	 * @internal Callback de `shutdown`.
	 */
	public function handle_shutdown(): void {
		$order_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $this->pending_refund_parent_ids ),
					static fn ( int $order_id ): bool => $order_id > 0
				)
			)
		);
		$this->pending_refund_parent_ids = array();

		foreach ( $order_ids as $order_id ) {
			$this->flush_for_order( $order_id );
		}
	}

	/**
	 * Cubre `WC_Order_Refund::delete()` en cualquier data store antes de perder el
	 * parent ID. Es filtro de cortocircuito: siempre devuelve el valor intacto.
	 *
	 * @param mixed        $pre_delete   Valor de short-circuit de WooCommerce.
	 * @param object|mixed $refund       Refund todavía hidratado.
	 * @param bool|mixed   $force_delete Borrado forzado (informativo).
	 * @return mixed
	 * @internal Filtro `woocommerce_pre_delete_order_refund`.
	 */
	public function handle_refund_before_delete( $pre_delete, $refund, $force_delete ) {
		unset( $force_delete );
		if ( null === $pre_delete
			&& is_object( $refund )
			&& method_exists( $refund, 'get_parent_id' )
			&& $this->refund_affects_products( $refund ) ) {
			$refund_id = method_exists( $refund, 'get_id' ) ? (int) $refund->get_id() : 0;
			$order_id  = (int) $refund->get_parent_id();
			if ( $refund_id > 0 && $order_id > 0 ) {
				$this->pending_refund_parent_ids[ $refund_id ] = $order_id;
			}
			$this->flush_for_order( $order_id );
		}

		return $pre_delete;
	}

	/**
	 * Recuerda si un `remove_order_items( null)` realmente incluía productos.
	 * WooCommerce 11 borra esos ítems en lote y no dispara el hook individual.
	 *
	 * @param object|mixed $order Pedido o refund antes del borrado persistido.
	 * @param string|null  $type  Tipo concreto o null para todos.
	 * @internal Callback de `woocommerce_remove_order_items`.
	 */
	public function capture_bulk_product_removal( $order, $type ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || null !== $type ) {
			return;
		}

		$order_id = (int) $order->get_id();
		if ( $order_id > 0 ) {
			$this->pending_bulk_product_removals[ $order_id ] = $this->has_product_items( $order );
		}
	}

	/**
	 * Invalida el bulk-delete ya persistido sólo si pudo afectar line items.
	 *
	 * @param object|mixed $order Pedido o refund después del borrado.
	 * @param string|null  $type  `line_item`, otro tipo o null para todos.
	 * @internal Callback de `woocommerce_removed_order_items`.
	 */
	public function handle_bulk_product_removal( $order, $type ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
			return;
		}

		$order_id = (int) $order->get_id();
		$affects_products = 'line_item' === $type
			|| ( null === $type && ! empty( $this->pending_bulk_product_removals[ $order_id ] ) );
		unset( $this->pending_bulk_product_removals[ $order_id ] );
		if ( ! $affects_products ) {
			return;
		}

		if ( $order instanceof \WC_Order ) {
			$this->flush_order_object( $order );
		} elseif ( method_exists( $order, 'get_parent_id' ) ) {
			$this->flush_for_order( (int) $order->get_parent_id() );
		}
	}

	/**
	 * Invalida un reembolso parcial de productos aunque el pedido siga pagado.
	 *
	 * Un reembolso sólo de despacho/fee no modifica la colección. También se
	 * comprueba el parent ID del refund para no confiar en un par de IDs
	 * inconsistente entregado por un callback externo.
	 *
	 * @param int|mixed $order_id  Pedido padre informado por WooCommerce.
	 * @param int|mixed $refund_id Reembolso recién creado.
	 * @internal Callback de `woocommerce_order_refunded`.
	 */
	public function handle_order_refunded( $order_id, $refund_id ): void {
		$order_id  = (int) $order_id;
		$refund_id = (int) $refund_id;
		if ( $order_id <= 0 || $refund_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! is_object( $refund )
			|| ! method_exists( $refund, 'get_parent_id' )
			|| $order_id !== (int) $refund->get_parent_id()
			|| ! method_exists( $refund, 'get_items' ) ) {
			return;
		}

		if ( $this->refund_affects_products( $refund ) ) {
			$this->flush_for_order( $order_id );
		}
	}

	/**
	 * Repite el bump DESPUÉS de que WooCommerce sincroniza sus lookup tables.
	 *
	 * Los hooks CRUD/refund anteriores invalidan de inmediato, pero Analytics
	 * puede importarse con Action Scheduler. Sin esta segunda frontera, una visita
	 * entre ambos eventos podría volver a cachear el lookup viejo durante un día.
	 * Para refunds se deriva el pedido padre y se exige una línea de producto; un
	 * reembolso sólo de envío no toca la colección.
	 *
	 * @param int|mixed $order_id Pedido o refund recién importado.
	 * @internal Callback de `woocommerce_order_scheduler_after_import_order`.
	 */
	public function handle_analytics_import( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}

		$user_ids = array();
		$imported = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $imported instanceof \WC_Order ) {
			$user_ids[] = (int) $imported->get_user_id();
		} elseif ( is_object( $imported )
			&& method_exists( $imported, 'get_parent_id' )
			&& $this->refund_affects_products( $imported ) ) {
			$parent = wc_get_order( (int) $imported->get_parent_id() );
			if ( $parent instanceof \WC_Order ) {
				$user_ids[] = (int) $parent->get_user_id();
			}
		}

		$this->consume_owner_fences( $order_id, $user_ids );
	}

	/**
	 * Limpia un fence vencido mediante WP-Cron sin aceptar nombres ajenos.
	 *
	 * @param string|mixed $option_name Option no-autoload agendada al crear el fence.
	 * @internal Callback de `akibara_business_cleanup_collection_owner_fence`.
	 */
	public function handle_owner_fence_cleanup( $option_name ): void {
		if ( ! is_string( $option_name ) || ! self::is_owner_fence_option( $option_name ) ) {
			return;
		}

		try {
			$stored = get_option( $option_name, false );
			if ( false === $stored ) {
				return;
			}

			if ( ! is_array( $stored )
				|| 1 !== (int) ( $stored['version'] ?? 0 )
				|| (int) ( $stored['order_id'] ?? 0 ) <= 0
				|| (int) ( $stored['user_id'] ?? 0 ) <= 0 ) {
				$this->delete_owner_fence( $option_name, true );
				return;
			}

			$expires_at  = (int) ( $stored['expires_at'] ?? 0 );
			$purge_at    = (int) ( $stored['purge_at'] ?? 0 );
			$at_hard_cap = $purge_at <= time();
			if ( ! $at_hard_cap && $expires_at > time() ) {
				$this->schedule_owner_fence_cleanup( $option_name, $expires_at );
				return;
			}

			$user_ids = array( (int) $stored['user_id'] );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $stored['order_id'] ) : null;
			if ( $order instanceof \WC_Order ) {
				$user_ids[] = (int) $order->get_user_id();
			}

			$all_flushed = true;
			foreach ( self::normalize_user_ids( $user_ids ) as $user_id ) {
				$all_flushed = $this->flush_user( $user_id ) && $all_flushed;
			}
			if ( $at_hard_cap ) {
				$this->delete_owner_fence( $option_name, true );
				return;
			}

			$retry_delay = $all_flushed ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
			$this->schedule_owner_fence_cleanup( $option_name, min( time() + $retry_delay, $purge_at ) );
		} catch ( \Throwable $e ) {
			$this->schedule_owner_fence_cleanup( $option_name, time() + HOUR_IN_SECONDS );
		}
	}

	/** Borra por API WP y reintenta si la fila sigue presente tras una falla. */
	private function delete_owner_fence( string $option_name, bool $retry ): bool {
		try {
			if ( delete_option( $option_name ) || false === get_option( $option_name, false ) ) {
				return true;
			}
		} catch ( \Throwable $e ) {
			// El reintento se agenda fuera del catch para cubrir false y excepción.
		}

		if ( $retry ) {
			$this->schedule_owner_fence_cleanup( $option_name, time() + HOUR_IN_SECONDS );
		}
		return false;
	}

	/** Agenda cleanup único en Action Scheduler, con WP-Cron como respaldo. */
	private function schedule_owner_fence_cleanup( string $option_name, int $timestamp ): void {
		if ( $timestamp <= 0 || ! self::is_owner_fence_option( $option_name ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			try {
				$action_id = as_schedule_single_action(
					$timestamp,
					self::OWNER_FENCE_CLEANUP_HOOK,
					array( $option_name ),
					'akibara-business',
					false
				);
				if ( is_int( $action_id ) && $action_id > 0 ) {
					return;
				}
			} catch ( \Throwable $e ) {
				// Continúa con WP-Cron.
			}
		}

		try {
			wp_schedule_single_event( $timestamp, self::OWNER_FENCE_CLEANUP_HOOK, array( $option_name ), true );
		} catch ( \Throwable $e ) {
			// El consumer oportunista de Analytics puede limpiar la fila después.
		}
	}

	/**
	 * Añade una fila inmutable y no-autoload con sólo IDs internos y vencimiento.
	 */
	private function append_owner_fence( int $order_id, int $user_id ): void {
		if ( $order_id <= 0 || $user_id <= 0 ) {
			return;
		}

		try {
			$expires_at = time() + self::OWNER_FENCE_TTL;
			$purge_at   = time() + self::OWNER_FENCE_PURGE_TTL;
			$option_name = self::owner_fence_prefix( $order_id ) . md5( wp_generate_uuid4() );
			$stored = add_option(
				$option_name,
				array(
					'version'    => 1,
					'order_id'   => $order_id,
					'user_id'    => $user_id,
					'expires_at' => $expires_at,
					'purge_at'   => $purge_at,
				),
				'',
				false
			);
			if ( false !== $stored ) {
				$this->schedule_owner_fence_cleanup( $option_name, $expires_at );
			}
		} catch ( \Throwable $e ) {
			// Los bumps inmediatos todavía evitan servir una caché previa al cambio.
		}
	}

	/**
	 * Rota dueños del snapshot y borra sólo esas filas; altas concurrentes quedan.
	 *
	 * @param array<int,int> $current_user_ids Dueños derivados tras sincronizar.
	 */
	private function consume_owner_fences( int $order_id, array $current_user_ids ): void {
		$ledger    = $this->read_owner_fences( $order_id );
		$user_ids  = self::normalize_user_ids( array_merge( $ledger['user_ids'], $current_user_ids ) );
		$all_flushed = true;
		foreach ( $user_ids as $user_id ) {
			$all_flushed = $this->flush_user( $user_id ) && $all_flushed;
		}
		if ( ! $all_flushed ) {
			return;
		}

		foreach ( $ledger['option_names'] as $option_name ) {
			$this->delete_owner_fence( $option_name, true );
		}
	}

	/**
	 * @return array{option_names:array<int,string>,user_ids:array<int,int>}
	 */
	private function read_owner_fences( int $order_id ): array {
		$empty = array(
			'option_names' => array(),
			'user_ids'     => array(),
		);
		if ( $order_id <= 0 ) {
			return $empty;
		}

		global $wpdb;
		if ( ! is_object( $wpdb )
			|| ! isset( $wpdb->options )
			|| ! method_exists( $wpdb, 'esc_like' )
			|| ! method_exists( $wpdb, 'prepare' )
			|| ! method_exists( $wpdb, 'get_col' ) ) {
			return $empty;
		}

		$prefix = self::owner_fence_prefix( $order_id );
		try {
			$sql = $wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d',
				$wpdb->options,
				$wpdb->esc_like( $prefix ) . '%',
				self::OWNER_FENCE_MAX_BATCH
			);
			// No existe una API de WP para enumerar options no-autoload por prefijo.
			$option_names = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
		} catch ( \Throwable $e ) {
			return $empty;
		}

		foreach ( array_unique( array_filter( array_map( 'strval', (array) $option_names ) ) ) as $option_name ) {
			if ( 0 !== strpos( $option_name, $prefix ) || ! self::is_owner_fence_option( $option_name ) ) {
				continue;
			}

			try {
				$stored = get_option( $option_name, false );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_array( $stored )
				|| 1 !== (int) ( $stored['version'] ?? 0 )
				|| $order_id !== (int) ( $stored['order_id'] ?? 0 ) ) {
				continue;
			}

			$user_id = (int) ( $stored['user_id'] ?? 0 );
			if ( $user_id > 0 ) {
				$empty['option_names'][] = $option_name;
				$empty['user_ids'][]     = $user_id;
			}
		}

		return $empty;
	}

	/**
	 * @param mixed $user_ids IDs internos sin datos de contacto.
	 * @return array<int,int>
	 */
	private static function normalize_user_ids( $user_ids ): array {
		if ( ! is_array( $user_ids ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);
	}

	/** Prefijo de filas append-only para un pedido saneado. */
	private static function owner_fence_prefix( int $order_id ): string {
		return self::OWNER_FENCE_PREFIX . $order_id . '_';
	}

	/** Sólo permite options creadas por este ledger. */
	private static function is_owner_fence_option( string $option_name ): bool {
		return 1 === preg_match( '/^akb_byp_owner_fence_[1-9][0-9]*_[a-f0-9]{32}$/D', $option_name );
	}

	/**
	 * Resuelve una línea canónica y exige que sea de producto.
	 *
	 * @param int   $item_id ID saneado.
	 * @param mixed $item    Objeto recibido por el hook, si existe.
	 */
	private function resolve_product_item( int $item_id, $item ): ?\WC_Order_Item_Product {
		if ( $item instanceof \WC_Order_Item_Product ) {
			return $item;
		}
		if ( $item_id <= 0 || ! class_exists( '\\WC_Order_Factory' ) ) {
			return null;
		}

		try {
			$resolved = \WC_Order_Factory::get_order_item( $item_id );
		} catch ( \Throwable $e ) {
			return null;
		}

		return $resolved instanceof \WC_Order_Item_Product ? $resolved : null;
	}

	/**
	 * @param object $order_like Pedido o refund con API `get_items()`.
	 */
	private function has_product_items( object $order_like ): bool {
		if ( ! method_exists( $order_like, 'get_items' ) ) {
			return false;
		}

		try {
			$items = $order_like->get_items( 'line_item' );
		} catch ( \Throwable $e ) {
			return false;
		}

		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Un full refund puede no traer line items: WooCommerce 11 sintetiza sus
	 * cantidades negativas desde el padre usando `_refund_type=full`.
	 */
	private function refund_affects_products( object $refund ): bool {
		if ( $this->has_product_items( $refund ) ) {
			return true;
		}

		return method_exists( $refund, 'get_meta' )
			&& 'full' === (string) $refund->get_meta( '_refund_type', true );
	}

	/**
	 * Invalida la caché de colección del usuario dueño del pedido incrementando
	 * su generación. Las entradas viejas quedan inaccesibles de inmediato y
	 * expiran por TTL; no se borran filas internas de transient a espaldas del
	 * object cache persistente.
	 *
	 * @param int|mixed $order_id
	 */
	public function flush_for_order( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$this->flush_order_object( $order );
			return;
		}

		// CRUD directo de una línea del refund entrega el refund ID, no el padre.
		if ( is_object( $order ) && method_exists( $order, 'get_parent_id' ) ) {
			$parent = wc_get_order( (int) $order->get_parent_id() );
			if ( $parent instanceof \WC_Order ) {
				$this->flush_order_object( $parent );
			}
		}
	}

	/** Rota la generación del usuario dueño del pedido canónico. */
	private function flush_order_object( \WC_Order $order ): bool {
		return $this->flush_user( (int) $order->get_user_id() );
	}

	/** Rota la generación de un usuario válido. */
	private function flush_user( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return true;
		}

		// Token write-only: dos invalidaciones concurrentes nunca colapsan en N+1.
		try {
			return false !== update_user_meta( $user_id, self::GENERATION_META, wp_generate_uuid4() );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
