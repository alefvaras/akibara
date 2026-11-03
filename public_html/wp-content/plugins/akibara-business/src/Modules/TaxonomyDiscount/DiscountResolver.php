<?php
/**
 * DiscountResolver — lógica pura: producto → precio final descontado.
 *
 * Multi-taxonomía: itera todas las taxonomías soportadas, recoge los términos
 * del producto en cada una, lee `_akb_taxonomy_discount_pct` term-meta y gana
 * el % más alto (OR semantics).
 *
 * Memoiza por request para evitar O(N × M × T) en loops (M términos, T taxonomías).
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.16.1
 */
final class DiscountResolver {

	private Settings $settings;

	/** @var array<int, float|null> */
	private array $cache = array();

	/** Estado de autoridad compartido con Settings y el proveedor estricto. */
	private PriceAuthority $authority;

	/**
	 * Productos cuyo precio memoizado proviene del camino ESTRICTO. El memo
	 * tolerante y el autoritativo comparten estructura, así que sin esta marca
	 * `get_price_html()` no podría distinguir cuál está reutilizando.
	 *
	 * @var array<int, true>
	 */
	private array $strict_ids = array();

	public function __construct( Settings $settings, ?PriceAuthority $authority = null ) {
		$this->settings  = $settings;
		$this->authority = $authority ?? $settings->authority();
	}

	/**
	 * Resuelve el precio con AUTORIDAD: entra en modo estricto, descarta
	 * cualquier memo tolerante previo de este producto y recalcula leyendo en
	 * crudo. El resultado queda memoizado como autoritativo, de modo que un
	 * `get_price_html()` posterior reutilice ESTE valor y no recalcule contra
	 * caches contaminados.
	 *
	 * @param \WC_Product $product Producto.
	 * @return float|null
	 */
	public function resolve_strict( \WC_Product $product ): ?float {
		if ( null === $this->settings->adopted_snapshot() ) {
			$this->settings->enter_strict_mode();
		}

		$product_id = $product->get_id();

		// Nunca heredar el memo del camino tolerante: pudo calcularse con la
		// configuración `A` mientras la base ya está en `B`.
		unset( $this->cache[ $product_id ], $this->strict_ids[ $product_id ] );

		$result = $this->compute( $product );

		if ( $this->is_authoritative() ) {
			$this->cache[ $product_id ]      = $result;
			$this->strict_ids[ $product_id ] = true;

			// Con foto ADOPTADA el token lo gobierna el proveedor y el reader no
			// se toca: recalcular su huella acá volvería a consultar options y
			// atributos, que es justo el SQL que la adopción elimina.
			if ( null === $this->settings->adopted_snapshot() ) {
				$this->authority->adopt_fingerprint( $this->settings->reader()->config_fingerprint() );
			}
		}

		return $result;
	}

	/**
	 * Liga este resolver a la foto adoptada de Search: a partir de acá toda la
	 * configuración sale de ella y no se relee nada.
	 *
	 * @param AdoptedSnapshot $snapshot Foto validada.
	 * @return void
	 */
	public function bind_adopted_snapshot( AdoptedSnapshot $snapshot ): void {
		$this->cache      = array();
		$this->strict_ids = array();
		$this->settings->adopt( $snapshot );
	}

	/** ¿Sigue ligado a esta foto exacta? */
	public function is_bound_to( AdoptedSnapshot $snapshot ): bool {
		return $this->settings->is_bound_to( $snapshot );
	}

	/**
	 * Marca la autoridad como perdida desde afuera (el proveedor al pasar a
	 * FAILED), para que el storefront también lo vea.
	 *
	 * @param string $why Motivo.
	 * @return void
	 */
	public function mark_authority_failed( string $why ): void {
		$this->authority->fail( $why );
	}

	/**
	 * Generación monótona de la autoridad. Avanza en cada
	 * `invalidate_derived_memos()`, así que un consumidor que la capture al
	 * adoptar puede detectar una invalidación AUNQUE todavía no haya nada
	 * ligado que comprobar.
	 */
	public function authority_generation(): int {
		return $this->authority->epoch();
	}

	/**
	 * Snapshot canónico COMPARABLE con el de Search: opciones efectivas,
	 * timezone, `max_pct`, decimales y taxonomías efectivas.
	 *
	 * @return array<string, mixed>
	 */
	public function authoritative_snapshot(): array {
		return $this->settings->reader()->canonical_snapshot();
	}

	/**
	 * Descarta TODOS los memos derivados de esta instancia y avanza el epoch.
	 *
	 * Se llama antes de capturar la configuración `B`, de modo que nada
	 * calculado con `A` —ni el precio memoizado, ni el mapa de taxonomías, ni el
	 * % por término— pueda sobrevivir al cambio.
	 *
	 * NO es parte del contrato con Search: lo que Search exige por duck typing
	 * son los tres métodos de `SearchPriceProvider` —`adopt_search_snapshot()`,
	 * `snapshot_is_fresh()` y `authoritative_snapshot()`— y nada de esta clase
	 * (ver el `add_filter` de `akb_search_effective_price` en `Plugin.php`).
	 *
	 * @return void
	 */
	public function invalidate_derived_memos(): void {
		$this->cache      = array();
		$this->strict_ids = array();
		$this->settings->invalidate_derived_memos();
		$this->authority->invalidate();
	}

	/**
	 * ¿El resultado de esta instancia es AUTORITATIVO?
	 *
	 * `null` de `resolve()` significa "este producto no lleva descuento" **sólo
	 * si** esto devuelve true. Si devuelve false, el `null` sólo significa "no
	 * pude averiguarlo", y quien materialice un precio a partir de ahí estará
	 * sellando una suposición.
	 *
	 * Incluye el estado del `Settings` inyectado: el fallo puede haber ocurrido
	 * en la lectura de term-meta, un nivel más abajo.
	 */
	public function is_authoritative(): bool {
		return $this->authority->available();
	}

	/** Motivo del primer fallo de autoridad (propio o del Settings). */
	public function authority_error(): string {
		return $this->authority->error();
	}

	/**
	 * Marca la instancia como no autoritativa, conservando el primer motivo.
	 *
	 * @param string $why Motivo.
	 * @return void
	 */
	private function note_authority_failure( string $why ): void {
		$this->authority->fail( $why );
	}

	/**
	 * Devuelve el precio final descontado de un producto, o null si no aplica.
	 *
	 * @param \WC_Product $product Producto a evaluar.
	 * @return float|null
	 */
	public function resolve( \WC_Product $product ): ?float {
		$product_id = $product->get_id();

		if ( array_key_exists( $product_id, $this->cache ) ) {
			return $this->cache[ $product_id ];
		}

		$result = $this->compute( $product );

		// Un resultado calculado sin autoridad NO se memoiza: memoizarlo lo
		// convertiría en "verdad" para el resto de la petición y borraría la
		// diferencia entre "sin descuento" y "no se pudo saber".
		if ( $this->is_authoritative() ) {
			$this->cache[ $product_id ] = $result;
		}

		return $result;
	}

	/**
	 * Devuelve el % aplicado a un producto, o 0 si no aplica.
	 *
	 * @param \WC_Product $product Producto a evaluar.
	 * @return int Porcentaje 0..tope editorial (Settings::MAX_PCT, default 15).
	 */
	public function get_applied_pct( \WC_Product $product ): int {
		if ( ! $this->settings->is_enabled() ) {
			return 0;
		}
		if ( ! $this->settings->is_stock_eligible( $product ) ) {
			return 0;
		}
		return $this->resolve_term_pct( $product );
	}

	/**
	 * Limpia el cache (útil para tests).
	 */
	public function flush(): void {
		$this->cache      = array();
		$this->strict_ids = array();
	}

	/**
	 * Firma de la config global que afecta el precio computado de un producto
	 * más allá del % por término: vigencia (enabled) y política de rebaja.
	 *
	 * Se usa en el hash de variation prices de WC para invalidar su cache cuando
	 * el admin cambia la policy o desactiva el módulo (el % por sí solo no cambia,
	 * pero el precio resultante sí), evitando servir precios cacheados stale.
	 *
	 * @return string
	 */
	public function price_config_signature(): string {
		return ( $this->settings->is_enabled() ? '1' : '0' ) . ':' . $this->settings->get_sale_policy();
	}

	private function compute( \WC_Product $product ): ?float {
		if ( ! $this->settings->is_enabled() ) {
			return null;
		}
		if ( ! $this->settings->is_stock_eligible( $product ) ) {
			return null;
		}

		$pct = $this->resolve_term_pct( $product );
		if ( 0 === $pct ) {
			return null;
		}

		$regular = $product->get_regular_price( 'edit' );
		if ( '' === $regular || ! is_numeric( $regular ) ) {
			return null;
		}
		$regular = (float) $regular;
		if ( $regular <= 0 ) {
			return null;
		}

		$factor = ( 100 - $pct ) / 100;

		// Una oferta solo cuenta si está VIGENTE. WC respeta date_on_sale_from/to:
		// una oferta programada a futuro o ya vencida tiene `_sale_price` seteado
		// pero `is_on_sale()` = false. Sin gatear por is_on_sale, el resolver trataba
		// esos productos como "en oferta" → bajo skip_if_on_sale perdía el descuento
		// editorial, y bajo greatest_wins/stack aplicaba un precio de oferta fantasma
		// (undercharge). Verificado con datos sembrados (programada/vencida/activa).
		$sale_raw   = $product->get_sale_price( 'edit' );
		$has_active = $product->is_on_sale( 'edit' ) && is_numeric( $sale_raw ) && '' !== $sale_raw;
		$sale       = $has_active ? (float) $sale_raw : null;

		if ( null === $sale || $sale <= 0 || $sale >= $regular ) {
			return $this->round( $regular * $factor );
		}

		switch ( $this->settings->get_sale_policy() ) {
			case Settings::POLICY_SKIP_IF_ON_SALE:
				return null;

			case Settings::POLICY_FROM_REGULAR:
				return $this->round( $regular * $factor );

			case Settings::POLICY_STACK_ON_SALE:
				return $this->round( $sale * $factor );

			case Settings::POLICY_GREATEST_WINS:
			default:
				return $this->round( min( $regular * $factor, $sale ) );
		}
	}

	/**
	 * Itera todas las taxonomías soportadas, recoge todos los términos del
	 * producto en cada una, lee term-meta y gana el % más alto.
	 *
	 * `get_the_terms` por taxonomía lee el object-term cache que el main loop
	 * ya primó (`update_post_term_cache`): 0 queries en caliente. La versión
	 * anterior (`wp_get_object_terms` multi-tax) era 1 query SQL por producto
	 * SIEMPRE — 24 queries extra por refresh AJAX del archive.
	 *
	 * @param \WC_Product $product Producto.
	 * @return int Porcentaje 0..tope editorial (Settings::MAX_PCT, default 15).
	 */
	private function resolve_term_pct( \WC_Product $product ): int {
		$product_id = $product->get_parent_id() > 0 ? $product->get_parent_id() : $product->get_id();

		$taxonomies = $this->settings->get_supported_taxonomy_slugs();
		if ( empty( $taxonomies ) ) {
			return 0;
		}

		if ( $this->settings->in_strict_mode() ) {
			// Relaciones por JOIN, sin object cache: `update_object_term_cache()`
			// puede haber dejado un vacío que es indistinguible de "este producto
			// no tiene términos".
			$terms = $this->settings->reader()->object_term_ids( $product_id, $taxonomies );

			$max = 0;
			foreach ( $terms as $term_id ) {
				$pct = $this->settings->get_term_pct( (int) $term_id );
				if ( $pct > $max ) {
					$max = $pct;
				}
			}
			return $max;
		}

		$terms = array();
		foreach ( $taxonomies as $taxonomy ) {
			try {
				$tax_terms = get_the_terms( $product_id, $taxonomy );
			} catch ( \Throwable $e ) {
				$this->note_authority_failure(
					sprintf( 'get_the_terms(%d, %s) lanzó %s: %s', $product_id, $taxonomy, get_class( $e ), $e->getMessage() )
				);
				return 0;
			}

			// La respuesta admite array de WP_Term, false y WP_Error. Un error de
			// lectura no significa producto sin términos y no autoriza publicar precio base.

			if ( function_exists( 'is_wp_error' ) && is_wp_error( $tax_terms ) ) {
				$this->note_authority_failure(
					sprintf(
						'get_the_terms(%d, %s) devolvió WP_Error: %s',
						$product_id,
						$taxonomy,
						method_exists( $tax_terms, 'get_error_message' ) ? (string) $tax_terms->get_error_message() : 'sin mensaje'
					)
				);
				return 0;
			}

			if ( false === $tax_terms || null === $tax_terms ) {
				continue; // ausencia LEGÍTIMA de términos en esta taxonomía.
			}

			if ( ! is_array( $tax_terms ) ) {
				$this->note_authority_failure(
					sprintf( 'get_the_terms(%d, %s) devolvió una forma inválida (%s)', $product_id, $taxonomy, gettype( $tax_terms ) )
				);
				return 0;
			}

			foreach ( $tax_terms as $tax_term ) {
				if ( ! is_object( $tax_term ) || ! isset( $tax_term->term_id ) ) {
					$this->note_authority_failure(
						sprintf( 'get_the_terms(%d, %s) devolvió un término sin term_id', $product_id, $taxonomy )
					);
					return 0;
				}
				$terms[] = (int) $tax_term->term_id;
			}
		}
		if ( empty( $terms ) ) {
			return 0;
		}

		$max = 0;
		foreach ( $terms as $term_id ) {
			$pct = $this->settings->get_term_pct( (int) $term_id );
			if ( $pct > $max ) {
				$max = $pct;
			}
		}
		return $max;
	}

	private function round( float $price ): float {
		$adopted = $this->settings->adopted_snapshot();
		if ( null !== $adopted ) {
			return round( $price, $adopted->decimals() );
		}

		if ( $this->settings->in_strict_mode() ) {
			// `wc_get_price_decimals()` lee una option cacheada: un default
			// nacido de un cache contaminado cambia el precio publicado
			//.
			return round( $price, $this->settings->reader()->price_decimals() );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 0;
		return round( $price, $decimals );
	}
}
