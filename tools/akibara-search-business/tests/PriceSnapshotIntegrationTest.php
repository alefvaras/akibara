<?php
/**
 * Contrato ejecutable entre el productor Search y el proveedor real Business.
 *
 * @package Akibara\SearchBusiness\Tests
 */

declare( strict_types = 1 );

namespace Akibara\SearchBusiness\Tests;

use Akibara\Business\Modules\TaxonomyDiscount\DiscountResolver;
use Akibara\Business\Modules\TaxonomyDiscount\PriceAuthority;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Akibara\Search\EditorialDiscountPublication;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class IntegratedPriceWpdb {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $termmeta = 'wp_termmeta';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** @var array<int, object> */
	public array $database_rows = array();

	public int $reads = 0;

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query       = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}

		return $query;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		++$this->reads;
		$this->last_error = '';

		if ( false !== strpos( $query, "SELECT 'meta' source_kind" ) ) {
			return $this->database_rows;
		}
		if ( false === strpos( $query, 'option_name, option_value' ) ) {
			return array();
		}

		$rows = array();
		foreach ( $this->raw_options as $name => $value ) {
			if ( false !== strpos( $query, "'" . str_replace( "'", "''", $name ) . "'" ) ) {
				$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
			}
		}
		usort( $rows, static fn( object $a, object $b ): int => strcmp( $a->option_name, $b->option_name ) );

		return $rows;
	}

	public function get_var( string $query ): ?string {
		++$this->reads;
		$this->last_error = '';

		if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
			$name = str_replace( "''", "'", $match[1] );
			return $this->raw_options[ $name ] ?? null;
		}

		return null;
	}
}

/**
 * @covers \Akibara\Search\EditorialDiscountPublication
 * @covers \Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider
 */
final class PriceSnapshotIntegrationTest extends TestCase {
	private IntegratedPriceWpdb $db;
	private SearchPriceProvider $provider;
	private DiscountResolver $resolver;

	/** @var array<string, mixed> */
	private array $original = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->db = new IntegratedPriceWpdb();
		$GLOBALS['wpdb'] = $this->db;

		$authority      = new PriceAuthority();
		$settings       = new Settings( $authority );
		$this->resolver = new DiscountResolver( $settings, $authority );
		$this->provider = new SearchPriceProvider( $this->resolver, static fn(): int => 1700000000 );
		$this->install_provider();

		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );

		foreach ( $this->static_defaults() as $name => $value ) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$this->original[ $name ] = $property->getValue();
			$property->setValue( null, $value );
		}

		$this->db->raw_options = array(
			'akibara_business_search_feed_contract_version' => '2',
			'akibara_taxonomy_discount_enabled'              => '',
			'akibara_taxonomy_discount_sale_policy'          => '',
			'gmt_offset'                                     => '',
			'timezone_string'                                => 'America/Santiago',
			'woocommerce_price_num_decimals'                 => '0',
		);
		$this->install_ready_state();
	}

	protected function tearDown(): void {
		foreach ( $this->original as $name => $value ) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}

		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filter']['akb_search_effective_price'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Search adopta y revalida el proveedor real de Business dentro de once lecturas.
	 */
	public function test_real_provider_is_adopted_and_revalidated_within_eleven_reads(): void {
		$this->db->reads = 0;

		$capture = EditorialDiscountPublication::capture_price_authority();

		self::assertIsArray( $capture, 'Search debe capturar una foto autoritativa.' );
		self::assertSame( 5, $this->db->reads, 'La captura debe consumir exactamente cinco lecturas.' );
		self::assertSame( SearchPriceProvider::STATE_PENDING, $this->provider->state() );
		self::assertSame( $capture['provider_token'], $this->provider->snapshot_token() );
		self::assertSame( $capture['price_snapshot'], $this->provider->authoritative_snapshot() );
		self::assertTrue( $this->provider->snapshot_is_fresh( $capture['provider_token'] ) );

		self::assertTrue( EditorialDiscountPublication::price_authority_matches( $capture ) );
		self::assertSame( 10, $this->db->reads, 'Captura y recheck deben consumir diez lecturas.' );
		self::assertLessThanOrEqual( 11, $this->db->reads );
	}

	/**
	 * @testdox Una invalidación real de Business cierra el recheck de Search.
	 */
	public function test_business_invalidation_closes_search_final_check(): void {
		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture );

		$this->resolver->invalidate_derived_memos();

		self::assertFalse( $this->provider->snapshot_is_fresh( $capture['provider_token'] ) );
		self::assertFalse( EditorialDiscountPublication::price_authority_matches( $capture ) );
	}

	/**
	 * @testdox Un cambio de options entre captura y final no puede publicarse.
	 */
	public function test_option_drift_closes_real_cross_plugin_contract(): void {
		$capture = EditorialDiscountPublication::capture_price_authority();
		self::assertIsArray( $capture );

		$this->db->raw_options['gmt_offset'] = '0.25';

		self::assertFalse( EditorialDiscountPublication::price_authority_matches( $capture ) );
		self::assertSame( $capture['price_snapshot'], $this->provider->authoritative_snapshot() );
	}

	private function install_provider(): void {
		$GLOBALS['wp_filter']['akb_search_effective_price'] = (object) array(
			'callbacks' => array(
				10 => array(
					'provider' => array(
						'function'      => $this->provider,
						'accepted_args' => 2,
					),
				),
			),
		);
	}

	private function install_ready_state(): void {
		$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
		$options_method->setAccessible( true );
		$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
		$source_method->setAccessible( true );
		$options = $options_method->invoke( null, $this->db->raw_options );
		$source  = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );
		self::assertIsArray( $source );

		$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode(
			array(
				'protocol'            => 1,
				'contract'            => 'akb-editorial-materialization-v1',
				'wanted_seq'          => 7,
				'wanted_fingerprint'  => $source['fingerprint'],
				'window_active'       => false,
				'next_boundary_at'    => null,
				'source_term_ids'     => array(),
				'debt_staged'         => true,
				'active_run_id'       => '',
				'purge_pre_seq'       => 7,
				'purge_post_seq'      => 7,
				'reason'              => 'real-provider-integration-test',
				'updated_at'          => 1700000000,
			)
		);
		$this->reset_source_latches();
	}

	/** @return array<string, mixed> */
	private function static_defaults(): array {
		return array(
			'registered'                 => true,
			'runtime'                    => null,
			'state_read_failed'          => false,
			'test_serving_override'      => null,
			'test_price_match_sequence'  => null,
			'term_meta_row_failed'       => false,
			'source_memo_set'            => false,
			'read_authority_available'   => true,
			'read_authority_error'       => '',
			'adopted_price_provider'     => null,
			'adopted_price_token'        => null,
			'adopted_price_snapshot'     => null,
			'adopted_provider_absent'    => false,
			'source_memo'                => null,
		);
	}

	private function reset_source_latches(): void {
		foreach (
			array(
				'source_memo_set'         => false,
				'source_memo'             => null,
				'state_read_failed'       => false,
				'read_authority_available'=> true,
				'read_authority_error'    => '',
			) as $name => $value
		) {
			$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}
}
