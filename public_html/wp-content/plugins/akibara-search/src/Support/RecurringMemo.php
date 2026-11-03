<?php
/**
 * RecurringMemo — duración y TTL del memo «la acción recurrente ya está programada».
 *
 * El vigilante de reconstrucción ({@see \Akibara\Search\RebuildJob::ensure_watchdog()}) y
 * la reconciliación periódica ({@see \Akibara\Search\Reconciler::ensure_scheduled()}) utilizan
 * un transient en memoria para evitar consultar Action Scheduler en cada ciclo de WordPress.
 *
 * Política de retención:
 * Un memo nunca dura más que el intervalo propio de la acción protegida, con un límite
 * superior estricto de una hora (`MAX_TTL = 3600`). Esto garantiza que la ausencia accidental
 * de una acción programada sea detectada oportunamente sin sobrecargar la base de datos.
 *
 * @package Akibara\Search\Support
 */

declare( strict_types = 1 );

namespace Akibara\Search\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Política única de caducidad de los memos de acciones recurrentes.
 */
final class RecurringMemo {

	/** Techo: ninguna acción recurrente del buscador pasa más de una hora sin que se compruebe que existe. */
	public const MAX_TTL = 3600;

	/**
	 * Suelo. `set_transient( …, 0 )` significa «no caduca nunca»: un intervalo
	 * nulo o negativo convertiría el arreglo en el defecto, pero para siempre.
	 */
	public const MIN_TTL = 60;

	/**
	 * Duración del memo para una acción que se repite cada `$interval` segundos.
	 *
	 * @param int $interval Intervalo de la acción recurrente, en segundos.
	 * @return int Segundos de vida del memo.
	 */
	public static function ttl( int $interval ): int {
		return max( self::MIN_TTL, min( $interval, self::MAX_TTL ) );
	}
}
