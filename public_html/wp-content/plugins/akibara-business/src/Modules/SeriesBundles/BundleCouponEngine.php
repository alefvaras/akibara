<?php
/**
 * Series Bundles — BundleCouponEngine (CAPA DE HOOKS WC).
 *
 * Materializa el descuento de los Bundles como cupones virtuales de tipo
 * `akb_byp_exact`, inyectados en memoria, NUNCA vía `set_price()`, para no
 * romper el cálculo del envío gratis (Req 3, Decisión 1). El tipo custom usa
 * la extensión oficial de WC para cupones por producto: aplica exactamente la
 * contribución best-wins de cada tomo, sin el reparto uniforme de `fixed_cart`.
 * El patrón de cupones virtuales del módulo:
 *
 *   1. Fabrica los datos del cupón "al vuelo" cuando WooCommerce los pide
 *      (`woocommerce_get_shop_coupon_data`), delegando el monto en el
 *      `DiscountEngine` puro.
 *   2. Auto-aplica/remueve sus cupones en `woocommerce_before_calculate_totals`
 *      con prioridad 1000, de modo que corre DESPUÉS de
 *      `TaxonomyDiscount\PriceFilters` (prio 20) y lee precios ya rebajados
 *      (Decisión 3, diagrama de secuencia). El guard `did_action(...) >= 2`
 *      garantiza idempotencia (Property 5).
 *   3. Aporta su raíz de prefijo `akb_sb_` al filtro de whitelisting de
 *      `TaxonomyDiscount\CouponInteraction` (Mejora 1) para que sus cupones no
 *      sean invalidados cuando `stacks_with_coupons = no`.
 *
 * Familia de cupón:
 *   - `akb_sb_byp_{md5(term_id)}` → Build_Your_Pack por volumen de serie.
 *
 * Los DTOs de precio los reúne el `CartSeriesCollector` compartido (fuente única
 * de la rutina carrito → serie → config; también la consumen el aviso del carrito
 * y la instrumentación de orden, así aviso y motor coinciden por construcción):
 * `get_price()` (precio FILTRADO de vista, que ya incluye ofertas WC +
 * TaxonomyDiscount) como `current_price` y `get_regular_price('edit')` como
 * `regular_price`. El DiscountEngine descuenta sobre el regular pero cede si el
 * filtrado ya es ≤ el target → best-wins (nunca apila BYP sobre la taxonomía).
 * El conteo de Build_Your_Pack es por tomos DISTINTOS (dedupe por `product_id`,
 * Decisión 8).
 *
 * El etiquetado del cupón en carrito/checkout (label, ocultar remover y
 * presentación) pertenece al adaptador first-party de WooCommerce Blocks;
 * aquí NO se registran filtros de UI.
 *
 * Sin funciones i18n (Req 11.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

defined( 'ABSPATH' ) || exit;

final class BundleCouponEngine {

	/** Raíz común de todos los cupones del módulo (whitelisting, Mejora 1). */
	public const COUPON_PREFIX = 'akb_sb_';

	/** Prefijo de los cupones de Build_Your_Pack: `akb_sb_byp_{md5(term_id)}`. */
	public const COUPON_BYP_PREFIX = 'akb_sb_byp_';

	/** Tipo de cupón por producto con contribución BYP exacta por línea. */
	public const COUPON_TYPE = 'akb_byp_exact';

	/** Snapshot inmutable usado al recalcular una orden histórica. */
	public const ORDER_SNAPSHOT_META = '_akb_byp_exact_snapshot_v1';

	/** Identidad estable que enlaza la línea en checkout con su ID persistido. */
	public const ORDER_LINE_KEY_META = '_akb_byp_line_key_v1';

	/**
	 * Contexto efímero para los objetos de cupón reconstruidos por una orden.
	 *
	 * @var array<int,array{mode:string,contributions?:array<int,array{product_id:int,amount:float}>}>
	 */
	private array $order_coupon_context = array();

	/**
	 * Bandera de re-entrancia compartida para evitar colisiones durante recalculación.
	 */
	private static bool $running = false;

	public static function is_running(): bool {
		return self::$running;
	}

	public function __construct(
		private readonly Settings $settings,
		private readonly DiscountEngine $engine,
		private readonly CartSeriesCollector $collector
	) {
	}

	/**
	 * Conecta los hooks de la capa de cupones virtuales.
	 *
	 * El etiquetado de UI NO se registra aquí: es responsabilidad del adaptador
	 * first-party de WooCommerce Blocks.
	 */
	public function register(): void {
		// Registrar el tipo custom antes de que WC valide/fabrique el cupón.
		add_filter( 'woocommerce_coupon_discount_types', array( $this, 'register_coupon_type' ) );
		add_filter( 'woocommerce_product_coupon_types', array( $this, 'register_product_coupon_type' ) );

		// 1. Fabricar el cupón virtual "al vuelo" cuando WooCommerce lo pide.
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'fabricate_virtual_coupon' ), 10, 2 );

		// Aislar líneas/qty y reemplazar el monto por la contribución exacta del SKU.
		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'handle_woocommerce_coupon_get_items_to_apply' ), 10, 3 );
		add_filter( 'woocommerce_coupon_get_discount_amount', array( $this, 'handle_woocommerce_coupon_get_discount_amount' ), 10, 5 );

		// Persistir e hidratar la asignación exacta para recálculo administrativo.
		add_action( 'woocommerce_checkout_create_order_coupon_item', array( $this, 'handle_checkout_create_order_coupon_item' ), 10, 4 );
		add_filter( 'woocommerce_order_recalculate_coupons_coupon_object', array( $this, 'handle_order_recalculate_coupon_object' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'finalize_order_snapshot' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_created', array( $this, 'finalize_order_snapshot' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'reconcile_store_api_order_snapshot' ), 15, 1 );

		// 2. Auto-aplicar / remover los cupones basados en el carrito actual (prio 1000).
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'manage_cart_coupons' ), 1000 );

		// 3. Aportar la raíz `akb_sb_` al whitelisting de TaxonomyDiscount (Mejora 1).
		add_filter( 'akibara_taxonomy_discount_ignored_coupon_prefixes', array( $this, 'whitelist_prefix' ) );
	}

	/**
	 * Hace visible el tipo custom al constructor/validador de `WC_Coupon`.
	 *
	 * @param array<string,string> $types Tipos registrados.
	 * @return array<string,string>
	 */
	public function register_coupon_type( array $types ): array {
		$types[ self::COUPON_TYPE ] = 'Descuento exacto de pack';

		return $types;
	}

	/**
	 * Declara el tipo como cupón de producto para que WC valide `product_ids` y
	 * ejecute una vez su contribución custom por línea.
	 *
	 * @param array<int,string> $types Tipos de cupón por producto.
	 * @return array<int,string>
	 */
	public function register_product_coupon_type( array $types ): array {
		$types[] = self::COUPON_TYPE;

		return array_values( array_unique( $types ) );
	}

	// -------------------------------------------------------------------------
	// Hook 1: Fabricación de datos del cupón virtual
	// -------------------------------------------------------------------------

	/**
	 * Fabrica los datos de un cupón virtual exacto para un código del módulo.
	 *
	 * Resuelve el prefijo del código, reúne los DTOs de precio del carrito y
	 * delega el monto en el `DiscountEngine`. Si el monto resultante no es
	 * positivo, devuelve `$data` intacto (no se materializa cupón). Para códigos
	 * ajenos al módulo, devuelve `$data` sin tocar.
	 *
	 * @param mixed  $data Datos del cupón provistos por WooCommerce (false si no existe).
	 * @param string $code Código del cupón solicitado.
	 *
	 * @return mixed Datos del cupón virtual (array) o `$data` original.
	 */
	public function fabricate_virtual_coupon( $data, $code ) {
		$code = (string) $code;
		$cart = WC()->cart;

		if ( ! $cart instanceof \WC_Cart ) {
			return $data;
		}

		$hash = self::byp_hash_from_code( $code );
		if ( null !== $hash ) {
			$payload = $this->byp_payload( $cart, $hash );
			if ( $payload['amount'] > 0 && ! empty( $payload['product_ids'] ) ) {
				return $this->generate_coupon_data( $payload['amount'], $payload['product_ids'] );
			}
			return $data;
		}

		return $data;
	}

	/**
	 * Datos de un cupón virtual BYP por producto.
	 *
	 * @return array<string, mixed>
	 */
	private function generate_coupon_data( float $amount, array $product_ids ): array {
		return array(
			'id'                         => PHP_INT_MAX, // Evita colisiones con cupones en DB.
			'amount'                     => $amount,
			'discount_type'              => self::COUPON_TYPE,
			'individual_use'             => false,
			'usage_limit'                => 0,
			'usage_count'                => 0,
			'date_expires'               => null,
			'free_shipping'              => false,
			'product_ids'                => array_values( array_unique( array_map( 'intval', $product_ids ) ) ),
			'exclude_product_ids'        => array(),
			'usage_limit_per_user'       => 0,
			'limit_usage_to_x_items'     => null,
			'free_shipping_requires_min' => false,
			'product_categories'         => array(),
			'exclude_product_categories' => array(),
			'exclude_sale_items'         => false,
			'minimum_amount'             => '',
			'maximum_amount'             => '',
			'email_restrictions'         => array(),
			'used_by'                    => array(),
			'virtual'                    => true, // Marcador interno del módulo.
		);
	}

	/**
	 * Extrae el hash canónico de un código BYP completo.
	 *
	 * Exigir los 32 hexadecimales de `md5(term_id)` evita tratar como propio un
	 * prefijo parcial, un hash truncado o una familia interna desconocida.
	 */
	public static function byp_hash_from_code( string $code ): ?string {
		$pattern = '/^' . preg_quote( self::COUPON_BYP_PREFIX, '/' ) . '([a-f0-9]{32})$/D';

		return 1 === preg_match( $pattern, $code, $matches ) ? $matches[1] : null;
	}

	/**
	 * Limita el cupón BYP a sus tomos elegibles y una unidad por SKU.
	 *
	 * Vuelve a resolver el hash contra el carrito vivo, falla cerrado si el
	 * código/config ya no existe y devuelve clones de una sola unidad. También
	 * reduce `price` al valor unitario para que el techo de descuento de una línea
	 * repetida no represente todas sus unidades.
	 *
	 * @internal Callback de `woocommerce_coupon_get_items_to_apply`.
	 *
	 * @param array<int, object> $items     Líneas candidatas de WC_Discounts.
	 * @param mixed              $coupon    Cupón evaluado.
	 * @param mixed              $discounts Instancia WC_Discounts (no usada).
	 * @return array<int, object>
	 */
	public function handle_woocommerce_coupon_get_items_to_apply( array $items, $coupon, $discounts ): array {
		if ( ! $coupon instanceof \WC_Coupon ) {
			return $items;
		}

		$code = (string) $coupon->get_code();
		if ( 0 !== strpos( $code, self::COUPON_PREFIX ) ) {
			return $items;
		}

		$hash = self::byp_hash_from_code( $code );
		if ( null === $hash || ! method_exists( $coupon, 'get_discount_type' ) ) {
			return array();
		}

		$context = $this->order_coupon_context[ spl_object_id( $coupon ) ] ?? null;
		$object  = is_object( $discounts ) && method_exists( $discounts, 'get_object' )
			? $discounts->get_object()
			: null;
		if ( $object instanceof \WC_Order ) {
			if ( is_array( $context ) && 'legacy' === $context['mode'] ) {
				return $items;
			}
			if ( ! is_array( $context ) || 'snapshot' !== $context['mode'] ) {
				return array();
			}

			return $this->filter_order_items( $items, $context['contributions'] ?? array() );
		}

		if ( self::COUPON_TYPE !== (string) $coupon->get_discount_type() ) {
			// Un fixed_cart BYP forjado en carrito nunca hereda el fallback legacy.
			return array();
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return array();
		}

		$payload      = $this->byp_payload( $cart, $hash );
		$eligible_ids = array_fill_keys( $payload['product_ids'], true );
		if ( $payload['amount'] <= 0 || empty( $eligible_ids ) ) {
			return array();
		}

		$filtered = array();
		$seen_ids = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item )
				|| ! isset( $item->product )
				|| ! $item->product instanceof \WC_Product
				|| ! isset( $item->quantity, $item->price )
				|| (float) $item->quantity <= 0 ) {
				continue;
			}

			$product_id = (int) $item->product->get_id();
			$parent_id  = (int) $item->product->get_parent_id();
			$matched_id = isset( $eligible_ids[ $product_id ] ) ? $product_id : $parent_id;
			if ( $matched_id <= 0
				|| ! isset( $eligible_ids[ $matched_id ] )
				|| isset( $seen_ids[ $matched_id ] ) ) {
				continue;
			}

			$clone           = clone $item;
			$clone->price    = (float) $item->price / (float) $item->quantity;
			$clone->quantity = 1;
			$filtered[]      = $clone;
			$seen_ids[ $matched_id ] = true;
		}

		return $filtered;
	}

	/**
	 * Sustituye el descuento genérico de WC por la contribución exacta del SKU.
	 *
	 * `WC_Discounts::apply_coupon_custom()` llama este filtro una vez por item,
	 * antes de asignar impuestos y totales a la línea. El callback sólo actúa en
	 * carrito vivo, con el tipo propio, hash canónico y `cart_item` array; cualquier
	 * re-aplicación sobre orden/reembolso u objeto incompleto falla cerrada a 0.
	 * Los cupones externos conservan exactamente `$discount`.
	 *
	 * @internal Callback de `woocommerce_coupon_get_discount_amount`.
	 *
	 * @param mixed $discount           Descuento propuesto por WooCommerce.
	 * @param mixed $discounting_amount Precio unitario al que se aplica.
	 * @param mixed $cart_item          Línea de carrito o null en otros contextos.
	 * @param mixed $single             Indicador unitario de WC (no usado).
	 * @param mixed $coupon             Cupón evaluado.
	 * @return float|mixed
	 */
	public function handle_woocommerce_coupon_get_discount_amount( $discount, $discounting_amount, $cart_item, $single, $coupon ) {
		unset( $single );

		if ( ! $coupon instanceof \WC_Coupon ) {
			return $discount;
		}

		$code = (string) $coupon->get_code();
		if ( 0 !== strpos( $code, self::COUPON_PREFIX ) ) {
			return $discount;
		}

		$hash = self::byp_hash_from_code( $code );
		if ( null === $hash || ! method_exists( $coupon, 'get_discount_type' ) ) {
			return 0.0;
		}

		$context = $this->order_coupon_context[ spl_object_id( $coupon ) ] ?? null;
		if ( $cart_item instanceof \WC_Order_Item_Product ) {
			if ( is_array( $context ) && 'legacy' === $context['mode'] ) {
				return $discount;
			}
			if ( ! is_array( $context ) || 'snapshot' !== $context['mode'] ) {
				return 0.0;
			}

			$line_id       = (int) $cart_item->get_id();
			$contributions = $context['contributions'] ?? array();
			$amount        = isset( $contributions[ $line_id ]['amount'] ) ? (float) $contributions[ $line_id ]['amount'] : 0.0;
			$base          = max( 0.0, (float) $discounting_amount );

			return min( $base, max( 0.0, $amount ) );
		}

		if ( self::COUPON_TYPE !== (string) $coupon->get_discount_type()
			|| ! is_array( $cart_item )
			|| empty( $cart_item['data'] )
			|| ! $cart_item['data'] instanceof \WC_Product ) {
			return 0.0;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return 0.0;
		}

		$series = $this->collector->collect( $cart );
		if ( ! isset( $series[ $hash ] ) ) {
			return 0.0;
		}

		$product_id = isset( $cart_item['product_id'] )
			? (int) $cart_item['product_id']
			: (int) $cart_item['data']->get_id();
		if ( $product_id <= 0 || ! isset( $series[ $hash ]['items'][ $product_id ] ) ) {
			return 0.0;
		}

		return $this->engine->contribution_for_base(
			$product_id,
			array_values( $series[ $hash ]['items'] ),
			$series[ $hash ]['tiers'],
			(float) $discounting_amount,
			$this->precise_discounting_base( $cart_item['data'], (float) $discounting_amount )
		);
	}

	/**
	 * Persiste la asignación monetaria ya aplicada por producto en el cupón de orden.
	 *
	 * @internal Callback de `woocommerce_checkout_create_order_coupon_item`.
	 *
	 * @param mixed  $item   WC_Order_Item_Coupon en construcción.
	 * @param string $code   Código aplicado.
	 * @param mixed  $coupon WC_Coupon aplicado.
	 * @param mixed  $order  WC_Order destino.
	 */
	public function handle_checkout_create_order_coupon_item( $item, $code, $coupon, $order ): void {
		unset( $coupon );

		$hash = self::byp_hash_from_code( (string) $code );
		if ( null === $hash
			|| ! $item instanceof \WC_Order_Item_Coupon
			|| ! $order instanceof \WC_Order ) {
			return;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$payload  = $this->byp_payload( $cart, $hash );
		$eligible = array_fill_keys( $payload['product_ids'], true );
		if ( $payload['amount'] <= 0 || empty( $eligible ) ) {
			return;
		}

		$series = $this->collector->collect( $cart );
		if ( ! isset( $series[ $hash ] ) ) {
			return;
		}

		$line_contributions = array();
		$seen_products      = array();
		$sequence           = 0;
		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $this->order_item_product_id( $line_item );
			if ( $product_id <= 0 || ! isset( $eligible[ $product_id ] ) ) {
				continue;
			}

			// Un SKU repetido recibe beneficio sólo en su primera línea.
			if ( isset( $seen_products[ $product_id ] ) ) {
				continue;
			}
			$seen_products[ $product_id ] = true;

			$quantity = max( 1, (int) $line_item->get_quantity() );
			$base     = (float) $line_item->get_subtotal();
			if ( $order->get_prices_include_tax() ) {
				$base += (float) $line_item->get_subtotal_tax();
			}
			$base /= $quantity;

			$contribution = $this->engine->contribution_for_base(
				$product_id,
				array_values( $series[ $hash ]['items'] ),
				$series[ $hash ]['tiers'],
				$base,
				$this->precise_discounting_base( $this->order_item_product( $line_item ), $base )
			);
			if ( $contribution > 0 ) {
				$line_key = $hash . ':' . $product_id . ':' . $sequence;
				$line_item->add_meta_data( self::ORDER_LINE_KEY_META, $line_key, true );
				$line_contributions[ $line_key ] = array(
					'product_id' => $product_id,
					'amount'     => $contribution,
				);
			}
			++$sequence;
		}

		if ( empty( $line_contributions ) ) {
			return;
		}

		$snapshot = array(
			'version'               => 1,
			'prices_include_tax'    => (bool) $order->get_prices_include_tax(),
			'gross_discount'        => array_sum( array_column( $line_contributions, 'amount' ) ),
			'line_contributions'    => $line_contributions,
			'order_line_contributions' => array(),
		);
		$item->add_meta_data( self::ORDER_SNAPSHOT_META, wp_json_encode( $snapshot ), true );
	}

	/**
	 * Reemplaza las claves provisionales por IDs persistidos de línea.
	 *
	 * Corre después del save clásico y en los dos puntos seguros de Store API;
	 * si un hook temprano aún no tiene IDs, el siguiente completa el snapshot.
	 *
	 * @param mixed $order Orden recién creada/procesada.
	 */
	public function finalize_order_snapshot( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$lines_by_key = array();
		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof \WC_Order_Item_Product || (int) $line_item->get_id() <= 0 ) {
				continue;
			}
			$key = (string) $line_item->get_meta( self::ORDER_LINE_KEY_META, true );
			if ( '' !== $key ) {
				$lines_by_key[ $key ] = (int) $line_item->get_id();
			}
		}
		if ( empty( $lines_by_key ) ) {
			return;
		}

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}
			$raw  = $coupon_item->get_meta( self::ORDER_SNAPSHOT_META, true );
			$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			if ( ! is_array( $data ) || empty( $data['line_contributions'] ) || ! is_array( $data['line_contributions'] ) ) {
				continue;
			}

			$by_id = array();
			foreach ( $data['line_contributions'] as $line_key => $entry ) {
				if ( ! isset( $lines_by_key[ $line_key ], $entry['product_id'], $entry['amount'] ) ) {
					continue;
				}
				$by_id[ $lines_by_key[ $line_key ] ] = array(
					'product_id' => (int) $entry['product_id'],
					'amount'     => (float) $entry['amount'],
				);
			}
			if ( count( $by_id ) !== count( $data['line_contributions'] ) ) {
				continue;
			}

			$data['order_line_contributions'] = $by_id;
			$data['gross_discount'] = array_sum( array_column( $by_id, 'amount' ) );
			$coupon_item->update_meta_data( self::ORDER_SNAPSHOT_META, wp_json_encode( $data ) );
			$coupon_item->save();
		}
	}

	/**
	 * Hidrata un cupón reconstruido para recálculo de orden.
	 *
	 * Snapshot válido => tipo exacto y mapa inmutable. BYP legacy/corrupto =>
	 * `fixed_cart` con el importe histórico, nunca un custom sin contexto que
	 * pudiera llevar las líneas a cero.
	 *
	 * @internal Callback de `woocommerce_order_recalculate_coupons_coupon_object`.
	 *
	 * @param mixed  $coupon      Cupón reconstruido.
	 * @param string $code        Código de la orden.
	 * @param mixed  $coupon_item Ítem de cupón persistido.
	 * @param mixed  $order       Orden recalculada.
	 * @return mixed
	 */
	public function handle_order_recalculate_coupon_object( $coupon, $code, $coupon_item, $order ) {
		if ( null === self::byp_hash_from_code( (string) $code )
			|| ! $coupon instanceof \WC_Coupon
			|| ! $coupon_item instanceof \WC_Order_Item_Coupon
			|| ! $order instanceof \WC_Order ) {
			return $coupon;
		}

		$historical_gross = (float) $coupon_item->get_discount();
		if ( $order->get_prices_include_tax() ) {
			$historical_gross += (float) $coupon_item->get_discount_tax();
		}

		$snapshot = $this->decode_order_snapshot(
			$coupon_item->get_meta( self::ORDER_SNAPSHOT_META, true ),
			$historical_gross
		);
		if ( null !== $snapshot && $this->snapshot_matches_order( $snapshot, $order ) ) {
			$coupon->set_discount_type( self::COUPON_TYPE );
			$coupon->set_amount( (float) $snapshot['gross_discount'] );
			$coupon->set_product_ids( array_values( array_unique( array_column( $snapshot['order_line_contributions'], 'product_id' ) ) ) );
			$this->order_coupon_context[ spl_object_id( $coupon ) ] = array(
				'mode'          => 'snapshot',
				'contributions' => $snapshot['order_line_contributions'],
			);
			return $coupon;
		}

		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( max( 0.0, $historical_gross ) );
		$this->order_coupon_context[ spl_object_id( $coupon ) ] = array( 'mode' => 'legacy' );

		return $coupon;
	}

	/**
	 * Reconcilia el snapshot BYP contra el carrito vivo al cerrar Store API.
	 *
	 * Store API reutiliza una orden `checkout-draft`. Si cambia el carrito pero
	 * mantiene el mismo código de cupón, WooCommerce reemplaza las líneas por su
	 * nuevo hash, pero conserva el ítem de cupón porque el hash de códigos no
	 * cambió. En ese caso el snapshot anterior apunta a IDs eliminados. Este hook
	 * es la última frontera con carrito vivo: reconstruye el mapa por los IDs ya
	 * persistidos y actualiza neto/impuesto con los totales que WC calculó para
	 * ese mismo código. Nunca reutiliza el importe del intento anterior.
	 *
	 * @internal Callback de `woocommerce_store_api_checkout_order_processed`.
	 *
	 * @param mixed $order Orden Store API lista para pago.
	 */
	public function reconcile_store_api_order_snapshot( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$cart = function_exists( 'WC' ) ? WC()->cart : null;
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		$applied_codes = array_map( 'strtolower', array_map( 'strval', $cart->get_applied_coupons() ) );
		$series        = $this->collector->collect( $cart );

		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			if ( ! $coupon_item instanceof \WC_Order_Item_Coupon ) {
				continue;
			}

			$code = (string) $coupon_item->get_code();
			$hash = self::byp_hash_from_code( $code );
			if ( null === $hash
				|| ! in_array( strtolower( $code ), $applied_codes, true )
				|| ! isset( $series[ $hash ] ) ) {
				continue;
			}

			$contributions = $this->build_current_order_contributions( $order, $series[ $hash ] );
			if ( empty( $contributions ) ) {
				continue;
			}

			$discount     = max( 0.0, (float) $cart->get_coupon_discount_amount( $code ) );
			$discount_tax = max( 0.0, (float) $cart->get_coupon_discount_tax_amount( $code ) );
			$gross        = $order->get_prices_include_tax() ? $discount + $discount_tax : $discount;
			$calculated   = array_sum( array_column( $contributions, 'amount' ) );
			if ( $gross <= 0 || ! $this->amounts_match_currency( $gross, $calculated ) ) {
				// Un carrito incompleto o no recalculado no debe sellar meta falsa.
				continue;
			}

			$snapshot = array(
				'version'                    => 1,
				'prices_include_tax'         => (bool) $order->get_prices_include_tax(),
				'gross_discount'             => $calculated,
				'line_contributions'         => array(),
				'order_line_contributions'   => $contributions,
			);
			$coupon_item->set_discount( $discount );
			$coupon_item->set_discount_tax( $discount_tax );
			$coupon_item->update_meta_data( self::ORDER_SNAPSHOT_META, wp_json_encode( $snapshot ) );
			$coupon_item->save();
		}
	}

	// -------------------------------------------------------------------------
	// Hook 2: Auto-gestión de cupones (aplicar / remover)
	// -------------------------------------------------------------------------

	/**
	 * Auto-aplica y remueve los cupones virtuales del módulo según el carrito.
	 *
	 * Réplica EXACTA del patrón `VirtualCouponEngine::gestionar_cupones_carrito`:
	 *   - Guard `is_admin() && ! DOING_AJAX` ⇒ no opera en admin sin AJAX.
	 *   - Flag re-entrante `static $running` ⇒ corta la recursión que produce
	 *     `add_discount()`/`remove_coupon()` al redisparar el hook (Property 5),
	 *     sin depender de `did_action()` (que es global al request).
	 *   - Calcula el set esperado de códigos (packs en carrito ∪ series BYP que
	 *     alcanzan el primer tramo) y usa `array_diff` para remover los que ya
	 *     no aplican y agregar los nuevos (Req 3.2, 3.3).
	 */
	public function manage_cart_coupons( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Re-entrancia: add_discount()/remove_coupon() vuelven a disparar
		// `before_calculate_totals`. Un flag re-entrante corta la recursión SIN
		// depender de did_action() (global al request, se rompía en mini-cart/
		// checkout que recalculan ≥2 veces y dejaban cupones sin reconciliar).
		if ( self::$running ) {
			return;
		}
		self::$running = true;

		try {
			$expected = array();
			if ( $this->settings->is_enabled() ) {
				$expected = $this->expected_byp_codes( $cart );
			}

			// Guard anti-apilado: si el cliente aplicó un cupón de campaña EXTERNO
			// (no del módulo), NO auto-aplicar BYP. Un cupón manual y BYP se sumarían
			// (el AntiDoubleDiscountGuard solo dirime fuentes internas) → fuga de
			// margen sin COGS. Política margin-safe: BYP cede ante el cupón manual.
			if ( ! empty( $expected ) ) {
				foreach ( $cart->get_applied_coupons() as $applied ) {
					if ( 0 !== strpos( (string) $applied, self::COUPON_PREFIX ) ) {
						// Si el pack ofrece mayor beneficio que el cupón de referido, cede el referido.
						if ( 0 === strpos( (string) $applied, 'ref' ) ) {
							$potential_discount = 0.0;
							foreach ( $this->collector->collect( $cart ) as $s_data ) {
								$potential_discount += $this->engine->calc_byp_discount(
									array_values( $s_data['items'] ),
									$s_data['tiers']
								);
							}
							$ref_amount = function_exists( 'get_option' ) ? (float) get_option( 'akb_referral_guest_discount_amount', 2000 ) : 2000.0;
							if ( $potential_discount > $ref_amount ) {
								if ( function_exists( 'WC' ) && WC()->session ) {
									WC()->session->set( 'akb_sb_superseded_ref_coupon', (string) $applied );
								}
								if ( method_exists( $cart, 'remove_coupon' ) ) {
									$cart->remove_coupon( (string) $applied );
								}
								if ( function_exists( 'wc_add_notice' ) && function_exists( 'wc_has_notice' ) ) {
									$msg = 'Aplicamos automáticamente el descuento de Arma tu Pack porque te ahorra más que el cupón de invitación.';
									if ( ! wc_has_notice( $msg, 'notice' ) ) {
										wc_add_notice( $msg, 'notice' );
									}
								}
								continue;
							}
						}

						$expected = array();
						break;
					}
				}

				// Guard Best-Wins con Puntos Akibara (Decisión Ejecutiva):
				// Si el cliente activó el switch para usar sus Puntos Akibara en vez del pack,
				// o si ya tiene un canje de puntos confirmado en sesión, el pack cede.
				if ( ! empty( $expected ) ) {
					$prefer_points = ( function_exists( 'WC' ) && WC()->session && (bool) WC()->session->get( 'akb_prefer_points_over_bundle' ) )
						|| ( class_exists( '\Akibara\Rewards\Points\Redemption' ) && \Akibara\Rewards\Points\Redemption::intent_points() > 0 );

					if ( $prefer_points ) {
						$expected = array();
					}
				}
			}

			$current_own = array();
			foreach ( $cart->get_applied_coupons() as $applied ) {
				if ( 0 === strpos( (string) $applied, self::COUPON_PREFIX ) ) {
					$current_own[] = $applied;
				}
			}

			// 1. Remover los nuestros que ya NO aplican.
			foreach ( array_diff( $current_own, $expected ) as $code ) {
				$cart->remove_coupon( $code );
			}

			// 2. Aplicar los nuevos que sí corresponden.
			foreach ( array_diff( $expected, $current_own ) as $code ) {
				$cart->add_discount( $code );
			}
		} finally {
			self::$running = false;
		}
	}

	/**
	 * Aporta la raíz de prefijo `akb_sb_` al whitelisting de
	 * `TaxonomyDiscount\CouponInteraction` (Mejora 1).
	 *
	 * @param array<int, string> $prefixes
	 * @return array<int, string>
	 */
	public function whitelist_prefix( array $prefixes ): array {
		$prefixes[] = self::COUPON_PREFIX;
		return $prefixes;
	}

	/**
	 * Filtra líneas de orden según el snapshot y limita cada SKU a una unidad.
	 *
	 * @param array<int,object> $items         Líneas normalizadas de WC_Discounts.
	 * @param array<int,array{product_id:int,amount:float}> $contributions Aporte persistido por ID de línea.
	 * @return array<int,object>
	 */
	private function filter_order_items( array $items, array $contributions ): array {
		$filtered = array();

		foreach ( $items as $item ) {
			if ( ! is_object( $item )
				|| ! isset( $item->object, $item->quantity, $item->price )
				|| ! $item->object instanceof \WC_Order_Item_Product
				|| (float) $item->quantity <= 0 ) {
				continue;
			}

			$line_id = (int) $item->object->get_id();
			if ( $line_id <= 0
				|| ! isset( $contributions[ $line_id ]['amount'] )
				|| (float) $contributions[ $line_id ]['amount'] <= 0 ) {
				continue;
			}

			$clone           = clone $item;
			$clone->price    = (float) $item->price / (float) $item->quantity;
			$clone->quantity = 1;
			$filtered[]      = $clone;
		}

		return $filtered;
	}

	/** Obtiene el ID efectivo (variación o producto) de una línea de orden. */
	private function order_item_product_id( \WC_Order_Item_Product $item ): int {
		$variation_id = (int) $item->get_variation_id();

		return $variation_id > 0 ? $variation_id : (int) $item->get_product_id();
	}

	/** Obtiene el producto vivo de una línea cuando la implementación lo expone. */
	private function order_item_product( \WC_Order_Item_Product $item ): ?\WC_Product {
		if ( method_exists( $item, 'get_product' ) ) {
			$product = $item->get_product();
			if ( $product instanceof \WC_Product ) {
				return $product;
			}
		}

		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $this->order_item_product_id( $item ) );
			if ( $product instanceof \WC_Product ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Recupera la base tributaria previa al redondeo CLP para un cliente exento.
	 *
	 * `WC_Cart_Totals::remove_item_base_taxes()` redondea primero el neto a la
	 * moneda (1.005 / 1,19 pasa a 845) y ése es el monto que recibe el filtro de
	 * cupón. Escalar desde 845 produce 85; la base fiscal real 844,537... produce
	 * 84. Sólo sustituye la base si difiere a lo sumo una unidad monetaria, para
	 * no deshacer descuentos secuenciales ni ajustes ajenos.
	 */
	private function precise_discounting_base( ?\WC_Product $product, float $discounting_amount ): float {
		$base = max( 0.0, $discounting_amount );
		if ( $base <= 0
			|| ! $product instanceof \WC_Product
			|| ! function_exists( 'WC' )
			|| ! function_exists( 'wc_prices_include_tax' )
			|| ! function_exists( 'wc_get_price_excluding_tax' )
			|| ! wc_prices_include_tax()
			|| ! method_exists( $product, 'is_taxable' )
			|| ! $product->is_taxable() ) {
			return $base;
		}

		$runtime  = WC();
		$customer = is_object( $runtime ) && isset( $runtime->customer ) ? $runtime->customer : null;
		if ( ! is_object( $customer )
			|| ! method_exists( $customer, 'get_is_vat_exempt' )
			|| ! $customer->get_is_vat_exempt() ) {
			return $base;
		}

		$current = (float) $product->get_price();
		$precise = $current > 0
			? wc_get_price_excluding_tax( $product, array( 'qty' => 1, 'price' => $current ) )
			: 0.0;
		if ( ! is_numeric( $precise )
			|| (float) $precise <= 0
			|| ! $this->amounts_match_currency( (float) $precise, $base ) ) {
			return $base;
		}

		return (float) $precise;
	}

	/**
	 * Verifica que el snapshot siga describiendo las líneas actuales de la orden.
	 *
	 * Store API y la edición administrativa pueden reemplazar una línea y, con
	 * ello, cambiar su ID. Aceptar un snapshot autoconsistente pero obsoleto haría
	 * que el custom coupon no encuentre items y aplique 0. Exige identidad, SKU y
	 * capacidad monetaria actuales; cualquier divergencia activa el fallback
	 * histórico `fixed_cart` del caller.
	 *
	 * @param array{version:int,prices_include_tax:bool,gross_discount:float,order_line_contributions:array<int,array{product_id:int,amount:float}>} $snapshot Snapshot decodificado.
	 * @param \WC_Order $order Orden actual.
	 */
	private function snapshot_matches_order( array $snapshot, \WC_Order $order ): bool {
		$lines = $order->get_items( 'line_item' );
		foreach ( $snapshot['order_line_contributions'] as $line_id => $entry ) {
			$line = $lines[ $line_id ] ?? null;
			if ( ! $line instanceof \WC_Order_Item_Product
				|| (int) $line->get_id() !== (int) $line_id
				|| $this->order_item_product_id( $line ) !== (int) $entry['product_id'] ) {
				return false;
			}

			$quantity = (int) $line->get_quantity();
			if ( $quantity <= 0 ) {
				return false;
			}
			$base = (float) $line->get_subtotal() / $quantity;
			if ( $order->get_prices_include_tax() ) {
				$base += (float) $line->get_subtotal_tax() / $quantity;
			}
			if ( $base <= 0 || (float) $entry['amount'] > $base + 0.01 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Calcula el mapa exacto para las líneas actualmente persistidas de Store API.
	 *
	 * @param \WC_Order $order Orden checkout-draft actual.
	 * @param array{items:array<int,array{regular_price:float,current_price:float,qty:int,product_id:int}>,tiers:array<int,array{min:int|string,pct:int|string}>} $series Serie recolectada del carrito vivo.
	 * @return array<int,array{product_id:int,amount:float}> Aporte por ID de línea.
	 */
	private function build_current_order_contributions( \WC_Order $order, array $series ): array {
		$items         = isset( $series['items'] ) && is_array( $series['items'] ) ? $series['items'] : array();
		$tiers         = isset( $series['tiers'] ) && is_array( $series['tiers'] ) ? $series['tiers'] : array();
		$distinct      = array_values( $items );
		$contributions = array();
		$seen_products = array();

		foreach ( $order->get_items( 'line_item' ) as $line_item ) {
			if ( ! $line_item instanceof \WC_Order_Item_Product || (int) $line_item->get_id() <= 0 ) {
				continue;
			}

			$product_id = $this->order_item_product_id( $line_item );
			$quantity   = (int) $line_item->get_quantity();
			if ( $product_id <= 0
				|| $quantity <= 0
				|| ! isset( $items[ $product_id ] )
				|| isset( $seen_products[ $product_id ] ) ) {
				continue;
			}
			$seen_products[ $product_id ] = true;

			$base = (float) $line_item->get_subtotal() / $quantity;
			if ( $order->get_prices_include_tax() ) {
				$base += (float) $line_item->get_subtotal_tax() / $quantity;
			}

			$amount = $this->engine->contribution_for_base(
				$product_id,
				$distinct,
				$tiers,
				$base,
				$this->precise_discounting_base( $this->order_item_product( $line_item ), $base )
			);
			if ( $amount > 0 ) {
				$contributions[ (int) $line_item->get_id() ] = array(
					'product_id' => $product_id,
					'amount'     => $amount,
				);
			}
		}

		return $contributions;
	}

	/** Compara montos con la tolerancia de una unidad de la moneda activa. */
	private function amounts_match_currency( float $left, float $right ): bool {
		$decimals      = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
		$currency_unit = 1 / pow( 10, max( 0, $decimals ) );

		return abs( $left - $right ) <= $currency_unit;
	}

	/**
	 * Decodifica y valida estrictamente el snapshot de una orden.
	 *
	 * @param mixed      $raw            Meta persistida.
	 * @param float|null $expected_gross Importe histórico del ítem cupón.
	 * @return array{version:int,prices_include_tax:bool,gross_discount:float,order_line_contributions:array<int,array{product_id:int,amount:float}>}|null
	 */
	private function decode_order_snapshot( $raw, ?float $expected_gross = null ): ?array {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data )
			|| 1 !== (int) ( $data['version'] ?? 0 )
			|| ! isset( $data['order_line_contributions'] )
			|| ! is_array( $data['order_line_contributions'] )
			|| ! is_numeric( $data['gross_discount'] ?? null ) ) {
			return null;
		}

		$contributions = array();
		foreach ( $data['order_line_contributions'] as $line_id => $entry ) {
			$line_id    = (int) $line_id;
			$product_id = is_array( $entry ) && isset( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
			$amount     = is_array( $entry ) ? ( $entry['amount'] ?? null ) : null;
			if ( $line_id <= 0 || $product_id <= 0 || ! is_numeric( $amount ) || (float) $amount <= 0 ) {
				return null;
			}
			$contributions[ $line_id ] = array(
				'product_id' => $product_id,
				'amount'     => (float) $amount,
			);
		}
		$sum   = array_sum( array_column( $contributions, 'amount' ) );
		$gross = (float) $data['gross_discount'];
		if ( empty( $contributions ) || $gross <= 0 || abs( $sum - $gross ) > 0.01 ) {
			return null;
		}

		if ( null !== $expected_gross ) {
			if ( $expected_gross <= 0 || ! $this->amounts_match_currency( $gross, $expected_gross ) ) {
				return null;
			}
		}

		return array(
			'version'               => 1,
			'prices_include_tax'    => ! empty( $data['prices_include_tax'] ),
			'gross_discount'        => $gross,
			'order_line_contributions' => $contributions,
		);
	}

	// -------------------------------------------------------------------------
	// Cálculo de montos (delegan en el DiscountEngine puro)
	// -------------------------------------------------------------------------

	/**
	 * Monto y productos elegibles de una serie BYP del carrito vivo.
	 *
	 * @return array{amount:float,product_ids:array<int,int>,contributions:array<int,float>}
	 */
	private function byp_payload( \WC_Cart $cart, string $hash ): array {
		$series = $this->collector->collect( $cart );
		if ( ! isset( $series[ $hash ] ) ) {
			return array(
				'amount'      => 0.0,
				'product_ids' => array(),
				'contributions' => array(),
			);
		}

		$distinct_items = array_values( $series[ $hash ]['items'] );
		$product_ids     = array();
		$contributions   = array();
		foreach ( $distinct_items as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$qty        = isset( $item['qty'] ) ? (int) $item['qty'] : 0;
			if ( $product_id > 0 && $qty > 0 ) {
				$product_ids[] = $product_id;
				$contribution = $this->engine->contribution_for_product(
					$product_id,
					$distinct_items,
					$series[ $hash ]['tiers']
				);
				if ( $contribution > 0 ) {
					$contributions[ $product_id ] = $contribution;
				}
			}
		}

		return array(
			'amount'      => $this->engine->calc_byp_discount(
				$distinct_items,
				$series[ $hash ]['tiers']
			),
			'product_ids' => array_values( array_unique( $product_ids ) ),
			'contributions' => $contributions,
		);
	}

	// -------------------------------------------------------------------------
	// Cálculo del set esperado de códigos
	// -------------------------------------------------------------------------

	/**
	 * Códigos esperados de Build_Your_Pack: series con BYP habilitado para las
	 * que el `DiscountEngine` produce un descuento ESTRICTAMENTE positivo.
	 *
	 * La fuente de verdad de "aplica o no" es el mismo cálculo del
	 * `DiscountEngine` que luego materializa el cupón (`calc_byp_amount`), NO la
	 * mera existencia de un tramo por cantidad. Alcanzar el primer tramo por
	 * volumen es condición NECESARIA pero no suficiente: si otra fuente (p. ej.
	 * `TaxonomyDiscount`) ya dejó el precio filtrado ≤ el target del tramo, el
	 * engine cede a best-wins y el descuento es 0. En ese caso el cupón rendiría
	 * 0 y WooCommerce lo rechazaría como inválido, filtrando el código interno al
	 * cliente como un error de carrito. Gatear por descuento > 0 elimina ese
	 * cupón fantasma sin tocar el resto de la reconciliación.
	 *
	 * @return array<int, string>
	 */
	private function expected_byp_codes( \WC_Cart $cart ): array {
		$codes = array();

		foreach ( $this->collector->collect( $cart ) as $hash => $data ) {
			$discount = $this->engine->calc_byp_discount(
				array_values( $data['items'] ),
				$data['tiers']
			);

			if ( $discount > 0 ) {
				$codes[] = self::COUPON_BYP_PREFIX . $hash;
			}
		}

		return $codes;
	}

}
