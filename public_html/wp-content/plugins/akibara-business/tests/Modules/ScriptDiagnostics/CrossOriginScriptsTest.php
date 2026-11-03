<?php
/**
 * Contrato del marcado `crossorigin` en scripts de terceros.
 *
 * Lo que se protege acá no es una mejora, es la caja: marcar un host que NO
 * devuelve `Access-Control-Allow-Origin` hace que el navegador BLOQUEE el
 * script, y en `/checkout/` eso significa quedarse sin el SDK de Mercado Pago.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ScriptDiagnostics;

use Akibara\Business\Modules\ScriptDiagnostics\CrossOriginScripts;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.26
 */
final class CrossOriginScriptsTest extends TestCase {

	private CrossOriginScripts $sut;

	/** @var array<string, mixed> */
	private array $opciones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->opciones = array();
		$this->sut      = new CrossOriginScripts();

		Functions\when( 'get_option' )->alias(
			fn( string $o, $def = false ) => $this->opciones[ $o ] ?? $def
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$rest ) => $value
		);
		Functions\when( 'wp_parse_url' )->alias(
			static fn( string $url, int $componente = -1 ) => parse_url( $url, $componente )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function etiqueta( string $src ): string {
		return sprintf( '<script src="%s" id="x-js"></script>', $src );
	}

	/**
	 * @testdox Marca los hosts verificados para que su error deje de ser opaco.
	 */
	public function test_it_marks_verified_hosts(): void {
		foreach ( array(
			'https://sdk.mercadopago.com/js/v2?ver=8.9.2',
			'https://www.mercadopago.com/v2/security.js',
			'https://http2.mlstatic.com/storage/v1/mercadopago/woocommerce/scripts/x.js',
			'https://www.googletagmanager.com/gtag/js?id=G-VXW8CW2FXR',
			'https://scripts.clarity.ms/0.8.69/clarity.js',
		) as $src ) {
			$out = $this->sut->mark_third_party( $this->etiqueta( $src ), 'x', $src );

			self::assertStringContainsString( 'crossorigin="anonymous"', (string) $out, $src );
		}
	}

	/**
	 * @testdox NO marca un host sin CORS: marcarlo lo bloquearía.
	 *
	 * `googleads.g.doubleclick.net` fue el único de los doce del checkout que
	 * no pasó CORS al medirlo. Si algún día entra en la lista sin comprobarlo,
	 * el navegador dejaría de cargarlo.
	 */
	public function test_it_never_marks_a_host_without_cors(): void {
		$src = 'https://googleads.g.doubleclick.net/pagead/viewthroughconversion/123';

		self::assertStringNotContainsString(
			'crossorigin',
			(string) $this->sut->mark_third_party( $this->etiqueta( $src ), 'x', $src )
		);
	}

	/**
	 * @testdox Un host que sólo PARECE de la lista no cuela.
	 *
	 * Comparar por subcadena daría verdadero para
	 * `sdk.mercadopago.com.atacante.cl`, que es de otro dueño.
	 */
	public function test_a_lookalike_host_does_not_pass(): void {
		foreach ( array(
			'https://sdk.mercadopago.com.atacante.cl/x.js',
			'https://evil-sdk.mercadopago.com.co/x.js',
			'https://notsdk.mercadopago.com/x.js',
		) as $src ) {
			self::assertStringNotContainsString(
				'crossorigin',
				(string) $this->sut->mark_third_party( $this->etiqueta( $src ), 'x', $src ),
				$src
			);
		}
	}

	/**
	 * @testdox Los scripts propios se quedan como están.
	 */
	public function test_first_party_scripts_are_untouched(): void {
		$src = 'https://akibara.cl/wp-includes/js/dist/element.min.js';
		$tag = $this->etiqueta( $src );

		self::assertSame( $tag, $this->sut->mark_third_party( $tag, 'x', $src ) );
	}

	/**
	 * @testdox El interruptor de emergencia desactiva el módulo sin desplegar.
	 *
	 * Existe porque esto corre en la página de pago: si un tercero dejara de
	 * enviar la cabecera CORS, hay que poder apagarlo en la siguiente petición
	 * y no esperar a un despliegue.
	 */
	public function test_the_kill_switch_works_without_a_deploy(): void {
		$src = 'https://sdk.mercadopago.com/js/v2';
		$tag = $this->etiqueta( $src );

		$this->opciones[ CrossOriginScripts::OPTION_ENABLED ] = 'no';

		self::assertSame( $tag, $this->sut->mark_third_party( $tag, 'x', $src ) );
	}

	/**
	 * @testdox No duplica el atributo si el tercero ya lo trae.
	 */
	public function test_it_does_not_duplicate_an_existing_attribute(): void {
		$tag = '<script crossorigin="anonymous" src="https://scripts.clarity.ms/x.js"></script>';

		self::assertSame( $tag, $this->sut->mark_third_party( $tag, 'x', 'https://scripts.clarity.ms/x.js' ) );
	}

	/**
	 * @testdox El módulo está instanciado Y registrado en Plugin.php.
	 *
	 * Instanciar sin llamar a `register()` deja un módulo que no engancha nada:
	 * las pruebas pasan, el código se lee bien, y en producción no hace
	 * absolutamente nada. Ya pasó antes en este repo.
	 */
	public function test_the_module_is_actually_wired_in_the_plugin(): void {
		$plugin = file_get_contents( __DIR__ . '/../../../src/Plugin.php' );

		self::assertStringContainsString( 'new CrossOriginScripts()', (string) $plugin );
		self::assertMatchesRegularExpression(
			"/\\\$this->modules\\['crossorigin_scripts'\\]->register\\(\\)/",
			(string) $plugin,
			'instanciarlo sin registrarlo lo deja inerte'
		);
	}

	/**
	 * @testdox Entradas raras no rompen la página.
	 */
	public function test_odd_input_never_breaks_the_page(): void {
		self::assertSame( '', $this->sut->mark_third_party( '', 'x', '' ) );
		self::assertNull( $this->sut->mark_third_party( null, 'x', 'https://sdk.mercadopago.com/js/v2' ) );
		$tag = $this->etiqueta( 'no-es-una-url' );
		self::assertSame( $tag, $this->sut->mark_third_party( $tag, 'x', 'no-es-una-url' ) );
	}
}
