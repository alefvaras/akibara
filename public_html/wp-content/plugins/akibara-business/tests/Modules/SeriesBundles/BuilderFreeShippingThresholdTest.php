<?php
/**
 * El armador de packs tiene que prometer lo MISMO que la barra del carrito.
 *
 * En esta tienda el envío gratis lo concede Blue Express dentro de su tarifa y
 * las instancias core `free_shipping` están apagadas a propósito
 * (`FreeShippingThreshold::for_current_cart()` devuelve `unavailable` si nadie
 * le pasa el umbral del transportista). El armador llamaba al servicio SIN ese
 * argumento, así que jamás decía cuánto faltaba: medido en la auditoría de
 * márgenes del 2026-09-02, un pack de 5 tomos de Ivrea deja el carrito a $44
 * del umbral y el cliente no se enteraba.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Modules\SeriesBundles;

use PHPUnit\Framework\TestCase;

final class BuilderFreeShippingThresholdTest extends TestCase {

	private function source(): string {
		return (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/SeriesBundles/BuilderWidget.php'
		);
	}

	/**
	 * @testdox El armador pasa el umbral del transportista al resolver el envío gratis.
	 */
	public function test_builder_passes_the_carrier_threshold(): void {
		$fuente = $this->source();

		self::assertMatchesRegularExpression(
			'/FreeShippingThreshold::for_current_cart\(\s*0\.0\s*,\s*\$carrier_threshold\s*\)/',
			$fuente,
			'Sin el segundo argumento el servicio devuelve unavailable y el armador calla el «te faltan $X».'
		);
		self::assertStringContainsString(
			"get_option( 'akibara_fsp_carrier_threshold', 0 )",
			$fuente,
			'El umbral sale de la MISMA option que la barra del carrito: dos fuentes = dos promesas distintas.'
		);
	}

	/**
	 * @testdox La barra del carrito y el armador leen la misma option.
	 */
	public function test_both_surfaces_read_the_same_option(): void {
		$barra = (string) file_get_contents(
			dirname( __DIR__, 3 ) . '/src/Modules/FreeShippingProgress/FreeShippingProgress.php'
		);

		self::assertStringContainsString( "'akibara_fsp_carrier_threshold'", $barra );
		self::assertStringContainsString( "'akibara_fsp_carrier_threshold'", $this->source() );
	}
}
