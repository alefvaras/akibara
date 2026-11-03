<?php
/**
 * QueueHealth — la cola de Action Scheduler, vigilada desde la tarjeta de salud.
 *
 * Por qué existe
 * --------------
 * «Alertas del Sistema» sólo miraba los eventos de WP-Cron (`OwnCrons::RECURRING`).
 * La cola de Action Scheduler —la vía normal de casi todo el trabajo del plugin:
 * avisos de stock, correos de preventa, resúmenes de series, la vitrina, el
 * calentado de caché, las purgas de borde— no tenía vigilancia: la vitrina llevó
 * 14 días sin reordenarse y la tarjeta decía «todo en verde»
 *.
 *
 * Qué mira (sólo lectura sobre las tablas del Action Scheduler)
 * ----------------------------------------------------------------
 *  - Acciones PROPIAS fallidas en los últimos 7 días, por hook, con la última.
 *  - Acciones PROPIAS pendientes vencidas hace más que el margen de la tarjeta
 *    (la cola no está corriendo, o una acción quedó atascada).
 *
 * «Propias» = los hooks de `OwnCrons::QUEUE`. Las fallidas de plugins que ya no
 * están instalados (36 de 50 en producción) no cuentan: no son señal nuestra.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.116.31
 */
final class QueueHealth {

	public const FAILED_WINDOW_DAYS = 7;

	/** @var callable(string): array<int, array<string, mixed>> */
	private $rows;

	private string $table;

	/**
	 * @param callable|null $rows  Ejecuta un SELECT y devuelve filas asociativas (inyectable en pruebas).
	 * @param string        $table Tabla de acciones (por defecto la del sitio).
	 */
	public function __construct( ?callable $rows = null, string $table = '' ) {
		$this->rows  = $rows ?? array( $this, 'query_rows' );
		$this->table = '' !== $table ? $table : self::default_table();
	}

	/**
	 * Los hooks propios de la cola: los de business (`OwnCrons::QUEUE`) más los
	 * que otros plugins de la casa registren por filtro (akibara-rewards agenda
	 * once, cuatro de ellos diarios; revisión independiente, M4).
	 *
	 * @return array<int, string>
	 */
	public static function own_hooks(): array {
		$hooks = array_merge( ...array_values( OwnCrons::QUEUE ) );

		/**
		 * Hooks de Action Scheduler que la tarjeta de salud debe vigilar.
		 *
		 * @since 1.116.31
		 *
		 * @param array<int, string> $hooks Los de akibara-business.
		 */
		$hooks = (array) apply_filters( 'akibara_queue_health_hooks', $hooks );

		return array_values( array_unique( array_filter( array_map( 'strval', $hooks ) ) ) );
	}

	/**
	 * Alertas listas para la tarjeta, en el mismo formato que las de WP-Cron.
	 *
	 * @param int $grace_seconds Margen antes de llamar «atrasada» a una pendiente.
	 * @return array<int, array{level: string, html: string}>
	 */
	public function alerts( int $grace_seconds ): array {
		if ( '' === $this->table ) {
			return array();
		}

		$alerts = array();

		foreach ( $this->failed_recently() as $fila ) {
			$alerts[] = array(
				'level' => 'warning',
				'html'  => sprintf(
					'⚙️ <strong>Cola con fallos</strong>: <code>%s</code> falló %s en los últimos %d días (última: %s UTC). Mirar WooCommerce → Estado → Acciones programadas, pestaña «Fallidas».',
					esc_html( $fila['hook'] ),
					esc_html( 1 === $fila['count'] ? 'una vez' : $fila['count'] . ' veces' ),
					self::FAILED_WINDOW_DAYS,
					esc_html( $fila['last'] )
				),
			);
		}

		foreach ( $this->past_due( $grace_seconds ) as $fila ) {
			$alerts[] = array(
				'level' => 'warning',
				'html'  => sprintf(
					'⏰ <strong>Cola atrasada</strong>: <code>%s</code> tiene %s pendiente%s sin ejecutar, la más antigua desde %s UTC. O la cola de tareas del sitio no está corriendo, o hay un atasco por volumen.',
					esc_html( $fila['hook'] ),
					esc_html( (string) $fila['count'] ),
					1 === $fila['count'] ? '' : 's',
					esc_html( $fila['since'] )
				),
			);
		}

		return $alerts;
	}

	/**
	 * Fallidas por su hora de FALLO (`last_attempt_gmt`), no por la prevista:
	 * una cola parada varios días deja acciones con `scheduled_date_gmt` vieja
	 * que fallan al volver, fuera de una ventana medida sobre la fecha prevista
	 * (revisión independiente, M3).
	 *
	 * @return array<int, array{hook: string, count: int, last: string}>
	 */
	public function failed_recently(): array {
		$sql = sprintf(
			"SELECT hook, COUNT(*) AS c, MAX(last_attempt_gmt) AS last_gmt FROM %s WHERE status = 'failed' AND hook IN (%s) AND last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL %d DAY GROUP BY hook ORDER BY last_gmt DESC",
			$this->table,
			$this->hooks_sql(),
			self::FAILED_WINDOW_DAYS
		);

		$out = array();
		foreach ( ( $this->rows )( $sql ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['hook'] ) ) {
				continue;
			}
			$out[] = array(
				'hook'  => (string) $row['hook'],
				'count' => (int) ( $row['c'] ?? 0 ),
				'last'  => (string) ( $row['last_gmt'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Pendientes vencidas. Una acción asíncrona (`as_enqueue_async_action`)
	 * también entra: el Action Scheduler le guarda como fecha prevista el
	 * instante en que se encoló, así que una vencida hace horas es un problema
	 * de verdad, no un falso positivo.
	 *
	 * @param int $grace_seconds Margen.
	 * @return array<int, array{hook: string, count: int, since: string}>
	 */
	public function past_due( int $grace_seconds ): array {
		$sql = sprintf(
			"SELECT hook, COUNT(*) AS c, MIN(scheduled_date_gmt) AS since_gmt FROM %s WHERE status = 'pending' AND hook IN (%s) AND scheduled_date_gmt < UTC_TIMESTAMP() - INTERVAL %d SECOND GROUP BY hook ORDER BY since_gmt ASC",
			$this->table,
			$this->hooks_sql(),
			max( 0, $grace_seconds )
		);

		$out = array();
		foreach ( ( $this->rows )( $sql ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['hook'] ) ) {
				continue;
			}
			$out[] = array(
				'hook'  => (string) $row['hook'],
				'count' => max( 1, (int) ( $row['c'] ?? 1 ) ),
				'since' => (string) ( $row['since_gmt'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Lista `'a','b'` para el IN. Los hooks son constantes del plugin, no datos
	 * de usuario; se escapan igual.
	 *
	 * @return string
	 */
	private function hooks_sql(): string {
		$escapados = array_map(
			static fn ( string $hook ): string => "'" . ( function_exists( 'esc_sql' ) ? esc_sql( $hook ) : addslashes( $hook ) ) . "'",
			self::own_hooks()
		);

		return implode( ',', $escapados );
	}

	/**
	 * @return string '' si no hay base (suite, CLI temprano).
	 */
	private static function default_table(): string {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return '';
		}

		return (string) $wpdb->prefix . 'actionscheduler_actions';
	}

	/**
	 * @param string $sql SELECT ya armado (tabla propia del Action Scheduler; sin datos de usuario).
	 * @return array<int, array<string, mixed>>
	 */
	private function query_rows( string $sql ): array {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SELECT sobre la tabla del Action Scheduler con constantes del plugin (hooks) y enteros; sin entrada de usuario.

		return is_array( $rows ) ? $rows : array();
	}
}
