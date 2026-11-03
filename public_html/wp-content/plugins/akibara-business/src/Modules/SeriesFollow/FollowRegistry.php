<?php
/**
 * FollowRegistry — opt-out y tope semanal, sobre usermeta.
 *
 * Por qué usermeta y no una tabla: el volumen es diminuto (una fila por cliente
 * aunque se dé de baja de veinte series), la higiene RGPD sale gratis con el
 * borrado del usuario —así que este módulo no necesita `PrivacyHandler` propio—
 * y la usermeta no viaja en `alloptions`.
 *
 * El acceso siempre es «para este lote de N usuarios»: `prime()` hace UN
 * `update_meta_cache()` para todo el lote, misma doctrina de prime único que
 * usan `BackInStock` y `AccountCollection\CollectionResolver`.
 *
 * La suscripción NO vive aquí: es un hecho DERIVADO de las compras pagadas.
 * Aquí sólo vive la NEGACIÓN (el opt-out) y la marca del último envío.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesFollow;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.110.2
 */
final class FollowRegistry {

	/** Array de `term_id` de `pa_serie` que el cliente ya no quiere. */
	public const META_OPTOUT = '_akb_sf_optout';

	/** '1' si el cliente usó el List-Unsubscribe global de su cliente de correo. */
	public const META_OPTOUT_ALL = '_akb_sf_optout_all';

	/** Timestamp UTC del último digest ENVIADO CON ÉXITO. */
	public const META_LAST_SENT = '_akb_sf_last_sent';

	/**
	 * Un solo `update_meta_cache` para todo el lote: sin esto, leer el opt-out
	 * de 50 clientes son 50 consultas.
	 *
	 * @param array<int, int> $user_ids Clientes del lote.
	 * @return void
	 */
	public static function prime( array $user_ids ): void {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $ids || ! function_exists( 'update_meta_cache' ) ) {
			return;
		}

		update_meta_cache( 'user', $ids );
	}

	/**
	 * ¿El cliente pidió no recibir avisos de esta serie (o de ninguna)?
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Término de `pa_serie`.
	 * @return bool
	 */
	public static function is_opted_out( int $user_id, int $term_id ): bool {
		if ( $user_id < 1 ) {
			return true;
		}

		if ( self::opted_out_of_everything( $user_id ) ) {
			return true;
		}

		return in_array( $term_id, self::opted_out_terms( $user_id ), true );
	}

	/**
	 * @param int $user_id Cliente.
	 * @return bool
	 */
	public static function opted_out_of_everything( int $user_id ): bool {
		if ( $user_id < 1 ) {
			return true;
		}

		return '1' === (string) get_user_meta( $user_id, self::META_OPTOUT_ALL, true );
	}

	/**
	 * Términos de los que el cliente se dio de baja.
	 *
	 * @param int $user_id Cliente.
	 * @return array<int, int>
	 */
	public static function opted_out_terms( int $user_id ): array {
		if ( $user_id < 1 ) {
			return array();
		}

		$stored = get_user_meta( $user_id, self::META_OPTOUT, true );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $stored ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);
	}

	/**
	 * Baja de UNA serie. Idempotente.
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Término de `pa_serie`.
	 * @return void
	 */
	public static function opt_out( int $user_id, int $term_id ): void {
		if ( $user_id < 1 || $term_id < 1 ) {
			return;
		}

		$terms = self::opted_out_terms( $user_id );
		if ( in_array( $term_id, $terms, true ) ) {
			return;
		}

		$terms[] = $term_id;
		sort( $terms, SORT_NUMERIC );
		update_user_meta( $user_id, self::META_OPTOUT, $terms );
	}

	/**
	 * Alta de UNA serie: quita el opt-out puntual Y el global.
	 *
	 * El global tiene que caer también, porque si no el interruptor de «Mi
	 * colección» diría «sigues esta serie» mientras la cabecera global sigue
	 * callando todos los avisos del cliente.
	 *
	 * @param int $user_id Cliente.
	 * @param int $term_id Término de `pa_serie`.
	 * @return void
	 */
	public static function opt_in( int $user_id, int $term_id ): void {
		if ( $user_id < 1 || $term_id < 1 ) {
			return;
		}

		if ( self::opted_out_of_everything( $user_id ) ) {
			delete_user_meta( $user_id, self::META_OPTOUT_ALL );
		}

		$terms = array_values(
			array_filter(
				self::opted_out_terms( $user_id ),
				static fn ( int $id ): bool => $id !== $term_id
			)
		);

		update_user_meta( $user_id, self::META_OPTOUT, $terms );
	}

	/**
	 * Baja global (List-Unsubscribe de la cabecera).
	 *
	 * @param int $user_id Cliente.
	 * @return void
	 */
	public static function opt_out_all( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}

		update_user_meta( $user_id, self::META_OPTOUT_ALL, '1' );
	}

	/**
	 * Alta global: el interruptor «Avisos de mi colección» de Mi cuenta.
	 *
	 * SÓLO borra la baja global. NO toca `_akb_sf_optout`: las bajas por serie
	 * son decisiones explícitas del cliente («de esta no me avises») y
	 * encender el global no las deshace — si las deshiciera, quien apagó todo
	 * desde un correo y luego lo reenciende desde la pantalla recuperaría
	 * avisos que había rechazado uno a uno. El camino inverso (encender UNA
	 * serie levanta el global, `opt_in()`) se conserva por la razón que ahí se
	 * explica. Idempotente: sin la meta, `delete_user_meta` no hace nada.
	 *
	 * @param int $user_id Cliente.
	 * @return void
	 */
	public static function opt_in_all( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}

		delete_user_meta( $user_id, self::META_OPTOUT_ALL );
	}

	/**
	 * Marca de tiempo del último digest enviado con éxito (0 si nunca).
	 *
	 * @param int $user_id Cliente.
	 * @return int
	 */
	public static function last_sent_at( int $user_id ): int {
		if ( $user_id < 1 ) {
			return 0;
		}

		return max( 0, (int) get_user_meta( $user_id, self::META_LAST_SENT, true ) );
	}

	/**
	 * Sella el tope. SOLO se llama tras un envío exitoso: si el correo no salió,
	 * el cliente no gastó su semana.
	 *
	 * @param int      $user_id Cliente.
	 * @param int|null $now     Reloj inyectable (tests).
	 * @return void
	 */
	public static function stamp_sent( int $user_id, ?int $now = null ): void {
		if ( $user_id < 1 ) {
			return;
		}

		update_user_meta( $user_id, self::META_LAST_SENT, null === $now ? time() : $now );
	}
}
