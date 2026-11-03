<?php
/**
 * El descuento por editorial NO alcanza a las preventas.
 *
 * Éste era el único punto de la tienda donde «a pedido» y «preventa» eran lo
 * mismo: el badge, la faceta, la ruta, la copia y el CTA sí los distinguen, pero
 * `is_stock_eligible()` decidía por `_stock_status`, donde los dos valen
 * `onbackorder`.
 *
 * Y lo peor no era el alcance: era el retardo. Con cero preventas en catálogo no
 * hacía nada, y el día que alguien pusiera la primera fecha de lanzamiento, ese
 * tomo habría entrado al descuento SOLO, con la configuración intacta y sin un
 * aviso. Una preventa es dinero cobrado por un tomo que aún no existe.
 *
 * DECISIÓN DEL DUEÑO, 2026-09-05: las preventas quedan fuera de «Disponibles +
 * a pedido», y «si pasa la fecha debería llegar a pedido» — o sea, entra al
 * descuento sola cuando deja de ser preventa.
 *
 * Los tres casos son los que QA sembró en el espejo para poder probar esta rama.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\TaxonomyDiscount;

use Akibara\Business\Modules\Stock\Availability;
use Akibara\Business\Modules\TaxonomyDiscount\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class PreventaOutOfDiscountTest extends TestCase {

	/** @var array<int, array<string, string>> post_id => meta */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta = array();

		Functions\when( 'get_post_meta' )->alias(
			fn( int $id, string $key, bool $single = false ) => $this->meta[ $id ][ $key ] ?? ''
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Santiago' ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param int    $id     Id del producto.
	 * @param string $estado `_stock_status` crudo de WooCommerce.
	 */
	private function producto( int $id, string $estado ): \WC_Product {
		return new class( $id, $estado ) extends \WC_Product {
			private int $pid;
			private string $estado;

			public function __construct( int $id, string $estado ) {
				$this->pid    = $id;
				$this->estado = $estado;
			}

			public function get_id(): int {
				return $this->pid;
			}

			public function get_stock_status(): string {
				return $this->estado;
			}
		};
	}

	private function settings_con( string $alcance ): Settings {
		$GLOBALS['akibara_test_options'] = array( Settings::OPTION_APPLY_TO => $alcance );

		return new Settings();
	}

	/**
	 * @testdox Una preventa con fecha futura NO entra en «Disponibles + a pedido».
	 */
	public function test_a_preorder_is_left_out_of_the_discount(): void {
		$manana = ( new \DateTimeImmutable( '+30 days', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m-d' );
		$this->meta[12945] = array(
			Availability::META_TYPE         => Availability::TYPE_RELEASE_DATE,
			Availability::META_RELEASE_DATE => $manana,
		);
		$producto = $this->producto( 12945, 'onbackorder' );

		self::assertSame( Availability::PREVENTA, Availability::of( $producto ), 'Precondición: es una preventa.' );
		self::assertFalse(
			$this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ),
			'Una preventa es dinero cobrado por un tomo que aún no existe: descontarlo regala margen sobre una venta sin servir.'
		);
	}

	/**
	 * @testdox Una preventa «sin fecha» (TBA) tampoco entra.
	 */
	public function test_a_tba_preorder_is_left_out_too(): void {
		$this->meta[12959] = array( Availability::META_TYPE => Availability::TYPE_TBA );
		$producto          = $this->producto( 12959, 'onbackorder' );

		self::assertSame( Availability::PREVENTA, Availability::of( $producto ) );
		self::assertFalse( $this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ) );
	}

	/**
	 * Lo que el dueño pidió con estas palabras: «si pasa la fecha debería llegar
	 * a pedido».
	 *
	 * @testdox Cuando la fecha de lanzamiento pasa, el tomo entra al descuento solo.
	 */
	public function test_once_the_launch_date_passes_it_joins_the_discount(): void {
		$ayer              = ( new \DateTimeImmutable( '-10 days', new \DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m-d' );
		$this->meta[13079] = array(
			Availability::META_TYPE         => Availability::TYPE_RELEASE_DATE,
			Availability::META_RELEASE_DATE => $ayer,
		);
		$producto = $this->producto( 13079, 'onbackorder' );

		self::assertSame(
			Availability::A_PEDIDO,
			Availability::of( $producto ),
			'El tomo ya salió: deja de ser preventa por sí solo.'
		);
		self::assertTrue(
			$this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ),
			'Ya es un «a pedido» normal y hereda el descuento como cualquier otro.'
		);
	}

	/**
	 * @testdox Un «a pedido» de siempre sigue entrando, que es el caso de los 398.
	 */
	public function test_a_plain_backorder_still_qualifies(): void {
		$producto = $this->producto( 555, 'onbackorder' );

		self::assertSame( Availability::A_PEDIDO, Availability::of( $producto ) );
		self::assertTrue( $this->settings_con( Settings::APPLY_INSTOCK_AND_ONBACKORDER )->is_stock_eligible( $producto ) );
	}

	/**
	 * @testdox «Solo disponibles» sigue dejando fuera todo lo que no está en bodega.
	 */
	public function test_in_stock_only_excludes_everything_else(): void {
		$this->meta[12945] = array( Availability::META_TYPE => Availability::TYPE_TBA );
		$ajustes           = $this->settings_con( Settings::APPLY_INSTOCK_ONLY );

		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 1, 'instock' ) ) );
		self::assertFalse( $ajustes->is_stock_eligible( $this->producto( 12945, 'onbackorder' ) ) );
		self::assertFalse( $ajustes->is_stock_eligible( $this->producto( 2, 'outofstock' ) ) );
	}

	/**
	 * @testdox «Todos» sigue alcanzando incluso a las preventas y a los agotados.
	 */
	public function test_all_still_means_all(): void {
		$this->meta[12945] = array( Availability::META_TYPE => Availability::TYPE_TBA );
		$ajustes           = $this->settings_con( Settings::APPLY_ALL );

		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 12945, 'onbackorder' ) ) );
		self::assertTrue( $ajustes->is_stock_eligible( $this->producto( 2, 'outofstock' ) ) );
	}
}
