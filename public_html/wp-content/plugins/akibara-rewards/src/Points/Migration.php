<?php
/**
 * Migration — importa el estado de Simple Points and Rewards (SPAR).
 *
 * Semántica de `wp_spar_points_logs`:
 *   - `action`   = la NOTA humana («Puntos obtenidos por pedido: #27720»).
 *   - `type`     = add / remove.
 *   - `action_id`= slug del ORIGEN ('order', 'redeem_refund'…), NUNCA un ID.
 *   - El número de pedido vive DENTRO de la nota (#NNNNN); las filas del bono
 *     («Bono de bienvenida…») no llevan número y se resuelven por la usermeta
 *     `_akibara_welcome_bonus_paid` del mu-plugin.
 *
 * Metas de pedido de SPAR espejadas: `points_earned` MENOS `points_deducted`
 * (SPAR conserva earned como histórico tras deducir: un pedido ya revertido
 * pre-cutover debe importarse con 0 pendiente, o sería deducible otra vez),
 * `points_first_order_bonus`, `_spar_points_redeemed`(+`_value`,`_refunded`).
 *
 * Idempotencia: se niega a correr si el ledger tiene CUALQUIER fila (no solo
 * de migración); `--force` borra las filas de migración previas antes de
 * re-insertar (nunca duplica). Siempre hay `--dry-run`. Cada insert se
 * verifica: un fallo aborta el sellado y se reporta.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Points;

use Akibara\Rewards\Support\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.0
 */
final class Migration {

	/**
	 * Registra el comando WP-CLI.
	 *
	 * OJO: esto se llama SIEMPRE (aunque el plugin duerma por convivencia con
	 * SPAR): la migración corre PRECISAMENTE con SPAR aún activo — lee su
	 * mundo y escribe solo claves akb_. El runbook del cutover es: migrar,
	 * desactivar SPAR, y el plugin despierta con los saldos ya puestos.
	 *
	 * @return void
	 */
	public static function register_cli(): void {
		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'akb-rewards migrate',
			array( self::class, 'run_cli' ),
			array(
				'shortdesc' => 'Migra saldos, ledger y metas de pedido desde Simple Points and Rewards.',
			)
		);

		\WP_CLI::add_command(
			'akb-rewards cleanup',
			array( self::class, 'run_cleanup_cli' ),
			array(
				'shortdesc' => 'Retira tablas y datos residuales de SPAR/CusRev tras el cutover (exige --confirm).',
			)
		);
	}

	/**
	 * Limpieza post-migración: remoción segura de tablas y datos residuales de plugins anteriores.
	 * Se niega salvo que: (a) ambos plugins estén inactivos, (b) la migración ya resida en el ledger, (c) venga --confirm.
	 * Con --dry-run lista lo que borraría.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Muestra lo que borraría sin tocar nada.
	 *
	 * [--confirm]
	 * : Ejecuta el borrado de verdad.
	 *
	 * @param array<int,string>    $args       Posicionales (no usados).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function run_cleanup_cli( array $args, array $assoc_args ): void {
		unset( $args );
		global $wpdb;

		$dry = isset( $assoc_args['dry-run'] ) || ! isset( $assoc_args['confirm'] );

		if ( function_exists( 'is_plugin_active' ) === false ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array( 'simple-points-and-rewards/simple-points-and-rewards.php', 'customer-reviews-woocommerce/ivole.php' ) as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				\WP_CLI::error( sprintf( 'Abortado: %s sigue ACTIVO. La limpieza es solo post-cutover.', $plugin ) );
				return;
			}
		}

		$migrated = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- el único interpolado es el nombre de nuestra tabla.
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::ledger_table() . ' WHERE action = %s', 'migration' )
		);
		if ( 0 === $migrated ) {
			\WP_CLI::error( 'Abortado: el ledger no tiene filas de migración — migrar ANTES de limpiar.' );
			return;
		}

		$tables = array( 'spar_points_logs', 'cr_local_forms', 'cr_reminders_log' );
		foreach ( $tables as $t ) {
			$full   = $wpdb->prefix . $t;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
			$rows   = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			\WP_CLI::log( sprintf( '%s %s (%d filas)', $dry ? '[dry] DROP' : 'DROP', $full, $rows ) );
			if ( ! $dry && $exists ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$full}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		// Options y usermeta residuales de los terceros. Las metas ivole_* de
		// COMENTARIOS se conservan a propósito: son datos de reseñas vivas.
		$sweeps = array(
			array( $wpdb->options, 'option_name', array( 'spar\_%', 'ivole\_%', 'cr\_%' ) ),
			array( $wpdb->usermeta, 'meta_key', array( '\_spar\_%', 'spar\_%' ) ),
		);
		foreach ( $sweeps as list( $table, $col, $patterns ) ) {
			foreach ( $patterns as $pattern ) {
				$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$col} LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 0 === $count ) {
					continue;
				}
				\WP_CLI::log( sprintf( '%s %s.%s LIKE %s (%d filas)', $dry ? '[dry] DELETE' : 'DELETE', $table, $col, $pattern, $count ) );
				if ( ! $dry ) {
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$col} LIKE %s", $pattern ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		}

		\WP_CLI::success( $dry ? 'Dry-run: nada se borró. Ejecutar con --confirm.' : 'Limpieza completa: cero tablas de terceros.' );
	}

	/**
	 * Entrada WP-CLI.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Muestra lo que haría sin escribir nada.
	 *
	 * [--force]
	 * : Re-corre la migración: borra las filas de migración previas del
	 *   ledger y las re-inserta desde el log de SPAR.
	 *
	 * @param array<int,string>    $args       Posicionales (no usados).
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public static function run_cli( array $args, array $assoc_args ): void {
		unset( $args );

		$dry   = isset( $assoc_args['dry-run'] );
		$force = isset( $assoc_args['force'] );

		$report = self::migrate( $dry, $force );

		foreach ( $report['lines'] as $line ) {
			\WP_CLI::log( $line );
		}

		if ( '' !== $report['error'] ) {
			\WP_CLI::error( $report['error'] );
			return;
		}

		\WP_CLI::success( $dry ? 'Dry-run completo; nada se escribió.' : 'Migración completa.' );
	}

	/**
	 * Ejecuta la migración. Separada del CLI para poder probarla.
	 *
	 * @param bool $dry   True = solo reportar.
	 * @param bool $force True = re-correr (limpia la migración previa).
	 * @return array{lines: array<int,string>, error: string}
	 */
	public static function migrate( bool $dry, bool $force = false ): array {
		global $wpdb;

		$lines  = array();
		$ledger = Schema::ledger_table();

		$spar_logs = $wpdb->prefix . 'spar_points_logs';
		$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $spar_logs ) );
		if ( $exists !== $spar_logs ) {
			return array(
				'lines' => $lines,
				'error' => sprintf( 'No existe %s: nada que migrar.', $spar_logs ),
			);
		}

		// El guard mira TODO el ledger: si el plugin nuevo ya movió puntos,
		// pisar saldos con los de SPAR huerfanea esos movimientos.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia.
		$total_rows     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger}" );
		$migration_rows = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$ledger} WHERE action = %s", 'migration' )
		);

		if ( $total_rows > 0 && ! $force ) {
			return array(
				'lines' => $lines,
				'error' => sprintf(
					'El ledger ya tiene %d filas (%d de migración); usa --force solo si entiendes que los saldos se re-pisarán con los de SPAR.',
					$total_rows,
					$migration_rows
				),
			);
		}

		// 1. Bono: mapa usuario → pedido pagador (las filas del bono no
		// llevan número de pedido en la nota).
		$welcome = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_akibara_welcome_bonus_paid'",
			ARRAY_A
		);
		$welcome_by_user = array();
		foreach ( (array) $welcome as $row ) {
			$welcome_by_user[ (int) $row['user_id'] ] = (int) $row['meta_value'];
		}

		// 2. Ledger: reconstruir los movimientos en orden, con saldo corrido.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla interpolado arriba.
			"SELECT id, user_id, action, type, action_id, points, date FROM {$spar_logs} ORDER BY date ASC, id ASC",
			ARRAY_A
		);

		$running   = array();
		$to_insert = array();
		$order_ids = array();

		foreach ( (array) $rows as $row ) {
			$user_id = (int) $row['user_id'];
			$note    = (string) $row['action'];
			$delta   = 'remove' === (string) $row['type'] ? -abs( (int) $row['points'] ) : abs( (int) $row['points'] );

			$running[ $user_id ] = max( 0, ( $running[ $user_id ] ?? 0 ) + $delta );

			// El ID de pedido vive dentro de la NOTA; el bono se resuelve por
			// la usermeta del mu-plugin.
			$order_ref = 0;
			if ( preg_match( '/#(\d+)/', $note, $m ) ) {
				$order_ref = (int) $m[1];
			} elseif ( false !== stripos( $note, 'bono de bienvenida' ) ) {
				$order_ref = $welcome_by_user[ $user_id ] ?? 0;
			}

			$to_insert[] = array(
				'user_id'       => $user_id,
				'delta'         => $delta,
				'balance_after' => $running[ $user_id ],
				'action'        => 'migration',
				'ref_type'      => $order_ref > 0 ? 'order' : 'spar',
				'ref_id'        => $order_ref,
				'note'          => substr( sprintf( 'SPAR log %d [%s]: %s', (int) $row['id'], (string) $row['action_id'], $note ), 0, 255 ),
				'created_at'    => (string) $row['date'],
			);

			if ( $order_ref > 0 ) {
				$order_ids[ $order_ref ] = true;
			}
		}

		$lines[] = sprintf( 'Ledger: %d movimientos a importar.', count( $to_insert ) );

		// 3. Saldos y totales históricos por usuario.
		$balances = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_spar_points'",
			ARRAY_A
		);
		$totals   = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '_spar_total_earned_points'",
			ARRAY_A
		);

		$lines[] = sprintf(
			'Usuarios: %d saldos, %d totales históricos, %d marcas de bono.',
			count( (array) $balances ),
			count( (array) $totals ),
			count( $welcome_by_user )
		);

		// Contraste saldo declarado vs reconstruido, en AMBAS direcciones: la
		// divergencia es historia perdida y merece decisión humana.
		$declared_users = array();
		foreach ( (array) $balances as $row ) {
			$user_id                    = (int) $row['user_id'];
			$declared_users[ $user_id ] = true;
			$declared                   = (int) $row['meta_value'];
			$rebuilt                    = $running[ $user_id ] ?? 0;
			if ( $declared !== $rebuilt ) {
				$lines[] = sprintf(
					'AVISO user %d: saldo SPAR %d != reconstruido del log %d. Se conserva el SPAR (es lo que el cliente ve).',
					$user_id,
					$declared,
					$rebuilt
				);
			}
		}
		foreach ( $running as $user_id => $rebuilt ) {
			if ( $rebuilt > 0 && ! isset( $declared_users[ $user_id ] ) ) {
				$lines[] = sprintf(
					'AVISO user %d: tiene historia en el log (saldo reconstruido %d) pero SPAR no declara saldo; se migrará el reconstruido.',
					$user_id,
					$rebuilt
				);
			}
		}

		$lines[] = sprintf( 'Pedidos con metas a espejar: %d.', count( $order_ids ) );

		if ( $dry ) {
			return array(
				'lines' => $lines,
				'error' => '',
			);
		}

		if ( $force && $migration_rows > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tabla propia.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$ledger} WHERE action = %s", 'migration' ) );
			$lines[] = sprintf( 'Filas de migración previas borradas: %d.', $migration_rows );
		}

		$failed = 0;
		foreach ( $to_insert as $mov ) {
			$ok = $wpdb->insert(
				$ledger,
				$mov,
				array( '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false === $ok ) {
				++$failed;
			}
		}

		if ( $failed > 0 ) {
			return array(
				'lines' => $lines,
				'error' => sprintf(
					'%d de %d inserts del ledger FALLARON (%s); saldos NO tocados. Corregir y re-correr con --force.',
					$failed,
					count( $to_insert ),
					(string) $wpdb->last_error
				),
			);
		}

		// Saldos: primero los declarados por SPAR; después, los usuarios que
		// solo existen en el log.
		foreach ( (array) $balances as $row ) {
			update_user_meta( (int) $row['user_id'], Ledger::META_BALANCE, max( 0, (int) $row['meta_value'] ) );
		}
		foreach ( $running as $user_id => $rebuilt ) {
			if ( $rebuilt > 0 && ! isset( $declared_users[ $user_id ] ) ) {
				update_user_meta( (int) $user_id, Ledger::META_BALANCE, $rebuilt );
			}
		}
		foreach ( (array) $totals as $row ) {
			update_user_meta( (int) $row['user_id'], Ledger::META_TOTAL_EARNED, max( 0, (int) $row['meta_value'] ) );
		}
		foreach ( $welcome_by_user as $user_id => $paying_order ) {
			update_user_meta( $user_id, WelcomeBonus::META_WELCOME_PAID, $paying_order );
		}

		// Época del programa: los movimientos migrados conservan su fecha
		// SPAR (historia honesta) pero para el VENCIMIENTO envejecen desde
		// aquí — sin este sello, la primera corrida nocturna vencería en
		// masa todo punto con más de N meses y el cliente reclamaría.
		update_option( 'akb_rewards_migration_epoch', current_time( 'mysql' ), false );

		foreach ( array_keys( $order_ids ) as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				$lines[] = sprintf( 'AVISO: el pedido #%d del log ya no existe; metas no espejadas.', (int) $order_id );
				continue;
			}

			$earned   = (int) $order->get_meta( 'points_earned' );
			$deducted = (int) $order->get_meta( 'points_deducted' );
			$bonus    = (int) $order->get_meta( 'points_first_order_bonus' );

			// SPAR conserva earned como histórico tras deducir: lo pendiente
			// de reversa es earned − deducted, nunca el bruto.
			$outstanding = max( 0, $earned - $deducted );
			$order->update_meta_data( Accrual::META_EARNED, $outstanding );
			if ( $bonus > 0 && $outstanding > 0 ) {
				$order->update_meta_data( Accrual::META_WELCOME_BONUS, $bonus );
			}

			// Canje pre-cutover: sin espejarlo, un reembolso futuro
			// recalcularía sobre base inflada.
			$redeemed = (int) $order->get_meta( '_spar_points_redeemed' );
			if ( $redeemed > 0 ) {
				$order->update_meta_data( Redemption::META_REDEEMED_POINTS, $redeemed );
				$redeemed_value = (float) $order->get_meta( '_spar_points_redeemed_value' );
				$order->update_meta_data( Redemption::META_REDEEMED_VALUE, $redeemed_value > 0 ? $redeemed_value : (float) $redeemed );
				$restored = (int) $order->get_meta( '_spar_points_redeemed_refunded' );
				if ( $restored > 0 ) {
					$order->update_meta_data( Redemption::META_RESTORED_POINTS, $restored );
				}
			}

			// El correo del plugin viejo ya salió: sellar para no re-avisar.
			$order->update_meta_data( Emails::META_EMAIL_SENT, time() );
			$order->save_meta_data();
		}

		return array(
			'lines' => $lines,
			'error' => '',
		);
	}
}
