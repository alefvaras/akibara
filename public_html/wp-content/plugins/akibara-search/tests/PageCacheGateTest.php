<?php
/**
 * Contrato del gate condicional de page-cache.
 *
 * Hasta 0.1.14 `cap_page_cache_ttl()` marcaba no-store en TODA petición de
 * front-end. Acá se fija el predicado que lo vuelve condicional y —sobre todo—
 * las cuatro formas en que sigue cerrando: campaña viva, lease sin resolver,
 * token que cambia en vuelo y borde de ventana dentro del horizonte.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Search\Tests;

use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\DocumentBuilder;
use Akibara\Search\FacetRegistry;
use Akibara\Search\Indexer;
use Akibara\Search\Install;
use Akibara\Search\ProductSourceAuthority;
use Akibara\Search\RebuildJob;
use Akibara\Search\SemanticPublication;
use Akibara\Search\Support\RestCache;
use Akibara\Search\WorkRepository;
use Akibara\Search\WriteFence;
use Akibara\Business\Modules\TaxonomyDiscount\SearchPriceProvider;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** wpdb determinista para el gate de página. */
final class PageCacheGateWpdb {

	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $termmeta = 'wp_termmeta';
	public string $posts = 'wp_posts';
	public string $last_error = '';

	/** @var array<string, string> */
	public array $raw_options = array();

	/** @var array<int, object> */
	public array $database_rows = array();

	public int $docs_count = 3;
	public int $reads = 0;

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$query = (string) preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}

	/** @return array<int, object> */
	public function get_results( string $query ): array {
		++$this->reads;
		$this->last_error = '';
		if ( false !== stripos( $query, 'option_name, option_value' ) ) {
			$rows = array();
			$raw  = $this->raw_options;
			ksort( $raw, SORT_STRING );
			foreach ( $raw as $name => $value ) {
				if ( false === strpos( $query, "'" . str_replace( "'", "''", (string) $name ) . "'" ) ) {
					continue;
				}
				$rows[] = (object) array( 'option_name' => $name, 'option_value' => $value );
			}
			return $rows;
		}
		if ( false !== stripos( $query, "SELECT 'meta' source_kind" ) ) {
			return $this->database_rows;
		}
		return array();
	}

	/** @return int|string|null */
	public function get_var( string $query ) {
		++$this->reads;
		$this->last_error = '';
		if ( false !== stripos( $query, 'GET_LOCK(' ) || false !== stripos( $query, 'RELEASE_LOCK(' ) ) {
			return '1';
		}
		if ( false !== stripos( $query, 'CONNECTION_ID()' ) || false !== stripos( $query, 'IS_USED_LOCK(' ) ) {
			return '37';
		}
		if ( false !== stripos( $query, 'COUNT(*)' ) && false !== stripos( $query, Install::docs_table() ) ) {
			return (string) $this->docs_count;
		}
		if ( 1 === preg_match( "/option_name = '((?:''|[^'])*)'/", $query, $match ) ) {
			$name = str_replace( "''", "'", $match[1] );
			return $this->raw_options[ $name ] ?? null;
		}
		return '0';
	}

	/** @var array<int, int> Productos elegibles del catálogo de prueba. */
	public array $eligible_ids = array( 11, 12, 13 );

	/** @return array<int, string> */
	public function get_col( string $query ) {
		++$this->reads;
		$this->last_error = '';
		unset( $query );
		return array_map( 'strval', $this->eligible_ids );
	}

	/** @return object|null */
	public function get_row( string $query ) {
		++$this->reads;
		$this->last_error = '';
		unset( $query );
		return null;
	}

	/** @param array<string, mixed> $data @return int|false */
	public function insert( string $table, array $data, $format = null ) {
		unset( $table, $data, $format );
		$this->last_error = '';
		return 1;
	}

	/** @param array<string, mixed> $data @param array<string, mixed> $where @return int|false */
	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		unset( $table, $data, $where, $format, $where_format );
		$this->last_error = '';
		return 1;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @return int|false */
	public function query( string $query ) {
		$this->last_error = '';
		if ( 1 === preg_match( "/INSERT IGNORE INTO .* VALUES \('((?:''|[^'])*)', '((?:''|[^'])*)', 'no'\)/s", $query, $match ) ) {
			$name  = str_replace( "''", "'", $match[1] );
			$value = str_replace( "''", "'", $match[2] );
			if ( ! array_key_exists( $name, $this->raw_options ) ) {
				$this->raw_options[ $name ] = $value;
			}
			return 1;
		}
		// CAS real de `replace_state_cas()`: sólo escribe si los bytes previos
		// siguen ahí. Sin esto el rail de publicación no avanza y jamás purga.
		if ( 1 === preg_match(
			"/UPDATE .*options SET option_value = '((?:''|[^'])*)', autoload = '(?:[^']*)' WHERE option_name = '((?:''|[^'])*)' AND BINARY option_value = BINARY '((?:''|[^'])*)'/s",
			$query,
			$match
		) ) {
			$value    = str_replace( "''", "'", $match[1] );
			$name     = str_replace( "''", "'", $match[2] );
			$expected = str_replace( "''", "'", $match[3] );
			if ( ( $this->raw_options[ $name ] ?? null ) !== $expected ) {
				return 0;
			}
			$this->raw_options[ $name ] = $value;
			return 1;
		}
		// Trabajo/deuda del indexador: fuera del alcance de este contrato.
		return 1;
	}
}

/** @covers \Akibara\Search\EditorialDiscountPublication */
final class PageCacheGateTest extends TestCase {
	use \Akibara\Search\Tests\Support\MarcosEditorialesVigilados;


	private PageCacheGateWpdb $db;

	/** @var array<int, array<int, mixed>> Acciones LiteSpeed observadas. */
	private array $actions = array();

	/** @var array<int, array<int, mixed>> Hooks registrados en el request. */
	private array $hooks = array();

	/** @var array<string, mixed> */
	private array $original_static = array();

	/** @var mixed */
	private $previous_wpdb;
	private bool $had_previous_wpdb = false;

	/** @var array<string, mixed> */
	private array $previous_server = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->had_previous_wpdb = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$this->db                = new PageCacheGateWpdb();
		$GLOBALS['wpdb']         = $this->db;
		$this->actions           = array();
		$this->hooks             = array();
		unset( $GLOBALS['wp_filter']['akb_search_effective_price'] );

		// Superficie pública canónica: GET, sin query string, sin cookies.
		$this->previous_server = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';
		$_GET                      = array();
		$_COOKIE                   = array();

		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'is_admin' )->justReturn( false );
		// Defaults deterministas: Patchwork deja el símbolo DEFINIDO después del
		// tearDown de otro archivo, así que `function_exists()` da true y la
		// llamada sin expectativa lanza. El gate lo atrapa y cierra —correcto en
		// producción, pero volvía este suite dependiente del orden.
		foreach ( array( 'is_user_logged_in', 'is_preview', 'is_customize_preview', 'post_password_required', 'is_cart', 'is_checkout', 'is_account_page', 'is_singular' ) as $predicate ) {
			Functions\when( $predicate )->justReturn( false );
		}
		Functions\when( 'sanitize_key' )->alias( static fn( string $key ): string => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) );
		// Action Scheduler despierta el trabajo; el contrato de acá es la purga.
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_next_scheduled_action' )->justReturn( false );
		Functions\when( 'as_unschedule_all_actions' )->justReturn( null );
		Functions\when( 'maybe_serialize' )->alias( static fn( $value ): string => is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value );
		Functions\when( 'do_action' )->alias(
			function ( ...$args ) {
				$this->actions[] = $args;
				return null;
			}
		);
		Functions\when( 'add_action' )->alias(
			function ( ...$args ) {
				$this->hooks[] = $args;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->db->raw_options[ $name ] = is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $default = false ) {
				if ( ! array_key_exists( $name, $this->db->raw_options ) ) {
					return $default;
				}
				$raw = $this->db->raw_options[ $name ];
				$un  = @unserialize( $raw, array( 'allowed_classes' => false ) );
				return false === $un && 'b:0;' !== $raw ? $raw : $un;
			}
		);

		foreach ( $this->publication_static_defaults() as $name => $value ) {
			$this->original_static[ $name ] = $this->static_value( $name );
			$this->set_static_value( $name, $value );
		}
		$this->reset_rebuild_statics();
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
	}

	protected function tearDown(): void {
		// Primero, con los stubs montados: un marco abierto no puede cerrarse al apagar PHP.
		$this->soltar_publicaciones_vigiladas();
		foreach ( $this->original_static as $name => $value ) {
			$this->set_static_value( $name, $value );
		}
		$this->reset_rebuild_statics();
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
		$_SERVER = $this->previous_server;
		$_GET    = array();
		$_COOKIE = array();
		if ( $this->had_previous_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------- estado normal

	/** @testdox Sin campaña y con la lease resuelta, la página NO se marca no-store. */
	public function test_without_campaign_and_with_a_resolved_lease_the_page_is_cacheable(): void {
		$this->install_ready_bundle( array() );

		$this->publication()->cap_page_cache_ttl();

		self::assertFalse( $this->no_store_was_marked(), (string) json_encode( $this->actions ) );
		self::assertSame(
			array( 'litespeed_control_set_ttl', EditorialDiscountPublication::PAGE_CACHE_TTL, 'akibara editorial publication lease' ),
			$this->action( 'litespeed_control_set_ttl' )
		);
	}

	/** @testdox La respuesta autorizada engancha la revalidación final de LiteSpeed. */
	public function test_authorized_response_registers_the_final_revalidation(): void {
		$this->install_ready_bundle( array() );

		$publication = $this->publication();
		$publication->cap_page_cache_ttl();

		$finalize = array_values(
			array_filter( $this->hooks, static fn( array $hook ): bool => 'litespeed_control_finalize' === ( $hook[0] ?? '' ) )
		);
		self::assertCount( 1, $finalize );
		self::assertSame( array( $publication, 'finalize_page_cache' ), $finalize[0][1] );
	}

	/**
	 * REGRESIÓN — el memo de la revalidación final era ÚNICO para todo el request.
	 *
	 * En un archive de producto el orden real de WordPress es
	 * `pre_get_posts` → `woocommerce_product_query:20` →
	 * `FacetQueryIntegration::finalize_request_lease()` → `template_redirect:1`
	 * (donde recién se engancha este gate) → … → `shutdown`. Con un memo único, la
	 * faceta ya había quemado la revalidación ANTES de que el gate existiera, y
	 * `finalize_page_cache()` recibía el veredicto viejo sin releer nada.
	 *
	 * El daño concreto: se enciende la campaña mientras alguien renderiza
	 * `/manga/`; ese request tomó la foto ANTES del purge y almacena DESPUÉS, con
	 * TTL de una hora — archive con precio de lista mientras el carrito cobra con
	 * descuento, y la ficha del producto sin el defecto, así que la incoherencia
	 * queda a la vista entre las dos.
	 *
	 * La prueba imita ese orden: un consumidor cualquiera revalida primero y el
	 * gate de página tiene que seguir cerrando cuando la publicación cambia.
	 *
	 * @testdox La revalidación de otro consumidor no puede dar por buena la de la página.
	 */
	public function test_another_consumer_revalidation_does_not_satisfy_the_page_gate(): void {
		$this->install_ready_bundle( array() );

		$publication = $this->publication();
		$publication->cap_page_cache_ttl();
		self::assertFalse( $this->no_store_was_marked(), 'Precondición: la página quedó autorizada.' );

		// Otro consumidor (la faceta) revalida ANTES y memoiza SU veredicto. En
		// producción esto ocurre en `woocommerce_product_query:20`, o sea antes
		// incluso de que `template_redirect:1` enganche este gate.
		$token = $this->static_value( 'serving_capture_token' );
		self::assertTrue( EditorialDiscountPublication::serving_token_matches( (string) $token, 'facets' ) );

		// Y RECIÉN AHORA un writer publica otra generación, entre la captura y el
		// almacenamiento — el mismo escenario que ya cubre
		// `test_token_that_changes_in_flight_closes_at_finalize`, pero con la
		// faceta habiendo pasado primero.
		$this->rewrite_generation( 'generation-b' );
		$this->actions = array();

		$publication->finalize_page_cache( false );

		self::assertTrue(
			$this->no_store_was_marked(),
			'Con el memo compartido esto pasaba: la faceta revalidaba por la página. ' . (string) json_encode( $this->actions )
		);
	}

	// ---------------------------------------------------------------- campaña viva

	/** @testdox Con campaña viva la página vuelve a no-store. */
	public function test_live_campaign_keeps_the_page_no_store(): void {
		$this->install_ready_bundle(
			array(
				'akibara_taxonomy_discount_enabled' => 'yes',
				'akibara_taxonomy_discount_from'    => '',
				'akibara_taxonomy_discount_to'      => '',
			)
		);

		$this->publication()->cap_page_cache_ttl();

		self::assertTrue( $this->no_store_was_marked() );
		self::assertNull( $this->action( 'litespeed_control_set_ttl' ) );
	}

	/** @testdox Una campaña que ya empezó y todavía no termina es campaña viva. */
	public function test_campaign_inside_its_dates_keeps_the_page_no_store(): void {
		$zone = new DateTimeZone( 'America/Santiago' );
		$this->install_ready_bundle(
			array(
				'timezone_string'                   => 'America/Santiago',
				'akibara_taxonomy_discount_enabled' => 'yes',
				'akibara_taxonomy_discount_from'    => $this->local_date( $zone, '-3 days' ),
				'akibara_taxonomy_discount_to'      => $this->local_date( $zone, '+3 days' ),
			)
		);

		$this->publication()->cap_page_cache_ttl();

		self::assertTrue( $this->no_store_was_marked() );
	}

	// ---------------------------------------------------------------- lease cerrada

	/** @testdox Sin lease resuelta la página vuelve a no-store. */
	public function test_unresolved_lease_keeps_the_page_no_store(): void {
		$this->install_ready_bundle( array() );
		$this->db->docs_count = 0; // El conteo físico ya no acredita el receipt.

		$this->publication()->cap_page_cache_ttl();

		self::assertNull( EditorialDiscountPublication::capture_serving_token() );
		self::assertTrue( $this->no_store_was_marked() );
		self::assertNull( $this->action( 'litespeed_control_set_ttl' ) );
	}

	/** @testdox Un token que cambia en vuelo cierra la respuesta en la revalidación final. */
	public function test_token_that_changes_in_flight_closes_at_finalize(): void {
		$this->install_ready_bundle( array() );
		$publication = $this->publication();
		$publication->cap_page_cache_ttl();
		self::assertFalse( $this->no_store_was_marked() );

		// Un writer publica otra generación entre la captura y el almacenamiento.
		$this->rewrite_generation( 'generation-b' );
		$this->actions = array();

		$publication->finalize_page_cache( false );

		self::assertTrue( $this->no_store_was_marked() );
	}

	/** @testdox Sin token capturado la revalidación final también cierra. */
	public function test_finalize_without_a_captured_token_closes(): void {
		$this->install_ready_bundle( array() );

		$this->publication()->finalize_page_cache( false );

		self::assertTrue( $this->no_store_was_marked() );
	}

	// ---------------------------------------------------------------- bordes de ventana

	/**
	 * @testdox Un borde dentro del horizonte no autoriza caché.
	 *
	 * Este es el caso peligroso: la ventana se abre SOLA a medianoche y ningún
	 * hook lo anuncia. Se fija la zona por gmt_offset para que la hora local sea
	 * las 13:00 y la medianoche siguiente caiga a 11 h, dentro del horizonte.
	 */
	public function test_boundary_inside_the_horizon_is_not_authorized(): void {
		$zone = $this->zone_with_local_hour( 13 );
		$this->install_ready_bundle(
			array(
				'timezone_string'                   => '',
				'gmt_offset'                        => $this->gmt_offset_for_local_hour( 13 ),
				'akibara_taxonomy_discount_enabled' => 'yes',
				'akibara_taxonomy_discount_from'    => $this->local_date( $zone, '+1 day' ),
			)
		);

		$remaining = $this->seconds_to_next_local_midnight( $zone );
		self::assertLessThanOrEqual( EditorialDiscountPublication::PAGE_CACHE_BOUNDARY_HORIZON, $remaining );

		$this->publication()->cap_page_cache_ttl();

		self::assertTrue( $this->no_store_was_marked(), 'Un borde a ' . $remaining . ' s no puede autorizar caché.' );
		self::assertNull( $this->action( 'litespeed_control_set_ttl' ) );
	}

	/** @testdox Un borde lejano sí autoriza caché, y el TTL nunca lo alcanza. */
	public function test_boundary_far_beyond_the_horizon_is_authorized(): void {
		$zone = new DateTimeZone( 'America/Santiago' );
		$this->install_ready_bundle(
			array(
				'timezone_string'                   => 'America/Santiago',
				'akibara_taxonomy_discount_enabled' => 'yes',
				'akibara_taxonomy_discount_from'    => $this->local_date( $zone, '+30 days' ),
			)
		);

		$this->publication()->cap_page_cache_ttl();

		self::assertFalse( $this->no_store_was_marked() );
		$ttl = $this->action( 'litespeed_control_set_ttl' );
		self::assertIsArray( $ttl );
		self::assertSame( EditorialDiscountPublication::PAGE_CACHE_TTL, $ttl[1] );
		self::assertLessThan(
			$this->seconds_to_next_local_midnight( $zone ) + 29 * 86400,
			(int) $ttl[1]
		);
	}

	/**
	 * @testdox INVARIANTE: el horizonte supera el TTL, así nada almacenado sobrevive al borde.
	 *
	 * Es la prueba de la garantía entera. Si alguien sube PAGE_CACHE_TTL por
	 * encima del horizonte, una página guardada justo antes de entrar a la franja
	 * ciega seguiría viva cuando la campaña arranque.
	 */
	public function test_horizon_strictly_exceeds_the_authorized_ttl(): void {
		self::assertGreaterThan(
			EditorialDiscountPublication::PAGE_CACHE_TTL,
			EditorialDiscountPublication::PAGE_CACHE_BOUNDARY_HORIZON
		);
	}

	/** @testdox El TTL autorizado nunca pasa del borde, sea cual sea la distancia. */
	public function test_authorized_ttl_never_outlives_the_boundary(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );
		$horizon = EditorialDiscountPublication::PAGE_CACHE_BOUNDARY_HORIZON;

		$cases = array(
			null,
			0,
			1,
			$horizon - 1,
			$horizon,
			$horizon + 1,
			$horizon + 3600,
			30 * 86400,
		);
		foreach ( $cases as $remaining ) {
			$this->set_static_value(
				'serving_capture_window',
				array(
					'active'      => false,
					'boundary_at' => null === $remaining ? null : time() + $remaining,
				)
			);
			$ttl = $method->invoke( null );
			if ( null === $ttl ) {
				continue;
			}
			self::assertIsInt( $ttl );
			self::assertLessThanOrEqual( EditorialDiscountPublication::PAGE_CACHE_TTL, $ttl );
			if ( null === $remaining ) {
				continue; // Sin borde el techo es el TTL del plugin.
			}
			self::assertGreaterThan( $ttl, (int) $remaining, 'El TTL autorizado sobreviviría al borde.' );
		}
	}

	/** @testdox La ficha de producto vive hasta la medianoche de la tienda, menos el margen. */
	public function test_product_page_lives_until_store_midnight(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		$zone = 'America/Santiago';
		$this->set_static_value( 'serving_capture_window', array( 'active' => false, 'boundary_at' => null, 'timezone' => $zone ) );

		$antes    = EditorialDiscountPublication::seconds_before_store_midnight( time(), $zone );
		$ttl      = $method->invoke( null );
		$despues  = EditorialDiscountPublication::seconds_before_store_midnight( time(), $zone );
		$midnight = ( new \DateTimeImmutable( 'now', new DateTimeZone( $zone ) ) )->modify( 'tomorrow' )->getTimestamp();

		if ( null === $antes ) {
			self::assertNull( $ttl, 'En el último minuto del día la ficha no se guarda.' );
			return;
		}
		self::assertIsInt( $ttl );
		self::assertGreaterThanOrEqual( (int) $despues, $ttl );
		self::assertLessThanOrEqual( $antes, $ttl );
		self::assertLessThanOrEqual( $midnight - EditorialDiscountPublication::PRODUCT_PAGE_MIDNIGHT_MARGIN, time() + $ttl, 'La ficha no puede cruzar la medianoche.' );
	}

	/** @testdox Un listado sigue con el techo de una hora aunque haya zona. */
	public function test_listing_keeps_the_hourly_ceiling(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );
		Functions\when( 'is_singular' )->justReturn( false );
		$this->set_static_value( 'serving_capture_window', array( 'active' => false, 'boundary_at' => null, 'timezone' => 'America/Santiago' ) );

		self::assertSame( EditorialDiscountPublication::PAGE_CACHE_TTL, $method->invoke( null ) );
	}

	/** @testdox Una ficha sin zona legible vuelve al techo de una hora: la duda no alarga la caché. */
	public function test_product_without_readable_zone_keeps_the_hourly_ceiling(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		foreach ( array( array(), array( 'timezone' => '' ), array( 'timezone' => 7 ) ) as $extra ) {
			$this->set_static_value( 'serving_capture_window', array_merge( array( 'active' => false, 'boundary_at' => null ), $extra ) );
			self::assertSame( EditorialDiscountPublication::PAGE_CACHE_TTL, $method->invoke( null ) );
		}
		$this->set_static_value( 'serving_capture_window', array( 'active' => false, 'boundary_at' => null, 'timezone' => 'No/Existe' ) );
		self::assertNull( $method->invoke( null ), 'Una zona que no existe no autoriza guardar la ficha.' );
	}

	/** @testdox La ficha nunca pasa del borde de campaña ni de la medianoche, sea cual sea la distancia. */
	public function test_product_ttl_never_outlives_the_boundary_or_midnight(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );
		Functions\when( 'is_singular' )->alias( static fn( $type = '' ): bool => 'product' === $type );
		$horizon = EditorialDiscountPublication::PAGE_CACHE_BOUNDARY_HORIZON;
		foreach ( array( $horizon - 1, $horizon, $horizon + 1, $horizon + 90, 2 * 86400, 30 * 86400 ) as $remaining ) {
			$this->set_static_value( 'serving_capture_window', array( 'active' => false, 'boundary_at' => time() + $remaining, 'timezone' => 'America/Santiago' ) );
			$ttl = $method->invoke( null );
			if ( null === $ttl ) {
				continue;
			}
			self::assertIsInt( $ttl );
			self::assertLessThan( $remaining, $ttl, 'La ficha sobreviviría al borde.' );
			$hasta = EditorialDiscountPublication::seconds_before_store_midnight( time(), 'America/Santiago' );
			self::assertNotNull( $hasta );
			self::assertLessThanOrEqual( $hasta + 1, $ttl, 'La ficha cruzaría la medianoche.' );
		}
	}

	/**
	 * @testdox La medianoche sale del calendario de la tienda, con cambio de hora incluido.
	 *
	 * Chile adelanta el reloj la noche del primer sábado de septiembre (23:59:59 → 01:00): esa
	 * medianoche no existe y la próxima hora válida está a 30 minutos, no a 90. En abril atrasa
	 * (la última hora del sábado dura dos): de 23:30 a medianoche son 90 minutos, no 30.
	 */
	public function test_seconds_before_store_midnight_follows_the_store_calendar(): void {
		$zone   = new DateTimeZone( 'America/Santiago' );
		$margen = EditorialDiscountPublication::PRODUCT_PAGE_MIDNIGHT_MARGIN;
		$casos  = array(
			'2026-09-15 12:00:00' => 43200 - $margen,
			'2026-09-05 23:30:00' => 1800 - $margen,
			'2026-04-04 23:30:00' => 5400 - $margen,
		);
		foreach ( $casos as $local => $esperado ) {
			$now = ( new \DateTimeImmutable( $local, $zone ) )->getTimestamp();
			self::assertSame( $esperado, EditorialDiscountPublication::seconds_before_store_midnight( $now, 'America/Santiago' ), $local );
		}
		$ultimo = ( new \DateTimeImmutable( '2026-09-15 23:59:30', $zone ) )->getTimestamp();
		self::assertNull( EditorialDiscountPublication::seconds_before_store_midnight( $ultimo, 'America/Santiago' ), 'Dentro del margen no hay TTL.' );
		$fijo = ( new \DateTimeImmutable( '2026-09-15 20:00:00', new DateTimeZone( '-03:00' ) ) )->getTimestamp();
		self::assertSame( 4 * 3600 - $margen, EditorialDiscountPublication::seconds_before_store_midnight( $fijo, '-03:00' ), 'Con gmt_offset la zona es un desfase fijo.' );
		self::assertNull( EditorialDiscountPublication::seconds_before_store_midnight( $fijo, 'No/Existe' ) );
	}

	/** @testdox Una ventana activa nunca autoriza caché, ni con el borde lejísimos. */
	public function test_active_window_never_authorizes_cache(): void {
		$method = new ReflectionMethod( EditorialDiscountPublication::class, 'authorized_page_cache_ttl' );
		$method->setAccessible( true );

		foreach ( array( null, time() + 365 * 86400 ) as $boundary ) {
			$this->set_static_value( 'serving_capture_window', array( 'active' => true, 'boundary_at' => $boundary ) );
			self::assertNull( $method->invoke( null ) );
		}
		// Ventana ilegible: la duda también es no-store.
		foreach ( array( null, array(), array( 'active' => 'no' ), array( 'active' => false ) ) as $broken ) {
			$this->set_static_value( 'serving_capture_window', $broken );
			self::assertNull( $method->invoke( null ) );
		}
	}

	// ---------------------------------------------------------------- superficies cerradas

	/**
	 * Desde 0.1.18 la portada vuelve a ser cacheable en el origen: el mu-plugin
	 * `akibara-home-reviews.php` purga `home_url('/')` en cada mutación de
	 * visibilidad de reseñas, así que la exclusión por frescura dejó de tener
	 * causa. Este test fija el contrato nuevo: `is_front_page` YA NO cierra la
	 * página por sí sola.
	 *
	 * @testdox La portada ya no queda no-store por ser portada.
	 */
	public function test_front_page_is_no_longer_closed_by_itself(): void {
		$this->reset_request();
		$this->install_ready_bundle( array() );
		Functions\when( 'is_front_page' )->justReturn( true );

		$this->publication()->cap_page_cache_ttl();

		self::assertFalse( $this->no_store_was_marked(), 'La portada debe poder cachearse en el origen.' );
		self::assertNotNull( $this->action( 'litespeed_control_set_ttl' ), 'La portada debe recibir un TTL autorizado.' );
		Functions\when( 'is_front_page' )->justReturn( false );
	}

	/** @testdox Carrito, checkout y mi-cuenta siguen no-store y no gastan la lease. */
	public function test_user_state_surfaces_stay_no_store(): void {
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $surface ) {
			$this->reset_request();
			$this->install_ready_bundle( array() );
			Functions\when( $surface )->justReturn( true );

			$this->publication()->cap_page_cache_ttl();

			self::assertTrue( $this->no_store_was_marked(), $surface . ' debe seguir cerrado.' );
			self::assertNull( $this->action( 'litespeed_control_set_ttl' ) );
			Functions\when( $surface )->justReturn( false );
		}
	}

	/** @testdox Sesión, carrito y login cierran la página por cookie. */
	public function test_private_cookies_close_the_page(): void {
		foreach ( array( 'wordpress_logged_in_abc', 'wp_woocommerce_session_abc', 'woocommerce_cart_hash', 'woocommerce_items_in_cart', 'wp-postpass_abc', 'comment_author_abc' ) as $cookie ) {
			$this->reset_request();
			$this->install_ready_bundle( array() );
			$_COOKIE = array( $cookie => '1' );

			$this->publication()->cap_page_cache_ttl();

			self::assertTrue( $this->no_store_was_marked(), $cookie . ' debe cerrar la página.' );
		}
	}

	/** @testdox Cualquier query string cierra la página: facetas, búsqueda y cardinalidad. */
	public function test_any_query_string_closes_the_page(): void {
		foreach ( array( array( 's' => 'naruto' ), array( '_buscar' => 'naruto' ), array( '_disponibilidad' => 'instock' ), array( 'add-to-cart' => '10' ) ) as $query ) {
			$this->reset_request();
			$this->install_ready_bundle( array() );
			$_GET = $query;
			$_SERVER['QUERY_STRING'] = http_build_query( $query );

			$this->publication()->cap_page_cache_ttl();

			self::assertTrue( $this->no_store_was_marked(), (string) json_encode( $query ) );
			self::assertNull( $this->action( 'litespeed_control_set_ttl' ) );
		}
	}

	/** @testdox Un POST nunca se almacena. */
	public function test_non_idempotent_methods_close_the_page(): void {
		foreach ( array( 'POST', 'PUT', 'DELETE', '' ) as $method ) {
			$this->reset_request();
			$this->install_ready_bundle( array() );
			$_SERVER['REQUEST_METHOD'] = $method;

			$this->publication()->cap_page_cache_ttl();

			self::assertTrue( $this->no_store_was_marked(), $method . ' debe cerrar la página.' );
		}
	}

	/** @testdox Un usuario logueado nunca recibe una respuesta almacenable. */
	public function test_logged_in_user_closes_the_page(): void {
		$this->install_ready_bundle( array() );
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$this->publication()->cap_page_cache_ttl();

		self::assertTrue( $this->no_store_was_marked() );
	}

	/** @testdox El admin sigue saliendo antes de tocar nada. */
	public function test_admin_returns_untouched(): void {
		$this->install_ready_bundle( array() );
		Functions\when( 'is_admin' )->justReturn( true );

		$this->publication()->cap_page_cache_ttl();

		self::assertSame( array(), $this->actions );
	}

	// ---------------------------------------------------------------- invalidación

	/**
	 * Con el rail de Business montado: encender la campaña sin proveedor de
	 * precio deja la foto ilegible, y desde 0.1.63 esa escritura se veta.
	 *
	 * @testdox Encender la campaña purga el page-cache completo (LiteSpeed y edge).
	 */
	public function test_turning_the_campaign_on_purges_everything(): void {
		$this->assertOptionWriteDispatchesPurgeAll( 'akibara_taxonomy_discount_enabled', 'no', 'yes', true );
	}

	/** @testdox Apagar la campaña purga el page-cache completo. */
	public function test_turning_the_campaign_off_purges_everything(): void {
		$this->assertOptionWriteDispatchesPurgeAll( 'akibara_taxonomy_discount_enabled', 'yes', 'no' );
	}

	/** @testdox Reprogramar las fechas purga el page-cache completo. */
	public function test_rescheduling_the_campaign_purges_everything(): void {
		$this->assertOptionWriteDispatchesPurgeAll( 'akibara_taxonomy_discount_from', '2026-09-01', '2026-09-10' );
		$this->assertOptionWriteDispatchesPurgeAll( 'akibara_taxonomy_discount_to', '2026-09-20', '2026-09-30' );
	}

	/** @testdox Cambiar el porcentaje de un término purga el page-cache completo. */
	public function test_changing_a_term_percentage_purges_everything(): void {
		$this->install_ready_bundle( array() );
		Functions\when( 'get_term' )->justReturn( new \WP_Term( 'product_cat', 7, 0, 'shonen', 'Shonen' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array( 11, 12 ) );
		$publication = $this->publication();
		$this->actions = array();

		$publication->filter_update_term_metadata( null, 7, EditorialDiscountPublication::META_KEY, '10', '' );
		$publication->after_updated_term_meta( 0, 7, EditorialDiscountPublication::META_KEY, '10' );

		self::assertNotNull( $this->action( 'litespeed_purge_all' ), (string) json_encode( $this->actions ) );
	}

	/** @testdox Ningún hook frecuente cuelga un purge-all: sólo la fuente editorial. */
	public function test_purge_all_is_not_wired_to_frequent_events(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/EditorialDiscountPublication.php' );
		self::assertSame( 2, substr_count( $source, "do_action( 'litespeed_purge_all' )" ) );
		foreach ( array( 'save_post', 'woocommerce_update_product', 'wp_insert_post', 'transition_post_status', 'updated_postmeta' ) as $frequent ) {
			self::assertStringNotContainsString( $frequent, $source, $frequent . ' no puede colgar de este rail.' );
		}
	}

	// ---------------------------------------------------------------- helpers


	private function assertOptionWriteDispatchesPurgeAll( string $option, string $before, string $after, bool $business_rail = false ): void {
		$this->reset_request();
		$this->install_ready_bundle(
			$business_rail
				? array( $option => $before, 'akibara_business_search_feed_contract_version' => '2' )
				: array( $option => $before )
		);
		$publication = $this->publication();
		$this->actions = array();

		$publication->filter_pre_update_option( $after, $option, $before );
		$publication->after_update_option( $option, $before, $after );

		self::assertNotNull(
			$this->action( 'litespeed_purge_all' ),
			$option . ' ' . $before . ' -> ' . $after . ' no purgó: ' . (string) json_encode( $this->actions )
		);
	}

	/**
	 * Instala el proveedor efectivo de Business.
	 *
	 * `enabled=yes` sin proveedor es un modo inválido y cierra la lease por otra
	 * razón; para probar la rama de VENTANA hace falta que el resto esté sano.
	 */
	private function install_provider(): void {
		// El doble con el FQCN de producción lo declara
		// `PriceProviderAdoptionProtocolTest.php`, que PHPUnit ya cargó al armar
		// el suite. Bajo el runner integrado la clase REAL de Business ocupa el
		// nombre y estos casos se saltan solos, como allá.
		if ( ! class_exists( SearchPriceProvider::class ) ) {
			self::markTestSkipped( 'SearchPriceProvider no disponible en este runner.' );
		}
		$constructor = ( new \ReflectionClass( SearchPriceProvider::class ) )->getConstructor();
		if ( null !== $constructor && $constructor->getNumberOfRequiredParameters() > 0 ) {
			self::markTestSkipped( 'SearchPriceProvider real cargado en este proceso; este caso usa el doble.' );
		}
		$GLOBALS['wp_filter']['akb_search_effective_price'] = (object) array(
			'callbacks' => array(
				10 => array( 'provider' => array( 'function' => new SearchPriceProvider(), 'accepted_args' => 2 ) ),
			),
		);
	}

	private function reset_request(): void {
		foreach ( $this->publication_static_defaults() as $name => $value ) {
			$this->set_static_value( $name, $value );
		}
		$this->reset_rebuild_statics();
		SemanticPublication::reset_authoritative_state_for_tests();
		FacetRegistry::reset_cache();
		$this->actions = array();
		$this->hooks   = array();
		$_GET          = array();
		$_COOKIE       = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = '';
		unset( $GLOBALS['wp_filter']['akb_search_effective_price'] );
	}

	private function publication(): EditorialDiscountPublication {
		$work    = new WorkRepository( $this->db, 'wp_akb_search_work' );
		$fence   = new WriteFence( $this->db, 'akb-page-cache-gate' );
		$builder = new DocumentBuilder();
		$source  = new ProductSourceAuthority( $this->db, static fn( array $ids ): array => $ids );
		$indexer = new Indexer( $builder, $work, $fence, $source );
		// `stage_locked()` consulta el run activo del rebuild bajo LA MISMA fence;
		// sin runtime de RebuildJob la deuda no se crea y nada purgaría.
		new RebuildJob( $indexer, $work, $fence );
		return $this->vigilar_publicacion( new EditorialDiscountPublication( $work, $indexer, $fence ) );
	}

	private function no_store_was_marked(): bool {
		return null !== $this->action( 'litespeed_control_set_nocache' );
	}

	/** @return array<int, mixed>|null */
	private function action( string $hook ): ?array {
		foreach ( $this->actions as $args ) {
			if ( ( $args[0] ?? null ) === $hook ) {
				return $args;
			}
		}
		return null;
	}

	private function local_date( DateTimeZone $zone, string $modifier ): string {
		return ( new DateTimeImmutable( 'now', $zone ) )->modify( $modifier )->format( 'Y-m-d' );
	}

	private function seconds_to_next_local_midnight( DateTimeZone $zone ): int {
		$now = new DateTimeImmutable( 'now', $zone );
		$midnight = new DateTimeImmutable( $now->modify( '+1 day' )->format( 'Y-m-d' ) . ' 00:00:00', $zone );
		return $midnight->getTimestamp() - $now->getTimestamp();
	}

	/** Offset entero válido que deja la hora local en `$hour`. */
	private function gmt_offset_for_local_hour( int $hour ): string {
		$utc_hour = (int) ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->format( 'G' );
		$offset   = ( ( $hour - $utc_hour ) % 24 + 24 ) % 24;
		if ( $offset > 12 ) {
			$offset -= 24;
		}
		return (string) $offset;
	}

	private function zone_with_local_hour( int $hour ): DateTimeZone {
		$offset = (int) $this->gmt_offset_for_local_hour( $hour );
		return new DateTimeZone( sprintf( '%s%02d:00', $offset < 0 ? '-' : '+', abs( $offset ) ) );
	}

	/**
	 * Bundle publicado y sano, con las opciones editoriales que pida el caso.
	 *
	 * El state se deriva de la MISMA foto que leerá el runtime, así que un cambio
	 * en las opciones mueve fingerprint, ventana y borde a la vez.
	 *
	 * @param array<string, string> $editorial Opciones crudas a instalar.
	 */
	private function install_ready_bundle( array $editorial ): void {
		// `enabled=yes` sin proveedor efectivo es un modo inválido por contrato:
		// la lease cerraría por AUSENCIA DE PROVEEDOR y el caso no probaría la
		// ventana. Con la campaña encendida se monta el rail completo.
		if ( 'yes' === ( $editorial['akibara_taxonomy_discount_enabled'] ?? 'no' )
			|| isset( $editorial['akibara_business_search_feed_contract_version'] ) ) {
			$this->install_provider();
			$editorial['akibara_business_search_feed_contract_version'] = '2';
		}
		$generation = 'generation-a';
		$base       = array_merge( array( Install::OPTION_INDEX_GEN => $generation ), $editorial );
		$semantic   = SemanticPublication::fingerprints_from_raw_bundle( $base );
		self::assertIsArray( $semantic );

		$options_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_options_from_bundle' );
		$options_method->setAccessible( true );
		$source_method = new ReflectionMethod( EditorialDiscountPublication::class, 'source_snapshot_from_options' );
		$source_method->setAccessible( true );
		$options = $options_method->invoke( null, $base );
		$source  = $source_method->invoke( null, $options, array( 'meta' => array(), 'attributes' => array() ) );
		self::assertIsArray( $source, 'La foto fuente del caso debe ser legible.' );

		$now   = time();
		$state = array(
			'protocol'           => 1,
			'contract'           => 'akb-editorial-materialization-v1',
			'wanted_seq'         => 1,
			'wanted_fingerprint' => $source['fingerprint'],
			'window_active'      => (bool) $source['window_active'],
			'next_boundary_at'   => $source['boundary_at'],
			'source_term_ids'    => array(),
			'debt_staged'        => true,
			'active_run_id'      => '',
			'purge_pre_seq'      => 1,
			'purge_post_seq'     => 1,
			'reason'             => 'test-ready',
			'updated_at'         => $now,
		);
		$receipt = array(
			'protocol'                => 1,
			'contract'                => 'akb-editorial-materialization-v1',
			'run_id'                  => 'run-ready-1',
			'wanted_seq'              => 1,
			'fingerprint'             => $source['fingerprint'],
			'swapped_at'              => $now,
			'published_at'            => $now,
			'db_version'              => Install::DB_VERSION,
			'schema_seal'             => Install::schema_seal(),
			'coverage'                => array( 'eligible' => 3, 'docs' => 3, 'missing' => 0, 'extra' => 0, 'orphans' => 0 ),
			'generation_target'       => $generation,
			'semantic_fingerprint'    => $semantic['semantic'],
			'publication_acked'       => true,
			'purge_all_dispatched_at' => $now,
		);

		$this->db->raw_options = array_merge(
			$editorial,
			array(
				Install::OPTION_INDEX_GEN                    => $generation,
				Install::OPTION_KEY                          => Install::DB_VERSION,
				Install::OPTION_SCHEMA_SEAL                  => Install::schema_seal(),
				EditorialDiscountPublication::OPTION_STATE   => (string) json_encode( $state ),
				EditorialDiscountPublication::OPTION_RECEIPT => serialize( $receipt ),
				SemanticPublication::OPTION_FINGERPRINT      => serialize(
					array( 'protocol' => 1, 'generation' => $generation, 'fingerprint' => $semantic['semantic'], 'published_at' => $now )
				),
				RestCache::OPTION_PURGE_RECEIPT              => serialize(
					array( 'protocol' => 1, 'generation_target' => $generation, 'tag' => RestCache::TAG, 'ack_scope' => 'local_dispatch_only', 'dispatched_at' => $now )
				),
			)
		);
		$this->db->docs_count = 3;
		$this->db->reads      = 0;
	}

	private function rewrite_generation( string $generation ): void {
		$this->db->raw_options[ Install::OPTION_INDEX_GEN ] = $generation;

		$receipt = unserialize( $this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ], array( 'allowed_classes' => false ) );
		$receipt['generation_target'] = $generation;
		$this->db->raw_options[ EditorialDiscountPublication::OPTION_RECEIPT ] = serialize( $receipt );

		$semantic = unserialize( $this->db->raw_options[ SemanticPublication::OPTION_FINGERPRINT ], array( 'allowed_classes' => false ) );
		$semantic['generation'] = $generation;
		$this->db->raw_options[ SemanticPublication::OPTION_FINGERPRINT ] = serialize( $semantic );

		$purge = unserialize( $this->db->raw_options[ RestCache::OPTION_PURGE_RECEIPT ], array( 'allowed_classes' => false ) );
		$purge['generation_target'] = $generation;
		$this->db->raw_options[ RestCache::OPTION_PURGE_RECEIPT ] = serialize( $purge );
	}

	/** @return array<string, mixed> */
	private function publication_static_defaults(): array {
		return array(
			'registered'                => true,
			'runtime'                   => null,
			'state_read_failed'         => false,
			'test_serving_override'     => null,
			'test_price_match_sequence' => null,
			'term_meta_row_failed'      => false,
			'source_memo_set'           => false,
			'read_authority_available'  => true,
			'read_authority_error'      => '',
			'serving_capture_done'      => false,
			'serving_capture_token'     => null,
			'serving_capture_window'    => null,
			'serving_final_checked'     => array(),
			'serving_final_matches_by'  => array(),
			'serving_final_matches'     => false,
			'adopted_price_provider'    => null,
			'adopted_price_token'       => null,
			'adopted_price_snapshot'    => null,
			'adopted_provider_absent'   => false,
			'source_memo'               => null,
		);
	}

	/**
	 * El runtime de RebuildJob es estático y sobrevive a tearDown().
	 *
	 * Dejarlo colgado le entregaría a otro test un doble de wpdb muerto, y el
	 * fallo aparecería sólo con `--order-by=random`.
	 */
	private function reset_rebuild_statics(): void {
		foreach ( array( 'runtime' => null, 'state_read_failed' => false, 'state_row_present' => false ) as $name => $value ) {
			$property = new ReflectionProperty( RebuildJob::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	/** @return mixed */
	private function static_value( string $name ) {
		$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
		$property->setAccessible( true );
		return $property->getValue();
	}

	/** @param mixed $value */
	private function set_static_value( string $name, $value ): void {
		$property = new ReflectionProperty( EditorialDiscountPublication::class, $name );
		$property->setAccessible( true );
		$property->setValue( null, $value );
	}
}
