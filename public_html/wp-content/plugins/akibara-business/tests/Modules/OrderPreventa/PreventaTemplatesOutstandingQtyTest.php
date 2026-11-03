<?php
/**
 * El correo «ya llegó» cuenta lo que el cliente sigue esperando, no lo que compró.
 *
 * Con 3 tomos comprados y 1 reembolsado, el correo decía «× 3»: le anunciaba
 * al cliente un tomo que ya le habíamos devuelto. La cantidad NETA la conoce
 * `PreventaItems::outstanding_qty()`, y las dos variantes del template tienen
 * que usarla.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\OrderPreventa;

use Akibara\Business\Modules\OrderPreventa\MetaKeys;
use Akibara\Business\Tests\Support\PedidoConDatosDeCliente;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WC_Order_Item_Product;

defined( 'AKIBARA_BUSINESS_DIR' ) || define( 'AKIBARA_BUSINESS_DIR', dirname( __DIR__, 3 ) . '/' );

final class PreventaTemplatesOutstandingQtyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$GLOBALS['akibara_test_options'] = array();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		// La parte plana no usa esc_html()/esc_url(): borran apóstrofes (revisión 1.116.8).
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses' )->alias( static fn( $texto ): string => strip_tags( (string) $texto ) );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn( string $format, int $ts ): string => gmdate( 'j \\d\\e n \\d\\e Y', $ts ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_test_options'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( string $variante, string $plantilla = 'customer-preventa-ready.php' ): string {
		$item               = new WC_Order_Item_Product();
		$item->akb_id       = 7;
		$item->akb_name     = 'Frieren 06';
		$item->akb_quantity = 3;
		$item->akb_meta     = array( MetaKeys::ITEM_WAS_PREVENTA => 'yes' );

		$order               = new PedidoConDatosDeCliente( array( MetaKeys::ORDER_HAS_PREVENTA => 'yes' ) );
		$order->akb_items    = array( $item );
		$order->akb_refunded = array( 7 => 1 );

		$vars = array(
			'order'               => $order,
			'preventa_items'      => array( $item ),
			'is_local_pickup'     => false,
			'mode'                => 't7',
			'summary_max_ymd'     => '2026-12-14',
			'date_change_ctx'     => array( 'product_id' => 0, 'product_name' => '', 'old_ymd' => '', 'new_ymd' => '' ),
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
	 * @testdox El texto plano dice «× 2»: tres comprados, uno devuelto.
	 */
	public function test_plain_text_counts_the_net_quantity(): void {
		$salida = $this->render( 'plain' );

		self::assertStringContainsString( 'Frieren 06 × 2', $salida );
		self::assertStringNotContainsString( '× 3', $salida );
	}

	/**
	 * @testdox El HTML pone 2 en la celda de cantidad.
	 */
	public function test_html_counts_the_net_quantity(): void {
		$salida = $this->render( 'html' );

		self::assertStringContainsString( 'Frieren 06', $salida );
		self::assertMatchesRegularExpression( '/<td[^>]*>\s*2\s*<\/td>/', $salida );
		self::assertDoesNotMatchRegularExpression( '/<td[^>]*>\s*3\s*<\/td>/', $salida );
	}

	/**
	 * @testdox El recordatorio (HTML y texto) cuenta igual: 2, no 3.
	 */
	public function test_the_reminder_counts_the_net_quantity_too(): void {
		$html  = $this->render( 'html', 'customer-preventa-reminder.php' );
		$plain = $this->render( 'plain', 'customer-preventa-reminder.php' );

		self::assertMatchesRegularExpression( '/<td[^>]*>\s*2\s*<\/td>/', $html );
		self::assertDoesNotMatchRegularExpression( '/<td[^>]*>\s*3\s*<\/td>/', $html );
		self::assertStringContainsString( 'Frieren 06 × 2', $plain );
		self::assertStringNotContainsString( '× 3', $plain );
	}
}
