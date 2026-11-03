<?php
/**
 * Contrato `akibara_search_price_snapshot_v1`: Business ADOPTA la foto de Search.
 *
 * Las rondas 4 y 5 fallaron por la misma razón de fondo: Business tomaba su
 * propia foto de la configuración y dos lecturas independientes de la misma base
 * pueden diverger. Desde 1.99.16 la captura la hace Search, la entrega entera, y
 * Business la valida y la adopta — sin releer nada de configuración y sin volver
 * a aplicar hooks.
 *
 * Lo que fijan estas pruebas:
 *
 *   - la foto se acepta sólo con su forma exacta (10 claves top-level en orden,
 *     9 options `{present, raw}` en `ksort`, term_meta ordenada y canónica);
 *   - `adopt` sólo deja PENDING; el modo estricto y el lector se siembran en el
 *     primer `__invoke()`;
 *   - `__invoke()` sin adopción **lanza** y no lee por su cuenta;
 *   - el token es ABA-safe: misma foto/mismo epoch es idempotente, foto distinta
 *     falla sticky, y de FAILED no se vuelve;
 *   - con la foto `A` adoptada, cambiar la base y los hooks a `B` no mueve el
 *     precio, y no se ejecuta una sola consulta de configuración.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\TaxonomyDiscount\AdoptedSnapshot;
use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthorityUnavailable;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Business\Tests\Support\AuthorityWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Product;

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class PriceSnapshotParityTest extends TestCase {

	private const PRODUCT = 4242;

	private const TERM = 501;

	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                = new AuthorityWpdb();
		$this->wpdb->relationships = array( self::PRODUCT => array( array( self::TERM, 'product_brand' ) ) );

		// La base dice cosas DISTINTAS de la foto a propósito: si algo se
		// releyera, el precio cambiaría y el test lo delataría.
		$this->wpdb->option_rows    = array( Settings::OPTION_ENABLED => 'no' );
		$this->wpdb->term_meta_rows = array( self::TERM => '5' );

		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'get_the_terms' )->justReturn( false );
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'wc_get_attribute_taxonomies' )->justReturn( array() );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-08-06' );
		Functions\when( 'clean_object_term_cache' )->justReturn( null );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Fixtures.
	// -----------------------------------------------------------------------

	/**
	 * Foto `A` válida: descuento del 15 % vigente.
	 *
	 * @param array<string, mixed> $overrides Cambios puntuales.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $overrides = array(), array $option_overrides = array() ): array {
		$snapshot = array(
			'contract'           => AdoptedSnapshot::CONTRACT,
			'source_fingerprint' => str_repeat( 'a', 64 ),
			'options'            => array(
				'akb_discount_max_pct'                         => array( 'present' => false, 'raw' => '' ),
				'akibara_business_search_feed_contract_version' => array( 'present' => true, 'raw' => '2' ),
				'akibara_taxonomy_discount_apply_to'           => array( 'present' => true, 'raw' => Settings::APPLY_ALL ),
				'akibara_taxonomy_discount_enabled'            => array( 'present' => true, 'raw' => 'yes' ),
				'akibara_taxonomy_discount_from'               => array( 'present' => false, 'raw' => '' ),
				'akibara_taxonomy_discount_sale_policy'        => array( 'present' => true, 'raw' => Settings::POLICY_GREATEST_WINS ),
				'akibara_taxonomy_discount_to'                 => array( 'present' => false, 'raw' => '' ),
				'gmt_offset'                                   => array( 'present' => false, 'raw' => '' ),
				'timezone_string'                              => array( 'present' => true, 'raw' => 'America/Santiago' ),
				'woocommerce_price_num_decimals'               => array( 'present' => true, 'raw' => '0' ),
			),
			'timezone'           => 'America/Santiago',
			'window_active'      => true,
			'boundary_at'        => null,
			'max_pct'            => 15,
			'decimals'           => 0,
			'taxonomies'         => array( 'pa_editorial', 'product_brand' ),
			'term_meta'          => array(
				array( 'meta_id' => 11, 'term_id' => self::TERM, 'raw' => '15' ),
			),
		);

		foreach ( $option_overrides as $name => $row ) {
			$snapshot['options'][ $name ] = $row;
		}

		foreach ( $overrides as $key => $value ) {
			$snapshot[ $key ] = $value;
		}

		return $snapshot;
	}

	private function product(): WC_Product {
		$product                    = new WC_Product( 'instock' );
		$product->akb_id            = self::PRODUCT;
		$product->akb_regular_price = '10000';
		return $product;
	}

	private function provider(): SearchPriceProvider {
		return new SearchPriceProvider( new DiscountResolver( new Settings() ) );
	}

	// -----------------------------------------------------------------------
	// Máquina de estados.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Adoptar deja PENDING: no siembra el lector ni activa el modo estricto.
	 */
	public function test_adopt_only_marks_pending(): void {
		$provider = $this->provider();

		self::assertSame( SearchPriceProvider::STATE_EMPTY, $provider->state() );

		$token = $provider->adopt_search_snapshot( $this->snapshot() );

		self::assertNotSame( '', $token );
		self::assertSame( SearchPriceProvider::STATE_PENDING, $provider->state() );
		self::assertSame( array(), $this->wpdb->queries, 'Adoptar no puede consultar la base.' );
	}

	/**
	 * @testdox El primer __invoke activa la foto y devuelve el precio de la foto.
	 */
	public function test_first_invoke_activates_and_prices_from_the_snapshot(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot() );

		self::assertSame( 8500.0, $provider( 10000.0, $this->product() ) );
		self::assertSame( SearchPriceProvider::STATE_ACTIVE, $provider->state() );
	}

	/**
	 * @testdox __invoke sin foto adoptada lanza y deja FAILED, sin leer nada.
	 */
	public function test_invoke_without_adoption_fails(): void {
		$provider = $this->provider();

		try {
			$provider( 10000.0, $this->product() );
			self::fail( 'Sin foto adoptada no puede haber precio.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			self::assertStringContainsString( 'sin haber adoptado', $e->getMessage() );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertSame( array(), $this->wpdb->queries, 'No puede haber self-read.' );
	}

	/**
	 * @testdox De FAILED no se vuelve, ni siquiera adoptando una foto válida.
	 */
	public function test_failed_state_does_not_revive(): void {
		$provider = $this->provider();

		try {
			$provider( 10000.0, $this->product() );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		$this->expectException( PriceAuthorityUnavailable::class );
		$provider->adopt_search_snapshot( $this->snapshot() );
	}

	// -----------------------------------------------------------------------
	// Token ABA-safe.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Adoptar dos veces la MISMA foto en el mismo epoch es idempotente.
	 */
	public function test_adopting_the_same_snapshot_twice_is_idempotent(): void {
		$provider = $this->provider();

		$first  = $provider->adopt_search_snapshot( $this->snapshot() );
		$second = $provider->adopt_search_snapshot( $this->snapshot() );

		self::assertSame( $first, $second );
		self::assertSame( SearchPriceProvider::STATE_PENDING, $provider->state() );
	}

	/**
	 * @testdox Adoptar una foto DISTINTA después de la primera falla sticky.
	 */
	public function test_adopting_a_different_snapshot_fails_sticky(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot() );

		try {
			$provider->adopt_search_snapshot( $this->snapshot( array( 'max_pct' => 20 ) ) );
			self::fail( 'Cambiar de foto a mitad de operación no puede aceptarse.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
	}

	/**
	 * @testdox El token distingue dos providers con la misma foto (epoch propio).
	 */
	public function test_token_is_aba_safe_across_instances(): void {
		$one = $this->provider();
		$two = $this->provider();

		$token_one = $one->adopt_search_snapshot( $this->snapshot() );
		$token_two = $two->adopt_search_snapshot( $this->snapshot() );

		// Mismo epoch inicial y misma foto ⇒ mismo token: es el contrato
		// idempotente. Lo que no puede pasar es que un token ajeno valide.
		self::assertSame( $token_one, $token_two );
		self::assertFalse( $one->snapshot_is_fresh( 'token-inventado' ) );
	}

	/**
	 * @testdox El final-check acepta el token en PENDING y en ACTIVE.
	 */
	public function test_final_check_accepts_pending_and_active(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		self::assertTrue( $provider->snapshot_is_fresh( $token ) );

		$provider( 10000.0, $this->product() );

		self::assertTrue( $provider->snapshot_is_fresh( $token ) );
	}

	/**
	 * @testdox Tras un fallo, el final-check devuelve false.
	 */
	public function test_final_check_is_false_after_failure(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		try {
			$provider->adopt_search_snapshot( $this->snapshot( array( 'decimals' => 3 ) ) );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		self::assertFalse( $provider->snapshot_is_fresh( $token ) );
	}

	// -----------------------------------------------------------------------
	// Vigencia declarada por la foto.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Cruzar boundary_at falla sticky.
	 */
	public function test_crossing_the_boundary_fails_sticky(): void {
		// Reloj que AVANZA entre la adopción y la invocación: al adoptar el
		// límite todavía es futuro; cuando llega el primer producto, ya pasó.
		// `2027-01-01 00:00 + 1 día` en Santiago: el cierre que se deriva de la
		// propia foto, no un entero cualquiera [ronda 7.2].
		$boundary = 1798858800;
		$now      = $boundary - 100;

		$provider = new SearchPriceProvider(
			new DiscountResolver( new Settings() ),
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$token = $provider->adopt_search_snapshot(
			$this->snapshot(
				array( 'boundary_at' => $boundary ),
				array( 'akibara_taxonomy_discount_to' => array( 'present' => true, 'raw' => '2027-01-01' ) )
			)
		);

		$now = $boundary + 1;

		try {
			$provider( 10000.0, $this->product() );
			self::fail( 'Con la vigencia expirada no puede publicarse un precio.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			self::assertStringContainsString( 'boundary_at', $e->getMessage() );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertFalse( $provider->snapshot_is_fresh( $token ) );
	}

	/**
	 * @testdox Una ventana inactiva en la foto significa "sin descuento", no un fallo.
	 */
	public function test_inactive_window_means_base_price(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot(
			$this->snapshot(
				array( 'window_active' => false ),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ) )
			)
		);

		self::assertSame( 10000.0, $provider( 10000.0, $this->product() ) );
		self::assertTrue( $provider->is_authoritative() );
	}

	// -----------------------------------------------------------------------
	// A adoptada → DB/hooks en B → el precio sigue siendo A.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Con A adoptada, cambiar la base y los hooks a B no mueve el precio.
	 */
	public function test_adopted_a_survives_database_and_hooks_moving_to_b(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		// La base pasa a B…
		$this->wpdb->option_rows[ Settings::OPTION_ENABLED ] = 'no';
		$this->wpdb->term_meta_rows[ self::TERM ]            = '5';

		// …y los hooks también.
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				if ( 'akibara_taxonomy_discount_max_pct' === $hook ) {
					return 25;
				}
				if ( 'wc_get_price_decimals' === $hook ) {
					return 4;
				}
				return $value;
			}
		);

		self::assertSame( 8500.0, $provider( 10000.0, $this->product() ), 'El precio debe seguir siendo el de A.' );
		self::assertTrue( $provider->snapshot_is_fresh( $token ) );
	}

	/**
	 * @testdox Sólo se consulta la relación producto↔término: cero SQL de configuración.
	 */
	public function test_only_relationships_hit_the_database(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		$provider( 10000.0, $this->product() );
		$provider->snapshot_is_fresh( $token );

		self::assertCount( 1, $this->wpdb->queries, 'Toda la configuración viene de la foto: ' . implode( ' | ', $this->wpdb->queries ) );
		self::assertStringContainsString( 'term_relationships', $this->wpdb->queries[0] );
	}

	/**
	 * @testdox El term_meta adoptado gobierna el porcentaje; no se consulta termmeta.
	 */
	public function test_adopted_term_meta_governs_the_percentage(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot(
			$this->snapshot(
				array(
					'term_meta' => array( array( 'meta_id' => 11, 'term_id' => self::TERM, 'raw' => '10' ) ),
				)
			)
		);

		self::assertSame( 9000.0, $provider( 10000.0, $this->product() ) );

		foreach ( $this->wpdb->queries as $query ) {
			self::assertStringNotContainsString( 'termmeta', $query );
		}
	}

	/**
	 * @testdox Un término sin fila en term_meta es ausencia legítima: precio base.
	 */
	public function test_term_without_adopted_meta_keeps_base_price(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot( array( 'term_meta' => array() ) ) );

		self::assertSame( 10000.0, $provider( 10000.0, $this->product() ) );
		self::assertTrue( $provider->is_authoritative() );
	}

	/**
	 * @testdox La foto adoptada se devuelve literalmente para comparar con Search.
	 */
	public function test_snapshot_is_returned_literally(): void {
		$snapshot = $this->snapshot();
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $snapshot );

		self::assertSame( $snapshot, $provider->authoritative_snapshot() );
	}

	// -----------------------------------------------------------------------
	// Forma: todo lo que no sea exactamente el contrato se rechaza.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider malformed_snapshots
	 * @testdox Una foto malformada se rechaza cerrada
	 *
	 * @param array<string, mixed> $snapshot Foto inválida.
	 */
	public function test_malformed_snapshots_are_rejected( array $snapshot ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		( $this->provider() )->adopt_search_snapshot( $snapshot );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function malformed_snapshots(): array {
		$base = array(
			'contract'           => AdoptedSnapshot::CONTRACT,
			'source_fingerprint' => str_repeat( 'a', 64 ),
			'options'            => array(
				'akb_discount_max_pct'                         => array( 'present' => false, 'raw' => '' ),
				'akibara_business_search_feed_contract_version' => array( 'present' => true, 'raw' => '2' ),
				'akibara_taxonomy_discount_apply_to'           => array( 'present' => true, 'raw' => 'all' ),
				'akibara_taxonomy_discount_enabled'            => array( 'present' => true, 'raw' => 'yes' ),
				'akibara_taxonomy_discount_from'               => array( 'present' => false, 'raw' => '' ),
				'akibara_taxonomy_discount_sale_policy'        => array( 'present' => true, 'raw' => 'greatest_wins' ),
				'akibara_taxonomy_discount_to'                 => array( 'present' => false, 'raw' => '' ),
				'gmt_offset'                                   => array( 'present' => false, 'raw' => '' ),
				'timezone_string'                              => array( 'present' => true, 'raw' => 'America/Santiago' ),
				'woocommerce_price_num_decimals'               => array( 'present' => true, 'raw' => '0' ),
			),
			'timezone'           => 'America/Santiago',
			'window_active'      => true,
			'boundary_at'        => null,
			'max_pct'            => 15,
			'decimals'           => 0,
			'taxonomies'         => array( 'pa_editorial', 'product_brand' ),
			'term_meta'          => array( array( 'meta_id' => 11, 'term_id' => 501, 'raw' => '15' ) ),
		);

		$with = static function ( array $changes ) use ( $base ): array {
			foreach ( $changes as $key => $value ) {
				$base[ $key ] = $value;
			}
			return $base;
		};

		$reordered = $base;
		unset( $reordered['contract'] );
		$reordered['contract'] = AdoptedSnapshot::CONTRACT; // misma clave, otro orden.

		$options_missing = $base['options'];
		unset( $options_missing['gmt_offset'] );

		$options_present_with_raw          = $base['options'];
		$options_present_with_raw['akibara_taxonomy_discount_from'] = array( 'present' => false, 'raw' => '2026-01-01' );

		$options_wrong_feed          = $base['options'];
		$options_wrong_feed['akibara_business_search_feed_contract_version'] = array( 'present' => true, 'raw' => '1' );

		return array(
			'clave extra'                 => array( $with( array( 'extra' => 1 ) ) ),
			'top-level desordenado'       => array( $reordered ),
			'contrato distinto'           => array( $with( array( 'contract' => 'otro_contrato' ) ) ),
			'fingerprint no hex'          => array( $with( array( 'source_fingerprint' => 'no-es-un-sha' ) ) ),
			'options incompletas'         => array( $with( array( 'options' => $options_missing ) ) ),
			'ausente con raw'             => array( $with( array( 'options' => $options_present_with_raw ) ) ),
			'contrato de feed distinto'   => array( $with( array( 'options' => $options_wrong_feed ) ) ),
			'timezone inválida'           => array( $with( array( 'timezone' => 'UTC5.5' ) ) ),
			'window_active no booleano'   => array( $with( array( 'window_active' => 1 ) ) ),
			'boundary_at negativo'        => array( $with( array( 'boundary_at' => -1 ) ) ),
			'max_pct fuera de dominio'    => array( $with( array( 'max_pct' => 91 ) ) ),
			'max_pct cero'                => array( $with( array( 'max_pct' => 0 ) ) ),
			'decimals fuera de dominio'   => array( $with( array( 'decimals' => 9 ) ) ),
			'taxonomies desordenadas'     => array( $with( array( 'taxonomies' => array( 'product_brand', 'pa_editorial' ) ) ) ),
			'taxonomies duplicadas'       => array( $with( array( 'taxonomies' => array( 'pa_editorial', 'pa_editorial' ) ) ) ),
			'taxonomies sin trim'         => array( $with( array( 'taxonomies' => array( ' pa_editorial' ) ) ) ),
			'term_meta duplicada'         => array(
				$with(
					array(
						'term_meta' => array(
							array( 'meta_id' => 11, 'term_id' => 501, 'raw' => '15' ),
							array( 'meta_id' => 12, 'term_id' => 501, 'raw' => '10' ),
						),
					)
				),
			),
			'term_meta desordenada'       => array(
				$with(
					array(
						'term_meta' => array(
							array( 'meta_id' => 11, 'term_id' => 502, 'raw' => '15' ),
							array( 'meta_id' => 12, 'term_id' => 501, 'raw' => '10' ),
						),
					)
				),
			),
			'term_meta con raw no canónico' => array(
				$with( array( 'term_meta' => array( array( 'meta_id' => 11, 'term_id' => 501, 'raw' => '1e2' ) ) ) ),
			),
			'term_meta con id no positivo'  => array(
				$with( array( 'term_meta' => array( array( 'meta_id' => 0, 'term_id' => 501, 'raw' => '15' ) ) ) ),
			),
		);
	}
}
