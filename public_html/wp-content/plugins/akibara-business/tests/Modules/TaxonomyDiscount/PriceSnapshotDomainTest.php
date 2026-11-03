<?php
/**
 * Dominios semánticos de la foto adoptada, y contención de fallos del proveedor.
 *
 * La ronda 6 validó la FORMA de la foto pero no su SEMÁNTICA: una foto con
 * `enabled = 'quizás'`, `sale_policy` inventada, una fecha `2026-13-45`,
 * `timezone = '+99:99'` o un `boundary_at` ya vencido pasaba la validación y
 * gobernaba el precio. Y un `Throwable` durante el bind o el cálculo dejaba el
 * proveedor en `ACTIVE` en vez de `FAILED`.
 *
 * Los dominios replican exactamente `valid_source_option_value()` de Search
 * —incluida la decodificación de valores serializados— porque una foto que un
 * lado acepta y el otro rechaza no es una foto compartida.
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
use RuntimeException;
use WC_Product;

require_once dirname( __DIR__, 2 ) . '/Support/AuthorityWpdb.php';

final class PriceSnapshotDomainTest extends TestCase {

	private const PRODUCT = 4242;

	private const TERM = 501;

	/** Reloj determinista de todos los casos: 2026-07-25 13:20 en Santiago. */
	private const NOW = 1785000000;

	/**
	 * Cierre derivado de `to = 2027-01-01` en Santiago (`00:00 + 1 día`). Desde
	 * la ronda 7.2 el `boundary_at` no es un entero futuro cualquiera: es
	 * exactamente el instante que se recomputa desde las options.
	 */
	private const BOUNDED_END = 1798858800;

	private AuthorityWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->wpdb                = new AuthorityWpdb();
		$this->wpdb->relationships = array( self::PRODUCT => array( array( self::TERM, 'product_brand' ) ) );

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
	 * @param array<string, mixed> $overrides       Cambios al top-level.
	 * @param array<string, mixed> $option_overrides Cambios a options.
	 * @return array<string, mixed>
	 */
	private function snapshot( array $overrides = array(), array $option_overrides = array() ): array {
		$options = array(
			// akb_ ordena ANTES de akibara_ en el ksort del contrato (10 options
			// desde el 2026-09-01: el tope configurable entró a la foto).
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
		);

		foreach ( $option_overrides as $name => $row ) {
			$options[ $name ] = $row;
		}

		$snapshot = array(
			'contract'           => AdoptedSnapshot::CONTRACT,
			'source_fingerprint' => str_repeat( 'a', 64 ),
			'options'            => $options,
			'timezone'           => 'America/Santiago',
			'window_active'      => true,
			'boundary_at'        => null,
			'max_pct'            => 15,
			'decimals'           => 0,
			'taxonomies'         => array( 'pa_editorial', 'product_brand' ),
			'term_meta'          => array( array( 'meta_id' => 11, 'term_id' => self::TERM, 'raw' => '15' ) ),
		);

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

	private function provider( ?DiscountResolver $resolver = null ): SearchPriceProvider {
		return new SearchPriceProvider(
			$resolver ?? new DiscountResolver( new Settings() ),
			static fn(): int => self::NOW
		);
	}

	/**
	 * Adopta con el reloj fijo del caso.
	 *
	 * @param array<string, mixed> $snapshot Foto.
	 * @return string
	 */
	private function adopt( array $snapshot ): string {
		return ( $this->provider() )->adopt_search_snapshot( $snapshot );
	}

	// -----------------------------------------------------------------------
	// 1 — dominios semánticos de las nueve options.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider invalid_option_domains
	 * @testdox Una option fuera de su dominio semántico se rechaza
	 *
	 * @param string               $name Option.
	 * @param array<string, mixed> $row  Fila.
	 */
	public function test_option_domains_are_enforced( string $name, array $row ): void {
		$top_level = array();
		$options   = array( $name => $row );
		if ( 'gmt_offset' === $name ) {
			$top_level['timezone']     = '+00:00';
			$options['timezone_string'] = array( 'present' => false, 'raw' => '' );
		}
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->snapshot( $top_level, $options ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public function invalid_option_domains(): array {
		return array(
			'enabled inventado'      => array( 'akibara_taxonomy_discount_enabled', array( 'present' => true, 'raw' => 'quizás' ) ),
			'enabled vacío serializado'=> array( 'akibara_taxonomy_discount_enabled', array( 'present' => true, 'raw' => 's:0:"";' ) ),
			'apply_to inventado'     => array( 'akibara_taxonomy_discount_apply_to', array( 'present' => true, 'raw' => 'a_veces' ) ),
			'sale_policy inventada'  => array( 'akibara_taxonomy_discount_sale_policy', array( 'present' => true, 'raw' => 'lo_que_sea' ) ),
			'from con formato malo'  => array( 'akibara_taxonomy_discount_from', array( 'present' => true, 'raw' => '06-08-2026' ) ),
			'from inexistente'       => array( 'akibara_taxonomy_discount_from', array( 'present' => true, 'raw' => '2026-13-45' ) ),
			'to inexistente'         => array( 'akibara_taxonomy_discount_to', array( 'present' => true, 'raw' => '2026-02-30' ) ),
			'decimals no canónico'   => array( 'woocommerce_price_num_decimals', array( 'present' => true, 'raw' => '02' ) ),
			'decimals fuera de rango'=> array( 'woocommerce_price_num_decimals', array( 'present' => true, 'raw' => '9' ) ),
			'decimals negativo'      => array( 'woocommerce_price_num_decimals', array( 'present' => true, 'raw' => '-1' ) ),
			'timezone_string basura' => array( 'timezone_string', array( 'present' => true, 'raw' => "America/Santiago\0" ) ),
			'gmt_offset no canónico' => array( 'gmt_offset', array( 'present' => true, 'raw' => '5.4' ) ),
			'gmt_offset fuera rango' => array( 'gmt_offset', array( 'present' => true, 'raw' => '15' ) ),
			'gmt_offset no numérico' => array( 'gmt_offset', array( 'present' => true, 'raw' => 'cinco' ) ),
			'feed distinto de 2'     => array( 'akibara_business_search_feed_contract_version', array( 'present' => true, 'raw' => '3' ) ),
		);
	}

	/**
	 * Search decodifica el raw antes de validarlo: un valor serializado que no
	 * hace round-trip, o que esconde un array, no puede pasar por bueno.
	 *
	 * @testdox Un raw serializado ambiguo se rechaza.
	 */
	public function test_ambiguous_serialized_raw_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array(),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'a:1:{i:0;s:3:"yes";}' ) )
			)
		);
	}

	/**
	 * @testdox Un raw serializado que SÍ decodifica a un escalar válido se acepta.
	 */
	public function test_serialized_scalar_raw_is_accepted(): void {
		$token = $this->adopt(
			$this->snapshot(
				array(),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 's:3:"yes";' ) )
			)
		);

		self::assertNotSame( '', $token );
	}

	/**
	 * @testdox Los valores válidos de cada dominio se aceptan.
	 */
	public function test_valid_domains_are_accepted(): void {
		$token = $this->adopt(
			$this->snapshot(
				array( 'decimals' => 2 ),
				array(
					'akibara_taxonomy_discount_apply_to'    => array( 'present' => true, 'raw' => 'instock_and_onbackorder' ),
					'akibara_taxonomy_discount_sale_policy' => array( 'present' => true, 'raw' => 'stack_on_sale' ),
					'akibara_taxonomy_discount_from'        => array( 'present' => true, 'raw' => '2026-02-28' ),
					'woocommerce_price_num_decimals'        => array( 'present' => true, 'raw' => '2' ),
				)
			)
		);

		self::assertNotSame( '', $token );
	}

	// -----------------------------------------------------------------------
	// 2 — firma de timezone.
	// -----------------------------------------------------------------------

	/**
	 * Todos estos casos van SIN `timezone_string`, así que la firma tiene que
	 * ser el offset canónico derivado de `gmt_offset`. Desde la ronda 7.3, con
	 * un `timezone_string` literal varios de ellos (`+14:45`, `-00:00`) SÍ son
	 * firmas válidas: la estrictez vive en la derivación, no en la forma.
	 *
	 * @dataProvider invalid_timezones
	 * @testdox Sin timezone_string, una firma que no se deriva de gmt_offset se rechaza
	 *
	 * @param string $timezone Firma.
	 */
	public function test_invalid_timezone_signatures_are_rejected( string $timezone ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => $timezone ),
				array( 'timezone_string' => array( 'present' => false, 'raw' => '' ) )
			)
		);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function invalid_timezones(): array {
		return array(
			'minutos imposibles' => array( '+99:99' ),
			'minutos no cuarto'  => array( '+14:45' ),
			'cero negativo'      => array( '-00:00' ),
			'magnitud excedida'  => array( '+15:00' ),
			'sin signo'          => array( '05:30' ),
		);
	}

	/**
	 * @testdox Los offsets canónicos +05:30 y -05:30 se aceptan.
	 */
	public function test_canonical_offsets_are_accepted(): void {
		foreach ( array( '+05:30' => '5.5', '-05:30' => '-5.5' ) as $signature => $raw ) {
			$token = $this->adopt(
				$this->snapshot(
					array( 'timezone' => $signature ),
					array(
						'timezone_string' => array( 'present' => false, 'raw' => '' ),
						'gmt_offset'      => array( 'present' => true, 'raw' => $raw ),
					)
				)
			);

			self::assertNotSame( '', $token, $signature . ' debería ser válido.' );
		}
	}

	/**
	 * @testdox La firma declarada debe coincidir con la derivada de las options.
	 */
	public function test_timezone_signature_must_match_the_options(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'timezone' => '+05:30' ), // no se deriva de las options…
				array(
					'timezone_string' => array( 'present' => false, 'raw' => '' ),
					'gmt_offset'      => array( 'present' => true, 'raw' => '2' ), // …que dicen +02:00.
				)
			)
		);
	}

	/**
	 * @testdox Con timezone_string presente, la firma debe ser esa misma zona.
	 */
	public function test_iana_signature_must_match_the_option(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->snapshot( array( 'timezone' => 'Europe/Madrid' ) ) );
	}

	// -----------------------------------------------------------------------
	// 3 — boundary_at.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Un boundary_at ya vencido se rechaza en la adopción.
	 */
	public function test_past_boundary_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->bounded_snapshot( self::NOW - 1 ) );
	}

	/**
	 * @testdox Un boundary_at igual al ahora tampoco sirve: tiene que ser futuro.
	 */
	public function test_present_boundary_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->bounded_snapshot( self::NOW ) );
	}

	/**
	 * @testdox Un boundary_at futuro se acepta.
	 */
	public function test_future_boundary_is_accepted(): void {
		self::assertNotSame( '', $this->adopt( $this->bounded_snapshot( self::BOUNDED_END ) ) );
	}

	/**
	 * @testdox Un boundary futuro pero distinto del derivado también se rechaza.
	 */
	public function test_future_but_wrong_boundary_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->bounded_snapshot( self::NOW + 60 ) );
	}

	/**
	 * Foto con ventana ACOTADA: un `boundary_at` sólo tiene sentido cuando la
	 * configuración declara un `to`.
	 *
	 * @param int $boundary Instante del límite.
	 * @return array<string, mixed>
	 */
	private function bounded_snapshot( int $boundary ): array {
		return $this->snapshot(
			array( 'boundary_at' => $boundary ),
			array( 'akibara_taxonomy_discount_to' => array( 'present' => true, 'raw' => '2027-01-01' ) )
		);
	}

	/**
	 * @testdox Con el módulo apagado, la ventana no puede declararse activa.
	 */
	public function test_disabled_module_cannot_have_an_active_window(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'window_active' => true ),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ) )
			)
		);
	}

	/**
	 * Apagar el módulo no borra el calendario: lo que cambia es que no se
	 * aplica. El límite declarado sigue teniendo que ser el derivado, y
	 * `NOW + 60` no lo es [ronda 7.2].
	 *
	 * @testdox Con el módulo apagado, el boundary declarado sigue siendo el derivado.
	 */
	public function test_disabled_module_cannot_declare_an_arbitrary_boundary(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array( 'window_active' => false, 'boundary_at' => self::NOW + 60 ),
				array(
					'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 'no' ),
					'akibara_taxonomy_discount_to'      => array( 'present' => true, 'raw' => '2027-01-01' ),
				)
			)
		);
	}

	/**
	 * @testdox Sin from ni to y con el módulo activo, la ventana es infinita: activa y sin boundary.
	 */
	public function test_open_window_must_be_active_without_boundary(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->snapshot( array( 'window_active' => false ) ) );
	}

	// -----------------------------------------------------------------------
	// 4 — un Throwable del bind/resolve deja FAILED sticky.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Un Throwable durante el cálculo deja el proveedor en FAILED, con la causa preservada.
	 */
	public function test_throwable_during_resolve_fails_sticky(): void {
		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		$exploding                    = new ExplodingProduct( 'instock' );
		$exploding->akb_id            = self::PRODUCT;
		$exploding->akb_regular_price = '10000';

		try {
			$provider( 10000.0, $exploding );
			self::fail( 'Un Throwable del cálculo no puede pasar desapercibido.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			self::assertInstanceOf( RuntimeException::class, $e->getPrevious(), 'La causa original debe preservarse.' );
			self::assertSame( 'getter de WooCommerce reventado', $e->getPrevious()->getMessage() );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertFalse( $provider->snapshot_is_fresh( $token ) );
	}

	/**
	 * @testdox Tras el Throwable, una segunda invocación falla sin reintentar el cálculo.
	 */
	public function test_second_invocation_after_throwable_does_not_retry(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot() );

		$exploding                    = new ExplodingProduct( 'instock' );
		$exploding->akb_id            = self::PRODUCT;
		$exploding->akb_regular_price = '10000';

		try {
			$provider( 10000.0, $exploding );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		$queries_after_first = count( $this->wpdb->queries );

		try {
			$provider( 10000.0, $this->product() );
			self::fail( 'De FAILED no se vuelve.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		self::assertCount( $queries_after_first, $this->wpdb->queries, 'No puede reintentar el cálculo.' );
	}

	// -----------------------------------------------------------------------
	// 5 — invalidar memos no puede dejar un estado híbrido.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Invalidar los memos derivados también suelta la foto adoptada.
	 */
	public function test_invalidating_memos_drops_the_adopted_snapshot(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );
		$provider = $this->provider( $resolver );

		$provider->adopt_search_snapshot( $this->snapshot() );
		$provider( 10000.0, $this->product() );

		self::assertNotNull( $settings->adopted_snapshot() );

		$resolver->invalidate_derived_memos();

		self::assertNull( $settings->adopted_snapshot(), 'La foto vieja no puede sobrevivir a la invalidación.' );
		self::assertFalse( $settings->in_strict_mode(), 'Ni el modo estricto que dependía de ella.' );
	}

	/**
	 * @testdox Tras invalidar, ninguna ruta legacy reutiliza el porcentaje de A.
	 */
	public function test_no_legacy_path_reuses_the_old_snapshot(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );
		$provider = $this->provider( $resolver );

		$provider->adopt_search_snapshot( $this->snapshot() );
		self::assertSame( 8500.0, $provider( 10000.0, $this->product() ) );

		// La base dice otra cosa que la foto A.
		$this->wpdb->option_rows    = array( Settings::OPTION_ENABLED => 'yes' );
		$this->wpdb->term_meta_rows = array( self::TERM => '10' );

		$resolver->invalidate_derived_memos();

		// La MISMA instancia vuelve a leer de la base, no de A.
		self::assertSame( 9000.0, $resolver->resolve_strict( $this->product() ) );
		self::assertTrue( $resolver->is_authoritative(), 'Invalidar no puede envenenar la autoridad.' );

		// Y una instancia NUEVA —el caso que no admite discusión— lee B.
		self::assertSame( 9000.0, ( new DiscountResolver( new Settings() ) )->resolve_strict( $this->product() ) );
	}

	// -----------------------------------------------------------------------
	// 6 — listas vacías legítimas.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Una foto sin taxonomías es válida y significa "nada que barrer".
	 */
	public function test_empty_taxonomies_are_valid(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot( array( 'taxonomies' => array() ) ) );

		self::assertSame( 10000.0, $provider( 10000.0, $this->product() ) );
		self::assertTrue( $provider->is_authoritative() );
	}

	/**
	 * @testdox Una foto sin term_meta es válida y significa "ningún término lleva descuento".
	 */
	public function test_empty_term_meta_is_valid(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot( $this->snapshot( array( 'term_meta' => array() ) ) );

		self::assertSame( 10000.0, $provider( 10000.0, $this->product() ) );
		self::assertTrue( $provider->is_authoritative() );
	}

	// -----------------------------------------------------------------------
	// 7 — cero SQL de configuración con VARIAS invocaciones.
	// -----------------------------------------------------------------------

	/**
	 * @testdox Tres productos seguidos no producen ni una consulta de configuración.
	 */
	public function test_multiple_invocations_never_read_configuration(): void {
		$this->wpdb->relationships = array(
			4242 => array( array( self::TERM, 'product_brand' ) ),
			4243 => array( array( self::TERM, 'product_brand' ) ),
			4244 => array( array( self::TERM, 'product_brand' ) ),
		);

		$provider = $this->provider();
		$token    = $provider->adopt_search_snapshot( $this->snapshot() );

		foreach ( array( 4242, 4243, 4244 ) as $id ) {
			$product                    = new WC_Product( 'instock' );
			$product->akb_id            = $id;
			$product->akb_regular_price = '10000';

			self::assertSame( 8500.0, $provider( 10000.0, $product ) );
		}

		$provider->snapshot_is_fresh( $token );

		self::assertCount( 3, $this->wpdb->queries, 'Una consulta por producto y ninguna de configuración.' );
		foreach ( $this->wpdb->queries as $query ) {
			self::assertStringContainsString( 'term_relationships', $query );
		}
	}
	// -----------------------------------------------------------------------
	// Firma pública sellada.
	// -----------------------------------------------------------------------

	/**
	 * Search descubre el proveedor por su firma exacta. Un segundo parámetro
	 * —aunque sea opcional— rompería ese discovery, así que el reloj se inyecta
	 * por constructor [ronda 7.1].
	 *
	 * @testdox adopt_search_snapshot conserva su firma sellada: un array obligatorio, devuelve string.
	 */
	public function test_public_signature_is_sealed(): void {
		$method = new \ReflectionMethod( SearchPriceProvider::class, 'adopt_search_snapshot' );

		self::assertTrue( $method->isPublic() );
		self::assertSame( 1, $method->getNumberOfParameters(), 'Exactamente un parámetro.' );
		self::assertSame( 1, $method->getNumberOfRequiredParameters(), 'Y es obligatorio.' );

		$parameter = $method->getParameters()[0];
		self::assertSame( 'snapshot', $parameter->getName() );
		self::assertFalse( $parameter->isOptional() );
		self::assertSame( 'array', (string) $parameter->getType() );
		self::assertSame( 'string', (string) $method->getReturnType() );
	}

	/**
	 * @testdox Las otras dos firmas públicas del contrato también están selladas.
	 */
	public function test_companion_signatures_are_sealed(): void {
		$fresh = new \ReflectionMethod( SearchPriceProvider::class, 'snapshot_is_fresh' );
		self::assertSame( 1, $fresh->getNumberOfRequiredParameters() );
		self::assertSame( 'string', (string) $fresh->getParameters()[0]->getType() );
		self::assertSame( 'bool', (string) $fresh->getReturnType() );

		$snapshot = new \ReflectionMethod( SearchPriceProvider::class, 'authoritative_snapshot' );
		self::assertSame( 0, $snapshot->getNumberOfParameters() );
		self::assertSame( 'array', (string) $snapshot->getReturnType() );
	}

	// -----------------------------------------------------------------------
	// Addendum — vínculo verificado, decodificación efectiva y dominios finos.
	// -----------------------------------------------------------------------

	/**
	 * El proveedor no puede confiar en que el resolver siga ligado a SU foto: si
	 * otro actor invalidó los memos entre dos invocaciones, el precio se estaría
	 * calculando con otra configuración bajo el token viejo.
	 *
	 * @testdox Tras invalidar los memos, el final-check falla y la siguiente invocación termina FAILED sin leer.
	 */
	public function test_unbinding_between_invocations_fails_sticky(): void {
		$settings = new Settings();
		$resolver = new DiscountResolver( $settings );
		$provider = $this->provider( $resolver );

		$token = $provider->adopt_search_snapshot( $this->snapshot() );
		self::assertSame( 8500.0, $provider( 10000.0, $this->product() ) );

		// Otro actor suelta la foto.
		$resolver->invalidate_derived_memos();

		self::assertFalse( $provider->snapshot_is_fresh( $token ) );

		$queries_before = count( $this->wpdb->queries );

		try {
			$provider( 10000.0, $this->product() );
			self::fail( 'Sin vínculo con la foto no puede calcularse un precio.' );
		} catch ( PriceAuthorityUnavailable $e ) {
			unset( $e );
		}

		self::assertSame( SearchPriceProvider::STATE_FAILED, $provider->state() );
		self::assertCount( $queries_before, $this->wpdb->queries, 'No puede haber self-read tras perder el vínculo.' );
	}

	/**
	 * Aceptar `s:3:"yes";` y luego compararlo literalmente contra `'yes'` daría
	 * "desactivado" en silencio: si se acepta, tiene que decodificarse como lo
	 * hace Search.
	 *
	 * @testdox Un raw serializado aceptado se interpreta por su valor decodificado, no por su literal.
	 */
	public function test_serialized_raw_is_interpreted_by_its_decoded_value(): void {
		$provider = $this->provider();
		$provider->adopt_search_snapshot(
			$this->snapshot(
				array(),
				array( 'akibara_taxonomy_discount_enabled' => array( 'present' => true, 'raw' => 's:3:"yes";' ) )
			),
			self::NOW
		);

		self::assertSame( 8500.0, $provider( 10000.0, $this->product() ), 'El módulo está ACTIVO: el raw decodifica a yes.' );
	}

	/**
	 * @testdox Un meta_id repetido entre términos distintos se rechaza.
	 */
	public function test_globally_duplicated_meta_id_is_rejected(): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt(
			$this->snapshot(
				array(
					'term_meta' => array(
						array( 'meta_id' => 11, 'term_id' => 501, 'raw' => '15' ),
						array( 'meta_id' => 11, 'term_id' => 502, 'raw' => '10' ),
					),
				)
			)
		);
	}

	/**
	 * @dataProvider invalid_taxonomy_slugs
	 * @testdox Un slug de taxonomía fuera del dominio se rechaza
	 *
	 * @param string $slug Slug inválido.
	 */
	public function test_invalid_taxonomy_slugs_are_rejected( string $slug ): void {
		$this->expectException( PriceAuthorityUnavailable::class );
		$this->adopt( $this->snapshot( array( 'taxonomies' => array( $slug ) ) ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function invalid_taxonomy_slugs(): array {
		return array(
			'con NUL'            => array( "product_brand\0" ),
			'con espacio'        => array( 'product brand' ),
			'con mayúsculas'     => array( 'Product_Brand' ),
			'demasiado largo'    => array( str_repeat( 'a', 33 ) ),
			'con barra'          => array( 'product/brand' ),
		);
	}

	/**
	 * @testdox Un slug de 32 caracteres —el máximo de WordPress— sigue siendo válido.
	 */
	public function test_maximum_length_slug_is_valid(): void {
		self::assertNotSame(
			'',
			$this->adopt( $this->snapshot( array( 'taxonomies' => array( str_repeat( 'a', 32 ) ) ) ) )
		);
	}
}

/**
 * Producto cuyo getter revienta, como puede hacerlo un `WC_Product` real con una
 * integración de terceros enganchada a `woocommerce_product_get_regular_price`.
 */
final class ExplodingProduct extends WC_Product {

	public function get_regular_price( string $context = 'view' ): string {
		unset( $context );
		throw new RuntimeException( 'getter de WooCommerce reventado' );
	}
}
