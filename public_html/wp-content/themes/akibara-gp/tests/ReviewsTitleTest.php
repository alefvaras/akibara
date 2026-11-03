<?php
/**
 * Título de la sección de reseñas de la ficha (T6-R1, 2026-09-23): el nombre de
 * marca, como texto real, por los dos caminos de la plantilla de WooCommerce.
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
final class ReviewsTitleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		require_once dirname( __DIR__ ) . '/inc/site-copy.php';
		require_once dirname( __DIR__ ) . '/inc/page-targets.php';
		require_once dirname( __DIR__ ) . '/inc/woocommerce.php';

		Functions\when( 'esc_html' )->alias( static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) );
		$this->con_options( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $options Options guardadas.
	 */
	private function con_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static fn( string $name, $default = false ) => array_key_exists( $name, $options ) ? $options[ $name ] : $default
		);
	}

	/**
	 * @testdox Sin nada guardado, el título es el que ya se veía: «Voces de Akibara».
	 */
	public function test_default_is_the_brand_title_customers_already_saw(): void {
		self::assertSame( 'Voces de Akibara', akibara_gp_reviews_title() );
	}

	/**
	 * @testdox El dueño lo cambia en el mismo campo que el carrusel; vacío vuelve al de fábrica.
	 */
	public function test_owner_edits_it_from_the_carousel_title_option(): void {
		$this->con_options( array( 'akb_rewards_carousel_title' => '  Lo que dice la comunidad ' ) );
		self::assertSame( 'Lo que dice la comunidad', akibara_gp_reviews_title() );

		$this->con_options( array( 'akb_rewards_carousel_title' => '   ' ) );
		self::assertSame( 'Voces de Akibara', akibara_gp_reviews_title(), 'Un título en blanco dejaría la barra roja huérfana.' );
	}

	/**
	 * @testdox Con reseñas, el «N valoraciones en Tomo» de WooCommerce pasa a ser el de marca, escapado.
	 */
	public function test_with_reviews_the_whole_title_is_the_brand_title_escaped(): void {
		$this->con_options( array( 'akb_rewards_carousel_title' => 'Voces <del>Distrito</del>' ) );

		self::assertSame( 'Voces &lt;del&gt;Distrito&lt;/del&gt;', akibara_gp_reviews_title_with_reviews(), 'WooCommerce imprime el filtro sin escapar.' );
	}

	/**
	 * @testdox Sin reseñas, el gettext se arma SÓLO al cargar la plantilla de reseñas.
	 */
	public function test_gettext_is_armed_only_for_the_reviews_template(): void {
		$ruta = '/wp-content/plugins/woocommerce/templates/comments.php';
		self::assertSame( $ruta, akibara_gp_reviews_title_arm( $ruta ) );
		self::assertFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ), 'Otra plantilla de comentarios no arma nada.' );

		$ruta = '/wp-content/plugins/woocommerce/templates/single-product-reviews.php';
		self::assertSame( $ruta, akibara_gp_reviews_title_arm( $ruta ), 'La ruta de la plantilla no se toca.' );
		self::assertNotFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ) );
	}

	/**
	 * @testdox El gettext cambia el primer «Reviews» y se retira: ningún otro texto pasa por él.
	 */
	public function test_gettext_is_single_use(): void {
		akibara_gp_reviews_title_arm( '/x/single-product-reviews.php' );

		self::assertSame( 'Valoraciones (1)', akibara_gp_reviews_title_without_reviews( 'Valoraciones (1)', 'Reviews (%d)' ), 'Otro literal no se toca.' );
		self::assertNotFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ), 'Sigue armado hasta el título.' );

		self::assertSame( 'Voces de Akibara', akibara_gp_reviews_title_without_reviews( 'Valoraciones', 'Reviews' ) );
		self::assertFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ), 'Un solo uso.' );
	}

	/**
	 * @testdox Si la plantilla toma el camino «con reseñas» o sale antes, el gettext se desarma igual.
	 */
	public function test_other_exits_disarm_the_gettext(): void {
		akibara_gp_reviews_title_arm( '/x/single-product-reviews.php' );
		akibara_gp_reviews_title_with_reviews();
		self::assertFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ), 'Con reseñas no queda armado.' );

		akibara_gp_reviews_title_arm( '/x/single-product-reviews.php' );
		akibara_gp_reviews_title_disarm();
		self::assertFalse( has_filter( 'gettext_woocommerce', 'akibara_gp_reviews_title_without_reviews' ), 'Al cerrar las pestañas no queda armado.' );
	}

	/**
	 * @testdox Los tres enganches están donde WooCommerce los dispara.
	 */
	public function test_hooks_are_registered_in_source(): void {
		// inc/woocommerce.php se carga una sola vez por proceso y Brain Monkey limpia
		// los hooks entre pruebas: se fija la llamada en la fuente.
		$fuente = (string) file_get_contents( dirname( __DIR__ ) . '/inc/woocommerce.php' );
		self::assertMatchesRegularExpression( "/add_filter\\(\\s*'woocommerce_reviews_title',\\s*'akibara_gp_reviews_title_with_reviews'/", $fuente );
		self::assertMatchesRegularExpression( "/add_filter\\(\\s*'comments_template',\\s*'akibara_gp_reviews_title_arm',\\s*99\\s*\\)/", $fuente, 'Después del cargador de WooCommerce (prioridad 10).' );
		self::assertMatchesRegularExpression( "/add_action\\(\\s*'woocommerce_product_after_tabs',\\s*'akibara_gp_reviews_title_disarm'/", $fuente );
	}
}
