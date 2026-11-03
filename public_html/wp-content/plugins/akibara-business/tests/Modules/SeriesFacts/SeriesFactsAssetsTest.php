<?php
/**
 * La hoja del resumen, el atajo y la ficha de serie sólo viaja a las landings.
 *
 * QA integral 2026-09-24 (QA-RC-4): esas reglas iban en `estimated-delivery.css`,
 * servida bloqueante en la tienda, la ficha, el carrito, la cuenta, el 404 y la
 * portada, donde eran 4,3 de sus 9 KB minificados sin casar con nada.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesFacts;

use Akibara\Business\Modules\SeriesFacts\SeriesFacts;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SeriesFactsAssetsTest extends TestCase {

	/** @var list<string> Handles encolados. */
	private array $encoladas = array();

	/** Taxonomía de la consulta simulada ('' = no es un archive de taxonomía). */
	private string $taxonomia = '';

	/**
	 * The System Under Test.
	 */
	private SeriesFacts $sut;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->encoladas = array();
		$this->taxonomia = '';
		$this->sut       = new SeriesFacts();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_tax' )->alias( fn( $taxonomy = '' ): bool => '' !== $this->taxonomia && $taxonomy === $this->taxonomia );
		Functions\when( 'get_queried_object' )->alias(
			function () {
				if ( '' === $this->taxonomia ) {
					return null;
				}
				$term                   = new \WP_Term( 400, 'jujutsu-kaisen', 'Jujutsu Kaisen' );
				$term->taxonomy         = $this->taxonomia;
				$term->term_taxonomy_id = 400;

				return $term;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $handle ): void {
				$this->encoladas[] = $handle;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Should register its own stylesheet hook.
	 */
	public function test_registers_the_enqueue(): void {
		$this->sut->register();

		self::assertSame( 10, Monkey\Actions\has( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_assets' ) ) );
	}

	/**
	 * @testdox Should enqueue the series facts sheet on a series landing and nowhere else.
	 */
	public function test_enqueues_only_on_series_landings(): void {
		$this->taxonomia = 'pa_serie';
		$this->sut->enqueue_assets();
		self::assertSame( array( 'akb-series-facts' ), $this->encoladas );

		$this->encoladas = array();
		$this->taxonomia = 'product_cat';
		$this->sut->enqueue_assets();
		self::assertSame( array(), $this->encoladas, 'Una categoría no pinta resumen ni ficha de serie.' );

		$this->taxonomia = '';
		$this->sut->enqueue_assets();
		self::assertSame( array(), $this->encoladas, 'Ni la tienda, ni la ficha, ni la portada.' );
	}

	/**
	 * @testdox Should keep every series summary, shortcut and sheet rule out of the sheet the whole store loads.
	 */
	public function test_the_rules_live_in_their_own_sheet(): void {
		$raiz   = dirname( __DIR__, 3 ) . '/assets/css/';
		$global = (string) file_get_contents( $raiz . 'estimated-delivery.css' );
		$propia = (string) file_get_contents( $raiz . 'series-facts.css' );
		$sin_comentarios = static fn( string $css ): string => (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		foreach ( array( '.akb-series-summary', '.akb-series-latest', '.akb-series-facts', '.akb-sb-landing' ) as $clase ) {
			self::assertStringNotContainsString( $clase, $sin_comentarios( $global ), $clase . ' no se usa donde se sirve estimated-delivery.css.' );
		}
		foreach ( array( '.akb-series-summary {', '.akb-series-summary__more:focus-visible', '.akb-series-latest {', '.akb-series-facts {', '.akb-series-facts__link::after' ) as $regla ) {
			self::assertStringContainsString( $regla, $propia, $regla );
		}
	}

	/**
	 * @testdox Should separate the latest-volume shortcut from the total with a widened space, not a margin that travels with it when it wraps.
	 */
	public function test_the_shortcut_is_separated_by_a_widened_space(): void {
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/series-facts.css' ) );
		$regla = static function ( string $selector ) use ( $css ): string {
			$m = array();
			preg_match( '/(?:^|\})\s*' . preg_quote( $selector, '/' ) . '\s*\{([^}]*)\}/', $css, $m );

			return $m[1] ?? '';
		};

		// Revisión RN-REV1-2: con `margin-left`, el atajo que bajaba de línea en
		// 320 px arrancaba 12 px a la derecha de «109 productos».
		self::assertNotSame( '', $regla( '.akb-series-latest' ), 'La regla del atajo existe.' );
		self::assertStringNotContainsString( 'margin', $regla( '.akb-series-latest' ), 'El atajo no lleva margen propio.' );
		self::assertStringContainsString( 'word-spacing: var(--space-3, 12px)', $regla( '.akb-result-count--extra' ), 'El espacio del HTML se ensancha lo que sumaba el margen.' );
		self::assertStringContainsString( 'word-spacing: normal', $regla( '.akb-result-count--extra > *' ), 'Los hijos vuelven al espaciado normal.' );
	}
}
