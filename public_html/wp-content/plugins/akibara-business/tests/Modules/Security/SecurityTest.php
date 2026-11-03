<?php
/**
 * Pruebas del hardening de superficies públicas.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Security;

use Akibara\Business\Modules\Security\Security;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

	/** @var array<string,mixed> */
	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->original_get = $_GET;
		$_GET              = array();
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registers_the_expected_security_hooks(): void {
		$security = new Security();

		Monkey\Actions\expectAdded( 'wp_loaded' )
			->once()
			->with( array( $security, 'intercept_lostpassword_post' ), 15 );
		Monkey\Filters\expectAdded( 'rest_endpoints' )
			->once()
			->with( array( $security, 'maybe_remove_user_endpoints' ) );
		Monkey\Filters\expectAdded( 'rest_endpoints' )
			->once()
			->with( array( $security, 'protect_bluex_management_endpoints' ), 1000 );
		Monkey\Filters\expectAdded( 'rest_post_dispatch' )
			->once()
			->with( array( $security, 'add_bluex_no_store_headers' ), 1000, 3 );
		Monkey\Actions\expectAdded( 'parse_request' )
			->once()
			->with( array( $security, 'block_author_enumeration' ), 1 );
		Monkey\Actions\expectAdded( 'admin_print_footer_scripts' )
			->once()
			->with( array( $security, 'add_bluex_rest_nonce_bridge' ), 1 );

		foreach (
			array(
				'test_correios_integration',
				'correios_autofill_addresses_empty_database',
				'validate_integration_is_active',
				'update_integration_credentials',
				'woocommerce_correios_add_tracking_code',
				'woocommerce_correios_remove_tracking_code',
			) as $action
		) {
			Monkey\Actions\expectAdded( 'wp_ajax_' . $action )
				->once()
				->with( array( $security, 'enforce_bluex_ajax_capability' ), -1000 );
			Monkey\Actions\expectAdded( 'wp_ajax_nopriv_' . $action )
				->once()
				->with( array( $security, 'enforce_bluex_ajax_capability' ), -1000 );
		}

		Monkey\Filters\expectAdded( 'authenticate' )
			->once()
			->with( array( $security, 'harden_login_errors' ), 99, 3 );
		Monkey\Filters\expectAdded( 'woocommerce_process_registration_errors' )
			->once()
			->with( array( $security, 'harden_registration_errors' ), 99, 4 );
		$security->register();
		$this->addToAssertionCount( 20 );
	}

	/**
	 * El tercer lado del triángulo CWE-203: el alta no puede confirmar qué
	 * correos (ni qué usuarios) existen ya en la base.
	 */
	public function test_registration_errors_do_not_reveal_whether_the_account_exists(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'sanitize_user' )->returnArg();
		Functions\when( 'is_email' )->alias( static fn( string $email ): bool => str_contains( $email, '@' ) );
		Functions\when( 'email_exists' )->alias( static fn( string $email ): bool => 'cliente@akibara.cl' === $email );
		Functions\when( 'username_exists' )->alias( static fn( string $user ): bool => 'clienta' === $user );
		Functions\when( 'wp_lostpassword_url' )->justReturn( 'https://akibara.cl/mi-cuenta/lost-password/' );
		Functions\when( 'esc_url' )->returnArg();

		$security = new Security();

		$taken_email = $security->harden_registration_errors( new \WP_Error(), '', 'clave', 'cliente@akibara.cl' );
		self::assertInstanceOf( \WP_Error::class, $taken_email );
		self::assertSame( 'akibara_registration_failed', $taken_email->get_error_code() );
		self::assertStringNotContainsString( 'cliente@akibara.cl', $taken_email->get_error_message() );
		self::assertStringContainsString( 'lost-password', $taken_email->get_error_message() );

		$taken_user = $security->harden_registration_errors( new \WP_Error(), 'clienta', 'clave', 'nueva@akibara.cl' );
		self::assertSame( 'akibara_registration_failed', $taken_user->get_error_code() );
		self::assertStringNotContainsString( 'clienta', $taken_user->get_error_message() );

		// Los dos mensajes son EL MISMO: correo tomado y usuario tomado no se
		// distinguen desde fuera.
		self::assertSame( $taken_email->get_error_message(), $taken_user->get_error_message() );

		// El alta legítima pasa intacta: sin error, WooCommerce sigue su camino.
		$fresh = $security->harden_registration_errors( new \WP_Error(), 'clienta-nueva', 'clave', 'nueva@akibara.cl' );
		self::assertInstanceOf( \WP_Error::class, $fresh );
		self::assertSame( '', (string) $fresh->get_error_code(), 'un correo y un usuario libres no pueden generar error' );

		// Un error de validación previo se respeta tal cual (no se pisa ni se
		// duplica el mensaje).
		$previo = new \WP_Error( 'registration-error-invalid-email', 'Correo inválido.' );
		$result = $security->harden_registration_errors( $previo, '', 'clave', 'cliente@akibara.cl' );
		self::assertSame( $previo, $result );
		self::assertSame( array( 'Correo inválido.' ), $result->get_error_messages() );
	}

	public function test_login_errors_do_not_reveal_whether_the_account_exists(): void {
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_lostpassword_url' )->justReturn( 'https://akibara.cl/mi-cuenta/lost-password/' );
		Functions\when( 'esc_url' )->returnArg();
		$security = new Security();

		foreach ( array( 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo' ) as $code ) {
			$leaky  = new \WP_Error( $code, 'El nombre de usuario qa-inexistente no está registrado en este sitio.' );
			$result = $security->harden_login_errors( $leaky, 'qa-inexistente', 'clave' );
			self::assertInstanceOf( \WP_Error::class, $result, $code );
			self::assertSame( 'akibara_login_failed', $result->get_error_code(), $code );
			self::assertStringNotContainsString( 'qa-inexistente', $result->get_error_message(), $code );
			self::assertStringContainsString( 'lost-password', $result->get_error_message(), $code );
		}

		$empty = new \WP_Error( 'empty_password', 'La contraseña no puede estar vacía.' );
		self::assertSame( $empty, $security->harden_login_errors( $empty, 'qa', '' ), 'los errores por campo vacío no filtran nada y se conservan' );

		$user = new \stdClass();
		self::assertSame( $user, $security->harden_login_errors( $user, 'qa', 'clave' ), 'un login correcto pasa intacto' );
	}

	public function test_protects_every_bluex_management_handler_without_touching_other_routes(): void {
		$security  = new Security();
		$callback  = static fn(): bool => true;
		$endpoints = array(
			'/wc-bluex/v1/get-settings' => array(
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				),
				'namespace' => 'wc-bluex/v1',
			),
			'/wc-bluex/v1/delete-logs' => array(
				array(
					'methods'             => 'DELETE',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				),
			),
			'/wc-bluex/v1' => array(
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				),
			),
			'/wc/store/v1/cart' => array(
				array(
					'methods'             => 'GET',
					'callback'            => $callback,
					'permission_callback' => '__return_true',
				),
			),
		);

		$result = $security->protect_bluex_management_endpoints( $endpoints );

		self::assertSame(
			array( $security, 'can_manage_bluex' ),
			$result['/wc-bluex/v1/get-settings'][0]['permission_callback']
		);
		self::assertSame(
			array( $security, 'can_manage_bluex' ),
			$result['/wc-bluex/v1/delete-logs'][0]['permission_callback']
		);
		self::assertSame(
			array( $security, 'can_manage_bluex' ),
			$result['/wc-bluex/v1'][0]['permission_callback']
		);
		self::assertSame(
			'__return_true',
			$result['/wc/store/v1/cart'][0]['permission_callback']
		);
		self::assertSame( 'wc-bluex/v1', $result['/wc-bluex/v1/get-settings']['namespace'] );
	}

	public function test_bluex_permission_requires_manage_woocommerce(): void {
		Functions\expect( 'current_user_can' )
			->twice()
			->with( 'manage_woocommerce' )
			->andReturn( false, true );

		$security = new Security();
		self::assertFalse( $security->can_manage_bluex() );
		self::assertTrue( $security->can_manage_bluex() );
	}

	public function test_bluex_ajax_gate_blocks_guests_and_low_privilege_users(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_woocommerce' )
			->andReturn( false );
		Functions\expect( 'wp_send_json_error' )
			->once()
			->with(
				array(
					'code'    => 'bluex_forbidden',
					'message' => 'No tienes permisos para administrar Blue Express.',
				),
				403
			);

		( new Security() )->enforce_bluex_ajax_capability();
		self::addToAssertionCount( 1 );
	}

	public function test_bluex_ajax_gate_preserves_authorized_vendor_handler(): void {
		Functions\expect( 'current_user_can' )
			->once()
			->with( 'manage_woocommerce' )
			->andReturn( true );
		Functions\expect( 'wp_send_json_error' )->never();

		( new Security() )->enforce_bluex_ajax_capability();
		self::addToAssertionCount( 1 );
	}

	public function test_bluex_rest_responses_are_private_and_never_cacheable(): void {
		$response = new class() {
			/** @var array<string,string> */
			public array $headers = array();

			public function header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
		$request  = new class() {
			public function get_route(): string {
				return '/wc-bluex/v1/get-settings';
			}
		};

		$result = ( new Security() )->add_bluex_no_store_headers( $response, null, $request );

		self::assertSame( $response, $result );
		self::assertSame(
			'no-store, no-cache, must-revalidate, max-age=0, private',
			$response->headers['Cache-Control']
		);
		self::assertSame( 'no-store', $response->headers['Cloudflare-CDN-Cache-Control'] );
		self::assertSame( 'no-store', $response->headers['CDN-Cache-Control'] );
		self::assertSame( 'no-cache', $response->headers['Pragma'] );
	}

	public function test_no_store_headers_do_not_touch_store_api(): void {
		$response = new class() {
			/** @var array<string,string> */
			public array $headers = array();

			public function header( string $name, string $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
		$request  = new class() {
			public function get_route(): string {
				return '/wc/store/v1/cart';
			}
		};

		$result = ( new Security() )->add_bluex_no_store_headers( $response, null, $request );

		self::assertSame( $response, $result );
		self::assertSame( array(), $response->headers );
	}

	public function test_nonce_bridge_is_only_attached_to_the_official_bluex_handle(): void {
		Functions\expect( 'wp_script_is' )
			->once()
			->with( 'correios-integration-admin', 'enqueued' )
			->andReturn( true );
		Functions\expect( 'wp_add_inline_script' )
			->once()
			->with(
				'correios-integration-admin',
				\Mockery::on(
					static function ( string $script ): bool {
						return str_contains( $script, '/wp-json/wc-bluex/v1/' )
							&& str_contains( $script, 'X-WP-Nonce' )
							&& str_contains( $script, 'rest_nonce' );
					}
				),
				'before'
			);

		( new Security() )->add_bluex_rest_nonce_bridge();
		self::addToAssertionCount( 1 );
	}

	public function test_removes_public_wordpress_user_endpoints(): void {
		$endpoints = array(
			'/wp/v2/users'               => array(),
			'/wp/v2/users/(?P<id>[\d]+)' => array(),
			'/wp/v2/posts'               => array(),
		);

		self::assertSame(
			array( '/wp/v2/posts' => array() ),
			( new Security() )->filter_user_endpoints( $endpoints )
		);
	}

	public function test_runtime_rest_filter_blocks_listing_for_unprivileged_users(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$endpoints = array(
			'/wp/v2/users' => array(),
			'/wp/v2/posts' => array(),
		);

		self::assertSame(
			array( '/wp/v2/posts' => array() ),
			( new Security() )->maybe_remove_user_endpoints( $endpoints )
		);
	}

	public function test_runtime_rest_filter_preserves_endpoints_for_user_administrators(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$endpoints = array(
			'/wp/v2/users' => array(),
			'/wp/v2/posts' => array(),
		);

		self::assertSame( $endpoints, ( new Security() )->maybe_remove_user_endpoints( $endpoints ) );
	}

	public function test_turns_path_author_archive_into_real_404_for_guests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		$wp = new \WP();
		$wp->query_vars = array( 'author_name' => 'ale-fvaras' );

		( new Security() )->block_author_enumeration( $wp );

		self::assertSame( array( 'error' => '404' ), $wp->query_vars );
	}

	public function test_turns_numeric_author_query_into_real_404_for_guests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		$_GET['author'] = '1';
		$wp = new \WP();
		$wp->query_vars = array( 'author' => 1 );

		( new Security() )->block_author_enumeration( $wp );

		self::assertSame( array( 'error' => '404' ), $wp->query_vars );
	}

	public function test_keeps_author_context_only_for_users_allowed_to_list_users(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'list_users' === $capability
		);
		$wp = new \WP();
		$wp->query_vars = array( 'author_name' => 'editor' );

		( new Security() )->block_author_enumeration( $wp );

		self::assertSame( array( 'author_name' => 'editor' ), $wp->query_vars );
	}

	public function test_blocks_author_archive_for_logged_in_store_customer_without_list_users(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		$wp = new \WP();
		$wp->query_vars = array( 'author_name' => 'administrator-slug' );

		( new Security() )->block_author_enumeration( $wp );

		self::assertSame( array( 'error' => '404' ), $wp->query_vars );
	}
}
