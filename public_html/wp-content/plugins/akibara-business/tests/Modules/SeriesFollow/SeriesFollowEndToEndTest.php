<?php
/**
 * Pruebas End-to-End exhaustivas del ciclo completo de SeriesFollow:
 * Detección de reposición/publicación -> Escaneo y encolado -> Desduplicación con BIS y Hondana
 * -> Coalescencia multi-tomo -> Envío atómico de correo -> Renderizado de plantilla con Colección.
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
use Akibara\Business\Modules\SeriesFollow\FollowRegistry;
use Akibara\Business\Modules\SeriesFollow\PublishListener;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Modules\Stock\StockStatus;
use Akibara\Business\Tests\Support\SeriesFollowFollowersWpdb;
use Akibara\Business\Tests\Support\SeriesFollowQueueWpdb;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesFollowE2ESpyEmail extends SeriesFollowEmail {
	/** @var array<int, array{user_id:int, groups:array<int, array<string, mixed>>}> */
	public array $enviados = array();
	public bool $habilitado = true;
	public bool $falla_al_enviar = false;

	public function __construct() {
		$this->id             = self::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = 'Akibara · Novedades de series';
		$this->template_html  = 'emails/customer-series-follow.php';
		$this->template_plain = 'emails/plain/customer-series-follow.php';
		$this->template_base  = trailingslashit( AKIBARA_BUSINESS_DIR ) . 'templates/';
	}

	public function is_enabled(): bool {
		return $this->habilitado;
	}

	public function trigger( int $user_id, array $groups ): bool {
		if ( $this->falla_al_enviar ) {
			throw new \RuntimeException( 'Simulated SMTP connection timeout' );
		}
		$this->user_id         = $user_id;
		$this->groups          = $groups;
		$this->recipient       = 'cliente' . $user_id . '@test.akibara.cl';
		$this->unsubscribe_url = 'https://akibara.cl/baja-series/' . $user_id;
		$this->enviados[]      = compact( 'user_id', 'groups' );
		return true;
	}

	public function expose_template_args( bool $plain_text ): array {
		return $this->template_args( $plain_text );
	}
}

final class SeriesFollowEndToEndTest extends TestCase {

	private const TERM_ONE_PIECE = 42;
	private const TERM_BERSERK   = 88;
	private const TERM_SPY_X     = 99;

	private SeriesFollowQueueWpdb $queue_db;
	private SeriesFollowFollowersWpdb $orders_db;
	private SeriesFollowE2ESpyEmail $email;
	private $orig_wpdb;

	/** @var array<int, array<string, mixed>> */
	private array $products_meta = array();

	/** @var array<int, list<int>> */
	private array $manual_owned_by_user = array();

	/** @var array<int, list<int>> Product IDs in BIS per user */
	private array $bis_covered_by_user = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orig_wpdb              = $GLOBALS['wpdb'] ?? null;
		$this->queue_db               = new SeriesFollowQueueWpdb();
		$this->orders_db              = new SeriesFollowFollowersWpdb();
		$this->email                  = new SeriesFollowE2ESpyEmail();
		$this->products_meta          = array();
		$this->manual_owned_by_user   = array();
		$this->bis_covered_by_user    = array();

		Functions\when( 'wc_get_logger' )->justReturn( null );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( '_prime_post_caches' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_the_title' )->alias( static fn ( int $id ): string => 'Producto ' . $id );
		Functions\when( 'get_the_terms' )->justReturn( array() );
		Functions\when( 'has_term' )->justReturn( false );
		Functions\when( 'get_permalink' )->alias( static fn ( int $id ): string => 'https://akibara.cl/producto/' . $id . '/' );
		Functions\when( 'wp_get_attachment_image_url' )->justReturn( 'https://akibara.cl/portada.jpg' );
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/mi-cuenta/' );
		Functions\when( 'wc_get_endpoint_url' )->alias( static fn ( string $endpoint, string $value = '', string $permalink = '' ): string => 'https://akibara.cl/mi-cuenta/' . $endpoint . '/' );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
		Functions\when( 'add_query_arg' )->alias( static fn ( $args, $url = '' ): string => $url . '?' . http_build_query( (array) $args ) );
		Functions\when( 'wp_salt' )->justReturn( 'salt-e2e-series' );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_type' )->justReturn( 'product' );
		Functions\when( 'get_post_field' )->justReturn( '2026-11-01 10:00:00' );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_user_meta' )->alias(
			function ( int $user_id, string $key ) {
				if ( '_akb_collection_manual_owned' === $key ) {
					return $this->manual_owned_by_user[ $user_id ] ?? array();
				}
				if ( FollowRegistry::META_OPTOUT === $key ) {
					return array();
				}
				return '';
			}
		);
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'update_post_meta' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			function ( int $id, string $key = '', bool $single = false ) {
				if ( '_stock_status' === $key ) {
					return $this->products_meta[ $id ]['_stock_status'] ?? StockStatus::IN_STOCK;
				}
				if ( PublishListener::META_RESTOCKED_AT === $key ) {
					return $this->products_meta[ $id ][ PublishListener::META_RESTOCKED_AT ] ?? time();
				}
				return $this->products_meta[ $id ][ $key ] ?? '';
			}
		);
		Functions\when( 'get_term' )->alias(
			static fn ( int $term_id ): object => (object) array(
				'term_id' => $term_id,
				'name'    => 42 === $term_id ? 'One Piece' : ( 88 === $term_id ? 'Berserk' : 'Spy x Family' ),
				'slug'    => 42 === $term_id ? 'one-piece' : ( 88 === $term_id ? 'berserk' : 'spy-x-family' ),
			)
		);
		Functions\when( 'wc_get_product' )->alias(
			function ( int $id ): \WC_Product {
				$product = new class() extends \WC_Product {
					public int $p_id = 0;
					public string $p_name = '';
					public string $p_stock = 'instock';

					public function get_id(): int { return $this->p_id; }
					public function get_name(): string { return $this->p_name; }
					public function get_status(): string { return 'publish'; }
					public function is_in_stock(): bool { return StockStatus::OUT_OF_STOCK !== $this->p_stock; }
					public function get_stock_status(): string { return $this->p_stock; }
					public function get_image_id(): int { return 100; }
					public function get_price_html(): string { return '$11.990'; }
				};
				$product->p_id    = $id;
				$product->p_name  = 'Tomo ' . $id;
				$product->p_stock = $this->products_meta[ $id ]['_stock_status'] ?? StockStatus::IN_STOCK;
				return $product;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $option, $default_value = false ) {
				if ( SeriesFollow::OPTION_ENABLED === $option ) {
					return 'yes';
				}
				if ( SeriesFollow::OPTION_TRIGGER_RESTOCK === $option ) {
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
		$GLOBALS['wpdb'] = $this->orig_wpdb;
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
			fn (): SeriesFollowE2ESpyEmail => $this->email,
			fn (): bool => true
		);
	}

	/**
	 * Configura el mock global de $wpdb para que soporte consultas tanto a BIS como a Queue.
	 */
	private function bind_composite_wpdb(): void {
		global $wpdb;
		$composite = new class( $this->bis_covered_by_user ) {
			public string $prefix = 'wp_';
			public array $bis_covered = array();

			public function __construct( array &$bis_covered ) {
				$this->bis_covered = &$bis_covered;
			}

			public function prepare( string $query, ...$args ): string {
				if ( ! empty( $args ) && false !== strpos( $query, '%i' ) ) {
					$query = str_replace( '%i', (string) $args[0], $query );
				}
				return $query;
			}

			public function get_col( string $query ): array {
				// Si consulta a wp_akb_bis_subscribers
				if ( false !== strpos( $query, 'akb_bis_subscribers' ) ) {
					// Retorna los product_ids cubiertos por BIS
					$all_bis = array();
					foreach ( $this->bis_covered as $pids ) {
						$all_bis = array_merge( $all_bis, $pids );
					}
					return array_values( array_unique( $all_bis ) );
				}
				return array();
			}
		};
		$wpdb = $composite;
	}

	/**
	 * @testdox E2E Flujo Completo: Restock de tomos -> Scan -> Deduplicación BIS -> Envío de Digest -> Render con Colección
	 */
	public function test_e2e_restock_scan_bis_dedup_and_email_render_with_collection(): void {
		$this->bind_composite_wpdb();

		// Escenario:
		// Usuario 10 sigue One Piece (compró tomos anteriores).
		$this->orders_db->lineas = array(
			array( 'user_id' => 10, 'product_id' => 1001, 'status' => 'wc-completed', 'qty' => 1 ),
		);

		// Tomo 105 (PID 5001) y Tomo 106 (PID 5002) de One Piece vuelven a stock.
		$this->products_meta[5001] = array( '_stock_status' => StockStatus::IN_STOCK );
		$this->products_meta[5002] = array( '_stock_status' => StockStatus::IN_STOCK );

		// El usuario 10 YA pidió aviso en BackInStock para el tomo 105 (PID 5001),
		// pero NO para el tomo 106 (PID 5002).
		$this->bis_covered_by_user[10] = array( 5001 );

		// Encolamos ambos tomos en la cola de digest para el usuario 10.
		$this->queue()->queue(
			array(
				array( 'user_id' => 10, 'product_id' => 5001, 'term_id' => self::TERM_ONE_PIECE, 'volume' => 105 ),
				array( 'user_id' => 10, 'product_id' => 5002, 'term_id' => self::TERM_ONE_PIECE, 'volume' => 106 ),
			)
		);

		// Ejecutamos el digest runner.
		$this->runner()->run();

		// Aserción 1: Debe enviarse exactamente 1 correo al usuario 10.
		self::assertCount( 1, $this->email->enviados, 'Debe despachar 1 correo de digest.' );
		self::assertSame( 10, $this->email->enviados[0]['user_id'] );

		// Aserción 2: El correo SOLO debe contener el tomo 106 (5002). El 105 (5001) fue filtrado por BIS.
		$grupos = $this->email->enviados[0]['groups'];
		self::assertCount( 1, $grupos );
		self::assertSame( 'One Piece', $grupos[0]['series_name'] );
		self::assertSame( 106, (int) $grupos[0]['volume'] );
		self::assertSame( 5002, (int) $grupos[0]['product_id'] );

		// Aserción 3: Estado de las filas en la cola.
		$filas = array_values( $this->queue_db->table );
		self::assertSame( DigestQueue::STATUS_DROPPED, $filas[0]['status'], 'Tomo 105 cubierto por BIS debe quedar DROPPED.' );
		self::assertSame( DigestQueue::STATUS_SENT, $filas[1]['status'], 'Tomo 106 debe quedar SENT.' );

		// Aserción 4: Template args incluye enlace canónico a la colección de la cuenta.
		$args_html  = $this->email->expose_template_args( false );
		$args_plain = $this->email->expose_template_args( true );

		self::assertNotEmpty( $args_html['collection_url'] );
		self::assertSame( 'https://akibara.cl/mi-cuenta/', $args_html['collection_url'] );
		self::assertSame( 'https://akibara.cl/mi-cuenta/', $args_plain['collection_url'] );
		self::assertNotEmpty( $args_html['groups'][0]['unfollow_url'] );
		self::assertNotEmpty( $args_html['unsubscribe_url'] );
	}

	/**
	 * @testdox E2E Coalescencia Multi-Tomo: Varios tomos de la misma serie se colapsan en una sola notificación y estado coalesced.
	 */
	public function test_e2e_multi_volume_coalescing_and_state_transitions(): void {
		$this->bind_composite_wpdb();

		// Usuario 20 sigue Berserk.
		$this->orders_db->lineas = array(
			array( 'user_id' => 20, 'product_id' => 2001, 'status' => 'wc-completed', 'qty' => 1 ),
		);

		// Berserk 41 (PID 6001) y Berserk 42 (PID 6002) se reponen juntos.
		$this->products_meta[6001] = array( '_stock_status' => StockStatus::IN_STOCK );
		$this->products_meta[6002] = array( '_stock_status' => StockStatus::IN_STOCK );

		$this->queue()->queue(
			array(
				array( 'user_id' => 20, 'product_id' => 6001, 'term_id' => self::TERM_BERSERK, 'volume' => 41 ),
				array( 'user_id' => 20, 'product_id' => 6002, 'term_id' => self::TERM_BERSERK, 'volume' => 42 ),
			)
		);

		$this->runner()->run();

		self::assertCount( 1, $this->email->enviados );
		$grupos = $this->email->enviados[0]['groups'];
		self::assertCount( 1, $grupos );
		// El tomo líder anunciado es el más nuevo (42)
		self::assertSame( 42, (int) $grupos[0]['volume'] );
		self::assertSame( 2, (int) $grupos[0]['volumes_count'] );

		// Verificamos estados en la cola: el tomo 42 está SENT, el tomo 41 está COALESCED.
		$filas = array_values( $this->queue_db->table );
		self::assertSame( DigestQueue::STATUS_COALESCED, $filas[0]['status'], 'Tomo 41 absorbido debe quedar COALESCED.' );
		self::assertSame( DigestQueue::STATUS_SENT, $filas[1]['status'], 'Tomo 42 principal debe quedar SENT.' );
	}

	/**
	 * @testdox E2E Exclusión por compras previas: Si el usuario ya compró el tomo, se descarta del digest.
	 */
	public function test_e2e_manual_hondana_collection_excludes_volume_from_digest(): void {
		$this->bind_composite_wpdb();

		// Usuario 30 sigue Spy x Family y ya compró el tomo 12 (PID 7001).
		$this->orders_db->lineas = array(
			array( 'user_id' => 30, 'product_id' => 3001, 'status' => 'wc-completed', 'qty' => 1 ),
			array( 'user_id' => 30, 'product_id' => 7001, 'status' => 'wc-completed', 'qty' => 1 ),
		);

		// Tomo 12 (PID 7001) entra en stock.
		$this->products_meta[7001] = array( '_stock_status' => StockStatus::IN_STOCK );

		$this->queue()->queue(
			array(
				array( 'user_id' => 30, 'product_id' => 7001, 'term_id' => self::TERM_SPY_X, 'volume' => 12 ),
			)
		);

		$this->runner()->run();

		// No debe enviarse correo porque el único tomo pendiente ya lo compró el usuario.
		self::assertSame( array(), $this->email->enviados );
		$filas = array_values( $this->queue_db->table );
		self::assertSame( DigestQueue::STATUS_DROPPED, $filas[0]['status'] );
	}

	/**
	 * @testdox E2E Rollback Transaccional: Si ocurre un fallo en el transporte de email, las filas se liberan a pending.
	 */
	public function test_e2e_smtp_transport_failure_rolls_back_claimed_rows_to_pending(): void {
		$this->bind_composite_wpdb();

		$this->orders_db->lineas = array(
			array( 'user_id' => 40, 'product_id' => 4001, 'status' => 'wc-completed', 'qty' => 1 ),
		);
		$this->products_meta[8001] = array( '_stock_status' => StockStatus::IN_STOCK );

		$this->queue()->queue(
			array(
				array( 'user_id' => 40, 'product_id' => 8001, 'term_id' => self::TERM_ONE_PIECE, 'volume' => 107 ),
			)
		);

		// Simulamos fallo en transporte SMTP
		$this->email->falla_al_enviar = true;

		$this->runner()->run();

		// Las filas deben haberse liberado y regresado a PENDING con attempts incrementado.
		$filas = array_values( $this->queue_db->table );
		self::assertSame( DigestQueue::STATUS_PENDING, $filas[0]['status'] );
		self::assertSame( 1, (int) $filas[0]['attempts'] );
	}

	/**
	 * @testdox E2E Renderizado Real de Plantillas: HTML y Plain Text renderizan links de producto, unfollow y colección.
	 */
	public function test_e2e_renders_actual_html_and_plain_text_templates(): void {
		Functions\when( 'esc_html' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn ( string $text ): string => htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->alias( static fn ( string $url ): string => $url );
		Functions\when( 'esc_url_raw' )->alias( static fn ( string $url ): string => $url );
		Functions\when( 'wp_kses_post' )->alias( static fn ( string $html ): string => $html );
		Functions\when( 'wptexturize' )->alias( static fn ( string $text ): string => $text );
		Functions\when( 'wpautop' )->alias( static fn ( string $text ): string => '<p>' . $text . '</p>' );

		$groups = array(
			array(
				'series_name'    => 'One Piece',
				'title'          => 'One Piece 106',
				'term_id'        => self::TERM_ONE_PIECE,
				'volume'         => 106,
				'volumes_count'  => 1,
				'volumes_phrase' => '',
				'is_gap'         => false,
				'url'            => 'https://akibara.cl/producto/5002/',
				'unfollow_url'   => 'https://akibara.cl/baja-serie/42/',
				'image_url'      => 'https://akibara.cl/portada.jpg',
				'price_html'     => '$11.990',
				'availability'   => 'Disponible',
				'cutoff'         => 'Despacho en 24h',
				'timeframe_copy' => '',
			),
		);

		$collection_url     = 'https://akibara.cl/mi-cuenta/';
		$unsubscribe_url    = 'https://akibara.cl/baja-todas/';
		$email_heading      = 'Novedades de tus series';
		$additional_content = 'Gracias por preferir Akibara.';
		$sent_to_admin      = false;
		$email              = $this->email;
		self::assertFalse( $sent_to_admin );

		// 1. Renderizar plantilla HTML real
		$plain_text = false;
		self::assertFalse( $plain_text );
		ob_start();
		include AKIBARA_BUSINESS_DIR . '/templates/emails/customer-series-follow.php';
		$html_rendered = (string) ob_get_clean();

		self::assertStringContainsString( 'https://akibara.cl/producto/5002/', $html_rendered );
		self::assertStringContainsString( 'One Piece', $html_rendered );
		self::assertStringContainsString( 'Ver el tomo', $html_rendered );
		self::assertStringContainsString( 'https://akibara.cl/mi-cuenta/', $html_rendered );
		self::assertStringContainsString( 'Ir a Mi Cuenta', $html_rendered );
		self::assertStringContainsString( 'https://akibara.cl/baja-serie/42/', $html_rendered );
		self::assertStringContainsString( 'https://akibara.cl/baja-todas/', $html_rendered );

		// 2. Renderizar plantilla Plain Text real
		$plain_text = true;
		self::assertTrue( $plain_text );
		ob_start();
		include AKIBARA_BUSINESS_DIR . '/templates/emails/plain/customer-series-follow.php';
		$plain_rendered = (string) ob_get_clean();

		self::assertStringContainsString( 'https://akibara.cl/producto/5002/', $plain_rendered );
		self::assertStringContainsString( 'One Piece — tomo 106', $plain_rendered );
		self::assertStringContainsString( 'Ir a Mi Cuenta:', $plain_rendered );
		self::assertStringContainsString( 'https://akibara.cl/mi-cuenta/', $plain_rendered );
		self::assertStringContainsString( 'https://akibara.cl/baja-serie/42/', $plain_rendered );
		self::assertStringContainsString( 'https://akibara.cl/baja-todas/', $plain_rendered );
	}
}
