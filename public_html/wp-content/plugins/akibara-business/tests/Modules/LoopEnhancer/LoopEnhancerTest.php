<?php
/**
 * Tests del "slot de estado" de la card del catálogo (release 1.99.2).
 *
 * Fija los contratos que LoopEnhancer introduce bajo el precio de cada card:
 * la PALABRA de disponibilidad para instock, el placeholder invisible que
 * alinea la grilla para todo lo demás, la procedencia de esa palabra (fuente
 * única StockStatus, no literal) y el allowlist de UCSS que evita que
 * LiteSpeed pode las reglas condicionales de este slot.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\LoopEnhancer;

use Akibara\Business\Modules\EstimatedDelivery\BannerRenderer;
use Akibara\Business\Modules\LoopEnhancer\LoopEnhancer;
use Akibara\Business\Modules\Stock\StockStatus;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class LoopEnhancerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value, ...$args ) => $value
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['product'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Captura lo que el hint emite para un `stock_status` dado.
	 *
	 * @param string $stock_status Estado de stock crudo del producto en scope.
	 */
	private function hint_for( string $stock_status ): string {
		$GLOBALS['product'] = new \WC_Product( $stock_status );

		ob_start();
		( new LoopEnhancer() )->render_availability_hint_loop();

		return (string) ob_get_clean();
	}

	/**
	 * El marcador de instock: la palabra, sola, sin ícono.
	 *
	 * assertSame sobre el markup COMPLETO a propósito: es la única forma de que
	 * reintroducir el ✓ (un `<svg>` dentro del span, como emitía 1.99.1) ponga
	 * este test en rojo. Un assertStringContainsString( 'Disponible' ) seguiría
	 * verde con el ícono de vuelta.
	 *
	 * @testdox Should render the availability word alone, with no icon markup at all.
	 */
	public function test_instock_hint_is_exactly_the_word_without_an_icon(): void {
		$html = $this->hint_for( StockStatus::IN_STOCK );

		self::assertSame( '<span class="akb-avail-hint">Disponible</span>', $html );
		self::assertStringNotContainsString(
			'<svg',
			$html,
			'El ✓ salió del hint en 1.99.2: instock es el estado modal (62,6% del catálogo) '
			. 'y palabra + verde + ícono era triple codificación para "sin novedad". '
			. 'La PALABRA es el cue no-color que sostiene WCAG 1.4.1, no el ícono.'
		);
		self::assertStringNotContainsString( 'akb-avail-hint__check', $html );
	}

	/**
	 * @testdox Should take the availability word from the StockStatus vocabulary.
	 */
	public function test_instock_hint_word_comes_from_the_single_source(): void {
		$html = $this->hint_for( StockStatus::IN_STOCK );

		self::assertSame(
			sprintf( '<span class="akb-avail-hint">%s</span>', StockStatus::LABELS[ StockStatus::IN_STOCK ] ),
			$html,
			'La palabra debe derivar de StockStatus::LABELS, no de un literal propio del módulo.'
		);
	}

	/**
	 * Prueba de procedencia: si la palabra fuera un literal en LoopEnhancer, el
	 * override del vocabulario no la alcanzaría y este test quedaría rojo.
	 *
	 * @testdox Should honor the akb_stock_status_label filter instead of hardcoding the word.
	 */
	public function test_instock_hint_respects_the_stock_label_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, ...$args ) {
				unset( $args );

				return 'akb_stock_status_label' === $hook ? 'Hay stock' : $value;
			}
		);

		$html = $this->hint_for( StockStatus::IN_STOCK );

		self::assertSame( '<span class="akb-avail-hint">Hay stock</span>', $html );
		self::assertStringNotContainsString(
			'Disponible',
			$html,
			'Un literal en el módulo ignoraría el único punto de override del vocabulario.'
		);
	}

	/**
	 * @testdox Should render the aria-hidden, textless placeholder for every non-instock card.
	 *
	 * @dataProvider non_instock_statuses
	 *
	 * @param string $stock_status Estado no-instock.
	 */
	public function test_non_instock_renders_the_empty_placeholder( string $stock_status ): void {
		$html = $this->hint_for( $stock_status );

		self::assertSame(
			'<span class="akb-avail-hint akb-avail-hint--empty" aria-hidden="true"></span>',
			$html
		);
		self::assertStringContainsString(
			'aria-hidden="true"',
			$html,
			'El placeholder reserva altura para alinear la grilla: no debe existir para AT.'
		);
		self::assertSame(
			'',
			trim( strip_tags( $html ) ),
			'El placeholder no lleva texto: la palabra de estado vive en el badge, no acá.'
		);
	}

	/**
	 * `outofstock` y `onbackorder` son las dos ramas reales del catálogo (82 y 479
	 * cards); ninguna lleva la palabra en este slot.
	 *
	 * @return array<string, array{string}>
	 */
	public function non_instock_statuses(): array {
		return array(
			'agotado'  => array( StockStatus::OUT_OF_STOCK ),
			'preventa' => array( StockStatus::ON_BACKORDER ),
		);
	}

	/**
	 * Estricto instock, no `is_in_stock()`: WooCommerce considera `onbackorder`
	 * como "en stock", y un status desconocido no debe prometer disponibilidad.
	 *
	 * @testdox Should fail closed to the placeholder for an unknown stock status.
	 */
	public function test_unknown_status_falls_back_to_the_placeholder(): void {
		$html = $this->hint_for( 'algo-que-woo-no-conoce' );

		self::assertSame(
			'<span class="akb-avail-hint akb-avail-hint--empty" aria-hidden="true"></span>',
			$html
		);
	}

	/**
	 * @testdox Should render nothing when there is no product in scope.
	 */
	public function test_renders_nothing_without_a_product_in_scope(): void {
		unset( $GLOBALS['product'] );

		ob_start();
		( new LoopEnhancer() )->render_availability_hint_loop();

		self::assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @testdox Should shield the four conditional selectors of the status slot from UCSS tree-shaking.
	 */
	public function test_ucss_allowlist_carries_the_four_conditional_selectors(): void {
		$selectors = ( new LoopEnhancer() )->allowlist_ucss_selectors( array() );

		// Los dos del CTA de agotado (1.9x) más los dos del slot de estado (1.99.2).
		self::assertContains( '.akb-notify-link', $selectors );
		self::assertContains( '.akb-badge--out_of_stock', $selectors );
		self::assertContains(
			'.akb-avail-hint--empty',
			$selectors,
			'Sin esta regla protegida, un muestreo de UCSS sin preventas poda el colapso '
			. 'del placeholder y desalinea las 479 cards de preventa.'
		);
		self::assertContains( '.akb-eta-loop', $selectors );
	}

	/**
	 * @testdox Should append to the accumulated UCSS whitelist without dropping or duplicating entries.
	 */
	public function test_ucss_allowlist_preserves_accumulated_selectors_and_dedupes(): void {
		$selectors = ( new LoopEnhancer() )->allowlist_ucss_selectors(
			array( '.akb-wishlist-btn', '.akb-eta-loop' )
		);

		self::assertContains( '.akb-wishlist-btn', $selectors, 'El filter es acumulativo: otro módulo ya aportó lo suyo.' );
		self::assertSame(
			array_values( array_unique( $selectors ) ),
			$selectors,
			'Claves reindexadas y sin repetidos: LiteSpeed recibe una lista limpia.'
		);
		self::assertSame( 1, count( array_keys( $selectors, '.akb-eta-loop', true ) ) );
	}

	/**
	 * Contrato de ORDEN en el DOM, no de estilo: loop.css colapsa el placeholder
	 * con `.akb-avail-hint--empty:has(~ .akb-eta-loop)`, que exige que la píldora
	 * de ETA salga DESPUÉS del hint. Empatados en 11 el orden lo decidía el orden
	 * de instanciación de módulos en Plugin.php — reordenarlos apagaba el colapso
	 * sin error y sin test rojo, solo con la grilla desalineada.
	 *
	 * @testdox Should keep the availability hint before the delivery pill on the same loop hook.
	 */
	public function test_hint_and_eta_pill_keep_their_relative_hook_order(): void {
		$enhancer = new LoopEnhancer();
		$enhancer->register();

		// El BannerRenderer real pide un DateCalculator que register() no toca.
		$banner = ( new ReflectionClass( BannerRenderer::class ) )->newInstanceWithoutConstructor();
		$banner->register();

		self::assertTrue(
			Actions\has(
				'woocommerce_after_shop_loop_item_title',
				array( $enhancer, 'render_availability_hint_loop' ),
				11
			),
			'El hint va en 11 (después del precio, que WC rinde en 10).'
		);
		self::assertTrue(
			Actions\has(
				'woocommerce_after_shop_loop_item_title',
				array( $banner, 'render_loop_item' ),
				12
			),
			'La píldora de ETA va en 12, NO empatada en 11: el `~` del CSS depende de ese orden.'
		);
	}
}
