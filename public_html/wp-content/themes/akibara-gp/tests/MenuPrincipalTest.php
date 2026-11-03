<?php
/**
 * El menú principal: lo que el cliente usa para moverse por la tienda.
 *
 * 170 líneas que deciden cada enlace del header —y su `aria-current`, y si el
 * desplegable se abre con teclado— sin una sola prueba hasta hoy (ampliación de
 * cobertura, 2026-09-18). Lo que se fija acá es lo que se nota cuando se rompe:
 * un enlace sin `href`, un submenú sin `aria-controls` y la página actual sin
 * marcar.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class MenuPrincipalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `esc_url` y `esc_attr` NO se doblan: los define `tests/wp-stubs.php`
		// antes que Patchwork y doblarlos lanza «DefinedTooEarly»
		// [[function-exists-miente-en-la-suite]]. Los dos devuelven el valor tal
		// cual, que es justo lo que necesitan estas comprobaciones de estructura.
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'sanitize_html_class' )->returnArg();

		if ( ! class_exists( 'Walker_Nav_Menu' ) ) {
			eval( 'class Walker_Nav_Menu {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- doble mínimo del core; el walker sólo necesita heredar.
		}
		if ( ! class_exists( 'Akibara_GP_Mainnav_Walker' ) ) {
			require_once dirname( __DIR__ ) . '/inc/class-mainnav-walker.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Ítem de menú con la forma que entrega WordPress.
	 *
	 * @param string             $titulo  Texto visible.
	 * @param string             $url     Destino.
	 * @param array<int, string> $classes Clases del ítem.
	 * @param int                $id      ID del ítem.
	 */
	private function item( string $titulo, string $url, array $classes = array(), int $id = 7 ): object {
		return (object) array(
			'title'   => $titulo,
			'url'     => $url,
			'classes' => $classes,
			'ID'      => $id,
		);
	}

	/** @testdox Un ítem simple sale como enlace con su destino. */
	public function test_un_item_simple_es_un_enlace(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Tienda', 'https://akibara.cl/tienda/' ) );

		self::assertStringContainsString( 'href="https://akibara.cl/tienda/"', $salida );
		self::assertStringContainsString( 'Tienda', $salida );
		self::assertStringContainsString( 'mainnav__link', $salida );
	}

	/** @testdox La página actual se marca para el lector de pantalla, no sólo con color. */
	public function test_la_pagina_actual_se_anuncia(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Tienda', 'https://akibara.cl/tienda/', array( 'current-menu-item' ) ) );

		self::assertStringContainsString( 'aria-current="page"', $salida );
		self::assertStringContainsString( 'mainnav__link--active', $salida );
	}

	/**
	 * Texto de la etiqueta de apertura del primer elemento que cumple el patrón.
	 *
	 * @param string $html    Marcado.
	 * @param string $patron  Regex que casa la etiqueta de apertura entera.
	 */
	private function etiqueta( string $html, string $patron ): string {
		self::assertSame( 1, preg_match( $patron, $html, $m ), "No aparece {$patron} en: {$html}" );
		return $m[0];
	}

	/**
	 * @testdox Un ítem con hijos es un ENLACE que navega y, al lado, un BOTÓN que abre su panel (AI-8).
	 *
	 * Antes aria-haspopup/aria-expanded/aria-controls iban en el <a>: el lector
	 * anunciaba «menú emergente» en algo que al pulsar Enter navegaba, y el panel
	 * se abría solo al recibir el foco.
	 */
	public function test_un_item_con_hijos_es_enlace_mas_boton_de_desplegable(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Editoriales', 'https://akibara.cl/editoriales/', array( 'menu-item-has-children' ), 42 ) );

		$enlace = $this->etiqueta( $salida, '/<a [^>]*class="mainnav__link[^"]*"[^>]*>/' );
		self::assertStringContainsString( 'href="https://akibara.cl/editoriales/"', $enlace, 'El padre sigue llevando a su página.' );
		self::assertStringNotContainsString( 'aria-haspopup', $enlace, 'Un enlace que navega no es un «menú emergente».' );
		self::assertStringNotContainsString( 'aria-expanded', $enlace );

		$boton = $this->etiqueta( $salida, '/<button [^>]*>/' );
		self::assertStringContainsString( 'type="button"', $boton, 'Sin type="button" sería un submit si algún día cae dentro de un formulario.' );
		self::assertStringContainsString( 'class="mainnav__toggle"', $boton );
		self::assertStringContainsString( 'aria-expanded="false"', $boton );
		self::assertStringContainsString( 'aria-controls="mainnav-sub-42"', $boton, 'Sin `aria-controls`, el desplegable no existe para quien navega con teclado.' );
		self::assertStringContainsString( 'aria-label="Submenú de Editoriales"', $boton, 'Un botón sólo con una flecha necesita nombre.' );
		self::assertLessThan( strpos( $salida, '<button' ), strpos( $salida, '<a ' ), 'El enlace va antes que su botón: Tab recorre enlace → botón.' );
	}

	/** @testdox Un ítem de segundo nivel con hijos sigue el mismo patrón, con su propio panel. */
	public function test_un_subitem_con_hijos_es_enlace_mas_boton(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Argentinas', 'https://akibara.cl/argentinas/', array( 'menu-item-has-children' ), 77 ), 1 );

		// El enlace es el hermano que va justo antes del botón del sub-submenú.
		$enlace = $this->etiqueta( $salida, '/<a [^>]*class="mainnav__sublink"[^>]*>(?=Argentinas<\/a><button [^>]*class="mainnav__toggle mainnav__toggle--sub")/' );
		self::assertStringNotContainsString( 'aria-haspopup', $enlace );
		self::assertStringContainsString( 'href="https://akibara.cl/argentinas/"', $enlace, 'El subítem sigue llevando a su página.' );
		// Ningún CSS ni JS lee `mainnav__sublink--has-sub` desde que la flecha
		// pasó al botón (T5-R4): imprimirla era código muerto.
		self::assertStringNotContainsString( 'mainnav__sublink--has-sub', $salida );
		$boton = $this->etiqueta( $salida, '/<button [^>]*>/' );
		self::assertStringContainsString( 'class="mainnav__toggle mainnav__toggle--sub"', $boton );
		self::assertStringContainsString( 'aria-controls="mainnav-subsub-77"', $boton );
		self::assertStringContainsString( 'aria-expanded="false"', $boton );
	}

	/** @testdox El nombre del botón escapa el título del ítem, que escribe el dueño en Apariencia → Menús. */
	public function test_el_nombre_del_boton_se_escapa(): void {
		// `esc_attr` es el de tests/wp-stubs.php (htmlspecialchars con ENT_QUOTES).
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Cómics "raros"', 'https://akibara.cl/comics/', array( 'menu-item-has-children' ), 9 ) );

		self::assertStringContainsString( 'aria-label="Submenú de Cómics &quot;raros&quot;"', $salida );
	}

	/** @testdox Un ítem sin destino real no finge ser un enlace a ninguna parte. */
	public function test_un_item_sin_destino(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Sección', '', array( 'menu-item-has-children' ) ) );

		self::assertStringContainsString( 'href="#"', $salida );
	}

	/** @testdox El submenú se abre y se cierra con su contenedor y su id. */
	public function test_el_submenu_abre_y_cierra(): void {
		$walker = new \Akibara_GP_Mainnav_Walker();
		$salida = '';

		$walker->start_el( $salida, $this->item( 'Editoriales', 'https://akibara.cl/editoriales/', array( 'menu-item-has-children' ), 42 ) );
		$walker->start_lvl( $salida );
		$walker->end_lvl( $salida );

		self::assertStringContainsString( 'id="mainnav-sub-42"', $salida );
		// El `<div>` del ítem lo cierra `end_el()`, que es el siguiente paso del
		// walker: con él, el marcado queda balanceado.
		$walker->end_el( $salida, $this->item( 'Editoriales', 'https://akibara.cl/editoriales/', array( 'menu-item-has-children' ), 42 ) );
		self::assertSame(
			substr_count( $salida, '<div' ),
			substr_count( $salida, '</div>' ),
			'Un <div> sin cerrar en el header desmaqueta la página entera.'
		);
	}
}
