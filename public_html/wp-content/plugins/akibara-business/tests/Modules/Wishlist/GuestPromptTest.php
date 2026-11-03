<?php
/**
 * Corazón de un invitado: aviso en la página en vez de salir de ella (UM-9).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\Wishlist;

use Akibara\Business\Modules\CopyManager\CopyManager;
use Akibara\Business\Modules\Wishlist\GuestPrompt;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class GuestPromptTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();
		Functions\when( 'get_option' )->alias( fn( string $k, $default = false ) => $this->options[ $k ] ?? $default );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ): string => strip_tags( (string) $v ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Apagado de fábrica: sin la fila, o con cualquier cosa que no sea «yes», el corazón navega como siempre.
	 */
	public function test_off_unless_explicitly_on(): void {
		// Es decisión del dueño (captación frente a fricción): el comportamiento
		// de fábrica es el de hoy, la navegación directa (revisión B5-R2).
		self::assertFalse( GuestPrompt::enabled(), 'Sin fila: navegación directa, como siempre.' );
		foreach ( array( 'no', '', '1', 'on', 'YES', 'true' ) as $raro ) {
			$this->options[ GuestPrompt::OPTION_ENABLED ] = $raro;
			self::assertFalse( GuestPrompt::enabled(), sprintf( '«%s» no enciende: el lector no acepta lo que la casilla no escribe.', $raro ) );
		}
		$this->options[ GuestPrompt::OPTION_ENABLED ] = 'yes';
		self::assertTrue( GuestPrompt::enabled(), 'La casilla encendida guarda «yes».' );
	}

	/**
	 * @testdox La casilla del panel nace apagada, igual que el lector sin fila.
	 */
	public function test_panel_checkbox_defaults_to_off(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/CopyManager/CopyManager.php' );
		self::assertMatchesRegularExpression(
			"/'id'\s*=> GuestPrompt::OPTION_ENABLED,\s*'type'\s*=> 'checkbox',\s*'default' => 'no',/",
			$fuente,
			'Si la casilla naciera marcada, el primer guardado de Textos encendería el aviso sin que el dueño lo eligiera.'
		);
	}

	/**
	 * @testdox Los textos caen a su literal si se guardan vacíos, y llevan el marcador del tomo.
	 */
	public function test_labels_fall_back_to_defaults(): void {
		$this->options[ GuestPrompt::OPTION_TITLE ] = '   ';
		$this->options[ GuestPrompt::OPTION_CTA ]   = "Entrar\n<b>ya</b>";

		$labels = GuestPrompt::labels();

		self::assertSame( 'Inicia sesión para guardar tus favoritos', $labels['guestTitle'] );
		self::assertSame( GuestPrompt::DEFAULT_BODY, $labels['guestBody'] );
		self::assertStringContainsString( GuestPrompt::PRODUCT_MARKER, $labels['guestBody'] );
		self::assertSame( 'Entrar ya', $labels['guestCta'], 'Una línea, sin etiquetas.' );
		self::assertSame( 'Seguir mirando', $labels['guestDismiss'] );
		self::assertSame( '{producto}', $labels['guestMarker'] );
	}

	/**
	 * @testdox Los cuatro textos son copys obligatorios del panel: vaciarlos no deja el aviso mudo.
	 */
	public function test_copies_are_registered_in_the_panel(): void {
		$metodo = new ReflectionMethod( CopyManager::class, 'required_one_line_copies' );
		$metodo->setAccessible( true );
		$copys = (array) $metodo->invoke( null );

		foreach ( GuestPrompt::defaults() as $option => $literal ) {
			self::assertArrayHasKey( $option, $copys );
			self::assertSame( $literal, $copys[ $option ] );
		}

		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/CopyManager/CopyManager.php' );
		foreach ( array( 'OPTION_ENABLED', 'OPTION_TITLE', 'OPTION_BODY', 'OPTION_CTA', 'OPTION_DISMISS' ) as $constante ) {
			self::assertStringContainsString( "'id'      => GuestPrompt::{$constante},", $fuente, "{$constante} tiene su campo en Akibara → Textos." );
		}
	}

	/**
	 * @testdox wishlist.js recibe el interruptor y los textos; el corazón del invitado anuncia el diálogo.
	 */
	public function test_wiring_to_the_front(): void {
		$wishlist = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Wishlist.php' );
		$button   = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/Wishlist/Button.php' );
		$js       = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/wishlist.js' );

		self::assertStringContainsString( "'guestPrompt' => GuestPrompt::enabled(),", $wishlist );
		self::assertStringContainsString( ') + GuestPrompt::labels(),', $wishlist );
		self::assertStringContainsString( "GuestPrompt::enabled() ? ' aria-haspopup=\"dialog\"' : ''", $button );
		self::assertStringContainsString( 'if (!cfg.isLogged) {' . "\n\t\t\tpromptGuest(productId, btn);", $js, 'El invitado ya no navega en el primer toque.' );
		self::assertStringContainsString( '!!cfg.guestPrompt', $js, 'Apagado en el panel, vuelve a navegar.' );
		self::assertStringContainsString( 'body.split(marker).join(', $js, 'El copy del dueño se completa por marcador, nunca como formato.' );
	}
}
