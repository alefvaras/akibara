<?php
/**
 * Contrato ejecutable de la cáscara de las páginas de baja un-clic.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Business\Tests\Services;

use Akibara\Business\Services\OneClickPage;
use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class OneClickPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->alias(
			static fn( $value ) => htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' )
		);
		Functions\when( 'status_header' )->justReturn( null );
		Functions\when( 'nocache_headers' )->justReturn( null );
		// Sin cortar el `exit`, la prueba muere con el proceso.
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => 'akb_test_should_exit' === $hook ? false : $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		OneClickPage::render( 'Avisos de serie', '<h1>Listo</h1>', 'akb_test_should_exit' );
		return (string) ob_get_clean();
	}

	/**
	 * El flujo de baja se abre desde un correo y hasta hoy llegaba a una
	 * pantalla anónima: el cliente no podía saber de quién se estaba dando de
	 * baja. El wordmark va ANTES del `<main>` y en el rojo de marca.
	 *
	 * @testdox La página de baja lleva el wordmark AKIBARA antes del contenido.
	 */
	public function test_la_pagina_lleva_el_wordmark_antes_del_main(): void {
		$html = $this->render();

		self::assertStringContainsString( 'AKIBARA', $html );
		self::assertLessThan(
			strpos( $html, '<main>' ),
			strpos( $html, 'AKIBARA' ),
			'El wordmark va ARRIBA del contenido, no debajo.'
		);
		self::assertStringContainsString( 'color:#E60012', $html, 'Rojo de marca, no un rojo cualquiera.' );
	}

	/**
	 * Sin assets externos a propósito: una petición extra que llegue tarde o
	 * falle dejaría la pantalla sin marca justo en el momento que importa.
	 *
	 * @testdox El wordmark es tipográfico: ni <img> ni hojas ni fuentes remotas.
	 */
	public function test_el_wordmark_no_trae_assets_externos(): void {
		$html = $this->render();

		self::assertStringNotContainsString( '<img', $html );
		self::assertStringNotContainsString( '<link', $html );
		self::assertStringNotContainsString( '@import', $html );
		self::assertStringNotContainsString( 'https://fonts.', $html );
	}

	/**
	 * La marca ya viaja en el `<title>`; repetirla en el árbol de accesibilidad
	 * sólo añade ruido antes del `<h1>` que de verdad dice qué pasó.
	 *
	 * @testdox El wordmark está oculto para lectores de pantalla.
	 */
	public function test_el_wordmark_es_decorativo_para_lectores_de_pantalla(): void {
		self::assertMatchesRegularExpression(
			'/<div class="akb-wordmark" aria-hidden="true">AKIBARA<\/div>/',
			$this->render()
		);
	}

	/**
	 * N-bis-unsub (2026-09-19, medido en producción): `nocache_headers()` es una
	 * cabecera de ORIGEN y el borde de Cloudflare la pisa con `override_origin`
	 * (`cf-cache-status: HIT` medido en `/?akb_bis_unsub=…` con diez minutos de
	 * caché encima). LiteSpeed sólo se rinde ante `DONOTCACHEPAGE` o su propio
	 * hook: sin esto, un segundo cliente que abre el mismo enlace de baja
	 * durante la ventana de caché puede recibir la respuesta guardada para
	 * OTRO token.
	 *
	 * @testdox Toda página de un clic apaga la caché de LiteSpeed, no sólo la cabecera del origen.
	 */
	public function test_apaga_la_cache_de_litespeed_ademas_de_la_cabecera_de_origen(): void {
		Actions\expectDone( 'litespeed_control_set_nocache' )->once();

		$this->render();

		self::assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}
}
