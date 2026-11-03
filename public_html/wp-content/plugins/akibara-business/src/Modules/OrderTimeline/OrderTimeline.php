<?php
/**
 * OrderTimeline — Progress visual del pedido en /mi-cuenta/view-order/{id}/.
 *
 * Cierra el gap de soporte "¿cuándo llega?" — el dolor recurrente en
 * pedidos con preventa donde el cliente queda en silencio entre
 * `on-hold/processing` y la entrega real.
 *
 * Estrategia:
 *   - NO crear statuses custom. Mapeamos los 7 estados WC nativos
 *     (pending/processing/on-hold/completed/cancelled/refunded/failed)
 *     a 4 nodos visuales: "Pago confirmado" → "Preparando" →
 *     "Despachado" → "Entregado".
 *   - Si el pedido tiene líneas en espera (meta `_akb_eta_has_preventa = yes`
 *     y alguna sin reembolsar) inyectamos un 5to nodo entre "Pago confirmado"
 *     y "Preparando", con la fecha estimada de llegada. Su nombre es la
 *     palabra del pedido —«Preventa» o «A pedido»— que decide PreventaItems.
 *   - Estados terminales no-progresivos (cancelled/refunded/failed)
 *     renderizan un banner alternativo, no el timeline lineal.
 *
 * Hook: `woocommerce_order_details_before_order_table` priority 5 — antes
 * del banner de OrderPreventa (que va after-table) y antes de la tabla
 * de productos. El cliente ve el timeline ni bien abre el pedido.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderTimeline;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Modules\OrderPreventa\PreventaItems;
use Akibara\Business\Modules\OrderPreventa\SummaryRecalculator;
use Akibara\Business\Utilities\StoreTime;

defined( 'ABSPATH' ) || exit;

final class OrderTimeline {

	public function register(): void {
		add_action( 'woocommerce_order_details_before_order_table', array( $this, 'render' ), 5, 1 );
	}

	/**
	 * Render del timeline o del banner terminal según estado.
	 *
	 * @param \WC_Order $order
	 */
	public function render( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$status = $order->get_status();

		// Estados terminales no-progresivos — banner alternativo, no timeline.
		if ( in_array( $status, array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$this->render_terminal_state( $order, $status );
			return;
		}

		// Timeline normal.
		$is_preventa = 'yes' === (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA );
		$steps       = $this->build_steps( $order, $is_preventa );

		?>
		<section class="akb-timeline" aria-label="Estado del pedido">
			<ol class="akb-timeline__steps">
				<?php foreach ( $steps as $i => $step ) :
					$state_class = 'akb-timeline__step--' . $step['state'];
					$aria_current = 'current' === $step['state'] ? ' aria-current="step"' : '';
					?>
					<li class="akb-timeline__step <?php echo esc_attr( $state_class ); ?>"<?php echo $aria_current; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $aria_current es uno de dos literales fijados dos líneas arriba, sin dato de entrada; escaparlo imprimiría el atributo como texto. ?>>
						<span class="akb-timeline__step-marker" aria-hidden="true">
							<?php if ( 'done' === $step['state'] ) : ?>
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
							<?php else : ?>
								<?php echo (int) ( $i + 1 ); ?>
							<?php endif; ?>
						</span>
						<span class="akb-timeline__step-label"><?php echo esc_html( $step['label'] ); ?></span>
						<?php if ( ! empty( $step['hint'] ) ) : ?>
							<span class="akb-timeline__step-hint"><?php echo esc_html( $step['hint'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * Banner para pedidos en estado terminal no-progresivo.
	 */
	private function render_terminal_state( \WC_Order $order, string $status ): void {
		$labels = array(
			'cancelled' => array(
				'title' => 'Pedido cancelado',
				'desc'  => 'Este pedido fue cancelado. Si lo pagaste, te devolvemos el monto por el mismo método.',
			),
			'refunded'  => array(
				'title' => 'Pedido reembolsado',
				'desc'  => 'Te devolvimos el pago de este pedido.',
			),
			'failed'    => array(
				'title' => 'Pago no completado',
				// El texto de atención mantiene el español de Chile usado por la tienda.

				'desc'  => 'El pago no llegó a procesarse. Si ya transferiste, escríbenos por WhatsApp con el comprobante.',
			),
		);

		$copy = $labels[ $status ] ?? $labels['cancelled'];
		?>
		<section class="akb-timeline akb-timeline--terminal akb-timeline--<?php echo esc_attr( $status ); ?>">
			<p class="akb-timeline__terminal-title"><?php echo esc_html( $copy['title'] ); ?></p>
			<p class="akb-timeline__terminal-desc"><?php echo esc_html( $copy['desc'] ); ?></p>
		</section>
		<?php
	}

	/**
	 * Construye el timeline según el estado real del pedido.
	 *
	 * Cada paso contiene label, state (done/current/pending) y un hint opcional.
	 * Pending y on-hold permanecen en pago; processing prepara despacho;
	 * shipping-progress usa el paso final operativo y completed lo completa.
	 * Una espera reservable inserta su paso con PreventaItems::order_label().
	 * El estado interno checkout-draft no forma parte de la vista del cliente.
	 *
	 * @param \WC_Order $order
	 * @param bool      $is_preventa
	 * @return array<int, array{label:string, state:string, hint?:string}>
	 */
	private function build_steps( \WC_Order $order, bool $is_preventa ): array {
		$status = $order->get_status();

		// Mismo portero que el cartel y la línea (`should_render_preventa_status`):
		// sin líneas en espera vigentes no hay etapa que mostrar. La bandera del
		// pedido nunca se apaga, así que un pedido cuya única reserva se
		// reembolsó seguía pintando «En preventa» para siempre (#27760, #27782).
		$is_preventa = $is_preventa && PreventaItems::has_outstanding_items( $order );

		$shipped = 'shipping-progress' === $status;
		$sealed  = $shipped || 'completed' === $status; // Etapas previas cerradas.

		// La espera termina cuando la mercancía llegó, aunque el pedido continúe
		// processing. Usar la misma señal de PreventaItems que cartel y línea; una ETA
		// conservada para traza no debe mantener un paso de espera activo en el timeline.

		$preventa_lista = 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP )
			|| PreventaItems::all_arrived( $order );

		// Step 1 — Pago: "current" si pending/on-hold, "done" si ya avanzó.
		$paid = $sealed || 'processing' === $status;

		$steps = array();

		$steps[] = array(
			'label' => $paid ? 'Pago confirmado' : 'Esperando pago',
			'state' => $paid ? 'done' : 'current',
			'hint'  => $paid && $order->get_date_paid() ? wc_format_datetime( $order->get_date_paid(), 'd \\d\\e M' ) : null,
		);

		// Step 2 — la espera (solo si aplica). "current" mientras no llega el
		// stock; "done" cuando el cron marcó el pedido listo. La palabra la decide
		// PreventaItems por cómo se compró: este nodo decía «En preventa» a fuego
		// a los 20 pedidos a pedido de la tienda, que nunca tuvieron una.
		if ( $is_preventa ) {
			$eta_hint = $this->preventa_eta_hint( $order );
			if ( $sealed || ( $paid && $preventa_lista ) ) {
				$state = 'done';
			} elseif ( $paid ) {
				$state = 'current';
			} else {
				$state = 'pending';
			}
			$steps[] = array(
				'label' => PreventaItems::order_label( $order ),
				'state' => $state,
				'hint'  => 'done' === $state ? null : $eta_hint,
			);
		}

		// Step "Preparando" — "current" si processing + (no preventa o preventa
		// resuelta). Akibara convención: processing significa "ya pagado y en
		// preparación de despacho". Si es preventa, el preparando viene después
		// del "En preventa" (no se solapan visualmente).
		$preparing_state = 'pending';
		if ( $sealed ) {
			$preparing_state = 'done';
		} elseif ( $paid && ( ! $is_preventa || $preventa_lista ) ) {
			// Con la preventa ya resuelta, el pedido está de verdad en preparación:
			// el paso actual pasa a ser éste y no uno que ya terminó.
			$preparing_state = 'current';
		}
		$steps[] = array(
			'label' => 'Preparando despacho',
			'state' => $preparing_state,
		);

		// Step final — "Despachado / Entregado". Convención Akibara: completed
		// marca despachado (no necesariamente entregado, porque el tracking del
		// courier no se confirma automático); shipping-progress (BlueExpress)
		// es el despacho EN CURSO → current. Copy honesto.
		if ( $shipped ) {
			$final_state = 'current';
		} elseif ( 'completed' === $status ) {
			$final_state = 'done';
		} else {
			$final_state = 'pending';
		}
		// El nombre del paso coincide con el estado efectivo del pedido.
		// shipping-progress significa Listo para enviar y no confirma una salida física.

		$steps[] = array(
			'label' => $shipped ? 'Listo para enviar' : 'Despachado',
			'state' => $final_state,
			'hint'  => 'completed' === $status && $order->get_date_completed() ? wc_format_datetime( $order->get_date_completed(), 'd \\d\\e M' ) : null,
		);

		return $steps;
	}

	/**
	 * Hint de ETA del paso "En preventa". ÚNICA fuente: el estimado VIGENTE
	 * de la orden vía el lector canónico `SummaryRecalculator::current_max_ts`.
	 *
	 *  El fallback histórico al snapshot inmutable de la fecha de
	 * lanzamiento se ELIMINÓ: el summary ahora es borrable por diseño
	 * (retractación de fecha → delete), y ese fallback habría RESUCITADO la
	 * fecha retirada mientras el resto de la página dice "sin fecha" — una
	 * segunda interpretación del meta, la clase de error que parió el Bug 1.
	 * Órdenes legacy sin summary se cubren con el back-fill (`WpCli::backfill`),
	 * no con un camino de lectura paralelo. Sin ese lector el meta quedó de sólo
	 * escritura y dejó de estamparse en 1.110.1; las filas históricas de
	 * `_akb_eta_release_date_at_purchase` que sobreviven en la base no las lee
	 * nadie.
	 *
	 * Retorna "Llega ~mes año", "Llegaba ~mes año · atrasado" si esa promesa
	 * ya venció, o "Fecha por confirmar" (mismo lenguaje que
	 * CustomerVisibility/AdminColumn) si no hay estimado vigente.
	 */
	private function preventa_eta_hint( \WC_Order $order ): ?string {
		// ORDER_SUMMARY_MAX es un timestamp Unix (int) — el lector canónico
		// normaliza ausente/''/0/negativo a null.
		$timestamp = SummaryRecalculator::current_max_ts( $order );

		if ( null === $timestamp ) {
			return 'Fecha por confirmar';
		}

		// "~mes año" — vaguedad intencional para no comprometerse a fecha exacta.
		// wp_date() interpreta el timestamp en la tz del sitio (America/Santiago), no
		// en UTC del runtime (gmdate) — tripwire #2: el mes/anio lo ve el cliente.
		$month_idx = (int) wp_date( 'n', $timestamp );
		$year      = wp_date( 'Y', $timestamp );
		$cuando    = PedidoPolicy::mes( $month_idx ) . ' ' . $year;

		// Mismo criterio que el cartel: una promesa vencida no es una previsión.
		return $timestamp < StoreTime::start_of_today() ? 'Llegaba ~' . $cuando . ' · atrasado' : 'Llega ~' . $cuando;
	}
}
