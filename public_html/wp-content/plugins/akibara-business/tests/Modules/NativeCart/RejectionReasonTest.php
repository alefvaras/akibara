<?php
/**
 * UM-3 (auditoría del tema, 2026-09-22): el rechazo de «Añadir al carrito» lleva
 * un CÓDIGO además del texto, para que la ficha y las tarjetas cambien el botón a
 * «Ver carrito» sin clasificar el aviso de WooCommerce (texto libre y
 * traducible).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\NativeCart;

use Akibara\Business\Modules\NativeCart\RejectionReason;
use PHPUnit\Framework\TestCase;

/**
 * Producto con el estado de stock que pida cada caso. Las preguntas son las de
 * `WC_Product`, con su misma regla de `has_enough_stock()`.
 */
final class RejectionReasonProductDouble extends \WC_Product {

	public bool $individual = false;

	public bool $comprable = true;

	public bool $en_stock = true;

	public bool $gestiona = true;

	public bool $admite_reservas = false;

	public int $unidades = 1;

	public function is_sold_individually(): bool {
		return $this->individual;
	}

	public function is_purchasable(): bool {
		return $this->comprable;
	}

	public function is_in_stock(): bool {
		return $this->en_stock;
	}

	public function managing_stock(): bool {
		return $this->gestiona;
	}

	/**
	 * Copia de la regla de WooCommerce (abstract-wc-product.php).
	 *
	 * @param int $cantidad Unidades.
	 */
	public function has_enough_stock( $cantidad ): bool {
		return ! $this->gestiona || $this->admite_reservas || $this->unidades >= $cantidad;
	}
}

final class RejectionReasonTest extends TestCase {

	/**
	 * @param array<string, bool|int> $estado Propiedades del doble.
	 */
	private function producto( array $estado = array() ): RejectionReasonProductDouble {
		$producto = new RejectionReasonProductDouble();
		foreach ( $estado as $clave => $valor ) {
			$producto->{$clave} = $valor;
		}

		return $producto;
	}

	/**
	 * El caso de la grabación de Clarity: un tomo de una unidad que ya está en el
	 * carrito. El cliente machacaba «No se pudo agregar».
	 *
	 * @testdox Un tomo de una unidad que ya está en el carrito se rechaza como «ya en el carrito».
	 */
	public function test_la_ultima_unidad_ya_en_el_carrito(): void {
		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 1 ) ), 1, 1 ) );
		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 3 ) ), 1, 3 ), 'El carrito tiene las tres que hay.' );
	}

	/**
	 * R-I1-2 (revisión del lote I1): con 2 en stock y 1 en el carrito, pedir 2 se
	 * rechaza, pero la segunda unidad SÍ cabe. Con «ya en el carrito» la ficha
	 * pasaba a «Ver carrito» y, aunque el cliente bajara la cantidad a 1, el envío
	 * navegaba al carrito en vez de agregarla (medido en el espejo en
	 * /dan-da-dan-1-ivrea-argentina/: el carrito se quedaba con 1).
	 *
	 * @testdox Si todavía quedan unidades por agregar, un pedido que no alcanza no es «ya en el carrito».
	 */
	public function test_quedan_unidades_por_agregar(): void {
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'unidades' => 2 ) ), 2, 1 ), 'Stock 2, carrito 1, pide 2: la segunda cabe.' );
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'unidades' => 3 ) ), 2, 2 ), 'Stock 3, carrito 2, pide 2: la tercera cabe.' );
		self::assertNotSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 5 ) ), 4, 3 ) );
	}

	/**
	 * Con el carrito lleno no cabe ninguna cantidad, tampoco una que por sí sola
	 * ya superaba el stock: es «ya en el carrito», no «sin stock».
	 *
	 * @testdox Con el carrito lleno, cualquier cantidad es «ya en el carrito».
	 */
	public function test_carrito_lleno_con_cualquier_cantidad(): void {
		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 2 ) ), 3, 2 ) );
		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 2 ) ), 1, 2 ) );
	}

	/**
	 * @testdox Vendido de a uno y ya en el carrito es «ya en el carrito», aunque haya stock.
	 */
	public function test_vendido_de_a_uno(): void {
		$producto = $this->producto( array( 'individual' => true, 'unidades' => 20 ) );

		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $producto, 1, 1 ) );
	}

	/**
	 * @testdox Agotado, o sin unidades para lo pedido con el carrito vacío de ese tomo, es «sin stock».
	 */
	public function test_sin_stock(): void {
		self::assertSame( RejectionReason::SIN_STOCK, RejectionReason::for_product( $this->producto( array( 'en_stock' => false, 'unidades' => 0 ) ), 1, 0 ) );
		self::assertSame( RejectionReason::SIN_STOCK, RejectionReason::for_product( $this->producto( array( 'unidades' => 2 ) ), 3, 0 ) );
	}

	/**
	 * Sin tope propio (a pedido: sin stock gestionado o con reservas) el estado
	 * no explica el rechazo: no se inventa un código.
	 *
	 * @testdox Sin tope de stock, o si el producto no es comprable, no hay código.
	 */
	public function test_sin_tope_no_hay_codigo(): void {
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'gestiona' => false ) ), 5, 5 ) );
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'admite_reservas' => true, 'unidades' => 0 ) ), 1, 3 ) );
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'comprable' => false ) ), 1, 0 ) );
		self::assertSame( '', RejectionReason::for_product( null, 1, 1 ) );
	}

	/**
	 * El código viaja en la MISMA respuesta que el texto (`akb_get_cart` con
	 * `drain_notices=1`) y sólo cuando hubo un motivo que mostrar.
	 *
	 * @testdox El endpoint del carrito devuelve el código junto al motivo drenado.
	 */
	public function test_el_endpoint_devuelve_el_codigo(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );

		self::assertMatchesRegularExpression( '/\$motivo = array\(\) !== \$errores \? \$this->rejection_reason\(\) : \'\';/', $fuente );
		self::assertMatchesRegularExpression( '/\'motivo\'\s*=> \$motivo,/', $fuente );
		self::assertStringContainsString( 'RejectionReason::for_product(', $fuente );
		self::assertStringContainsString( '$producto->get_stock_managed_by_id()', $fuente, 'Las unidades del carrito se cuentan como WooCommerce.' );
	}

	/**
	 * Un intento con cantidad 0 o negativa (lo que llegue por la query) cuenta
	 * como una unidad, igual que el alta de WooCommerce.
	 *
	 * @testdox Una cantidad rara cuenta como una unidad.
	 */
	public function test_cantidad_rara(): void {
		self::assertSame( RejectionReason::YA_EN_CARRITO, RejectionReason::for_product( $this->producto( array( 'unidades' => 1 ) ), 0, 1 ) );
		self::assertSame( '', RejectionReason::for_product( $this->producto( array( 'unidades' => 1 ) ), -4, 0 ) );
	}
}
