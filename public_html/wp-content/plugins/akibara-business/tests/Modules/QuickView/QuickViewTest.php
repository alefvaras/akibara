<?php
/**
 * Tests for the QuickView loop trigger.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\QuickView;

use Akibara\Business\Modules\QuickView\QuickView;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class QuickViewTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => trim( strip_tags( $text ) ) );
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/producto-x/' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub mínimo de WC_Product para el trigger.
	 *
	 * @param string $short Short description.
	 * @param string $long  Description.
	 */
	private function product( string $short, string $long = '' ): object {
		return new class( $short, $long ) extends \WC_Product {
			public function __construct( private string $short, private string $long ) {}
			public function get_id() { return 123; }
			public function get_name( $context = 'view' ) { return 'Dan Da Dan 01'; }
			public function get_short_description( $context = 'view' ) { return $this->short; }
			public function get_description( $context = 'view' ) { return $this->long; }
		};
	}

	/**
	 * @testdox Should truncate the synopsis at a word boundary with an ellipsis and never mid-word.
	 */
	public function test_synopsis_truncates_at_word_boundary(): void {
		$quick_view = new QuickView();
		$long       = str_repeat( 'palabra ', 60 ); // ~480 chars.

		$synopsis = $quick_view->synopsis( $this->product( $long ) );

		self::assertLessThanOrEqual( QuickView::SYNOPSIS_MAX_CHARS + 1, mb_strlen( $synopsis ) );
		self::assertStringEndsWith( '…', $synopsis );
		self::assertStringNotContainsString( 'palabr…', $synopsis, 'Never cut mid-word.' );
	}

	/**
	 * @testdox Should fall back to the long description and strip HTML.
	 */
	public function test_synopsis_falls_back_and_strips_tags(): void {
		$quick_view = new QuickView();

		$synopsis = $quick_view->synopsis( $this->product( '', '<p>Okarun y <strong>Momo</strong> contra lo oculto.</p>' ) );

		self::assertSame( 'Okarun y Momo contra lo oculto.', $synopsis );
	}

	/**
	 * @testdox Should keep editorial copy and remove repeated commercial sentences from the sheet.
	 */
	public function test_synopsis_removes_repeated_commercial_sentences(): void {
		$quick_view = new QuickView();
		$raw        = 'Adquirir The Walking Dead Tomo 1 en Akibara, Chile. Comic de Robert Kirkman. Acción original. Envío a todo Chile desde Akibara.';

		$synopsis = $quick_view->synopsis( $this->product( $raw ) );

		self::assertSame( 'Comic de Robert Kirkman. Acción original.', $synopsis );
	}

	/**
	 * @testdox Should use the long description when the short description only repeats logistics.
	 */
	public function test_synopsis_falls_back_when_short_copy_is_only_commercial(): void {
		$quick_view = new QuickView();

		$synopsis = $quick_view->synopsis(
			$this->product(
				'Envío según cobertura del transportista desde Akibara.',
				'<p>La supervivencia de Rick y su grupo se vuelve más incierta.</p>'
			)
		);

		self::assertSame( 'La supervivencia de Rick y su grupo se vuelve más incierta.', $synopsis );
	}

	/**
	 * @testdox Should remove a generic purchase lead while keeping the following plot sentence.
	 */
	public function test_synopsis_removes_generic_purchase_lead(): void {
		$quick_view = new QuickView();

		$synopsis = $quick_view->synopsis(
			$this->product( 'Compra One Piece Vol. El inicio de la épica historia de Luffy y los Sombrero de Paja.' )
		);

		self::assertSame( 'El inicio de la épica historia de Luffy y los Sombrero de Paja.', $synopsis );
	}

	/**
	 * @testdox Should preserve narrative uses of purchase verbs that are not storefront calls to action.
	 */
	public function test_synopsis_keeps_purchase_verbs_inside_the_plot(): void {
		$quick_view = new QuickView();

		$synopsis = $quick_view->synopsis(
			$this->product( 'Momo adquiere poderes inesperados. Okarun compra una espada para enfrentar al yokai.' )
		);

		self::assertSame(
			'Momo adquiere poderes inesperados. Okarun compra una espada para enfrentar al yokai.',
			$synopsis
		);
	}

	/**
	 * @testdox Should preserve narrative uses of words that can also describe store logistics.
	 */
	public function test_synopsis_keeps_logistics_words_without_commercial_context(): void {
		$quick_view = new QuickView();

		$synopsis = $quick_view->synopsis(
			$this->product( 'Durante el retiro de las tropas, el envío de refuerzos cambia el destino de la batalla.' )
		);

		self::assertSame(
			'Durante el retiro de las tropas, el envío de refuerzos cambia el destino de la batalla.',
			$synopsis
		);
	}

	/**
	 * @testdox Should render a hidden trigger with escaped JSON payload outside any anchor.
	 */
	public function test_trigger_renders_hidden_button_with_payload(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$quick_view          = new QuickView();
		$GLOBALS['product'] = $this->product( 'Sinopsis corta.' );

		ob_start();
		$quick_view->render_trigger();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );

		self::assertStringContainsString( 'akb-qv-trigger', $html );
		self::assertStringContainsString( 'hidden', $html, 'Hidden until JS confirms <dialog> support.' );
		self::assertStringContainsString( 'aria-haspopup="dialog"', $html );
		self::assertStringContainsString( 'Sinopsis corta.', $html );
		self::assertStringContainsString( 'https://akibara.cl/producto-x/', $html );
		self::assertStringNotContainsString( '<a ', $html, 'The trigger must never wrap or contain an anchor.' );
	}

	/**
	 * D5b: the label is the owner's copy (Akibara → Textos) and reaches the
	 * theme's hover tooltip through data-akb-qv-label; never via sprintf.
	 *
	 * @testdox Should use the panel label for the text, the accessible name and the theme tooltip.
	 */
	public function test_trigger_uses_the_panel_label(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'get_option' )->alias(
			static fn( $option, $default_value = false ) => QuickView::OPTION_LABEL === $option ? 'Mirar 100% rápido' : $default_value
		);

		$GLOBALS['product'] = $this->product( 'Sinopsis corta.' );
		ob_start();
		( new QuickView() )->render_trigger();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );

		self::assertStringContainsString( 'data-akb-qv-label="Mirar 100% rápido"', $html );
		self::assertMatchesRegularExpression( '/aria-label="Mirar 100% rápido: [^"]+"/', $html, 'Label in name: the accessible name starts with the visible label.' );
		self::assertStringContainsString( '>Mirar 100% rápido</button>', $html );
	}

	/**
	 * @testdox Should render nothing when the product has no synopsis at all.
	 */
	public function test_trigger_skipped_without_synopsis(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$quick_view          = new QuickView();
		$GLOBALS['product'] = $this->product( '', '' );

		ob_start();
		$quick_view->render_trigger();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );

		self::assertSame( '', $html );
	}

	/**
	 * @testdox Should stay inert outside catalog archives.
	 */
	public function test_trigger_skipped_outside_catalog(): void {
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );

		$quick_view          = new QuickView();
		$GLOBALS['product'] = $this->product( 'Sinopsis corta.' );

		ob_start();
		$quick_view->render_trigger();
		$html = (string) ob_get_clean();
		unset( $GLOBALS['product'] );

		self::assertSame( '', $html );
	}
}
