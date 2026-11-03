<?php
/**
 * El tomo EN STOCK dice su plazo de despacho junto al botón (UC-2, auditoría
 * del tema 2026-09-22).
 *
 * Medido ese día en producción: «Despacha en 24–48 h» sólo aparecía en la
 * grilla de la pestaña Descripción, 1.700 px bajo el botón; la ficha «a pedido»
 * sí decía su fecha junto a la compra.
 *
 * Contratos:
 *   1) Sale sólo para «disponible» y comprable; nunca en «a pedido», preventa
 *      ni agotado.
 *   2) El texto es la promesa editable del panel (`akb_copy_promise_dispatch`),
 *      con el de fábrica si la fila quedó vacía.
 *   3) Va DESPUÉS del formulario —no baja el botón— y ANTES de los sellos del
 *      tema (`woocommerce_after_add_to_cart_form` a 15), no en el resumen a 31,
 *      donde quedaba bajo los sellos y bajo el pliegue a 1366x768.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\EstimatedDelivery;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\EstimatedDelivery\DateCalculator;
use Akibara\Business\Modules\EstimatedDelivery\Settings;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class DespachoEnStockTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		Functions\when( 'get_option' )->alias(
			fn( string $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default
		);
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'get_the_terms' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function renderer(): BannerRenderer {
		$timezone = new \DateTimeZone( 'America/Santiago' );

		return new BannerRenderer(
			new DateCalculator(
				new Settings(),
				static fn(): int => ( new \DateTimeImmutable( '2026-09-22 15:00:00', $timezone ) )->getTimestamp(),
				$timezone
			)
		);
	}

	private function render( string $stock, bool $comprable = true ): string {
		$GLOBALS['product'] = new \WC_Product( $stock, array(), $comprable );

		ob_start();
		$this->renderer()->render_despacho();

		return (string) ob_get_clean();
	}

	/**
	 * @testdox Un tomo disponible dice «Despacha en 24–48 h» bajo el botón.
	 */
	public function test_disponible_dice_el_plazo(): void {
		$html = $this->render( StockStatus::IN_STOCK );

		self::assertStringContainsString( 'class="akb-despacho"', $html );
		self::assertStringContainsString( '<span class="akb-despacho__texto">Despacha en 24–48 h</span>', $html );
		self::assertStringContainsString( 'aria-hidden="true"', $html, 'El camión es decorativo: el lector lee sólo la promesa.' );
	}

	/**
	 * @testdox El texto es el del panel (Textos → Promesas), y vacío vuelve al de fábrica.
	 */
	public function test_usa_la_promesa_del_panel(): void {
		$this->options['akb_copy_promise_dispatch'] = 'Sale hoy si compras antes de las 14:00';
		self::assertStringContainsString( 'Sale hoy si compras antes de las 14:00', $this->render( StockStatus::IN_STOCK ) );

		$this->options['akb_copy_promise_dispatch'] = '   ';
		self::assertStringContainsString( 'Despacha en 24–48 h', $this->render( StockStatus::IN_STOCK ) );
	}

	/**
	 * @testdox «A pedido», preventa, agotado o no comprable: nada (tienen su banner de fecha o no despachan).
	 */
	public function test_otros_estados_no_prometen_despacho(): void {
		self::assertSame( '', $this->render( StockStatus::ON_BACKORDER ) );
		self::assertSame( '', $this->render( StockStatus::OUT_OF_STOCK ) );
		self::assertSame( '', $this->render( StockStatus::IN_STOCK, false ) );
	}

	/**
	 * @testdox Cuelga tras el formulario y antes de los sellos del tema; no del resumen a 25 ni a 31.
	 */
	public function test_va_tras_el_formulario_antes_de_los_sellos(): void {
		$renderer = $this->renderer();
		$renderer->register();

		$prioridad = Actions\has( 'woocommerce_after_add_to_cart_form', array( $renderer, 'render_despacho' ) );
		self::assertSame( 5, $prioridad );
		self::assertLessThan( 15, $prioridad, 'Los sellos de confianza del tema cuelgan del mismo hook a 15.' );
		self::assertFalse( Actions\has( 'woocommerce_single_product_summary', array( $renderer, 'render_despacho' ) ) );
	}

	/**
	 * @testdox El comentario del hook da la regla relativa y la dependencia con el tema, no coordenadas del espejo de un día.
	 */
	public function test_el_comentario_no_fija_coordenadas_del_espejo(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Modules/EstimatedDelivery/BannerRenderer.php' );
		$hook   = strpos( $fuente, 'add_action( \'woocommerce_after_add_to_cart_form\', array( $this, \'render_despacho\' ), 5 );' );
		self::assertNotFalse( $hook );

		// El bloque de comentario que va justo encima del add_action.
		$inicio     = strrpos( substr( $fuente, 0, $hook ), 'El tomo EN STOCK dice su plazo' );
		$comentario = substr( $fuente, (int) $inicio, $hook - (int) $inicio );

		// Revisión B2-R2: «el botón termina en y=645» sólo valía con el extracto
		// ya movido por OTRO lote; con el tema de producción la línea caía bajo
		// el pliegue a 1366x768.
		self::assertDoesNotMatchRegularExpression( '/\by=\d+/', $comentario, 'Coordenadas del espejo de un día: caducan con cualquier cambio del tema.' );
		self::assertStringContainsString( 'extracto', $comentario, 'Tiene que nombrar lo que decide el pliegue: el extracto del tema sobre el botón.' );
		self::assertStringContainsString( 'el tema primero', $comentario, 'Y el orden de despliegue que eso exige.' );
	}
}
