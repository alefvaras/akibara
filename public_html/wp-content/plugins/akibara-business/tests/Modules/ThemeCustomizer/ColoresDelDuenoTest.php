<?php
/**
 * Los colores que el dueño cambia desde el panel, y el silencio cuando no cambió nada.
 *
 * El módulo inyecta un `<style>` en CADA página del front, así que dos cosas
 * importan por igual: que respete lo que el dueño guardó y que NO emita nada
 * cuando el color coincide con el del tema —si no, el literal duplicado vuelve
 * a separarse de `theme.json` y nadie se entera hasta que un negro deja de ser
 * el mismo negro.
 *
 * 218 líneas en el camino de todo el front y ninguna prueba hasta hoy
 * (ampliación de cobertura, 2026-09-18).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\ThemeCustomizer;

use Akibara\Business\Modules\AdminPanel\AdminPanel;
use Akibara\Business\Modules\ThemeCustomizer\ThemeCustomizer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ColoresDelDuenoTest extends TestCase {

	/** @var array<string, string> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options = array();

		Functions\when( 'get_option' )->alias( fn( string $n, $def = false ) => $this->options[ $n ] ?? $def );
		Functions\when( 'sanitize_hex_color' )->alias(
			static fn( $color ) => is_string( $color ) && 1 === preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : null
		);
		Functions\when( 'wp_get_global_settings' )->justReturn(
			array(
				array( 'slug' => 'primary', 'color' => '#E60012' ),
				array( 'slug' => 'surface', 'color' => '#111111' ),
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pintar(): string {
		ob_start();
		( new ThemeCustomizer( new AdminPanel() ) )->inject_dynamic_css();

		return (string) ob_get_clean();
	}

	/** @testdox Sin nada guardado no se emite una sola línea de CSS. */
	public function test_sin_overrides_no_emite_nada(): void {
		self::assertSame( '', $this->pintar(), 'Emitir el literal duplicado es lo que hace que se separe de theme.json.' );
	}

	/** @testdox Un color igual al del tema tampoco emite: el linkage sigue vivo. */
	public function test_el_mismo_color_del_tema_no_es_un_override(): void {
		$this->options = array(
			'akb_brand_color_primary' => '#e60012', // mismo color, otra caja
			'akb_brand_color_surface' => '#111111',
		);

		self::assertSame( '', $this->pintar() );
	}

	/** @testdox El color que el dueño cambió sí se inyecta, sobre :root. */
	public function test_el_color_del_dueno_manda(): void {
		$this->options = array( 'akb_brand_color_primary' => '#00AAFF' );

		$css = $this->pintar();

		self::assertStringContainsString( 'akb-dynamic-branding', $css );
		self::assertStringContainsString( ':root', $css );
		self::assertStringContainsString( '#00AAFF', $css );
		self::assertStringNotContainsString( '#111111', $css, 'El que no cambió no viaja.' );
	}

	/** @testdox Un valor corrupto no ensucia el CSS del sitio. */
	public function test_un_valor_corrupto_se_ignora(): void {
		$this->options = array( 'akb_brand_color_primary' => 'rojo; } body { display:none' );

		self::assertSame( '', $this->pintar(), 'Un override roto es peor que ninguno: puede tapar la tienda entera.' );
	}

	/** @testdox Sin theme.json disponible se cae al literal de respaldo, no a un vacío. */
	public function test_sin_theme_json_hay_respaldo(): void {
		Functions\when( 'wp_get_global_settings' )->justReturn( null );
		$this->options = array( 'akb_brand_color_primary' => '#E60012' );

		// Igual al respaldo declarado: sigue sin ser un override.
		self::assertSame( '', $this->pintar() );

		$this->options = array( 'akb_brand_color_primary' => '#123456' );
		self::assertStringContainsString( '#123456', $this->pintar() );
	}
}
