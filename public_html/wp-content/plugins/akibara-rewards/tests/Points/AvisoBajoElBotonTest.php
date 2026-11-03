<?php
/**
 * El aviso «Gana N Puntos Akibara» va bajo el botón de compra (UC-4b).
 *
 * Pegado al precio (prioridad 11 del resumen), sus dos líneas bajaban el botón:
 * a 1366x768 el botón real de un tomo «a pedido» quedaba en 707-752 px, tapado
 * por la barra fija de la ficha (medido en el espejo el 2026-09-23). Ahora sale
 * bajo el formulario de compra y, donde no hay formulario (agotado), por el
 * respaldo del resumen a 32. Cuelga de dos ganchos, así que lo que se fija aquí
 * es que salga UNA vez.
 *
 * Con eso el botón queda a la vista SÓLO en los tomos a pedido sin valoraciones;
 * en uno con valoraciones lo tapan filas de business y la barra del tema. La
 * geometría se mide en rewards-aviso-de-puntos.spec.mjs (tools/akibara-e2e), que
 * lleva ese caso como pendiente, con dueño.
 *
 * @package Akibara\Rewards\Tests
 */

declare( strict_types = 1 );

namespace Akibara\Rewards\Tests\Points;

use Akibara\Rewards\Points\PdpNotice;
use Akibara\Rewards\Tests\Support\RewardsTestCase;
use Brain\Monkey\Functions;

/**
 * @since 0.4.18
 */
final class AvisoBajoElBotonTest extends RewardsTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PdpNotice
	 */
	private PdpNotice $sut;

	protected function setUp(): void {
		parent::setUp();
		$this->sut = new PdpNotice();

		Functions\when( 'wc_get_price_including_tax' )->justReturn( 10000.0 );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_page_by_path' )->justReturn( null );
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	/**
	 * Un tomo comprable con el ID dado.
	 *
	 * @param int $id ID del producto.
	 * @return \WC_Product
	 */
	private static function tomo( int $id ): \WC_Product {
		$tomo     = new class() extends \WC_Product {
			public function is_purchasable(): bool {
				return true;
			}
		};
		$tomo->id = $id;
		return $tomo;
	}

	/**
	 * Lo que el aviso pinta en una llamada.
	 *
	 * @return string
	 */
	private function pintar(): string {
		ob_start();
		$this->sut->render();
		return (string) ob_get_clean();
	}

	/**
	 * @testdox Cuelga del final del formulario de compra (10) y del resumen a 32, y ya no del 11 junto al precio.
	 */
	public function test_cuelga_bajo_el_boton_y_no_junto_al_precio(): void {
		$this->sut->register();

		$this->assertSame(
			10,
			has_action( 'woocommerce_after_add_to_cart_form', array( $this->sut, 'render' ) ),
			'Bajo el botón: después del despacho (5) y antes de los sellos del tema (15).'
		);
		$this->assertSame(
			32,
			has_action( 'woocommerce_single_product_summary', array( $this->sut, 'render' ) ),
			'El respaldo del agotado va tras las promesas (31) y sin empatar con ellas.'
		);
	}

	/**
	 * @testdox Si ya salió bajo el botón, el respaldo del resumen no lo repite.
	 */
	public function test_el_respaldo_no_repite_el_aviso(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 14200 ) );

		$bajo_el_boton = $this->pintar();
		$respaldo      = $this->pintar();

		$this->assertSame( 1, substr_count( $bajo_el_boton, 'akb-rw-pdp-points' ), 'La primera llamada lo pinta.' );
		$this->assertSame( '', $respaldo, 'La segunda (el resumen a 32) no lo repite.' );
	}

	/**
	 * @testdox Sin formulario (agotado), el respaldo del resumen lo pinta.
	 */
	public function test_sin_formulario_lo_pinta_el_respaldo(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 16083 ) );

		// El gancho del formulario nunca llegó: la única llamada es la del resumen.
		$html = $this->pintar();

		$this->assertStringContainsString( '200 Puntos Akibara = $200', $html );
	}

	/**
	 * @testdox El «ya salió» es por producto: otra ficha en el mismo proceso vuelve a pintarlo.
	 */
	public function test_el_candado_es_por_producto(): void {
		$tomos = array( self::tomo( 1 ), self::tomo( 2 ) );
		Functions\when( 'wc_get_product' )->alias(
			static function () use ( &$tomos ) {
				return array_shift( $tomos );
			}
		);

		$this->assertNotSame( '', $this->pintar() );
		$this->assertNotSame( '', $this->pintar(), 'El segundo producto no hereda el candado del primero.' );
	}

	/**
	 * Si la promesa se queda en cero (programa sin empezar), no se pinta nada y
	 * el candado no se cierra: el respaldo vuelve a preguntar y vuelve a callar.
	 *
	 * @testdox Una promesa en cero no pinta nada por ninguno de los dos ganchos.
	 */
	public function test_promesa_en_cero_no_pinta_nada(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 3 ) );
		Functions\when( 'get_option' )->alias(
			static fn( string $option, $default_value = false ) => 'akb_rewards_program_start' === $option ? '2099-01-01 00:00:00' : $default_value
		);

		$this->assertSame( '', $this->pintar() );
		$this->assertSame( '', $this->pintar() );
	}

	/**
	 * UM-15/AI-7: «Ver programa» medía 90x17. El relleno vertical de un enlace
	 * en línea agranda su área de toque sin cambiar el alto de la línea.
	 *
	 * @testdox «Ver programa» lleva relleno vertical para llegar a 24 px de alto.
	 */
	public function test_ver_programa_tiene_area_de_toque(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 4 ) );
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug ) {
				$page     = new \WP_Post( 27519 );
				$page->ID = 27519;
				return 'akibara-puntos' === $slug ? $page : null;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/akibara-puntos/' );

		$html = $this->pintar();

		$this->assertMatchesRegularExpression(
			'#<a href="https://akibara\.cl/akibara-puntos/" style="[^"]*padding:4px 0[^"]*">Ver programa</a>#',
			$html
		);
	}

	/**
	 * REV-F-R-rev2-2: con la página del programa en privado (o en borrador), la
	 * ficha pintaba «Ver programa» hacia una página que da 404 al invitado.
	 *
	 * @testdox Con la página del programa sin publicar, el aviso sale sin «Ver programa».
	 */
	public function test_sin_pagina_publicada_no_hay_ver_programa(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 6 ) );
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug ) {
				$page              = new \WP_Post( 27519 );
				$page->post_status = 'private';
				return 'akibara-puntos' === $slug ? $page : null;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/akibara-puntos/' );

		$html = $this->pintar();

		$this->assertStringContainsString( 'Puntos Akibara', $html, 'El aviso sigue saliendo.' );
		$this->assertStringNotContainsString( 'Ver programa', $html );
		$this->assertStringNotContainsString( '<a ', $html );
	}

	/**
	 * QA-RW-2: en WebKit a 390 px el corte de línea caía DENTRO del enlace:
	 * «Ver» (22x25) al final de una línea y «programa» (64x25) en la siguiente,
	 * solapados 3 px por el relleno. El enlace va entero en una línea, y el
	 * espacio que lo separa del texto queda fuera: ahí se parte la línea.
	 *
	 * @testdox «Ver programa» no se parte en dos líneas: baja entero.
	 */
	public function test_ver_programa_no_se_parte(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 5 ) );
		Functions\when( 'get_page_by_path' )->alias(
			static function ( string $slug ) {
				$page     = new \WP_Post( 27519 );
				$page->ID = 27519;
				return 'akibara-puntos' === $slug ? $page : null;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'https://akibara.cl/akibara-puntos/' );

		$html = $this->pintar();

		$this->assertMatchesRegularExpression(
			'#con este tomo\. <a href="[^"]+" style="[^"]*white-space:nowrap[^"]*">Ver programa</a>#',
			$html,
			'El espacio de corte va FUERA del enlace y el enlace no se parte.'
		);
	}

	/**
	 * H-06: sobre el fondo oscuro de la ficha (#0e0e10), --aki-red (#E60012) daba
	 * 4.31:1 en la estrella (< 4.5:1 WCAG AA). --aki-red-text (#ff4756) da 5.6:1 y
	 * aria-hidden evita que el lector de pantalla deletree el símbolo decorativo.
	 *
	 * @testdox La estrella usa --aki-red-text (#ff4756) para cumplir contraste AA sobre fondo oscuro.
	 */
	public function test_estrella_usa_rojo_de_texto_con_contraste_aa(): void {
		Functions\when( 'wc_get_product' )->justReturn( self::tomo( 7 ) );

		$html = $this->pintar();

		$this->assertStringContainsString(
			'<span aria-hidden="true" style="color:var(--aki-red-text,#ff4756);margin-right:4px">★</span>',
			$html
		);
	}
}
