<?php
/**
 * Contrato de supresión del H1 nativo de GeneratePress.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class PageHeaderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_filter' )->justReturn( true );

		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/page-header.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Envios reutiliza el conjunto content-driven y suprime el H1 duplicado de GeneratePress.
	 */
	public function test_envios_hides_the_generatepress_entry_title(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\expect( 'is_page' )
			->once()
			->with( array_merge( AKIBARA_GP_CONTENT_PAGES, array( 'carrito', 'checkout' ) ) )
			->andReturn( true );

		self::assertContains( 'envios', AKIBARA_GP_CONTENT_PAGES );
		self::assertFalse( akibara_gp_hide_entry_title( true ) );
	}

	/**
	 * Carrito y checkout no están en `AKIBARA_GP_CONTENT_PAGES`: se suman aparte
	 * porque su H1 no viene de un `.akb-page__hero` sino del contenido de la
	 * página (el shortcode `[akibara_cart_blocks_title]` en el carrito, un bloque
	 * HTML en el checkout). La exclusión nunca tuvo prueba propia y el docblock
	 * la justificaba nombrando un `<h2>` de otra plantilla.
	 *
	 * S-741a3fd9 (2026-09-19) propuso retirar `'checkout'` por creerla una rama
	 * que no casa (el slug SÍ es `checkout`) o por redundante con el filtro de
	 * akibara-cart-checkout-blocks. No lo es: ese filtro se abstiene en los
	 * endpoints `order-received` y `order-pay`, que son la misma página. Medido
	 * en el espejo quitando la rama: «gracias por tu compra» sirve dos H1. Esta
	 * prueba es la que cae si alguien la retira.
	 *
	 * @testdox Carrito y checkout se suman a la exclusión aunque no sean páginas de contenido.
	 */
	public function test_cart_and_checkout_are_excluded_beyond_the_content_pages(): void {
		self::assertNotContains( 'carrito', AKIBARA_GP_CONTENT_PAGES );
		self::assertNotContains( 'checkout', AKIBARA_GP_CONTENT_PAGES );

		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\expect( 'is_page' )
			->once()
			->with(
				\Mockery::on(
					static function ( $slugs ): bool {
						return is_array( $slugs )
							&& in_array( 'carrito', $slugs, true )
							&& in_array( 'checkout', $slugs, true );
					}
				)
			)
			->andReturn( true );

		self::assertFalse( akibara_gp_hide_entry_title( true ) );
	}

	/**
	 * @testdox Una página sin H1 propio conserva la decisión original de GeneratePress.
	 */
	public function test_unrelated_page_preserves_the_original_title_decision(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );

		self::assertTrue( akibara_gp_hide_entry_title( true ) );
		self::assertFalse( akibara_gp_hide_entry_title( false ) );
	}

	/**
	 * Candado de FUENTE, y a propósito: lo que se arregla aquí es un comentario
	 * que mentía, y un comentario no tiene comportamiento que montar. El rótulo
	 * de la rama atribuía el H1 de carrito y checkout a la plantilla de carrito
	 * vacío —que pinta un `<h2>`, y sólo con el carrito vacío— mientras el
	 * docblock del mismo fichero ya decía lo contrario. Ese rótulo, sumado a una
	 * ruta `/finalizar-compra/` que no existe, es lo que llevó a S-741a3fd9
	 * (2026-09-19) a dar por muerta una rama viva.
	 *
	 * @testdox El fichero no atribuye el H1 de carrito y checkout a una plantilla que no lo pinta.
	 */
	public function test_the_file_does_not_credit_a_template_that_renders_no_h1(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/inc/page-header.php' );

		self::assertStringNotContainsString( 'cart-empty.php', $source );
		self::assertStringContainsString( '<h1>Finalizar compra</h1>', $source );
		self::assertStringContainsString( 'order-received', $source );
	}
}
