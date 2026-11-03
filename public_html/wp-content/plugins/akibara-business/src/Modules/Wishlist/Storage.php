<?php
/**
 * Storage — CRUD de wishlist por usuario logueado.
 *
 * Login required: no hay session_key ni cookie guest. Las llamadas con
 * user_id=0 retornan no-op (add) o vacío (list/count). El botón corazón se
 * encarga de redirigir guests a login antes de llegar acá.
 *
 * Concurrencia: PRIMARY KEY (user_id, product_id) garantiza UNIQUE atómico.
 * INSERT IGNORE evita warning si el botón se clickea dos veces seguidas.
 *
 * Retención: la wishlist vive mientras vive la cuenta. NO hay purga por tiempo
 * (a diferencia de BackInStock, cuyas filas tienen estados terminales): son
 * favoritos que el usuario espera encontrar el año que viene, un TTL borraría
 * datos vivos. La supresión ocurre por el eraser de privacidad (PrivacyHandler)
 * o por `deleted_user` → on_user_deleted().
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\Wishlist;

defined( 'ABSPATH' ) || exit;

final class Storage {

	/**
	 * Set per-request de product_ids por usuario (`user_id => [product_id => true]`).
	 * `has()` se llama una vez por card del shop loop (24/página + cada refresh
	 * AJAX del buscador propio); sin cache son 24 SELECT por request para usuarios logueados.
	 * Las wishlists son chicas: 1 query carga todo. add()/remove() lo mantienen
	 * coherente dentro del mismo request (AJAX toggle).
	 *
	 * @var array<int, array<int, true>>
	 */
	private static array $has_cache = array();

	/**
	 * Agrega un producto. Idempotente: si ya existe, no-op.
	 *
	 * @return bool True si se agregó (o ya estaba), false si user_id=0, producto
	 *              inválido o la escritura falló.
	 */
	public static function add( int $product_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}
		if ( 'product' !== get_post_type( $product_id ) ) {
			return false;
		}

		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Install::table_name() . ' (user_id, product_id, added_at) VALUES (%d, %d, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla propio (Install::table_name(), sin entrada de usuario); los valores sí viajan por marcadores de $wpdb->prepare().
				$user_id,
				$product_id,
				current_time( 'mysql' )
			)
		);

		// Sólo `false` es fallo real de escritura (tabla ausente, DB read-only,
		// deadlock). 0 filas afectadas es el duplicado legítimo del INSERT IGNORE
		// (doble click sobre el corazón) y sigue siendo éxito: chequear `> 0`
		// convertiría el caso idempotente en un error espurio para el usuario.
		if ( false === $result ) {
			return false;
		}

		// Después del guard: marcar la cache con una fila que no llegó a existir
		// envenenaría has() por el resto del request (el corazón se vería guardado).
		if ( isset( self::$has_cache[ $user_id ] ) ) {
			self::$has_cache[ $user_id ][ $product_id ] = true;
		}

		return true;
	}

	/**
	 * Quita un producto. Idempotente: si no estaba, sigue siendo éxito.
	 *
	 * @return bool False sólo ante error de DB o argumentos inválidos. 0 filas
	 *              borradas (otra pestaña ya lo quitó) NO es fallo, misma
	 *              semántica que el INSERT IGNORE de add().
	 */
	public static function remove( int $product_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}

		global $wpdb;
		$deleted = $wpdb->delete(
			Install::table_name(),
			array(
				'user_id'    => $user_id,
				'product_id' => $product_id,
			),
			array( '%d', '%d' )
		);

		unset( self::$has_cache[ $user_id ][ $product_id ] );

		return false !== $deleted;
	}

	public static function has( int $product_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 || $product_id <= 0 ) {
			return false;
		}

		if ( ! isset( self::$has_cache[ $user_id ] ) ) {
			self::$has_cache[ $user_id ] = array_fill_keys( self::product_ids( $user_id ), true );
		}

		return isset( self::$has_cache[ $user_id ][ $product_id ] );
	}

	public static function count( ?int $user_id = null ): int {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Install::table_name() . ' WHERE user_id=%d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla propio (Install::table_name(), sin entrada de usuario); los valores sí viajan por marcadores de $wpdb->prepare().
				$user_id
			)
		);
	}

	/**
	 * IDs de productos del usuario, ordenados por más reciente primero.
	 *
	 * @return int[]
	 */
	public static function product_ids( ?int $user_id = null, int $limit = 0, int $offset = 0 ): array {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$sql = $wpdb->prepare(
			'SELECT product_id FROM ' . Install::table_name() . ' WHERE user_id=%d ORDER BY added_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla propio (Install::table_name(), sin entrada de usuario); los valores sí viajan por marcadores de $wpdb->prepare().
			$user_id
		);
		if ( $limit > 0 ) {
			if ( $offset > 0 ) {
				$sql .= $wpdb->prepare( ' LIMIT %d, %d', $offset, $limit );
			} else {
				$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
			}
		}

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql ya viene armado por $wpdb->prepare() arriba (más el LIMIT, también preparado).
	}

	/**
	 * Filas del usuario (producto + fecha de alta), más reciente primero.
	 *
	 * Sólo la usa el exporter de privacidad: el listado del endpoint no necesita
	 * `added_at` y se queda con product_ids() (una columna, menos payload).
	 *
	 * @return array<int, array{product_id: int, added_at: string}>
	 */
	public static function entries( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT product_id, added_at FROM ' . Install::table_name() . ' WHERE user_id=%d ORDER BY added_at DESC', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Nombre de tabla propio (Install::table_name(), sin entrada de usuario); los valores sí viajan por marcadores de $wpdb->prepare().
				$user_id
			),
			ARRAY_A
		);

		$entries = array();
		foreach ( (array) $rows as $row ) {
			$entries[] = array(
				'product_id' => (int) $row['product_id'],
				'added_at'   => (string) $row['added_at'],
			);
		}

		return $entries;
	}

	/**
	 * Borra TODOS los favoritos de un usuario.
	 *
	 * Lo usan el eraser de privacidad y el hook `deleted_user`.
	 *
	 * @return int Filas borradas (0 si no había nada o si la escritura falló).
	 */
	public static function erase_user( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$deleted = $wpdb->delete( Install::table_name(), array( 'user_id' => $user_id ), array( '%d' ) );

		unset( self::$has_cache[ $user_id ] );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Cleanup cuando se elimina un producto: borrar entradas huérfanas.
	 *
	 * Hookeado a `before_delete_post` desde Wishlist::register().
	 */
	public static function on_product_deleted( int $product_id ): void {
		if ( 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		global $wpdb;
		$wpdb->delete( Install::table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/**
	 * Cleanup cuando se elimina una cuenta: la wishlist muere con su titular.
	 *
	 * Hookeado a `deleted_user` (post-borrado) desde Wishlist::register().
	 * wp_delete_user() no toca tablas custom: sin esto quedaban filas huérfanas
	 * con user_id inexistente que ningún proceso limpiaba jamás.
	 */
	public static function on_user_deleted( int $user_id ): void {
		self::erase_user( $user_id );
	}
}
