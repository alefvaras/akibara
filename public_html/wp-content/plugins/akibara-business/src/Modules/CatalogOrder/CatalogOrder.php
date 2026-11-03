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

final class CatalogOrder {

	private const RECOMPUTE_HOOK   = 'akibara_catalog_order_recompute';
	private const DAILY_HOOK       = 'akibara_catalog_order_daily';
	private const GROUP            = 'akb-catalog-order';
	private const DEBOUNCE_SECONDS = 120; // los imports publican en ráfaga: una sola corrida.
	private const WRITE_CHUNK      = 200;

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

	/** Pestaña propia dentro del panel Akibara. */
	public const PANEL_TAB_ID = 'catalog_order';

	/** Acción de `admin-post.php` que encola un recálculo manual. */
	private const ADMIN_ACTION = 'akibara_catalog_order_recompute';

	/** Qué vitrina calcula el recompute. Se elige en la pestaña «Vitrina». */
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
		add_filter( 'akibara_panel_save_errors', array( self::class, 'validate_save' ), 10, 2 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::OPTION_SHOWCASE, array( self::class, 'sanitize_showcase_for_save' ) );
		// Elegir otra vitrina no mueve nada hasta recalcular: se encola al
		// momento. `add_option_*` cubre el primer cambio (la fila no existe
		// mientras se usa el valor por defecto) y `update_option_*` los demás;
		// WordPress no dispara ninguno de los dos si el valor no cambió.
		add_action( 'add_option_' . self::OPTION_SHOWCASE, array( $this, 'on_showcase_changed' ), 10, 0 );
		add_action( 'update_option_' . self::OPTION_SHOWCASE, array( $this, 'on_showcase_changed' ), 10, 0 );
		// Y cuando cambia el CÓDIGO que calcula la vitrina, que no pasa por
		// ninguno de los dos hooks de arriba (B1-REV2-1).
		add_action( 'akibara_business_upgraded', array( $this, 'on_business_upgraded' ), 10, 0 );
		add_action( 'admin_post_' . self::ADMIN_ACTION, array( $this, 'handle_admin_recompute' ) );
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
	 * La vitrina vigente. Fila ausente o con un valor desconocido (escrito a
	 * mano, por ejemplo) = la del dueño: nunca deja el catálogo sin orden.
	 */
	public static function current_showcase(): string {
		return self::parse_showcase( get_option( self::OPTION_SHOWCASE, self::DEFAULT_SHOWCASE ) ) ?? self::DEFAULT_SHOWCASE;
	}

	/**
	 * Lo que WooCommerce escribe: la vitrina enviada, o la vigente si lo enviado
	 * no es una vitrina (la validación previa ya habría detenido el guardado).
	 *
	 * Hace falta porque WooCommerce sanea los `radio` con `wc_clean()` y nada
	 * más: sin esto, cualquier texto enviado a mano quedaba guardado.
	 *
	 * @internal Callback de `woocommerce_admin_settings_sanitize_option_akb_catalog_order_showcase`.
	 *
	 * @param mixed $value Valor enviado.
	 */
	public static function sanitize_showcase_for_save( $value ): string {
		return self::parse_showcase( $value ) ?? self::current_showcase();
	}

	/**
	 * Rechaza la pestaña entera si la vitrina enviada no es una de las dos.
	 *
	 * El formulario sólo ofrece valores válidos; esto cubre el envío fabricado a
	 * mano, y lo DICE en vez de guardar otra cosa en silencio.
	 *
	 * @internal Callback de `akibara_panel_save_errors`.
	 *
	 * @param mixed  $errors Mensajes acumulados.
	 * @param string $tab    Pestaña que se guarda.
	 * @return mixed
	 */
	public static function validate_save( $errors, $tab ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- `AdminPanel::handle_save()` verifica el nonce antes de aplicar este filtro.
		if ( self::PANEL_TAB_ID !== $tab || ! is_array( $errors ) || ! isset( $_POST[ self::OPTION_SHOWCASE ] ) ) {
			return $errors;
		}

		$enviado = wp_unslash( $_POST[ self::OPTION_SHOWCASE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida con parse_showcase() y no se guarda desde aquí.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( null === self::parse_showcase( $enviado ) ) {
			$errors[] = 'La vitrina elegida no existe: elige «Disponibles primero» o «Intercalado». No se guardó nada.';
		}

		return $errors;
	}

	/**
	 * Encola el recálculo en cuanto cambia la vitrina elegida.
	 *
	 * Sin debounce: no hay ráfaga que agrupar, hay una persona que acaba de
	 * guardar y quiere ver el cambio. Si ya había uno pendiente, ése leerá la
	 * vitrina nueva al correr (la lee al empezar, no al encolarse).
	 *
	 * @internal Callback de `add_option_akb_catalog_order_showcase` y `update_option_akb_catalog_order_showcase`.
	 */
	public function on_showcase_changed(): void {
		$this->schedule_recompute( 0 );
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
	 * Ajustes de la pestaña «Vitrina».
	 *
	 * El `title` va vacío a propósito: el panel borra el título de la primera
	 * sección para no repetir el suyo (`AdminPanel::prepare_settings_for_display()`),
	 * así que cualquier texto aquí sería copy que nadie ve. El encabezado del
	 * ajuste y su explicación los pinta `render_admin_section()`, justo encima
	 * del formulario.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'title' => '',
				'type'  => 'title',
				'id'    => 'akb_catalog_order_showcase_settings',
			),
			array(
				'title'    => 'Vitrina',
				'id'       => self::OPTION_SHOWCASE,
				'type'     => 'radio',
				'default'  => self::DEFAULT_SHOWCASE,
				'autoload' => false,
				'options'  => array(
					self::SHOWCASE_AVAILABLE_FIRST => 'Disponibles primero (recomendado): arriba lo que está en bodega y se despacha ya; después lo «a pedido» y las preventas; al final lo agotado. Dentro de cada grupo se siguen intercalando las series, con lo más nuevo arriba.',
					self::SHOWCASE_INTERLEAVED     => 'Intercalado, como hasta septiembre de 2026: disponibles y «a pedido» mezclados según lo más nuevo de cada serie; lo agotado baja dentro de su serie.',
				),
				'desc'     => 'Al guardar se encola el recálculo: lo ejecuta el cron del servidor en unos minutos y, al terminar, limpia la caché del sitio para que el cliente vea el orden nuevo.',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_catalog_order_showcase_settings',
			),
		);
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
	 * Distingue con precisión los estados: encolada, ya encolada previamente,
	 * encolada tras una acción en progreso o ejecutada de forma síncrona.
	 *
	 * @param int|null $delay_seconds Espera antes de correr. `null` aplica el debounce estándar.
	 * @return string `encolada`, `ya-encolada`, `en-curso-y-encolada` o `ejecutada`.
	 */
	private function schedule_recompute( ?int $delay_seconds = null ): string {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->recompute();
			return 'ejecutada';
		}

		// Si ya existe una acción pendiente, no se duplica.
		if ( self::hay_recalculo_pendiente() ) {
			return 'ya-encolada';
		}

		// Detecta si hay una acción actualmente en ejecución para reflejarlo en la respuesta.
		$en_curso = self::hay_recalculo_en_curso();

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
	 * Encola el recálculo completo solicitado por el dueño, con ejecución directa
	 * sólo si no existe Action Scheduler. Evita recorrer y reescribir el catálogo
	 * dentro de la petición administrativa.
	 *
	 * @internal Callback de `admin_post_akibara_catalog_order_recompute`.
	 *
	 * @return void
	 */
	public function handle_admin_recompute(): void {
		if ( ! current_user_can( AdminPanel::PANEL_CAP ) ) {
			wp_die( 'Sin permisos.', 'akibara', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ADMIN_ACTION );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'akibara-panel',
					'tab'         => self::PANEL_TAB_ID,
					'akb_vitrina' => $this->schedule_recompute( 0 ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Estado de la vitrina y su botón de recálculo.
	 *
	 * La vitrina reordena 1.568 productos y hasta ahora lo hacía a ciegas: el
	 * Interfaz administrativa de la sección «Vitrina».
	 *
	 * Renderiza el estado del último recálculo y la opción de disparo manual.
	 *
	 * @internal Callback de `AdminPanel::add_section($header_fn)`.
	 *
	 * @return void
	 */
	public function render_admin_section(): void {
		$last = self::last_run();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- bandera de sólo lectura puesta por nuestro propio wp_safe_redirect; no cambia estado.
		$resultado = isset( $_GET['akb_vitrina'] ) ? sanitize_key( wp_unslash( (string) $_GET['akb_vitrina'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$aviso = match ( $resultado ) {
			'encolada'            => array( 'success', 'Recálculo encolado. Lo ejecuta el cron del servidor en los próximos minutos; vuelve a esta pantalla para ver la fecha nueva.' ),
			'ya-encolada'         => array( 'info', 'Ya había un recálculo en cola; no se duplicó.' ),
			'en-curso-y-encolada' => array( 'success', 'Hay un recálculo ejecutándose ahora mismo; se encoló otro para justo después, así no se pierde nada de lo que se publique mientras tanto.' ),
			'ejecutada'           => array( 'success', 'Vitrina recalculada.' ),
			default               => null,
		};
		?>
		<?php if ( null !== $aviso ) : ?>
			<div class="notice notice-<?php echo esc_attr( $aviso[0] ); ?> is-dismissible"><p><?php echo esc_html( $aviso[1] ); ?></p></div>
		<?php endif; ?>

		<h3>Recálculo</h3>

		<p>
			El orden «Destacados» —el que ve el cliente al entrar al catálogo sin elegir
			ningún orden— intercala series para que la página no sea una fila de tomos
			de la misma colección, con la vitrina que elijas abajo. Se recalcula solo
			cada día de madrugada, cada vez que publicas o retiras un producto y cada
			vez que cambias la vitrina. Un tomo que se agota o que llega a bodega
			cambia de grupo en el recálculo siguiente.
		</p>

		<p>
			<?php if ( array() === $last ) : ?>
				<strong>Nunca se ha recalculado.</strong> El catálogo se está ordenando con los valores que tenga cada producto.
			<?php else : ?>
				Última corrida: <strong><?php echo esc_html( self::format_moment( $last['time'] ) ); ?></strong>
				— <?php echo esc_html( sprintf( '%s productos revisados, %s reordenados', number_format_i18n( $last['rows'] ), number_format_i18n( $last['applied'] ) ) ); ?>.
			<?php endif; ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ADMIN_ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_ACTION ); ?>">
			<button type="submit" class="button">Recalcular la vitrina ahora</button>
		</form>

		<p class="description">
			Al recalcular se pisa cualquier orden que hayas arrastrado a mano en la lista
			de productos: el orden de la vitrina lo calcula la tienda.
		</p>

		<?php // El formulario del panel, con el ajuste, va justo debajo: este encabezado es el suyo. ?>
		<h3>Qué ve primero el cliente</h3>

		<p>
			Es el orden «Destacados»: el que aparece al entrar a la tienda, a una categoría
			(Manga, Cómics, un distrito) o a una editorial sin elegir otro. Las landings de
			serie no cambian: abren en «Tomo 1 → N», y su opción «Novedades primero» sigue
			mostrando lo más nuevo arriba y lo agotado al final, elijas la vitrina que elijas.
		</p>
		<?php
	}

	/**
	 * Fecha legible en la zona horaria de la tienda.
	 *
	 * @param int $timestamp Marca de tiempo UTC.
	 */
	private static function format_moment( int $timestamp ): string {
		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( 'j \d\e F Y, H:i', $timestamp );
		}

		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
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
		foreach ( array_chunk( $changes, self::WRITE_CHUNK, true ) as $chunk ) {
			$cases = array();
			$ids   = array();
			foreach ( $chunk as $id => $order ) {
				$cases[] = $wpdb->prepare( 'WHEN %d THEN %d', $id, $order );
				$ids[]   = (int) $id;
			}
			$result = $wpdb->query(
				"UPDATE {$wpdb->posts} SET menu_order = CASE ID " . implode( ' ', $cases ) . ' END WHERE ID IN (' . implode( ',', $ids ) . ')' // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Cada WHEN sale de $wpdb->prepare() y los ID son enteros casteados arriba.
			);
			if ( false === $result ) {
				// Sin traza el fallo es invisible: por Action Scheduler nadie lee
				// el retorno. No se purga la cache de estas filas — no cambiaron,
				// y su menu_order stale es el que sigue vigente.
				\Akibara\Business\Utilities\Log::warn(
					'catalog-order',
					sprintf( 'Chunk de %d menu_order falló: %s', count( $ids ), $wpdb->last_error )
				);
				continue;
			}
			$applied += count( $ids );
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
		}

		// Si se aplicaron cambios de orden en el catálogo, se ejecuta una purga integral
		// de caché de página para sincronizar listados y archivos.
		if ( $applied > 0 ) {
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
