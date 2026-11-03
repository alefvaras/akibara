<?php
/**
 * Guardar una diapositiva del hero sin imagen no puede borrar lo tecleado.
 *
 * `Settings::sanitize()` descartaba entera la slide que llegaba sin
 * `desktop_id` ni `mobile_id`: título, bajada y los CTAs desaparecían al
 * guardar sin ningún aviso, y el dueño lo descubría recién al recargar la
 * pantalla. `Settings::active_slides()` —la misma condición que usa
 * `Renderer`— ya se encarga de que esa slide no aparezca en el sitio hasta que
 * tenga imagen; `sanitize()` no necesitaba además destruir el trabajo tecleado
 * (S-8f270422, 2026-09-19).
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\HomeHero;

use Akibara\Business\Modules\HomeHero\Settings;
use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsSlideWithoutImageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'absint' )->alias( static fn( $v ): int => abs( (int) $v ) );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $v ): string => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @testdox Una slide sin imagen conserva título, bajada y CTAs tras guardar.
	 */
	public function test_una_slide_sin_imagen_no_pierde_lo_tecleado(): void {
		$raw = array(
			'slides' => array(
				array(
					'desktop_id' => 0,
					'mobile_id'  => 0,
					'title'      => 'Naruto llega en octubre',
					'eyebrow'    => 'PREVENTA',
					'lede'       => 'Reserva tu ejemplar antes de que se agote.',
					'ctas'       => array(
						array( 'label' => 'Reservar', 'url' => '/producto/naruto-1/', 'style' => 'manga' ),
					),
				),
			),
		);

		$clean = Settings::sanitize( $raw );

		self::assertCount( 1, $clean['slides'], 'La slide sin imagen se descartaba entera al guardar.' );
		self::assertSame( 'Naruto llega en octubre', $clean['slides'][0]['title'] );
		self::assertSame( 'PREVENTA', $clean['slides'][0]['eyebrow'] );
		self::assertSame( 'Reserva tu ejemplar antes de que se agote.', $clean['slides'][0]['lede'] );
		self::assertCount( 1, $clean['slides'][0]['ctas'] );
		self::assertSame( 'Reservar', $clean['slides'][0]['ctas'][0]['label'] );
	}

	/**
	 * `active_slides()` es la barrera real de visualización (misma condición
	 * que `Renderer`): conservar la slide en `sanitize()` no debe hacer que una
	 * slide sin imagen aparezca en el sitio.
	 *
	 * @testdox `active_slides()` sigue excluyendo la slide sin imagen del sitio.
	 */
	public function test_active_slides_sigue_ocultando_la_slide_sin_imagen(): void {
		$raw = array(
			'slides' => array(
				array(
					'desktop_id' => 0,
					'mobile_id'  => 0,
					'title'      => 'Sin imagen todavía',
				),
				array(
					'desktop_id' => 42,
					'mobile_id'  => 0,
					'title'      => 'Con imagen',
				),
			),
		);

		$clean = Settings::sanitize( $raw );

		self::assertCount( 2, $clean['slides'], 'Las dos slides deben conservarse en el storage.' );
		self::assertCount(
			1,
			Settings::active_slides( $clean ),
			'Sólo la slide CON imagen debe considerarse activa para el sitio.'
		);
		self::assertSame( 'Con imagen', Settings::active_slides( $clean )[0]['title'] );
	}
}
