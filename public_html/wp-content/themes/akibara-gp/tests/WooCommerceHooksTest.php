<?php
/**
 * Contrato ejecutable de `inc/woocommerce.php`.
 *
 * Cubre las tres decisiones que la inspección encontró rotas o duplicadas: el
 * layout del sidebar (que tenía DOS dueños encadenados), la body-class de
 * distrito (que iba a la base para comparar dos cadenas) y la resolución del
 * logo de editorial (copiada en tres plantillas con tres políticas distintas).
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * @coversNothing
 */
final class WooCommerceHooksTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'is_woocommerce' )->justReturn( true );

		// `inc/woocommerce.php` llama a `akibara_gp_wc_activo()`, que vive en
		// `inc/page-targets.php`. Sin este require la clase pasaba sólo cuando otra
		// prueba había cargado ese fichero antes; aislada caía con «undefined
		// function» (2026-09-19).
		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		// El título de la venta cruzada de la búsqueda vacía sale de
		// `akibara_gp_empty_popular_title()` (R-I1-6).
		require_once dirname( __DIR__ ) . '/inc/site-copy.php';
		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Fija las condicionales de ruta que la función bajo prueba consulta.
	 *
	 * @param array<string, mixed> $verdaderas Nombre de función => valor.
	 */
	private function ruta( array $verdaderas = array() ): void {
		// `is_product` entró a esta lista con S-bf0c1196 (2026-09-19):
		// `akibara_gp_distrito_slug()` no la llama directo, pero SÍ la exige
		// `akibara_gp_wc_activo()` (vía `function_exists()`) antes de seguir.
		// Sin estabearla aquí, esta prueba pasaba en la suite completa SOLO por
		// contagio de otro fichero (`PageTargetsTest`) que sí la estabea, y
		// caía sola con `--filter WooCommerceHooksTest`.
		foreach ( array( 'is_shop', 'is_product_taxonomy', 'is_product', 'is_singular', 'is_search', 'is_product_category', 'is_product_tag' ) as $condicional ) {
			Functions\when( $condicional )->justReturn( $verdaderas[ $condicional ] ?? false );
		}

		$taxonomias = $verdaderas['is_tax'] ?? array();
		Functions\when( 'is_tax' )->alias(
			static function ( $taxonomies ) use ( $taxonomias ) {
				foreach ( (array) $taxonomies as $taxonomy ) {
					if ( in_array( $taxonomy, (array) $taxonomias, true ) ) {
						return true;
					}
				}
				return false;
			}
		);
		Functions\when( 'get_query_var' )->justReturn( $verdaderas['get_query_var'] ?? '' );
	}

	/* --- Layout del sidebar ------------------------------------------- */

	/**
	 * @testdox La ficha de producto y las landings de serie/autor van a ancho completo.
	 */
	public function test_full_width_surfaces(): void {
		$this->ruta( array( 'is_singular' => true ) );
		Functions\when( 'is_singular' )->alias(
			static function ( $type = '' ): bool {
				return 'product' === $type;
			}
		);
		self::assertSame( 'no-sidebar', akibara_gp_archive_sidebar_layout( 'left-sidebar' ) );

		$this->ruta( array( 'is_tax' => array( 'pa_serie' ) ) );
		self::assertSame( 'no-sidebar', akibara_gp_archive_sidebar_layout( 'left-sidebar' ) );

		$this->ruta( array( 'is_tax' => array( 'pa_autor' ) ) );
		self::assertSame( 'no-sidebar', akibara_gp_archive_sidebar_layout( 'left-sidebar' ) );
	}

	/**
	 * @testdox El catálogo y la búsqueda conservan la columna de facetas.
	 */
	public function test_catalog_and_search_keep_the_facet_column(): void {
		$this->ruta( array( 'is_shop' => true ) );
		self::assertSame( 'left-sidebar', akibara_gp_archive_sidebar_layout( 'no-sidebar' ) );

		// La búsqueda nativa de entradas también: es la composición que servían
		// los dos filtros encadenados y se conserva tal cual.
		$this->ruta( array( 'is_search' => true ) );
		self::assertSame( 'left-sidebar', akibara_gp_archive_sidebar_layout( 'no-sidebar' ) );
	}

	/**
	 * ESTA es la mitad que vivía sólo en `inc/setup.php` y que la consolidación
	 * tenía que no perder: la preferencia por página del editor.
	 *
	 * @testdox Una página singular respeta el layout que fijó el editor.
	 */
	public function test_per_page_preference_survives(): void {
		$this->ruta( array( 'is_singular' => true ) );
		Functions\when( 'is_singular' )->alias(
			static function ( $type = '' ): bool {
				return '' === $type;
			}
		);
		Functions\when( 'get_the_ID' )->justReturn( 42 );
		Functions\expect( 'get_post_meta' )
			->once()
			->with( 42, '_generate-sidebar-layout-meta', true )
			->andReturn( 'right-sidebar' );

		self::assertSame( 'right-sidebar', akibara_gp_archive_sidebar_layout( 'no-sidebar' ) );
	}

	/**
	 * @testdox Sin preferencia ni contexto de catálogo, no hay sidebar.
	 */
	public function test_default_is_no_sidebar(): void {
		$this->ruta();

		self::assertSame( 'no-sidebar', akibara_gp_archive_sidebar_layout( 'both-sidebars' ) );
	}

	/* --- Body-class de distrito --------------------------------------- */

	/**
	 * REAPERTURA S-bf0c1196 (2026-09-19): esta función corre en TODAS las
	 * peticiones de front vía el filtro `body_class`. Sin la guarda,
	 * `is_shop()`/`is_product_taxonomy()` son funciones indefinidas sin
	 * WooCommerce activo y fatalean el sitio entero.
	 *
	 * Candado de FUENTE, y a propósito: probar el escenario «WooCommerce
	 * ausente» como comportamiento exigiría que NADA en esta suite —ni en este
	 * fichero, cuyo `setUp()` ya estabea `is_woocommerce`, ni en ningún otro
	 * que corra antes en el mismo proceso— haya llamado nunca `Functions\when()`
	 * sobre `is_shop`/`is_product_taxonomy`: Brain Monkey los da por «existentes»
	 * (`function_exists()` = true) por CONTAGIO en cuanto se estabean una sola
	 * vez, y no hay forma de deshacerlo dentro del mismo proceso (memoria del
	 * proyecto, «function-exists-miente-en-la-suite»). Lo que sí se fija: la
	 * guarda es la PRIMERA línea del cuerpo, antes de `is_shop()`.
	 *
	 * @testdox `akibara_gp_distrito_slug()` sale temprano sin WooCommerce, antes de llamar cualquier condicional suya.
	 */
	public function test_district_slug_bails_before_any_woocommerce_conditional(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );

		$inicio  = strpos( $fuente, 'function akibara_gp_distrito_slug(): string {' );
		self::assertIsInt( $inicio, 'No se encontró akibara_gp_distrito_slug() en inc/woocommerce.php.' );
		$fin = strpos( $fuente, "\n}\n", $inicio );
		self::assertIsInt( $fin );
		$cuerpo = substr( $fuente, $inicio, $fin - $inicio );

		$guarda   = strpos( $cuerpo, 'akibara_gp_wc_activo()' );
		$is_shop  = strpos( $cuerpo, 'is_shop()' );

		self::assertIsInt( $guarda, 'La función no llama a akibara_gp_wc_activo().' );
		self::assertIsInt( $is_shop, 'La función ya no llama a is_shop(): actualizar este candado si cambió la condicional que dispara el fatal.' );
		self::assertLessThan( $is_shop, $guarda, 'La guarda de WooCommerce tiene que ir ANTES de is_shop(), o el fatal ya ocurrió.' );

		self::assertMatchesRegularExpression(
			"/if\\s*\\(\\s*!\\s*akibara_gp_wc_activo\\(\\)\\s*\\)\\s*\\{\\s*\\n\\s*return '';\\s*\\n\\s*\\}/",
			$cuerpo,
			'La guarda debe cortar la ejecución (return) sin WooCommerce, no sólo consultarla.'
		);
	}

	/**
	 * EL DEFECTO QUE CIERRA: resolvía los dos términos con `get_term_by()` para
	 * comparar ids, o sea cuatro consultas en frío por archive de categoría,
	 * cuando WordPress ya garantiza slug único dentro de una taxonomía.
	 *
	 * @testdox Las dos puertas del distrito se reconocen sin tocar la base.
	 */
	public function test_district_is_resolved_without_a_query(): void {
		Functions\expect( 'get_term_by' )->never();

		foreach ( array( 'manga', 'comics' ) as $slug ) {
			$this->ruta( array( 'is_product_taxonomy' => true ) );
			Functions\when( 'get_queried_object' )->justReturn(
				new WP_Term(
					array(
						'term_id'  => 7,
						'slug'     => $slug,
						'taxonomy' => 'product_cat',
					)
				)
			);

			self::assertSame( $slug, akibara_gp_distrito_slug() );
		}
	}

	/**
	 * @testdox Un género no es un distrito, y la tienda tampoco.
	 */
	public function test_other_archives_are_not_a_district(): void {
		$this->ruta( array( 'is_product_taxonomy' => true ) );
		Functions\when( 'get_queried_object' )->justReturn(
			new WP_Term(
				array(
					'term_id'  => 9,
					'slug'     => 'shonen',
					'taxonomy' => 'product_cat',
				)
			)
		);
		self::assertSame( '', akibara_gp_distrito_slug() );

		// Un término de OTRA taxonomía con el mismo slug tampoco cuenta.
		Functions\when( 'get_queried_object' )->justReturn(
			new WP_Term(
				array(
					'term_id'  => 11,
					'slug'     => 'manga',
					'taxonomy' => 'pa_genero',
				)
			)
		);
		self::assertSame( '', akibara_gp_distrito_slug() );

		$this->ruta( array( 'is_shop' => true, 'is_product_taxonomy' => true ) );
		self::assertSame( '', akibara_gp_distrito_slug() );
	}

	/* --- Logo de editorial -------------------------------------------- */

	/**
	 * @testdox El SVG del tema gana y viaja con la caja de la placa.
	 */
	public function test_theme_svg_wins_and_declares_the_plate_box(): void {
		Functions\when( 'get_stylesheet_directory' )->justReturn( dirname( __DIR__ ) );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://akibara.test/tema' );
		Functions\expect( 'wp_get_attachment_image_src' )->never();

		$logo = akibara_gp_brand_logo( 'ivrea-espana', 123 );

		self::assertSame( 'https://akibara.test/tema/assets/img/editoriales/ivrea-espana.svg', $logo['url'] );
		self::assertSame( 200, $logo['w'] );
		self::assertSame( 100, $logo['h'] );
	}

	/**
	 * @testdox Sin SVG local se cae a la imagen del término, con sus medidas.
	 */
	public function test_term_image_is_the_fallback(): void {
		Functions\when( 'get_stylesheet_directory' )->justReturn( dirname( __DIR__ ) );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://akibara.test/tema' );
		Functions\expect( 'wp_get_attachment_image_src' )
			->once()
			->with( 456, 'full' )
			->andReturn( array( 'https://akibara.test/logo.png', 640, 320 ) );

		$logo = akibara_gp_brand_logo( 'editorial-sin-svg', 456 );

		self::assertSame( 'https://akibara.test/logo.png', $logo['url'] );
		self::assertSame( 640, $logo['w'] );
		self::assertSame( 320, $logo['h'] );
	}

	/**
	 * Las tres superficies pintan el logo dentro de una placa de tamaño fijo, así
	 * que lo único que no pueden recibir es una URL vacía con medidas puestas.
	 *
	 * @testdox Sin logo la respuesta es vacía y coherente.
	 */
	public function test_missing_logo_returns_an_empty_shape(): void {
		Functions\when( 'get_stylesheet_directory' )->justReturn( dirname( __DIR__ ) );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://akibara.test/tema' );

		self::assertSame(
			array(
				'url' => '',
				'w'   => 0,
				'h'   => 0,
			),
			akibara_gp_brand_logo( 'editorial-sin-svg' )
		);
	}

	/* --- ARIA del trigger del carrito (ficha R4-9c9fffc5) -------------- */

	/**
	 * En cualquier página normal (tienda, ficha de producto, home, etc.) con
	 * el carrito nativo activo, el click en `.akb-cart-trigger` SÍ abre el
	 * cajón de NativeCart, así que el lector de pantalla debe anunciarlo como
	 * un disparador de diálogo. SIN `aria-expanded`: `native-cart.js`
	 * (akibara-business) no lo togglea sobre el trigger (revisor
	 * independiente, hallazgo R4-9c9fffc5-1, 2026-09-19) — declararlo aquí
	 * fijo en "false" mentiría en cuanto el cajón se abriera.
	 *
	 * @testdox Fuera de carrito/checkout, con el carrito nativo activo, el trigger anuncia el diálogo.
	 */
	public function test_cart_trigger_announces_the_dialog_by_default(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'yes' );

		self::assertSame(
			' aria-haspopup="dialog"',
			akibara_gp_cart_trigger_aria()
		);
	}

	/**
	 * EL DEFECTO QUE CIERRA: en `/carrito/` y `/finalizar-compra/` NativeCart
	 * (`NativeCart::enqueue_assets()` / `::render_drawer_html()`) no carga su
	 * script ni pinta el drawer, así que `.akb-cart-trigger` queda como un
	 * `<a>` normal que navega — anunciar `aria-haspopup="dialog"` ahí sería un
	 * diálogo que nunca se abre.
	 *
	 * @testdox En carrito y checkout el trigger es un enlace normal: sin ARIA de diálogo.
	 */
	public function test_cart_trigger_stays_silent_on_cart_and_checkout(): void {
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'is_checkout' )->justReturn( false );
		self::assertSame( '', akibara_gp_cart_trigger_aria(), 'La página /carrito/ no debe anunciar un diálogo.' );

		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( true );
		self::assertSame( '', akibara_gp_cart_trigger_aria(), 'El checkout no debe anunciar un diálogo.' );
	}

	/**
	 * EL DEFECTO QUE CIERRA (revisor independiente, hallazgo R4-9c9fffc5-2,
	 * 2026-09-19): un admin puede desmarcar «Activar carrito nativo»
	 * (`Settings::OPTION_ENABLED`) sin tocar ninguna URL. Con eso,
	 * `NativeCart::enqueue_assets()`/`::render_drawer_html()` dejan de cargar
	 * el script y de pintar el drawer en TODO el sitio, así que
	 * `.akb-cart-trigger` vuelve a ser un `<a>` normal que navega — mirar sólo
	 * `is_cart()`/`is_checkout()` seguía anunciando el diálogo fantasma.
	 *
	 * @testdox Con el carrito nativo desactivado, el trigger es un enlace normal en todo el sitio.
	 */
	public function test_cart_trigger_stays_silent_when_native_cart_disabled(): void {
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 'no' );

		self::assertSame(
			'',
			akibara_gp_cart_trigger_aria(),
			'Con el carrito nativo apagado no debe anunciarse un diálogo que no existe.'
		);
	}

	/**
	 * UC-4: el extracto de la ficha va DESPUÉS del botón de compra.
	 *
	 * WooCommerce lo registra en la prioridad 20, entre el precio y el formulario
	 * (30); a 1366x768 empujaba el botón de la ficha a pedido a 823 px, bajo el
	 * pliegue. Tiene que quedar tras el formulario, las promesas (31) y el
	 * «avísame»/favoritos (35), y no puede quedar duplicado en la 20.
	 */
	public function test_el_extracto_va_despues_del_boton_de_compra(): void {
		// Lo que deja registrado WooCommerce (wc-template-hooks.php).
		add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 );

		akibara_gp_extracto_tras_la_compra();

		self::assertFalse( has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20 ) );
		self::assertTrue( has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 36 ) );
	}

	/* --- «Añadir» de la tarjeta: sin descripción vacía (AI-11) ---------- */

	/**
	 * @testdox Sin texto, el «Añadir» de la tarjeta no pide aria-describedby (ni su span con id).
	 *
	 * loop/add-to-cart.php de WooCommerce pinta el atributo y el span siempre que
	 * el argumento EXISTE: 32 spans vacíos en la portada y, con el mismo tomo en
	 * dos secciones, ids duplicados (axe duplicate-id-aria).
	 */
	public function test_el_anadir_de_la_tarjeta_no_apunta_a_una_descripcion_vacia(): void {
		$args = array(
			'quantity'              => 1,
			'class'                 => 'button add_to_cart_button',
			'aria-describedby_text' => '',
			'attributes'            => array( 'aria-label' => 'Añadir al carrito: “Tomo 1”' ),
		);

		$filtrados = akibara_gp_loop_add_to_cart_sin_descripcion_vacia( $args );

		self::assertArrayNotHasKey( 'aria-describedby_text', $filtrados, 'isset() en la plantilla basta para pintar el span vacío.' );
		self::assertSame( $args['attributes'], $filtrados['attributes'], 'El nombre del botón (aria-label) no se toca.' );
		self::assertSame( $args['class'], $filtrados['class'] );

		self::assertArrayNotHasKey( 'aria-describedby_text', akibara_gp_loop_add_to_cart_sin_descripcion_vacia( array( 'aria-describedby_text' => "  \n" ) ), 'Sólo espacios también es vacío.' );
	}

	/** @testdox Con texto (un variable, o un plugin que lo filtre), la descripción se respeta. */
	public function test_con_texto_la_descripcion_se_respeta(): void {
		$args = array( 'aria-describedby_text' => 'Este producto tiene varias variantes.' );

		self::assertSame( $args, akibara_gp_loop_add_to_cart_sin_descripcion_vacia( $args ) );
		self::assertSame( array( 'class' => 'button' ), akibara_gp_loop_add_to_cart_sin_descripcion_vacia( array( 'class' => 'button' ) ), 'Sin el argumento, nada que quitar.' );
	}

	/** @testdox El filtro queda enganchado donde WooCommerce arma los argumentos del botón. */
	public function test_el_filtro_de_la_descripcion_esta_enganchado(): void {
		// inc/woocommerce.php se carga con require_once una sola vez por proceso y
		// Brain Monkey limpia los hooks entre pruebas: has_filter() no sirve aquí.
		// Se fija la llamada en la fuente.
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );
		self::assertMatchesRegularExpression( "/add_filter\\(\\s*'woocommerce_loop_add_to_cart_args',\\s*'akibara_gp_loop_add_to_cart_sin_descripcion_vacia'/", $fuente );
	}

	/* --- Búsqueda sin resultados (UM-8 = UC-1) ------------------------- */

	/**
	 * @testdox Una búsqueda de texto sin resultados pinta «Lo más vendido» dentro del aviso del buscador.
	 */
	public function test_la_busqueda_sin_resultados_lleva_la_venta_cruzada(): void {
		Functions\when( 'get_option' )->alias( static fn( $option, $fallback = false ) => $fallback );
		Functions\expect( 'get_template_part' )
			->once()
			->with(
				'template-parts/empty/cross-sell',
				null,
				array(
					'heading_id'   => 'akb-search-empty-popular-title',
					'heading_tag'  => 'h2',
					'heading_text' => 'Lo más vendido',
				)
			);

		akibara_gp_search_empty_cross_sell( 'inuyasha', 'busqueda' );
		$this->addToAssertionCount( 1 ); // La expectativa de Mockery se verifica al cerrar la prueba.
	}

	/**
	 * R-I1-6 (revisión del lote I1): el título era un literal del tema en una
	 * superficie nueva. Lo edita el dueño en Akibara → Textos → «Blog /
	 * Buscador» (`akb_copy_search_empty_popular`), y en blanco vuelve al de
	 * fábrica: el guardado no puede dejar la fila sin encabezado.
	 *
	 * @testdox El título de los más vendidos en la búsqueda vacía sale del texto del panel.
	 */
	public function test_el_titulo_de_la_venta_cruzada_se_edita_en_el_panel(): void {
		$valor = 'Los favoritos del Distrito';
		Functions\when( 'get_option' )->alias(
			static function ( $option, $fallback = false ) use ( &$valor ) {
				return 'akb_copy_search_empty_popular' === $option ? $valor : $fallback;
			}
		);
		$titulos = array();
		Functions\when( 'get_template_part' )->alias(
			static function ( $slug, $name = null, $args = array() ) use ( &$titulos ): void {
				$titulos[] = $args['heading_text'] ?? null;
			}
		);

		akibara_gp_search_empty_cross_sell( 'inuyasha', 'busqueda' );
		$valor = '   ';
		akibara_gp_search_empty_cross_sell( 'inuyasha', 'busqueda' );

		self::assertSame( array( 'Los favoritos del Distrito', 'Lo más vendido' ), $titulos );

		// El vacío de WooCommerce (sin el buscador) usa el mismo texto.
		$plantilla = (string) file_get_contents( dirname( __DIR__ ) . '/woocommerce/loop/no-products-found.php' );
		self::assertStringContainsString( "'heading_text' => akibara_gp_empty_popular_title()", $plantilla );
		self::assertStringNotContainsString( "'heading_text' => 'Lo más vendido'", $plantilla );
	}

	/**
	 * QA-DIFF-TEMA-5: la fila es una superficie comercial y el dueño la apaga en
	 * Akibara → Recomendaciones. El tema no lee la option: pregunta al filtro que
	 * responde akibara-business, y sin respuesta la pinta (de fábrica, encendida:
	 * es la prueba de arriba, donde nadie responde el filtro).
	 *
	 * @testdox Apagada en el panel (o con una respuesta vacía), la búsqueda sin resultados no pinta «Lo más vendido».
	 */
	public function test_la_venta_cruzada_de_la_busqueda_se_apaga_desde_el_panel(): void {
		Functions\when( 'get_option' )->alias( static fn( $option, $fallback = false ) => $fallback );
		$pintadas = 0;
		Functions\when( 'get_template_part' )->alias(
			static function () use ( &$pintadas ): void {
				++$pintadas;
			}
		);

		Filters\expectApplied( 'akibara_show_popular_in_empty_search' )->once()->with( true )->andReturn( false );
		akibara_gp_search_empty_cross_sell( 'gundam', 'busqueda' );
		self::assertSame( 0, $pintadas, 'Apagada: el aviso queda sin la fila.' );

		Filters\expectApplied( 'akibara_show_popular_in_empty_search' )->once()->with( true )->andReturn( '' );
		akibara_gp_search_empty_cross_sell( 'gundam', 'busqueda' );
		self::assertSame( 0, $pintadas, 'Una respuesta vacía también la apaga.' );
	}

	/**
	 * Con filtros sin texto la salida es quitar un chip; la serie sin tomos ya
	 * ofrece el directorio (y ponerle venta cruzada es decisión del dueño).
	 *
	 * @testdox Una rejilla vaciada sólo por filtros, o una serie sin tomos, no repite los más vendidos.
	 */
	public function test_filtros_y_serie_vacia_no_llevan_venta_cruzada(): void {
		Functions\expect( 'get_template_part' )->never();

		akibara_gp_search_empty_cross_sell( '', 'filtros' );
		akibara_gp_search_empty_cross_sell( '', 'serie' );
		akibara_gp_search_empty_cross_sell( 'x', 'otro-motivo-futuro' );
		$this->addToAssertionCount( 1 ); // La expectativa de Mockery se verifica al cerrar la prueba.
	}

	/** @testdox La venta cruzada cuelga del hook que akibara-search dispara DENTRO de `[data-akb-empty]`. */
	public function test_la_venta_cruzada_esta_enganchada_al_hook_del_buscador(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );
		self::assertMatchesRegularExpression( "/add_action\\(\\s*'akibara_search_empty_after',\\s*'akibara_gp_search_empty_cross_sell',\\s*10,\\s*2\\s*\\)/", $fuente );

		// El productor, si está en el árbol: el hook sigue dentro del nodo que el PJAX reemplaza.
		$buscador = dirname( __DIR__, 3 ) . '/plugins/akibara-search/frontend/facets-render.php';
		if ( ! is_readable( $buscador ) ) {
			return;
		}
		$render = (string) file_get_contents( $buscador );
		$inicio = strpos( $render, 'function akibara_gp_akb_print_empty_state' );
		self::assertNotFalse( $inicio );
		$cuerpo = substr( $render, (int) $inicio, 6000 );
		$hook   = strpos( $cuerpo, "do_action( 'akibara_search_empty_after', \$q, \$motivo );" );
		self::assertNotFalse( $hook, 'akibara-search sigue disparando el hook con ($q, $motivo).' );
		self::assertGreaterThan( $hook, strpos( $cuerpo, "echo '</div>';", (int) $hook ), 'El hook corre antes de cerrar [data-akb-empty].' );
	}

	/* --- Paginación: la página 1 es la URL base -------------------------- */

	/**
	 * Enlace de la página `$n` como lo arma `paginate_links()` de WordPress core
	 * (wp-includes/general-template.php): las dos líneas que deciden la URL, sin
	 * `add_args` (que se añaden después igual para todas las páginas).
	 *
	 * @param array<string, mixed> $args Argumentos ya filtrados.
	 * @param int                  $n    Página.
	 */
	private function enlace_de_pagina( array $args, int $n ): string {
		$enlace = str_replace( '%_%', 1 === $n ? '' : (string) $args['format'], (string) $args['base'] );
		return str_replace( '%#%', (string) $n, $enlace );
	}

	/**
	 * @testdox La página 1 del catálogo enlaza a la URL base y no a /page/1/ (que redirige 301).
	 */
	public function test_la_pagina_1_enlaza_a_la_base(): void {
		unset( $GLOBALS['wp_rewrite'] );
		$this->textos_de_paginacion();
		// Lo que arma woocommerce/loop/pagination.php (medido: /tienda/page/2/ en
		// producción enlazaba href="https://akibara.cl/tienda/page/1/").
		$args = akibara_gp_pagination_args(
			array(
				'base'   => 'https://akibara.test/tienda/page/%#%/',
				'format' => '',
			)
		);
		self::assertSame( 'https://akibara.test/tienda/', $this->enlace_de_pagina( $args, 1 ) );
		self::assertSame( 'https://akibara.test/tienda/page/2/', $this->enlace_de_pagina( $args, 2 ) );
		self::assertSame( 'https://akibara.test/tienda/page/57/', $this->enlace_de_pagina( $args, 57 ) );
		self::assertSame( 1, $args['mid_size'] );

		// Con filtros en la base (get_pagenum_link conserva la consulta).
		$args = akibara_gp_pagination_args(
			array(
				'base'   => 'https://akibara.test/serie/one-piece/page/%#%/?_buscar=one%20piece&_estado=preventa',
				'format' => '',
			)
		);
		self::assertSame( 'https://akibara.test/serie/one-piece/?_buscar=one%20piece&_estado=preventa', $this->enlace_de_pagina( $args, 1 ) );
		self::assertSame( 'https://akibara.test/serie/one-piece/page/3/?_buscar=one%20piece&_estado=preventa', $this->enlace_de_pagina( $args, 3 ) );
	}

	/**
	 * Traducciones y escape de WordPress, tal cual (el sitio las devuelve en español).
	 */
	private function textos_de_paginacion(): void {
		Functions\when( 'is_rtl' )->justReturn( false );
		Functions\when( '__' )->alias(
			static fn( string $texto ) => array(
				'Previous page' => 'Página anterior',
				'Next page'     => 'Página siguiente',
				'Page'          => 'Página',
			)[ $texto ] ?? $texto
		);
		Functions\when( 'esc_html' )->alias( static fn( $texto ) => htmlspecialchars( (string) $texto, ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * @testdox Las flechas de la paginación se llaman «Página siguiente» / «Página anterior» y el número lleva «Página» oculto (QA-ESC-TECLADO-11).
	 */
	public function test_la_paginacion_nombra_flechas_y_numeros(): void {
		unset( $GLOBALS['wp_rewrite'] );
		$this->textos_de_paginacion();
		$args = akibara_gp_pagination_args(
			array(
				'base'      => 'https://akibara.test/tienda/page/%#%/',
				'format'    => '',
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
			)
		);

		self::assertSame( '<span aria-hidden="true">&rarr;</span><span class="screen-reader-text">Página siguiente</span>', $args['next_text'], 'antes el nombre era sólo «→»' );
		self::assertSame( '<span aria-hidden="true">&larr;</span><span class="screen-reader-text">Página anterior</span>', $args['prev_text'] );
		self::assertSame( '<span class="screen-reader-text">Página </span>', $args['before_page_number'] );

		// En un idioma de derecha a izquierda las flechas se invierten, como en la plantilla.
		Functions\when( 'is_rtl' )->justReturn( true );
		$rtl = akibara_gp_pagination_args( array( 'base' => 'https://akibara.test/tienda/page/%#%/', 'format' => '' ) );
		self::assertStringStartsWith( '<span aria-hidden="true">&larr;</span>', $rtl['next_text'] );
	}

	/**
	 * Carga el procesador de etiquetas de WordPress (el mismo que usa WooCommerce
	 * para poner los aria-label) desde el core del repositorio.
	 */
	private function procesador_html(): void {
		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return;
		}
		$core = dirname( __DIR__, 4 ) . '/wp-includes/';
		if ( ! is_readable( $core . 'html-api/class-wp-html-tag-processor.php' ) ) {
			self::markTestSkipped( 'Sin el core de WordPress al lado no hay WP_HTML_Tag_Processor.' );
		}
		require_once $core . 'class-wp-token-map.php';
		foreach ( array( 'class-wp-html-attribute-token', 'class-wp-html-span', 'class-wp-html-text-replacement', 'class-wp-html-decoder', 'html5-named-character-references', 'class-wp-html-tag-processor' ) as $fichero ) {
			require_once $core . 'html-api/' . $fichero . '.php';
		}
	}

	/**
	 * @testdox El número de la página actual pierde el aria-label prohibido en un <span>; los enlaces lo conservan; otra paginación no se toca (QA-ESC-TECLADO-11).
	 */
	public function test_la_pagina_actual_no_lleva_aria_label(): void {
		$this->procesador_html();
		$antes = '<span class="screen-reader-text">Página </span>';
		// La salida después de wc_add_aria_label_to_pagination_numbers (prioridad 10), medida en el espejo.
		$html = "<ul class='page-numbers'><li><span aria-label=\"Página 1\" aria-current=\"page\" class=\"page-numbers current\">{$antes}1</span></li>"
			. "<li><a aria-label=\"Página 2\" class=\"page-numbers\" href=\"/tienda/page/2/\">{$antes}2</a></li></ul>";

		$salida = akibara_gp_pagination_sin_aria_label_en_la_actual( $html, array( 'before_page_number' => $antes ) );

		self::assertStringNotContainsString( 'aria-label="Página 1"', $salida );
		self::assertStringContainsString( 'aria-current="page" class="page-numbers current"', $salida );
		self::assertStringContainsString( 'aria-label="Página 2"', $salida, 'en un enlace el atributo es válido' );

		// La paginación del blog no lleva el texto oculto: ahí el atributo es lo único que dice «Página».
		self::assertSame( $html, akibara_gp_pagination_sin_aria_label_en_la_actual( $html, array( 'before_page_number' => '' ) ) );
		self::assertSame( '', akibara_gp_pagination_sin_aria_label_en_la_actual( null, array() ) );
	}

	/**
	 * @testdox Sin barra final, con otra base de paginación, o si ya viene con formato, se respeta.
	 */
	public function test_la_pagina_1_respeta_otras_formas_de_la_base(): void {
		$GLOBALS['wp_rewrite'] = (object) array( 'pagination_base' => 'pagina' );
		$args                  = akibara_gp_pagination_pagina_1_en_la_base(
			array(
				'base'   => 'https://akibara.test/tienda/pagina/%#%',
				'format' => '',
			)
		);
		unset( $GLOBALS['wp_rewrite'] );
		self::assertSame( 'https://akibara.test/tienda', $this->enlace_de_pagina( $args, 1 ) );
		self::assertSame( 'https://akibara.test/tienda/pagina/4', $this->enlace_de_pagina( $args, 4 ) );

		// Sin permalinks (`?paged=%#%`), o con un `format` puesto por otro filtro: intacto.
		foreach ( array(
			array( 'base' => 'https://akibara.test/?post_type=product&paged=%#%', 'format' => '' ),
			array( 'base' => 'https://akibara.test/tienda/%_%', 'format' => 'page/%#%/' ),
			array( 'base' => 'https://akibara.test/tienda/page/%#%/', 'format' => '?pg=%#%' ),
		) as $original ) {
			self::assertSame( $original, akibara_gp_pagination_pagina_1_en_la_base( $original ) );
		}
	}
}
