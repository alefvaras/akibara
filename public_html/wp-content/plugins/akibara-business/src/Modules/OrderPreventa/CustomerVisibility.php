<?php
/**
 * Visibilidad cliente del estado de preventa en 4 superficies WooCommerce:
 *
 *   1) Email transaccional (procesando / on-hold):
 *      - Banner global "Tu pedido incluye preventa..." después de la tabla.
 *        No se pinta en `completed` ni tras la entrega al courier: el estado
 *        de WooCommerce ya cuenta esa parte.
 *      - Línea por item bajo el nombre con ETA + país (esta sí queda como
 *        traza en `completed`).
 *
 *   2) `/mi-cuenta/orders/` — listado de pedidos del cliente:
 *      - Columna "Llegada" con badge (📦 Llega ~5 jun  /  ✓ Lista  /  —).
 *
 *   3) `/mi-cuenta/view-order/{id}/` — detalle del pedido:
 *      - Mismo banner global después de la tabla.
 *      - Misma línea por item bajo el nombre.
 *
 *   4) PDF / impresiones que usan `wc_display_item_meta()` heredan
 *      automáticamente la línea per-item.
 *
 * Source of truth: `PreventaItems` (qué espera el cliente y qué fecha le
 * rige hoy) sobre los metas de line item / order que escribió LineItemSnapshot
 * + OrderTagging al checkout y que SummaryRecalculator mantiene al día.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\OrderPreventa;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Utilities\StoreTime;
use Akibara\Business\Utilities\Text;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Inyecta info de preventa en las superficies que ve el cliente.
 *
 * @since 1.14.1
 */
final class CustomerVisibility {

	private const COLUMN_ID = 'akb_preventa_llegada';

	/** El cartel cuando la promesa ya venció. `%s` es la fecha; se edita en Textos. */
	public const OPTION_OVERDUE  = 'akb_copy_eta_overdue';
	public const DEFAULT_OVERDUE = 'La fecha estimada era el %s y todavía no ha llegado: estamos detrás de la editorial. Te avisamos en cuanto entre.';

	public function register(): void {
		// Línea por item — gancho compartido por correo, «pedido recibido», «ver
		// pedido» y «pagar pedido». El detalle del pedido en el escritorio va por
		// otro gancho (OrderItemDisplay), así que no hay doble render que evitar.
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render_item_line' ), 10, 3 );

		// Banner global — email only.
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email_banner' ), 10, 4 );

		// Banner global — customer view-order only.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_banner' ), 10, 1 );

		// Columna en /mi-cuenta/orders/.
		add_filter( 'woocommerce_account_orders_columns', array( $this, 'register_orders_column' ) );
		add_action( 'woocommerce_my_account_my_orders_column_' . self::COLUMN_ID, array( $this, 'render_orders_column' ) );
	}

	/**
	 * Muestra la espera de la línea en correo y en Mi cuenta.
	 *
	 * No se condiciona a is_admin(): el mailer puede renderizar desde administración,
	 * cron o checkout. El estado comercial y la fecha se resuelven por la línea para
	 * mantener la misma promesa en todas las superficies del pedido.
	 *
	 * @param int           $item_id  Order item id.
	 * @param WC_Order_Item $item     Order item.
	 * @param WC_Order      $order    Order contenedora.
	 * @return void
	 */
	public function render_item_line( $item_id, $item, $order ): void {
		unset( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		// La LÍNEA se queda también en pedidos ya despachados o completados: es
		// la traza de procedencia («Ya llegó a nuestra bodega · Argentina») que
		// el cliente conserva en su historial y no promete nada. El CARTEL, que
		// sí promete («sale en los próximos X»), tiene su propio portero.
		if ( ! $order instanceof WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return;
		}
		if ( ! PreventaItems::is_outstanding_item( $order, $item ) ) {
			return;
		}

		echo '<div class="akb-eta-badge" style="margin-top:6px;font-size:0.9em;color:#555;">';
		printf( '📦 <strong>%s</strong> · ', esc_html( PreventaItems::item_label( $item ) ) );
		echo esc_html( $this->item_status_text( $order, $item ) );
		echo '</div>';
	}

	/**
	 * Presenta la promesa vigente de la línea y su estado de llegada.
	 *
	 * Mientras se espera el tomo usa PreventaItems::promised_window: la ventana
	 * recalculada o, si no cambió, la de compra. Cuando el tomo llegó informa esa
	 * llegada en lugar de una fecha futura. El snapshot de compra conserva la traza
	 * para administración. El país de procedencia se mantiene en ambas ramas.
	 *
	 * @since 1.114.12
	 *
	 * @param WC_Order              $order Pedido.
	 * @param WC_Order_Item_Product $item  Línea.
	 * @return string
	 */
	private function item_status_text( WC_Order $order, WC_Order_Item_Product $item ): string {
		// Un pedido completado o entregado al courier SALIÓ: la línea no puede
		// depender del stock de hoy (el tomo vuelve a «a pedido» tras venderse)
		// ni decir «Llegaba entre el 2 y el 5 de junio» sobre algo entregado.
		if ( ! $this->order_left( $order ) && ! PreventaItems::has_arrived( $order, $item ) ) {
			return $this->item_eta_text( $item );
		}

		$arrived = Text::one_line_option( 'akb_copy_eta_item_arrived', 'Ya llegó a nuestra bodega' );
		$country = $this->item_country_name( $item );

		return '' !== $country ? sprintf( '%s · %s', $arrived, $country ) : $arrived;
	}

	/**
	 * País de procedencia del snapshot; slug capitalizado si no se capturó el nombre.
	 *
	 * @param WC_Order_Item_Product $item Línea.
	 * @return string
	 */
	private function item_country_name( WC_Order_Item_Product $item ): string {
		$country_name = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_NAME_AT_PURCHASE );
		if ( '' === $country_name ) {
			$slug         = (string) $item->get_meta( MetaKeys::ITEM_COUNTRY_AT_PURCHASE );
			$country_name = '' !== $slug ? ucfirst( $slug ) : '';
		}
		return $country_name;
	}

	/**
	 * Banner global en email después de la tabla. Solo si la orden tiene preventa.
	 *
	 * Firma WC: `do_action('woocommerce_email_after_order_table', $order, $sent_to_admin, $plain_text, $email)`.
	 *
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param mixed    $email
	 * @return void
	 */
	public function render_email_banner( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );

		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// El email a admin no necesita el banner cliente — ya tiene la columna admin.
		if ( $sent_to_admin ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( ! $this->should_render_preventa_status( $order ) ) {
			return;
		}

		if ( $plain_text ) {
			echo "\n\n" . $this->banner_text( $order ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cuerpo del correo en TEXTO PLANO: escaparlo metería entidades HTML en lo que lee el cliente.
			return;
		}

		$this->render_html_banner( $order );
	}

	/**
	 * Banner global en /mi-cuenta/view-order/{id}/.
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	public function render_customer_banner( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			return;
		}
		if ( ! $this->should_render_preventa_status( $order ) ) {
			return;
		}
		$this->render_html_banner( $order );
	}

	/**
	 * Registra columna "Llegada" en /mi-cuenta/orders/.
	 *
	 * @param array<string, string> $columns map column_id → label.
	 * @return array<string, string>
	 */
	public function register_orders_column( array $columns ): array {
		$out = array();
		foreach ( $columns as $id => $label ) {
			$out[ $id ] = $label;
			if ( 'order-status' === $id ) {
				$out[ self::COLUMN_ID ] = 'Llegada';
			}
		}
		if ( ! isset( $out[ self::COLUMN_ID ] ) ) {
			$out[ self::COLUMN_ID ] = 'Llegada';
		}
		return $out;
	}

	/**
	 * Renderea celda de la columna "Llegada" para cada fila.
	 *
	 * Hook recibe el `WC_Order` actual vía `$wp_query->post` en el template
	 * nativo (orders.php usa `wc_get_order( $customer_order)`). El order
	 * actual está en `global $woocommerce->order` o lo recuperamos del `$_REQUEST`.
	 * WC pasa el order como parámetro implícito al hook — accedemos via wc().
	 *
	 * @param WC_Order|null $order WC pasa el order al action handler.
	 * @return void
	 */
	public function render_orders_column( $order = null ): void {
		// WC dispara este action con `$order` como argumento implícito en orders.php
		// (línea típica: `do_action( "woocommerce_my_account_my_orders_column_{$column_id}", $customer_order);`).
		// Si no llega el order, lo intentamos resolver del scope global.
		if ( ! $order instanceof WC_Order ) {
			global $post;
			if ( $post && function_exists( 'wc_get_order' ) ) {
				$resolved = wc_get_order( $post->ID );
				if ( $resolved instanceof WC_Order ) {
					$order = $resolved;
				}
			}
		}
		if ( ! $order instanceof WC_Order ) {
			echo '—';
			return;
		}

		if ( 'yes' !== (string) $order->get_meta( MetaKeys::ORDER_HAS_PREVENTA ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		if ( ! $this->should_render_preventa_status( $order ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}

		if ( $this->is_ready( $order ) ) {
			echo '<span style="color:#1e7e34;font-weight:600;">✓ Lista para despachar</span>';
			return;
		}

		$summary_max = SummaryRecalculator::current_max_ts( $order );
		if ( null !== $summary_max ) {
			// Mismo criterio que el cartel: una promesa vencida no se lee como
			// previsión («Llega ~25 ago» en septiembre).
			// Mes corto de la casa («14 dic»), no el de WordPress («14 Dic»,
			// con mayúscula y dependiente del idioma cargado).
			$fecha_corta = sprintf(
				'%d %s',
				(int) wp_date( 'j', $summary_max ),
				mb_substr( PedidoPolicy::mes( (int) wp_date( 'n', $summary_max ) ), 0, 3 )
			);
			echo '<span style="color:#856404;">📦 ' . esc_html(
				$summary_max < StoreTime::start_of_today()
					? sprintf( 'Llegaba ~%s · atrasado', $fecha_corta )
					: sprintf( 'Llega ~%s', $fecha_corta )
			) . '</span>';
			return;
		}

		printf(
			'<span style="color:#856404;">📦 %s · sin fecha</span>',
			esc_html( PreventaItems::order_label( $order ) )
		);
	}

	/**
	 * Texto plain del banner (para emails plain-text + reuso interno).
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function banner_text( WC_Order $order ): string {
		if ( $this->is_ready( $order ) ) {
			// «Tu pedido» y no «tu preventa»: sirve para los dos casos y evita el
			// lista/listo. La palabra correcta de cada tipo ya la da cada línea.
			return sprintf(
				'Tu pedido está listo para despachar. Sale en los próximos %s.',
				ShipPromise::window()
			);
		}

		$lines = array( sprintf( 'Tu pedido incluye %s.', PreventaItems::order_noun( $order ) ) );

		$summary_max = SummaryRecalculator::current_max_ts( $order );
		if ( null !== $summary_max ) {
			$lines[] = $this->promise_sentence( $summary_max );
		}

		$lines[] = 'Te avisaremos por correo cuando los productos lleguen a nuestra bodega y estén listos para despachar.';

		return implode( "\n", $lines );
	}

	/**
	 * La frase de la fecha estimada del cartel, honesta también cuando ya pasó.
	 *
	 * Cuando la editorial se atrasa, la promesa se conserva vencida a propósito
	 * (SummaryRecalculator): el cliente no puede leer «estimamos que llega el 1
	 * de octubre» un 20 de octubre como si fuera una previsión vigente.
	 *
	 * @since 1.114.16
	 *
	 * @param int $summary_max Arribo estimado vigente.
	 * @return string
	 */
	private function promise_sentence( int $summary_max ): string {
		if ( $summary_max < StoreTime::start_of_today() ) {
			// Texto del dueño: NUNCA como formato de sprintf. Un «%» suelto
			// («estamos al 100%») tumbaba con fatal la página del pedido y los
			// correos de todo cliente con promesa vencida. Sin marcador, la
			// fecha va al final: es la única frase que explica el retraso.
			$copy  = Text::one_line_option( self::OPTION_OVERDUE, self::DEFAULT_OVERDUE );
			$fecha = $this->long_date( $summary_max );

			return false !== strpos( $copy, '%s' )
				? str_replace( '%s', $fecha, $copy )
				: $copy . ' (era el ' . $fecha . ')';
		}

		return sprintf( 'Estimamos que el último producto llega el %s.', $this->long_date( $summary_max ) );
	}

	/**
	 * ¿Se puede decir «listo para despachar»? Cuando el cron lo marcó, o cuando
	 * todo lo que se esperaba ya está en bodega aunque el cron no haya pasado
	 * (stock cargado sin el hook). Es la misma señal que usa la línea por
	 * producto; decidir aquí sólo por la bandera del pedido hacía que la línea
	 * dijera «Ya llegó» y el cartel «estimamos que llega el X».
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function is_ready( WC_Order $order ): bool {
		if ( 'yes' === (string) $order->get_meta( MetaKeys::ORDER_READY_TO_SHIP ) ) {
			return true;
		}
		// «Listo para despachar» sólo con el pedido PAGADO y en curso: un
		// pedido a la espera del pago (transferencia sin confirmar, Webpay
		// abandonado) con el tomo ya en bodega no sale sin pagar, y el cron
		// tampoco lo marca.
		return $order->has_status( FulfillmentCapabilities::active_order_statuses( $order ) )
			&& PreventaItems::all_arrived( $order );
	}

	/**
	 * ¿El pedido ya salió de la tienda? Completado o entregado al courier: el
	 * estado de WooCommerce lo prueba, sin mirar el stock de hoy.
	 *
	 * @since 1.114.16
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private function order_left( WC_Order $order ): bool {
		return $order->has_status( array_merge( array( 'completed' ), FulfillmentCapabilities::carrier_handoff_statuses( $order ) ) );
	}

	/**
	 * Portero del CARTEL y de la columna. Cancelado/reembolsado/fallido no
	 * prometen nada; un pedido ya entregado al courier o completado tampoco
	 * pinta cartel («listo para despachar, sale en X días» salía en el correo
	 * del código de seguimiento), porque el estado de WooCommerce ya cuenta esa
	 * parte. La LÍNEA por producto tiene su propio portero en
	 * `render_item_line()`: se conserva como traza en el historial.
	 *
	 * @param WC_Order $order Orden a evaluar.
	 * @return bool
	 */
	private function should_render_preventa_status( WC_Order $order ): bool {
		return ! $order->has_status( array( 'cancelled', 'refunded', 'failed' ) )
			&& ! $this->order_left( $order )
			&& PreventaItems::has_outstanding_items( $order );
	}

	/**
	 * Render del banner HTML (email + customer view-order). Estilo inline para
	 * sobrevivir al inliner CSS de WC y a clientes de email restrictivos.
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function render_html_banner( WC_Order $order ): void {
		$is_ready    = $this->is_ready( $order );
		$summary_max = SummaryRecalculator::current_max_ts( $order );

		$bg     = $is_ready ? '#d4edda' : '#fff3cd';
		$border = $is_ready ? '#28a745' : '#ffc107';
		$icon   = $is_ready ? '✓' : '📦';
		$title  = $is_ready
			? 'Tu pedido está listo para despachar'
			: sprintf( 'Tu pedido incluye %s', PreventaItems::order_noun( $order ) );
		?>
		<div class="akb-email-callout" style="margin: 20px 0; padding: 16px 20px; background: <?php echo esc_attr( $bg ); ?>; border: 1px solid <?php echo esc_attr( $border ); ?>; border-top: 3px solid <?php echo esc_attr( $border ); ?>; border-radius: 6px;">
			<p style="margin: 0 0 8px 0; font-weight: 600; font-size: 1.05em;">
				<?php echo esc_html( $icon ); ?> <?php echo esc_html( $title ); ?>
			</p>
			<?php if ( $is_ready ) : ?>
				<p style="margin: 0; font-size: 0.95em; line-height: 1.5;">
					Sale en los próximos <?php echo esc_html( ShipPromise::window() ); ?>.
				</p>
			<?php else : ?>
				<?php if ( null !== $summary_max ) : ?>
					<p style="margin: 0 0 6px 0; font-size: 0.95em; line-height: 1.5;">
						<?php echo esc_html( $this->promise_sentence( $summary_max ) ); ?>
					</p>
				<?php endif; ?>
				<p style="margin: 0; font-size: 0.95em; line-height: 1.5; color: #555;">
					Te avisaremos por correo cuando los productos lleguen a nuestra bodega y estén listos para despachar.
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Texto ETA del line item para cliente:
	 *   - Range concreto → "Llega entre el 2 y el 5 de junio · Argentina"
	 *   - TBA            → "Sin fecha confirmada · España"
	 *
	 * @param WC_Order_Item_Product $item
	 * @return string
	 */
	private function item_eta_text( WC_Order_Item_Product $item ): string {
		// Usar la ventana vigente de PreventaItems, o la de compra si no cambió,
		// para que línea y cartel presenten la misma promesa.

		$ventana      = PreventaItems::promised_window( $item );
		$min_ts       = (int) ( $ventana['min'] ?? 0 );
		$max_ts       = (int) ( $ventana['max'] ?? 0 );
		$country_name = $this->item_country_name( $item );

		// Sin ventana vigente (se compró sin fecha, o la editorial la retiró):
		// no se inventa una.
		if ( $min_ts <= 0 && $max_ts <= 0 ) {
			return '' !== $country_name
				? sprintf( 'Sin fecha confirmada · %s', $country_name )
				: 'Sin fecha confirmada';
		}

		// Con la ventana ya pasada, la línea deja de hablar en presente: «Llega
		// entre el 8 y el 14 de octubre» un 20 de octubre, dos renglones bajo
		// un cartel que reconoce el retraso, se leía como si el cartel mintiera.
		$range = $this->date_range_text( $min_ts, $max_ts, $max_ts < StoreTime::start_of_today() ? 'Llegaba' : 'Llega' );

		return '' !== $country_name
			? sprintf( '%s · %s', $range, $country_name )
			: $range;
	}

	/**
	 * Formato "Llega entre el 2 y el 5 de junio" / "Llega el 5 de junio" si min=max.
	 *
	 * @param int    $min_ts
	 * @param int    $max_ts
	 * @param string $verbo  «Llega» mientras la ventana está viva; «Llegaba» cuando ya pasó.
	 * @return string
	 */
	private function date_range_text( int $min_ts, int $max_ts, string $verbo = 'Llega' ): string {
		$min_day   = (int) wp_date( 'j', $min_ts );
		$max_day   = (int) wp_date( 'j', $max_ts );
		$min_month = (int) wp_date( 'n', $min_ts );
		$max_month = (int) wp_date( 'n', $max_ts );

		$max_month_name = PedidoPolicy::mes( $max_month );

		if ( $min_day === $max_day && $min_month === $max_month ) {
			return sprintf( '%s el %d de %s', $verbo, $max_day, $max_month_name );
		}

		if ( $min_month === $max_month ) {
			return sprintf( '%s entre el %d y el %d de %s', $verbo, $min_day, $max_day, $max_month_name );
		}

		$min_month_name = PedidoPolicy::mes( $min_month );
		return sprintf(
			'%s entre el %d de %s y el %d de %s',
			$verbo,
			$min_day,
			$min_month_name,
			$max_day,
			$max_month_name
		);
	}

	/**
	 * Formato "5 de junio" (sin "el").
	 *
	 * @param int $ts
	 * @return string
	 */
	private function long_date( int $ts ): string {
		$day   = (int) wp_date( 'j', $ts );
		$month = (int) wp_date( 'n', $ts );
		$name  = PedidoPolicy::mes( $month );
		return sprintf( '%d de %s', $day, $name );
	}
}
