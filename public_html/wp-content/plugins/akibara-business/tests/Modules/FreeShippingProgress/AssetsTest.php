<?php
/**
 * Contratos visuales de la barra de envío gratis.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\FreeShippingProgress;

use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase {

	/**
	 * @testdox Los íconos sobreviven la minificación sin convertirse en cuadrados
	 */
	public function test_mask_icons_use_minifier_safe_data_urls(): void {
		$css = $this->css();

		self::assertStringContainsString( '.akb-fsp-truck', $css );
		self::assertGreaterThanOrEqual( 4, substr_count( $css, 'data:image/svg+xml;base64,' ) );
		self::assertStringNotContainsString( 'data:image/svg+xml;utf8,', $css );
		self::assertStringContainsString( 'mask-position: center', $css );
	}

	/**
	 * @testdox En casi logrado el camión queda completo sobre el fill y la pista pendiente es neutral
	 */
	public function test_almost_there_truck_stays_inside_fill_and_pending_track_is_neutral(): void {
		$css         = $this->css();
		$track       = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-track' );
		$fill        = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-fill' );
		$truck       = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-truck' );
		$truck_inner = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-truck::after' );
		$base_truck  = $this->selector_block( $css, '.akb-fsp-truck' );

		self::assertStringContainsString( 'border: 1px solid var(--akb-fsp-track);', $track );
		self::assertStringNotContainsString( 'var(--aki-yellow', $track );
		self::assertStringContainsString( 'background: var(--aki-yellow, #ffd700);', $fill );
		self::assertStringContainsString( 'right: 4px;', $truck );
		self::assertStringNotContainsString( 'right: -', $truck );
		self::assertStringContainsString( 'background-color: var(--aki-yellow, #ffd700);', $truck );
		self::assertStringNotContainsString( 'filter:', $truck );
		self::assertStringNotContainsString( 'drop-shadow', $truck );
		self::assertStringNotContainsString( 'box-shadow', $truck );
		self::assertStringNotContainsString( 'border:', $truck );
		self::assertStringNotContainsString( 'border-radius:', $truck );
		self::assertStringNotContainsString( 'padding:', $truck );

		self::assertStringContainsString( 'content: "";', $truck_inner );
		self::assertStringContainsString( 'position: absolute;', $truck_inner );
		self::assertStringContainsString( 'inset: 2px;', $truck_inner );
		self::assertStringContainsString( 'background-color: var(--aki-black, #0a0a0a);', $truck_inner );
		self::assertStringContainsString( '-webkit-mask-image:', $truck_inner );
		self::assertStringContainsString( 'mask-image:', $truck_inner );
		self::assertStringContainsString( '-webkit-mask-size: contain;', $truck_inner );
		self::assertStringContainsString( 'mask-size: contain;', $truck_inner );
		self::assertStringContainsString( '-webkit-mask-repeat: no-repeat;', $truck_inner );
		self::assertStringContainsString( 'mask-repeat: no-repeat;', $truck_inner );
		self::assertStringContainsString( '-webkit-mask-position: center;', $truck_inner );
		self::assertStringContainsString( 'mask-position: center;', $truck_inner );
		self::assertStringContainsString( 'pointer-events: none;', $truck_inner );
		self::assertStringNotContainsString( 'filter:', $truck_inner );
		self::assertStringNotContainsString( 'drop-shadow', $truck_inner );

		self::assertSame(
			1,
			preg_match(
				'/mask-image:\s*url\("(?<mask>data:image\/svg\+xml;base64,[^"]+)"\)/',
				$base_truck,
				$base_mask
			),
			'La silueta exterior debe conservar la máscara SVG del camión.'
		);
		self::assertSame(
			1,
			preg_match(
				'/mask-image:\s*url\("(?<mask>data:image\/svg\+xml;base64,[^"]+)"\)/',
				$truck_inner,
				$inner_mask
			),
			'La capa interior debe repetir la máscara SVG para dejar un contorno anatómico.'
		);
		self::assertSame( $base_mask['mask'], $inner_mask['mask'] );
	}

	/**
	 * @testdox La máscara conserva cabina y dos ruedas reconocibles
	 */
	public function test_truck_mask_keeps_cab_and_two_wheel_shapes(): void {
		$truck = $this->selector_block( $this->css(), '.akb-fsp-truck' );

		self::assertSame(
			1,
			preg_match(
				'/mask-image:\s*url\("data:image\/svg\+xml;base64,(?<svg>[^"]+)"\)/',
				$truck,
				$matches
			)
		);

		$svg = base64_decode( $matches['svg'], true );
		self::assertIsString( $svg );
		self::assertStringContainsString( 'M17 12V9.5', $svg, 'La máscara debe conservar la cabina.' );
		self::assertStringContainsString( 'M6 18.5', $svg, 'La máscara debe conservar la rueda trasera.' );
		self::assertStringContainsString( 'm12 0', $svg, 'La máscara debe conservar la rueda delantera.' );
	}

	/**
	 * @testdox La doble máscara no depende de animación y reduced motion inmoviliza su transición
	 */
	public function test_double_mask_is_static_and_respects_reduced_motion(): void {
		$css         = $this->css();
		$truck       = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-truck' );
		$truck_inner = $this->selector_block( $css, '.akb-fsp-almost-there .akb-fsp-truck::after' );

		self::assertStringNotContainsString( 'animation', $truck );
		self::assertStringNotContainsString( 'animation', $truck_inner );
		self::assertMatchesRegularExpression(
			'/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\.akb-fsp-fill,\s*\.akb-fsp-truck\s*\{\s*transition:\s*none;/s',
			$css
		);
	}

	/**
	 * @dataProvider progressBoundaryProvider
	 * @testdox Los porcentajes 79, 80, 99 y 100 conservan sus estados visuales de frontera
	 */
	public function test_progress_boundary_contract( float $percent, string $expected_state ): void {
		$source = $this->source();

		self::assertSame(
			1,
			preg_match(
				'/\$almost_there_class\s*=\s*\$percent\s*>=\s*(?<threshold>\d+(?:\.\d+)?)\s*\?/',
				$source,
				$matches
			)
		);

		$threshold = (float) $matches['threshold'];
		self::assertSame( 80.0, $threshold );

		if ( 100.0 === $percent ) {
			self::assertSame( 'qualified', $expected_state );
			$this->assert_qualified_state_routes_before_progress( $source );
			return;
		}

		$actual_state = $percent >= $threshold ? 'almost-there' : 'progress';
		self::assertSame( $expected_state, $actual_state );
	}

	/**
	 * @return array<string,array{0:float,1:string}>
	 */
	public function progressBoundaryProvider(): array {
		return array(
			'79 percent remains normal progress' => array( 79.0, 'progress' ),
			'80 percent enters almost there'     => array( 80.0, 'almost-there' ),
			'99 percent remains almost there'    => array( 99.0, 'almost-there' ),
			'100 percent uses qualified green'   => array( 100.0, 'qualified' ),
		);
	}

	/**
	 * @testdox El estado logrado conserva la banda verde y no reutiliza el track amarillo
	 */
	public function test_qualified_state_keeps_its_green_contract(): void {
		$css       = $this->css();
		$qualified = $this->selector_block(
			$css,
			'.akb-cart-progress-slot .akb-fsp-wrap.akb-fsp-qualified'
		);
		$message   = $this->selector_block(
			$css,
			'.akb-cart-progress-slot .akb-fsp-qualified .akb-fsp-message'
		);

		self::assertStringContainsString( 'background: var(--aki-success, #00c853);', $qualified );
		self::assertStringContainsString( 'color: var(--aki-black, #121212);', $message );
		$this->assert_qualified_state_routes_before_progress( $this->source() );
	}

	private function css(): string {
		$css = file_get_contents(
			dirname( __DIR__, 3 ) . '/assets/css/free-shipping-progress.css'
		);

		self::assertIsString( $css );
		return $css;
	}

	private function source(): string {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/FreeShippingProgress/FreeShippingProgress.php'
		);

		self::assertIsString( $source );
		return $source;
	}

	private function selector_block( string $css, string $selector ): string {
		self::assertSame(
			1,
			preg_match(
				'/' . preg_quote( $selector, '/' ) . '\s*\{(?<body>[^}]*)\}/',
				$css,
				$matches
			),
			'No se encontró el bloque CSS de ' . $selector
		);

		return $matches['body'];
	}

	private function assert_qualified_state_routes_before_progress( string $source ): void {
		$get_html_start = strpos( $source, 'private function get_html(): string' );
		$next_method    = strpos( $source, 'private function cart_ready(): bool', $get_html_start );

		self::assertIsInt( $get_html_start );
		self::assertIsInt( $next_method );

		$get_html = substr( $source, $get_html_start, $next_method - $get_html_start );
		self::assertMatchesRegularExpression(
			'/STATE_QUALIFIED.*?render_qualified.*?STATE_PROGRESS.*?render_progress/s',
			$get_html,
			'El 100% debe resolver la banda verde antes de considerar el track de progreso.'
		);
	}
}
