<?php
/**
 * Pruebas de frontera de los endpoints públicos del riel de serie.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use Akibara\Business\Modules\SeriesBundles\BuilderWidget;
use Akibara\Business\Modules\SeriesBundles\CartSeriesCollector;
use Akibara\Business\Modules\SeriesBundles\EligibilityGuard;
use Akibara\Business\Modules\SeriesBundles\LandingOffer;
use Akibara\Business\Modules\SeriesBundles\SelectionCart;
use Akibara\Business\Modules\SeriesBundles\SeriesDepth;
use Akibara\Business\Modules\SeriesBundles\Settings;
use Akibara\Business\Modules\SeriesBundles\VolumeTierResolver;
use Akibara\Business\Services\VolumeVariant;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LandingOfferJsonResponse extends \RuntimeException {
	/** @param array<string,mixed> $payload Respuesta interceptada. */
	public function __construct( public array $payload ) {
		parent::__construct( 'wp_send_json' );
	}
}

final class LandingOfferNonceRejected extends \RuntimeException {
}

final class LandingOfferAjaxProduct extends \WC_Product {
	public bool $visible = true;
	public string $status = 'publish';
	public string $name   = 'Tomo';

	public function get_name( $context = 'view' ): string {
		unset( $context );
		return $this->name;
	}

	public function get_price( $context = 'view' ): string {
		unset( $context );
		return '9990';
	}

	public function get_regular_price( $context = 'view' ): string {
		unset( $context );
		return '9990';
	}

	public function is_visible(): bool {
		return $this->visible;
	}

	public function get_status(): string {
		return $this->status;
	}

	/** Unidades con stock gestionado: las preguntas de `RejectionReason`, con la regla de WooCommerce. */
	public int $unidades = 1;

	public function is_sold_individually(): bool {
		return false;
	}

	public function is_in_stock(): bool {
		return 'outofstock' !== $this->get_stock_status();
	}

	public function managing_stock(): bool {
		return true;
	}

	/**
	 * @param int $cantidad Unidades.
	 */
	public function has_enough_stock( $cantidad ): bool {
		return $this->unidades >= $cantidad;
	}

	public function get_stock_managed_by_id(): int {
		return (int) $this->get_id();
	}
}

/** Doble mínimo del storage atómico de RateLimit. */
final class LandingOfferRateWpdb {
	public string $options = 'wp_options';
	public int $count = 1;
	public int $queries = 0;

	public function prepare( string $sql, ...$args ): string {
		unset( $args );
		return $sql;
	}

	public function query( string $sql ): int {
		unset( $sql );
		++$this->queries;
		return 1;
	}

	public function get_var( string $sql ): string {
		unset( $sql );
		return (string) $this->count;
	}
}

/**
 * Cada test corre aislado porque define las fixtures globales `WC_Cart`/`WC()`.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class LandingOfferAjaxTest extends TestCase {

	private const TERM_ID = 378;

	/** @var array<int,\WC_Product> */
	private array $products = array();

	/** @var array<int,string> Títulos por producto; sin entrada, «Serie {id}». */
	private array $titles = array();

	/** @var array<int,string> Marca `_akb_variante_tomo` por producto. */
	private array $variants = array();

	/** @var mixed */
	private $original_wpdb;

	private LandingOfferRateWpdb $rate_db;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		if ( ! defined( 'DAY_IN_SECONDS' ) ) {
			define( 'DAY_IN_SECONDS', 86400 );
		}

		// Un alta rechazada deja su aviso en la cola, como `WC_Cart::add_to_cart()`.
		if ( ! class_exists( '\WC_Cart' ) ) {
			eval(
				'namespace { class WC_Cart {
					public array $results;
					public array $quantities;
					public array $attempted = array();
					public function __construct( array $results = array(), array $quantities = array() ) { $this->results = $results; $this->quantities = $quantities; }
					public function add_to_cart( int $id, int $qty = 1 ) {
						unset( $qty ); $this->attempted[] = $id;
						$ok = $this->results[ $id ] ?? true;
						if ( ! $ok ) { $GLOBALS["akibara_landing_notices"]["error"][] = array( "notice" => "No se pudo." ); }
						return $ok;
					}
					public function get_cart_item_quantities() { return $this->quantities; }
				} }'
			);
		}
		$GLOBALS['akibara_landing_notices'] = array();
		Functions\when( 'wc_get_notices' )->alias( static fn(): array => $GLOBALS['akibara_landing_notices'] );
		Functions\when( 'wc_set_notices' )->alias(
			static function ( $avisos ): void {
				$GLOBALS['akibara_landing_notices'] = $avisos;
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v ) );
		if ( ! function_exists( 'WC' ) ) {
			eval( 'namespace { function WC() { return $GLOBALS["akibara_landing_wc"]; } }' );
		}

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->rate_db       = new LandingOfferRateWpdb();
		$GLOBALS['wpdb']     = $this->rate_db;
		$this->titles        = array();
		$this->variants      = array();
		$_POST               = array();
		$_SERVER             = array(
			'HTTP_ORIGIN' => 'https://akibara.cl',
			'REMOTE_ADDR' => '127.0.0.1',
		);

		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $meta_key = '', $single = false ) {
				unset( $single );

				return VolumeVariant::META_KEY === $meta_key
					? ( $this->variants[ (int) $post_id ] ?? '' )
					: '';
			}
		);
		Functions\when( 'wp_send_json' )->alias(
			static function ( array $payload ): void {
				throw new LandingOfferJsonResponse( $payload );
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $value ): int => abs( (int) $value ) );
		Functions\when( 'sanitize_text_field' )->alias( static fn( $value ): string => (string) $value );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias( static fn( string $path = '' ): string => 'https://akibara.cl' . $path );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_option' )->alias( static fn( string $key, $default = false ) => $default );
		Functions\when( 'wc_get_product' )->alias( fn( int $id ) => $this->products[ $id ] ?? null );
		// El recuento por tomo lee el título de cada producto del universo.
		Functions\when( 'get_the_title' )->alias( fn( int $id ): string => $this->titles[ $id ] ?? ( 'Serie ' . $id ) );
		Functions\when( 'get_the_terms' )->alias(
			function ( int $product_id, string $taxonomy ): array {
				if ( 'pa_serie' !== $taxonomy || ! isset( $this->products[ $product_id ] ) ) {
					return array();
				}
				$term           = new \WP_Term( self::TERM_ID, 'serie', 'Serie' );
				$term->taxonomy = 'pa_serie';
				return array( $term );
			}
		);
		Functions\when( 'get_term' )->alias(
			static function ( int $term_id, string $taxonomy ) {
				if ( self::TERM_ID !== $term_id || 'pa_serie' !== $taxonomy ) {
					return null;
				}
				$term           = new \WP_Term( $term_id, 'serie', 'Serie' );
				$term->taxonomy = $taxonomy;
				return $term;
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_user_meta' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://akibara.cl/mi-cuenta/' );
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, $value ) => $value );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_landing_wc'], $GLOBALS['akibara_landing_notices'] );
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		$_POST   = array();
		$_SERVER = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox handle_add_series_selection verifies its nonce before any rate-limit or cart work. */
	public function test_handle_add_series_selection_rejects_before_work_when_nonce_fails(): void {
		Functions\when( 'check_ajax_referer' )->alias(
			static function (): void {
				throw new LandingOfferNonceRejected( 'bad nonce' );
			}
		);

		$this->expectException( LandingOfferNonceRejected::class );
		try {
			$this->offer()->handle_add_series_selection();
		} finally {
			self::assertSame( 0, $this->rate_db->queries );
		}
	}

	/** @testdox handle_add_series_selection blocks a declared foreign origin before rate-limit and cart. */
	public function test_handle_add_series_selection_rejects_foreign_origin(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';

		$response = $this->capture( fn() => $this->offer()->handle_add_series_selection() );

		self::assertFalse( $response['success'] );
		self::assertSame( 0, $this->rate_db->queries );
	}

	/** @testdox handle_add_series_selection enforces the shared public rate limit. */
	public function test_handle_add_series_selection_stops_when_rate_limit_is_exceeded(): void {
		$this->rate_db->count = 11;

		$response = $this->capture( fn() => $this->offer()->handle_add_series_selection() );

		self::assertFalse( $response['success'] );
		self::assertStringContainsString( 'Demasiados intentos', (string) $response['message'] );
		self::assertGreaterThan( 0, $this->rate_db->queries );
	}

	/** @testdox handle_add_series_selection rejects an overflow selection before opening or mutating the cart. */
	public function test_handle_add_series_selection_fails_closed_above_the_id_ceiling(): void {
		$_POST = array(
			'series_id'   => self::TERM_ID,
			'product_ids' => range( 1, BuilderWidget::MAX_ADD_PER_REQUEST + 1 ),
		);

		$response = $this->capture( fn() => $this->offer()->handle_add_series_selection() );

		self::assertFalse( $response['success'] );
		self::assertStringContainsString( 'hasta 300 tomos', (string) $response['message'] );
		self::assertArrayNotHasKey( 'akibara_landing_wc', $GLOBALS );
	}

	/** @testdox handle_add_series_selection reports partial cart writes and the exact retry set. */
	public function test_handle_add_series_selection_is_explicitly_partial(): void {
		$nonce_calls = 0;
		Functions\when( 'check_ajax_referer' )->alias(
			static function ( string $action, string $field ) use ( &$nonce_calls ): bool {
				++$nonce_calls;
				self::assertSame( LandingOffer::NONCE_ADD_SERIES, $action );
				self::assertSame( 'nonce', $field );
				return true;
			}
		);
		$_POST = array(
			'series_id'  => self::TERM_ID,
			'product_ids' => array( 1, 2, 3 ),
		);
		foreach ( array( 1, 2, 3 ) as $id ) {
			$product         = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id = $id;
			$this->products[ $id ] = $product;
		}
		$cart = new \WC_Cart( array( 1 => true, 2 => false, 3 => true ) );
		$GLOBALS['akibara_landing_wc'] = (object) array( 'cart' => $cart );

		$response = $this->capture( fn() => $this->offer()->handle_add_series_selection() );

		self::assertTrue( $response['success'] );
		self::assertSame( 2, $response['added'] );
		// El cliente deja marcado el complemento de `added_ids` y `rejected_ids`
		// sobre lo que envió. El 2 no entró por un motivo que el estado no
		// explica (quedan unidades y no está en el carrito): reintentar puede
		// servir, así que no va en `rejected_ids`.
		self::assertSame( array( 1, 3 ), $response['added_ids'] );
		self::assertSame( array(), $response['rejected_ids'] );
		self::assertSame( SelectionCart::DEFAULT_ERROR, $response['message'] );
		self::assertArrayNotHasKey( 'skipped_ids', $response );
		self::assertArrayNotHasKey( 'skipped', $response );
		self::assertArrayNotHasKey( 'requested', $response );
		self::assertSame( array( 1, 2, 3 ), $cart->attempted );
		self::assertSame( 1, $nonce_calls );
	}

	/**
	 * Deuda previa (verificación del 2026-09-25): elegir otra vez tomos de una
	 * unidad que ya están en el carrito respondía «No se pudo agregar. Revisa el
	 * stock e inténtalo de nuevo.», fijo en el código, y los dejaba marcados.
	 *
	 * @testdox «Elegir tomos» con tomos que ya están en el carrito dice el motivo, los desmarca y no deja avisos para la página siguiente.
	 */
	public function test_handle_add_series_selection_explains_volumes_already_in_the_cart(): void {
		$_POST = array(
			'series_id'   => self::TERM_ID,
			'product_ids' => array( 1, 2 ),
		);
		foreach ( array( 1, 2 ) as $id ) {
			$product         = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id = $id;
			$this->products[ $id ] = $product;
		}
		$GLOBALS['akibara_landing_wc'] = (object) array( 'cart' => new \WC_Cart( array( 1 => false, 2 => false ), array( 1 => 1, 2 => 1 ) ) );

		$response = $this->capture( fn() => $this->offer()->handle_add_series_selection() );

		self::assertFalse( $response['success'] );
		self::assertSame( SelectionCart::DEFAULT_YA_EN_CARRITO, $response['message'] );
		self::assertSame( array( 1, 2 ), $response['rejected_ids'] );
		self::assertSame( array(), $GLOBALS['akibara_landing_notices'], 'Sin avisos colgando para la página siguiente.' );
	}

	/** @testdox handle_collection_state returns no private collection data to guests and accepts no user id. */
	public function test_handle_collection_state_guest_response_is_privacy_safe(): void {
		$_POST['user_id'] = 999999;

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertTrue( $response['success'] );
		self::assertFalse( $response['authenticated'] );
		self::assertArrayNotHasKey( 'owned_ids', $response );

		// SIN `login_url`. Este handler corre sobre `admin-ajax.php`, así que el
		// destino de vuelta que se resolvía aquí era la propia URL del endpoint:
		// el invitado que pulsaba «Inicia sesión» aterrizaba en
		// `/wp-admin/admin-ajax.php` en vez de en la landing. El enlace ya trae
		// el destino correcto desde el render de la página, y el JS lo prefiere.
		self::assertArrayNotHasKey( 'login_url', $response );

		$method = new \ReflectionMethod( LandingOffer::class, 'handle_collection_state' );
		$lines  = file( (string) $method->getFileName() );
		$source = implode( '', array_slice( $lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1 ) );
		self::assertStringNotContainsString( "\$_POST['user_id']", $source );
		self::assertStringContainsString( 'get_current_user_id()', $source );
		self::assertStringContainsString( 'PublicBatchRequest::origin_allowed()', $source );
	}

	/** @testdox handle_collection_state exposes only the same visible simple purchasable universe as the selector. */
	public function test_handle_collection_state_uses_the_canonical_catalog_universe(): void {
		$specs = array(
			1 => array( 'stock' => 'instock', 'visible' => true, 'type' => 'simple', 'purchasable' => true ),
			2 => array( 'stock' => 'instock', 'visible' => false, 'type' => 'simple', 'purchasable' => true ),
			3 => array( 'stock' => 'instock', 'visible' => true, 'type' => 'variable', 'purchasable' => true ),
			4 => array( 'stock' => 'instock', 'visible' => true, 'type' => 'simple', 'purchasable' => false ),
			5 => array( 'stock' => 'outofstock', 'visible' => true, 'type' => 'simple', 'purchasable' => true ),
			6 => array( 'stock' => 'onbackorder', 'visible' => true, 'type' => 'simple', 'purchasable' => true ),
			7 => array( 'stock' => 'instock', 'visible' => true, 'type' => 'simple', 'purchasable' => true ),
		);
		foreach ( $specs as $id => $spec ) {
			$product           = new LandingOfferAjaxProduct( $spec['stock'], array(), $spec['purchasable'] );
			$product->akb_id   = $id;
			$product->akb_type = $spec['type'];
			$product->visible  = $spec['visible'];
			$product->status   = 7 === $id ? 'draft' : 'publish';
			$this->products[ $id ] = $product;
		}
		$_POST['term_id'] = self::TERM_ID;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_objects_in_term' )->justReturn( array_keys( $specs ) );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => 'ok',
				'product_ids' => array( 1, 6 ),
			)
		);

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertTrue( $response['authenticated'] );
		self::assertSame( array( 1, 6 ), $response['owned_ids'] );
		self::assertSame( 2, $response['total'], 'Sólo instock y preventa visibles, simples y comprables.' );
		self::assertSame( 0, $response['missing_count'] );
		self::assertArrayNotHasKey( 2, array_flip( $response['owned_ids'] ), 'Un producto oculto no se revela.' );
	}

	/**
	 * El escenario de la edición especial dentro de su serie: el universo trae
	 * dos productos del MISMO tomo. El mensaje que sale de acá habla en tomos
	 * («De los N tomos comprables ahora, ya tienes M»), así que restar por
	 * `product_id` le encendía «te falta 1 tomo» a quien ya tiene la serie
	 * entera y le ofrecía el contenido que ya compró.
	 *
	 * @testdox handle_collection_state counts volumes, not products, when a volume has two editions.
	 */
	public function test_handle_collection_state_counts_volumes_not_products(): void {
		foreach ( array( 1, 2, 3, 13 ) as $id ) {
			$product               = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id       = $id;
			$product->akb_type     = 'simple';
			$product->name         = 'Demon Slayer ' . ( 13 === $id ? '03 (Edición Especial)' : sprintf( '%02d', $id ) );
			$this->products[ $id ] = $product;
		}
		$this->titles = array(
			1  => 'Demon Slayer 01',
			2  => 'Demon Slayer 02',
			3  => 'Demon Slayer 03',
			13 => 'Demon Slayer 03 (Edición Especial)',
		);
		$this->variants = array( 13 => VolumeVariant::KIND_EDICION_ESPECIAL );
		$_POST['term_id'] = self::TERM_ID;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_objects_in_term' )->justReturn( array( 1, 2, 3, 13 ) );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => 'ok',
				'product_ids' => array( 1, 2, 3 ),
			)
		);

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertSame( 3, $response['total'], 'Cuatro productos, tres tomos.' );
		self::assertSame( 3, $response['owned_count'], 'Los tres tomos están cubiertos.' );
		self::assertSame( 0, $response['missing_count'] );
		self::assertFalse( $response['near_goal'], 'Quien tiene la serie completa no puede leer «te falta 1 tomo».' );
		self::assertSame( array( 1, 2, 3 ), $response['owned_ids'], 'La lista de ids sigue siendo de productos: la usa el selector.' );
	}

	/**
	 * @testdox handle_collection_state includes manual owned items from account collection.
	 */
	public function test_handle_collection_state_includes_manual_owned_items(): void {
		foreach ( array( 1, 2, 3 ) as $id ) {
			$product               = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id       = $id;
			$product->akb_type     = 'simple';
			$product->name         = sprintf( 'Chainsaw Man %02d', $id );
			$this->products[ $id ] = $product;
		}
		$this->titles = array(
			1 => 'Chainsaw Man 01',
			2 => 'Chainsaw Man 02',
			3 => 'Chainsaw Man 03',
		);
		$_POST['term_id'] = self::TERM_ID;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_objects_in_term' )->justReturn( array( 1, 2, 3 ) );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => 'ok',
				'product_ids' => array( 1 ),
			)
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key, $single = false ) {
				unset( $user_id, $single );
				return \Akibara\Business\Modules\AccountCollection\CollectionResolver::USER_META_MANUAL_OWNED === $key
					? array( 2 )
					: 0;
			}
		);

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertSame( array( 1, 2 ), $response['owned_ids'] );
		self::assertSame( 2, $response['owned_count'] );
		self::assertSame( 1, $response['missing_count'] );
		self::assertCount( 1, $response['missing_items'] );
		self::assertSame( 3, $response['missing_items'][0]['id'] );
	}

	/**
	 * @testdox handle_collection_state names a missing volume once, with the normal edition.
	 */
	public function test_handle_collection_state_names_a_missing_volume_once(): void {
		foreach ( array( 1, 2, 3, 13 ) as $id ) {
			$product               = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id       = $id;
			$product->akb_type     = 'simple';
			$product->name         = 'Demon Slayer ' . ( 13 === $id ? '03 (Edición Especial)' : sprintf( '%02d', $id ) );
			$this->products[ $id ] = $product;
		}
		$this->titles = array(
			1  => 'Demon Slayer 01',
			2  => 'Demon Slayer 02',
			3  => 'Demon Slayer 03',
			13 => 'Demon Slayer 03 (Edición Especial)',
		);
		$this->variants = array( 13 => VolumeVariant::KIND_EDICION_ESPECIAL );
		$_POST['term_id'] = self::TERM_ID;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_objects_in_term' )->justReturn( array( 1, 2, 3, 13 ) );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => 'ok',
				'product_ids' => array( 1, 2 ),
			)
		);

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertSame( 3, $response['total'] );
		self::assertSame( 2, $response['owned_count'] );
		self::assertSame( 1, $response['missing_count'], 'El tomo 3 falta UNA vez, no dos.' );
		self::assertTrue( $response['near_goal'] );
	}

	/**
	 * El desempate no puede depender del orden en que llegan los ids.
	 *
	 * Acá el universo llega en el orden de `get_objects_in_term()` —object_id
	 * ascendente— y la variante tiene el id MENOR, que es lo que pasa con una
	 * portada alternativa cargada antes que su reimpresión. Con el desempate por
	 * orden, «Seleccionar el tomo que te falta» metía al carrito la variante.
	 *
	 * @testdox handle_collection_state offers the normal volume even when the variant has the lower ID.
	 */
	public function test_handle_collection_state_offers_the_normal_volume_not_the_variant(): void {
		foreach ( array( 1, 2, 5, 25 ) as $id ) {
			$product               = new LandingOfferAjaxProduct( 'instock', array(), true );
			$product->akb_id       = $id;
			$product->akb_type     = 'simple';
			$product->name         = 5 === $id ? 'One Piece 03 (Edición Especial)' : 'One Piece ' . sprintf( '%02d', 25 === $id ? 3 : $id );
			$this->products[ $id ] = $product;
		}
		$this->titles = array(
			1  => 'One Piece 01',
			2  => 'One Piece 02',
			5  => 'One Piece 03 (Edición Especial)',
			25 => 'One Piece 03',
		);
		$this->variants   = array( 5 => VolumeVariant::KIND_PORTADA_ALTERNATIVA );
		$_POST['term_id'] = self::TERM_ID;
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		Functions\when( 'get_objects_in_term' )->justReturn( array( 1, 2, 5, 25 ) );
		Functions\when( 'get_transient' )->justReturn(
			array(
				'version'     => 3,
				'status'      => 'ok',
				'product_ids' => array( 1, 2 ),
			)
		);

		$response = $this->capture( fn() => $this->offer()->handle_collection_state() );

		self::assertSame( 3, $response['total'], 'Cuatro productos, tres tomos.' );
		self::assertSame( 1, $response['missing_count'] );
		self::assertSame(
			array( 25 ),
			array_column( $response['missing_items'], 'id' ),
			'Se ofrece el tomo normal, aunque la variante tenga el id menor.'
		);
	}

	/** @testdox Both handlers are registered for customers and guests without an admin capability gate. */
	public function test_public_handlers_have_logged_and_guest_routes(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( string $hook ) use ( &$hooks ): void {
				$hooks[] = $hook;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		$this->offer()->register();

		self::assertContains( 'wp_ajax_' . LandingOffer::ACTION_ADD_SERIES, $hooks );
		self::assertContains( 'wp_ajax_nopriv_' . LandingOffer::ACTION_ADD_SERIES, $hooks );
		self::assertContains( 'wp_ajax_' . LandingOffer::ACTION_COLLECTION_STATE, $hooks );
		self::assertContains( 'wp_ajax_nopriv_' . LandingOffer::ACTION_COLLECTION_STATE, $hooks );
	}

	/** @return array<string,mixed> */
	private function capture( callable $callback ): array {
		try {
			$callback();
			self::fail( 'El handler no llamó wp_send_json().' );
		} catch ( LandingOfferJsonResponse $response ) {
			return $response->payload;
		}
	}

	private function offer(): LandingOffer {
		$settings  = new Settings();
		$guard     = new EligibilityGuard( $settings );
		$collector = new CartSeriesCollector( $settings, $guard );

		return new LandingOffer(
			$settings,
			$guard,
			new VolumeTierResolver(),
			$collector,
			new SeriesDepth( $settings ),
			static fn(): array => array( 'tomos' => 1, 'instock' => 1, 'preventa' => 0, 'agotados' => 0 )
		);
	}
}
