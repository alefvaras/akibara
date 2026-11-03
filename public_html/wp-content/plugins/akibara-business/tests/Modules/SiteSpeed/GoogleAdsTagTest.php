<?php
/**
 * Etiqueta de Google Ads: apagada por defecto, encendida sólo con `yes`, purga sólo
 * cuando cambia y se decide antes de que Google for WooCommerce registre sus servicios.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\GoogleAdsTag;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class GoogleAdsTagTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, callable> */
	private array $al_apagar = array();

	/** @var array<int, array<int, mixed>> */
	private array $acciones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options   = array();
		$this->al_apagar = array();
		$this->acciones  = array();
		Functions\when( 'get_option' )->alias( fn( string $name, $default = false ) => $this->options[ $name ] ?? $default );
		Functions\when( 'add_action' )->alias(
			function ( string $hook, callable $callback ): bool {
				if ( 'shutdown' === $hook ) {
					$this->al_apagar[] = $callback;
				}
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( ...$args ): void {
				$this->acciones[] = $args;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox Sin fila o con cualquier valor distinto de yes, la etiqueta no se carga. */
	public function test_tag_is_off_unless_explicitly_on(): void {
		self::assertTrue( GoogleAdsTag::filter_disable( false ), 'Fila ausente: apagada.' );
		$this->options[ GoogleAdsTag::OPTION ] = 'no';
		self::assertTrue( GoogleAdsTag::filter_disable( false ) );
		$this->options[ GoogleAdsTag::OPTION ] = '1';
		self::assertTrue( GoogleAdsTag::filter_disable( false ) );
		$this->options[ GoogleAdsTag::OPTION ] = 'yes';
		self::assertFalse( GoogleAdsTag::filter_disable( false ) );
		self::assertTrue( GoogleAdsTag::filter_disable( true ), 'Si otro ya la apagó, sigue apagada.' );
	}

	/** @testdox Cambiar el interruptor purga la caché; guardarlo igual, no. */
	public function test_purges_only_on_a_real_change(): void {
		GoogleAdsTag::purge_after_change( 'no', 'no' );
		GoogleAdsTag::purge_after_change( 'yes', 'yes' );
		GoogleAdsTag::purge_after_change( '', 'no' );
		self::assertSame( array(), $this->al_apagar );

		GoogleAdsTag::purge_after_change( 'no', 'yes' );
		GoogleAdsTag::purge_after_change( 'yes', 'no' );
		self::assertCount( 2, $this->al_apagar );
		( $this->al_apagar[0] )();
		self::assertSame( array( array( 'litespeed_purge_all' ) ), $this->acciones );
	}

	/** @testdox La primera escritura sólo purga si enciende: la fila ausente ya apagaba. */
	public function test_first_write_purges_only_when_turning_on(): void {
		GoogleAdsTag::purge_after_add( GoogleAdsTag::OPTION, 'no' );
		self::assertSame( array(), $this->al_apagar );
		GoogleAdsTag::purge_after_add( GoogleAdsTag::OPTION, 'yes' );
		self::assertCount( 1, $this->al_apagar );
	}

	/**
	 * @testdox El filtro se engancha antes de que Google for WooCommerce registre sus servicios.
	 *
	 * Ese plugin registra sus servicios en `plugins_loaded:20`
	 * (`GoogleListingsAndAdsPlugin::register()`), la misma prioridad que el arranque de
	 * este plugin: ahí decidiría el orden de carga. Si alguien mueve el enganche al
	 * arranque, la etiqueta puede volver a cargarse aunque el panel diga que está apagada.
	 */
	public function test_is_hooked_before_google_for_woocommerce_registers(): void {
		$principal = (string) file_get_contents( dirname( __DIR__, 3 ) . '/akibara-business.php' );
		$principal = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $principal );

		self::assertMatchesRegularExpression( "#require_once AKIBARA_BUSINESS_DIR \. 'src/Modules/SiteSpeed/GoogleAdsTag\.php';#", $principal );
		self::assertMatchesRegularExpression( "#add_action\(\s*'plugins_loaded',\s*array\(\s*\\\\Akibara\\\\Business\\\\Modules\\\\SiteSpeed\\\\GoogleAdsTag::class,\s*'register_early'\s*\),\s*([0-9]+)\s*\);#", $principal );
		preg_match( "#GoogleAdsTag::class,\s*'register_early'\s*\),\s*([0-9]+)#", $principal, $m );
		self::assertLessThan( 20, (int) $m[1], 'Tiene que correr antes que Google for WooCommerce (plugins_loaded:20).' );

		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/SiteSpeed/GoogleAdsTag.php' );
		self::assertStringContainsString( "public const FILTER = 'woocommerce_gla_disable_gtag_tracking';", $fuente );
	}
}
