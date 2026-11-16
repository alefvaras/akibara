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
 *   - Estados terminales no-progresivos (cancelled/refunded/failed)
 *     renderizan un banner alternativo, no el timeline lineal.
 *
 * Hook: `woocommerce_order_details_before_order_table` priority 5 — antes
 * de la tabla de productos. El cliente ve el timeline ni bien abre el pedido.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderTimeline;

use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * @api
 * @psalm-suppress HookNotFound
 */
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
		$steps = $this->build_steps( $order );

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
								<?php echo (int) $i + 1; ?>
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
	 * @param \WC_Order $order
	 * @return array<int, array{label:string, state:string, hint?:string|null}>
	 */
	private function build_steps( \WC_Order $order ): array {
		$status  = $order->get_status();
		$shipped = 'shipping-progress' === $status;
		$sealed  = $shipped || 'completed' === $status;
		$paid    = $sealed || 'processing' === $status;

		$steps = array();

		// Paso 1: Pago
		$steps[] = array(
			'label' => $paid ? 'Pago confirmado' : 'Esperando pago',
			'state' => $paid ? 'done' : 'current',
			'hint'  => $paid && $order->get_date_paid() ? wc_format_datetime( $order->get_date_paid(), 'd \\d\\e M' ) : null,
		);

		// Paso 2: Preparando
		$preparing_state = 'pending';
		if ( $sealed ) {
			$preparing_state = 'done';
		} elseif ( $paid ) {
			$preparing_state = 'current';
		}
		$steps[] = array(
			'label' => 'Preparando despacho',
			'state' => $preparing_state,
		);

		// Paso 3: Despachado
		if ( $shipped ) {
			$final_state = 'current';
		} elseif ( 'completed' === $status ) {
			$final_state = 'done';
		} else {
			$final_state = 'pending';
		}

		$steps[] = array(
			'label' => $shipped ? 'Listo para enviar' : 'Despachado',
			'state' => $final_state,
			'hint'  => 'completed' === $status && $order->get_date_completed() ? wc_format_datetime( $order->get_date_completed(), 'd \\d\\e M' ) : null,
		);

		return $steps;
	}
}
