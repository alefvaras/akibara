<?php
/** @package Akibara\Rewards\Tests */
declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Reviews;

use Akibara\Rewards\Reviews\Carousel;
use Akibara\Rewards\Reviews\HomeCache;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

final class HomeCacheTest extends RewardsTestCase {

	private array $options = array();
	private array $requests = array();
	private array $scheduled = array();
	private array $actions = array();
	private $response;
	private string $environment = 'production';

	protected function setUp(): void {
		parent::setUp();
		$this->reset_queue();
		$this->options = array(
			'home' => 'https://akibara.cl',
			'litespeed.conf.cdn-cloudflare' => 1,
			'litespeed.conf.cdn-cloudflare_zone' => str_repeat( 'a', 32 ),
			'litespeed.conf.cdn-cloudflare_key' => 'cfat_dummy_scoped_token',
			'litespeed.conf.cdn-cloudflare_email' => 'owner@example.invalid',
		);
		$this->response = array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' );
		Functions\when( 'get_option' )->alias( fn( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'wp_get_environment_type' )->alias( fn() => $this->environment );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $value ) => json_encode( $value ) );
		Functions\when( 'wp_remote_post' )->alias( function( string $url, array $args ) { $this->requests[] = array( 'url' => $url, 'args' => $args ); return $this->response; } );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( array $response ) => $response['body'] ?? '' );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( array $response ) => (int) ( $response['response']['code'] ?? 0 ) );
		Functions\when( 'add_action' )->alias( function( string $hook, $callback, int $priority = 10, int $accepted = 1 ) { $this->actions[] = array( $hook, $callback, $priority, $accepted ); } );
		Functions\when( 'as_schedule_single_action' )->alias( function( int $time, string $hook, array $args, string $group, bool $unique ) { $this->scheduled[] = array( $time, $hook, $args, $group, $unique ); return 1; } );
	}

	protected function tearDown(): void {
		$this->reset_queue();
		parent::tearDown();
	}

	private function reset_queue(): void {
		$property = new \ReflectionProperty( HomeCache::class, 'queued' );
		$property->setAccessible( true );
		$property->setValue( null, false );
	}

	/** @testdox El registro conecta la cabecera y el hook de reintento con un único argumento. */
	public function test_registration_connects_tag_header_and_bounded_retry_handler(): void {
		$filters = array();
		Functions\when( 'add_filter' )->alias( static function( string $hook, $callback ) use ( &$filters ) { $filters[] = array( $hook, $callback ); } );
		HomeCache::register();
		self::assertSame( array( array( 'wp_headers', array( HomeCache::class, 'tag_headers' ) ) ), $filters );
		self::assertSame( array( array( HomeCache::HOOK, array( HomeCache::class, 'purge' ), 10, 1 ) ), $this->actions );
	}

	/** @testdox El tag se añade únicamente a la portada, preservando otras cabeceras y tags. */
	public function test_cache_tag_is_scoped_to_front_page_only(): void {
		$headers = array( 'Cache-Control' => 'public,max-age=600', 'Cache-Tag' => 'existing_tag' );
		self::assertSame( $headers, HomeCache::tag_headers( $headers ), 'Fichas, tienda y otras rutas no reciben el tag.' );
		Functions\when( 'is_front_page' )->justReturn( true );
		$tagged = HomeCache::tag_headers( $headers );
		self::assertSame( 'existing_tag,' . HomeCache::TAG, $tagged['Cache-Tag'] );
		self::assertSame( $headers['Cache-Control'], $tagged['Cache-Control'] );
		self::assertSame( array( 'Cache-Tag' => HomeCache::TAG ), HomeCache::tag_headers( array() ) );
	}

	/** @testdox La purga se difiere hasta shutdown y varios cambios de una petición generan una sola llamada. */
	public function test_purge_is_queued_once_after_origin_invalidation(): void {
		HomeCache::queue_purge();
		HomeCache::queue_purge();
		HomeCache::queue_purge();
		self::assertSame( array( array( 'shutdown', array( HomeCache::class, 'purge' ), 30, 1 ) ), $this->actions );
		self::assertSame( array(), $this->requests, 'No se invalida el borde antes de terminar el cambio.' );
	}

	/** @testdox La API recibe sólo el tag del inicio, nunca URLs, prefijos, hostnames ni purge_everything. */
	public function test_payload_and_endpoint_are_narrow_and_reuse_bearer_auth(): void {
		HomeCache::purge();
		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( 'https://api.cloudflare.com/client/v4/zones/' . str_repeat( 'a', 32 ) . '/purge_cache', $request['url'] );
		self::assertSame( array( 'tags' => array( HomeCache::TAG ) ), json_decode( $request['args']['body'], true ) );
		self::assertSame( array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer cfat_dummy_scoped_token' ), $request['args']['headers'] );
		self::assertSame( 8, $request['args']['timeout'] );
		self::assertSame( array(), $this->scheduled );
		self::assertSame( array(), $this->logger->entries );
	}

	/** @testdox Los formatos de Global API Key se autentican como LiteSpeed, sin mezclar Bearer. */
	public function test_existing_global_key_formats_reuse_litespeed_auth(): void {
		foreach ( array( 'cfk_dummy_global_key', str_repeat( 'b', 40 ) ) as $key ) {
			$this->options['litespeed.conf.cdn-cloudflare_key'] = $key;
			HomeCache::purge();
			$headers = end( $this->requests )['args']['headers'];
			self::assertSame( $key, $headers['X-Auth-Key'] );
			self::assertSame( 'owner@example.invalid', $headers['X-Auth-Email'] );
			self::assertArrayNotHasKey( 'Authorization', $headers );
		}
	}

	/** @testdox Un espejo con credenciales productivas copiadas no llama ni agenda una purga real. */
	public function test_local_environment_with_copied_production_credentials_is_safe(): void {
		foreach ( array( 'local', 'development', 'staging' ) as $environment ) {
			$this->environment = $environment;
			HomeCache::queue_purge();
			HomeCache::purge();
		}
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->actions );
		self::assertSame( array(), $this->scheduled );
	}

	/** @testdox Otro host se descarta aunque WP se identifique erróneamente como producción. */
	public function test_non_production_home_hosts_never_call_cloudflare(): void {
		foreach ( array( 'http://localhost:8080', 'https://akibara.test', 'https://akibara.cl.attacker.invalid', 'https://attacker.invalid/akibara.cl', '', 'https://akibara.cl@attacker.invalid' ) as $home ) {
			$this->options['home'] = $home;
			HomeCache::queue_purge(); HomeCache::purge();
		}
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->actions );
	}

	/** @testdox Configuración apagada, zona inválida o clave ausente impiden cualquier petición o reintento. */
	public function test_disabled_or_invalid_credentials_fail_closed(): void {
		foreach ( array(
			array( 'litespeed.conf.cdn-cloudflare' => false ),
			array( 'litespeed.conf.cdn-cloudflare_zone' => '' ),
			array( 'litespeed.conf.cdn-cloudflare_zone' => '../other-zone' ),
			array( 'litespeed.conf.cdn-cloudflare_key' => '' ),
		) as $override ) {
			$original = $this->options;
			$this->options = array_replace( $this->options, $override );
			HomeCache::queue_purge(); HomeCache::purge();
			$this->options = $original;
		}
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->scheduled );
		self::assertSame( array(), $this->actions );
	}

	/** @testdox Fallos HTTP, JSON o red sólo agendan los intentos 1 y 2 y nunca registran la respuesta externa. */
	public function test_failures_retry_at_most_twice_without_sensitive_logs_or_arguments(): void {
		$start = time();
		foreach ( array(
			array( 'response' => array( 'code' => 403 ), 'body' => '{"success":false,"errors":[{"message":"sensitive_external_body"}]}' ),
			array( 'response' => array( 'code' => 200 ), 'body' => 'not json sensitive_external_body' ),
			new \WP_Error( 'network', 'sensitive_external_body' ),
		) as $response ) {
			$this->scheduled = array(); $this->requests = array();
			$this->response = $response;
			HomeCache::purge( 0 ); HomeCache::purge( 1 ); HomeCache::purge( 2 );
			self::assertCount( 3, $this->requests, 'Inicial más dos reintentos.' );
			self::assertCount( 2, $this->scheduled, 'El último intento no se reprograma.' );
			foreach ( $this->scheduled as $index => $job ) {
				self::assertGreaterThanOrEqual( $start + 75, $job[0] );
				self::assertSame( HomeCache::HOOK, $job[1] );
				self::assertSame( array( $index + 1 ), $job[2], 'La cola sólo recibe el contador, ninguna credencial o URL.' );
				self::assertSame( 'akibara-rewards', $job[3] );
				self::assertTrue( $job[4], 'Los reintentos son únicos para el mismo argumento.' );
			}
		}
		$logs = json_encode( $this->logger->entries );
		self::assertStringNotContainsString( 'sensitive_external_body', $logs );
		self::assertStringNotContainsString( 'cfat_dummy', $logs );
		self::assertStringNotContainsString( 'owner@example.invalid', $logs );
		self::assertStringNotContainsString( str_repeat( 'a', 32 ), $logs );
	}

	/** @testdox Una respuesta 200 con success false sigue siendo fallo y no vacía una caché más amplia. */
	public function test_unsuccessful_api_envelope_is_not_treated_as_success(): void {
		$this->response = array( 'response' => array( 'code' => 200 ), 'body' => '{"success":false}' );
		HomeCache::purge( 2 );
		self::assertCount( 1, $this->logger->entries );
		self::assertSame( array(), $this->scheduled );
		self::assertSame( array( 'tags' => array( HomeCache::TAG ) ), json_decode( $this->requests[0]['args']['body'], true ) );
	}

	/** @testdox Carousel invalida el origen antes de encolar la única purga del borde. */
	public function test_carousel_flush_keeps_origin_purge_before_deferred_edge_purge(): void {
		$order = array();
		Functions\when( 'delete_transient' )->alias( static function( string $key ) use ( &$order ) { $order[] = array( 'transient', $key ); return true; } );
		Functions\when( 'add_action' )->alias( static function( string $hook, $callback, int $priority = 10 ) use ( &$order ) { $order[] = array( 'edge_queue', $hook, $callback, $priority ); } );
		( new Carousel() )->flush();
		self::assertSame( array( 'transient', Carousel::TRANSIENT ), $order[0] );
		self::assertSame( 'edge_queue', $order[1][0] );
		self::assertSame( array(), $this->requests );
	}
}
