<?php
/**
 * Contrato ejecutable de la resolución de páginas objetivo del theme.
 *
 * Estas condiciones deciden qué CSS/JS se encola en cada ruta. Antes vivían
 * como IDs de `wp_posts` incrustados (210, 22637, 24484…) repartidos en tres
 * archivos: dos de ellos ya no correspondían a ningún post, así que sus ramas
 * eran código muerto y nadie se enteró. Al migrarlas a slug hacía falta una
 * prueba que ejecute la decisión, no que haga grep del fuente (THM-04/THM-09).
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

namespace Akibara\GP\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/page-targets.php';

/**
 * @coversNothing
 */
final class PageTargetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * S-bf0c1196 (2026-09-19): fuente única de «¿hay WooCommerce?» para las dos
	 * superficies que la REAPERTURA encontró llamando condicionales de
	 * WooCommerce sin ninguna guarda (el closure de `wp_head` en functions.php
	 * y `akibara_gp_distrito_slug()` en inc/woocommerce.php).
	 *
	 * @testdox Con ambas condicionales presentes, WooCommerce cuenta como activo.
	 */
	public function test_wc_activo_is_true_with_both_conditionals(): void {
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product' )->justReturn( true );

		self::assertTrue( akibara_gp_wc_activo() );
	}

	/**
	 * El caso «WooCommerce ausente» (cualquiera de las dos condicionales
	 * indefinida) no se puede montar como prueba de comportamiento en esta
	 * suite: Brain Monkey da `function_exists()` = true por CONTAGIO en cuanto
	 * cualquier prueba —de este fichero o de cualquier otro que corra antes en
	 * el mismo proceso, como `WooCommerceHooksTest`— estabea `is_shop` o
	 * `is_product` una sola vez (ver memoria del proyecto,
	 * «function-exists-miente-en-la-suite»). Montarlo exigiría aislar esta
	 * prueba en su propio proceso PHP, desproporcionado para un `&&` de una
	 * línea. Candado de FUENTE: fija que las dos condicionales sean AMBAS
	 * necesarias (un `&&`, no un `||`) y que sean justo esas dos.
	 *
	 * @testdox La implementación exige AMBAS condicionales con `&&`, no una sola ni un `||`.
	 */
	public function test_wc_activo_source_requires_both_conditionals_with_and(): void {
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/page-targets.php' );

		self::assertMatchesRegularExpression(
			"/function akibara_gp_wc_activo\\(\\): bool \\{\\s*\\n\\s*return function_exists\\( 'is_shop' \\) && function_exists\\( 'is_product' \\);/",
			$fuente
		);
	}

	/**
	 * @testdox La home real cuenta como superficie home sin consultar la página alterna.
	 */
	public function test_front_page_is_a_home_surface(): void {
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\expect( 'is_page' )->never();

		self::assertTrue( akibara_gp_is_home_surface() );
	}

	/**
	 * @testdox La home alternativa se resuelve por SLUG, nunca por ID.
	 */
	public function test_alternate_home_resolves_by_slug(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\expect( 'is_page' )->once()->with( 'home-1' )->andReturn( true );

		self::assertTrue( akibara_gp_is_home_surface() );
	}

	/**
	 * @testdox Una ruta que no es home ni la alterna queda fuera.
	 */
	public function test_other_routes_are_not_home_surface(): void {
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );

		self::assertFalse( akibara_gp_is_home_surface() );
	}

	/**
	 * @testdox Las páginas content-driven se consultan como conjunto de slugs.
	 */
	public function test_content_pages_are_queried_as_slugs(): void {
		Functions\expect( 'is_page' )
			->once()
			->with( AKIBARA_GP_CONTENT_PAGES )
			->andReturn( true );

		self::assertTrue( akibara_gp_is_content_page() );
	}

	/**
	 * @testdox El conjunto content-driven no contiene un solo ID numérico.
	 */
	public function test_no_numeric_ids_survive_in_the_page_sets(): void {
		$targets = array_merge(
			AKIBARA_GP_CONTENT_PAGES,
			array( AKIBARA_GP_HOME_ALT )
		);

		foreach ( $targets as $target ) {
			self::assertIsString( $target, 'Las páginas objetivo se declaran por slug.' );
			self::assertFalse(
				ctype_digit( $target ),
				sprintf( 'El objetivo "%s" volvió a ser un ID de wp_posts.', $target )
			);
		}
	}

	/**
	 * @testdox Se cubren las diez páginas estáticas que usan pages.css.
	 */
	public function test_content_page_set_is_complete(): void {
		self::assertSame(
			array(
				'contacto',
				'editoriales',
				// `series` es el directorio de series (`[akibara_series_directory]`),
				// gemelo funcional de `editoriales`: usa el mismo `.akb-page__hero`
				// y por eso necesita `pages.css` y la supresión del H1 de
				// GeneratePress. Sin esto la página sale con DOS H1 y sin sangría.
				'series',
				'faq',
				'nosotros',
				'envios',
				'embalaje-seguro',
				'devoluciones',
				'terminos-y-condiciones',
				'politica-de-privacidad',
			),
			AKIBARA_GP_CONTENT_PAGES
		);
	}

	/**
	 * Los cuatro slugs que el tema declaraba sin página detrás.
	 *
	 * `suscripcion-confirmada` tenía constante y helper propios; `encargos`
	 * vivía en el conjunto content-driven; `wishlist` y `manga` eran dos
	 * `is_page()` sueltos en inc/enqueue.php. Ninguno casaba nunca: medido en
	 * producción, no existe un post con esos slugs en ningún estado, ni
	 * siquiera en la papelera. Se retiraron en 2.1.139.
	 *
	 * Esta prueba es lo que impide que vuelvan por costumbre. Un slug sin
	 * página publicada no falla: se queda en falso para siempre y hace creer
	 * al que lo lee que la superficie existe.
	 *
	 * @testdox Los slugs sin página detrás no vuelven al tema.
	 */
	public function test_retired_slugs_do_not_come_back(): void {
		$retirados = array( 'suscripcion-confirmada', 'encargos', 'wishlist', 'manga' );

		foreach ( $retirados as $slug ) {
			self::assertNotContains(
				$slug,
				AKIBARA_GP_CONTENT_PAGES,
				sprintf( 'El slug "%s" no tiene página en producción: no puede volver al conjunto.', $slug )
			);
		}

		self::assertFalse(
			defined( 'AKIBARA_GP_SUBSCRIBED_PAGE' ),
			'La superficie /suscripcion-confirmada/ no existe: su constante no debe renacer.'
		);
		self::assertFalse(
			function_exists( 'akibara_gp_is_subscribed_page' ),
			'La superficie /suscripcion-confirmada/ no existe: su helper no debe renacer.'
		);

		// Sobre el CÓDIGO, no sobre los comentarios: el comentario que explica
		// por qué se fueron cita los dos `is_page()` textualmente, y un candado
		// de fuente ingenuo se dispararía contra la propia explicación.
		$enqueue = self::codigo_sin_comentarios( dirname( __DIR__ ) . '/inc/enqueue.php' );

		foreach ( array( 'wishlist', 'manga' ) as $slug ) {
			self::assertStringNotContainsString(
				sprintf( "is_page('%s')", $slug ),
				$enqueue,
				sprintf( 'No hay página "%s": esa rama no casa nunca.', $slug )
			);
		}
	}

	/**
	 * Devuelve el código PHP de un fichero sin comentarios ni espacios.
	 *
	 * @param string $ruta Ruta absoluta del fichero.
	 * @return string
	 */
	private static function codigo_sin_comentarios( string $ruta ): string {
		$codigo = '';

		foreach ( token_get_all( (string) file_get_contents( $ruta ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
					continue;
				}
				$codigo .= $token[1];
				continue;
			}

			$codigo .= $token;
		}

		return $codigo;
	}

	/**
	 * Deja todas las condicionales de ruta en falso salvo las que se pidan.
	 *
	 * @param array<string, mixed> $verdaderas Nombre de función => valor a devolver.
	 */
	private function ruta( array $verdaderas = array() ): void {
		$condicionales = array(
			'is_shop',
			'is_product_category',
			'is_product_tag',
			'is_product',
			'is_search',
			'is_cart',
			'is_account_page',
			'is_404',
			'is_front_page',
			'is_page',
			'is_product_taxonomy',
		);
		foreach ( $condicionales as $condicional ) {
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
		Functions\when( 'is_woocommerce' )->justReturn( true );
	}

	/**
	 * EL DEFECTO QUE CIERRA. La lista de superficies con rejilla estaba copiada
	 * en tres sitios y dos copias se habían quedado sin `pa_genero` ni
	 * `pa_autor`: los 137 archivos de /genero/ y /autor/ pintaban «Añadir al
	 * carrito» mientras el dequeue les quitaba `wc-add-to-cart`, así que el
	 * botón navegaba en vez de agregar.
	 *
	 * @testdox Género y autor cuentan como superficie con rejilla de producto.
	 */
	public function test_genre_and_author_archives_carry_a_product_loop(): void {
		foreach ( array( 'pa_genero', 'pa_autor' ) as $taxonomia ) {
			$this->ruta( array( 'is_tax' => array( $taxonomia ) ) );

			self::assertTrue(
				akibara_gp_surface_has_product_loop(),
				sprintf( 'El archivo de %s rinde rejilla con botón de compra.', $taxonomia )
			);
		}
	}

	/**
	 * @testdox La búsqueda de productos también cuenta; la de entradas no.
	 */
	public function test_product_search_counts_but_post_search_does_not(): void {
		$this->ruta(
			array(
				'is_search'     => true,
				'get_query_var' => 'product',
			)
		);
		self::assertTrue( akibara_gp_surface_has_product_loop() );

		$this->ruta( array( 'is_search' => true ) );
		self::assertFalse( akibara_gp_surface_has_product_loop() );
	}

	/**
	 * @testdox Las superficies históricas de la lista siguen dentro.
	 */
	public function test_known_loop_surfaces_stay_in(): void {
		foreach ( array( 'is_shop', 'is_product_category', 'is_product_tag', 'is_product', 'is_cart', 'is_account_page', 'is_404', 'is_front_page' ) as $condicional ) {
			$this->ruta( array( $condicional => true ) );

			self::assertTrue(
				akibara_gp_surface_has_product_loop(),
				sprintf( '%s dejó de contar como superficie con rejilla.', $condicional )
			);
		}

		foreach ( array( 'product_brand', 'pa_serie' ) as $taxonomia ) {
			$this->ruta( array( 'is_tax' => array( $taxonomia ) ) );
			self::assertTrue( akibara_gp_surface_has_product_loop() );
		}
	}

	/**
	 * @testdox Una página cualquiera no arrastra los assets de la rejilla.
	 */
	public function test_a_plain_page_has_no_product_loop(): void {
		$this->ruta();

		self::assertFalse( akibara_gp_surface_has_product_loop() );
	}
}
