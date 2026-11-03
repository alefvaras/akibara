<?php
/**
 * Ledger — puerta ÚNICA de movimientos de puntos.
 *
 * Todo crédito y débito del programa pasa por acá: candado por usuario
 * (GET_LOCK de MySQL, reentrante en MySQL 5.7+/MariaDB 10.0.2+ — el candado
 * de pedido de Accrual puede anidar este sin abrazo mortal), re-lectura del
 * saldo BAJO candado (la caché de usermeta puede estar tibia entre dos
 * requests concurrentes del mismo cliente), fila de auditoría en
 * {prefix}akb_rewards_ledger y saldo/total-histórico en usermeta.
 *
 * Idempotencia en DOS capas: los flujos re-leen su meta bajo candado, y los
 * movimientos de un solo disparo llevan `event_key` contra el índice UNIQUE
 * de la tabla — si la fila ya existe, el movimiento se declara ya-aplicado y
 * NO se toca el saldo. Es la red final contra la doble acreditación cuando
 * una caché mintió más arriba.
 *
 * Contrato de retornos (dinero: los fallos se distinguen, no se confunden):
 *   - null  = infraestructura (candado no disponible, INSERT roto). El caller
 *     puede reintentar; JAMÁS debe leerse como «saldo insuficiente».
 *   - false = rechazo de negocio (argumentos inválidos; en débito estricto,
 *     saldo insuficiente).
 *   - int   = éxito (saldo resultante en crédito; puntos debitados en débito,
 *     0 incluido para el clamp o el duplicado).
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Log;
use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Ledger {

	public const META_BALANCE      = '_akb_rewards_points';
	public const META_TOTAL_EARNED = '_akb_rewards_total_earned';

	private const LOCK_TIMEOUT = 3;

	/**
	 * Saldo actual del usuario (lectura normal, con caché).
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function balance( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_BALANCE, true ) );
	}

	/**
	 * Total histórico ganado (nunca baja con los débitos).
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function total_earned( int $user_id ): int {
		return max( 0, (int) get_user_meta( $user_id, self::META_TOTAL_EARNED, true ) );
	}

	/**
	 * Acredita puntos.
	 *
	 * @param int         $user_id          Usuario.
	 * @param int         $points           Puntos positivos a acreditar.
	 * @param string      $action           Acción de auditoría (order, welcome_bonus, redeem_refund, admin, migration…).
	 * @param string      $ref_type         Tipo de referencia (order, review…).
	 * @param int         $ref_id           ID de la referencia.
	 * @param string      $note             Nota corta de auditoría.
	 * @param bool        $counts_as_earned Si suma al total histórico (default sí).
	 * @param string|null $event_key        Clave única de idempotencia (movimientos de un solo disparo).
	 * @return int|false|null Saldo resultante; false argumentos; null infraestructura.
	 */
	public static function credit( int $user_id, int $points, string $action, string $ref_type = '', int $ref_id = 0, string $note = '', bool $counts_as_earned = true, ?string $event_key = null ) {
		/**
		 * Punto de ajuste o validación extensible antes de asentar un crédito.
		 * Permite a filtros de negocio ajustar o vetar el monto propuesto. Retornar <= 0 cancela el crédito.
		 *
		 * @param int    $points   Puntos propuestos.
		 * @param int    $user_id  Usuario.
		 * @param string $action   Acción del asiento.
		 * @param string $ref_type Tipo de referencia.
		 * @param int    $ref_id   ID de la referencia.
		 */
		$points = (int) apply_filters( 'akb_rewards_pre_credit_points', $points, $user_id, $action, $ref_type, $ref_id );

		if ( $user_id <= 0 || $points <= 0 ) {
			return false;
		}

		return self::with_user_lock(
			$user_id,
			static function () use ( $user_id, $points, $action, $ref_type, $ref_id, $note, $counts_as_earned, $event_key ) {
				if ( null !== $event_key && self::event_exists( $event_key ) ) {
					// Ya aplicado: éxito sin tocar nada.
					return self::balance_locked( $user_id );
				}

				$new_balance = self::apply_locked( $user_id, $points, $action, $ref_type, $ref_id, $note, $event_key );

				if ( is_int( $new_balance ) && $counts_as_earned ) {
					$total = self::total_earned_locked( $user_id ) + $points;
					update_user_meta( $user_id, self::META_TOTAL_EARNED, $total );
				}

				return $new_balance;
			}
		);
	}

	/**
	 * Debita puntos.
	 *
	 * Dos semánticas, según quién cobra:
	 *   - Reversa de pedido ($require_sufficient = false): debita hasta donde
	 *     alcanza el saldo (clamp en 0) y registra el delta REAL — el cliente
	 *     pudo haber gastado ya esos puntos y un saldo negativo no es cobrable.
	 *   - Captura de canje ($require_sufficient = true): o alcanza el saldo
	 *     completo o NO se debita nada — jamás financiar un descuento con
	 *     puntos que ya no existen (compra concurrente en otra pestaña).
	 *
	 * @param int         $user_id            Usuario.
	 * @param int         $points             Puntos positivos a debitar.
	 * @param string      $action             Acción de auditoría.
	 * @param string      $ref_type           Tipo de referencia.
	 * @param int         $ref_id             ID de la referencia.
	 * @param string      $note               Nota corta.
	 * @param bool        $require_sufficient Exigir saldo completo (captura de canje).
	 * @param string|null $event_key          Clave única de idempotencia.
	 * @return int|false|null Puntos debitados (0 con clamp o duplicado); false negocio; null infraestructura.
	 */
	public static function debit( int $user_id, int $points, string $action, string $ref_type = '', int $ref_id = 0, string $note = '', bool $require_sufficient = false, ?string $event_key = null ) {
		if ( $user_id <= 0 || $points <= 0 ) {
			return false;
		}

		return self::with_user_lock(
			$user_id,
			static function () use ( $user_id, $points, $action, $ref_type, $ref_id, $note, $require_sufficient, $event_key ) {
				if ( null !== $event_key && self::event_exists( $event_key ) ) {
					return 0; // Ya aplicado: éxito sin tocar nada.
				}

				$balance = self::balance_locked( $user_id );

				if ( $require_sufficient && $balance < $points ) {
					return false;
				}

				$to_debit = min( $points, $balance );
				if ( $to_debit <= 0 ) {
					return 0;
				}

				$new_balance = self::apply_locked( $user_id, -$to_debit, $action, $ref_type, $ref_id, $note, $event_key );

				return is_int( $new_balance ) ? $to_debit : null;
			}
		);
	}

	/**
	 * Ejecuta $fn con el candado del usuario tomado. Devuelve null si el
	 * candado no se consigue en LOCK_TIMEOUT segundos (infraestructura, no un
	 * false de negocio: la captura del canje distingue los dos).
	 *
	 * Público a propósito: los flujos con idempotencia propia (bono de
	 * bienvenida) necesitan re-leer SU meta bajo el mismo candado antes de
	 * decidir si acreditan.
	 *
	 * @param int      $user_id Usuario.
	 * @param callable $fn      Cuerpo a ejecutar bajo candado.
	 * @return mixed Null si no hubo candado; si no, lo que devuelva $fn.
	 */
	public static function with_user_lock( int $user_id, callable $fn ) {
		global $wpdb;

		$lock = 'akb_rewards_user_' . $user_id;

		$acquired = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT )
		);

		if ( '1' !== $acquired ) {
			Log::error( sprintf( 'Candado %s no disponible; movimiento rechazado.', $lock ) );
			return null;
		}

		try {
			return $fn();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Cuenta movimientos de una acción sobre una referencia. La usa Accrual
	 * para reconciliar la meta del pedido contra la VERDAD del ledger (ventana
	 * de caída entre acreditar y sellar) y para derivar claves de evento por
	 * ocurrencia («award_100_2» tras un revival del pedido).
	 *
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción a contar.
	 * @return int
	 */
	public static function count_movements( string $ref_type, int $ref_id, string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE ref_type = %s AND ref_id = %d AND action = %s',
				$ref_type,
				$ref_id,
				$action
			)
		);
	}

	/**
	 * Último CRÉDITO de una acción sobre una referencia, para avisar al dueño
	 * antes de repetir un premio manual sobre el mismo pedido: cuándo, cuánto y
	 * con qué nota. Sólo informa; no decide contabilidad (eso lo hacen
	 * `count_movements()` y `sum_movements()` con la verdad del ledger).
	 *
	 * Mira créditos y no «el último movimiento»: si después de premiar se quitó
	 * parte, el pedido igual ya fue premiado, y el aviso tiene que seguir saliendo.
	 *
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción.
	 * @return array{delta:int,note:string,created_at:string}|null
	 */
	public static function last_credit( string $ref_type, int $ref_id, string $action ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT delta, note, created_at FROM ' . Schema::ledger_table() . ' WHERE ref_type = %s AND ref_id = %d AND action = %s AND delta > 0 ORDER BY id DESC LIMIT 1',
				$ref_type,
				$ref_id,
				$action
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'delta'      => (int) ( $row['delta'] ?? 0 ),
			'note'       => (string) ( $row['note'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * Suma de deltas de una referencia para un conjunto de acciones.
	 * Representa el saldo neto real de la referencia según el libro mayor.
	 *
	 * @param string            $ref_type Tipo de referencia.
	 * @param int               $ref_id   ID de la referencia.
	 * @param array<int,string> $actions  Acciones a sumar.
	 * @return int Neto firmado.
	 */
	public static function sum_movements( string $ref_type, int $ref_id, array $actions ): int {
		global $wpdb;

		if ( array() === $actions ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $actions ), '%s' ) );

		// Nombre de tabla propio (`Schema::ledger_table()`) y `{$placeholders}`
		// generado aquí arriba, uno por acción. Todos los datos viajan por sus
		// placeholders, en el array que `prepare()` recibe entero — una forma que
		// Core acepta y que el sniff cuenta mal.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . " WHERE ref_type = %s AND ref_id = %d AND action IN ({$placeholders})",
				array_merge( array( $ref_type, $ref_id ), $actions )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * Cuenta movimientos de una acción para un usuario y referencia específicos.
	 * Aísla registros por usuario cuando múltiples clientes comparten un mismo ref_id.
	 *
	 * @param int    $user_id  Usuario.
	 * @param string $ref_type Tipo de referencia.
	 * @param int    $ref_id   ID de la referencia.
	 * @param string $action   Acción a contar.
	 * @return int
	 */
	public static function count_movements_for_user( int $user_id, string $ref_type, int $ref_id, string $action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND ref_type = %s AND ref_id = %d AND action = %s',
				$user_id,
				$ref_type,
				$ref_id,
				$action
			)
		);
	}

	/**
	 * Créditos vigentes de una acción para un usuario, agrupados por referencia:
	 * computa únicamente referencias cuyo saldo neto (créditos menos reversas) sea positivo.
	 *
	 * @param int    $user_id         Usuario.
	 * @param string $ref_type        Tipo de referencia agrupada.
	 * @param string $credit_action   Acción del crédito.
	 * @param string $reversal_action Acción de su reversa.
	 * @return int
	 */
	public static function count_live_credits( int $user_id, string $ref_type, string $credit_action, string $reversal_action ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COUNT(*) FROM ( SELECT ref_id FROM ' . Schema::ledger_table() . ' WHERE user_id = %d AND ref_type = %s AND action IN (%s,%s) GROUP BY ref_id HAVING SUM(delta) > 0 ) AS vigentes',
				$user_id,
				$ref_type,
				$credit_action,
				$reversal_action
			)
		);
	}

	/**
	 * Premios de referido vigentes de un padrino: invitados cuyo balance neto entre
	 * premio y reversas se mantiene estrictamente positivo.
	 *
	 * @param int $referrer Padrino.
	 * @return int
	 */
	public static function count_live_referrals( int $referrer ): int {
		return self::count_live_credits( $referrer, 'user', 'referral', 'referral_reversal' );
	}

	/**
	 * Lectura PÚBLICA de cortesía: ¿esta clave ya se asentó? Solo para UX
	 * (avisar un doble envío al admin); el guardián real sigue siendo el
	 * UNIQUE de la tabla más la re-verificación bajo candado.
	 *
	 * @param string $event_key Clave de idempotencia.
	 * @return bool
	 */
	public static function event_applied( string $event_key ): bool {
		return self::event_exists( $event_key );
	}

	/**
	 * ¿Existe ya un movimiento con esta clave? SOLO bajo candado.
	 *
	 * @param string $event_key Clave de idempotencia.
	 * @return bool
	 */
	private static function event_exists( string $event_key ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT id FROM ' . Schema::ledger_table() . ' WHERE event_key = %s LIMIT 1',
				$event_key
			)
		);

		return null !== $found && '' !== (string) $found;
	}

	/**
	 * Inserta la fila del ledger y persiste el nuevo saldo. SOLO bajo candado.
	 *
	 * @param int         $user_id   Usuario.
	 * @param int         $delta     Delta firmado.
	 * @param string      $action    Acción.
	 * @param string      $ref_type  Tipo de referencia.
	 * @param int         $ref_id    ID de referencia.
	 * @param string      $note      Nota.
	 * @param string|null $event_key Clave única (null = movimiento repetible).
	 * @return int|null Nuevo saldo o null si el INSERT falló.
	 */
	private static function apply_locked( int $user_id, int $delta, string $action, string $ref_type, int $ref_id, string $note, ?string $event_key = null ) {
		global $wpdb;

		$new_balance = max( 0, self::balance_locked( $user_id ) + $delta );

		$row = array(
			'user_id'       => $user_id,
			'delta'         => $delta,
			'balance_after' => $new_balance,
			'action'        => substr( $action, 0, 40 ),
			'ref_type'      => substr( $ref_type, 0, 20 ),
			'ref_id'        => $ref_id,
			'note'          => substr( $note, 0, 255 ),
			'created_at'    => current_time( 'mysql' ),
		);
		$fmt = array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' );

		if ( null !== $event_key ) {
			$row['event_key'] = substr( $event_key, 0, 191 );
			$fmt[]            = '%s';
		}

		$inserted = $wpdb->insert( Schema::ledger_table(), $row, $fmt );

		if ( false === $inserted ) {
			Log::error( sprintf( 'INSERT del ledger falló para user %d: %s', $user_id, (string) $wpdb->last_error ) );
			return null;
		}

		update_user_meta( $user_id, self::META_BALANCE, $new_balance );

		return $new_balance;
	}

	/**
	 * Saldo derivado del ledger: suma de todos los deltas del usuario en el libro mayor.
	 * Constituye la fuente de verdad contable contra la que se audita el balance en metadatos.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	public static function balance_from_ledger( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- nombre de tabla propio (Schema::ledger_table()); los datos van por sus placeholders.
				'SELECT COALESCE(SUM(delta),0) FROM ' . Schema::ledger_table() . ' WHERE user_id = %d',
				$user_id
			)
		);
	}

	/**
	 * Saldo leído DIRECTO de la base, saltándose la caché de usermeta. Solo
	 * tiene sentido bajo candado: es la re-lectura que hace segura la
	 * concurrencia de dos pedidos del mismo cliente.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	private static function balance_locked( int $user_id ): int {
		return self::raw_user_meta_int( $user_id, self::META_BALANCE );
	}

	/**
	 * Total histórico leído directo de la base, bajo candado.
	 *
	 * @param int $user_id Usuario.
	 * @return int
	 */
	private static function total_earned_locked( int $user_id ): int {
		return self::raw_user_meta_int( $user_id, self::META_TOTAL_EARNED );
	}

	/**
	 * Lee un usermeta entero directo por SQL y refresca la caché para que las
	 * lecturas normales posteriores no vean un valor viejo.
	 *
	 * Público a propósito: los flujos con idempotencia propia (bono de
	 * bienvenida) re-leen su marca bajo el mismo candado.
	 *
	 * @param int    $user_id  Usuario.
	 * @param string $meta_key Meta a leer.
	 * @return int
	 */
	public static function raw_user_meta_int( int $user_id, string $meta_key ): int {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
				$user_id,
				$meta_key
			)
		);

		wp_cache_delete( $user_id, 'user_meta' );

		return max( 0, (int) $value );
	}
}
