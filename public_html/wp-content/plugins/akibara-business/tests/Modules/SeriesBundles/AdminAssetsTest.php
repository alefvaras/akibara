<?php
/**
 * Contratos de mantenibilidad de la interfaz de administración de packs.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use PHPUnit\Framework\TestCase;

final class AdminAssetsTest extends TestCase {

	/**
	 * @testdox La vista de estado usa el stylesheet condicional y no CSS inline
	 */
	public function test_status_card_has_a_single_stylesheet_owner(): void {
		$php = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/Admin/AdminSection.php'
		);

		self::assertIsString( $php );
		self::assertStringContainsString( 'akb-sb-status-card', $php );
		self::assertStringNotContainsString( '<style>', $php );
	}

	/**
	 * @testdox El bundle admin no conserva selectores de la interfaz retirada
	 */
	public function test_removed_curated_pack_ui_has_no_css_payload(): void {
		$css = file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/css/series-bundles-admin.css'
		);

		self::assertIsString( $css );
		self::assertStringContainsString( '.akb-sb-tier-table', $css );

		foreach (
			array(
				'.akb-sb-media__preview',
				'.akb-sb-product-search',
				'.akb-sb-card-list',
				'.akb-sb-preview',
				'.akb-sb-grid-layout',
			) as $removed_selector
		) {
			self::assertStringNotContainsString( $removed_selector, $css );
		}
	}
}
