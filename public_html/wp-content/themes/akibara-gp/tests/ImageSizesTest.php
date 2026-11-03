<?php
/**
 * `sizes` de las imágenes que NO son la rejilla del catálogo (lote T1, 2026-09-22):
 * la venta cruzada de los estados vacíos, la portada de la ficha y el blog.
 *
 * Cada caso compara lo que declara la regla con el ancho que MIDIÓ el navegador
 * (Chromium sin cabeza contra el espejo, `getBoundingClientRect()`), como hace
 * `PerformanceHooksTest` con el catálogo. Las tablas son medidas CONGELADAS en
 * la fecha que dice cada una: esta prueba avisa si alguien cambia la REGLA, pero
 * no lee el CSS, así que un relleno, un hueco o un corte tocado en una hoja no
 * la pone roja. Lo que sí lo vigila es tests/catalog-cover-sizes.test.js, que
 * deriva de las hojas la fórmula del catálogo, la de la venta cruzada del 404 y
 * las del blog. La del carrito vacío (bloque del carrito + relleno de
 * GeneratePress) sólo vive aquí: si cambia ese CSS, hay que volver a medirla
 * (revisión T1-D2).
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		/** Doble mínimo del producto de WooCommerce: el tema sólo comprueba el tipo. */
		class WC_Product {}
	}

	/** Producto de prueba con la portada y la galería que pida cada caso. */
	class Akb_Sizes_Producto_Doble extends WC_Product {

		private int $imagen;

		/** @var int[] */
		private array $galeria;

		/**
		 * @param int   $imagen  Portada.
		 * @param int[] $galeria Resto de la galería.
		 */
		public function __construct( int $imagen, array $galeria = array() ) {
			$this->imagen  = $imagen;
			$this->galeria = $galeria;
		}

		public function get_image_id(): int {
			return $this->imagen;
		}

		/** @return int[] */
		public function get_gallery_image_ids(): array {
			return $this->galeria;
		}
	}
}

namespace Akibara\GP\Tests {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	require_once dirname( __DIR__ ) . '/inc/page-targets.php';

	/**
	 * @coversNothing
	 */
	final class ImageSizesTest extends TestCase {

		/**
		 * Portada de la venta cruzada, en CSS px, medida el 2026-09-22 en el espejo.
		 * Clave: ancho del viewport. La celda no depende del `sizes` (la imagen
		 * llena una caja 2:3 posicionada), así que la medida es independiente.
		 */
		private const VENTA_CRUZADA = array(
			'404'     => array(
				320  => 136, 360 => 156, 375 => 163.5, 380 => 166, 381 => 164.5, 390 => 169, 412 => 180, 430 => 189,
				540  => 244, 600 => 274, 700 => 324, 768 => 358, 769 => 231, 820 => 248, 900 => 274.66, 1024 => 316,
				1025 => 232.25, 1100 => 251, 1280 => 296, 1366 => 317.5, 1400 => 326, 1431 => 333.75, 1432 => 334,
				1440 => 334, 1920 => 334, 2560 => 334,
			),
			'carrito' => array(
				320  => 108, 360 => 128, 375 => 135.5, 380 => 138, 381 => 136.5, 390 => 141, 412 => 152, 430 => 161,
				540  => 216, 600 => 246, 700 => 296, 768 => 330, 769 => 220.33, 820 => 237.33, 900 => 264, 1024 => 305.33,
				1025 => 224.25, 1100 => 243, 1280 => 288, 1366 => 309.5, 1400 => 318, 1431 => 325.75, 1432 => 326,
				1440 => 326, 1920 => 326, 2560 => 326,
			),
			// Favoritos vacíos en Mi cuenta (módulo Wishlist de business), medido el
			// 2026-09-23 con sesión de cliente (revisión T1-D3).
			'cuenta'  => array(
				320  => 120, 360 => 140, 380 => 150, 381 => 148.5, 390 => 153, 412 => 164, 600 => 258, 768 => 342,
				769  => 132.33, 900 => 176, 1024 => 217.33, 1025 => 158.25, 1280 => 222, 1366 => 243.5, 1440 => 262,
				1920 => 334,
			),
			// Dentro del aviso de «sin resultados» de /tienda/?_buscar=inuyasha, con la
			// barra de facetas al lado (medido 2026-09-23, lote de integración). En
			// móvil comparte tramo con el 404, que declara hasta 1 px de más.
			'listado' => array(
				320  => 135, 360 => 155, 380 => 165, 381 => 163.5, 390 => 168, 412 => 179, 600 => 273, 768 => 357,
				769  => 115.67, 800 => 126, 900 => 159.33, 1024 => 200.67, 1025 => 145.75, 1100 => 164.5, 1280 => 209.5,
				1366 => 231, 1400 => 239.5, 1401 => 239.5, 1440 => 239.5, 1920 => 239.5, 2560 => 239.5,
			),
		);

		/**
		 * Portada de la ficha 800×1200: el hueco en que se DIBUJA, medido el
		 * 2026-09-22 en Chromium y WebKit (mismos valores) como
		 * min(ancho del contenedor, max-height × proporción). Clave: «ancho×alto».
		 */
		private const FICHA = array(
			'320x568'   => 189.33,
			'360x780'   => 260,
			'375x667'   => 222.33,
			'390x844'   => 281.33,
			'412x823'   => 274.33,
			'412x915'   => 305,
			'430x932'   => 310.67,
			'600x960'   => 320,
			'768x1024'  => 341.33,
			'820x1180'  => 378.23,
			'1024x768'  => 476.16,
			'1180x820'  => 500,
			'1366x768'  => 500,
			'1440x900'  => 500,
			'1920x1080' => 500,
		);

		/** Segunda imagen de la galería de Gannibal 1 (800×1124), medida igual. */
		private const FICHA_800X1124 = array(
			'390x844'  => 300.36,
			'412x823'  => 292.88,
			'768x1024' => 364.41,
			'1024x768' => 476.14,
			'1440x900' => 500,
		);

		/** Cabecera de una entrada (`img.akb-post__img`), medida el 2026-09-22. */
		private const ENTRADA = array(
			320  => 294, 360 => 334, 390 => 364, 412 => 386, 430 => 404, 600 => 574, 768 => 742, 769 => 735,
			820  => 786, 900 => 866, 911 => 877, 912 => 878, 1024 => 878, 1440 => 878, 2560 => 878,
		);

		/**
		 * Tarjeta del índice del blog y de sus archivos, medida el 2026-09-22 sin
		 * barra de desplazamiento (teléfonos, Mac con trackpad), y el 2026-09-23 con
		 * la barra clásica de 17 px de Windows (Chromium con barras visibles).
		 */
		private const TARJETA_BLOG = array(
			320  => 286, 360 => 326, 390 => 356, 412 => 378, 600 => 566, 687 => 653, 688 => 318, 700 => 324,
			704  => 326, 705 => 326.5, 768 => 358, 769 => 346.5, 820 => 372, 900 => 412, 1024 => 474, 1055 => 489.5,
			1056 => 318, 1072 => 323.33, 1073 => 323.66, 1100 => 332.66, 1199 => 365.66, 1200 => 366, 1440 => 366,
			2560 => 366,
		);

		/** La misma tarjeta con barra de 17 px (su ancho útil es 17 px menor). */
		private const TARJETA_BLOG_CON_BARRA = array(
			320  => 269, 390 => 339, 687 => 636, 688 => 637, 704 => 653, 705 => 318, 720 => 325.5, 768 => 349.5,
			769  => 338, 1024 => 465.5, 1056 => 481.5, 1072 => 489.5, 1073 => 318, 1100 => 327, 1200 => 360.33,
			1216 => 365.66, 1217 => 366, 1440 => 366, 2560 => 366,
		);

		/**
		 * Franjas de 17 px donde, SIN barra, la rejilla ya tiene una columna más de
		 * las que declara el `sizes` (declararlas bien ahí es declarar de menos con
		 * barra). [desde, hasta] en px de ventana.
		 */
		private const FRANJAS_DE_LA_BARRA = array( array( 688, 704 ), array( 1056, 1072 ) );

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();

			require_once dirname( __DIR__ ) . '/inc/performance.php';
			Functions\when( 'is_woocommerce' )->justReturn( true );
			require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
			require_once dirname( __DIR__ ) . '/inc/blog.php';

			// Ninguna de estas superficies es un archive de catálogo.
			Functions\when( 'is_shop' )->justReturn( false );
			Functions\when( 'is_product_taxonomy' )->justReturn( false );
			Functions\when( 'is_search' )->justReturn( false );
			Functions\when( 'get_query_var' )->justReturn( '' );
			Functions\when( 'is_tax' )->justReturn( false );
			Functions\when( 'is_front_page' )->justReturn( false );
			Functions\when( 'is_page' )->justReturn( false );
			Functions\when( 'is_cart' )->justReturn( false );
			Functions\when( 'is_account_page' )->justReturn( false );
			Functions\when( 'wc_get_image_size' )->justReturn(
				array(
					'width'  => 350,
					'height' => 525,
					'crop'   => 0,
				)
			);

			unset( $GLOBALS['woocommerce_loop'] );
		}

		protected function tearDown(): void {
			unset( $GLOBALS['woocommerce_loop'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Evalúa un `sizes` como el navegador: el primer tramo cuya condición se
		 * cumple manda; un tramo que el navegador no entiende se salta. Sólo conoce
		 * las formas que declaran estas reglas, a propósito: una forma nueva tiene
		 * que venir con su prueba.
		 *
		 * @param string $sizes        Atributo completo.
		 * @param int    $ancho        Ancho del viewport en CSS px.
		 * @param int    $alto         Alto del viewport en CSS px.
		 * @param bool   $entiende_min Si el navegador entiende `min()` en `sizes`.
		 * @return float
		 */
		private function evaluate( string $sizes, int $ancho, int $alto = 900, bool $entiende_min = true ): float {
			foreach ( $this->tramos( $sizes ) as $tramo ) {
				if ( 1 === preg_match( '/^\((max|min)-width: (\d+)px\) (.+)$/', $tramo, $m ) ) {
					$cumple = 'max' === $m[1] ? $ancho <= (int) $m[2] : $ancho >= (int) $m[2];
					if ( ! $cumple ) {
						continue;
					}
					$valor = $m[3];
				} else {
					$valor = $tramo;
				}

				$resultado = $this->value( $valor, $ancho, $alto, $entiende_min );
				if ( null !== $resultado ) {
					return $resultado;
				}
			}
			self::fail( "Ningún tramo cubre {$ancho}×{$alto} en {$sizes}" );
		}

		/**
		 * Parte un `sizes` por las comas de primer nivel (`min()` lleva una dentro).
		 *
		 * @param string $sizes Atributo completo.
		 * @return string[]
		 */
		private function tramos( string $sizes ): array {
			$tramos = array();
			$nivel  = 0;
			$actual = '';
			foreach ( str_split( $sizes ) as $c ) {
				$nivel += '(' === $c ? 1 : ( ')' === $c ? -1 : 0 );
				if ( ',' === $c && 0 === $nivel ) {
					$tramos[] = trim( $actual );
					$actual   = '';
					continue;
				}
				$actual .= $c;
			}
			$tramos[] = trim( $actual );

			return $tramos;
		}

		/**
		 * Valor de un tramo, o null si el navegador no lo entendería.
		 *
		 * @param string $valor        Longitud del tramo.
		 * @param int    $ancho        Viewport.
		 * @param int    $alto         Viewport.
		 * @param bool   $entiende_min Soporte de `min()`.
		 * @return float|null
		 */
		private function value( string $valor, int $ancho, int $alto, bool $entiende_min ): ?float {
			if ( 1 === preg_match( '/^min\(calc\(100vw - (\d+)px\), calc\(50vh \* ([\d.]+)\)\)$/', $valor, $v ) ) {
				return $entiende_min ? min( $ancho - (int) $v[1], $alto / 2 * (float) $v[2] ) : null;
			}
			if ( 1 === preg_match( '/^calc\(100vw - (\d+)px\)$/', $valor, $v ) ) {
				return (float) ( $ancho - (int) $v[1] );
			}
			if ( 1 === preg_match( '/^calc\(\(100vw - (\d+)px\) \* ([\d.]+)\)$/', $valor, $v ) ) {
				return ( $ancho - (int) $v[1] ) * (float) $v[2];
			}
			if ( 1 === preg_match( '/^calc\(50vw - (\d+)px\)$/', $valor, $v ) ) {
				return $ancho / 2 - (int) $v[1];
			}
			if ( 1 === preg_match( '/^calc\(\(100vw - (\d+)px\) \/ (\d+) - (\d+)px\)$/', $valor, $v ) ) {
				return ( $ancho - (int) $v[1] ) / (int) $v[2] - (int) $v[3];
			}
			if ( 1 === preg_match( '/^(\d+)px$/', $valor, $v ) ) {
				return (float) $v[1];
			}
			self::fail( "Forma de sizes sin evaluador: {$valor}" );
		}

		/**
		 * Viewport «ancho×alto» → [ancho, alto].
		 *
		 * @param string $clave Clave de las tablas de la ficha.
		 * @return int[]
		 */
		private function viewport( string $clave ): array {
			return array_map( 'intval', explode( 'x', $clave ) );
		}

		/**
		 * El `sizes` que da la regla a una portada de la venta cruzada.
		 *
		 * @param bool $en_el_carrito Si la página es el carrito.
		 * @return mixed
		 */
		private function cross_sell_sizes( bool $en_el_carrito ) {
			Functions\when( 'is_cart' )->justReturn( $en_el_carrito );
			$GLOBALS['woocommerce_loop'] = array( 'name' => AKIBARA_GP_CROSS_SELL_LOOP );

			return akibara_gp_catalog_image_sizes( 'lo-que-traía-core', array( 350, 525 ) );
		}

		/**
		 * Ficha servida con su producto, la portada 800×1200 y lo que pida el caso.
		 *
		 * @param int   $producto_id Producto consultado (distinto por caso: la regla lo guarda).
		 * @param int[] $medidas     Adjunto => [ancho, alto] de su `woocommerce_single`.
		 * @param int[] $galeria     Resto de la galería.
		 * @return void
		 */
		private function on_product( int $producto_id, array $medidas, array $galeria = array() ): void {
			Functions\when( 'is_product' )->justReturn( true );
			Functions\when( 'get_queried_object_id' )->justReturn( $producto_id );
			$portada = (int) array_key_first( $medidas );
			Functions\when( 'wc_get_product' )->alias(
				static function ( $id ) use ( $producto_id, $portada, $galeria ) {
					return (int) $id === $producto_id ? new \Akb_Sizes_Producto_Doble( $portada, $galeria ) : false;
				}
			);
			Functions\when( 'wp_get_attachment_image_src' )->alias(
				static function ( $id, $size ) use ( $medidas ) {
					if ( 'woocommerce_single' !== $size || ! isset( $medidas[ (int) $id ] ) ) {
						return false;
					}
					return array( 'https://akibara.test/portada-' . $id . '.avif', $medidas[ (int) $id ][0], $medidas[ (int) $id ][1], true );
				}
			);
		}

		/**
		 * @testdox La venta cruzada del 404 declara al píxel el ancho medido en 26 viewports.
		 */
		public function test_cross_sell_on_404_matches_the_measured_width(): void {
			$sizes = $this->cross_sell_sizes( false );

			self::assertIsString( $sizes );
			foreach ( self::VENTA_CRUZADA['404'] as $viewport => $medido ) {
				self::assertEqualsWithDelta( $medido, $this->evaluate( $sizes, $viewport ), 0.01, "404, viewport {$viewport}" );
			}
		}

		/**
		 * @testdox La venta cruzada del carrito vacío declara al píxel el ancho medido en 26 viewports.
		 */
		public function test_cross_sell_on_empty_cart_matches_the_measured_width(): void {
			$sizes = $this->cross_sell_sizes( true );

			self::assertIsString( $sizes );
			foreach ( self::VENTA_CRUZADA['carrito'] as $viewport => $medido ) {
				self::assertEqualsWithDelta( $medido, $this->evaluate( $sizes, $viewport ), 0.01, "carrito, viewport {$viewport}" );
			}
		}

		/**
		 * Revisión T1-D3: en Mi cuenta la columna es más estrecha que la del 404
		 * (menú al lado en escritorio) y la fórmula del 404 declaraba de más.
		 *
		 * @testdox La venta cruzada de favoritos vacíos (Mi cuenta) declara al píxel el ancho medido en 16 viewports.
		 */
		public function test_cross_sell_on_empty_favourites_matches_the_measured_width(): void {
			Functions\when( 'is_account_page' )->justReturn( true );
			$sizes = $this->cross_sell_sizes( false );

			self::assertIsString( $sizes );
			foreach ( self::VENTA_CRUZADA['cuenta'] as $viewport => $medido ) {
				self::assertEqualsWithDelta( $medido, $this->evaluate( $sizes, $viewport ), 0.01, "cuenta, viewport {$viewport}" );
			}
		}

		/**
		 * La venta cruzada que el tema pinta DENTRO del aviso del buscador
		 * (`akibara_search_empty_after`) queda en la columna de al lado de las
		 * facetas: con la fórmula del 404 declaraba 334 a 1440 y pintaba 240.
		 *
		 * @testdox La venta cruzada del «sin resultados» de un listado con facetas declara el ancho medido, nunca menos y a lo sumo 1 px más.
		 */
		public function test_cross_sell_in_the_listing_empty_state_matches_the_measured_width(): void {
			Functions\when( 'is_shop' )->justReturn( true );
			Functions\when( 'is_singular' )->justReturn( false );
			$sizes = $this->cross_sell_sizes( false );

			self::assertIsString( $sizes );
			foreach ( self::VENTA_CRUZADA['listado'] as $viewport => $medido ) {
				$declarado = $this->evaluate( $sizes, $viewport );
				self::assertGreaterThanOrEqual( $medido - 0.01, $declarado, "listado, viewport {$viewport}: se queda corto" );
				self::assertLessThanOrEqual( $medido + 1.0, $declarado, "listado, viewport {$viewport}: declara de más" );
			}
		}

		/**
		 * @testdox Sin el loop de la venta cruzada, fuera del catálogo el `sizes` de core no se toca.
		 */
		public function test_cross_sell_rule_needs_its_loop(): void {
			self::assertSame( 'lo-que-traía-core', akibara_gp_catalog_image_sizes( 'lo-que-traía-core', array( 350, 525 ) ) );

			$GLOBALS['woocommerce_loop'] = array( 'name' => 'related' );
			self::assertSame( 'lo-que-traía-core', akibara_gp_catalog_image_sizes( 'lo-que-traía-core', array( 350, 525 ) ) );

			// Dentro del loop, sólo la miniatura de la tarjeta.
			$GLOBALS['woocommerce_loop'] = array( 'name' => AKIBARA_GP_CROSS_SELL_LOOP );
			self::assertSame( 'lo-que-traía-core', akibara_gp_catalog_image_sizes( 'lo-que-traía-core', array( 560, 243 ) ) );
			self::assertSame( 'lo-que-traía-core', akibara_gp_catalog_image_sizes( 'lo-que-traía-core', 'akibara-logo-footer' ) );
		}

		/**
		 * `wc_get_loop_prop()` llama a `wc_setup_loop()`, que crea el global. La regla
		 * corre en cada imagen del sitio: preguntar no puede dejar un loop montado.
		 *
		 * @testdox Preguntar por la venta cruzada no crea el loop de WooCommerce.
		 */
		public function test_asking_for_the_cross_sell_loop_does_not_create_it(): void {
			Functions\expect( 'wc_get_loop_prop' )->never();
			Functions\expect( 'wc_setup_loop' )->never();

			self::assertFalse( akibara_gp_is_cross_sell_loop() );
			akibara_gp_catalog_image_sizes( 'lo-que-traía-core', array( 350, 525 ) );
			self::assertArrayNotHasKey( 'woocommerce_loop', $GLOBALS );
		}

		/**
		 * @testdox La portada de la ficha declara el hueco en que se dibuja en 15 pantallas, de 320×568 a 1920×1080.
		 */
		public function test_single_cover_matches_the_measured_width(): void {
			$this->on_product( 16083, array( 501 => array( 800, 1200 ) ) );
			$sizes = akibara_gp_single_cover_sizes( 'lo-que-traía-core', array( 800, 1200 ), '', array(), 501 );

			self::assertIsString( $sizes );
			foreach ( self::FICHA as $clave => $medido ) {
				list( $ancho, $alto ) = $this->viewport( $clave );
				$declarado            = $this->evaluate( $sizes, $ancho, $alto );
				// Nunca por debajo de lo que se dibuja; como mucho una centésima por encima.
				self::assertGreaterThanOrEqual( $medido - 0.01, $declarado, "ficha {$clave}: declara de menos" );
				self::assertLessThanOrEqual( $medido + 0.05, $declarado, "ficha {$clave}: declara de más" );
			}
		}

		/**
		 * Cada imagen de la galería usa su propia proporción (Gannibal 1: 800×1124).
		 *
		 * @testdox Una imagen de la galería con otra proporción declara su propio hueco.
		 */
		public function test_gallery_image_uses_its_own_ratio(): void {
			$this->on_product( 16774, array( 25574 => array( 800, 1200 ), 23361 => array( 800, 1124 ) ), array( 23361 ) );
			$sizes = akibara_gp_single_cover_sizes( 'lo-que-traía-core', array( 800, 1124 ), '', array(), 23361 );

			self::assertIsString( $sizes );
			self::assertStringContainsString( 'calc(50vh * 0.7118)', $sizes, 'proporción redondeada hacia arriba' );
			foreach ( self::FICHA_800X1124 as $clave => $medido ) {
				list( $ancho, $alto ) = $this->viewport( $clave );
				$declarado            = $this->evaluate( $sizes, $ancho, $alto );
				self::assertGreaterThanOrEqual( $medido - 0.01, $declarado, "galería {$clave}: declara de menos" );
				self::assertLessThanOrEqual( $medido + 0.05, $declarado, "galería {$clave}: declara de más" );
			}
		}

		/**
		 * Un navegador que no entiende `min()` en `sizes` salta ese tramo y cae en el
		 * de respaldo: el ancho del contenedor, que es lo de antes y nunca de menos.
		 *
		 * @testdox Sin soporte de `min()`, la ficha declara el ancho del contenedor en móvil.
		 */
		public function test_single_cover_fallback_without_min(): void {
			$this->on_product( 16084, array( 601 => array( 800, 1200 ) ) );
			$sizes = akibara_gp_single_cover_sizes( 'lo-que-traía-core', array( 800, 1200 ), '', array(), 601 );

			self::assertSame( 380.0, $this->evaluate( $sizes, 412, 823, false ) );
			self::assertSame( 358.0, $this->evaluate( $sizes, 390, 844, false ) );
			self::assertSame( 500.0, $this->evaluate( $sizes, 1440, 900, false ) );
		}

		/**
		 * @testdox La regla de la ficha deja intactas las imágenes de otros productos, otros tamaños y otras páginas.
		 */
		public function test_single_cover_rule_only_touches_the_queried_product(): void {
			$this->on_product( 16085, array( 701 => array( 800, 1200 ) ) );

			// Otro adjunto (una tarjeta de relacionados, el logo): intacto.
			self::assertSame( 'core', akibara_gp_single_cover_sizes( 'core', array( 800, 1200 ), '', array(), 999 ) );
			// El adjunto del producto a otro tamaño (miniatura de la galería): intacto.
			self::assertSame( 'core', akibara_gp_single_cover_sizes( 'core', array( 100, 100 ), '', array(), 701 ) );
			self::assertSame( 'core', akibara_gp_single_cover_sizes( 'core', 'woocommerce_single', '', array(), 701 ) );
			// Sin adjunto: intacto.
			self::assertSame( 'core', akibara_gp_single_cover_sizes( 'core', array( 800, 1200 ) ) );

			// Fuera de la ficha: intacto.
			Functions\when( 'is_product' )->justReturn( false );
			self::assertSame( 'core', akibara_gp_single_cover_sizes( 'core', array( 800, 1200 ), '', array(), 701 ) );
		}

		/**
		 * Una portada más estrecha que 800 no tiene derivado `woocommerce_single`:
		 * WordPress sirve el original y la regla lo reconoce por las medidas.
		 *
		 * @testdox Una portada sin derivado de 800 también recibe el ancho pintado.
		 */
		public function test_single_cover_without_800_derivative(): void {
			$this->on_product( 16086, array( 801 => array( 600, 900 ) ) );

			$sizes = akibara_gp_single_cover_sizes( 'core', array( 600, 900 ), '', array(), 801 );
			self::assertIsString( $sizes );
			self::assertStringEndsWith( ', 500px', $sizes );
			self::assertEqualsWithDelta( 274.33, $this->evaluate( $sizes, 412, 823 ), 0.02 );
		}

		/**
		 * @testdox La cabecera de una entrada declara al píxel el ancho medido en 15 viewports.
		 */
		public function test_post_header_matches_the_measured_width(): void {
			$sizes = akibara_blog_post_image_sizes();

			foreach ( self::ENTRADA as $viewport => $medido ) {
				self::assertEqualsWithDelta( $medido, $this->evaluate( $sizes, $viewport ), 0.01, "entrada, viewport {$viewport}" );
			}
		}

		/**
		 * @testdox Las tarjetas del blog declaran al píxel el ancho medido en 20 viewports, cortes de la rejilla incluidos.
		 */
		public function test_blog_card_matches_the_measured_width(): void {
			$sizes = akibara_blog_card_image_sizes();

			foreach ( self::TARJETA_BLOG as $viewport => $medido ) {
				$declarado = $this->evaluate( $sizes, $viewport );
				$en_franja = false;
				foreach ( self::FRANJAS_DE_LA_BARRA as list( $desde, $hasta ) ) {
					$en_franja = $en_franja || ( $viewport >= $desde && $viewport <= $hasta );
				}
				if ( $en_franja ) {
					// Una columna de más: la imagen del tramo siguiente, nunca una menor.
					self::assertGreaterThanOrEqual( $medido, $declarado, "tarjeta, viewport {$viewport}: nunca de menos" );
					self::assertLessThanOrEqual( 2.1 * $medido, $declarado, "tarjeta, viewport {$viewport}: como mucho la columna siguiente" );
					continue;
				}
				self::assertEqualsWithDelta( $medido, $declarado, 0.01, "tarjeta, viewport {$viewport}" );
			}
		}

		/**
		 * Revisión T1-D1: con los cortes de la cuenta sin barra, entre 1.056 y 1.072
		 * (y entre 688 y 704) una ventana con barra clásica tenía dos columnas (una)
		 * y el `sizes` declaraba tres (dos): portada blanda en escritorio Windows.
		 *
		 * @testdox La tarjeta del blog nunca declara menos de lo que mide con barra de desplazamiento clásica.
		 */
		public function test_blog_card_never_under_declares_with_a_classic_scrollbar(): void {
			$sizes = akibara_blog_card_image_sizes();

			foreach ( self::TARJETA_BLOG_CON_BARRA as $viewport => $medido ) {
				$declarado = $this->evaluate( $sizes, $viewport );
				self::assertGreaterThanOrEqual( $medido, $declarado, "con barra, viewport {$viewport}: nunca de menos" );
				self::assertLessThanOrEqual( $medido + 18, $declarado, "con barra, viewport {$viewport}: a lo sumo el ancho de la barra de más" );
			}
		}
	}
}
