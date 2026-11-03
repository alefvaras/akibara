<?php
/**
 * Series Bundles — Instrumentación del pedido (medición de Build_Your_Pack).
 *
 * Estampa order meta cuando un pedido usó BYP, para medir incrementalidad sin
 * revertir el hash del cupón (`akb_sb_byp_{md5(term_id)}` es opaco). Convierte
 * cada pregunta de análisis en un SQL trivial sobre `wp_postmeta`:
 *
 *   - `_akb_byp_used`         = 'yes'
 *   - `_akb_byp_discount_clp` = descuento BYP total (incl. impuestos), redondeado
 *   - `_akb_byp_series`       = CSV de term_ids `pa_serie` que dispararon BYP
 *   - `_akb_byp_distinct`     = tomos DISTINTOS comprables sumados de series BYP
 *   - `_akb_byp_tiers`        = JSON `{term_id: {name, distinct, pct}}`
 *
 * Hook `woocommerce_checkout_create_order` (prio 20): el carrito todavía tiene
 * el cupón virtual aplicado, así que se lee de ahí. Sin funciones i18n (Req 11.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

defined( 'ABSPATH' ) || exit;

final class OrderInstrumentation {

	public function __construct(
		private readonly VolumeTierResolver $tiers,
		private readonly CartSeriesCollector $collector
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'stamp_order_store_api' ), 20, 1 );
	}

	/**
	 * Checkout clásico: estampa la meta BYP antes de que WC persista la orden.
	 * WC la guarda tras el hook → sin save() propio.
	 *
	 * @param mixed $order Instancia de pedido (WC_Order) en construcción.
	 * @param mixed $data  Datos del checkout (no usado).
	 */
	public function stamp_order( $order, $data = array() ): void {
		unset( $data );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$this->apply( $order );
	}

	/**
	 * Checkout Blocks / Store API: el carrito sigue vivo cuando corre el hook (WC
	 * lo vacía después de procesar la orden), así que la instrumentación se lee
	 * igual del carrito. La orden ya está persistida → save() explícito si estampó.
	 *
	 * @param mixed $order Orden Store API ya procesada (WC_Order).
	 * @return void
	 *
	 * @since 1.71.0
	 */
	public function stamp_order_store_api( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $this->apply( $order ) ) {
			$order->save();
		}
	}

	/**
	 * Estampa la meta de BYP en el pedido si el carrito tiene cupones del módulo.
	 * Fuente-agnóstica de superficie: lee el carrito vivo, que existe tanto en el
	 * checkout clásico como en el de Blocks al momento de sus hooks respectivos.
	 *
	 * @param \WC_Order $order Pedido destino.
	 * @return bool True si estampó la instrumentación; false si no había cupones BYP.
	 *
	 * @since 1.71.0
	 */
	private function apply( \WC_Order $order ): bool {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return false;
		}

		$byp_codes  = array();
		$byp_hashes = array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$code = (string) $code;
			$hash = BundleCouponEngine::byp_hash_from_code( $code );
			if ( null !== $hash ) {
				$byp_codes[]          = $code;
				$byp_hashes[ $hash ] = true;
			}
		}
		if ( empty( $byp_codes ) ) {
			return false;
		}

		$discount = 0.0;
		foreach ( $byp_codes as $code ) {
			// `false` ⇒ el monto YA incluye impuestos (WC suma el IVA internamente).
			// No volver a sumar get_coupon_discount_tax_amount() o se contaría el IVA
			// dos veces (métrica inflada ~19%). Es solo instrumentación, no afecta lo
			// cobrado, pero igual debe ser correcta.
			$discount += (float) $cart->get_coupon_discount_amount( $code, false );
		}

		$series = $this->summarize_cart( $cart, array_keys( $byp_hashes ) );
		if ( $discount <= 0 || empty( $series ) ) {
			return false;
		}

		$total_distinct = 0;
		foreach ( $series as $s ) {
			$total_distinct += (int) $s['distinct'];
		}

		$order->update_meta_data( '_akb_byp_used', 'yes' );
		$order->update_meta_data( '_akb_byp_discount_clp', (string) (int) round( $discount ) );
		$order->update_meta_data( '_akb_byp_series', implode( ',', array_keys( $series ) ) );
		$order->update_meta_data( '_akb_byp_distinct', (string) $total_distinct );
		$order->update_meta_data( '_akb_byp_tiers', (string) wp_json_encode( $series ) );

		return true;
	}

	/**
	 * Resume sólo las series con cupón BYP realmente aplicado:
	 * `term_id => {name, distinct, pct}`.
	 * Delega la recolección en el `CartSeriesCollector` compartido con el motor
	 * (mismos guards: solo tomos comprables y simples, dedupe por product_id).
	 * Descarta DTOs de cantidad no positiva y tramos que resuelven 0%, de modo
	 * que un cupón obsoleto o ajeno a la serie no infle la instrumentación. El
	 * shape de salida de la meta `_akb_byp_*` no cambia.
	 *
	 * Si `$applied_hashes` es null, deriva los hashes desde los cupones aplicados
	 * del carrito. El argumento explícito permite que `apply()` use exactamente
	 * el mismo snapshot con que sumó los montos.
	 *
	 * @param \WC_Cart              $cart           Carrito vivo.
	 * @param array<int,string>|null $applied_hashes Hashes BYP aplicados.
	 * @return array<int, array{name:string, distinct:int, pct:int}>
	 */
	public function summarize_cart( \WC_Cart $cart, ?array $applied_hashes = null ): array {
		if ( null === $applied_hashes ) {
			$applied_hashes = array();
			foreach ( $cart->get_applied_coupons() as $code ) {
				$hash = BundleCouponEngine::byp_hash_from_code( (string) $code );
				if ( null !== $hash ) {
					$applied_hashes[] = $hash;
				}
			}
		}

		$allowed = array_fill_keys( $applied_hashes, true );
		$out     = array();

		foreach ( $this->collector->collect( $cart ) as $hash => $data ) {
			if ( ! isset( $allowed[ $hash ] ) ) {
				continue;
			}

			$valid_items = array_filter(
				$data['items'],
				static function ( array $item ): bool {
					return isset( $item['product_id'], $item['qty'] )
						&& (int) $item['product_id'] > 0
						&& (int) $item['qty'] > 0;
				}
			);
			$distinct = count( $valid_items );
			$pct      = $this->tiers->resolve_pct( $distinct, $data['tiers'] );
			if ( $pct <= 0 ) {
				continue;
			}

			$out[ $data['term_id'] ] = array(
				'name'     => $data['name'],
				'distinct' => $distinct,
				'pct'      => $pct,
			);
		}

		return $out;
	}
}
