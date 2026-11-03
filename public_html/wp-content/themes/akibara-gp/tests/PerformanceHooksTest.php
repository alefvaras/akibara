<?php
/**
 * Contrato ejecutable de los callbacks de `inc/performance.php`.
 *
 * Son filtros que MUTAN atributos de imagen y etiquetas <link> servidas a todo
 * visitante del home. La cobertura previa del theme comprobaba que el texto del
 * archivo no cambiara; acá se ejecuta la función y se afirma sobre su salida,
 * que es lo único que puede romper la página (THM-04/THM-06).
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/page-targets.php';

/**
 * @coversNothing
 */
final class PerformanceHooksTest extends TestCase {

	/**
	 * Marcado real con que SeriesFacts abre el resumen de una línea que pinta
	 * ENCIMA de la rejilla (`render_summary()`, `woocommerce_before_shop_loop` 2).
	 * La ficha completa va bajo la rejilla desde el lote B3.
	 */
	private const RESUMEN_DE_SERIE = '<div class="akb-series-summary"><dl class="akb-series-summary__facts"></dl></div>';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// `inc/performance.php` registra sus filtros al cargarse; Brain Monkey
		// intercepta add_filter, así que el require es seguro y idempotente.
		require_once dirname( __DIR__ ) . '/inc/performance.php';
		// Las reglas del catálogo consultan `akibara_gp_is_catalog_archive()`, que
		// es la definición de la casa y vive en el módulo de WooCommerce.
		Functions\when( 'is_woocommerce' )->justReturn( true );
		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';

		// Copia literal de core (wp-includes/media.php:2179, WP 7.1): la
		// realineación de `sizes` la usa y Brain Monkey no la trae.
		Functions\when( 'wp_sizes_attribute_includes_valid_auto' )->alias(
			static function ( string $sizes_attr ): bool {
				list( $first_size ) = explode( ',', $sizes_attr, 2 );
				return 'auto' === strtolower( trim( $first_size, " \t\f\r\n" ) );
			}
		);

		// Estado por petición: cada test es una página nueva.
		unset( $GLOBALS['akibara_gp_rejilla'] );

		// La rejilla principal de un archive no es de shortcode; los casos que sí
		// lo son lo dicen en su propia prueba.
		Functions\when( 'wc_get_loop_prop' )->justReturn( false );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['akibara_gp_rejilla'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * /tienda/ (o nada de catálogo, con false).
	 *
	 * @param bool $es_catalogo Si la ruta debe considerarse archive de catálogo.
	 * @return void
	 */
	private function on_catalog_archive( bool $es_catalogo ): void {
		Functions\when( 'is_shop' )->justReturn( $es_catalogo );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'is_tax' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
	}

	/**
	 * Archive de una taxonomía de producto concreta. `is_tax` contesta según lo
	 * que le pregunten, como el de core: un doble que dijera «sí» a todo haría
	 * pasar por editorial un archive de autor.
	 *
	 * @param string $taxonomia Taxonomía consultada (pa_serie, product_brand, pa_autor…).
	 * @return void
	 */
	private function on_taxonomy_archive( string $taxonomia ): void {
		Functions\when( 'is_shop' )->justReturn( false );
		Functions\when( 'is_product_taxonomy' )->justReturn( true );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'is_tax' )->alias(
			static function ( $pregunta = '' ) use ( $taxonomia ): bool {
				return '' === $pregunta || in_array( $taxonomia, (array) $pregunta, true );
			}
		);
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
	}

	/**
	 * Fuerza el resultado de la superficie home.
	 *
	 * @param bool $is_home Si la ruta actual debe considerarse home.
	 * @return void
	 */
	private function on_home( bool $is_home ): void {
		Functions\when( 'is_front_page' )->justReturn( $is_home );
		Functions\when( 'is_page' )->justReturn( false );
	}

	/**
	 * Atributos tal como llegan al filtro desde `wp_get_attachment_image()`
	 * (WP 6.8.2+ ya incluye `width`/`height`). `$core_lazy` reproduce lo que
	 * deja la heurística de core con su lazy encendido: `loading` y `auto,`.
	 *
	 * @param bool $core_lazy Si core ya marcó la imagen como diferida.
	 * @return array<string, string>
	 */
	private function cover_attr( bool $core_lazy = false ): array {
		$sizes = '(max-width: 380px) calc(50vw - 8px), (max-width: 768px) calc(50vw - 10px), (max-width: 1024px) calc((100vw - 376px) / 3 - 4px), (max-width: 1400px) calc((100vw - 392px) / 4 - 4px), 248px';
		$attr  = array(
			'src'      => 'https://x.test/wp-content/uploads/portada-350x525.jpg',
			'class'    => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail',
			'alt'      => 'Portada',
			'width'    => '350',
			'height'   => '525',
			'decoding' => 'async',
			'srcset'   => 'https://x.test/a-350x525.jpg 350w, https://x.test/a-683x1024.jpg 683w',
			'sizes'    => $sizes,
		);

		if ( $core_lazy ) {
			$attr['loading'] = 'lazy';
			$attr['sizes']   = 'auto, ' . $sizes;
		}

		return $attr;
	}

	/**
	 * Recorre `woocommerce_before_shop_loop` como WooCommerce: abre la rejilla en
	 * prioridad 1 e imprime lo de las prioridades siguientes (el resumen de la
	 * serie, la barra de herramientas…).
	 *
	 * @param string $encima Lo que imprimen los callbacks de después.
	 * @return string Lo que llegó a la salida, en orden.
	 */
	private function before_shop_loop( string $encima = '' ): string {
		ob_start();
		akibara_gp_catalog_grid_start();
		echo $encima; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixture.

		return (string) ob_get_clean();
	}

	/**
	 * Pinta `$cuantas` celdas de la rejilla, cada una con su portada.
	 *
	 * @param int  $cuantas   Celdas.
	 * @param bool $core_lazy Si core llega con su lazy encendido.
	 * @return array<int, array<string, string>> Atributos finales, por celda desde 1.
	 */
	private function paint_cells( int $cuantas, bool $core_lazy = false ): array {
		$rejilla = array();
		$desde   = (int) did_action( 'woocommerce_before_shop_loop_item' ) - (int) ( $GLOBALS['akibara_gp_rejilla']['base'] ?? 0 );

		for ( $i = 1; $i <= $cuantas; $i++ ) {
			do_action( 'woocommerce_before_shop_loop_item' );
			$rejilla[ $desde + $i ] = akibara_gp_catalog_cover_loading( $this->cover_attr( $core_lazy ), null, 'woocommerce_thumbnail' );
		}

		return $rejilla;
	}

	/**
	 * Rejilla de /tienda/ a 390x844: fila 1 (#1, #2) en `top=303`, fila 2 (#3,
	 * #4) en `top=848`, ya fuera del pliegue. Valía 4 para no partir la fila 2
	 * contra el 3 de core, pero bajaba de entrada dos portadas que no se ven.
	 *
	 * @testdox En la tienda sólo las 2 portadas de la primera fila móvil se cargan de entrada.
	 */
	public function test_catalog_archives_load_only_the_first_mobile_row_eagerly(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		self::assertSame( 2, akibara_gp_catalog_lazy_threshold( 3 ) );
	}

	/**
	 * Fuera del catálogo mandan las 3 de WordPress: eximir más imágenes en una
	 * ruta cualquiera es regalar ancho de banda al principio de la carga.
	 *
	 * @testdox Fuera de los archives de catálogo el umbral de WordPress se respeta.
	 */
	public function test_other_routes_keep_the_core_threshold(): void {
		$this->on_catalog_archive( false );

		self::assertSame( 3, akibara_gp_catalog_lazy_threshold( 3 ) );
		self::assertSame( 7, akibara_gp_catalog_lazy_threshold( 7 ), 'Se devuelve el valor entrante, no un literal.' );
	}

	/**
	 * UM-1 (lote de integración, 2026-09-23). Desde B3 encima de la rejilla de la
	 * serie sólo va el resumen de una línea; la ficha completa baja bajo la
	 * rejilla. Medido en el espejo, primera celda: haikyuu 683 sobre 844 (móvil)
	 * y 681 sobre 900 (escritorio); one-piece, con «Otras ediciones», 779 y 714.
	 * Hasta este lote el tema escuchaba la ficha impresa encima y bajaba a 0: esa
	 * rama ya no sonaba nunca y se retiró.
	 *
	 * @testdox En la landing de serie, con el resumen encima, la primera fila carga de entrada y la #1 lleva prioridad.
	 */
	public function test_series_landing_loads_first_row_with_priority(): void {
		$this->on_taxonomy_archive( 'pa_serie' );

		$salida = $this->before_shop_loop( self::RESUMEN_DE_SERIE );
		$rejilla = $this->paint_cells( 4 );

		self::assertSame( self::RESUMEN_DE_SERIE, $salida, 'Lo impreso encima sale intacto.' );
		self::assertSame( 2, akibara_gp_catalog_lazy_threshold( 3 ) );
		self::assertSame( 'high', $rejilla[1]['fetchpriority'] ?? null, 'La portada #1 es la candidata a LCP.' );
		self::assertArrayNotHasKey( 'loading', $rejilla[1] );
		self::assertArrayNotHasKey( 'loading', $rejilla[2] );
		self::assertArrayNotHasKey( 'fetchpriority', $rejilla[2] );
		self::assertSame( 'lazy', $rejilla[3]['loading'] );
		self::assertSame( 'lazy', $rejilla[4]['loading'] );
	}

	/**
	 * La rejilla sólo se CUENTA: abrir un búfer de salida para escuchar lo pintado
	 * encima era lo que hacía la señal retirada, y un búfer abierto por el tema en
	 * `woocommerce_before_shop_loop` es un sitio más donde un callback ajeno puede
	 * quedarse a medias.
	 *
	 * @testdox Empezar la rejilla no abre ningún búfer de salida.
	 */
	public function test_grid_start_does_not_buffer_output(): void {
		$this->on_taxonomy_archive( 'pa_serie' );

		$nivel = ob_get_level();
		akibara_gp_catalog_grid_start();

		self::assertSame( $nivel, ob_get_level() );
		self::assertSame( array( 'base' ), array_keys( $GLOBALS['akibara_gp_rejilla'] ) );
	}

	/**
	 * Medido 2026-09-14 en /marca/ivrea-argentina/: la cabecera con logo mide
	 * 154-540 y la primera celda queda en 666 sobre 844 (móvil) y 538 sobre 900
	 * (escritorio, donde la portada #1 es el LCP). Tener cabecera no basta.
	 *
	 * @testdox En editorial, con cabecera y logo, la primera fila carga de entrada y la #1 lleva prioridad.
	 */
	public function test_brand_archive_keeps_first_row_and_priority(): void {
		$this->on_taxonomy_archive( 'product_brand' );
		$this->before_shop_loop( '<header class="akb-archive-hero akb-archive-hero--brand"><img src="logo.svg"></header>' );

		$rejilla = $this->paint_cells( 3, true );

		self::assertSame( 'high', $rejilla[1]['fetchpriority'] );
		self::assertArrayNotHasKey( 'loading', $rejilla[1] );
		self::assertArrayNotHasKey( 'loading', $rejilla[2] );
		self::assertSame( 'lazy', $rejilla[3]['loading'] );
	}

	/**
	 * Medido 2026-09-14: en /autor/shinichi-sakamoto/, /manga/ y la búsqueda, la
	 * portada #1 es el LCP en móvil y en escritorio.
	 *
	 * @testdox En autor, género o categoría la portada #1 lleva prioridad.
	 */
	public function test_author_archive_gets_priority_on_first_cover(): void {
		$this->on_taxonomy_archive( 'pa_autor' );
		$this->before_shop_loop();

		self::assertFalse( akibara_gp_is_brand_or_series_archive(), 'El doble de is_tax no puede decir que un autor es serie.' );

		$rejilla = $this->paint_cells( 2 );

		self::assertSame( 'high', $rejilla[1]['fetchpriority'] );
		self::assertArrayNotHasKey( 'fetchpriority', $rejilla[2] );
	}

	/**
	 * EL DEFECTO QUE CIERRA. En producción LiteSpeed apagaba el lazy de core y el
	 * catálogo no tenía regla propia: 1 de 26 imágenes diferida y 22 portadas
	 * fuera de pantalla bajadas de entrada [2026-09-14]. Aquí core llega APAGADO.
	 *
	 * @testdox En el catálogo, con el lazy de core apagado, las 2 primeras portadas cargan de entrada y el resto se difiere.
	 */
	public function test_catalog_grid_defers_from_the_third_cover_without_core_help(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		$rejilla = $this->paint_cells( 8 );

		foreach ( array( 1, 2 ) as $posicion ) {
			self::assertArrayNotHasKey( 'loading', $rejilla[ $posicion ], "La portada #{$posicion} está en la primera fila visible." );
		}
		for ( $posicion = 3; $posicion <= 8; $posicion++ ) {
			self::assertSame( 'lazy', $rejilla[ $posicion ]['loading'], "La portada #{$posicion} está bajo el pliegue móvil." );
			self::assertSame( 'async', $rejilla[ $posicion ]['decoding'] );
		}
	}

	/**
	 * Con la baranda de LiteSpeed puesta core vuelve a difundir su propio lazy y
	 * puede haber marcado mal alguna portada. El resultado tiene que ser idéntico.
	 *
	 * @testdox Con el lazy de core encendido el catálogo sale igual que con core apagado.
	 */
	public function test_catalog_grid_is_the_same_with_core_lazy_on(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();
		$apagado = $this->paint_cells( 6, false );

		unset( $GLOBALS['akibara_gp_rejilla'] );
		$this->before_shop_loop();
		$encendido = $this->paint_cells( 6, true );

		self::assertSame( $apagado, $encendido );
	}

	/**
	 * @testdox En la tienda sólo la primera portada lleva fetchpriority=high: es la candidata a LCP.
	 */
	public function test_only_the_first_shop_cover_gets_high_priority(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		$attr                  = $this->cover_attr();
		$attr['fetchpriority'] = 'high'; // Como lo dejaría core en otra imagen «grande».

		$rejilla = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			do_action( 'woocommerce_before_shop_loop_item' );
			$rejilla[ $i ] = akibara_gp_catalog_cover_loading( $attr, null, 'woocommerce_thumbnail' );
		}

		self::assertSame( 'high', $rejilla[1]['fetchpriority'] );
		foreach ( array( 2, 3, 4 ) as $posicion ) {
			self::assertArrayNotHasKey( 'fetchpriority', $rejilla[ $posicion ], "Dos prioritarias compiten entre sí (#{$posicion})." );
		}
	}

	/**
	 * Un `[products paginate="true"]` impreso al pie de /tienda/ vuelve a disparar
	 * `woocommerce_before_shop_loop` (class-wc-shortcode-products.php:651). Si
	 * reiniciara la cuenta, sus portadas saldrían de entrada y con prioridad.
	 *
	 * @testdox Una segunda rejilla en la misma página no reinicia la cuenta: sale entera diferida.
	 */
	public function test_a_later_grid_does_not_restart_the_count(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();
		$this->paint_cells( 3 );

		$this->before_shop_loop( self::RESUMEN_DE_SERIE );
		$pie = $this->paint_cells( 2 );

		foreach ( $pie as $posicion => $attr ) {
			self::assertSame( 'lazy', $attr['loading'], "Portada #{$posicion} de la rejilla del pie." );
			self::assertArrayNotHasKey( 'fetchpriority', $attr );
		}
		self::assertSame( 2, akibara_gp_catalog_lazy_threshold( 3 ), 'Lo impreso antes de otra rejilla no decide la principal.' );
	}

	/**
	 * Un producto sin portada con placeholder que no es adjunto se pinta con un
	 * `<img>` a mano (wc-product-functions.php:471-481) y no pasa por el filtro.
	 * Ocupa su celda igual: la portada siguiente es la #2, no la #1.
	 *
	 * @testdox Una celda sin portada filtrable cuenta igual y no corre las posiciones.
	 */
	public function test_a_cell_without_filterable_image_still_counts(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		do_action( 'woocommerce_before_shop_loop_item' ); // Celda 1: placeholder a mano.
		$rejilla = $this->paint_cells( 2 );

		self::assertArrayNotHasKey( 'loading', $rejilla[2] );
		self::assertArrayNotHasKey( 'fetchpriority', $rejilla[2], 'La prioridad era de la celda 1.' );
		self::assertSame( 'lazy', $rejilla[3]['loading'] );
	}

	/**
	 * @testdox Una imagen pintada encima de la rejilla, fuera de toda celda, se deja a core.
	 */
	public function test_images_above_the_grid_are_left_to_core(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		$attr = $this->cover_attr( true );

		self::assertSame( $attr, akibara_gp_catalog_cover_loading( $attr, null, 'woocommerce_thumbnail' ) );
	}

	/**
	 * Core antepone `auto,` ANTES de este filtro y sólo a lo que él difirió. Una
	 * portada que la regla difiere tiene que llevarlo, y una que carga de entrada
	 * no puede conservarlo. El resto del `sizes` no se toca.
	 *
	 * @testdox `sizes="auto, …"` acompaña exactamente a las portadas diferidas y el sizes real se conserva.
	 */
	public function test_auto_sizes_follow_the_final_loading(): void {
		$this->on_catalog_archive( true );
		$real = '(max-width: 380px) calc(50vw - 8px), (max-width: 768px) calc(50vw - 10px), (max-width: 1024px) calc((100vw - 376px) / 3 - 4px), (max-width: 1400px) calc((100vw - 392px) / 4 - 4px), 248px';

		foreach ( array( false, true ) as $core_lazy ) {
			unset( $GLOBALS['akibara_gp_rejilla'] );
			$this->before_shop_loop();
			$rejilla = $this->paint_cells( 3, $core_lazy );

			self::assertSame( $real, $rejilla[1]['sizes'] );
			self::assertSame( $real, $rejilla[2]['sizes'] );
			self::assertSame( 'auto, ' . $real, $rejilla[3]['sizes'] );
		}
	}

	/**
	 * Ancho real de la portada, en CSS px, medido en Chromium el 2026-09-14 con
	 * `getBoundingClientRect()` sobre portadas reales (no sobre las sembradas de
	 * QA, que llevan 1 px de borde). Clave: ancho del viewport.
	 *
	 * `sin_barra` se volvió a medir el 2026-09-24 (lote de cruce X1) en
	 * /serie/jujutsu-kaisen/ y /autor/eiichiro-oda/, con el canalón de 20 px por
	 * lado desde 769 px: de 769 a 1439 la portada mide 10-13 px menos que antes y
	 * el tope de 334 llega a 1440 en vez de a 1400 (1439 y 1441 son el borde del
	 * tramo). Chromium redondea a 1/64 px: 228,3281 contra 228,3333.
	 */
	private const ANCHO_MEDIDO = array(
		'con_barra' => array(
			320  => 152, 344 => 164, 360 => 172, 375 => 179.5, 380 => 182, 381 => 180.5, 390 => 185, 393 => 186.5,
			412  => 196, 430 => 205, 540 => 260, 600 => 290, 700 => 340, 768 => 374, 769 => 127, 820 => 144, 900 => 170.6667, 1024 => 212,
			1025 => 154.25, 1100 => 173, 1180 => 193, 1280 => 218, 1366 => 239.5, 1400 => 248, 1401 => 248, 1440 => 248, 1536 => 248,
			1600 => 248, 1920 => 248, 2560 => 248,
		),
		'sin_barra' => array(
			320  => 152, 344 => 164, 360 => 172, 375 => 179.5, 380 => 182, 381 => 180.5, 390 => 185, 393 => 186.5,
			412  => 196, 430 => 205, 540 => 260, 600 => 290, 700 => 340, 768 => 374, 769 => 228.3333, 820 => 245.3333, 900 => 272,
			1024 => 313.3333, 1025 => 230.25, 1100 => 249, 1180 => 269, 1280 => 294, 1366 => 315.5, 1400 => 324, 1401 => 324.25,
			1439 => 333.75, 1440 => 334, 1441 => 334, 1536 => 334, 1600 => 334, 1920 => 334, 2560 => 334,
		),
	);

	/**
	 * El `sizes` que la regla calcula para una portada del catálogo.
	 *
	 * @param mixed $size Lo que WordPress pasa al filtro (array de medidas o nombre).
	 * @return mixed
	 */
	private function catalog_sizes( $size = array( 350, 525 ) ) {
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'wc_get_image_size' )->justReturn(
			array(
				'width'  => 350,
				'height' => 525,
				'crop'   => 0,
			)
		);

		return akibara_gp_catalog_image_sizes( 'lo-que-traía-core', $size );
	}

	/**
	 * Evalúa un `sizes` como el navegador: la primera condición que se cumple
	 * manda. Sólo entiende las formas que declara la regla, a propósito: una forma
	 * nueva tiene que venir con su prueba.
	 *
	 * @param string $sizes    Atributo completo.
	 * @param int    $viewport Ancho del viewport en CSS px.
	 * @return float
	 */
	private function evaluate_sizes( string $sizes, int $viewport ): float {
		// Ninguna forma lleva comas por dentro: la coma separa tramos.
		foreach ( explode( ', ', $sizes ) as $tramo ) {
			if ( 1 === preg_match( '/^\(max-width: (\d+)px\) (.+)$/', $tramo, $m ) ) {
				if ( $viewport > (int) $m[1] ) {
					continue;
				}
				$valor = $m[2];
			} else {
				$valor = $tramo;
			}
			if ( 1 === preg_match( '/^calc\(50vw - (\d+)px\)$/', $valor, $v ) ) {
				return $viewport / 2 - (int) $v[1];
			}
			if ( 1 === preg_match( '/^calc\(\(100vw - (\d+)px\) \/ (\d+) - (\d+)px\)$/', $valor, $v ) ) {
				return ( $viewport - (int) $v[1] ) / (int) $v[2] - (int) $v[3];
			}
			if ( 1 === preg_match( '/^(\d+)px$/', $valor, $v ) ) {
				return (float) $v[1];
			}
			self::fail( "Forma de sizes sin evaluador: {$valor}" );
		}
		self::fail( "Ningún tramo cubre {$viewport}px en {$sizes}" );
	}

	/**
	 * Con el peldaño de 560 px, 1-2 px de más deciden el archivo: 185 px a DPR 3
	 * caben en 560 y 187,2 ya no. Por eso la regla declara el ancho al píxel, y
	 * esta prueba lo compara con lo que midió el navegador en 30 anchos.
	 *
	 * @testdox El `sizes` de /tienda/ (con barra de filtros) reproduce al píxel el ancho medido en 30 viewports.
	 */
	public function test_shop_sizes_match_the_measured_width(): void {
		$this->on_catalog_archive( true );
		$sizes = $this->catalog_sizes();

		self::assertStringEndsWith( '248px', $sizes );
		foreach ( self::ANCHO_MEDIDO['con_barra'] as $viewport => $medido ) {
			self::assertEqualsWithDelta( $medido, $this->evaluate_sizes( $sizes, $viewport ), 0.01, "viewport {$viewport}" );
		}
	}

	/**
	 * @testdox El `sizes` de una landing de serie (sin barra, con su canalón) reproduce al píxel el ancho medido en 32 viewports.
	 */
	public function test_series_sizes_match_the_measured_width(): void {
		$this->on_taxonomy_archive( 'pa_serie' );
		$sizes = $this->catalog_sizes();

		self::assertStringEndsWith( '334px', $sizes );
		foreach ( self::ANCHO_MEDIDO['sin_barra'] as $viewport => $medido ) {
			self::assertEqualsWithDelta( $medido, $this->evaluate_sizes( $sizes, $viewport ), 0.01, "viewport {$viewport}" );
		}
	}

	/**
	 * La barra la llevan tienda, categorías, editoriales, género y país; no la
	 * llevan serie ni autor. Se pregunta a `akibara_gp_archive_sidebar_layout()`.
	 *
	 * @testdox Editorial, género y país declaran el ancho con barra; serie y autor, sin barra.
	 */
	public function test_each_product_taxonomy_gets_its_sidebar_formula(): void {
		foreach ( array( 'product_brand', 'pa_genero', 'pa_pais', 'product_cat' ) as $con_barra ) {
			$this->on_taxonomy_archive( $con_barra );
			self::assertStringEndsWith( ' 248px', $this->catalog_sizes(), $con_barra );
		}
		foreach ( array( 'pa_serie', 'pa_autor' ) as $sin_barra ) {
			$this->on_taxonomy_archive( $sin_barra );
			self::assertStringEndsWith( ' 334px', $this->catalog_sizes(), $sin_barra );
		}
	}

	/**
	 * El filtro recibe unas veces el nombre y otras las medidas ya resueltas.
	 *
	 * @testdox La regla reconoce la miniatura por nombre o por medidas y deja intacta cualquier otra imagen o ruta.
	 */
	public function test_catalog_sizes_only_touch_the_card_thumbnail(): void {
		$this->on_catalog_archive( true );
		self::assertSame( $this->catalog_sizes( array( 350, 525 ) ), $this->catalog_sizes( 'woocommerce_thumbnail' ) );
		self::assertSame( 'lo-que-traía-core', $this->catalog_sizes( array( 500, 750 ) ) );
		self::assertSame( 'lo-que-traía-core', $this->catalog_sizes( 'large' ) );
		self::assertSame( 'lo-que-traía-core', $this->catalog_sizes( 7 ) );

		$this->on_catalog_archive( false );
		self::assertSame( 'lo-que-traía-core', $this->catalog_sizes() );
	}

	/**
	 * @testdox Si core apaga `wp_img_tag_add_auto_sizes`, la regla no añade `auto`.
	 */
	public function test_auto_sizes_respect_the_core_switch(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();
		Filters\expectApplied( 'wp_img_tag_add_auto_sizes' )->andReturn( false );

		$rejilla = $this->paint_cells( 3 );

		self::assertSame( '(max-width: 380px) calc(50vw - 8px), (max-width: 768px) calc(50vw - 10px), (max-width: 1024px) calc((100vw - 376px) / 3 - 4px), (max-width: 1400px) calc((100vw - 392px) / 4 - 4px), 248px', $rejilla[3]['sizes'] );
	}

	/**
	 * Sin `sizes` el navegador supone 100vw para el `srcset`. Si detrás de `auto`
	 * no hay nada, quitarlo dejaría un `srcset` sin pareja.
	 *
	 * @testdox Una portada de entrada con `sizes="auto"` a secas conserva su `sizes`.
	 */
	public function test_sizes_is_never_removed_from_an_eager_cover(): void {
		$this->on_catalog_archive( true );
		$this->before_shop_loop();

		$attr          = $this->cover_attr( true );
		$attr['sizes'] = 'auto';

		do_action( 'woocommerce_before_shop_loop_item' );
		$final = akibara_gp_catalog_cover_loading( $attr, null, 'woocommerce_thumbnail' );

		self::assertArrayNotHasKey( 'loading', $final );
		self::assertArrayHasKey( 'srcset', $final );
		self::assertSame( 'auto', $final['sizes'] ?? null );
	}

	/**
	 * @testdox Fuera del catálogo la regla no toca nada ni abre la rejilla.
	 */
	public function test_catalog_rule_ignores_other_routes(): void {
		$this->on_catalog_archive( false );

		self::assertSame( '', $this->before_shop_loop() );
		self::assertArrayNotHasKey( 'akibara_gp_rejilla', $GLOBALS );

		$attr = $this->cover_attr( true );
		do_action( 'woocommerce_before_shop_loop_item' );
		self::assertSame( $attr, akibara_gp_catalog_cover_loading( $attr, null, 'woocommerce_thumbnail' ) );
	}

	/**
	 * La home tiene su regla (difiere TODAS las portadas, prioridad 10). La del
	 * catálogo corre después (11) y no puede devolverle el `loading` ni la
	 * prioridad a ninguna, aunque la condicional de catálogo diera true.
	 *
	 * @testdox En la home la regla del catálogo no deshace la de la home.
	 */
	public function test_catalog_rule_does_not_undo_the_home_rule(): void {
		$this->on_catalog_archive( true );
		$this->on_home( true );

		for ( $i = 1; $i <= 3; $i++ ) {
			do_action( 'woocommerce_before_shop_loop_item' );
			$home  = akibara_gp_handle_home_product_image_loading( $this->cover_attr(), null, 'woocommerce_thumbnail' );
			$final = akibara_gp_catalog_cover_loading( $home, null, 'woocommerce_thumbnail' );

			self::assertSame( 'lazy', $final['loading'], "Portada #{$i} de la home." );
			self::assertArrayNotHasKey( 'fetchpriority', $final );
		}
	}

	/**
	 * @testdox Fuera del home los atributos de imagen se devuelven intactos.
	 */
	public function test_image_attributes_untouched_outside_home(): void {
		$this->on_home( false );

		$attr = array( 'loading' => 'eager', 'fetchpriority' => 'high' );

		self::assertSame(
			$attr,
			akibara_gp_handle_home_product_image_loading( $attr, null, 'woocommerce_thumbnail' )
		);
	}

	/**
	 * @testdox En el home, las portadas del catálogo pasan a lazy y sueltan fetchpriority.
	 */
	public function test_home_catalog_covers_go_lazy(): void {
		$this->on_home( true );

		$result = akibara_gp_handle_home_product_image_loading(
			array( 'loading' => 'eager', 'fetchpriority' => 'high' ),
			null,
			'woocommerce_thumbnail'
		);

		self::assertSame( 'lazy', $result['loading'] );
		self::assertArrayNotHasKey(
			'fetchpriority',
			$result,
			'Competir con el hero por prioridad de descarga degrada el LCP.'
		);
	}

	/**
	 * @testdox En la home, una portada diferida por la regla lleva `auto` en su sizes.
	 */
	public function test_home_lazy_covers_get_auto_sizes(): void {
		$this->on_home( true );

		$result = akibara_gp_handle_home_product_image_loading( $this->cover_attr(), null, 'woocommerce_thumbnail' );

		self::assertSame( 'lazy', $result['loading'] );
		self::assertStringStartsWith( 'auto, ', $result['sizes'] );
		self::assertSame( 1, substr_count( $result['sizes'], 'auto' ), 'Nunca «auto, auto, …».' );
	}

	/**
	 * @testdox El hero del home NO se degrada: solo aplican los tamaños de catálogo.
	 */
	public function test_home_hero_size_is_left_alone(): void {
		$this->on_home( true );

		$attr = array( 'loading' => 'eager', 'fetchpriority' => 'high' );

		self::assertSame(
			$attr,
			akibara_gp_handle_home_product_image_loading( $attr, null, 'full' ),
			'Degradar el tamaño del hero movería el LCP en la dirección contraria.'
		);
	}

	/**
	 * @testdox Un tamaño como array (ancho/alto explícitos) no se toca.
	 */
	public function test_array_size_is_left_alone(): void {
		$this->on_home( true );

		$attr = array( 'loading' => 'eager' );

		self::assertSame(
			$attr,
			akibara_gp_handle_home_product_image_loading( $attr, null, array( 300, 300 ) )
		);
	}

	/**
	 * CANDADO DEL CLS. Todas las imágenes declaran `width`/`height` y por eso el
	 * diseño no salta (CLS 0,000 en portada y 0,002 en catálogo, medido
	 * 2026-09-14). Desde WP 6.8.2 esos dos atributos viajan DENTRO de `$attr` y
	 * este filtro podría quitarlos o cambiarlos: ninguna regla de este módulo
	 * puede hacerlo, en ninguna posición ni superficie.
	 *
	 * @testdox Ninguna regla de carga quita ni cambia `width`/`height` de una portada.
	 */
	public function test_no_loading_rule_touches_width_or_height(): void {
		$superficies = array(
			'tienda'           => array( fn () => $this->on_catalog_archive( true ), '' ),
			'serie'            => array( fn () => $this->on_taxonomy_archive( 'pa_serie' ), self::RESUMEN_DE_SERIE ),
			'editorial'        => array( fn () => $this->on_taxonomy_archive( 'product_brand' ), '' ),
			'home'             => array(
				function (): void {
					$this->on_catalog_archive( false );
					$this->on_home( true );
				},
				'',
			),
			'otra superficie'  => array( fn () => $this->on_catalog_archive( false ), '' ),
		);

		foreach ( $superficies as $nombre => list( $preparar, $encima ) ) {
			$preparar();
			unset( $GLOBALS['akibara_gp_rejilla'] );
			$this->before_shop_loop( $encima );

			for ( $i = 1; $i <= 6; $i++ ) {
				do_action( 'woocommerce_before_shop_loop_item' );
				foreach ( array( false, true ) as $core_lazy ) {
					$attr  = $this->cover_attr( $core_lazy );
					$final = akibara_gp_catalog_cover_loading(
						akibara_gp_handle_home_product_image_loading( $attr, null, 'woocommerce_thumbnail' ),
						null,
						'woocommerce_thumbnail'
					);

					self::assertSame( '350', $final['width'] ?? null, "{$nombre} #{$i}: width" );
					self::assertSame( '525', $final['height'] ?? null, "{$nombre} #{$i}: height" );
					self::assertSame( $attr['srcset'], $final['srcset'], "{$nombre} #{$i}: srcset" );
				}
			}
		}
	}

	/**
	 * Los correos que se arman fuera de una plantilla de página (checkout por la
	 * Store API, REST, cron) no están ni en el loop ni en la cabecera: con el lazy
	 * nativo de vuelta core los difería y les anteponía `auto,`. Salen como hasta
	 * hoy: sin ninguno de los dos.
	 *
	 * Se pregunta por la acción en curso y no por una marca que haya que apagar:
	 * si la plantilla lanza una excepción —y `WC_Emails::send_transactional_email()`
	 * la captura—, una marca encendida dejaría sin carga diferida al resto de la
	 * petición, que en el checkout clásico incluye la página de «gracias».
	 *
	 * @testdox Dentro de los artículos de un correo de pedido, core no difiere imágenes.
	 */
	public function test_order_email_images_are_never_lazy(): void {
		$dentro = null;
		Actions\expectDone( 'woocommerce_email_order_details' )->whenHappen(
			static function () use ( &$dentro ): void {
				$dentro = akibara_gp_email_images_without_lazy( true );
			}
		);

		do_action( 'woocommerce_email_order_details', null, false, false, null );

		self::assertFalse( $dentro );
	}

	/**
	 * Los artículos de los correos de preparación de pedido (WooCommerce 11) se
	 * pintan con OTRO par de filtros (`woocommerce_email_fulfillment_items_args`,
	 * wc-template-functions.php:4078-4090): una regla atada al par de los pedidos
	 * los dejaba fuera.
	 *
	 * @testdox Dentro de los artículos de un correo de preparación, core tampoco difiere.
	 */
	public function test_fulfillment_email_images_are_never_lazy(): void {
		$dentro = null;
		Actions\expectDone( 'woocommerce_email_fulfillment_details' )->whenHappen(
			static function () use ( &$dentro ): void {
				$dentro = akibara_gp_email_images_without_lazy( true );
			}
		);

		do_action( 'woocommerce_email_fulfillment_details', null, null, false, false, null );

		self::assertFalse( $dentro );
	}

	/**
	 * @testdox Fuera de un correo manda core, y nunca se enciende lo que otro apagó.
	 */
	public function test_outside_an_email_core_decides(): void {
		self::assertTrue( akibara_gp_email_images_without_lazy( true ) );
		self::assertFalse( akibara_gp_email_images_without_lazy( false ) );
	}

	/**
	 * EL DEFECTO QUE CIERRA. `woocommerce_archive_description` corre ANTES que
	 * `woocommerce_before_shop_loop`, y la descripción de un término pasa por
	 * `wc_format_content()`, que ejecuta shortcodes. Un `[products paginate="true"]`
	 * escrito por el dueño en esa descripción dispara el hook ANTES que la rejilla
	 * de verdad: si se quedara con el reinicio, la rejilla real saldría entera
	 * diferida y sin `fetchpriority`, en silencio.
	 *
	 * @testdox Una rejilla de shortcode antes de la principal no se queda con la cuenta.
	 */
	public function test_a_shortcode_grid_before_the_main_one_does_not_hijack(): void {
		$this->on_catalog_archive( true );

		// La rejilla del shortcode: WooCommerce la marca antes de disparar el hook.
		Functions\when( 'wc_get_loop_prop' )->justReturn( true );
		$this->before_shop_loop();
		self::assertArrayNotHasKey( 'akibara_gp_rejilla', $GLOBALS, 'La rejilla de un shortcode no es la principal.' );
		$this->paint_cells( 3 );

		// Y ahora la de verdad.
		Functions\when( 'wc_get_loop_prop' )->justReturn( false );
		$this->before_shop_loop();
		$rejilla = $this->paint_cells( 3 );

		self::assertSame( 'high', $rejilla[1]['fetchpriority'], 'La portada #1 de la rejilla real es la candidata a LCP.' );
		self::assertArrayNotHasKey( 'loading', $rejilla[2] );
		self::assertSame( 'lazy', $rejilla[3]['loading'] );
	}

	/**
	 * @testdox Los handles críticos del primer viewport nunca entran a la lista diferida.
	 */
	public function test_critical_handles_are_never_deferred(): void {
		$deferred = akibara_gp_home_deferred_style_handles();

		foreach ( array( 'akibara-base', 'akibara-header', 'akibara-home', 'akibara-trust', 'akb-announcement-bar' ) as $critical ) {
			self::assertNotContains( $critical, $deferred );
		}
	}

	/**
	 * @testdox El diferido usa el patrón print+onload y conserva la hoja en <noscript>.
	 */
	public function test_deferred_style_keeps_progressive_enhancement(): void {
		$this->on_home( true );
		Functions\when( 'is_admin' )->justReturn( false );

		$handle   = akibara_gp_home_deferred_style_handles()[0];
		$original = "<link rel='stylesheet' id='{$handle}-css' href='https://x.test/a.css' media='all' />";

		$html = akibara_gp_defer_noncritical_home_style( $original, $handle, 'https://x.test/a.css', 'all' );

		self::assertStringContainsString( "media='print'", $html );
		self::assertStringContainsString( "this.media='all'", $html );
		self::assertStringContainsString( '<noscript>', $html );
		self::assertStringContainsString( $original, $html, 'Sin JS la hoja debe seguir aplicándose.' );
	}

	/**
	 * @testdox Fuera del home ninguna hoja se difiere.
	 */
	public function test_no_deferral_outside_home(): void {
		$this->on_home( false );
		Functions\when( 'is_admin' )->justReturn( false );

		$handle   = akibara_gp_home_deferred_style_handles()[0];
		$original = "<link rel='stylesheet' id='{$handle}-css' href='https://x.test/a.css' media='all' />";

		self::assertSame(
			$original,
			akibara_gp_defer_noncritical_home_style( $original, $handle, 'https://x.test/a.css', 'all' )
		);
	}

	/**
	 * EL DEFECTO QUE CIERRA. La consulta del catálogo ceba los productos, no sus
	 * adjuntos: cada portada iba a la base por su cuenta a buscar el post del
	 * adjunto y su `_wp_attachment_metadata` para armar el `srcset`. 48
	 * consultas en una rejilla de 24.
	 *
	 * @testdox Las portadas del loop se ceban de una sola vez antes de pintarse.
	 */
	public function test_catalog_thumbnails_are_primed_in_one_go(): void {
		$query  = new \WP_Query_Double( array( 101, 102, 103 ) );
		$vistas = $this->watch_priming();

		$GLOBALS['wp_query'] = $query;
		akibara_gp_prime_catalog_thumbnails();
		unset( $GLOBALS['wp_query'] );

		self::assertCount( 1, $vistas, 'Una sola llamada ceba las 24 portadas de la rejilla.' );
		self::assertSame( $query, $vistas[0], 'Se ceba la consulta principal, no otra.' );
	}

	/**
	 * @testdox Sin resultados no se ceba nada.
	 */
	public function test_priming_skips_an_empty_loop(): void {
		$vistas = $this->watch_priming();

		$GLOBALS['wp_query'] = new \WP_Query_Double( array() );
		akibara_gp_prime_catalog_thumbnails();
		unset( $GLOBALS['wp_query'] );

		self::assertSame( array(), $vistas->getArrayCopy() );
	}

	/**
	 * @testdox Sin consulta principal el cebado no explota.
	 */
	public function test_priming_survives_a_missing_query(): void {
		$vistas = $this->watch_priming();

		unset( $GLOBALS['wp_query'] );
		akibara_gp_prime_catalog_thumbnails();

		self::assertSame( array(), $vistas->getArrayCopy() );
	}

	/**
	 * Las vitrinas `[products]` de la portada no pasan por la consulta
	 * principal: sin este cebado eran 31 lecturas de wp_postmeta, una por
	 * portada (medido 2026-10-24).
	 *
	 * @testdox Las portadas de una rejilla `[products]` se ceban en una sola tanda, sin tocar el resultado.
	 */
	public function test_shortcode_grid_thumbnails_are_primed_in_one_go(): void {
		$meta   = new \ArrayObject();
		$primed = new \ArrayObject();
		Functions\when( 'update_meta_cache' )->alias(
			static function ( $type, $ids ) use ( $meta ) {
				$meta[] = array( $type, $ids );
				return array();
			}
		);
		$thumbs = array( 101 => '9001', 102 => '', 103 => '9001', 104 => '9002' );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key = '', $single = false ) => '_thumbnail_id' === $key ? ( $thumbs[ $id ] ?? '' ) : ''
		);
		Functions\when( '_prime_post_caches' )->alias(
			static function ( $ids, $terms = true, $with_meta = true ) use ( $primed ): void {
				$primed[] = array( $ids, $terms, $with_meta );
			}
		);

		$results = (object) array( 'ids' => array( 101, 102, 103, 104 ), 'total' => 4 );

		self::assertSame( $results, akibara_gp_prime_shortcode_thumbnails( $results ), 'El filtro devuelve el MISMO resultado.' );
		self::assertSame( array( array( 'post', array( 101, 102, 103, 104 ) ) ), $meta->getArrayCopy(), 'Una tanda para el meta de los productos.' );
		self::assertSame( array( array( array( 9001, 9002 ), false, true ) ), $primed->getArrayCopy(), 'Una tanda, sin repetir, para post + meta de los adjuntos.' );
	}

	/**
	 * @testdox Una rejilla vacía o un resultado raro pasan intactos y sin consultas.
	 */
	public function test_shortcode_priming_skips_empty_results(): void {
		$empty = (object) array( 'ids' => array() );
		self::assertSame( $empty, akibara_gp_prime_shortcode_thumbnails( $empty ) );
		self::assertNull( akibara_gp_prime_shortcode_thumbnails( null ) );
	}

	/**
	 * Registra cada llamada a `update_post_thumbnail_cache()`.
	 *
	 * @return \ArrayObject<int, mixed> Colector vivo de las llamadas observadas.
	 */
	private function watch_priming(): \ArrayObject {
		$vistas = new \ArrayObject();
		Functions\when( 'update_post_thumbnail_cache' )->alias(
			static function ( $query = null ) use ( $vistas ): void {
				$vistas[] = $query;
			}
		);

		return $vistas;
	}

	/**
	 * @testdox Una hoja con media distinto de `all` se respeta tal cual.
	 */
	public function test_non_all_media_is_respected(): void {
		$this->on_home( true );
		Functions\when( 'is_admin' )->justReturn( false );

		$handle   = akibara_gp_home_deferred_style_handles()[0];
		$original = "<link rel='stylesheet' id='{$handle}-css' href='https://x.test/a.css' media='screen' />";

		self::assertSame(
			$original,
			akibara_gp_defer_noncritical_home_style( $original, $handle, 'https://x.test/a.css', 'screen' )
		);
	}
}
