<?php
/**
 * Tests for HomeHero progressive media rendering.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\Renderer;
use Akibara\Business\Modules\HomeHero\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );

final class RendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'sanitize_html_class' )->returnArg( 1 );
		Functions\when( 'get_the_ID' )->justReturn( 210 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_get_upload_dir' )->justReturn(
			array(
				'basedir' => '/tmp/akb-home-hero-tests',
				'baseurl' => 'https://example.test/wp-content/uploads',
			)
		);
		Functions\when( 'get_attached_file' )->alias(
			static fn( int $attachment_id ): string => '/tmp/akb-home-hero-tests/slide-' . $attachment_id . '.avif'
		);
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( int $attachment_id ): array => array(
				'width'  => $attachment_id >= 200 ? 768 : 1920,
				'height' => $attachment_id >= 200 ? 1377 : 811,
			)
		);
		Functions\when( 'wp_get_attachment_image_srcset' )->alias(
			static function ( int $attachment_id ): string {
				$max_width = $attachment_id >= 200 ? 768 : 1920;
				return sprintf(
					'https://example.test/wp-content/uploads/slide-%1$d-350.avif 350w, https://example.test/wp-content/uploads/slide-%1$d.avif %2$dw',
					$attachment_id,
					$max_width
				);
			}
		);
		Functions\when( 'wp_get_attachment_image_sizes' )->alias(
			static fn( int $attachment_id ): string => $attachment_id >= 200
				? '(max-width: 768px) 100vw, 768px'
				: '(max-width: 1920px) 100vw, 1920px'
		);
		Functions\when( 'wp_parse_url' )->alias(
			static function ( string $url, int $component = -1 ) {
				return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_only_first_slide_picture_is_rendered_directly_and_high_priority(): void {
		$html = ( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) );

		self::assertSame( 2, substr_count( $html, '<template data-akb-hero-media>' ) );
		self::assertSame( 2, substr_count( $html, '<noscript>' ) );
		self::assertSame( 1, substr_count( $html, 'loading="eager"' ) );
		self::assertSame( 1, substr_count( $html, 'fetchpriority="high"' ) );
		self::assertSame( 4, substr_count( $html, 'loading="lazy"' ) );

		$first_picture  = strpos( $html, '<picture class="akb-hero__picture"' );
		$first_template = strpos( $html, '<template data-akb-hero-media>' );
		self::assertIsInt( $first_picture );
		self::assertIsInt( $first_template );
		self::assertLessThan( $first_template, $first_picture );

		// Slide 0 aparece una vez; los diferidos aparecen en template + noscript.
		self::assertSame( 2, substr_count( $html, 'slide-101.avif' ) );
		self::assertSame( 1, substr_count( $html, 'slide-201.avif' ) );
		self::assertSame( 4, substr_count( $html, 'slide-102.avif' ) );
		self::assertSame( 2, substr_count( $html, 'slide-202.avif' ) );
		self::assertSame( 2, substr_count( $html, 'alt="Arte slide 3"' ) );
		self::assertSame( 5, substr_count( $html, 'width="1920"' ) );
		self::assertSame( 5, substr_count( $html, 'height="811"' ) );
	}

	public function test_picture_uses_wordpress_derivatives_for_both_art_directed_sources(): void {
		$html = ( new Renderer() )->render_from_settings( $this->settings_with_slides( 1 ) );

		self::assertStringContainsString(
			'srcset="https://example.test/wp-content/uploads/slide-201-350.avif 350w, https://example.test/wp-content/uploads/slide-201.avif 768w"',
			$html
		);
		self::assertStringContainsString( 'sizes="(max-width: 768px) 100vw, 768px"', $html );
		self::assertStringContainsString(
			'srcset="https://example.test/wp-content/uploads/slide-101-350.avif 350w, https://example.test/wp-content/uploads/slide-101.avif 1920w"',
			$html
		);
		self::assertStringContainsString( 'sizes="(max-width: 1920px) 100vw, 1920px"', $html );
	}

	/**
	 * AI-13 (auditoría 2026-09-22): el indicador se pinta ARRIBA del hero y el
	 * CTA del slide abajo; con el indicador detrás del track en el DOM, el Tab
	 * subía del CTA a los segmentos y volvía a bajar. Y un <nav> por slide
	 * sumaba landmarks «Acciones del slide».
	 *
	 * @testdox Los segmentos del carrusel van antes que el contenido de los slides en el DOM, y las acciones no son un landmark.
	 */
	public function test_indicator_precedes_slides_and_actions_are_not_a_landmark(): void {
		$settings = $this->settings_with_slides( 3 );
		foreach ( $settings['slides'] as $i => $slide ) {
			$settings['slides'][ $i ]['ctas'] = array( array( 'label' => 'Ver novedades', 'url' => 'https://example.test/novedades/', 'style' => 'shop' ) );
		}
		$html = ( new Renderer() )->render_from_settings( $settings );

		$indicador = strpos( $html, 'class="akb-hero__seg' );
		$track     = strpos( $html, 'class="akb-hero__track"' );
		$cta       = strpos( $html, 'class="akb-hero__action' );
		self::assertNotFalse( $indicador );
		self::assertNotFalse( $cta );
		self::assertLessThan( $track, $indicador, 'El indicador va antes del track.' );
		self::assertLessThan( $cta, $indicador, 'Y antes de cualquier CTA de un slide.' );
		self::assertStringNotContainsString( '<nav class="akb-hero__actions"', $html );
		self::assertStringContainsString( '<div class="akb-hero__actions">', $html );
	}

	public function test_carousel_is_accessible_without_javascript_and_controls_start_hidden(): void {
		$html = ( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) );

		preg_match_all( '/<div\s+class="akb-hero__slide[^>]*>/s', $html, $slide_openings );
		self::assertCount( 3, $slide_openings[0] );
		foreach ( $slide_openings[0] as $slide_opening ) {
			self::assertStringNotContainsString( 'aria-hidden', $slide_opening );
		}

		self::assertMatchesRegularExpression( '/class="akb-hero__progress[^"]*"[^>]*\shidden(?:\s|>)/s', $html );
		self::assertStringContainsString(
			'class="akb-hero__arrow akb-hero__arrow--prev" aria-label="Slide anterior" hidden',
			$html
		);
		self::assertStringContainsString(
			'class="akb-hero__arrow akb-hero__arrow--next" aria-label="Slide siguiente" hidden',
			$html
		);

		// Todo el contenido editorial/SEO sigue server-rendered aun con media diferida.
		self::assertStringContainsString( '<h1', $html );
		self::assertSame( 2, substr_count( $html, '<h2' ) );
		self::assertSame( 3, substr_count( $html, 'class="akb-hero__action akb-hero__action--shop"' ) );
	}

	/**
	 * @testdox Should use the first non-empty title as the primary heading.
	 */
	public function test_first_non_empty_title_is_primary_heading(): void {
		$settings                       = $this->settings_with_slides( 3 );
		$settings['slides'][0]['title'] = '';

		$html = ( new Renderer() )->render_from_settings( $settings );

		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertSame( 1, substr_count( $html, '<h2' ) );
		self::assertMatchesRegularExpression(
			'/<h1[^>]*>Título slide 2<\/h1>/s',
			$html
		);
		self::assertMatchesRegularExpression(
			'/<h2[^>]*>Título slide 3<\/h2>/s',
			$html
		);
	}

	/**
	 * @testdox Should emit unique IDs and matching ARIA references for repeated renders.
	 */
	public function test_repeated_renders_use_unique_ids_with_matching_aria_references(): void {
		$settings = $this->settings_with_slides( 1 );
		$first    = ( new Renderer() )->render_from_settings( $settings );
		$second   = ( new Renderer() )->render_from_settings( $settings );

		self::assertSame(
			1,
			preg_match( '/<section[^>]*aria-labelledby="([^"]+)"/s', $first, $first_aria )
		);
		self::assertSame(
			1,
			preg_match( '/<section[^>]*aria-labelledby="([^"]+)"/s', $second, $second_aria )
		);
		self::assertNotSame( $first_aria[1], $second_aria[1] );
		self::assertStringContainsString( 'id="' . $first_aria[1] . '"', $first );
		self::assertStringContainsString( 'id="' . $second_aria[1] . '"', $second );

		self::assertSame( 1, preg_match( '/class="akb-hero__track" id="([^"]+)"/', $first, $first_track ) );
		self::assertSame( 1, preg_match( '/class="akb-hero__track" id="([^"]+)"/', $second, $second_track ) );
		self::assertNotSame( $first_track[1], $second_track[1] );
	}

	public function test_effective_autoplay_state_controls_static_progress_fill(): void {
		$plugin_root = dirname( __DIR__, 3 );
		$javascript  = (string) file_get_contents( $plugin_root . '/assets/js/home-hero.js' );
		$stylesheet  = (string) file_get_contents( $plugin_root . '/assets/css/home-hero.css' );

		self::assertStringContainsString(
			"cfg.autoplay = configuredAutoplay && ! reduced && desktopAutoplayMql.matches;",
			$javascript
		);
		self::assertStringContainsString(
			"root.setAttribute( 'data-akb-autoplay-active', cfg.autoplay ? '1' : '0' );",
			$javascript
		);
		self::assertStringContainsString(
			'.akb-hero[data-akb-autoplay-active="0"] .akb-hero__seg.is-active .akb-hero__seg-fill',
			$stylesheet
		);
	}

	public function test_carousel_labels_include_position_and_editorial_title(): void {
		$html        = ( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) );
		$plugin_root = dirname( __DIR__, 3 );
		$javascript  = (string) file_get_contents( $plugin_root . '/assets/js/home-hero.js' );

		self::assertStringContainsString( 'aria-label="Slide 1 de 3: Título slide 1"', $html );
		self::assertStringContainsString( 'aria-label="Ir al slide 2 de 3: Título slide 2"', $html );
		self::assertStringContainsString(
			'class="akb-hero__sr-status" role="status" aria-live="polite" aria-atomic="true">Slide 1 de 3: Título slide 1</span>',
			$html
		);
		self::assertStringContainsString(
			"slides[ idx ].getAttribute( 'aria-label' )",
			$javascript
		);
	}

	/**
	 * QA-ESC-TECLADO-13: el carrusel se anunciaba «Hero rotativo de Akibara,
	 * carrusel», jerga interna leída a los clientes y fija en el código. El
	 * nombre es un texto del dueño (Akibara → Textos) con «Destacados» de
	 * fábrica; vacío o sólo espacios vuelve al de fábrica.
	 *
	 * @testdox El carrusel y el hero sin título se anuncian con el nombre de Textos, «Destacados» de fábrica; nunca con jerga.
	 */
	public function test_hero_region_name_is_the_owner_copy(): void {
		$GLOBALS['akibara_test_options'] = array();

		try {
			$carrusel = ( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) );
			self::assertStringContainsString( 'aria-roledescription="carrusel" aria-label="Destacados"', $carrusel );
			self::assertStringNotContainsString( 'Hero', $carrusel, 'Ningún nombre accesible dice «Hero».' );

			$sin_titulo                       = $this->settings_with_slides( 1 );
			$sin_titulo['slides'][0]['title'] = '';
			$estatico                         = ( new Renderer() )->render_from_settings( $sin_titulo );
			self::assertMatchesRegularExpression( '/<section[^>]*aria-label="Destacados"/s', $estatico, 'El hero de un solo slide sin título también se nombra con el texto del dueño.' );
			self::assertStringNotContainsString( 'Hero', $estatico );

			$GLOBALS['akibara_test_options'][ Renderer::OPTION_LABEL ] = "  Lo  nuevo\nen Akibara ";
			self::assertStringContainsString(
				'aria-label="Lo nuevo en Akibara"',
				( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) ),
				'El texto del dueño, en una línea.'
			);

			$GLOBALS['akibara_test_options'][ Renderer::OPTION_LABEL ] = '   ';
			self::assertStringContainsString(
				'aria-roledescription="carrusel" aria-label="Destacados"',
				( new Renderer() )->render_from_settings( $this->settings_with_slides( 3 ) ),
				'Guardado vacío no deja el carrusel sin nombre.'
			);
		} finally {
			unset( $GLOBALS['akibara_test_options'] );
		}
	}

	public function test_empty_alt_is_decorative_without_removing_editorial_alt_support(): void {
		$settings                     = $this->settings_with_slides( 1 );
		$settings['slides'][0]['alt'] = '';
		$decorative_html              = ( new Renderer() )->render_from_settings( $settings );

		self::assertSame( 1, preg_match( '/<img\s+[^>]*class="akb-hero__img[^>]*>/s', $decorative_html, $match ) );
		self::assertStringContainsString( 'alt=""', $match[0] );
		self::assertStringContainsString( 'aria-hidden="true"', $match[0] );

		$settings['slides'][0]['alt'] = 'Arte editorial único';
		$editorial_html               = ( new Renderer() )->render_from_settings( $settings );

		self::assertSame( 1, preg_match( '/<img\s+[^>]*class="akb-hero__img[^>]*>/s', $editorial_html, $match ) );
		self::assertStringContainsString( 'alt="Arte editorial único"', $match[0] );
		self::assertStringNotContainsString( 'aria-hidden="true"', $match[0] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings_with_slides( int $count ): array {
		$slides = array();
		for ( $idx = 1; $idx <= $count; $idx++ ) {
			$slides[] = array_merge(
				Settings::default_slide(),
				array(
					'desktop_id' => 100 + $idx,
					'mobile_id'  => 200 + $idx,
					'alt'        => 'Arte slide ' . $idx,
					'title'      => 'Título slide ' . $idx,
					'ctas'       => array(
						array(
							'label' => 'Ver slide ' . $idx,
							'url'   => 'https://example.test/slide-' . $idx,
							'style' => 'shop',
						),
					),
				)
			);
		}

		return array(
			'version'  => Settings::SCHEMA_VERSION,
			'slides'   => $slides,
			'carousel' => array_merge(
				Settings::default_carousel(),
				array( 'autoplay' => true )
			),
		);
	}
}
