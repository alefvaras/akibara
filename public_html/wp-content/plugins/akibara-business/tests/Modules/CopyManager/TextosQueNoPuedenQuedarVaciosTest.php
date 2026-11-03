<?php
/**
 * Ningún cartel de la tienda puede publicarse vacío.
 *
 * Hallazgo S-6492daca: once textos —los ocho carteles de fecha y las tres
 * promesas de la ficha— no tenían saneador. Borrar su contenido en Textos y
 * guardar dejaba la option en '' y el cliente veía una etiqueta vacía, porque
 * el segundo argumento de `get_option()` sólo cubre la option inexistente.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CopyManager;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\CopyManager\CopyManager;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class TextosQueNoPuedenQuedarVaciosTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $v ): string => (string) preg_replace( '/<[^>]*>/', '', (string) $v )
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $v ): string => trim( (string) preg_replace( '/\s+/', ' ', (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Los carteles obligatorios vuelven a su texto de fábrica si se guardan vacíos.
	 * @dataProvider carteles
	 */
	public function test_un_cartel_vacio_vuelve_al_de_fabrica( string $option, string $fabrica ): void {
		$copy = new CopyManager( new AdminPanel() );

		self::assertSame(
			$fabrica,
			$copy->sanitize_required_copy( '', array( 'id' => $option ), '' ),
			sprintf( '«%s» se publicaría vacío.', $option )
		);
		self::assertSame(
			$fabrica,
			$copy->sanitize_required_copy( '   ', array( 'id' => $option ), '   ' ),
			'Sólo espacios es lo mismo que vacío.'
		);
	}

	/** @return array<int, array{0:string,1:string}> */
	public static function carteles(): array {
		return array(
			array( 'akb_copy_eta_cart_ready', 'Tu pedido completo llega' ),
			array( 'akb_copy_eta_cart_preventa', 'Tu pedido incluye reservas' ),
			array( 'akb_copy_eta_tba', 'Te avisamos en cuanto tengamos fecha' ),
			array( 'akb_copy_eta_single_preventa', 'Preventa' ),
			array( 'akb_copy_eta_single_pedido', 'A pedido' ),
			array( 'akb_copy_eta_single_ready', 'Fecha estimada de llegada' ),
			array( 'akb_copy_eta_noun_preventa', 'preventa' ),
			array( 'akb_copy_eta_noun_pedido', 'productos a pedido' ),
			array( 'akb_copy_eta_item_arrived', 'Ya llegó a nuestra bodega' ),
			array( 'akb_copy_promise_dispatch', 'Despacha en 24–48 h' ),
			array( 'akb_copy_promise_preventa_transit', 'En camino a Akibara' ),
			array( 'akb_copy_promise_pedido_transit', 'Lo pedimos a la editorial por ti' ),
			array( 'akb_copy_series_summary_link', 'Ver ficha completa' ),
			array( 'akb_copy_series_latest_volume', 'Último tomo: {tomo}' ),
			array( 'akb_copy_series_offer_hook', 'Hasta {pct}% de descuento' ),
			array( 'akb_copy_series_offer_rules', 'Cómo funciona' ),
		);
	}

	/** @testdox Lo que el dueño sí escribe se respeta tal cual. */
	public function test_lo_escrito_se_respeta(): void {
		$copy = new CopyManager( new AdminPanel() );

		self::assertSame(
			'Llega en 3 días',
			$copy->sanitize_required_copy( 'Llega en 3 días', array( 'id' => 'akb_copy_eta_single_ready' ), 'Llega en 3 días' )
		);
	}

	/** @testdox Los textos que SÍ pueden apagarse siguen aceptando el vacío. */
	public function test_los_apagables_siguen_apagandose(): void {
		$copy = new CopyManager( new AdminPanel() );

		self::assertSame( '', $copy->sanitize_optional_copy( '', array( 'id' => 'akb_copy_footer_phone' ), '' ) );
	}
}
