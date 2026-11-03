<?php
/**
 * El cableado del módulo.
 *
 * Que `register()` corra entero importa: es la única llamada que hace
 * `Plugin::register_modules()`, y un fatal ahí tumba TODO el plugin en cada
 * petición —incluidas las 1.568 fichas—, no sólo esta feature.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFollow;

use Akibara\Business\Modules\EstimatedDelivery\PedidoPolicy;
use Akibara\Business\Modules\SeriesBundles\CustomerCollection;
use Akibara\Business\Modules\SeriesFollow\GlobalToggle;
use Akibara\Business\Modules\SeriesFollow\Install;
use Akibara\Business\Modules\SeriesFollow\SeriesFollow;
use Akibara\Business\Modules\SeriesFollow\SeriesFollowEmail;
use Akibara\Business\Modules\SeriesFollow\UnfollowHandler;
use Akibara\Business\Tests\Support\SeriesFollowFixedEta;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

/** `$wpdb` mínimo: `register()` sólo construye la cola y sondea el esquema. */
final class ModuleWiringWpdb {

	public string $prefix = 'wp_';

	public function esc_like( string $value ): string {
		return $value;
	}

	/** @param mixed ...$args Argumentos ligados. */
	public function prepare( string $sql, ...$args ): string {
		return str_replace( '%s', (string) ( $args[0] ?? '' ), $sql );
	}

	public function get_var( string $sql ): string {
		unset( $sql );
		return 'wp_akb_series_follow_queue';
	}
}

final class ModuleWiringTest extends TestCase {

	private SeriesFollow $modulo;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$GLOBALS['wpdb'] = new ModuleWiringWpdb();

		Functions\when( 'apply_filters' )->alias( static fn ( string $hook, $value ) => $value );
		Functions\when( 'get_option' )->justReturn( Install::DB_VERSION );
		Functions\when( 'trailingslashit' )->alias(
			static fn ( string $value ): string => rtrim( $value, '/\\' ) . '/'
		);

		$this->modulo = new SeriesFollow(
			new CustomerCollection( $GLOBALS['wpdb'] ),
			new SeriesFollowFixedEta(),
			new PedidoPolicy()
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox register() cablea el listener, el worker, la baja y el toggle. */
	public function test_register_cablea_todos_los_submodulos(): void {
		$this->modulo->register();

		self::assertTrue( has_action( 'transition_post_status' ), 'La detección de publicaciones.' );
		self::assertTrue( has_action( 'set_object_terms' ), 'La red del importer.' );
		self::assertTrue( has_action( SeriesFollow::HOOK_SCAN ), 'El scan de un tomo.' );
		self::assertTrue( has_action( SeriesFollow::HOOK_DIGEST ), 'La corrida del digest.' );
		self::assertTrue( has_action( 'template_redirect' ), 'La baja de un clic.' );
		self::assertTrue( has_action( 'rest_api_init' ), 'El interruptor de Mi colección.' );
		self::assertTrue( has_filter( 'woocommerce_email_classes' ), 'El correo registrado en el mailer.' );
	}

	/**
	 * Los handlers de los jobs se registran SIEMPRE, aunque los avisos estén
	 * apagados: un job ya encolado en Action Scheduler que no encuentra callback
	 * queda colgado para siempre.
	 *
	 * @testdox Los handlers de los jobs se registran aunque el carril esté apagado.
	 */
	public function test_los_handlers_se_registran_con_el_carril_apagado(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => Install::OPTION_KEY === $option
				? Install::DB_VERSION
				: 'no'
		);

		$this->modulo->register();

		self::assertFalse( SeriesFollow::enabled() );
		self::assertTrue( has_action( SeriesFollow::HOOK_SCAN ) );
		self::assertTrue( has_action( SeriesFollow::HOOK_DIGEST ) );
	}

	/**
	 * La baja NUNCA cuelga del flag: un enlace de baja muerto en un correo ya
	 * enviado es incumplimiento.
	 *
	 * @testdox La baja de un clic sigue viva con el carril apagado.
	 */
	public function test_la_baja_sigue_viva_con_el_carril_apagado(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => Install::OPTION_KEY === $option
				? Install::DB_VERSION
				: 'no'
		);

		$this->modulo->register();

		self::assertTrue( has_action( 'template_redirect' ) );
	}

	/**
	 * El interruptor GLOBAL de Mi cuenta tampoco cuelga del flag: quien se dio
	 * de baja desde un correo tiene que poder reencender aunque los envíos
	 * estén apagados. Va en `template_redirect` prio 1, como la baja.
	 *
	 * @testdox El interruptor global sigue vivo con el carril apagado, en template_redirect prio 1.
	 */
	public function test_el_interruptor_global_sigue_vivo_con_el_carril_apagado(): void {
		Functions\when( 'get_option' )->alias(
			static fn ( string $option, $default_value = false ) => Install::OPTION_KEY === $option
				? Install::DB_VERSION
				: 'no'
		);

		Monkey\Actions\expectAdded( 'template_redirect' )
			->twice()
			->with(
				\Mockery::on(
					static fn ( $callback ): bool => is_array( $callback )
						&& 'handle' === ( $callback[1] ?? '' )
						&& ( $callback[0] instanceof UnfollowHandler || $callback[0] instanceof GlobalToggle )
				),
				1
			);

		$this->modulo->register();

		self::assertFalse( SeriesFollow::enabled() );
		$this->addToAssertionCount( 1 );
	}

	/** @testdox El correo entra al mailer bajo una clave propia del plugin. */
	public function test_el_correo_entra_al_mailer(): void {
		$emails = $this->modulo->register_email( array( 'WC_Email_Customer_Invoice' => new \stdClass() ) );

		self::assertArrayHasKey( 'WC_Email_Customer_Invoice', $emails, 'No pisa los de WooCommerce.' );
		self::assertInstanceOf( SeriesFollowEmail::class, $emails['AkibaraSeriesFollowEmail'] );
	}

	/** @testdox El módulo expone UNA sola cola para todo el request. */
	public function test_la_cola_es_una_sola_instancia(): void {
		self::assertSame( $this->modulo->queue(), $this->modulo->queue() );
	}
}
