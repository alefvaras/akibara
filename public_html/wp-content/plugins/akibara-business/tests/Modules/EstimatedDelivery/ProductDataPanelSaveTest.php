<?php
/**
 * Contrato de guardado del panel «Fecha de llegada».
 *
 * El módulo no tenía ni una prueba y perdía datos del dueño en el flujo más
 * común que existe: guardar un producto.
 *
 * Cuando el producto estaba en stock, el panel pintaba sus controles con el
 * atributo `disabled`. Un control deshabilitado NO se envía con el formulario,
 * así que el POST llegaba sin `_akb_eta_type` ni `_akb_eta_release_date`;
 * `sanitize_post()` leía eso como «auto, sin fecha» y `save_product_meta()`
 * sobrescribía el tipo y borraba la fecha de lanzamiento. El borrado además
 * disparaba `akb_eta_release_date_changed` con fecha nueva vacía, y
 * `ReleaseDateChangeListener` avisaba por correo a los clientes con preventas
 * vivas de un cambio de fecha que nadie había hecho.
 *
 * Bastaba con corregirle el precio a un producto ya lanzado.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\ProductDataPanel;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @since 1.108.19
 */
final class ProductDataPanelSaveTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $meta = array();

	/** @var list<array{0:int,1:string,2:string}> */
	private array $fired = array();

	/** @var array<string,mixed> */
	private array $original_post = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->original_post = $_POST;
		$_POST               = array();
		$this->meta          = array();
		$this->fired         = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( int $id, string $key, $value ): bool {
				$this->meta[ $id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( int $id, string $key ): bool {
				unset( $this->meta[ $id ][ $key ] );
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			function ( string $hook, ...$args ): void {
				if ( 'akb_eta_release_date_changed' === $hook ) {
					$this->fired[] = array( (int) $args[0], (string) $args[1], (string) $args[2] );
				}
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' ) );
		\WC_Admin_Meta_Boxes::$errors = array();
	}

	protected function tearDown(): void {
		$_POST = $this->original_post;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * `save_product_meta()` no toca el calculador; se construye uno real para no
	 * doblar una clase `final` (y para que el test siga valiendo si algún día sí
	 * lo usara).
	 */
	private function panel(): ProductDataPanel {
		return new ProductDataPanel(
			new DateCalculator( new Settings(), static fn(): int => 1795000000, new \DateTimeZone( 'America/Santiago' ) )
		);
	}

	/**
	 * @testdox Un guardado que no incluye el panel deja la configuración intacta.
	 */
	public function test_a_save_without_the_panel_leaves_the_configuration_alone(): void {
		$this->meta[10] = array(
			DateCalculator::META_TYPE         => 'release_date',
			DateCalculator::META_RELEASE_DATE => '2026-11-04',
		);

		$_POST = array( 'post_title' => 'Naruto 72' );
		$this->panel()->save_product_meta( 10 );

		self::assertSame( 'release_date', $this->meta[10][ DateCalculator::META_TYPE ] );
		self::assertSame( '2026-11-04', $this->meta[10][ DateCalculator::META_RELEASE_DATE ] );
	}

	/**
	 * @testdox Ese guardado tampoco avisa a los clientes de un cambio de fecha inexistente.
	 */
	public function test_a_save_without_the_panel_never_notifies_customers(): void {
		$this->meta[10] = array( DateCalculator::META_RELEASE_DATE => '2026-11-04' );

		$_POST = array( 'post_title' => 'Naruto 72' );
		$this->panel()->save_product_meta( 10 );

		self::assertSame( array(), $this->fired, 'Avisar de una fecha «retirada» que nadie retiró es peor que no avisar.' );
	}

	/**
	 * @testdox Con el panel presente, el dueño sí puede cambiar tipo y fecha.
	 */
	public function test_the_panel_still_saves_what_the_owner_typed(): void {
		$this->meta[10] = array( DateCalculator::META_TYPE => 'auto' );

		$_POST = array(
			ProductDataPanel::PANEL_MARKER => '1',
			'_akb_eta_type'                => 'release_date',
			'_akb_eta_release_date'        => '2026-11-04',
		);
		$this->panel()->save_product_meta( 10 );

		self::assertSame( 'release_date', $this->meta[10][ DateCalculator::META_TYPE ] );
		self::assertSame( '2026-11-04', $this->meta[10][ DateCalculator::META_RELEASE_DATE ] );
		self::assertSame( array( array( 10, '', '2026-11-04' ) ), $this->fired );
	}

	/**
	 * @testdox Una preventa con el campo de fecha en blanco queda como preventa SIN fecha (tba), no como «a pedido».
	 */
	public function test_clearing_the_date_keeps_the_tomo_as_a_preventa(): void {
		$this->meta[10] = array(
			DateCalculator::META_TYPE         => 'release_date',
			DateCalculator::META_RELEASE_DATE => '2026-11-04',
		);
		$_POST = array(
			ProductDataPanel::PANEL_MARKER => '1',
			'_akb_eta_type'                => 'release_date',
			'_akb_eta_release_date'        => '',
		);
		$this->panel()->save_product_meta( 10 );
		self::assertSame( 'tba', $this->meta[10][ DateCalculator::META_TYPE ], 'El tomo no ha salido de la editorial: no puede caer a «a pedido».' );
		self::assertArrayNotHasKey( DateCalculator::META_RELEASE_DATE, $this->meta[10] );
		self::assertSame( array( array( 10, '2026-11-04', '' ) ), $this->fired, 'Sigue siendo una retirada de fecha para los pedidos que la esperaban.' );
	}

	/**
	 * @testdox Y sí puede vaciarla a propósito cuando cambia el tipo.
	 */
	public function test_the_owner_can_still_clear_the_date_on_purpose(): void {
		$this->meta[10] = array(
			DateCalculator::META_TYPE         => 'release_date',
			DateCalculator::META_RELEASE_DATE => '2026-11-04',
		);

		$_POST = array(
			ProductDataPanel::PANEL_MARKER => '1',
			'_akb_eta_type'                => 'tba',
		);
		$this->panel()->save_product_meta( 10 );

		self::assertSame( 'tba', $this->meta[10][ DateCalculator::META_TYPE ] );
		self::assertArrayNotHasKey( DateCalculator::META_RELEASE_DATE, $this->meta[10] );
		self::assertSame( array( array( 10, '2026-11-04', '' ) ), $this->fired );
	}

	/**
	 * @testdox Un año con un dígito de más (2206) conserva la fecha anterior y se lo dice al dueño.
	 */
	public function test_an_impossible_year_keeps_the_previous_date_and_tells_the_owner(): void {
		$this->meta[10] = array(
			DateCalculator::META_TYPE         => 'release_date',
			DateCalculator::META_RELEASE_DATE => '2026-11-04',
		);

		$_POST = array(
			ProductDataPanel::PANEL_MARKER => '1',
			'_akb_eta_type'                => 'release_date',
			'_akb_eta_release_date'        => '2206-11-04',
		);
		$this->panel()->save_product_meta( 10 );

		self::assertSame( '2026-11-04', $this->meta[10][ DateCalculator::META_RELEASE_DATE ], 'Antes se borraba en silencio.' );
		self::assertSame( array(), $this->fired, 'Borrarla disparaba «fecha retirada» en todos los pedidos que la esperaban.' );
		self::assertCount( 1, \WC_Admin_Meta_Boxes::$errors );
		self::assertStringContainsString( '2206', \WC_Admin_Meta_Boxes::$errors[0] );
	}

	/**
	 * @testdox sanitize_post() reporta la fecha inválida en vez de convertirla en «sin fecha».
	 */
	public function test_sanitize_post_reports_an_invalid_date_instead_of_erasing_it(): void {
		$invalida = ProductDataPanel::sanitize_post(
			array(
				'_akb_eta_type'         => 'release_date',
				'_akb_eta_release_date' => '2206-11-04',
			),
			'2026-09-04'
		);
		self::assertNull( $invalida['release_date'] );
		self::assertTrue( $invalida['release_date_invalid'] );

		$vacia = ProductDataPanel::sanitize_post( array( '_akb_eta_type' => 'release_date', '_akb_eta_release_date' => '' ), '2026-09-04' );
		self::assertNull( $vacia['release_date'] );
		self::assertFalse( $vacia['release_date_invalid'], 'Vacía a propósito no es inválida.' );

		$valida = ProductDataPanel::sanitize_post( array( '_akb_eta_type' => 'release_date', '_akb_eta_release_date' => ' 2026-11-04 ' ), '2026-09-04' );
		self::assertSame( '2026-11-04', $valida['release_date'], 'Los espacios del pegado no son un error.' );
		self::assertFalse( $valida['release_date_invalid'] );
	}

	/**
	 * @testdox El panel no puede pintar campos que el navegador se niegue a enviar.
	 *
	 * El candado fija la propiedad —todo control cuyo valor se persiste tiene que
	 * viajar en el POST—, no el marcado concreto que hoy la cumple.
	 */
	public function test_the_panel_never_renders_fields_the_browser_would_drop(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/EstimatedDelivery/ProductDataPanel.php'
		);

		$panel_markup = substr(
			$source,
			(int) strpos( $source, '<div id="akb_eda_data"' ),
			(int) strpos( $source, '</div>' ) - (int) strpos( $source, '<div id="akb_eda_data"' )
		);

		self::assertStringNotContainsString( 'disabled', $panel_markup );
		self::assertStringNotContainsString( 'readonly', $panel_markup );
	}
}
