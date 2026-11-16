<?php
/**
 * Pruebas unitarias de DigestRunner enfocadas en desduplicación contra BackInStock.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\AccountCollection\CustomerCollection;
use Akibara\Business\Modules\SeriesFollow\CutoffPhrase;
use Akibara\Business\Modules\SeriesFollow\DigestQueue;
use Akibara\Business\Modules\SeriesFollow\DigestRunner;
use Akibara\Business\Modules\SeriesFollow\FollowerFinder;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Tests\Support\SeriesFollowFollowersWpdb;
use Akibara\Business\Tests\Support\SeriesFollowQueueWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DigestRunnerSpyEmail extends SeriesFollowEmail {
	/** @var array<int, array{user_id:int, groups:array<int, array<string, mixed>>}> */
	public array $enviados = array();
	public bool $habilitado = true;
	public bool $acepta = true;

	public function __construct() {}

	public function is_enabled(): bool {
		return $this->habilitado;
	}

	public function trigger( int $user_id, array $groups ): bool {
		$this->enviados[] = compact( 'user_id', 'groups' );
		return $this->acepta;
	}
}

final class DigestRunnerTest extends TestCase {

	private const TERM_ONE_PIECE = 42;

	private SeriesFollowQueueWpdb $queue_db;
	private SeriesFollowFollowersWpdb $orders_db;
	private DigestRunnerSpyEmail $email;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->queue_db  = new SeriesFollowQueueWpdb();
		$this->orders_db = new SeriesFollowFollowersWpdb();
		$this->email     = new DigestRunnerSpyEmail();

		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			static fn ( int $id, string $key = '', bool $single = false ): string => '_stock_status' === $key ? 'instock' : ''
		);
		Functions\when( 'get_the_title' )->justReturn( 'One Piece 100' );
		Functions\when( 'get_the_terms' )->justReturn( array() );
		Functions\when( 'has_term' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias( static fn ( int $id ): string => 'https://akibara.test/p/' . $id );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( '' );
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.test' . $path );
		Functions\when( 'wp_salt' )->justReturn( 'salt-de-prueba' );
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				if ( 'akibara_log' === $hook ) {
					var_dump( $args );
				}
			}
		);
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_meta' )->alias(
			static function ( int $user_id, string $key ) {
				if ( \Akibara\Business\Modules\SeriesFollow\FollowRegistry::META_OPTOUT === $key ) {
					return array();
				}
				return '';
			}
		);
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'get_term' )->alias(
			static fn ( int $term_id ): object => (object) array(
				'term_id' => $term_id,
				'name'    => 'Serie ' . $term_id,
			)
		);
		Functions\when( 'wc_get_product' )->alias(
			static function ( int $id ): \WC_Product {
				$product = new class() extends \WC_Product {
					public int $akb_product_id = 0;

					public function get_id(): int {
						return $this->akb_product_id;
					}

					public function get_name(): string {
						return 'Tomo ' . $this->akb_product_id;
					}

					public function get_status(): string {
						return 'publish';
					}

					public function is_in_stock(): bool {
						return true;
					}

					public function get_stock_status(): string {
						return 'instock';
					}

					public function get_image_id(): int {
						return 0;
					}

					public function get_price_html(): string {
						return '$12.990';
					}
				};
				$product->akb_product_id = $id;

				return $product;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				if ( SeriesFollow::OPTION_ENABLED === $option ) {
					return 'yes';
				}
				if ( SeriesFollow::OPTION_MAX_SERIES === $option ) {
					return 5;
				}
				if ( SeriesFollow::OPTION_CAP_DAYS === $option ) {
					return 7;
				}
				return $default_value;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function queue(): DigestQueue {
		return new DigestQueue( $this->queue_db, 'wp_akb_series_follow_queue' );
	}

	private function runner(): DigestRunner {
		$collection = new CustomerCollection( $this->orders_db );

		return new DigestRunner(
			$this->queue(),
			new FollowerFinder( $collection, $this->orders_db ),
			$collection,
			new CutoffPhrase( 3 ),
			fn (): DigestRunnerSpyEmail => $this->email,
			fn (): bool => true
		);
	}

	private function encolar( int $user_id, int $product_id, int $term_id, int $volume ): void {
		$this->queue()->queue(
			array(
				array(
					'user_id'    => $user_id,
					'product_id' => $product_id,
					'term_id'    => $term_id,
					'volume'     => $volume,
				),
			)
		);
	}

	/**
	 * @testdox Desduplicación BIS: Si el suscriptor tiene registro en BIS para ese producto, se descarta del digest y no envía correo.
	 */
	public function test_desduplicacion_bis_descarta_producto_y_no_duplica_correo(): void {
		$this->encolar( 10, 5001, self::TERM_ONE_PIECE, 100 );

		global $wpdb;
		$orig_wpdb = $wpdb ?? null;
		$recorded_queries = array();

		$bis_db = new class( $recorded_queries ) {
			public string $prefix = 'wp_';
			public array $queries = array();

			public function __construct( array &$queries ) {
				$this->queries = &$queries;
			}

			public function prepare( string $query, ...$args ): string {
				$this->queries[] = array( 'query' => $query, 'args' => $args );
				return $query;
			}

			public function get_col( string $query ): array {
				return array( 5001 );
			}
		};
		$wpdb   = $bis_db;

		try {
			$this->runner()->run();

			self::assertSame( array(), $this->email->enviados, 'No debe enviar correo de SeriesFollow cuando el producto está en BIS.' );
			$filas = array_values( $this->queue_db->table );
			self::assertNotEmpty( $filas );
			self::assertSame( DigestQueue::STATUS_DROPPED, $filas[0]['status'] );

			// Verificar que la consulta SQL contiene la cláusula defensiva requerida:
			self::assertNotEmpty( $recorded_queries );
			$sql = $recorded_queries[0]['query'];
			self::assertStringContainsString( "status = 'subscribed'", $sql );
			self::assertStringContainsString( "status = 'notified'", $sql );
			self::assertStringContainsString( 'notified_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)', $sql );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox Desduplicación BIS: Si el producto NO está en BIS, se mantiene y se envía el correo.
	 */
	public function test_producto_no_en_bis_se_mantiene_y_se_envia(): void {
		$this->encolar( 10, 5002, self::TERM_ONE_PIECE, 101 );

		global $wpdb;
		$orig_wpdb = $wpdb ?? null;

		$bis_db = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			public function get_col( string $query ): array {
				return array(); // Vacío: no está en BIS
			}
		};
		$wpdb   = $bis_db;

		try {
			$this->runner()->run();

			self::assertCount( 1, $this->email->enviados, 'Debe enviar correo de SeriesFollow si no está cubierto por BIS.' );
			$filas = array_values( $this->queue_db->table );
			self::assertNotEmpty( $filas );
			self::assertSame( DigestQueue::STATUS_SENT, $filas[0]['status'] );
		} finally {
			$wpdb = $orig_wpdb;
		}
	}

	/**
	 * @testdox Desduplicación BIS: Consulta única agrupada sin N+1 para múltiples tomos del mismo cliente.
	 */
	public function test_consulta_agrupada_sin_n_mas_uno_para_multiples_tomos(): void {
		$this->encolar( 10, 5001, self::TERM_ONE_PIECE, 100 );
		$this->encolar( 10, 5002, self::TERM_ONE_PIECE, 101 );

		global $wpdb;
		$orig_wpdb = $wpdb ?? null;
		$query_count = 0;

		$bis_db = new class( $query_count ) {
			public string $prefix = 'wp_';
			public int $count = 0;

			public function __construct( int &$count ) {
				$this->count = &$count;
			}

			public function prepare( string $query, ...$args ): string {
				$this->count++;
				return $query;
			}

			public function get_col( string $query ): array {
				return array( 5001 ); // Solo 5001 está en BIS
			}
		};
		$wpdb   = $bis_db;

		try {
			$this->runner()->run();

			// Debe haber ejecutado exactamente 1 consulta a la tabla BIS para todos los tomos del usuario
			self::assertSame( 1, $query_count, 'Debe haber exactamente 1 consulta agrupada a BIS, sin N+1.' );
			self::assertCount( 1, $this->email->enviados, 'Debe enviar correo solo con el tomo no cubierto (5002).' );

			$filas = array_values( $this->queue_db->table );
			self::assertSame( DigestQueue::STATUS_DROPPED, $filas[0]['status'] ); // 5001 dropped
			self::assertSame( DigestQueue::STATUS_SENT, $filas[1]['status'] ); // 5002 sent
		} finally {
			$wpdb = $orig_wpdb;
		}
	}
}
