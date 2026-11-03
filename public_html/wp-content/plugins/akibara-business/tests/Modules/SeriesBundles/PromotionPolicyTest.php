<?php
/**
 * Pruebas de la política comercial de promociones por serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\Admin\BypConfigController;
use Akibara\Business\Modules\SeriesBundles\PromotionPolicy;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PromotionPolicyProduct extends \WC_Product {
	public string $current_price = '10000';

	public function get_price(): string {
		return $this->current_price;
	}
}

final class PromotionPolicyWpdb {
	public string $posts = 'wp_posts';
	public string $term_relationships = 'wp_term_relationships';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $postmeta = 'wp_postmeta';

	/** @param array<int,array<int,int>> $ids_by_term */
	public function __construct( private array $ids_by_term ) {
	}

	public function prepare( string $query, int $term_id, string $taxonomy ): string {
		unset( $query, $taxonomy );

		return (string) $term_id;
	}

	/** @return array<int,int> */
	public function get_col( string $query ): array {
		return $this->ids_by_term[ (int) $query ] ?? array();
	}
}

final class PromotionPolicyTest extends TestCase {
	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `series_names()` traduce los IDs a nombres para que los errores del panel
		// digan «Naruto» y no «Serie #364». Brain Monkey deja el símbolo definido
		// pero sin comportamiento, así que hay que darle uno explícito.
		Functions\when( 'get_terms' )->alias(
			static function ( array $args ): array {
				$out = array();
				foreach ( (array) ( $args['include'] ?? array() ) as $id ) {
					$term           = new \WP_Term( (int) $id, 'serie-' . (int) $id, 'Serie ' . (int) $id );
					$term->taxonomy = 'pa_serie';
					$out[]          = $term;
				}
				return $out;
			}
		);

		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox No recomienda un descuento que la serie no puede alcanzar.
	 */
	public function test_recommends_no_tiers_below_five_sellable_volumes(): void {
		self::assertSame( array(), PromotionPolicy::recommended_tiers( 4 ) );
	}

	/**
	 * @testdox Recomienda 8% desde cinco tomos en series de cinco o seis.
	 */
	public function test_recommends_only_the_reachable_tier_for_medium_series(): void {
		$expected = array( array( 'min' => 5, 'pct' => 8 ) );

		self::assertSame( $expected, PromotionPolicy::recommended_tiers( 5 ) );
		self::assertSame( $expected, PromotionPolicy::recommended_tiers( 6 ) );
	}

	/**
	 * @testdox Recomienda la progresión uniforme 8% y 10% desde siete tomos.
	 */
	public function test_recommends_both_margin_safe_tiers_for_deep_series(): void {
		self::assertSame(
			array(
				array( 'min' => 5, 'pct' => 8 ),
				array( 'min' => 7, 'pct' => 10 ),
			),
			PromotionPolicy::recommended_tiers( 7 )
		);
	}

	/**
	 * @testdox Cappa a 8% máximo las ediciones Omnibus, Deluxe, Maximum y 3-en-1 para proteger margen y envío gratis.
	 */
	public function test_caps_omnibus_and_deluxe_editions_at_eight_percent(): void {
		$expected = array( array( 'min' => 5, 'pct' => 8 ) );

		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Maximum Berserk' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'One Piece 3 en 1 (Planeta Cómic)' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Gantz Deluxe Edition' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Yu Yu Hakusho Kanzenban' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Homunculus' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Hajime no Ippo' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Mob Psycho 100' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Rurouni Kenshin' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Biblioteca Vertigo. John Constantine: Hellblazer' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'The Walking Dead' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Re:Zero (novela)' ) );
		self::assertTrue( PromotionPolicy::is_omnibus_or_deluxe_name( 'Akira Edición Integral' ) );
		self::assertFalse( PromotionPolicy::is_omnibus_or_deluxe_name( 'Fire Force' ) );
		self::assertFalse( PromotionPolicy::is_omnibus_or_deluxe_name( 'Dai Dark' ) );
		self::assertFalse( PromotionPolicy::is_omnibus_or_deluxe_name( 'A Silent Voice' ) );

		self::assertSame( $expected, PromotionPolicy::recommended_tiers( 9, 'Maximum Berserk' ) );
		self::assertSame( $expected, PromotionPolicy::recommended_tiers( 12, 'One Piece 3 en 1' ) );
	}

	/**
	 * @testdox Estandariza sólo promociones activas con un tramo heredado conocido.
	 */
	public function test_standardizes_only_enabled_known_legacy_tiers(): void {
		$config = array(
			'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			'11' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ), 'owner_note' => 'preservar' ),
			'12' => array( 'enabled' => false, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			'13' => array(
				'enabled' => true,
				'tiers'   => array(
					array( 'min' => 4, 'pct' => 7 ),
					array( 'min' => 8, 'pct' => 11 ),
				),
			),
			'14' => array( 'enabled' => true, 'tiers' => array( array( 'min' => '5', 'pct' => '10' ) ) ),
			'15' => 'entrada-malformada',
			'16' => array( 'enabled' => true, 'tiers' => array( array( 'pct' => 10, 'min' => 5 ) ) ),
			'17' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10, 'source' => 'manual' ) ) ),
		);

		$standardized = $this->policy_with_depths( array( 10 => 8, 11 => 8, 16 => 8 ) )->standardize_config( $config );
		$expected      = array(
			array( 'min' => 5, 'pct' => 8 ),
			array( 'min' => 7, 'pct' => 10 ),
		);

		self::assertSame( $expected, $standardized['10']['tiers'] );
		self::assertSame( $expected, $standardized['11']['tiers'] );
		self::assertSame( 'preservar', $standardized['11']['owner_note'] );
		self::assertSame( $config['12'], $standardized['12'], 'Los holdouts deben permanecer intactos.' );
		self::assertSame( $config['13'], $standardized['13'], 'Los tramos personalizados no se deben sobrescribir.' );
		self::assertSame( $config['14'], $standardized['14'], 'Una forma no exacta no se debe migrar por coerción.' );
		self::assertSame( $config['15'], $standardized['15'] );
		self::assertSame( $expected, $standardized['16']['tiers'], 'El orden de las claves no cambia el significado del tramo.' );
		self::assertSame( $config['17'], $standardized['17'], 'Un tramo con metadatos adicionales es personalizado.' );
	}

	/**
	 * @testdox Estandarizar dos veces produce exactamente el mismo resultado.
	 */
	public function test_standardization_is_idempotent(): void {
		$config = array(
			'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			'11' => array( 'enabled' => false, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
		);

		$policy = $this->policy_with_depths( array( 10 => 8, 11 => 8 ) );
		$once = $policy->standardize_config( $config );

		self::assertSame( $once, $policy->standardize_config( $once ) );
	}

	/** @testdox La migración respeta la profundidad real de series cortas, medianas y largas. */
	public function test_standardization_never_adds_an_unreachable_tier(): void {
		$config = array(
			'4' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ), 'note' => 'keep' ),
			'5' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			'7' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
		);

		$actual = $this->policy_with_depths( array( 4 => 4, 5 => 5, 7 => 7 ) )->standardize_config( $config );

		self::assertFalse( $actual['4']['enabled'] );
		self::assertSame( 'keep', $actual['4']['note'] );
		self::assertSame( array( array( 'min' => 5, 'pct' => 8 ) ), $actual['5']['tiers'] );
		self::assertSame(
			array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) ),
			$actual['7']['tiers']
		);
	}

	/** @testdox Un CAS perdido vuelve a leer y preserva el cambio humano antes de reintentar. */
	public function test_persisted_standardization_recomputes_after_a_lost_cas(): void {
		$initial = (string) json_encode(
			array(
				'10' => array(
					'enabled'    => true,
					'tiers'      => array( array( 'min' => 5, 'pct' => 10 ) ),
					'owner_note' => 'snapshot inicial',
				),
			)
		);
		$human = (string) json_encode(
			array(
				'10' => array(
					'enabled'    => true,
					'tiers'      => array( array( 'min' => 5, 'pct' => 10 ) ),
					'owner_note' => 'cambio humano',
				),
				'99' => array(
					'enabled' => false,
					'tiers'   => array( array( 'min' => 4, 'pct' => 7 ) ),
				),
			)
		);
		$reads  = 0;
		$writes = array();
		$policy = $this->policy_with_dependencies(
			static fn( int $term_id, array $_planned ): int => 10 === $term_id ? 8 : 0,
			static function () use ( &$reads, $initial, $human ): string {
				return 0 === $reads++ ? $initial : $human;
			},
			static function ( string $expected_raw, array $updated ) use ( &$writes ): bool {
				$writes[] = array( 'expected' => $expected_raw, 'updated' => $updated );

				return count( $writes ) > 1;
			}
		);

		self::assertTrue( $policy->standardize_persisted_config() );
		self::assertSame( 2, $reads );
		self::assertCount( 2, $writes );
		self::assertSame( $initial, $writes[0]['expected'] );
		self::assertSame( $human, $writes[1]['expected'] );
		self::assertSame( 'cambio humano', $writes[1]['updated']['10']['owner_note'] );
		self::assertSame(
			array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) ),
			$writes[1]['updated']['10']['tiers']
		);
		self::assertSame(
			array( 'enabled' => false, 'tiers' => array( array( 'min' => 4, 'pct' => 7 ) ) ),
			$writes[1]['updated']['99'],
			'El reintento debe partir del snapshot humano completo.'
		);
	}

	/** @testdox Un CAS perdido vuelve a evaluar profundidad con el overlay humano más reciente. */
	public function test_persisted_standardization_recomputes_depth_for_changed_active_snapshot(): void {
		$initial_config = array(
			'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
		);
		$human_config   = array(
			'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			'20' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 4, 'pct' => 7 ), array( 'min' => 8, 'pct' => 11 ) ) ),
		);
		$initial        = (string) json_encode( $initial_config );
		$human          = (string) json_encode( $human_config );
		$planned_calls  = array();
		$reads          = 0;
		$writes         = array();
		$policy         = $this->policy_with_dependencies(
			static function ( int $term_id, array $planned ) use ( &$planned_calls ): int {
				if ( 10 === $term_id ) {
					$planned_calls[] = $planned;

					return in_array( 20, $planned, true ) ? 4 : 8;
				}

				return 8;
			},
			static function () use ( &$reads, $initial, $human ): string {
				return 0 === $reads++ ? $initial : $human;
			},
			static function ( string $expected_raw, array $updated ) use ( &$writes ): bool {
				$writes[] = array( 'expected' => $expected_raw, 'updated' => $updated );

				return count( $writes ) > 1;
			}
		);

		self::assertTrue( $policy->standardize_persisted_config() );
		self::assertSame( array( array(), array( 20 ) ), $planned_calls );
		self::assertTrue( $writes[0]['updated']['10']['enabled'] );
		self::assertFalse(
			$writes[1]['updated']['10']['enabled'],
			'El segundo intento debe decidir desde el snapshot humano, no reutilizar la profundidad del primero.'
		);
		self::assertSame( $human_config['20'], $writes[1]['updated']['20'] );
	}

	/** @testdox La migración abandona de forma acotada tras tres conflictos CAS consecutivos. */
	public function test_persisted_standardization_bounds_consecutive_cas_conflicts(): void {
		$raw = (string) json_encode(
			array(
				'10' => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 10 ) ) ),
			)
		);
		$reads  = 0;
		$writes = 0;
		$policy = $this->policy_with_dependencies(
			static fn( int $_term_id, array $_planned ): int => 8,
			static function () use ( &$reads, $raw ): string {
				++$reads;

				return $raw;
			},
			static function ( string $_expected, array $_updated ) use ( &$writes ): bool {
				++$writes;

				return false;
			}
		);

		self::assertFalse( $policy->standardize_persisted_config() );
		self::assertSame( 3, $reads );
		self::assertSame( 3, $writes );
	}

	/** @testdox El alta masiva bloquea una segunda serie con cualquier tomo compartido, aunque tenga cinco exclusivos. */
	public function test_bulk_plan_rejects_a_second_new_term_with_shared_products(): void {
		global $wpdb;

		$first_ids   = array( 101, 102, 103, 104, 105 );
		$second_ids  = array( 101, 201, 202, 203, 204, 205 );
		$product_ids = array_values( array_unique( array_merge( $first_ids, $second_ids ) ) );
		$products    = array();
		$memberships = array();
		foreach ( $product_ids as $product_id ) {
			$product         = new PromotionPolicyProduct();
			$product->akb_id = $product_id;
			$products[ $product_id ] = $product;
			$memberships[ $product_id ] = 101 === $product_id
				? array( new \WP_Term( 10, 'serie-a', 'Serie A' ), new \WP_Term( 11, 'serie-b', 'Serie B' ) )
				: array(
					$product_id < 200
						? new \WP_Term( 10, 'serie-a', 'Serie A' )
						: new \WP_Term( 11, 'serie-b', 'Serie B' ),
				);
		}

		$wpdb = new PromotionPolicyWpdb(
			array(
				10 => $first_ids,
				11 => $second_ids,
			)
		);
		$byp_raw = '{}';
		Functions\when( 'get_option' )->alias(
			static function ( string $key, $default = false ) use ( &$byp_raw ) {
				if ( Settings::OPTION_BYP === $key ) {
					return $byp_raw;
				}
				if ( Settings::OPTION_EXCLUDE_CATS === $key ) {
					return array();
				}
				if ( Settings::OPTION_ALLOW_BACKORDER === $key ) {
					return 'no';
				}

				return $default;
			}
		);
		Functions\when( 'wc_get_product' )->alias( static fn( int $product_id ) => $products[ $product_id ] ?? null );
		Functions\when( 'get_the_terms' )->alias(
			static fn( int $product_id, string $taxonomy ): array => 'pa_serie' === $taxonomy
				? ( $memberships[ $product_id ] ?? array() )
				: array()
		);

		$settings = new Settings();
		$policy   = new PromotionPolicy(
			$settings,
			new SeriesDepth( $settings ),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) )
		);
		$plan = $policy->plan_bulk_enable( array(), array( 10, 11 ) );

		self::assertSame( 1, $plan['enabled'] );
		self::assertSame( 1, $plan['shallow'] );
		self::assertArrayHasKey( '10', $plan['config'] );
		self::assertArrayNotHasKey( '11', $plan['config'] );
		self::assertSame(
			array( array( 'min' => 5, 'pct' => 8 ) ),
			$plan['config']['10']['tiers']
		);

		// Regresión: si la primera serie ya estaba persistida, el collector
		// descartaba el tomo compartido antes de que la política pudiera verlo.
		// Los cinco tomos exclusivos restantes habilitaban erróneamente la segunda.
		$persisted = array(
			'10' => array(
				'enabled' => true,
				'tiers'   => array( array( 'min' => 5, 'pct' => 8 ) ),
			),
		);
		$byp_raw   = (string) json_encode( $persisted );
		$settings  = new Settings();
		$policy    = new PromotionPolicy(
			$settings,
			new SeriesDepth( $settings ),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) )
		);
		$plan      = $policy->plan_bulk_enable( $persisted, array( 11 ) );

		self::assertSame( 0, $plan['enabled'] );
		self::assertSame( 1, $plan['shallow'] );
		self::assertSame( $persisted, $plan['config'] );


		// El POST inverso debe observar su overlay, no el memo persistido: A se
		// desactiva y B queda con exactamente cinco tomos, incluido el compartido.
		// Si el collector consultara el toggle viejo de A contaría sólo cuatro.
		$wpdb     = new PromotionPolicyWpdb(
			array(
				10 => $first_ids,
				11 => array( 101, 201, 202, 203, 204 ),
			)
		);
		$settings = new Settings();
		$policy   = new PromotionPolicy(
			$settings,
			new SeriesDepth( $settings ),
			new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) )
		);
		$controller = new BypConfigController( $settings, new VolumeTierResolver(), $policy );
		$method     = new \ReflectionMethod( $controller, 'validate_submitted_config' );
		$method->setAccessible( true );
		/** @var array{config:array<string|int,mixed>,notices:array<int,array{type:string,message:string}>} $saved */
		$saved = $method->invoke(
			$controller,
			$persisted,
			array(
				10 => array( 'enabled' => false, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
				11 => array( 'enabled' => true, 'tiers' => array( array( 'min' => 5, 'pct' => 8 ) ) ),
			)
		);

		self::assertFalse( $saved['config']['10']['enabled'] );
		self::assertTrue( $saved['config']['11']['enabled'] );
		self::assertSame( array(), $saved['notices'] );
	}

	/** @testdox Los avisos no publican tramos cuando la profundidad no se puede verificar. */
	public function test_reachable_tiers_fail_closed_when_depth_is_unknown(): void {
		$policy = $this->policy_with_dependencies( static fn( int $_term_id, array $_planned ): ?int => null );

		self::assertSame(
			array(),
			$policy->reachable_tiers(
				10,
				array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) )
			)
		);
	}

	/** @testdox Los tramos legacy malformados se descartan sin TypeError y sólo sobreviven entradas completas alcanzables. */
	public function test_reachable_tiers_fail_closed_for_malformed_legacy_entries(): void {
		$valid  = array( 'min' => '5', 'pct' => '8' );
		$policy = $this->policy_with_depths( array( 10 => 7 ) );

		self::assertSame(
			array( $valid ),
			$policy->reachable_tiers(
				10,
				array(
					null,
					'corrupto',
					42,
					array(),
					array( 'min' => 5 ),
					array( 'pct' => 8 ),
					array( 'min' => 'cinco', 'pct' => 8 ),
					array( 'min' => 5, 'pct' => 99 ),
					array( 'min' => 2, 'pct' => 8 ),
					array( 'min' => 5.0, 'pct' => 8 ),
					array( 'min' => 5, 'pct' => true ),
					$valid,
					array( 'min' => 8, 'pct' => 10 ),
				)
			)
		);
	}

	/** @testdox La validación manual falla cerrada ante profundidad desconocida, tramo inalcanzable o forma corrupta. */
	public function test_enabled_tier_validation_rejects_unknown_unreachable_and_malformed_proposals(): void {
		$config      = array();
		$unreachable = $this->policy_with_depths( array( 10 => 5 ) )->validate_enabled_tiers(
			10,
			array( array( 'min' => 5, 'pct' => 8 ), array( 'min' => 7, 'pct' => 10 ) ),
			$config
		);
		$unknown     = $this->policy_with_dependencies(
			static fn( int $_term_id, array $_planned ): ?int => null
		)->validate_enabled_tiers( 10, array( array( 'min' => 5, 'pct' => 8 ) ), $config );
		$malformed   = $this->policy_with_depths( array( 10 => 7 ) )->validate_enabled_tiers(
			10,
			array( 'entrada-corrupta' ),
			$config
		);

		self::assertIsString( $unreachable );
		self::assertStringContainsString( 'inalcanzables', $unreachable );
		self::assertIsString( $unknown );
		self::assertStringContainsString( 'No se pudo verificar', $unknown );
		self::assertIsString( $malformed );
		self::assertStringContainsString( 'malformado', $malformed );
	}

	/** @param array<int,int|null> $depths */
	private function policy_with_depths( array $depths ): PromotionPolicy {
		return $this->policy_with_dependencies(
			static fn( int $term_id, array $_planned ): ?int => array_key_exists( $term_id, $depths ) ? $depths[ $term_id ] : 0
		);
	}

	private function policy_with_dependencies(
		callable $depth_override,
		?callable $raw_reader = null,
		?callable $config_cas = null
	): PromotionPolicy {
		$settings  = new Settings();
		$depth     = new SeriesDepth( $settings );
		$collector = new CartSeriesCollector( $settings, new EligibilityGuard( $settings ) );

		return new PromotionPolicy(
			$settings,
			$depth,
			$collector,
			$depth_override,
			$raw_reader,
			$config_cas
		);
	}
}
