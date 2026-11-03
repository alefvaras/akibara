<?php
/**
 * Contrato ejecutable para la identidad semántica de los SVG públicos.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/icons.php';

/**
 * @coversNothing
 */
final class IconSemanticsTest extends TestCase {

	/**
	 * @testdox Cada icono renderizado expone su nombre allowlisted para la auditoría pública.
	 */
	public function test_rendered_icons_expose_the_allowlisted_semantic_name(): void {
		self::assertStringContainsString(
			'data-akibara-icon="package"',
			akibara_gp_icon( 'package', 18 )
		);
		self::assertStringContainsString(
			'data-akibara-icon="clock"',
			akibara_gp_icon( 'clock', 22 )
		);
		self::assertStringContainsString(
			'data-akibara-icon="whatsapp"',
			akibara_gp_icon( 'whatsapp', 18 )
		);
	}

	/**
	 * La librería no guarda glifos que nadie pide.
	 *
	 * `map-pin` vivía sólo porque ESTE test lo renderizaba, mientras
	 * `online-store-truth.test.js` prohíbe usarlo (Akibara no tiene local a la
	 * calle) y `EmailFooterTruthTest` exige que no aparezca en el correo. Un
	 * glifo que un test enciende y otro test prohíbe no es un icono: es un
	 * fósil con coartada.
	 *
	 * @testdox Los glifos retirados no vuelven a colarse en la librería.
	 */
	public function test_retired_glyphs_stay_out(): void {
		foreach ( array( 'map-pin', 'chevron-down', 'chevron-up' ) as $retirado ) {
			self::assertSame(
				'',
				akibara_gp_icon( $retirado ),
				sprintf( 'El glifo «%s» se retiró por no tener peticionario.', $retirado )
			);
		}
	}

	/**
	 * @testdox Un nombre ajeno al allowlist no produce markup ni atributos.
	 */
	public function test_unknown_icon_name_renders_nothing(): void {
		self::assertSame( '', akibara_gp_icon( 'location-from-user-input' ) );
	}
}
