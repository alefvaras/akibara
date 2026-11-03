<?php
/**
 * El campo del umbral dice, con el número vivo, cuándo va a tener efecto.
 *
 * «Umbral envío gratis (CLP)» es un PISO: sólo puede exigir más que el
 * transportista, nunca menos (`max( real, override )` en
 * `Services/FreeShippingThreshold`). Está documentado en la ayuda del campo,
 * y aun así QA escribió 45.000 sobre un umbral de transportista de 55.000, no
 * vio ningún cambio en la barra, y lo levantó como «un interruptor muerto».
 *
 * No lo es. Pero que alguien que lee la ayuda acabe creyendo que el campo no
 * sirve es un defecto de la pantalla: la regla de la casa es que todo sea
 * operable sin código y que ningún campo acepte un número sin decir qué hará
 * con él. La ayuda explicaba la MECÁNICA en abstracto; lo que faltaba era el
 * número concreto contra el que se compara.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use Akibara\Business\Modules\FreeShippingProgress\FreeShippingProgress;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class UmbralConEfectoVisibleTest extends TestCase {

	/** @var array<string, mixed> */
	private array $opciones = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->opciones = array( 'akibara_fsp_carrier_threshold' => '55000' );

		Functions\when( 'get_option' )->alias(
			fn( string $nombre, $default = false ) => $this->opciones[ $nombre ] ?? $default
		);
		Functions\when( 'number_format_i18n' )->alias(
			static fn( $numero ) => number_format( (float) $numero, 0, ',', '.' )
		);
		Functions\when( 'wc_price' )->alias(
			static fn( $numero ) => '$' . number_format( (float) $numero, 0, ',', '.' )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** La ayuda del campo del piso, tal como se pinta. */
	private function ayuda_del_piso(): string {
		$metodo = new ReflectionMethod( FreeShippingProgress::class, 'get_settings' );
		$metodo->setAccessible( true );

		$modulo   = ( new \ReflectionClass( FreeShippingProgress::class ) )->newInstanceWithoutConstructor();
		$settings = (array) $metodo->invoke( $modulo );

		foreach ( $settings as $campo ) {
			if ( is_array( $campo ) && ( $campo['id'] ?? '' ) === 'akibara_fsp_threshold' ) {
				return (string) ( $campo['desc'] ?? '' );
			}
		}

		self::fail( 'No se encontró el campo del umbral.' );
	}

	/**
	 * @testdox La ayuda del piso nombra el umbral del transportista que hay configurado.
	 */
	public function test_the_help_names_the_live_carrier_threshold(): void {
		$ayuda = $this->ayuda_del_piso();

		self::assertStringContainsString(
			'55.000',
			$ayuda,
			'Sin el número vivo, el dueño no puede saber si el valor que escribe hará algo.'
		);
	}

	/**
	 * @testdox Y dice explícitamente que un valor menor no cambia nada.
	 */
	public function test_the_help_says_a_lower_value_does_nothing(): void {
		$ayuda = $this->ayuda_del_piso();

		self::assertMatchesRegularExpression(
			'/(menor|por debajo|inferior)/i',
			$ayuda,
			'Es justo el caso en que el campo parece muerto: hay que nombrarlo.'
		);
	}

	/**
	 * El número tiene que seguir al de verdad, no quedarse clavado.
	 *
	 * @testdox Si cambia el umbral del transportista, la ayuda cambia con él.
	 */
	public function test_the_number_follows_the_real_setting(): void {
		$this->opciones['akibara_fsp_carrier_threshold'] = '72000';

		self::assertStringContainsString( '72.000', $this->ayuda_del_piso() );
	}

	/**
	 * @testdox Sin umbral de transportista configurado, la ayuda no inventa un número.
	 */
	public function test_without_a_carrier_threshold_it_does_not_invent_one(): void {
		$this->opciones['akibara_fsp_carrier_threshold'] = '0';

		$ayuda = $this->ayuda_del_piso();

		self::assertStringNotContainsString( '$0', $ayuda, 'Un «$0» en la ayuda no informa: confunde.' );
	}
}
