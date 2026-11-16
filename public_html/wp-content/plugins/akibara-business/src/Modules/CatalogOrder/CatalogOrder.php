<?php
/**
 * CatalogOrder — mantiene menu_order como orden de vitrina intercalado.
 *
 * El modo de vitrina activo se configura en la pestaña «Vitrina» del panel
 * (`akb_catalog_order_showcase`): disponibles primero (por defecto) o intercalado.
 * La ordenación se materializa en la columna `menu_order`, permitiendo que la tienda
 * ordene nativamente ('menu_order title') sin joins adicionales en tiempo de consulta.
 * Las landings de serie no cambian: abren en orden de título ASC («Tomo 1 → N»).
 *
 * `menu_order` es administrado automáticamente por este módulo.
 *
 * Disparadores:
 *   - Publicación o despublicación de producto: recálculo debounceado mediante Action Scheduler.
 *   - Actualización del plugin (`akibara_business_upgraded`).
 *   - Corrida diaria a las 00:05 de la tienda (`DAILY_HOUR`/`DAILY_MINUTE`), asegurando que
 *     los cambios de disponibilidad se reflejen antes del ciclo nocturno de precalentamiento de caché.
 *
 * Comandos WP-CLI:
 *   - Recálculo manual: `wp akibara-catalog-order recompute`
 *   - Estado de vitrina: `wp akibara-catalog-order status`
 *
 * Ventana entre publish y recompute: el producto nuevo queda con menu_order=0
 * → ordena PRIMERO en 'menu_order' ASC hasta el recompute (side-effect
 * deliberadamente aceptable: lo más nuevo arriba).
 *
 * @package Akibara\Business\Modules\CatalogOrder
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\CatalogOrder;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookInvalidArgs
 * @psalm-suppress InvalidArgument
 * @psalm-suppress UnusedReturnValue
 * @psalm-suppress UnusedVariable
 * @psalm-suppress UnusedReturnValue
 */
final class CatalogOrder {

	private const RECOMPUTE_HOOK   = 'akibara_catalog_order_recompute';
	private const DAILY_HOOK       = 'akibara_catalog_order_daily';
	private const GROUP            = 'akb-catalog-order';
	public const DEBOUNCE_SECONDS  = 600; // 600 segundos = 10 minutos (debounce deslizante).

	/**
	 * La corrida diaria ocurre a las 00:05 de la tienda para purgar antes del
	 * precalentamiento nocturno. En el salto de horario en que esa hora no
	 * existe se desplaza a la 01:05, antes del correspondiente precalentamiento.
	 */
	private const DAILY_HOUR   = 0;
	private const DAILY_MINUTE = 5;

	/**
	 * Cuánto puede alejarse la acción agendada de su hora antes de re-anclarla.
	 *
	 * Quince minutos: suficiente para no reprogramar por el ruido de una corrida
	 * que tardó un poco más, y bastante menos que la hora que mueve el cambio de
	 * horario de Chile.
	 */
	private const DAILY_DRIFT_TOLERANCE = 15 * MINUTE_IN_SECONDS;

	/** Última corrida: `array{time:int, rows:int, applied:int}`. */
	private const OPTION_LAST_RUN = 'akb_catalog_order_last_run';

	/** Pestaña propia dentro del panel Akibara (retirada a código / constantes). */
	public const PANEL_TAB_ID = 'catalog_order';

	/** Qué vitrina calcula el recompute. */
	public const OPTION_SHOWCASE = 'akb_catalog_order_showcase';

	/** Disponible → «a pedido» → agotado, intercalando series dentro de cada grupo. */
	public const SHOWCASE_AVAILABLE_FIRST = 'disponibles_primero';

	/** El intercalado de siempre: disponible y «a pedido» mezclados por novedad de serie. */
	public const SHOWCASE_INTERLEAVED = 'intercalado';

	/**
	 * Modo de vitrina por defecto: productos disponibles para compra inmediata primero.
	 */
	public const DEFAULT_SHOWCASE = self::SHOWCASE_AVAILABLE_FIRST;

	public function register(): void {
		( new SeriesNewestOrder() )->register();
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
		add_action( 'akibara_business_upgraded', array( $this, 'on_business_upgraded' ), 10, 0 );
		add_action( self::RECOMPUTE_HOOK, array( $this, 'recompute' ) );
		add_action( self::DAILY_HOOK, array( $this, 'recompute' ) );
		add_action( 'init', array( $this, 'maybe_schedule_daily' ), 20 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'akibara-catalog-order recompute',
				function (): void {
					$changed = $this->recompute();
					\WP_CLI::success( sprintf( 'menu_order recomputado: %d productos actualizados.', $changed ) );
				}
			);

			\WP_CLI::add_command(
				'akibara-catalog-order status',
				function (): void {
					$last = self::last_run();
					if ( array() === $last ) {
						\WP_CLI::warning( 'La vitrina no registra ninguna corrida.' );
						return;
					}
					\WP_CLI::log(
						sprintf(
							'Última corrida: %s · %d productos leídos · %d menu_order reescritos.',
							gmdate( 'Y-m-d H:i:s', $last['time'] ) . ' UTC',
							$last['rows'],
							$last['applied']
						)
					);
				}
			);
		}
	}

	/**
	 * Deja armada UNA corrida diaria.
	 *
	 * ── Ejecución diaria desacoplada de eventos por venta ──
	 * Un recomputo de vitrina recalcula las posiciones del catálogo completo y
	 * emite una purga global de caché de página. Para evitar sobrecarga en la base
	 * de datos y purgas masivas ante cada compra individual, la sincronización se
	 * programa como tarea diaria consolidada y ante publicaciones de nuevos productos.
	 *
	 * @internal Callback de `init`.
	 */
	public function maybe_schedule_daily(): void {
		// La comprobación es una consulta a las tablas de Action Scheduler. En el
		// front no se paga: el agendado lo establece cualquier request de admin,
		// de WP-Cron o de WP-CLI, y todos ocurren al menos una vez al día.
		$is_backend = ( function_exists( 'is_admin' ) && is_admin() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $is_backend ) {
			return;
		}

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return; // Sin Action Scheduler queda el trigger de publish/unpublish y el CLI.
		}

		$agendada = as_next_scheduled_action( self::DAILY_HOOK, array(), self::GROUP );

		if ( true === $agendada ) {
			return; // Vencida o en curso: no se toca una acción que está corriendo.
		}

		if ( is_int( $agendada ) ) {
			// Si la acción programada ya venció, se permite su ejecución inmediata por el runner.
			if ( $agendada <= time() ) {
				return;
			}
			$ahora  = time();
			$deriva = min(
				abs( $agendada - $this->next_daily_timestamp( $ahora ) ),
				abs( $agendada - $this->next_daily_timestamp( $ahora - DAY_IN_SECONDS ) )
			);
			if ( $deriva <= self::DAILY_DRIFT_TOLERANCE ) {
				return;
			}

			// Re-anclaje: corrige derivas temporales acumuladas o desajustes por horario de verano/invierno.
			if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
				return;
			}
			as_unschedule_all_actions( self::DAILY_HOOK, array(), self::GROUP );
		}

		as_schedule_recurring_action( $this->next_daily_timestamp(), DAY_IN_SECONDS, self::DAILY_HOOK, array(), self::GROUP );
	}

	/**
	 * Próxima DAILY_HOUR en la zona del sitio, expresada en UTC.
	 *
	 * Se calcula sobre la hora del sitio y no sobre `time()` porque el servidor
	 * corre en UTC y agendar «a las 5» sin convertir dejaría la corrida a las 2
	 * de la madrugada de Chile en horario de verano y a la 1 en invierno: dos
	 * horas distintas para la misma intención.
	 */
	private function next_daily_timestamp( ?int $now = null ): int {
		// Con el calendario de la zona, NO con aritmética de desfases: el desfase
		// de hoy no vale para mañana. En el cambio de horario de Chile
		// (septiembre y abril) sumar el desfase de hoy dejaba la corrida —y su
		// purga de CDN— a las 06:00 locales, que es lo que este método existe
		// para evitar.
		$zona  = StoreTime::zone();
		$ahora = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->setTimezone( $zona );
		$next  = $ahora->setTime( self::DAILY_HOUR, self::DAILY_MINUTE );
		if ( $next->getTimestamp() <= $ahora->getTimestamp() ) {
			$next = $ahora->modify( '+1 day' )->setTime( self::DAILY_HOUR, self::DAILY_MINUTE );
		}

		return $next->getTimestamp();
	}

	/**
	 * Estado de la última corrida, para el CLI y para cualquier panel que lo
	 * quiera mostrar.
	 *
	 * @return array{time:int, rows:int, applied:int}|array{}
	 */
	public static function last_run(): array {
		$stored = get_option( self::OPTION_LAST_RUN, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['time'], $stored['rows'], $stored['applied'] ) ) {
			return array();
		}

		return array(
			'time'    => (int) $stored['time'],
			'rows'    => (int) $stored['rows'],
			'applied' => (int) $stored['applied'],
		);
	}

	/**
	 * Valida e interpreta el modo de vitrina.
	 *
	 * Acepta exclusivamente los modos soportados (`SHOWCASE_AVAILABLE_FIRST` y `SHOWCASE_INTERLEAVED`).
	 *
	 * @param mixed $value Valor a interpretar.
	 * @return string|null El valor si es una vitrina conocida; null si no.
	 */
	public static function parse_showcase( $value ): ?string {
		return in_array( $value, array( self::SHOWCASE_AVAILABLE_FIRST, self::SHOWCASE_INTERLEAVED ), true ) ? $value : null;
	}

	/**
	 * La vitrina vigente.
	 *
	 * Arquitectura de 2 niveles (12-Factor App / WordPress VIP):
	 * 1. Override opcional en wp-config.php (`AKB_CATALOG_ORDER_SHOWCASE`).
	 * 2. Default canónico en código (`SHOWCASE_AVAILABLE_FIRST = 'disponibles_primero'`).
	 *
	 * Cero consultas a base de datos.
	 */
	public static function current_showcase(): string {
		if ( defined( 'AKB_CATALOG_ORDER_SHOWCASE' ) && is_string( AKB_CATALOG_ORDER_SHOWCASE ) ) {
			$override = self::parse_showcase( AKB_CATALOG_ORDER_SHOWCASE );
			if ( null !== $override ) {
				return $override;
			}
		}

		return self::DEFAULT_SHOWCASE;
	}

	/**
	 * Encola el recálculo al actualizar el plugin para aplicar la configuración
	 * de vitrina vigente en la siguiente pasada de la cola de tareas.
	 *
	 * @internal Callback de `akibara_business_upgraded`. Idempotente.
	 */
	public function on_business_upgraded(): void {
		$this->schedule_recompute( 0 );
	}

	/**
	 * Solo interesan las TRANSICIONES hacia/desde publish de productos (alta de
	 * catálogo o retiro): un update publish→publish no cambia el universo a
	 * intercalar y no debe encolar nada.
	 *
	 * @param string   $new  Estado nuevo.
	 * @param string   $old  Estado anterior.
	 * @param \WP_Post $post Post transicionado.
	 */
	public function on_status_change( $new, $old, $post ): void {
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type ) {
			return;
		}
		if ( $new === $old || ( 'publish' !== $new && 'publish' !== $old ) ) {
			return;
		}

		$this->schedule_recompute();
	}

	/**
	 * Garantiza una única acción pendiente en cola a la vez mediante verificación
	 * de estado pendiente antes de encolar una nueva ejecución.
	 *
	 * Aplica debounce de ventana deslizante (600s): antes de programar con
	 * `as_schedule_single_action`, desprograma cualquier acción pendiente previa.
	 *
	 * @param int|null $delay_seconds Espera antes de correr. `null` aplica el debounce estándar (600s).
	  * @psalm-suppress UnusedReturnValue
	 * @return string `encolada`, `ya-encolada`, `en-curso-y-encolada` o `ejecutada`.
	 */
	private function schedule_recompute( ?int $delay_seconds = null ): string {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->recompute();
			return 'ejecutada';
		}

		// Si se solicita inmediata (0s, manual) y ya hay una pendiente, no se duplica.
		if ( 0 === $delay_seconds && self::hay_recalculo_pendiente() ) {
			return 'ya-encolada';
		}

		// Detecta si hay una acción actualmente en ejecución para reflejarlo en la respuesta.
		$en_curso = self::hay_recalculo_en_curso();

		// Debounce de ventana deslizante: desprograma cualquier acción pendiente del mismo hook.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RECOMPUTE_HOOK, array(), self::GROUP );
		}

		as_schedule_single_action(
			time() + ( null === $delay_seconds ? self::DEBOUNCE_SECONDS : max( 0, $delay_seconds ) ),
			self::RECOMPUTE_HOOK,
			array(),
			self::GROUP
		);

		return $en_curso ? 'en-curso-y-encolada' : 'encolada';
	}

	/**
	 * Verifica si existe una acción pendiente en la cola de Action Scheduler.
	 *
	 * Consulta directamente por status='pending' para desacoplar de acciones en curso.
	 *
	 * @return bool
	 */
	private static function hay_recalculo_pendiente(): bool {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => self::RECOMPUTE_HOOK,
					'group'    => self::GROUP,
					'status'   => 'pending', // ActionScheduler_Store::STATUS_PENDING.
					'per_page' => 1,
				),
				'ids'
			);

			return is_array( $ids ) && array() !== $ids;
		}

		// Action Scheduler anterior a 3.3 (sin as_get_scheduled_actions): sólo
		// existe la consulta que también cuenta la acción en curso. Se conserva
		// el comportamiento viejo antes que quedarse sin guardia.
		return function_exists( 'as_next_scheduled_action' )
			&& false !== as_next_scheduled_action( self::RECOMPUTE_HOOK, array(), self::GROUP );
	}

	/**
	 * ¿Hay un recálculo ejecutándose ahora mismo?
	 *
	 * @return bool
	 */
	private static function hay_recalculo_en_curso(): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::RECOMPUTE_HOOK,
				'group'    => self::GROUP,
				'status'   => 'in-progress',
				'per_page' => 1,
			),
			'ids'
		);

		return is_array( $ids ) && array() !== $ids;
	}



	/**
	 * Lo que el recálculo escribiría AHORA con la vitrina pedida, sin escribir.
	 *
	 * Una fila por producto publicado con su serie (menor term_taxonomy_id si
	 * tiene varias — elección determinista), su stock status (vendible =
	 * instock/onbackorder; los agotados ceden el espacio prime) y su menu_order
	 * actual. `recompute()` escribe la diferencia entre `orders` y `current`;
	 * QA la mide con esta misma función (`wp eval`) para saber qué vitrina está
	 * aplicada sin depender de una muestra de tarjetas.
	 *
	 * @param string $showcase Vitrina a calcular; un valor desconocido cae a la del dueño.
	 * @return array{orders: array<int, int>, current: array<int, int>} product_id => menu_order propuesto / actual.
	 */
	public function plan( string $showcase ): array {
		global $wpdb;

		$stock_join = StockStatus::sql_postmeta_join( $wpdb->postmeta, 'pm', 'p.ID', 'LEFT' );
		$sql        = "
			SELECT p.ID AS id, p.post_date AS date, p.menu_order AS current_order,
			       MIN( tt.term_taxonomy_id ) AS serie_tt,
			       MAX( pm.meta_value ) AS stock_status
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			LEFT JOIN {$wpdb->term_taxonomy} tt
				ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'pa_serie'
			{$stock_join}
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
			GROUP BY p.ID, p.post_date, p.menu_order";
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- sin input externo.

		$input   = array();
		$current = array();
		foreach ( (array) $rows as $row ) {
			$id             = (int) $row['id'];
			$stock_status   = null !== $row['stock_status'] ? (string) $row['stock_status'] : null;
			$input[]        = array(
				'id'       => $id,
				'date'     => (string) $row['date'],
				// Sin serie → singleton: participa del round-robin como serie propia.
				'serie'    => null !== $row['serie_tt'] ? 't' . (int) $row['serie_tt'] : 'p' . $id,
				'sellable' => StockStatus::OUT_OF_STOCK !== $stock_status,
				'tier'     => Interleaver::availability_tier( $stock_status ),
			);
			$current[ $id ] = (int) $row['current_order'];
		}

		$orders = self::SHOWCASE_INTERLEAVED === self::parse_showcase( $showcase )
			? Interleaver::compute( $input )
			: Interleaver::compute_available_first( $input );

		return array(
			'orders'  => $orders,
			'current' => $current,
		);
	}

	/**
	 * Recomputa el intercalado completo y persiste los menu_order que cambian.
	 *
	 * Ejecuta actualizaciones directas en lotes sobre `wp_posts` evitando disparar
	 * hooks individuales de guardado para optimizar el rendimiento.
	 *
	 * @return int Cantidad de productos cuyo menu_order se actualizó realmente.
	 */
	public function recompute(): int {
		global $wpdb;

		// La vitrina se lee al CORRER, no al encolar: un recálculo pendiente
		// aplica la última elección del dueño aunque la hiciera después.
		$plan    = $this->plan( self::current_showcase() );
		$orders  = $plan['orders'];
		$current = $plan['current'];

		if ( array() === $current ) {
			$this->record_run( 0, 0 );
			return 0;
		}

		$changes = array();
		foreach ( $orders as $id => $order ) {
			if ( $current[ $id ] !== $order ) {
				$changes[ $id ] = $order;
			}
		}

		if ( empty( $changes ) ) {
			$this->record_run( count( $current ), 0 );
			return 0;
		}

		$applied = 0;
		foreach ( $changes as $id => $order ) {
			$result = $wpdb->update(
				$wpdb->posts,
				array( 'menu_order' => (int) $order ),
				array( 'ID' => (int) $id ),
				array( '%d' ),
				array( '%d' )
			);
			if ( false === $result ) {
				\Akibara\Business\Utilities\Log::warn(
					'catalog-order',
					sprintf( 'menu_order falló para producto %d: %s', (int) $id, $wpdb->last_error )
				);
				continue;
			}
			++$applied;
			clean_post_cache( (int) $id );
		}

		// Si se aplicaron cambios de orden en el catálogo, se sincroniza la tabla
		// de búsqueda akibara-search y se ejecuta una purga integral de caché.
		if ( $applied > 0 ) {
			$prefix       = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
			$search_table = $prefix . 'akb_search_docs';
			// Sincronizar d.menu_order directamente para garantizar paridad estricta con wp_posts.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE %i d
					 JOIN %i p ON d.post_id = p.ID
					 SET d.menu_order = p.menu_order
					 WHERE d.menu_order != p.menu_order",
					$search_table,
					$wpdb->posts
				)
			);

			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook público oficial de LiteSpeed Cache.
		}

		$this->record_run( count( $current ), $applied );

		return $applied;
	}

	/**
	 * Registra cada corrida, incluso si no hubo deriva. Un fallo al escribir
	 * la marca no deshace el recompute ya realizado; la marca distingue ejecución
	 * sin cambios de ausencia de ejecución.
	 */
	private function record_run( int $rows, int $applied ): void {
		update_option(
			self::OPTION_LAST_RUN,
			array(
				'time'    => time(),
				'rows'    => $rows,
				'applied' => $applied,
			),
			false
		);
	}
}
