<?php
/**
 * Tests for frontend/CDN compatibility.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FrontendCompatibility;

use Akibara\Business\Modules\FrontendCompatibility\FrontendCompatibility;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );
defined( 'AKIBARA_BUSINESS_URL' ) || define( 'AKIBARA_BUSINESS_URL', 'https://example.test/wp-content/plugins/akibara-business/' );
defined( 'AKIBARA_BUSINESS_VERSION' ) || define( 'AKIBARA_BUSINESS_VERSION', 'test-version' );

final class FrontendCompatibilityTest extends TestCase {
	/** @var array<string,mixed> Original request restored after each case. */
	private array $original_get;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_get = $_GET;
		unset( $_GET['key'] );

		// Los condicionales de WooCommerce que consulta el encolado de Clarity
		// van aquí y no en cada prueba: en cuanto UNA los define,
		// `function_exists()` da true para el resto de la clase y el código
		// llamaría a un doble que ya no existe. Cada prueba pisa el que necesita.
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => '' ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_query_var' )->justReturn( 0 );
		Functions\when( 'absint' )->alias( static fn ( $value ) => abs( (int) $value ) );
		Functions\when( 'wc_clean' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'test-only-clarity-secret' );
		Functions\when( 'wc_get_order' )->justReturn( false );
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $_SERVER['HTTP_HOST'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_merges_checkout_exclusions_without_duplicates(): void {
		$result = ( new FrontendCompatibility() )->add_litespeed_checkout_script_exclusions(
			array(
				'custom-checkout.js',
				'sdk.mercadopago.com/js/v2',
			)
		);

		self::assertContains( 'custom-checkout.js', $result );
		self::assertContains( 'woocommerce-mercadopago/assets/js/checkouts/', $result );
		self::assertSame( 1, array_count_values( $result )['sdk.mercadopago.com/js/v2'] );
	}

	public function test_accepts_newline_separated_litespeed_exclusions(): void {
		$result = ( new FrontendCompatibility() )->add_litespeed_checkout_script_exclusions(
			"first.js\nsecond.js"
		);

		self::assertContains( 'first.js', $result );
		self::assertContains( 'second.js', $result );
		self::assertContains( 'mp-checkout', $result );
	}

	public function test_adds_cloudinary_skip_lazy_bypass_once(): void {
		$result = ( new FrontendCompatibility() )->add_cloudinary_lazy_bypass_classes(
			array( 'cld-bypass-lazy', 'skip-lazy' )
		);

		self::assertSame( array( 'cld-bypass-lazy', 'skip-lazy' ), $result );
	}

	/**
	 * @testdox Raises the debug detector stack depth before WooCommerce installs its proxy.
	 */
	public function test_increases_dependency_detection_stack_depth(): void {
		Functions\expect( 'wp_print_inline_script_tag' )
			->once()
			->with(
				'if(typeof Error.stackTraceLimit==="number"&&Error.stackTraceLimit<50){Error.stackTraceLimit=50;}',
				array( 'id' => 'akibara-wc-dependency-detection-stack' )
			);

		( new FrontendCompatibility() )->increase_dependency_detection_stack_depth();
		self::addToAssertionCount( 1 );
	}

	public function test_disables_google_tracking_on_nonproduction_clone_even_if_environment_type_is_production(): void {
		$_SERVER['HTTP_HOST'] = 'clone.akibara.invalid';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$module = new FrontendCompatibility();
		$rank   = $module->filter_rank_math_analytics_options(
			array(
				'install_code' => true,
				'property_id'  => 'G-TEST',
			)
		);
		$woo    = $module->filter_woocommerce_analytics_options(
			array( 'ga_id' => 'G-TEST' )
		);

		self::assertFalse( $rank['install_code'] );
		self::assertSame( 'G-TEST', $rank['property_id'] );
		self::assertSame( '', $woo['ga_id'] );
		self::assertTrue( $module->disable_google_ads_tracking( false ) );
	}

	public function test_keeps_google_tracking_configuration_on_canonical_production_host(): void {
		$_SERVER['HTTP_HOST'] = 'www.akibara.cl';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		$module = new FrontendCompatibility();
		$rank   = array( 'install_code' => true );
		$woo    = array( 'ga_id' => 'G-TEST' );

		self::assertSame( $rank, $module->filter_rank_math_analytics_options( $rank ) );
		self::assertSame( $woo, $module->filter_woocommerce_analytics_options( $woo ) );
		self::assertFalse( $module->disable_google_ads_tracking( false ) );
		self::assertTrue( $module->disable_google_ads_tracking( true ) );
	}

	/**
	 * @testdox Joinchat compatibility assets stay off unrelated pages.
	 */
	public function test_joinchat_trust_clearance_assets_are_home_only(): void {
		$enqueue_calls = 0;

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->alias(
			static function () use ( &$enqueue_calls ): void {
				++$enqueue_calls;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function () use ( &$enqueue_calls ): void {
				++$enqueue_calls;
			}
		);

		( new FrontendCompatibility() )->enqueue_joinchat_trust_clearance_assets();

		self::assertSame( 0, $enqueue_calls );
	}

	/**
	 * @testdox Joinchat compatibility assets are cache-busted and enqueued on home.
	 */
	public function test_joinchat_trust_clearance_assets_load_on_home(): void {
		$style_args  = array();
		$script_args = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( ...$args ) use ( &$style_args ): void {
				$style_args = $args;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$script_args ): void {
				$script_args = $args;
			}
		);

		( new FrontendCompatibility() )->enqueue_joinchat_trust_clearance_assets();

		self::assertSame(
			array(
				'akibara-joinchat-trust-clearance',
				AKIBARA_BUSINESS_URL . 'assets/css/joinchat-trust-clearance.css',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/css/joinchat-trust-clearance.css' ),
			),
			$style_args
		);
		self::assertSame(
			array(
				'akibara-joinchat-trust-clearance',
				AKIBARA_BUSINESS_URL . 'assets/js/joinchat-trust-clearance.js',
				array(),
				AKIBARA_BUSINESS_VERSION . '.' . filemtime( AKIBARA_BUSINESS_DIR . 'assets/js/joinchat-trust-clearance.js' ),
				true,
			),
			$script_args
		);
	}

	/**
	 * @testdox The Joinchat guard measures physical overlap and preserves active chat access.
	 */
	public function test_joinchat_trust_clearance_asset_contract(): void {
		$script = (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'assets/js/joinchat-trust-clearance.js'
		);
		$style  = (string) file_get_contents(
			AKIBARA_BUSINESS_DIR . 'assets/css/joinchat-trust-clearance.css'
		);

		self::assertStringContainsString( 'new window.IntersectionObserver', $script );
		self::assertStringContainsString( 'getBoundingClientRect()', $script );
		self::assertStringContainsString( 'rootMargin: rootMargin', $script );
		self::assertStringContainsString( 'new window.MutationObserver', $script );
		self::assertStringContainsString( "attributeFilter: [ 'hidden', 'class' ]", $script );
		self::assertStringContainsString( 'nextJoinchatState === lastJoinchatState', $script );
		self::assertStringContainsString( "classList.contains( 'joinchat--show' )", $script );
		self::assertStringContainsString( 'new window.ResizeObserver', $script );
		self::assertStringContainsString( "'joinchat:start'", $script );
		self::assertStringContainsString( "'joinchat:show'", $script );
		self::assertStringContainsString( 'MAX_GEOMETRY_RETRIES = 8', $script );
		self::assertStringContainsString( 'geometryRetryCount < MAX_GEOMETRY_RETRIES', $script );
		self::assertStringContainsString( 'akb-joinchat--trust-overlap', $script );

		self::assertStringContainsString( ':not(.joinchat--chatbox):not(:focus-within)', $style );
		self::assertStringContainsString( 'visibility: hidden !important', $style );
		self::assertStringContainsString( 'pointer-events: none !important', $style );
	}

	/**
	 * Encola el guion de Clarity como lo haría producción y devuelve lo que
	 * recibió WordPress: los argumentos de `wp_enqueue_script()` y el JS inline.
	 *
	 * @return array{script: array<int,mixed>, inline: string}
	 */
	private function enqueue_clarity_on_production(): array {
		$captured = array(
			'script' => array(),
			'inline' => '',
		);

		$_SERVER['HTTP_HOST'] = 'akibara.cl';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_option' )->alias(
			static fn ( string $name, $fallback = false ) => 'clarity_project_id' === $name ? 'abc123' : $fallback
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $value, int $flags = 0 ) => json_encode( $value, $flags )
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			static function ( ...$args ) use ( &$captured ): void {
				$captured['script'] = $args;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			static function ( string $handle, string $js ) use ( &$captured ): bool {
				$captured['inline'] = $js;
				return true;
			}
		);

		( new FrontendCompatibility() )->enqueue_clarity_commerce_events();

		return $captured;
	}

	/**
	 * S-b9250562 (2026-09-19). «Cupón aplicado» y «error de pago» se leen de los
	 * stores de WooCommerce Blocks: el guion tiene que correr DESPUÉS de
	 * `wp-data` allí donde esos bloques viven, y declararlo es la única forma de
	 * que el orden no dependa de quién encoló primero.
	 *
	 * @testdox En el carrito y en el checkout de bloques el guion de Clarity declara wp-data como dependencia.
	 */
	public function test_clarity_events_depend_on_wp_data_where_the_blocks_live(): void {
		Functions\when( 'is_cart' )->justReturn( true );
		$cart = $this->enqueue_clarity_on_production();

		self::assertSame( 'akibara-clarity-commerce-events', $cart['script'][0] );
		self::assertSame( array( 'wp-data' ), $cart['script'][2] );
		self::assertTrue( $cart['script'][4], 'El guion va al pie: lee `document.body`.' );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		$checkout = $this->enqueue_clarity_on_production();

		self::assertSame( array( 'wp-data' ), $checkout['script'][2] );
		self::assertSame( 'window.akibaraClarityCommerce={"initialEvent":"begin_checkout"};', $checkout['inline'] );
	}

	/**
	 * `wp-data` arrastra el runtime de React. En la ficha y en la portada no hay
	 * ningún store de carrito que mirar, y en «gracias por tu compra» —la misma
	 * página de checkout servida por el endpoint `order-received`— el bloque no
	 * se pinta: cargarlo ahí sería pagar React para no medir nada.
	 *
	 * @testdox Fuera del carrito y del checkout de bloques el guion de Clarity no arrastra wp-data.
	 */
	public function test_clarity_events_do_not_pull_wp_data_elsewhere(): void {
		Functions\when( 'is_product' )->justReturn( true );
		$product = $this->enqueue_clarity_on_production();

		self::assertSame( array(), $product['script'][2] );
		self::assertSame( 'window.akibaraClarityCommerce={"initialEvent":"view_product"};', $product['inline'] );

		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'is_order_received_page' )->justReturn( true );
		Functions\when( 'is_wc_endpoint_url' )->justReturn( true );
		$thanks = $this->enqueue_clarity_on_production();

		self::assertSame( array(), $thanks['script'][2] );
		self::assertSame( 'window.akibaraClarityCommerce={"initialEvent":""};', $thanks['inline'] );
	}

	/** @return array<string,mixed> The public browser configuration. */
	private function clarity_config(): array {
		$result = $this->enqueue_clarity_on_production();
		return json_decode( substr( $result['inline'], strlen( 'window.akibaraClarityCommerce=' ), -1 ), true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * In-memory order only: the tests never create orders, payment hooks or mail.
	 *
	 * @param array<string,mixed> $values Test order properties.
	 * @return \WC_Order
	 */
	private function receipt_order( array $values = array() ): \WC_Order {
		$order = \Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_order_key' )->andReturn( 'wc_order_test_secret' );
		$order->shouldReceive( 'get_customer_id' )->andReturn( $values['customer_id'] ?? 0 );
		$order->shouldReceive( 'get_billing_email' )->andReturn( $values['email'] ?? 'customer@example.test' );
		$order->shouldReceive( 'get_created_via' )->andReturn( $values['created_via'] ?? 'checkout' );
		$order->shouldReceive( 'get_billing_first_name' )->andReturn( $values['first_name'] ?? 'Customer' );
		$order->shouldReceive( 'is_paid' )->andReturn( $values['paid'] ?? false );
		$order->shouldReceive( 'get_status' )->andReturn( $values['status'] ?? ( ( $values['paid'] ?? false ) ? 'processing' : 'pending' ) );
		$order->shouldReceive( 'get_date_paid' )->andReturn( $values['date_paid'] ?? null );
		$metadata = array();
		foreach ( $values['meta'] ?? array() as $key => $value ) {
			$meta = \Mockery::mock();
			$meta->shouldReceive( 'get_data' )->andReturn( array( 'key' => $key, 'value' => $value ) );
			$metadata[] = $meta;
		}
		$order->shouldReceive( 'get_meta_data' )->andReturn( $metadata );
		Functions\when( 'wc_get_order' )->justReturn( $order );
		Functions\when( 'get_query_var' )->justReturn( 456 );
		Functions\when( 'is_order_received_page' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( true );
		$_GET['key'] = 'wc_order_test_secret';
		return $order;
	}

	/** @dataProvider payment_evidence_cases */
	public function test_receipt_requires_paid_status_and_payment_date( bool $paid, bool $has_date, array $events ): void {
		$this->receipt_order( array( 'paid' => $paid, 'date_paid' => $has_date ? new \DateTimeImmutable() : null ) );
		$config = $this->clarity_config();
		self::assertSame( $events, $config['orderEvents'] );
		self::assertSame( '', $config['initialEvent'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $config['orderToken'] );
		self::assertStringNotContainsString( 'wc_order_test_secret', json_encode( $config ) );
		self::assertStringNotContainsString( 'customer@example.test', json_encode( $config ) );
	}

	public function payment_evidence_cases(): array {
		return array(
			'pending without payment' => array( false, false, array( 'order_created' ) ),
			'cancelled or refunded with old payment date' => array( false, true, array( 'order_created' ) ),
			'processing without payment evidence' => array( true, false, array( 'order_created' ) ),
			'Woo paid status including registered custom status' => array( true, true, array( 'order_created', 'purchase' ) ),
		);
	}

	/** @dataProvider akibara_payment_states */
	public function test_akibara_paid_vocabulary_and_woo_custom_states_require_a_date( string $status, bool $woo_paid, bool $has_date, bool $purchase ): void {
		require_once dirname( AKIBARA_BUSINESS_DIR ) . '/akibara-rewards/src/Support/OrderStates.php';
		$this->receipt_order( array( 'status' => $status, 'paid' => $woo_paid, 'date_paid' => $has_date ? new \DateTimeImmutable() : null ) );
		self::assertSame( $purchase ? array( 'order_created', 'purchase' ) : array( 'order_created' ), $this->clarity_config()['orderEvents'] );
	}

	public function akibara_payment_states(): array {
		return array(
			'shipping-progress paid' => array( 'shipping-progress', false, true, true ),
			'shipping-progress no date' => array( 'shipping-progress', false, false, false ),
			'cancelled old date' => array( 'cancelled', false, true, false ),
			'refunded old date' => array( 'refunded', false, true, false ),
			'failed old date' => array( 'failed', false, true, false ),
			'pending old date' => array( 'pending', false, true, false ),
			'custom Woo paid status' => array( 'custom-paid', true, true, true ),
			'custom Woo paid status no date' => array( 'custom-paid', true, false, false ),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_rewards_the_woo_paid_contract_remains_available(): void {
		self::assertFalse( class_exists( \Akibara\Rewards\Support\OrderStates::class ) );
		$this->receipt_order( array( 'status' => 'shipping-progress', 'date_paid' => new \DateTimeImmutable() ) );
		self::assertSame( array( 'order_created' ), $this->clarity_config()['orderEvents'] );
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable() ) );
		self::assertSame( array( 'order_created', 'purchase' ), $this->clarity_config()['orderEvents'] );
	}

	/** @dataProvider invalid_receipt_keys */
	public function test_missing_or_invalid_keys_never_reveal_order_signals( $key ): void {
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable(), 'email' => 'ale.fvara@gmail.com' ) );
		$_GET['key'] = $key;
		self::assertSame( array( 'initialEvent' => '' ), $this->clarity_config() );
	}

	public function invalid_receipt_keys(): array {
		return array( array( null ), array( '' ), array( 'wrong-key' ), array( array( 'wc_order_test_secret' ) ) );
	}

	public function test_unknown_order_and_zero_endpoint_id_never_emit_receipt_events(): void {
		$this->receipt_order();
		Functions\when( 'wc_get_order' )->justReturn( false );
		self::assertSame( array( 'initialEvent' => '' ), $this->clarity_config() );
		$this->receipt_order();
		Functions\when( 'get_query_var' )->justReturn( 0 );
		self::assertSame( array( 'initialEvent' => '' ), $this->clarity_config() );
	}

	public function test_registered_receipt_requires_its_customer_session(): void {
		$this->receipt_order( array( 'customer_id' => 42 ) );
		self::assertSame( array( 'initialEvent' => '' ), $this->clarity_config() );
		Functions\when( 'get_current_user_id' )->justReturn( 41 );
		self::assertSame( array( 'initialEvent' => '' ), $this->clarity_config() );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		self::assertSame( array( 'order_created' ), $this->clarity_config()['orderEvents'] );
	}

	/** @dataProvider exact_qa_emails */
	public function test_exact_qa_emails_suppress_order_and_account_events( string $email ): void {
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable(), 'email' => $email ) );
		self::assertSame( array( 'initialEvent' => '', 'internalTraffic' => 'qa' ), $this->clarity_config() );
		Functions\when( 'is_order_received_page' )->justReturn( false );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => $email ) );
		self::assertSame( array( 'initialEvent' => '', 'internalTraffic' => 'qa' ), $this->clarity_config() );
	}

	public function exact_qa_emails(): array {
		return array( array( 'ale.fvara@gmail.com' ), array( 'alejandro@mail.com' ), array( ' ALE.FVARA@GMAIL.COM ' ) );
	}

	public function test_similar_owner_email_and_qa_first_name_do_not_imply_a_test_order(): void {
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable(), 'email' => 'ale.fvaras@gmail.com', 'first_name' => 'QA' ) );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'ale.fvaras@gmail.com' ) );
		self::assertSame( array( 'order_created', 'purchase' ), $this->clarity_config()['orderEvents'] );
	}

	/** @dataProvider explicit_qa_marks */
	public function test_only_active_explicit_qa_marks_classify_the_receipt( array $values, bool $qa ): void {
		$this->receipt_order( $values );
		$config = $this->clarity_config();
		self::assertSame( $qa ? 'qa' : null, $config['internalTraffic'] ?? null );
		self::assertSame( $qa ? null : array( 'order_created' ), $config['orderEvents'] ?? null );
	}

	public function explicit_qa_marks(): array {
		$cases = array();
		foreach ( array( '_akb_byp_qa_run_id', '_akb_collection_qa_run_id', '_akibara_points_qa_t15_run', '_akibara_points_qa_ledger_run', '_akb_qa_pedido_de_prueba', 'QA' ) as $key ) {
			$cases[ $key ] = array( array( 'meta' => array( $key => 'test-run' ) ), true );
		}
		foreach ( array( false, 0, '0', '', 'false', ' no ', 'OFF', null, array() ) as $index => $value ) {
			$cases[ 'disabled-' . $index ] = array( array( 'meta' => array( '_akb_qa' => $value ) ), false );
		}
		$cases['unrelated key'] = array( array( 'meta' => array( '_akb_faq_answer' => 'yes', '_squash' => 'qa' ) ), false );
		$cases['QA source'] = array( array( 'created_via' => 'akb-byp-qa' ), true );
		$cases['ordinary source'] = array( array( 'created_via' => 'squad-checkout' ), false );
		return $cases;
	}

	public function test_staff_is_identified_by_commerce_capability_only(): void {
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable() ) );
		Functions\when( 'current_user_can' )->alias( static fn ( string $capability ) => 'manage_woocommerce' === $capability );
		self::assertSame( array( 'initialEvent' => '', 'internalTraffic' => 'staff' ), $this->clarity_config() );
	}

	public function test_receipt_token_is_stable_per_order_and_changes_with_order_id(): void {
		$this->receipt_order();
		$unpaid = $this->clarity_config()['orderToken'];
		$this->receipt_order( array( 'paid' => true, 'date_paid' => new \DateTimeImmutable() ) );
		self::assertSame( $unpaid, $this->clarity_config()['orderToken'] );
		Functions\when( 'get_query_var' )->justReturn( 457 );
		self::assertNotSame( $unpaid, $this->clarity_config()['orderToken'] );
	}

	/**
	 * @testdox Un clon de producción no encola el guion de Clarity aunque esté en el checkout.
	 */
	public function test_clarity_events_stay_off_nonproduction_hosts(): void {
		$enqueued = false;

		$_SERVER['HTTP_HOST'] = 'localhost:8080';
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias(
			static function () use ( &$enqueued ): void {
				$enqueued = true;
			}
		);

		( new FrontendCompatibility() )->enqueue_clarity_commerce_events();

		self::assertFalse( $enqueued );
	}
}
