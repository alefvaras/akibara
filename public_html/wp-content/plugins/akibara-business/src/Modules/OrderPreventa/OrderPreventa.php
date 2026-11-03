<?php
/**
 * Akibara OrderPreventa — identifica órdenes con productos en preventa al
 * momento de la compra y los rastrea hasta que están "listos para despachar".
 *
 * Resuelve el problema operacional de "¿qué pedidos están esperando preventa?"
 * persistiendo un snapshot inmutable en line items + agregación denormalizada
 * en order, ambos compatibles HPOS. Reusa `DateCalculator` del módulo
 * EstimatedDelivery — single source of truth para "es preventa" y para el ETA
 * capturado al checkout.
 *
 * Sub-componentes:
 *   - MetaKeys             — constants centralizadas.
 *   - LineItemSnapshot     — hook checkout_create_order_line_item.
 *   - OrderTagging         — hook checkout_create_order (agregación).
 *   - ReadyToShipCron      — cron daily que flippea ready_to_ship.
 *   - ShippableStamp       — sella cuándo el pedido se volvió despachable de verdad.
 *   - AdminColumn          — columna "Preventa" en lista de órdenes (HPOS).
 *   - AdminFilter          — dropdown filter por estado de preventa.
 *   - OrderItemDisplay     — info por línea en vista detalle orden admin.
 *   - CustomerVisibility   — 4 superficies cliente (email + my-account orders
 *                            list + my-account view-order + PDF heredado).
 *   - ReadyEmailDispatcher — agendado, reintento y reenvío del aviso «lista».
 *   - WpCli                — back-fill + trigger manual del cron.
 *
 * El evento `akb_order_preventa_marked_ready` se dispara en cada flip y
 * `ReadyEmailDispatcher` lo enruta a una acción única por pedido para el email
 * cliente. Esta clase es SOLO la raíz de composición: instancia, ordena el
 * registro y expone accesores — no tiene comportamiento propio.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Contracts\EtaProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Orquestador del módulo. Recibe el `EtaProvider` (DateCalculator de
 * EstimatedDelivery) y compone los sub-componentes.
 *
 * @since 1.14.0
 */
final class OrderPreventa {

	private LineItemSnapshot $line_item_snapshot;
	private OrderTagging $order_tagging;
	private ReadyToShipCron $cron;
	private RemindersCron $reminders_cron;
	private ReleaseDateChangeListener $release_date_listener;
	private StockChangeListener $stock_listener;
	private RefundListener $refund_listener;
	private CarrierHandoffMonitor $carrier_handoff_monitor;
	private CompletionPolicy $completion_policy;
	private ShippableStamp $shippable_stamp;
	private PreventasScreen $preventas_screen;

	private CompletionBlockedNotice $completion_blocked_notice;
	private AdminColumn $admin_column;
	private AdminFilter $admin_filter;
	private OrderItemDisplay $order_item_display;
	private CustomerVisibility $customer_visibility;
	private WpCli $wp_cli;
	private Settings $settings;
	private PreventaUX $preventa_ux;
	private ReadyEmailDispatcher $ready_email_dispatcher;

	/**
	 * @param EtaProvider $calculator Provider de ETA (DateCalculator de EstimatedDelivery).
	 */
	public function __construct( EtaProvider $calculator ) {
		$recalculator                 = new SummaryRecalculator( $calculator );
		$this->line_item_snapshot     = new LineItemSnapshot( $calculator );
		$this->order_tagging          = new OrderTagging( $recalculator, $this->line_item_snapshot );
		$this->cron                   = new ReadyToShipCron( $recalculator );
		$this->preventas_screen       = new PreventasScreen( $this->cron );
		$this->reminders_cron         = new RemindersCron();
		$this->release_date_listener  = new ReleaseDateChangeListener( $recalculator );
		$this->stock_listener         = new StockChangeListener( $this->cron );
		$this->refund_listener        = new RefundListener( $this->cron );
		$this->carrier_handoff_monitor = new CarrierHandoffMonitor();
		$this->completion_policy       = new CompletionPolicy();
		$this->shippable_stamp         = new ShippableStamp();
		$this->completion_blocked_notice = new CompletionBlockedNotice();
		$this->admin_column           = new AdminColumn();
		$this->admin_filter           = new AdminFilter();
		$this->order_item_display     = new OrderItemDisplay();
		$this->customer_visibility    = new CustomerVisibility();
		$this->wp_cli                 = new WpCli( $calculator, $this->cron, $recalculator, $this->line_item_snapshot );
		$this->settings               = new Settings();
		$this->preventa_ux            = new PreventaUX();
		$this->ready_email_dispatcher = new ReadyEmailDispatcher();
	}

	/**
	 * Registra los componentes en todos los contextos que emiten sus hooks.
	 *
	 * Los callbacks administrativos sólo se ejecutan en sus pantallas correspondientes.
	 * CompletionBlockedNotice también atiende cambios de estado desde frontend,
	 * REST y Action Scheduler. Settings escucha update_option_akb_preventa_t7_enabled
	 * para reagendar RemindersCron incluso desde CLI o REST. Por eso su registro no
	 * depende de is_admin(). WpCli::register() aplica su propia condición WP_CLI.
	 */
	public function register(): void {
		$this->line_item_snapshot->register();
		$this->order_tagging->register();
		$this->cron->register();
		$this->reminders_cron->register();
		$this->release_date_listener->register();
		$this->stock_listener->register();
		$this->refund_listener->register();
		$this->carrier_handoff_monitor->register();
		$this->completion_policy->register();
		$this->shippable_stamp->register();
		$this->completion_blocked_notice->register();
		$this->admin_column->register();
		$this->admin_filter->register();
		$this->order_item_display->register();
		$this->customer_visibility->register();
		$this->wp_cli->register();
		$this->settings->register();
		$this->preventa_ux->register();
		$this->ready_email_dispatcher->register();
		// La pantalla «Por llegar» del panel. Sólo cuelga de admin_post_* y
		// admin_notices: en el frontend no añade nada.
		$this->preventas_screen->register();

		// Registrar los emails custom en el mailer de WooCommerce.
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email_classes' ) );

		// Los recordatorios siguen la cola transaccional nativa: WC bootea el
		// mailer antes de re-disparar el hook con sufijo `_notification`.
		// El email "preventa lista" queda fuera de esta lista: su dispatcher
		// dedicado usa una acción única por pedido para serializar cron y restock.
		add_filter(
			'woocommerce_email_actions',
			static function ( array $actions ): array {
				$actions[] = 'akb_order_preventa_t7_reminder';
				$actions[] = 'akb_order_preventa_date_changed';
				$actions[] = 'akb_order_preventa_date_announced';
				return $actions;
			}
		);
	}

	/**
	 * Inyecta los emails custom del módulo al mailer de WC.
	 *
	 * - `ReminderEmail`: recordatorio T-7d + notificación de postergación de fecha.
	 * - `ReadyToShipEmail`: cuando preventa pasa a "lista para despachar".
	 *
	 * `ShippedEmail` (email "despachado" + metabox de tracking manual) se retiró en
	 * CUT-7 Fase 2 (): dependía de `*_to_completed` y no representaba el
	 * flujo operativo real. El aviso "ya llegó / se despacha" lo cubre
	 * `ReadyToShipEmail` en los estados que declara cada integración de fulfillment.
	 *
	 * @param array<string, \WC_Email> $emails
	 * @return array<string, \WC_Email>
	 */
	public function register_email_classes( array $emails ): array {
		$emails[ ReminderEmail::EMAIL_ID ]    = new ReminderEmail();
		$emails[ ReadyToShipEmail::EMAIL_ID ] = new ReadyToShipEmail();
		return $emails;
	}

	/**
	 * Accessor del cron — público para tests y WP-CLI externo.
	 *
	 * @return ReadyToShipCron
	 */
	/**
	 * La pantalla «Por llegar» del panel, para registrarla como sección.
	 *
	 * @since 1.114.5
	 *
	 * @return PreventasScreen
	 */
	public function preventas_screen(): PreventasScreen {
		return $this->preventas_screen;
	}

	public function cron(): ReadyToShipCron {
		return $this->cron;
	}

	/**
	 * Settings array para `AdminPanel::add_section` — pestaña Preventa del
	 * panel central Akibara.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->settings->get_panel_settings();
	}

	/**
	 * Header callback para `AdminPanel::add_section($cb_header)` — renderiza
	 * sub-tab nav antes del form. Desde 1.22.0 el panel Preventa agrupa los
	 * 5 sub-temas (fechas/recordatorio/cambio-fecha/bloqueo/stale).
	 *
	 * @return void
	 */
	public function render_panel_header(): void {
		$this->settings->render_subtab_nav();
	}

	/**
	 * Accessor del Settings — usado por `Plugin::bootstrap()` para inyectar
	 * el módulo `EstimatedDelivery` y delegar el sub-tab "fechas".
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}
}
