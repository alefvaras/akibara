<?php
/**
 * Series Bundles — política comercial de tramos para "arma tu pack".
 *
 * Mantiene una única recomendación de margen para las altas nuevas y permite
 * estandarizar, de forma explícita e idempotente, las configuraciones heredadas
 * de un solo tramo. No toca holdouts ni promociones personalizadas.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\SeriesBundles;

defined( 'ABSPATH' ) || exit;

final class PromotionPolicy {
	/** @var array<string,int|null> */
	private array $depth_memo = array();

	private readonly ?\Closure $depth_override;
	private readonly \Closure $raw_reader;
	private readonly \Closure $config_cas;
	private readonly EligibilityGuard $eligibility_guard;

	/** Máximo de intentos CAS dentro de una ejecución del upgrade. */
	private const CAS_MAX_ATTEMPTS = 3;

	public function __construct(
		private readonly Settings $settings,
		private readonly SeriesDepth $depth,
		private readonly CartSeriesCollector $collector,
		?callable $depth_override = null,
		?callable $raw_reader = null,
		?callable $config_cas = null
	) {
		$this->depth_override = null === $depth_override ? null : \Closure::fromCallable( $depth_override );
		$this->raw_reader     = null === $raw_reader
			? fn(): string => $this->settings->raw_byp_config()
			: \Closure::fromCallable( $raw_reader );
		$this->config_cas     = null === $config_cas
			? fn( string $expected_raw, array $config ): bool => $this->settings->compare_and_swap_byp_config( $expected_raw, $config )
			: \Closure::fromCallable( $config_cas );
		$this->eligibility_guard = new EligibilityGuard( $this->settings );
	}

	/** Primer umbral comercial alcanzable. */
	public const FIRST_TIER_MIN = 5;

	/** Segundo umbral comercial alcanzable. */
	public const SECOND_TIER_MIN = 7;

	/**
	 * Determina si el nombre de una serie corresponde a una edición doble, triple,
	 * omnibus o de lujo que amerita tope de 8% (sin segundo tramo del 12%).
	 */
	public static function is_omnibus_or_deluxe_name( string $name ): bool {
		return (bool) preg_match( '/\b(maximum|3 en 1|3-en-1|deluxe|kanzenban|omnibus|integral|novela|the walking dead|hellblazer|homunculus|hajime no ippo|mob psycho|rurouni kenshin)\b/i', $name );
	}

	/**
	 * Recomienda tramos según la cantidad de tomos comprables y el formato de la serie.
	 *
	 * Las ediciones Omnibus/Maximum/Kanzenban/Deluxe/3-en-1 se topan en 8% (un solo tramo de 5 tomos)
	 * para proteger el margen de tomos de alto valor unitario con envío gratis.
	 *
	 * @return array<int, array{min:int,pct:int}>
	 */
	public static function recommended_tiers( int $sellable_count, string $series_name = '' ): array {
		if ( $sellable_count < self::FIRST_TIER_MIN ) {
			return array();
		}

		if ( '' !== $series_name && self::is_omnibus_or_deluxe_name( $series_name ) ) {
			return array( array( 'min' => self::FIRST_TIER_MIN, 'pct' => 8 ) );
		}

		if ( $sellable_count < self::SECOND_TIER_MIN ) {
			return array( array( 'min' => self::FIRST_TIER_MIN, 'pct' => 8 ) );
		}

		return self::standard_tiers();
	}

	/**
	 * Estandariza sólo promociones activas con un tramo heredado conocido.
	 *
	 * @param array<string|int, mixed> $config Configuración BYP completa.
	 * @return array<string|int, mixed>
	 */
	public function standardize_config( array $config ): array {
		$active_term_ids = self::active_term_ids( $config );
		foreach ( $config as $term_id => $entry ) {
			if ( ! is_array( $entry ) || true !== ( $entry['enabled'] ?? false ) ) {
				continue;
			}

			$tiers = $entry['tiers'] ?? null;
			if ( ! self::is_legacy_single_tier( $tiers ) ) {
				continue;
			}

			$count = $this->eligible_depth( (int) $term_id, $active_term_ids, $config );
			if ( null === $count ) {
				continue;
			}

			$term_name   = '';
			if ( function_exists( 'get_term' ) ) {
				try {
					$term_object = @get_term( (int) $term_id, 'pa_serie' );
					if ( $term_object instanceof \WP_Term && ! empty( $term_object->name ) ) {
						$term_name = (string) $term_object->name;
					}
				} catch ( \Throwable $e ) {
					$term_name = '';
				}
			}

			$recommended = self::recommended_tiers( $count, $term_name );
			if ( empty( $recommended ) ) {
				$config[ $term_id ]['enabled'] = false;
				continue;
			}

			$config[ $term_id ]['tiers'] = $recommended;
		}

		return $config;
	}

	/**
	 * Estandariza la opción viva sin perder un guardado administrativo concurrente.
	 *
	 * Cada CAS fallido vuelve a leer y recalcular desde el JSON más reciente. Así
	 * un cambio humano ocurrido entre lectura y escritura se conserva en vez de
	 * reintentarse con un snapshot obsoleto.
	 */
	public function standardize_persisted_config(): bool {
		for ( $attempt = 0; $attempt < self::CAS_MAX_ATTEMPTS; ++$attempt ) {
			$raw     = (string) ( $this->raw_reader )();
			$current = json_decode( $raw, true );
			$current = is_array( $current ) ? $current : array();
			$updated = $this->standardize_config( $current );

			if ( $updated === $current ) {
				return true;
			}

			if ( true === ( $this->config_cas )( $raw, $updated ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Construye el alta masiva como un único plan y evita solapamientos internos.
	 *
	 * @param array<string|int,mixed> $config   Configuración BYP vigente.
	 * @param array<int,int>          $term_ids Términos candidatos en orden estable.
	 * @return array{config:array<string|int,mixed>,enabled:int,shallow:int}
	 */
	public function plan_bulk_enable( array $config, array $term_ids ): array {
		$planned_active = self::active_term_ids( $config );
		$enabled        = 0;
		$shallow        = 0;

		foreach ( $term_ids as $term_id ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 || isset( $config[ (string) $term_id ] ) ) {
				continue;
			}

			$term_name = '';
			if ( function_exists( 'get_term' ) ) {
				try {
					$term_obj = @get_term( $term_id, 'pa_serie' );
					if ( $term_obj instanceof \WP_Term && ! empty( $term_obj->name ) ) {
						$term_name = (string) $term_obj->name;
					}
				} catch ( \Throwable $e ) {
					$term_name = '';
				}
			}

			$depth = $this->eligible_depth( $term_id, $planned_active, $config );
			$tiers = null === $depth ? array() : self::recommended_tiers( $depth, $term_name );
			if ( empty( $tiers ) ) {
				++$shallow;
				continue;
			}

			$config[ (string) $term_id ] = array(
				'enabled' => true,
				'tiers'   => $tiers,
			);
			$planned_active[] = $term_id;
			++$enabled;
		}

		return array(
			'config'  => $config,
			'enabled' => $enabled,
			'shallow' => $shallow,
		);
	}

	/**
	 * Profundidad promocional exacta para un término, independiente de su toggle.
	 *
	 * Reusa el universo SQL de `SeriesDepth`, pero cada candidato atraviesa la
	 * misma frontera del carrito: simple, precio positivo, categoría permitida,
	 * comprable, stock/preventa elegible y membresía exacta. Si la consulta no se
	 * pudo comprobar devuelve null para que migración/admin fallen cerrados.
	 */
	public function eligible_depth(
		int $term_id,
		array $planned_active_term_ids = array(),
		?array $candidate_config = null
	): ?int {
		if ( $term_id <= 0 ) {
			return 0;
		}

		$planned_active_term_ids = self::normalize_term_ids( $planned_active_term_ids, $term_id );
		$config_fingerprint = null === $candidate_config
			? 'persisted'
			: hash( 'sha256', (string) wp_json_encode( $candidate_config ) );
		$memo_key           = $term_id . ':' . implode( ',', $planned_active_term_ids ) . ':' . $config_fingerprint;
		if ( array_key_exists( $memo_key, $this->depth_memo ) ) {
			return $this->depth_memo[ $memo_key ];
		}
		if ( null !== $this->depth_override ) {
			$value = ( $this->depth_override )( $term_id, $planned_active_term_ids );

			return $this->depth_memo[ $memo_key ] = null === $value ? null : max( 0, (int) $value );
		}

		$ids = $this->depth->sellable_ids( $term_id );
		if ( null === $ids ) {
			return $this->depth_memo[ $memo_key ] = null;
		}
		if ( function_exists( '_prime_post_caches' ) && ! empty( $ids ) ) {
			_prime_post_caches( $ids, true, true );
		}

		$count = 0;
		foreach ( $ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			// Un solo tomo compartido vuelve ambigua la resolución del collector.
			// No basta con descontarlo del conteo: la serie completa no debe quedar
			// activa junto al otro término del plan. Esta comprobación debe ocurrir
			// ANTES del collector: cuando el otro término ya está persistido, el
			// collector descarta el tomo compartido y ocultaría el solapamiento.
			if ( self::product_overlaps_plan( (int) $product_id, $term_id, $planned_active_term_ids ) ) {
				return $this->depth_memo[ $memo_key ] = 0;
			}

			$is_candidate = null === $candidate_config
				? $this->collector->is_promotion_candidate_for_term( $product, $term_id )
				: $this->is_intrinsic_candidate_for_term( $product, $term_id );
			if ( ! $is_candidate ) {
				continue;
			}

			++$count;
		}

		return $this->depth_memo[ $memo_key ] = $count;
	}

	/**
	 * Valida una serie habilitada contra el snapshot completo que quedaría activo.
	 *
	 * @param array<int,mixed>          $tiers            Tramos normalizados propuestos.
	 * @param array<string|int,mixed>   $candidate_config Snapshot candidato antes de guardar esta serie.
	 * @return string|null Mensaje de rechazo o null cuando la propuesta es segura.
	 */
	public function validate_enabled_tiers( int $term_id, array $tiers, array $candidate_config ): ?string {
		$max_min = 0;
		foreach ( $tiers as $tier ) {
			$values = self::validated_tier_values( $tier );
			if ( null === $values ) {
				return 'La configuración contiene un tramo malformado';
			}
			$max_min = max( $max_min, $values['min'] );
		}

		if ( 0 === $max_min ) {
			return 'Una serie habilitada debe tener al menos un tramo de descuento';
		}

		$depth = $this->eligible_depth( $term_id, self::active_term_ids( $candidate_config ), $candidate_config );
		if ( null === $depth ) {
			return 'No se pudo verificar el catálogo de la serie; no se guardaron sus cambios';
		}

		if ( $max_min > $depth ) {
			return sprintf(
				'No se puede habilitar: tiene tramos inalcanzables o comparte tomos con otra serie activa (máximo verificable: %d)',
				$depth
			);
		}

		return null;
	}

	/** Filtra tramos a los que la serie puede llegar con su catálogo actual. */
	public function reachable_tiers( int $term_id, array $tiers ): array {
		$depth = $this->eligible_depth( $term_id );
		if ( null === $depth ) {
			return array();
		}

		return array_values(
			array_filter(
				$tiers,
				static function ( $tier ) use ( $depth ): bool {
					$values = self::validated_tier_values( $tier );

					return null !== $values && $values['min'] <= $depth;
				}
			)
		);
	}

	/**
	 * Convierte un tramo sólo si su forma y sus límites son íntegramente válidos.
	 *
	 * @param mixed $tier Tramo posiblemente heredado o corrupto.
	 * @return array{min:int,pct:int}|null
	 */
	private static function validated_tier_values( $tier ): ?array {
		if ( ! is_array( $tier ) || ! array_key_exists( 'min', $tier ) || ! array_key_exists( 'pct', $tier ) ) {
			return null;
		}

		if ( ! self::is_integer_value( $tier['min'] ) || ! self::is_integer_value( $tier['pct'] ) ) {
			return null;
		}

		$min = (int) $tier['min'];
		$pct = (int) $tier['pct'];
		if (
			$min < VolumeTierResolver::MIN_QUANTITY
			|| $pct < VolumeTierResolver::PCT_MIN
			|| $pct > VolumeTierResolver::pct_max()
			|| ( $pct > 0 && $min < VolumeTierResolver::DISCOUNT_MIN_QUANTITY )
		) {
			return null;
		}

		return array( 'min' => $min, 'pct' => $pct );
	}

	/** @param mixed $value Valor posiblemente proveniente de JSON legacy. */
	private static function is_integer_value( $value ): bool {
		return is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d+$/D', $value ) );
	}

	/**
	 * Aplica los guards promocionales sin consultar el toggle persistido.
	 *
	 * El guardado administrativo valida un snapshot que todavía no existe en
	 * wp_options. Reusar el collector completo haría que una serie desactivada en
	 * ese mismo POST siguiera bloqueando los productos compartidos por su estado
	 * anterior. Los predicados son los mismos del collector, salvo precisamente
	 * la resolución de toggles, que ya se controla con `$planned_active_term_ids`.
	 */
	private function is_intrinsic_candidate_for_term( \WC_Product $product, int $term_id ): bool {
		$product_id = (int) $product->get_id();

		return $product_id > 0
			&& $term_id > 0
			&& (float) $product->get_price() > 0
			&& $this->eligibility_guard->is_eligible( $product_id )
			&& $product->is_purchasable()
			&& $this->settings->is_stock_eligible( $product )
			&& $product->is_type( 'simple' )
			&& null !== $this->collector->resolve_series_membership( $product_id, $term_id );
	}

	/**
	 * Extrae términos realmente activos del snapshot que se va a ampliar.
	 *
	 * @param array<string|int,mixed> $config
	 * @return array<int,int>
	 */
	private static function active_term_ids( array $config ): array {
		$term_ids = array();
		foreach ( $config as $term_id => $entry ) {
			if (
				is_array( $entry )
				&& ! empty( $entry['enabled'] )
				&& isset( $entry['tiers'] )
				&& is_array( $entry['tiers'] )
				&& ! empty( $entry['tiers'] )
			) {
				$term_ids[] = (int) $term_id;
			}
		}

		return self::normalize_term_ids( $term_ids );
	}

	/**
	 * Normaliza un conjunto de términos para comparación y memo deterministas.
	 *
	 * @param array<int,mixed> $term_ids
	 * @return array<int,int>
	 */
	private static function normalize_term_ids( array $term_ids, int $except = 0 ): array {
		$normalized = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $term_ids ),
					static fn( int $term_id ): bool => $term_id > 0 && $term_id !== $except
				)
			)
		);
		sort( $normalized, SORT_NUMERIC );

		return $normalized;
	}

	/**
	 * Detecta si el producto pertenece también a un término ya activo del plan.
	 *
	 * Ante una lectura no verificable falla cerrado: habilitar con membresía
	 * ambigua haría que el collector descartara el descuento en runtime.
	 *
	 * @param array<int,int> $planned_active_term_ids
	 */
	private static function product_overlaps_plan( int $product_id, int $term_id, array $planned_active_term_ids ): bool {
		if ( empty( $planned_active_term_ids ) ) {
			return false;
		}

		$terms = get_the_terms( $product_id, SeriesDepth::TAXONOMY );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return true;
		}

		foreach ( $terms as $term ) {
			$other_id = isset( $term->term_id ) ? (int) $term->term_id : 0;
			if ( $other_id !== $term_id && in_array( $other_id, $planned_active_term_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Identifica las dos formas heredadas autorizadas para la migración.
	 *
	 * @param mixed $tiers Tramos candidatos.
	 */
	private static function is_legacy_single_tier( $tiers ): bool {
		if ( ! is_array( $tiers ) || 1 !== count( $tiers ) ) {
			return false;
		}

		$tier = array_values( $tiers )[0];
		if (
			! is_array( $tier )
			|| 2 !== count( $tier )
			|| ! array_key_exists( 'min', $tier )
			|| ! array_key_exists( 'pct', $tier )
		) {
			return false;
		}

		return self::FIRST_TIER_MIN === $tier['min'] && in_array( $tier['pct'], array( 8, 10 ), true );
	}

	/**
	 * Política uniforme: 8% desde cinco tomos y 10% desde siete.
	 *
	 * @return array<int, array{min:int,pct:int}>
	 */
	private static function standard_tiers(): array {
		return array(
			array( 'min' => self::FIRST_TIER_MIN, 'pct' => 8 ),
			array( 'min' => self::SECOND_TIER_MIN, 'pct' => 10 ),
		);
	}

	/**
	 * Configuración oficial de Arma tu Pack para una serie concreta (Lista Blanca Estricta v1.1).
	 * Si la serie no fue agregada por el dueño a akb_series_bundles_byp, retorna null (0% OFF).
	 *
	 * @param int $serie_term_id ID de término pa_serie.
	 * @return array{enabled:bool, profile:string, backorder_mode:string, tiers:array<int, array{min:int, pct:int}>}|null
	 */
	public static function get_series_bundle_config( int $serie_term_id ): ?array {
		if ( function_exists( 'get_option' ) && 'yes' !== get_option( 'akb_series_bundles_enabled', 'yes' ) ) {
			return null;
		}

		$raw_json = function_exists( 'get_option' ) ? (string) get_option( 'akb_series_bundles_byp', '{}' ) : '{}';
		$byp_map  = json_decode( $raw_json, true );
		if ( ! is_array( $byp_map ) ) {
			return null;
		}

		$entry = $byp_map[ $serie_term_id ] ?? ( $byp_map[ (string) $serie_term_id ] ?? null );
		if ( empty( $entry ) || ! is_array( $entry ) ) {
			// REGLA INQUEBRANTABLE DEL DUEÑO: Si la serie no está en la lista blanca, NO tiene pack.
			return null;
		}

		// Retrocompatibilidad con formato antiguo que guardaba directamente un array de tramos:
		if ( isset( $entry[0] ) && is_array( $entry[0] ) ) {
			return array(
				'enabled'        => true,
				'profile'        => 'custom',
				'backorder_mode' => 'inherit',
				'tiers'          => self::sanitize_tiers( $entry ),
			);
		}

		if ( isset( $entry['enabled'] ) && false === (bool) $entry['enabled'] ) {
			return null;
		}

		$explicit_profile = isset( $entry['profile'] ) ? (string) $entry['profile'] : '';
		if ( 'template_standard' === $explicit_profile ) {
			$profile = 'template_standard';
			$tiers   = self::get_standard_template_tiers();
		} elseif ( 'template_special' === $explicit_profile ) {
			$profile = 'template_special';
			$tiers   = self::get_special_template_tiers();
		} elseif ( ! empty( $entry['tiers'] ) && is_array( $entry['tiers'] ) ) {
			$tiers   = self::sanitize_tiers( $entry['tiers'] );
			$profile = '' !== $explicit_profile ? $explicit_profile : 'custom';
		} else {
			$profile = 'template_standard';
			$tiers   = self::get_standard_template_tiers();
		}

		return array(
			'enabled'        => true,
			'profile'        => $profile,
			'backorder_mode' => (string) ( $entry['backorder_mode'] ?? 'inherit' ),
			'tiers'          => $tiers,
		);
	}

	public static function get_standard_template_tiers(): array {
		$ceiling = function_exists( 'get_option' ) ? (int) get_option( 'akb_discount_max_pct', 15 ) : 15;
		$tiers   = array();

		$t0_min = function_exists( 'get_option' ) ? max( 3, (int) get_option( 'akb_sb_tier0_min_qty', 3 ) ) : 3;
		$t0_pct = function_exists( 'get_option' ) ? min( $ceiling, max( 0, (int) get_option( 'akb_sb_tier0_pct', 0 ) ) ) : 0;
		if ( $t0_pct > 0 ) {
			$tiers[] = array( 'min' => $t0_min, 'pct' => $t0_pct );
		}

		$t1_min  = function_exists( 'get_option' ) ? max( 3, (int) get_option( 'akb_sb_tier1_min_qty', 5 ) ) : 5;
		$t1_pct  = function_exists( 'get_option' ) ? min( $ceiling, max( 1, (int) get_option( 'akb_sb_tier1_pct', 8 ) ) ) : 8;
		$tiers[] = array( 'min' => $t1_min, 'pct' => $t1_pct );

		$t2_min  = function_exists( 'get_option' ) ? max( $t1_min + 1, (int) get_option( 'akb_sb_tier2_min_qty', 7 ) ) : 7;
		$t2_pct  = function_exists( 'get_option' ) ? min( $ceiling, max( $t1_pct, (int) get_option( 'akb_sb_tier2_pct', 10 ) ) ) : 10;
		$tiers[] = array( 'min' => $t2_min, 'pct' => $t2_pct );

		return $tiers;
	}

	public static function get_special_template_tiers(): array {
		$ceiling = function_exists( 'get_option' ) ? (int) get_option( 'akb_discount_max_pct', 15 ) : 15;
		$min_qty = function_exists( 'get_option' ) ? max( 3, (int) get_option( 'akb_sb_special_min_qty', 5 ) ) : 5;
		$max_pct = function_exists( 'get_option' ) ? min( $ceiling, max( 1, (int) get_option( 'akb_sb_special_max_pct', 8 ) ) ) : 8;
		return array(
			array( 'min' => $min_qty, 'pct' => $max_pct ),
		);
	}

	public static function sanitize_tiers( array $tiers ): array {
		$sanitized = array();
		$ceiling   = function_exists( 'get_option' ) ? (int) get_option( 'akb_discount_max_pct', 15 ) : 15;
		foreach ( $tiers as $t ) {
			if ( is_array( $t ) && isset( $t['min'], $t['pct'] ) ) {
				$min = (int) $t['min'];
				$pct = (int) $t['pct'];
				if ( $min >= 3 && $pct >= 0 ) {
					$sanitized[] = array(
						'min' => $min,
						'pct' => min( $ceiling, $pct ),
					);
				}
			}
		}
		usort( $sanitized, static fn( $a, $b ) => $a['min'] <=> $b['min'] );
		return $sanitized;
	}
}
