<?php
/**
 * Series Bundles — CartSeriesCollector (lógica pura, sin hooks).
 *
 * Fuente ÚNICA de la rutina "carrito → término `pa_serie` → config BYP →
 * dedupe" que antes vivía copiada (con drift real) en `BundleCouponEngine`,
 * `BypCartNoticeProvider`, `OrderInstrumentation` y `BuilderWidget`:
 *
 *   - B2: el aviso del carrito NO aplicaba los guards de stock/tipo del motor
 *     y prometía tramos/% que el cupón no otorgaba (reproducido: −$7.195
 *     otorgados vs −$10.493 prometidos). `collect()` aplica SIEMPRE los 4
 *     guards del engine, así aviso y motor coinciden por construcción.
 *   - B3: cuatro sitios resolvían la serie con `reset( $terms)` (first-term):
 *     un producto con 2 términos `pa_serie` donde solo el 2º tiene BYP nunca
 *     matcheaba. `resolve_enabled_series()` itera TODOS los términos y exige un
 *     único match. Si dos series tienen BYP activo, no adjudica silenciosamente
 *     el producto/tramo a la primera; las rutas con término explícito (landing)
 *     siguen resolviendo la membresía exacta.
 *   - Lookup config ×6 → 1: `config_for_term()` tolera claves int/string
 *     (JSON decodificado) y normaliza `tiers` con `array_values()` (lista
 *     reindexada — elimina la dependencia de claves asociativas del JSON).
 *
 * Decisiones de forma (diseño Ola 2, Bloque A):
 *   - UN solo shape de retorno en `collect()`: el engine consume `items` con
 *     precios; aviso/instrumentación consumen `count(items)` + flags. Dos
 *     métodos "ligero/pesado" reintroducirían drift.
 *   - `tiers` RAW (reindexados): la presentación (sort + coalesce de pct
 *     repetidos) sigue siendo responsabilidad del consumidor que la necesita
 *     (BypCartNoticeProvider / BuilderWidget); el engine consume crudos.
 *   - Un tomo agotado queda EXCLUIDO por completo: no cuenta para el tramo ni
 *     para las flags preventa/instock ("no cuenta para nada").
 *
 * Performance: corre dentro de `woocommerce_before_calculate_totals` ≥2 veces
 * por request vía el engine → `get_the_terms` (object-term cache, 0 queries en
 * caliente) y `Settings::get_byp_config()` memoizado. Cero queries nuevas por
 * construcción.
 *
 * Sin funciones i18n (Req 11.4).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\Stock\StockStatus;

defined( 'ABSPATH' ) || exit;

final class CartSeriesCollector {

	public function __construct(
		private readonly Settings $settings,
		private readonly EligibilityGuard $guard
	) {
	}

	/**
	 * Resuelve la serie con BYP habilitado de un producto, iterando TODOS sus
	 * términos `pa_serie` (B3: nada de first-term). Devuelve el match sólo cuando
	 * es inequívoco: cero o más de una config habilitada producen null. Aplicar
	 * un tramo a una serie elegida por orden de taxonomía sería un descuento no
	 * determinista y podría contradecir la landing exacta.
	 *
	 * @param int $product_id Producto a resolver.
	 * @return array{term_id:int, name:string, cfg:array{tiers:array<int, array{min:int|string, pct:int|string}>}}|null
	 */
	public function resolve_enabled_series( int $product_id ): ?array {
		// Object-term cache (0 queries en caliente), ver header.
		$terms = get_the_terms( $product_id, 'pa_serie' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}

		$matches = array();
		foreach ( $terms as $term ) {
			$term_id = (int) $term->term_id;
			$cfg     = $this->config_for_term( $term_id );
			if ( null !== $cfg ) {
				$matches[ $term_id ] = array(
					'term_id' => $term_id,
					'name'    => (string) $term->name,
					'cfg'     => $cfg,
				);
			}
		}

		return 1 === count( $matches ) ? array_values( $matches )[0] : null;
	}

	/**
	 * Comprueba que un producto pertenece a una serie concreta.
	 *
	 * A diferencia de `resolve_enabled_series()`, esta operación NO consulta ni
	 * exige BYP. Es la frontera de dominio que permite comprar varios tomos en
	 * cualquier landing sin convertir una configuración promocional en requisito
	 * de compra. Cuando se entrega `$expected_term_id` nunca cae al primer término:
	 * un producto con más de una `pa_serie` sólo pasa si contiene exactamente la
	 * serie de la URL.
	 *
	 * @param int $product_id      Producto a resolver.
	 * @param int $expected_term_id Serie exigida; 0 acepta el primer término.
	 * @return array{term_id:int,name:string}|null
	 */
	public function resolve_series_membership( int $product_id, int $expected_term_id = 0 ): ?array {
		if ( $product_id <= 0 || $expected_term_id < 0 ) {
			return null;
		}

		$terms = get_the_terms( $product_id, 'pa_serie' );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			$term_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
			if ( $term_id <= 0 || ( $expected_term_id > 0 && $term_id !== $expected_term_id ) ) {
				continue;
			}

			return array(
				'term_id' => $term_id,
				'name'    => isset( $term->name ) ? (string) $term->name : '',
			);
		}

		return null;
	}

	/**
	 * Configuración BYP de un término si está habilitada y tiene tramos.
	 * Tolera claves de `term_id` numéricas o string (JSON decodificado) y
	 * reindexa `tiers` como lista (`array_values`), eliminando la dependencia
	 * de claves asociativas del JSON persistido.
	 *
	 * @param int $term_id Término `pa_serie`.
	 * @return array{tiers:array<int, array{min:int|string, pct:int|string}>}|null
	 */
	public function config_for_term( int $term_id ): ?array {
		$bundle_cfg = PromotionPolicy::get_series_bundle_config( $term_id );
		if ( null !== $bundle_cfg ) {
			return $bundle_cfg;
		}

		$config = $this->settings->get_byp_config();
		$cfg    = $config[ $term_id ] ?? ( $config[ (string) $term_id ] ?? null );

		if ( ! is_array( $cfg ) || empty( $cfg['enabled'] ) ) {
			return null;
		}

		$tiers = ( isset( $cfg['tiers'] ) && is_array( $cfg['tiers'] ) ) ? array_values( $cfg['tiers'] ) : array();
		if ( empty( $tiers ) ) {
			return null;
		}

		return array(
			'tiers'          => $tiers,
			'profile'        => $cfg['profile'] ?? 'template_standard',
			'backorder_mode' => $cfg['backorder_mode'] ?? 'inherit',
		);
	}

	/**
	 * Resuelve una serie BYP sólo tras los cuatro guardas canónicos del carrito.
	 *
	 * Comparte la conjunción de elegibilidad, comprabilidad, disponibilidad y producto
	 * simple entre carrito, AJAX, landing y selección en grilla. La forma devuelta
	 * conserva el contrato de resolve_enabled_series() para permitir que el consumidor
	 * verifique también la identidad esperada de la serie.
	 *
	 * @param \WC_Product $product Producto vivo de WooCommerce.
	 * @return array{term_id:int, name:string, cfg:array{tiers:array<int, array{min:int|string, pct:int|string}>}}|null
	 */
	public function resolve_eligible_series( \WC_Product $product ): ?array {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0
			|| (float) $product->get_price() <= 0
			|| ! $this->guard->is_eligible( $product_id )
			|| ! $product->is_purchasable()
			|| ! $this->settings->is_stock_eligible( $product )
			|| ! $product->is_type( 'simple' ) ) {
			return null;
		}

		return $this->resolve_enabled_series( $product_id );
	}

	/**
	 * Variante exacta del guard BYP para una landing de serie.
	 *
	 * `resolve_eligible_series()` exige un único término BYP para el carrito. La
	 * landing conoce el término que muestra y exige esa membresía exacta, pero no
	 * puede prometer un tramo que el carrito luego rechazaría: si otra serie del
	 * producto también tiene BYP activo, la elegibilidad promocional falla cerrada.
	 * La compra múltiple ordinaria sigue disponible mediante
	 * `resolve_catalog_series()` y no depende de esta config.
	 *
	 * @param \WC_Product $product Producto vivo de WooCommerce.
	 * @param int         $term_id Serie esperada.
	 * @return array{term_id:int,name:string,cfg:array{tiers:array<int,array{min:int|string,pct:int|string}>}}|null
	 */
	public function resolve_eligible_series_for_term( \WC_Product $product, int $term_id ): ?array {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0
			|| $term_id <= 0
			|| (float) $product->get_price() <= 0
			|| ! $this->guard->is_eligible( $product_id )
			|| ! $product->is_purchasable()
			|| ! $this->settings->is_stock_eligible( $product )
			|| ! $product->is_type( 'simple' ) ) {
			return null;
		}

		$membership = $this->resolve_series_membership( $product_id, $term_id );
		$cfg        = $this->config_for_term( $term_id );
		$enabled    = $this->resolve_enabled_series( $product_id );
		if ( null === $membership
			|| null === $cfg
			|| null === $enabled
			|| $term_id !== (int) $enabled['term_id'] ) {
			return null;
		}

		return array(
			'term_id' => $membership['term_id'],
			'name'    => $membership['name'],
			'cfg'     => $cfg,
		);
	}

	/**
	 * Candidato promocional exacto para una serie, sin exigir que ya esté activa.
	 *
	 * La política de altas/migración necesita contar antes de escribir config.
	 * Mantener esta puerta junto al collector evita que admin cuente variables,
	 * precios cero, categorías excluidas o preventas que el carrito rechazará.
	 */
	public function is_promotion_candidate_for_term( \WC_Product $product, int $term_id ): bool {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0
			|| $term_id <= 0
			|| (float) $product->get_price() <= 0
			|| ! $this->guard->is_eligible( $product_id )
			|| ! $product->is_purchasable()
			|| ! $this->settings->is_stock_eligible( $product )
			|| ! $product->is_type( 'simple' )
			|| null === $this->resolve_series_membership( $product_id, $term_id ) ) {
			return false;
		}

		// Si otro término del mismo producto ya tiene BYP activo, habilitar éste
		// volvería ambigua la serie y el collector del carrito fallaría cerrado.
		$terms = get_the_terms( $product_id, 'pa_serie' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$other_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
				if ( $other_id > 0 && $other_id !== $term_id && null !== $this->config_for_term( $other_id ) ) {
					return false;
				}
			}
		}

		return $product_id > 0
			&& $term_id > 0
			&& null !== $this->resolve_series_membership( $product_id, $term_id );
	}

	/**
	 * Puerta canónica para compra múltiple SIN afirmar que habrá descuento.
	 *
	 * Se apoya sólo en contratos de WooCommerce/StockStatus que también gobiernan
	 * el CTA individual de la card: producto visible, simple, comprable y con
	 * estado `instock` o `onbackorder`. Las preventas pueden comprarse juntas aunque la
	 * promo BYP decida no contarlas; `resolve_eligible_series_for_term()` informa
	 * por separado si cada tomo participa del descuento. Variables/variaciones se
	 * rechazan porque este POST no transporta atributos de variación.
	 *
	 * @param \WC_Product $product Producto vivo de WooCommerce.
	 * @param int         $term_id Serie exacta de la landing.
	 * @return array{term_id:int,name:string}|null
	 */
	public function resolve_catalog_series( \WC_Product $product, int $term_id ): ?array {
		$product_id = (int) $product->get_id();
		$status     = StockStatus::of( $product );
		if ( $product_id <= 0
			|| $term_id <= 0
			|| ! $product->is_type( 'simple' )
			|| ( method_exists( $product, 'get_status' ) && 'publish' !== $product->get_status() )
			|| ( method_exists( $product, 'is_visible' ) && ! $product->is_visible() )
			|| ! $product->is_purchasable()
			|| ! in_array( $status, array( StockStatus::IN_STOCK, StockStatus::ON_BACKORDER ), true ) ) {
			return null;
		}

		return $this->resolve_series_membership( $product_id, $term_id );
	}

	/**
	 * Filtra en lote IDs candidatos al universo canónico comprable de una serie.
	 *
	 * Es la única rutina que deben usar las superficies para calcular progreso de
	 * colección: limita trabajo, hidrata cada producto y delega publicación,
	 * visibilidad, tipo, compra, stock/preventa y membresía exacta en
	 * `resolve_catalog_series()`. Así un ID oculto nunca llega a
	 * `CustomerCollection` ni puede aparecer en `owned_ids`.
	 *
	 * @param int              $term_id      Serie exacta.
	 * @param array<int,mixed> $candidate_ids IDs devueltos por la taxonomía.
	 * @param int              $max          Techo de hidrataciones.
	 * @return array<int,int>
	 */
	public function resolve_catalog_product_ids( int $term_id, array $candidate_ids, int $max = 999 ): array {
		if ( $term_id <= 0 || $max <= 0 ) {
			return array();
		}

		$ids = array_slice(
			array_values( array_unique( array_filter( array_map( 'intval', $candidate_ids ) ) ) ),
			0,
			$max
		);
		if ( function_exists( '_prime_post_caches' ) && ! empty( $ids ) ) {
			_prime_post_caches( $ids, true, true );
		}

		$catalog_ids = array();
		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product && null !== $this->resolve_catalog_series( $product, $term_id ) ) {
				$catalog_ids[] = $product_id;
			}
		}

		return $catalog_ids;
	}

	/**
	 * Reúne las series con BYP habilitado presentes en el carrito, con sus
	 * ítems elegibles deduplicados por `product_id` (Decisión 8: el tramo
	 * cuenta tomos DISTINTOS; múltiples unidades acumulan `qty`).
	 *
	 * Aplica SIEMPRE los 4 guards del motor (B2): `is_eligible` +
	 * `is_purchasable` + `is_in_stock` + `is_type('simple')`. Un tomo que se
	 * agotó tras agregarse no cuenta para el tramo, ni para el aviso, ni para
	 * las flags (evita el "descuento fantasma" que el checkout rechaza y el
	 * aviso que promete un tramo que el motor no otorga). Variables/grouped no
	 * tienen `regular_price` escalar → quedan fuera (sobre-descuento).
	 *
	 * La identidad de cada serie es `md5(term_id)` (único por construcción, NO
	 * por nombre: dos términos homónimos colisionaban en `md5(name)`).
	 *
	 * Flags (BYP-PREVENTA-CLARIDAD), calculadas solo sobre ítems que pasaron
	 * los guards: `has_reservable` (algún tomo `onbackorder`, el PREDICADO de
	 * quién entra al pack), `has_preventa` (alguno es preventa DE VERDAD según
	 * `Availability`, la PALABRA que se le dice al cliente) y `has_instock`.
	 * Antes `has_preventa` valía para cualquier reservable, y el aviso de pack
	 * prometía «preventa» sobre tomos a pedido.
	 *
	 * @param \WC_Cart $cart Carrito vivo.
	 * @return array<string, array{term_id:int, name:string, tiers:array<int, array{min:int|string, pct:int|string}>, items:array<int, array{regular_price:float, current_price:float, qty:int, product_id:int}>, has_reservable:bool, has_preventa:bool, has_instock:bool}>
	 */
	public function collect( \WC_Cart $cart ): array {
		$series = array();

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! ( $cart_item['data'] instanceof \WC_Product ) ) {
				continue;
			}

			$product    = $cart_item['data'];
			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : (int) $product->get_id();
			$qty        = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			$current    = (float) $product->get_price();
			if ( $qty <= 0 || $current <= 0 ) {
				continue;
			}

			$resolved   = $this->resolve_eligible_series( $product );
			if ( null === $resolved ) {
				continue;
			}

			$term_id = $resolved['term_id'];
			$hash    = md5( (string) $term_id );
			$regular = (float) $product->get_regular_price( 'edit' );
			$regular = $regular > 0 ? $regular : $current;

			if ( ! isset( $series[ $hash ] ) ) {
				$series[ $hash ] = array(
					'term_id'      => $term_id,
					'name'         => $resolved['name'],
					'tiers'        => $resolved['cfg']['tiers'],
					'items'        => array(),
					'has_reservable' => false,
					'has_preventa'   => false,
					'has_instock'    => false,
				);
			}

			if ( StockStatus::is_reservable( $product ) ) {
				$series[ $hash ]['has_reservable'] = true;
				if ( Availability::is_preventa( $product ) ) {
					$series[ $hash ]['has_preventa'] = true;
				}
			} elseif ( StockStatus::is_instock( $product ) ) {
				$series[ $hash ]['has_instock'] = true;
			}

			$is_backorder   = StockStatus::is_reservable( $product );
			$backorder_mode = (string) ( $resolved['cfg']['backorder_mode'] ?? 'inherit' );
			$effective_bo   = ( '' === $backorder_mode || 'inherit' === $backorder_mode )
				? ( function_exists( 'get_option' ) ? (string) get_option( 'akb_series_bundles_backorder_mode', 'best_wins_topup' ) : 'best_wins_topup' )
				: $backorder_mode;

			if ( $is_backorder && 'exclude_backorder' === $effective_bo ) {
				continue;
			}

			// Dedupe por product_id: múltiples unidades del mismo tomo cuentan
			// como un único tomo distinto, acumulando la cantidad.
			if ( isset( $series[ $hash ]['items'][ $product_id ] ) ) {
				$series[ $hash ]['items'][ $product_id ]['qty'] += $qty;
				continue;
			}

			$is_special     = ( 'template_special' === ( $resolved['cfg']['profile'] ?? '' ) )
				|| ( function_exists( 'get_post_meta' ) && 'yes' === get_post_meta( $product_id, '_akb_is_special_format', true ) )
				|| PromotionPolicy::is_omnibus_or_deluxe_name( method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '' );

			$series[ $hash ]['items'][ $product_id ] = array(
				'regular_price'  => $regular,
				// current_price = precio FILTRADO (vista), NO 'edit': así refleja
				// TODAS las rebajas runtime (ofertas WC + TaxonomyDiscount, que aplica
				// por filtro `woocommerce_product_get_price` y NO toca el `_price`).
				// El DiscountEngine descuenta sobre regular pero cede si el filtrado ya
				// es ≤ el target → best-wins real, nunca apila BYP sobre la taxonomía.
				'current_price'  => $current,
				'qty'            => $qty,
				'product_id'     => $product_id,
				'is_backorder'   => $is_backorder,
				'is_special'     => $is_special,
				'backorder_mode' => $backorder_mode,
			);
		}

		return $series;
	}
}
