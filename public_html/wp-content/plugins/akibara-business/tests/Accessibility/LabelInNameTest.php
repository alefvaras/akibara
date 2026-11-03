<?php
/**
 * Regression tests for WCAG 2.5.3 visible-label/name parity.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Accessibility;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class LabelInNameTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static fn( string $single, string $plural, int $number ): string => 1 === $number ? $single : $plural
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_announcement_button_accessible_name_keeps_visible_fraction(): void {
		$plugin_root = dirname( __DIR__, 2 );
		$renderer    = (string) file_get_contents( $plugin_root . '/src/Modules/AnnouncementBar/AnnouncementBar.php' );
		$javascript  = (string) file_get_contents( $plugin_root . '/assets/js/announcement-bar.js' );

		self::assertStringContainsString(
			"'Siguiente anuncio. %1\$d/%2\$d'",
			$renderer
		);
		self::assertStringContainsString(
			"'Siguiente anuncio. ' + position + '/' + messages.length",
			$javascript
		);
		self::assertStringNotContainsString( "'Siguiente anuncio. Mostrando '", $javascript );
	}

	public function test_bottom_search_uses_its_visible_label_as_accessible_name(): void {
		$template = (string) file_get_contents(
			ABSPATH . 'wp-content/themes/akibara-gp/template-parts/footer/bottom-nav.php'
		);

		self::assertSame(
			1,
			preg_match( '/<button[^>]+id="bottom-nav-search"[^>]*>/s', $template, $opening )
		);
		self::assertStringNotContainsString( 'aria-label=', $opening[0] );
		self::assertStringContainsString( '<span class="bottom-nav__label">Buscar</span>', $template );
	}

	public function test_editorial_card_name_starts_with_all_visible_card_text(): void {
		$theme_root = ABSPATH . 'wp-content/themes/akibara-gp';
		$brands     = array(
			array(
				'name'        => 'IVREA',
				'slug'        => 'ivrea-argentina',
				'url'         => 'https://example.test/marca/ivrea-argentina/',
				'img'         => 'https://example.test/ivrea.svg',
				'img_w'       => 200,
				'img_h'       => 100,
				'count'       => 855,
				'region'      => 'argentina',
				'badge_text'  => 'AR',
				'badge_color' => '#75AADB',
				'badge_label' => 'Argentina',
			),
		);
		$more_url   = 'https://example.test/editoriales/';
		$more_label = 'Ver todas';

		Functions\when( 'get_stylesheet_directory' )->justReturn( $theme_root );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://example.test/theme' );
		// El template resuelve el logo con el helper del theme; aquí sólo se mide
		// el nombre accesible de la tarjeta, así que devuelve «sin logo propio»
		// para que caiga en la imagen que ya trae `$brand`.
		Functions\when( 'akibara_gp_brand_logo' )->justReturn(
			array(
				'url' => '',
				'w'   => 0,
				'h'   => 0,
			)
		);

		ob_start();
		require $theme_root . '/template-parts/brands/home-carousel.php';
		$html = (string) ob_get_clean();

		self::assertSame(
			1,
			preg_match(
				'/<a\\s+class="akb-brands-home__item"[^>]+data-brand="ivrea-argentina"[^>]*>/s',
				$html,
				$opening
			)
		);
		self::assertStringNotContainsString( 'aria-label=', $opening[0] );
		self::assertMatchesRegularExpression(
			'/IVREA\\s*<span[^>]+>\\s*• AR<\\/span>\\s*<\\/span>\\s*<span[^>]+>\\s*855 productos en catálogo\\s*<\\/span>/s',
			$html
		);
	}
}
