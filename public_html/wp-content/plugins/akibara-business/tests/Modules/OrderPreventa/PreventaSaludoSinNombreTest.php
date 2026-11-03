<?php
/**
 * El saludo de los correos de preventa cuando el pedido no trae nombre de
 * facturación.
 *
 * Un pedido creado a mano desde wp-admin «Añadir pedido» no exige
 * `billing_first_name`, y las 4 plantillas (HTML/plain de
 * `customer-preventa-ready.php` y `customer-preventa-reminder.php`)
 * interpolaban ese campo directo en `printf('Hola %s,', …)` sin resguardo: el
 * cliente recibía «Hola ,», una coma huérfana al inicio del correo
 * (revisión independiente, 2026-09-19, ficha R4-da94be99).
 *
 * El arreglo centraliza la regla en `EmailBrand::saludo()` — el mismo patrón
 * de repuesto neutro que ya usaba `akibara-rewards\Points\Emails` — y las
 * cuatro plantillas lo usan en vez de interpolar el campo por su cuenta.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\EmailBrand\EmailBrand;
use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class PreventaSaludoSinNombreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'esc_html' )->alias(
			static fn( $valor ) => htmlspecialchars( (string) $valor, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );
		Functions\when( 'wp_date' )->alias( static fn( string $formato, int $ts ): string => gmdate( 'j \\d\\e n \\d\\e Y', $ts ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Doble mínimo: el nombre de facturación es el único dato que varía en esta prueba. */
	private function pedido( string $nombre ): WC_Order {
		return new class( $nombre ) extends WC_Order {
			private string $nombre;

			public function __construct( string $nombre ) {
				$this->nombre = $nombre;
			}

			public function get_billing_first_name() {
				return $this->nombre;
			}

			public function get_customer_id() {
				return 5;
			}

			public function get_view_order_url() {
				return 'https://akibara.test/mi-cuenta/view-order/1/';
			}

			public function get_checkout_order_received_url() {
				return 'https://akibara.test/checkout/order-received/1/?key=wc_order_qa';
			}
		};
	}

	private function render( WC_Order $order, string $variante, string $plantilla ): string {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 7;
		$item->akb_name     = 'Frieren 06';
		$item->akb_quantity = 1;
		$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$vars = array(
			'order'               => $order,
			'preventa_items'      => array( $item ),
			'is_local_pickup'     => false,
			'mode'                => 't7',
			'summary_max_ymd'     => '2026-12-14',
			'item_max_ymd'        => '2026-12-14',
			'date_change_ctx'     => array(
				'product_id'   => 0,
				'product_name' => '',
				'old_ymd'      => '',
				'new_ymd'      => '',
			),
			'bought_without_date' => false,
			'email_heading'       => 'Tu pedido ya está listo',
			'additional_content'  => '',
			'sent_to_admin'       => false,
			'plain_text'          => 'plain' === $variante,
			'email'               => new \stdClass(),
		);

		$ruta = AKIBARA_BUSINESS_DIR . 'templates/emails/'
			. ( 'plain' === $variante ? 'plain/' : '' )
			. $plantilla;

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- contrato del template.
		extract( $vars );
		unset( $vars );
		ob_start();
		require $ruta;
		return (string) ob_get_clean();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function plantillas(): array {
		return array(
			'ready HTML'     => array( 'html', 'customer-preventa-ready.php' ),
			'ready plain'    => array( 'plain', 'customer-preventa-ready.php' ),
			'reminder HTML'  => array( 'html', 'customer-preventa-reminder.php' ),
			'reminder plain' => array( 'plain', 'customer-preventa-reminder.php' ),
		);
	}

	/**
	 * @dataProvider plantillas
	 * @testdox Sin nombre de facturación, $plantilla saluda «Hola,» y no deja la coma huérfana.
	 */
	public function test_sin_nombre_el_saludo_es_neutro( string $variante, string $plantilla ): void {
		$salida = $this->render( $this->pedido( '' ), $variante, $plantilla );

		self::assertStringContainsString( 'Hola,', $salida, 'Sin nombre el saludo debe caer al genérico.' );
		self::assertStringNotContainsString( 'Hola ,', $salida, 'Coma huérfana: el nombre vacío se coló en el printf.' );
	}

	/**
	 * @dataProvider plantillas
	 * @testdox Un nombre sólo de espacios también cae al saludo neutro en $plantilla.
	 */
	public function test_nombre_solo_espacios_tambien_cae_al_neutro( string $variante, string $plantilla ): void {
		$salida = $this->render( $this->pedido( '   ' ), $variante, $plantilla );

		self::assertStringContainsString( 'Hola,', $salida );
		self::assertStringNotContainsString( 'Hola ,', $salida );
		self::assertStringNotContainsString( 'Hola    ,', $salida );
	}

	/**
	 * @dataProvider plantillas
	 * @testdox Con nombre, $plantilla sigue saludando por él.
	 */
	public function test_con_nombre_saluda_por_el_nombre( string $variante, string $plantilla ): void {
		$salida = $this->render( $this->pedido( 'Ana' ), $variante, $plantilla );

		self::assertStringContainsString( 'Hola Ana,', $salida );
	}

	/** @testdox El helper: sin nombre devuelve 'Hola,'; con nombre, 'Hola NOMBRE,'; y hace trim(). */
	public function test_el_helper_devuelve_el_saludo_correcto(): void {
		self::assertSame( 'Hola,', EmailBrand::saludo( $this->pedido( '' ) ) );
		self::assertSame( 'Hola,', EmailBrand::saludo( $this->pedido( '   ' ) ) );
		self::assertSame( 'Hola Ana,', EmailBrand::saludo( $this->pedido( 'Ana' ) ) );
		self::assertSame( 'Hola Ana,', EmailBrand::saludo( $this->pedido( '  Ana  ' ) ) );
	}
}
