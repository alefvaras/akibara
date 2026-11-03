<?php
/** Contrato integrado Search -> proveedor efectivo de Business. */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount {
	if ( ! class_exists( SearchPriceProvider::class, false ) ) {
		/** Doble con el FQCN exacto; valida la frontera pública, no internals de Business. */
		final class SearchPriceProvider {
			/** @var array<string,mixed> */
			public array $snapshot = array();
			public string $token = '';
			public bool $fresh = true;
			public int $adoptions = 0;

			/** @param array<string,mixed> $snapshot */
			public function adopt_search_snapshot( array $snapshot ): string {
				$keys = array( 'contract', 'source_fingerprint', 'options', 'timezone', 'window_active', 'boundary_at', 'max_pct', 'decimals', 'taxonomies', 'term_meta' );
				if ( $keys !== array_keys( $snapshot ) ) {
					throw new \RuntimeException( 'invalid snapshot shape' );
				}
				++$this->adoptions;
				if ( array() !== $this->snapshot && $this->snapshot !== $snapshot ) {
					throw new \RuntimeException( 'snapshot changed after adoption' );
				}
				$this->snapshot = $snapshot;
				$this->token    = hash( 'sha256', (string) json_encode( $snapshot ) );
				return $this->token;
			}

			public function snapshot_is_fresh( string $token ): bool {
				return $this->fresh && '' !== $token && hash_equals( $this->token, $token );
			}

			/** @return array<string,mixed> */
			public function authoritative_snapshot(): array {
				return $this->snapshot;
			}

			/** @param mixed $base @param mixed $product */
			public function __invoke( $base, $product ): float {
				unset( $product );
				return (float) $base;
			}
		}
	}
}

namespace Akibara\Search\Tests {

	use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
	use Akibara\Search\EditorialDiscountPublication;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;
	use ReflectionMethod;
	use ReflectionProperty;

	final class PriceProviderAdoptionWpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $termmeta = 'wp_termmeta';
		public string $last_error = '';
		/** @var array<string,string> */
		public array $raw_options = array();
		/** @var array<int,object> */
		public array $database_rows = array();
		public int $reads = 0;

		/** @param mixed ...$args */
		public function prepare( string $query, ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
			}
			return $query;
		}

		/** @return array<int,object> */
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

		/** @return string|null */
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

	/** @covers \Akibara\Search\EditorialDiscountPublication */
	final class PriceProviderAdoptionProtocolTest extends TestCase {
		private PriceProviderAdoptionWpdb $db;
		private SearchPriceProvider $provider;
		/** @var array<string,mixed> */
		private array $original = array();

		protected function setUp(): void {
			parent::setUp();

			// El doble ocupa el FQCN de producción a propósito: Search resuelve
			// el proveedor por nombre de clase exacto. Pero si la clase REAL de
			// Business ya está en el proceso, el `class_exists()` de arriba no
			// declara el doble y `new SearchPriceProvider()` moriría con un
			// `ArgumentCountError` ilegible. Se detecta y se dice dónde vive el
			// contrato integrado, que es donde corresponde ejercitar el objeto
			// real [auditoría 7.4].
			$constructor = ( new \ReflectionClass( SearchPriceProvider::class ) )->getConstructor();
			if ( null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
				self::markTestSkipped(
					'SearchPriceProvider real cargado en este proceso: el contrato integrado se ejercita con '
					. 'tools/akibara-search-business/phpunit.xml.dist, no con este doble.'
				);
			}

			Monkey\setUp();
			$this->db = new PriceProviderAdoptionWpdb();
			$GLOBALS['wpdb'] = $this->db;
			$this->provider = new SearchPriceProvider();
			$this->install_provider( $this->provider );
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
				'akibara_taxonomy_discount_enabled' => 'no',
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

		public function test_capture_adopts_exact_snapshot_and_final_stays_within_eleven_reads(): void {
			$this->db->reads = 0;
			$capture = EditorialDiscountPublication::capture_price_authority();
			self::assertIsArray( $capture );
			self::assertSame( 5, $this->db->reads );
			self::assertSame(
				array( 'contract', 'source_fingerprint', 'options', 'timezone', 'window_active', 'boundary_at', 'max_pct', 'decimals', 'taxonomies', 'term_meta' ),
				array_keys( $this->provider->snapshot )
			);
			$option_keys = array_keys( $this->provider->snapshot['options'] );
			$sorted = $option_keys;
			sort( $sorted, SORT_STRING );
			self::assertSame( $sorted, $option_keys );
			self::assertTrue( EditorialDiscountPublication::price_authority_matches( $capture ) );
			self::assertSame( 10, $this->db->reads );
			self::assertLessThanOrEqual( 11, $this->db->reads );
		}

		public function test_option_a_to_b_between_capture_and_final_is_rejected(): void {
			$capture = EditorialDiscountPublication::capture_price_authority();
			self::assertIsArray( $capture );
			$this->db->raw_options['gmt_offset'] = '0.25';
			self::assertFalse( EditorialDiscountPublication::price_authority_matches( $capture ) );
		}

		public function test_same_class_callback_swap_is_not_the_adopted_provider(): void {
			$capture = EditorialDiscountPublication::capture_price_authority();
			self::assertIsArray( $capture );
			$this->install_provider( new SearchPriceProvider() );
			self::assertFalse( EditorialDiscountPublication::price_authority_matches( $capture ) );
		}

		public function test_canonical_option_domains_reject_padding_fraction_and_negative_zero(): void {
			$method = new ReflectionMethod( EditorialDiscountPublication::class, 'valid_source_option_value' );
			$method->setAccessible( true );
			foreach ( array( '02', '09', '00' ) as $bad ) {
				self::assertFalse( $method->invoke( null, 'woocommerce_price_num_decimals', $bad ), $bad );
			}
			foreach ( array( '', '-0', '-0.0', '-00', '5.2', '14.25', '1e2', '05.5' ) as $bad ) {
				self::assertFalse( $method->invoke( null, 'gmt_offset', $bad ), $bad );
			}
			foreach ( array( '0', '5.25', '-3.5', '14', '-14' ) as $good ) {
				self::assertTrue( $method->invoke( null, 'gmt_offset', $good ), $good );
			}
		}

		public function test_real_empty_defaults_with_named_timezone_are_authoritative(): void {
			$this->db->raw_options = array(
				'akibara_business_search_feed_contract_version' => '2',
				'akibara_taxonomy_discount_apply_to'            => '',
				'akibara_taxonomy_discount_enabled'             => '',
				'akibara_taxonomy_discount_sale_policy'         => '',
				'gmt_offset'                                    => '',
				'timezone_string'                               => 'America/Santiago',
				'woocommerce_price_num_decimals'                => '0',
			);
			$this->install_ready_state();

			$this->db->reads = 0;
			$capture         = EditorialDiscountPublication::capture_price_authority();

			self::assertIsArray( $capture );
			self::assertSame( 5, $this->db->reads );
			self::assertFalse( $this->provider->snapshot['window_active'] );
			self::assertSame( 'America/Santiago', $this->provider->snapshot['timezone'] );
			self::assertSame( '', $this->provider->snapshot['options']['gmt_offset']['raw'] );
			self::assertSame( 10000.0, ( $this->provider )( 10000.0, null ) );
			self::assertTrue( EditorialDiscountPublication::price_authority_matches( $capture ) );
			self::assertSame( 10, $this->db->reads );
		}

		public function test_named_timezone_ignores_an_irrelevant_scalar_gmt_offset(): void {
			$this->db->raw_options = array(
				'akibara_business_search_feed_contract_version' => '2',
				'akibara_taxonomy_discount_enabled'             => 'no',
				'gmt_offset'                                    => 'cinco',
				'timezone_string'                               => 'America/Santiago',
			);

			$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
			$options_method->setAccessible( true );
			$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
			$source_method->setAccessible( true );
			$options = $options_method->invoke( null, $this->db->raw_options );
			$source  = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );

			self::assertIsArray( $source );
			self::assertSame( 'America/Santiago', $source['timezone'] );
			self::assertSame( 'cinco', $source['options']['gmt_offset']['raw'] );
		}

		public function test_serialized_empty_enum_is_not_a_legacy_literal_default(): void {
			$bundle_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
			$bundle_method->setAccessible( true );

			self::assertNull(
				$bundle_method->invoke(
					null,
					array(
						'akibara_business_search_feed_contract_version' => '2',
						'akibara_taxonomy_discount_enabled'             => 's:0:"";',
					)
				)
			);
		}

		public function test_editorial_state_accepts_only_its_three_audit_timestamps(): void {
			$method = new ReflectionMethod( EditorialDiscountPublication::class, 'valid_state' );
			$method->setAccessible( true );
			$state = array(
				'protocol'           => 1,
				'contract'           => 'akb-editorial-materialization-v1',
				'wanted_seq'         => 7,
				'wanted_fingerprint' => str_repeat( 'a', 64 ),
				'window_active'      => false,
				'next_boundary_at'   => null,
				'source_term_ids'    => array(),
				'debt_staged'        => true,
				'active_run_id'      => '',
				'purge_pre_seq'      => 7,
				'purge_post_seq'     => 7,
				'reason'             => 'state-shape-test',
				'updated_at'         => 1700000000,
				'purge_pre_at'       => 1700000001,
				'purge_post_at'      => 1700000002,
				'source_confirmed_at'=> 1700000003,
			);

			self::assertTrue( $method->invoke( null, $state ) );

			$state['purge_post_at'] = 0;
			self::assertFalse( $method->invoke( null, $state ) );
			$state['purge_post_at'] = 1700000002;
			$state['invented'] = true;
			self::assertFalse( $method->invoke( null, $state ) );
		}

		public function test_raw_and_serving_bundle_project_the_same_canonical_source(): void {
			$raw_method = new ReflectionMethod( EditorialDiscountPublication::class, 'raw_source_options' );
			$raw_method->setAccessible( true );
			$bundle_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
			$bundle_method->setAccessible( true );
			$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
			$source_method->setAccessible( true );
			$raw_options = $raw_method->invoke( null );
			$bundle_options = $bundle_method->invoke( null, array_reverse( $this->db->raw_options, true ) );
			self::assertSame( $raw_options, $bundle_options );
			$database = array( 'meta' => array(), 'attributes' => array() );
			self::assertSame(
				$source_method->invoke( null, $raw_options, $database ),
				$source_method->invoke( null, $bundle_options, $database )
			);
		}

		private function install_provider( SearchPriceProvider $provider ): void {
			$GLOBALS['wp_filter']['akb_search_effective_price'] = (object) array(
				'callbacks' => array(
					10 => array( 'provider' => array( 'function' => $provider, 'accepted_args' => 2 ) ),
				),
			);
		}

		private function install_ready_state(): void {
			$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
			$options_method->setAccessible( true );
			$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
			$source_method->setAccessible( true );
			$options = $options_method->invoke( null, $this->db->raw_options );
			$source = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );
			self::assertIsArray( $source );
			$this->db->raw_options[ EditorialDiscountPublication::OPTION_STATE ] = (string) json_encode(
				array(
					'protocol' => 1,
					'contract' => 'akb-editorial-materialization-v1',
					'wanted_seq' => 7,
					'wanted_fingerprint' => $source['fingerprint'],
					'window_active' => false,
					'next_boundary_at' => null,
					'source_term_ids' => array(),
					'debt_staged' => true,
					'active_run_id' => '',
					'purge_pre_seq' => 7,
					'purge_post_seq' => 7,
					'reason' => 'provider-test',
					'updated_at' => 1700000000,
				)
			);
			$this->reset_source_latches();
		}

		/** @return array<string,mixed> */
		private function static_defaults(): array {
			return array(
				'registered' => true,
				'runtime' => null,
				'state_read_failed' => false,
				'test_serving_override' => null,
				'test_price_match_sequence' => null,
				'term_meta_row_failed' => false,
				'source_memo_set' => false,
				'read_authority_available' => true,
				'read_authority_error' => '',
				'adopted_price_provider' => null,
				'adopted_price_token' => null,
				'adopted_price_snapshot' => null,
				'adopted_provider_absent' => false,
				'source_memo' => null,
			);
		}

		private function reset_source_latches(): void {
			foreach ( array( 'source_memo_set' => false, 'source_memo' => null, 'state_read_failed' => false, 'read_authority_available' => true, 'read_authority_error' => '' ) as $name => $value ) {
				$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
				$property->setAccessible( true );
				$property->setValue( null, $value );
			}
		}
	}
}
