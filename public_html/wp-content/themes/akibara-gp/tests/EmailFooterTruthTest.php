<?php
/**
 * Contrato ejecutable del footer de correo: una opción WooCommerce con texto
 * histórico nunca puede volver a publicar una dirección operativa.
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
final class EmailFooterTruthTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_woocommerce' )->justReturn( true );

		// S-e9309aea (2026-09-19): la plantilla ya no duplica el literal de la
		// línea pública, llama a `akibara_gp_email_footer_public_line()`
		// (`inc/woocommerce.php`) — igual que en producción, donde
		// `functions.php` siempre carga ese fichero antes de renderizar
		// cualquier plantilla.
		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox El render ignora por completo el footer configurable de WooCommerce.
	 */
	public function test_render_never_reads_or_outputs_a_literal_private_address(): void {
		Functions\expect( 'get_option' )->never();
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $value ) => strip_tags( (string) $value )
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.test' . (string) $path
		);
		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);

		ob_start();
		require dirname( __DIR__ ) . '/woocommerce/emails/email-footer.php';
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Tienda 100% online', $output );
		self::assertStringContainsString( 'Sin local abierto al público', $output );
		self::assertStringContainsString( 'Retiro con coordinación previa en San Miguel', $output );
		self::assertStringNotContainsString( 'Fixture Private 123', $output );
		self::assertStringNotContainsString( 'map-pin', $output );
		self::assertDoesNotMatchRegularExpression( '/<address\b/i', $output );
		self::assertDoesNotMatchRegularExpression(
			'/(?:maps\.google\.|google\.[^\/]+\/maps|maps\.apple\.com|(?:www\.)?waze\.(?:com|to)|(?:www\.)?bing\.com\/maps|maps\.bing\.com|openstreetmap\.org)/i',
			$output
		);
	}

	/**
	 * @testdox Si una plantilla plain de WooCommerce dispara woocommerce_email_footer sin woocommerce_email_header, emite sólo texto plano.
	 */
	public function test_plain_template_calling_woocommerce_email_footer_gets_plain_line_without_html(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Akibara' );
		Functions\when( 'wp_date' )->justReturn( '2026' );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $value ) => strip_tags( (string) $value )
		);
		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' )
		);
		Functions\when( 'did_action' )->alias(
			static fn( string $hook ) => 'woocommerce_email_footer' === $hook ? 1 : 0
		);

		ob_start();
		require dirname( __DIR__ ) . '/woocommerce/emails/email-footer.php';
		$output = (string) ob_get_clean();

		self::assertSame( akibara_gp_email_footer_public_line() . "\n", $output );
		self::assertStringNotContainsString( '<table', $output );
	}
}
