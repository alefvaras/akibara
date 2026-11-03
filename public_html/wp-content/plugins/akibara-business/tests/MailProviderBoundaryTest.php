<?php
/**
 * Contrato de frontera entre Akibara Business y el proveedor de correo.
 *
 * Business conserva los productores de correo de la tienda y la identidad del
 * remitente, pero no debe volver a administrar transporte, credenciales ni UI
 * del proveedor. El transporte SMTP vive fuera de Business y es el único dueño
 * de la entrega de `wp_mail()`.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests;

use PHPUnit\Framework\TestCase;

final class MailProviderBoundaryTest extends TestCase {

	private function plugin_root(): string {
		return dirname( __DIR__ );
	}

	private function source_tree(): string {
		$source   = '';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->plugin_root() . '/src' )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$source .= "\n" . (string) file_get_contents( $file->getPathname() );
			}
		}

		return $source;
	}

	/**
	 * @testdox Business must not own an email transport or its admin UI.
	 */
	public function test_business_does_not_own_email_transport(): void {
		$plugin_source = (string) file_get_contents( $this->plugin_root() . '/src/Plugin.php' );
		$source_tree   = $this->source_tree();
		$legacy_files  = glob( $this->plugin_root() . '/src/Modules/MailSmtp/*.php' );

		self::assertSame( array(), false === $legacy_files ? array() : $legacy_files );
		self::assertStringNotContainsString( 'Modules\\MailSmtp', $plugin_source );
		self::assertStringNotContainsString( "['mail_smtp']", $plugin_source );
		self::assertStringNotContainsString( "['mail_failure_capture']", $plugin_source );
		self::assertStringNotContainsString( "['mail_admin']", $plugin_source );
		self::assertStringNotContainsString( "'correo'", $plugin_source );
		self::assertDoesNotMatchRegularExpression(
			'/add_action\(\s*[\'\"]phpmailer_init[\'\"]/',
			$source_tree,
			'Akibara Business no debe reintroducir configuración propia de PHPMailer.'
		);
		self::assertStringNotContainsString( 'SMTPAuth', $source_tree );
		self::assertStringNotContainsString( 'sib_api_key_v3', $source_tree );
		self::assertStringNotContainsString( 'api.brevo.com', $source_tree );
		self::assertStringNotContainsString( 'in-automate.brevo.com', $source_tree );
		$this->assertProviderFieldKeyIsTheOnlyMention( $source_tree );
		self::assertStringNotContainsString( 'smtp.resend.com', $source_tree );
		self::assertStringNotContainsString( 'api.resend.com', $source_tree );
		self::assertStringNotContainsString( 'akibara_resend_', $source_tree );
	}

	/**
	 * CAMBIO DE CONTRATO DELIBERADO (2026-09-11). Hasta hoy `SendinblueWoocommerce`
	 * no podía aparecer en `src/`: Business no habla con el proveedor de
	 * campañas. Eso sigue siendo verdad. Lo que sí necesita Business es el
	 * NOMBRE del campo adicional del checkout que registra ese plugin
	 * (`SendinblueWoocommerce/newsletter_opt_in`) para guardar en el pedido la
	 * prueba de consentimiento —qué texto vio el cliente y cuándo—, leída del
	 * registro de campos de WooCommerce y no de ninguna option ni API del
	 * proveedor. Es un identificador de dato, no una integración.
	 *
	 * Por eso la frontera se estrecha en vez de borrarse: la cadena puede
	 * aparecer UNA sola vez en todo el árbol, y sólo como el valor de
	 * `MarketingConsent::DEFAULT_FIELD_KEY`. Un `use SendinblueWoocommerce\…`,
	 * una llamada o una segunda mención en cualquier otro fichero vuelven a
	 * poner esto rojo.
	 *
	 * @param string $source_tree Todo el PHP de `src/` concatenado.
	 */
	private function assertProviderFieldKeyIsTheOnlyMention( string $source_tree ): void {
		$consent = (string) file_get_contents( $this->plugin_root() . '/src/Modules/MarketingConsent/MarketingConsent.php' );

		self::assertSame(
			1,
			substr_count( $source_tree, 'SendinblueWoocommerce' ),
			'El proveedor sólo puede nombrarse como clave del campo de consentimiento, y una sola vez.'
		);
		self::assertSame(
			1,
			preg_match( "/DEFAULT_FIELD_KEY\s*=\s*'SendinblueWoocommerce\/newsletter_opt_in';/", $consent ),
			'La única mención es la constante con la clave del campo adicional del checkout.'
		);
		self::assertStringNotContainsString( 'use SendinblueWoocommerce', $source_tree );
		self::assertStringNotContainsString( 'SendinblueWoocommerce\\', $source_tree );
	}

	/**
	 * @testdox The provider cutover must preserve store emails and sender identity.
	 */
	public function test_functional_store_email_components_remain(): void {
		$plugin_source = (string) file_get_contents( $this->plugin_root() . '/src/Plugin.php' );

		self::assertStringContainsString( "['mail_from'] = new MailFrom()", $plugin_source );
		self::assertFileExists( $this->plugin_root() . '/src/Modules/Forms/ContactoSubmit.php' );
		self::assertFileExists( $this->plugin_root() . '/src/Modules/BackInStock/SubscriptionNotice.php' );
		self::assertFileExists( $this->plugin_root() . '/src/Modules/BackInStock/InstockEmail.php' );
		self::assertFileExists( $this->plugin_root() . '/src/Modules/OrderPreventa/ReadyToShipEmail.php' );
	}

	/**
	 * @testdox Legacy SMTP options must be deletion-only upgrade data.
	 */
	public function test_legacy_options_are_upgrade_cleanup_only(): void {
		$plugin_source = (string) file_get_contents( $this->plugin_root() . '/src/Plugin.php' );
		$source_tree   = $this->source_tree();
		$options       = array(
			'akibara_smtp_host',
			'akibara_smtp_port',
			'akibara_smtp_secure',
			'akibara_smtp_user',
			'akibara_smtp_pass',
			'akibara_mail_failures',
		);

		foreach ( $options as $option ) {
			self::assertStringContainsString( "delete_option( '{$option}' );", $plugin_source );
			self::assertSame(
				1,
				substr_count( $source_tree, "'{$option}'" ),
				"{$option} debe existir una sola vez, exclusivamente como limpieza de upgrade."
			);
		}

		self::assertStringNotContainsString( 'AKIBARA_SMTP_PASS', $source_tree );
		self::assertStringNotContainsString( 'smtp.resend.com', $source_tree );
		self::assertStringNotContainsString( 'Resend\\', $source_tree );
	}
}
