<?php
/**
 * Agrupa los ajustes de preventa en el panel Akibara → Preventa.
 *
 * Las subpestañas reúnen fechas estimadas de EstimatedDelivery, avisos previos,
 * cambios de fecha y notas de pedidos atrasados. Los módulos conservan sus
 * responsabilidades; el panel presenta una navegación común. get_int() y
 * get_bool() leen mediante get_option y participan en su caché de opciones.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\EstimatedDelivery;

defined( 'ABSPATH' ) || exit;

/**
 * Configuración del módulo OrderPreventa + agrupador UX para
 * `EstimatedDelivery` bajo el panel `Akibara → Preventa`.
 *
 * @since 1.21.0
 */
final class Settings {

	public const PANEL_TAB_ID = 'preventa';

	public const OPT_T7_ENABLED             = 'akb_preventa_t7_enabled';
	public const OPT_T7_WINDOW_MAX          = 'akb_preventa_t7_window_max_days';
	public const OPT_T7_WINDOW_MIN          = 'akb_preventa_t7_window_min_days';
	public const OPT_T7_LEAD_DAYS           = 'akb_preventa_t7_lead_days';
	public const OPT_DATE_CHANGE_THRESHOLD     = 'akb_preventa_date_change_threshold_days';
	public const OPT_DATE_CHANGE_NOTIFY_ADV    = 'akb_preventa_date_change_notify_advance';
	public const OPT_DATE_CHANGE_NOTIFY_WINDOW = 'akb_preventa_date_change_notify_window_days';
	public const OPT_STALE_PENDING_DAYS     = 'akb_preventa_stale_pending_days';
	public const OPT_STALE_MAX_AGE_DAYS     = 'akb_preventa_stale_max_age_days';

	/**
	 * Plazo contractual de reembolso contado desde la llegada prometida.
	 *
	 * La constante alimenta tanto BannerRenderer::promesas_para() como
	 * ReadyToShipCron::maybe_mark_refund_eligible(). Debe concordar con el contenido
	 * publicado en Términos, FAQ y Devoluciones; no es un umbral operativo editable.
	 * Los plazos de maybe_mark_stale() tienen un propósito distinto y no lo alteran.
	 * El control sólo señala la elegibilidad: el reembolso es manual, con atención
	 * por el canal acordado para el pedido.
	 *
	 * @since 1.116.27
	 */
	public const REFUND_AFTER_DAYS = 15;

	/**
	 * Tope de frescura del aviso «ya llegó»: pasados estos días desde que el
	 * pedido quedó listo, el correo ya no sale solo.
	 *
	 * @since 1.114.7
	 */
	public const OPT_READY_EMAIL_MAX_AGE_DAYS = 'akb_preventa_ready_email_max_age_days';
	public const OPT_STALE_POST_READY_DAYS  = 'akb_preventa_stale_post_ready_days';

	/**
	 * Defaults canónicos. Si el owner no toca el panel, los crons funcionan
	 * idénticamente al comportamiento hardcoded previo a 1.21.0.
	 *
	 * @var array<string, int|string>
	 */
	public const DEFAULTS = array(
		self::OPT_T7_ENABLED             => 'yes',
		self::OPT_T7_WINDOW_MAX          => 10,
		self::OPT_T7_WINDOW_MIN          => 1,
		self::OPT_T7_LEAD_DAYS           => 14,
		self::OPT_DATE_CHANGE_THRESHOLD     => 7,
		self::OPT_DATE_CHANGE_NOTIFY_ADV    => 'no',
		self::OPT_DATE_CHANGE_NOTIFY_WINDOW => 14,
		self::OPT_STALE_PENDING_DAYS     => 30,
		self::OPT_STALE_MAX_AGE_DAYS     => 120,
		self::OPT_READY_EMAIL_MAX_AGE_DAYS => 7,
		self::OPT_STALE_POST_READY_DAYS  => 4,
	);

	/**
	 * Mapping sub-tab slug → label visible. El orden define el orden del nav.
	 *
	 * @var array<string, string>
	 */
	private const SUBTABS = array(
		'cortes'       => 'Cortes por país y editorial',
		'fechas'       => 'Fechas estimadas',
		'recordatorio' => 'Aviso previo a la llegada',
		'cambio-fecha' => 'Cambio de fecha',
		'stale'        => 'Pedidos atrasados',
	);

	// La navegación del panel agrupa esta operación en Cortes.
	private const DEFAULT_SUBTAB = 'cortes';

	/**
	 * Inyectado desde OrderPreventa::register() para poder delegar el sub-tab
	 * "Fechas estimadas" al módulo EstimatedDelivery sin romper su single
	 * responsibility.
	 *
	 * @var EstimatedDelivery|null
	 */
	private ?EstimatedDelivery $eda_module = null;

	/**
	 * Inyección del módulo EstimatedDelivery para el sub-tab "fechas". Llamado
	 * desde OrderPreventa::register() después de que ambos módulos están
	 * instanciados.
	 */
	public function set_eda_module( EstimatedDelivery $eda ): void {
		$this->eda_module = $eda;
	}

	/**
	 * Lee una option int con default canónico.
	 *
	 * @param string $option_key Constante OPT_*.
	 * @return int
	 */
	public static function get_int( string $option_key ): int {
		$default = (int) ( self::DEFAULTS[ $option_key ] ?? 0 );
		return (int) get_option( $option_key, $default );
	}

	/**
	 * Lee una option boolean (yes/no) con default canónico.
	 *
	 * @param string $option_key Constante OPT_*.
	 * @return bool
	 */
	public static function get_bool( string $option_key ): bool {
		$default = (string) ( self::DEFAULTS[ $option_key ] ?? 'no' );
		return 'yes' === (string) get_option( $option_key, $default );
	}

	/**
	 * Dispatcher principal — devuelve los fields del sub-tab activo. Llamado
	 * por `AdminPanel::add_section($cb_settings)`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_panel_settings(): array {
		$subtab = $this->current_subtab();

		switch ( $subtab ) {
			case 'cortes':
				return array(); // La tabla trae su PROPIO form (admin-post); ver render_subtab_nav.
			case 'fechas':
				return $this->eda_module instanceof EstimatedDelivery
					? $this->eda_module->get_settings()
					: array();
			case 'recordatorio':
				return $this->get_t7_settings();
			case 'cambio-fecha':
				return $this->get_date_change_settings();
			case 'stale':
				return $this->get_stale_settings();
			default:
				return $this->get_t7_settings();
		}
	}

	/**
	 * Header del panel — renderiza nav lateral de sub-tabs antes del form. Llamado
	 * por `AdminPanel::add_section($cb_header)`.
	 *
	 * @return void
	 */
	/**
	 * El sub-tab «cortes» pinta su propio form hacia admin-post: el form de
	 * settings del panel se suprime vía este filtro para no anidar forms.
	 *
	 * @param bool   $is_action Valor calculado por el panel.
	 * @param string $tab       Tab activo.
	 * @return bool
	 */
	public function filter_section_is_action( $is_action, $tab ) {
		if ( self::PANEL_TAB_ID === (string) $tab && 'cortes' === $this->current_subtab() ) {
			return true;
		}
		return (bool) $is_action;
	}

	public function render_subtab_nav(): void {
		$current = $this->current_subtab();
		$this->render_subtab_styles();
		?>
		<nav class="akb-subtab-nav" aria-label="Sub-secciones de preventa">
			<?php foreach ( self::SUBTABS as $id => $label ) : ?>
				<a href="<?php echo esc_url( $this->subtab_url( $id ) ); ?>"
				   class="akb-subtab<?php echo $current === $id ? ' is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'cortes' === $current ) {
			( new \Akibara\Business\Modules\EstimatedDelivery\CortesPanel() )->render();
		}
	}

	/**
	 * Hook handler — inyecta input hidden con sub-tab actual al form para
	 * preservar tras save.
	 *
	 * @param string $current_tab Slug del tab activo.
	 * @return void
	 */
	public function inject_subtab_hidden_input( string $current_tab ): void {
		if ( self::PANEL_TAB_ID !== $current_tab ) {
			return;
		}
		echo '<input type="hidden" name="akibara_current_subtab" value="' . esc_attr( $this->current_subtab() ) . '">';
	}

	/**
	 * Slug del sub-tab activo basado en `?subtab=` o `$_POST` fallback.
	 *
	 * @return string
	 */
	private function current_subtab(): string {
		$raw = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AdminPanel verifica el nonce; esto sólo decide qué sub-pestaña se PINTA.
		if ( isset( $_GET['subtab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AdminPanel verifica el nonce; esto sólo decide qué sub-pestaña se PINTA.
			$raw = sanitize_key( (string) wp_unslash( $_GET['subtab'] ) );
		} elseif ( isset( $_POST['akibara_current_subtab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- AdminPanel verifica el nonce; esto sólo decide qué sub-pestaña se PINTA.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- AdminPanel verifica el nonce.
			$raw = sanitize_key( (string) wp_unslash( $_POST['akibara_current_subtab'] ) );
		}
		return isset( self::SUBTABS[ $raw ] ) ? $raw : self::DEFAULT_SUBTAB;
	}

	/**
	 * URL del panel para un sub-tab dado.
	 *
	 * @param string $subtab Slug.
	 * @return string
	 */
	private function subtab_url( string $subtab ): string {
		return add_query_arg(
			array(
				'page'   => 'akibara-panel',
				'tab'    => self::PANEL_TAB_ID,
				'subtab' => $subtab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * CSS inline del nav sub-tab. Inline porque es trivial (~25 líneas) y
	 * usar un enqueue separado complica el wire-up para 1 panel admin.
	 *
	 * @return void
	 */
	private function render_subtab_styles(): void {
		?>
		<style>
		.akb-subtab-nav {
			display: flex;
			flex-wrap: wrap;
			gap: 0;
			margin: 16px 0 4px;
			border-bottom: 1px solid #c3c4c7;
		}
		.akb-subtab {
			padding: 10px 18px;
			text-decoration: none;
			color: #50575e;
			font-size: 13px;
			font-weight: 500;
			border: 1px solid transparent;
			border-bottom: none;
			margin-bottom: -1px;
			transition: color .15s;
		}
		.akb-subtab:hover {
			color: #1d2327;
			background: #f6f7f7;
		}
		.akb-subtab.is-active {
			color: #D90010;
			background: #fff;
			border-color: #c3c4c7;
			border-bottom: 1px solid #fff;
			font-weight: 600;
		}
		</style>
		<?php
	}

	// -----------------------------------------------------------------------
	// Settings fields por sub-tab
	// -----------------------------------------------------------------------

	/**
	 * Sub-tab `recordatorio`: T-7d cron settings.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_t7_settings(): array {
		return array(
			array(
				'title' => 'Aviso previo a la llegada («tu preventa se acerca»)',
				'type'  => 'title',
				'desc'  => 'Correo automático al cliente unos días antes de la fecha estimada de llegada. Solo se envía si la preventa era lo bastante larga (ver «Duración mínima de la preventa» más abajo). La revisión corre una vez por semana, los domingos a las 03:00 de Chile.',
				'id'    => 'akb_preventa_t7_section',
			),
			array(
				'title'   => 'Activar el aviso previo',
				'desc'    => 'Si lo apagas, el aviso deja de programarse. Volver a encenderlo lo reprograma solo.',
				'id'      => self::OPT_T7_ENABLED,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPT_T7_ENABLED ],
			),
			array(
				'title'    => 'Enviar como máximo (días antes)',
				'desc'     => 'Cuántos días antes de la fecha estimada puede salir el aviso. El valor recomendado es 10: la revisión es semanal, así que necesita ese margen.',
				'desc_tip' => true,
				'id'       => self::OPT_T7_WINDOW_MAX,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_T7_WINDOW_MAX ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 1, 'max' => 30 ),
			),
			array(
				'title'    => 'Enviar como mínimo (días antes)',
				'desc'     => 'Si a la fecha estimada le quedan menos días que este número, ya no se envía el aviso (no tiene sentido avisar el día anterior).',
				'desc_tip' => true,
				'id'       => self::OPT_T7_WINDOW_MIN,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_T7_WINDOW_MIN ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 0, 'max' => 14 ),
			),
			array(
				'title'    => 'Duración mínima de la preventa (días)',
				'desc'     => 'El aviso solo se envía si pasaron al menos estos días entre la compra y la fecha estimada de llegada. Con 14 evitas escribirle a quien compró hace poco y tiene la fecha fresca.',
				'desc_tip' => true,
				'id'       => self::OPT_T7_LEAD_DAYS,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_T7_LEAD_DAYS ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 0, 'max' => 90 ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_preventa_t7_section_end',
			),
		);
	}

	/**
	 * Sub-tab `cambio-fecha`: threshold + advance toggle.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_date_change_settings(): array {
		return array(
			array(
				'title' => 'Notificación de cambio de fecha',
				'type'  => 'title',
				'desc'  => 'Cuando cambias la fecha de lanzamiento de un producto en preventa, los clientes con pedidos activos reciben un correo. Las correcciones menores (bajo el umbral de abajo) no molestan a nadie.',
				'id'    => 'akb_preventa_date_change_section',
			),
			array(
				'title'    => 'Diferencia mínima para avisar (días)',
				'desc'     => 'Solo se avisa si la fecha nueva se corre al menos estos días respecto de la anterior. Con 7 se filtran las correcciones triviales.',
				'desc_tip' => true,
				'id'       => self::OPT_DATE_CHANGE_THRESHOLD,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_DATE_CHANGE_THRESHOLD ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 1, 'max' => 90 ),
			),
			array(
				'title'    => 'Ventana entre avisos (días)',
				'desc'     => 'Cap por frecuencia: no re-notificar al cliente más de 1 vez por esta ventana. Si el admin posterga de nuevo antes de que pasen N días desde el último aviso, el cambio queda registrado como order-note pero NO se envía otro email (anti-spam). No es un techo duro: pasada la ventana, un nuevo retraso vuelve a avisarse (obligación Ley 21.719). Default 14.',
				'desc_tip' => true,
				'id'       => self::OPT_DATE_CHANGE_NOTIFY_WINDOW,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_DATE_CHANGE_NOTIFY_WINDOW ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 1, 'max' => 90 ),
			),
			array(
				'title'   => 'Notificar también los adelantos',
				'desc'    => 'Por defecto solo se avisan los atrasos. Si lo activas, también se avisa cuando la fecha se adelanta (esa buena noticia igual llega sola con el aviso de «listo para despachar»).',
				'id'      => self::OPT_DATE_CHANGE_NOTIFY_ADV,
				'type'    => 'checkbox',
				'default' => self::DEFAULTS[ self::OPT_DATE_CHANGE_NOTIFY_ADV ],
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_preventa_date_change_section_end',
			),
		);
	}

	/**
	 * Sub-tab `stale`: alertas admin sobre órdenes lentas.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_stale_settings(): array {
		return array(
			array(
				'title' => 'Alertas de órdenes lentas',
				'type'  => 'title',
				'desc'  => 'Order notes automáticas que agregan info para el admin cuando una orden lleva demasiado tiempo en estado preventa. No envían email al cliente — solo aparecen en el log de la orden.',
				'id'    => 'akb_preventa_stale_section',
			),
			array(
				'title'    => 'No avisar de pedidos viejos (días)',
				'desc'     => 'Un pedido que quedó listo hace más de estos días ya no manda solo el correo «tu pedido ya llegó»: en su lugar deja una nota para que decidas tú. Evita que un cliente de hace meses reciba un aviso desfasado si tocas el estado del pedido. Con 0 se desactiva el tope y vuelve a avisar siempre.',
				'desc_tip' => true,
				'id'       => self::OPT_READY_EMAIL_MAX_AGE_DAYS,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_READY_EMAIL_MAX_AGE_DAYS ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 0, 'max' => 365 ),
			),
			array(
				'title'    => 'Alerta: preventa sin novedades (días)',
				'desc'     => 'Días de gracia después de la fecha de llegada prometida al cliente: si el pedido sigue sin quedar listo, se agrega una nota para que lo revises a mano. Si el tomo no tiene fecha, se cuentan desde la compra.',
				'desc_tip' => true,
				'id'       => self::OPT_STALE_PENDING_DAYS,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_STALE_PENDING_DAYS ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 7, 'max' => 180 ),
			),
			array(
				'title'    => 'Avisar igual a los (días desde la primera fecha prometida)',
				'desc'     => 'Aunque la fecha estimada se siga corriendo por postergaciones de la editorial, deja la nota de revisar cuando pasen estos días desde la fecha de llegada que se le prometió al cliente AL COMPRAR (si el tomo no tenía fecha, desde la compra). Los días de arriba se cuentan desde la fecha prometida vigente.',
				'desc_tip' => true,
				'id'       => self::OPT_STALE_MAX_AGE_DAYS,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_STALE_MAX_AGE_DAYS ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 30, 'max' => 365 ),
			),
			array(
				'title'    => 'Alerta: listo pero sin despachar (días hábiles)',
				'desc'     => sprintf(
					'Si un pedido ya está listo para despachar pero nadie lo completó en estos días hábiles, se agrega una nota de prioridad. Con 4 queda un día de gracia sobre la promesa de «%s».',
					ShipPromise::window()
				),
				'desc_tip' => true,
				'id'       => self::OPT_STALE_POST_READY_DAYS,
				'type'     => 'number',
				'default'  => self::DEFAULTS[ self::OPT_STALE_POST_READY_DAYS ],
				'css'      => 'width: 80px;',
				'custom_attributes' => array( 'min' => 1, 'max' => 30 ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'akb_preventa_stale_section_end',
			),
		);
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	/**
	 * Registra hooks: toggle T-7d desschedule/reschedule + hidden input para
	 * preservar sub-tab tras save.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action(
			'update_option_' . self::OPT_T7_ENABLED,
			array( $this, 'on_t7_enabled_change' ),
			10,
			2
		);
		add_action(
			'akibara_panel_form_hidden_inputs',
			array( $this, 'inject_subtab_hidden_input' )
		);
		add_filter( 'akibara_panel_save_errors', array( $this, 'validate_windows' ), 10, 2 );
	}

	/**
	 * Rechaza combinaciones de ventanas que apagarían los emails en silencio.
	 *
	 * Los custom_attributes min/max de los number fields son solo validación
	 * del navegador; el server guardaba cualquier valor. Con min > max la
	 * condición del cron (`days_remaining > max || days_remaining < min`,
	 * RemindersCron) es siempre verdadera y el T-7d muere sin error visible.
	 *
	 * @since 1.98.0
	 *
	 * @param array<int, string> $errors Errores previos de otros módulos.
	 * @param string             $tab    Tab activo del panel.
	 * @return array<int, string>
	 */
	public function validate_windows( array $errors, string $tab ): array {
		if ( self::PANEL_TAB_ID !== $tab ) {
			return $errors;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- AdminPanel verifica el nonce antes de este filter.
		$raw_max = isset( $_POST[ self::OPT_T7_WINDOW_MAX ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_T7_WINDOW_MAX ] ) ) : null;
		$raw_min = isset( $_POST[ self::OPT_T7_WINDOW_MIN ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::OPT_T7_WINDOW_MIN ] ) ) : null;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Solo validar si el sub-tab posteado incluye ambos campos (los
		// sub-tabs fechas/cambio-fecha postean otros ids y no deben bloquear).
		if ( null === $raw_max || null === $raw_min ) {
			return $errors;
		}

		$max = (int) $raw_max;
		$min = (int) $raw_min;

		if ( $max < 1 || $max > 30 ) {
			$errors[] = 'Recordatorio: la ventana máxima debe estar entre 1 y 30 días.';
		}
		if ( $min < 0 || $min > 14 ) {
			$errors[] = 'Recordatorio: la ventana mínima debe estar entre 0 y 14 días.';
		}
		if ( $min > $max ) {
			$errors[] = sprintf(
				'Recordatorio: la ventana mínima (%d) no puede ser mayor que la máxima (%d) — con esa combinación el email previo a la llegada no se enviaría nunca.',
				$min,
				$max
			);
		}

		return $errors;
	}

	/**
	 * Handler del cambio del toggle T-7d.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 * @return void
	 */
	public function on_t7_enabled_change( $old_value, $new_value ): void {
		if ( 'yes' === (string) $new_value ) {
			if ( ! wp_next_scheduled( RemindersCron::HOOK ) ) {
				$cron = new RemindersCron();
				$cron->maybe_schedule();
			}
		} else {
			$timestamp = wp_next_scheduled( RemindersCron::HOOK );
			if ( false !== $timestamp ) {
				wp_unschedule_event( $timestamp, RemindersCron::HOOK );
			}
		}
	}
}
