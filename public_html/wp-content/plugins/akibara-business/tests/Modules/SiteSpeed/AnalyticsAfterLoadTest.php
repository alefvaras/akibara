<?php
/**
 * GA4 después de la carga: 100% código always-on.
 * La etiqueta de GA4 sale inerte y se carga tras la interacción del usuario.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SiteSpeed;

use Akibara\Business\Modules\SiteSpeed\AnalyticsAfterLoad;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnalyticsAfterLoadTest extends TestCase {

	/**
	 * The System Under Test.
	 *
	 * @var AnalyticsAfterLoad
	 */
	private AnalyticsAfterLoad $sut;

	/** @var array<int, array<int, mixed>> */
	private array $added = array();

	/** @var array<int, array<int, mixed>> */
	private array $removed = array();

	private const GTAG_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-VXW8CW2FXR';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->sut     = new AnalyticsAfterLoad();
		$this->added   = array();
		$this->removed = array();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'add_action' )->alias(
			function ( ...$args ): bool {
				$this->added[] = $args;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( ...$args ): bool {
				$this->added[] = $args;
				return true;
			}
		);
		Functions\when( 'remove_action' )->alias(
			function ( ...$args ): bool {
				$this->removed[] = $args;
				return true;
			}
		);
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( string $code, array $attrs = array() ): void {
				echo '<script' . self::attrs( $attrs ) . '>' . $code . "</script>\n";
			}
		);
		Functions\when( 'wp_print_script_tag' )->alias(
			static function ( array $attrs ): void {
				echo '<script' . self::attrs( $attrs ) . "></script>\n";
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @param array<string, mixed> $attrs */
	private static function attrs( array $attrs ): string {
		$out = '';
		foreach ( $attrs as $k => $v ) {
			$out .= ' ' . $k . '="' . htmlspecialchars( (string) $v, ENT_QUOTES ) . '"';
		}
		return $out;
	}

	/** @return array<string, mixed> */
	private function gtag(): array {
		return array(
			'id'    => AnalyticsAfterLoad::GTAG_ID,
			'src'   => self::GTAG_SRC,
			'async' => true,
		);
	}

	/**
	 * @testdox El módulo está encendido incondicionalmente por código.
	 */
	public function test_module_is_always_enabled(): void {
		self::assertTrue( AnalyticsAfterLoad::enabled() );
	}

	/**
	 * @testdox Encendido permanentemente, la etiqueta de GA4 sale inerte con su URL a la espera y fuera del optimizador.
	 */
	public function test_gtag_is_deferred_when_on(): void {
		$attrs = $this->sut->defer_gtag( $this->gtag() );

		self::assertSame( 'text/plain', $attrs['type'], 'Un type que no es JavaScript no se ejecuta ni se descarga.' );
		self::assertSame( self::GTAG_SRC, $attrs[ AnalyticsAfterLoad::ATTRIBUTE ] );
		self::assertArrayNotHasKey( 'src', $attrs );
		self::assertArrayNotHasKey( 'async', $attrs );
		self::assertSame( '1', $attrs['data-no-optimize'], 'LiteSpeed no puede reescribirla.' );
		self::assertSame( AnalyticsAfterLoad::GTAG_ID, $attrs['id'] );
	}

	/**
	 * @testdox Sólo cambia la etiqueta de GA4: su script en línea, las demás y una sin src siguen igual.
	 */
	public function test_only_the_gtag_tag_changes(): void {
		$otras = array(
			array( 'id' => 'google_gtagjs-inline' ),
			array( 'id' => 'akibara-native-cart-js', 'src' => 'https://akibara.cl/x.js' ),
			array( 'src' => 'https://akibara.cl/y.js' ),
			array( 'id' => AnalyticsAfterLoad::GTAG_ID, 'src' => '' ),
		);

		foreach ( $otras as $attrs ) {
			self::assertSame( $attrs, $this->sut->defer_gtag( $attrs ) );
		}
		self::assertSame( 'no es un array', $this->sut->defer_gtag( 'no es un array' ) );
	}

	/**
	 * @testdox Si se difiere la etiqueta, el cargador sale en el pie.
	 */
	public function test_loader_prints_when_deferred(): void {
		$this->sut->defer_gtag( $this->gtag() );

		ob_start();
		$this->sut->print_loader();
		$pie = (string) ob_get_clean();
		$js  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/analytics-after-load.js' );

		self::assertStringContainsString( 'id="akb-analytics-after-load"', $pie );
		self::assertStringContainsString( 'data-no-optimize="1"', $pie );
		self::assertStringContainsString( trim( $js ), $pie );
	}

	/**
	 * @testdox Se engancha en el front: filtro de GA4 y cargador en el pie.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$ganchos = array_map( static fn( array $a ): string => $a[0] . ':' . ( $a[2] ?? 10 ), $this->added );
		self::assertContains( 'wp_script_attributes:10', $ganchos );
		self::assertContains( 'wp_footer:99', $ganchos );
	}

	/**
	 * @testdox En el admin no engancha nada.
	 */
	public function test_register_hooks_nothing_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->sut->register();

		self::assertSame( array(), $this->added, 'En el admin no engancha nada.' );
	}
}
