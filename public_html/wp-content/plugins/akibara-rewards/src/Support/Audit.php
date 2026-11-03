<?php
/**
 * Audit — reconciliación saldo-cacheado vs ledger.
 *
 * El saldo vive cacheado en usermeta y el ledger es la verdad contable.
 * Esta auditoría reconcilia desviaciones eventuales garantizando consistencia.
 *
 * La reparación NO escribe asientos: el ledger ya es correcto (el INSERT
 * ocurrió); lo que quedó viejo es la caché. Reparar = re-derivar la meta de
 * la suma, bajo el candado del usuario. Asentar «la diferencia» — lo que
 * hace el set_points_balance del oficial — movería la verdad para igualar
 * la caché: la dirección equivocada.
 *
 * @package Akibara\Rewards
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Support;

use Akibara\Rewards\Points\Ledger;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.3.0
 */
final class Audit {

	/**
	 * Usuarios cuya meta de saldo no cuadra con la suma del ledger.
	 *
	 * @return array<int,array{user_id:int,meta:int,ledger:int}>
	 */
	public static function mismatches(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.user_id, COALESCE(m.meta_value, 0) + 0 AS meta, COALESCE(SUM(l.delta), 0) AS ledger'
				. ' FROM ' . Schema::ledger_table() . ' l' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia.
				. " LEFT JOIN {$wpdb->usermeta} m ON m.user_id = l.user_id AND m.meta_key = %s"
				// GREATEST(0, ...): la meta no baja de 0; ledgers con saldo negativo se reportan por separado.
				. ' GROUP BY l.user_id, m.meta_value HAVING meta <> GREATEST(0, ledger)',
				Ledger::META_BALANCE
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'meta'    => (int) $row['meta'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	/**
	 * Repara la meta de UN usuario re-derivándola del ledger, bajo candado y
	 * con la suma releída dentro (la foto de fuera puede ser vieja).
	 *
	 * @param int $user_id Usuario.
	 * @return int|null Saldo reparado, o null sin candado.
	 */
	/**
	 * Usuarios cuyo ledger suma valor negativo. Se reporta para inspección contable independiente.
	 *
	 * @return array<int, array{user_id: int, ledger: int}>
	 */
	public static function negatives(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT user_id, COALESCE(SUM(delta), 0) AS ledger FROM ' . Schema::ledger_table() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- tabla propia, sin entrada de usuario.
			. ' GROUP BY user_id HAVING ledger < 0',
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'user_id' => (int) $row['user_id'],
				'ledger'  => (int) $row['ledger'],
			);
		}

		return $out;
	}

	public static function repair( int $user_id ) {
		return Ledger::with_user_lock(
			$user_id,
			static function () use ( $user_id ): int {
				$truth = max( 0, Ledger::balance_from_ledger( $user_id ) );
				update_user_meta( $user_id, Ledger::META_BALANCE, $truth );
				return $truth;
			}
		);
	}

	/**
	 * Comando `wp akb-rewards audit [--fix]`.
	 *
	 * @return void
	 */
	public static function register_cli(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'akb-rewards audit',
			static function ( $args, $assoc ): void {
				unset( $args );
				foreach ( self::negatives() as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: el ledger suma %d (negativo). No es una desviación: el saldo visible es 0. Le falta el movimiento de arranque de la migración; se corrige con un ajuste manual, no con --fix.',
						$row['user_id'],
						$row['ledger']
					) );
				}
				$bad = self::mismatches();

				if ( array() === $bad ) {
					\WP_CLI::success( 'Ledger y saldos cuadran: 0 desviaciones.' );
					return;
				}

				foreach ( $bad as $row ) {
					\WP_CLI::log( sprintf(
						'user %d: meta=%d ledger=%d (dif %+d)',
						$row['user_id'],
						$row['meta'],
						$row['ledger'],
						$row['ledger'] - $row['meta']
					) );

					if ( isset( $assoc['fix'] ) ) {
						$fixed = self::repair( $row['user_id'] );
						\WP_CLI::log( null === $fixed
							? sprintf( '  → SIN candado para user %d; reintentar.', $row['user_id'] )
							: sprintf( '  → meta reparada a %d (derivada del ledger).', $fixed ) );
					}
				}

				if ( ! isset( $assoc['fix'] ) ) {
					\WP_CLI::warning( sprintf( '%d desviaciones. Repite con --fix para reparar las metas desde el ledger.', count( $bad ) ) );
				}
			}
		);
	}
}
