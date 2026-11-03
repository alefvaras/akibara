<?php
/**
 * La rotación de la barra se manda desde el panel, como la del Slider.
 *
 * El manual del dueño lo anotó como «la barra rota sola y el Slider no:
 * criterios opuestos». No eran criterios opuestos: el Slider tenía su casilla
 * de «rotar solo» y sus segundos, y la barra llevaba los 6 segundos escritos en
 * el JS, sin forma de apagarlos.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\AnnouncementBar;

use Akibara\Business\Modules\AnnouncementBar\AnnouncementBar;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AnnouncementBarRotacionTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias(
			fn( string $k, $default_value = false ) => $this->options[ $k ] ?? $default_value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @testdox De fábrica rota cada 6 segundos: lo que ya hacía. */
	public function test_los_valores_de_fabrica_son_los_de_antes(): void {
		self::assertTrue( AnnouncementBar::rotates() );
		self::assertSame( 6, AnnouncementBar::rotate_seconds() );
	}

	/** @testdox Apagarla desde el panel la detiene. */
	public function test_el_interruptor_la_detiene(): void {
		$this->options['akibara_announcement_rotate'] = 'no';

		self::assertFalse( AnnouncementBar::rotates() );
	}

	/** @testdox Los segundos se acotan: ni ilegible ni eterno. */
	public function test_los_segundos_se_acotan(): void {
		$this->options['akibara_announcement_rotate_secs'] = 1;
		self::assertSame( 3, AnnouncementBar::rotate_seconds(), 'Menos de 3 s no da tiempo a leer.' );

		$this->options['akibara_announcement_rotate_secs'] = 900;
		self::assertSame( 30, AnnouncementBar::rotate_seconds(), 'Más de 30 s y el segundo mensaje no se ve nunca.' );

		$this->options['akibara_announcement_rotate_secs'] = 0;
		self::assertSame( 6, AnnouncementBar::rotate_seconds(), 'Un campo vaciado vuelve al de fábrica, no a 3.' );

		$this->options['akibara_announcement_rotate_secs'] = 12;
		self::assertSame( 12, AnnouncementBar::rotate_seconds() );
	}

	/** @testdox El JS lee el ritmo del marcado, no lo lleva escrito. */
	public function test_el_js_no_escribe_el_ritmo(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/announcement-bar.js' );

		self::assertStringContainsString( "bar.getAttribute('data-rotate-ms')", $js );
		self::assertStringContainsString( "'0' !== bar.getAttribute('data-rotate')", $js );
		self::assertDoesNotMatchRegularExpression(
			'/var ROTATE_MS = 6000;/',
			$js,
			'El 6000 fijo era justo el defecto: el dueño no podía cambiarlo.'
		);
	}
}
