<?php
/**
 * Una option de copy vaciada FUERA del panel (`wp option update`, un import,
 * cualquier escritura directa a `wp_options`) no debe dejar el banner en
 * blanco.
 *
 * `sanitize_required_copy()` (CopyManager.php) sólo corre cuando el dueño
 * guarda el formulario de Akibara → Textos. `get_option($id, $default)` a
 * pelo sólo aplica el default cuando la fila NO EXISTE, nunca cuando existe
 * con valor ''. Estas cuatro copies de `BannerRenderer` se leían así, a
 * diferencia de `Availability::palabra()` (para sus dos primas del mismo
 * grupo), que ya revalida al leer. Con la fila vacía en la base, el cliente
 * veía el banner vacío en vez del texto de fábrica (2026-09-19, R2-2f374da1,
 * reabre S-6492daca por la puerta que su arreglo no cerró).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\EtaResult;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BannerRendererEmptyCopiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// `BannerRenderer::current_year_local()` llama a `StoreTime::zone()` →
		// `wp_timezone()` directo, fuera del `DateCalculator` inyectable: sin
		// este stub, Patchwork exige el mock en cuanto otro test de la suite
		// ya usó Brain Monkey sobre esa función antes que éste.
		Functions\when( 'wp_timezone' )->alias(
			static fn(): \DateTimeZone => new \DateTimeZone( 'America/Santiago' )
		);

		// Fila EXISTENTE y vacía, no ausente: es el vector real (`wp option
		// update`/import), y es distinto de "sin fila" — que sí cae al
		// default incluso con el `get_option()` crudo que este ticket corrige.
		$GLOBALS['akibara_test_options'] = array(
			'akb_copy_eta_tba'            => '',
			'akb_copy_eta_cart_ready'     => '',
			'akb_copy_eta_single_ready'   => '',
			'akb_copy_eta_cart_preventa'  => '',
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function renderer(): BannerRenderer {
		return new BannerRenderer( new DateCalculator( new Settings(), null, new \DateTimeZone( 'America/Santiago' ) ) );
	}

	/** Invoca `format_inline()` (privado) por reflexión. */
	private function format_inline( EtaResult $eta ): string {
		$metodo = new ReflectionMethod( BannerRenderer::class, 'format_inline' );
		$metodo->setAccessible( true );

		return (string) $metodo->invoke( $this->renderer(), $eta );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function labels_for( string $context, EtaResult $eta ): array {
		$metodo = new ReflectionMethod( BannerRenderer::class, 'labels_for' );
		$metodo->setAccessible( true );

		return (array) $metodo->invoke( $this->renderer(), $context, $eta, null );
	}

	/** @testdox format_inline(): TBA sin lead cae al literal de fábrica, no a ''. */
	public function test_format_inline_tba_falls_back_to_the_factory_copy(): void {
		self::assertSame( 'Te avisamos en cuanto tengamos fecha', $this->format_inline( EtaResult::tba() ) );
	}

	/** @testdox labels_for(): rango de fechas, carrito — cae al literal de fábrica, no a ''. */
	public function test_labels_for_cart_ready_falls_back_to_the_factory_copy(): void {
		$labels = $this->labels_for( 'cart-overall', EtaResult::range( 1000, 2000 ) );

		self::assertSame( 'Tu pedido completo llega', $labels['label'] );
	}

	/** @testdox labels_for(): rango de fechas, single — cae al literal de fábrica, no a ''. */
	public function test_labels_for_single_ready_falls_back_to_the_factory_copy(): void {
		$labels = $this->labels_for( 'single', EtaResult::range( 1000, 2000 ) );

		self::assertSame( 'Fecha estimada de llegada', $labels['label'] );
	}

	/** @testdox labels_for(): TBA en el carrito — cae al literal de fábrica, no a ''. */
	public function test_labels_for_cart_preventa_falls_back_to_the_factory_copy(): void {
		$labels = $this->labels_for( 'cart-overall', EtaResult::tba() );

		self::assertSame( 'Tu pedido incluye reservas', $labels['label'] );
		self::assertSame( 'Te avisamos en cuanto tengamos fecha', $labels['value'] );
	}
}
