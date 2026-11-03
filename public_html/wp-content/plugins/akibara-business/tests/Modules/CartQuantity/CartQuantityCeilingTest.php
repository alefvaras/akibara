<?php
/**
 * El techo de cantidad del Store API, llevado a la ficha, al añadir clásico y al cajón.
 *
 * R6-cantidad-sin-techo (2026-09-19): en la ficha el campo cantidad no tenía
 * `max`, 999999 unidades entraron por `?wc-ajax=add_to_cart`, el cajón y el
 * ícono mostraron 999.999 y $9.989.990.010, y /carrito/ las recortó a 9.999
 * sin decir nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\CartQuantity;

use Akibara\Business\Modules\CartQuantity\CartQuantityCeiling;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class CartQuantityCeilingTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> Avisos (texto, tipo). */
	private array $avisos = array();

	/** @var array<int, object> Productos por id. */
	private array $productos = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->avisos    = array();
		$this->productos = array();

		Functions\when( 'wc_get_product' )->alias( fn ( int $id ) => $this->productos[ $id ] ?? false );
		Functions\when( 'wc_add_notice' )->alias(
			function ( string $message, string $type = 'success' ): void {
				$this->avisos[] = array( $message, $type );
			}
		);
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n ) => number_format( (float) $n, 0, ',', '.' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param bool   $managed      ¿Gestiona stock sin reservas? (tope propio)
	 * @param string $name         Nombre.
	 * @param bool   $individually ¿De uno en uno?
	 * @return \WC_Product
	 */
	private function producto( bool $managed, string $name = 'Vinland Saga 01', bool $individually = false ): \WC_Product {
		return new class( $managed, $name, $individually ) extends \WC_Product {
			/**
			 * @param bool   $managed      Stock gestionado.
			 * @param string $name         Nombre.
			 * @param bool   $individually De uno en uno.
			 */
			public function __construct( private bool $managed, private string $name, private bool $individually ) {
				parent::__construct();
			}

			public function managing_stock(): bool {
				return $this->managed;
			}

			public function backorders_allowed(): bool {
				return false;
			}

			public function is_sold_individually(): bool {
				return $this->individually;
			}

			public function get_name(): string {
				return $this->name;
			}
		};
	}

	/**
	 * @testdox El contrato de tres valores se conserva: 0 es 0, N es N, y el -1 pasa a ser el techo de WooCommerce.
	 * @dataProvider topes
	 * @param int $max_purchase Entrada.
	 * @param int $esperado     Salida.
	 */
	public function test_maximo_para_traduce_el_contrato_de_tres_valores( int $max_purchase, int $esperado ): void {
		self::assertSame( $esperado, CartQuantityCeiling::maximo_para( $max_purchase ) );
		self::assertSame( $esperado, ( new CartQuantityCeiling() )->cap_input_max( $max_purchase, null ) );
	}

	/** @return array<string, array{0: int, 1: int}> */
	public static function topes(): array {
		return array(
			'sin límite (-1) → techo'   => array( -1, 9999 ),
			'nada comprable (0) → 0'    => array( 0, 0 ),
			'tope propio (3) → 3'       => array( 3, 3 ),
			'tope propio en el techo'   => array( 9999, 9999 ),
			'tope propio absurdo → techo' => array( 20000, 9999 ),
		);
	}

	/** @testdox 999.999 unidades de un tomo sin stock gestionado se rechazan con un aviso que dice el techo y el tomo. */
	public function test_una_cantidad_por_encima_del_techo_se_rechaza_con_aviso(): void {
		$this->productos[42] = $this->producto( false );

		$passed = ( new CartQuantityCeiling() )->validate_add_to_cart( true, 42, 999999 );

		self::assertFalse( $passed );
		self::assertSame( array( array( 'Puedes llevar hasta 9.999 unidades de «Vinland Saga 01» por pedido.', 'error' ) ), $this->avisos );
	}

	/** @testdox [NEG] 9.999 unidades pasan; y una variación se mira por su propio id. */
	public function test_el_techo_exacto_pasa_y_la_variacion_se_resuelve(): void {
		$this->productos[42] = $this->producto( false );
		$this->productos[43] = $this->producto( false, 'Variación' );

		self::assertTrue( ( new CartQuantityCeiling() )->validate_add_to_cart( true, 42, 9999 ) );
		self::assertFalse( ( new CartQuantityCeiling() )->validate_add_to_cart( true, 42, 10000, 43 ) );
		self::assertStringContainsString( '«Variación»', $this->avisos[0][0] );
	}

	/**
	 * Un tomo con stock gestionado ya lo valida WooCommerce («No hay
	 * suficiente stock»), y uno «de uno en uno» también: meter aquí otro
	 * mensaje sería pisar el suyo. Y no se decide por
	 * `get_max_purchase_quantity()`, que en el espejo devolvió 9.999 para un
	 * tomo sin stock gestionado y dejaba pasar 999.999.
	 *
	 * @testdox [NEG] Con tope propio (stock gestionado o de uno en uno) no se opina: WooCommerce ya valida con su mensaje.
	 */
	public function test_con_tope_propio_no_se_opina(): void {
		$this->productos[42] = $this->producto( true );
		$this->productos[43] = $this->producto( false, 'Único', true );

		self::assertTrue( ( new CartQuantityCeiling() )->validate_add_to_cart( true, 42, 999999 ) );
		self::assertTrue( ( new CartQuantityCeiling() )->validate_add_to_cart( true, 43, 999999 ) );
		self::assertSame( array(), $this->avisos );
	}

	/** @testdox [NEG] Una validación anterior en falso se respeta, y un producto inexistente no rompe nada. */
	public function test_respeta_lo_anterior_y_no_rompe_sin_producto(): void {
		$modulo = new CartQuantityCeiling();

		self::assertFalse( $modulo->validate_add_to_cart( false, 42, 1 ) );
		self::assertTrue( $modulo->validate_add_to_cart( true, 999, 999999 ) );
		self::assertSame( array(), $this->avisos );
	}

	/** @testdox register() engancha el `max` del campo cantidad y la validación del añadir clásico. */
	public function test_register_engancha_los_filtros(): void {
		$modulo = new CartQuantityCeiling();

		Monkey\Filters\expectAdded( 'woocommerce_quantity_input_max' )->once()->with( array( $modulo, 'cap_input_max' ), 10, 2 );
		Monkey\Filters\expectAdded( 'woocommerce_add_to_cart_validation' )->once()->with( array( $modulo, 'validate_add_to_cart' ), 10, 4 );

		$modulo->register();
		$this->addToAssertionCount( 2 );
	}

	/**
	 * El cajón tiene que pasar por la misma traducción, en el payload y en el
	 * clamp del servidor: si no, 999999 sigue entrando por `akb_update_cart_item`.
	 *
	 * @testdox El cajón nativo traduce el tope con maximo_para() en el payload y en el clamp, y el módulo queda cableado.
	 */
	public function test_el_cajon_y_el_plugin_usan_el_mismo_techo(): void {
		$cajon  = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/NativeCart/NativeCart.php' );
		$plugin = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Plugin.php' );

		self::assertSame( 2, substr_count( $cajon, 'CartQuantityCeiling::maximo_para(' ), 'payload `max` y clamp de `akb_update_cart_item`' );
		self::assertSame( 1, substr_count( $plugin, "['cart_quantity_ceiling'] = new CartQuantityCeiling()" ) );
		self::assertSame( 1, substr_count( $plugin, "['cart_quantity_ceiling']->register()" ) );
	}
}
