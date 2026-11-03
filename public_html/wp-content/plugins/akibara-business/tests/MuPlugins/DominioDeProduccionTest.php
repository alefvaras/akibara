<?php
/**
 * En el dominio de la tienda, un entorno de clon no puede apagar las alarmas.
 *
 * Revisión de 1.2.1 (correo) / 1.2.0 (accesos): las tres piezas del correo
 * decidían sólo con `wp_get_environment_type()`. Si akibara.cl quedaba con
 * `WP_ENVIRONMENT_TYPE` distinto de `production` —una copia de staging
 * restaurada encima—, el guardarraíl desviaba el correo de clientes a la
 * bandeja de captura, el transporte lo dejaba pasar por su rama de clones y
 * `wp akibara access-check` daba verde. Con 1.2.0 del transporte, en cambio,
 * el envío se cortaba y salía el aviso rojo.
 *
 * Los dos mu-plugins cargan en orden alfabético y no pueden depender uno del
 * otro, así que el predicado del dominio está duplicado: esta prueba fija que
 * los dos respondan igual.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\MuPlugins;

use Akibara\HostingerMail\Transport;
use Akibara\ProviderAccess\Access;
use Akibara\ProviderAccess\Cli;
use Akibara\ProviderAccess\Registry;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DominioDeProduccionTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( Transport::class, false ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-smtp.php';
		}
		if ( ! class_exists( Registry::class, false ) ) {
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-provider-access.php';
		}

		$this->options = array();
		Access::flush();
	}

	protected function tearDown(): void {
		Access::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function urls(): array {
		return array(
			'raíz'                    => array( 'https://akibara.cl', true ),
			'con barra'               => array( 'https://akibara.cl/', true ),
			'www'                     => array( 'https://www.akibara.cl', true ),
			'mayúsculas'              => array( 'https://AKIBARA.CL', true ),
			'punto final'             => array( 'https://akibara.cl./', true ),
			'con puerto'              => array( 'https://akibara.cl:443', true ),
			'con espacios'            => array( ' https://akibara.cl ', true ),
			'http'                    => array( 'http://www.akibara.cl', true ),
			'subdominio de staging'   => array( 'https://staging.akibara.cl', false ),
			'dominio parecido'        => array( 'https://akibara.cl.example.test', false ),
			'dominio que lo contiene' => array( 'https://noakibara.cl', false ),
			'espejo local'            => array( 'http://localhost:8080', false ),
			'dominio de pruebas'      => array( 'https://akibara.test', false ),
			'vacía'                   => array( '', false ),
		);
	}

	/**
	 * @dataProvider urls
	 * @testdox Los dos mu-plugins reconocen igual el dominio de la tienda
	 */
	public function test_both_predicates_agree( string $url, bool $esperado ): void {
		self::assertSame( $esperado, Transport::is_production_host( $url ), 'Transport' );
		self::assertSame( $esperado, Registry::is_production_host( $url ), 'Registry' );
		self::assertSame( Transport::PRODUCTION_HOSTS, Registry::PRODUCTION_HOSTS );
	}

	/** @testdox En akibara.cl con entorno «staging» el transporte corta y deja la causa verdadera en el aviso */
	public function test_the_transport_blocks_and_says_why_on_the_store_domain(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		$this->stub_option_store();

		self::assertFalse( Transport::guard_configuration( null, array() ) );

		$corte = $this->options[ Transport::OPTION_OUTAGE ] ?? null;
		self::assertIsArray( $corte, 'Sin rastro no hay aviso rojo en el admin.' );
		self::assertSame( Transport::KIND_BLOCKED, $corte['kind'] );
		self::assertStringContainsString( 'akibara.cl', $corte['reason'] );
		self::assertStringContainsString( 'WP_ENVIRONMENT_TYPE vale «staging»', $corte['reason'] );
		self::assertFalse( Transport::profile_is_valid(), 'Y el gate no puede dar el perfil por bueno.' );
	}

	/** @testdox En un clon de verdad (otro dominio) la rama permisiva sigue igual que en 1.2.1 */
	public function test_a_real_clone_keeps_the_permissive_branch(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		Functions\when( 'home_url' )->justReturn( 'https://staging.akibara.cl' );

		self::assertSame( 'previo', Transport::guard_configuration( 'previo', array() ) );
		self::assertTrue( Transport::profile_is_valid(), 'Sin constantes, fuera de producción vale el perfil de captura.' );
	}

	/** @testdox El gate de accesos juzga como producción por el entorno O por el dominio, y nombra el desajuste */
	public function test_the_access_gate_judges_by_domain_too(): void {
		self::assertTrue( Registry::judged_as_production( 'staging', 'https://www.akibara.cl' ) );
		self::assertTrue( Registry::judged_as_production( 'production', 'http://localhost:8080' ) );
		self::assertFalse( Registry::judged_as_production( 'local', 'http://localhost:8080' ) );

		self::assertStringContainsString( 'WP_ENVIRONMENT_TYPE vale «staging»', Registry::environment_mismatch( 'staging', 'https://www.akibara.cl' ) );
		self::assertSame( '', Registry::environment_mismatch( 'production', 'https://akibara.cl' ) );
		self::assertSame( '', Registry::environment_mismatch( 'local', 'http://localhost:8080' ) );
	}

	/** @testdox `wp akibara access-check` falla con el desajuste y aplica las reglas de producción en el dominio */
	public function test_the_cli_gate_uses_the_domain_rule(): void {
		$codigo = Cli::code_only( (string) file_get_contents( dirname( __DIR__, 4 ) . '/mu-plugins/akibara-provider-access.php' ) );

		$inicio = strpos( $codigo, 'public static function check()' );
		self::assertNotFalse( $inicio );
		$cuerpo = substr( $codigo, $inicio, (int) strpos( $codigo, 'public static function code_only(' ) - $inicio );

		self::assertStringContainsString( 'Registry::environment_mismatch(', $cuerpo );
		self::assertStringContainsString( 'Registry::judged_as_production(', $cuerpo );
		self::assertStringNotContainsString( "'production' === wp_get_environment_type()", $cuerpo, 'El entorno solo ya no decide.' );
	}

	/**
	 * En el espejo el correo va a Mailpit (`mailpit`, 1025, sin cifrado) y el
	 * inventario pintaba «✗ formato inválido» con el correo funcionando.
	 *
	 * @testdox Fuera de producción un valor de pruebas que el transporte acepta no sale como formato inválido
	 */
	public function test_a_test_profile_is_not_a_red_outside_production(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost:8080' );

		$host = array(
			'id'        => 'hostinger_smtp_host',
			'malformed' => true,
		);

		self::assertTrue( Access::accepted_outside_production( $host, false ) );
		self::assertFalse( Access::accepted_outside_production( $host, true ), 'En producción el patrón manda.' );
		self::assertFalse(
			Access::accepted_outside_production(
				array(
					'id'        => 'hostinger_mail_enabled',
					'malformed' => true,
				),
				false
			),
			'El interruptor exige `true` de verdad en todo entorno.'
		);
		self::assertFalse(
			Access::accepted_outside_production(
				array(
					'id'        => 'hostinger_smtp_host',
					'malformed' => false,
				),
				false
			)
		);
	}

	private function stub_option_store(): void {
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => $this->options[ $name ] ?? $default
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'do_action' )->justReturn( null );
	}

	/**
	 * Revisión independiente de 1.116.9 (ALTA): un staging de Hostinger copia
	 * `wp-config.php` —con `WP_ENVIRONMENT_TYPE = production`— y sólo reescribe
	 * el dominio. El guardarraíl de captura miraba el entorno y se apartaba: el
	 * clon mandaba correo real a clientes reales.
	 *
	 * @testdox Con entorno de producción, sólo el dominio de la tienda deja salir correo a clientes.
	 */
	public function test_production_config_on_a_clone_domain_still_captures(): void {
		if ( ! function_exists( 'akibara_correo_es_la_tienda_de_verdad' ) ) {
			Functions\when( 'add_filter' )->justReturn( true );
			require_once dirname( __DIR__, 4 ) . '/mu-plugins/akibara-correo-no-sale-a-clientes.php';
		}
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		self::assertTrue( akibara_correo_es_la_tienda_de_verdad(), 'La tienda de verdad envía.' );

		foreach ( array( 'https://staging.akibara.cl', 'https://xyz.hostingersite.com', 'http://localhost:8080' ) as $clon ) {
			Functions\when( 'home_url' )->justReturn( $clon );
			self::assertFalse( akibara_correo_es_la_tienda_de_verdad(), "{$clon} con entorno de producción no es la tienda: captura." );
		}

		Functions\when( 'wp_get_environment_type' )->justReturn( 'staging' );
		Functions\when( 'home_url' )->justReturn( 'https://akibara.cl' );
		self::assertFalse( akibara_correo_es_la_tienda_de_verdad() );
	}

	/** @testdox El gate de accesos también avisa del caso inverso: producción en un dominio ajeno. */
	public function test_the_access_gate_flags_production_config_on_a_foreign_domain(): void {
		$problema = Registry::environment_mismatch( 'production', 'https://xyz.hostingersite.com' );

		self::assertStringContainsString( 'NO es la tienda', $problema );
		self::assertStringContainsString( 'xyz.hostingersite.com', $problema );
		self::assertSame( '', Registry::environment_mismatch( 'production', 'https://akibara.cl' ) );
		self::assertSame( '', Registry::environment_mismatch( 'staging', 'https://staging.akibara.cl' ) );
	}
}
