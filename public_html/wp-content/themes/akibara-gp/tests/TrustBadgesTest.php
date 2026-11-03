<?php
/**
 * Contrato de contenido y semántica para los beneficios reutilizables.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class TrustBadgesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( 'home_url' )->alias(
			static fn( string $path = '' ): string => 'https://akibara.test' . $path
		);
		Functions\when( 'esc_html' )->returnArg( 1 );
		// Un valor DISTINTO del de fábrica: si el sello volviera a llevar el
		// literal escrito a mano, este test lo caza.
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => 'akb_copy_promise_installments' === $name
				? '6 cuotas sin interés'
				: $default
		);

		require_once dirname( __DIR__ ) . '/inc/icons.php';
		// `inc/trust-badges.php` llama a `akibara_gp_promise_installments()`, que
		// vive en `inc/site-copy.php`. Esta prueba pasaba SOLO por contagio: en
		// orden alfabético `SiteCopyTest` cargaba ese archivo antes. En
		// aislamiento —o en orden aleatorio— moría con «undefined function».
		require_once dirname( __DIR__ ) . '/inc/site-copy.php';
		require_once dirname( __DIR__ ) . '/inc/trust-badges.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Los cuatro beneficios son claros y toda la celda de embalaje es un enlace accesible.
	 */
	public function test_trust_badges_have_exact_copy_and_link_semantics(): void {
		$html = akibara_get_trust_badges_html();
		$dom  = new DOMDocument();
		$previous_libxml_state = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_libxml_state );
		$xpath = new DOMXPath( $dom );

		self::assertCount( 1, $xpath->query( '//*[@role="list"]' ) );
		self::assertCount( 4, $xpath->query( '//*[@role="listitem"]' ) );
		self::assertStringContainsString( 'Envíos a todo Chile', $html );
		self::assertStringNotContainsString( 'nacional', mb_strtolower( $html, 'UTF-8' ) );
		self::assertStringContainsString( 'Embalaje reforzado', $html );
		// El literal se fue a la option `akb_copy_promise_installments`: fijarlo
		// acá convertía el test en el guardián de una promesa financiera que el
		// dueño puede cambiar desde el panel, y habría vuelto rojo el día que la
		// tienda pasara a 6 cuotas. Lo que se afirma es que el sello DICE lo
		// configurado.
		self::assertStringContainsString( '6 cuotas sin interés', $html );
		self::assertStringContainsString( 'Atención por WhatsApp', $html );
		self::assertStringNotContainsString( 'blindado', $html );
		self::assertStringNotContainsString( 'Atención personalizada', $html );

		$links = $xpath->query( '//*[@role="listitem" and contains(concat(" ", normalize-space(@class), " "), " akb-badge-trust--linked ")]/a[@class="akb-badge-trust__link"]' );
		self::assertCount( 1, $links );
		self::assertSame( 'https://akibara.test/embalaje-seguro/', $links->item( 0 )->getAttribute( 'href' ) );
		self::assertCount( 1, $xpath->query( './/svg[@aria-hidden="true" and @focusable="false"]', $links->item( 0 ) ) );
		self::assertCount( 1, $xpath->query( './/span[normalize-space()="Embalaje reforzado"]', $links->item( 0 ) ) );
		self::assertCount( 1, $xpath->query( '//*[@data-akibara-icon="whatsapp" and @aria-hidden="true" and @focusable="false"]' ) );
	}
}
